# FuelAU

Australian fuel price and routing API project.

FuelAU is a Docker-first PHP application based on the previous Fuel app structure. It currently provides:

- A fixed-width-font web UI with tabs for Fuel Prices, Route Planning, and Container Management.
- Fuel price graphs, a region-based station map, and clickable station price popups for the selected fuel.
- Route planning with Nominatim geocoding, OSRM routing, local map display, and fuel-stop planning.
- A PHP/Apache app container with cron.
- MariaDB-backed Fuel Prices Queensland imports.
- MariaDB-backed South Australia fuel imports.
- MariaDB-backed NSW Fuel API imports for NSW and Tasmania.
- Victoria Servo Saver open-data imports.
- Docker container status, logs, restart controls, and safe prune actions through the Container Management tab.
- Optional Australia routing/geocoding services using OSRM and Nominatim.
- Optional local Australia vector basemap using Planetiler and TileServer GL.

All services are managed from the single root `docker-compose.yml`.

## Quick Start

For a fresh clone, follow these steps in order:

1. Copy the sample config files:

```bash
cp .env.sample .env
cp config/app-sample.env config/app.env
cp config/mysql-sample.env config/mysql.env
```

2. Edit `.env`, `config/app.env`, and `config/mysql.env`.

`UID` and `GID` live in the root `.env` file. Keep the defaults of `999:999` for most Linux hosts. On macOS or on any host where the mounted MariaDB data directory needs different ownership, set `UID` and `GID` to the host user IDs before starting the stack.

3. Start the base stack:

```bash
docker compose up -d --build
```

4. Create the runtime folders and database tables:

```bash
docker compose exec app php setup.php
```

5. Open the web UI:

```text
http://localhost:18080/
```

6. If you want routing/geocoding, start the routing profile services:

```bash
docker compose --profile routing --profile routing-setup up -d
```

7. If you want the local Australia basemap, build it once and then start the map server:

```bash
docker compose --profile map-setup run --rm map-build
docker compose --profile map up -d map-server map-scheduler
```

## Tester Setup Checklist

FuelAU can be tested in stages. Start with the base stack first, then add the large routing and map services only when the basic app is working.

### Stage 1: Base App and Container Panel

Use this stage to confirm Docker, PHP, MariaDB, cron, and the Container Management tab work.

1. Clone the repository and enter the project directory.

```bash
git clone https://github.com/Xesyliad/FuelAU.git
cd FuelAU
```

2. Copy the sample configuration files.

```bash
cp .env.sample .env
cp config/app-sample.env config/app.env
cp config/mysql-sample.env config/mysql.env
```

3. Edit `.env` and `config/mysql.env`.

At minimum, set:

- `UID` and `GID` to match the host user if the MariaDB data directory needs to be writable by your local Docker engine. The defaults are `999:999`; on macOS or other hosts with different file ownership, changing these values prevents the startup error during the base stack build.
- `MYSQL_ROOT_PASSWORD`
- `MYSQL_PASSWORD`
- `NOMINATIM_PASSWORD`
- `FUELAU_HOST_PROJECT_ROOT` if the checkout is not at `/opt/FuelAU`

4. Start the base stack.

```bash
docker compose up -d --build
docker compose exec app php setup.php
```

5. Open the app.

```text
http://localhost:18080/
```

Expected result:

- The UI loads.
- The Container Management tab shows `app` and `db`.
- `/api/health` responds.
- Fuel charts may be empty until API keys are added and sync jobs run.

### Stage 2: Fuel Price Imports

Use this stage to test the Fuel Prices tab, history graphs, station map markers, and route fuel-stop pricing.

1. Apply for the required API credentials.

| Source | Covers | Config keys | Access/sign-up URL |
| --- | --- | --- | --- |
| Fuel Prices Queensland | QLD | `FUEL_PRICES_QLD_SUBSCRIBER_TOKEN` | https://www.fuelpricesqld.com.au/#developers |
| South Australia Fuel Pricing Information Scheme | SA | `SA_FUEL_API_BASE_URL`, `SA_FUEL_SUBSCRIBER_TOKEN` | https://www.safuelpricinginformation.com.au/publishers.html |
| NSW Fuel API | NSW and TAS | `NSW_FUEL_API_KEY`, `NSW_FUEL_API_SECRET`, `NSW_FUEL_API_AUTHORIZATION_HEADER` | https://api.nsw.gov.au/Product/Index/22 |
| Victoria Servo Saver Public API | VIC | `VIC_SERVO_SAVER_API_KEY` | https://service.vic.gov.au/find-services/transport-and-driving/servo-saver/help-centre/servo-saver-public-api |

