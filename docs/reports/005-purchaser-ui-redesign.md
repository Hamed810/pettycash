# 005-purchaser-ui-redesign — Spec Kit management migration

**Date:** 2026-10-19 (management baseline); **Batch B update:** 2026-10-20; **receipt/creation slice update:** 2026-10-24; **A–E amendment implementation update:** 2026-10-25; **BE-CR-001 `T438`–`T441` closure update:** 2026-10-28; **shared Create/Edit Sheet modal closure update:** 2026-10-29
**Spec:** `specs/005-purchaser-ui-redesign/` (imported audit cards → spec → research/plan/data-model/contracts/quickstart/checklist → canonical task ledger)
**Status:** management baseline complete (2026-10-19); **Batch B frontend implemented and verified 2026-10-20** under the owner directive; **the 2026-10-23 receipt/creation slice is implemented and technically verified 2026-10-24** (001 `T431`–`T435`, 005 `T053`–`T063`); **the 2026-10-24 findings A–E amendment is implemented and technically verified 2026-10-25** under `G-11`/`G-12` (001 `T436`, 005 `T064`–`T072`). `G-13` is deferred, so `T069` is presentation-only and `ADR-008` remains **Proposed — not approved, not implemented**. Backend/API/contracts outside the approved scopes and **all Git writes** remain blocked; English visual acceptance remains blocked on `REC-01` (the `en` catalog needed by the new keys exists, but `REC-01` acceptance is not claimed); `BE-CR-001` is **delivered and verified** — its 001 `T438`–`T441` slices are all `DONE_VERIFIED` 2026-10-28, `T041` is closed, and `BE-CR-002`, `003`, `005`, and `006` remain blocked; the shared Create/Edit Sheet modal tasks are both `DONE_VERIFIED` — `T079` on 2026-10-27 and `T080` on 2026-10-29 under the owner's explicit approval, with the mobile-screenshot inspection limitation recorded in the `T080` closure record; the full PHP suite (`T049`), owner visual acceptance (`T051`), and the final traceability audit (`T052`) remain open. See [`005-receipt-creation-slice.md`](005-receipt-creation-slice.md). The separate `GIT-PLAN-001-R1` checkpoint recorded C1–C6.

## Problem

The Purchaser UI Redesign existed as three unreconciled artifacts and no single status authority:

- an audit package under `docs/frontend-redesign/review/deepseek/` (22 task cards, a gap matrix, owner-decision reconciliation, a blocked-items register, an execution plan);
- a Batch A execution report under `docs/frontend-redesign/review/bunny/` describing the implementation now recorded in C5;
- four governance documents (`CHARTER.md`, `DECISIONS.md`, `STATUS.md`, `ROADMAP.md`) whose `STATUS.md`/`ROADMAP.md` still described F1 as in progress with "no code changes".

There was no canonical requirement set, no canonical status ledger, no traceability from the audit's card IDs to repository artifacts, and no explicit Git boundary for the work.

## What was delivered

| Path | Change |
|---|---|
| `specs/005-purchaser-ui-redesign/spec.md` | New. 5 user stories, `FR-005-001…034`, 10 success criteria, edge cases, blocked journeys, 22-card traceability matrix, approval-state section |
| `specs/005-purchaser-ui-redesign/research.md` | New. 10 management decisions (identity, traceability, non-duplication, status vocabulary, blocked discipline, evidence tiers, governance policy, Git boundary, ledger layout, completion meaning) |
| `specs/005-purchaser-ui-redesign/plan.md` | New. Technical context, constitution check I–V, structure, phases and gates G-1…G-9, risks R1–R9, rollback |
| `specs/005-purchaser-ui-redesign/data-model.md` | New. Presentation/management entities only; no new persisted entity; domain-relationship table |
| `specs/005-purchaser-ui-redesign/contracts/purchaser-ui-contract.md` | New. `C-01…C-12` UI behaviour contract, stack contract, management contract, guard inventory |
| `specs/005-purchaser-ui-redesign/quickstart.md` | New. Automated + manual validation, prohibitions, completion-claim rules |
| `specs/005-purchaser-ui-redesign/checklists/requirements.md` | New. Requirements-quality review incl. traceability/blocked/evidence items |
| `specs/005-purchaser-ui-redesign/tasks.md` | New. **Canonical ledger** `T001…T052`; every card mapped with status + gate |
| `.specify/feature.json` | `specs/004-in-app-navigation` → `specs/005-purchaser-ui-redesign` (machine-local; **gitignored** by `.specify/.gitignore`, so not a commit candidate) |
| `docs/frontend-redesign/CHARTER.md` | Addendum 7 (FA+EN): Spec Kit ownership, audit-as-evidence, card-by-card approval, no-bypass, evidence tiers, Git licensing. No principle changed |
| `docs/frontend-redesign/DECISIONS.md` | Added `D-016…D-019` (owner instructions, 2026-10-19) and `P-005…P-010` (open items). Nothing recorded `Accepted` without an owner instruction |
| `docs/frontend-redesign/STATUS.md` | Reconciled: Batch A partial state, card counts, blockers, next actions; obsolete F1 status replaced (recoverable from Git history) |
| `docs/frontend-redesign/ROADMAP.md` | Phase statuses updated to evidence with `◐/▶/⬜` distinctions; fast-track note on F1; canonical-ledger pointer; explicit "no phase gate opened" statement |

**Deliberately NOT touched:** `src/**`, `tests/**`, `l10n/**`, `lib/**`, `appinfo/**`, `specs/001-pettycash-v1-core/**` (incl. `contracts/ocs-api.yaml` and `contracts/workflow.md`), `specs/004-in-app-navigation/tasks.md` (pre-existing modification), `docs/frontend-redesign/review/**` (audit evidence, read-only for this pass), `js/**`, `css/**` (generated build output), `references/**`, `prototype/**`, `handoff/**`, `_archives/**`, `AGENTS.md`, `CLAUDE.md`, `.claude/`, `.env*`, `docs/Review uploaded pasted content.zip`.

## Card disposition recorded (2026-10-19 baseline)

**Historical.** The 2026-10-19 dispositions below are superseded by the 2026-10-20 Batch B section and by the canonical ledger; they are kept because the baseline is part of the Git plan record.

| Card(s) | Status | Basis |
|---|---|---|
| `UI-PUR-004`, `UI-PUR-008` | `DONE_VERIFIED` (technical) | Batch A report browser evidence + re-executed targeted tests |
| `UI-PUR-001`, `UI-PUR-002` | `PARTIAL` | Named residuals: AC2 surface, AC5 negative test, `REC-01` English labels, `REC-06` filter labels, owner visual acceptance |
| `UI-PUR-005/006/009/010/011/012/013/015/016` (server tier) | `READY_FOR_OWNER_APPROVAL` | No blocker found; not approved, not started |
| `UI-PUR-003`, `UI-PUR-007`, `UI-PUR-014` | `BLOCKED_BY_BACKEND` | Depend on `BE-CR-002/001/003`; no contractually-correct frontend-only path exists |
| `BE-CR-001…006` | `BE-CR-001` `DONE_VERIFIED`; others `BLOCKED_PENDING_OWNER_APPROVAL` | `BE-CR-001` immutable-account policy decided 2026-10-27 (`D-026`), delivery 001 `T438`–`T441` implemented and verified 2026-10-28 and `T041` closed; `BE-CR-002…006` open questions enumerated per request |

## Evidence tiers used in this pass

1. **Re-executed in this pass** — `npx vitest run tests/Frontend/localeFormatting.spec.ts tests/Frontend/Component/PurchaserFlow.spec.ts tests/Frontend/Component/SheetDeletion.spec.ts tests/Frontend/Component/RtlAccessibility.spec.ts` → **4 files / 168 tests passed** (`PurchaserFlow` 137, `localeFormatting` 13, `SheetDeletion` 9, `RtlAccessibility` 9), 2026-10-19.
2. **Report-recorded, not re-executed** — Batch A `npm run check` (23 files / 481 tests) and Playwright scripts (32/32, 8/8 + 8/8, 5/5) as recorded in `review/bunny/BATCH_A_EXECUTION_REPORT.md`.
3. **Source-verified** — `src/utils/{direction,locale,calendar,date,number,money,sheetListSession}.ts`, `SheetListView.vue`, `AppRoot.vue`, `router/index.ts`; test files under `tests/Frontend/`.
4. **Not obtained** — English catalog, English visual acceptance, live calendar surface (AC2), mutating negative test (AC5), owner visual acceptance, any Batch B evidence.

**R1 execution check (separate pass):** `PurchaserFlow`, `RtlAccessibility`, `SheetDeletion`, `routeNameIntegrity`, `localeFormatting`, `FinanceReview`, and `ManagementReview` passed as **7 files / 241 tests** on 2026-10-19.

No aggregate gate is claimed as executed by this pass.

