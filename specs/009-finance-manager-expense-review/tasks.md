# Implementation Task Ledger: Finance Manager Expense Review (Feature 009)

**Feature**: `009-finance-manager-expense-review` | **Date**: 2026-11-19 | **Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

**Purpose**: Implementation-ready, dependency-ordered task ledger generated from the approved Feature 009 specification and plan. This document plans work only — no task is implemented here, no code is changed, and no `tasks.md` for 006/007/008 is touched.

**Architecture decision (settled in plan)**: Option A — frontend grouping over the existing flat `GET /api/v1/queues/finance` API. No new backend endpoint, no OpenAPI change, no schema change, no generated API regeneration. Sheet identity fields (title/purchaser/submitted date) render as honest unavailable placeholders until a separately-authorized backend capability exists.

---

## How to read this ledger

- Each task has a unique ID (`T009-<phase>-<nn>`), exact target file(s), required behavior, FR/AC/SC mappings, dependencies, and targeted verification.
- Tasks are ordered so dependencies precede dependents. Complete-set fetch precedes grouping; grouping precedes queue UI; queue/modal foundation precedes decision integration; implementation precedes broad E2E/convergence; the manual owner gate is last.
- Parallelize only tasks with no dependency relationship.
- Boundary: no task touches `lib/`, `appinfo/`, database schema/migrations, canonical OpenAPI contract, or generated API. No Finance-specific backend sheet endpoint is added. Feature 006/007/008 task ledgers are not modified.

## Task Status

T009-A-01 → T009-A-05 [x]
T009-B-01 → T009-B-04 [x]
T009-C-01 → T009-C-08 [x]
T009-D-01 → T009-D-08 [x]
T009-E-01 → T009-E-06 [x]
T009-F-01 → T009-F-05 [x]

T009-G-01 onward       [ ]
T009-GATE-01           [ ]

---

## Phase A — Finance Complete-Set Read

**Goal**: Prove the complete finance-scoped pending set before any UI grouping. No UI grouping before complete-set behavior is proven.

### T009-A-01: Add bounded all-pages Finance queue fetch helper
- **Target file(s)**: `src/services/reviewsApi.ts`
- **Required behavior**: Add `fetchAllFinanceQueues(pageSize = 100)` that loops `GET /queues/finance?page=N&pageSize=P` following the server's `total`, accumulating `TransactionSummary[]` until `items.length >= total`, a short page arrives, or a page cap (100 pages) is hit. Bounded page size (contract max 100). Returns `{ items, page, pageSize, total }` carrying the server's own `total` so the completeness guard can distinguish "got everything" from truncated.
- **FR/AC/SC**: FR-009-007, FR-009-008; SC-009-003.
- **Depends on**: none.
- **Verification**: Unit test — pagination traversal follows `total`; page-size never exceeds contract max; short page stops loop; page cap stops loop; returns server `total`.

### T009-A-02: Dedupe finance transactions by transaction id
- **Target file(s)**: `src/composables/useFinanceQueue.ts` (new pure helper, modeled on `useSheetQueue.ts` `dedupeTransactions`)
- **Required behavior**: De-duplicate accumulated `TransactionSummary[]` by `id`; first copy wins; order preserved. Safety net for overlapping finance assignments.
- **FR/AC/SC**: FR-009-007, FR-009-009; SC-009-002.
- **Depends on**: T009-A-01.
- **Verification**: Unit test — duplicate ids counted once; first copy wins; relative order preserved; blank/empty input handled.

### T009-A-03: Finance completeness guard
- **Target file(s)**: `src/composables/useFinanceQueue.ts`
- **Required behavior**: `checkFinanceQueueCompleteness(page)` returns `{ complete: boolean, reason: 'complete' | 'empty' | 'at-threshold' | 'truncated' | 'no-total' }`. Complete only when `total` is finite, `returned === 0 && total === 0` (empty), or `total <= returned && returned < pageSize`. Otherwise `complete: false` with reason.
- **FR/AC/SC**: FR-009-007, FR-009-008; SC-009-003.
- **Depends on**: T009-A-02.
- **Verification**: Unit tests — complete set returns `complete: true`; truncation returns `complete: false`/`truncated`; missing total returns `no-total`; empty-with-zero-total returns `empty`; full page at threshold returns `at-threshold`.

### T009-A-04: Wire store fetchFinanceQueue to all-pages + incomplete state
- **Target file(s)**: `src/stores/reviews.ts`
- **Required behavior**: `fetchFinanceQueue()` uses `fetchAllFinanceQueues`; sets `financeQueue` to the accumulated page; exposes a finance completeness/incomplete state (loading → loaded | error | incomplete-unavailable) without blanking an already-rendered queue on partial failure. A failed page fetch mid-loop renders error + retry, never partial grouping as complete.
- **FR/AC/SC**: FR-009-007, FR-009-008; AC-4, AC-10; SC-009-003.
- **Depends on**: T009-A-01, T009-A-03.
- **Verification**: Unit test — successful all-pages load sets financeQueue; truncated set marks incomplete; fetch failure renders error + retry state; no partial flat table rendered.

