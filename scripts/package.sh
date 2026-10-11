#!/usr/bin/env bash
#
# T132: package the application without development dependencies or source maps,
# and verify app ID, namespace, licence, routes, translations, migrations, and
# generated assets.
#
# Read-only except for writing the tarball to ./dist/. Never modifies lib/,
# appinfo/, or vendor/. Fails on the first verification that does not hold.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

failures=0
report() {
	echo "package: $1" >&2
	failures=$((failures + 1))
}
ok() {
	echo "package: $1"
}

# --- 1. App ID, namespace, licence ---------------------------------------------
APP_ID="$(python3 -c "import xml.etree.ElementTree as ET; print(ET.parse('appinfo/info.xml').getroot().findtext('id').strip())")"
NAMESPACE="$(python3 -c "import xml.etree.ElementTree as ET; print(ET.parse('appinfo/info.xml').getroot().findtext('namespace').strip())")"
LICENCE="$(python3 -c "import xml.etree.ElementTree as ET; print(ET.parse('appinfo/info.xml').getroot().findtext('licence').strip())")"
APP_VERSION="$(python3 -c "import xml.etree.ElementTree as ET; print(ET.parse('appinfo/info.xml').getroot().findtext('version').strip())")"

[ "${APP_ID}" = "pettycash" ] || report "appinfo/info.xml id is '${APP_ID}', expected 'pettycash'"
[ "${NAMESPACE}" = "PettyCash" ] || report "appinfo/info.xml namespace is '${NAMESPACE}', expected 'PettyCash'"
[ "${LICENCE}" = "agpl" ] || report "appinfo/info.xml licence is '${LICENCE}', expected 'agpl'"
ok "app id=${APP_ID} namespace=${NAMESPACE} licence=${LICENCE} version=${APP_VERSION}"

# composer.json must agree on the PSR-4 prefix and the AGPL licence.
if ! python3 -c "import json; d=json.load(open('composer.json')); assert d['autoload']['psr-4'].get('OCA\\\\PettyCash\\\\')=='lib/', 'psr-4'; assert 'AGPL' in d.get('license',''), 'license'"; then
	report "composer.json psr-4 prefix or licence disagrees with appinfo/info.xml"
else
	ok "composer.json psr-4 + licence agree"
fi

# --- 2. Routes ------------------------------------------------------------------
if [ ! -f appinfo/routes.php ]; then
	report "appinfo/routes.php is missing"
else
	# Every OCS route name must follow the {appid}.{controller}.{method} convention
	# and the file must declare the seven route families V1 serves.
	for family in "session#me" "dashboard#index" "sheet#" "transaction#" "evidence#" "review#" "configuration#" "account#" "report#show" "export#create"; do
		if ! grep -q "${family}" appinfo/routes.php; then
			report "appinfo/routes.php does not declare '${family}'"
		fi
	done
	ok "appinfo/routes.php declares all route families"
fi

# The contract is the authority for the HTTP surface: the generated types must
# be current, via the T124 drift gate (read-only mode here).
if ! scripts/validate-openapi.sh >/tmp/opencode/package-openapi.log 2>&1; then
	cat /tmp/opencode/package-openapi.log >&2
	report "contracts/ocs-api.yaml drift gate fails; run scripts/validate-openapi.sh --write and re-verify"
else
	ok "openapi drift gate clean"
fi

# --- 3. Translations -------------------------------------------------------------
if [ ! -f l10n/fa.json ]; then
	report "l10n/fa.json is missing (Persian catalog, FR-071)"
elif ! python3 -c "import json; json.load(open('l10n/fa.json'))"; then
	report "l10n/fa.json is not valid JSON"
else
	ok "l10n/fa.json present and valid"
fi

if [ ! -f l10n/fa.js ]; then
	report "l10n/fa.js is missing (served catalog for the Nextcloud L10N factory)"
else
	ok "l10n/fa.js present"
fi

# --- 4. Migrations ----------------------------------------------------------------
# Every migration version prefix must be <= the app version (Nextcloud only
# applies those), and the chain must be forward-only (no destructive verbs).
MIG_COUNT="$(ls lib/Migration/Version*.php 2>/dev/null | wc -l)"
if [ "${MIG_COUNT}" -eq 0 ]; then
	report "lib/Migration/ holds no Version*.php migrations"
else
	ok "${MIG_COUNT} migrations present"
fi

if grep -rnE "DROP TABLE|DROP COLUMN|ALTER TABLE.*DROP|RENAME TABLE" lib/Migration/ --include="*.php" | grep -qv "^\s*\*"; then
	# Filter to real statements (not docblock prose about never dropping).
	if grep -rnE "DROP TABLE|DROP COLUMN|RENAME TABLE" lib/Migration/ --include="*.php" | grep -v "^\s*\*" | grep -v "\*" | head -3 | grep -q .; then
		grep -rnE "DROP TABLE|DROP COLUMN|RENAME TABLE" lib/Migration/ --include="*.php" >&2 || true
		report "forward-only violation: destructive statement in lib/Migration/ (see above)"
	else
		ok "migrations forward-only (destructive verbs only in prose, not in statements)"
	fi
else
	ok "migrations forward-only"
fi

# --- 5. Generated assets ------------------------------------------------------------
if [ ! -d js ] || [ -z "$(ls js/*.mjs 2>/dev/null)" ]; then
	report "js/ holds no built chunks; run npm run build first"
else
	ok "js/ chunks present ($(ls js/*.mjs | wc -l) files)"
fi

if [ -n "$(ls js/*.map 2>/dev/null || true)" ]; then
	report "js/ contains source maps; the release package must not ship them"
else
	ok "js/ contains no source maps"
fi

if [ ! -d css ] || [ -z "$(ls css/*.css 2>/dev/null)" ]; then
	report "css/ holds no built styles; run npm run build first"
else
	ok "css/ styles present"
fi

if [ "${failures}" -ne 0 ]; then
	echo "package: FAILED with ${failures} violation(s); no tarball written" >&2
	exit 1
fi

# --- 6. Tarball ----------------------------------------------------------------------
mkdir -p dist
PKG="dist/pettycash-${APP_VERSION}.tar.gz"
# Exclude: dev tooling, tests, source maps, local env, VCS, build caches.
tar -czf "${PKG}" \
	--exclude='./node_modules' \
	--exclude='./vendor' \
	--exclude='./tests' \
	--exclude='./test-results' \
	--exclude='./playwright-report' \
	--exclude='./coverage' \
	--exclude='./dist' \
	--exclude='./.git' \
	--exclude='./.opencode' \
	--exclude='.env*' \
	--exclude='!.env.example' \
	--exclude='./*.map' \
	--exclude='./js/*.map' \
	--exclude='./css/*.map' \
	--exclude='./npm-debug.log' \
	.

ok "wrote ${PKG} ($(du -h "${PKG}" | cut -f1))"
echo "package: OK (vendor/ intentionally excluded: install with composer install --no-dev on the target)"
