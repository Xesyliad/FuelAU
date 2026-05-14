# FuelAU Pre-Go-Live Review Todo

Scope: review the application and deployment setup in this repository before launch.
Do not inspect or commit the gitignored `.env` file contents directly. Use the tracked sample env files and runtime behavior as the basis for review.

## 0. Review Rules

- [ ] Confirm the exact release scope: base app only, or base app plus routing and map profiles.
- [ ] Record the review date, reviewer, and codebase revision.
- [ ] Define the pass/fail bar for go-live: block on security or data-loss issues, document all non-blocking risks.
- [ ] Keep a findings log with file references, severity, impact, and recommended fix.

## 1. Repository and Runtime Inventory

- [ ] Map the application entrypoints and runtime surfaces.
- [ ] Review `public/index.php`, `setup.php`, `src/bootstrap.php`, `src/http.php`, `src/docker.php`, `src/fuel.php`, `src/routing.php`, and the sync CLIs under `src/*_sync/cli.py`.
- [ ] Review `docker-compose.yml`, `docker/app/Dockerfile`, `docker/app/entrypoint.sh`, `docker/app/apache-vhost.conf`, and `docker/cron.d/fuelau`.
- [ ] Review `README.md`, `config/app-sample.env`, and `config/mysql-sample.env` for operator assumptions and setup correctness.
- [ ] Identify any code paths that reach external services, the database, the filesystem, cron, or the Docker socket.

## 2. Secrets and Configuration Review

- [ ] Confirm all required secrets are sourced from env files or runtime env, not hardcoded.
- [ ] Verify `.env.sample` and `config/*-sample.env` list every required variable and do not leak production values.
- [ ] Check config parsing in `src/bootstrap.php` for unsafe assumptions, malformed lines, whitespace handling, and missing-key behavior.
- [ ] Verify the app fails closed when required config is missing.
- [ ] Review whether config values are ever echoed into logs, UI, or error messages.
- [ ] Confirm filesystem paths for config files are constrained to the intended locations.

## 3. Authentication and Authorization

- [ ] Identify every action that changes container, database, or filesystem state.
- [ ] Verify the container management UI cannot be used by an unauthorized user to control Docker resources.
- [ ] Check whether the app exposes any admin actions without auth, CSRF protection, or origin checks.
- [ ] Review whether destructive operations require explicit confirmation and server-side authorization.
- [ ] Confirm health and status endpoints expose only what is necessary.

## 4. Docker Socket and Container Management

- [ ] Review all Docker API calls in `src/docker.php`.
- [ ] Confirm socket access is limited to the intended container and cannot be abused for arbitrary host control.
- [ ] Check request construction, response parsing, chunked decoding, and error handling for robustness.
- [ ] Verify container action endpoints only target the current Compose project.
- [ ] Review whether user-controlled values can influence Docker requests, container names, filters, or commands.
- [ ] Confirm restart, stop, prune, and log retrieval flows are safe and bounded.

## 5. Input Validation and Output Safety

- [ ] Review every request parameter consumed by the web UI.
- [ ] Verify search, route planning, and filter inputs are validated server-side.
- [ ] Check for injection risks in SQL, shell commands, HTTP URLs, Docker paths, and template output.
- [ ] Review all HTML output for escaping and any places where raw data is injected into the page.
- [ ] Confirm JSON responses are structured, predictable, and do not leak internals.
- [ ] Review any file path, service name, or route parameter handling for traversal or unexpected expansion.

## 6. Database Correctness and Data Integrity

- [ ] Review schema creation and initialization in `setup.php`.
- [ ] Confirm imports are idempotent and safe to rerun.
- [ ] Check for transaction use where partial writes would cause inconsistent state.
- [ ] Review deduplication, upsert logic, and stale record cleanup.
- [ ] Verify time zone handling, timestamp normalization, and date-range queries.
- [ ] Check that database credentials, hostnames, and ports are not assumed incorrectly across environments.

## 7. External HTTP and API Handling

- [ ] Review upstream HTTP clients in `src/http.php` and the sync modules.
- [ ] Confirm request timeouts, error handling, and retry behavior are reasonable.
- [ ] Check response decoding, schema validation, and handling of malformed JSON.
- [ ] Review all upstream URL construction for SSRF risk or unsafe interpolation.
- [ ] Confirm upstream failures degrade gracefully without breaking the app shell.
- [ ] Verify rate limits, API key handling, and backoff behavior for the fuel data sources.

