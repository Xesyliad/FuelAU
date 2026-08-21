#!/bin/sh
set -eu

if [ "${CONTAINER_MANAGEMENT_ENABLED:-false}" = "true" ] \
    && [ -z "${CONTAINER_MANAGEMENT_TOKEN:-}" ]; then
    echo "CONTAINER_MANAGEMENT_TOKEN must be set for the admin service." >&2
    exit 1
fi

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

if [ -r /etc/fuelapi/app.env ]; then
    mkdir -p /run/fuelapi
    cp /etc/fuelapi/app.env /run/fuelapi/app.env
    chown www-data:www-data /run/fuelapi/app.env
    chmod 0400 /run/fuelapi/app.env
    export FUELAU_APP_ENV_PATH=/run/fuelapi/app.env
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

# Persisted cache files can also be created by root-run maintenance commands.
# Normalise ownership at startup so Apache can safely reuse those files.
app_state_directory=/var/www/html/var/docker/app-state
mkdir -p "$app_state_directory"
chown -R www-data:www-data "$app_state_directory" 2>/dev/null || true

if [ "${FUELAU_CRON_ENABLED:-true}" = "true" ]; then
    crontab /etc/cron.d/fuelau
    service cron start
fi

exec "$@"
