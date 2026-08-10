#!/bin/sh

set -eu

input_root="${PHOTON_INPUT_ROOT:-/input}"
data_root="${PHOTON_DATA_ROOT:-/data}"
snapshot_name="photon-dump-australia-1.0-latest.jsonl.zst"
snapshot_url="${PHOTON_SNAPSHOT_URL:-https://download1.graphhopper.com/public/australia-oceania/australia/${snapshot_name}}"
checksum_url="${PHOTON_SNAPSHOT_MD5_URL:-${snapshot_url}.md5}"
result_file="${data_root}/.refresh-result"
stage_directory=""

publish_result() {
    result="$1"
    temporary_result="${data_root}/.refresh-result.$$"
    printf '%s\n' "${result}" > "${temporary_result}"
    mv -f "${temporary_result}" "${result_file}"
}

cleanup() {
    status=$?
    if [ -n "${stage_directory}" ] && [ -d "${stage_directory}" ]; then
        rm -rf -- "${stage_directory}"
    fi
    if [ "${status}" -ne 0 ]; then
        publish_result failed || true
        printf '%s\n' "Photon snapshot refresh failed; the published index was preserved." >&2
    fi
}
trap cleanup EXIT INT TERM

mkdir -p "${input_root}" "${data_root}"
exec 8>"${input_root}/.refresh.lock"
if ! flock -n 8; then
    printf '%s\n' "Another Photon snapshot refresh is already running." >&2
    exit 1
fi

stage_directory="$(mktemp -d "${input_root}/.refresh.XXXXXX")"
checksum_path="${stage_directory}/${snapshot_name}.md5"
snapshot_path="${stage_directory}/${snapshot_name}"
canonical_snapshot="${input_root}/${snapshot_name}"

curl --fail --location --silent --show-error \
    --retry 4 --retry-delay 5 --retry-all-errors \
    --output "${checksum_path}" \
    "${checksum_url}"

if ! grep -Eq "^[0-9a-fA-F]{32} [ *]${snapshot_name}$" "${checksum_path}"; then
    printf '%s\n' "Photon published checksum has an unexpected format or filename." >&2
    exit 1
fi
expected_md5="$(awk 'NR == 1 {print tolower($1)}' "${checksum_path}")"

if [ -r "${canonical_snapshot}" ]; then
    current_md5="$(md5sum "${canonical_snapshot}" | awk '{print $1}')"
    if [ "${current_md5}" = "${expected_md5}" ]; then
        current_sha256="$(sha256sum "${canonical_snapshot}" | awk '{print $1}')"
        printf '%s  %s\n' "${current_sha256}" "${snapshot_name}" > "${stage_directory}/${snapshot_name}.sha256"
        mv -f "${checksum_path}" "${input_root}/${snapshot_name}.md5"
        mv -f "${stage_directory}/${snapshot_name}.sha256" "${input_root}/${snapshot_name}.sha256"
        if [ -d "${data_root}/current/photon_data" ]; then
            publish_result unchanged
            printf '%s\n' "Photon Australia snapshot is unchanged (${current_sha256})."
        else
            PHOTON_IMPORT_FILE="${canonical_snapshot}" \
            PHOTON_IMPORT_SHA256="${current_sha256}" \
            build-photon-index
            publish_result updated
            printf '%s\n' "Built the missing Photon index from the verified local snapshot (${current_sha256})."
        fi
        trap - EXIT INT TERM
        cleanup
        exit 0
    fi
fi

curl --fail --location --silent --show-error \
    --retry 4 --retry-delay 5 --retry-all-errors \
    --output "${snapshot_path}" \
    "${snapshot_url}"

printf '%s  %s\n' "${expected_md5}" "${snapshot_name}" \
    | (cd "${stage_directory}" && md5sum --check -)

snapshot_sha256="$(sha256sum "${snapshot_path}" | awk '{print $1}')"
PHOTON_IMPORT_FILE="${snapshot_path}" \
PHOTON_IMPORT_SHA256="${snapshot_sha256}" \
build-photon-index

printf '%s  %s\n' "${snapshot_sha256}" "${snapshot_name}" > "${stage_directory}/${snapshot_name}.sha256"
mv -f "${snapshot_path}" "${canonical_snapshot}"
mv -f "${checksum_path}" "${input_root}/${snapshot_name}.md5"
mv -f "${stage_directory}/${snapshot_name}.sha256" "${input_root}/${snapshot_name}.sha256"
publish_result updated
printf '%s\n' "Published refreshed Photon Australia snapshot and index (${snapshot_sha256})."

trap - EXIT INT TERM
cleanup
