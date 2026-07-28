# FuelAU Fix Plan

Each phase should be delivered as a separate, reviewable change. Urgent correctness and data-loss risks come before architectural refactoring.

| Order | Priority | Phase | Main outcome |
|---:|---|---|---|
| 0 | Prerequisite | ✅ Regression baseline — completed | Reproduce existing failures automatically |
| 1 | Critical | ✅ Fuel filtering — completed | Correct state/source results without HTTP 500s |
| 2 | Critical | ✅ Atomic map builds — completed | Failed rebuilds cannot damage live maps |
| 3 | High | ✅ Route lookup optimization — completed | Replace hundreds or thousands of browser requests |
| 4 | High | ✅ API correctness — completed | Proper validation, limits, and status codes |
| 5 | High | ✅ Docker/security hardening — completed | Remove Docker socket from the public app |
| 6 | High | ✅ Import reliability — completed | Prevent overlapping or partially published imports |
| 7 | Medium | ✅ Database migrations — completed | Make schema upgrades reliable and auditable |
| 8 | Medium | ✅ Architecture and tooling — completed | Split the monolith and establish CI/static analysis |
| 9 | High | ✅ Post-deployment reliability — completed | Stop hidden-map traffic and improve dashboard responsiveness |

## Phase 0 — Regression baseline ✅

Add focused tests before changing behavior:

- Fuel source/state/fuel combinations.
- `source=all` state isolation.
- Empty-fuel requests.
- Geocoding result limits.
- Invalid coordinates and waypoint counts.
- Rate-limit responses.
- Database health responses.
- Importer SQL-generation tests.
- Map-build failure tests.

Capture current performance metrics: route request counts, fuel-query duration, and map-build publication behavior.

Exit criteria: every confirmed defect has a failing automated test.

Status: completed. The dependency-light PHP and Python regression harness captures the confirmed failures and is run with `tests/run`.

## Phase 1 — Fix fuel filtering ✅

Primary files: `src/fuel.php` and `public/index.php`.

- Whitelist valid sources and states.
- Add missing VIC source inference.
- Determine applicable sources centrally from the requested source/state.
- Bind only parameters present in each query.
- Ensure every source-specific current and historical query respects state.
- Return HTTP 400 for unsupported source/state values.
- Filter unavailable or null NT prices at the query boundary where appropriate.

Exit criteria:

- QLD, SA, and VIC requests without a fuel filter return HTTP 200.
- `source=all&state=NSW` contains only NSW data.
- Current and historical APIs behave consistently.
- The complete source × state × fuel test matrix passes.

Status: completed. Unit tests cover validation and provider selection; database integration tests cover inferred and explicit-all queries for every supported state plus NSW historical isolation.

## Phase 2 — Publish map builds atomically ✅

Primary files: `scripts/build-terrain-mbtiles.py` and `docker-compose.yml`.

- Build into a temporary file on the same filesystem.
- Add a rebuild lock to prevent overlapping jobs.
- Validate SQLite integrity, metadata, expected zoom levels, and minimum tile counts.
- Atomically rename the verified file into place.
- Clean up failed temporary builds.
- Apply equivalent staged publication to Planetiler output.
- Reload or restart the map server only after successful publication.

Exit criteria: an injected download or build failure leaves the existing live MBTiles checksum unchanged and the map service healthy.

Status: completed. Terrain and basemap builders use exclusive locks, validate temporary databases, and publish with same-filesystem atomic replacement. Injected failures preserve the previous output, and the scheduler restarts the map server only after successful publication.

## Phase 3 — Optimize route-station discovery ✅

Primary files: `public/index.php` and `src/fuel.php`.

- Add a server-side route-station candidate endpoint.
- Accept a bounded route polyline or corridor instead of many individual radius requests.
- Use indexed latitude/longitude bounding boxes before exact distance calculations.
- Query each applicable fuel source once per route or corridor section.
- Deduplicate and rank candidates on the server.
- Cache repeated route/fuel lookups briefly.
- Reduce client budgets from thousands of calls to a small fixed number.
- Preserve current reserve, contingency, and repeated-stop behavior with fixtures.

Exit criteria: representative long routes require tens of requests at most, with materially lower database and OSRM load.

Status: completed. Candidate collection uses one cached corridor request, source queries use coordinate bounds before exact distance filtering, coordinate indexes are provisioned, and client route/fuel budgets cap each request class at 50.

## Phase 4 — Correct API contracts ✅

Primary files: `src/routing.php` and `public/index.php`.

- Clamp and apply geocoding limits.
- Validate latitude/longitude ranges.
- Limit query length and waypoint count.
- Introduce typed exceptions for validation, rate limits, and upstream failures.
- Map them consistently:
  - Invalid input → HTTP 400 or 422.
  - Rate limit → HTTP 429 with `Retry-After`.
  - Upstream failure → HTTP 502 or 503.
  - Internal fault → HTTP 500.
- Restrict endpoints to their intended HTTP methods.
- Return HTTP 503 from health checks when required dependencies fail.
- Add an application healthcheck to Compose.

Exit criteria: malformed routes return HTTP 400 or 422, geocoding limits are exact, rate limits return HTTP 429, and database failure makes the app unhealthy.

Status: completed. Typed validation, throttling, and upstream exceptions now map to stable HTTP responses; geocoding and route inputs are bounded; endpoint methods are restricted; database failure returns an unhealthy HTTP 503; and Compose probes the application health endpoint.

## Phase 5 — Harden Docker management and exposure ✅

Primary files: `docker-compose.yml`, `src/docker.php`, and `public/index.php`.

