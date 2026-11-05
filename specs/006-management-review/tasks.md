# Tasks: Sheet-First Management Review (Phase 1)

**Input**: Design documents from `/specs/006-management-review/`
**Prerequisites**: `spec.md`, `plan.md`
**Scope**: Cost Center Manager **Sheets Queue**, desktop + mobile, visual gate only. No backend edits, no Phase 2 workspace.

**Organization**: Tasks are grouped by delivery phase. The task IDs are the canonical ledger for this feature. Check a task only when its stated evidence exists.

## Format

`- [ ] T### [P?] Description — evidence`

- `[P]` = can run in parallel with other `[P]` tasks in the same phase.
- Every task names the file or evidence that proves it.

## Phase 1 — Foundation (catalogue + derivation)

- [x] T001 Add the new management-queue keys to `l10n/en.json`, `l10n/fa.json`, `l10n/en.js`, `l10n/fa.js`, keeping key order and the JSON/JS parity gate — `tests/Frontend/l10nCatalog.spec.ts` passes (7 tests).
- [x] T002 Create `src/composables/useSheetQueue.ts` with the `SheetQueueItem` projection, de-duplication by transaction id, completeness guard (`total <= items.length && items.length < PAGE_SIZE`), per-currency totals, latest activity, search, sort and filter — exported pure functions, no network calls.
- [x] T003 Add `tests/Frontend/Composables/SheetQueue.spec.ts` covering duplicates, truncation, at-threshold, blank sheet reference, multi-currency, search/sort, and latest-activity parsing — targeted Vitest passes (19 tests).
- [x] T004 Define and test the presentation state machine (loading, error, empty, complete, unavailable) in the composable/view contract — states are exclusive and covered by `SheetQueue.spec.ts` + `ManagementReview.spec.ts`.

## Phase 2 — Queue UI (desktop)

- [x] T005 Rebuild `src/views/reviews/CostCenterQueueView.vue` header: breadcrumb, eyebrow, title, subtitle, role badge and managed cost-centre scope — rendered from session data, never inferred.
- [x] T006 Add the responsibility banner: explains manager-only counts/amounts, shows the owner-approved Senior Review rule, and marks sheet title/purchaser/history as unavailable — visible text, not tooltip-only.
- [x] T007 Add the summary cards: sheets needing attention, transactions to review, relevant amount per currency, reviewed-this-period disabled with accessible reason — every value traceable to the complete pending set.
- [x] T008 Add the sheet panel: desktop table with reference, pending count, per-currency amount, "Awaiting your decision" status, latest activity (honest label + footnote), and disclosure control — one row per sheet, no sheet-level decision action.
- [x] T009 Add local search (sheet reference), filter tabs (Awaiting / Reviewed disabled), sort (newest, oldest, most transactions, reference) — no network request per keystroke, no partial-set claim.
- [x] T010 Add inline sheet expansion listing only the manager's pending transactions, each linking to `review-detail` with `stage: 'cost-center'` — no sheet-level Approve/Reject/Return/decide-all exists in the DOM.
- [x] T011 Preserve the transaction fallback: a "Transaction list" view renders the existing `ResponsiveTable` + pagination unchanged and reloads page 1 at 25 rows — existing operational path remains reachable.
- [x] T012 Add the honest unavailable state for an incomplete/truncated response, with a working link to the transaction fallback and no invented counts — guarded by the completeness check.

## Phase 3 — Mobile, RTL, verification

- [x] T013 Make the queue responsive at 390px: summary cards 2×2, filter/sort stack, sheet rows become cards, expansion remains usable — no horizontal scroll, no `min-width` above 390px.
- [x] T014 Verify English LTR and Persian RTL direction, Gregorian/Jalali dates, locale digits and per-currency money formatting — direction from `direction`, dates from `formatSheetDate`, numbers from `formatNumber`; captured in T020 evidence (`۱۴۰۵/۷/۲۰`, `۵۰٬۰۰۰`).
- [x] T015 Update `tests/Frontend/Component/ManagementReview.spec.ts` queue tests to the sheet-first default and keep the existing table/card/pagination/RTL/font/status assertions against the transaction fallback — targeted Vitest passes with no deleted or skipped test (46 tests).
- [x] T016 Add queue-specific component assertions: sheet row count, count/sum correctness, disabled Reviewed control, no sheet-level action, transaction links, unavailable state — added to `ManagementReview.spec.ts`; targeted Vitest passes.

## Phase 4 — Build and visual evidence

