# Database backup and restore verification

FuelAU stores verified MariaDB backups in the private `fuelau-production-backups` S3 bucket in Sydney. The host job
creates a daily backup, validates its compressed stream and expected schema markers, uploads it with a SHA-256
checksum, and records the successful completion in `var/docker/backup-status.json`.

## Restore drill procedure

1. Download a current `database/daily/` object from S3 with checksum verification enabled.
2. Confirm the downloaded file passes `gzip -t` and `history_cleanup.verify_backup`.
3. Start an isolated MariaDB 11.4 container with no published ports and memory-backed database storage.
4. Stream the decompressed SQL into an empty drill database.
5. Compare the complete table list, exact migration versions and checksums, and representative row counts with
   production.
6. Run `mariadb-check` against every restored table.
7. Stop the drill container and remove the temporary downloaded copy.

Never restore a drill over the production database. A production recovery must stop writers, preserve the failed
database for diagnosis, and use a separately reviewed recovery window.

## Verified drill — 2026-08-08

- Source: `database/daily/2026/08/08/fuelau-production.sql.gz`
- S3 version: `IdYqwKMt2Uy9wlMZ84qtPkUvrnen02Do`
- Bytes: `27,202,756`
- SHA-256: `4d4e925f5fab7f6123ccdf23d064ff311d5ef9285f689a7e8c79393e77cba73a`
- Restore target: isolated MariaDB 11.4 container with no network access and tmpfs storage
- Result: all 42 application tables restored and passed `mariadb-check`
- Migrations: all 10 versions, names, and checksums matched production exactly
- Row validation: all snapshot/reference tables matched; production had only expected additional cron, sync-run,
  NSW-history, and SA-history rows written after the backup timestamp

Result: passed.
