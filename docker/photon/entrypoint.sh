#!/bin/sh

set -eu

data_root="${PHOTON_DATA_ROOT:-/data}"
jar_path="/opt/photon/photon.jar"

case "${1:-serve}" in
    serve)
        if [ ! -d "${data_root}/current/photon_data" ]; then
            printf '%s\n' "Photon index is missing. Run the photon-import setup service first." >&2
            exit 1
        fi
        exec java -jar "${jar_path}" serve \
            -data-dir "${data_root}/current" \
            -listen-ip 0.0.0.0 \
            -listen-port 2322 \
            -default-language en \
            -country-codes AU \
            -languages en \
            -max-results "${PHOTON_MAX_RESULTS:-10}" \
            -max-reverse-results "${PHOTON_MAX_REVERSE_RESULTS:-10}" \
            -query-timeout "${PHOTON_QUERY_TIMEOUT_SECONDS:-5}"
        ;;
    import)
        exec build-photon-index
        ;;
    refresh)
        exec refresh-photon-index
        ;;
    cli)
        shift
        exec java -jar "${jar_path}" "$@"
        ;;
    *)
        printf '%s\n' "Unknown Photon command: $1" >&2
        exit 64
        ;;
esac
