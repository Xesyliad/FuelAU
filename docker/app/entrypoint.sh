#!/bin/sh
set -eu

mkdir -p /var/log/fuelapi
touch /var/log/fuelapi/cron.log
chown -R www-data:www-data /var/log/fuelapi 2>/dev/null || true

if [ -S /var/run/docker.sock ]; then
    docker_gid="$(stat -c '%g' /var/run/docker.sock)"
    docker_group="$(getent group "$docker_gid" | cut -d: -f1 || true)"
    if [ -z "$docker_group" ]; then
        docker_group="dockerhost"
        groupadd -g "$docker_gid" "$docker_group"
    fi
    usermod -aG "$docker_group" www-data
fi

crontab /etc/cron.d/fuelau
service cron start

exec "$@"
