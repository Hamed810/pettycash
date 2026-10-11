# PettyCash release (V1)

## What V1 ships

Role workflow (purchaser → cost-center → senior → finance), corrections with
immutable revisions, shared finance queue, role-scoped reports + exports
(CSV/XLSX/PDF/ZIP), evidence with public links, Persian RTL UI, per-backend
portability (mysql/pgsql/sqlite).

## What V1 excludes (FR-070, seven exclusions — verified in release-readiness)

1. Scheduled exports. 2. Automatic report delivery. 3. External export
   interfaces. 4. Custom organisational templates. 5. Direct integration with
   other systems. 6. Bulk multi-transaction decisions (FR-033).
   7. Sheet-level audit history (FR-051).

## Entry criteria

- `specs/001-pettycash-v1-core/checklists/traceability.md` covers
  FR-001–FR-088 + SC-001–SC-016 (T134, done).
- `quickstart-validation.md` (T133) records every quickstart scenario.
- `release-readiness.md` (T135) records constitution/compatibility/
  documentation review with the seven FR-070 exclusions named.
- `ui-acceptance.md` (T123) human matrix passed (human-only, not automated).

## Build

`scripts/package.sh` verifies app ID (`pettycash`), namespace (`PettyCash`),
licence (`agpl`), routes, translations (`l10n/fa.json` + `fa.js`),
migrations, and generated assets (`js/`, `css/`, no source maps), then writes
`dist/pettycash-<version>.tar.gz` excluding `vendor/`, `node_modules/`,
`tests/`, maps, and local env. Install on target with
`composer install --no-dev`.

## Deferred to V2 (not defects)

- SC-008 performance bound (informational probes only in V1).
- FR-030 advanced evidence restrictions.
- FR-076 screen-reader association items.
- Oracle/Enterprise backend.
