#!/usr/bin/env python3

from __future__ import annotations

import argparse
import concurrent.futures
import contextlib
import fcntl
import math
import os
import sqlite3
import sys
import time
import urllib.error
import urllib.request


BOUNDS = {
    "west": 110.0,
    "south": -45.5,
    "east": 156.0,
    "north": -8.0,
}

SOURCE_URL = "https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png"


def lon_to_tile(lon: float, zoom: int) -> int:
    return int((lon + 180.0) / 360.0 * (1 << zoom))


def lat_to_tile(lat: float, zoom: int) -> int:
    lat = max(min(lat, 85.05112878), -85.05112878)
    rad = math.radians(lat)
    return int((1.0 - math.log(math.tan(rad) + 1.0 / math.cos(rad)) / math.pi) / 2.0 * (1 << zoom))


def tile_bounds(zoom: int) -> tuple[int, int, int, int]:
    x_min = lon_to_tile(BOUNDS["west"], zoom)
    x_max = lon_to_tile(BOUNDS["east"], zoom)
    y_min = lat_to_tile(BOUNDS["north"], zoom)
    y_max = lat_to_tile(BOUNDS["south"], zoom)
    return x_min, x_max, y_min, y_max


def tms_y(zoom: int, y: int) -> int:
    return (1 << zoom) - 1 - y


def fetch_tile(url: str, retries: int, timeout: int) -> bytes:
    request = urllib.request.Request(url, headers={"User-Agent": "FuelAU terrain builder"})
    last_error: Exception | None = None
    for attempt in range(retries + 1):
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                if response.status != 200:
                    raise RuntimeError(f"unexpected status {response.status}")
                return response.read()
        except Exception as error:  # noqa: BLE001
            last_error = error
            if attempt < retries:
                time.sleep(min(10, 1 + attempt * 2))
    assert last_error is not None
    raise last_error


def fetch_task(task: tuple[int, int, int, str], retries: int, timeout: int) -> tuple[int, int, int, bytes | None, str | None]:
    zoom, x, y, url = task
    try:
        return zoom, x, y, fetch_tile(url, retries, timeout), None
    except Exception as error:  # noqa: BLE001
        return zoom, x, y, None, str(error)


def init_db(path: str) -> sqlite3.Connection:
    if os.path.exists(path):
        os.remove(path)
    connection = sqlite3.connect(path)
    connection.execute("PRAGMA journal_mode=WAL;")
    connection.execute("PRAGMA synchronous=NORMAL;")
    connection.execute("PRAGMA temp_store=MEMORY;")
    connection.execute(
        """
        CREATE TABLE metadata (
            name TEXT,
            value TEXT
        )
        """
    )
    connection.execute(
        """
        CREATE TABLE tiles (
            zoom_level INTEGER,
            tile_column INTEGER,
            tile_row INTEGER,
            tile_data BLOB
        )
        """
    )
    connection.execute("CREATE UNIQUE INDEX tiles_index ON tiles (zoom_level, tile_column, tile_row)")
    return connection


def remove_sqlite_files(path: str) -> None:
    for suffix in ("", "-wal", "-shm"):
        candidate = f"{path}{suffix}"
        try:
            os.remove(candidate)
        except FileNotFoundError:
            pass