### T009-A-05: Phase A unit test suite
- **Target file(s)**: `tests/Frontend/Composable/FinanceSheetQueue.spec.ts` (new)
- **Required behavior**: Unit tests covering T009-A-01..T009-A-04: all-pages fetch, dedupe, completeness guard (complete/incomplete/truncated/no-total/at-threshold), honest unavailable state on incomplete.
- **FR/AC/SC**: FR-009-007, FR-009-008; SC-009-002, SC-009-003.
- **Depends on**: T009-A-01, T009-A-02, T009-A-03, T009-A-04.
- **Verification**: `npm test -- --run tests/Frontend/Composable/FinanceSheetQueue.spec.ts` green.

---

## Phase B — Finance Cost Sheet Projection

**Goal**: Derive one presentation row per Cost Sheet from the proven-complete finance-eligible set, with honest unavailable handling for fields the flat queue doesn't provide.

### T009-B-01: Define Finance sheet queue presentation types
- **Target file(s)**: `src/types/api.ts`
- **Required behavior**: Additive interfaces: `FinanceSheetQueueItem` (sheetId, transactions[], transactionCount, totals per currency, latestActivityAt, costCenters[], reviewStatus), `FinanceSheetAmountTotal` (currency, minorUnit, amountMinor), `FinanceSheetCostCenter` (code, name), `FinanceQueueCompleteness`. No database/schema change; purely additive presentation types.
- **FR/AC/SC**: FR-009-004, FR-009-009, FR-009-010, FR-009-011; SC-009-002.
- **Depends on**: T009-A-03.
- **Verification**: Typecheck (`npm run typecheck`) green; interfaces compile without touching existing types.

### T009-B-02: Implement Finance grouping composable (buildFinanceSheetQueue)
- **Target file(s)**: `src/composables/useFinanceQueue.ts`
- **Required behavior**: Group de-duplicated finance-eligible transactions by `sheetId` → one `FinanceSheetQueueItem` per sheet. Deterministic ordering: within a sheet newest activity first; across sheets newest activity desc, tiebreak by sheet reference. Transaction count = de-duplicated finance-eligible count (never approximated). Per-currency totals (group by `currency`+`minorUnit`; never cross-currency sum). Cost centres collected distinctly.
- **FR/AC/SC**: FR-009-001, FR-009-004, FR-009-009, FR-009-010; AC-1, AC-2, AC-11; SC-009-001, SC-009-002.
- **Depends on**: T009-B-01, T009-A-02, T009-A-03.
- **Verification**: Unit test — N transactions one sheet → one row; two distinct sheets → two rows; correct transaction count; per-currency totals separate; no cross-currency sum; deterministic ordering; blank sheet reference handled.