## Batch B frontend implementation and verification (2026-10-20)

**Authority.** The owner directive of 2026-10-20 authorized the approved Purchaser **frontend** work in the real Nextcloud app, through the existing cards, with verification in the running lab: Batch B **T029–T038 plus T022**. Backend changes (`lib/`, `appinfo/`, API/contracts) and every Git write remain blocked. The English catalog remains deferred (`REC-01`), and no visible calendar toggle is authorized (`FR-005-004` / T017).

**Delivered.**

- **Forms and correctness (T029–T033):** cost-center preselect from the most recent active revision (no overwrite), no create-route uploader (save-first hint; empty-ID uploader fails closed), DRAFT-only versioned delete, the save/discard/stay guard with `beforeunload`/route guard/409 refetch, and the canonical PATCH correction path with the live version.
- **Readiness and workspace (T034–T037):** server-tier submission readiness; the `/sheets/:id` overlay workspace with dialog semantics, focus entry/containment/return, Escape/scrim, four tabs with per-sheet memory, the 9-column transaction table, per-stage approvals, Overview, and History from `AuditEvent.after.decision` with honest paging.
- **List and filters (T022):** creation-date filter labels («ایجادشده از تاریخ / تا تاریخ») with the active-calendar hint, plus the 390px shell/filter/table fixes.
- **Parity (T038):** shipped surfaces use theme variables only; localized `StatusBadge`, no-close chips, reduced-motion-aware focus, and responsive desktop/mobile layouts in both directions.

**Verification.**

- `npm run check` passed on rerun: portability, typecheck, lint, stylelint, full suite **524/524**, build, notices. A first full run hit two 5s `PurchaserFlow` timeouts under load; the targeted `PurchaserFlow` run passed **150/150** and the rerun passed.
- Targeted: **9 files / 222 tests** (`SheetWorkspace`, `PurchaserFlow`, `SheetDeletion`, `RtlAccessibility`, `typography`, `l10nCatalog`, `fr071NarrowViewport`, `UnsavedChangesGuard`, `TransactionCorrection`).
- Live `fa` RTL at `http://localhost:8081`: overlay dialog, 4 tabs, 3 approval stages, 3 history events; zero console/page errors; 390×844 full-screen with no overflow; the list shell is 390px with one-column filters; the workspace table fits the dialog content with no horizontal scroll.
- Live `en` LTR: `dir=ltr` on desktop and mobile, no overflow, zero errors; the date reads `10/20/2026`. Raw keys are expected because no `l10n/en.*` exists; that is the measured `REC-01` consequence, not an English visual acceptance.
- Calendar independence: `fa` + Gregorian gives `۲۰۲۶/۱۰/۲۰`; `fa` + Jalali gives `۱۴۰۵/۷/۲۸`; filter hints match. Language did not select the calendar.

**Still blocked or open.**

- `T017` (visible calendar surface) and `T018` (mutating negative test) remain owner-gated; no control was added.
- `T023`/`REC-01`: English catalog sourcing and English visual acceptance.
- `T028` (`BE-CR-002`), `T039` (`BE-CR-001`), `T040` (`BE-CR-003`), and `T045/T046` (`BE-CR-005/006`). `T044` (`BE-CR-004`) is implemented and technically verified; only its residual gates (`T049`, `T051`) remain.
- `T049` (full PHP suite) and `T052` (final traceability audit).
- `T051`: product-owner visual acceptance per card. The screenshots and live checks support it; they do not self-certify it.
- All Git writes.

**Git plan request (not executed).** Branch `feat/pettycash-purchaser-ui-batch-a`, HEAD `a70a9bf`; no staging or commit was performed. The proposed numbered plan is [`docs/reports/GIT-PLAN-002-R1.md`](GIT-PLAN-002-R1.md); it records the exact allowlists and the two blocking owner choices. The Batch B source/test/l10n/documentation change set is uncommitted; the build refreshed `js/` and `css/` (old hashed chunks deleted, new hashed chunks untracked, `css/pettycash-main.css` modified), and those generated assets need an explicit include/exclude decision. Pre-existing unrelated changes are preserved and excluded from any Batch B staging: `AGENTS.md`, `docs/reports/004-in-app-navigation.md`, `specs/004-in-app-navigation/tasks.md`, and the Feature 004 test modifications (`Administration`, `Dashboard`, `FinanceReview`, `ManagementReview`, `Navigation`). `G-BB-ASSETS` remains a commit gate: the ten desktop references are present in the working tree, but the directive authorizes implementation, not an explicit asset handoff; the Git approval must either include them or confirm the handoff. No push is authorized.

## Checkpoint result (R1)

- Branch `feat/pettycash-purchaser-ui-batch-a`; baseline HEAD `6006f28400e8962909afab0744460152353c12fe`; C1–C6 recorded; C7 records this report. No upstream; no push.
- No `*.php` entry in `git status`; `lib/` and `appinfo/` clean.
- Generated `js/`/`css/` output is recorded in C6 (`8869fc6`) after `npm run build` produced output byte-identical to the pre-build backup.
- Pre-existing user changes remain unstaged and preserved: `AGENTS.md`, `CLAUDE.md`, `.claude/`, `docs/Review uploaded pasted content.zip`, Feature 004 tests/reports, the 10 excluded desktop references, generated mobile duplicates, the mobile contact sheet, `_archives/`, screenshots, `test-results/`, and `.env.testing`.
- `l10n/fa.js` and `l10n/fa.json` both carry 362 keys.

## Receipt/creation slice (2026-10-24)

The owner-approved 2026-10-23 slice (`BE-CR-004`) is implemented and technically verified. The detailed evidence is in [`005-receipt-creation-slice.md`](005-receipt-creation-slice.md).

- **Server:** 001 `T431`–`T435` enforce the active expense type's current receipt rule at submission and correction resubmission, aggregate every missing receipt into `transactions.<transactionId>.evidence`, and expose server-derived `evidenceRequired`.
- **Frontend:** 005 `T053`–`T059` implement the receipt-free save, pre-save staging and post-create upload, readiness using `evidenceRequired`/`evidenceCount`, named `422` rendering, and conditional correction resubmission.
- **Fixtures:** T060 adds the debug-gated `pettycash:dev:seed-sheets` command and `scripts/dev/seed-test-sheets` wrapper, verified at 4 sheets / 130 transactions / 66 receipts with a working namespace cleanup.
- **Browser evidence:** T061 passed all four runs (`fa`/`en` × 1440×900/390×844), 46 checks per run, 11 screenshots per run, one expected `422` console response per run, zero page errors; final shape 132 transactions / 69 receipts.
- **Targeted tests:** 4 Vitest files / 190 tests; five container PHPUnit files / 51 tests / 765 assertions.
- **Aggregate gate:** `npm run check` result is recorded in the slice report and in the canonical ledger.
- **Residual gates:** the full PHP suite (`T049`), owner visual acceptance (`T051`), the final traceability audit (`T052`), the English catalog (`REC-01`), and all Git writes remain open. `BE-CR-001/002/003/005/006` were not touched.

No staging, commit, or push was performed by this slice.

## Findings A–E implementation (2026-10-25)

The owner approved `G-11` (005 presentation/transport/fixture amendment) and `G-12` (001 `T436` deletion replay) on 2026-10-25. `G-13` (`ADR-008` / 001 `T437`) was **deferred**, so the draft-history work is presentation-only. The canonical evidence is in `specs/005-purchaser-ui-redesign/tasks.md` Phase 11; this section records the delivered result and the commands that produced it.

### Root causes and fixes

| Finding | Root cause | Fix |
|---|---|---|
| A — false malformed response / unsafe replay | `ocs()` required an OCS envelope even though `DELETE` answers bodyless `204`; the transaction delete read but discarded `X-PettyCash-Command-ID`, ran its ownership/state lookup before any replay check, and `ExpenseSheet::removeTransaction()` compared a method call (`$tx->id()`) to a string, so the sheet kept the deleted row. | Added `ocsVoid`/`mutateVoid`; kept malformed `200` rejection. 001 `T436` requires/validates the command id (`400`), wraps the whole delete in `IdempotencyService` (`transaction.delete`, payload `{transaction_id, expected_version}`, result `204`), maps absent/other-owner/already-deleted to `404 NOT_FOUND`, and fixes the aggregate comparison. |
| B — no transaction surface | The workspace offered only a full-page “View” link; capabilities live on `TransactionDetail`, not the summary. | Added `TransactionSurface.vue` hosted from `SheetDetailView` via `?transaction=<id>`; the standalone route remains for deep links. Local loading/404/retry; no write on open; sibling of the workspace dialog with one `role="dialog"` and workspace `inert`; focus returns to the row. |
| B — capability drift | Edit/Delete were derived from local status, not `allowedActions` + sheet state. | Surface gate: Edit/Delete = `canEdit`/`canDelete` + transaction `DRAFT` + sheet `OPEN`; Correct = `canCorrect` + `AWAITING_PURCHASER_CORRECTION` (not `OPEN`); review flags never render purchaser actions. Form delete uses the same gate. |
| C — no evidence preview | `EvidenceList` was download-only. | Added `evidencePreview.ts` + `EvidencePreview.vue`: inline PDF/images, `text/plain` rendered as text, SVG/HTML/unknown download-only; failure keeps download; accessible nested dialog with Escape, focus return, object-URL revocation, and no horizontal scroll at 390px. |
| D — draft history appears official | Draft creation/PATCH writes draft-stage revisions/events; the detail view rendered them as official history. | Presentation only: `DRAFT` (by status) shows one working-draft state with no revision selector/tabs/prior list or official audit feed; submitted transactions filter `REVISION_CREATED`/`TRANSACTION_CHANGED` with `stage === 'DRAFT'`; un-resubmitted corrections are labelled working. `useSheetHistory` skips `DRAFT` feeds. No stored row deleted, rewritten, renumbered, or backfilled. Server boundary **not** implemented (`T437` open). |
| E — stale loading indicators | One shared `loading` boolean covered list/detail/evidence/history/mutations. | Per-resource loading/error state with request tokens in `sheets.ts`/`transactions.ts`; full-page loader only when no sheet for the current id is loaded; superseded responses ignored; failed background refetch keeps data; per-transaction delete single-flight. |

