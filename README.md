# FuelAU

Australian fuel price and routing API project.

Demo available at <a href="https://fuelau.familysnaps.net" target="_blank" rel="noopener noreferrer">https://fuelau.familysnaps.net</a>.

FuelAU is a Docker-first PHP application based on the previous Fuel app structure. It currently provides:

- A responsive map-first web UI for fuel prices and route planning.
- Fuel price graphs, a region-based station map, and clickable station price popups for the selected fuel.
- Route planning with Nominatim geocoding, OSRM routing, local map display, and fuel-stop planning.
- A PHP/Apache app container with cron.
- MariaDB-backed Fuel Prices Queensland imports.
- MariaDB-backed South Australia fuel imports.
- MariaDB-backed NSW Fuel API imports for NSW and Tasmania.
- Victoria Servo Saver open-data imports.
- Docker container status, logs, restart controls, and constrained prune actions through an opt-in admin service.
- Optional Australia routing/geocoding services using OSRM and Nominatim.
- Optional local Australia vector basemap using Planetiler and TileServer GL.
- Optional Container Management tool on a separate loopback-only admin service; disabled by default.

All services are managed from the single root `docker-compose.yml`.

## Security Notes

FuelAU is designed for trusted home use on a local machine or private LAN. It is intended to be reachable from devices on your home network, but not exposed directly to the public internet.

The intended access model is LAN-only:

- Safe: access from a desktop, laptop, or phone on your home network.
- Not safe: exposing the app to the public internet with router port forwarding or WAN firewall rules.
- Not safe: treating the app as a general internet-facing service.
- Optional routing and map profiles can remain disabled if you only need the fuel price UI and sync jobs.

If this app is exposed to the internet and compromised, an attacker may be able to:

- read local application data and logs,
- interfere with routing, map, and sync jobs,
- and potentially pivot into other devices or services on the same local network.

Recommended controls before first use:

- Do not configure router port forwarding to the app or database ports.
- Keep the Docker host behind a firewall and block inbound access from the internet.
- Allow only trusted LAN devices to reach the app port.
- If you need remote access, use a VPN or other private tunnel rather than opening the app to the internet.
- Treat the separate Container Management service as a local-admin tool only; it can control allowlisted Docker resources on the host.
- Keep `MYSQL_HOST_PORT` bound to `127.0.0.1` unless you have a specific reason to expose MariaDB on a trusted private network.
- The application and admin ports bind to `127.0.0.1` by default. Set `APP_BIND_ADDRESS` or `ADMIN_BIND_ADDRESS` only when deliberate network access is required.
- To enable the Container Management tool, set `CONTAINER_MANAGEMENT_TOKEN` to a strong local secret and start the `admin` profile. Open `http://localhost:18083/`; the browser exchanges the secret for a 30-minute HttpOnly session and does not persist it in local storage.

If the app is reachable beyond your trusted LAN, that is a misconfiguration that should be corrected before launch.

### Before You Open It Up At Home

1. Confirm the Docker host has no WAN port forwards configured for FuelAU.
2. Confirm the host firewall blocks inbound access from the internet.
3. Confirm the app is only reachable from trusted devices on the home LAN.
4. Confirm `config/app.env` contains real API credentials, not sample placeholders.
5. Confirm `MYSQL_HOST_PORT` stays loopback-only unless you intentionally expose MariaDB on a trusted private network.
6. Confirm the Container Management tool is only used by people who are allowed to manage the host Docker daemon.
7. Confirm `/api/health` works before relying on the sync jobs.

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

For home use, keep the host firewall enabled and block inbound access from the public internet. The app may be reachable on your LAN, but it should not be exposed with router port forwards or WAN rules.
3. Start the base stack:

```bash
docker compose up -d --build
```

4. Create the runtime folders and database tables:

```bash
docker compose exec app php setup.php
```

### Database migrations

`setup.php` applies ordered files from `migrations/` under a MariaDB advisory lock. It checks already-applied migration checksums, runs transactional migrations inside a transaction, records a version only after its callback and schema assertions pass, and leaves failed versions unapplied. DDL migrations use idempotent statements because MariaDB implicitly commits most DDL.

