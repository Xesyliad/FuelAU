# FuelAU

Australian fuel price and routing API project.

FuelAU is a Docker-first PHP application based on the previous Fuel app structure. It currently provides:

- A fixed-width-font web UI with tabs for Fuel Prices, Route Planning, and Container Management.
- A PHP/Apache app container with cron.
- MariaDB-backed Fuel Prices Queensland imports.
- MariaDB-backed NSW Fuel API imports for NSW and Tasmania.
- Docker container status, logs, restart controls, and safe prune actions through the Container Management tab.
- Optional Australia routing/geocoding services using OSRM and Nominatim.

All services are managed from the single root `docker-compose.yml`.

## Services

- `app`: PHP Apache runtime, API/UI, cron jobs, and Docker management API.
- `db`: MariaDB 11.4 application database.
- `nominatim`: Australia geocoding service using `mediagis/nominatim:5.1`.
- `osrm-download`: downloads the Australia OSM PBF for OSRM.
- `osrm-extract`: builds the OSRM extract.
- `osrm-partition`: prepares OSRM MLD partitions.
- `osrm-customize`: customizes OSRM MLD cells.
- `osrm-routed`: Australia routing API service.

The `app` image copies the project source into the image at build time. Rebuild the app container after source changes:

```bash
docker compose up -d --build app
```

## Configuration

Create local runtime config from the tracked samples:

```bash
cp .env.sample .env
cp config/app-sample.env config/app.env
cp config/mysql-sample.env config/mysql.env
```

Edit these files before starting the stack:

- `.env`: Compose ports, MariaDB bootstrap credentials, Nominatim password, timezone.
- `config/app.env`: central application settings such as external API tokens and non-MySQL integration credentials.
- `config/mysql.env`: MySQL connection settings only.

Files containing real secrets are ignored by Git. Commit only `.env.sample`, `config/app-sample.env`, and `config/mysql-sample.env`.

Current application-level config keys include:

- `FUEL_PRICES_QLD_SUBSCRIBER_TOKEN`
- `NSW_FUEL_API_BASE_URL`
- `NSW_FUEL_API_STATES`
- `NSW_FUEL_API_KEY`
- `NSW_FUEL_API_SECRET`
- `NSW_FUEL_API_AUTHORIZATION_HEADER`
- `VIC_SERVO_SAVER_API_KEY`

## Local Runtime State

Project-owned runtime state is stored under `/opt/FuelAU/var/docker/`, which is ignored by Git:

- `var/docker/app-logs`: app, cron, and sync logs
- `var/docker/db-data`: MariaDB data directory
- `var/docker/nominatim-db`: Nominatim PostgreSQL data
- `var/docker/nominatim-flatnode`: Nominatim flatnode data
- `var/docker/osrm-data`: downloaded and processed OSRM routing data

Docker image layers, container metadata, and build cache are still managed by the host Docker daemon. Normal Compose cannot relocate those per project without using a separate Docker daemon or compatible external BuildKit builder.

`setup.php` creates the project-local runtime directories required by later services. The MariaDB image uses UID/GID `999:999`; the Nominatim image uses PostgreSQL as UID/GID `100:103`.

For Synology/NFS-backed `/opt/FuelAU`, the share must allow real ownership changes for PostgreSQL-backed services. Verify Nominatim ownership on the Docker host:

```bash
sudo chown -R 100:103 /opt/FuelAU/var/docker/nominatim-db
sudo chmod 700 /opt/FuelAU/var/docker/nominatim-db/16/main
stat -c '%u:%g %a %n' /opt/FuelAU/var/docker/nominatim-db/16/main
```

The `stat` output must show `100:103 700`. If it remains `65534:65534`, Synology is still mapping the Docker host user to `nobody`; set the NFS permission for the Docker host to read/write with squash/no mapping disabled as appropriate for the DSM version, or move Nominatim data to local disk or a Docker-managed volume.

## Start

Start the core app and database:

```bash
docker compose up -d --build
docker compose exec app php setup.php
```

Default local endpoints:

```text
Web UI:      http://localhost:18080/
Health API:  http://localhost:18080/api/health
OSRM:        http://localhost:15000/
Nominatim:   http://localhost:18081/
```

OSRM and Nominatim bind to `127.0.0.1` by default. Their ports are only active when those profile services are running.

## App API

The frontend should use the app-owned API under `http://localhost:18080/api/` rather than talking directly to Nominatim or OSRM on separate ports.

Current core endpoints:

- `/api/health`
- `/api/services/status`
- `/api/fuel/sources`
- `/api/fuel/current?source=all&limit=100`
- `/api/fuel/current?source=all&q=sydney&fuel=DL&state=NSW&lat=-33.8688&lon=151.2093&radius_km=5`
- `/api/geo/search?q=Sydney&limit=5`
- `/api/geo/reverse?lat=-33.8688&lon=151.2093`
- `/api/route?coordinates=151.2093,-33.8688;151.2069,-33.8731`

Fuel response notes:

- `price` is normalized to cents-per-litre across sources.
- `price_raw` preserves the original stored source value.
- `source` currently supports `qld`, `nsw`, `tas`, and `all`.

## Fuel Prices Queensland

The Fuel Prices Queensland importer is in `src/fpq_sync`. It loads:

- brands
- geographic regions
- fuel types
- sites
- current prices
- price history
- sync run records

The cron job runs every 30 minutes:

```cron
0,30 * * * * cd /var/www/html && PYTHONPATH=src /usr/bin/python3 -m fpq_sync.cli all >> /var/log/fuelapi/fpq_sync.log 2>&1
```

Manual sync:

```bash
docker compose exec app env PYTHONPATH=src python3 -m fpq_sync.cli all
```

View sync logs:

```bash
docker compose exec app tail -f /var/log/fuelapi/fpq_sync.log
```

The importer requires `FUEL_PRICES_QLD_SUBSCRIBER_TOKEN` in the ignored `config/app.env` file.

## NSW Fuel API

The NSW importer is in `src/nsw_sync`. It uses the official NSW Fuel API v2 endpoints, which cover both `NSW` and `TAS`.

Set `NSW_FUEL_API_STATES=NSW|TAS` in `config/app.env`. The NSW v2 Swagger defaults to `NSW` when the `states` query is omitted, so FuelAU sends the explicit state list on the LOV and price endpoints.

It caches the OAuth access token locally, refreshes reference data once per day, runs a full current-price refresh once per Sydney day, and uses the lighter `prices/new` endpoint for the other 30-minute runs. This keeps the sync under the free monthly API quota.

Manual sync:

```bash
docker compose exec app env PYTHONPATH=src python3 -m nsw_sync.cli all
```

Diagnostics:

```bash
docker compose exec app env PYTHONPATH=src python3 -m nsw_sync.cli diagnose
```

View logs:

```bash
docker compose exec app tail -f /var/log/fuelapi/nsw_sync.log
```

## Cron

Cron runs inside the `app` container from `docker/cron.d/fuelau`.

Current jobs:

- Every 15 minutes: PHP heartbeat to `/var/log/fuelapi/cron-heartbeat.log`.
- Every 30 minutes: Fuel Prices Queensland sync to `/var/log/fuelapi/fpq_sync.log`.
- Every 30 minutes at `:15` and `:45`: NSW Fuel API sync to `/var/log/fuelapi/nsw_sync.log`.

Useful checks:

```bash
docker compose exec app ps -ef | grep '[c]ron'
docker compose exec app tail -f /var/log/fuelapi/cron-heartbeat.log
docker compose exec app tail -f /var/log/fuelapi/fpq_sync.log
docker compose exec app tail -f /var/log/fuelapi/nsw_sync.log
```

## Container Management

The Container Management tab uses the Docker socket mounted into the `app` container:

```yaml
/var/run/docker.sock:/var/run/docker.sock
```

It shows configured services even before a container exists, including app, database, Nominatim, and the OSRM setup/runtime services. It currently supports:

- service/container status
- container logs
- container restart
- project stopped-container pruning
- dangling-image pruning
- Docker disk usage summary

Because this UI can control Docker on the host, only run it in a trusted local environment.

## Routing Services

Routing and geocoding are profile-gated and do not need to run for core Fuel Prices Queensland imports.

Nominatim uses:

```text
PBF_URL=https://download.geofabrik.de/australia-oceania/australia-latest.osm.pbf
REPLICATION_URL=https://download.geofabrik.de/australia-oceania/australia-updates
```

OSRM uses the same Australia PBF and stores generated files in `var/docker/osrm-data`.

Custom OSRM profile template:

- `config/osrm/fuelmiser.car.profile.template.lua` is a tracked scaffold for a custom OSRM Lua profile.
- The Compose setup mounts it as `/opt/fuelmiser.car.lua` and the file wraps the stock `/opt/car.lua` from the OSRM image.
- The profile is tuned as a hybrid approximation. The exact 30 km switch still has to be selected outside OSRM because profiles are fixed at extraction time.
- Fill in the `EDIT ME` sections with your access, speed, and penalty rules before rebuilding OSRM data.

Build OSRM data:

```bash
docker compose --profile routing-setup up osrm-customize
```

Start routing services after OSRM data exists:

```bash
docker compose --profile routing up -d nominatim osrm-routed
```

Or start all profile services:

```bash
docker compose --profile routing --profile routing-setup up -d
```

Nominatim import for the Australia PBF is large and can take a long time. During import, `nominatim` may show `health: starting`.

## Dependency Order

Compose health and dependency rules are configured so the core database becomes healthy before the app starts:

```text
db -> app -> nominatim/osrm-routed
osrm-download -> osrm-extract -> osrm-partition -> osrm-customize
```

`depends_on` controls startup ordering, not application readiness beyond configured health checks. Nominatim can still take hours to finish its import after the container starts.

## Common Commands

```bash
docker compose up -d --build
docker compose exec app php setup.php
docker compose restart app
docker compose exec app env PYTHONPATH=src python3 -m fpq_sync.cli all
docker compose exec app env PYTHONPATH=src python3 -m nsw_sync.cli all
docker compose --profile routing-setup up osrm-customize
docker compose --profile routing up -d nominatim osrm-routed
docker compose --profile routing ps
docker compose down
```

## Git Hygiene

Ignored local files include:

- `.codex`
- `.env`
- `config/app.env`
- `config/mysql.env`
- `var/`
- `#recycle/`
- Python cache files
- swap files

Any new file containing real passwords, API keys, or tokens should be ignored, with a tracked sample file added beside it.