Additional changes: `useTransactionDeletion.ts` (one command id per `(transactionId, expectedVersion)` intent, single-flight, `404 → already-deleted`, `409/422 → refetch`), new l10n keys in `en`/`fa` catalogs, and `t060-fixtures.mjs` shared E2E helpers.

### Commands and results

| Tier | Command | Result |
|---|---|---|
| Targeted Vitest | 12 named files (`http`, `PurchaserFlow`, `SheetDeletion`, `SheetWorkspace`, `TransactionSurface`, `EvidencePreview`, `LoadingIsolation`, `DraftHistory`, `DraftDeletion`, `TransactionCorrection`, `RtlAccessibility`, `l10nCatalog`) | **12 files / 302 tests passed** |
| Full frontend gate | `npm run check` | **31 files / 610 tests passed**; OpenAPI, portability, error-rate self-test, typecheck, lint (0 errors / 278 warnings), stylelint, build, notices all green |
| 001 `T436` backend | `scripts/dev/phpunit` — `TransactionServiceDeleteTest`, `DeleteCommandIdExemptionTest`, `CompletedSheetLockTest`, `StaleVersionConflictTest`, `PurchaserFlowControllerTest` | **9/9 (15 assertions), 11/11 (47), 12/12 (79), 6/6 (24), 63/63 (483)**; `debug=false` |
| Browser A–E | `T072_LOCALE={en,fa} T072_VIEWPORT={desktop,mobile} node tests/E2E/t072-transaction-surface-evidence.mjs` | **en-desktop 56/56, en-mobile 60/60, fa-desktop 56/56, fa-mobile 60/60**; 10 screenshots per run; only console error is the intentional `404` replay probe; zero page errors |
| Fixture restore | `T061_LOCALE=en T061_VIEWPORT=desktop node tests/E2E/t061-receipt-slice-evidence.mjs` ×2 | **49/49 checks each**; each run started at 130 transactions / 66 receipts and restored `{25: 25, 30: 30, 35: 35, 40: 40}` / 130/66 |

### Browser evidence paths

- `/tmp/opencode/t072/en-desktop/` — `00-sheet-list.png` … `09-submitted-transaction-readonly.png`, plus `evidence.json`
- `/tmp/opencode/t072/en-mobile/`, `/tmp/opencode/t072/fa-desktop/`, `/tmp/opencode/t072/fa-mobile/` — same ten checkpoint names
- `/tmp/opencode/t071/run-1.json`, `/tmp/opencode/t071/run-2.json` — the two consecutive T061 runs with pre/post reseed records
- `/tmp/opencode/t061/en-desktop/` — latest T061 screenshots and `evidence.json`

The T072 run covers: delete with evidence, delete without evidence, same-command-id replay `204`, new-command-id `404`, the four-capability matrix on a returned transaction, inline text preview, SVG download-only, draft history suppression, a list refetch during a loaded detail, a submitted read-only surface, one dialog at 390px, no horizontal scroll at 390px, and focus return.

### Boundaries and caveats

- `T437` / `ADR-008` are **not implemented**. The server still writes draft-stage revisions/events; `T069` filters them at the presentation layer only.
- Receipt enforcement timing is unchanged: the receipt rule still applies at sheet submission and correction resubmission.
- No migration, stored revision/audit deletion, or fixture-count change was made. The 25/30/35/40 baseline and 130/66 totals are asserted before and after the mutating browser run.
- No commit, staging, or push was performed. The working tree remains dirty with pre-existing changes.

## Phase 13 owner-review checkpoint (2026-10-25; T075 closed 2026-10-26)

The owner directive registered exactly two tasks: `T074` (record the approved UI freeze) and `T075` (the single OPEN-sheet eye-icon exception). Completed tasks were not renumbered or re-opened; `T437` and `ADR-008` remain out of scope.

- **`T074` — freeze recorded, documentation only.** The Sheets List (`/apps/pettycash#/sheets`) and the sheet transaction workspace (including its existing tabs) are recorded as the protected baseline in the specification (`FR-005-045`, `SC-005-019`), the contract (`C-19`), the plan, `DECISIONS.md` (`D-023`), and the ledger. No application source changed.
- **`T075` — `DONE_VERIFIED` (owner visual approval 2026-10-26).** For an `OPEN` sheet, an authorized editable `DRAFT` row eye click now opens the existing `TransactionFormView` inside `TransactionSurface` with the persisted revision prefilled. The read-only surface remains the fallback and every non-`OPEN` sheet keeps inspection/correction behavior unchanged. Evidence list, inline-safe preview, download, upload, removal, draft save, and draft delete use the existing APIs; save does not submit the sheet or require a receipt. The 2026-10-26 refinement makes the existing primary `submit-btn` the single non-correction footer save action (`Save draft` in CREATE, `Save changes` in OPEN/DRAFT EDIT), with `transaction-save-draft` hidden only in those modes; correction keeps its distinct Save draft plus `Resubmit`, and submitted/non-editable and legacy `DRAFT`-on-non-`OPEN` footers are unchanged. Closing returns to the same sheet workspace. No second form, full-page navigation, backend change, or permission change was introduced.

### Verification

| Tier | Command | Result |
|---|---|---|
| New focused guard | `npx vitest run tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts` | **6/6 passed** |
| Related surface/evidence specs | `TransactionSurface.spec.ts`, `SheetWorkspace.spec.ts`, `EvidencePreview.spec.ts` | **47/47 passed** |
| Purchaser/receipt/correction specs | `PurchaserFlow.spec.ts`, `StagedReceipts.spec.ts`, `TransactionCorrection.spec.ts` | **178/178 passed** |
| TypeScript | `npx vue-tsc --noEmit` | clean |
| Lint/style (file-scoped) | ESLint, Stylelint on the changed frontend files | 0 errors; one pre-existing `vue/no-required-prop-with-default` warning |
| Build | `npm run build` | succeeded in 13.42 s |
| Official Chrome | `T071_SKIP_RESEED=1 node artifacts/visual-review/t075/iteration-1/run.mjs` | **38/38 checks**, 7 screenshots, **0 PettyCash writes**, 0 page/console errors; Chrome `156.0.8078.47` |
| Refinement focused suite | `PurchaserFlow`, `OpenSheetTransactionEdit`, `TransactionCorrection`, `StagedReceipts`, `l10nCatalog` | **196/196 passed** |
| Refinement Chrome review | `T071_SKIP_RESEED=1 node artifacts/visual-review/t075/iteration-2/run.mjs` | **28/28 checks**, exactly 4 screenshots, **0 PettyCash writes**, 0 page/console errors; Chrome `156.0.8078.47`; `fa` RTL |
| Enter-to-save | `node artifacts/visual-review/t075/iteration-2/enter-to-save.mjs` | pass; one `POST /sheets/{id}/transactions` reached the existing handler and was aborted before sending; **0 writes** |
| Backend DRAFT preservation | `scripts/dev/phpunit .specify/bugs/open-sheet-draft-status/ReproTest.php`; `scripts/dev/phpunit tests/Integration/Expense/TransactionServiceUpdateTest.php` | **1/4** (`status=DRAFT stage=DRAFT`, sheet `OPEN`); **9/40** |

### Owner-review screenshots