Fresh databases apply the version-7 baseline followed by later migrations. Existing installations take the forward-only coordinate-index, importer `last_seen_at`, and UTC-normalization migrations through version 10. Re-running setup is safe:

```bash
docker compose exec app php setup.php
```

Back up before applying migrations:

```bash
docker compose exec -T db sh -c \
  'mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction "$MYSQL_DATABASE"' \
  > fuelau-before-migration.sql
```

Migrations are forward-only. If an upgrade must be rolled back, stop the application, preserve the failed database for diagnosis, and restore the backup:

```bash
docker compose stop app admin
docker compose exec -T db sh -c \
  'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  < fuelau-before-migration.sql
```

### Automated database backups

Production database backups are created by `scripts/backup-database.sh`. The script takes a non-blocking host lock,
streams a `mariadb-dump --single-transaction` backup from the database container, compresses it, validates the gzip
stream and expected database markers, and only then uploads it to the private S3 bucket. Each upload includes its
SHA-256 digest as object metadata and requests AES-256 server-side encryption.

The default destination is `s3://fuelau-production-backups` in `ap-southeast-2`, using the `fuelau-mcp` profile in
`/root/.aws/credentials`. These defaults can be overridden with `FUELAU_BACKUP_BUCKET`, `AWS_REGION`, `AWS_PROFILE`,
and `AWS_SHARED_CREDENTIALS_FILE`. Run a backup manually with:

```bash
sudo /opt/FuelAU/scripts/backup-database.sh
```

The host cron definition in `ops/cron/fuelau-backup` runs the backup at 04:55 in `Australia/Brisbane`. Install it as
`/etc/cron.d/fuelau-backup`, owned by root with mode `0644`. Logs are written to
`var/docker/app-logs/database-backup.log`.

Retention keeps the seven newest daily objects, four newest Sunday copies, six newest first-of-month copies, and
seven local `fuelau-production-*.sql.gz` files. S3 bucket lifecycle separately expires database objects after 210
days, removes noncurrent versions after 30 days, and aborts incomplete multipart uploads after seven days.

After a successful upload the job atomically updates `var/docker/backup-status.json`. `/api/health` reports the last
verified backup time and age, while the hourly `check-backup-status.sh` cron job emits an error to syslog and
`database-backup-alert.log` if the status is missing, invalid, or older than 24 hours. The maximum age defaults to
86,400 seconds and can be overridden with `FUELAU_BACKUP_MAX_AGE_SECONDS`.

List the newest off-host backups with:

```bash
AWS_PROFILE=fuelau-mcp AWS_REGION=ap-southeast-2 \
  aws s3api list-objects-v2 --bucket fuelau-production-backups \
  --prefix database/ --query 'reverse(sort_by(Contents,&LastModified))[:10].[LastModified,Size,Key]' \
  --output table
```

The restore procedure and latest drill evidence are recorded in
[`docs/operations/database-backup-restore.md`](docs/operations/database-backup-restore.md).

Fresh database initialization can also create separate least-privilege accounts when the `MYSQL_APP_*` and `MYSQL_SYNC_*` values from both `.env.sample` and `config/mysql-sample.env` are configured consistently:

- `MYSQL_USERNAME` / `MYSQL_PASSWORD`: schema migrator used only by `setup.php`.
- `MYSQL_APP_USERNAME` / `MYSQL_APP_PASSWORD`: public API account with `SELECT` only.
- `MYSQL_SYNC_USERNAME` / `MYSQL_SYNC_PASSWORD`: importer account with row read/write and temporary-table privileges, but no permanent DDL.

Legacy configurations without the new keys continue to use `MYSQL_USERNAME`. To provision the restricted accounts on an existing database volume, first back up the database, add the matching credentials to both config files, then recreate the database container so it receives the new environment and mounted provisioning script:

```bash
docker compose up -d --force-recreate db
docker compose exec db /docker-entrypoint-initdb.d/010-least-privilege-users.sh
docker compose up -d --force-recreate app
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

### Stage 1: Base App

Use this stage to confirm Docker, PHP, MariaDB, and cron work.

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
- The public application has no Container Management tool or Docker socket access.
- `/api/health` responds.
- Fuel charts may be empty until API keys are added and sync jobs run.

To test the optional admin service, set `CONTAINER_MANAGEMENT_TOKEN` in `.env`, then run:

```bash
docker compose --profile admin up -d --build admin docker-proxy
```

Open `http://localhost:18083/`. The Container Management tool should show `app`, `admin`, and `db`.

