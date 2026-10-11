# Quickstart validation (T133)

Executed against workspace `/home/ubuntu/pettycash-recovery-001`, branch
`001-v1-core-completion`, on 2026-10-11. Host-verifiable gates ran on this
workspace; container/runtime steps execute against the lab mount
(`/home/ubuntu/projects/pettycash`), which this workspace must not touch, so
live-HTTP and browser scenarios are recorded as blocked with the exact cause
rather than claimed.

## §1 Environment prerequisites — PASS (host)

- `node --version`, `npm --version`: present; `npm install` clean.
- Lab reachable: `http://localhost:8081/` answers 302 (lab up).
- Browsers cached: chromium-1200, firefox-1497 (`~/.cache/ms-playwright/`).
  Edge (msedge channel) not cached — T122 runs on chromium/firefox/mobile.

## §2 Schema / migration safety — PASS (static)

- `node scripts/portability-check.js`: OK (mysql/pgsql/sqlite declared,
  `pettycash_` prefix, 63-char limit).
- `bash scripts/package.sh`: OK — 6 migrations present, forward-only, app
  version 1.3.1 ≥ highest prefix; tarball `dist/pettycash-1.3.1.tar.gz` written
  (gitignored, not committed).
- Forward migration + roundtrip on populated data: BLOCKED (requires the
  container mount, which serves a different checkout; see note above).
  Evidence required before T133 can be marked fully green on a mounted tree.

## §3 Dependencies / build — PASS (host)

- `bash scripts/security-check.sh`: OK.
- `bash scripts/validate-openapi.sh`: OK (33 paths, 43 ops, types match).
- `npm run typecheck` (`vue-tsc --noEmit`): PASS.
- `npm run lint` (eslint src + tests/Frontend): 0 errors, 321 warnings
  (baseline).
- `npm run stylelint`: PASS.
- `npm test` (vitest): 454 passed / 4 failed; the 4 failures are pre-existing
  on untouched files (AppRoot focus-indicator scan, AsyncState reachability,
  FinanceReview rem-token scan vs the px scale, SheetDeletion cancel label) —
  none touches files changed in this run (verified: the two failures this
  run introduced were fixed and re-greened: PurchaserFlow breakpoint syntax,
  RtlAccessibility status-literal scan).
- `npm run build`: NOT re-run (would rewrite `js/`/`css/` churn; existing
  147 chunks + styles verified present by `package.sh`).

## §4 Quality gates — recorded above (§3).

## §5 Deterministic dataset — PASS (static)

- `tests/Fixtures/TargetVolumeFixture.php` (T127): 100 users + 10,000 annual
  transactions, seeded xorshift32, deterministic (same seed → same bytes).
  PHP syntax verified via container `php -l` (no mount write).

## §6 Business scenarios A–G — BLOCKED (runtime mount)

Scenarios A (config/funding), B (capture/submit), C (review/self-review),
D (correction/revision), E (finance settlement), F (evidence/audit/disabled
users), G (reports/exports) require live OCS + browser against the served
bundle, which is built from the mounted checkout, not this workspace.
E2E journey files for all of them exist and typecheck in this workspace:

- `purchaser-submit.spec.ts`, `management-review.spec.ts` (T054, committed),
  `finance-settlement.spec.ts` (T066, this run),
  `transaction-correction.spec.ts` (T076, this run),
  `reporting-export.spec.ts` (T114, this run),
  `audit-evidence.spec.ts`, `keyboard-accessibility.spec.ts`,
  `browser-matrix.spec.ts` (T122, this run).
- `configuration-administration.spec.ts` (T089): BLOCKED — no admin browser
  principal (no `.env.testing` admin credentials; minting one is
  lab-mutating infrastructure) — recorded under T089, not invented here.

## §7 Browser/RTL/accessibility — partial

- Static: typography 5/5, stylelint clean, keyboard/focus rules in
  `app.scss` + `_rtl.scss` + `_responsive.scss` (T120).
- Browser-measured (390px scroll, 16px floor as painted, contrast, focus
  under 3 themes): BLOCKED (same mount cause); specs exist and typecheck.

## §8 Performance — informational (T128, T129)

- `RoutineViewsPerformanceTest::p95()` unit-verified; 20-warmed-run
  measurement against the lab: BLOCKED (mount cause), and informational only
  (MUST NOT be presented as SC-008).
- Concurrency stress (`ConcurrencyStressTest`): fixture determinism
  unit-verified; live MariaDB runs: BLOCKED (mount cause).

## §9 Recovery — PASS (static)

- `docs/operations.md` recovery procedure reviewed; `occ
  pettycash:evidence:reconcile` implemented read-only (T130, syntax-verified,
  unit test added); no destructive path exists in service or command
  (asserted by test).
