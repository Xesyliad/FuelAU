from __future__ import annotations

import argparse
import json
import os
import subprocess
import uuid
from dataclasses import dataclass
from datetime import UTC
from datetime import datetime
from datetime import timedelta
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request

from sync_utils import build_atomic_snapshot_sql
from sync_utils import is_unconfigured_value
from sync_utils import retry_urlopen
from sync_utils import sync_duration_message
from sync_utils import sync_mysql_credentials
from sync_utils import sync_monotonic


DEFAULT_MYSQL_ENV_PATH = "/etc/fuelapi/mysql.env"
DEFAULT_APP_ENV_PATH = "/etc/fuelapi/app.env"
DEFAULT_API_BASE_URL = "https://api.fuel.service.vic.gov.au/open-data/v1"
USER_AGENT = "fuelau-vic-sync/0.1"
MYSQL_INSERT_BATCH_SIZE = 500


@dataclass(frozen=True)
class SyncResult:
    job_name: str
    rows_processed: int
    message: str


def parse_env_file(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    content = path.read_text(encoding="utf-8")
    for raw_line in content.splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip()
    return values


def mysql_escape(value: object | None) -> str:
    if value is None:
        return "NULL"
    return "'" + str(value).replace("\\", "\\\\").replace("'", "''") + "'"


def build_mysql_command(mysql_env_path: str) -> tuple[list[str], dict[str, str]]:
    config = parse_env_file(Path(mysql_env_path))
    required = ("MYSQL_HOST", "MYSQL_PORT", "MYSQL_DATABASE")
    missing = [key for key in required if not config.get(key)]
    if missing:
        raise ValueError(f"MySQL env file is missing required keys: {', '.join(missing)}")

    username, password = sync_mysql_credentials(config)
    command = [
        "mysql",
        f"--host={config['MYSQL_HOST']}",
        f"--port={config['MYSQL_PORT']}",
        f"--user={username}",
        f"--default-character-set={config.get('MYSQL_CHARSET', 'utf8mb4')}",
        "--protocol=TCP",
        config["MYSQL_DATABASE"],
    ]
    env = os.environ.copy()
    env["MYSQL_PWD"] = password
    return command, env


def run_mysql_sql(mysql_env_path: str, sql: str) -> None:
    command, env = build_mysql_command(mysql_env_path)
    result = subprocess.run(command, input=sql, text=True, env=env, capture_output=True, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or "mysql exited with a non-zero status")


def query_mysql_values(mysql_env_path: str, sql: str) -> list[str]:
    command, env = build_mysql_command(mysql_env_path)
    command.extend(["--batch", "--raw", "--skip-column-names"])
    result = subprocess.run(command, input=sql, text=True, env=env, capture_output=True, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or "mysql exited with a non-zero status")
    return [line.split("\t")[0] if line else "" for line in result.stdout.splitlines()]


def chunk_items(items: list[dict[str, object]], batch_size: int) -> list[list[dict[str, object]]]:
    if batch_size <= 0:
        raise ValueError("batch_size must be positive")
    return [items[index:index + batch_size] for index in range(0, len(items), batch_size)]


def run_batched_sql(
    mysql_env_path: str,
    items: list[dict[str, object]],
    sql_builder,
    batch_size: int = MYSQL_INSERT_BATCH_SIZE,
) -> None:
    for batch in chunk_items(items, batch_size):
        sql = sql_builder(batch)
        if sql:
            run_mysql_sql(mysql_env_path, sql)


def required_app_config(config: dict[str, str], key: str) -> str:
    value = config.get(key, "").strip()
    if value == "":
        raise ValueError(f"app env file is missing required key: {key}")
    return value


def fetch_json(api_base_url: str, consumer_id: str, path: str) -> dict[str, object]:
    request = Request(
        api_base_url.rstrip("/") + path,
        headers={
            "x-consumer-id": consumer_id,
            "x-transactionid": str(uuid.uuid4()),
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
        },
    )
    try:
        with retry_urlopen(request, timeout=180) as response:
            return json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code} for {path}: {body[:500]}") from exc


def parse_vic_datetime(value: object | None) -> str | None:
    text = str(value or "").strip()
    if text == "":
        return None
    parsed = datetime.fromisoformat(text.replace("Z", "+00:00"))
    return parsed.astimezone(UTC).replace(tzinfo=None).isoformat(sep=" ")