- [x] T017 Run the minimal frontend gates for this change (`npm run typecheck`, targeted Vitest, targeted ESLint/Stylelint) and fix only regressions caused by this feature — all pass; commands recorded in the delivery report.
- [x] T018 Build and deploy into the running `pettycash-lab` Nextcloud (`npm run build`) — app loads at `http://localhost:8081` without a build error.
- [x] T019 Capture desktop evidence (1440×1237, English LTR) with official Chrome, including the default sheet list, rows, sort, expanded transaction handoff and transaction fallback — `artifacts/visual-review/T019/001/`.
- [x] T020 Capture mobile evidence (390×844, Persian RTL) with official Chrome, including no horizontal scroll, sort control and the expanded handoff — `artifacts/visual-review/T020/001/`.
- [x] T021 Compare the fresh screenshots to `docs/frontend-redesign/references/managment/cost-center/01-queue-desktop-en.png` and `01-queue-mobile-en.png`; record matches, deliberate deviations and unsupported fields — comparison section in the delivery report.
- [ ] T022 Stop for owner visual approval and report the review URL, screenshots, supported/unavailable functionality, Spec Kit progress and blockers — **gate reached 2026-10-29; awaiting owner decision.** No Phase 2 implementation begins before approval.

## Dependencies

- T002 → T003 → T004
- T001 → T005..T012 (labels)
- T005..T012 → T013..T016
- T017 → T018 → T019/T020 → T021 → T022

## Phase 2 — Inline Quick Review (Sheet Review Workspace) — planned, gated

**Not started.** Gate: `T022` (Phase 1 owner visual approval) then the Phase 2 visual gate (`FR-006-025/026`). Design authority: `docs/frontend-redesign/proposals/006-management-review-quick-review/UX_SPEC.md` + `mockups/`. The mockup's `mockup.js` renderers are a static reference with invented data and must NOT be copied into production; every surface is built on the existing Vue components, the `TransactionDetail` read, the `DecisionForm` decision semantic, and the evidence preview overlay.

**Organization**: Phase 2 is organized into **five bounded batches** (`spec.md` §2.4, `plan.md` §2.3). Task tags: `[BI]` = backend-independent (builds/works on connected data without Batch 1); `[BD←B0xx]` = reconnects/requires the named Batch 1 backend capability. The **queue duplicate/pagination defect is a separate bug-workflow item (Batch 2), not a Feature 006 task.**

## Batch 1 — Backend authorization + manager-scoped data capabilities (required product capability; no longer deferred)

Additive backend reads only, scoped to the cost centers the current user actively manages (`RoleAssignmentRepository::findActiveByUserAndRole(...Role::CostCenterManager...)`, same source as `getCostCenterQueue`). Reuse `ExpenseSheetRepository`, `UserDirectory`, `TransactionPayloadFormatter`/`SheetPayloadFormatter`. Must keep awaiting / decided-by-this-manager / returned-for-correction / manager-complete / whole-stage / Senior-eligibility distinct and NEVER derive whole-sheet completion or Senior eligibility from one manager's decisions (`FR-006-047/048`). Requires the separate backend-change authorization (`spec.md` §2.4).

- [x] B001 Manager-scoped sheet summary read: title, currency, submitted total (`submittedTotalMinor`), purchaser identity + display name (`UserDirectory`), genuine submitted date (`firstSubmittedAt`, set in `ExpenseSheet::submit`, `lib/Domain/Expense/ExpenseSheet.php:117`) — authorized only to the manager's active managed cost centers, reusing `ExpenseSheetRepository`; evidence = new service method + targeted `scripts/dev/phpunit` asserting no out-of-scope sheet is exposed.
- [x] B002 Decided/returned transactions in the manager's scope: return the previously-decided transactions in the manager's scope with decided-by-this-manager kept distinct from returned-for-correction, so cards and in-panel states can render — scoped to the manager's cost centers and own decisions; evidence = service + payload + targeted `scripts/dev/phpunit`.
- [x] B003 Accurate manager-specific progress: per-sheet counts of awaiting-this-manager / decided-by-this-manager / returned-for-correction and a manager-complete flag — MUST NOT infer whole-sheet Cost Center completion or Senior eligibility, and MUST NOT invent history or derive totals from pending-only data (`FR-006-047/048`); evidence = service + targeted `scripts/dev/phpunit`.
- [x] B004 API route/contract + payload exposure: additive manager-scoped reads wired through an OCS route consistent with existing role-scoped surfaces; payload shaped via `TransactionPayloadFormatter`/`SheetPayloadFormatter` conventions; contract updated in `specs/001-pettycash-v1-core/contracts/ocs-api.yaml`; evidence = route list + contract + live OCS check.
- [x] B005 Batch 1 backend acceptance tests: authorization (no out-of-scope sheet/transaction), workflow-correctness separation (`FR-006-047/048`), submission-date provenance (`firstSubmittedAt`), and ready-for-frontend payload shape — targeted `scripts/dev/phpunit` passes.

