#!/usr/bin/env bash
#
# T239: run the database-portability pass for one backend.
#
# Invoked once per job by `.github/workflows/database-portability.yml`, with `PETTYCASH_BACKEND`
# naming which of the four ephemeral backends this job owns. The database itself is a GitHub Actions
# service container started by the workflow, not by this script -- the workflow owns the service
# definition because only it can express the four services' differing variable names.
#
# WHAT THIS DOES, IN ORDER
#
#   1. Bring up a Nextcloud instance pointed at this job's backend, with this repository mounted as
#      the app. Installing it creates the schema; `occ app:enable` then applies the forward-only
#      migrations in version order. Both steps are what "runs the forward-only migrations and schema
#      creation" means in ACC-002 -- and they are the FIRST thing that can fail on a backend, before
#      any test runs, which is the correct order: a backend whose migrations do not apply has nothing
#      for a test suite to report.
#   2. Run `tests/Integration/Migration` -- migration and schema compatibility checks.
#   3. Run `tests/Integration/Persistence` -- repository round trips and the representative
#      transaction and workflow queries, against the real backend.
#
# WHY A CONTAINER AND NOT A BARE PHP INSTALL
#
# These tests cannot run in the PHPUnit process. `tests/bootstrap.php` wires a container of in-memory
# doubles, and loading `lib/base.php` into it would replace the global server -- which is why every
# real-database test shells out to `roundtrip-runner.php` or a fixture instead. Those subprocesses
# need a bootstrapped Nextcloud at `NEXTCLOUD_ROOT`, and they need it to be the one the app runs in,
# so the runtime is a real `nextcloud:34-apache` container rather than a hand-assembled PHP.
#
# The image tag matches the development lab's (`NEXTCLOUD_IMAGE_TAG=34-apache` in
# `~/projects/nextcloud-lab/env/pettycash.env`) so the CI runtime and the development runtime are the
# same Nextcloud. A portability result from a different Nextcloud major would not be a result about
# this app on its declared platform.
#
# WHY THIS DOES NOT TOUCH THE LOCAL LABS
#
# ACC-002: "no CI job may modify or replace the shared Nextcloud database, Docker volumes, or another
# application's data." The container below is created and removed within this job, mounts only this
# checkout, and shares no volume with `pettycash-lab` or `pts-lab`. `scripts/dev/in-container` is
# deliberately NOT used: it targets `pettycash-lab-nextcloud-1` by name and would reach the wrong
# runtime entirely.
#
# ENVIRONMENT REQUIRED
#
#   PETTYCASH_BACKEND           one of: mysql | mariadb | postgresql | sqlite
#   PETTYCASH_NEXTCLOUD_IMAGE   the Nextcloud image (defaults to nextcloud:34-apache)
#   backend credentials         MYSQL_*, POSTGRES_* or SQLITE_DATABASE, per the workflow
#
# A result produced under any other condition is not a portability result, so every precondition is
# checked and a missing one fails loudly rather than installing a default instance and reporting on it.

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"

readonly BACKEND="${PETTYCASH_BACKEND:-}"
readonly NEXTCLOUD_IMAGE="${PETTYCASH_NEXTCLOUD_IMAGE:-nextcloud:34-apache}"

# The mounted app path inside the container. This is where the tests resolve `NEXTCLOUD_ROOT` from,
# so it must be a Nextcloud `custom_apps` directory: the app's own migrations and routes address
# tables by the logical `pettycash_` name and Nextcloud applies its configured prefix itself.
readonly APP_PATH_IN_CONTAINER=/var/www/html/custom_apps/pettycash

# A fixed, job-scoped name. Not `--rm`-only: the container is removed explicitly on exit so a failed
# step still cleans up, and a leaked container holding a mounted checkout would outlive the job.
readonly CONTAINER_NAME="pettycash-portability-${BACKEND:-unknown}"

log() { printf '\n[portability] %s\n' "$*"; }
fail() { printf '[portability] ERROR: %s\n' "$*" >&2; exit 1; }

