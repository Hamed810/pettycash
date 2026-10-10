#!/usr/bin/env bash
#
# T124: validate `contracts/ocs-api.yaml`, derive `src/generated/api.ts` from it, and **fail when the
# two disagree**.
#
# ## Why this exists
#
# The contract document and the TypeScript the frontend is written against are two expressions of the
# same API, and nothing in this repository connected them. So they drifted: `src/types/api.ts` is
# hand-written, `contracts/ocs-api.yaml` changed underneath it repeatedly, and no gate noticed. T272
# exists because the same drift one layer down — a route declared in the contract and missing from
# `appinfo/routes.php` — was invisible until a test happened to exercise it.
#
# This is that layer. It is a **drift detector**, not a linter: the failure it exists to produce is
#
#     contracts/ocs-api.yaml was amended but src/generated/api.ts was not regenerated
#
# because a stale `src/generated/api.ts` that still *reads* as current is worse than no file at all —
# it compiles, it type-checks, and it is wrong. That is exactly the failure T124's own ordering note
# warned about: regenerating against a document still awaiting an amendment produces types that look
# current and are not.
#
# ## Usage
#
#   scripts/validate-openapi.sh          # validate + fail on drift  (what CI runs)
#   scripts/validate-openapi.sh --write  # regenerate src/generated/api.ts
#
# `--write` is the only way the generated file changes. An edit to the contract therefore cannot reach
# `src/generated/api.ts` without passing through this script, which is what makes the drift gate
# meaningful rather than advisory.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTRACT="${ROOT}/specs/001-pettycash-v1-core/contracts/ocs-api.yaml"
GENERATED="${ROOT}/src/generated/api.ts"

cd "${ROOT}"

if [ ! -f "${CONTRACT}" ]; then
    echo "validate-openapi: contract not found at ${CONTRACT}" >&2
    exit 2
fi

if [ ! -x "${ROOT}/node_modules/.bin/openapi-typescript" ]; then
    echo "validate-openapi: openapi-typescript is not installed." >&2
    echo "  Run 'npm install' first. It is a devDependency because it is a build-time tool only;" >&2
    echo "  nothing in js/ or the runtime depends on it." >&2
    exit 2
fi

write_mode=0
if [ "${1:-}" = "--write" ]; then
    write_mode=1
elif [ -n "${1:-}" ]; then
    echo "usage: $(basename "$0") [--write]" >&2
    exit 2
fi

# --- 1. The document must parse as OpenAPI at all ---------------------------------------------
#
# Checked before generation because openapi-typescript's own error for a malformed document is a stack
# trace, and "the contract does not parse" is a materially different problem from "the types are stale".
#
# The structural requirements are the ones that make a document an OpenAPI description rather than a
# YAML file that happens to have `paths:` in it: a version, an info block with a title and a version, and
# a paths object. Each is asserted with the value it must carry, so a document that silently loses its
# version cannot pass as valid.
echo "validate-openapi: checking ${CONTRACT#"${ROOT}/"} parses as OpenAPI..."
node - "${CONTRACT}" <<'NODE'
const fs = require('node:fs');
const file = process.argv[2];
let text;
try {
    text = fs.readFileSync(file, 'utf8');
} catch (e) {
    console.error(`validate-openapi: cannot read the contract: ${e.message}`);
    process.exit(2);
}

// Parsed with a real YAML parser rather than a regex, so a document that is malformed fails here
// instead of producing a plausible-looking subset of paths.
let yaml;
try {
    yaml = require('yaml');
} catch {
    console.error('validate-openapi: the `yaml` package is required to validate the contract.');
    process.exit(2);
}

let doc;
try {
    doc = yaml.parse(text);
} catch (e) {
    console.error(`validate-openapi: ${file} is not valid YAML:\n  ${e.message}`);
    process.exit(1);
}

const problems = [];
if (typeof doc !== 'object' || doc === null) {
    problems.push('the document is not a mapping');
} else {
    if (typeof doc.openapi !== 'string' || !/^3\./.test(doc.openapi)) {
        problems.push(`\`openapi\` must be a 3.x version string, got ${JSON.stringify(doc.openapi)}`);
    }
    if (!doc.info || typeof doc.info.title !== 'string' || doc.info.title === '') {
        problems.push('`info.title` is required and must be a non-empty string');
    }
    if (!doc.info || typeof doc.info.version !== 'string' || doc.info.version === '') {
        problems.push('`info.version` is required and must be a non-empty string');
    }
    if (!doc.paths || typeof doc.paths !== 'object') {
        problems.push('`paths` is required and must be a mapping');
    } else if (Object.keys(doc.paths).length === 0) {
        problems.push('`paths` is empty: an API with no operations describes nothing');
    }
}

if (problems.length > 0) {
    console.error(`validate-openapi: ${file} is not a usable OpenAPI document:`);
    for (const p of problems) console.error(`  - ${p}`);
    process.exit(1);
}