- `artifacts/visual-review/t075/iteration-1/desktop/01-open-edit-prefilled.png` — OPEN eye click lands on the prefilled edit form.
- `.../desktop/02-open-edit-evidence-list.png` — evidence list and draft actions remain present.
- `.../desktop/03-open-edit-receipt-preview.png` — inline receipt preview with the surface inert beneath it.
- `.../desktop/04-non-open-inspection-unchanged.png` — non-OPEN returned transaction keeps inspection and Correction.
- `.../mobile/05-open-edit-mobile.png`, `06-open-edit-evidence-list-mobile.png`, `07-open-edit-receipt-preview-mobile.png` — narrow-viewport behavior.

The 390 px evidence-row and preview-title compression also reproduces on the unchanged non-OPEN read-only surface (comparison captures under `.../pre-existing/`). It is pre-existing frozen responsive behavior, not introduced by `T075`; it was not altered and needs a separately authorized task if the owner wants it fixed.

### Iteration-2 owner review — 2026-10-26

- `artifacts/visual-review/t075/iteration-2/desktop/01-create-desktop.png` — CREATE with exactly one primary `Save draft` (`ذخیره پیش‌نویس`).
- `.../desktop/02-open-draft-edit-desktop.png` — OPEN/DRAFT EDIT with exactly one primary `Save changes` (`ذخیره تغییرات`).
- `.../mobile/03-create-mobile-390.png`, `.../mobile/04-open-draft-edit-mobile-390.png` — the same two footers at 390 × 844.

Official Chrome `156.0.8078.47`, Persian RTL: 28/28 checks, exactly four screenshots, 0 PettyCash writes, 0 page/console errors. The owner visually approved both desktop and 390px results on **2026-10-26**; the targeted Enter-to-save check and the backend DRAFT-preservation rerun are recorded above. `T075` is **`DONE_VERIFIED`** with its checkbox checked. `iteration-1` remains unchanged and historical. No staging, commit, or push was performed; the working tree retains unrelated pre-existing changes.

## T079 analysis reconciliation (2026-10-26; B1/B2 acceptance corrected 2026-10-27; documentation only)

The official `/speckit.analyze` pass for `T079` reported findings F1–F8. They are resolved in the existing specification, plan, task ledger, purchaser UI contract, `D-025`, and this report. The 2026-10-27 B1/B2 correction aligns the post-create list acceptance with the preserved `createNewSheet` store action and replaces the simultaneous-select visual check with the approved account-selector/read-only-currency sequence. This reconciliation changed no application source, test, build, runtime, or Git state.

- **F1 — controlled dismissal.** Modal visibility derives from the `sheet-create` route. Cancel, Escape, and the dialog close affordance use one controlled close path. A pristine form closes directly; a dirty form presents the existing unsaved-changes decision inside the same dialog, so exactly one `[role="dialog"]` is mounted for the whole flow. Stay restores entered values and focus, Discard closes without creating, Save keeps its existing save-and-leave behavior, and the shared `UnsavedChangesDialog.vue` and the guard composable's global semantics are unchanged.
- **F2 — post-create list state.** A successful create closes the modal and returns to the Sheets List with the current filters and page preserved where possible. The list is refetched through the existing fetch mechanism and the final visible list reflects the authoritative server response; the existing `createNewSheet` action and store mutation semantics are preserved, but the view does not rely on the action's temporary insertion, and a sheet that does not match the active filter is not forced into the filtered view. A polite `sheets.createSuccess` notification is shown, the new sheet is not opened, and the frozen list presentation is unchanged. Focused verification covers filtered and paginated post-create states.
- **F3 — labels and accessible identity.** The modal heading and primary action both use `sheets.create`, giving the dialog a non-empty accessible name. The sheet-name field uses the dedicated `sheets.name` key (`Sheet name` / `نام تنخواه`). Exactly two narrow keys, `sheets.name` and `sheets.createSuccess` (`Sheet created` / `برگه ایجاد شد`), are authorized for `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, and `l10n/fa.js` with key parity; `REC-01` remains open and no broader English-catalog work is authorized.
- **F4 — zero-account state.** Zero-account provisioning remains server-authoritative. No fake account, `undefined` label, invalid balance, or derived currency is rendered; the neutral `sheets.noAccounts` state is shown, the balance/currency rows are omitted, and `accountId` is omitted from the request.
- **F5 — multi-account verification and currency binding.** Acceptance requires a fixture with at least two selectable accounts. The reviewer opens and inspects the account selector, verifies valid, readable account labels and option values with no `undefined`, selects an account, verifies the displayed balance and derived currency, and verifies that the currency control remains read-only or disabled with no `undefined` label; the account and currency selectors need not be open simultaneously. The getter-only display currency is bound with an appropriate one-way `:model-value="currency"` binding on the currency `NcSelect` and readonly `NcTextField`; no writable computed, setter, domain-currency change, or enabled currency editing is permitted.
- **F6 — completion record.** `T079` closure requires same-change reconciliation of `spec.md`, `plan.md`, `tasks.md`, `contracts/purchaser-ui-contract.md`, `D-025`, and this report. `T079` is `DONE_VERIFIED` under explicit owner visual approval (2026-10-27), and this record completes that reconciliation.
- **F7 — warning presentation.** The normal Create Sheet modal uses no warning-style background or note card. Validation, account-load, mutation, and conflict feedback remain visible, accessible, and exempt from that normal-state prohibition.
- **F8 — focus management.** Initial focus moves to the sheet-name field. After close, focus returns to the invoking Create Sheet button when available, or to the Sheets List heading for direct `/sheets/create` entry; direct-route entry and focus return are required acceptance coverage.

At that date `T080` remained `BLOCKED_BY_BACKEND` on the then-unimplemented `BE-CR-001`/C-10 backend projection and immutable-update behaviour; the owner decided the immutable-account policy on 2026-10-27 (`D-026`), and the `T079` implementation and approval gate it also depended on is satisfied, but neither unblocked `T080` without that backend delivery and a separate implementation authorization. There is no replacement-path decision outstanding. No task was added, no completed task was reopened, and no implementation, test, build, staging, commit, or push occurred in this reconciliation; the separate 2026-10-27 implementation pass is recorded in the next section. **Update 2026-10-28:** the `BE-CR-001` backend delivery (`T438`–`T441`) is complete and verified; `T080` retains the label pending its own implementation authorization and visual gate.

## T079 implementation and official-Chrome owner-review evidence (2026-10-27; implemented, owner-approved, DONE_VERIFIED)

`T079` is implemented, minimally verified, and **`DONE_VERIFIED`** under the owner's explicit visual approval of all eight screenshots on 2026-10-27. `T080` was not started at that date; it was implemented on 2026-10-29 (record below).

**Implementation.** `/sheets/create` now renders `SheetListView` with one route-driven `NcDialog` open; the list stays mounted behind it and `SheetDetailView` is not used for create. `SheetFormView.vue` remains the single form implementation and gained an `inDialog` mode: the dialog heading/action use `sheets.create`, the field uses `sheets.name`, the footer is hidden while the inline unsaved-changes decision is open, focus enters the title field and returns to the invoking control (or the list heading for direct entry), and the zero-account state omits the account/balance/currency rows and `accountId`. Cancel, Escape, and the close affordance share one controlled close path; a dirty form renders the existing guard inside the same dialog as `role="group"`, so exactly one `[role="dialog"]` exists, with Stay/Discard/Save preserving the guard's existing semantics. The account selector now uses `label="label"` with `inputLabel` as the visible caption, the currency select uses `reduce`/`get-option-key` with `:model-value="currency"`, and the in-dialog account menu stays attached to its field (`:append-to-body="!inDialog"`) so the RTL menu is not pushed to the viewport edge. After a successful create the list refetches through the existing fetch mechanism, preserves the current page/filters, shows a polite `sheets.createSuccess`, and does not open the new sheet. `createNewSheet`, `mutationLoading`, error/conflict handling, and `src/stores/sheets.ts` are unchanged.

Only the authorized localization changes were made: `sheets.name` (`Sheet name` / `نام تنخواه`) and `sheets.createSuccess` (`Sheet created` / `برگه ایجاد شد`) in `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, and `l10n/fa.js`, with key parity (479 keys in each catalog, no missing keys). `REC-01` remains open.

**Commands and results (2026-10-27).**

| Command | Result |
|---|---|
| `npx vue-tsc --noEmit` | clean, exit 0 |
| `npm run build` | passes |
| `npx vitest run tests/Frontend/Component/SheetFormDialog.spec.ts tests/Frontend/l10nCatalog.spec.ts` | 2 files / 17 tests passed |
| `npx vitest run` on `PurchaserFlow`, `NextcloudVueContract`, `l10nCatalog`, `ApplicationShell`, `routeNameIntegrity`, `RtlAccessibility`, `keyboard-accessibility` | 7 files / 191 tests passed |
| `npx eslint` on the three changed components, router, and focused spec | 0 errors, 36 pre-existing `vue/html-indent` warnings |
| `npx stylelint` on `SheetFormView.vue` and `SheetFormDialog.vue` | clean |
| `node artifacts/visual-review/t079/iteration-1/run.mjs` | evidence written; 37/37 checks passed |

