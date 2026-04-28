#!/bin/sh
set -eu

mkdir -p /var/log/fuelapi
touch /var/log/fuelapi/cron.log
chown -R www-data:www-data /var/log/fuelapi

crontab /etc/cron.d/fuelau
service cron start

exec "$@"
