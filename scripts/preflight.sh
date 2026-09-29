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
	# The legacy table inventory.
	#
	# This step was inert. It read `MYSQL_USER`, `MYSQL_PASSWORD` and `MYSQL_DATABASE`, which the
	# official MariaDB image never sets -- it publishes `MARIADB_*` -- so the client was invoked as
	# `mariadb -u -p` with an empty user and an empty password, the server answered
	# `ERROR 1045 (28000): Access denied for user -p@localhost`, the `|| fail` branch fired, and
	# preflight exited 1 on every invocation, including the ones whose whole job was to confirm the
	# environment was healthy. A gate that always fails is not a gate.
	#
	# Two further things are now true that were not before. The credentials are read from the
	# database container's own environment and never appear on this host's command line, so they
	# cannot reach this process's argument list or the shell history of whoever ran preflight. And the
	# inventory is *reported* rather than merely attempted.
	#
	# The two prefixes are queried separately and neither is chosen. `DECLARED_PREFIX` is what
	# `spec.md:333` states the legacy tables are; it matches nothing in this database. `OBSERVED_PREFIX`
	# is what the database actually carries, measured rather than assumed: with the declared filter the
	# old step reported one table, and the observed prefix returns eleven more. Reconciling the
	# artifacts is T252's, and a preflight that silently adopted one prefix would hide the very
	# disagreement that reconciliation exists to settle.
	#
	# Note the SQL below uses double-quoted string literals and contains no single quote: the whole
	# body is a single-quoted `sh -c` argument, and a single quote inside it would terminate that
	# quoting. The original draft did exactly that and failed to parse.
	INVENTORY="$(docker exec "$DB_CONTAINER" sh -c '
		user="${MARIADB_USER:-$MYSQL_USER}"
		pass="${MARIADB_PASSWORD:-$MYSQL_PASSWORD}"
		db="${MARIADB_DATABASE:-$MYSQL_DATABASE}"
		if [ -z "$db" ]; then
			printf "preflight: no database name in MARIADB_DATABASE or MYSQL_DATABASE\n" >&2
			exit 0
		fi
		mariadb -u"$user" -p"$pass" "$db" -N -e "
			SELECT CONCAT(\"DECLARED|\", table_name) FROM information_schema.tables
				WHERE table_schema = DATABASE() AND table_name LIKE \"oc\_pcash\_%\"
			UNION ALL
			SELECT CONCAT(\"OBSERVED|\", table_name) FROM information_schema.tables
				WHERE table_schema = DATABASE() AND table_name LIKE \"oc\_pc\_%\"
			UNION ALL
			SELECT CONCAT(\"MIGRATIONS|\", table_name) FROM information_schema.tables
				WHERE table_schema = DATABASE() AND table_name = \"oc_migrations\"
			ORDER BY 1;"
	' || fail "read-only database inspection failed")"

	DECLARED_COUNT=0
	OBSERVED_COUNT=0

	if [ -z "$INVENTORY" ]; then
		# A finding, not a pass: this database carries no legacy tables and no migration table at all.
		printf 'preflight: WARNING - the legacy table inventory is empty; this database carries none of the tables this gate looks for\n' >&2
	else
		printf '%s\n' "$INVENTORY"
		DECLARED_COUNT="$(printf '%s\n' "$INVENTORY" | grep -c '^DECLARED|' || true)"
		OBSERVED_COUNT="$(printf '%s\n' "$INVENTORY" | grep -c '^OBSERVED|' || true)"
		printf 'preflight: %s table(s) present, %s migration table(s) present\n' \
			"$((DECLARED_COUNT + OBSERVED_COUNT))" \
			"$(printf '%s\n' "$INVENTORY" | grep -c '^MIGRATIONS|' || true)"
	fi

	if [ "$DECLARED_COUNT" -eq 0 ] && [ "$OBSERVED_COUNT" -gt 0 ]; then
		# Deliberately a warning and not a failure. The step is read-only, and this is a disagreement
		# between an approved document and a database rather than a fault in either one. Failing here
		# would replace T252 -- a documentation defect -- with a blocked build, and reproduce the
		# always-red gate this task was raised to fix.
		printf 'preflight: WARNING - spec.md:333 declares the legacy tables are oc_pcash_* and none exists, while oc_pc_* tables do; T252 owns the reconciliation\n' >&2
	fi
else
	printf 'preflight: database container not running; skipped legacy table inventory\n'
fi

printf 'preflight: checks completed without modifying application or legacy data\n'