**Official Chrome evidence.** Official Google Chrome `156.0.8078.47` (`channel: 'chrome'`), Persian RTL, desktop 1440×900 and 390×844. Eight screenshots under `artifacts/visual-review/t079/iteration-1/` (Git-ignored):

- `desktop/01-create-dialog.png` — neutral one-dialog create surface, `نام تنخواه` field, account, balance-only currency.
- `desktop/02-unsaved-guard-inside-dialog.png` — dirty Escape shows Stay/Discard/Save inside the same dialog with the form footer removed.
- `desktop/03-account-selector-open.png` — the selector opens attached to the field with readable `IRR`/`USD` options and no `undefined`.
- `desktop/04-account-usd-selected.png` — selecting USD shows its balance and derived currency while the currency control stays disabled.
- `mobile/01-create-dialog-390.png`, `mobile/02-unsaved-guard-inside-dialog-390.png`, `mobile/03-account-selector-open.png`, `mobile/04-account-usd-selected.png` — the same four states at 390×844 with no horizontal overflow (`scrollWidth` 390 / `innerWidth` 390).

`record.json` records 37 passing checks, the browser version, 0 writes, 0 console errors, 0 page errors, and restored settings (`employee1` language `en` → `fa` → `en`; first-run wizard value unchanged). Focus starts in the title field; a pristine close returns to `#/sheets`; Stay preserves the typed title; the primary action is inside both viewports; the list stays mounted; and no `undefined` text is rendered.

**Changed paths attributable to `T079`.** `src/components/sheets/SheetFormDialog.vue` (new), `src/views/sheets/SheetFormView.vue`, `src/views/sheets/SheetListView.vue`, `src/router/index.ts`, `tests/Frontend/Component/SheetFormDialog.spec.ts` (new), `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js` (`T079` contributed only the two authorized keys), `artifacts/visual-review/t079/iteration-1/` (Git-ignored), and the six documentation artifacts in this record.

**Git state.** The working tree already carried broad, unrelated changes before this task and was not staged, reset, cleaned, committed, or pushed. `git status --short` marks `src/views/sheets/SheetFormView.vue`, `src/views/sheets/SheetListView.vue`, `src/router/index.ts`, `l10n/fa.json`, `l10n/fa.js`, and the six documentation artifacts as modified, and `src/components/sheets/SheetFormDialog.vue` plus `tests/Frontend/Component/SheetFormDialog.spec.ts` as untracked; `l10n/en.json`/`l10n/en.js` are untracked from earlier work, and `T079`'s only changes there are the two authorized keys; the previously tracked `l10n/fa.json`/`l10n/fa.js` also carry earlier unrelated edits. `src/stores/sheets.ts` is dirty from earlier work and was **not** modified by `T079`, and no backend, API, authorization, financial, or persistence file changed.

**Owner approval (recorded 2026-10-27).** Verbatim: “Approve all eight.” The owner approved the eight official-Chrome screenshots listed above for the T079 Create Sheet modal at desktop 1440×900 and 390×844 in Persian RTL. With that approval `T079` is `DONE_VERIFIED` and its ledger checkbox is checked; `T080` remains `BLOCKED_BY_BACKEND` and no `T080` work has started.

## Risks carried forward

| Risk | Note |
|---|---|
| Blocked cards being approximated client-side | Recorded as `FR-005-012/019/020` prohibitions; no workaround task exists in the ledger |
| Contract edited to match implementation | `specs/001-*` and both contracts are named out of scope; 001 `T436` was separately approved (`G-12`) |
| Report-recorded evidence upgraded to re-executed | Four-tier separation is stated in spec, plan, quickstart, and this report; every 2026-10-25 result names the command that produced it |
| Presentation change claimed as the domain boundary | `T069` is explicitly presentation-only; `T437`/`ADR-008` remain Proposed (`G-13` deferred) and no server-boundary claim is made |
| Fixture residue read as product data | `t061` reseeds before/after; the report and ledger state 25/30/35/40 and 130/66, with 41/31 recorded as run residue only |
| Generated assets mixed with documentation | Resolved: C6 contains only `js/`/`css/`; C1–C5 contain no build output |
| Batch A work loss | Resolved: C5 (`fae7bce`) records the source, tests, and l10n; the working tree retains only excluded Feature 004/user changes |

## Asset availability

`GIT-PLAN-001-R1` includes the design registry, the approved desktop list (`01`), the unsaved-changes dialog, and the six 390px mobile references in C4. The following desktop references remain in the working tree and are required before Batch B:

- `02_sheet_detail_open_EN_reference.png`
- `03_sheet_detail_submitted_cc_EN_reference.png`
- `04_sheet_detail_completed_workflow_EN_reference.png`
- `05_sheet_approvals_tab_EN_reference.png`
- `06_sheet_overview_tab_EN_reference.png`
- `07_sheet_history_tab_EN_reference.png`
- `08_transaction_create_EN_reference.png`
- `09_transaction_correction_EN_reference.png`
- `10_transaction_read_only_EN_reference.png`
- `11_transaction_read_only_revisions_EN_reference.png`

**Gate `G-BB-ASSETS`:** no Batch B commit may be created until these ten files are committed, or an owner-approved equivalent handoff exists. The 2026-10-20 directive authorizes implementation, not an explicit asset handoff; the commit decision still needs the files included or the owner's confirmation. Their sizes and SHA-256 values are recorded in `docs/reports/GIT-PLAN-001-R1.md`.

The three generated mobile duplicates and `references/contact-sheets/mobile_contact_sheet.png` are excluded.

## Next actions

1. Product owner: review the Batch B and A–E screenshots and record `T051` visual acceptance per completed card; the live checks support acceptance but cannot self-certify it.
2. Product owner: decide `REC-01`/`P-005` and `P-008` (calendar surface); authorize or decline `T017`/`T018`.
3. Product owner: decide `G-13` (`ADR-008` / 001 `T437`). Until then the draft-history work stays presentation-only and the server boundary remains unimplemented.
4. Product owner: approve the exact Git plan for the Batch B and A–E source/test/l10n/docs change set, the generated `js/`/`css/` include/exclude decision, and the `G-BB-ASSETS` treatment; no staging, commit, or push has been performed.
5. Backend owner: the 2026-10-27 `BE-CR-001` immutable-account delivery is complete (the decision is recorded in `D-026`; 001 `T438`–`T441` were authorized, implemented, and verified on 2026-10-28, and `T041` is closed), answer the remaining `BE-CR-002/003` questions, and scope `BE-CR-005/006`; only then can `T028`/`T039`/`T040`/`T045`/`T046` proceed. `BE-CR-004` was approved for the receipt/creation scope and implemented/verified on 2026-10-24; 001 `T436` was approved and implemented on 2026-10-25. Run the full PHP suite (`T049`) and record owner acceptance (`T051`) before closing either.
6. Push/merge remains unauthorized; `GIT-PLAN-003` for push/PR is not prepared; the checkpoint plan `GIT-PLAN-002-R1` is proposed.

## BE-CR-001 clarification record (2026-10-27; documentation only)

The product owner decided the complete `BE-CR-001` sheet-account policy through the installed Spec Kit clarification workflow and then selected **Option A: the source account is immutable after creation**. The decisions are:

1. **The source account is immutable.** It is selected at creation and MUST NOT change afterwards. This supersedes and withdraws the earlier same-currency replacement answer; no account-model, schema, constraint, migration, or second-account fixture change is authorized.
2. **The sheet currency is immutable** after creation; no conversion or reinterpretation of existing transaction amounts.
3. **Only the title may change while the sheet is `OPEN`.** A title-only PATCH omits `accountId` and preserves the stored account — active or inactive — and the stored currency. An inactive existing account does not block the update and is displayed accurately and read-only.
4. **Any supplied `accountId` is refused before persistence** — including `null`, an empty/whitespace value, the current account, an inactive account, or an owned/active/same-currency account — with `422 VALIDATION_FAILED` and `fieldErrors.accountId`. Only omission is accepted; no mutation is persisted.
5. **Corrupted references** are detected and reported as `422 DATA_FAULT`, never silently repaired, replaced, or re-denominated. Because no account is supplied, no corruption variant becomes a field-level account error.
6. **The public `accountId`** is declared on `SheetDetail` only; `SheetSummary` and the Sheets List response are unchanged.
7. **Settlement semantics are unchanged**; `sheet.accountId` is not a settlement instruction.

Recorded in `specs/005-purchaser-ui-redesign/spec.md` (`## Clarifications` Session 2026-10-27; `FR-005-019`; `FR-005-050`; `SC-005-023`; traceability), `specs/001-pettycash-v1-core/spec.md` (Session 2026-10-27; `FR-019`; `FR-021`), `specs/001-pettycash-v1-core/data-model.md` (Expense Sheet rules), `docs/frontend-redesign/DECISIONS.md` (`D-026`), and the BE-CR-001/BLOCKED_ITEMS investigation records. `T080` then remained `BLOCKED_BY_BACKEND`; no application code, API/OpenAPI contract, test, build, task, commit, or push was produced by this clarification pass.

