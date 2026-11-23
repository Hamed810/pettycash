# Bug Assessment — BUG-TRX-CANCEL-NAV-001

## 1. Status

`REQUIREMENT_GAP`

## 2. Reproduction

**Exact route sequence (reported case: full-page Add transaction from a sheet):**

| Step | Route name | URL | Component | Responsible function |
|------|-----------|-----|-----------|----------------------|
| 1 | `sheet-detail` | `/sheets/{sheetId}` | `SheetListView` (sheet-detail route) | `SheetDetailView.goToCreateTransaction()` |
| 2 | `transaction-create` | `/sheets/{sheetId}/transactions/create` | `TransactionFormView` | `router.push({ name: 'transaction-create', params: { sheetId: sheetId.value } })` (SheetDetailView.vue:160) |
| 3 | Cancel clicked (clean form) | — | `TransactionFormView` | Cancel button `@click="askLeave()"` (TransactionFormView.vue:944) → `useUnsavedChangesGuard.ask()` (useUnsavedChangesGuard.ts:33) |
| 4 | `transactions` (current actual destination) | `/sheets/{sheetId}/transactions` | `TransactionListView` | Guard `ask()` clean path → `router.push(options.fallback())` (useUnsavedChangesGuard.ts:36) → fallback `{ name: 'transactions', params: { sheetId: sheetId.value } }` (TransactionFormView.vue:368) |

**Clean vs dirty CREATE:**
- Clean CREATE form → Cancel: `ask()` sees `!isDirty()` → immediate `router.push(fallback())` → `transactions`.
- Dirty CREATE form → Cancel → Discard: `discard()` → `router.push(fallback())` → `transactions`.
- Dirty CREATE form → Cancel → Save and leave: `saveAndLeave()` → `router.push(pending ?? fallback())` → `transactions` (after save).

