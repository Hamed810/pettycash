#!/usr/bin/env sh
# T250: the preflight gate must be a gate.
#
# ## Why this exists
#
# `scripts/preflight.sh` exited 1 on every invocation for as long as it existed, and nothing noticed.
# It is not run by `make check`, it is not part of `npm run check`, and no CI configuration exists --
# so a permanently-red script looked exactly like a healthy one. This file is the missing assertion:
# it runs the gate and checks that what it reports is true.
#
# ## What each assertion is for
#
# The first is the one that matters. "Exits 0" is not a formality here: the original script exited 1
# on a *correct* environment because it read credential variables the database image never sets, so a
# red gate was indistinguishable from a broken environment. Asserting exit 0 alone would not catch a
# script that passes by never reaching the database, which is why the inventory assertions below check
# that real table names came back rather than that the script was quiet.
#
# Run it directly: `sh scripts/preflight.test.sh`

# Deliberately NOT `set -e`. Under it the first failing probe aborts the whole file, so a broken gate
# produces an exit code and no explanation -- and a run that dies at assertion one never reports
# assertions two through six, which is exactly when a second problem is hiding. Every probe therefore
# ends in `|| probe=$?` and its status is handed to `check`.
set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PREFLIGHT="$SCRIPT_DIR/preflight.sh"

failures=0
check() {
	if [ "$2" -eq 0 ]; then
		printf 'ok   %s\n' "$1"
	else
		printf 'FAIL %s\n' "$1" >&2
		failures=$((failures + 1))
	fi
}

# 1. It exits 0 on a healthy environment.
status=0
OUTPUT="$(sh "$PREFLIGHT" 2>&1)" || status=$?
check 'preflight exits 0 on a healthy environment' "$status"

# 2. The database step actually ran. A pass produced by never reaching the database is not a pass,
#    and this is the assertion that separates the two. An exit-code-only check would be satisfied by
#    a script that had deleted the step entirely.
probe=0
printf '%s' "$OUTPUT" | grep -q 'oc_migrations' || probe=$?
check 'the legacy table inventory reached the database' "$probe"

# 3. It reports the tables that actually exist, not the ones the artifacts describe. The original
#    filter was the artifact's prefix, which matches nothing here, so this is the assertion that
#    distinguishes "ran" from "ran and found the right thing".
probe=0
printf '%s' "$OUTPUT" | grep -q 'oc_pc_projects' || probe=$?
check 'it reports the observed legacy prefix (oc_pc_*)' "$probe"

# 4. The declared prefix is reported as empty rather than quietly dropped. T252 owns reconciling the
#    artifacts; a gate that adopted one prefix silently would hide the disagreement that the
#    reconciliation exists to settle.
probe=0
printf '%s' "$OUTPUT" | grep -q 'WARNING.*oc_pcash' || probe=$?
check 'it reports that the declared prefix (oc_pcash_*) matches nothing' "$probe"

# 5. The count it prints is the count of rows it listed. Exactly, not "at least".
#
#    The first version of this assertion was `-ge` -- "the claim accounts for the listed rows" -- and
#    a gate that printed `999` sailed through it while listing eleven. A lower bound is the weakest
#    form an assertion can take, and a fabricated count is precisely the kind of defect a summary
#    line is prone to: it is printed by a different expression than the one that produced the rows.
#    Both `DECLARED|` and `OBSERVED|` rows are counted here from the script's own output, so the
#    number is compared against the evidence beside it rather than against a second computation of
#    the same expression.
listed_declared="$(printf '%s\n' "$OUTPUT" | grep -c '^DECLARED|' || true)"
listed_observed="$(printf '%s\n' "$OUTPUT" | grep -c '^OBSERVED|' || true)"
claimed="$(printf '%s\n' "$OUTPUT" | sed -n 's/^preflight: \([0-9]*\) table(s) present.*/\1/p')"
probe=0
{
	[ -n "$claimed" ] &&
	[ "$claimed" -gt 0 ] &&
	[ "$claimed" -eq "$((listed_declared + listed_observed))" ]
} || probe=$?
check "the reported count ($claimed) equals the rows it listed ($((listed_declared + listed_observed)))" "$probe"

# 6. The database password never reaches the output.
#
#    Scoped honestly: this checks the *output*, not the host's process table. The fix keeps the
#    password off the host command line by expanding it inside the database container, and that part
#    is not observable from here -- a value passed as `docker exec -e FOO=...` never appears in a host
#    argument list either. What this can prove is that the secret is not printed, which is the half
#    that would reach a log or a terminal scrollback.
db_password="$(docker exec "${PETTYCASH_DB_CONTAINER:-docker-db-1}" printenv MARIADB_ROOT_PASSWORD 2>/dev/null || true)"
if [ -n "$db_password" ]; then
	# Inverted deliberately and worth stating: **finding** the password is the failure, so the probe
	# is 1 (fail) when grep succeeds. Written the other way round -- `grep && probe=0` -- this
	# assertion passed exactly when the secret leaked, and the mutation that printed the password
	# survived. An assertion whose polarity is wrong is worse than no assertion, because it is
	# indistinguishable from one that works.
	probe=0
	if printf '%s' "$OUTPUT" | grep -qF "$db_password"; then
		probe=1
	fi
	check 'the database password does not appear in preflight output' "$probe"
else
	printf 'skip the password-leak check (could not read MARIADB_ROOT_PASSWORD)\n'
fi

if [ "$failures" -gt 0 ]; then
	printf '\npreflight.test: %s assertion(s) failed\n' "$failures" >&2
	exit 1
fi

printf '\npreflight.test: all assertions passed\n'