cleanup() {
  local status=$?
  # Best-effort. A container that is already gone is the expected case on a failed step, and its
  # absence must not mask the real exit status, so this never becomes the job's result.
  docker rm --force --volumes "$CONTAINER_NAME" >/dev/null 2>&1 || true
  return "$status"
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Preconditions
# ---------------------------------------------------------------------------

case "$BACKEND" in
  mysql|mariadb|postgresql|sqlite) ;;
  *)
    fail "PETTYCASH_BACKEND must be one of mysql|mariadb|postgresql|sqlite; got '${BACKEND}'."
    ;;
esac

command -v docker >/dev/null 2>&1 || fail "docker is not available on this runner."
docker info >/dev/null 2>&1 || fail "the docker daemon is not reachable."

# The credentials are checked here rather than discovered later. The Nextcloud image installs
# against whatever it is given, so a missing variable does not fail -- it produces an instance with a
# default or empty configuration, and every test then runs against a database that is not the one
# this job declared. That is a green run for the wrong backend, which is the specific false-evidence
# outcome this matrix must never produce.
case "$BACKEND" in
  sqlite)
    [ -n "${SQLITE_DATABASE:-}" ] \
      || fail "SQLITE_DATABASE is unset; the SQLite job must name the database file to create."
    ;;
  mysql|mariadb)
    for variable in MYSQL_HOST MYSQL_PORT MYSQL_DATABASE MYSQL_USER MYSQL_PASSWORD; do
      [ -n "${!variable:-}" ] || fail "${variable} is unset; the ${BACKEND} job cannot reach its service."
    done
    ;;
  postgresql)
    for variable in POSTGRES_HOST POSTGRES_PORT POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD; do
      [ -n "${!variable:-}" ] || fail "${variable} is unset; the PostgreSQL job cannot reach its service."
    done
    ;;
esac

log "backend=${BACKEND} image=${NEXTCLOUD_IMAGE} app=${REPO_ROOT}"

# ---------------------------------------------------------------------------
# 1. Nextcloud, installed against this backend
# ---------------------------------------------------------------------------

# A fresh anonymous volume per run. The image declares `VOLUME /var/www/html`, so without `--tmpfs`
# Docker silently creates one that outlives the container; `--rm` reclaims it on a clean exit but an
# interrupted run leaves it behind, and those accumulate. tmpfs satisfies the declaration with an
# in-memory mount that has nothing to leak.
#
# `--network host` is what reaches the service container: GitHub Actions service containers are
# reachable from the runner's host interface on the published port, not by service name, because the
# job itself is not inside a compose network.
#
# The install variables are passed through explicitly per backend because the three services use
# different names (MYSQL_* / POSTGRES_* / SQLITE_DATABASE). The image's entrypoint selects the
# database type from which family is present: SQLITE_DATABASE -> sqlite, MYSQL_* -> mysql,
# POSTGRES_* -> pgsql.
#
# NOTE ON PORTS — `MYSQL_PORT` / `POSTGRES_PORT` are deliberately **not** forwarded, even though the
# precondition check above requires them to be set. The image's entrypoint passes only
# `--database-host` to `occ maintenance:install` and never the port, so Nextcloud connects on its own
# default (5432 for PostgreSQL, 3306 for MySQL/MariaDB) regardless of what those variables say.
# Measured against the shipped `nextcloud:34-apache` entrypoint:
#
#     --database pgsql --database-name "$POSTGRES_DB" ... --database-host "$POSTGRES_HOST"
#
# Setting them here would look meaningful and do nothing. They are validated instead, because a
# non-default port is the usual symptom of a service published somewhere other than the default and
# that mismatch fails as a connection error naming port 5432 — which reads like a Nextcloud bug and is
# not one. GitHub Actions publishes these services on their default ports, which is what makes this
# correct rather than merely lucky.
docker_args=(
  --name "$CONTAINER_NAME"
  --network host
  --tmpfs /var/www/html:exec
  --detach
  --env NEXTCLOUD_ADMIN_USER=admin
  --env NEXTCLOUD_ADMIN_PASSWORD=admin
  --env NEXTCLOUD_TRUSTED_DOMAINS=localhost
)