Useful portal links:

- API.NSW account/sign-up: https://api.nsw.gov.au/
- API.NSW Fuel API product: https://api.nsw.gov.au/Product/Index/22
- Fuel Prices Queensland developer information: https://www.fuelpricesqld.com.au/#developers
- South Australia publishers page and outbound API guide: https://www.safuelpricinginformation.com.au/publishers.html
- Servo Saver API information: https://service.vic.gov.au/find-services/transport-and-driving/servo-saver/help-centre/servo-saver-public-api

2. Put the credentials in `config/app.env`.

```env
FUEL_PRICES_QLD_SUBSCRIBER_TOKEN=your_qld_token
SA_FUEL_API_BASE_URL=https://fppdirectapi-prod.safuelpricinginformation.com.au
SA_FUEL_SUBSCRIBER_TOKEN=your_sa_token
NSW_FUEL_API_BASE_URL=https://api.onegov.nsw.gov.au
NSW_FUEL_API_STATES=NSW|TAS
NSW_FUEL_API_KEY=your_nsw_key
NSW_FUEL_API_SECRET=your_nsw_secret
NSW_FUEL_API_AUTHORIZATION_HEADER=Basic your_nsw_basic_header
VIC_SERVO_SAVER_API_KEY=your_vic_key
```

3. Rebuild or restart the app after changing env files.

```bash
docker compose up -d --build app
docker compose exec app php setup.php
```

4. Run initial imports manually instead of waiting for cron.

```bash
docker compose exec app env PYTHONPATH=src python3 -m fpq_sync.cli all
docker compose exec app env PYTHONPATH=src python3 -m sa_sync.cli all
docker compose exec app env PYTHONPATH=src python3 -m nsw_sync.cli all
docker compose exec app env PYTHONPATH=src python3 -m vic_sync.cli all
```

5. Check logs if any source is empty.

```bash
docker compose exec app tail -f /var/log/fuelapi/fpq_sync.log
docker compose exec app tail -f /var/log/fuelapi/sa_sync.log
docker compose exec app tail -f /var/log/fuelapi/nsw_sync.log
docker compose exec app tail -f /var/log/fuelapi/vic_sync.log
```

Expected result:

- Fuel Prices has selectable states, regions, and fuels.
- Weekly and monthly graphs populate after price/history data is present.
- The station map shows clickable stations for the selected state, region, and fuel.
- Cron continues refreshing supported sources every 30 minutes.

### Stage 3: Route Planning Without Local Map Tiles

Use this stage to test Nominatim search, OSRM routing, route summaries, turn-by-turn legs, and fuel-stop planning. This stage does not require the local basemap tile build, but it does require routing/geocoding data.

1. Build OSRM data.

```bash
docker compose --profile routing-setup up osrm-customize
```

2. Start Nominatim and OSRM.

```bash
docker compose --profile routing up -d nominatim osrm-routed
```

3. Watch service status.

```bash
docker compose --profile routing ps
docker compose --profile routing logs -f nominatim
docker compose --profile routing logs -f osrm-routed
```

Expected result:

- Origin and destination search suggestions work after Nominatim import is ready.
- Route Planning can build routes after OSRM data exists.
- Fuel stops are calculated from imported fuel data.
- The route map area may still lack a useful basemap until Stage 4 is complete.

Important notes:

- Nominatim Australia import is large and can take hours.
- OSRM setup downloads and processes the Australia OSM extract.
- On Synology or NFS-backed storage, Nominatim may need host-side ownership fixes described in `Local Runtime State`.

### Stage 4: Full Local Map Display

Use this stage to test local Australia map tiles in the Fuel Prices and Route Planning maps.

1. Build the Australia basemap.

```bash
docker compose --profile map-setup run --rm map-build
```

2. Start the tile server and weekly rebuild scheduler.

```bash
docker compose --profile map up -d map-server map-scheduler
```

3. Confirm tile configuration through the app.

```text
http://localhost:18080/api/map/config
```

Expected result:

- `map-server` is healthy.
- The app serves tiles through `/tiles/`.
- Fuel Prices station map displays local basemap tiles.
- Route Planning displays route lines and fuel-stop markers over the local basemap.

### Stage 5: Tester Sanity Routes

After Stages 2-4 are running, test these route examples in the Route Planning tab with Diesel, a `60 L` tank, and `12 L/100km` economy:

- Cairns, Queensland -> Townsville, Queensland
- Cairns, Queensland -> Brisbane, Queensland
- Cairns, Queensland -> Sydney, NSW
- Cairns, Queensland -> Melbourne, Victoria
- Cairns, Queensland -> Brisbane, Queensland -> Sydney, NSW

Expected result:

- The planner returns a route summary.
- Fuel stops are listed with litres and total fill price.
- The map shows the route and fuel-stop markers.
- No normal stop should be a tiny refill; safety stops should be labelled if the planner cannot maintain the normal half-tank refill rule.
- If the tank is too small to reach a safe stop, the planner should show the extra external reserve needed to reach the destination safely.

## Services

- `app`: PHP Apache runtime, API/UI, cron jobs, and Docker management API.
- `db`: MariaDB 11.4 application database.
- `nominatim`: Australia geocoding service using `mediagis/nominatim:5.1`.
- `osrm-download`: downloads the Australia OSM PBF for OSRM.
- `osrm-extract`: builds the OSRM extract.
- `osrm-partition`: prepares OSRM MLD partitions.
- `osrm-customize`: customizes OSRM MLD cells.
- `osrm-routed`: Australia routing API service.
- `map-build`: one-shot Planetiler build for the local Australia vector basemap.
- `map-server`: local TileServer GL service exposed through the app `/tiles/` proxy.
- `map-scheduler`: weekly Docker CLI scheduler for local basemap rebuilds.

The `app` image copies the project source into the image at build time. Rebuild the app container after source changes:

```bash
docker compose up -d --build app
```

## Configuration

Edit these files before starting the stack:

- `.env`: Compose ports, MariaDB bootstrap credentials, MariaDB container UID/GID, Nominatim password, timezone.
- `config/app.env`: central application settings such as external API tokens and non-MySQL integration credentials.
- `config/mysql.env`: MySQL connection settings only.

Files containing real secrets are ignored by Git. Commit only `.env.sample`, `config/app-sample.env`, and `config/mysql-sample.env`.

Set `FUELAU_HOST_PROJECT_ROOT` in `.env` when the project is not checked out at `/opt/FuelAU`. The `map-scheduler` container runs Docker Compose from inside Docker, so the project must be mounted at the same absolute path that exists on the Docker host.

Current application-level config keys include:

- `FUEL_PRICES_QLD_SUBSCRIBER_TOKEN`
- `SA_FUEL_API_BASE_URL`
- `SA_FUEL_SUBSCRIBER_TOKEN`
- `NSW_FUEL_API_BASE_URL`
- `NSW_FUEL_API_STATES`
- `NSW_FUEL_API_KEY`
- `NSW_FUEL_API_SECRET`
- `NSW_FUEL_API_AUTHORIZATION_HEADER`
- `VIC_SERVO_SAVER_API_KEY`
- `MAP_TILE_SERVER_URL`
- `MAP_TILE_STYLE`

## First Run Details

The `app` container copies config files into `/run/fuelapi` at startup. That means:

- `config/app.env` is where non-MySQL credentials live.
- `config/mysql.env` is only for database connection settings.
- `setup.php` creates the project-local runtime directories required by later services.

If you are on Synology or any NFS-backed share, the PostgreSQL ownership step may need to be done on the host before Nominatim can start.

## Local Runtime State

Project-owned runtime state is stored under `/opt/FuelAU/var/docker/`, which is ignored by Git:

- `var/docker/app-logs`: app, cron, and sync logs
- `var/docker/db-data`: MariaDB data directory
- `var/docker/nominatim-db`: Nominatim PostgreSQL data
- `var/docker/nominatim-flatnode`: Nominatim flatnode data
- `var/docker/osrm-data`: downloaded and processed OSRM routing data
- `var/docker/map-tiles`: Australia basemap tiles for the local map stack

Docker image layers, container metadata, and build cache are still managed by the host Docker daemon. Normal Compose cannot relocate those per project without using a separate Docker daemon or compatible external BuildKit builder.

`setup.php` creates the project-local runtime directories required by later services. The MariaDB image uses UID/GID `999:999` by default; override that in `.env` with `UID` and `GID` when your host ownership requires it. The Nominatim image uses PostgreSQL as UID/GID `100:103`.

For Synology/NFS-backed `/opt/FuelAU`, the share must allow real ownership changes for PostgreSQL-backed services. Verify Nominatim ownership on the Docker host:

```bash
sudo chown -R 100:103 /opt/FuelAU/var/docker/nominatim-db
sudo chmod 700 /opt/FuelAU/var/docker/nominatim-db/16/main
stat -c '%u:%g %a %n' /opt/FuelAU/var/docker/nominatim-db/16/main
```

