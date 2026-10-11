# Quickstart validation (T133)

Executed against workspace `/home/ubuntu/pettycash-recovery-001`, branch
`001-v1-core-completion`, on 2026-10-11, with the lab temporarily serving
this workspace (bind mount verified by file hash; restored afterwards, see
final report). Lab-only E2E principals created disposable with documented
teardown; credentials in gitignored `.env.testing`, never committed.

## §1 Environment prerequisites — PASS

- `node`/`npm` present; `npm install` clean; `composer install` clean.
- Lab reachable: `http://localhost:8081/` answers 302 (lab up).
- Browsers: chromium-1200 + headless shell, firefox-1497, system Edge
  (`/usr/bin/microsoft-edge`) — all four Playwright projects executed.

## §2 Schema / migration safety — PASS (runtime)

- `node scripts/portability-check.js`: OK (mysql/pgsql/sqlite declared,
  `pettycash_` prefix, 63-char limit).
- `bash scripts/package.sh`: OK — 6 migrations present, forward-only, app
  version 1.3.1 ≥ highest prefix; tarball `dist/pettycash-1.3.1.tar.gz` written
  (gitignored, not committed).
- `V1SchemaMigrationTest` + migration round trips: green inside the
  integration suite (364 tests green) against the populated lab MariaDB.
  No legacy tables touched; no drops/renames.

## §3 Dependencies / build — PASS

- `bash scripts/security-check.sh`: OK.
- `bash scripts/validate-openapi.sh`: OK (33 paths, 43 ops, types match;
  regenerated once for the documented POST /accounts 200).
- `npm run typecheck` (`vue-tsc --noEmit`): PASS.
- `npm run lint` (eslint src + tests/Frontend): 0 errors (319 warnings).
- `npm run stylelint`: PASS.
- `npm test` (vitest): **458/458 green** (the 4 provisional failures were
  each reproduced, root-caused to stale scans, and repaired with evidence).
- `npm run build`: PASS (12–34s; output verified served by the lab; hash
  churn reverted before commit per repository policy).

## §4 Quality gates — recorded above (§3).

## §5 Deterministic dataset — PASS (runtime)

- `tests/Fixtures/TargetVolumeFixture.php` (T127): 100 users + 10,000 annual
  transactions, seeded xorshift32. Verified live: two generations,
  sha256 `f8dd302d…723ca02c`, identical.

## §6 Business scenarios A–G — PASS (browser + API, clean lab)

E2E journeys green on chromium-desktop, firefox-desktop, edge-desktop,
chromium-mobile (per-file, final spec versions):

- B (capture/submit): `purchaser-submit.spec.ts` (pre-existing, in matrix).
- C (review/self-review): `management-review.spec.ts` (pre-existing).
- D (correction/revision): `transaction-correction.spec.ts` 4/4 ×4 (T076).
- E (finance settlement): `finance-settlement.spec.ts` 6/6 ×4 (T066).
- A (config/funding): `configuration-administration.spec.ts` 6/6 ×4,
  incl. the full admin open→charge→read-back→deactivate lifecycle with
  retained ledger (T089).
- F (evidence/audit): `audit-evidence.spec.ts` (pre-existing, in matrix).
- G (reports/exports): `reporting-export.spec.ts` 5/5 ×4 (T114).

## §7 Browser/RTL/accessibility — PASS (automated)

- Static: typography 5/5, stylelint clean.
- Browser-measured: `browser-matrix.spec.ts` 5/5 ×4 (390px no-scroll,
  16px floor on app text with the recorded T120 library exception,
  keyboard focus incl. dark + forced-colours) and
  `keyboard-accessibility.spec.ts` 6/6 ×4.
- Human visual matrix (T123) remains human-only; see ui-acceptance.md.

## §8 Performance — informational (T128, T129)

- 20 warmed runs each, quiet lab, `time_total`: sheet-list p95 85ms,
  sheet-detail 33ms, transaction-detail 31ms, audit-timeline 28ms,
  evidence-report 30ms (target 3s). Informational only; MUST NOT be
  presented as SC-008 (deferred post-V1).
- Concurrency: fixture determinism hash-verified; settlement/idempotency
  exactly-once proven live by T066 journeys; `ConcurrencyStressTest`
  green inside the integration suite.

## §9 Recovery — PASS

- `docs/operations.md` recovery procedure reviewed; `occ
  pettycash:evidence:reconcile` read-only (T130) with unit test and no
  removal path (asserted). Lab mount restored after verification.