**Frontend reconnects (dependent on Batch 1)**: `[BD←B001]` sheet title/purchaser/submitted on cards, panel and drawer; `[BD←B002]` decided/returned cards + "X of Y decided"; `[BD←B003]` accurate manager progress. Until such a capability lands, the affected fields/cards render unavailable/absent (`FR-006-046`).

## Batch 2 — Queue-defect correction (dedicated bug workflow — NOT a Feature 006 task)

The duplicate-transaction + pagination defect is tracked as bug `cost-center-queue-duplicate-transactions` (`.specify/bugs/cost-center-queue-duplicate-transactions/assessment.md`, high severity, assessment valid). It is driven through the Spec Kit bug lifecycle (`/speckit-bug-assess → /speckit-bug-fix → /speckit-bug-test`) and is done only when `verified`. Remediation from the assessment: de-duplicate managed cost-center IDs/roles; fetch + merge each managed cost centre once; apply pagination once, after the merge; add regression tests; cover `getSeniorQueue()` too. It is **not** a Feature 006 task and is **not** gated by the Phase 2 visual gate.

## Batch 3 — Inline quick-review interface (frontend; backend-independent core)

Core surface on connected data. Field/card reconnects are tagged `[BD]` and wait on Batch 1.

- [x] T024 `[BI]` Replace the expanded-row pending-only list in `CostCenterQueueView.vue` with the inline quick-review panel shell: expanded header (eyebrow, reference, `Collapse`), compact transaction-card list (connected fields; merchant/paid/method/category unavailable; sheet title/purchaser/submitted `[BD←B001]`), footer note — queue stays mounted, collapse preserves scroll/filter state — implemented in `src/views/reviews/CostCenterQueueView.vue`; covered by `ManagementReview.spec.ts` (47 tests).
- [x] T025 `[BI]` Move the transaction-card renderer into a reusable component (e.g. `src/components/review/QuickReviewCard.vue`) with per-card state (awaiting vs decided), connected id/amount/currency/status/cost-centre/description, and the decided-state note block — unit-tested with no fabricated value; decided/returned cards + "X of Y decided" reconnect `[BD←B002/B003]` — `QuickReviewCard.spec.ts` passes (8 tests).
- [x] T035 `[BI]` Conform the summary and tabs to final decision #2: only `Sheets awaiting decisions` and `Transactions pending decision`; tabs `All sheets / Awaiting decision / In progress`; remove the disabled "reviewed" stat/tab and any invented/disabled placeholder — every value traceable to the proven-complete set (labels use the T023 catalogue, Batch 5) — asserted by `ManagementReview.spec.ts` ("should expose only the supported summary stats and queue tabs").

## Batch 4 — Transaction details, evidence preview, and decision workflows (frontend)

- [x] T026 `[BI]` Add a guarded per-transaction detail read for the expanded sheet (`GET /transactions/{id}`) that supplies `allowedActions` + `version`; no action is enabled without an explicit server-granted `true` — refusal and absence stay distinct.
- [x] T027 `[BI]` Add the shared decision confirmation modal: per-kind copy/warning, per-kind note rule (`requiresExplanation` = REJECT ‖ RETURN, `FR-033`), submitting state (controls disabled, scrim no-close, `X-PettyCash-Command-ID` first-write-wins `FR-085`), and the reject/return-without-note error state — posts through `reviewsStore.recordDecision`.
- [x] T028 `[BI]` On success re-render the card in its decided state and show a toast; a failed decision keeps the loaded panel and data.
- [x] T029 `[BI]` Component tests for the confirm workflow (copy, guard, submitting, error, success) — targeted Vitest passes.
- [x] T030 `[BD←B001]` Add the read-only details drawer: Description panel (hero amount, detail grid — connected fields real, purchaser/sheet-title/submitted reconnect with `B001`, merchant/paid/method/category unavailable, description, attachments list) from `TransactionDetail`.
- [x] T031 `[BI]` Add the History timeline panel from `decisions[]`; reuse the `PriorDecisionHistory`/audit-event rendering conventions.
- [x] T032 `[BD←B001]` Footer decision controls (`Decide this transaction`: Reject / Return / Approve / Close) using the shared Batch 4 workflow; RTL-mirrored drawer; mobile full-screen sheet; single-overlay rule with focus return (drawer metadata reconnects with `B001`).
- [x] T033 `[BD←B002]` Add the first-two thumbnails + `+N more` tile to awaiting cards and the drawer attachments list, each opening the preview overlay at exactly that document (`FR-006-040`); decided/returned attachment rows reconnect with `B002`.
- [x] T034 `[BI]` Extend the preview overlay reuse (`EvidencePreview.vue` + `FR-060`/`FR-005-042` inline-safe policy) with Previous/Next navigation and a `1 / N` counter over the transaction's returned evidence, disabling at the ends, object URLs revoked on close/unmount (`FR-006-041`).