The `stat` output must show `100:103 700`. If it remains `65534:65534`, Synology is still mapping the Docker host user to `nobody`; set the NFS permission for the Docker host to read/write with squash/no mapping disabled as appropriate for the DSM version, or move Nominatim data to local disk or a Docker-managed volume.

## Start

The base stack starts with:

```bash
docker compose up -d --build
docker compose exec app php setup.php
```

That gives you the app, database, cron jobs, and the Fuel Prices tab. Routing and the local map stack are optional profiles and must be started separately if you want route planning maps, geocoding, local basemap tiles, or station maps backed by local tiles.

Default local endpoints:

```text
Web UI:      http://localhost:18080/
Health API:  http://localhost:18080/api/health
OSRM:        http://localhost:15000/
Nominatim:   http://localhost:18081/
```

OSRM and Nominatim bind to `127.0.0.1` by default. Their ports are only active when those profile services are running.

## Optional Services

Start routing and geocoding:

```bash
docker compose --profile routing --profile routing-setup up -d
```

Build the local Australia basemap:

```bash
docker compose --profile map-setup run --rm map-build
```

Start the local basemap server and weekly rebuild scheduler:

```bash
docker compose --profile map up -d map-server map-scheduler
```

The basemap scheduler runs weekly after the first manual build. The routing setup and map build jobs are one-shot preprocessing services and do not stay running.

## User Experience

The Fuel Prices tab has:

- `State`: limits fuel options and regions to that state.
- `Region`: major city/region selector for the selected state. Regions are currently seeded from Australian cities over roughly 20,000 people.
- `Fuel`: selected fuel type, persisted in a long-lived browser cookie.
- Weekly and monthly trend graphs.
- A recent snapshot table.
- A station map showing current prices for the selected state, region, and fuel. Click a station marker to see station name, address, selected fuel price, source/state, and update time.

The Route Planning tab has:

- Origin and reorderable destinations with Nominatim-backed search suggestions.
- Fuel type, tank fill size, and fuel economy controls.
- Direct-return or reverse-path return mode.
- A MapLibre route map using the local `/tiles/` basemap when the map stack is running.
- Fuel stops plotted on the route and a turn-by-turn breakdown.
- If a segment cannot reach any safe fuel stop, the planner shows an external reserve requirement instead of failing silently.

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
- `source` currently supports `qld`, `sa`, `nsw`, `tas`, `vic`, and `all`.

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

## South Australia Fuel Pricing Information Scheme

The SA importer is in `src/sa_sync`. It uses the official South Australia publisher API with:

- Publishers page and outbound API guide: https://www.safuelpricinginformation.com.au/publishers.html
- HTTP authorization header: `Authorization: FPDAPI SubscriberToken=<token>`

Set these keys in `config/app.env`:

```env
SA_FUEL_API_BASE_URL=https://fppdirectapi-prod.safuelpricinginformation.com.au
SA_FUEL_SUBSCRIBER_TOKEN=your_sa_token
```

Expected cadence:

- Reference data: once per day
- Prices: every 30 minutes

If your SA token is newly issued, allow up to 24 hours before the first successful sync.

Manual sync:

```bash
docker compose exec app env PYTHONPATH=src python3 -m sa_sync.cli all
```

Diagnostics:

```bash
docker compose exec app env PYTHONPATH=src python3 -m sa_sync.cli diagnose
```

View logs:

```bash
docker compose exec app tail -f /var/log/fuelapi/sa_sync.log
```

## Victoria Servo Saver Open Data

The Victoria importer is in `src/vic_sync`. It uses the official Service Victoria open-data API with:

- `/fuel/reference-data/brands`
- `/fuel/reference-data/stations`
- `/fuel/reference-data/types`
- `/fuel/prices`

The data is delayed by about 24 hours, but polling every 30 minutes is still fine. The official docs also note a limit of 10 requests per 60 seconds, so FuelAU keeps the VIC sync job compact.

Manual sync:

```bash
docker compose exec app env PYTHONPATH=src python3 -m vic_sync.cli all
```

Diagnostics:

```bash
docker compose exec app env PYTHONPATH=src python3 -m vic_sync.cli diagnose
```

View logs:

```bash
docker compose exec app tail -f /var/log/fuelapi/vic_sync.log
```

## Cron

Cron runs inside the `app` container from `docker/cron.d/fuelau`.

Current jobs:

- Every 15 minutes: PHP heartbeat to `/var/log/fuelapi/cron-heartbeat.log`.
- Every 30 minutes: Fuel Prices Queensland sync to `/var/log/fuelapi/fpq_sync.log`.
- Every 30 minutes at `:20` and `:50`: South Australia sync to `/var/log/fuelapi/sa_sync.log`.
- Every 30 minutes at `:15` and `:45`: NSW Fuel API sync to `/var/log/fuelapi/nsw_sync.log`.
- Every 30 minutes at `:05` and `:35`: Victoria Servo Saver sync to `/var/log/fuelapi/vic_sync.log`.

The weekly local basemap rebuild is handled by the `map-scheduler` Docker service, not by the app container cron. Its output goes to `var/docker/app-logs/map_build.log`.

Useful checks:

```bash
docker compose exec app ps -ef | grep '[c]ron'
docker compose exec app tail -f /var/log/fuelapi/cron-heartbeat.log
docker compose exec app tail -f /var/log/fuelapi/fpq_sync.log
docker compose exec app tail -f /var/log/fuelapi/sa_sync.log
docker compose exec app tail -f /var/log/fuelapi/nsw_sync.log
docker compose exec app tail -f /var/log/fuelapi/vic_sync.log
docker compose --profile map logs -f map-scheduler
```

## Container Management

The Container Management tab uses the Docker socket mounted into the `app` container:

```yaml
/var/run/docker.sock:/var/run/docker.sock
```

It shows configured services even before a container exists, including app, database, Nominatim, OSRM setup/runtime services, and local map services. It currently supports:

- service/container status
- expected state for each service or one-shot job
- container logs
- container restart
- project stopped-container pruning
- dangling-image pruning
- Docker disk usage summary

The local map services are shown as:

- `map-build`: one-shot Planetiler job.
- `map-server`: TileServer GL runtime.
- `map-scheduler`: weekly Docker CLI scheduler.

Expected states distinguish always-on runtime services, optional profile runtime services, and one-shot setup jobs. For example, `app` and `db` are expected to be running in the base stack, OSRM setup jobs are expected to be exited successfully or prepared, and `map-server` is expected to run only when the map profile is enabled.

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

## Local Map Stack

The local map stack is built from an Australia OpenStreetMap extract and stored under `var/docker/map-tiles`.

Current services:

- `map-build`: one-shot Planetiler rebuild job that writes `australia.mbtiles`
- `map-server`: local TileServer GL light instance that serves the rebuilt tiles and style JSON
- `map-scheduler`: weekly Docker CLI scheduler that runs the map build through Compose

The app exposes the tile server config at `/api/map/config`. The default local settings are:

- `MAP_TILE_SERVER_URL=/tiles`
- `MAP_TILE_STYLE=basic-preview`

The browser should load map assets from the app host at `/tiles/`, which Apache reverse proxies to the internal `map-server` container.

Rebuild the basemap manually:

```bash
docker compose --profile map-setup run --rm map-build
```

Start the local tile server and weekly rebuild scheduler:

```bash
docker compose --profile map up -d map-server map-scheduler
```

The rebuild cadence is weekly at Sunday 03:10 Australia/Brisbane time. The scheduler requires access to the Docker socket and the host project path via `FUELAU_HOST_PROJECT_ROOT`. The server is designed to read the latest `var/docker/map-tiles/australia.mbtiles` file without involving the app database.

## Dependency Order

Compose health and dependency rules are configured so the core database becomes healthy before the app starts:

```text
db -> app -> nominatim
osrm-download -> osrm-extract -> osrm-partition -> osrm-customize
osrm-customize -> osrm-routed when routing setup and routing profiles are started together
map-build -> map-server -> map-scheduler when map setup and map profiles are started together
```

`depends_on` controls startup ordering, not application readiness beyond configured health checks. Optional dependencies are marked non-required so already-prepared artifacts can be used without rerunning one-shot jobs every time. Nominatim can still take hours to finish its import after the container starts.

## Common Commands

```bash
docker compose up -d --build
docker compose exec app php setup.php
docker compose restart app
docker compose exec app env PYTHONPATH=src python3 -m fpq_sync.cli all
docker compose exec app env PYTHONPATH=src python3 -m sa_sync.cli all
docker compose exec app env PYTHONPATH=src python3 -m nsw_sync.cli all
docker compose exec app env PYTHONPATH=src python3 -m vic_sync.cli all
docker compose --profile routing-setup up osrm-customize
docker compose --profile routing up -d nominatim osrm-routed
docker compose --profile map-setup run --rm map-build
docker compose --profile map up -d map-server map-scheduler
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
