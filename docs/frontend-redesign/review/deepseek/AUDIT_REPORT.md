# AUDIT_REPORT — Purchaser UI/UX vs Spec Kit vs implementation

**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe` · recorded 2026-10-19
**Method:** static source inspection of `specs/001-pettycash-v1-core/**`, `lib/**`, `src/**`, `appinfo/routes.php`, `tests/**`, plus the Manus intake under `docs/frontend-redesign/`. No application code was executed, no test suite was run, no browser session was used (see `FINAL_HANDOFF.md` §"Checks actually run").
**Companion documents:** `SOURCE_MAP.md` (architecture + evidence map), `GAP_MATRIX.md` (structured table), `PROPOSED_TASK_BACKLOG.md`, `tasks/`, `EXECUTION_PLAN.md`, `DECISIONS_AND_BLOCKERS.md`.

**Confidence vocabulary:** `confirmed_in_source` (the cited lines were read and say what is claimed) · `inferred` (a consequence that follows from the code but was not executed) · `unverified` (an open question; nothing is asserted).

---

## 0. Summary

**72 numbered entries:** **49 audit findings** (classified) plus **23 verified-control records** (entries where the behaviour was checked and found correct — they are numbered so they can be cited, and they are also summarised in the "Verified controls" table at the end).

| Class | Count | P1 | P2 | P3 |
|---|---|---|---|---|
| MISSING_FRONTEND_CAPABILITY | 13 | 6 | 5 | 2 |
| VERIFIED_DEFECT | 10 | 6 | 4 | 0 |
| PROTOTYPE_SIMPLIFICATION | 8 | 2 | 3 | 3 |
| OPTIONAL_IMPROVEMENT | 6 | 0 | 0 | 6 |
| DESIGN_INCONSISTENCY | 6 | 0 | 4 | 2 |
| SPEC_AMBIGUITY | 4 | 0 | 1 | 3 |
| MISSING_BACKEND_CAPABILITY | 2 | 1 | 1 | 0 |
| **Total (findings)** | **49** | **15** | **18** | **16** |
| *(verified-control records, no gap)* | *(23)* | — | — | — |

**P1 findings by responsibility:** frontend-only 10, backend-only 3, both 1, owner-decision 1.

**P1 finding ids:** GAP-001, GAP-002, GAP-011, GAP-017, GAP-021, GAP-023, GAP-024, GAP-025, GAP-030, GAP-054, GAP-062, GAP-064, GAP-065, GAP-066, GAP-068.

Three structural conclusions, each expanded below:

1. **The current purchaser surfaces are spec-compliant and financially correct; they are simply not the accepted design.** Minor units, categorical decisions, stage order, evidence ceiling, delete cascade and optimistic locking all check out against `specs/`. The gap is layout, navigation model, sorting and metadata editing.
2. **The redesign's headline behaviour — "OPEN sheets first, in either direction, across server pagination" — cannot be built today**, because `GET /sheets` has no sort parameter and no ordering clause (GAP-001). This is the one item that needs a contract decision before the list can be finished.
3. **The app is Persian-only and RTL-hardcoded** (GAP-063/064/065), while the accepted design direction is English LTR. This is a foundation issue that touches every screen, not a cosmetic one.

---

## Area A — Sheets list: columns, ordering, pagination, filters

#### GAP-001 — `GET /sheets` has no sort parameter and no OPEN-first ordering
- **Class:** MISSING_BACKEND_CAPABILITY · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Expected (accepted direction):** OPEN (never-submitted) sheets always first, in **either** ascending or descending first-submission order, **across server pagination**.
- **Actual:** the endpoint accepts only `status, ownerId, currency, from, to, page, pageSize` (`specs/001-pettycash-v1-core/contracts/ocs-api.yaml:364-381`); the handler signature has exactly those (`lib/Controller/SheetController.php:93-100`); the query orders by **one** key (`$qb->orderBy('s.created_at', 'DESC')` — `lib/Infrastructure/Persistence/Expense/DbExpenseSheetRepository.php:58`). A whole-document search for `sort|order by|ordering` across the OpenAPI contract returns **nothing**.
- **Impact:** the accepted list behaviour is unreachable; any client-side attempt cannot cross pagination because the client only ever holds one page (`src/stores/sheets.ts:30`).
- **Responsibility:** backend (contract + query). · **Minimal fix:** add a documented `sort` parameter and an ordering clause with OPEN-first as a leading key — `BE-CR-002`. · **Task:** `UI-PUR-003` (conditional), `BE-CR-002`.
- **Test strategy:** contract test on `GET /sheets?sort=…` with OPEN sheets interleaved across ≥3 pages; integration test pinning page 1..N membership; a Vitest case asserting the UI passes the parameter.
- **In purchaser-redesign scope:** yes.

#### GAP-002 — the "Open sheets" region is page-local, so an OPEN sheet on page 2 never appears in it
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Expected:** the region is the purchaser's working set and must be complete.
- **Actual:** `openSheets = computed(() => sheets.value.filter(s => s.status === 'OPEN'))` (`src/stores/sheets.ts:15`) where `sheets.value` is only the current page (`:30`); rendered at `src/views/sheets/SheetListView.vue:521-536`, with the overlap into the main table deliberately test-pinned (`tests/Frontend/Component/PurchaserFlow.spec.ts:2655-2661`).
- **Impact:** a purchaser with more OPEN sheets than one page silently loses sight of them — the exact case the region exists for.
- **Responsibility:** frontend, but blocked on GAP-001 for a correct server-wide answer. · **Minimal fix:** once `BE-CR-002` exists, request the OPEN set explicitly (filter or leading sort) instead of filtering the loaded page. · **Task:** `UI-PUR-003`.
- **In scope:** yes.

#### GAP-003 — no sort control on the list at all
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** no sort UI in `src/views/sheets/SheetListView.vue`; columns are not declared sortable there. (By contrast the *workspace* transaction table declares `sortable: true` per column — `src/views/sheets/SheetDetailView.vue:114-121` — client-side only.)
- **Impact:** even with `BE-CR-002`, the purchaser has no way to choose the direction the accepted direction requires.
- **Responsibility:** frontend. · **Task:** `UI-PUR-003`. · **In scope:** yes.

#### GAP-004 — page size is fixed at 25 with no control
- **Class:** OPTIONAL_IMPROVEMENT · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** `pageSize = 25` in store defaults (`src/stores/sheets.ts:11`); `PageSize` exists in the contract (`ocs-api.yaml:987`) but no UI exposes it.
- **Impact:** none functional. · **Disposition:** noted only; not a task. · **In scope:** yes (optional).

#### GAP-005 — non-deterministic ordering under offset pagination
- **Class:** VERIFIED_DEFECT · **Priority:** P2 · **Confidence:** confirmed_in_source for the code shape; `inferred` for tie occurrence
- **Actual:** a single `ORDER BY s.created_at DESC` (`DbExpenseSheetRepository.php:58`) combined with offset/limit (`offset = (page-1)*pageSize`, `lib/Application/Expense/SheetService.php:141-149`) and create-time tie-breakers absent → rows can be duplicated or skipped across pages when two sheets share `created_at`.
- **Impact:** a purchaser can see the same sheet twice, or miss one, without any error. Low probability (datetime column), high annoyance, and trivially avoided.
- **Responsibility:** backend. · **Minimal fix:** append a stable secondary key (public id) to the ORDER BY — folded into `BE-CR-002`. · **Test:** integration test inserting two sheets with an identical timestamp and asserting exact page membership.
- **In scope:** yes (correctness, independent of the redesign).

#### GAP-006 — the list's date filter is applied to `created_at`, while the list shows expense dates
- **Class:** SPEC_AMBIGUITY · **Priority:** P2 · **Confidence:** confirmed_in_source for the code; the *intent* is unverified
- **Actual:** `from`/`to` compare `s.created_at` (`DbExpenseSheetRepository.php:121-133`, end-of-day normalisation `lib/Application/Expense/SheetListFilterFactory.php:148`), while FR-064 says "filter by … date" without naming which (`spec.md:1058-1076`) and the list's date column family shows `firstExpenseDate`/`lastExpenseDate` alongside creation/submission dates (`src/views/sheets/SheetListView.vue:308-326`).
- **Impact:** a purchaser filtering "from 1403/07/01" can reasonably expect expense dates and get sheet-creation dates instead — a wrong-result look-alike with no error.
- **Disposition:** owner decision (`DECISIONS_AND_BLOCKERS.md` D-03); if the answer is "expense dates", that is a contract + query change (a `BE-CR` candidate), not a frontend fix.

#### GAP-007 — no free-text search on the purchaser list
- **Class:** OPTIONAL_IMPROVEMENT · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** absent from the view, the API client and the controller; FR-064 does not require it. The Manus prototype offers search (`prototype/manus-purchaser-v1/app.js:194`).
- **Disposition:** out of the minimum viable batch; revisit after `UI-PUR-003`.

#### GAP-008 — totals are queried per sheet (N+1) on the list endpoint
- **Class:** OPTIONAL_IMPROVEMENT · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** expense-date ranges are batched (`lib/Infrastructure/Http/SheetPayloadFormatter.php:164-177`) but totals go through `SheetSummaryQuery::totalsForSheet()` per sheet (`:45-52`).
- **Impact:** proportional query growth per page (25 sheets). SC-008 is explicitly deferred (`spec.md:1163-1164`). · **Disposition:** noted; not a task in this batch.

#### GAP-009 — the purchaser list is an 11-column table; the accepted design is a 6-column purchaser view
- **Class:** DESIGN_INCONSISTENCY · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Expected:** Sheet (title above ID) · Status · Transactions · Recorded Amount · Latest Approved Amount · First Submitted.
- **Actual:** 11 columns — `title, owner, currency, status, transactionCount, activeRevision, totals, latestEffective, stageApproved, keyDates, actions` (`src/views/sheets/SheetListView.vue:140-158`); **no sheet id is rendered in any cell** (`item.id` appears only as a row key/navigation target, `:199`, `:405`); Recorded and Submitted amounts share one `totals` cell (`:274-285`) rather than the design's separate Recorded/Latest-Approved columns.
- **Impact:** the purchaser's primary screen still reads as an administrative table; the accepted layout is not implemented.
- **Constraint:** FR-062 mandates the three stage-approved figures **and** the latest-effective figure as separate columns (`spec.md:1046-1048`) — dropping them from the *list* is an owner decision, not an audit finding (`DECISIONS_AND_BLOCKERS.md` D-04).
- **Responsibility:** frontend. · **Task:** `UI-PUR-002`.

#### GAP-010 — status colouring and semantic grouping in the list
- **Class:** DESIGN_INCONSISTENCY · **Priority:** P3 · **Confidence:** observed_in_source (structure only; no visual rendering was executed)
- **Actual:** status renders through a badge plus a class map; the redesign expects a distinct pill per lifecycle state including correction states. Cannot be judged visually from source alone → **VISUAL_REVIEW_NEEDED** for the final colour tokens.
- **Disposition:** folded into `UI-PUR-002` acceptance (token mapping only); no separate task.

---

## Area B — Sheet create / open / edit / delete / submit

#### GAP-011 — editing a sheet's title can silently repoint the sheet to another account
- **Class:** VERIFIED_DEFECT · **Priority:** P1 · **Confidence:** confirmed_in_source (data-integrity consequence `inferred`)
- **Expected:** a purchaser's "edit sheet" saves what they changed and nothing else; the account moves only by explicit choice, and only if the account is valid for that sheet.
- **Actual — three facts that compose into the defect:**
  1. the edit form prefills **only** the title, then defaults the account to the caller's **first** account: `form.value.title = sheetStore.currentSheet.title` (`src/views/sheets/SheetFormView.vue:120-127`) followed by `loadAccounts()` → `form.value.accountId = accounts.value[0]?.id` (`:113-118`);
  2. the sheet payload deliberately **omits** `accountId` ("`formatSheet()` also emitted an `accountId` the contract does not declare. It is dropped here" — `lib/Infrastructure/Http/SheetPayloadFormatter.php:22-24`), and the contract confirms it: `SheetSummary` has no account member (`contracts/ocs-api.yaml`, `SheetSummary` at :1422-1448), so the client has no correct value to send;
  3. the PATCH path **requires** `accountId` (`lib/Controller/SheetController.php:147-158`) and `updateSheet()` assigns it after only the owner/version/OPEN guards — no existence, ownership, active-state or currency check (`lib/Application/Expense/SheetService.php:180-186`).
- **Why it matters beyond the field itself:** a sheet stores `accountId` and `currency` as **independent** persisted facts (`ExpenseSheet.php:23-24`; both written at `DbExpenseSheetRepository.php:177-178,196-197`), and `updateSheet()` never reconciles the currency. A sheet whose currency is IRR can therefore end up pointing at an account whose currency is USD — the account's own money operations require a matching currency (`lib/Domain/Account/PettyCashAccount.php:53,73`) while the sheet's transactions continue to inherit the sheet's stored currency (`ExpenseSheet.php:65-66`). The repo already treats an unresolvable sheet currency as a data fault (`tests/Contract/Controller/DataIntegritySheetCurrencyTest.php:14-38`).
- **Impact:** a pure retitle changes which account a sheet charges at settlement. There is no confirmation and no undo.
- **Responsibility:** both. · **Minimal fix:** two coherent options — (a) expose the account on the sheet payload and prefill it, plus validate the assignment server-side; (b) treat the account as immutable after creation and drop it from the edit form. (a) preserves the accepted "account editable while OPEN" direction; (b) is the smaller frontend-only change. **Decide in `DECISIONS_AND_BLOCKERS.md` D-05.** · **Tasks:** `UI-PUR-007` + `BE-CR-001`.
- **Test strategy:** frontend — mount the edit form for a sheet on a second account and assert the payload carries the sheet's own account; backend — contract test that PATCH with a foreign/inactive/differently-denominated account is refused with a named `422`, and that the existing happy path is unchanged.
- **In scope:** yes (correctness + the accepted metadata-editing behaviour).

#### GAP-012 — no inline metadata edit while the sheet is OPEN
- **Class:** DESIGN_INCONSISTENCY · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** editing navigates to `/sheets/:id/edit` → `SheetFormView` (`src/router/index.ts:58`); the Edit control is hidden once submitted (`src/views/sheets/SheetDetailView.vue:250-253`).
- **Expected:** the accepted direction prefers inline title/account editing inside the OPEN sheet, with the details workspace staying in place.
- **Responsibility:** frontend. · **Task:** `UI-PUR-007` (depends on GAP-011's decision). · **In scope:** yes.

#### GAP-013 — delete is a plain button, not a 3-dot overflow action
- **Class:** DESIGN_INCONSISTENCY · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** bare Delete buttons in the open-sheets region (`SheetListView.vue:247-252`), the main table (`:361-367`) and the detail view (`SheetDetailView.vue:269-276`). **No `NcActions` is used anywhere under `src/`**, so the overflow-menu pattern does not exist in this codebase yet.
- **Impact:** destructive actions are permanently visible next to routine ones; the accepted design puts them behind the sheet's overflow menu.
- **Responsibility:** frontend. · **Task:** `UI-PUR-008`.
- **Note:** the confirmation dialog itself is correct and must be kept — it names the sheet (`src/components/shared/DeleteSheetDialog.vue:86-121`) which `spec.md:201` requires because the cascade destroys evidence files irreversibly.
- **In scope:** yes.

#### GAP-014 — `firstSubmittedAt` immutability is conventional, not enforced
- **Class:** SPEC_AMBIGUITY · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** `ExpenseSheet::submit()` is the only writer (`lib/Domain/Expense/ExpenseSheet.php:89`; persisted `DbExpenseSheetRepository.php:181,201`; rehydrated `:259`); the field is a public property, so nothing structural prevents a future writer. A repo-wide search found no other assignment.
- **Disposition:** documented, no task. The accepted rule ("first submitted timestamp is immutable") holds today by construction and is guarded in practice by the OPEN-only update path.

#### GAP-015 — the prototype's "reversible" delete copy is wrong for production
- **Class:** PROTOTYPE_SIMPLIFICATION · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** the prototype says the demo deletion is "reversible" (`app.js:308`) while it persists to localStorage (`:323`, `:129-130`) with no undo. The real system cascades irreversibly by design (`spec.md:201`).
- **Disposition:** copy must not be ported; recorded so a reviewer comparing screens does not treat the prototype as behavioural truth.

#### GAP-016 — Submit Sheet availability (verified correct)
- **Evidence:** `canSubmit = status==='OPEN' && transactions.length>0` (`src/stores/sheets.ts:14`), rendered only under `v-if` (`SheetDetailView.vue:258-261`) — no disabled placeholder on submitted/completed sheets, matching the accepted rule. Server preconditions are enforced independently (`lib/Application/Workflow/SubmitSheet.php:58-116`). `confirmed_in_source`.
- **Open sub-issue:** `canSubmit` does not consider evidence completeness — see GAP-030/GAP-031.

---

## Area C — Sheet detail workspace, stage indicator, metrics, scrolling

#### GAP-017 — no detail workspace over the list; navigation replaces the list and loses the page
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Expected:** selecting a sheet opens a large detail workspace/modal **over** the list, preserving scroll and filter state on close; on mobile the same access must be reachable without a nested modal stack.
- **Actual:** opening is a route push (`SheetListView.vue:405` → `src/router/index.ts:57`); there is no modal primitive for it (the only `NcDialog` in the purchaser flow is `DeleteSheetDialog`; `TransactionDetailView.vue:404` is the second). `AppRoot.vue:41` is a plain `<RouterView />` — no `KeepAlive`, and no `scrollBehavior` anywhere in `src/`. Returning to the list remounts and reloads with defaults, so **the page number resets to 1** (`SheetListView.vue:388-393` + `src/stores/sheets.ts:24`) even though the filters survive in the store.
- **Impact:** paging through 40 sheets and inspecting one returns the purchaser to page 1 — the "resume where I was" requirement is unmet, and the accepted workspace design does not exist.
- **Responsibility:** frontend. · **Tasks:** `UI-PUR-005` (workspace shell), `UI-PUR-004` (list state preservation). · **Splitting rationale:** the shell is a large, independently reviewable change; state preservation is a small change with its own acceptance test and no dependency on the shell's internals.
- **Test strategy:** Playwright journey — filter, page to 3, open a sheet, close, assert page + filters + scroll offset; Vitest — modal semantics (focus trap, Escape, focus return), mobile breakpoint renders one full-screen layer with no nested dialog. · **In scope:** yes.

#### GAP-018 — no stage/decision summary surface on the sheet
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Expected:** stage-specific workflow statistics for the sheet's transactions.
- **Actual:** the detail view presents a totals card and a transactions table (`SheetDetailView.vue:355-390`, `:114-121`); there is no stage summary, no decision breakdown and no approved/rejected/pending split per stage.
- **Dependency:** what can be shown is limited by GAP-020. · **Task:** `UI-PUR-014` (conditional). · **In scope:** yes.

#### GAP-019 — 30+ transactions render as a plain table
- **Class:** OPTIONAL_IMPROVEMENT · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** one `ResponsiveTable` over the full transaction array (`SheetDetailView.vue:403-425`); the maximum observed fixture is 32 transactions. No virtualization, no paging, but also no measured problem.
- **Disposition:** acceptance in `UI-PUR-006` is a scroll/performance check at ≥40 rows, not a re-architecture.

#### GAP-020 — per-stage metrics exist as approval **amounts** only
- **Class:** MISSING_BACKEND_CAPABILITY · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** `SheetTotals` carries `costCenterApproved`, `seniorApproved`, `financeApproved` amounts and the four headline figures (`lib/Domain/Expense/SheetTotals.php:30-32`, surfaced at `lib/Infrastructure/Http/SheetPayloadFormatter.php:246-261`). Per-stage **counts** and per-stage rejected/pending/returned are NOT_FOUND across `lib/` and `src/`. `TransactionSummary` exposes only one decision per transaction (`latestDecision` — `contracts/ocs-api.yaml`, `TransactionSummary`), so a client cannot reconstruct earlier stage outcomes from the sheet payload.
- **Consequence:** stage cards showing approved/rejected/pending/returned per stage are **not buildable from the current contract**. Stage-level queue populations exist only as dashboard figures (`DashboardController.php:220-222`).
- **Responsibility:** backend (a new aggregation) — `BE-CR-003`, explicitly conditional (`DECISIONS_AND_BLOCKERS.md` D-06). · **Task:** `UI-PUR-014`.
- **Note:** the accepted constraint "do not invent rolled-over totals" is satisfied today — **no** cross-sheet or rolled-over total exists anywhere (verified).

#### GAP-021 — the persisted sheet status is only recalculated during finance settlement
- **Class:** VERIFIED_DEFECT · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Expected:** `contracts/workflow.md:110-113` requires the sheet status to be a derived projection of its transactions ("the earliest still-outstanding stage"), which is exactly what the accepted "sheet stage/status derives from the earliest outstanding transaction" rule restates.
- **Actual:** the derivation exists (`lib/Domain/Expense/ExpenseSheet.php:133-179`, min-rank + all-terminal override) but the only caller is finance settlement (`lib/Application/Workflow/FinanceSettlementService.php:408` → `:524-536`). `ExpenseSheet::recalculateStatus()` has **no** other caller, and neither `ManagementReviewService` (cost-center/senior decisions) nor `ResubmitCorrection` recomputes.
- **Impact:** after a cost-center approval or a return, the sheet's stored status — which the **list** renders as its Status column — can remain at its previous value until some finance settlement touches that sheet. The purchaser's list is the primary surface, so the misreport is user-visible, not internal.
- **Responsibility:** backend. · **Minimal fix:** recompute on every decision and on resubmission inside the same transaction as the write — `BE-CR-005`. · **Test:** integration test asserting the sheet status after a cost-center decision, after a return, and after resubmission, without any finance settlement.
- **In scope:** yes — correctness of the list redesign depends on it.

#### GAP-022 — a decision submitted against a transaction in `CORRECTION` is accepted and stored without effect
- **Class:** VERIFIED_DEFECT · **Priority:** P2 · **Confidence:** confirmed_in_source for the code path; `unverified` behaviourally (no request was sent)
- **Actual:** a POST that passes owner authorisation (`lib/Application/Security/AuthorizationService.php:68-70`), the stage guard (`lib/Application/Workflow/ManagementReviewService.php:221-223`) and persistence then reaches `applyDecision()`, whose Correction branch does nothing (`lib/Domain/Expense/ExpenseTransaction.php:184-186`). The decision row and its audit event are written.
- **Impact:** an impossible-to-see decision exists in history and counts toward nothing — a reconciliation trap for the History surface.
- **Responsibility:** backend. · **Minimal fix:** refuse decisions while the transaction is awaiting purchaser correction (`BE-CR-006`). · **In scope:** borderline; filed because the redesign's Approvals/History tabs would surface it. Marked as a separately blocked backend item, not bundled into a UI ticket.

---

## Area D — Transaction create / edit / view / defaults

#### GAP-023 — a draft transaction cannot be deleted from the UI (FR-025 violation)
- **Class:** VERIFIED_DEFECT · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Expected:** "Before submission, the purchaser MUST be able to add, edit, and delete transactions" (`spec.md:832-833`, FR-025).
- **Actual:** the API client exists (`src/services/transactionsApi.ts:32-34`), the store action exists and is exported (`src/stores/transactions.ts:117-130,215`), and **no view or component calls either** — a search over `src/views`, `src/components` and `src/composables` for `deleteExistingTransaction(`/`deleteTransaction(` returns nothing. A test currently pins the *absence* of a delete button (`tests/Frontend/Component/TransactionCorrection.spec.ts:735`).
- **Server side is ready and correct:** sheet must be OPEN (`TransactionService.php:518-521`), transaction must be DRAFT (`:537-539`), owner only (`:514-516`), stale version → 409 (`:525-532`). So this is purely a missing affordance.
- **Impact:** a mistyped draft can only be corrected, never removed — the purchaser's only remedy is to abandon the sheet. Direct requirement violation.
- **Responsibility:** frontend. · **Task:** `UI-PUR-011`. · **Test:** component test for the control's presence/absence by status, plus an interaction test asserting the confirm step and the store call; the existing "no delete button" assertion must be updated deliberately, not deleted.
- **In scope:** yes.

#### GAP-024 — no cost-center preselection from the last created and saved transaction in the sheet
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Expected (owner-accepted rule):** preselect the cost center of the most recently **created and saved** transaction in the same sheet, only if it is still valid in the current configuration; never derive it from the last displayed/sorted row; otherwise leave blank; the user may override.
- **Actual:** the draft initialises `costCenterId: ''` (`src/views/transactions/TransactionFormView.vue:26-32`); the edit path copies from the payload only (`:187-200`); no `lastCreated*` tracking exists in the store, the API client or the server. `lastCreatedTransactionId` exists **only** in the design prototype (`prototype/manus-purchaser-v1/app.js:98`, used at `:282`).
- **Impact:** every transaction in a sheet defaults to an empty cost center, so the field becomes a repeated manual lookup — the single most repeated interaction in the purchaser's job.
- **Responsibility:** frontend (the sheet payload already carries each transaction's revision detail, so the value is derivable; no backend change needed). · **Task:** `UI-PUR-009`. · **Test:** component test — save two transactions with different centers, open a new draft, assert the second's center is preselected; assert an invalidated center (deactivated in configuration) leaves the field blank; assert the value is not taken from a re-sorted table.
- **In scope:** yes.

#### GAP-025 — the evidence uploader is rendered on the create form with an empty transaction id
- **Class:** VERIFIED_DEFECT · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Expected:** evidence is uploaded after the transaction exists, linked to it.
- **Actual:** the uploader renders whenever the form is editable (`src/views/transactions/TransactionFormView.vue:400-403`, `v-if="editable"`), and `editable` is **true** when creating (`:71-77` — `if (!isEditing.value) return true`). The id is `(route.params.id as string | undefined) ?? ''` (`:24`), which is `''` on `/sheets/:sheetId/transactions/create`. The uploader then POSTs to `/transactions//evidence` (`src/services/evidenceApi.ts:25`), an id-less path.
- **Impact:** a purchaser who attaches a receipt while drafting loses it (or gets an error) — the affordance advertises a capability the flow cannot perform. The existing test only asserts the area *renders* (`tests/Frontend/Component/PurchaserFlow.spec.ts:1876-1879`).
- **Responsibility:** frontend. · **Minimal fix:** hide the uploader until a transaction id exists and make the "save first, then attach" guidance the visible state on create. · **Task:** `UI-PUR-010`. · **Test:** component test asserting no upload control on the create route and that the guidance text is present; interaction test that the uploader appears on the edit route.
- **In scope:** yes.

#### GAP-026 — currency inheritance (verified correct)
- **Evidence:** currency comes from the sheet (`lib/Application/Expense/TransactionService.php:116-118`); the aggregate refuses a mismatched amount (`ExpenseSheet.php:65-66`); the field is derived, not selectable. FR-021 (`spec.md:792-793`). `confirmed_in_source`.

#### GAP-027 — partial draft save (verified correct)
- **Evidence:** the draft path requires only expense date + positive amount (`TransactionFormView.vue:155-162`, `lib/Controller/TransactionController.php:66-98`) while submission validates the full set (FR-022/FR-023). `confirmed_in_source`.

#### GAP-028 — the correction endpoint is not used by the correction flow, and its client omits the required version
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** `createCorrection()` sends `{}` with no `expectedVersion` (`src/services/transactionsApi.ts:58-60`) while `TransactionController` requires it (`lib/Controller/TransactionController.php:261-267`) → any future caller gets a `422`. Nothing calls it today: the UI's "Create correction" control routes to the edit form (`TransactionDetailView.vue:109`), which PATCHes instead (`lib/Application/Expense/TransactionService.php:309-312,426-431`).
- **Impact:** latent, not live. The two paths create a revision differently (`POST /correction` clones a working revision without activating; PATCH attaches a working revision), so which one is canonical affects the revision/audit story the redesign's History surface will display.
- **Responsibility:** frontend. · **Task:** `UI-PUR-013` (wire or retire the endpoint client; the server contract already defines the correction route).

#### GAP-029 — a 409 conflict does not reload the resource as FR-025 requires
- **Class:** VERIFIED_DEFECT · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Expected:** "Where a save carries a version that is no longer current, the application MUST refuse it with `409` … and **the client MUST reload before saving again**" (`spec.md:832-841`, FR-025).
- **Actual:** the refusal is correct on every write path, and the transaction edit path does send the version (`TransactionFormView.vue:204-208`, `src/services/transactionsApi.ts:23-30`) — but the client's response to a 409 is to display the store error; no reload of the conflicting resource was found for either the sheet or the transaction form.
- **Impact:** the purchaser is told the save failed and left with an editor holding stale values; the prescribed remedy is missing.
- **Responsibility:** frontend. · **Task:** `UI-PUR-012`. · **Test:** mock a 409 and assert the form refetches and re-presents the stored values with an explanatory message, and that a second save carries the new version.

---

## Area E — Evidence lifecycle

#### GAP-030 — "all required evidence" is not enforced at sheet submission
- **Class:** VERIFIED_DEFECT · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Expected:** "Submission MUST require every transaction to have … **and all required evidence**" (`spec.md:796-797`, FR-023); `data-model.md:122,267` repeats it.
- **Actual:** the submission gate calls `$revision->assertSubmittable()` with the default argument: `SubmitSheet.php:83` → `TransactionRevision.php:124-129` where the parameter defaults to `false`, and **no call site under `lib/` passes `true`**. An expense type that declares evidence required therefore does not block submission. The requirement is enforced only on the transaction form's primary button (`TransactionFormView.vue:112-117,140-142,149`), which is not the submission path.
- **Impact:** a sheet can be submitted — and proceed through cost-center, senior and finance review — with missing mandatory receipts; the missing document surfaces to a reviewer as an ordinary pending item, and the eventual remedy is a return, i.e. a full round trip for a check the purchaser's own screen claimed to make.
- **Responsibility:** backend (the enforcement belongs where `assertSubmittable` is already called). · **Fix:** `BE-CR-004`, blocked pending owner approval because it changes submission behaviour.

#### GAP-031 — the Submit affordance carries no readiness signal
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** `canSubmit` considers only status and transaction count (`src/stores/sheets.ts:14`), so an OPEN sheet with incomplete transactions shows an enabled Submit; the refusal arrives as a `422` rendered into `submission-errors` (`SheetDetailView.vue:442-451`), which the code documents as intentional (`:439-441`).
- **Impact:** acceptable once the server is authoritative (GAP-030), but the redesign's stated goal is that the purchaser can see what blocks submission *before* pressing it.
- **Responsibility:** frontend. · **Task:** `UI-PUR-016` (depends on `BE-CR-004` for a truthful signal).

#### GAP-032 — resubmission demands evidence even when the expense type does not require it
- **Class:** VERIFIED_DEFECT · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** `ResubmitCorrection` enforces full data **plus evidence** unconditionally (`lib/Application/Workflow/ResubmitCorrection.php:108-110`) while the sheet-submission path enforces no evidence at all (GAP-030) — the two are inverted relative to each other and relative to FR-023's "required" qualifier.
- **Impact:** a returned transaction whose expense type requires no evidence cannot be resubmitted without attaching something — the purchaser is forced to upload an irrelevant file, which then enters the audit record.
- **Responsibility:** backend. · **Fix:** one consistent policy in both places — `BE-CR-004` (same fix, same review).

#### GAP-033 — 100 MB ceiling (verified correct)
- **Evidence:** `MAX_EVIDENCE_BYTES = 104857600` (`lib/Application/Evidence/EvidenceService.php:29`), pre-buffer refusal with `413` (`lib/Controller/EvidenceController.php:101-107`), UI mirror (`src/components/evidence/EvidenceUploader.vue:29,55-62`). FR-074 requires exactly this "own ceiling … regardless of what the host file service allows" (`spec.md:1187`). `confirmed_in_source`.

#### GAP-034 — no MIME allowlist invented (verified correct)
- **Evidence:** the uploader accepts anything (`accept="*"`, `EvidenceUploader.vue:113`); the server keeps the client's media type verbatim and documents that "V1 applies no evidence restrictions beyond the size ceiling" (`EvidenceController.php:111-114`); serving forces unsafe types to `attachment`/`octet-stream` (`:503-512`). `confirmed_in_source`.

#### GAP-035 — multiple attachments, upload only after the transaction exists (verified correct)
- **Evidence:** each POST creates an additional evidence row (`EvidenceController.php:132-146`), all are listed (`src/components/evidence/EvidenceList.vue:88`), the upload route is nested under a transaction and gated to DRAFT/awaiting-correction states (`routes.php:35`, `EvidenceController.php:118-130`); FR-029 (`spec.md:852-854`). `confirmed_in_source`.
- **Caveat:** the *create form* breach is GAP-025.

#### GAP-036 — evidence is per transaction, not per revision
- **Class:** PROTOTYPE_SIMPLIFICATION · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** evidence rows attach to the transaction (`EvidenceController.php:132-146`); revisions carry no evidence member (`TransactionPayloadFormatter.php:156-171`). The prototype labels its evidence card "active transaction revision" (`app.js:251`) — wording, not structure. FR-029 says "each transaction", so the model is spec-consistent.
- **Disposition:** the redesign must not promise per-revision evidence; recorded so the wording is not ported.

---

## Area F — Review decisions, mixed stages, corrections, terminal consistency

#### GAP-037 — decisions are categorical; no partial-amount approval exists (verified correct)
- **Evidence:** the enum has exactly three members (`lib/Domain/Workflow/Decision.php:4`); no amount field on any write path (`src/types/api.ts:221-225`, `ManagementReviewService.php:63-70`, `FinanceSettlementService.php:74-80`); one immutable decision per (revision, stage) with a unique index and insert refusal (`DbWorkflowDecisionRepository.php:94-97,118-149`). `confirmed_in_source`.

#### GAP-038 — stage order is enforced server-side (verified correct)
- **Evidence:** Cost Center → Senior → Finance → Final (`ExpenseTransaction.php:114-191`), stage guard (`ManagementReviewService.php:221-223`), finance decision matrix (`FinanceDecisionPolicy.php:37-68`); the stage in the request body is ignored in favour of the transaction's real stage (`lib/Controller/ReviewController.php:211,227-243`). `confirmed_in_source`.
- **Dead code noted:** `WorkflowStateMachine.php` has no caller.

#### GAP-039 — return creates a correction revision that restarts at Cost Center (verified correct)
- **Evidence:** return sets `AWAITING_PURCHASER_CORRECTION` (`ExpenseTransaction.php:124-127,154-157,178-181`); correction is a new revision with the prior retained (`:208-214`); resubmission validates owner + full dataset then activates and re-enters at Cost Center (`ResubmitCorrection.php:90-143`, `ExpenseTransaction.php:42-48,271-279`); the audit event names `COST_CENTER` (`ResubmitCorrection.php:152-172`). Matches `contracts/workflow.md:67-74`. `confirmed_in_source`.

#### GAP-040 — terminal sheets contain only terminal transactions (verified, with a hardening note)
- **Evidence:** the completion gate requires every transaction in `{CompletedApproved, CompletedRejected}` and distinguishes the rejection case (`ExpenseSheet.php:165-179`). There is no standalone assertion; the invariant rests on the method being the sole writer, reachable only from finance settlement. Since GAP-021 shows that the *status* can lag, the invariant is sound but the display is not — one more reason `BE-CR-005` matters.

#### GAP-041 — `AWAITING_SENIOR_REVIEW` / `AWAITING_FINANCE_REVIEW` are declared but never produced
- **Class:** SPEC_AMBIGUITY · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** both values are in the domain enum (`lib/Domain/Workflow/TransactionStatus.php:7-17`) and in the published contract's `TransactionStatus` enum (`contracts/ocs-api.yaml`), and appear in the prototype's status list (`app.js:47`), but `applyDecision()` writes `COST_CENTER_APPROVED` / `SENIOR_APPROVED` / `SENIOR_REJECTED` instead (`ExpenseTransaction.php:119-153`) and nothing else produces them.
- **Impact:** an API consumer mapping the full published enum will write a dead branch; the redesign must not render a state that cannot occur.
- **Disposition:** spec/enum hygiene — recorded for the owner (`DECISIONS_AND_BLOCKERS.md` D-07). No task: removing an enum member from a published contract is a change-control decision, and no purchaser behaviour depends on it.

#### GAP-042 — rejected transactions continue independently (verified correct)
- **Evidence:** a cost-center rejection advances the stage without blocking a later approval (`ExpenseTransaction.php:121-123`), pinned by `tests/Unit/Domain/Workflow/ManagementWorkflowTest.php:119` and `tests/Integration/Workflow/ManagementDecisionTest.php:185-208`. This matches the accepted "each stage decides independently" rule and the contract's categorical model.

#### GAP-043 — no business rule is enforced only in the frontend (verified)
- **Evidence:** the offered decisions come from a server capability set (`DecisionForm.vue:111-115` vs `TransactionPayloadFormatter.php:303-313`); the finance "cannot approve a senior-rejected transaction" note reads the same server capability (`FinanceReviewView.vue:155-160` vs `FinanceDecisionPolicy.php:37-40`); explanation requirements exist on both sides (`DecisionForm.vue:117,126-131` vs `ManagementReviewService.php:226-228`, `WorkflowDecision.php:56-58`). Presentation-only derivations are labelled as such in the components. `confirmed_in_source`.
- **Caveat:** the *absence* of the frontend is a real problem elsewhere (GAP-023), and GAP-031 exists because the sheet's Submit gate cannot see evidence state.

#### GAP-044 — explanation length limit is UI-only
- **Class:** SPEC_AMBIGUITY · **Priority:** P3 · **Confidence:** unverified (no server guard located)
- **Actual:** `maxlength=500` on the textarea (`DecisionForm.vue:36`); no server-side maximum was found. · **Disposition:** noted only.

---

## Area G — Money semantics

#### GAP-045 — integer minor units end to end (verified correct)
- **Evidence:** `amount_minor` cast to int (`SheetSummaryQuery.php:191`), summed as ints (`SheetTotals.php:38-66`), emitted as `Money{amountMinor, currency, minorUnit}` (`SheetPayloadFormatter.php:273-283`), typed `amountMinor: number` (`src/types/api.ts:140-144`). No float money anywhere in the application. `confirmed_in_source`.

#### GAP-046 — `current` vs `effectiveApproved` semantics (verified correct)
- **Evidence:** `current` sums all active revisions; the effective outcome is `finance ?? senior ?? manager`; a `Return` yields no effective outcome and stays pending; stage approval sums are kept separately (`SheetTotals.php:38-84`), pinned by `tests/Unit/Domain/Expense/SheetTotalsTest.php:13-86` (including "a returned transaction is pending while earlier-stage approvals still count"). This is exactly the accepted "Recorded Amount = totals.current / Latest Approved = totals.effectiveApproved" mapping. `confirmed_in_source`.
- **Naming caution for the redesign:** the payload's `totals.approved/rejected/pending` are effective totals, not finance-stage totals; the six-column list must not conflate them with the `*Approved` stage figures.

#### GAP-047 — the prototype's money model must not be ported
- **Class:** PROTOTYPE_SIMPLIFICATION · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** amounts are JS floats (`25, 18.5, 42, 15, 25` — `app.js:60-64`), rendered with a hardcoded `$` and `en-US` grouping (`money()` — `app.js:34`), summed by raw float addition (`:139-142`), and the currency field is fixed to `USD` (`:56,98`) even though the real app's sheets are denominated in the configured account currency. Stage summaries fold `returned` into `pending` (`:148-150`), so a returned transaction shows as pending in the prototype's stage cards.
- **Impact if ported:** display-only currency, floating-point money, and a "returned looks pending" stage card. All three contradict surfaces the real API already gets right.
- **Disposition:** constraint recorded in `UI-PUR-002`/`UI-PUR-014` acceptance.

#### GAP-048 — a never-submitted sheet's `submitted` total renders as `0` rather than as absent
- **Class:** DESIGN_INCONSISTENCY · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** `SheetPayloadFormatter.php:253` falls back to `0` for a never-submitted sheet, while the codebase elsewhere insists a missing date is a dash and never a plausible-looking value (`src/utils/date.ts:1-31` — the same reasoning, applied to dates).
- **Impact:** a purchaser reading "submitted: 0" cannot distinguish "nothing submitted yet" from "submitted and totalling zero".
- **Disposition:** folded into `UI-PUR-002` acceptance (the list must render the submitted figure only when the sheet has been submitted).

#### GAP-049 — no separate finding (per-stage counts are GAP-020; rolled-over totals are GAP-050).

#### GAP-050 — no rolled-over or cross-sheet totals anywhere (verified)
- **Evidence:** the totals card is per sheet (`SheetDetailView.vue:355-390`); dashboard figures are queue counts, not sums (`DashboardController.php:220-222`). `confirmed_in_source`. This satisfies the accepted constraint; the redesign must keep it that way.

---

## Area H — Revision / audit / history

#### GAP-051 — history is per transaction only; there is no sheet-wide history endpoint (verified, by design)
- **Evidence:** `GET /transactions/{id}/revisions` and `/audit` (`appinfo/routes.php:31-32`); no sheet-scoped history route exists; and this is deliberate — `spec.md:240` reasons explicitly that a sheet-level history "would be a derived aggregate" introducing unanswerable ordering/authorisation/pagination questions. The prototype's History tab is a client-side merge of per-transaction audits and says so (`app.js:225-226`).
- **Consequence for the redesign:** a workspace History tab must either aggregate client-side from per-transaction endpoints or be dropped. `UI-PUR-006` acceptance covers the aggregation-with-disclosure option.

#### GAP-052 — the audit event's top-level `decision` is the literal string `'DECISION'`
- **Class:** DESIGN_INCONSISTENCY · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** `AuditController.php:115` emits `'decision' => 'DECISION'` whenever a decision id is attached; the real value (and the revision number) live inside `after` (`ManagementReviewService.php:286-291`; finance adds `financial_effect` at `FinanceSettlementService.php:353-359`).
- **Impact:** a History surface that renders the obvious field shows the word "DECISION" for every approval, rejection and return — a display defect waiting to be written.
- **Responsibility:** frontend (read `after`) — no backend change requested. · **Disposition:** recorded as an explicit acceptance criterion in `UI-PUR-006`, and as an open API-hygiene note for the owner.

#### GAP-053 — revisions are unpaged, audit is paged
- **Evidence:** revisions returns a bare array (`lib/Controller/AuditController.php:48-64`); audit returns an `AuditPage` (`:76-101`). `confirmed_in_source`.
- **Disposition:** the History surface must page the audit feed and must not assume the revisions array is bounded. Acceptance criterion in `UI-PUR-006`.

---

## Area I — Unsaved changes, conflicts, recovery, states

#### GAP-054 — there is no unsaved-changes guard anywhere in the application
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Expected:** the accepted Unsaved Changes dialog (`references/dialogs/unsaved-changes-en-desktop-v1.png`): save / discard / stay, on close, Escape and browser back.
- **Actual:** a search across `src/` for `beforeunload`, `onBeforeRouteLeave` and `isDirty` returns **zero** hits; `src/router/index.ts` registers no guards; Cancel navigates immediately (`SheetFormView.vue:265`, `TransactionFormView.vue:411-413`); Escape only dismisses existing `NcDialog` overlays with no dirty check (`DeleteSheetDialog.vue:91`); browser back is unguarded. The prototype implements the guard (`app.js:275-279,301-305,354-355`), including `beforeunload` and `popstate`.
- **Impact:** a purchaser who edits a sheet title or fills a transaction form and then closes, Escape-presses or navigates back loses the work with no prompt — the single acceptance that was explicitly already signed off visually.
- **Responsibility:** frontend. · **Task:** `UI-PUR-012`. · **Test:** component tests for each exit path with a dirty and a clean form; a Playwright journey for back-navigation; a unit test for the save/discard/stay branch, including the "save failed, stay" path the prototype's `allowSave` models.
- **In scope:** yes.

#### GAP-055 — optimistic locking is present and wired (verified correct)
- **Evidence:** `expectedVersion` is required and validated on sheet update (`SheetService.php:171-178`), sheet delete (`DeleteSheet.php:137-144`), sheet submit (`SubmitSheet.php:67-74`), transaction update/delete (`TransactionService.php:525-532`), decisions (`DecisionWrite`), and the transaction edit form sends it (`TransactionFormView.vue:204-208`). `confirmed_in_source`. GAP-029 is about the client's *reaction* to a 409, not about detection.

#### GAP-056 — no recovery of unsaved edits
- **Class:** OPTIONAL_IMPROVEMENT · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** no draft persistence in the form path (localStorage is not used for form state; the prototype uses it for demo state only).
- **Disposition:** explicitly a **non-goal** of `UI-PUR-012` unless the owner asks — it is a new capability, not part of the accepted guard.

#### GAP-057 — `AsyncState.vue` is a tested, unused shared component, and purchaser views hand-roll their states
- **Class:** OPTIONAL_IMPROVEMENT · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** `src/components/shared/AsyncState.vue` has a dedicated spec (`tests/Frontend/Component/AsyncState.spec.ts:65-67`) and **zero** import sites under `src/`. The purchaser views implement their own state handling: `loading` bound to the table (`SheetListView.vue:554`), a hand-written `loading-row` (`SheetDetailView.vue:403-404`) and an `empty-hint` (`SheetListView.vue:533`); `NcEmptyContent`/`NcLoadingIcon` are used by review/account views but not by the sheets/transactions views.
- **Impact:** three different loading/empty idioms across surfaces the redesign is about to restyle.
- **Disposition:** folded into `UI-PUR-002`/`UI-PUR-005`/`UI-PUR-006` acceptance ("one state component, or one documented pattern, per surface"); no standalone ticket, so a trivial refactor is not promoted to an independent outcome.

---

## Area J — Layout, responsive behaviour, accessibility, theming

#### GAP-058 — no `prefers-reduced-motion` handling
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** zero occurrences under `src/`; the prototype implements it (`styles.css:264-266`). The redesign introduces overlay transitions, so the gap becomes live.
- **Task:** `UI-PUR-015`.

#### GAP-059 — field validation errors are not programmatically associated with their inputs
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** `aria-invalid` has zero occurrences under `src/`; `aria-describedby` has one. Errors are passed through `error`/`helper-text` props (`TransactionFormView.vue:388-389`), which `@nextcloud/vue` renders visually but which do not by themselves announce the invalid state.
- **Impact:** screen-reader users are told a form failed to save without being told which field failed.
- **Task:** `UI-PUR-015`.

#### GAP-060 — responsive floor and table→card switching (verified correct)
- **Evidence:** `.responsive-table__desktop` hides and cards take over at ≤640px, with a ≤480px tightening pass (`src/styles/_responsive.scss`); FR-071's "every element visible without horizontal scroll at 390px" is pinned by `tests/Frontend/fr071NarrowViewport.spec.ts:61,98,126` and `tests/Frontend/Component/RtlAccessibility.spec.ts:140-141`. `confirmed_in_source`.
- **Note:** the redesign's mobile full-screen workspace is a *new* layout on top of this floor, not a fix to it.

#### GAP-061 — accessibility baseline is real but not complete
- **Evidence:** 61 `aria-label`s, 39 `role=` attributes, 9 `aria-live` regions, 9 `:focus-visible` rules; keyboard journeys exist (`tests/E2E/keyboard-accessibility.spec.ts`); `@nextcloud/vue` component contracts are pinned by `tests/Frontend/Component/NextcloudVueContract.spec.ts`. `confirmed_in_source`.
- **Gaps:** no `aria-modal` anywhere (custom overlays must supply it or use `NcDialog`), `aria-invalid` absent (GAP-059), reduced motion absent (GAP-058).

#### GAP-062 — the prototype's dialog semantics are not sufficient for production
- **Class:** PROTOTYPE_SIMPLIFICATION · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Actual:** the prototype declares `role="dialog" aria-modal="true"` (`app.js:233,244,248`) but implements **no focus trap** (no Tab containment), **no focus return** to the invoking row, no `role="tablist"`/`aria-selected` on its tabs (plain buttons, `:233`), and no Escape handling while the confirm dialog is open (`:351`). Transaction rows are focusable without a role (`:214`).
- **Impact if ported:** the workspace would trap screen-reader users outside the modal and lose the user's place on close — the failure mode `aria-modal` exists to prevent.
- **Disposition:** mandatory acceptance criteria in `UI-PUR-005`.

---

## Area K — Localization, direction, calendar preference

#### GAP-064 — every purchaser view hardcodes `dir="rtl"`
- **Class:** VERIFIED_DEFECT · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Expected:** D-006 requires Persian **and** English from day one with RTL/LTR following Nextcloud's/user's language; the accepted design work is English LTR.
- **Actual:** `dir="rtl"` is hardcoded on the view roots — 20 occurrences, including `SheetListView.vue:427,452`, `SheetDetailView.vue:203`, `SheetFormView.vue:170`, `DashboardView.vue:41`, `ReportView.vue:72`, `AuditView.vue:64`. An English-language user still gets an RTL layout, and the redesign's LTR layout cannot be exercised in the running app.
- **Impact:** the design being implemented cannot be seen in the product; the defect is invisible in a Persian-only test matrix.
- **Responsibility:** frontend. · **Task:** `UI-PUR-001`. · **Test:** mount with a mocked locale/direction and assert the root attribute follows it; keep the existing RTL assertions as the fa case so nothing regresses.

#### GAP-065 — the catalog ships Persian only
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Actual:** `l10n/` contains exactly `fa.js` and `fa.json`; `src/l10n.ts` names `FA_LOCALE = 'fa'` as *the* V1 catalog and the completeness gate (`tests/Frontend/l10nCatalog.spec.ts`) checks that one locale. There is no English catalog and no English UI strings anywhere.
- **Impact:** the accepted English-LTR design has no strings to render in a real deployment; conversely, deferring English translation while building English-LTR screens means the shipped default remains Persian RTL with an English layout grafted on — the two must be sequenced deliberately.
- **Disposition:** owner decision (`DECISIONS_AND_BLOCKERS.md` D-02). `UI-PUR-001` ships the *mechanism* (direction follows the locale; formatting is locale-aware) and explicitly does **not** author translations.

#### GAP-066 — no Gregorian/Jalali display preference; dates and numbers are hardcoded to `fa-IR`
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Expected (owner rule):** a Gregorian/Jalali preference independent of UI language, applying throughout Pettycash, without changing stored date semantics.
- **Actual:** every rendering site calls `toLocaleDateString('fa-IR')` / `toLocaleString('fa-IR')` with no calendar option — `src/utils/date.ts:36-38` and `src/utils/money.ts:19-25` plus ~12 further call sites across views. Jalali exists in the product only as a **server-side reporting-period resolver** (`lib/Domain/Shared/JalaliReportingPeriods.php`, FR-068), which is unrelated to display.
- **Impact:** an English-language user (or a Persian user who prefers Gregorian) sees Jalali dates formatted with Persian digits and grouping. There is no mechanism to change it and no single place to change it from.
- **Responsibility:** frontend. · **Task:** `UI-PUR-001`. · **Test:** unit tests over the formatting module for both calendars and both languages, asserting identical stored values produce the two renderings.

#### GAP-067 — no calendar conversion utility exists (constraint, not a task)
- **Evidence:** FR-068's Jalali logic is server-side and period-scoped (`JalaliReportingPeriods.php`); the prototype's "conversion" is a five-entry hardcoded pair table (`app.js:37-43`). `confirmed_in_source`.- **Disposition:** the display preference must be implemented as **formatting** (`Intl` calendar option) over the stored `YYYY-MM-DD` values, never as a conversion that rewrites stored dates. This is a hard constraint recorded in `UI-PUR-001`.

#### GAP-068 — the prototype fabricates the opposite-calendar value on save
- **Class:** PROTOTYPE_SIMPLIFICATION · **Priority:** P1 · **Confidence:** confirmed_in_source
- **Actual:** the create path stores `{ jalali: form.date or '1402/10/06', gregorian: form.date or '2023-12-27' }` selected by the *current* toggle (`app.js:290`), and the edit path repeats it (`:293`); the date field is free text with no format validation (`:244`). Its `{j,g}` pair objects (`:36-43`) are the only "conversion".
- **Impact if ported:** saving a transaction while the toggle is set to Jalali would write a hardcoded 2023-12-27 as the real date. This is the most dangerous single artefact in the prototype.
- **Disposition:** explicit "do not port" instruction in `UI-PUR-001`; the real implementation formats a single stored value.

#### GAP-069 — Persian/English string discipline (verified partially)
- **Evidence:** all purchaser labels go through `t('pettycash', …)` with catalogued keys (`SheetListView.vue:141-157`, `:429-601`), and a completeness gate enforces catalog coverage (`tests/Frontend/l10nCatalog.spec.ts:90-92`). Residual hardcoded strings are markers: `×`, `—` (`date.ts:31`), and a store fallback message (`src/stores/sheets.ts:34`). `confirmed_in_source`.
- **Disposition:** `UI-PUR-002`/`UI-PUR-005` acceptance requires new strings to be catalog keys, and the fallback message to become one.

---

## Area L — Prototype-to-production feasibility

#### GAP-070 — the prototype's design tokens must be re-expressed, not ported
- **Class:** PROTOTYPE_SIMPLIFICATION · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** a self-contained token set with a light and a dark value per semantic colour (`styles.css:3-47`), `--radius:16px`, an Inter-first font stack, and its own `--focus` ring; status pills and transaction-status classes are bound to those tokens (`:114-118,163-169`); tabular numerals are set explicitly (`:111,140`).
- **Constraint:** the app must express these through `@nextcloud/vue` 9.12.0 and Nextcloud's design tokens (D-002); Vuetify is prohibited; a parallel token system would produce two theming systems in one app — the failure mode `tests/Frontend/Component/NextcloudVueContract.spec.ts` exists to prevent.
- **Disposition:** acceptance constraint in `UI-PUR-005`/`UI-PUR-015`; the semantic bindings worth preserving are listed in `SOURCE_MAP.md` §7 and the prototype inventory.

#### GAP-071 — the prototype's rendering model must not be ported
- **Class:** PROTOTYPE_SIMPLIFICATION · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** vanilla JS with full-string re-render on every keystroke and manual caret/focus restoration (`app.js:339-346`), search re-render per character (`:191`), and `state.confirm` used but never initialised (`:257` vs `:115-122`).
- **Disposition:** the production forms use Vue reactivity; the caret-restoration pattern must not be reproduced as a workaround for a re-render that should not happen.

#### GAP-072 — external asset dependency in the prototype config
- **Class:** PROTOTYPE_SIMPLIFICATION · **Priority:** P3 · **Confidence:** confirmed_in_source
- **Actual:** `prototype/manus-purchaser-v1/app.config.ts` references an external Manus CDN for `logoUrl`.
- **Disposition:** informational. No external asset may be introduced into the app; the app mark stays local under `appinfo/`. Recorded so nobody copies the config.

#### GAP-073 — the mobile workspace has no nested modal stack (design constraint)
- **Class:** MISSING_FRONTEND_CAPABILITY · **Priority:** P2 · **Confidence:** confirmed_in_source
- **Actual:** today the sheet detail is a route (GAP-017) so there is no stack to nest; the prototype switches its workspace to a full-screen layer at ≤680px and treats the transaction drill-down as an internal view rather than a second modal (`styles.css:234-235`; `app.js:230`).
- **Disposition:** the production workspace must reproduce that **without** creating an inner dialog above an outer dialog; acceptance criterion in `UI-PUR-005`.

---

## Verified controls (checked, no gap)

| Control | Evidence | Note |
|---|---|---|
| Currency inherited per transaction, read-only | FR-021 `spec.md:792-793`; `TransactionService.php:116-118`; `ExpenseSheet.php:65-66` | correct |
| Partial draft save vs complete submission | FR-022/023; `TransactionFormView.vue:155-162`; `TransactionController.php:66-98` | correct |
| Expense date has no validity window (future dates valid) | `spec.md:787-806` (FR-023 clarification) | **do not invent a prohibition** — a future date is ordinary input |
| Categorical decisions only | `Decision.php:4`; no amount on any write path | matches "never partial approvals" |
| One immutable decision per (revision, stage) | `DbWorkflowDecisionRepository.php:94-97,118-149` | correct |
| Stage order + independent later stages | `ExpenseTransaction.php:114-191`; `FinanceDecisionPolicy.php:37-68` | correct |
| Return → correction → restart at Cost Center | `ResubmitCorrection.php:90-143`; `ExpenseTransaction.php:42-48,271-279` | correct |
| Terminal sheet ⇒ only terminal transactions | `ExpenseSheet.php:165-179` | sound; display lag is GAP-021 |
| No sheet-wide audit endpoint | `routes.php:31-32`; deliberate non-goal `spec.md:240` | **do not invent one** |
| 100 MB per-file ceiling | `EvidenceService.php:29`; `EvidenceController.php:101-107` | correct |
| No MIME allowlist | `EvidenceController.php:111-114` | **do not invent one** |
| Multiple attachments | FR-029; `EvidenceController.php:132-146` | correct |
| Evidence only after the transaction exists (server) | `EvidenceController.php:118-130` | correct; the *create form* breach is GAP-025 |
| No global "Save sheet" control | `SheetDetailView.vue:247-277`; `TransactionFormView.vue:414-429` | **do not add one** |
| No bulk/partial approval | `tests/Contract/Controller/SingleTransactionDecisionTest.php:75-198` | correct |
| No rolled-over / cross-sheet totals | `SheetDetailView.vue:355-390`; `DashboardController.php:220-222` | **do not invent** |
| Submit hidden when not permitted (no disabled placeholder) | `src/stores/sheets.ts:14`; `SheetDetailView.vue:258-261` | correct |
| Delete confirm names the sheet; OPEN-only; owner-only; cascade | `DeleteSheetDialog.vue:86-121`; `DeleteSheet.php:121-133`; `spec.md:201` | correct |
| Optimistic locking on every write path | see GAP-055 | correct |
| Save returns to the sheet detail (FR-088) | `TransactionFormView.vue:212-216,258-260` | correct |
| 390px no-horizontal-scroll floor | `_responsive.scss`; `fr071NarrowViewport.spec.ts` | correct |
| Purchaser's authority does not extend to approval | `AuthorizationService.php:68-77`; `FinanceDecisionPolicy.php:47-67` | correct |

---

## Findings that cannot be resolved from source

| Item | Why | Disposition |
|---|---|---|
| Whether the redesign's mobile set equals the owner's "eight mobile images" | the contact sheet shows 8 tiles (7 mobile + 1 desktop dialog); 7 mobile files are in `references/mobile/` | `DECISIONS_AND_BLOCKERS.md` D-08 |
| Approval status of Unsaved Changes V3 and the 390px mobile references | `references/DESIGN_REFERENCES.md` says "not yet approved"; the handoff documents call them accepted; no committed repository decision records approval | `DECISIONS_AND_BLOCKERS.md` D-01 — no image is labelled "approved" by this audit |
| Final colour/token mapping of status pills | not decidable from source; needs a rendering comparison | `VISUAL_REVIEW_NEEDED`, folded into `UI-PUR-002`/`UI-PUR-015` |
| Whether duplicate/skipped rows actually occur (GAP-005) | requires a live database with tied timestamps | left as `inferred`; the fix is cheap and safe |

---

*End of AUDIT_REPORT.md — 49 findings (15 P1 / 18 P2 / 16 P3), 23 verified-control records, 4 open visual/product questions.*
