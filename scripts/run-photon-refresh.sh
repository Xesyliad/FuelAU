#!/bin/sh

set -eu

result_file="var/docker/photon-eval/.refresh-result"

docker compose --profile photon-setup run --rm --no-deps photon-refresh

if grep -qx updated "${result_file}"; then
    docker compose --profile routing restart photon
    docker compose --profile routing up -d --wait --wait-timeout 180 --no-deps photon
    printf '%s\n' "Photon restarted successfully on the refreshed index."
elif grep -qx unchanged "${result_file}"; then
    printf '%s\n' "Photon snapshot is unchanged; runtime restart skipped."
else
    printf '%s\n' "Photon refresh returned an unexpected state." >&2
    exit 1
fi
