#!/usr/bin/env sh
set -eu

APP_ID="pettycash"
NC_CONTAINER="${PETTYCASH_NC_CONTAINER:-docker-nextcloud-1}"
DB_CONTAINER="${PETTYCASH_DB_CONTAINER:-docker-db-1}"

fail() { printf 'preflight: %s\n' "$1" >&2; exit 1; }
command -v docker >/dev/null 2>&1 || fail "docker is required"
docker inspect -f '{{.State.Running}}' "$NC_CONTAINER" 2>/dev/null | grep -qx true || fail "$NC_CONTAINER is not running"

docker exec --user www-data "$NC_CONTAINER" php -r '
$version = PHP_VERSION;
if (version_compare($version, "8.2.0", "<") || version_compare($version, "8.6.0", ">=")) {
    fwrite(STDERR, "Unsupported PHP $version; expected >=8.2 <8.6\n"); exit(1);
}
printf("PHP %s OK\n", $version);
'

NC_VERSION="$(docker exec --user www-data "$NC_CONTAINER" php occ status --output=json | sed -n 's/.*"versionstring":"\([^"]*\)".*/\1/p')"
case "$NC_VERSION" in 34.*) printf 'Nextcloud %s OK\n' "$NC_VERSION" ;; *) fail "expected Nextcloud 34.x, found $NC_VERSION" ;; esac

docker exec "$NC_CONTAINER" test -d "/var/www/html/custom_apps/$APP_ID" || fail "pettycash app mount is missing"
docker exec --user www-data "$NC_CONTAINER" php occ app:list | grep -q "$APP_ID" || printf 'preflight: app is mounted but not enabled yet\n'

if docker inspect -f '{{.State.Running}}' "$DB_CONTAINER" 2>/dev/null | grep -qx true; then
	docker exec "$DB_CONTAINER" sh -c 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND (table_name LIKE '\''oc_pcash_%'\'' OR table_name = '\''oc_migrations'\'') ORDER BY table_name"' || fail "read-only database inspection failed"
else
	printf 'preflight: database container not running; skipped legacy table inventory\n'
fi

printf 'preflight: checks completed without modifying application or legacy data\n'
