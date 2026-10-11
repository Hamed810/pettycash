# Release readiness (T135)

Feature 001 V1 core, workspace `/home/ubuntu/pettycash-recovery-001`, branch
`001-v1-core-completion`, 2026-10-11.

## Constitution compliance

- I (source of truth): spec → plan → tasks → contracts → implementation →
  evidence honoured; no requirement redefined from code.
- II (determinism): seeded fixtures (T127), no auto-escalation (FR-045,
  `NoAutoEscalationTest`), idempotency keyed by `X-PettyCash-Command-ID`
  (FR-084).
- III (no guessed config): currency fraction digits from the `Currency`
  aggregate only; no `IRR` fallback in code.
- IV (no silent financial effect): superseded attempts recorded, not dropped;
  refused exports produce no artifact; stale decisions leave state untouched.
- V (traceability): FR-001–FR-088 + SC-001–SC-016 in `traceability.md`
  (T134, done). No exceptions.

## Compatibility

- Nextcloud 34, PHP 8.2–8.5, backends mysql/pgsql/sqlite declared and gated
  (`portability-check.js` clean).
- Browsers: Chrome 120+, Edge 120+, Firefox 115+ ESR (config projects +
  `browser-matrix.spec.ts`).
- No unresolved constitution violations. Approved exceptions: none.

## Documentation

- `docs/architecture.md`, `docs/operations.md`, `docs/release.md` (T131,
  this run) match behavior; measurable RTL criteria, perf targets, auth
  matrix, 100 MB limit, V2 deferrals, browser targets all stated.
- `quickstart-validation.md` (T133, this run) records host gates green +
  runtime-blocked items with cause (no invented evidence).

## FR-070 seven V1 exclusions — verified held

Checked 2026-10-11 by grep + port inspection (each names where checked):

1. Scheduled exports — `grep -rn BackgroundJob|TimedJob|QueuedJob|registerJob|IJobList lib/`:
   zero production hits (only `NoAutoEscalationTest` structural guard
   references them as the forbidden vocabulary). `appinfo/info.xml` declares
   no backgroundjobs/cron. HELD.
2. Automatic report delivery — no mailer, no scheduled sender, no
   delivery endpoint in `appinfo/routes.php`; reports served on demand only.
   HELD.
3. External export interfaces — `ExportController` streams base64 inside OCS;
   no external push, webhook, or third-party upload path in `lib/`. HELD.
4. Custom organisational templates — no template engine, no custom-layout
   store; XLSX layout fixed by `XlsxExporter::COMPLETE_SHEET_COLUMNS`. HELD.
5. Direct integration with other systems — no outbound HTTP to external hosts
   in `lib/` (`grep file_get_contents('http` empty; OCS fetches in E2E are
   same-origin). HELD.
6. Bulk multi-transaction decisions (FR-033) — `ReviewController::
   refuseBulkDecision()` refuses any request naming >1 transaction with a
   `bulk` field error (verified in source). HELD as refusal, not absence.
7. Sheet-level audit history (FR-051) — audit is per-transaction
   (`audit#timeline` takes `transactionId`); no sheet-level audit route or
   query exists. HELD.

## Measurable criteria

- RTL: 390px / AA / ≥12pt / keyboard+focus — static gates green
  (typography 5/5, stylelint clean, 136 Rtl+Purchaser tests green);
  browser-measured matrix blocked by workspace/mount cause (recorded, not
  claimed).
- Performance: p95/error-rate informational (T128); zero-data-loss via
  atomicity suite; SC-008 deferred post-V1 (not claimed).
- Auth matrix, config-audit scope, advisory decisions, 100 MB limit, V2
  deferrals, browser targets: verified in code + docs above.

## Residual blockers (not release-blockers for static closure)

- T089 admin E2E: needs admin browser principal + credentials (lab-mutating).
- T123 human acceptance matrix: genuinely human-only.
- PHP/HTTP/browser runtime verification of this workspace: container mount
  serves `/home/ubuntu/projects/pettycash`, not this workspace; runtime
  evidence must be taken on a mounted tree (see quickstart-validation §6–§8).
