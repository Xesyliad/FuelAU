#!/bin/sh
set -eu

output="${MAP_OUTPUT:-/data/australia.mbtiles}"
minimum_tiles="${MAP_MINIMUM_TILES:-1000}"
case "$output" in
    *.mbtiles)
        temporary="${output%.mbtiles}.building-$$.mbtiles"
        ;;
    *)
        temporary="${output}.building-$$.mbtiles"
        ;;
esac
lock_file="${output}.lock"

cleanup() {
    rm -f "$temporary" "$temporary-wal" "$temporary-shm"
}

trap cleanup EXIT INT TERM

exec 9>"$lock_file"
if ! flock -n 9; then
    printf '%s\n' "Another map build is already publishing $output" >&2
    exit 1
fi

cleanup

java -cp "@/app/jib-classpath-file" com.onthegomap.planetiler.Main \
    "$@" \
    --output="$temporary" \
    --force

integrity="$(sqlite3 "$temporary" 'PRAGMA integrity_check;')"
if [ "$integrity" != "ok" ]; then
    printf '%s\n' "Map database integrity check failed: $integrity" >&2
    exit 1
fi

tile_count="$(sqlite3 "$temporary" 'SELECT COUNT(*) FROM tiles;')"
case "$tile_count" in
    ''|*[!0-9]*)
        printf '%s\n' "Map tile count is invalid: $tile_count" >&2
        exit 1
        ;;
esac

if [ "$tile_count" -lt "$minimum_tiles" ]; then
    printf '%s\n' "Map database contains $tile_count tiles; expected at least $minimum_tiles" >&2
    exit 1
fi

metadata_count="$(sqlite3 "$temporary" "SELECT COUNT(*) FROM metadata WHERE name IN ('bounds', 'format', 'maxzoom', 'minzoom');")"
if [ "$metadata_count" -lt 4 ]; then
    printf '%s\n' "Map database is missing required metadata" >&2
    exit 1
fi

rm -f "$temporary-wal" "$temporary-shm"
sync -f "$temporary"
mv -f "$temporary" "$output"
sync -f "$(dirname "$output")"
trap - EXIT INT TERM

printf '%s\n' "Published $tile_count map tiles to $output"
