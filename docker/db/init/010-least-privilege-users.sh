#!/bin/sh
set -eu

if [ -z "${MYSQL_APP_USERNAME:-}" ] || [ -z "${MYSQL_APP_PASSWORD:-}" ] \
    || [ -z "${MYSQL_SYNC_USERNAME:-}" ] || [ -z "${MYSQL_SYNC_PASSWORD:-}" ]; then
    echo "FuelAU least-privilege database users are not configured; using legacy credentials."
    exit 0
fi

for identifier in "$MYSQL_DATABASE" "$MYSQL_APP_USERNAME" "$MYSQL_SYNC_USERNAME"; do
    case "$identifier" in
        ''|*[!A-Za-z0-9_]*)
            echo "Unsafe database or user identifier: $identifier" >&2
            exit 1
            ;;
    esac
done

hex_value() {
    printf '%s' "$1" | od -An -v -tx1 | tr -d ' \n'
}

app_password_hex="$(hex_value "$MYSQL_APP_PASSWORD")"
sync_password_hex="$(hex_value "$MYSQL_SYNC_PASSWORD")"

mariadb --protocol=socket -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
SET @app_password = CONVERT(0x${app_password_hex} USING utf8mb4);
SET @app_create = CONCAT(
    'CREATE USER IF NOT EXISTS ''${MYSQL_APP_USERNAME}''@''%'' IDENTIFIED BY ',
    QUOTE(@app_password)
);
PREPARE app_statement FROM @app_create;
EXECUTE app_statement;
DEALLOCATE PREPARE app_statement;

SET @sync_password = CONVERT(0x${sync_password_hex} USING utf8mb4);
SET @sync_create = CONCAT(
    'CREATE USER IF NOT EXISTS ''${MYSQL_SYNC_USERNAME}''@''%'' IDENTIFIED BY ',
    QUOTE(@sync_password)
);
PREPARE sync_statement FROM @sync_create;
EXECUTE sync_statement;
DEALLOCATE PREPARE sync_statement;

GRANT SELECT ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_APP_USERNAME}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES
    ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_SYNC_USERNAME}'@'%';
FLUSH PRIVILEGES;
SQL