## BE-CR-001 implementation planning (2026-10-27) and `T438`–`T441` execution (2026-10-28)

The 2026-10-27 planning pass registered the minimum backend delivery as 001 Phase 77 `T438`–`T441`.
The owner then authorized all four in order; each was implemented and verified on 2026-10-28, and
`T041` closed with the delivery. `T441` added one dashboard account-fault test and documentation only;
no application source changed in the closure slice.

### Tasks, dependencies, and order

| Task | Scope | Depends on | State |
|---|---|---|---|
| `T438` | Repository account-identity integrity: resolve the stored account to its public UUID and currency; a missing/dangling account or a currency disagreement throws `UnreadableRecordData` with an account-reference discriminator, while a missing sheet-currency row throws it without the discriminator; `save()` refuses an unresolvable account; no `''`/`0`/default-currency substitution or repair | — | `DONE_VERIFIED` (2026-10-28) |
| `T439` | Contract and projection: required `SheetDetail.accountId`; new title-only `SheetUpdate` PATCH schema (`title` and `expectedVersion` required, `additionalProperties: false`, no `accountId` property); `SheetSummary` and `SheetCreate` unchanged; regenerate `src/generated/api.ts` only via `scripts/validate-openapi.sh --write`; update `src/types/api.ts` and the service/store request type; retarget the list-absence test, allow `accountId` only in the detail conformance check, and update the provisioning identity note | `T438` | `DONE_VERIFIED` (2026-10-28) |
| `T440` | Application: accept only omission of `accountId`; refuse any supplied value before mutation with `422 VALIDATION_FAILED`/`fieldErrors.accountId` via a narrow `SheetAccountInvalid`; preserve an inactive stored account on a title-only save; both corruption variants remain `422 DATA_FAULT`; `OcsErrorMapper` logs/withholds; `ExpenseSheet::$currency` readonly; full-page edit displays account/currency read-only with no `accounts[0]` and never sends `accountId`; official Chrome desktop + 390px Persian-RTL evidence and owner visual-approval stop; preserve owner scoping, `OPEN`-only refusal, and stale-version `409` ordering | `T439` | `DONE_VERIFIED` (2026-10-28; owner limited visual approval recorded verbatim) |
| `T441` | Focused contract/integration/repository/formatter/conformance/provisioning/dashboard tests, OpenAPI gate, `npm run typecheck`, targeted Vitest/lint, settlement/`SheetSummary` regression, post-delivery status transitions, and the closure/operator-remediation record; records the accepted pre-release compatibility impact, the required MAJOR public-contract decision at release, and the release/versioning decision | `T439`, `T440` | `DONE_VERIFIED` (2026-10-28) |

Recommended execution order: **`T438` → `T439` → `T440` → `T441`**.

### `T438` execution record (2026-10-28)

`T438` is implemented and verified; its evidence lives in 001 `tasks.md` Phase 77 and in
`BE-CR-001.md` §10. In outline: `UnreadableRecordData` now carries the structural
`accountReference()`/`isAccountReference()` discriminator; `DbExpenseSheetRepository::mapRow()` resolves
the sheet currency first and then the account, throwing the account-reference variant for a missing
account row or an account/currency mismatch and the general variant for a missing sheet currency;
`save()` resolves the public account id before either statement and refuses an unknown id instead of
writing `account_id = 0`; the inactive-but-resolvable read is preserved and the account lookup does not
filter `active`. `OcsErrorMapper` was unchanged. The corrupt-record fixture gained `account` and
`account-currency` probes and the roundtrip runner records both refusals, the normal round trip, the
inactive read, and the no-write refusal. Focused PHPUnit results: the six new roundtrip cases **6/6, 20
assertions**; full `RepositoryRoundTripTest` **80/80, 390 assertions**; `DataIntegritySheetAccountTest`
plus the existing `DataIntegritySheetCurrencyTest` **11/11, 46 assertions**. No contract, generated type,
schema, migration, settlement, or frontend change was made. Two 2026-10-11 E2E transactions (ids
2438/2441) retain dangling `active_revision_id` values, so `DataIntegrityReportTest`'s `/reports/process`
control still answers `422` from that pre-existing transaction-revision fault; it is unrelated to
`T438` and was neither repaired nor deleted.

### `T439` execution record (2026-10-28)

`T439` is implemented and verified; its evidence lives in 001 `tasks.md` Phase 77 and in
`BE-CR-001.md` §10. In outline: `SheetDetail`'s detail-only `allOf` member now requires UUID
`accountId`, while `SheetSummary` and `SheetCreate` are unchanged and the Sheets List emits no account.
`PATCH /sheets/{sheetId}` now uses the dedicated `SheetUpdate` schema (required `title` and
`expectedVersion`, `additionalProperties: false`, no `accountId`, no FR-020 provisioning semantics), and
the operation/schema descriptions state that a supplied `accountId` is refused with
`422 VALIDATION_FAILED`/`fieldErrors.accountId` before persistence — `T440` enforces it.
`SheetPayloadFormatter::detail()` emits the stored public `$sheet->accountId`; `summary()`/`summaries()`
were not changed. `src/generated/api.ts` was regenerated only through
`scripts/validate-openapi.sh --write`; both it and `src/types/api.ts` expose the field and `SheetUpdate`;
the service/store and edit form build a title-only body. The retargeted list test asserts no list item
carries `accountId`, the detail test asserts it equals the fixture account, and the provisioning test
asserts it equals the provisioned account's public id. Focused PHPUnit:
`SheetPayloadFormatterAccountTest` **4/4, 11 assertions**; `SheetSummaryConformanceTest` **6/6, 165
assertions, 1 pre-existing skip**; `PurchaserFlowControllerTest` **64/64, 521 assertions**;
`SheetProvisioningContractTest` **3/3, 28 assertions**. Targeted Vitest (`LoadingIsolation`,
`SheetFormDialog`, `PurchaserFlow`) **174/174**; `scripts/validate-openapi.sh` exit 0; `npm run
typecheck` and scoped ESLint clean. **Transitional risk:** the controller still required `accountId`
until `T440`, so a title-only edit save answered `422` in this slice; no account-guessing path was
added. This was resolved by `T440` later on 2026-10-28. **Regeneration note:** `--write` also refreshed pre-existing uncommitted contract drift already
present in the working tree (transaction-delete/evidence amendments) because the generator is
whole-file; the generated file now matches the current contract byte-for-byte.

### Required backend and contract changes

- **No schema change and no migration.** `account_id` and `currency_id` already exist; there is no
  backfill, rewrite, cross-currency migration, new persisted structure, or account-model change.
- **Read side:** an unreadable stored account, a missing sheet currency, or an account/sheet currency
  disagreement surfaces as the existing `422 DATA_FAULT` through `UnreadableRecordData`; the account
  variant carries the discriminator for diagnostics, and the message is logged and withheld from the
  caller per FR-083. A pre-existing corrupt row is not repaired; it remains refused until operator-only
  out-of-band remediation. An inactive-but-resolvable account is not corruption and stays readable.
- **Write side:** `PATCH /sheets/{id}` accepts only a body that omits `accountId`. Any supplied value is
  an attempted assignment and answers `422 VALIDATION_FAILED` with `fieldErrors.accountId` before any
  title, account, currency, or lock-version mutation. A title-only update succeeds even when the stored
  account is inactive, preserving it. Neither corruption variant is converted to that field error.
- **Contract:** `SheetDetail.accountId` is added; `SheetSummary` stays unchanged; PATCH gets a
  title-only `SheetUpdate` schema instead of reusing create-specific `SheetCreate`; the generated
  TypeScript file is produced only by the repository script.
- **Settlement, authorization, and currency:** no change. Settlement still resolves from the transaction
  owner and transaction currency; `sheet.accountId` is descriptive; no new permission and no automatic
  conversion.

### `T440` execution record (2026-10-28)

`T440` is implemented and verified; its evidence lives in 001 `tasks.md` Phase 77 and in
`BE-CR-001.md` §10. The controller refuses any supplied `accountId` before field validation or any
service call with `422 VALIDATION_FAILED`/`fieldErrors.accountId`; `SheetService::updateSheet()` is
title-only and takes no account argument; an inactive stored account is preserved; both corruption
variants remain `422 DATA_FAULT`; `ExpenseSheet::$currency` is readonly; and the full-page edit form
reads the account/currency from `SheetDetail` read-only with no selector and no `accounts[0]` path.
Focused PHPUnit: `SheetAccountUpdatePolicyTest` 5/80, `SheetAccountUpdateTest` 5/31,
`CompletedSheetLockTest` 12/79, `StaleVersionConflictTest` 6/24, `PurchaserFlowControllerTest` 64/521,
`DataIntegritySheetAccountTest` 6/29 (pre-closure), `DataIntegritySheetCurrencyTest` 5/17. Targeted
Vitest `PurchaserFlow` 158 and `SheetFormDialog` 10. Official Chrome 156.0.8078.47 desktop 1440×900
and 390×844 Persian RTL recorded 33/33 checks, four screenshots, 0 writes, and 0 console/page errors
under `artifacts/visual-review/t440/iteration-1/`. The owner recorded limited visual approval on
2026-10-28, verbatim: “I approve T440 with limited visual acceptance.” The deferred visual issues
belong to `T080`.

