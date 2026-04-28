# FuelAU

Australian routing by fuel stations.

Docker-first PHP API scaffold based on the previous Fuel app conventions, but without the Fuel Prices QLD sync code.

## Services

- `app`: PHP Apache runtime, cron, and project files.
- `db`: MariaDB for API storage.

Both services are managed from the single root `docker-compose.yml`.

## Start

```bash
docker compose up -d --build
docker compose exec app php setup.php
```

The API will be available at:

```text
http://localhost:18080/api/health
```

## Runtime Config

The app reads database settings from `/etc/fuelapi/mysql.env` inside the container. Docker Compose mounts `config/mysql.env` there.

Copy the sample before first run if needed:

```bash
cp config/mysql-sample.env config/mysql.env
```

## Cron

Cron runs inside the `app` container and loads jobs from `docker/cron.d/fuelau`.

The starter job runs every 15 minutes and records a heartbeat in the database:

```bash
docker compose logs app
docker compose exec app tail -f /var/log/fuelapi/cron-heartbeat.log
```

## Common Commands

```bash
docker compose up -d --build
docker compose restart app
docker compose exec app php setup.php
docker compose exec app php bin/cron-heartbeat.php
docker compose down
```
