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

## Out of Scope (do not start)

- Sheet Review Workspace (Phase 2), Senior/Finance screens, backend grouping/history/eligibility, sheet-level decisions, API contract changes, workflow transitions.
