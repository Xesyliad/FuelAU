# FuelAU

Australian routing by fuel stations.

Docker-first PHP API scaffold based on the previous Fuel app conventions, but without the Fuel Prices QLD sync code.

## Services

- `app`: PHP Apache runtime, cron, and project files.
- `db`: MariaDB for API storage.

Both services are managed from the single root `docker-compose.yml`.

## Local Docker State

Project-owned runtime state is stored under `var/docker/`, which is ignored by Git:

- `var/docker/db-data`: MariaDB data directory
- `var/docker/app-logs`: app and cron logs

Docker image layers, container metadata, and build cache are still managed by the host Docker daemon. Normal Compose cannot relocate those per project without using a separate Docker daemon or a compatible external BuildKit builder.

## Start

Create local runtime config from the tracked samples:

```bash
cp .env.sample .env
cp config/mysql-sample.env config/mysql.env
```

Edit both files so the database passwords match.

```bash
docker compose up -d --build
docker compose exec app php setup.php
```

The API will be available at:

```text
http://localhost:18080/api/health
```

## Runtime Config

The app reads database settings from `/etc/fuelapi/mysql.env` inside the container. Docker Compose mounts `config/mysql.env` there. Compose itself reads database bootstrap settings from `.env`.

Files containing real passwords are ignored. Commit only `.env.sample` and `config/mysql-sample.env`.

## Cron

Cron runs inside the `app` container and loads jobs from `docker/cron.d/fuelau`.

The starter job runs every 15 minutes and records a heartbeat in the database:

```bash
docker compose logs app
docker compose exec app tail -f /var/log/fuelapi/cron-heartbeat.log
```

Fuel Prices QLD sync runs every 30 minutes and loads reference data, sites, current prices, and price history:

```bash
docker compose exec app env PYTHONPATH=src python3 -m fpq_sync.cli all
docker compose exec app tail -f /var/log/fuelapi/fpq_sync.log
```

Set `FUEL_PRICES_QLD_SUBSCRIBER_TOKEN` in the ignored `config/mysql.env` file.

## Common Commands

```bash
docker compose up -d --build
docker compose restart app
docker compose exec app php setup.php
docker compose exec app env PYTHONPATH=src python3 -m fpq_sync.cli all
docker compose exec app php bin/cron-heartbeat.php
docker compose down
```