### Stage 2: Fuel Price Imports

Use this stage to test the Explore Prices tool, history graphs, station map markers, and route fuel-stop pricing.

1. Apply for the required API credentials.

| Source | Covers | Config keys | Access/sign-up URL |
| --- | --- | --- | --- |
| Fuel Prices Queensland | QLD | `FUEL_PRICES_QLD_SUBSCRIBER_TOKEN` | https://www.fuelpricesqld.com.au/#developers |
| South Australia Fuel Pricing Information Scheme | SA | `SA_FUEL_API_BASE_URL`, `SA_FUEL_SUBSCRIBER_TOKEN` | https://www.safuelpricinginformation.com.au/publishers.html |
| NSW Fuel API | NSW and TAS | `NSW_FUEL_API_KEY`, `NSW_FUEL_API_SECRET`, `NSW_FUEL_API_AUTHORIZATION_HEADER` | https://api.nsw.gov.au/Product/Index/22 |
| Victoria Servo Saver Public API | VIC | `VIC_SERVO_SAVER_API_KEY` | https://service.vic.gov.au/find-services/transport-and-driving/servo-saver/help-centre/servo-saver-public-api |
| MyFuel NT Third Party API | NT | `NT_MYFUEL_USERNAME`, `NT_MYFUEL_PASSWORD` | https://myfuelnt.nt.gov.au/ |

Useful portal links:

- API.NSW account/sign-up: https://api.nsw.gov.au/
- API.NSW Fuel API product: https://api.nsw.gov.au/Product/Index/22
- Fuel Prices Queensland developer information: https://www.fuelpricesqld.com.au/#developers
- South Australia publishers page and outbound API guide: https://www.safuelpricinginformation.com.au/publishers.html
- Servo Saver API information: https://service.vic.gov.au/find-services/transport-and-driving/servo-saver/help-centre/servo-saver-public-api
- MyFuel NT portal: https://myfuelnt.nt.gov.au/

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
NT_MYFUEL_USERNAME=your_nt_username
NT_MYFUEL_PASSWORD=your_nt_password
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
docker compose exec app env PYTHONPATH=src python3 -m nt_sync.cli all
```

5. Check logs if any source is empty.

```bash
docker compose exec app tail -f /var/log/fuelapi/fpq_sync.log
docker compose exec app tail -f /var/log/fuelapi/sa_sync.log
docker compose exec app tail -f /var/log/fuelapi/nsw_sync.log
docker compose exec app tail -f /var/log/fuelapi/vic_sync.log
docker compose exec app tail -f /var/log/fuelapi/nt_sync.log
```

Expected result:

- Fuel Prices has selectable states, regions, and fuels.
- Weekly and monthly graphs populate after price/history data is present.
- The station map shows clickable stations for the selected state, region, and fuel.
- Cron continues refreshing supported sources every 30 minutes.

If any fuel API credential is left at its sample placeholder value, the corresponding importer exits before making upstream requests. That keeps the stack quiet until real credentials are configured.

### Stage 3: Route Planning Without Local Map Tiles

Use this stage to test Photon autocomplete, Nominatim final/reverse geocoding, OSRM routing, route summaries,
turn-by-turn legs, and fuel-stop planning. This stage does not require the local basemap tile build, but it does
require routing/geocoding data.

1. Build OSRM data.

```bash
docker compose --profile routing-setup up osrm-customize
```

2. Prepare Photon once, then start the geocoders, updater, and OSRM.

```bash
sudo install -d -m 0755 -o 10001 -g 10001 var/docker/photon-input var/docker/photon-eval
docker compose --profile photon-setup run --rm --no-deps photon-refresh
docker compose --profile routing up -d photon photon-scheduler nominatim osrm-routed
```

3. Watch service status.

```bash
docker compose --profile routing ps
docker compose --profile routing logs -f photon
docker compose --profile routing logs -f photon-scheduler
docker compose --profile routing logs -f nominatim
docker compose --profile routing logs -f osrm-routed
```

Expected result:

- Origin and destination suggestions use Photon by default, with Nominatim fallback.
- Final unresolved searches and reverse geocoding continue to use Nominatim.
- Route Planning can build routes after OSRM data exists.
- Fuel stops are calculated from imported fuel data.
- The route map area may still lack a useful basemap until Stage 4 is complete.

Important notes:

- Nominatim Australia import is large and can take hours.
- OSRM setup downloads and processes the Australia OSM extract.
- On Synology or NFS-backed storage, Nominatim may need host-side ownership fixes described in `Local Runtime State`.

#### Photon autocomplete and automatic updates (Docker only)

Photon is FuelAU's default autocomplete provider. Like Nominatim, its data import and API run through Docker
Compose; Java and OpenSearch are not installed on the host. The same pinned `fuelau-photon:1.3.0` image is used by
the updater, importer, validation server, and long-running service. Set `GEOCODER_AUTOCOMPLETE_PROVIDER=nominatim`
in `config/app.env` for immediate rollback without deleting Photon data.

Prepare the persistent directories for the container's non-root UID, build the image, and run the initial refresh:

```bash
sudo install -d -m 0755 -o 10001 -g 10001 var/docker/photon-input var/docker/photon-eval
docker compose --profile photon-setup build photon-refresh
docker compose --profile photon-setup run --rm --no-deps photon-refresh
```

The updater retrieves the publisher's checksum first and skips the large download when the snapshot is unchanged.
For changed data, it verifies the complete snapshot, builds into a versioned directory, starts Photon against the
new index, checks health and a Brisbane query, and atomically updates the `current` symlink only after validation.
The current index and two rollback generations are retained by default.

Start and inspect the loopback-only service and weekly scheduler with:

```bash
docker compose --profile routing up -d photon photon-scheduler
docker compose --profile routing ps photon photon-scheduler
curl --fail http://127.0.0.1:12322/status
```

The scheduler checks for updates at 02:15 each Monday in Brisbane time. An unchanged snapshot does not restart
Photon. A changed snapshot is imported and validated before Photon is restarted, and scheduler output is written to
`var/docker/app-logs/photon_refresh.log`. Run `scripts/run-photon-refresh.sh` for an immediate manual check.

With both Photon and Nominatim running, compare them against the tracked Australian prefix, typo, postcode, remote,
and street corpus with:

```bash
python3 scripts/benchmark-geocoders.py --runs 3
```

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

After Stages 2-4 are running, test these route examples in the Route Planning tool with Diesel, a `60 L` tank, and `12 L/100km` economy:

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
- If the tank is too small to reach a safe stop, the planner should first try a contingency refill; only then should it show the extra external reserve needed to reach the destination safely, while continuing later legs.

## Services

- `app`: PHP Apache runtime, API/UI, cron jobs, and Docker management API.
- `db`: MariaDB 12.3.2 application database using a tested digest-pinned image.
- `nominatim`: Australia geocoding service using the digest-pinned `mediagis/nominatim:5.3.2` image.
- `photon`: default Australia autocomplete geocoder using the pinned Photon 1.3.0 image.
- `photon-refresh`: verified, atomic one-shot Photon snapshot refresh.
- `photon-scheduler`: weekly Docker CLI scheduler for Photon refreshes.
- `osrm-download`: downloads the Australia OSM PBF for OSRM.
- `osrm-extract`: builds the OSRM extract using the digest-pinned OSRM 26.8.0 Debian image.
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

Set `FUELAU_HOST_PROJECT_ROOT` in `.env` when the project is not checked out at `/opt/FuelAU`. The `map-scheduler`
and `photon-scheduler` containers run Docker Compose from inside Docker, so the project must be mounted at the same
absolute path that exists on the Docker host.

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
- `GEOCODER_AUTOCOMPLETE_PROVIDER` (`photon` by default; `nominatim` for rollback)
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
- `var/docker/photon-input`: last verified Australia Photon snapshot and checksums
- `var/docker/photon-eval`: active and rollback Photon indexes
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

That gives you the app, database, cron jobs, and the Explore Prices tool. Routing and the local map stack are optional profiles and must be started separately if you want route planning maps, geocoding, local basemap tiles, or station maps backed by local tiles.

Default local endpoints:

```text
Web UI:      http://localhost:18080/
Health API:  http://localhost:18080/api/health
OSRM:        http://localhost:15000/
Nominatim:   http://localhost:18081/
Photon:      http://localhost:12322/
```

OSRM, Nominatim, and Photon bind to `127.0.0.1` by default. Their ports are only active when those profile services
are running.

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

The basemap and Photon schedulers run weekly after their first manual builds. The routing setup and map build jobs
are one-shot preprocessing services and do not stay running.

## User Experience

The map-first Explore Prices tool has:

- One persistent MapLibre canvas shared with fuel-stop finding and route planning, so changing tools does not recreate the style or restart base-tile loading.
- `State`: limits fuel options and regions to that state.
- `Region`: major city/region selector for the selected state. Regions are currently seeded from Australian cities over roughly 20,000 people.
- `Fuel`: selected fuel type, persisted in a long-lived browser cookie.
- Weekly and monthly trend graphs.
- A recent snapshot table.
- A station map showing current prices for the selected state, region, and fuel. Click a station marker to see station name, address, selected fuel price, source/state, and update time.

The Route Planning tool has:

- Origin and reorderable destinations with Photon-backed suggestions and Nominatim fallback.
- Fuel type, tank fill size, and fuel economy controls.
- National route-fuel groups that map state-specific products into compatible choices: Unleaded, Premium Unleaded 95, Premium Unleaded 98, Diesel, Premium Diesel, LPG, CNG/NGV, LNG, and Hydrogen.
- `Cheapest Unleaded` includes every mapped petrol product, including ethanol blends; `Cheapest Diesel` includes conventional, premium, and biodiesel products. Fuel classes are never mixed.
- EV charge products remain available in fuel-price exploration but are excluded from the litre-based route planner.
- Direct-return or reverse-path return mode.
- A MapLibre route map using the local `/tiles/` basemap when the map stack is running.
- Fuel stops plotted on the route and a turn-by-turn breakdown.
- Coordinate-based Waze navigation links on recommended/planned fuel-stop cards, route breakdown rows, and map markers; mobile opens Waze when available and otherwise uses the Waze web experience.
- If a segment cannot reach a normal or safety stop, the planner can fall back to a smaller contingency refill before showing an external reserve requirement.
- Reserve warnings are shown under the route button in red, and the planner continues into later legs instead of stopping the whole trip.
- Route stations that are skipped because they have no government pricing or no price are listed under the route button with the station name, address, and exclusion reason.

Release acceptance checks and the presentation-only rollback procedure are documented in
[`docs/operations/ui-refresh-rollout.md`](docs/operations/ui-refresh-rollout.md).

## App API

The frontend should use the app-owned API under `http://localhost:18080/api/` rather than talking directly to Nominatim or OSRM on separate ports.

