#!/bin/sh
set -eu

mkdir -p /var/log/fuelapi
touch /var/log/fuelapi/cron.log
chown -R www-data:www-data /var/log/fuelapi 2>/dev/null || true

if [ -r /etc/fuelapi/mysql.env ]; then
    mkdir -p /run/fuelapi
    cp /etc/fuelapi/mysql.env /run/fuelapi/mysql.env
    chown www-data:www-data /run/fuelapi/mysql.env
    chmod 0400 /run/fuelapi/mysql.env
    export FUELAU_MYSQL_ENV_PATH=/run/fuelapi/mysql.env
fi

if [ -e /var/www/html/public/index.php ]; then
    web_uid="$(stat -c '%u' /var/www/html/public/index.php)"
    web_gid="$(stat -c '%g' /var/www/html/public/index.php)"
    if [ "$web_uid" != "0" ] && [ "$web_uid" != "$(id -u www-data)" ]; then
        usermod -u "$web_uid" www-data 2>/dev/null || true
    fi
    if [ "$web_gid" != "0" ]; then
        usermod -g "$web_gid" www-data 2>/dev/null || true
    fi
fi

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