const ops = Object.values(doc.paths).reduce(
    (n, item) => n + Object.keys(item ?? {}).filter((k) => ['get', 'post', 'put', 'patch', 'delete'].includes(k)).length,
    0
);
const schemas = Object.keys(doc.components?.schemas ?? {}).length;
console.log(`  OpenAPI ${doc.openapi}, ${Object.keys(doc.paths).length} paths, ${ops} operations, ${schemas} schemas.`);
NODE

# --- 2. Derive the types, either in place or to a scratch file -------------------------------
#
# The header carries a fingerprint of the contract it was generated from. That is what lets the drift
# message say *which* amendment the types predate, instead of only that they are stale — and it means a
# hand-edited generated file cannot quietly pass by being textually similar.
gen() {
    node_modules/.bin/openapi-typescript "${CONTRACT}" \
        --alphabetize \
        --empty-objects-unknown \
        "$@"
}

if [ "${write_mode}" -eq 1 ]; then
    echo "validate-openapi: regenerating ${GENERATED#"${ROOT}/"}..."
    mkdir -p "$(dirname "${GENERATED}")"
    {
        echo '// T124: GENERATED FILE — DO NOT EDIT BY HAND.'
        echo '//'
        echo '// Derived from specs/001-pettycash-v1-core/contracts/ocs-api.yaml by'
        echo '// scripts/validate-openapi.sh --write. Edit the contract, then regenerate; an edit here'
        echo '// is discarded by the next run and fails `npm run check:openapi` in the meantime.'
        echo '//'
        echo '// @contractFingerprint '"$(node -e '
            const fs=require("node:fs"),crypto=require("node:crypto");
            process.stdout.write(crypto.createHash("sha256").update(fs.readFileSync(process.argv[1])).digest("hex").slice(0,16));
        ' "${CONTRACT}")"
        echo ''
        gen
    } > "${GENERATED}"
    echo "validate-openapi: wrote ${GENERATED#"${ROOT}/"}."
    exit 0
fi

# --- 3. The drift gate -----------------------------------------------------------------------
#
# The generated file is produced **again**, to a scratch path, and compared with the committed one. A
# byte comparison rather than a semantic one, on purpose: a semantic comparison would accept a
# regenerated file whose ordering or comments differ, which is precisely the "looks current but is not"
# state this gate exists to prevent.
scratch="$(mktemp)"
trap 'rm -f "${scratch}"' EXIT

echo "validate-openapi: deriving types to compare against the committed file..."
{
    echo '// T124: GENERATED FILE — DO NOT EDIT BY HAND.'
    echo '//'
    echo '// Derived from specs/001-pettycash-v1-core/contracts/ocs-api.yaml by'
    echo '// scripts/validate-openapi.sh --write. Edit the contract, then regenerate; an edit here'
    echo '// is discarded by the next run and fails `npm run check:openapi` in the meantime.'
    echo '//'
    echo '// @contractFingerprint '"$(node -e '
        const fs=require("node:fs"),crypto=require("node:crypto");
        process.stdout.write(crypto.createHash("sha256").update(fs.readFileSync(process.argv[1])).digest("hex").slice(0,16));
    ' "${CONTRACT}")"
    echo ''
    gen
} > "${scratch}"

if [ ! -f "${GENERATED}" ]; then
    cat >&2 <<EOF
validate-openapi: FAILED — ${GENERATED#"${ROOT}/"} does not exist.

  The contract is valid but no types have been derived from it, so the frontend is written against
  hand-written interfaces that nothing keeps in step with the document. Run:

      scripts/validate-openapi.sh --write

  and commit the result.
EOF
    exit 1
fi

if ! diff -u "${GENERATED}" "${scratch}" > /tmp/validate-openapi.diff 2>&1; then
    fingerprint_now="$(node -e '
        const fs=require("node:fs"),crypto=require("node:crypto");
        process.stdout.write(crypto.createHash("sha256").update(fs.readFileSync(process.argv[1])).digest("hex").slice(0,16));
    ' "${CONTRACT}")"
    fingerprint_had="$(grep -oE '@contractFingerprint [0-9a-f]+' "${GENERATED}" | head -1 | awk '{print $2}')"

    cat >&2 <<EOF
validate-openapi: FAILED — unreviewed API drift.

  ${GENERATED#"${ROOT}/"} is not what ${CONTRACT#"${ROOT}/"} currently describes
  (committed fingerprint ${fingerprint_had:-none}, contract is now ${fingerprint_now}).

  The committed types still compile and still type-check, which is why this has to be a gate rather
  than a review step: a stale generated file is worse than none, because it reads as current.

  Review the change to the contract, then:

      scripts/validate-openapi.sh --write

  If the contract change is wrong, revert the contract instead — do not regenerate to match an
  amendment that was not meant to land. First 40 lines of the difference:
EOF
    head -40 /tmp/validate-openapi.diff >&2
    exit 1
fi

echo "validate-openapi: OK — generated types match the contract."