Current core endpoints:

- `/api/health`
- `/api/services/status`
- `/api/fuel/sources`
- `/api/fuel/current?source=all&limit=100`
- `/api/fuel/current?source=all&q=sydney&fuel=DL&state=NSW&lat=-33.8688&lon=151.2093&radius_km=5`
- `POST /api/fuel/route-candidates` with sampled route points, fuel, and corridor radius
- `/api/geo/autocomplete?q=Syd&limit=5` (Photon by default, Nominatim fallback)
- `/api/geo/search?q=Sydney&limit=5`
- `/api/geo/reverse?lat=-33.8688&lon=151.2093`
- `/api/route?coordinates=151.2093,-33.8688;151.2069,-33.8731`

Fuel response notes:

- `price` is normalized to cents-per-litre across sources.
- `price_raw` preserves the original stored source value.
- `source` currently supports `qld`, `sa`, `nsw`, `wa`, `tas`, `vic`, `nt`, and `all`.

## Fuel Prices Queensland

The Fuel Prices Queensland importer is in `src/fpq_sync`. It loads:

- brands
- geographic regions
- fuel types
- sites
- current prices
- price history
- sync run records

Prices run every 30 minutes. Reference data refreshes once daily under the same
non-overlapping lock:

```cron
0,30 * * * * ... python3 -m fpq_sync.cli prices
25 2 * * * ... python3 -m fpq_sync.cli daily-reference
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

## Western Australia FuelWatch RSS

The Western Australia importer is in `src/wa_sync`. It consumes the public FuelWatch RSS feed at `https://www.fuelwatch.wa.gov.au/fuelwatch/fuelWatchRSS?` and supports the documented `Product`, `StateRegion`, and `Day` parameters.