## 8. Security Hardening

- [ ] Review Apache and PHP runtime configuration for unnecessary exposure.
- [ ] Check that only required extensions, packages, and modules are installed in the app image.
- [ ] Confirm the container does not run with more privilege than necessary.
- [ ] Review whether the app can access the Docker socket, host paths, or mounted logs more broadly than intended.
- [ ] Validate file permissions on mounted volumes and generated artifacts.
- [ ] Check for insecure defaults in Compose, such as exposed ports, healthcheck behavior, and profile assumptions.
- [ ] Review for command injection, path injection, header injection, and unsafe deserialization.
- [ ] Confirm error pages, stack traces, and logs do not reveal secrets or internal infrastructure details.

## 9. Performance and Scalability

- [ ] Profile startup cost for the base app and each optional profile.
- [ ] Review any expensive synchronous work on the request path.
- [ ] Check database query patterns for N+1 behavior, missing indexes, or unbounded result sets.
- [ ] Review map rendering and route planning for large input sizes.
- [ ] Check cron jobs and sync jobs for overlap, contention, and excessive frequency.
- [ ] Review memory and disk growth of logs, caches, and imported datasets.
- [ ] Confirm upstream requests and DB queries have bounded timeouts.

## 10. Reliability and Failure Modes

- [ ] Review what happens when MariaDB is down, slow, or partially initialized.
- [ ] Review what happens when Docker is unavailable or the socket is missing.
- [ ] Review what happens when upstream fuel APIs fail or return partial data.
- [ ] Review what happens when routing/map services are absent but the app is still used.
- [ ] Verify background jobs fail visibly and do not silently corrupt state.
- [ ] Check for race conditions around startup, health checks, and one-shot setup jobs.

## 11. Operations and Observability

- [ ] Review logging format, location, rotation, and retention.
- [ ] Confirm logs are useful for incident response but do not overexpose sensitive data.
- [ ] Verify health endpoints reflect real readiness, not just process liveness.
- [ ] Review the cron heartbeat and sync job observability.
- [ ] Confirm the README operational steps are accurate and complete.
- [ ] Review backup and restore assumptions for MariaDB and generated artifacts.

## 12. Dependency and Container Supply Chain Review

- [ ] Review base images in `docker-compose.yml` and `docker/app/Dockerfile`.
- [ ] Check whether image tags are pinned tightly enough for launch risk.
- [ ] Review any `latest` tags and decide whether they are acceptable before go-live.
- [ ] Check package installs in the Dockerfile for unnecessary attack surface.
- [ ] Verify third-party JS/CSS assets in `public/resources` are intentional and current.

## 13. Functional Verification

- [ ] Start the base stack from a clean state.
- [ ] Run `php setup.php` successfully.
- [ ] Confirm the home page loads and the main tabs render.
- [ ] Confirm `/api/health` behaves as expected.
- [ ] Confirm Fuel Prices, Route Planning, and Container Management work with realistic data.
- [ ] Validate the optional routing profile end to end.
- [ ] Validate the optional map profile end to end.
- [ ] Test the app with missing env values, failed upstreams, and absent optional services.

## 14. Testing and Regression Review

- [ ] Identify existing automated tests and their coverage gaps.
- [ ] Add tests for the highest-risk code paths if coverage is missing.
- [ ] Run static analysis or linting tools if available.
- [ ] Run manual verification for the security-sensitive and operator-sensitive flows.
- [ ] Document any untested areas as explicit residual risk.

## 15. Final Review Output

- [ ] Produce a findings list sorted by severity.
- [ ] Separate blockers from non-blockers.
- [ ] For each finding, include file path, exact behavior, impact, and recommended remediation.
- [ ] Record the final go/no-go decision with rationale.
- [ ] List any follow-up work that can be deferred safely after launch.

## Suggested Execution Order

1. Inventory and config review.
2. Docker socket, auth, and input/output safety review.
3. Database and external HTTP handling review.
4. Security hardening review.
5. Performance, reliability, and observability review.
6. Functional verification and regression testing.
7. Final findings report and go/no-go decision.