- Remove the Docker socket from the normal public application container.
- Move management into an opt-in admin profile or service.
- Place a restricted Docker socket proxy between the service and daemon.
- Make the application bind address explicit and configurable; default to loopback.
- Replace long-lived local-storage tokens with short-lived authenticated sessions.
- Add CSRF protection to restart and prune actions.
- Add frame, MIME-sniffing, referrer, and permissions headers.
- Introduce a strict Content Security Policy after inline assets are extracted.

Exit criteria: the normal app cannot access the Docker socket, and Docker mutations require an authenticated, protected admin path.

Status: completed. The public app is loopback-bound by default and has neither a Docker socket nor management routes enabled. An opt-in admin service uses an internal, path-allowlisted Docker proxy, 30-minute HttpOnly sessions, per-session CSRF protection, browser security headers, and a nonce-based script policy. Proxy allow/deny behavior, session cookies, CSRF rejection, Compose configuration, rendered JavaScript, and the application image build are verified.

## Phase 6 — Make importers reliable ✅

Primary files: `src/*_sync/cli.py` and `docker/cron.d/fuelau`.

- Add per-importer `flock` or database advisory locks.
- Add bounded exponential backoff for transient API failures.
- Load complete snapshots into staging tables.
- Validate row counts, keys, timestamps, and reference coverage.
- Publish current tables atomically.
- Reject older records when a newer current record exists.
- Ensure missing stations and prices are intentionally expired rather than retained forever.
- Record start, completion, duration, skipped overlap, and validation failures.
- Add retention policies for history, staging data, and sync logs.

Exit criteria: overlapping jobs cannot run, injected mid-import failures do not alter current data, and older API records cannot regress current prices.

Status: completed. All importer schedules use provider-specific non-blocking locks; HTTP calls retry transient failures with bounded backoff; current-price feeds stage, validate, freshness-merge, expire, and atomically publish full snapshots; NSW incremental updates do not expire absent keys; and run events record starts, outcomes, counts, and durations with documented retention. Unit tests cover every provider, while MariaDB integration verifies freshness preservation and snapshot expiry.

## Phase 7 — Implement real schema migrations ✅

Primary file: `setup.php`.

- Create ordered migration files or functions.
- Read the installed schema version before applying changes.
- Apply each migration transactionally where MariaDB permits.
- Record a version only after successful completion.
- Add schema assertions using `information_schema`.
- Provide an explicit migration from existing version 7 installations.
- Document backup and rollback procedures.

Exit criteria: upgrades work from a representative older schema, failed migrations do not falsely advance the recorded version, and repeated execution is safe.

Status: completed. Ordered, checksummed migrations run under an advisory lock and record only after callbacks and `information_schema` assertions succeed. Fresh and legacy-v7 MariaDB upgrades, repeat execution, and injected rollback behavior are verified. Fresh installs can provision separate schema-migrator, read-only app, and row-write sync credentials while legacy credentials remain compatible; backup and restore procedures are documented.

## Phase 8 — Refactor and establish quality tooling ✅

- ✅ Split `public/index.php` into routing/controllers, templates, CSS, and JavaScript modules.
- ✅ Separate fuel queries, routing, Docker operations, and HTTP responses into services.
- ✅ Introduce request/filter DTOs instead of loosely structured arrays.
- ✅ Add Composer configuration, PHPUnit, PHPStan, and PHP-CS-Fixer.
- ✅ Add Python tests, Ruff, and type-checking configuration.
- ✅ Add ShellCheck and Dockerfile/Compose checks.
- ✅ Add CI covering tests, static analysis, rendered JavaScript syntax, and container builds.
- ✅ Pin container versions or digests and align local and container PHP versions.
- ✅ Cache fuel options and source summaries to avoid repeated aggregate queries.

Exit criteria: CI is mandatory and green, the public entry point only dispatches requests, and all extracted modules retain regression coverage.

Status: completed. `public/index.php` is now a seven-line bootstrap and dispatcher. Page markup, browser assets, application dispatch, and API controllers are separate modules; immutable endpoint request DTOs normalize and validate raw HTTP input before handing documented array shapes to the existing SQL services. The extracted HTTP modules pass PHPStan level 9, focused PHPUnit architecture and DTO coverage, the full regression suite, rendered JavaScript validation, and the CI-equivalent container checks.

## Phase 9 — Post-deployment reliability ✅

- ✅ Break the Fuel Prices map `moveend`/render/resize refresh cycle.
- ✅ Cancel and deduplicate superseded viewport and autocomplete requests.
- ✅ Dispose the Fuel Prices map while its tab is hidden and recreate it on return.
- ✅ Add indexed bounding-box prefilters and direct numeric fuel-ID predicates to history queries.
- ✅ Cache repeated history and successful geocoding results with bounded TTLs.
- ✅ Preserve upstream HTTP status details and retry transient Nominatim failures.
- ✅ Exclude stale and implausible current-price records from dashboard rendering.
- ✅ Add a FuelAU SVG favicon.
- ✅ Add content-hash versions to application assets so deployments bypass stale CDN copies.
- ✅ Apply schema migration 8 to provision station coordinate indexes.
- ✅ Add regression coverage for the new behavior and deploy the rebuilt application image.

Exit criteria: inactive map tabs generate no background traffic, the representative QLD history query completes in under one second before application caching, the previously failing `Gold` search succeeds, stale/out-of-range current prices are not rendered, and production health/browser smoke tests pass.

Status: completed. Direct database tests reduced representative uncached QLD weekly/monthly history queries from approximately 5.3/33.5 seconds to 0.75/0.14 seconds. Production browser verification recorded zero requests during an eight-second inactive-map observation window, no JavaScript errors, correct map recreation, active favicon and versioned assets, and successful health, history, and geocoding responses.
