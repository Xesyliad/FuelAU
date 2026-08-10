#!/bin/sh

set -eu

data_root="${PHOTON_DATA_ROOT:-/data}"
input_path="${PHOTON_IMPORT_FILE:-/input/photon-dump-australia-1.0-latest.jsonl.zst}"
expected_sha256="${PHOTON_IMPORT_SHA256:-95f8a16e8dbbd0f869f3c91bc37d8b4721e24cb835450483007c3ccfd1f2a829}"
import_threads="${PHOTON_IMPORT_THREADS:-2}"
validation_port="${PHOTON_VALIDATION_PORT:-2323}"
index_retention="${PHOTON_INDEX_RETENTION:-3}"
jar_path="/opt/photon/photon.jar"

case "${index_retention}" in
    ''|*[!0-9]*)
        printf '%s\n' "PHOTON_INDEX_RETENTION must be an integer of at least 2." >&2
        exit 1
        ;;
esac
if [ "${index_retention}" -lt 2 ]; then
    printf '%s\n' "PHOTON_INDEX_RETENTION must retain the current and at least one rollback index." >&2
    exit 1
fi

if [ ! -r "${input_path}" ]; then
    printf '%s\n' "Photon import snapshot is not readable: ${input_path}" >&2
    exit 1
fi

if [ "$(sha256sum "${input_path}" | awk '{print $1}')" != "${expected_sha256}" ]; then
    printf '%s\n' "Photon import snapshot checksum does not match the pinned SHA-256." >&2
    exit 1
fi

mkdir -p "${data_root}"
lock_path="${data_root}/.import.lock"
exec 9>"${lock_path}"
if ! flock -n 9; then
    printf '%s\n' "Another Photon import is already running." >&2
    exit 1
fi

build_name="index-$(date -u +%Y%m%dT%H%M%SZ)-$$"
build_directory="${data_root}/${build_name}"
link_path="${data_root}/.current-$$"
server_pid=""

cleanup() {
    status=$?
    if [ -n "${server_pid}" ]; then
        kill "${server_pid}" 2>/dev/null || true
        wait "${server_pid}" 2>/dev/null || true
    fi
    if [ -L "${link_path}" ]; then
        rm -f "${link_path}"
    fi
    if [ "${status}" -ne 0 ]; then
        printf '%s\n' "Photon import failed; retained ${build_directory} for inspection." >&2
    fi
    exit "${status}"
}
trap cleanup EXIT INT TERM

mkdir "${build_directory}"
printf '%s\n' "Importing verified Australia snapshot into ${build_directory}"
zstd --stdout --decompress "${input_path}" \
    | java -jar "${jar_path}" import \
        -data-dir "${build_directory}" \
        -import-file - \
        -country-codes AU \
        -languages en \
        -j "${import_threads}"

if [ ! -d "${build_directory}/photon_data" ]; then
    printf '%s\n' "Photon import did not create the expected photon_data directory." >&2
    exit 1
fi

java -jar "${jar_path}" serve \
    -data-dir "${build_directory}" \
    -listen-ip 127.0.0.1 \
    -listen-port "${validation_port}" \
    -default-language en \
    -country-codes AU \
    -languages en \
    -max-results 10 \
    -query-timeout 5 &
server_pid=$!

attempt=0
while [ "${attempt}" -lt 60 ]; do
    if curl --fail --silent --max-time 5 "http://127.0.0.1:${validation_port}/status" >/dev/null; then
        break
    fi
    if ! kill -0 "${server_pid}" 2>/dev/null; then
        printf '%s\n' "Photon validation server exited before becoming healthy." >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    sleep 2
done

if [ "${attempt}" -ge 60 ]; then
    printf '%s\n' "Photon validation server did not become healthy within 120 seconds." >&2
    exit 1
fi

curl --fail --silent --get \
    --data-urlencode 'q=Brisbane' \
    --data 'limit=1' \
    "http://127.0.0.1:${validation_port}/api" \
    | grep --quiet 'FeatureCollection'

kill "${server_pid}"
wait "${server_pid}" || true
server_pid=""

ln -s "${build_name}" "${link_path}"
mv -Tf "${link_path}" "${data_root}/current"
printf '%s\n' "Published validated Photon index ${build_name}"

find "${data_root}" -mindepth 1 -maxdepth 1 -type d -name 'index-*' -printf '%f\n' \
    | sort -r \
    | awk -v retention="${index_retention}" 'NR > retention' \
    | while IFS= read -r old_build; do
        if printf '%s\n' "${old_build}" | grep -Eq '^index-[0-9]{8}T[0-9]{6}Z-[0-9]+$'; then
            if [ "${old_build}" != "${build_name}" ]; then
                rm -rf -- "${data_root:?}/${old_build}"
                printf '%s\n' "Removed expired Photon rollback index ${old_build}"
            fi
        else
            printf '%s\n' "Refusing to remove unexpected Photon index path: ${old_build}" >&2
        fi
    done

trap - EXIT INT TERM