case "$BACKEND" in
  sqlite)
    docker_args+=(--env "SQLITE_DATABASE=${SQLITE_DATABASE}")
    ;;
  mysql|mariadb)
    docker_args+=(
      --env "MYSQL_HOST=${MYSQL_HOST}"
      --env "MYSQL_DATABASE=${MYSQL_DATABASE}"
      --env "MYSQL_USER=${MYSQL_USER}"
      --env "MYSQL_PASSWORD=${MYSQL_PASSWORD}"
    )
    ;;
  postgresql)
    docker_args+=(
      --env "POSTGRES_HOST=${POSTGRES_HOST}"
      --env "POSTGRES_DB=${POSTGRES_DB}"
      --env "POSTGRES_USER=${POSTGRES_USER}"
      --env "POSTGRES_PASSWORD=${POSTGRES_PASSWORD}"
    )
    ;;
esac

# `vendor/` is mounted from the host so the PHPUnit binary and the autoloader are the ones this
# repository resolves, rather than a second install inside the container that could differ.
docker_args+=(--volume "$REPO_ROOT:${APP_PATH_IN_CONTAINER}:ro")

# The Composer-loader shim, mounted read-only and activated through a `conf.d` ini so it applies to
# Apache's mod_php, the CLI SAPI and PHPUnit alike.
#
# Without it, enabling the app registers this repository's DEVELOPMENT vendor tree in front of
# Nextcloud's own, and `symfony/console` v8 (which removed `Application::add()`) shadows the copy
# Nextcloud 34 is built against -- so every `occ` command that registers a core command dies with
# "Call to undefined method Symfony\Component\Console\Application::add()", and the matrix cannot
# reach its tests at all.
#
# That is a pre-existing application defect, not something T239 introduces; the development lab
# carries an equivalent shim for the same reason. It is duplicated here because a GitHub Actions
# runner cannot mount a file out of a sibling repository, and CI has to be self-contained. See
# `core-autoload-shim.php` for the full reasoning and for the instruction to delete both this mount
# and that file once the application's own autoloader is corrected at source.
docker_args+=(
  --volume "$SCRIPT_DIR/core-autoload-shim.php:/usr/local/share/pettycash-ci-shim/core-autoload-shim.php:ro"
  --volume "$SCRIPT_DIR/ci-autoload.ini:/usr/local/etc/php/conf.d/zz-pettycash-ci-autoload.ini:ro"
)

log "starting Nextcloud against ${BACKEND}"
docker run "${docker_args[@]}" "$NEXTCLOUD_IMAGE" >/dev/null

# Wait for the install to finish. The entrypoint retries `maintenance:install` internally and only
# then starts Apache, so readiness is "installed", not merely "served".
#
# `status.php` alone is NOT sufficient, and the reason is worth recording because it produced a
# misleading failure during development: Apache starts serving early in the install sequence, so
# `curl status.php` can return 200 while `/var/www/html/lib/versioncheck.php` — which `console.php`
# requires on line 14 — has not been written yet. Every subsequent `occ` call then failed with
# "Failed opening required '/var/www/html/lib/versioncheck.php'", which reads like a broken image
# rather than a race. The gate below asks the question that actually matters: can `occ` load?
log "waiting for the install to complete"
ready=0
for _ in $(seq 1 60); do
  if docker exec "$CONTAINER_NAME" curl --silent --fail --max-time 5 http://localhost/status.php >/dev/null 2>&1 \
    && docker exec --user www-data "$CONTAINER_NAME" \
         test -f /var/www/html/lib/versioncheck.php \
    && docker exec --user www-data "$CONTAINER_NAME" \
         php /var/www/html/occ status >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 5
done

if [ "$ready" -ne 1 ]; then
  log "Nextcloud did not become ready; the install log follows"
  docker logs --tail 80 "$CONTAINER_NAME" >&2 || true
  fail "Nextcloud never served status.php on backend ${BACKEND}."
fi

