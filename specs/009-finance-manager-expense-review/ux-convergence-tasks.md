# Cross-Feature Convergence Execution Ledger: Shared Management-Review UX Refactor

**Feature**: `009-finance-manager-expense-review` | **Ledger**: `ux-convergence-tasks.md`
**Task ID prefix**: `UXC-` | **Task ID format**: `UXC-<phase>-<nn>`
**Date**: 2026-11-20 | **Updated**: 2026-11-21 (Phase UXC-V3 added, then **superseded before implementation** by the product-owner selection of the Manus v2 baseline — Phase UXC-V2 added) | **Branch**: `main` (no branch operation authorized)

## Purpose

This is a **technical cross-feature refactor ledger only**. It plans one bounded implementation ledger for the approved shared management-review UX refactor spanning Features `006-management-review`, `007-senior-manager-cost-sheet-review`, and `009-finance-manager-expense-review`.

**Do not implement.** No task here is executed by this document. No production code is changed by this document. No E2E is run by this document. Product requirements are not reopened.

## Scope (bounded)

In scope: shared `SheetDetailsModal`, shared `TransactionReviewCard`, shared `TransactionDetailDrawer`, shared `DecisionConfirmation`, migration of Cost Center (`006`), Senior (`007`), and Finance (`009`) onto those shared primitives, duplicate removal, stable selectors, cross-role frontend regression, runtime/build convergence, and real-Chrome E2E convergence.

Out of scope (boundary — no task may require these): backend APIs, OpenAPI, database schema, persistence, authorization, financial rules, settlement rules, evidence permissions. Any such dependency discovered is a blocker requiring an owner decision.

## How to read this ledger

- Each task has a unique ID (`UXC-<phase>-<nn>`), exact target file(s), required behavior, affected feature(s), dependencies, targeted tests, and explicit exclusions.
- Tasks are ordered so dependencies precede dependents within the required high-level order:
  `Shared modal → Senior migration → Shared card → Shared drawer → Finance migration → Cost Center migration → Tabs/approvals convergence → whole-sheet totals follow-up (Phase 7F) → Duplicate removal → selector convergence → frontend regression → runtime convergence → real Chrome E2E → owner review`.
- Parallelize only tasks with no dependency relationship.
- This ledger does **not** duplicate its checkboxes independently across 006/007/009. A short cross-reference note may be added to the existing feature task ledgers only for traceability; completed historical task history is not rewritten.
- Phase 12 (E2E) is last. `UXC-GATE` is manual, unchecked by automation, and does not silently complete the 006/007/009 owner gates.
- Phase UXC-V3 (2026-11-21) was a bounded post-convergence implementation phase added after the FU follow-ups. **Superseded before implementation by the product-owner selection of the Manus v2 baseline (2026-11-21)**: its shared interaction decisions (inline expansion, stable modal geometry, first-view date, persistent card actions, evidence summary, typography floor, focus lifecycle) were carried into Phase UXC-V2, but its `Review Ledger` visual pattern was withdrawn. All `UXC-V3-*` tasks remain `[ ]` unchecked; **no UXC-V3 implementation task was executed**; the phase is retained below for auditability only.
- Phase UXC-V2 (owner-approved Manus v2 baseline, 2026-11-21) is the active post-convergence implementation phase; it changes presentation requirements in the 006/007/009 specs (drawer → Inline Expanded Transaction; review progress → compact **stage identity + state marker**) but reopens no business boundary and rewrites no historical phase status above.

---

## Task Status