It loads:

- brands
- fuel types
- stations
- current prices
- price history
- sync run records

The importer runs once per day after the 2:30pm AWST release window:

```cron
35 16 * * * cd /var/www/html && PYTHONPATH=src /usr/bin/python3 -m wa_sync.cli all >> /var/log/fuelapi/wa_sync.log 2>&1
```

Manual sync:

```bash
docker compose exec app env PYTHONPATH=src python3 -m wa_sync.cli all
```

View sync logs:

```bash
docker compose exec app tail -f /var/log/fuelapi/wa_sync.log
```

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
- Every 30 minutes: Fuel Prices Queensland price sync to `/var/log/fuelapi/fpq_sync.log`.
- Daily at 02:25: Fuel Prices Queensland reference-data refresh under the same lock.
- Every 30 minutes at `:10` and `:40`: Northern Territory MyFuel sync to `/var/log/fuelapi/nt_sync.log`.
- Every 30 minutes at `:20` and `:50`: South Australia sync to `/var/log/fuelapi/sa_sync.log`.
- Every 30 minutes at `:15` and `:45`: NSW Fuel API sync to `/var/log/fuelapi/nsw_sync.log`.
- Every 30 minutes at `:05` and `:35`: Victoria Servo Saver sync to `/var/log/fuelapi/vic_sync.log`.
- Daily at 16:35 Brisbane time: Western Australia FuelWatch RSS sync to `/var/log/fuelapi/wa_sync.log`.

Every importer cron entry uses its own non-blocking `flock`; if a previous run is still active, the new invocation records that it skipped the overlap instead of running concurrently. Transient HTTP failures retry up to four times with bounded exponential backoff.

Current-price feeds are validated and loaded into connection-local staging tables before publication. For QLD, SA, NSW/TAS, VIC, and NT, one transaction compares chronologically ordered incoming events with the previously effective state, inserts history only for meaningful changes, and freshness-protects the current table. `last_seen_at` advances for successfully observed rows even when their provider timestamp and price are unchanged. Older incoming timestamps cannot overwrite newer live prices. Empty snapshots fail before the live transaction, and NSW incremental updates never expire naturally absent keys.

Missing rows in valid VIC and NT full snapshots become one availability transition with `is_available = 0` and `price = NULL`; repeated missing snapshots do not add more history. Other full snapshots expire missing current rows. WA deliberately retains one daily history observation per station/fuel.

Importer logs distinguish API rows fetched, current rows published, history changes inserted, unchanged observations skipped, missing rows expired, duration, and errors. Sync-run records are retained for 90 days. FPQ diagnostic staging rows are retained for seven days. Fuel price history is intentionally retained indefinitely because it powers the trend views; operators with storage constraints should archive it before introducing a local deletion policy.

Weekly and monthly charts reconstruct each station/fuel's effective state at Brisbane daily or monthly boundaries, including the last event before the requested range. This prevents change-only history from producing empty or change-biased buckets.

### Historical cleanup audit

Exact station/fuel/timestamp duplicates are prevented by unique indexes. Older installations may still contain
consecutive observations whose price and other meaningful state did not change. Audit those rows without changing
the database:

```bash
docker compose exec app env PYTHONPATH=src python3 -m history_cleanup
```

The audit:

- opens a read-only transaction for each provider;
- preserves the first state and every later price, availability, or collection-method transition;
- treats a return such as `A -> B -> A` as three meaningful states;
- reports exact candidate and retained row counts plus an approximate logical size reduction; and
- excludes WA because its daily observations are intentional.

Audit one or more providers with repeated `--provider` options, or produce machine-readable output:

```bash
docker compose exec app env PYTHONPATH=src python3 -m history_cleanup --provider nt --provider sa
docker compose exec app env PYTHONPATH=src python3 -m history_cleanup --json
```

The audit never deletes rows. Deletion requires a separate reviewed procedure, a verified backup, bounded batches,
and post-cleanup chart/API validation. Deleted InnoDB space remains reusable by MariaDB; returning it to the
filesystem requires a separately scheduled table rebuild.