# The app is mounted read-only, so `occ app:enable` cannot write into it -- which is correct: it must
# not. Nextcloud enables the app from `custom_apps` and records the enablement in its own database,
# running the app's `lib/Migration` steps as it does so. That step IS the forward-only migration run
# ACC-002 requires, and a backend that cannot apply them fails here, before any test.
log "enabling the app (this applies lib/Migration in version order)"
docker exec --user www-data "$CONTAINER_NAME" \
  php /var/www/html/occ app:enable --no-interaction pettycash \
  || fail "app:enable failed on backend ${BACKEND} -- the forward-only migrations did not apply."

# `upgrade` applies pending *core* migrations. A no-op on a current install, and it is the command
# that guarantees the instance is fully migrated before the app's schema is exercised.
docker exec --user www-data "$CONTAINER_NAME" \
  php /var/www/html/occ upgrade --no-interaction \
  || fail "occ upgrade failed on backend ${BACKEND}."

# Report which backend actually answered, from Nextcloud's own configuration. Read inside the
# container, because that is where the instance's config.php lives and where the tests run -- and it
# turns "the matrix ran" into "the matrix ran against what it claims", which is the difference this
# whole file exists to preserve.
log "reporting the backend that answered"
docker exec --user www-data "$CONTAINER_NAME" php -r '
require_once "/var/www/html/lib/base.php";
$db = \OCP\Server::get(\OCP\IDBConnection::class);
$cfg = \OCP\Server::get(\OCP\IConfig::class);
printf(
    "dbtype=%s provider=%s platform=%s\n",
    $cfg->getSystemValue("dbtype", "?"),
    $db->getDatabaseProvider(),
    get_class($db->getDatabasePlatform())
);
'

# The persistence tests need an acting identity in `PETTYCASH_TEST_USER` and a member of the `admin`
# group: `roundtrip-runner.php` puts that user in the session (every `Db*Repository::save()` stamps
# `created_by` from `CurrentUser::uid()`, and `NextcloudCurrentUser` refuses to invent one) and
# resolves a separate real administrator uid for the authorization checks. Both are environment
# facts, so they are established here rather than assumed -- the runner exits with a named message if
# either is missing, which is a good failure, but a green job should never depend on that.
TEST_USER=pettycash-portability
TEST_PASSWORD=portability-pass-$(openssl rand -hex 8)

# The database is fresh per job, so the acting user cannot already exist and the exit status is a
# reliable signal here. `user:add` is not idempotent (it fails on an existing user), so it is *not*
# made best-effort the way `group:add` above is -- the difference is that a fresh database makes the
# two outcomes distinguishable.
docker exec --user www-data --env "NC_PASS=${TEST_PASSWORD}" "$CONTAINER_NAME" \
  php /var/www/html/occ user:add --password-from-env --display-name 'Portability Pass' "$TEST_USER" \
  || fail "could not create the acting user for the persistence tests."

# `group:add` is **not** idempotent: it succeeds on creation and returns non-zero when the group
# already exists, which is the normal case because Nextcloud creates `admin` during install. So it is
# called best-effort and the post-condition is asserted instead of the exit status — the same
# reasoning `~/projects/nextcloud-lab/bin/lab` applies to `user:add`, for the same reason.
docker exec --user www-data "$CONTAINER_NAME" \
  php /var/www/html/occ group:add admin >/dev/null 2>&1 || true

