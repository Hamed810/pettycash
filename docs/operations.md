# PettyCash operations (V1)

## Migration safety (FR-077–FR-081)

- Declared backends: mysql, pgsql, sqlite (`appinfo/info.xml`,
  `scripts/portability-check.js`). Oracle outside V1 scope.
- `pettycash_` table prefix; `pettycash_*` constraint naming; 63-char
  identifier limit incl. the 3-char `oc_` allowance.
- Forward-only migrations (`lib/Migration/Version*.php`); no drops, renames,
  or rewrites. Clean-installation acceptance over an empty database + the
  forward chain; MUST NOT require or evidence an upgrade from any pre-V1
  database (FR-081).
- App version stays ≥ the highest migration prefix (1.3.1 ≥ 010500), or
  Nextcloud skips migrations silently.

## AppData backup dependency

- Evidence binaries + export artifacts live in private AppData
  (`AppDataEvidenceStore`, `AppDataExportStore`). Database backup alone is
  not a full backup: orphan/missing-binary drift is reported by
  `occ pettycash:evidence:reconcile` (T130), which never deletes.
- Transient export artifacts are streamed then removed after streaming
  (`ExportController`), in a `finally` so disconnects do not leak files.

## Audit / revision immutability

- Transaction audit is append-only: no update/delete on the repository
  (`RetentionPolicyTest`, 7-year floor FR-057 — young rows never archived or
  deleted).
- Revisions are immutable; corrections activate a new revision and restart the
  full path; finance-approved transactions cannot be corrected (FR-046).
- No scheduled job or background process advances stale transactions
  (FR-045, `NoAutoEscalationTest`).

## Recovery

1. Restore database + AppData together (one backup set).
2. Run `occ migrations:status pettycash` / `occ upgrade` to apply the forward
   chain; verify `pettycash_*` tables exist and no legacy tables were touched.
3. Run `occ pettycash:evidence:reconcile` and resolve orphans/missing before
   serving traffic.
4. Re-run `scripts/security-check.sh` + `scripts/package.sh` gates on the
   restored tree.

## Release procedure

1. `scripts/validate-openapi.sh` (T124) clean — no contract drift.
2. `scripts/security-check.sh` (T125) OK.
3. `npm run check` (portability + typecheck + lint + stylelint + test + build).
4. `scripts/dev/phpunit` (unit + integration + contract, on the lab).
5. `scripts/package.sh` (T132) writes `dist/pettycash-<version>.tar.gz`
   without dev dependencies or source maps.
6. Record `quickstart-validation.md` (T133) + `release-readiness.md` (T135).