def log_sync_run(mysql_env_path: str, job_name: str, status: str, rows_processed: int, message: str) -> None:
    now = datetime.now(UTC).replace(microsecond=0).isoformat().replace("+00:00", "")
    finished_at_sql = "NULL" if status == "started" else mysql_escape(now)
    sql = f"""
INSERT INTO `vic_sync_runs` (
    `job_name`,
    `started_at_utc`,
    `finished_at_utc`,
    `status`,
    `rows_processed`,
    `message`
)
VALUES (
    {mysql_escape(job_name)},
    {mysql_escape(now)},
    {finished_at_sql},
    {mysql_escape(status)},
    {rows_processed},
    {mysql_escape(message[:65535])}
);
DELETE FROM `vic_sync_runs`
WHERE `started_at_utc` < UTC_TIMESTAMP() - INTERVAL 90 DAY;
""".strip()
    run_mysql_sql(mysql_env_path, sql)


def build_brands_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = [
        "("
        + ", ".join(
            [
                mysql_escape(item.get("id")),
                mysql_escape(item.get("name")),
                mysql_escape(item.get("type")),
            ]
        )
        + ")"
        for item in items
    ]
    return f"""
INSERT INTO `vic_brands` (`brand_id`, `name`, `brand_type`)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `brand_type` = VALUES(`brand_type`);
""".strip()


def build_fuel_types_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = [
        "(" + ", ".join([mysql_escape(item.get("id")), mysql_escape(item.get("name"))]) + ")"
        for item in items
    ]
    return f"""
INSERT INTO `vic_fuel_types` (`fuel_code`, `name`)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);
""".strip()