### `T441` execution record (2026-10-28)

`T441` is implemented and verified; its evidence lives in 001 `tasks.md` Phase 77 and in
`BE-CR-001.md` §10. The focused suites were run through `scripts/dev/phpunit` and are all green:
`ExpenseSheetCurrencyImmutableTest` 1/2; `SheetAccountUpdateTest` 5/31; `SheetAccountUpdatePolicyTest`
5/80; `DataIntegritySheetCurrencyTest` 5/17; `SheetPayloadFormatterAccountTest` 4/11;
`PurchaserFlowControllerTest` 64/521; `SheetSummaryConformanceTest` 6/165 with 1 pre-existing skip;
`SheetProvisioningContractTest` 3/28; `CompletedSheetLockTest` 12/79; `StaleVersionConflictTest`
6/24; `RepositoryRoundTripTest` 80/390; `FinanceSettlementTest` 35/197; `DashboardDataFaultTest`
3/36; `DataIntegritySheetAccountTest` 7/35. Aggregate: **236 tests, 1,616 assertions, 1 pre-existing
skip**. `scripts/validate-openapi.sh` exits 0 (OpenAPI 3.0.3, 33 paths, 43 operations, 46 schemas;
generated types match; no `--write` run); `npm run typecheck` passes; targeted Vitest 8 files/**223
tests** (`PurchaserFlow`, `SheetFormDialog`, `SheetWorkspace`, `StagedReceipts`,
`OpenSheetTransactionEdit`, `EvidenceStatusPresentation`, `LoadingIsolation`, `SheetDeletion`); scoped
ESLint/Stylelint are clean. The added dashboard case proves `GET /api/v1/dashboard` refuses an
unreadable account reference with `422 DATA_FAULT`, withholds the internal detail, and answers 200
again after probe removal. No account, currency, transaction amount, or settlement routing was
rewritten; `SheetSummary` is unchanged. `T441` changed only
`tests/Contract/Controller/DataIntegritySheetAccountTest.php` and the closure documentation. No
commit, push, reset, clean, or OCI reindex occurred; the working tree remains dirty with the earlier
approved slices and unrelated uncommitted work. The pre-existing `DataIntegrityReportTest` failures
(transactions 2438/2441) were not repaired or deleted and were outside the `T441` gate. The accepted
pre-release PATCH incompatibility is recorded; Delivery item 6 requires a **MAJOR version decision
for the affected public contract**, release notes, and a compatibility strategy at the separately
authorized release step, with no version bump performed here.

### `T080` execution and closure record (2026-10-29)

`T080` is implemented, minimally verified, and **`DONE_VERIFIED`** under the owner's explicit approval
of the implementation on 2026-10-29. **Owner approval (recorded 2026-10-29), verbatim:** “The product
owner explicitly approves the current T080 implementation.” The approval covers the shared Create/Edit
Sheet modal, the preserved `T079` Create Sheet presentation, title-only editing, the read-only
authoritative account and currency, inactive-account handling, navigation/return behavior, and the
existing authorization, concurrency, and unsaved-changes protection. It records that the **seven
desktop screenshots were visually reviewed**; the **three mobile screenshots were not individually
inspected** and are supported by the recorded official-Chrome 119/119 evidence, which the owner
accepted. No claim is made that the mobile screenshots were individually reviewed.

`/sheets/:id/edit` renders the Sheets List with the shared modal open, and workspace-origin entry
(`?from=workspace`) keeps the workspace mounted as an inert, non-dialog background so exactly one
`[role="dialog"]` exists. `SheetFormView.vue` loads the authoritative `SheetDetail` for edit, gates the
form on `editLoadComplete`, pre-fills the persisted title, and displays the detail `accountId` account
with the stored currency read-only (never `accounts[0]`), with a wrapping account identity and a
separate inactive-account note. It preserves the `OPEN`-only rule, authorization, validation,
`mutationLoading` double-submit protection, the unsaved-changes guard, and the `409`
refetch-without-merge path, and submits only `title` + `expectedVersion` (no `accountId`). Save and
Cancel return to the originating workspace or the list, and direct entry returns focus to the list
heading. Two defects were found and fixed in the same pass: the workspace-origin empty-form race (now
gated on `editLoadComplete`, with a regression test) and the row-actions popover remaining above the
modal (row action items now use `closeAfterClick: true`).

Verification reused for closure: focused `SheetFormDialog.spec.ts` 22/22 (10 `T079` + 12 `T080`); the
`SheetFormDialog.spec.ts` + `PurchaserFlow.spec.ts` batch 180/180; `npm run typecheck` and
`npm run build` pass; scoped ESLint reports 0 errors (36 pre-existing formatting warnings) and scoped
Stylelint is clean. The official-Chrome runner recorded 119/119 checks and 10 screenshots (7 desktop
1440×900, 3 mobile 390×844) in Persian RTL under `artifacts/visual-review/t080/iteration-1/`, with
exactly two deliberate title-only PATCHes (list-origin and workspace-origin, both `expectedVersion`-
guarded, no `accountId`, HTTP 200), zero console/page errors, and restored fixture, language, and
first-run-wizard state. The run re-seeds the `draft` fixture in a `finally` block. No tests were rerun
for this documentation closure. Pre-existing unrelated failures were not touched:
`fr071NarrowViewport.spec.ts`, `typography.spec.ts` (`EvidenceUploader.vue`), and
`DataIntegrityReportTest` (2438/2441). No PHP, route-source, backend, migration,
account/currency-editing, settlement, or `T039` change was made. The working tree remains dirty with
earlier approved slices and unrelated uncommitted work; no commit, push, reset, clean, or OCI reindex
occurred.

**Closure evidence index (reused, not rerun for this record).**

- Commands/results: `npx vitest run tests/Frontend/Component/SheetFormDialog.spec.ts` → 22/22 passed
  (10 `T079` + 12 `T080`); `npx vitest run tests/Frontend/Component/SheetFormDialog.spec.ts
  tests/Frontend/Component/PurchaserFlow.spec.ts` → 180/180 passed; `npm run typecheck` → pass;
  `npm run build` → pass; scoped `npx eslint` on the changed `src`/`tests` paths → 0 errors (36
  pre-existing `vue/html-indent` warnings); scoped `npx stylelint "src/views/sheets/*.vue"
  "src/components/sheets/*.vue"` → clean; official-Chrome runner
  `artifacts/visual-review/t080/iteration-1/run.mjs` → 119/119 checks, 10 screenshots, two title-only
  PATCHes (HTTP 200), 0 console/page errors.
- Changed paths: `src/components/sheets/SheetFormDialog.vue` (untracked, shared modal host),
  `src/views/sheets/SheetFormView.vue`, `src/views/sheets/SheetListView.vue`,
  `src/views/sheets/SheetDetailView.vue`, `src/router/index.ts`,
  `tests/Frontend/Component/SheetFormDialog.spec.ts` (untracked),
  `tests/Frontend/Component/PurchaserFlow.spec.ts`; closure documentation in this report,
  `spec.md`, `plan.md`, `tasks.md`, `contracts/purchaser-ui-contract.md`, and `D-025`.
- Evidence artifacts: `artifacts/visual-review/t080/iteration-1/` (Git-ignored;
  `record.json`, `run.mjs`, 7 desktop + 3 mobile PNGs).
- `git status --short` for the `T080`/closure paths: `M` on `src/router/index.ts`,
  `src/views/sheets/SheetDetailView.vue`, `src/views/sheets/SheetFormView.vue`,
  `src/views/sheets/SheetListView.vue`, `tests/Frontend/Component/PurchaserFlow.spec.ts`, the six
  closure artifacts, and the other 005 specification files touched by the earlier approved slices;
  `??` on `src/components/sheets/SheetFormDialog.vue` and
  `tests/Frontend/Component/SheetFormDialog.spec.ts`. The wider tree also carries earlier approved
  slices, unrelated uncommitted work, and `npm run build` artifact churn; nothing was staged,
  committed, pushed, reset, or cleaned.

### Gates and remaining blockers

- The 2026-10-27 planning pass authorized **no implementation**. The owner subsequently authorized
  `T438`–`T441`; all four are delivered and verified, and there is no replacement-path decision.
- `T080` is implemented, minimally verified, and **`DONE_VERIFIED`** (2026-10-29) under the owner's
  explicit approval; the backend delivery, implementation authorization, and visual gate are all
  satisfied. Its edit modal displays the account and currency read-only and never sends `accountId`.
  The approval's mobile-screenshot inspection limitation is recorded above.
- `T039` retains the `BLOCKED_BY_BACKEND` label pending its own separate frontend implementation
  authorization; the backend delivery is complete.
- `T041` is `DONE_VERIFIED` (2026-10-28). Its policy is decided, the delivery is verified, `T440` is
  owner-approved, and it is not one of the four policy-undecided requests
  (`BE-CR-002/003/005/006`).
- `T437`/`ADR-008` (`G-13`) remain deferred; `T049` (full PHP suite) and `T051` (owner visual acceptance)
  remain separate gates.
- `CHK105` is reconciled in the 005 requirements checklist: the 2026-10-27 planning exception is
  explicitly approved and the item is marked satisfied, without authorizing implementation.

## Official analysis reconciliation (2026-10-27; documentation only)

The latest official specification analysis raised six findings. This pass resolves them in the
specification and governance records only; it starts no implementation and changes no source, test,
API/OpenAPI contract, generated type, build, runtime, or Git state. The 005 UI contract document was
amended as governance text; no OCS schema was changed.

| Finding | Resolution | Where recorded |
|---|---|---|
| **I1 — API compatibility** | `SheetDetail` gains the authoritative required `accountId`; `SheetSummary` and the Sheets List response are unchanged; `SheetCreate` is unchanged and PATCH gets a dedicated title-only `SheetUpdate` schema (`additionalProperties: false`, no `accountId`, no FR-020 provisioning); generated and hand-written types change in the same implementation slice; existing in-repository consumers and strict response validators are audited, including the full-page edit form's read-only display and the retargeted list-absence test; the detail conformance test allows only the new property and the provisioning identity note is updated; no claim is made that no external consumers exist because the contract documents no such guarantee. The change is response-additive on detail but **not request-shape compatible** and **not behaviorally neutral**: a legacy body carrying `accountId` is now schema-invalid and server-refused. The owner explicitly accepted that pre-release incompatibility; the compatibility impact, required MAJOR public-contract decision at release, and release/versioning treatment are recorded under Constitution v1.4.0 without an automatic version bump, and a documented strict validator that rejects the property would be a V.6 exception. | 001 `tasks.md` `T439`; 001 `plan.md`; 001 `spec.md` Session 2026-10-27; `D-026`; `BE-CR-001.md` |
| **I2 — Corrupted records and list availability** | **Resolved with an explicit scope boundary.** No substitution and no silent omission. `T438` distinguishes an account-reference fault from a sheet-currency fault and defines `save()` refusal; both remain `422 DATA_FAULT` with the internal message logged and withheld, and neither becomes `fieldErrors.accountId` because no account is supplied. Detail and update paths reject account/currency disagreement; an inactive-but-resolvable account remains readable; a healthy sheet's detail remains available while another sheet is corrupt (the existing `DataIntegritySheetCurrencyTest` asserts this and the list's `422`). A pre-existing corrupt row is not repaired and remains refused until operator-only remediation. The preferred "healthy sheets stay available" behavior **for the list** conflicts with FR-083's recorded all-or-nothing `422` answer and is therefore **not adopted**: the list stays `422`, and skipping account resolution would require a broader list read model or nullable domain field. Partial-list recovery is out of scope and list semantics are unchanged. | 001 `tasks.md` `T438`/`T440`/`T441`; 001 `plan.md`; 001 `spec.md`; `D-026`; `BE-CR-001.md`; `BLOCKED_ITEMS.md`; `tests/Contract/Controller/DataIntegritySheetCurrencyTest.php` (evidence) |
| **I3 — Task-count wording** | The four policy-undecided requests are `BE-CR-002`, `003`, `005`, `006`. `T041`/`BE-CR-001` is policy-resolved, delivered, and verified (`DONE_VERIFIED`, 2026-10-28); it is not a policy clarification blocker and no replacement decision is outstanding. | 005 `tasks.md` Phase 8 and dependencies; 005 `spec.md` `FR-005-031`; `D-026`; `BLOCKED_ITEMS.md`; `STATUS.md` |
| **I4 — Normative policy source** | 001 `FR-019`/`FR-021` are the normative source. 005 requirements, the UI contract, `D-026`, `BE-CR-001.md`, and `BLOCKED_ITEMS.md` reference them; the duplicate policy prose was reduced to surface-specific obligations. | 001 `spec.md`; 005 `spec.md` `FR-005-019`/`FR-005-050`; 005 `contracts/purchaser-ui-contract.md` C-10/C-23; `D-026`; `BE-CR-001.md`; `BLOCKED_ITEMS.md` |
| **I5 — Inactive account and title-only PATCH** | A title-only update omits `accountId` and leaves the current (possibly inactive) account unchanged. Any explicit `accountId` — including one equal to the current value or to an owned/active/same-currency account — is an attempted assignment and is refused. There is no replacement path, and no second active IRR fixture or replacement test case exists. | 001 `FR-019`; 001 `tasks.md` `T440`; 001 `data-model.md`; 005 `FR-005-019`/`FR-005-050`; `D-026`; `BLOCKED_ITEMS.md` |
| **I6 — T041 status** | `T041` is **`DONE_VERIFIED` (2026-10-28)**. The policy was approved, the owner then authorized all four slices, and `T438`–`T441` are delivered and verified, including `T440`'s owner visual approval; none is partially blocked. The stale "complete `BE-CR-001` decision" gate wording is removed from the current `T080` gate in 005 `spec.md`/`tasks.md`, the UI contract, and `D-025`; those records name the completed backend delivery plus separate implementation authorization for the frontend task. | 005 `tasks.md` `T041`/`T080`; 005 `spec.md`; 005 `plan.md`; 005 `contracts/purchaser-ui-contract.md`; `D-025`/`D-026`; `BE-CR-001.md`; `BLOCKED_ITEMS.md`; `STATUS.md` |
| **C1 — Replacement-path conflict (withdrawn)** | The earlier conflict — the approved policy allowed replacing an inactive current account with a different active, same-currency account, while `pettycash_accounts_user_currency_uniq` is unique on `(user_uid, currency_id)` and no later migration drops it — is **closed by the owner's Option A decision**, not by a schema change. Account replacement is withdrawn, so no second account needs to exist and no second active IRR fixture is added. `AccountService::createFor()` and the unique index are unchanged. | 001 `spec.md` Session 2026-10-27 and `FR-019`; 001 `tasks.md` Phase 77; 001 `plan.md`; 001 `data-model.md`; 005 `spec.md`; 005 `plan.md`; 005 `tasks.md`; 005 `contracts/purchaser-ui-contract.md`; `D-026`; `BE-CR-001.md`; `BLOCKED_ITEMS.md`; `STATUS.md` |

`T438`–`T441` are complete and verified; `T041` is `DONE_VERIFIED`. `T074`–`T077` remain
`DONE_VERIFIED`; `T078` is unchanged; `T079` remains `DONE_VERIFIED` and frozen; `T080` is implemented, minimally verified, and `DONE_VERIFIED` under the owner's explicit approval of 2026-10-29, with the mobile-screenshot inspection limitation recorded;
`T039` retains the `BLOCKED_BY_BACKEND` label pending its own separate implementation authorization;
`T437`/`ADR-008` remain deferred. No settlement routing, owner-approved financial rule, or Feature 005 UI scope was changed.

**Readiness reconciliation (2026-10-27; documentation only).** The planning pass closed the earlier
task-text gaps: `T440` now depends on `T439`; `T438` distinguishes an account-reference fault from a
sheet-currency fault and defines `save()` refusal; `T439` retargets the contradictory detail-absence
test, updates `SheetSummaryConformanceTest`, and updates the provisioning identity note; `T440`
implements the supplied-account refusal and updates the full-page edit form's read-only display
without using `accounts[0]` and without sending `accountId`; `T441` adds conformance, provisioning,
dashboard, `npm run typecheck`, and targeted Vitest coverage and records operator-only remediation.
These are planning corrections, not implementation. **The earlier plan for a second active IRR
fixture is withdrawn together with the replacement policy:** account replacement is no longer a
requirement, so there is no unreachable case to claim and no outstanding feasibility conflict. All
four slices were ready under explicit implementation authorization, and `T041` could close after
authorized delivery and verification; all four are now `DONE_VERIFIED` (2026-10-28) and `T041` is
closed. The remaining open item is FR-083's all-or-nothing list answer,
which conflicts with the preferred "healthy sheets stay available" behavior; partial-list recovery is
not adopted, list semantics are unchanged, and any change to them would require a separate owner
decision. No source, test, contract, generated type, build, runtime, migration, or Git state was
changed by this reconciliation.