# The acting user must NOT be an administrator, and that is the opposite of the obvious reading.
#
# `roundtrip-runner.php` uses two distinct identities on purpose: `$actorUid` (this user, in the
# session) and `$administratorUid` (a real member of the `admin` group, resolved separately). Several
# assertions exist to prove a NON-administrator is refused — `testTheCurrencyServiceRefusesANonAdministratorAgainstTheRealGroupManager`
# and `testTheFinanceAssignmentServiceRefusesANonAdministratorAgainstTheRealGroupManager` both call
# `CurrencyService::create($actorUid, ...)` and expect a `LogicException`.
#
# So adding this user to `admin` would make the very refusals under test succeed, and those two
# assertions fail. Measured on SQLite: promoting the CI user took the run from 8 failures to exactly
# these 2, which is the whole signature of that mistake.
#
# The administrator identity the runner needs is the Nextcloud `admin` account created during install,
# which is a member of the `admin` group already. Nothing has to be done for it, and the runner exits
# with "The 'configuration' scenario needs a member of the admin group." if it is ever missing.
#
# The assertion that the admin group really has a member is worth making explicitly, because it is a
# silent precondition otherwise — and it is what would actually need fixing if the install changed.
docker exec --user www-data "$CONTAINER_NAME" \
  sh -c 'php /var/www/html/occ group:list' 2>/dev/null | grep -qE '^[[:space:]]*- admin:[[:space:]]*$' \
  || fail "the 'admin' group does not exist on backend ${BACKEND}; the persistence tests resolve an administrator from it."

# The acting user must also HOLD AN ACCOUNT. `leak-a-sheet.php` needs one: a sheet references an
# account, so with no account the leak probe cannot build the row it is meant to leave behind and
# `RepositoryRoundTripTest::testTheFingerprintsMatchRealRowsRatherThanOnlyTheSourceThatDeclaresThem`
# fails with "holds no account, so a sheet has no account to reference".
#
# The contract fixture seeder is what supplies it -- the same seeding the development lab relies on,
# which is why the lab's `employee1` already has an account and a freshly created CI user does not.
# It is idempotent by design (it asserts the rows it expects rather than assuming them), so running it
# per job against a fresh database is exactly the case it is written for.
log "seeding fixtures for ${TEST_USER}"
docker exec \
  --user www-data \
  --workdir "$APP_PATH_IN_CONTAINER" \
  --env "NEXTCLOUD_ROOT=/var/www/html" \
  "$CONTAINER_NAME" \
  php tests/Contract/Fixture/seed-contract-fixtures.php "$TEST_USER" 2>&1 \
  || fail "seeding the contract fixtures failed on backend ${BACKEND}. The persistence tests need the acting user to hold an account."

# ---------------------------------------------------------------------------
# 2 & 3. The compatibility suites
# ---------------------------------------------------------------------------

# Run inside the container, because that is where Nextcloud is bootstrapped and where
# `roundtrip-runner.php` resolves `NEXTCLOUD_ROOT`. Run as `www-data`, matching every other PHP test
# invocation in this repository (`scripts/dev/in-container` defaults to the same identity).
run_suite() {
  local label="$1" path="$2"

  log "running ${label} against ${BACKEND}"
  # `--do-not-cache-result` because the container's `vendor/` is mounted read-only and PHPUnit's
  # result cache would otherwise fail to write. `2>&1` so a subprocess's STDERR -- which is where
  # every fixture in this suite reports -- reaches the job log instead of being swallowed.
  docker exec \
    --user www-data \
    --workdir "$APP_PATH_IN_CONTAINER" \
    --env "NEXTCLOUD_ROOT=/var/www/html" \
    --env "PETTYCASH_TEST_USER=${TEST_USER}" \
    "$CONTAINER_NAME" \
    php vendor/bin/phpunit --do-not-cache-result "$path" 2>&1 \
    || fail "${label} failed on backend ${BACKEND}. See the output above for the failing assertion."
}

# Migration and schema compatibility checks. `V1SchemaMigrationTest` is source-level and runs on
# every backend; `SchemaConstraintNamingTest` reads the live schema through the portable API, which
# is what makes it meaningful on PostgreSQL and SQLite rather than MySQL-only.
run_suite 'migration and schema compatibility checks' 'tests/Integration/Migration'

# The persistence and repository integration tests, including the representative transaction and
# workflow queries (round-trip scenarios: configuration, account, expense, command, retention,
# appdata, exportservice, sheetlist, dashboardcounts, provisioning).
run_suite 'persistence, repository, transaction and workflow queries' 'tests/Integration/Persistence'

log "backend ${BACKEND}: migrations, compatibility checks, persistence and workflow queries all passed"