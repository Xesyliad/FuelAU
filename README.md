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
- `var/docker/nominatim-db`: Nominatim PostgreSQL data
- `var/docker/nominatim-flatnode`: Nominatim flatnode data
- `var/docker/osrm-data`: downloaded and processed OSRM routing data

Docker image layers, container metadata, and build cache are still managed by the host Docker daemon. Normal Compose cannot relocate those per project without using a separate Docker daemon or a compatible external BuildKit builder.

`setup.php` creates the project-local runtime directories required by later services, including the Nominatim PostgreSQL path `var/docker/nominatim-db/16/main`.

If `/opt/FuelAU` is mounted from Synology over NFS, the share must allow real ownership changes for PostgreSQL-backed services. Verify this on the Docker host:

```bash
sudo chown -R 999:999 /opt/FuelAU/var/docker/nominatim-db
stat -c '%u:%g %n' /opt/FuelAU/var/docker/nominatim-db/16/main
```

The `stat` output must show `999:999`. If it remains `65534:65534`, Synology is still mapping the Docker host user to `nobody`; set the NFS permission for the Docker host to read/write with squash/no mapping disabled as appropriate for your DSM version, or move Nominatim data to local disk or a Docker-managed volume.

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

## Routing Services

Nominatim and OSRM are defined in `docker-compose.yml` but are behind profiles and will not start with a normal `docker compose up -d`.

Nominatim uses:

```text
PBF_URL=https://download.geofabrik.de/australia-oceania/australia-latest.osm.pbf
REPLICATION_URL=https://download.geofabrik.de/australia-oceania/australia-updates
```

OSRM setup uses the same Australia PBF and stores generated files in `var/docker/osrm-data`.

After reviewing `docker-compose.yml`, the intended manual sequence is:

```bash
docker compose --profile routing-setup run --rm osrm-download
docker compose --profile routing-setup run --rm osrm-extract
docker compose --profile routing-setup run --rm osrm-partition
docker compose --profile routing-setup run --rm osrm-customize
docker compose --profile routing up -d nominatim osrm-routed
```

## Common Commands

```bash
docker compose up -d --build
docker compose restart app
docker compose exec app php setup.php
docker compose exec app env PYTHONPATH=src python3 -m fpq_sync.cli all
docker compose exec app php bin/cron-heartbeat.php
docker compose down
```
