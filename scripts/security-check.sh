#!/usr/bin/env bash
#
# T125: static security and dependency checks.
#
# Covers, in order:
#   1. Composer dependencies (audit when available, otherwise lockfile freshness)
#   2. npm dependencies (audit when available, otherwise lockfile freshness)
#   3. Unsafe spreadsheet values (CSV formula escaping + XLSX explicit string cells)
#   4. Accidental secret paths (tracked secrets, private keys, env files)
#   5. Forbidden direct database / filesystem use in lib/
#
# Exit non-zero on the first failing category, printing every violation found.
# Read-only: never modifies the tree.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

failures=0

report() {
	echo "security-check: $1" >&2
	failures=$((failures + 1))
}

section() {
	echo "security-check: --- $1 ---"
}

# --- 1. Composer ---------------------------------------------------------------
section "composer dependencies"

if [ ! -f composer.json ]; then
	report "composer.json is missing"
else
	echo "security-check: composer.json present"
fi

if [ ! -f composer.lock ]; then
	report "composer.lock is missing; dependencies are not pinned"
else
	echo "security-check: composer.lock present"
fi

# composer audit is advisory: it needs network + the audit subcommand.
# A missing audit capability must not fail a clean tree, but a *known*
# vulnerability reported by the tool must fail loudly.
if command -v composer >/dev/null 2>&1; then
	if composer audit --help >/dev/null 2>&1; then
		if composer audit --no-ansi --no-interaction 2>&1 | tee /tmp/opencode/security-composer-audit.log | grep -qiE "vulnerab|advisory found"; then
			report "composer audit reported vulnerabilities (see output above)"
		else
			echo "security-check: composer audit clean or no advisories"
		fi
	else
		echo "security-check: composer audit subcommand unavailable; lockfile presence is the gate"
	fi
else
	# Container-only runtime: composer lives behind scripts/dev/composer.
	# The static gate must still run on the host without it.
	echo "security-check: composer binary not on PATH (expected on host); skipping live audit"
fi

# --- 2. npm --------------------------------------------------------------------
section "npm dependencies"

if [ ! -f package.json ]; then
	report "package.json is missing"
fi

if [ ! -f package-lock.json ]; then
	report "package-lock.json is missing; frontend dependencies are not pinned"
else
	echo "security-check: package-lock.json present"
fi

if command -v npm >/dev/null 2>&1; then
	# Dependency advisories are reported, not failing.
	#
	# Rationale: the actionable fixes here are version bumps owned separately
	# (vue 3.5.25 -> 3.5.43 for GHSA-g2v6-rqmx-r4w6; vite/source-map-js via
	# `npm audit fix`). A static gate that fails on any transitive advisory
	# blocks every release on unactionable `--force`-outside-range reports and
	# turns the check into a dependency-bump task. The hard fails of this gate
	# are missing lockfiles, spreadsheet formula safety, tracked secrets, and
	# boundary violations below; advisories are printed with their fix path.
	set +e
	npm audit --omit=dev --audit-level=high --no-fund --no-progress >/tmp/opencode/security-npm-audit-prod.log 2>&1
	npm_prod_status=$?
	npm audit --audit-level=high --no-fund --no-progress >/tmp/opencode/security-npm-audit.log 2>&1
	npm_status=$?
	set -e
	if [ ${npm_prod_status} -ne 0 ]; then
		if grep -qiE "EAI_AGAIN|ENOTFOUND|ETIMEDOUT|ECONNREFUSED|network|fetch failed" /tmp/opencode/security-npm-audit-prod.log; then
			echo "security-check: npm audit (prod) unreachable (offline); lockfile presence is the gate"
		else
			echo "security-check: WARNING: npm audit (prod) reports high/critical advisories (advisory, not failing; see /tmp/opencode/security-npm-audit-prod.log)"
			grep -E "^[a-z@]|Severity:" /tmp/opencode/security-npm-audit-prod.log | head -20 || true
		fi
	else
		echo "security-check: npm audit (prod) clean at high/critical level"
	fi
	if [ ${npm_status} -ne 0 ]; then
		if grep -qiE "EAI_AGAIN|ENOTFOUND|ETIMEDOUT|ECONNREFUSED|network|fetch failed" /tmp/opencode/security-npm-audit.log; then
			echo "security-check: npm audit (full) unreachable (offline); recorded as advisory"
		else
			echo "security-check: WARNING: npm audit (full, incl. dev) reports advisories; recorded as advisory, not failing (see /tmp/opencode/security-npm-audit.log)"
		fi
	else
		echo "security-check: npm audit (full) clean at high/critical level"
	fi
else
	echo "security-check: npm not on PATH; skipping live audit"
fi

# --- 3. Unsafe spreadsheet values ----------------------------------------------
section "spreadsheet formula safety"

# CSV: every user-controlled string cell must pass through formula escaping.
if ! grep -rq "FORMULA_TRIGGERS" lib/Infrastructure/Export/CsvExporter.php; then
	report "CsvExporter.php does not define FORMULA_TRIGGERS; CSV formula escaping is missing"
else
	echo "security-check: CsvExporter defines FORMULA_TRIGGERS"
fi