For an approved cleanup, first create and validate a fresh `mariadb-dump`, then pause cron without stopping the web
application. The mutation commands require the backup to pass gzip and dump-marker validation, explicit providers,
and an exact confirmation phrase:

```bash
docker compose exec app service cron stop

docker compose run --rm --no-deps \
  -e FUELAU_CRON_ENABLED=false \
  -v "$PWD/var/backups:/backups:ro" \
  app env PYTHONPATH=src python3 -m history_cleanup stage-cleanup \
  --provider nt --provider sa --provider qld --provider vic \
  --backup /backups/fuelau-before-history-cleanup.sql.gz

docker compose run --rm --no-deps \
  -e FUELAU_CRON_ENABLED=false \
  -v "$PWD/var/backups:/backups:ro" \
  app env PYTHONPATH=src python3 -m history_cleanup delete-cleanup \
  --provider nt --provider sa --provider qld --provider vic \
  --backup /backups/fuelau-before-history-cleanup.sql.gz \
  --batch-size 50000 \
  --confirm-delete 'DELETE REDUNDANT HISTORY'

docker compose exec app env PYTHONPATH=src python3 -m history_cleanup
docker compose exec app service cron start
```

Deletion is resumable because staged IDs are removed only after the matching history transaction commits. Use
`--max-batches` to pace a run deliberately. Confirm that the candidate table is empty and the post-cleanup audit
reports zero candidates for every cleaned provider before dropping the staging table. Keep the backup until the
cleanup and any separately scheduled table rebuild have both been validated.

The weekly local map rebuild is handled by the `map-scheduler` Docker service, not by the app container cron.
Terrain starts at 03:05 and the basemap starts at 03:10 each Sunday in Brisbane time. The lightweight scheduler
image has no timezone database, so `MAP_SCHEDULER_TZ` uses the POSIX value `AEST-10` by default. Its output goes to
`var/docker/app-logs/terrain_build.log` and `var/docker/app-logs/map_build.log`.

The weekly Photon refresh is handled by `photon-scheduler` at 02:15 each Monday in Brisbane time. It downloads only
the publisher checksum when data is unchanged, and its output goes to `var/docker/app-logs/photon_refresh.log`.
Both Docker CLI schedulers require the Docker socket and the correctly resolved `FUELAU_HOST_PROJECT_ROOT` mount.

Useful checks:

```bash
docker compose exec app ps -ef | grep '[c]ron'
docker compose exec app tail -f /var/log/fuelapi/cron-heartbeat.log
docker compose exec app tail -f /var/log/fuelapi/fpq_sync.log
docker compose exec app tail -f /var/log/fuelapi/nt_sync.log
docker compose exec app tail -f /var/log/fuelapi/sa_sync.log
docker compose exec app tail -f /var/log/fuelapi/nsw_sync.log
docker compose exec app tail -f /var/log/fuelapi/vic_sync.log
docker compose exec app tail -f /var/log/fuelapi/wa_sync.log
docker compose --profile map logs -f map-scheduler
docker compose --profile routing logs -f photon-scheduler
tail -f var/docker/app-logs/photon_refresh.log
```

## Container Management

The base `app` service cannot access the Docker socket and always disables management. The opt-in `admin` profile starts:

- an `admin` web service on `127.0.0.1:18083` by default; and
- an internal-only HAProxy service with the Docker socket mounted read-only.

The proxy allowlists only container listing/logs, disk usage, project-container restart, stopped-container prune, and dangling-image prune paths. Docker version, container creation, exec, build, network, volume, and other daemon endpoints are denied. The admin secret is exchanged for a 30-minute HttpOnly, SameSite session; restart and prune requests also require a per-session CSRF token.

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

Because this UI can control allowlisted Docker operations on the host, only run the `admin` profile in a trusted local environment. Stop it when it is not needed:

```bash
docker compose --profile admin stop admin docker-proxy
```

## Routing Services

Routing and geocoding are profile-gated and do not need to run for core Fuel Prices Queensland imports.

Nominatim uses:

```text
PBF_URL=https://download.geofabrik.de/australia-oceania/australia-latest.osm.pbf
REPLICATION_URL=https://download.geofabrik.de/australia-oceania/australia-updates
```