@contextlib.contextmanager
def build_lock(output: str):
    lock_path = f"{output}.lock"
    lock_handle = open(lock_path, "a+", encoding="utf-8")
    try:
        try:
            fcntl.flock(lock_handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise RuntimeError(f"another terrain build is already publishing {output}") from error
        yield
    finally:
        fcntl.flock(lock_handle.fileno(), fcntl.LOCK_UN)
        lock_handle.close()


def validate_db(path: str, min_zoom: int, max_zoom: int, expected_tiles: int) -> None:
    connection = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    try:
        integrity = connection.execute("PRAGMA integrity_check").fetchone()
        if integrity is None or integrity[0] != "ok":
            raise RuntimeError(f"terrain database integrity check failed: {integrity}")

        metadata = dict(connection.execute("SELECT name, value FROM metadata").fetchall())
        expected_metadata = {
            "format": "png",
            "minzoom": str(min_zoom),
            "maxzoom": str(max_zoom),
            "encoding": "terrarium",
        }
        for key, expected in expected_metadata.items():
            if metadata.get(key) != expected:
                raise RuntimeError(
                    f"terrain metadata {key} expected {expected!r}, got {metadata.get(key)!r}"
                )

        tile_count = int(connection.execute("SELECT COUNT(*) FROM tiles").fetchone()[0])
        if tile_count != expected_tiles:
            raise RuntimeError(
                f"terrain database expected {expected_tiles:,} tiles, found {tile_count:,}"
            )
    finally:
        connection.close()


def durable_replace(source: str, destination: str) -> None:
    source_handle = os.open(source, os.O_RDONLY)
    try:
        os.fsync(source_handle)
    finally:
        os.close(source_handle)

    os.replace(source, destination)
    directory = os.path.dirname(os.path.abspath(destination)) or "."
    directory_handle = os.open(directory, os.O_RDONLY)
    try:
        os.fsync(directory_handle)
    finally:
        os.close(directory_handle)


def write_metadata(connection: sqlite3.Connection, min_zoom: int, max_zoom: int) -> None:
    entries = {
        "name": "FuelAU Terrain",
        "type": "baselayer",
        "version": "1",
        "description": "Terrarium terrain tiles for local hillshade and contour rendering.",
        "format": "png",
        "bounds": f'{BOUNDS["west"]},{BOUNDS["south"]},{BOUNDS["east"]},{BOUNDS["north"]}',
        "minzoom": str(min_zoom),
        "maxzoom": str(max_zoom),
        "encoding": "terrarium",
        "attribution": "Elevation tiles from AWS Open Data Terrain Tiles",
    }
    connection.executemany("INSERT INTO metadata (name, value) VALUES (?, ?)", entries.items())
    connection.commit()


def build_temporary_database(
    output: str,
    min_zoom: int,
    max_zoom: int,
    workers: int,
    retries: int,
    timeout: int,
) -> int:
    connection = init_db(output)
    tile_tasks: list[tuple[int, int, int, str]] = []
    for zoom in range(min_zoom, max_zoom + 1):
        x_min, x_max, y_min, y_max = tile_bounds(zoom)
        for x in range(x_min, x_max + 1):
            for y in range(y_min, y_max + 1):
                tile_tasks.append((zoom, x, y, SOURCE_URL.format(z=zoom, x=x, y=y)))

    total = len(tile_tasks)
    print(f"Preparing {total:,} terrain tiles", file=sys.stderr)

    inserted = 0
    failures = 0
    batch: list[tuple[int, int, int, bytes]] = []

    try:
        write_metadata(connection, min_zoom, max_zoom)
        with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as executor:
            iterator = executor.map(
                lambda task: fetch_task(task, retries, timeout),
                tile_tasks,
                chunksize=64,
            )
            for zoom, x, y, data, error in iterator:
                if error is not None or data is None:
                    failures += 1
                    print(f"failed z{zoom}/{x}/{y}: {error}", file=sys.stderr)
                    continue
                batch.append((zoom, x, tms_y(zoom, y), data))
                inserted += 1
                if len(batch) >= 1000:
                    connection.executemany(
                        "INSERT OR REPLACE INTO tiles (zoom_level, tile_column, tile_row, tile_data) VALUES (?, ?, ?, ?)",
                        batch,
                    )
                    connection.commit()
                    batch.clear()
                if inserted % 5000 == 0 or inserted == total:
                    print(f"downloaded {inserted:,}/{total:,}", file=sys.stderr)

        if batch:
            connection.executemany(
                "INSERT OR REPLACE INTO tiles (zoom_level, tile_column, tile_row, tile_data) VALUES (?, ?, ?, ?)",
                batch,
            )
            connection.commit()

        connection.execute("ANALYZE")
        connection.commit()
        connection.execute("PRAGMA wal_checkpoint(TRUNCATE)")
        journal_mode = connection.execute("PRAGMA journal_mode=DELETE").fetchone()
        if journal_mode is None or journal_mode[0].lower() != "delete":
            raise RuntimeError(f"failed to finalize terrain database journal mode: {journal_mode}")
    finally:
        connection.close()

    if failures:
        raise RuntimeError(f"terrain build completed with {failures} failed tiles")

    return total


def build(output: str, min_zoom: int, max_zoom: int, workers: int, retries: int, timeout: int) -> None:
    if min_zoom < 0 or max_zoom < min_zoom:
        raise ValueError("zoom range is invalid")
    if workers <= 0:
        raise ValueError("workers must be positive")

    output = os.path.abspath(output)
    os.makedirs(os.path.dirname(output), exist_ok=True)
    temporary_output = f"{output}.building-{os.getpid()}"

    with build_lock(output):
        remove_sqlite_files(temporary_output)
        try:
            expected_tiles = build_temporary_database(
                temporary_output,
                min_zoom,
                max_zoom,
                workers,
                retries,
                timeout,
            )
            validate_db(temporary_output, min_zoom, max_zoom, expected_tiles)
            durable_replace(temporary_output, output)
            print(f"Published {expected_tiles:,} terrain tiles to {output}", file=sys.stderr)
        finally:
            remove_sqlite_files(temporary_output)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build a local terrain mbtiles file for FuelAU.")
    parser.add_argument("--output", default="/data/terrain.mbtiles")
    parser.add_argument("--min-zoom", type=int, default=0)
    parser.add_argument("--max-zoom", type=int, default=8)
    parser.add_argument("--workers", type=int, default=4)
    parser.add_argument("--retries", type=int, default=5)
    parser.add_argument("--timeout", type=int, default=60)
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    build(args.output, args.min_zoom, args.max_zoom, args.workers, args.retries, args.timeout)


if __name__ == "__main__":
    main()