- Phase 1 — Shared Sheet Details Modal: `UXC-1-01`–`UXC-1-08` `[x]` done — implemented in `src/components/review/SheetDetailsModal.vue`; unit tests in `tests/Frontend/Component/SheetDetailsModal.spec.ts`.
- Phase 2 — Senior Migration: `UXC-2-01`–`UXC-2-06` `[x]` done — Senior migrated onto shared `SheetDetailsModal` via the Senior stage adapter in `src/views/reviews/SeniorQueueView.vue`; `SeniorSheetModal.vue` and its spec retained until `UXC-8-01` removes them.
- Phase 3 — Shared Transaction Review Card: `UXC-3-01`–`UXC-3-04` `[x]` done — implemented in `src/components/review/TransactionReviewCard.vue`; unit tests in `tests/Frontend/Component/TransactionReviewCard.spec.ts`. Consumers unchanged in this phase (card migration belongs to `UXC-5-02`/`UXC-6-03`); `QuickReviewCard.vue` retained until `UXC-8-03`.
- Phase 4 — Shared Transaction Detail Drawer: `UXC-4-01`–`UXC-4-06` `[x]` done — implemented in `src/components/review/TransactionDetailDrawer.vue`; unit tests in `tests/Frontend/Component/TransactionDetailDrawer.spec.ts`; `PriorDecisionHistory.vue` reused with additive `heading`/`emptyText` props (defaults unchanged); `TransactionDetailsDrawer.vue` retained until Phase 8.
- Phase 5 — Finance Migration: `UXC-5-01`–`UXC-5-06` `[x]` done — Finance queue opens the shared `SheetDetailsModal` (Finance adapter, honest Option A identity), Transactions tab renders the shared `TransactionReviewCard`, transaction titles open the shared `TransactionDetailDrawer` (stage-gated Finance actions), and decisions confirm through the new shared `DecisionConfirmation.vue` (generalized from `QuickDecisionModal.vue`); reconciliation stays server-authoritative (`patchedTransactions` from `result.transaction`, stale detail cleared, queue refetched, sheet repointed or closed). `FinanceSheetModal.vue` is now unreachable and retained until `UXC-8-02`; `tests/Frontend/Component/FinanceSheetModal.spec.ts` is the retargeted shared-migration suite; `DecisionConfirmation.spec.ts` is new; `tests/Frontend/routeNameIntegrity.spec.ts` pending-migration exemption now covers only `SeniorSheetModal.vue` (`UXC-8-01`) and `FinanceSheetModal.vue` (`UXC-8-02`) — the shared primitives are Finance-reachable and no longer exempt.
- Phase 6 — Cost Center Migration: `UXC-6-01`–`UXC-6-04` `[x]` done — Cost Center sheet rows open the shared `SheetDetailsModal` (Cost Center adapter, manager-read identity with honest unavailable before the read), the Transactions tab renders the shared `TransactionReviewCard`, titles open the shared `TransactionDetailDrawer`, and decisions confirm through the shared `DecisionConfirmation.vue`; the inline sheet expansion (panel markup, chevron, collapse button, scroll-into-view wiring) is removed. Cost Center reconciliation unchanged (clear stale detail → refetch sheet review(force) + detail(force) + toast; no queue refetch). `tests/Frontend/Component/ManagementReview.spec.ts` and `tests/Frontend/Component/QuickReviewWorkflow.spec.ts` retargeted to the shared architecture (workflow/POST/toast assertions preserved; T036's inline scroll-into-view assertion removed with the interaction).
- Phase 7 — Shared Approvals / Tabs Convergence: `UXC-7-01`–`UXC-7-03` `[x]` done — the Approvals tab converges onto the shared `SheetApprovalsSummary.vue` presentation over the pure `src/utils/stageApprovals.ts` aggregation: sheet-level per-stage (Cost Center / Senior Manager / Finance Manager) approved totals grouped per currency, derived only from each role's authoritative read (Cost Center stage from the manager read's `managerState`/`managerDecision`, Senior stage from the senior read's `seniorState`/`seniorDecision`, Finance stage from finance-eligible queue semantics plus `latestDecision`, cross-stage only through the per-transaction detail reads' `decisions[]`); any stage whose decisions are not proven renders the honest unavailable state (never a partial sum, count substitute, or estimate), and an unproven transaction set renders every stage unavailable. Senior's Approvals/Overview placeholders are replaced by the stage-aggregate summary and a real sheet-level Overview (identity/purchaser/submitted/totals/pending/latest activity/status from the authoritative list row and per-sheet read); all three History tabs retargeted from the transactions-queue message to `reviews.sheetHistory.unavailable` (additive EN/FA keys, `reviews.approvals.*` + `reviews.sheetHistory.unavailable`). Previous Decisions remain transaction-level inside `TransactionDetailDrawer` only. New specs: `tests/Frontend/stageApprovals.spec.ts` and `tests/Frontend/Component/SheetApprovalsSummary.spec.ts`; cross-role tab regression blocks added to `ManagementReview.spec.ts`, `SeniorReview.spec.ts`, and `FinanceSheetModal.spec.ts`, plus a drawer distinctness test in `TransactionDetailDrawer.spec.ts`.
- Phase 7F — Whole-Sheet Stage-Approved Totals (owner-authorized follow-up): `UXC-7F-01`–`UXC-7F-04` `[x]` done — contract aligned (`StageApprovedTotals` schema + `ManagerSheetSummary` required + `SeniorManagerSheetReview.sheet` allOf-required + optional `TransactionSummary`; `scripts/validate-openapi.sh` drift-green; `src/generated/api.ts` regenerated; `npm run typecheck` green) and the backend projections landed: `SheetPayloadFormatter::stageApprovedTotals()`/`::stageApprovedTotalsForSheets()` over `SheetSummaryQuery::totalsForSheet()`/the new batched `::stageApprovedTotalsForSheets()` (one IN-set query per page, currency scale joined once per sheet), stamped at `ReviewController::costCenterSheet`/`::seniorSheet` sheet payloads and on Finance queue items only (CC/senior queue items and senior list rows verified field-free). Evidence: `tests/Integration/Persistence/SheetSummaryQueryStageTotalsRoundTripTest.php` (new, 6/6 against MariaDB — approve counts; reject/return/undecided don't; superseded-revision approval excluded; other-cost-centre transaction contributes; per-sheet currency scale; unknown id absent) plus `FinanceReadQueryRoundTripTest` 14/14 regression, and contract suites `ManagementReviewControllerTest` 22/22, `SeniorManagerReviewControllerTest` 14/14, `FinanceReviewControllerTest` 24/24 (finance items carry identical whole-sheet aggregates per Sheet), `SheetSummaryConformanceTest`+`TransactionSheetIdConformanceTest` 10 green/1 pre-existing documented skip. `UXC-7F-05`–`UXC-7F-07` `[x]` done — the three role views consume the server-authoritative whole-sheet aggregate (`authoritativeStageApprovals` over `stageApprovedTotals`, honest-unavailable fallback, zero ≠ unavailable) and contract/runtime convergence is verified (`scripts/validate-openapi.sh` drift-green, `npm run typecheck` green, `npm run build` green, the live local runtime serves the current build). `UXC-7-01` remains **complete with authorized follow-up**. (Status corrected 2026-11-20 at the Phase 8 batch — the lines above were stale; 7F had converged.)
- Phase 8 — Duplicate Removal: `UXC-8-01`–`UXC-8-04` `[x]` done — `SeniorSheetModal.vue` and `FinanceSheetModal.vue` deleted (zero remaining imports; `tests/Frontend/Component/SeniorSheetModal.spec.ts` retargeted in place to the live SeniorQueueView flow, preserving the UXC-2-05 decision-semantics assertions: single-decision exactness, mandatory Reject/Return comments, 409/403 own words, no local decided state, BUG-05 drawer metadata, preview navigation, filter/sort/export); SeniorQueueView's transaction list migrated onto the shared `TransactionReviewCard` (UXC-8-03: the custom CC advisory paragraph is superseded by the card's canonical `costCenter` stage indicator; `QuickReviewCard.vue` + its spec deleted after equivalent `TransactionReviewCard.spec.ts` coverage was verified; `QuickReviewAccessibilityConformance.spec.ts` card assertions retargeted to the shared card source); SeniorQueueView's drawer and decision surface migrated onto the shared `TransactionDetailDrawer` + `DecisionConfirmation` (UXC-8-04, owner-authorized batch scope matching the mission brief's drawer/decision dedup: `TransactionDetailsDrawer.vue`, `QuickDecisionModal.vue` and their dedicated suites removed after equivalence with the shared suites; `QuickDecisionModalBidi.spec.ts` retargeted to `DecisionConfirmation`). `UXC-8-05` `[x]` done as cleanup/reachability convergence — the interim pending-migration exemptions are fully removed (`tests/Frontend/routeNameIntegrity.spec.ts` gate back to strict, empty exemption set, assertion unchanged) and every role-specific selector inside the deleted implementations is gone; the **selector replacement on the live role views** (`senior-modal-*`/`finance-modal-*` data-testids → stable concept-based selectors) is deferred to `UXC-9-01` per this task's recorded dependency — Phase 8 must not begin Phase 9 selector migration (the deferred selector replacement was completed by `UXC-9-01` on 2026-11-21; `UXC-8-05` is fully complete with no selector-cleanup dependency remaining). Retained deliberately: `aggregateStageApprovals`/`latestDecisionValueForStage` in `src/utils/stageApprovals.ts` (zero production consumers, but no Phase 8 task lists `src/utils/` in its targets — removal is not authorized by any UXC-8 task).
- Phase 9 — Stable Shared Selectors: `UXC-9-01` `[x]`, `UXC-9-02` `[x]` done (2026-11-21) — the three role views' slot content inside the shared `SheetDetailsModal` migrated off the deleted-component vocabulary onto the shared concept-based selectors: `quick-review-loading`/`senior-modal-loading`/`finance-modal-loading` → `sheet-details-modal-loading`; `quick-review-details-unavailable`/`senior-modal-unavailable`/`finance-modal-unavailable` → `sheet-details-modal-unavailable`; `senior-modal-retry`/`finance-modal-retry` → `sheet-details-modal-retry`; the transaction-card containers `quick-review-cards`/`senior-modal-transactions`/`finance-modal-transactions` → `transaction-review-cards`; `quick-review-no-transactions`/`finance-modal-empty-transactions` → `sheet-details-modal-no-transactions`; `finance-modal-reference`/`finance-modal-owner` → the shell's `sheet-details-modal-reference`/`sheet-details-modal-owner` (the `#identity-meta` slot replaces the shell's default meta, so no duplicate selectors); `finance-modal-transaction-count` → `sheet-details-modal-transaction-count`; `finance-transaction-*`/`senior-transaction-*` list items → `sheet-transaction-*` (unified with Cost Center's existing concept); queue rows `senior-sheet-row-*`/`finance-sheet-row-*` → `sheet-row-<sheetId>` with card variants `senior-sheet-card-*`/`finance-sheet-card-*` → `sheet-card-row-<sheetId>` (both views' focus-restore queries and `sheetRowKey` fallbacks updated). Senior's role-specific filter/sort toolbar `senior-modal-toolbar` → `senior-transactions-toolbar` (+ matching CSS class rename; role prefix retained because the behavior is role-specific) and its filter empty state `senior-modal-no-results` → `senior-filter-no-results`; the dead `:deep(.senior-modal__body)` rule was removed (matched nothing since the shell migration; the shell already declares the 390px body padding). Test/E2E consumers converged onto the same contract: component specs (`SeniorSheetModal`/`SeniorReview`/`FinanceSheetModal`/`FinanceQueue`/`FinanceReview`/`ManagementReview` — FinanceQueue's two stub-era `finance-sheet-*-stub` assertions retargeted to the real `sheet-details-modal`) and the E2E/visual-evidence scripts (`t026`, `t030`, `t034-t035`, `t040-t041`, `senior-queue-visual-convergence`, and `finance-expense-review.spec.ts` — the latter retargeted from stub-era interactions (`decision-form-stub` CustomEvent dispatches, `*-stub` markers) to the real `DecisionConfirmation` flow and `transaction-review-card-*`/`transaction-card-*` selectors); old drawer-tab vocabulary in `t026` retargeted to the shared drawer's `drawer-section-decisions`; `open-sheet-btn` click targets retargeted to the row's `sheet-title` button; t040's post-return note assertion retargeted to the shared card's `transaction-card-decision-costCenter` indicator (the card renders the decision state, the note lives in the drawer's `decision-item-*` history). Deliberately retained (not selector dependencies): the absence assertions on `open-sheet-btn`/`readonly-note` in `SeniorReview.spec.ts` (regression guards that the dedicated open button stays gone), the `quick-review` i18n namespace (`reviews.quickReview.*` — live translation keys, not selectors), the `t034` screenshot filename `details-drawer-en`, and `finance-expense-review.spec.ts`'s retarget note naming the retired families. Targeted suites + `npm test` + `npm run typecheck` + targeted ESLint/Stylelint green; browser E2E deliberately not executed (Phase 12 scope).
- Phase 10 — Cross-Role Frontend Regression: `UXC-10-01`–`UXC-10-10` `[x]` done (2026-11-21) — cross-role regression verification over the shared architecture; no UX, workflow, authorization or contract change. Evidence (one targeted batch over the exact ledger suites plus supporting gates, then the full suite): Cost Center `ManagementReview.spec.ts` (queue → shared modal, card/drawer hierarchy, evidence, prior decisions, stage actions, focus lifecycle; DecisionForm REJECT/RETURN-mandatory / APPROVE-optional + `X-PettyCash-Command-ID` header + `randomUUID` fallback; multi-role/self-review; scope gates; zero totals distinct from unavailable per UXC-7F-05) with `QuickReviewWorkflow.spec.ts` (CC workflow wiring); Senior `SeniorReview.spec.ts` (sheet-first queue, whole-sheet entry gate, read-only completed sheets, search/filter/sort, focus restore to the originating row, UXC-7F-05 no re-derivation of stage totals) + `SeniorSheetModal.spec.ts` (decision semantics) + `QuickDecisionModalBidi.spec.ts`; Finance `FinanceQueue.spec.ts` + `FinanceSheetModal.spec.ts` (Cost-Sheet-first queue, complete-set guard, one row per Sheet, allowedActions, Senior-rejected Approve restriction E-02, independent per-transaction decisions E-01..E-05, no bulk control, evidence binding F-01..F-05, honest unavailable identity) + `FinanceReview.spec.ts`; shared card `TransactionReviewCard.spec.ts` (summary fields, canonical ✓/✕/↩/• indicators read-only and not colour-only, capability gating, 390px floor, stage neutrality); shared drawer `TransactionDetailDrawer.spec.ts` (details honesty, per-transaction evidence + no mutation controls, stage-separated previous decisions with actor/timestamp/comment + "No decision yet", adapter-only stage actions, focus trap/restore, Escape layering with the parent modal, RTL/bdi/390px) — extended in this phase with a modal+drawer nesting duplicate-ID assertion (all element ids unique while both overlays are mounted; UXC-10-09 accessibility contract); evidence `EvidencePreview.spec.ts` (image/PDF/text/download fallback, prev/next with the 1/N counter, Escape non-bubbling, focus restore); confirmation `DecisionConfirmation.spec.ts` (per-kind copy, guarded version + one command identity, comment guards, in-flight escape suppression, 409/403 own words, focus restore); approvals `SheetApprovalsSummary.spec.ts` (canonical stage labels, per-currency totals, known zero "nothing approved yet", honest unavailable); l10n `l10nCatalog.spec.ts` + `localeFormatting.spec.ts` (EN/FA parity, no raw keys); 390px/a11y `QuickReviewAccessibilityConformance.spec.ts`, `fr071NarrowViewport.spec.ts`, `keyboard-accessibility.spec.ts`; reachability `routeNameIntegrity.spec.ts` (strict, empty exemption set) + `e2e-runnability.spec.ts`. Totals: targeted batch 21 files 357/357; full suite 64 files 1159/1159; `npm run typecheck` green; targeted ESLint green (Stylelint not applicable — no Vue source changed in this phase); `scripts/validate-openapi.sh` drift-green. Test-double hardening note resolved: `TransactionSummary.status` and `TransactionDetail.status` are required, non-nullable in `ocs-api.yaml`, so the drawer/card `StatusBadge` `.toLowerCase()` cannot receive an undefined status from a contract-valid payload — the historical throw was malformed-fixture-only; no defensive production code added and fixtures remain contract-correct. Deliberately residual (not a selector dependency): the confirmation's element id `quick-decision-modal-title` predates the Phase 9 data-testid contract, has no test consumer, and is outside the forbidden `quick-review-*` family. Browser E2E remains Phase 12 scope.
- Phase 11 — Runtime / Build Convergence: `UXC-11-01`–`UXC-11-04` `[x]` done (2026-11-21) — verification + generated-output refresh only; no source change. `UXC-11-01`: `npm run typecheck` green; `npm run lint` 0 errors (307 pre-existing warnings, exit 0); `npm run stylelint` green; `npm run build` (vite) green. Build content verified: zero chunks/strings named for deleted implementations (`SeniorSheetModal`/`FinanceSheetModal`/`QuickReviewCard`/`TransactionDetailsDrawer`/`QuickDecisionModal`), zero stale selector strings in `js/`+`css/`, and the shared selector vocabulary present in the served chunks — `sheet-details-modal-dialog`, `transaction-review-card-`, `decision-note-input`/`decision-confirm-btn` live in the shared `TransactionDetailDrawer-Ds2P1vYm` overlay chunk; `transaction-review-cards` + `sheet-details-modal-loading`/`-unavailable` in all three queue chunks; `sheet-row-` in all three queues; `sheet-card-row-` in Senior+Finance; `senior-transactions-toolbar`/`senior-filter-no-results` Senior-only; `sheet-details-modal-transaction-count` Finance-only; `cc-review-progress` CC-only; `decision-item-` in `PriorDecisionHistory-BmryiFiA`. Import graph proven: all three view chunks → the shared overlay chunk + the PriorDecisionHistory chunk; the main bundle references every view chunk. `UXC-11-02`: `scripts/dev/opcache-settle` settled (Apache serving current bytecode for `lib/`+`appinfo/`); `scripts/dev/route-settle` + `scripts/dev/route-cache-clear` executed because the pre-existing uncommitted `appinfo/routes.php` change (Feature 008 finance reads — prior-phase work, not convergence-attributable) is part of the verified runtime state; three-way SHA-256 identity proven for `js/pettycash-main.mjs`, the three queue view chunks, `TransactionDetailDrawer-Ds2P1vYm.chunk.mjs`, `PriorDecisionHistory-BmryiFiA.chunk.mjs` and `css/pettycash-main.css`: repo tree = runtime mount (`/var/www/html/custom_apps/pettycash`, read via `scripts/dev/in-container sha256sum`) = HTTP-served bytes (`http://localhost:8081/custom_apps/pettycash/...`; note `/apps/pettycash/...` answers 404 in this lab — `custom_apps` is served directly); `status.php` 200 and the app page 401 unauthenticated (expected auth wall, not a blocker). `UXC-11-03`: runtime-mounted `js/`+`css/` audited in-container — zero obsolete-name chunks, zero stale selector substrings; main bundle references zero obsolete implementation names. `UXC-11-04`: served runtime proves the converged surfaces — CC/Senior/Finance chunks all consume the shared `SheetDetailsModal` → `TransactionReviewCard` → `TransactionDetailDrawer` → `DecisionConfirmation` chunk with the shared slot-state/container selectors; the old CC inline-expansion and Finance-specific modal/card testid vocabulary is absent from every bundle. The build regenerated the working-tree `js/`+`css/` output (633 changed paths), absorbing the pre-existing Phase-8-era build-output dirty state. `scripts/validate-openapi.sh` drift-green; no OpenAPI edit.
- Phase 12 — Real Chrome / E2E Convergence: `UXC-12-01`–`UXC-12-04` `[x]` done (2026-11-21, official Chrome 156.0.8078.47 `channel: 'chrome'`, chrome-desktop + chrome-mobile projects). `UXC-12-01` Cost Center: `t026-quick-review-evidence.mjs` 15 checks/13 screenshots (purchaser fixture → sheet submit → CC queue row → shared `sheet-details-modal` → `transaction-review-card-*` → drawer with honest unavailable fields + `drawer-section-decisions` → evidence preview 1/3→3/3 with end stops → APPROVE via `decision-modal`/`decision-confirm-btn` → decided state = `status-badge-cost_center_approved` + decide bar gone; FA RTL drawer `dir=rtl`; 390px decision modal open/cancel), `t040-t041-batch5-visual-evidence.mjs` 25 checks/9 screenshots (EN desktop + FA RTL 390px: RETURN flow → `status-badge-awaiting_purchaser_correction` (non-final) + `transaction-card-decision-costCenter` ↩ indicator, full-screen overlay bounds, no-horizontal-scroll), `management-review.spec.ts` 8×2 projects (scope queue, self-review, reject escalation, senior return), `browser-matrix.spec.ts` + `keyboard-accessibility.spec.ts` (390px no-scroll, keyboard journeys, dark/forced-colours). `UXC-12-02` Senior: `senior-queue-visual-convergence.mjs` 47 checks/5 screenshots (queue surface EN/FA, completed-badge retention → read-only modal, row-title → modal path), `t034-t035-batch8-visual-evidence.mjs` 28 checks/14 screenshots (EN desktop: filter chips/sort/search/export-or-honest-refusal/drawer/preview/decision-cancel; FA 390px RTL: full-screen overlays, formatted-amount honesty, dark theme). `UXC-12-03` Finance: `finance-expense-review.spec.ts` Scenarios 1–7 on the sheet-first modal surface + `finance-settlement.spec.ts` (settlement, admin-without-finance-role, negative balance, command-id replay idempotency, simultaneous approvals settle once, stale-version no-op) + `finance-manager.spec.ts` (completed-sheet detail, recharge idempotency). `UXC-12-04` Feature 009 H tasks completed only after 12-01..03 were genuinely green: **H-03** — new `finance-expense-review.spec.ts` test completes a real Finance REJECT in Persian RTL at 390px (`finance-queue`/`sheet-details-modal`/drawer all `dir=rtl`, mandatory Persian explanation, decided state `status-badge-completed_rejected`, decide bar removed, focus restored to the opener on cancel, no horizontal scroll; screenshots `artifacts/visual-review/T009-H-03/001/`); **H-04** — the task's five named gates green (`npm run typecheck`, `npm run lint` 0 errors, `npm run stylelint`, `npm test` 1159/1159, `npm run build`; no new dependencies) — the aggregate `npm run check` additionally trips on **pre-existing** Phase-7F portability violations in `tests/Integration/Persistence/roundtrip-runner.php` (Doctrine\DBAL types bypassing OCP\DB\Types, present in the uncommitted batch since 2026-11-20; backend file, reported not fixed); **H-05** — evidence walk test captures EN desktop (queue/modal-cards/approvals-tab/drawer/preview/decision-modal) + EN 390px (modal/drawer) + FA RTL 390px under `artifacts/visual-review/T009-H-05/001/` and `T009-H-03/001/`, and the final gate **`npm run test:e2e` green: 108 passed, 2 skipped (pre-existing conditional skip in `reporting-export.spec.ts`), 0 failed, 43.7m**. E2E harness updates (documented, no product change): t026/t040 post-decision status assertions retargeted from the retired actor-relative labels to the shared card's pinned badge contract; `finance-expense-review.spec.ts` extended with the T009-H-03/H-05 describe (fixture mirrors `finance-settlement.spec.ts`); the settlement/transaction-correction walks reach the per-transaction decision surface by its route (the review queues are sheet-first — `view-details-btn` opened the same route) and the post-submit read-only Sheet Workspace is closed before navigating; `/sheets` list lookups use `pageSize=20`. **Backend defect reported, not fixed (Phase 12 boundary):** the `/sheets` list answers HTTP 500 for contract-valid pagination (`PageSize.maximum: 100` in ocs-api.yaml) once sheet volume crosses a threshold — observed `pageSize=40`/`100` → 500 and `page=2` (offset>0) → 500; `pageSize ≤ 30` page 1 works; the UI itself uses `pageSize=8` and is unaffected. No workflow, authorization, financial-rule or API change; no owner visual acceptance claimed. `UXC-GATE`: `[ ]` manual owner gate, not done.
- `UXC-GATE`: `[ ]` manual owner gate, not done.
- Post-convergence follow-up (owner-approved visual refinement, 2026-11-21): `UXC-FU-01` `[x]` done — compact icon-only review-progress indicators on the shared `TransactionReviewCard` (see "Post-Convergence Follow-Up" below). `UXC-FU-02` `[x]` done — tooltip edge-clipping correction + Returned warning-color correction, runtime-verified (see below). **Superseded for requirements (not for history):** the icon-only chip presentation was first replaced by the briefly aligned v3 Review Ledger, then the owner selected the **Manus v2** baseline (compact stage identity + state marker, 2026-11-21 — unimplemented); the drawer interaction is replaced by the **Inline Expanded Transaction** (unimplemented) — see Phase UXC-V2 below; FU completion states remain intact.
- Phase UXC-V3 — Management Review UX v3 (2026-11-21): `UXC-V3-01`–`UXC-V3-16` + `UXC-V3-GATE` `[ ]` **all unchecked — superseded before implementation by the product-owner selection of Manus v2** (same day). **No UXC-V3 implementation task was executed.** Its shared interaction decisions carry into Phase UXC-V2; the Review Ledger pattern is withdrawn; retained for auditability.
- Phase UXC-V2 — Management Review UX v2 (owner-approved Manus v2 baseline, 2026-11-21): `UXC-V2-01`–`UXC-V2-03` `[x]` done (2026-11-21 — shared stable-modal geometry, the shared `ReviewStageMarkers` stage-identity + state-marker component, and the transaction card's v2 first-view hierarchy with the mandatory visible transaction date; evidence: targeted specs green, 11 affected role/accessibility suites green, `npm run typecheck` green, targeted ESLint green, `npm run stylelint` green, full `npm test` 65 files 1194/1194 green; `TransactionDetailDrawer.vue` untouched; no E2E, no build/runtime convergence in this batch). `UXC-V2-04`–`UXC-V2-08` `[x]` done (2026-11-21 — the Inline Expanded Transaction: card disclosure expansion with focus/Escape lifecycle, the shared `TransactionInlineDetail` migration of the drawer's detail content, real-thumbnail evidence integration, the shared `ReviewDecideActions` v2 hierarchy, and the compact `PriorDecisionHistory` presentation; evidence: targeted specs green, role regression suites green, `npm run typecheck` green, targeted ESLint green, `npm run stylelint` green, full `npm test` 67 files 1267/1267 green; `TransactionDetailDrawer.vue`, `SheetDetailsModal.vue`, `DecisionConfirmation.vue` and `EvidencePreview.vue` untouched; role views untouched (compatibility preserved); no E2E, no build/runtime convergence in this batch). `UXC-V2-09`–`UXC-V2-11` `[x]` done (2026-11-21 — all three role views migrated onto the shared Inline Expanded Transaction: Cost Center, Senior and Finance each own one `expandedTransactionId`, reset on sheet change/decision/refresh with a dangling-expansion guard; drawer wiring removed from all three views — zero production consumers remain, file + spec retained until UXC-V2-13 and recorded in the routeNameIntegrity PENDING_MIGRATION set; role suites retargeted to the expansion with per-role drawer-unreachable interaction tests; evidence: CC 66/66, Senior 56/56, Finance 42/42, `npm run typecheck`/`npm run lint`/`npm run stylelint` green, full `npm test` 67 files 1281/1281; no workflow/gate/export/settlement/API change). `UXC-V2-12`–`UXC-V2-15` `[x]` done (2026-11-21/22 — RTL/390px/accessibility convergence verified and corrected on the shared v2 surfaces, the legacy drawer deleted after the zero-dependency proof, the final frontend regression passed with zero repairs, and build/runtime identity proven; see the task notes below). `UXC-V2-16` + `UXC-V2-GATE` `[ ]` **pending**. No historical phase status above is rewritten.

---

## Phase 1 — Shared Sheet Details Modal

**Goal**: Extract/generalize the common modal shell once; no business rules inside the shell. Modeled on the existing `SeniorSheetModal.vue` (closest complete modal) and `FinanceSheetModal.vue`; baseline visual language from `CostCenterQueueView.vue`/`FinanceReviewView.vue`.

### UXC-1-01: Create shared `SheetDetailsModal.vue` shell
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue` (new)
- **Required behavior**: Single shared modal shell rendered over a mounted queue (no route navigation as primary flow). `role="dialog"`, `aria-modal="true"`, labelled by sheet identity. Escape/scrim/close-button close. Focus trap while open; focus returns to the originating sheet-row activation control on close. Single-overlay rule (opening drawer/preview pushes one layer above; queue and modal stay in DOM). RTL/LTR-safe via `:dir="direction"`; responsive at 390px with no horizontal scroll. Accepts stage-specific configuration through props/slots only — zero business rules inside the shell.
- **Affected features**: 006, 007, 009
- **Depends on**: none
- **Targeted tests**: `tests/Frontend/Component/SheetDetailsModal.spec.ts` (new) — dialog role/aria-modal, Escape/scrim/close close, focus trap, focus restoration to originating row, single-overlay stacking, RTL dir binding, 390px no-scroll.
- **Exclusions**: no business rules, no stage-specific decision logic, no role-specific selectors inside the shell.

### UXC-1-02: Canonical header — sheet identity and status
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`
- **Required behavior**: Header shows sheet reference (always), sheet title/purchaser/submitted date as honest unavailable placeholders when not proven by the read model (never fabricated), review status where available, and per-currency monetary summary. Stage-specific slots/configuration inject identity fields without shell business rules.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-01
- **Targeted tests**: `tests/Frontend/Component/SheetDetailsModal.spec.ts` — reference shown, unavailable placeholders for unproven identity fields, no fabricated values, status displayed when available.
- **Exclusions**: no fabrication of sheet title/purchaser/submitted date; no sheet-wide completion claims.

### UXC-1-03: Authoritative summary metrics
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`
- **Required behavior**: Summary metrics (transaction count, per-currency totals, progress) derived only from the proven-complete set passed in by the stage adapter; unavailable state when completeness not proven; no cross-currency sums.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-01
- **Targeted tests**: `tests/Frontend/Component/SheetDetailsModal.spec.ts` — per-currency totals separate, unavailable when incomplete, no fabricated metrics.
- **Exclusions**: no cross-currency arithmetic; no client-derived completion.

### UXC-1-04: Canonical tabs — Transactions, Approvals, Overview, History
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`
- **Required behavior**: Modal exposes exactly the canonical tabs: Transactions, Approvals, Overview, History. Tab activation is keyboard-operable with visible focus and ARIA `role="tab"`/`aria-selected`. Transactions tab renders compact transaction cards; Approvals tab renders sheet-level approved monetary totals per workflow stage; Overview/History retain existing authoritative behavior. Tab meaning consistent across roles (see Phase 7).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-01
- **Targeted tests**: `tests/Frontend/Component/SheetDetailsModal.spec.ts` — four tabs present, keyboard traversal, ARIA roles, correct tab content mapping.
- **Exclusions**: no additional role-specific tabs; Approvals not conflated with Previous Decisions (Phase 7).

### UXC-1-05: Focus lifecycle — trap, Escape, close, restoration
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`
- **Required behavior**: Focus trap confines keyboard navigation to the dialog while open. Escape closes. Close button and scrim click close (scrim never closes during a submitting decision). On close, focus returns to the originating Cost Sheet row activation control; queue unchanged and still mounted.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-01
- **Targeted tests**: `tests/Frontend/Component/SheetDetailsModal.spec.ts` — focus trap, Escape close, scrim/close-button close, focus restored to originating row, scrim blocked during submit.
- **Exclusions**: no scrim-close during submitting decision; no focus loss on close.

### UXC-1-06: RTL/LTR and responsive 390px behavior
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`
- **Required behavior**: `:dir="direction"` binding (never hardcoded `direction: rtl`); English LTR and Persian RTL render correctly; UUIDs and currency codes direction-isolated (LTR) inside RTL pages via `<bdi dir="ltr">`; 390px desktop floor with no horizontal scroll (`scrollWidth <= clientWidth`); `≥44px` touch targets at 390px; light/dark/forced-colours spot-check.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-01
- **Targeted tests**: `tests/Frontend/Component/SheetDetailsModal.spec.ts` — LTR/RTL rendering, bdi isolation, no horizontal scroll at 390px, touch-target floor.
- **Exclusions**: no hardcoded direction; no new mobile product requirements beyond the 390px floor.

### UXC-1-07: Stage-specific slots/configuration (stage adapter)
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`
- **Required behavior**: The shell exposes stage-specific slots/configuration (identity fields, summary metrics, tab content, actions) through a stage adapter/capability configuration passed by the mounting queue view. The shell itself never references Cost Center/Senior/Finance business rules.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-01, UXC-1-02, UXC-1-03, UXC-1-04
- **Targeted tests**: `tests/Frontend/Component/SheetDetailsModal.spec.ts` — stage adapter injects role-specific content; shell has no role references; same shell mounts for all three roles.
- **Exclusions**: no role-specific business logic inside the shell.

### UXC-1-08: Shared modal unit test suite
- **Target file(s)**: `tests/Frontend/Component/SheetDetailsModal.spec.ts` (new)
- **Required behavior**: Unit tests covering UXC-1-01..UXC-1-07: shell behavior, header identity/status, summary metrics, canonical tabs, focus lifecycle, RTL/LTR, responsive 390px, stage adapter.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-01..UXC-1-07
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/SheetDetailsModal.spec.ts` green.
- **Exclusions**: no E2E in this phase.

---

## Phase 2 — Senior Migration

**Goal**: Migrate Senior first (its existing modal is the closest complete implementation). Behavior remains functionally unchanged. `SeniorSheetModal.vue` is replaced by the shared `SheetDetailsModal` via the Senior stage adapter.

### UXC-2-01: Senior queue → shared Sheet Details Modal
- **Target file(s)**: `src/views/reviews/SeniorQueueView.vue`, `src/components/review/SheetDetailsModal.vue` (Senior stage adapter config)
- **Required behavior**: Selecting a Senior sheet row opens the shared `SheetDetailsModal` (Senior stage) over the mounted queue; no route navigation as primary flow; queue remains mounted and visible behind the modal. Senior queue entry point and `/reviews/senior` route unchanged.
- **Affected features**: 007
- **Depends on**: UXC-1-08
- **Targeted tests**: `tests/Frontend/Component/SeniorReview.spec.ts` (extend) — sheet row opens shared modal over mounted queue, no router.push, queue stays mounted.
- **Exclusions**: no route navigation as primary flow; no Senior-specific modal shell.

### UXC-2-02: Preserve Senior sheet-wide gate
- **Target file(s)**: `src/views/reviews/SeniorQueueView.vue`, `src/components/review/SheetDetailsModal.vue`
- **Required behavior**: Sheet-wide Senior queue-eligibility gate (backend-computed `allTransactionsCostCenterDecided` / `reviewStatus`) preserved; sheets enter the queue only when the backend proves the gate. No client-derived "all CC decided" claim; no "Waiting for Cost Center Review" state. Honest unavailable/error state when not proven complete.
- **Affected features**: 007
- **Depends on**: UXC-2-01
- **Targeted tests**: `tests/Frontend/Component/SeniorReview.spec.ts` — gate-satisfying sheets only, no client-derived eligibility, honest unavailable on incomplete set.
- **Exclusions**: no backend change; no client-derived sheet-wide completion or Senior eligibility.

### UXC-2-03: Preserve Senior filter/sort/export
- **Target file(s)**: `src/views/reviews/SeniorQueueView.vue`, `src/components/review/SheetDetailsModal.vue`
- **Required behavior**: Queue-level Cost Sheet search/filter preserved; modal transaction search (id/description) and expense-type filter preserved; sort (amount/expenseDate/expenseType, transaction-date ascending default, click-active toggle) preserved; XLSX export control preserved beside close button; no vendor filter/column/placeholder.
- **Affected features**: 007
- **Depends on**: UXC-2-01
- **Targeted tests**: `tests/Frontend/Component/SeniorReview.spec.ts` and `tests/Frontend/Composables/SeniorSheetQueue.spec.ts` — two-level filter placement, sort model, export refusal path, no-vendor audit.
- **Exclusions**: no currency/amount-range/attachment filters; no vendor controls.

### UXC-2-04: Preserve Senior completed/read-only behavior
- **Target file(s)**: `src/views/reviews/SeniorQueueView.vue`, `src/components/review/SheetDetailsModal.vue`
- **Required behavior**: Completed sheets remain listed, read-only, non-actionable ("Review Completed"); cannot be re-decided; previous decisions and evidence remain accessible.
- **Affected features**: 007
- **Depends on**: UXC-2-01, UXC-2-02
- **Targeted tests**: `tests/Frontend/Component/SeniorReview.spec.ts` — completed sheet read-only/non-actionable, decisions/evidence still accessible.
- **Exclusions**: no re-decision of completed sheets.

### UXC-2-05: Preserve Senior decision semantics
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue` (Senior adapter), `src/components/review/TransactionReviewCard.vue`, `src/components/review/TransactionDetailDrawer.vue`
- **Required behavior**: Senior per-transaction decision semantics preserved (Approve/Reject/Return gated by `allowedActions`, mandatory comment for Reject/Return, optional for Approve, exactly one `POST /transactions/{id}/decisions` per action, Senior decision never overwrites CC decision, idempotency header).
- **Affected features**: 007
- **Depends on**: UXC-2-01, UXC-3-01 (shared card), UXC-4-01 (shared drawer)
- **Targeted tests**: `tests/Frontend/Component/SeniorSheetModal.spec.ts` (extend) — single-decision exactness, comment rules, CC decision unchanged, server-authoritative refresh.
- **Exclusions**: no sheet-level decide/decide-all; no overwriting CC decisions.

### UXC-2-06: Senior targeted regression
- **Target file(s)**: `tests/Frontend/Component/SeniorReview.spec.ts`, `tests/Frontend/Component/SeniorSheetModal.spec.ts`, `tests/Frontend/Composables/SeniorSheetQueue.spec.ts`
- **Required behavior**: Regression suite for migrated Senior surfaces green; existing 007 test coverage updated in place, none deleted or skipped; `npm test -- --run` on Senior specs green.
- **Affected features**: 007
- **Depends on**: UXC-2-01..UXC-2-05
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/SeniorReview.spec.ts tests/Frontend/Component/SeniorSheetModal.spec.ts tests/Frontend/Composables/SeniorSheetQueue.spec.ts` green.
- **Exclusions**: no 006/009 changes in this phase.

---

## Phase 3 — Shared Transaction Review Card

**Goal**: Generalize `QuickReviewCard.vue` into one canonical compact transaction card used by all three roles. Indicators are read-only status presentation, not checkboxes.

### UXC-3-01: Create shared `TransactionReviewCard.vue`
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue` (new)
- **Required behavior**: Canonical compact card generalized from `QuickReviewCard.vue`. Props: `transaction`, evidence, `allowedActions`, `busy`; emits `openReview`/`openDetails`/`openPreview`/`decide`. Cost Center surface unchanged during extraction; card reused unchanged where not yet migrated.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-08
- **Targeted tests**: `tests/Frontend/Component/TransactionReviewCard.spec.ts` (new) — one card per transaction, correct transaction binding, emitted events, no fabricated values.
- **Exclusions**: no role-specific business logic inside the card; no duplicate `QuickReviewCard` markup during extraction (removed in Phase 8 only).

### UXC-3-02: Required card summary fields
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`
- **Required behavior**: Card shows title/description, amount + currency, cost center where relevant, transaction status, evidence count/thumbnails, and previous-decision indicators. Order deterministic (newest activity first).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-3-01
- **Targeted tests**: `tests/Frontend/Component/TransactionReviewCard.spec.ts` — all summary fields render correctly, cost center where relevant, evidence count/thumbnails bound to correct transaction.
- **Exclusions**: no cross-transaction evidence leakage.

### UXC-3-03: Canonical previous-decision indicators
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`
- **Required behavior**: Compact previous-decision indicators for Cost Center / Senior Manager / Finance Manager: `✓ Approved`, `✕ Rejected`, `↩ Returned`, `• No decision yet`. Stage-labelled, never collapsed into one generic status. Read-only indicators only — not checkboxes or editable controls. A workflow stage is not hidden merely because it has no decision yet.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-3-01
- **Targeted tests**: `tests/Frontend/Component/TransactionReviewCard.spec.ts` — indicator glyphs per stage, stage labels, no checkboxes, no stage hidden when undecided.
- **Exclusions**: indicators are read-only status presentation, not editable controls.

### UXC-3-04: Shared card unit test suite
- **Target file(s)**: `tests/Frontend/Component/TransactionReviewCard.spec.ts` (new)
- **Required behavior**: Unit tests covering UXC-3-01..UXC-3-03: card rendering, summary fields, indicators, evidence binding, events.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-3-01, UXC-3-02, UXC-3-03
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/TransactionReviewCard.spec.ts` green.
- **Exclusions**: no E2E; no role-specific business logic.

---

## Phase 4 — Shared Transaction Detail Drawer

**Goal**: Generalize `TransactionDetailsDrawer.vue` into one shared drawer; no hardcoded role business logic; stage-specific actions via stage adapter/capability configuration.

### UXC-4-01: Create shared `TransactionDetailDrawer.vue`
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue` (new)
- **Required behavior**: Shared drawer generalized from `TransactionDetailsDrawer.vue`. `role="dialog"`, `aria-modal="true"`, one layer at a time above the modal (single-overlay rule), Escape/scrim/close-button close, focus restoration. Read-only fields with unavailable states. Sections: Transaction Details, Evidence, Previous Decisions, Current Stage Actions.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-08
- **Targeted tests**: `tests/Frontend/Component/TransactionDetailDrawer.spec.ts` (new) — dialog role/aria-modal, single-overlay, close behaviors, focus restoration, no navigation away from Sheet.
- **Exclusions**: no hardcoded role business logic; no second full modal over the Sheet modal; no navigation away from Sheet.

### UXC-4-02: Transaction Details section
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue`
- **Required behavior**: Description/title, expense date, amount, currency, expense type, cost center, transaction reference, current transaction status, and other existing authoritative fields from `TransactionDetail`. Sheet title/purchaser/submitted date honest unavailable when not proven.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-4-01
- **Targeted tests**: `tests/Frontend/Component/TransactionDetailDrawer.spec.ts` — all details fields render, unavailable placeholders honest.
- **Exclusions**: no fabricated sheet identity fields.

### UXC-4-03: Evidence section
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue`, `src/components/evidence/EvidencePreview.vue` (reuse verbatim)
- **Required behavior**: Evidence bound to the correct transaction only; thumbnails/metadata; image preview, PDF preview via existing viewer, metadata + open/download for unknown types; separate preview overlay with Previous/Next + `1/N` counter; download fallback; preview close returns to same transaction context; no evidence upload/replace/delete controls.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-4-01
- **Targeted tests**: `tests/Frontend/Component/TransactionDetailDrawer.spec.ts` — evidence bound to correct transaction, preview media handling, context restore, no mutation controls.
- **Exclusions**: no evidence viewer redesign; no upload/replace/delete controls.

### UXC-4-04: Previous Decisions section (Cost Center / Senior Manager / Finance Manager separate)
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue`, `src/components/review/PriorDecisionHistory.vue` (reuse)
- **Required behavior**: Cost Center, Senior Manager, and Finance Manager decisions shown separately per transaction, each with decision, actor, timestamp, comment/explanation; `No decision yet` when none exists; never collapsed into one generic approval status. Rendered from `TransactionDetail.decisions[]` filtered by stage.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-4-01
- **Targeted tests**: `tests/Frontend/Component/TransactionDetailDrawer.spec.ts` — stage-separated decisions, actor/timestamp/comment, no decision yet, no collapsing.
- **Exclusions**: no rolling up decisions to a sheet verdict.

### UXC-4-05: Current Stage Actions via stage adapter
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue`
- **Required behavior**: Current Stage Actions provided through stage adapter/capability configuration (Approve/Reject/Return gated by `allowedActions` where the role permits); no hardcoded role business logic; one decision per transaction; mandatory comment for Reject/Return, optional for Approve.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-4-01, UXC-4-04
- **Targeted tests**: `tests/Frontend/Component/TransactionDetailDrawer.spec.ts` — actions via adapter only, `allowedActions` gating, comment rules, one transaction per decision.
- **Exclusions**: no hardcoded role business logic; no bulk action.

### UXC-4-06: Shared drawer unit test suite
- **Target file(s)**: `tests/Frontend/Component/TransactionDetailDrawer.spec.ts` (new)
- **Required behavior**: Unit tests covering UXC-4-01..UXC-4-05: drawer shell, Transaction Details, Evidence, Previous Decisions, Current Stage Actions.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-4-01..UXC-4-05
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/TransactionDetailDrawer.spec.ts` green.
- **Exclusions**: no E2E.

---

## Phase 5 — Finance Migration

**Goal**: Replace Finance-specific presentation with shared primitives. Preserve Finance behavior unchanged. Do not rerun already-completed Finance data/grouping implementation unless affected by the refactor.

### UXC-5-01: Finance modal → shared Sheet Details Modal
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`, `src/components/review/SheetDetailsModal.vue` (Finance stage adapter config), `src/components/review/FinanceSheetModal.vue` (replaced, removed in Phase 8)
- **Required behavior**: Selecting a Finance Cost Sheet row opens the shared `SheetDetailsModal` (Finance stage) over the mounted queue; no route navigation as primary flow; queue remains mounted. Finance identity fields (title/purchaser/submitted date) honest unavailable placeholders until a backend capability proves them.
- **Affected features**: 009
- **Depends on**: UXC-1-08, UXC-3-01, UXC-4-01
- **Targeted tests**: `tests/Frontend/Component/FinanceQueue.spec.ts` and `tests/Frontend/Component/FinanceSheetModal.spec.ts` (extend) — shared modal over mounted queue, no router.push, honest unavailable identity.
- **Exclusions**: no Finance-specific modal shell; no fabrication of sheet identity.

### UXC-5-02: Finance transaction UI → shared Transaction Review Card
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue` (Finance adapter), `src/components/review/TransactionReviewCard.vue`
- **Required behavior**: Finance modal Transactions tab lists finance-eligible transactions as shared `TransactionReviewCard`s, one per transaction, no sibling omitted. Cards expose title/description, amount + currency, cost center where relevant, status, evidence count/thumbnails, previous-decision indicators for CC/Senior/Finance.
- **Affected features**: 009
- **Depends on**: UXC-5-01, UXC-3-02
- **Targeted tests**: `tests/Frontend/Component/FinanceSheetModal.spec.ts` — one card per eligible transaction, no omitted sibling, correct binding.
- **Exclusions**: no Finance-specific card; no transaction-level filter/sort at this stage (deferred per plan D6).

### UXC-5-03: Finance details → shared Transaction Detail Drawer
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue` (Finance adapter), `src/components/review/SheetDetailsModal.vue`
- **Required behavior**: Clicking a Finance transaction title opens the shared `TransactionDetailDrawer` inside the Sheet modal preserving Sheet context: Transaction Details, Evidence, Previous Decisions, Current Stage Actions. No navigation away from Sheet; no second full modal.
- **Affected features**: 009
- **Depends on**: UXC-5-01, UXC-4-02..UXC-4-05
- **Targeted tests**: `tests/Frontend/Component/FinanceSheetModal.spec.ts` — drawer opens inside modal, Sheet context preserved, sections render, no second modal.
- **Exclusions**: no navigation away from Sheet; no second full modal over Sheet modal.

### UXC-5-04: Finance decision confirmation → shared Decision Confirmation
- **Target file(s)**: `src/components/review/DecisionConfirmation.vue` (new, generalized from `QuickDecisionModal.vue`), `src/components/review/TransactionReviewCard.vue`, `src/components/review/TransactionDetailDrawer.vue`
- **Required behavior**: Finance decision confirmation uses shared `DecisionConfirmation` (per-kind copy, `requiresExplanation` = REJECT ‖ RETURN, `expectedVersion`, submitting/error/toast, single transaction via `reviewsStore.recordDecision`). Approve comment optional; Reject/Return mandatory; controls disabled during submit; scrim blocked during flight.
- **Affected features**: 009
- **Depends on**: UXC-5-01, UXC-3-01, UXC-4-05
- **Targeted tests**: `tests/Frontend/Component/DecisionConfirmation.spec.ts` (new) — per-kind copy, comment rules, submitting state, one transaction per request.
- **Exclusions**: no Finance-specific confirmation modal; no auto-retry (FR-075).

### UXC-5-05: Preserve Finance behavior invariants
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue` (Finance adapter), `src/components/review/TransactionReviewCard.vue`, `src/components/review/TransactionDetailDrawer.vue`, `src/stores/reviews.ts`
- **Required behavior**: Preserve `allowedActions` gating; Senior-rejected Approve restriction (Approve not offered when `status === SENIOR_REJECTED`, `canApprove === false`); independent per-transaction decisions; server-authoritative reconciliation (card re-renders from `result.transaction`, detail cache cleared as stale); no bulk action; no Feature 008 account-management controls (no holder directory, balance inspection, ledger, recharge, completed-sheet history).
- **Affected features**: 009
- **Depends on**: UXC-5-01..UXC-5-04
- **Targeted tests**: `tests/Frontend/Component/FinanceSheetModal.spec.ts` — senior-rejected no Approve, independent decisions, server-authoritative refresh, no bulk action, absence of Feature 008 controls.
- **Exclusions**: no bulk Finance approval; no Feature 008 surfaces; no backend change.

### UXC-5-06: Finance migration unit test suite
- **Target file(s)**: `tests/Frontend/Component/FinanceSheetModal.spec.ts` (extend), `tests/Frontend/Component/DecisionConfirmation.spec.ts` (new)
- **Required behavior**: Tests covering UXC-5-01..UXC-5-05: shared modal, card, drawer, decision confirmation, Finance invariants.
- **Affected features**: 009
- **Depends on**: UXC-5-01..UXC-5-05
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/FinanceSheetModal.spec.ts tests/Frontend/Component/DecisionConfirmation.spec.ts` green.
- **Exclusions**: no rerun of completed Finance data/grouping unless affected by the refactor; no E2E.

---

## Phase 6 — Cost Center Migration

**Goal**: Replace Cost Center inline sheet expansion with the shared Sheet Details Modal. Preserve Cost Center behavior.

### UXC-6-01: Cost Center inline sheet expansion → shared Sheet Details Modal
- **Target file(s)**: `src/views/reviews/CostCenterQueueView.vue`, `src/components/review/SheetDetailsModal.vue` (Cost Center stage adapter config)
- **Required behavior**: Cost Center sheet row activation opens the shared `SheetDetailsModal` (Cost Center stage) over the mounted queue; inline sheet expansion replaced by the modal; queue remains mounted. No sheet-level Approve/Reject/Return/decide-all in the DOM.
- **Affected features**: 006
- **Depends on**: UXC-1-08, UXC-3-01, UXC-4-01
- **Targeted tests**: `tests/Frontend/Component/ManagementReview.spec.ts` (update Cost Center queue assertions in place) — sheet row opens shared modal, no router.push, no sheet-level decide-all.
- **Exclusions**: no sheet-level decisions; no backend change.

### UXC-6-02: Preserve Cost Center eligibility, scope, correction rules, decision semantics
- **Target file(s)**: `src/views/reviews/CostCenterQueueView.vue`, `src/components/review/SheetDetailsModal.vue`
- **Required behavior**: Cost Center eligibility, managed cost-centre scope, correction rules, and decision semantics preserved; manager-scoped sheet summary read and decided/returned transactions behavior unchanged; honest unavailable for unproven fields.
- **Affected features**: 006
- **Depends on**: UXC-6-01
- **Targeted tests**: `tests/Frontend/Component/ManagementReview.spec.ts` — eligibility/scope/correction/decision semantics unchanged, honest unavailable for unproven fields.
- **Exclusions**: no backend change; no sheet-wide completion/Senior-eligibility derivation (FR-006-047/048).

### UXC-6-03: Use shared card/drawer/evidence/previous decisions/decision confirmation
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`, `src/components/review/TransactionDetailDrawer.vue`, `src/components/review/DecisionConfirmation.vue`
- **Required behavior**: Cost Center modal uses the same shared transaction card, drawer, evidence, previous-decisions, and decision confirmation as Senior and Finance. Cost Center surface behavior unchanged.
- **Affected features**: 006
- **Depends on**: UXC-6-01, UXC-3-01, UXC-4-01, UXC-5-04
- **Targeted tests**: `tests/Frontend/Component/ManagementReview.spec.ts` — shared primitives used, Cost Center behavior unchanged.
- **Exclusions**: no role-specific duplication; no behavior change.

### UXC-6-04: Cost Center migration unit test suite
- **Target file(s)**: `tests/Frontend/Component/ManagementReview.spec.ts` (update Cost Center queue assertions in place)
- **Required behavior**: Tests covering UXC-6-01..UXC-6-03: shared modal, preserved eligibility/scope/correction/decision semantics, shared primitives.
- **Affected features**: 006
- **Depends on**: UXC-6-01, UXC-6-02, UXC-6-03
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/ManagementReview.spec.ts` green (Cost Center queue assertions).
- **Exclusions**: no test deleted or skipped; no E2E.

---

## Phase 7 — Shared Approvals / Tabs Convergence

**Goal**: Ensure canonical tab meaning is consistent across roles; do not conflate Approvals with Previous Decisions.

### UXC-7-01: Approvals tab — sheet-level aggregate approved monetary totals per stage
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue` (Approvals tab content via stage adapter)
- **Required behavior**: Approvals tab shows approved monetary amount at each workflow stage (Cost Center approved amount, Senior Manager approved amount, Finance Manager approved amount) using authoritative stage totals. Sheet-level aggregate only.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-04, UXC-5-01, UXC-6-01
- **Targeted tests**: `tests/Frontend/Component/SheetDetailsModal.spec.ts` — Approvals tab shows per-stage approved amounts, sheet-level aggregate only.
- **Exclusions**: Approvals not conflated with per-transaction Previous Decisions.

### UXC-7-02: Previous Decisions — transaction-level decision history inside drawer
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue` (Previous Decisions section)
- **Required behavior**: Previous Decisions is transaction-level decision history inside the Transaction Detail Drawer (CC/Senior/Finance separate, each with decision/actor/timestamp/comment). Distinct from sheet-level Approvals.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-4-04
- **Targeted tests**: `tests/Frontend/Component/TransactionDetailDrawer.spec.ts` — Previous Decisions transaction-level, stage-separated, distinct from Approvals.
- **Exclusions**: no conflation with Approvals tab.

### UXC-7-03: Verify Overview and History retain existing authoritative behavior
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue` (Overview/History tabs via stage adapter)
- **Required behavior**: Overview and History tabs retain existing authoritative behavior across roles; no behavior change introduced by convergence.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-04, UXC-5-01, UXC-6-01
- **Targeted tests**: `tests/Frontend/Component/SheetDetailsModal.spec.ts` — Overview/History render authoritatively, unchanged across roles.
- **Exclusions**: no new Overview/History behavior; no role-specific divergence.

---

## Phase 7F — Whole-Sheet Stage-Approved Totals (owner-authorized follow-up)

**Goal**: Connect the canonical Approvals tab to the server-computed **whole-Cost-Sheet** stage-approved monetary totals (Cost Center / Senior Manager / Finance Manager) per the owner decision recorded 2026-11-20 (`specs/006-management-review/spec.md` §2.5, `specs/007-senior-manager-cost-sheet-review/spec.md` `FR-007-057`, `specs/009-finance-manager-expense-review/spec.md` `FR-009-028`). `UXC-7-01` remains **complete with authorized follow-up** — its honest scope-note/unavailable behavior is the interim presentation, and the tasks below replace the derivation source, not the tab's meaning.

**Single source of truth (mandatory)**: `lib/Domain/Expense/SheetTotals.php::fromRows()` over `lib/Infrastructure/Persistence/Expense/SheetSummaryQuery::totalsForSheet()` — computed server-side from each sheet's **complete** transaction set. A transaction contributes to a stage total only when that stage's authoritative decision on the **current active revision** is Approved; Rejected, Returned, undecided, and superseded-revision approvals do not contribute. No second aggregation rule; no client-side derivation from actor-scoped transaction collections.

**Disclosure boundary (mandatory)**: aggregate monetary disclosure only. No task in this phase may widen reviewer transaction scope, evidence visibility, decision-history visibility, mutation authorization, workflow transitions, settlement, account balances, database schema, or persistence semantics. Transaction-level **Previous Decisions** remain inside `TransactionDetailDrawer`, stage-separated, strictly separate from the Approvals tab.

**Contract (aligned and verified — `UXC-7F-01` `[x]`)**: shared additive `StageApprovedTotals` schema in `specs/001-pettycash-v1-core/contracts/ocs-api.yaml`, applied to `ManagerSheetSummary` (required), `SeniorManagerSheetReview.sheet` (required via allOf; `SeniorSheetSummary` itself and the senior list rows omit it), and `TransactionSummary` (optional, emitted only on `GET /queues/finance` items); `src/generated/api.ts` regenerated via `scripts/validate-openapi.sh --write`; `scripts/validate-openapi.sh` drift-green and `npm run typecheck` green.

### UXC-7F-01: Contract and generated-client alignment
- **Target file(s)**: `specs/001-pettycash-v1-core/contracts/ocs-api.yaml`, `src/generated/api.ts` (regenerated only)
- **Required behavior**: shared `StageApprovedTotals` schema (`costCenterApproved`/`seniorApproved`/`financeApproved`, all required when present, one `Money` each in the sheet's single currency — no conversion, no arrays); applied to the Cost Center per-sheet review summary (required), the Senior per-sheet review summary (required on the per-sheet read only), and the Finance queue item (optional field, emitted only on finance-queue items, identical for all items of one Sheet). `scripts/validate-openapi.sh` green; `scripts/validate-openapi.sh --write` regenerated `src/generated/api.ts`; `npm run typecheck` green.
- **Affected features**: 006, 007, 009
- **Depends on**: none
- **Targeted tests**: `scripts/validate-openapi.sh` green (validate + drift); `npm run typecheck` green.
- **Exclusions**: no production backend/frontend behavior implemented in this task.

### UXC-7F-02: Cost Center backend projection
- **Target file(s)**: `lib/Infrastructure/Http/SheetPayloadFormatter.php`, `lib/Controller/ReviewController.php` (`costCenterSheet`)
- **Required behavior**: `GET /queues/cost-center/sheets/{sheetId}` responses carry `sheet.stageApprovedTotals` computed by `SheetSummaryQuery::totalsForSheet()` over the whole Cost Sheet — not the manager-scoped subset. Existing scope/authorization gates unchanged; out-of-scope transactions remain absent from `transactions` while still contributing to the aggregate. No mutation, no workflow change.
- **Affected features**: 006
- **Depends on**: UXC-7F-01
- **Targeted tests**: backend tests asserting the aggregate equals the `SheetTotals` figures (approve/reject/return/undecided mix; superseded-revision approval excluded) and that an out-of-scope transaction contributes to the aggregate without being disclosed; scope gates unchanged (unknown/out-of-scope sheet still answers one 404).
- **Exclusions**: no authorization-mechanism change; no transaction-list widening.

### UXC-7F-03: Senior backend projection
- **Target file(s)**: `lib/Infrastructure/Http/SheetPayloadFormatter.php`, `lib/Controller/ReviewController.php` (`seniorSheet`)
- **Required behavior**: `GET /queues/senior/sheets/{sheetId}` responses carry the same authoritative `sheet.stageApprovedTotals`. Senior gates (`allTransactionsCostCenterDecided` owner D1, `reviewStatus` owner D5, `FR-007-056` scope) unchanged; senior list rows (`GET /queues/senior/sheets`) do not carry the field. Correction/current-revision behavior covered: a Return corrected and re-decided contributes only through its current-revision decision.
- **Affected features**: 007
- **Depends on**: UXC-7F-01
- **Targeted tests**: backend tests for aggregate correctness over approve/reject/return/undecided mixes, the correction/current-revision rule, unchanged scope gates (same 404 for unknown/out-of-scope sheets), and list rows omitting the field.
- **Exclusions**: no gate change; no transaction-list widening; no list-page per-sheet aggregate computation.

### UXC-7F-04: Finance backend projection
- **Target file(s)**: `lib/Infrastructure/Http/SheetPayloadFormatter.php` (or the finance queue projection it serves), `lib/Controller/ReviewController.php` (`index` for `stage=finance`)
- **Required behavior**: `GET /queues/finance` items carry the owning Cost Sheet's `stageApprovedTotals`, server-computed from the complete sheet; every item of one Sheet exposes the identical value; Finance queue eligibility and authorization unchanged; no hidden transaction/detail/evidence/actor/comment disclosure rides along. The aggregate is derived efficiently per **distinct** Sheet on the page (batched/`IN`-set read over the page's distinct `sheet_id` values, mirroring `SheetSummaryQuery::postedAmountsFor`'s batched shape) — no deliberate per-row N+1, and no schema/persistence workaround. If implementation inspection proves the additive queue projection technically unsafe, stop and report rather than redesigning the contract.
- **Affected features**: 009
- **Depends on**: UXC-7F-01
- **Targeted tests**: backend tests — same value for all items sharing a Sheet; approve/reject/return/undecided and superseded-revision rules; finance-eligibility/authorization unchanged; batched derivation verified (no per-row N+1); cost-center/senior queue items do NOT carry the field.
- **Exclusions**: no new endpoint; no eligibility change; no schema/persistence workaround.

### UXC-7F-05: Frontend authoritative aggregate wiring
- **Target file(s)**: `src/views/reviews/CostCenterQueueView.vue`, `src/views/reviews/SeniorQueueView.vue`, `src/views/reviews/FinanceQueueView.vue`, `src/types/api.ts` (additive types if the hand-written types need the field)
- **Required behavior**: Cost Center and Senior build the Approvals tab's stage states from `sheet.stageApprovedTotals` when present; Finance consumes the grouped Sheet's server value (`FinanceSheetQueueItem` carries one consistent aggregate per Sheet). Reliance on incomplete client-side transaction aggregation is removed for these authoritative values; `SheetApprovalsSummary.vue` is unchanged; the honest unavailable/known-zero fallback remains for missing/failed payloads (`src/utils/stageApprovals.ts` stays as the fallback/Finance-subset path only). `UXC-7-01`'s presentation semantics (stage identification, zero-vs-unavailable distinction, scope note) are preserved.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-7F-02, UXC-7F-03, UXC-7F-04
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/ManagementReview.spec.ts tests/Frontend/Component/SeniorReview.spec.ts tests/Frontend/Component/FinanceSheetModal.spec.ts tests/Frontend/Component/SheetApprovalsSummary.spec.ts` green with the authoritative source retargeted and fallbacks preserved.
- **Exclusions**: no new presentation semantics; no business-rule change; `SheetApprovalsSummary.vue` untouched.

### UXC-7F-06: Cross-role regression
- **Target file(s)**: `tests/Frontend/Component/ManagementReview.spec.ts`, `tests/Frontend/Component/SeniorReview.spec.ts`, `tests/Frontend/Component/FinanceSheetModal.spec.ts`, `tests/Frontend/Component/TransactionDetailDrawer.spec.ts`
- **Required behavior**: verified across Cost Center / Senior / Finance — Approved contributes; Rejected does not; Returned does not; undecided does not; superseded-revision approval does not; zero is distinguished from unavailable; transaction visibility is unchanged; Previous Decisions remain transaction-level inside the drawer; no cross-currency summing; EN/FA and RTL/LTR remain valid.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-7F-05
- **Targeted tests**: the four specs above green; `tests/Frontend/l10nCatalog.spec.ts` green (EN/FA parity for any key touched); `npm run typecheck` green.
- **Exclusions**: no new product semantics; no test deleted or skipped.

### UXC-7F-07: Contract/runtime convergence
- **Target file(s)**: `src/generated/api.ts` (regenerated only), build output (`css/`, `js/`)
- **Required behavior**: generated contract types, backend responses, frontend consumption, and the current runtime bundle agree: `scripts/validate-openapi.sh` green (validate + drift), `npm run build` green, the served hashed assets match the tree (`scripts/dev/opcache-settle` before any live check). Final owner visual acceptance is NOT part of this task (`UXC-GATE` remains manual).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-7F-06
- **Targeted tests**: `scripts/validate-openapi.sh` green; `npm run build` green; `npm run typecheck` green; opcache-settle before any live-HTTP evidence.
- **Exclusions**: no final owner visual acceptance; no Phase 8 duplicate removal; no selector migration.

**Dependency order (recorded)**:

```text
UXC-7F-01
→ UXC-7F-02 + UXC-7F-03 + UXC-7F-04 (backend role tasks may run as one bounded batch — the shared implementation is localized in SheetPayloadFormatter + ReviewController responses)
→ UXC-7F-05
→ UXC-7F-06
→ UXC-7F-07
→ resume UXC-8
```

---

## Phase 8 — Duplicate Removal

**Goal**: Remove obsolete implementations only after all migrations and regression tests pass. Do not delete anything before its replacement is verified.

### UXC-8-01: Remove obsolete Senior modal implementation (`SeniorSheetModal.vue`)
- **Target file(s)**: `src/components/review/SeniorSheetModal.vue` (delete), references in `src/views/reviews/SeniorQueueView.vue`
- **Required behavior**: Delete `SeniorSheetModal.vue` after Senior migration (Phase 2) verified by regression tests; all Senior references point to shared `SheetDetailsModal`.
- **Affected features**: 007
- **Depends on**: UXC-2-06 (Senior regression green)
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/SeniorReview.spec.ts tests/Frontend/Component/SeniorSheetModal.spec.ts` green before and after deletion; `npm run typecheck` green.
- **Exclusions**: not deleted before Senior migration verified; no backend change.

### UXC-8-02: Remove obsolete Finance modal implementation (`FinanceSheetModal.vue`)
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue` (delete), references in `src/views/reviews/FinanceQueueView.vue`
- **Required behavior**: Delete `FinanceSheetModal.vue` after Finance migration (Phase 5) verified by regression tests; all Finance references point to shared `SheetDetailsModal`/`TransactionReviewCard`/`TransactionDetailDrawer`/`DecisionConfirmation`.
- **Affected features**: 009
- **Depends on**: UXC-5-06 (Finance migration tests green)
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/FinanceQueue.spec.ts tests/Frontend/Component/FinanceSheetModal.spec.ts` green before and after deletion; `npm run typecheck` green.
- **Exclusions**: not deleted before Finance migration verified; no rerun of completed Finance data/grouping unless affected.

### UXC-8-03: Remove duplicate transaction-card markup
- **Target file(s)**: `src/components/review/QuickReviewCard.vue` (delete or retain only if still referenced by non-migrated surfaces), duplicate card markup in `FinanceSheetModal.vue`/`SeniorSheetModal.vue` (removed with modals)
- **Required behavior**: Remove duplicate transaction-card markup once all three roles use `TransactionReviewCard`; retain `QuickReviewCard` only if a non-migrated surface still references it (verify callers first).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-8-01, UXC-8-02
- **Targeted tests**: `npm run typecheck` green; component test suites for migrated roles green.
- **Exclusions**: no removal while a surface still references `QuickReviewCard`; verify all callers first.

### UXC-8-04: Remove duplicate modal CSS
- **Target file(s)**: `src/components/review/*.vue` scoped styles (FinanceSheetModal/SeniorSheetModal styles removed with modals), shared `SheetDetailsModal.vue` styles retained
- **Required behavior**: Remove duplicate modal CSS from obsolete stage-specific modals; shared modal styles consolidated in `SheetDetailsModal.vue`.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-8-01, UXC-8-02
- **Targeted tests**: `npm run stylelint` green; `npm run build` green.
- **Exclusions**: no removal of shared modal CSS.

### UXC-8-05: Remove obsolete role-specific selectors
- **Target file(s)**: `src/components/review/*.vue`, `src/views/reviews/*.vue`
- **Required behavior**: Remove obsolete role-specific selectors (e.g. `finance-modal-*`, `senior-modal-*`, `quick-review-card-*` where superseded) replaced by stable concept-based selectors (Phase 9). Role-specific selectors retained only where role-specific behavior is actually being tested.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-8-01, UXC-8-02, UXC-9-01
- **Targeted tests**: `npm run typecheck` green; component tests green.
- **Exclusions**: role-specific selectors retained when role-specific behavior is tested.

---

## Phase 9 — Stable Shared Selectors

**Goal**: Introduce stable concept-based selectors used across all three roles.

### UXC-9-01: Introduce stable concept-based selectors
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`, `src/components/review/TransactionReviewCard.vue`, `src/components/review/TransactionDetailDrawer.vue`, `src/views/reviews/CostCenterQueueView.vue`, `src/views/reviews/SeniorQueueView.vue`, `src/views/reviews/FinanceQueueView.vue`
- **Required behavior**: Stable selectors: `sheet-row-<sheetId>`, `sheet-details-modal`, `transaction-review-card-<txId>`, `transaction-detail-drawer`, `drawer-attachment-<evidenceId>`, `decision-item-<decisionId>`, `drawer-decide-<kind>`. Applied consistently across Cost Center, Senior, and Finance surfaces.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-01, UXC-3-01, UXC-4-01
- **Targeted tests**: component tests assert stable selectors; `npm run typecheck` green.
- **Exclusions**: role-specific selectors only where role-specific behavior is actually tested.

### UXC-9-02: Role-specific selector audit
- **Target file(s)**: `src/components/review/*.vue`, `src/views/reviews/*.vue`, `tests/Frontend/Component/**/*.spec.ts`
- **Required behavior**: Audit confirms role-specific selectors appear only where role-specific behavior is actually being tested; all other selectors are concept-based and shared.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-9-01
- **Targeted tests**: grep/audit of selectors; component tests green.
- **Exclusions**: no removal of selectors needed for role-specific behavior tests.

---

## Phase 10 — Cross-Role Frontend Regression

**Goal**: Required coverage across all three roles; preserve all stage-specific workflow assertions.

### UXC-10-01: Cost Center queue → sheet modal regression
- **Target file(s)**: `src/views/reviews/CostCenterQueueView.vue`, `src/components/review/SheetDetailsModal.vue`, `tests/Frontend/Component/ManagementReview.spec.ts`
- **Required behavior**: Cost Center queue row opens shared sheet modal; same transaction-card hierarchy; same drawer hierarchy; evidence behavior; prior decisions; stage-specific actions; focus lifecycle; EN/FA; RTL/LTR; responsive 390px.
- **Affected features**: 006
- **Depends on**: UXC-6-04
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/ManagementReview.spec.ts` green.
- **Exclusions**: no stage-specific workflow assertion removed.

### UXC-10-02: Senior queue → same sheet modal regression
- **Target file(s)**: `src/views/reviews/SeniorQueueView.vue`, `src/components/review/SheetDetailsModal.vue`, `tests/Frontend/Component/SeniorReview.spec.ts`
- **Required behavior**: Senior queue row opens the same shared sheet modal; same card/drawer hierarchy; evidence; prior decisions; stage-specific actions (Senior capability set); focus lifecycle; EN/FA; RTL/LTR; responsive 390px.
- **Affected features**: 007
- **Depends on**: UXC-2-06
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/SeniorReview.spec.ts` green.
- **Exclusions**: no Senior-specific workflow assertion removed.

### UXC-10-03: Finance queue → same sheet modal regression
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`, `src/components/review/SheetDetailsModal.vue`, `tests/Frontend/Component/FinanceQueue.spec.ts`, `tests/Frontend/Component/FinanceSheetModal.spec.ts`
- **Required behavior**: Finance queue row opens the same shared sheet modal; same card/drawer hierarchy; evidence; prior decisions; stage-specific actions (Finance capability set: allowedActions, Senior-rejected Approve restriction, independent decisions); focus lifecycle; EN/FA; RTL/LTR; responsive 390px.
- **Affected features**: 009
- **Depends on**: UXC-5-06
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/FinanceQueue.spec.ts tests/Frontend/Component/FinanceSheetModal.spec.ts` green.
- **Exclusions**: no Finance-specific workflow assertion removed; no bulk action; no Feature 008 controls.

### UXC-10-04: Same transaction-card hierarchy across roles
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`, `tests/Frontend/Component/TransactionReviewCard.spec.ts`
- **Required behavior**: All three roles render the same `TransactionReviewCard` hierarchy; card summary fields and indicators consistent; role differences expressed only via stage adapter/`allowedActions`.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-3-04
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/TransactionReviewCard.spec.ts` green.
- **Exclusions**: no role-specific card duplication.

### UXC-10-05: Same drawer hierarchy across roles
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue`, `tests/Frontend/Component/TransactionDetailDrawer.spec.ts`
- **Required behavior**: All three roles render the same `TransactionDetailDrawer` hierarchy; Transaction Details/Evidence/Previous Decisions/Current Stage Actions consistent; stage actions via adapter only.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-4-06
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/TransactionDetailDrawer.spec.ts` green.
- **Exclusions**: no hardcoded role business logic in drawer.

### UXC-10-06: Evidence behavior regression
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue`, `src/components/evidence/EvidencePreview.vue`, `tests/Frontend/Component/TransactionDetailDrawer.spec.ts`
- **Required behavior**: Evidence bound to correct transaction; image/PDF/unknown preview; Previous/Next + `1/N`; download fallback; return to same transaction context; no mutation controls.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-4-03
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/TransactionDetailDrawer.spec.ts` green.
- **Exclusions**: no evidence viewer redesign.

### UXC-10-07: Prior decisions regression
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue`, `src/components/review/PriorDecisionHistory.vue`, `tests/Frontend/Component/TransactionDetailDrawer.spec.ts`
- **Required behavior**: Prior decisions stage-separated (CC/Senior/Finance) with actor/timestamp/comment; `No decision yet`; never collapsed; consistent across roles.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-4-04
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/TransactionDetailDrawer.spec.ts` green.
- **Exclusions**: no rolling up to sheet verdict.

### UXC-10-08: Stage-specific actions regression
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue`, `src/components/review/TransactionReviewCard.vue`, `src/components/review/DecisionConfirmation.vue`
- **Required behavior**: Stage-specific actions via adapter/`allowedActions`; Approve/Reject/Return gating; comment rules; one transaction per decision; Senior-rejected Approve restriction; Finance independence.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-4-05, UXC-5-05
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/TransactionDetailDrawer.spec.ts tests/Frontend/Component/TransactionReviewCard.spec.ts tests/Frontend/Component/DecisionConfirmation.spec.ts` green.
- **Exclusions**: no bulk action; no hardcoded role logic.

### UXC-10-09: Focus lifecycle regression
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`, `src/components/review/TransactionDetailDrawer.vue`, `src/components/evidence/EvidencePreview.vue`
- **Required behavior**: Focus trap; Escape; close; focus restoration to originating sheet row; drawer/preview one layer at a time; focus returns correctly across modal/drawer/preview transitions.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-05
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/SheetDetailsModal.spec.ts tests/Frontend/Component/TransactionDetailDrawer.spec.ts` green.
- **Exclusions**: no focus loss on close.

### UXC-10-10: EN/FA, RTL/LTR, responsive 390px regression
- **Target file(s)**: `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js`, `src/components/review/SheetDetailsModal.vue`, `src/components/review/TransactionReviewCard.vue`, `src/components/review/TransactionDetailDrawer.vue`
- **Required behavior**: EN/FA catalogue key parity; English LTR and Persian RTL render correctly; UUIDs/currency codes direction-isolated; 390px no horizontal scroll; `≥44px` touch targets; light/dark/forced-colours spot-check.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-1-06, UXC-9-01
- **Targeted tests**: `npm test -- --run tests/Frontend/l10nCatalog.spec.ts` green; component tests in LTR/RTL at 390px.
- **Exclusions**: no hardcoded direction; no new mobile product requirements.

---

## Phase 11 — Runtime / Build Convergence

**Goal**: Ensure the live Nextcloud runtime serves the shared UX bundle; no deployment outside the established local environment.

### UXC-11-01: Verify current build output
- **Target file(s)**: repository root scripts (`npm run build`), `css/`, `js/` build output
- **Required behavior**: `npm run build` succeeds; current build output contains shared `SheetDetailsModal`, `TransactionReviewCard`, `TransactionDetailDrawer`, `DecisionConfirmation` assets; no build errors.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-10-10 (frontend regression green)
- **Targeted tests**: `npm run build` green; `npm run typecheck` green; `npm run lint` green; `npm run stylelint` green.
- **Exclusions**: no deployment outside local lab environment.

### UXC-11-02: Verify loaded hashed asset
- **Target file(s)**: runtime mount `/var/www/html/custom_apps/pettycash` (via `pettycash-lab-nextcloud-1`), `scripts/dev/opcache-settle`
- **Required behavior**: After build, run `scripts/dev/opcache-settle`; confirm the served hashed JS/CSS asset corresponds to the current build (served build hash == tree hash); app loads at `http://localhost:8081`.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-11-01
- **Targeted tests**: opcache settle succeeds; page loads without build error; hash comparison.
- **Exclusions**: no deployment outside local environment; no direct PHP/Composer/PHPUnit.

### UXC-11-03: No stale stage-specific UI bundle
- **Target file(s)**: `css/`, `js/` build output, runtime mount
- **Required behavior**: No stale stage-specific UI bundle (obsolete `FinanceSheetModal`/`SeniorSheetModal`/duplicate card JS) remains in runtime after duplicate removal (Phase 8) and rebuild.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-8-05, UXC-11-02
- **Targeted tests**: build output audit — obsolete stage-specific modal/card assets absent; served assets match current tree.
- **Exclusions**: no runtime changes outside local lab.

### UXC-11-04: No old inline/Finance-specific surface remains in runtime
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`, `src/views/reviews/CostCenterQueueView.vue`, runtime mount
- **Required behavior**: Old inline sheet expansion (Cost Center) and old Finance-specific surface removed/replaced by shared primitives in served runtime; route cache cleared if any `appinfo/routes.php` change (`scripts/dev/route-settle`, `scripts/dev/route-cache-clear`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-6-04, UXC-5-06, UXC-8-05
- **Targeted tests**: `scripts/dev/route-settle` + `scripts/dev/route-cache-clear` if routes changed; runtime loads shared UX; no old inline/Finance-specific surface.
- **Exclusions**: no route changes unless required; no deployment outside local environment.

---

## Phase 12 — Real Chrome / E2E Convergence

**Goal**: Run cross-role real-Chrome flows only after all shared migrations are complete. Do not run E2E as part of this document.

### UXC-12-01: Cost Center real-Chrome E2E flow
- **Target file(s)**: `tests/E2E/` (Cost Center flow spec), official Chrome channel
- **Required behavior**: Sheet row → Sheet Details Modal → Transaction Card → Drawer → Evidence → permitted decision (Cost Center capability set). Real Chrome, desktop + 390px, EN/FA, RTL/LTR.
- **Affected features**: 006
- **Depends on**: UXC-10-01, UXC-11-04
- **Targeted tests**: Playwright E2E (official Chrome) — Cost Center canonical flow green; stage-specific workflow assertions preserved.
- **Exclusions**: no E2E run by this document; no backend change.

### UXC-12-02: Senior real-Chrome E2E flow
- **Target file(s)**: `tests/E2E/` (Senior flow spec), official Chrome channel
- **Required behavior**: Same canonical UX with Senior capability set; sheet-wide gate; filter/sort/export preserved; completed/read-only behavior preserved.
- **Affected features**: 007
- **Depends on**: UXC-10-02, UXC-11-04
- **Targeted tests**: Playwright E2E (official Chrome) — Senior canonical flow green.
- **Exclusions**: no E2E run by this document.

### UXC-12-03: Finance real-Chrome E2E flow
- **Target file(s)**: `tests/E2E/finance-expense-review.spec.ts` (extend/update), official Chrome channel
- **Required behavior**: Same canonical UX with Finance capability set; preserve Finance settlement/idempotency/balance assertions; sheet-row → modal → card → drawer → evidence → permitted decision.
- **Affected features**: 009
- **Depends on**: UXC-10-03, UXC-11-04
- **Targeted tests**: Playwright E2E (official Chrome) — Finance canonical flow green; settlement/idempotency/negative-balance assertions preserved.
- **Exclusions**: no E2E run by this document; no backend change.

### UXC-12-04: Complete pending Feature 009 browser tasks only after scenarios pass
- **Target file(s)**: `tests/E2E/finance-expense-review.spec.ts`, `artifacts/visual-review/`
- **Required behavior**: Complete the still-pending Feature 009 browser tasks (H-03/H-04/H-05) only after the cross-role real-Chrome scenarios genuinely pass; record screenshot evidence under `artifacts/visual-review/<task-id>/<iteration>/` (Git-ignored); a visual result without recorded owner approval is not reported as accepted.
- **Affected features**: 009
- **Depends on**: UXC-12-01, UXC-12-02, UXC-12-03
- **Targeted tests**: `npm run test:e2e` green; screenshot evidence present.
- **Exclusions**: no E2E run by this document; no owner approval claimed.

---

## Post-Convergence Follow-Up (owner-approved, 2026-11-21)

A single bounded visual refinement recorded after the Phase 1–12 convergence was completed. It changes presentation only; no historical task state above is rewritten and no boundary below is reopened.

### UXC-FU-01: Compact icon-only review-progress indicators on the shared transaction card
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`, `tests/Frontend/Component/TransactionReviewCard.spec.ts`; canonical requirement wording updated in `specs/006-management-review/spec.md` (`FR-006-039` + §2.1 item 3), `specs/007-senior-manager-cost-sheet-review/spec.md` (`FR-007-019`), `specs/009-finance-manager-expense-review/spec.md` (`FR-009-011a`)
- **Required behavior**: Replace the full-width textual prior-decision strip with a compact icon-only review-progress row: exactly three ~28–32px circular status chips in fixed workflow order (Cost Center → Senior Manager → Finance Manager), glyph shape independently distinguishing state (`✓` / `✕` / `↩` / `•`) over semantic tokens (success / muted danger / warning / neutral), no permanently visible stage/status text, full `Stage — State` label (e.g. `Cost Center — Approved`) exposed as tooltip and accessible name and repeated as visually-hidden text, non-interactive (no checkbox, no button, not focusable), undecided stage still rendered. Semantic states (Approved / Rejected / Returned / No decision yet), the transaction status badge, and the drawer's Previous Decisions content are unchanged.
- **Affected features**: 006, 007, 009 (shared card)
- **Depends on**: Phase 12 convergence complete (`UXC-12-01`–`UXC-12-04`)
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/TransactionReviewCard.spec.ts`; affected role regression suites (QuickReviewWorkflow, ManagementReview, SeniorSheetModal, FinanceSheetModal, SeniorReview, QuickReviewAccessibilityConformance); `npm run typecheck`; ESLint/Stylelint on changed files; full `npm test`.
- **Exclusions**: no workflow, decision-state, `allowedActions`, API/backend/database, authorization, financial-rule, evidence-permission, or Approvals-tab change; no `TransactionDetailDrawer.vue` change; no new dependency; owner visual gates untouched and not claimed complete.
- **Status**: `[x]` done (2026-11-21) — implemented as three `28px` circular glyph chips with `role="img"` + `aria-label` + visually-hidden label text (no new l10n keys; the label composes the existing stage/state catalogue keys). Keyboard-tooltip acceptance gap closed the same day: each chip is keyboard-focusable (`tabindex="0"`, non-actionable — Enter/Space perform nothing) and a component-owned tooltip bubble reveals the full `Stage — State` label on pointer hover AND keyboard focus, dismissed on blur and pointer leave; the hover-only native `title` was removed. Checks actually run and green: `TransactionReviewCard.spec.ts` (incl. focus/blur/hover/one-at-a-time/Enter-Space-inert and RTL focus-reveal assertions); affected role suites (QuickReviewWorkflow, ManagementReview, SeniorSheetModal, FinanceSheetModal, SeniorReview, QuickReviewAccessibilityConformance); `npm run typecheck`; targeted ESLint on changed files; `npm run stylelint`; full `npm test`. FR-071 typography floor respected (`var(--font-size-floor)`). Real-Chrome E2E not run (mission-bounded batch); no owner visual gate claimed.

---

### UXC-FU-02: Tooltip edge-clipping correction + Returned warning-color correction
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`, `tests/Frontend/Component/TransactionReviewCard.spec.ts`, `tests/E2E/uxc-fu01-visual-evidence.mjs` (env-parameterized reuse for `artifacts/visual-review/UXC-FU-02/001/`)
- **Required behavior**: Fix the two presentation defects found by the UXC-FU-01 real-Chrome evidence, without redesigning the indicator concept. (A) The tooltip bubble anchors its inline-start edge to the chip's inline-start edge (`inset-inline-start: 0`, logical, direction-mirroring) and grows inward, with a `240px` cap + wrapping — the only deterministic placement that keeps the bubble (~200px+, wider than the whole 96px chip row plus the modal's ~40px slack) inside the card content box in LTR and RTL at desktop and 390px; centring any chip or end-aligning the last provably re-clips. (B) The `Returned` chip uses the Nextcloud warning semantic `var(--color-warning)` (amber, soft 14% background, 35% border) instead of the purple `--pettycash-review` accent; glyph `↩` unchanged. Hover/keyboard-focus reveal, one-at-a-time, accessible naming, non-actionable semantics, three-stage order unchanged.
- **Affected features**: 006, 007, 009 (shared card)
- **Depends on**: UXC-FU-01
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/TransactionReviewCard.spec.ts`; the six affected role suites; `npm run typecheck`; targeted ESLint; `npm run stylelint`; full `npm test`; then `npm run build` + `scripts/dev/opcache-settle` + repo/runtime/HTTP hash convergence and real-Chrome evidence under `artifacts/visual-review/UXC-FU-02/001/` (tooltip fully readable at inline-start in EN-LTR desktop and FA-RTL 390px; Returned amber; no horizontal scroll).
- **Exclusions**: no indicator-concept redesign, no workflow/decision-state/API/backend/authorization/financial change, no l10n change, no new dependency, owner gates untouched.

---

---

## Phase UXC-V3 — Management Review UX v3 (owner-approved requirement change, 2026-11-21)

**Status**: **Superseded before implementation by the product-owner selection of Manus v2** (2026-11-21). All tasks below remain `[ ]` unchecked. **No UXC-V3 implementation task was executed.** The shared interaction decisions that also belong to the approved v2 model (stable modal geometry, inline expansion, first-view date, persistent card actions, evidence summary, typography floor, focus lifecycle) are carried into Phase UXC-V2; the `Review Ledger` visual pattern is withdrawn and MUST NOT be implemented. This section is retained for auditability only — it does not authorize any work.

**Goal**: Implement the product-owner-approved Management Review UX v3 design across the three shared review surfaces: stable-modal-geometry `SheetDetailsModal`, first-view transaction cards with a visible transaction date and persistent reviewer actions, the **Review Ledger** per-stage indicator (replacing the `UXC-FU-01` icon-only chips), and the **Inline Expanded Transaction** (replacing the `TransactionDetailDrawer` interaction). Specification alignment is already recorded (2026-11-21) in `specs/006-management-review/spec.md` (reworded `FR-006-038/039/040/041/043/044/045`, new `FR-006-049`–`FR-006-052`), `specs/007-senior-manager-cost-sheet-review/spec.md` (reworded `FR-007-003/019/020/020a/031/057`, new `FR-007-058`), and `specs/009-finance-manager-expense-review/spec.md` (reworded `FR-009-003/011a/011b/011c/019/020/022/028`, new `FR-009-029`).

**Boundary (this ledger's Scope, unchanged)**: frontend presentation only. No backend API, OpenAPI contract, database schema, persistence, authorization, financial/settlement rule, evidence permission, workflow progression, or decision semantics may change. l10n changes are additive EN/FA catalogue keys only. `TransactionDetailDrawer.vue` is removed only by `UXC-V3-13`, after the migration is proven and no active production or test dependency remains. No historical task status above is rewritten; no task below may be marked complete by this document.

### UXC-V3-01: Shared stable-modal geometry (`SheetDetailsModal`)
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`, `tests/Frontend/Component/SheetDetailsModal.spec.ts`
- **Required behavior**: Modal geometry is stable across tabs and tab switches: stable modal width; stable usable content height; stable header; stable summary/header area; stable tab bar; only the internal tab-content region scrolls; sparse tabs retain intentional whitespace instead of collapsing or resizing the modal. The same geometry stability applies at the 390px viewport (006 `FR-006-049`).
- **Affected features**: 006, 007, 009
- **Depends on**: none (Phase 1–12 convergence complete)
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/SheetDetailsModal.spec.ts` — geometry-stability assertions (identical modal box across tab switches, scroll contained to the tab-content region, sparse-tab whitespace retained) green.
- **Exclusions**: no tab semantics change; no business rules inside the shell; no drawer removal.

### UXC-V3-02: Review Ledger shared component
- **Target file(s)**: `src/components/review/` (new shared Review Ledger component extracted from the `TransactionReviewCard` stage indicators; exact name fixed at implementation), `src/components/review/TransactionReviewCard.vue` (consumer), new component spec + `tests/Frontend/Component/TransactionReviewCard.spec.ts`, `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js` (additive keys only)
- **Required behavior**: One read-only Review Ledger component presenting exactly the stages Cost Center → Senior Manager → Finance Manager in fixed workflow order; per stage: stable stage identity, permanently visible stage label, separate shape-distinguishing state glyph, short state label, restrained semantic color treatment; states Approved / Rejected / Returned / No decision yet, the undecided stage still rendered; never colour-alone, never hover/tooltip-dependent (the `UXC-FU-01`/`UXC-FU-02` tooltip-only label treatment is retired for requirements; historical FU status above is untouched), never generic traffic-light semantics; NOT a checkbox/button/actionable control. The same component renders in both collapsed and expanded transaction states (006 `FR-006-039`, 007 `FR-007-019`, 009 `FR-009-011a`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-01
- **Targeted tests**: the new component spec + `npm test -- --run tests/Frontend/Component/TransactionReviewCard.spec.ts` (visible stage labels present in the DOM, glyph/label/colour independence, read-only semantics, RTL rendering) green; `tests/Frontend/l10nCatalog.spec.ts` green (EN/FA parity).
- **Exclusions**: no decision-state semantic change; no `allowedActions` change; no Approvals-tab change; no new dependency.

### UXC-V3-03: Transaction-card first-view hierarchy and visible date
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`, `tests/Frontend/Component/TransactionReviewCard.spec.ts`
- **Required behavior**: The collapsed card exposes without expansion: transaction title (≈18px), amount + currency (≈18–20px), **transaction date** from the existing authoritative source (`TransactionSummary.activeRevision.expenseDate`; no new field), transaction lifecycle status, compact metadata, the Review Ledger (UXC-V3-02), the evidence summary (UXC-V3-06), and all server-permitted current-stage actions (UXC-V3-07). Base body/metadata/state/button text ≥16px per the enforced floor (001 `FR-071`, the established `--font-size-floor` token); the ≈22–24px sheet title stays in the modal header; density achieved through spacing/grouping/hierarchy/reduced chrome, not sub-16px text (006 `FR-006-038`/`FR-006-051`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-01, UXC-V3-02
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/TransactionReviewCard.spec.ts` (date rendered from `expenseDate`, honest handling of a missing date, typography-floor assertions, no fabricated values) green.
- **Exclusions**: no new transaction field invented; merchant/paid-on/payment-method/category stay honest unavailable (006 `FR-006-038`); no status/badge semantic change.

### UXC-V3-04: Inline transaction expansion interaction
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue` (Transactions tab region), `src/components/review/TransactionReviewCard.vue`, `tests/Frontend/Component/SheetDetailsModal.spec.ts`, `tests/Frontend/Component/TransactionReviewCard.spec.ts`
- **Required behavior**: Activating a transaction opens the **Inline Expanded Transaction** in place within the Transactions tab (canonical `TransactionReviewCard → Inline Expanded Transaction`): no route navigation, no second full modal over the Sheet modal; exactly one transaction expanded at a time — opening another collapses the previously expanded one; the expanded region stays visually connected to the surrounding sheet transaction list; the modal's stable geometry (UXC-V3-01) is preserved while expanding/collapsing (content grows inside the scrollable tab-content region). Accessible disclosure lifecycle: `aria-expanded`/`aria-controls` on the toggle, focus moves into the expanded region on expand and returns to the toggle on collapse (006 `FR-006-040`/`FR-006-052`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-01, UXC-V3-03
- **Targeted tests**: `SheetDetailsModal.spec.ts` + `TransactionReviewCard.spec.ts` (one-at-a-time expansion, in-place rendering, no route push, geometry stability during expansion, disclosure a11y, focus lifecycle) green.
- **Exclusions**: no Evidence Preview/Decision Confirmation behavior change (they remain lightweight overlays); no drawer removal in this task.

### UXC-V3-05: Migration of drawer detail content into the inline expansion
- **Target file(s)**: the inline-expansion content region (new shared markup in the `SheetDetailsModal`/card family; exact placement fixed at implementation), `src/components/review/TransactionDetailDrawer.vue` (read-only source of record until UXC-V3-13), `src/components/review/StatusBadge.vue`/`PriorDecisionHistory.vue` reuse, new/extended expansion spec (mirroring `tests/Frontend/Component/TransactionDetailDrawer.spec.ts` content assertions)
- **Required behavior**: The drawer's canonical functional content moves into the expanded region, preserving the drawer's information requirements verbatim: Transaction Details (description/title, expense date, amount, currency, expense type, cost center, transaction reference, current transaction status, other existing authoritative fields, honest unavailable states), Previous Decisions (stage-separated CC/Senior/Finance with decision/actor/timestamp/comment; `No decision yet`; never collapsed), Current Stage Actions (adapter/`allowedActions`-gated only), Evidence (UXC-V3-06). Desktop MAY use a two-column internal arrangement where appropriate; 390px uses the deliberate vertical flow details → evidence → history → actions; unnecessary nested panels avoided (006 `FR-006-041`, 007 `FR-007-020`, 009 `FR-009-011b`). Escape layering now applies to the modal plus the preview/confirmation overlays (the drawer is no longer an overlay layer).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-04
- **Targeted tests**: expanded-content spec green (details fields, honest unavailable, stage-separated decisions, adapter-only actions); the drawer spec's content assertions mirrored onto the expansion before any drawer removal; role suites still green with the drawer interaction retired in favor of the expansion.
- **Exclusions**: no information removed or narrowed relative to the drawer; no business rules inside the expanded region; `TransactionDetailDrawer.vue` not yet modified or deleted in this task.

### UXC-V3-06: Evidence integration (collapsed summary + expanded detail + unchanged preview)
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`, the inline-expansion region, `src/components/evidence/EvidencePreview.vue` (reuse verbatim), the currently consumed evidence list/card rendering (`src/components/review/EvidenceCard.vue`/`EvidenceList.vue` where referenced), related specs
- **Required behavior**: Collapsed card evidence summary: evidence count; one or two real image thumbnails when available; PDF/file representation when applicable; `+N` for additional evidence. Expanded transaction: richer evidence information with the existing preview/download behavior. The Evidence Preview overlay (inline-safe closed set, download-only fallback, accessible dialog name, Escape/scrim close, object-URL revocation, Previous/Next + `1 / N`) is reused unchanged and returns to the same transaction context inside the expansion. No upload/replace/delete control on any reviewer surface (006 `FR-006-038`/`FR-006-042`, 009 `FR-009-021`/`FR-009-022`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-03, UXC-V3-05
- **Targeted tests**: card + expansion evidence assertions green (binding to the correct transaction, thumbnails/count/`+N`, preview open/close context restore, no mutation controls); `tests/Frontend/Component/EvidencePreview.spec.ts` green.
- **Exclusions**: no evidence viewer redesign; no evidence permission change; no uploader components on reviewer surfaces.

### UXC-V3-07: Persistent reviewer action treatment on the card
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`, `src/components/review/DecisionConfirmation.vue` (behavior unchanged, re-verified), `tests/Frontend/Component/TransactionReviewCard.spec.ts`, `tests/Frontend/Component/DecisionConfirmation.spec.ts`
- **Required behavior**: Approve / Return / Reject are directly visible on the collapsed card whenever permitted by server-authoritative capability rules (`allowedActions`/stage grants — the existing `transaction-card-decide-<kind>` decide bar), never hidden behind expansion or an overflow menu. Visual hierarchy: Approve primary; Return warning/correction; Reject restrained destructive. Per-stage/role restrictions preserved (Finance senior-rejected Approve restriction; completed-sheet read-only; `readOnly`/`showActions` gating). Decision Confirmation behavior and explanation rules unchanged (Reject/Return mandatory comment, Approve optional; FR-084/FR-085) (006 `FR-006-050`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-03
- **Targeted tests**: `TransactionReviewCard.spec.ts` + role suites (capability gating, hierarchy, one decision per transaction, confirmation flow unchanged) green.
- **Exclusions**: no new action type; no `allowedActions` semantics change; no sheet-level action; no DecisionConfirmation redesign.

### UXC-V3-08: Previous-decision history presentation in the expanded transaction
- **Target file(s)**: the inline-expansion region, `src/components/review/PriorDecisionHistory.vue` (reuse; additive props only if needed), related specs
- **Required behavior**: Detailed transaction-level decision history inside the expanded transaction: each stage (Cost Center / Senior Manager / Finance Manager) distinct, each with stage, decision, actor, timestamp, and explanation when present; actor/time visually secondary; `No decision yet` explicit; never collapsed into one generic status; never converted into sheet-level history (sheet-level aggregates remain in the Approvals tab only) (006 `FR-006-041`, 009 `FR-009-019`/`FR-009-020`/`FR-009-020a`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-05
- **Targeted tests**: expansion history assertions green (stage separation, actor/timestamp/comment, no-decision-yet, not sheet-level); existing `PriorDecisionHistory` coverage green.
- **Exclusions**: no history data change; no conflation with the Approvals tab.

### UXC-V3-09: Cost Center integration
- **Target file(s)**: `src/views/reviews/CostCenterQueueView.vue`, `tests/Frontend/Component/ManagementReview.spec.ts`, `tests/Frontend/Component/QuickReviewWorkflow.spec.ts`
- **Required behavior**: Cost Center queue → shared modal with UXC-V3-01..08 applied: stable geometry, card first-view hierarchy with visible date, Review Ledger, inline expansion with migrated drawer content, evidence summary/detail, persistent CC decide bar, stage-separated history. CC eligibility/scope/correction/decision semantics, reconciliation behavior, and `FR-006-047`/`FR-006-048` honesty unchanged.
- **Affected features**: 006
- **Depends on**: UXC-V3-05, UXC-V3-06, UXC-V3-07, UXC-V3-08
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/ManagementReview.spec.ts tests/Frontend/Component/QuickReviewWorkflow.spec.ts` green (drawer-path assertions retargeted to the expansion; no workflow assertion removed).
- **Exclusions**: no CC workflow change; no backend change; no sheet-level action.

### UXC-V3-10: Senior integration
- **Target file(s)**: `src/views/reviews/SeniorQueueView.vue`, `tests/Frontend/Component/SeniorReview.spec.ts`, `tests/Frontend/Component/SeniorSheetModal.spec.ts`, `tests/Frontend/Composables/SeniorSheetQueue.spec.ts`
- **Required behavior**: Senior queue → shared modal with UXC-V3-01..08 applied. Sheet-wide gate (`allTransactionsCostCenterDecided`, owner D1), completed/read-only sheets (owner D5), two-level filter/sort, XLSX export beside close, and decision semantics preserved; card/expanded surfaces render the Review Ledger and the inline expansion with the Senior capability set.
- **Affected features**: 007
- **Depends on**: UXC-V3-05, UXC-V3-06, UXC-V3-07, UXC-V3-08
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/SeniorReview.spec.ts tests/Frontend/Component/SeniorSheetModal.spec.ts tests/Frontend/Composables/SeniorSheetQueue.spec.ts` green (drawer-path assertions retargeted; gate/export/read-only assertions preserved).
- **Exclusions**: no gate change; no export change; no 006/009 surface change.

### UXC-V3-11: Finance integration
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`, `tests/Frontend/Component/FinanceQueue.spec.ts`, `tests/Frontend/Component/FinanceSheetModal.spec.ts`
- **Required behavior**: Finance queue → shared modal with UXC-V3-01..08 applied. `allowedActions` gating, Senior-rejected Approve restriction, independent per-transaction decisions, no bulk control, honest unavailable identity, whole-sheet `stageApprovedTotals` consumption — all unchanged; card/expanded surfaces render the Review Ledger and the inline expansion with the Finance capability set.
- **Affected features**: 009
- **Depends on**: UXC-V3-05, UXC-V3-06, UXC-V3-07, UXC-V3-08
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/FinanceQueue.spec.ts tests/Frontend/Component/FinanceSheetModal.spec.ts` green (drawer-path assertions retargeted; E-01..E-05/F-01..F-05 assertions preserved).
- **Exclusions**: no Finance workflow/authorization change; no Feature 008 surfaces; no settlement change.

### UXC-V3-12: RTL / 390px / accessibility convergence
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`, `src/components/review/TransactionReviewCard.vue`, the inline-expansion region, `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js`, related specs
- **Required behavior**: Desktop and 390px, English LTR and Persian RTL: no horizontal scroll; logical CSS properties throughout the new/changed surfaces; identifiers and currency codes direction-isolated where currently required; the canonical workflow order (Cost Center → Senior Manager → Finance Manager) preserved semantically in the Review Ledger and history in both directions; `≥44px` touch targets at 390px; keyboard operability and visible focus for the disclosure toggle, expanded region, card actions, and overlays; light/dark/forced-colours spot-check; EN/FA catalogue parity for additive keys (006 `FR-006-009`/`FR-006-045`/`FR-006-051`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-09, UXC-V3-10, UXC-V3-11
- **Targeted tests**: `tests/Frontend/l10nCatalog.spec.ts`, `tests/Frontend/Component/QuickReviewAccessibilityConformance.spec.ts`, `tests/Frontend/fr071NarrowViewport.spec.ts`, `tests/Frontend/keyboard-accessibility.spec.ts`, plus component RTL/390px assertions green.
- **Exclusions**: no new mobile product requirements beyond the 390px floor; no hardcoded direction; no l10n key removal.

### UXC-V3-13: Duplicate drawer cleanup (only after migration is proven)
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue` (delete), `tests/Frontend/Component/TransactionDetailDrawer.spec.ts` (retire/retarget), import/reference audit across `src/` and `tests/`
- **Required behavior**: Delete `TransactionDetailDrawer.vue` and its dedicated suite only after (a) `UXC-V3-09`/`UXC-V3-10`/`UXC-V3-11` targeted suites are green with the inline expansion serving all three roles and (b) a dependency audit proves zero remaining production or test imports of the drawer (the `UXC-8-05`-style reachability gate). Drawer-specific selectors (`drawer-*` family, `drawer-section-decisions`, `drawer-decide-<kind>`) converge onto the expansion's stable concept-based selectors; E2E/visual-evidence consumers updated in the same task; route-name/reachability gates restored to strict with no exemption.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-09, UXC-V3-10, UXC-V3-11
- **Targeted tests**: grep/import audit (zero drawer references); `npm test -- --run` on the three role suites + `tests/Frontend/routeNameIntegrity.spec.ts` + `tests/Frontend/e2e-runnability.spec.ts` green; `npm run typecheck` green.
- **Exclusions**: not deleted before the migration is proven and zero dependencies remain; no removal of any retained role-specific selector that still tests role-specific behavior; no source change beyond the drawer removal/retargeting.

### UXC-V3-14: Cross-role frontend regression (final tree)
- **Target file(s)**: `tests/Frontend/Component/ManagementReview.spec.ts`, `SeniorReview.spec.ts`, `SeniorSheetModal.spec.ts`, `FinanceQueue.spec.ts`, `FinanceSheetModal.spec.ts`, `TransactionReviewCard.spec.ts`, `SheetDetailsModal.spec.ts`, `DecisionConfirmation.spec.ts`, `SheetApprovalsSummary.spec.ts`, `EvidencePreview.spec.ts`, `l10nCatalog.spec.ts`, `QuickReviewAccessibilityConformance.spec.ts`, `fr071NarrowViewport.spec.ts`, `keyboard-accessibility.spec.ts`, `routeNameIntegrity.spec.ts`
- **Required behavior**: Full cross-role regression over the post-cleanup tree: all stage-specific workflow assertions preserved (CC eligibility/scope/correction; Senior gate/read-only/export; Finance `allowedActions`/senior-rejected restriction/independence/no-bulk; FR-084/FR-085; settlement untouched); geometry stability; card first-view content incl. visible date; Review Ledger semantics; inline expansion one-at-a-time and focus lifecycle; evidence binding; history stage separation; honest unavailable states; `UXC-7F-05` authoritative totals untouched.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-12, UXC-V3-13
- **Targeted tests**: targeted suites green, then full `npm test`; `npm run typecheck`; targeted ESLint/Stylelint.
- **Exclusions**: no test deleted or skipped; no assertion weakened; no backend change.

### UXC-V3-15: Build / runtime convergence
- **Target file(s)**: build output (`css/`, `js/`), runtime mount `/var/www/html/custom_apps/pettycash` (via `pettycash-lab-nextcloud-1`), `scripts/dev/opcache-settle`
- **Required behavior**: `npm run build` green; the build contains the Review Ledger + inline-expansion assets and no orphaned drawer chunk; three-way hash identity (repo tree = runtime mount = HTTP-served) for the affected chunks; opcache settled before any live check; no `appinfo/` change expected (route scripts only if evidence demands, per AGENTS.md §6).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-14
- **Targeted tests**: `npm run build` green; `npm run typecheck` green; `npm run lint`/`npm run stylelint` green; hash comparison; runtime loads the v3 surfaces.
- **Exclusions**: no deployment outside the local lab; no direct host PHP/Composer/PHPUnit; no contract change (`scripts/validate-openapi.sh` expected drift-green with no edit).

### UXC-V3-16: Real-Chrome cross-role verification
- **Target file(s)**: `tests/E2E/` (CC/Senior/Finance flow specs and visual-evidence scripts retargeted from drawer interactions to the inline expansion), official Chrome channel
- **Required behavior**: Cross-role canonical flows in real Chrome, desktop + 390px, EN/FA, RTL/LTR: queue → modal (stable geometry) → card (visible date, Review Ledger, permitted actions) → inline expansion (details → evidence → history → actions at 390px) → preview/decision overlays → decided state; Senior gate/filter/sort/export and completed read-only; Finance restrictions and settlement-adjacent assertions preserved; screenshots under `artifacts/visual-review/<task-id>/<iteration>/`.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-15
- **Targeted tests**: Playwright E2E (official Chrome `channel: 'chrome'`) green for the three roles.
- **Exclusions**: no owner approval claimed by automation; no backend change; no assertion weakening.

### UXC-V3-GATE: Owner visual acceptance — Management Review UX v3 (manual, unchecked)
- **Target file(s)**: none (artifact only — `artifacts/visual-review/` screenshots + this gate record)
- **Required behavior**: Product-owner manual review of the UX v3 surfaces across Cost Center, Senior, and Finance in English LTR and Persian RTL, desktop and 390px: stable modal geometry, card first-view content with visible transaction date, Review Ledger, inline expanded transaction, evidence presentation, persistent actions, previous-decision history. Remains unchecked by automation; requires explicit product-owner approval; does NOT silently complete `UXC-GATE` or the feature owner gates (`T022`, `T037`, `T009-GATE-01`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V3-16
- **Targeted tests**: Recorded owner approval (gate identifier, date, authorized scope). Automation MUST NOT check this task.
- **Exclusions**: not auto-completable; does not complete feature owner gates.

**Dependency order (recorded)**:

```text
UXC-V3-01
→ UXC-V3-02 → UXC-V3-03 → UXC-V3-04 → UXC-V3-05 → (UXC-V3-06 + UXC-V3-07 + UXC-V3-08)
  (UXC-V3-06 also depends on UXC-V3-03; UXC-V3-07 depends on UXC-V3-03; UXC-V3-08 depends on UXC-V3-05)
→ (UXC-V3-09 + UXC-V3-10 + UXC-V3-11 — role integrations may run as one bounded batch after 05–08)
→ (UXC-V3-12 + UXC-V3-13 — drawer cleanup only after the three role integrations are green and the dependency audit proves zero drawer references)
→ UXC-V3-14 → UXC-V3-15 → UXC-V3-16 → UXC-V3-GATE
```

---

## Phase UXC-V2 — Management Review UX v2 (owner-approved Manus v2 baseline, 2026-11-21)

**Status**: Active. This phase supersedes Phase UXC-V3 **before any implementation**: the owner reviewed both versions again and explicitly selected the final presentation baseline (cleaner, less visually dense transaction rows). The former external design-reference package was deleted with the design-governance directory on 2026-11-23; no external design file is retained or authoritative, and the feature specifications remain the authoritative executable requirements. `UXC-V2-01`–`UXC-V2-15` are implemented and verified (2026-11-21/22, status notes on the tasks below); `UXC-V2-16 [x]` is complete on real-Chrome evidence in `artifacts/visual-review/UXC-V2-16/002/`.

**Goal**: Implement the approved v2 presentation across the three shared review surfaces: stable-modal-geometry `SheetDetailsModal`, first-view transaction cards with a visible transaction date, always-visible reviewer actions, an evidence summary, the compact **stage identity + state marker** review-progress component (v2 — not the superseded v3 Review Ledger), and the **Inline Expanded Transaction** (replacing the `TransactionDetailDrawer` interaction). Specification alignment is recorded (2026-11-21) in `specs/006-management-review/spec.md` (reworded `FR-006-038/039/040/041/043/044/045`; `FR-006-049`–`FR-006-052` retained), `specs/007-senior-manager-cost-sheet-review/spec.md` (reworded `FR-007-003/019/020/020a/031/057`; `FR-007-058` retained), and `specs/009-finance-manager-expense-review/spec.md` (reworded `FR-009-003/011a/011b/011c/019/020/020a/022/028`; `FR-009-029` retained).

**Boundary (this ledger's Scope, unchanged)**: frontend presentation only. No backend API, OpenAPI contract, database schema, persistence, authorization, financial/settlement rule, evidence permission, workflow progression, or decision semantics may change. l10n changes are additive EN/FA catalogue keys only. `TransactionDetailDrawer.vue` is removed only by `UXC-V2-13`, after the migration is proven and no active production or test dependency remains. No historical task status (including Phase UXC-V3) is rewritten.

### UXC-V2-01: Stable Sheet Details Modal geometry
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`, `tests/Frontend/Component/SheetDetailsModal.spec.ts`
- **Required behavior**: Modal geometry is stable across tabs and tab switches: stable modal width; stable usable content height; stable header; stable summary/header area; stable tab bar; only the internal tab-content region scrolls; sparse tabs retain intentional whitespace instead of collapsing or resizing the modal. The same geometry stability applies at the 390px viewport (006 `FR-006-049`).
- **Affected features**: 006, 007, 009
- **Depends on**: none (Phase 1–12 convergence complete)
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/SheetDetailsModal.spec.ts` — geometry-stability assertions (identical modal box across tab switches, scroll contained to the tab-content region, sparse-tab whitespace retained) green.
- **Exclusions**: no tab semantics change; no business rules inside the shell; no drawer removal.
- **Status**: `[x]` done (2026-11-21) — the dialog is a fixed-height frame (`height: min(860px, calc(100vh - 76px))`, `overflow: hidden`; 390px `height: calc(100vh - 32px)`) so width and usable height are viewport-stable constants; header, summary metrics and tab bar are `flex: 0 0 auto` inside a non-scrolling body, and the tab-panels region is the single scroll container (panel is plain content — sparse tabs keep whitespace). Tab semantics, focus trap, Escape/scrim/close behavior, RTL and slots untouched. New `Stable geometry (UXC-V2-01)` describe: same dialog/title/tab-bar nodes across tab switches + source-level containment/fixed-height assertions; targeted specs green (SheetDetailsModal 36/36), role suites green, full `npm test` 1194/1194.

### UXC-V2-02: Compact stage identity + state marker component
- **Target file(s)**: `src/components/review/` (new shared review-progress component extracted from the `TransactionReviewCard` stage indicators; exact name fixed at implementation), `src/components/review/TransactionReviewCard.vue` (consumer), new component spec + `tests/Frontend/Component/TransactionReviewCard.spec.ts`, `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js` (additive keys only)
- **Required behavior**: One read-only review-progress component presenting exactly the stages Cost Center → Senior Manager → Finance Manager in fixed workflow order; per stage a restrained **stage identity** (identity icon/mark plus stage label, visible on desktop without hover; may compact on narrow viewports with accessible labels remaining available through text and focus treatment) and a **separate small semantic state marker** visually distinct from the stage identity; states Approved / Rejected / Returned / No decision yet, the undecided stage still rendered; compact and visually light — substantially less vertical space than the superseded v3 Review Ledger — and compact enough for scanning many transaction rows; the three-cell v3 ledger layout and large equal-height stage panels are NOT prescribed; never colour-alone, never hover-dependent, never a generic traffic-light appearance; restrained semantic color; RTL-compatible; NOT a checkbox/button/actionable control. The same component renders in both collapsed and expanded transaction states (006 `FR-006-039`, 007 `FR-007-019`, 009 `FR-009-011a`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-01
- **Targeted tests**: the new component spec + `npm test -- --run tests/Frontend/Component/TransactionReviewCard.spec.ts` (stage identity visible without hover, state marker distinct from identity, compactness, read-only semantics, RTL rendering) green; `tests/Frontend/l10nCatalog.spec.ts` green (EN/FA parity).
- **Exclusions**: no decision-state semantic change; no `allowedActions` change; no Approvals-tab change; no new dependency; no v3 ledger layout.
- **Status**: `[x]` done (2026-11-21) — new shared component `src/components/review/ReviewStageMarkers.vue` (name reported): fixed order Cost Center (`mdiOfficeBuildingOutline`) → Senior Manager (`mdiAccountTieOutline`) → Finance Manager (`mdiCashMultiple`), each stage a restrained identity (icon + label, visible text at every width, no hover dependency) plus a separate 22px semantic state pill (✓ success / ✕ error / ↩ warning / • neutral), undecided stage rendered, `role="img"` + composed `Stage — State` accessible name + visually-hidden duplicate, not focusable (no tooltip exists), no buttons/inputs, wraps instead of scrolling, RTL-safe via the shared direction util, labels composed from existing catalogue keys (no new l10n keys). Specs: new `tests/Frontend/Component/ReviewStageMarkers.spec.ts` (18/18) + card retarget; full `npm test` green.

### UXC-V2-03: Transaction-card hierarchy + first-view date
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`, `tests/Frontend/Component/TransactionReviewCard.spec.ts`
- **Required behavior**: The collapsed card exposes without expansion: transaction title, amount + currency, **transaction date** from the existing authoritative source (`TransactionSummary.activeRevision.expenseDate`; no new field), transaction lifecycle status, compact metadata, the review-progress component (UXC-V2-02), the evidence summary (UXC-V2-06), and all server-permitted current-stage actions (UXC-V2-07). The v2 card is cleaner and less visually dense than the superseded v3 composition, achieved through spacing/hierarchy/alignment/reduced chrome — never through sub-16px text: base body/metadata/state/button text ≥16px per the enforced floor (001 `FR-071`, the established `--font-size-floor` token; the v2 mockup's small illustrative text is not production typography); the sheet title stays in the modal header (006 `FR-006-038`/`FR-006-051`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-01, UXC-V2-02
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/TransactionReviewCard.spec.ts` (date rendered from `expenseDate`, honest handling of a missing date, typography-floor assertions, no fabricated values) green.
- **Exclusions**: no new transaction field invented; merchant/paid-on/payment-method/category stay honest unavailable (006 `FR-006-038`); no status/badge semantic change.
- **Status**: `[x]` done (2026-11-21) — the card renders the v2 first-view hierarchy: title, amount + currency, the mandatory visible transaction date directly under the title (`TransactionSummary.activeRevision.expenseDate` via `formatSheetDate`, honest absence marker, `transaction-card-date` testid), lifecycle status badge, quiet metadata row now including the available expense type (existing `reviews.expenseType` key, no new l10n keys), the shared `ReviewStageMarkers` row ordered before the evidence summary, the preserved evidence summary (count + up to two type-art tiles + `+N`; real image thumbnails remain the UXC-V2-06 migration — no backend thumbnail endpoint exists), and the unchanged server-granted decide bar. All pre-existing testids/emits preserved (role suites pass unmodified). Card spec retargeted to the marker semantics + new date/metadata tests (45/45); full `npm test` 65 files 1194/1194 green.

### UXC-V2-04: Inline transaction expansion interaction
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue` (Transactions tab region), `src/components/review/TransactionReviewCard.vue`, `tests/Frontend/Component/SheetDetailsModal.spec.ts`, `tests/Frontend/Component/TransactionReviewCard.spec.ts`
- **Required behavior**: Activating a transaction opens the **Inline Expanded Transaction** in place within the Transactions tab (canonical `TransactionReviewCard → Inline Expanded Transaction`): no route navigation, no second full modal over the Sheet modal; exactly one transaction expanded at a time — opening another collapses the previously expanded one; the expanded region stays visually connected to the surrounding sheet transaction list; the modal's stable geometry (UXC-V2-01) is preserved while expanding/collapsing (content grows inside the scrollable tab-content region). Accessible disclosure lifecycle: `aria-expanded`/`aria-controls` on the toggle, focus moves into the expanded region on expand and returns to the toggle on collapse (006 `FR-006-040`/`FR-006-052`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-01, UXC-V2-03
- **Targeted tests**: `SheetDetailsModal.spec.ts` + `TransactionReviewCard.spec.ts` (one-at-a-time expansion, in-place rendering, no route push, geometry stability during expansion, disclosure a11y, focus lifecycle) green.
- **Exclusions**: no Evidence Preview/Decision Confirmation behavior change (they remain lightweight overlays); no drawer removal in this task.
- **Status**: `[x]` done (2026-11-21) — `TransactionReviewCard` gained the disclosure expansion: `expandable`/`expanded` props with caller-owned single `expandedId`, `toggleExpand` emit, `aria-expanded`/`aria-controls` on the title toggle (which keeps the legacy `openDetails` contract when a caller wires no expansion), focus into the region on expand and back to the toggle on collapse (never stolen when a sibling expansion opens), and deterministic Escape layering — Escape inside the expanded card collapses it and never reaches the Sheet modal, Escape outside still closes the modal, the preview/confirmation overlays keep their own Escape. Expanded region renders in place inside the Transactions tab (one dialog at a time, no route push, stable frame; content grows inside the scrollable panels region). Tests: `TransactionReviewCard.spec.ts` 67/67, `SheetDetailsModal.spec.ts` 43/43 incl. the two-card one-at-a-time harness.

### UXC-V2-05: Migrate existing drawer detail content inline
- **Target file(s)**: the inline-expansion content region (new shared markup in the `SheetDetailsModal`/card family; exact placement fixed at implementation), `src/components/review/TransactionDetailDrawer.vue` (read-only source of record until UXC-V2-13), `src/components/review/StatusBadge.vue`/`PriorDecisionHistory.vue` reuse, new/extended expansion spec (mirroring `tests/Frontend/Component/TransactionDetailDrawer.spec.ts` content assertions)
- **Required behavior**: The drawer's canonical functional content moves into the expanded region, preserving the drawer's information requirements verbatim: Transaction Details (description/title, expense date, amount, currency, expense type, cost center, transaction reference, current transaction status, other existing authoritative fields, honest unavailable states), Previous Decisions (stage-separated CC/Senior/Finance with decision/actor/timestamp/comment; `No decision yet`; never collapsed), Current Stage Actions (adapter/`allowedActions`-gated only), Evidence (UXC-V2-06). Desktop MAY use the v2-style two-column internal arrangement where appropriate; 390px uses the vertical flow details → evidence → history → actions; unnecessary nested panels avoided (006 `FR-006-041`, 007 `FR-007-020`, 009 `FR-009-011b`). Escape layering now applies to the modal plus the preview/confirmation overlays (the drawer is no longer an overlay layer).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-04
- **Targeted tests**: expanded-content spec green (details fields, honest unavailable, stage-separated decisions, adapter-only actions); the drawer spec's content assertions mirrored onto the expansion before any drawer removal; role suites still green with the drawer interaction retired in favor of the expansion.
- **Exclusions**: no information removed or narrowed relative to the drawer; no business rules inside the expanded region; `TransactionDetailDrawer.vue` not yet modified or deleted in this task.
- **Status**: `[x]` done (2026-11-21) — new shared `src/components/review/TransactionInlineDetail.vue` carries the drawer's functional content into the expansion (drawer file byte-identical, still source of record until UXC-V2-13): read-state honesty (loading/loaded/forbidden/notFound/unavailable), the authoritative details hero + grid + description with every honest unavailable state, transaction-bound evidence rows emitting `preview`, stage-separated Previous Decisions via `PriorDecisionHistory` compact, and the adapter-gated decide surface (label override, capability gating, busy) + decide note. Desktop uses the approved light two-column arrangement; the DOM order is the 390px flow details → evidence → history → actions. Drawer spec content assertions mirrored in the new `TransactionInlineDetail.spec.ts` 28/28; drawer spec 34/34 unchanged.

### UXC-V2-06: Evidence summary and expanded evidence integration
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`, the inline-expansion region, `src/components/evidence/EvidencePreview.vue` (reuse verbatim), the currently consumed evidence list/card rendering (`src/components/review/EvidenceCard.vue`/`EvidenceList.vue` where referenced), related specs
- **Required behavior**: Collapsed card evidence summary: evidence count; one or two real image thumbnails when available; PDF/file representation when applicable; `+N` for additional evidence. Expanded transaction: richer evidence information with the existing preview/download behavior. The Evidence Preview overlay (inline-safe closed set, download-only fallback, accessible dialog name, Escape/scrim close, object-URL revocation, Previous/Next + `1 / N`) is reused unchanged and returns to the same transaction context inside the expansion. No upload/replace/delete control on any reviewer surface (006 `FR-006-038`/`FR-006-042`, 009 `FR-009-021`/`FR-009-022`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-03, UXC-V2-05
- **Targeted tests**: card + expansion evidence assertions green (binding to the correct transaction, thumbnails/count/`+N`, preview open/close context restore, no mutation controls); `tests/Frontend/Component/EvidencePreview.spec.ts` green.
- **Exclusions**: no evidence viewer redesign; no evidence permission change; no uploader components on reviewer surfaces.
- **Status**: `[x]` done (2026-11-21) — new shared `src/components/review/EvidenceTile.vue` renders the collapsed summary's up-to-two tiles as real fetched image thumbnails when the item is an active inline-safe image (the existing `GET /evidence/{id}/content` source EvidencePreview/EvidenceCard already use; object URL revoked on unmount, no persistent cache), with the honest type-art fallback for PDF/unknown/inactive/failed states (never a fabricated preview, C-16.8 honoured). Count + `+N` unchanged; expanded rows keep name/type/size metadata with tile art; `EvidencePreview.vue` reused verbatim (spec green); no upload/replace/delete controls (inline spec asserts); no new l10n keys.

### UXC-V2-07: Always-visible reviewer action treatment
- **Target file(s)**: `src/components/review/TransactionReviewCard.vue`, `src/components/review/DecisionConfirmation.vue` (behavior unchanged, re-verified), `tests/Frontend/Component/TransactionReviewCard.spec.ts`, `tests/Frontend/Component/DecisionConfirmation.spec.ts`
- **Required behavior**: Approve / Return / Reject are directly visible on the collapsed card whenever permitted by server-authoritative capability rules (`allowedActions`/stage grants — the existing `transaction-card-decide-<kind>` decide bar), never hidden behind expansion or an overflow menu; normal review actions MUST NOT require expansion. v2 visual hierarchy: Approve is the only filled/primary action; Return uses restrained warning styling; Reject uses a quiet restrained destructive outline; the action group is compact and must not overpower title, amount, date, status, or review progress. Per-stage/role restrictions preserved (Finance senior-rejected Approve restriction; completed-sheet read-only; `readOnly`/`showActions` gating). Decision Confirmation behavior and explanation rules unchanged (Reject/Return mandatory comment, Approve optional; FR-084/FR-085) (006 `FR-006-050`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-03
- **Targeted tests**: `TransactionReviewCard.spec.ts` + role suites (capability gating, hierarchy, one decision per transaction, confirmation flow unchanged) green.
- **Exclusions**: no new action type; no `allowedActions` semantics change; no sheet-level action; no DecisionConfirmation redesign.
- **Status**: `[x]` done (2026-11-21) — all server-permitted actions stay visible on the collapsed card (testids `transaction-card-decide-<kind>` preserved) through the new shared `src/components/review/ReviewDecideActions.vue`, the one decide-bar implementation rendered in both card contexts: Approve `primary` (only filled action), Return `warning` quiet outline, Reject `error` quiet outline (scoped, semantic variant classes preserved in the DOM). Capability gating, busy blocking, read-only/showActions withholding, and DecisionConfirmation unchanged; `QuickReviewAccessibilityConformance.spec.ts` retargeted to the shared component for the mobile full-width + 44px floors it now owns (10/10).

### UXC-V2-08: Previous-decision history presentation
- **Target file(s)**: the inline-expansion region, `src/components/review/PriorDecisionHistory.vue` (reuse; concise stage rows with restrained actor/time hierarchy; additive props only if needed), related specs
- **Required behavior**: Detailed transaction-level decision history inside the expanded transaction as three distinct compact rows (Cost Center / Senior Manager / Finance Manager), each stage distinct, with stage + decision leading, actor/timestamp visually secondary, and the explanation shown only when present; `No decision yet` explicit; never collapsed into one generic status; never converted into sheet-level history (sheet-level aggregates remain in the Approvals tab only); avoids a visually heavy audit-log presentation (006 `FR-006-041`, 009 `FR-009-019`/`FR-009-020`/`FR-009-020a`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-05
- **Targeted tests**: expansion history assertions green (stage separation, actor/timestamp/comment, no-decision-yet, not sheet-level); existing `PriorDecisionHistory` coverage green.
- **Exclusions**: no history data change; no conflation with the Approvals tab.
- **Status**: `[x]` done (2026-11-21) — `PriorDecisionHistory.vue` gained the additive `variant="compact"` (default byte-identical, drawer suites green): per stage a concise row with decision pill leading, actor · timestamp on one secondary line, explanation only when present, explicit `No decision yet`, lighter separators — the three stages remain distinct groups in canonical order, transaction-level only, never merged with the sheet Approvals aggregate (inline spec asserts the negative). No history data change.

### UXC-V2-09: Cost Center integration
- **Target file(s)**: `src/views/reviews/CostCenterQueueView.vue`, `tests/Frontend/Component/ManagementReview.spec.ts`, `tests/Frontend/Component/QuickReviewWorkflow.spec.ts`
- **Required behavior**: Cost Center queue → shared modal with UXC-V2-01..08 applied: stable geometry, card first-view hierarchy with visible date, stage identity + state marker review progress, inline expansion with migrated drawer content, evidence summary/detail, persistent CC decide bar, stage-separated history. CC eligibility/scope/correction/decision semantics, reconciliation behavior, and `FR-006-047`/`FR-006-048` honesty unchanged.
- **Affected features**: 006
- **Depends on**: UXC-V2-05, UXC-V2-06, UXC-V2-07, UXC-V2-08
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/ManagementReview.spec.ts tests/Frontend/Component/QuickReviewWorkflow.spec.ts` green (drawer-path assertions retargeted to the expansion; no workflow assertion removed).
- **Exclusions**: no CC workflow change; no backend change; no sheet-level action.
- **Status**: `[x]` done (2026-11-21) — `CostCenterQueueView.vue` migrated: card gains `expandable`/`:expanded`/`:detail`/`:detail-read-state`/`:detail-sheet`/`:detail-stage` + `@toggle-expand`; `detailsTransactionId` → caller-owned single `expandedTransactionId` (toggle + `ensureTransactionDetail` on expand); `drawerStage`/`detailsSheet`/`detailsReadState` repurposed as `inlineStage`/`expandedSheet`/`expandedReadState` keyed on the expanded id (CC decide-bar visibility rule `managerState === 'AWAITING_MANAGER_DECISION'` preserved; sheet facts still the manager read's); `openPreviewFromCard`/`openConfirmForTransaction` failure paths expand the transaction (honest inline read state) instead of opening the drawer; drawer import/template/functions removed (zero CC consumers). State lifecycle: reset on sheet-open watcher, on decision completion (deterministic collapse, drawer-era parity), and a dangling guard collapses when the expanded id leaves `panelTransactions` of the open sheet. CC suites: ManagementReview 61/61 (retargeted drawer-path assertions + new `inline transaction expansion (UXC-V2-09)` describe: one-at-a-time, transaction-bound detail, preview bound to the expanded transaction and returning to it, expanded decide row → one confirmation POST for that transaction → deterministic collapse, close/reopen starts collapsed, drawer unreachable) + QuickReviewWorkflow 5/5 (refusal state in expansion, single-overlay handover with the expansion surviving the preview cycle, queue state across the toggle cycle).

### UXC-V2-10: Senior integration
- **Target file(s)**: `src/views/reviews/SeniorQueueView.vue`, `tests/Frontend/Component/SeniorReview.spec.ts`, `tests/Frontend/Component/SeniorSheetModal.spec.ts`, `tests/Frontend/Composables/SeniorSheetQueue.spec.ts`
- **Required behavior**: Senior queue → shared modal with UXC-V2-01..08 applied. Sheet-wide gate (`allTransactionsCostCenterDecided`, owner D1), completed/read-only sheets (owner D5), two-level filter/sort, XLSX export beside close, and decision semantics preserved; card/expanded surfaces render the v2 review-progress presentation and the inline expansion with the Senior capability set.
- **Affected features**: 007
- **Depends on**: UXC-V2-05, UXC-V2-06, UXC-V2-07, UXC-V2-08
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/SeniorReview.spec.ts tests/Frontend/Component/SeniorSheetModal.spec.ts tests/Frontend/Composables/SeniorSheetQueue.spec.ts` green (drawer-path assertions retargeted; gate/export/read-only assertions preserved).
- **Exclusions**: no gate change; no export change; no 006/009 surface change.
- **Status**: `[x]` done (2026-11-21) — `SeniorQueueView.vue` migrated with the identical ownership/reset pattern; Senior specifics preserved verbatim: gate/eligibility untouched (sheets enter only via the backend-proved set), `isSheetCompleted` read-only (`:read-only` + `allowed-actions null` + `inlineStage` withholding the decide row), two-level filter/sort/XLSX export untouched (suites green unmodified), `inlineStage` keeps the legacy Senior display order REJECT→RETURN→APPROVE and withholds on `AWAITING_SENIOR_DECISION` violation; window-Escape fallback comment updated (the expanded card stops its own Escape). Suites: SeniorSheetModal 32/32 (4 drawer-path tests retargeted to the expansion incl. BUG-05 sheet-header metadata and the footer-decision→confirmation route) + new UXC-V2-10 describe (one-at-a-time binding, Escape collapse keeping the gated sheet context, completed-sheet read-only expansion, drawer unreachable) + SeniorReview 24/24 + SeniorSheetQueue 33/33 composable green.

### UXC-V2-11: Finance integration
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`, `tests/Frontend/Component/FinanceQueue.spec.ts`, `tests/Frontend/Component/FinanceSheetModal.spec.ts`
- **Required behavior**: Finance queue → shared modal with UXC-V2-01..08 applied. `allowedActions` gating, Senior-rejected Approve restriction, independent per-transaction decisions, no bulk control, honest unavailable identity, whole-sheet `stageApprovedTotals` consumption — all unchanged; card/expanded surfaces render the v2 review-progress presentation and the inline expansion with the Finance capability set.
- **Affected features**: 009
- **Depends on**: UXC-V2-05, UXC-V2-06, UXC-V2-07, UXC-V2-08
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/FinanceQueue.spec.ts tests/Frontend/Component/FinanceSheetModal.spec.ts` green (drawer-path assertions retargeted; E-01..E-05/F-01..F-05 assertions preserved).
- **Exclusions**: no Finance workflow/authorization change; no Feature 008 surfaces; no settlement change.
- **Status**: `[x]` done (2026-11-21) — `FinanceQueueView.vue` migrated with the identical ownership/reset pattern; Finance specifics preserved verbatim: capability gating stays server-authoritative (`allowedActionsFor` from the detail read; the senior-rejected Approve restriction is server data, not presentation — `inlineStage` adds no rule), `financeCardDecided` withholding preserved for both card bar and expanded row, Option A honest-unavailable identity untouched, `stageApprovedTotals` consumption untouched, settlement/balances/idempotency untouched (no financial logic in the view changed; the confirm path posts through the same `recordDecision` with FR-085 commandId). Suites: FinanceSheetModal 34/34 (3 drawer-path tests retargeted incl. E-02 gating on the expanded row and F-05 stage separation) + new UXC-V2-11 describe (one-at-a-time binding, Finance-decided expansion read-only, expanded decide row → exactly one POST → collapse, drawer unreachable) + FinanceQueue 8/8 green.

### UXC-V2-12: RTL / 390px / accessibility convergence
- **Target file(s)**: `src/components/review/SheetDetailsModal.vue`, `src/components/review/TransactionReviewCard.vue`, the inline-expansion region, `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js`, related specs
- **Required behavior**: Desktop and 390px, English LTR and Persian RTL: no horizontal scroll; logical CSS properties throughout the new/changed surfaces (layout, alignment, action placement, and review-progress direction mirror in RTL); identifiers and currency codes direction-isolated where currently required; the canonical stage sequence (Cost Center → Senior Manager → Finance Manager) remains explicit in accessible/data order even when the visual direction is RTL; at 390px the stage identity may compact to icon + marker while accessible labels remain available through text and focus treatment; `≥44px` touch targets at 390px; keyboard operability and visible focus for the disclosure toggle, expanded region, card actions, and overlays; light/dark/forced-colours spot-check; EN/FA catalogue parity for additive keys (006 `FR-006-009`/`FR-006-045`/`FR-006-051`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-09, UXC-V2-10, UXC-V2-11
- **Targeted tests**: `tests/Frontend/l10nCatalog.spec.ts`, `tests/Frontend/Component/QuickReviewAccessibilityConformance.spec.ts`, `tests/Frontend/fr071NarrowViewport.spec.ts`, `tests/Frontend/keyboard-accessibility.spec.ts`, plus component RTL/390px assertions green.
- **Exclusions**: no new mobile product requirements beyond the 390px floor; no hardcoded direction; no l10n key removal.
- **Status**: `[x]` done (2026-11-21) — convergence verified across the shared v2 interaction (`SheetDetailsModal`, `TransactionReviewCard` + expansion, `TransactionInlineDetail`, `ReviewStageMarkers`, `ReviewDecideActions`, `EvidenceTile`, `EvidencePreview`, `PriorDecisionHistory`) over desktop/390px × EN-LTR/FA-RTL. Corrections: the modal heading's remaining physical `min-width: 0` → `min-inline-size: 0`, and `prefers-reduced-motion` added to the transaction card (the one animated shared surface without a suppression block). Verified converged: single scroll container inside the fixed-height frame with the `width <= 390px` floor block (44px tabs/close); wrap-not-scroll rows (stage markers, card meta, decide groups) so no surface answers a narrow viewport with a horizontal scrollbar (FR-071 suite green); DOM order = the 390px flow details → evidence → history → actions; bdi `dir="ltr"` islands for currency/sheet-reference/transaction-id; disclosure `aria-expanded`/`aria-controls` with focus-in-on-expand/focus-out-on-collapse and one-Escape collapse; 44px floors on decide buttons, inline attachment rows, modal close and preview controls; stage markers keep glyph + label identity (no hover dependency, state not colour-alone, canonical order in data/accessibility order); logical properties with no physical `left/right` in any reviewed surface. Evidence: 14 targeted suites green (246/246) incl. `QuickReviewAccessibilityConformance` retargeted from the retired drawer source onto the inline/modal surfaces, `l10nCatalog` EN/FA parity, `fr071NarrowViewport`, `keyboard-accessibility`, `typography`, `RtlAccessibility`; the UXC-10-09 cross-layer unique-id assertion ported onto the active modal+expansion+preview harness in `QuickReviewWorkflow.spec.ts` and explicit bdi-isolation assertions added to `TransactionInlineDetail.spec.ts`. No l10n keys added or removed; no browser/visual evidence claimed (owner visual gate outstanding).

### UXC-V2-13: Drawer cleanup after zero-dependency proof
- **Target file(s)**: `src/components/review/TransactionDetailDrawer.vue` (delete), `tests/Frontend/Component/TransactionDetailDrawer.spec.ts` (retire/retarget), import/reference audit across `src/` and `tests/`
- **Required behavior**: Delete `TransactionDetailDrawer.vue` and its dedicated suite only after (a) `UXC-V2-09`/`UXC-V2-10`/`UXC-V2-11` targeted suites are green with the inline expansion serving all three roles and (b) a dependency audit proves zero remaining production or test imports of the drawer (the `UXC-8-05`-style reachability gate). Drawer-specific selectors (`drawer-*` family, `drawer-section-decisions`, `drawer-decide-<kind>`) converge onto the expansion's stable concept-based selectors; E2E/visual-evidence consumers updated in the same task; route-name/reachability gates restored to strict with no exemption.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-09, UXC-V2-10, UXC-V2-11
- **Targeted tests**: grep/import audit (zero drawer references); `npm test -- --run` on the three role suites + `tests/Frontend/routeNameIntegrity.spec.ts` + `tests/Frontend/e2e-runnability.spec.ts` green; `npm run typecheck` green.
- **Exclusions**: not deleted before the migration is proven and zero dependencies remain; no removal of any retained role-specific selector that still tests role-specific behavior; no source change beyond the drawer removal/retargeting.
- **Status**: `[x]` done (2026-11-21) — zero-dependency audit proven, then cleanup executed. Audit (exact, post-cleanup): zero active references to `TransactionDetailDrawer` in production source — no import, dynamic import, component registration, render/mount usage, drawer-only selector, or route reference anywhere under `src/`; the only remaining occurrences are explicitly historical comments (the inline detail's parity docblock naming the former file, its spec header, and this ledger/routeNameIntegrity history notes). Removed: `src/components/review/TransactionDetailDrawer.vue` and its dedicated `TransactionDetailDrawer.spec.ts` (34 tests; the behavioral content was already mirrored green in `TransactionInlineDetail.spec.ts` — read-state honesty, detail fields + honest unavailables, evidence rows + preview emit + no mutation controls, stage-separated compact history + never-the-aggregate, capability-gated decide surface incl. busy/label-override/note, 390px DOM-order flow, not-a-dialog, stage neutrality; only the overlay-only shell semantics — teleport, scrim, close button, focus trap/restore, drawer-layer Escape — retired with the component; UXC-10-09 unique-id coverage ported to `QuickReviewWorkflow.spec.ts` and bdi direction-isolation assertions added to `TransactionInlineDetail.spec.ts` so coverage did not merely shrink). Also removed: the `PENDING_MIGRATION` exemption in `routeNameIntegrity.spec.ts` (reachability gate strict again), the drawer-unreachable negative assertions in the ManagementReview/SeniorSheetModal/FinanceSheetModal/QuickReviewWorkflow/SeniorReview specs (the two approvals-panel `drawer-decisions-` guards retargeted to `inline-decisions-`, SeniorReview's decide guard onto the `inline-decide-`/`transaction-card-decide-` families, preserving UXC-7-02 semantics), stale present-tense drawer comments across src and specs, and the drawer selector consumers in the E2E/visual-evidence runners (`finance-expense-review.spec.ts`, `t026`, `t030`, `t034-t035`, `t040-t041` — retargeted onto `transaction-expanded-detail`/`inline-*` with Escape collapse, dir assertions on the expanded card, in-place expansion geometry checks instead of full-screen-overlay box checks, and screenshots renamed `drawer-*` → `expanded-*`). Evidence: role suites green (ManagementReview 61/61, SeniorSheetModal 32/32, SeniorReview 24/24, FinanceSheetModal 34/34, FinanceQueue 8/8, QuickReviewWorkflow 5/5), `routeNameIntegrity` 6/6, `e2e-runnability` 3/3, `npm run typecheck`/`npm run lint`/`npm run stylelint` green, full `npm test` 66 files 1248/1248 green (1281 → 1248 = the 34 retired drawer-only tests − the mirrored behavioral coverage they duplicated, +1 new bdi test; no other test count moved). No backend/API/schema/financial/evidence-permission change; the l10n `reviews.quickReview.drawer.*`/`reviews.transactionDrawer.*` keys are retained (catalogue key names, not component references).

### UXC-V2-14: Cross-role frontend regression
- **Target file(s)**: `tests/Frontend/Component/ManagementReview.spec.ts`, `SeniorReview.spec.ts`, `SeniorSheetModal.spec.ts`, `FinanceQueue.spec.ts`, `FinanceSheetModal.spec.ts`, `TransactionReviewCard.spec.ts`, `SheetDetailsModal.spec.ts`, `DecisionConfirmation.spec.ts`, `SheetApprovalsSummary.spec.ts`, `EvidencePreview.spec.ts`, `l10nCatalog.spec.ts`, `QuickReviewAccessibilityConformance.spec.ts`, `fr071NarrowViewport.spec.ts`, `keyboard-accessibility.spec.ts`, `routeNameIntegrity.spec.ts`
- **Required behavior**: Full cross-role regression over the post-cleanup tree: all stage-specific workflow assertions preserved (CC eligibility/scope/correction; Senior gate/read-only/export; Finance `allowedActions`/senior-rejected restriction/independence/no-bulk; FR-084/FR-085; settlement untouched); geometry stability; card first-view content incl. visible date; stage identity + state marker semantics; inline expansion one-at-a-time and focus lifecycle; evidence binding; history stage separation; honest unavailable states; `UXC-7F-05` authoritative totals untouched.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-12, UXC-V2-13
- **Targeted tests**: targeted suites green, then full `npm test`; `npm run typecheck`; targeted ESLint/Stylelint.
- **Exclusions**: no test deleted or skipped; no assertion weakened; no backend change.
- **Status**: `[x]` done (2026-11-22) — final frontend regression over the post-cleanup tree: zero regressions found, zero repairs needed, no source file changed by this task. Matrix evidence (grouped targeted runs, all green): Cost Center — ManagementReview 61/61 (queue → sheet review, stable modal, Transactions/Approvals/Overview/History, first-view date, stage identity + state markers, evidence summary, one-at-a-time inline expansion, details, evidence preview, Approve/Return/Reject, confirmation, post-decision refresh, returned/revised behavior, read-only/resolved states) + QuickReviewWorkflow 5/5 (one POST/command-id, conflict words, refusal-in-expansion, single-overlay handover, queue state across the cycle) + SheetDetailsModal 43/43 (stable geometry, tab semantics, slots); Senior — SeniorReview 24/24 (gate, previous-CC-decision visibility, completed read-only, export, returned workflow) + SeniorSheetModal 32/32 (expansion binding, read-only expansion, filter/sort, single dialog frame) + SeniorSheetQueue 33/33 (two-level filter/sort composable); Finance — FinanceSheetModal 34/34 (eligibility, senior-rejected Approve restriction, previous-stage separation, whole-sheet approved totals, completed read-only, server-authoritative allowedActions, settlement-adjacent consumption untouched) + FinanceQueue 8/8 + FinanceReview 26/26 + FinanceSheetQueue 34/34 + financeStore 20/20 + stageApprovals 22/22; shared components — TransactionReviewCard 67/67, ReviewStageMarkers 18/18, TransactionInlineDetail 29/29, EvidenceTile 16/16, SheetApprovalsSummary 5/5, DecisionConfirmation 13/13, QuickDecisionModalBidi 2/2, EvidencePreview 17/17; evidence regression — zero evidence, image evidence, PDF/type-art fallback, multiple evidence, `+N`, preview lifecycle, transaction binding, failed-thumbnail fallback (type-art tile), and no upload/replace/delete controls (EvidenceTile/EvidencePreview/EvidenceStatusPresentation 9/EvidenceListMobileLayout 6/AuditEvidence 17 + inline/card specs); accessibility/responsive — QuickReviewAccessibilityConformance 10/10, RtlAccessibility (T116) 9/9, fr071NarrowViewport 3/3, keyboard-accessibility floor 4/4, typography floor 5/5, l10nCatalog EN/FA parity 7/7, NextcloudVueContract 6/6. Grouped targeted totals 308/308 + 233/233 + 53/53. Statics: `npm run typecheck`, `npm run lint` (0 errors; 307 pre-existing warnings), `npm run stylelint` green. Aggregate `npm run check` components run individually because the `&&` chain aborts at the known pre-existing portability failure: `check:openapi` drift-green (types match the committed contract), `check:error-rate` self-test OK, `check:notices` OK, `npm run build` green — and the fresh build emits **no** `TransactionDetailDrawer-*` chunk (the card/inline surface now compiles into the `SheetDetailsModal-*` shared chunk; the stale chunk disappeared through the rebuild itself, not manual deletion — runtime-mount hash identity remains UXC-V2-15). Legacy sweep: zero active `TransactionDetailDrawer`/`drawer-*` selector/import references across `src/`, `tests/Frontend`, `tests/E2E`; only three clearly historical provenance comments remain. Full `npm test` 66 files 1248/1248. Known unrelated defects reported, not repaired: (1) `/sheets` volume-threshold pagination 500 (backend); (2) `check:portability` FAILED with exactly the two recorded Phase-7F DBAL violations (`tests/Integration/Persistence/roundtrip-runner.php:301` and `:306`, byte-identical to the recorded defect).

### UXC-V2-15: Build / runtime convergence
- **Target file(s)**: build output (`css/`, `js/`), runtime mount `/var/www/html/custom_apps/pettycash` (via `pettycash-lab-nextcloud-1`), `scripts/dev/opcache-settle`
- **Required behavior**: `npm run build` green; the build contains the v2 review-progress + inline-expansion assets and no orphaned drawer chunk; three-way hash identity (repo tree = runtime mount = HTTP-served) for the affected chunks; opcache settled before any live check; no `appinfo/` change expected (route scripts only if evidence demands, per AGENTS.md §6).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-14
- **Targeted tests**: `npm run build` green; `npm run typecheck` green; `npm run lint`/`npm run stylelint` green; hash comparison; runtime loads the v2 surfaces.
- **Exclusions**: no deployment outside the local lab; no direct host PHP/Composer/PHPUnit; no contract change (`scripts/validate-openapi.sh` expected drift-green with no edit).
- **Status**: `[x]` done (2026-11-22) — build/runtime identity proven. **Build**: `npm run build` exit 0 from the current working tree (21.8s); entries `js/pettycash-main.mjs` (254.13 kB) + `css/pettycash-main.css`; Management Review assets `js/SheetDetailsModal-BwLfVKeE.chunk.mjs` (carries the card, inline expansion and stage markers) + `css/SheetDetailsModal-B_YYzpKT.chunk.css` and the role-view chunks `CostCenterQueueView-BooQFCr1` / `SeniorQueueView-ebtSQyv1` / `FinanceQueueView-BDuS2Nnj` (+ per-view CSS); **no `TransactionDetailDrawer-*` chunk is emitted** — the only drawer string in the output graph is the historical provenance comment embedded verbatim in the inline detail's sourcemap `sourcesContent` (not a module reference). **Runtime layout (established, not guessed)**: `/home/ubuntu/projects/pettycash` is bind-mounted to `/var/www/html/custom_apps/pettycash` in `pettycash-lab-nextcloud-1` (verified read-only via docker inspect) — synchronization is inherent to the mount; no file copy needed or performed; assets serve at `http://localhost:8081/custom_apps/pettycash/<path>`. **Cache/opcode**: `scripts/dev/opcache-settle` → "settled; Apache is serving current bytecode for lib/ and appinfo/". **Three-way SHA-256 identity (host repo = in-container mount = HTTP-served) MATCH for 7 assets**: main JS `c4be6758…`, main CSS `f7a7aa6b…`, SheetDetailsModal JS `b4e79640…` / CSS `41d015ec…`, CC `ab680aa4…`, Senior `2439acac…`, Finance `02cd3a27…` (full hashes in the task report; hashed filenames themselves identical across surfaces). **Removed-drawer proof**: `js/TransactionDetailDrawer-8NmvVSk1.chunk.mjs` (and the older `-20VP3lgJ` hash) return HTTP 404 at the runtime; the stale chunk disappeared through the rebuild itself (vite output dir reset), not manual deletion; no active JS references any drawer chunk. **Smoke (headless official Chrome, read-only, session via the pre-existing Playwright storage state)**: `/apps/pettycash/#/` renders the app shell; `#/reviews/cost-center` / `#/reviews/senior` / `#/reviews/finance` each load with their queue marker testid present; **0 page errors, 0 console errors, 0 HTTP ≥400 responses**. **Active-graph completeness**: all 208 chunk references (JS + CSS) extracted from the built JS resolve HTTP 200 at the runtime. No stale drawer asset remains referenced; unrelated historical artifacts outside the active graph are left alone. No database/volume/credential/account/`.env.testing` mutation; runtime-affecting commands were opcache-settle plus read-only docker inspect/status, in-container sha256 probes, and curl GETs.

### UXC-V2-16: Real-Chrome cross-role verification
- **Target file(s)**: `tests/E2E/` (CC/Senior/Finance flow specs and visual-evidence scripts retargeted from drawer interactions to the inline expansion), official Chrome channel
- **Required behavior**: Cross-role canonical flows in real Chrome, desktop + 390px, EN/FA, RTL/LTR: queue → modal (stable geometry) → card (visible date, stage identity + state marker, permitted actions) → inline expansion (details → evidence → history → actions at 390px) → preview/decision overlays → decided state; Senior gate/filter/sort/export and completed read-only; Finance restrictions and settlement-adjacent assertions preserved; screenshots under `artifacts/visual-review/<task-id>/<iteration>/`.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-15
- **Targeted tests**: Playwright E2E (official Chrome `channel: 'chrome'`) green for the three roles.
- **Exclusions**: no owner approval claimed by automation; no backend change; no assertion weakening.

### UXC-V2-GATE: Product-owner visual acceptance — Management Review UX v2 (manual, unchecked)
- **Target file(s)**: none (artifact only — `artifacts/visual-review/` screenshots + this gate record)
- **Required behavior**: Product-owner manual review of the v2 surfaces across Cost Center, Senior, and Finance in English LTR and Persian RTL, desktop and 390px: stable modal geometry, card first-view content with visible transaction date, compact stage identity + state marker review progress, inline expanded transaction, evidence presentation, persistent actions, previous-decision history. The six owner refinements `UXC-V2-FU-01` through `UXC-V2-FU-06` MUST be complete and real-Chrome browser-verified before this gate can be considered; they remain unchecked implementation tasks and are not completed by this gate record. Remains unchecked by automation; requires explicit product-owner approval; does NOT silently complete `UXC-GATE` or the feature owner gates (`T022`, `T037`, `T009-GATE-01`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-16, then completion and real-Chrome browser verification of all eight owner refinements `UXC-V2-FU-01` through `UXC-V2-FU-08` (each unchecked until implemented and verified); the gate itself remains unchecked by automation.
- **Targeted tests**: Recorded owner approval (gate identifier, date, authorized scope). Automation MUST NOT check this task.
- **Exclusions**: not auto-completable; does not complete feature owner gates.

**Dependency order (recorded)**:

```text
Shared foundation → inline expansion → content migration → role integrations
→ accessibility/responsive → drawer cleanup → regression → runtime → Chrome → owner gate

UXC-V2-01
→ UXC-V2-02 → UXC-V2-03 → UXC-V2-04 → UXC-V2-05 → (UXC-V2-06 + UXC-V2-07 + UXC-V2-08)
  (UXC-V2-06 also depends on UXC-V2-03; UXC-V2-07 depends on UXC-V2-03; UXC-V2-08 depends on UXC-V2-05)
→ (UXC-V2-09 + UXC-V2-10 + UXC-V2-11 — role integrations may run as one bounded batch after 05–08)
→ (UXC-V2-12 + UXC-V2-13 — drawer cleanup only after the three role integrations are green and the dependency audit proves zero drawer references)
→ UXC-V2-14 → UXC-V2-15 → UXC-V2-16 → UXC-V2-GATE
```

## Phase UXC-V2-FU — Owner-requested visual refinements (post-UXC-V2-16, pre-owner-gate)

**Status**: Specification/ledger alignment only; no implementation task is executed by this document and no production code is changed. All eight tasks depend on `UXC-V2-16`; `UXC-V2-FU-05` also consumes the compact-ID contract from `UXC-V2-FU-03`; `UXC-V2-FU-06` is implemented together with or after `UXC-V2-FU-05` so queue density is evaluated against the final shared column model. `UXC-V2-GATE` remains unchecked and depends on completion and real-Chrome browser verification of all eight refinements.

### UXC-V2-FU-01: Stronger review-state marker contrast
- **Target file(s)**: `specs/006-management-review/spec.md` (`FR-006-039`), `specs/007-senior-manager-cost-sheet-review/spec.md` (`FR-007-019`), `specs/009-finance-manager-expense-review/spec.md` (`FR-009-011a`)
- **Required behavior**: Semantic state markers (Approved / Rejected / Returned / No decision yet) MUST have sufficient visual prominence for fast transaction scanning. For Approved in particular: the check glyph MUST be clearly visible; foreground/background/border contrast MUST be stronger than the current implementation while the marker remains compact — the stage row MUST NOT grow into a large panel; meaning MUST NOT depend on color alone; accessibility requirements (text labels, focus treatment, RTL, forced-colours) remain applicable. A generic traffic-light visual treatment MUST NOT be introduced. The existing compact `stage identity + state marker` model remains approved; the superseded v3 Review Ledger MUST NOT be reintroduced.
- **Affected features**: 006, 007, 009 (shared `ReviewStageMarkers` component)
- **Depends on**: UXC-V2-16
- **Targeted tests**: documentation consistency checks only (specs); no frontend implementation, tests, build, or E2E by this document.
- **Exclusions**: no v3 Review Ledger; no large stage panel; no traffic-light; no workflow/backend/API/authorization/financial change.

### UXC-V2-FU-02: Remove duplicated expanded-transaction information
- **Target file(s)**: `specs/006-management-review/spec.md` (`FR-006-041`), `specs/007-senior-manager-cost-sheet-review/spec.md` (`FR-007-020`), `specs/009-finance-manager-expense-review/spec.md` (`FR-009-011b`)
- **Required behavior**: Information already visible in the collapsed transaction card MUST NOT be repeated in the expanded transaction detail. The card owns, as first-view information: transaction title; amount and currency; expense date; lifecycle/stage presentation; transaction identifier; cost center; expense type; review stage markers; evidence summary; reviewer actions. The expanded detail MUST primarily contain complementary information not already visible above — where supplied by the server: purchaser; submitted timestamp; merchant; paid-on value; payment method; detailed evidence; previous decisions; additional contextual fields not already visible in the card. A second amount/status summary block restating the card MUST NOT be introduced. Transaction ID, expense type, date, and other first-view metadata MUST NOT be repeated unless a concrete accessibility or contextual requirement makes repetition necessary. The expanded surface becomes materially lighter and shorter while preserving the mobile semantic flow details → evidence → history → actions.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-16
- **Exclusions**: no inline expansion removal; no mobile flow change; no backend change; no stable modal geometry change.

### UXC-V2-FU-03: Compact sheet/transaction identifier presentation
- **Target file(s)**: `specs/006-management-review/spec.md` (`FR-006-053`), `specs/007-senior-manager-cost-sheet-review/spec.md` (`FR-007-059`), `specs/009-finance-manager-expense-review/spec.md` (`FR-009-030`)
- **Required behavior**: Long sheet and transaction identifiers use compact truncation (`25ccedd9…145b` or repository-consistent equivalent). The displayed value remains recognizably derived from the true identifier; the underlying identifier is unchanged. Full value available via an accessible disclosure mechanism (tooltip or equivalent); hover NOT the only access path; keyboard focus exposes the complete value; screen readers access the complete identifier. Identifiers direction-isolated in RTL; complete value available for operational/debugging use. Applied consistently to queue tables, transaction cards, inline details where required, modal headers/metadata. No new dependency solely for this behavior. Sheet title and sheet reference remain distinct.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-16
- **Exclusions**: no new dependency; no sheet-title/identifier concatenation; no backend change.

### UXC-V2-FU-04: Senior transaction-status presentation correction
- **Target file(s)**: `specs/007-senior-manager-cost-sheet-review/spec.md` (new `FR-007-060`)
- **Required behavior**: Per-stage Senior transaction decisions are communicated through the stage identity + state marker component (FR-007-019). Compound lifecycle + decision badges (`Completed approved`, `Completed rejected`) MUST NOT appear as Senior transaction-card status badges. Sheet-level completion MAY remain at sheet/workspace level. Read-only/completed Senior behavior unchanged; workflow semantics unchanged; no backend status value renamed or mutated — presentation only. Not silently extended to other roles unless their specifications already require it.
- **Affected features**: 007
- **Depends on**: UXC-V2-16
- **Exclusions**: no backend status rename/mutation; no Finance/Cost Center presentation change; no workflow change; no `ReviewStageMarkers` semantic change.

### UXC-V2-FU-05: Cross-role review-queue table convergence
- **Target file(s)**: `specs/006-management-review/spec.md` (`FR-006-054`), `specs/007-senior-manager-cost-sheet-review/spec.md` (`FR-007-061`), `specs/009-finance-manager-expense-review/spec.md` (`FR-009-031`)
- **Required behavior**: Senior and Finance queue tables converge with the Cost Center queue table on the shared base column model (Sheet, Purchaser, Transactions, Amount, Status, Submitted) and shared visual structure (FR-006-054). Equivalent terminology across roles; avoid `Pending`, `Latest activity`, `Review status`. Cost Center may retain `Your transactions` / `Your relevant amount` qualifiers; Senior and Finance use base vocabulary without `Your`. Same header typography, sortable treatment, cell spacing, sheet-title/reference hierarchy, purchaser avatar, amount alignment, status badge placement, submitted/date presentation, compact ID treatment (FR-006-053 / FR-007-059 / FR-009-030). No new backend fields; document unavailability in the implementation task if a field is genuinely unavailable.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-16, UXC-V2-FU-03
- **Exclusions**: no new backend fields; no backend scope change; no business-rule change.

### UXC-V2-FU-06: Table-first review-page density
- **Target file(s)**: `specs/006-management-review/spec.md` (`FR-006-055`), `specs/007-senior-manager-cost-sheet-review/spec.md` (`FR-007-062`), `specs/009-finance-manager-expense-review/spec.md` (`FR-009-032`)
- **Required behavior**: All three Management Review queue pages establish a table-first review workspace (FR-006-055): compact header region (breadcrumb, compact eyebrow/stage identity, page title, primary action such as Refresh, export where role-specific); explanatory paragraphs shortened to one concise line or removed; no large permanent instructional panels; compact statistics (inline counters, small chips, one low-height summary row, counts near filters) instead of large KPI cards; table begins materially higher so several rows are visible without initial page scrolling; search/filter/sort/role-state controls accessible and compact without reducing minimum control sizes. Comparable vertical rhythm, table start position, search/filter density, and shared vocabulary across roles; role-specific actions and status filters remain; controls not forced identical where workflows genuinely differ.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-16, UXC-V2-FU-05
- **Exclusions**: no business behavior change; no minimum control size reduction; no workflow/backend/API change.

### UXC-V2-FU-07: Canonical cross-role queue status + returned-transaction context
- **Target file(s)**: `specs/006-management-review/spec.md` (`FR-006-056`, `FR-006-057`), `specs/007-senior-manager-cost-sheet-review/spec.md` (`FR-007-063`, `FR-007-064`), `specs/009-finance-manager-expense-review/spec.md` (`FR-009-033`, `FR-009-034`)
- **Required behavior**: Record the canonical cross-role queue status contract: exactly two primary status values (`Awaiting your decision`, `Review completed`) for the current reviewer; returned-transaction context as secondary metadata only (`1 returned transaction` / `N returned transactions`, hidden at 0); no role-specific primary status synonyms (`Needs attention`, `Senior Review`, `Finance Review`, `In progress`, `N of N awaiting your decision`); EN and FA localization required. Workflow semantics unchanged — `Review completed` only when the stage is fully resolved under existing workflow rules; returned-for-correction remains unresolved.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-16
- **Exclusions**: no implementation; specification/ledger alignment only; no frontend or backend code change; no workflow, API, contract, or persistence change; owner gate not marked complete.

### UXC-V2-FU-08: Final queue visual-consistency corrections
- **Target file(s)**: `specs/006-management-review/spec.md` (`FR-006-058`), `specs/007-senior-manager-cost-sheet-review/spec.md` (`FR-007-065`), `specs/009-finance-manager-expense-review/spec.md` (`FR-009-035`, `FR-009-036`, `FR-009-037`)
- **Required behavior**: Record the final owner-approved visual-consistency corrections: (A) exact shared column order Sheet, Purchaser, Transactions, Amount, Status, Submitted across all three roles; (B) Finance `Purchaser unavailable` / `Date unavailable` is NOT accepted final UX — defect pending `BUG-FIN-QUEUE-METADATA`; (C) Finance Sheet cell uses human-readable title as primary text, compact reference as secondary metadata — no full UUID, no UUID duplication, no UUID-as-title; (D) compact summary/KPI numbers must never wrap digit-by-digit; (E) Cost Center table-first density with comparable vertical rhythm — no redundant explanatory text merely because it historically existed.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-16, UXC-V2-FU-05
- **Exclusions**: no implementation; specification/ledger alignment only; no frontend or backend code change; no workflow, API, contract, or persistence change; owner gate not marked complete.

### UXC-V2-FU-09: Final cross-role visual convergence
- **Target file(s)**: `src/views/reviews/CostCenterQueueView.vue`, `src/views/reviews/SeniorQueueView.vue`, `src/views/reviews/FinanceQueueView.vue`, `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js`
- **Required behavior**: Final visual refinement converging all three management queue tables onto the Cost Center queue as the canonical V2 visual reference. (A) Remove the obsolete Cost Center explanatory messages (`reviews.sheetQueue.scopeNote`) — "Only sheets relevant to your cost centers are shown" and "Sheet title, purchaser and totals are not yet provided by the server." — and their localization entries. (B) Establish a common typography standard: sheet title, compact sheet identifier, purchaser name, transaction count, amount and currency, status, submission date, and column headings all use the Cost Center conventions (font family, size, weight, line height, color, alignment). Amount presentation uses `font-family: 'Noto Sans Mono', monospace` + `font-variant-numeric: tabular-nums` consistently across all three roles. (C) Unify colors: primary/secondary text, table header, row separators/borders, backgrounds, icons, status badges, amount, secondary returned-transaction indicators all match the Cost Center palette. Awaiting = warning/amber; completed = success/green. (D) Unify status presentation: all three tables render status using the shared `StatusBadge` component with identical typography, badge dimensions, padding, border treatment, semantic colors — no role-specific variants, no truncated labels. (E) Preserve table density: column order, column semantics, row density, compact identifiers, search/filter/sort/pagination, queue headers, compact KPI layout unchanged. No increased vertical spacing. (F) Responsive and RTL behavior preserved: English LTR, Persian RTL, 390px mobile, no horizontal overflow, no clipped labels.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-16, UXC-V2-FU-05, UXC-V2-FU-07, UXC-V2-FU-08
- **Exclusions**: frontend presentation only; no backend/API/schema/persistence/workflow/authorization change; no workflow/status-logic change; no Finance metadata mapping change; owner gate not marked complete.

**Task status**: `UXC-V2-16 [x]`; `UXC-V2-FU-01 [ ]`, `UXC-V2-FU-02 [ ]`, `UXC-V2-FU-03 [ ]`, `UXC-V2-FU-04 [ ]`, `UXC-V2-FU-05 [x]`, `UXC-V2-FU-06 [x]`, `UXC-V2-FU-07 [x]`, `UXC-V2-FU-08 [x]`, `UXC-V2-FU-09 [x]`, `UXC-V2-FU-10 [x]`, `UXC-V2-FU-11 [x]`, `UXC-V2-FU-15 [x]`.

**Dependency order (recorded)**:
```text
UXC-V2-16
→ (UXC-V2-FU-01 + UXC-V2-FU-02 + UXC-V2-FU-03 — all depend only on UXC-V2-16)
→ UXC-V2-FU-05 (depends on UXC-V2-FU-03)
→ UXC-V2-FU-06 (depends on UXC-V2-FU-05)
→ UXC-V2-FU-07 (depends on UXC-V2-16)
→ UXC-V2-FU-08 (depends on UXC-V2-16, UXC-V2-FU-05)
→ UXC-V2-FU-09 (depends on UXC-V2-16, UXC-V2-FU-05, UXC-V2-FU-07, UXC-V2-FU-08)
→ UXC-V2-FU-10 (depends on UXC-V2-16, UXC-V2-FU-05, UXC-V2-FU-07, UXC-V2-FU-08, UXC-V2-FU-09)
→ UXC-V2-FU-11 (depends on UXC-V2-FU-10)
→ UXC-V2-FU-15 (depends on UXC-V2-16, UXC-V2-FU-09, UXC-V2-FU-10)
→ UXC-V2-GATE (depends on completion + real-Chrome browser verification of all twelve refinements)
```

### UXC-V2-FU-10: Cross-Role Queue Table Controls Convergence
- **Target file(s)**: `src/views/reviews/CostCenterQueueView.vue`, `src/views/reviews/SeniorQueueView.vue`, `src/views/reviews/FinanceQueueView.vue`, `tests/Frontend/Component/ManagementReview.spec.ts`, `tests/Frontend/Component/QuickReviewWorkflow.spec.ts`, `tests/Frontend/Component/QuickReviewAccessibilityConformance.spec.ts`
- **Required behavior**: Standardize search, filtering, sorting, and table-header presentation across all three management queue pages, using the Senior Manager queue as the canonical visual and behavioral reference. (FU-10-A) Unified search and filter toolbar: search at beginning, exactly three shared filter buttons (`All sheets`, `Awaiting your decision`, `Review completed`) — Cost Center removes `In progress` filter and sort dropdown; Finance adds all three filters; all filter buttons share dimensions, typography, spacing, alignment, selected/unselected appearance, borders, backgrounds, and interaction states. (FU-10-B) Unified table header sorting: sorting exclusively through column headers — Cost Center removes the independent Sort dropdown; Finance implements header-based sorting; all headers display sorting indicators and direction reverses on repeat click. (FU-10-C) Unified table header typography: bold column labels, identical font size/weight, consistent text color, vertical alignment, sorting icon size/spacing, header padding — all matching Senior's ResponsiveTable header appearance. (FU-10-D) Unified toolbar layout: search input width/height, filter button sizing, horizontal spacing, vertical alignment, borders/backgrounds, selected filter appearance, responsive wrapping — using logical CSS properties for LTR/RTL; 390px viewport wraps naturally without horizontal overflow.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-16, UXC-V2-FU-05, UXC-V2-FU-07, UXC-V2-FU-08, UXC-V2-FU-09
- **Exclusions**: frontend presentation only; no backend/API/schema/persistence/workflow/authorization/financial calculation change; no workflow/status-logic change; no Finance metadata mapping change; column order from FU-08 preserved; typography/amount formatting/status colors from FU-09 preserved; owner gate not marked complete.

### UXC-V2-FU-11: Multi-Cost-Center Visibility and Filtering
- **Target file(s)**: `src/views/reviews/CostCenterQueueView.vue`, `src/views/reviews/SeniorQueueView.vue`, `src/composables/useSheetQueue.ts`, `src/composables/useSeniorQueue.ts`, `src/stores/auth.ts`, `src/stores/configuration.ts`, `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js`
- **Required behavior**: (FU-11-A) Both Cost Center and Senior Manager queues display a selectable cost-center filter above the table showing only cost centers within the current user's authorized management scope, with an "All cost centers" option and readable cost-center names. (FU-11-B) Cost Center Manager: assigned cost centers are displayed as filter chips; selecting a center filters sheets to those containing at least one transaction from that center; each sheet appears once; transaction counts and amount totals are scoped to the selected center. (FU-11-C) Senior Manager: same filter behavior; when a center is selected, the Transactions column counts only that center's transactions and the Amount column sums only that center's amounts (not sheet-wide totals). Filter works with search, status tabs, and column sorting. "All cost centers" restores unrestricted management-scope results.
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-V2-FU-10
- **Exclusions**: no backend/API/schema/persistence/workflow/authorization change; no workflow/status-logic change; no financial calculation change outside center-scoped aggregation; table column structure unchanged (six columns preserved).

### UXC-V2-FU-15: Final Cost Center / Senior Queue Convergence
- **Target file(s)**: `src/views/reviews/CostCenterQueueView.vue`, `src/views/reviews/SeniorQueueView.vue`, `src/composables/useSeniorQueue.ts`, `src/types/api.ts`, `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js`, `tests/Frontend/Composables/SeniorSheetQueue.spec.ts`
- **Required behavior**: Bounded frontend UX convergence between Cost Center Manager Queue and Senior Manager Queue (Senior is canonical). (A) Cost Center toolbar/layout matches Senior: search control placement, status-filter placement, cost-center filter placement, vertical/horizontal spacing, control grouping, responsive wrapping, RTL. (B) Remove redundant `Your sheets` heading from Cost Center; controls sit naturally below the KPI area; no excessive blank space. (C) Eliminate visible table-column layout shift during Cost Center page load — root cause proven: async manager sheet reads (titles, purchaser names, submitted dates via `prefetchManagerDetails`) change cell content after the first table render; `table-layout: auto` recalculates column widths on content change, producing visible column movement. Fix: `table-layout: fixed` on the Cost Center desktop table (freezes column geometry from first paint), `width: 100%`, min-widths on all six columns (Sheet 180px, Purchaser 140px, Transactions 100px, Amount 130px, Status 100px, Submitted 110px), `white-space: nowrap` on monetary/submitted cells. Content wraps/clips inside fixed columns instead of resizing them. (D) Cost Center Purchaser presentation matches Senior exactly: `sheet-row__owner` container (flex, gap 8px, min-width 0, overflow hidden), `sheet-row__owner-avatar` (26px, no border, primary-tinted bg, font-weight 700), `sheet-row__owner-name` (overflow hidden, text-overflow ellipsis, white-space nowrap). (E) Unified search placeholder on both Cost Center and Senior: EN "Search by sheet, purchaser, or cost center"; FA "جستجو بر اساس شیت، خریدار یا مرکز هزینه". (F) Verified search semantics: both Cost Center `searchSheetQueue` and Senior `searchSeniorSheets` support case-insensitive partial matching against sheet identifier/reference, sheet title, purchaser display name, cost center name, and cost center code. Senior `searchSeniorSheets` previously matched `center.name` and `center.id` but not `center.code`; added `center.code` matching. `SeniorCostCenter` interface gained `code: string`; `collectSeniorCostCenters` populates it from `costCenter.code ?? ''`; `SeniorSheetQueueItem.costCenters` type updated; `sheetCostCentersById` and `sheetItems` in SeniorQueueView merge per-sheet-read cost centers (with `code`) into queue rows for search. Six-column table structure unchanged (Sheet, Purchaser, Transactions, Amount, Status, Submitted). No backend/API/schema/authorization changes.
- **Affected features**: 006, 007
- **Depends on**: UXC-V2-16, UXC-V2-FU-09, UXC-V2-FU-10
- **Targeted tests**: `npm test -- --run tests/Frontend/Component/ManagementReview.spec.ts tests/Frontend/Component/SeniorReview.spec.ts tests/Frontend/l10nCatalog.spec.ts tests/Frontend/Composables/SeniorSheetQueue.spec.ts` green (1297 tests, 0 failures); `npm run typecheck` green; `npm run lint` green (0 errors); `npm run stylelint` green; `npm run build` green. Real-Chrome layout-shift verification (header geometry timeline from first visible table through settled state, desktop EN/FA RTL and 390px) required for owner acceptance; lab runtime not started in this session — screenshots under `artifacts/visual-review/UXC-V2-FU-15-VERIFY/001/`.
- **Exclusions**: no redesign of the queue; no column structure change; no workflow/authorization/financial calculation/status semantics change; no Finance modification; owner gate not marked complete.

--- 

## Owner Gate

### UXC-GATE: Owner visual acceptance — shared management-review UX convergence (manual, unchecked)
- **Target file(s)**: none (artifact only — `artifacts/visual-review/` screenshots + this gate record)
- **Required behavior**: Product-owner manual review of the converged shared management-review UX across Cost Center, Senior, and Finance in English LTR and Persian/RTL. Covers shared Sheet Details Modal, Transaction Review Card, Transaction Detail Drawer, evidence, prior decisions, stage-specific actions, focus lifecycle, 390px no-scroll, l10n parity. Remains unchecked by automation; requires explicit product-owner approval; does not silently complete the 006/007/009 owner gates (`T022`, `T037`, `T009-GATE-01`).
- **Affected features**: 006, 007, 009
- **Depends on**: UXC-12-04
- **Targeted tests**: Recorded owner approval (gate identifier, date, authorized scope). Automation MUST NOT check this task.
- **Exclusions**: not auto-completable; does not complete feature owner gates.

---

## Dependency Graph

```
Phase 1 (Shared Sheet Details Modal)
  UXC-1-01 → UXC-1-02 → UXC-1-03 → UXC-1-04 → UXC-1-05 → UXC-1-06 → UXC-1-07 → UXC-1-08

Phase 2 (Senior Migration)
  UXC-1-08 → UXC-2-01 → UXC-2-02 → UXC-2-03 → UXC-2-04 → UXC-2-05 → UXC-2-06
  (UXC-2-05 also depends on UXC-3-01, UXC-4-01)

Phase 3 (Shared Transaction Review Card)
  UXC-1-08 → UXC-3-01 → UXC-3-02 → UXC-3-03 → UXC-3-04

Phase 4 (Shared Transaction Detail Drawer)
  UXC-1-08 → UXC-4-01 → UXC-4-02 → UXC-4-03 → UXC-4-04 → UXC-4-05 → UXC-4-06

Phase 5 (Finance Migration)
  UXC-1-08, UXC-3-01, UXC-4-01 → UXC-5-01 → UXC-5-02 → UXC-5-03 → UXC-5-04 → UXC-5-05 → UXC-5-06

Phase 6 (Cost Center Migration)
  UXC-1-08, UXC-3-01, UXC-4-01 → UXC-6-01 → UXC-6-02 → UXC-6-03 → UXC-6-04

Phase 7 (Shared Approvals / Tabs Convergence)
  UXC-1-04, UXC-5-01, UXC-6-01 → UXC-7-01 → UXC-7-02 → UXC-7-03

Phase 7F (Whole-Sheet Stage-Approved Totals — owner-authorized follow-up)
  UXC-7-01 → UXC-7F-01 → (UXC-7F-02 + UXC-7F-03 + UXC-7F-04) → UXC-7F-05 → UXC-7F-06 → UXC-7F-07
  (UXC-7F-02/03/04 may run as one bounded backend batch after UXC-7F-01)

Phase 8 (Duplicate Removal)
  UXC-7F-07, UXC-2-06, UXC-5-06 → UXC-8-01 → UXC-8-02 → UXC-8-03 → UXC-8-04 → UXC-8-05
  (UXC-8-05 also depends on UXC-9-01)

Phase 9 (Stable Shared Selectors)
  UXC-1-01, UXC-3-01, UXC-4-01 → UXC-9-01 → UXC-9-02

Phase 10 (Cross-Role Frontend Regression)
  UXC-6-04 → UXC-10-01
  UXC-2-06 → UXC-10-02
  UXC-5-06 → UXC-10-03
  UXC-3-04 → UXC-10-04
  UXC-4-06 → UXC-10-05
  UXC-4-03 → UXC-10-06
  UXC-4-04 → UXC-10-07
  UXC-4-05, UXC-5-05 → UXC-10-08
  UXC-1-05 → UXC-10-09
  UXC-1-06, UXC-9-01 → UXC-10-10

Phase 11 (Runtime / Build Convergence)
  UXC-10-10 → UXC-11-01 → UXC-11-02 → UXC-11-03 → UXC-11-04
  (UXC-11-04 also depends on UXC-6-04, UXC-5-06, UXC-8-05)

Phase 12 (Real Chrome / E2E Convergence)
  UXC-10-01, UXC-11-04 → UXC-12-01
  UXC-10-02, UXC-11-04 → UXC-12-02
  UXC-10-03, UXC-11-04 → UXC-12-03
  UXC-12-01, UXC-12-02, UXC-12-03 → UXC-12-04 → UXC-GATE

Cross-phase prerequisites:
  Shared modal (Phase 1) before Senior migration (Phase 2)
  Senior migration (Phase 2) before Shared card (Phase 3)
  Shared card (Phase 3) before Shared drawer (Phase 4)
  Shared drawer (Phase 4) before Finance migration (Phase 5)
  Finance migration (Phase 5) before Cost Center migration (Phase 6)
  Cost Center migration (Phase 6) before Tabs/approvals convergence (Phase 7)
  Tabs/approvals convergence (Phase 7) before the whole-sheet totals follow-up (Phase 7F)
  Whole-sheet totals follow-up (Phase 7F) before Duplicate removal (Phase 8)
  Duplicate removal (Phase 8) before Selector convergence (Phase 9)
  Selector convergence (Phase 9) before Frontend regression (Phase 10)
  Frontend regression (Phase 10) before Runtime convergence (Phase 11)
  Runtime convergence (Phase 11) before Real Chrome E2E (Phase 12)
  Real Chrome E2E (Phase 12) before Owner review (UXC-GATE)

Phase UXC-V3 (Management Review UX v3 — SUPERSEDED before implementation by Manus v2; NOT executed; retained for audit)
  UXC-V3-01 → UXC-V3-02 → UXC-V3-03 → UXC-V3-04 → UXC-V3-05 → (UXC-V3-06 + UXC-V3-07 + UXC-V3-08)
  (UXC-V3-06 also depends on UXC-V3-03; UXC-V3-07 on UXC-V3-03; UXC-V3-08 on UXC-V3-05)
  → (UXC-V3-09 + UXC-V3-10 + UXC-V3-11) → (UXC-V3-12 + UXC-V3-13) → UXC-V3-14 → UXC-V3-15 → UXC-V3-16 → UXC-V3-GATE

Phase UXC-V2 (Management Review UX v2 — owner-approved Manus v2 baseline, 2026-11-21; ACTIVE)
  UXC-V2-01 → UXC-V2-02 → UXC-V2-03 → UXC-V2-04 → UXC-V2-05 → (UXC-V2-06 + UXC-V2-07 + UXC-V2-08)
  (UXC-V2-06 also depends on UXC-V2-03; UXC-V2-07 on UXC-V2-03; UXC-V2-08 on UXC-V2-05)
  → (UXC-V2-09 + UXC-V2-10 + UXC-V2-11) → (UXC-V2-12 + UXC-V2-13) → UXC-V2-14 → UXC-V2-15 → UXC-V2-16 → UXC-V2-GATE
  Prerequisite: Phase 12 convergence complete — all UXC-V2 work builds on the shared primitives
  (shared foundation → inline expansion → content migration → role integrations → accessibility/responsive → drawer cleanup → regression → runtime → Chrome → owner gate)
```

## Stage-Adapter Coverage

| Role | Stage adapter configures | Shared primitive used |
|------|--------------------------|-----------------------|
| Cost Center (006) | identity fields, summary metrics, tab content, Current Stage Actions (CC capability set) | `SheetDetailsModal`, `TransactionReviewCard`, `TransactionDetailDrawer`, `DecisionConfirmation` |
| Senior (007) | sheet-wide gate, filter/sort/export, sheet-specific identity, Senior decision actions | `SheetDetailsModal`, `TransactionReviewCard`, `TransactionDetailDrawer`, `DecisionConfirmation` |
| Finance (009) | `allowedActions` gating, Senior-rejected Approve restriction, independent per-transaction decisions, honest unavailable identity | `SheetDetailsModal`, `TransactionReviewCard`, `TransactionDetailDrawer`, `DecisionConfirmation` |

No business rules inside the shared shell; all role-specific behavior expressed through the stage adapter/capability configuration.

## Existing-Ledger Cross References

- `specs/006-management-review/tasks.md`: Phase 2 Sheet Review Workspace (Batch 3–5) is architecturally stale relative to this convergence; leave historical completion state intact (`T024`–`T034` `[x]`, `T035`–`T043` pending). Cross-reference: this ledger's Phase 6 (Cost Center migration) and Phase 1/3/4 supersede the planned inline quick-review panel/drawer work; do not rewrite `T022` gate state (`T022` remains `[ ]`, awaiting owner decision).
- `specs/007-senior-manager-cost-sheet-review/tasks.md`: Backend/workflow work (T024–T029) verified and valid; UI migration (T006–T036) superseded by this ledger's Phase 2; leave historical completion state intact; `T037` owner gate remains `[ ]` and is not completed by `UXC-GATE`.
- `specs/009-finance-manager-expense-review/tasks.md`: A–F complete, G complete, H-01–H-06 complete (`H-03`/`H-04`/`H-05` completed by `UXC-12-04` on 2026-11-21 — real-Chrome Persian-RTL-390px decision flow, frontend gates green, evidence walk + full `npm run test:e2e` gate green; see the Phase 12 status bullet for the two reported non-frontend defects); owner gate pending. Do not regenerate or re-execute completed A–G/H tasks. This ledger's Phase 5 (Finance migration) and Phase 12 converge the pending H work; `T009-GATE-01` remains `[ ]` and is not completed by `UXC-GATE`.

No existing completed task history is reset or invalidated.

## E2E Convergence Mapping

| Role | E2E flow | Entry → Modal → Card → Drawer → Evidence → Decision | Preserved assertions |
|------|----------|-----------------------------------------------------|----------------------|
| Cost Center | UXC-12-01 | Sheet row → shared Sheet Details Modal → TransactionReviewCard → TransactionDetailDrawer → Evidence → permitted CC decision | All stage-specific CC workflow assertions |
| Senior | UXC-12-02 | Sheet row → shared Sheet Details Modal → TransactionReviewCard → TransactionDetailDrawer → Evidence → Senior decision | Sheet-wide gate, filter/sort/export, completed read-only |
| Finance | UXC-12-03 | Sheet row → shared Sheet Details Modal → TransactionReviewCard → TransactionDetailDrawer → Evidence → Finance decision | Settlement/idempotency/balance (FR-040/FR-084/FR-085), Senior-rejected Approve restriction |

All Phase 12 E2E runs on official Chrome channel, desktop + 390px, EN/FA, RTL/LTR; E2E is last (after Phase 11).

## Owner Gate

`UXC-GATE` is the final explicit gate: manual, unchecked by automation, depends on all shared migrations and Phase 12 E2E. It does not silently complete Feature 006 (`T022`), Feature 007 (`T037`), or Feature 009 (`T009-GATE-01`) owner gates; those remain owner-controlled.

## Files Expected to Change

Created:
- `src/components/review/SheetDetailsModal.vue`
- `src/components/review/TransactionReviewCard.vue`
- `src/components/review/TransactionDetailDrawer.vue`
- `src/components/review/DecisionConfirmation.vue`
- `tests/Frontend/Component/SheetDetailsModal.spec.ts`
- `tests/Frontend/Component/TransactionReviewCard.spec.ts`
- `tests/Frontend/Component/TransactionDetailDrawer.spec.ts`
- `tests/Frontend/Component/DecisionConfirmation.spec.ts`
- `specs/009-finance-manager-expense-review/ux-convergence-tasks.md` (this ledger)

Modified:
- `src/views/reviews/SeniorQueueView.vue` (shared modal)
- `src/views/reviews/FinanceQueueView.vue` (shared modal)
- `src/views/reviews/CostCenterQueueView.vue` (shared modal, replace inline expansion)
- `src/stores/reviews.ts` (extend)
- `src/services/reviewsApi.ts` (extend)
- `src/types/api.ts` (additive types)
- `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js` (additive keys)
- `tests/Frontend/Component/SeniorReview.spec.ts` (extend)
- `tests/Frontend/Component/SeniorSheetModal.spec.ts` (extend)
- `tests/Frontend/Composables/SeniorSheetQueue.spec.ts` (extend)
- `tests/Frontend/Component/FinanceQueue.spec.ts` (extend)
- `tests/Frontend/Component/FinanceSheetModal.spec.ts` (extend)
- `tests/Frontend/Component/ManagementReview.spec.ts` (update Cost Center assertions in place)
- `tests/E2E/finance-expense-review.spec.ts` (extend/update)
- `tests/E2E/` Cost Center + Senior flow specs (new/extend)

Deleted (Phase 8 only, after migration verified):
- `src/components/review/SeniorSheetModal.vue`
- `src/components/review/FinanceSheetModal.vue`
- `src/components/review/QuickReviewCard.vue` (only if no surface still references it)
- Duplicate modal CSS and duplicate transaction-card markup

Phase 7F (whole-sheet stage-approved totals, owner-authorized 2026-11-20 — the exception to the boundary below):
- `specs/001-pettycash-v1-core/contracts/ocs-api.yaml` (additive `StageApprovedTotals` schema; applied to `ManagerSheetSummary`, `SeniorManagerSheetReview.sheet`, `TransactionSummary`) — already aligned
- `src/generated/api.ts` (regenerated by `scripts/validate-openapi.sh --write` only) — already aligned
- `lib/Infrastructure/Http/SheetPayloadFormatter.php`, `lib/Controller/ReviewController.php` (UXC-7F-02/03/04)
- `src/types/api.ts`, the three role views (UXC-7F-05)

Phase UXC-V3 (Management Review UX v3, 2026-11-21 — **SUPERSEDED before implementation; retained for audit only**; the shared-decision portions below are carried into Phase UXC-V2):
- `src/components/review/SheetDetailsModal.vue`, `src/components/review/TransactionReviewCard.vue` (stable geometry, card hierarchy, inline expansion)
- a new shared Review Ledger component in `src/components/review/` (exact name fixed at implementation)
- the inline-expansion content region (new shared markup in the modal/card family; `TransactionDetailDrawer.vue` remains the read-only source of record until `UXC-V3-13`)
- `src/components/review/PriorDecisionHistory.vue` (reuse; additive props only if needed)
- `src/components/evidence/EvidencePreview.vue` and the current card/expansion evidence rendering (reuse unchanged; no viewer redesign)
- `src/views/reviews/CostCenterQueueView.vue`, `src/views/reviews/SeniorQueueView.vue`, `src/views/reviews/FinanceQueueView.vue` (role integrations)
- `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js` (additive keys only)
- affected component specs, role suites, and E2E specs/visual-evidence scripts (retargeted from drawer interactions to the inline expansion)
- deleted in `UXC-V3-13` only: `src/components/review/TransactionDetailDrawer.vue` + its dedicated spec (after the zero-dependency proof)

Phase UXC-V2 (Management Review UX v2, owner-approved Manus v2 baseline 2026-11-21 — ACTIVE; frontend presentation only, boundary respected):
- `src/components/review/SheetDetailsModal.vue`, `src/components/review/TransactionReviewCard.vue` (stable geometry, cleaner/less-dense card hierarchy, inline expansion)
- a new shared compact **stage identity + state marker** review-progress component in `src/components/review/` (exact name fixed at implementation; replaces the current card indicators; NOT the superseded v3 Review Ledger)
- the inline-expansion content region (new shared markup in the modal/card family; `TransactionDetailDrawer.vue` remains the read-only source of record until `UXC-V2-13`)
- `src/components/review/PriorDecisionHistory.vue` (reuse; concise stage rows with restrained actor/time hierarchy; additive props only if needed)
- `src/components/evidence/EvidencePreview.vue` and the current card/expansion evidence rendering (reuse unchanged; no viewer redesign)
- `src/views/reviews/CostCenterQueueView.vue`, `src/views/reviews/SeniorQueueView.vue`, `src/views/reviews/FinanceQueueView.vue` (role integrations)
- `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js` (additive keys only)
- affected component specs, role suites, and E2E specs/visual-evidence scripts (retargeted from drawer interactions to the inline expansion)
- deleted in `UXC-V2-13` only: `src/components/review/TransactionDetailDrawer.vue` + its dedicated spec (after the zero-dependency proof)

Not touched (boundary — outside Phase 7F and its recorded contract exception):
- `appinfo/`, database schema/migrations, persistence, authorization, financial/settlement rules, evidence permissions; for Phases 1–7 and 8–12, `lib/` and the OpenAPI contract also remain untouched.

## Git State (confirmation at task generation)

- No production code changed (`src/`, `lib/`, `appinfo/` untouched by this document).
- No implementation started.
- No completed feature tasks reset (006 `T001`–`T022`, 007 `T001`–`T037`, 009 `T009-A`–`T009-H`/`GATE` completion states intact).
- No stage/commit/push/reset/clean/stash/deploy.
- Pre-existing dirty/untracked working-tree state preserved.

## Validation

1. Each `UXC-*` task ID unique — `UXC-1-01` … `UXC-12-04`, `UXC-GATE`, `UXC-FU-01`–`UXC-FU-02` (historical, done), `UXC-V3-01`–`UXC-V3-16` + `UXC-V3-GATE` (superseded before implementation, retained for audit, all unchecked), `UXC-V2-01`–`UXC-V2-15` `[x]` (done 2026-11-21/22 with acceptance evidence), `UXC-V2-16 [x]` (complete on real-Chrome evidence in `artifacts/visual-review/UXC-V2-16/002/`), `UXC-V2-FU-01`–`UXC-V2-FU-06` `[x]` (done 2026-11-22), `UXC-V2-FU-07 [x]` (done), `UXC-V2-FU-08 [x]` (done), `UXC-V2-FU-09 [x]` (done), `UXC-V2-FU-10 [x]` (done), `UXC-V2-FU-11 [x]` (done), `UXC-V2-FU-12 [x]` (done, real-Chrome evidence in `artifacts/visual-review/UXC-V2-FU-12/001/`), `UXC-V2-FU-13 [x]` (done, real-Chrome evidence in `artifacts/visual-review/UXC-V2-FU-13/001/`), `UXC-V2-FU-14 [x]` (done, real-Chrome evidence in `artifacts/visual-review/UXC-V2-FU-14/001/`), `UXC-V2-GATE [ ]` (depends on completion + real-Chrome browser verification of all ten refinements); no duplicates.
2. No business-rule changes introduced — shared shell contains no business rules; role behavior via stage adapter only.
3. All three management roles migrated — Cost Center (Phase 6), Senior (Phase 2), Finance (Phase 5).
4. Shared components extracted only once — `SheetDetailsModal`, `TransactionReviewCard`, `TransactionDetailDrawer`, `DecisionConfirmation` each created once.
5. Duplicate removal happens after migration verification — Phase 8 gated on Phase 2/5/6 regression tests.
6. E2E is last — Phase 12 after Phase 11; `UXC-GATE` after Phase 12.
7. Owner gate is manual — `UXC-GATE` unchecked by automation.
8. Completed historical tasks not reset — existing ledger completion states preserved; cross-references added only.

---
*Task generation only — no code, no implementation, no commits. All backend/schema/contract boundaries respected per AGENTS.md §1, §5, §8 and the approved specs/plan.*