### T009-B-03: Honest unavailable state for incomplete grouping
- **Target file(s)**: `src/composables/useFinanceQueue.ts` + `src/stores/reviews.ts`
- **Required behavior**: When completeness guard is `complete: false`, the sheet list is NOT derived; instead an honest unavailable/incomplete state (with reason) is exposed. Never fabricate sheet identity, counts, totals, or review status. Sheet title/purchaser/submitted date remain unavailable placeholders (flat queue doesn't provide them).
- **FR/AC/SC**: FR-009-006, FR-009-008, FR-009-011; AC-4, AC-10; SC-009-002, SC-009-003.
- **Depends on**: T009-B-02, T009-A-04.
- **Verification**: Unit test — incomplete set → unavailable state, no rows derived, no fabricated values; empty legitimate set → empty state not unavailable.

### T009-B-04: Finance sheet queue unit tests
- **Target file(s)**: `tests/Frontend/Composable/FinanceSheetQueue.spec.ts`
- **Required behavior**: Unit tests for T009-B-01..T009-B-03: grouping one row per sheet, duplicate suppression, correct count, per-currency totals, no cross-currency sum, deterministic ordering, incomplete grouping → unavailable.
- **FR/AC/SC**: FR-009-001, FR-009-004, FR-009-006, FR-009-007, FR-009-008, FR-009-009, FR-009-010, FR-009-011; AC-1, AC-2, AC-4, AC-10, AC-11; SC-009-001, SC-009-002, SC-009-003.
- **Depends on**: T009-B-01, T009-B-02, T009-B-03.
- **Verification**: `npm test -- --run tests/Frontend/Composable/FinanceSheetQueue.spec.ts` green (Phase A + B tests).

---

## Phase C — Cost-Sheet-First Queue UI

**Goal**: Replace the transaction-first `FinanceQueueView.vue` with a Cost-Sheet-first queue; one row per Cost Sheet; honest states; selection opens the modal while queue stays mounted.

### T009-C-01: Replace FinanceQueueView.vue transaction rows with sheet-first presentation
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`
- **Required behavior**: Replace flat `ResponsiveTable` transaction rows with one row per Cost Sheet derived from `useFinanceQueue`. Row shows Cost Sheet reference (always), finance-eligible transaction count, per-currency monetary summary, latest activity date, and review status where available. Title/submitter/submitted date render as honest unavailable placeholders (flat queue doesn't provide them). No transaction row as primary navigation surface.
- **FR/AC/SC**: FR-009-001, FR-009-004, FR-009-006, FR-009-011; AC-1, AC-2, AC-3 (partial); SC-009-001, SC-009-002.
- **Depends on**: T009-B-04.
- **Verification**: Component test — renders one row per sheet; reference/count/currency-totals shown; unavailable placeholders for title/purchaser/submitted; no fabricated values.

### T009-C-02: Queue-level loading/error/empty/unavailable states with retry
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`
- **Required behavior**: Loading state (`NcLoadingIcon`), error state with retry, empty state (no finance-eligible transactions — not a fabricated sheet), unavailable state (incomplete grouping — honest message + retry, never a partial flat table). Retry re-triggers `fetchFinanceQueue`.
- **FR/AC/SC**: FR-009-008; AC-4, AC-10; SC-009-003.
- **Depends on**: T009-C-01, T009-B-03, T009-A-04.
- **Verification**: Component test — each state renders correctly; retry re-fetches; unavailable never shows partial rows.

### T009-C-03: Cost Sheet row selection opens modal; queue stays mounted
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`, `src/components/review/FinanceSheetModal.vue` (new, stub wired in T009-D)
- **Required behavior**: Selecting a Cost Sheet row activates the sheet modal; the queue remains mounted and visible behind the modal; no route navigation as the primary flow. Row activation is keyboard-operable with visible focus.
- **FR/AC/SC**: FR-009-002; AC-12 (partial); SC-009-001.
- **Depends on**: T009-C-01, T009-D-01.
- **Verification**: Component test — opening a sheet keeps queue mounted; no router.push on row activation; keyboard activation works.

### T009-C-04: Queue-level Cost Sheet search/filter/sort (acts on Cost Sheets only)
- **Target file(s)**: `src/composables/useFinanceQueue.ts`, `src/views/reviews/FinanceQueueView.vue`
- **Required behavior**: If provided, queue-level filtering/search/sorting acts on Cost Sheet fields (reference, activity date, transaction count). Transaction-level search/filter/sort MUST NOT be duplicated at the queue level. Local operations over the proven-complete set; no per-action network request.
- **FR/AC/SC**: FR-009-005; SC-009-002.
- **Depends on**: T009-C-01, T009-B-02.
- **Verification**: Unit test — search matches sheet reference only; sort orders sheets; no transaction-level controls exposed at queue.

### T009-C-05: English/Persian labels + LTR/RTL for queue
- **Target file(s)**: `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js`, `src/views/reviews/FinanceQueueView.vue`
- **Required behavior**: Add `reviews.financeSheet.*` catalogue keys (queue title, reference, pending count, amount, latest activity, status, empty/unavailable states, retry). Queue uses `:dir="direction"` binding (never hardcoded `direction: rtl`). UUIDs and currency codes direction-isolated (LTR) inside RTL pages.
- **FR/AC/SC**: AC-13, AC-14; SC-009-006.
- **Depends on**: T009-C-01.
- **Verification**: l10n key parity en/fa; component renders English LTR and Persian RTL correctly; no hardcoded direction.

### T009-C-06: Queue accessibility (keyboard, focus, ARIA)
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`
- **Required behavior**: Every queue row and action keyboard-operable with visible focus indicator; ARIA labels on row activation; sheet reference/currency in `<bdi dir="ltr">`; 390px desktop floor, no horizontal scroll.
- **FR/AC/SC**: AC-13, AC-14; SC-009-006.
- **Depends on**: T009-C-01, T009-C-05.
- **Verification**: Component/accessibility test — keyboard traversal, visible focus, no horizontal scroll at 390px.

### T009-C-07: No account-management/recharge controls in queue
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`
- **Required behavior**: No holder directory, account balance inspection, ledger, holder management, recharge, or completed Cost Sheet account-history controls appear. Feature 008 surfaces excluded.
- **FR/AC/SC**: FR-009-024; AC-16; SC-009-009.
- **Depends on**: T009-C-01.
- **Verification**: Component test — asserts absence of account-management/recharge controls and test ids.

### T009-C-08: Phase C queue unit + snapshot tests
- **Target file(s)**: `tests/Frontend/Component/FinanceQueue.spec.ts` (new)
- **Required behavior**: Component tests for T009-C-01..T009-C-07: sheet-first presentation, states, selection, search/filter/sort on sheets, l10n, accessibility, no account-management controls.
- **FR/AC/SC**: FR-009-001..FR-009-005, FR-009-008, FR-009-011, FR-009-024; AC-1, AC-2, AC-3, AC-4, AC-10, AC-11, AC-13, AC-14, AC-16; SC-009-001, SC-009-002, SC-009-003, SC-009-006, SC-009-009.
- **Depends on**: T009-C-01..T009-C-07.
- **Verification**: `npm test -- --run tests/Frontend/Component/FinanceQueue.spec.ts` green.

---

## Phase D — Finance Sheet Modal

**Goal**: A reusable Finance modal over the mounted queue; one transaction card per finance-eligible transaction; prior CC/Senior/Finance decisions visible; evidence transaction-scoped; focus trap and restoration.

### T009-D-01: Create FinanceSheetModal.vue shell over mounted queue
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue` (new)
- **Required behavior**: Modal shell (`role="dialog"`, `aria-modal="true"`, labelled by sheet reference) rendered by `FinanceQueueView.vue`; queue stays mounted behind scrim; no route navigation as primary flow; single overlay layer; Escape/scrim/close-button close; focus trap where applicable.
- **FR/AC/SC**: FR-009-002; AC-12 (partial); SC-009-007.
- **Depends on**: T009-C-03.
- **Verification**: Component test — queue mounted behind modal; Escape/scrim/close closes; no router.push; focus trap present.

### T009-D-02: One transaction card per finance-eligible transaction
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`, `src/components/review/QuickReviewCard.vue` (reuse/adapt)
- **Required behavior**: Modal lists all finance-eligible transactions of the sheet, one card/review surface per transaction; no sibling transaction silently omitted. Card shows transaction identity, amount, currency, expense date/type. Order deterministic (newest activity first).
- **FR/AC/SC**: FR-009-003, FR-009-021; AC-3, AC-6; SC-009-002, SC-009-008.
- **Depends on**: T009-D-01, T009-B-02.
- **Verification**: Component test — one card per eligible transaction; no omitted sibling; card bound to correct transaction id.

### T009-D-03: Prior Cost Center / Senior / Finance decisions visible per transaction
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`, `src/components/review/PriorDecisionHistory.vue` (reuse)
- **Required behavior**: Each card shows Cost Center (stage `COST_CENTER`, advisory per FR-034), Senior (stage `SENIOR`, final management per FR-036), and Finance (stage `FINANCE`) decisions distinctly with stage labels; never collapsed into one generic status. Rendered from `TransactionDetail.decisions[]` via guarded per-transaction detail read.
- **FR/AC/SC**: FR-009-019, FR-009-020; AC-5; SC-009-002.
- **Depends on**: T009-D-02.
- **Verification**: Component test — CC/Senior/Finance decisions shown stage-labelled, not collapsed; absent decision shows unavailable not fabricated.

### T009-D-04: Finance decision surface gated by allowedActions
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`, `src/components/review/DecisionForm.vue` / `QuickDecisionModal.vue` (reuse)
- **Required behavior**: Decision controls (Approve/Reject/Return) per transaction gated by server-granted `allowedActions` (`decisionAllowed(...) === true`). Approve gated on `canApprove` only (Finance cannot approve senior-rejected per FR-039 / `FinanceDecisionPolicy`); Reject/Return gated on `canReject`/`canReturn`. No sheet-level "decide all"; no bulk approval.
- **FR/AC/SC**: FR-009-012, FR-009-013, FR-009-014; AC-7, AC-8, AC-9; SC-009-004, SC-009-005.
- **Depends on**: T009-D-02, T009-E-01 (decision command reuse).
- **Verification**: Component test — controls present only when `allowedActions` true; senior-rejected transaction has no Approve; no bulk action; single transaction per decision request.

### T009-D-05: Evidence thumbnail strip + preview overlay per transaction
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`, `src/components/evidence/EvidencePreview.vue` (reuse verbatim)
- **Required behavior**: Each card shows its own `evidence[]` thumbnails/metadata; clicking opens `EvidencePreview.vue` at exactly that document with the transaction's document set (Previous/Next + `1/N`); image → large preview; PDF → existing viewer; unknown → metadata + open/download (never inline); download fallback; preview close returns to same transaction context; no evidence mutation controls.
- **FR/AC/SC**: FR-009-021, FR-009-022; AC-6; SC-009-008.
- **Depends on**: T009-D-02.
- **Verification**: Component test — evidence bound to correct transaction; preview opens correct document; image/PDF/unknown handled; close returns to same transaction; no upload/replace/delete controls.

### T009-D-06: Modal focus restoration to originating sheet row
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`, `src/components/review/FinanceSheetModal.vue`
- **Required behavior**: Closing the modal returns focus to the originating Cost Sheet row activation control; queue unchanged; single overlay stack (opening details drawer or preview pushes one layer above modal).
- **FR/AC/SC**: FR-009-002; AC-12; SC-009-007.
- **Depends on**: T009-D-01, T009-C-03.
- **Verification**: Component test — focus restored to originating row after close; drawer/preview one layer at a time.

### T009-D-07: Unavailable sheet identity fields remain honest
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`
- **Required behavior**: Sheet title/purchaser/submitted date render as honest unavailable placeholders in the modal header (flat queue doesn't provide them); never fabricated; sheet remains actionable (transactions visible) despite missing identity fields.
- **FR/AC/SC**: FR-009-004, FR-009-006, FR-009-011; SC-009-002.
- **Depends on**: T009-D-01.
- **Verification**: Component test — unavailable placeholders shown for title/purchaser/submitted; transactions still render; no fabricated identity.

### T009-D-08: Phase D modal unit tests
- **Target file(s)**: `tests/Frontend/Component/FinanceSheetModal.spec.ts` (new)
- **Required behavior**: Unit tests for T009-D-01..T009-D-07: modal over mounted queue, one card per transaction, prior decisions distinct, allowedActions gating, evidence binding/preview, focus restoration, honest unavailable identity.
- **FR/AC/SC**: FR-009-002, FR-009-003, FR-009-004, FR-009-006, FR-009-011, FR-009-019, FR-009-020, FR-009-021, FR-009-022; AC-3, AC-5, AC-6, AC-7, AC-8, AC-9, AC-12; SC-009-002, SC-009-005, SC-009-007, SC-009-008.
- **Depends on**: T009-D-01..T009-D-07.
- **Verification**: `npm test -- --run tests/Frontend/Component/FinanceSheetModal.spec.ts` green.

---

## Phase E — Independent Transaction Decisions

**Goal**: Integrate existing Finance decision behavior; independent per-transaction decisions; no bulk; FR-039 senior-rejected restriction preserved; failures visible, no fabrication.

### T009-E-01: Reuse existing Finance decision command path in modal
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue` (decision submission), `src/stores/reviews.ts` (`recordDecision` reuse)
- **Required behavior**: Recording a Finance decision uses the existing `POST /transactions/{id}/decisions` path via `reviewsStore.recordDecision` with `DecisionWrite { decision, explanation, expectedVersion }` and `X-PettyCash-Command-ID` idempotency header. No new decision endpoint. One transaction per request; no bulk.
- **FR/AC/SC**: FR-009-012, FR-009-013, FR-009-016; AC-7, AC-8; SC-009-004.
- **Depends on**: T009-D-04.
- **Verification**: Unit test — exactly one `POST /transactions/{id}/decisions` per action; request carries decision/explanation/expectedVersion + command id; no sheet-level decision endpoint.

### T009-E-02: Mandatory comment for Reject/Return; optional for Approve
- **Target file(s)**: `src/components/review/DecisionForm.vue` / `QuickDecisionModal.vue` (reuse), `src/components/review/FinanceSheetModal.vue`
- **Required behavior**: Reject/Return require an explanation (refuse with field error if absent); Approve comment optional. Submitting disables controls, blocks scrim close during flight.
- **FR/AC/SC**: FR-009-015; AC-9 (partial); SC-009-004.
- **Depends on**: T009-E-01.
- **Verification**: Component test — reject/return without comment refused; approve without comment allowed; controls disabled during submit.

### T009-E-03: Senior-rejected Approve restriction preserved
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`, `lib/Domain/Workflow/FinanceDecisionPolicy.php` (read-only reference)
- **Required behavior**: Finance cannot approve a senior-rejected transaction (Approve not offered when `status === SENIOR_REJECTED`); Reject/Return offered per `allowedActions`. Enforced server-side via `FinanceDecisionPolicy::canApprove`; UI reflects `allowedActions.canApprove === false`.
- **FR/AC/SC**: FR-009-014; AC-9; SC-009-005.
- **Depends on**: T009-D-04, T009-E-01.
- **Verification**: Unit test — senior-rejected transaction: `canApprove` false, Approve control absent; Reject/Return present per `allowedActions`.

### T009-E-04: Successful decision refreshes only authoritative affected state
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`, `src/stores/reviews.ts`
- **Required behavior**: On success (`DecisionResult`), the card re-renders from `result.transaction`; per-transaction detail cache cleared as stale; toast confirms; sheet progress derives from refetched queue. No local status fabricated; no sibling transaction mutated.
- **FR/AC/SC**: FR-009-016, FR-009-017; AC-7, AC-8; SC-009-004.
- **Depends on**: T009-E-01, T009-E-02.
- **Verification**: Component test — card re-renders from server result; detail cache cleared; sibling entries unchanged; toast shown.

### T009-E-05: Decision failure visible, no fabrication
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`, `src/stores/reviews.ts`
- **Required behavior**: FR-085 `409`/`SUPERSEDED_ATTEMPT`, stale version (`fieldErrors.expectedVersion`), explanation-less reject/return (`fieldErrors.explanation`) rendered distinctly; no local status fabricated; queue refetch reflects server only; client does not auto-retry (FR-075).
- **FR/AC/SC**: FR-009-016, FR-009-017, FR-009-018; SC-009-004.
- **Depends on**: T009-E-01, T009-E-04.
- **Verification**: Component test — conflict/stale/comment errors rendered distinctly; no fabricated success; no auto-retry; server state wins.

### T009-E-06: Phase E decision-isolation tests
- **Target file(s)**: `tests/Frontend/Component/FinanceSheetModal.spec.ts` (extend)
- **Required behavior**: Tests for T009-E-01..T009-E-05: one decision per transaction; sibling isolation; senior-rejected no Approve; mandatory comment; success refresh from server; failure visible no fabrication.
- **FR/AC/SC**: FR-009-012..FR-009-018; AC-7, AC-8, AC-9; SC-009-004, SC-009-005.
- **Depends on**: T009-E-01..T009-E-05.
- **Verification**: `npm test -- --run tests/Frontend/Component/FinanceSheetModal.spec.ts` green (Phase D + E tests).

---

## Phase F — Evidence and Review Context

**Goal**: Evidence transaction-scoped; existing viewer reused; previous decisions distinguishable; unavailable identity fields honest.

### T009-F-01: Evidence remains attached to correct transaction
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`
- **Required behavior**: Each transaction card renders only its own `evidence[]`; thumbnail/preview bound to transaction id; no cross-transaction evidence leakage.
- **FR/AC/SC**: FR-009-021; AC-6; SC-009-008.
- **Depends on**: T009-D-02.
- **Verification**: Component test — evidence id matches transaction id; switching cards shows different evidence sets.

### T009-F-02: Image/PDF preview uses existing viewer; download fallback
- **Target file(s)**: `src/components/evidence/EvidencePreview.vue` (reuse), `src/components/review/FinanceSheetModal.vue`
- **Required behavior**: Inline-safe closed set (`utils/evidencePreview.ts` + FR-060): PDF → existing viewer; images → large preview; text → text; other media → metadata + open/download (never inline); inactive evidence → metadata only. Download fallback available.
- **FR/AC/SC**: FR-009-022; SC-009-008.
- **Depends on**: T009-D-05.
- **Verification**: Component test — each media type handled per policy; download control present for non-inline.

### T009-F-03: Preview close returns to same transaction/sheet context
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`
- **Required behavior**: Closing the preview overlay returns the user to the same transaction card within the same sheet modal (not to queue root or a different transaction).
- **FR/AC/SC**: FR-009-022; AC-6; SC-009-008.
- **Depends on**: T009-D-05, T009-F-02.
- **Verification**: Component test — preview close restores focused transaction card in same modal.

### T009-F-04: No evidence mutation controls
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`
- **Required behavior**: No evidence upload/replace/delete controls; evidence is read-only in this review surface.
- **FR/AC/SC**: FR-009-022; SC-009-008.
- **Depends on**: T009-F-01.
- **Verification**: Component test — asserts absence of upload/replace/delete controls.

### T009-F-05: Phase F evidence tests
- **Target file(s)**: `tests/Frontend/Component/FinanceSheetModal.spec.ts` (extend)
- **Required behavior**: Tests for T009-F-01..T009-F-04: evidence binding, preview media handling, context restore, no mutation controls.
- **FR/AC/SC**: FR-009-021, FR-009-022; AC-6; SC-009-008.
- **Depends on**: T009-F-01..T009-F-04.
- **Verification**: `npm test -- --run tests/Frontend/Component/FinanceSheetModal.spec.ts` green.

---

## Phase G — Localization / Accessibility / Responsive Verification

**Goal**: EN/FA parity, LTR/RTL, keyboard, focus, accessible names, desktop + 390px regression (per spec; no new mobile product requirements).

### T009-G-01: EN/FA catalogue key parity for finance sheet
- **Target file(s)**: `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js`
- **Required behavior**: Every new `reviews.financeSheet.*` key present in both en and fa; existing `finance.*`/`reviews.*` keys reused unchanged. No unlocalized user-facing strings in Finance Expense Review surfaces.
- **FR/AC/SC**: AC-13, AC-14; SC-009-006.
- **Depends on**: T009-C-05, T009-D-01.
- **Verification**: l10n parity script/check — every new key in en exists in fa and vice versa; no missing translations.

### T009-G-02: RTL/LTR behavior and currency/UUID isolation
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`, `src/components/review/FinanceSheetModal.vue`, `l10n/fa.js`
- **Required behavior**: Persian RTL; English LTR. `:dir="direction"` binding. UUIDs and currency codes in `<bdi dir="ltr">` inside RTL pages. Gregorian/Jalali calendar preference via existing locale utilities.
- **FR/AC/SC**: AC-13, AC-14; SC-009-006.
- **Depends on**: T009-G-01.
- **Verification**: Component renders correctly in Persian RTL and English LTR; currency/UUID direction-isolated; no horizontal scroll at 390px.

### T009-G-03: Keyboard operation and visible focus
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`, `src/components/review/FinanceSheetModal.vue`
- **Required behavior**: Complete queue → modal → decision → close journey keyboard-only; every interactive element reached shows visible focus indicator; focus trap in modal.
- **FR/AC/SC**: AC-13, AC-14; SC-009-006, SC-009-007.
- **Depends on**: T009-C-06, T009-D-01, T009-D-06.
- **Verification**: Keyboard-only navigation test; visible focus on all interactive elements.

### T009-G-04: Accessible names and statuses
- **Target file(s)**: `src/components/review/FinanceSheetModal.vue`, `src/views/reviews/FinanceQueueView.vue`
- **Required behavior**: Dialog labelled by sheet reference (`aria-label`/`aria-labelledby`); decision controls accessible; preview dialog name = file name; status badges accessible; ARIA on row activation.
- **FR/AC/SC**: AC-13, AC-14; SC-009-006.
- **Depends on**: T009-D-01, T009-D-05.
- **Verification**: Accessibility audit of rendered ARIA roles/labels; screen-reader-relevant checks.

### T009-G-05: Desktop + 390px responsive regression
- **Target file(s)**: `src/views/reviews/FinanceQueueView.vue`, `src/components/review/FinanceSheetModal.vue`
- **Required behavior**: Desktop primary (1440×900 English LTR); 390×844 Persian RTL mobile; no horizontal scroll (`scrollWidth <= clientWidth`); `≥44px` touch targets at 390px; dark theme/forced-colours spot-check. No new mobile product requirements created (desktop primary).
- **FR/AC/SC**: AC-13, AC-14; SC-009-006.
- **Depends on**: T009-G-02, T009-G-03.
- **Verification**: Playwright screenshots at both viewports; no horizontal scroll assertion; touch-target check.

### T009-G-06: Phase G i18n/a11y/responsive test suite
- **Target file(s)**: `tests/Frontend/Component/FinanceQueue.spec.ts`, `tests/Frontend/Component/FinanceSheetModal.spec.ts` (extend)
- **Required behavior**: Tests for T009-G-01..T009-G-05: l10n parity, RTL/LTR, keyboard, focus, accessible names, viewport regressions.
- **FR/AC/SC**: AC-13, AC-14; SC-009-006, SC-009-007.
- **Depends on**: T009-G-01..T009-G-05.
- **Verification**: `npm test -- --run tests/Frontend/Component/Finance{Queue,SheetModal}.spec.ts` green; l10n parity check green.

---

## Phase H — Regression / E2E / Convergence

**Goal**: Full test convergence; E2E entry path sheet row → modal → card → decision; preserve existing settlement/idempotency/negative-balance assertions; frontend gates.

### T009-H-01: Update existing Finance E2E entry path to sheet-row → modal
- **Target file(s)**: `tests/E2E/FinanceReview.spec.ts` (new or update existing finance E2E)
- **Required behavior**: E2E replaces old transaction-row → `/reviews/finance/:id` route entry with sheet-row → modal → transaction card → Finance decision. Covers the full journey per the approved product flow.
- **FR/AC/SC**: FR-009-001, FR-009-002, FR-009-003, FR-009-012; AC-1, AC-3, AC-7, AC-12; SC-009-001, SC-009-004.
- **Depends on**: T009-C-03, T009-D-08, T009-E-06.
- **Verification**: Playwright E2E (official Chrome channel) — sheet row opens modal; card decision recorded; success reflected; no route navigation on primary flow.

### T009-H-02: Preserve settlement/idempotency/negative-balance assertions
- **Target file(s)**: `tests/E2E/FinanceReview.spec.ts` (extend), existing settlement tests (read-only reference)
- **Required behavior**: Existing settlement (FR-040), idempotency/replay safety (FR-084), first-write-wins (FR-085), and negative-balance behaviour assertions remain green and unchanged by the sheet-first UI.
- **FR/AC/SC**: FR-009-016, FR-009-017; SC-009-004.
- **Depends on**: T009-E-06.
- **Verification**: Existing settlement/idempotency/negative-balance E2E suite green.

### T009-H-03: Persian/RTL E2E verification
- **Target file(s)**: `tests/E2E/FinanceReview.spec.ts` (extend)
- **Required behavior**: E2E in Persian RTL at 390px: queue renders RTL, modal opens, decision flow completes, focus restored, no horizontal scroll. Official Chrome channel.
- **FR/AC/SC**: AC-14; SC-009-006.
- **Depends on**: T009-H-01, T009-G-05.
- **Verification**: Playwright E2E (Chrome, Persian RTL, 390px) green; no horizontal scroll.

### T009-H-04: Frontend gates — typecheck, lint, stylelint, build, unit suite
- **Target file(s)**: repository root scripts
- **Required behavior**: `npm run typecheck`, `npm run lint`, `npm run stylelint`, `npm run build`, `npm test -- --run` all green with the new Finance files. No new dependencies introduced.
- **FR/AC/SC**: SC-009-002 (traceability), SC-009-006 (quality).
- **Depends on**: T009-A-05, T009-B-04, T009-C-08, T009-D-08, T009-E-06, T009-F-05, T009-G-06.
- **Verification**: `npm run check` green; `npm test -- --run` green.

### T009-H-05: Chrome E2E convergence run + visual evidence
- **Target file(s)**: `artifacts/visual-review/<task-id>/<iteration>/` (evidence only, Git-ignored)
- **Required behavior**: Full Chrome E2E run (official `chrome` channel) for Finance Expense Review: queue, modal, drawer, preview, decision modal, filtering/sorting, English LTR + Persian RTL, 390px. Screenshots stored under `artifacts/visual-review/` and cited by repository-relative path. A visual result without recorded owner approval is not reported as accepted.
- **FR/AC/SC**: AC-13, AC-14; SC-009-006; constitution §V/§VIII.
- **Depends on**: T009-H-01, T009-H-02, T009-H-03, T009-H-04.
- **Verification**: `npm run test:e2e` green; screenshot evidence present; no horizontal scroll (`scrollWidth <= clientWidth`) in all states.

### T009-H-06: Phase H regression convergence + CC/Senior unchanged check
- **Target file(s): existing 006/007 test suites (read-only reference)
- **Required behavior**: Confirm Cost Center Manager and Senior Manager workflows/tests unchanged (FR-009-025); existing CC/Senior suite green; Feature 008 account-management suite green (separation). No 006/007/008 task ledgers modified.
- **FR/AC/SC**: FR-009-023, FR-009-024, FR-009-025; AC-15; SC-009-009, SC-009-010.
- **Depends on**: T009-H-04.
- **Verification**: Existing CC/Senior/008 suites green; `git status` confirms no 006/007/008 task ledger changes.

---

## Owner-Only Manual Visual Acceptance Gate

### T009-GATE-01: Owner visual acceptance — Finance Expense Review (manual, unchecked)
- **Target file(s)**: none (artifact only — `artifacts/visual-review/` screenshots + this gate record)
- **Required behavior**: Product-owner manual review of the Finance Expense Review in English LTR and Persian/RTL. Covers Cost-Sheet-first queue (one row per sheet), modal over mounted queue (no route navigation), per-transaction independent decisions, senior-rejected Approve restriction, evidence transaction-scoped, focus restoration, 390px no-scroll, l10n parity. Remains unchecked by automation; requires explicit product-owner approval; covers the full approved journey `Cost Sheet Queue → Cost Sheet Review Modal → Transaction Review → Independent Finance Decision`.
- **FR/AC/SC**: All FR-009-xxx, AC-1..AC-16, SC-009-001..SC-009-010.
- **Depends on**: T009-H-05 (all implementation + E2E + visual evidence complete).
- **Verification**: Recorded owner approval (gate identifier, date, authorized scope). Automation MUST NOT check this task; implementation progress or automated tests MUST NOT complete it. This is the final task.

---

## Dependency Summary

| Phase | Depends on | Notes |
|---|---|---|
| A (Complete-Set Read) | none | Foundation; proves complete set before any UI grouping |
| B (Cost Sheet Projection) | A | Groups proven-complete set; honest unavailable on incomplete |
| C (Queue UI) | B | Sheet-first presentation; selection opens modal |
| D (Modal) | C | Modal over mounted queue; one card per transaction |
| E (Decisions) | D | Integrates existing decision command; per-transaction independent |
| F (Evidence) | D | Evidence transaction-scoped; existing viewer reused |
| G (i18n/a11y/responsive) | C, D, F | EN/FA, LTR/RTL, keyboard, focus, 390px |
| H (Regression/E2E) | A–G | Convergence; preserves existing assertions; Chrome E2E |
| GATE-01 (Owner gate) | H | Final; manual; automation-unchecked |

Parallelizable within a phase only where no intra-phase dependency exists (e.g., T009-C-05 l10n keys can be authored in parallel with T009-C-01 structure once catalogue key names are settled; T009-F-04 no-mutation-controls in parallel with T009-F-01 evidence binding).

---

## FR / AC / SC Coverage

| ID | Covered by tasks |
|---|---|
| FR-009-001 | A, B, C |
| FR-009-002 | C, D |
| FR-009-003 | D |
| FR-009-004 | B, C, D, F |
| FR-009-005 | C |
| FR-009-006 | B, C, D, F |
| FR-009-007 | A, B |
| FR-009-008 | A, B, C |
| FR-009-009 | B |
| FR-009-010 | B |
| FR-009-011 | C, F |
| FR-009-012 | E |
| FR-009-013 | E |
| FR-009-014 | D, E |
| FR-009-015 | E |
| FR-009-016 | E |
| FR-009-017 | E |
| FR-009-018 | E |
| FR-009-019 | D |
| FR-009-020 | D |
| FR-009-021 | F |
| FR-009-022 | F |
| FR-009-023 | G, H |
| FR-009-024 | C, G |
| FR-009-025 | H |
| FR-009-026 | A (architecture decision) |
| FR-009-027 | all (boundary) |

All AC-1..AC-16 and SC-009-001..SC-009-010 are represented (see per-task mappings above).

---

## Validation Results (to be confirmed at task-generation time)

1. **Every task ID unique** — `T009-A-01` … `T009-H-06`, `T009-GATE-01`; no duplicates.
2. **Every FR covered** — FR-009-001 through FR-009-027 each mapped to ≥1 task.
3. **All acceptance scenarios represented** — AC-1 through AC-16 each mapped.
4. **Dependencies internally consistent** — complete-set fetch → grouping → queue UI → modal → decisions → evidence → i18n/a11y → E2E → owner gate.
5. **No backend/schema/OpenAPI task** — no task touches `lib/`, `appinfo/`, DB schema/migrations, canonical contract, or generated API; no Finance-specific backend sheet endpoint added.
6. **Owner visual gate explicit and last** — `T009-GATE-01`, manual, automation-unchecked, depends on all implementation.
7. **Bounded execution batches supported** — phases A–H are independently executable slices ending at owner approval gates (mirrors 006 `FR-006-025`).

---

## Files Changed

Only `specs/009-finance-manager-expense-review/tasks.md` (new file).

## Git State

- Only Feature 009 task artifact created (`specs/009-finance-manager-expense-review/tasks.md`); no `plan.md`/`spec.md`/other spec artifacts modified.
- No production code changed (`src/`, `lib/`, `appinfo/` untouched by this mission; pre-existing dirty state remains).
- No implementation started.
- Feature 006/007/008 artifacts not modified.
- No stage/commit/push/reset/clean/deploy.

---

*Prepared by Spec Kit planning workflow. Task generation phase only — no code, no implementation, no commits. All backend/schema/contract boundaries respected per the approved plan.*