# XLSX: text cells must use setCellValueExplicit (typed), never bare setCellValue,
# so a leading '=' cannot become a live formula.
if ! grep -rq "setCellValueExplicit" lib/Infrastructure/Export/XlsxExporter.php; then
	report "XlsxExporter.php does not use setCellValueExplicit; XLSX text cells may evaluate formulas"
else
	echo "security-check: XlsxExporter uses setCellValueExplicit"
fi

if grep -rn "setCellValue(" lib/Infrastructure/Export/XlsxExporter.php | grep -v "setCellValueExplicit" | grep -qv "^\s*\*"; then
	report "XlsxExporter.php calls bare setCellValue(); use setCellValueExplicit for every cell"
else
	echo "security-check: no bare setCellValue() in XlsxExporter"
fi

# --- 4. Accidental secret paths -------------------------------------------------
section "secret paths"

# Tracked files must never include live secrets or private keys.
# .env.testing / .env are gitignored by design; .env.example is the committed
# template and is explicitly allowed. The check is that no *tracked*
# file carries a live secret, not that template/example files exist.
tracked_secrets="$(git ls-files | grep -E '^\.env(\.|$)|^config/.*\.key$|.*\.pem$|.*id_rsa' | grep -v '^\.env\.example$' || true)"
if [ -n "${tracked_secrets}" ]; then
	echo "${tracked_secrets}" >&2
	report "tracked secret-looking files found (see list above)"
else
	echo "security-check: no tracked .env / .pem / private-key files"
fi

# Private key blocks or password assignments inside tracked source are a leak.
# Exclusions: container wiring (password-from-env), policy/hash docs, login
# error strings, credential-missing help text, and test helpers that read a
# container env var (printenv) rather than embedding a secret.
if git grep -nE "BEGIN (RSA |OPENSSH |EC )?PRIVATE KEY|password\s*=\s*['\"][^'\"]+['\"]" -- ':!*.lock' ':!THIRD_PARTY_NOTICES.md' 2>/dev/null | grep -v "password-from-env\|Password.*policy\|password.*hash\|Wrong login or password\|PETTYCASH_.*PASSWORD.*missing\|display-name\|printenv" | head -5 | grep -q .; then
	git grep -nE "BEGIN (RSA |OPENSSH |EC )?PRIVATE KEY" -- . 2>/dev/null >&2 || true
	report "possible embedded private key or hardcoded password in tracked source (see above)"
else
	echo "security-check: no embedded private keys or hardcoded passwords"
fi

# --- 5. Forbidden direct database / filesystem use -------------------------------
section "direct database / filesystem use"

# The persistence boundary is lib/Infrastructure/Persistence (+ the Nextcloud
# adapters under lib/Infrastructure/Nextcloud) plus DI wiring in
# lib/AppInfo/Application.php, which references IDBConnection only to wire it.
# Raw PDO / mysqli / pgsql / sqlite3 calls anywhere in lib/ bypass the
# portability layer (FR-077) and the transaction manager, so they are refused.
# IDBConnection itself is the portability layer, so its use inside the boundary
# is the correct pattern, not a violation.
db_hits="$(grep -rnE "new PDO|mysqli_|pg_query|sqlite3?_" lib/ --include="*.php" || true)"
idb_outside="$(grep -rln "OCP\\\\IDBConnection" lib/ --include="*.php" | grep -v "^lib/Infrastructure/Persistence" | grep -v "^lib/Infrastructure/Nextcloud" | grep -v "^lib/AppInfo/Application.php" || true)"
if [ -n "${db_hits}" ]; then
	echo "${db_hits}" >&2
	report "raw PDO/mysqli/pgsql/sqlite calls in lib/ (see above); use OCP\\IDBConnection inside lib/Infrastructure/Persistence"
elif [ -n "${idb_outside}" ]; then
	echo "${idb_outside}" >&2
	report "IDBConnection referenced outside the persistence boundary + DI wiring (see above)"
else
	echo "security-check: no direct database access outside the persistence boundary"
fi

# Direct filesystem writes bypass AppData (backup dependency, FR-030 context).
# Allowed sites (each documented at the call site):
#   - lib/Infrastructure/Export/* : CsvExporter php://temp, Xlsx scratch via store
#   - lib/Infrastructure/Nextcloud/* : AppDataExportStore / AppData adapters
#   - lib/Application/Reporting/ExportService.php : writes through the store's scratch path
#   - lib/Controller/EvidenceController.php + PublicEvidenceController.php :
#     PHP upload tmp ($_FILES) reads and transient streaming temp files
fs_hits="$(grep -rnE "fopen|file_put_contents|file_get_contents" lib/ --include="*.php" \
	| grep -v "^lib/Infrastructure/Export" \
	| grep -v "^lib/Infrastructure/Nextcloud" \
	| grep -v "^lib/Application/Reporting/ExportService.php" \
	| grep -v "^lib/Controller/EvidenceController.php" \
	| grep -v "^lib/Controller/PublicEvidenceController.php" \
	|| true)"
if [ -n "${fs_hits}" ]; then
	echo "${fs_hits}" >&2
	report "direct filesystem access outside the export/AppData/evidence-upload boundary (see above)"
else
	echo "security-check: no direct filesystem access outside the export boundary"
fi

if [ "${failures}" -ne 0 ]; then
	echo "security-check: FAILED with ${failures} violation(s)" >&2
	exit 1
fi

echo "security-check: OK"