**Actual destination:** `/sheets/{sheetId}/transactions` (the sheet's transaction list).

**Expected destination supported by evidence:** The owner expects `/sheets/{sheetId}` (the owning sheet-detail/workspace). No active requirement establishes this for Cancel; FR-088 governs only post-**save** navigation (see §4). So the "expected destination" is the owner's newly stated preference, not a requirement-backed destination.

**Sheet context is NOT missing in the normal flow:** `sheetId` (TransactionFormView.vue:118-123) resolves to `route.params.sheetId` in create mode, so Cancel correctly carries `{ sheetId }` to `transactions`. The `transactions` route (`/sheets/:sheetId/transactions`, router/index.ts:68) loads that sheet via `TransactionListView`. The observed "missing sheet context / empty table" symptom from the original report is not reproduced by the current source for the normal flow.

## 3. Root cause

**No implementation defect is confirmed for the normal flow.** The navigation works as coded: `sheetId` is preserved, and Cancel reaches `transactions` for the same sheet.

The smallest source-level explanation for why Cancel lands on `transactions` rather than `sheet-detail` is the **fallback supplied by `TransactionFormView`**:

- `src/views/transactions/TransactionFormView.vue:366-368`:
  ```
  fallback: () => props.embedded
      ? { name: 'sheet-detail', params: { id: sheetId.value } }
      : { name: 'transactions', params: { sheetId: sheetId.value } },
  ```
  - embedded form → `sheet-detail`
  - full-page form → `transactions`

- `src/composables/useUnsavedChangesGuard.ts:33-42` (`ask()`): the guard owns **no destination policy**. When clean it immediately `router.push(target ?? options.fallback())`; when dirty it opens the dialog and `discard()`/`saveAndLeave()` both push `pending ?? options.fallback()`. The guard only follows the caller-provided fallback.

- Route/origin info is **not lost**: `sheetId` is derived from `route.params.sheetId` (TransactionFormView.vue:118-123) and correctly carried to the fallback.

So the behavior is an **intentional historical navigation rule** encoded in the `TransactionFormView` fallback: full-page CREATE Cancel → `transactions`. This matches the EDIT fallback (test-locked at PurchaserFlow.spec.ts:2514, 2534). It is not a bug in `useUnsavedChangesGuard`, nor lost route/origin information.

## 4. Requirement evidence

| Requirement / task | ID | What it governs | Relevance to Cancel destination |
|---|---|---|---|
| FR-088 (001 spec.md:1529-1541) | Post-save navigation | "Saving a draft transaction MUST return the purchaser to the detail view of the sheet..." | Governs **SAVE only**. The 2026-10-09 clarification explicitly states "no requirement stated where a save leads" and addresses the save terminus. Does **not** govern Cancel. |
| Plan.md:167-168 | FR-088 | post-save navigation lands on sheet detail; defect on open T171 | T171 closed post-save routing (TransactionFormView.vue:212 → `sheet-detail`, FR-088). Save now goes to `sheet-detail` (TransactionFormView.vue:532). |
| T171 (001 tasks.md:1622-1636) | Post-save navigation fix | Correct post-save navigation per FR-088 | About **save**, not Cancel. Already delivered. |
| FR-005-024 (005 spec.md) | Unsaved-changes guard | Guard must exist on cancel/Escape/back/close, offer save/discard/stay | Governs guard **existence + three options**, not the Cancel destination. |
| T032 (005 tasks.md:168-169) | Guard + 409 | Save/Discard/Stay, Escape, beforeunload, route guard, 409 refetch | About guard mechanics, not Cancel destination. |
| FR-005-046 / T075 (005 spec.md:419) | OPEN-sheet editing | Embedded form save returns to same workspace | About embedded EDIT save, not full-page CREATE Cancel. |
| FR-005-045 (005 spec.md:418) | UI freeze | Sheets List + workspace are protected baseline | Not about Cancel destination. |

**FR-088 does NOT govern this Cancel case.** FR-088 governs only post-save navigation; the current save path already returns to `sheet-detail` (FR-088 satisfied via T171). No active specification, approved decision, or task acceptance criterion defines where Cancel from a CREATE form should go. This is a **requirement gap**, not an implementation defect against FR-088.

## 5. Existing test evidence

| File | Test name (line) | Route under test | Current assertion | Locks in old destination? |
|------|-----------------|------------------|-------------------|---------------------------|
| `tests/Frontend/Component/PurchaserFlow.spec.ts:2504` | 'discards without writing anything and leaves for the fallback route' | `/transactions/tx-1/edit` (EDIT) | `router.currentRoute.value.name).toBe('transactions')` after Discard | **Yes** — locks EDIT Cancel/Discard → `transactions`. |
| `tests/Frontend/Component/PurchaserFlow.spec.ts:2519` | 'saves the draft from the guard before leaving' | `/transactions/tx-1/edit` (EDIT) | `router.currentRoute.value.name).toBe('transactions')` after Save-and-leave | **Yes** — locks EDIT Cancel/Save-and-leave → `transactions`. |
| `tests/Frontend/Component/PurchaserFlow.spec.ts:2486` | 'guards a dirty form on Cancel and stays when asked to stay' | `/transactions/tx-1/edit` (EDIT) | Stays on `transaction-edit` when Stay chosen | Guards Stay behavior; not a destination assertion. |

**No test covers Cancel on the CREATE route.** Search for `transaction-create` + `cancel` across `tests/` returned no results. The existing Cancel tests are exclusively on the EDIT route and assert `transactions`. These tests represent implementation history for EDIT, not an authoritative requirement for CREATE. They must not be weakened or rewritten during assessment, and they create a regression boundary: EDIT Cancel → `transactions` must remain unchanged.

## 6. Classification

**This cannot proceed directly to `/speckit-bug-fix`. Specification work is required first.**

Classification 2 — REQUIREMENT_GAP: existing requirements define post-save navigation (FR-088 → `sheet-detail`, already implemented) but do **not** define create-form Cancel destination. FR-088 is explicitly scoped to save ("Saving a draft transaction MUST return..."); the 2026-10-09 clarification confirms no requirement stated where a save leads, and nothing states where Cancel leads. The owner's expectation (Cancel → owning sheet-detail) is a **new product decision**, not an existing requirement.

If the owner approves Cancel → `sheet-detail` as a new requirement, the specification artifact needing amendment would be a new requirement (or clarification) naming the create-form Cancel destination, plus reconciliation of any dependent task/test artifacts. No such amendment exists today.

## 7. Proposed bounded fix scope (no implementation yet)

If a future authorized fix adopts Cancel → `sheet-detail` for CREATE:

**Likely files to change:**
- `src/views/transactions/TransactionFormView.vue:366-368` — change the non-embedded fallback from `{ name: 'transactions', ... }` to `{ name: 'sheet-detail', params: { id: sheetId.value } }`, matching the embedded branch (line 367). Narrow, create-mode-scoped change; do not alter the reusable guard globally.

**Behavior to preserve (regression boundaries):**
- Successful Save → `sheet-detail` (FR-088, TransactionFormView.vue:532) — unchanged.
- Embedded form Cancel → `sheet-detail` (TransactionFormView.vue:367) — unchanged.
- EDIT Cancel/Discard/Save-and-leave → `transactions` (test-locked, PurchaserFlow.spec.ts:2514, 2534) — **must remain unchanged**.
- Unsaved Save/Discard/Stay semantics — unchanged.
- No transaction/draft created by Cancel or Discard — unchanged (guard is inert re: API).
- Staged receipts not uploaded unless Save chosen — unchanged.
- Correction/resubmission behavior — unchanged.
- Transaction list remains a deliberate navigation surface (keeps its Add control per FR-088 clarification).
- Authorization, financial rules, workflow states, API contracts, database schema — unchanged.

**Paths/subsystems that must remain untouched:**
- `src/composables/useUnsavedChangesGuard.ts` (guard owns no destination policy).
- `src/views/transactions/TransactionListView.vue` (list surface role).
- `src/router/index.ts` route definitions.
- EDIT-path tests in `PurchaserFlow.spec.ts` asserting `transactions`.

**Likely tests to add (future):**
- CREATE Cancel (clean) → `sheet-detail` with same `sheetId`; no create request.
- CREATE Cancel (dirty) → Discard → `sheet-detail` with same `sheetId`; no create request.
- CREATE Cancel (dirty) → Save → `sheet-detail` after create; one create request.
- EDIT Cancel → `transactions` remains (regression, already covered).

## 8. Verification plan for the future fix (`/speckit-bug-test`)

**Targeted component tests (Vitest):**
1. Mount `TransactionFormView` on `/sheets/{sheetId}/transactions/create` (clean). Click `[data-test="transaction-cancel"]`. Assert route `name === 'sheet-detail'` and `params.id === sheetId`; assert zero `POST /sheets/{sheetId}/transactions` calls.
2. Same mount, dirty the form, click Cancel → click Discard. Assert route `sheet-detail` with same `sheetId`; zero write calls.
3. Same mount, dirty the form, click Cancel → click Save. Assert one `POST` create call; assert route `sheet-detail` with same `sheetId`.

**One browser journey (E2E/Playwright):**
- `sheet-detail` → Add transaction → Cancel (no fields filled) → assert URL is `/sheets/{sheetId}` (same sheet-detail), and no transaction row appears on the sheet.

**Dirty-form variant:**
- `sheet-detail` → Add transaction → modify a field → Cancel → Discard → assert URL is `/sheets/{sheetId}` and no transaction created.

**Also preserve correct Save behavior:** Save from the same journey → assert URL is `/sheets/{sheetId}` (sheet-detail) and the transaction appears.

## 9. Repository state

- Current branch: `main`.
- Pre-existing dirty files relevant to this area: `src/router/index.ts` is modified (`M`) but that modification pre-dates this assessment and was not made by me; `TransactionFormView.vue`, `useUnsavedChangesGuard.ts`, `SheetDetailView.vue`, and the PurchaserFlow spec are at committed state (not in `git status` modified/untracked lists relevant to this area).
- Confirmation: this assessment made **no writes** to application source, tests, specs, plans, contracts, generated assets, or management documents. The only filesystem change was overwriting `.specify/bugs/cancel-add-transaction-navigation/assessment.md` (the assessment deliverable itself), which is not in the prohibited write-boundary list.
- Confirmation: no prohibited Git operation occurred (no add/commit/push/reset/clean/checkout/rebase/merge/stash/restore). `git status` was read only.