Nominatim also runs in `UPDATE_MODE=continuous`, so after the initial import it keeps applying Geofabrik replication diffs.
Its import and API worker counts default to four and can be adjusted with `NOMINATIM_THREADS` and
`NOMINATIM_GUNICORN_WORKERS`.

OSRM uses the same Australia PBF and stores generated files in `var/docker/osrm-data`.

Custom OSRM profile template:

- `config/osrm/fuelmiser.car.profile.template.lua` is a tracked scaffold for a custom OSRM Lua profile.
- The Compose setup mounts it as `/opt/fuelmiser.car.lua` and the file wraps the stock `/opt/car.lua` from the OSRM image.
- The profile is tuned as a hybrid approximation. The exact 30 km switch still has to be selected outside OSRM because profiles are fixed at extraction time.
- Review the tracked access, speed, and penalty overrides before rebuilding OSRM data.

Build OSRM data:

```bash
docker compose --profile routing-setup up osrm-customize
```

Start routing services after OSRM data exists:

```bash
docker compose --profile routing up -d photon photon-scheduler nominatim osrm-routed
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
- `terrain-build`: one-shot terrain tile builder that writes `terrain.mbtiles`
- `map-server`: local TileServer GL light instance that serves the rebuilt tiles and style JSON
- `map-scheduler`: weekly Docker CLI scheduler that runs the map build through Compose

The `map-server` healthcheck now probes both the style document and a real Australia vector tile so an empty `australia.mbtiles` file is marked unhealthy instead of rendering a blank map. Basemap and terrain rebuilds are written to temporary files, validated, and atomically published so a failed rebuild leaves the currently served map intact. The weekly scheduler restarts `map-server` only after a successful basemap publication.

The app exposes the tile server config at `/api/map/config`. The default local settings are:

- `MAP_TILE_SERVER_URL=/tiles`
- `MAP_TILE_STYLE=topo-3d`

The `topo-3d` style keeps the road hierarchy from the preview map, adds terrain shading, and enables 3D buildings with contour overlays in the browser. The older `basic-preview` style is still available if you want a simpler map.

Contour overlays now use a local terrain MBTiles source served through the map tile stack instead of the public elevation tile endpoint.

The browser should load map assets from the app host at `/tiles/`, which Apache reverse proxies to the internal `map-server` container.

Rebuild the basemap manually:

```bash
docker compose --profile map-setup run --rm map-build
docker compose --profile map restart map-server
```

Rebuild the local terrain tiles manually:

```bash
docker compose --profile map-setup run --rm terrain-build
docker compose --profile map restart map-server
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
docker compose exec app env PYTHONPATH=src python3 -m nt_sync.cli all
docker compose --profile routing-setup up osrm-customize
docker compose --profile routing up -d nominatim osrm-routed
docker compose --profile map-setup run --rm map-build
docker compose --profile map up -d map-server map-scheduler
docker compose --profile admin up -d admin docker-proxy
docker compose --profile routing ps
docker compose down
```

## Development Quality Checks

Run the dependency-light regression suite:

```bash
tests/run
```

Install the PHP development tools and run the full PHP checks:

```bash
composer install
composer test
composer analyse
composer analyse:new
composer format:check
```

Run the Python and shell checks used by CI:

```bash
PYTHONPATH=src python3 -m unittest discover -s tests/python -p 'test_*.py' -v
ruff check src tests/python scripts/build-terrain-mbtiles.py scripts/benchmark-geocoders.py
mypy
find docker scripts tests -type f \( -name '*.sh' -o -name run \) -print0 | xargs -0 shellcheck
docker compose --env-file .env.sample --profile admin config --quiet
node --check public/resources/app.js
```

GitHub Actions also builds the application, map-builder, and Photon images. `public/index.php` is a bootstrap-only entry point; HTTP dispatch and controllers live in `src/web.php` and `src/api.php`, immutable request DTOs live in `src/request.php`, and the page markup lives in `templates/app.php`. Browser CSS and JavaScript live in `public/resources/app.css` and `public/resources/app.js`; the remaining inline scripts contain the pre-render theme bootstrap and server-rendered configuration, both protected by a per-request CSP nonce. New HTTP modules are checked separately at PHPStan level 9 without raising the existing codebase-wide level or introducing a baseline.

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