## Batch 5 — Localization, accessibility, targeted testing, and visual verification

- [x] T023 `[BI]` Add the Phase 2 catalogue keys (panel, card, drawer, preview, decision, toast, summary/tabs) to `l10n/en.json`, `l10n/fa.json`, `l10n/en.js`, `l10n/fa.js` keeping order and the JSON/JS parity gate — `tests/Frontend/l10nCatalog.spec.ts` passes (7 tests).
- [ ] T036 `[BI]` Mobile/390px + Persian RTL + accessibility: full-screen overlays one at a time, expand-to-panel scroll on mobile, `≥44px` touch targets, focus return, accessible names/labels, no horizontal scroll, Latin isolates (`bdi`/mono), locale digits/dates/amounts via existing utilities; light/dark/forced-colours spot-check.
- [ ] T037 `[BI]` Unit/component tests for the panel, card, confirm workflow, drawer, preview navigation, honesty, single-overlay, RTL and 390px behaviour — targeted Vitest passes, no test deleted or skipped.
- [ ] T038 `[BI]` Run the minimal frontend gates (`npm run typecheck`, targeted Vitest, targeted ESLint/Stylelint) and fix only regressions caused by this feature.
- [ ] T039 `[BI]` Build and deploy into `pettycash-lab` (`npm run build`) — app loads at `http://localhost:8081` without a build error.
- [ ] T040 Capture desktop evidence (1440, English LTR) with official Chrome: expanded panel, cards, details drawer, document preview, return confirmation, decision completion — `artifacts/visual-review/T040/001/` (when Batch 1 has landed, connected fields appear; otherwise show the unavailable states).
- [ ] T041 Capture mobile evidence (390, Persian RTL) with official Chrome: expanded panel, full-screen overlays, no horizontal scroll — `artifacts/visual-review/T041/001/`.
- [ ] T042 Compare fresh screenshots to the approved design captures under `docs/frontend-redesign/proposals/006-management-review-quick-review/mockups/screenshots/`; record matches, deliberate deviations and unavailable fields — comparison section in the delivery report.
- [ ] T043 Stop for owner visual approval and report the review URL, screenshots, supported/unavailable functionality (including which Batch 1 capabilities have landed and which remain unavailable), Spec Kit progress and blockers — the Phase 2 visual gate.

## Phase 2 dependencies

- **Batch 1 (backend)**: B001 → B002/B003; B002/B003 → B004 → B005. Batch 1 is the prerequisite for every `[BD]` reconnect; it is not a prerequisite for the `[BI]` core in Batches 3/4.
- **Batch 2 (bug workflow)**: independent of the Phase 2 visual gate; runs via `/speckit-bug-fix`/`/speckit-bug-test` until `verified`.
- **Batch 3 (frontend core)**: T023 → T024/T025; T024/T025 → T035. T023 (catalogue keys) is foundational: keys are produced with Batch 3 as the panel needs labels, and its completeness/JSON-JS parity gate is recorded in Batch 5. All tasks here are `[BI]`, so they need not wait on Batch 1.
- **Batch 4**: T024/T025 → T026; T026 → T027 → T028 → T029; T024 + T030 + T032 → T033/T034.
- **Batch 5**: Batches 3-4 → T036/T037 → T038 → T039 → T040/T041 → T042 → T043.
- Reconnects land as their Batch 1 capability is verified (B001 → `[BD←B001]`; B002 → `[BD←B002]`; B003 → `[BD←B003]`) without changing the information architecture.

## Out of Scope (do not start)

- Phase 2 production code until `T022` and the Phase 2 visual gate are recorded (nothing above is authorized to start before then, apart from the separately authorized backend change and the bug workflow).
- The separately gated **sheet-wide-completion / Senior-eligibility** backend gate (`Workflow Reconciliation` §, `FR-006-018/023`) — Batch 1 (B003) explicitly must NOT produce it.
- Merchant / paid-on / payment-method / category fields (not in the required-capability scope).
- Senior/Finance screens; backend grouping/history/eligibility broader than the manager-scoped read; sheet-level decisions; changes to existing public contracts beyond the additive manager-scoped read; workflow-transition changes; and any copy of the mockup's `mockup.js` renderers or `mockups/` screenshots into `src/` (mockups are the design reference only).