def build_stations_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = []
    for item in items:
        location = item.get("location") if isinstance(item.get("location"), dict) else {}
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("id")),
                    mysql_escape(item.get("brandId")),
                    mysql_escape(item.get("name")),
                    mysql_escape(item.get("address")),
                    mysql_escape(item.get("contactPhone")),
                    mysql_escape(location.get("latitude")),
                    mysql_escape(location.get("longitude")),
                    mysql_escape(parse_vic_datetime(item.get("updatedAt"))),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `vic_stations` (
    `station_id`,
    `brand_id`,
    `name`,
    `address`,
    `contact_phone`,
    `latitude`,
    `longitude`,
    `updated_at_utc`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `brand_id` = VALUES(`brand_id`),
    `name` = VALUES(`name`),
    `address` = VALUES(`address`),
    `contact_phone` = VALUES(`contact_phone`),
    `latitude` = VALUES(`latitude`),
    `longitude` = VALUES(`longitude`),
    `updated_at_utc` = VALUES(`updated_at_utc`);
""".strip()


def build_prices_history_sql(items: list[dict[str, object]]) -> str:
    if not items:
        return ""
    values = []
    for item in items:
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("station_id")),
                    mysql_escape(item.get("fuel_code")),
                    mysql_escape(parse_vic_datetime(item.get("updated_at"))),
                    mysql_escape(1 if bool(item.get("is_available")) else 0),
                    mysql_escape(item.get("price")),
                ]
            )
            + ")"
        )
    return f"""
INSERT INTO `vic_site_prices_history` (
    `station_id`,
    `fuel_code`,
    `updated_at_utc`,
    `is_available`,
    `price`
)
VALUES
{",\n".join(values)}
ON DUPLICATE KEY UPDATE
    `is_available` = VALUES(`is_available`),
    `price` = VALUES(`price`);
""".strip()


def build_prices_current_sql(items: list[dict[str, object]]) -> str:
    latest_by_key: dict[tuple[object, object], dict[str, object]] = {}
    for item in items:
        key = (item.get("station_id"), item.get("fuel_code"))
        existing = latest_by_key.get(key)
        current_updated_at = parse_vic_datetime(item.get("updated_at")) or ""
        if existing is None:
            latest_by_key[key] = item
            continue
        existing_updated_at = parse_vic_datetime(existing.get("updated_at")) or ""
        if current_updated_at >= existing_updated_at:
            latest_by_key[key] = item

    values = []
    for item in latest_by_key.values():
        values.append(
            "("
            + ", ".join(
                [
                    mysql_escape(item.get("station_id")),
                    mysql_escape(item.get("fuel_code")),
                    mysql_escape(parse_vic_datetime(item.get("updated_at"))),
                    mysql_escape(1 if bool(item.get("is_available")) else 0),
                    mysql_escape(item.get("price")),
                ]
            )
            + ")"
        )
    return build_atomic_snapshot_sql(
        table="vic_site_prices_current",
        columns=["station_id", "fuel_code", "updated_at_utc", "is_available", "price"],
        key_columns=["station_id", "fuel_code"],
        freshness_column="updated_at_utc",
        values=values,
    )


def station_rows_from_price_details(items: list[dict[str, object]]) -> list[dict[str, object]]:
    stations: dict[str, dict[str, object]] = {}
    for item in items:
        station = item.get("fuelStation")
        if not isinstance(station, dict):
            continue
        station_id = str(station.get("id", "")).strip()
        if station_id == "":
            continue
        stations[station_id] = {
            "id": station_id,
            "brandId": station.get("brandId"),
            "name": station.get("name"),
            "address": station.get("address"),
            "contactPhone": station.get("contactPhone"),
            "location": station.get("location") if isinstance(station.get("location"), dict) else {},
            "updatedAt": station.get("updatedAt"),
        }
    return list(stations.values())


def price_rows_from_price_details(items: list[dict[str, object]]) -> list[dict[str, object]]:
    prices: list[dict[str, object]] = []
    for item in items:
        station = item.get("fuelStation")
        if not isinstance(station, dict):
            continue
        station_id = str(station.get("id", "")).strip()
        if station_id == "":
            continue
        fuel_prices = item.get("fuelPrices")
        if not isinstance(fuel_prices, list):
            continue
        for price in fuel_prices:
            if not isinstance(price, dict):
                continue
            fuel_code = str(price.get("fuelType", "")).strip()
            if fuel_code == "":
                continue
            prices.append(
                {
                    "station_id": station_id,
                    "fuel_code": fuel_code,
                    "price": price.get("price"),
                    "is_available": price.get("isAvailable"),
                    "updated_at": price.get("updatedAt"),
                }
            )
    return prices


def sync_reference(mysql_env_path: str, api_base_url: str, consumer_id: str) -> SyncResult:
    brands_payload = fetch_json(api_base_url, consumer_id, "/fuel/reference-data/brands")
    stations_payload = fetch_json(api_base_url, consumer_id, "/fuel/reference-data/stations")
    types_payload = fetch_json(api_base_url, consumer_id, "/fuel/reference-data/types")

    brands = list(brands_payload.get("brands", [])) if isinstance(brands_payload.get("brands"), list) else []
    stations = (
        list(stations_payload.get("fuelStations", []))
        if isinstance(stations_payload.get("fuelStations"), list)
        else []
    )
    fuel_types = list(types_payload.get("fuelTypes", [])) if isinstance(types_payload.get("fuelTypes"), list) else []

    sql_chunks = [build_brands_sql(brands), build_stations_sql(stations), build_fuel_types_sql(fuel_types)]
    sql = "\n\n".join(chunk for chunk in sql_chunks if chunk)
    if sql:
        run_mysql_sql(mysql_env_path, sql)
    total_rows = len(brands) + len(stations) + len(fuel_types)
    message = f"brands={len(brands)}, stations={len(stations)}, fuel_types={len(fuel_types)}"
    return SyncResult(job_name="vic_reference", rows_processed=total_rows, message=message)


def sync_prices(mysql_env_path: str, api_base_url: str, consumer_id: str) -> SyncResult:
    payload = fetch_json(api_base_url, consumer_id, "/fuel/prices")
    details = (
        list(payload.get("fuelPriceDetails", []))
        if isinstance(payload.get("fuelPriceDetails"), list)
        else []
    )
    stations = station_rows_from_price_details(details)
    prices = price_rows_from_price_details(details)

    if stations:
        run_batched_sql(mysql_env_path, stations, build_stations_sql)
    if prices:
        run_batched_sql(mysql_env_path, prices, build_prices_history_sql)
        current_sql = build_prices_current_sql(prices)
        if current_sql:
            run_mysql_sql(mysql_env_path, current_sql)
    else:
        build_prices_current_sql([])

    message = f"stations={len(stations)}, prices={len(prices)}"
    return SyncResult(job_name="vic_prices", rows_processed=len(prices), message=message)


def latest_success_value(mysql_env_path: str, job_name: str) -> str | None:
    sql = f"""
SELECT DATE_FORMAT(MAX(started_at_utc), '%Y-%m-%d %H:%i:%s')
FROM vic_sync_runs
WHERE job_name = {mysql_escape(job_name)}
  AND status = 'success';
""".strip()
    values = query_mysql_values(mysql_env_path, sql)
    value = values[0].strip() if values else ""
    if value == "" or value.upper() == "NULL":
        return None
    return value


def current_price_count(mysql_env_path: str) -> int:
    sql = "SELECT COUNT(*) FROM vic_site_prices_current;"
    values = query_mysql_values(mysql_env_path, sql)
    return int(values[0] or "0") if values else 0


def should_refresh_reference(mysql_env_path: str) -> bool:
    if current_price_count(mysql_env_path) == 0:
        return True
    latest = latest_success_value(mysql_env_path, "vic_reference")
    if latest is None:
        return True
    latest_dt = datetime.strptime(latest, "%Y-%m-%d %H:%M:%S").replace(tzinfo=UTC)
    return latest_dt <= datetime.now(UTC) - timedelta(hours=24)


def run_diagnostics(api_base_url: str, consumer_id: str) -> list[tuple[str, int, str]]:
    probes = [
        ("brands", "/fuel/reference-data/brands", ("brands",)),
        ("stations", "/fuel/reference-data/stations", ("fuelStations",)),
        ("fuel_types", "/fuel/reference-data/types", ("fuelTypes",)),
        ("prices", "/fuel/prices", ("fuelPriceDetails",)),
    ]
    results: list[tuple[str, int, str]] = []
    for label, path, keys in probes:
        try:
            payload: object = fetch_json(api_base_url, consumer_id, path)
            for key in keys:
                if isinstance(payload, dict):
                    payload = payload.get(key)
                else:
                    payload = None
                    break
            count = len(payload) if isinstance(payload, list) else -1
            results.append((label, count, "ok" if count >= 0 else "unexpected payload"))
        except Exception as exc:
            results.append((label, -1, str(exc)))
    return results


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Sync Victoria Servo Saver open data into MySQL.")
    parser.add_argument(
        "job",
        choices=("reference", "prices", "all", "diagnose"),
        help="Which VIC sync job to run.",
    )
    parser.add_argument(
        "--mysql-env",
        default=DEFAULT_MYSQL_ENV_PATH,
        help="Path to the MySQL env file.",
    )
    parser.add_argument(
        "--app-env",
        default=DEFAULT_APP_ENV_PATH,
        help="Path to the app env file.",
    )
    parser.add_argument(
        "--api-base-url",
        default=DEFAULT_API_BASE_URL,
        help="VIC Servo Saver API base URL.",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or os.sys.argv[1:])
    started_at = sync_monotonic()
    run_job_name = f"vic_{args.job.replace('-', '_')}"
    app_config = parse_env_file(Path(args.app_env))
    try:
        consumer_id = required_app_config(app_config, "VIC_SERVO_SAVER_API_KEY")
    except Exception as exc:
        print(f"error: {exc}", file=os.sys.stderr)
        return 1

    if is_unconfigured_value(consumer_id):
        print("info: VIC_SERVO_SAVER_API_KEY is not configured; skipping VIC sync", file=os.sys.stderr)
        return 0

    if args.job != "diagnose":
        try:
            log_sync_run(args.mysql_env, run_job_name, "started", 0, "sync started")
        except Exception:
            pass

    try:
        results: list[SyncResult] = []
        if args.job == "diagnose":
            diagnostics = run_diagnostics(args.api_base_url, consumer_id)
            for label, count, message in diagnostics:
                if count >= 0:
                    print(f"{label}: count={count}")
                else:
                    print(f"{label}: error={message}")
            return 0

        if args.job in ("reference", "all") and should_refresh_reference(args.mysql_env):
            results.append(sync_reference(args.mysql_env, args.api_base_url, consumer_id))
        elif args.job == "reference":
            results.append(SyncResult("vic_reference", 0, "skipped reference refresh; last success < 24h"))

        if args.job in ("prices", "all"):
            results.append(sync_prices(args.mysql_env, args.api_base_url, consumer_id))
    except Exception as exc:
        error_message = sync_duration_message(str(exc), started_at)
        try:
            log_sync_run(args.mysql_env, run_job_name, "error", 0, error_message)
        except Exception:
            pass
        print(f"error: {exc}", file=os.sys.stderr)
        return 1

    for result in results:
        result_message = sync_duration_message(result.message, started_at)
        try:
            log_sync_run(args.mysql_env, result.job_name, "success", result.rows_processed, result_message)
        except Exception:
            pass
        print(f"{result.job_name}: {result.message}")
    try:
        log_sync_run(
            args.mysql_env,
            run_job_name,
            "success",
            sum(result.rows_processed for result in results),
            sync_duration_message("sync completed", started_at),
        )
    except Exception:
        pass

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
