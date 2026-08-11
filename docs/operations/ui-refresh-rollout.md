# Map-first UI rollout and rollback

The map-first interface uses one persistent MapLibre instance for Explore Prices, Fuel Stop Finder, and Route Planning. Tool changes only change the visible workflow layers and panel; they must not recreate the map or call `setStyle()`.

## Release checks

Before deploying an interface change:

1. Run the JavaScript syntax, PHPUnit, PHP regression, Python, PHPStan, and protected admin contract checks.
2. Confirm the rendered page contains one `#fuel-map` element and no public tab roles.
3. Check all three tools with keyboard-only navigation. Arrow, Home, and End keys should move between tool buttons, while normal Tab navigation must still reach every tool.
4. Check System, Light, and Dark themes, including a live system-theme change.
5. Check reduced-motion mode. Sheet and map camera transitions should become immediate.
6. Check desktop, narrow mobile, short viewport, 200% zoom, and safe-area layouts.
7. Leave each tool idle for ten minutes with the browser network panel recording. After visible map tiles settle, there must be no repeating application API requests; changing away from Explore Prices must cancel its pending viewport request.
8. Verify route lines, per-leg colours, station details, fuel-stop markers, autocomplete, Waze links, and error/stale-result states.

The repository architecture tests enforce the single-map design, semantic tool navigation, hidden inactive regions, reduced-motion handling, deduplicated viewport requests, and the absence of periodic frontend polling.

## Deployment

Build both web images so the public and protected entry points use the same assets:

```bash
docker compose build app admin
docker compose up -d app
docker compose ps app
curl --fail --silent --show-error http://127.0.0.1:18080/api/health
```

The `admin` profile is intentionally left stopped unless protected container management is required.

## Rollback

UI releases do not change map, route, geocoder, or fuel-price data. Roll back only the application image:

1. Identify the last-known-good `fuelau-app` image or rebuild the previous Git commit.
2. Point the `app` service at that image and recreate only `app`.
3. Confirm `/api/health`, then load each public tool once.

Do not remove database volumes, map tiles, Photon indexes, Nominatim data, or OSRM files for a presentation-only rollback. If the protected admin UI was deployed too, rebuild or roll back `fuelau-admin` from the same Git commit before it is next started.
