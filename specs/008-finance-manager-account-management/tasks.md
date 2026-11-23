# Tasks: Finance Manager Account Management (Feature 008)

**Input**: Design documents from `specs/008-finance-manager-account-management/` — `spec.md` (user stories US1–US5 with priorities, FR-008-001…051, SC-008-001…020, corrected 2026-11-16), `plan.md` (design decisions D1–D11, backend/frontend design, query budgets, sequencing), `research.md` (R1–R18 with source anchors), `data-model.md` (projections and invariants, no schema change), `contracts/finance-manager-api.md` (five additive Finance reads + evidence-content authorization + reused charge), `quickstart.md` (observable validation scenarios). Constitution: `.specify/memory/constitution.md`. Formatting precedent: `specs/007-senior-manager-cost-sheet-review/tasks.md`.

**Prerequisites**: `spec.md` and `plan.md` present and corrected 2026-11-16. Settled decisions binding on every task: Cost Sheet reference V1 = the existing public UUID (no new reference format); no database schema change, migration, or new index in any task; purchaser-owned account reads unchanged; Finance cross-user reads additive and role-scoped; the holder population is exactly the users who own at least one petty-cash account; holder search covers UID and display name inside that population only; recharge reuses the existing financial write unchanged; every authoritative account movement stays individually represented; completed history is read-only and terminal-only; evidence access for Finance is content-only and scoped to exposed completed history; English primary with Persian localization; owner visual approval is a separate manual gate.

**Status**: Implementation ledger, rebuilt 2026-11-16 from the authoritative Feature 008 artifacts after the previous ledger was found contaminated; nothing from the previous ledger or the removed unauthorized agent file is carried over. Contract/foundation batch T001–T005 (canonical contract alignment, generated types, Finance gate, completed-sheet scope) and backend batch T006–T008 (holder directory, holder search, holder-account read, with the real `FinanceReadQuery` SQL verified against MariaDB through the persistence round-trip) are checked complete; T009+ remain. This file authorizes no code change by itself; a task is checked only when its stated evidence exists. Working-tree changes are preserved; no Git stage/commit/push/reset/clean is authorized by this ledger.

**Organization**: Six dependency-ordered phases (A–F) per the approved plan sequencing: contract and read-scope foundation → authoritative ledger backend → completed history and evidence → frontend data layer and recharge → Finance Manager UI → verification and owner-gate preparation. `[US#]` maps a task to its user story (US1 holder directory + accounts / US2 unified ledger / US3 recharge / US4 completed history / US5 localization + shell); contract, shared-foundation, and verification tasks carry no story label. Every task names exact file or narrow directory targets, its concrete outcome, `FR-008-*` / `SC-008-*` mappings, and the targeted check that proves it; non-obvious prerequisites are stated inline. Tests are included because `spec.md` §"User Scenarios & Testing" and `plan.md` §"Verification Strategy" require them.

## Format

`- [ ] T### [P] [US#] Description — verification`

- `[P]` = can run in parallel with other `[P]` tasks in the same phase (different files, no shared dependency).
- PHP work runs only through the repository wrappers (`scripts/dev/phpunit`, `scripts/dev/occ`, `scripts/dev/opcache-settle`, `scripts/dev/route-settle`, `scripts/dev/route-cache-clear`); host PHP/Composer/PHPUnit/OCC are prohibited. After `appinfo/routes.php` changes run route settling and cache clearing; before direct live-HTTP checks run opcode settling. Frontend work runs on the host with the existing npm scripts. Visual evidence uses official Google Chrome only and is stored under `artifacts/visual-review/<task>/<iteration>/` with repository-relative citations.

---

## Phase A — Contract and Finance Read-Scope Foundation

**Purpose**: align the canonical OCS contract with the five approved Finance read operations, refresh the generated typed source, validate the additive contract, and land the Finance read-scope primitives — authorization gate, completed-sheet scope, holder directory, holder search, holder account read — before any dependent public route implementation. Contract alignment is a technical prerequisite inside implementation sequencing, not a separate product phase. No database schema work exists in this or any phase.

- [x] T001 Merge the five approved Finance read operations from `specs/008-finance-manager-account-management/contracts/finance-manager-api.md` into the canonical contract `specs/001-pettycash-v1-core/contracts/ocs-api.yaml`, additive only: paths `GET /api/v1/finance/holders`, `GET /api/v1/finance/holders/{holderUid}/accounts`, `GET /api/v1/finance/accounts/{accountId}/ledger`, `GET /api/v1/finance/completed-sheets`, `GET /api/v1/finance/completed-sheets/{sheetId}`; their query parameters (`page` ≥1 default 1, `pageSize` 1–100 default 25, holder `search` trimmed ≤64); their response schemas (holder summary, holder account summary, ledger page/row, completed sheet summary/detail, pagination envelope, error envelope); and, on the existing `GET /evidence/{evidenceId}/content` operation, the recorded read-only completed-history authorization condition with no request/response shape change. No existing operation, schema, purchaser path (`GET /accounts`, `GET /accounts/{accountId}/movements`), or the reused `POST /accounts/{accountId}/charges` changes shape. — Verification: the parse stage of `scripts/validate-openapi.sh` passes over the amended document and the reviewed `git diff specs/001-pettycash-v1-core/contracts/ocs-api.yaml` shows additions only. (Contract basis for FR-008-005, FR-008-006, FR-008-008–010, FR-008-012, FR-008-032–034, FR-008-037; prerequisite of every public route task.)

- [x] T002 Regenerate the typed API source with the repository-prescribed generation command: run `scripts/validate-openapi.sh --write` to refresh `src/generated/api.ts` from the amended canonical contract; the generated file is never hand-edited. — Depends: T001. Verification: `npm run check:openapi` exits 0 (drift gate green, `@contractFingerprint` updated).

- [x] T003 Canonical contract validation for the additive batch: confirm `scripts/validate-openapi.sh` validates the amended document and `npm run check:openapi` is green; confirm the delta versus the pre-feature contract contains only the five Finance paths, the new schemas, and the recorded evidence-content authorization note — no existing operation lost a field, changed a status code, or was duplicated. — Depends: T002. Verification: exit-0 drift gate plus a reviewed additive-only diff. (FR-008-004 and FR-008-037 at the contract layer.)

- [x] T004 [P] Finance authorization primitive: create `lib/Application/Finance/FinanceAccess.php` resolving the actor's effective `FINANCE` role assignment once per request via the existing active-assignment lookup shape (`RoleAssignmentRepository::findActiveByUserUid($uid, $now)`); the actor uid comes from the authenticated session (`CurrentUser`), never from a request parameter; platform-administrator status alone grants nothing; `ReportScope` is not used. Register the service in `lib/AppInfo/Application.php`. — Verification: new `tests/Unit/Application/Finance/FinanceAccessTest.php` via `scripts/dev/phpunit tests/Unit/Application/Finance/FinanceAccessTest.php`: active assignment → admitted; no assignment, expired assignment, or administrator without assignment → refused. (FR-008-005, FR-008-007; SC-008-003.)

- [x] T005 [P] Completed-sheet scope primitive: create `lib/Application/Finance/CompletedSheetScope.php` with `isExposedByCompletedHistory(ExpenseTransaction $transaction)` — true only when the transaction is `COMPLETED_APPROVED` or `COMPLETED_REJECTED` at `WorkflowStage::Final`, its sheet is `COMPLETED` or `COMPLETED_WITH_REJECTIONS`, and every transaction of that sheet is terminal; returns `false` (never throws) for out-of-scope input; performs no write. This is the single shared predicate for the completed-history detail read and the evidence-content branch, so the two cannot drift. — Verification: new `tests/Unit/Application/Finance/CompletedSheetScopeTest.php` via `scripts/dev/phpunit tests/Unit/Application/Finance/CompletedSheetScopeTest.php`: terminal sheet and transactions → true; pending-Finance transaction, non-terminal sheet, or mixed sheet → false with no exception. (FR-008-027, FR-008-032, FR-008-036; SC-008-011.)

- [x] T006 [P] [US1] Holder directory backend: create `lib/Application/Finance/HolderDirectoryService.php` plus the grouped holder-population reads in `lib/Infrastructure/Persistence/Finance/FinanceReadQuery.php` (group `pettycash_accounts` by `user_uid`: `COUNT(*)` accountCount, `SUM(active)` activeAccountCount): a holder is only a user owning ≥1 petty-cash account, listed exactly once; derived `status` `ACTIVE`/`INACTIVE`/`MIXED`; display names resolved only for account-owning uids through the existing `UserDirectory::displayName()` port with a per-request cache, falling back to the uid; server-side `page`/`pageSize` pagination with `total` = the full matching holder count. — Depends: T004 (controller-level gate). Verification: holder-scope cases in `tests/Integration/Finance/FinanceReadQueryTest.php` via `scripts/dev/phpunit tests/Integration/Finance/FinanceReadQueryTest.php`: holder listed once with correct counts and status; account-less user absent; name resolution never adds or removes a holder. (FR-008-006, FR-008-008, FR-008-009; SC-008-001.)

- [x] T007 [US1] Holder search by UID and display name: extend `lib/Application/Finance/HolderDirectoryService.php` with the `search` parameter (trimmed, ≤64 characters; case-insensitive substring) matched against the holder `userId` or the resolved `displayName`, applied server-side to the complete account-owning holder population before pagination — never a filter of only the loaded page, and never an unrestricted Nextcloud user-directory search or enumeration (no `IUserManager::searchDisplayName`/`search` call is introduced); with a term, one grouped read of the complete holder population, display-name resolution bounded by that population with the per-request cache, in-memory match, sort by `user_uid`, then the page slice; `total` = the filtered holder count. — Depends: T006. Verification: search cases in `tests/Integration/Finance/FinanceReadQueryTest.php`: a term matches display name and uid case-insensitively; holders on later pages are found (population-wide, not page-local); an account-less user is never returned for any term. (FR-008-006, FR-008-008; SC-008-001.)

- [x] T008 [US1] Holder account Finance read: implement `FinanceLedgerService::accountsForHolder(holderUid, page, pageSize)` in `lib/Application/Finance/FinanceLedgerService.php` with the additive `AccountRepository::findAllByUserUid()` (`lib/Domain/Account/Repository/AccountRepository.php`, active and inactive accounts) and batched activity via `FinanceReadQuery::accountActivityFor($dbIds)` (movement `COUNT(*)` and `MAX(occurred_at)` per account in one grouped query; `lastMovementAt: null` for an unused account): a uid owning no account → 404, indistinguishable from an unknown user (no account-ownership oracle); payload per the holder-account summary projection (`id`, `userId`, `currency`, `minorUnit`, `balance`, `active`, `version`, `movementCount`, `lastMovementAt`); no mutation capability exists in the payload. — Depends: T006. Verification: account-list cases in `tests/Integration/Finance/FinanceReadQueryTest.php` plus holder-account endpoint cases in `tests/Contract/Controller/FinanceManagementControllerTest.php`. (FR-008-010, FR-008-011; SC-008-002.)

- [x] T009 Finance routes and controller (holder surface): add the additive Finance routes to `appinfo/routes.php` and create `lib/Controller/FinanceManagementController.php` exposing `GET /finance/holders` and `GET /finance/holders/{holderUid}/accounts`, gated by `FinanceAccess` — a caller without an effective `FINANCE` assignment receives 403 with no holder data; pagination validation (invalid `page`/`pageSize`/over-long `search` → 422 with field errors; empty result → 200 with `items: []`, `total: 0`); unknown holder/account → 404 — and create `lib/Infrastructure/Http/FinancePayloadFormatter.php` as the pure wire mapper (no queries, no authorization decisions, no invented values; composes existing formatters where shapes fit). — Depends: T002, T003, T004, T006, T007, T008. Verification: `scripts/dev/route-settle` and `scripts/dev/route-cache-clear` after the routes change, `scripts/dev/opcache-settle` before any live-HTTP check, then `scripts/dev/phpunit tests/Contract/Controller/FinanceManagementControllerTest.php` (holder endpoints scope) green. (FR-008-001, FR-008-005, FR-008-007.)

- [x] T010 Phase A authorization and contract tests: complete `tests/Contract/Controller/FinanceManagementControllerTest.php` for the holder surface — unauthenticated or non-Finance caller → 403 with no holder identity, account, balance, or movement data in the body; Finance caller → 200 on both reads; unknown holder/account → 404; invalid `page`/`pageSize`, over-long `search` → 422 with field errors; empty page → 200 `items: []`, `total: 0` — together with the holder scope/search integration assertions in `tests/Integration/Finance/FinanceReadQueryTest.php` (holder once; non-holder absent for every term; search complete over the holder population, never page-local). — Depends: T007, T009. Verification: `scripts/dev/phpunit tests/Contract/Controller/FinanceManagementControllerTest.php` and `scripts/dev/phpunit tests/Integration/Finance/FinanceReadQueryTest.php` green. (FR-008-001, FR-008-005, FR-008-006, FR-008-007, FR-008-008; SC-008-001, SC-008-003.)

**Checkpoint**: canonical contract aligned, `src/generated/api.ts` refreshed and drift-free, Finance read primitives plus the holder directory/search/account reads proven by unit, integration, and contract tests. Ledger, completed-history, and all frontend work depend on this phase. No schema change has occurred.

---

## Phase B — Authoritative Ledger Backend

**Purpose**: the unified account ledger as an authoritative projection of `pettycash_account_movements` — persisted posting timestamp, persisted resulting balance, separate recharge/expense monetary sides, movement → revision → transaction → Cost Sheet provenance, sheet-level approved/total counts — composed with bounded set-based queries. Every authoritative posting stays individually represented; no task may group authoritative postings into a synthetic single Cost Sheet financial row.

- [x] T011 [P] [US2] Additive paged movement reads: extend `lib/Domain/Account/Repository/AccountMovementRepository.php` and `lib/Infrastructure/Persistence/Account/DbAccountMovementRepository.php` with `findByAccountIdPaged()` (deterministic order `occurred_at DESC, id DESC`) and `countByAccountId()`; `total` comes from the count query, never the page length; the existing purchaser movement read is untouched. — Depends: T002 (contract alignment). Verification: repository ledger cases in `tests/Integration/Finance/FinanceReadQueryTest.php`: deterministic ordering across timestamp ties; count independent of page slice. (FR-008-012, FR-008-024.)

- [x] T012 [US2] Finance ledger application layer: implement `FinanceLedgerService::ledger(accountId, page, pageSize)` in `lib/Application/Finance/FinanceLedgerService.php` — account resolved by public id (404 when unresolvable), page of movements, and the per-row projection: `occurredAt` = the authoritative persisted movement timestamp (`pettycash_account_movements.occurred_at`), never a Cost Sheet creation date or a transaction expense date; `balanceAfter` = the authoritative persisted `balance_after_minor`, never recomputed; exactly one monetary side non-null (`recharge` for `CHARGE`, `expense` for `CONSUMPTION`, never both, never a misleading amount in the non-applicable side); `approved: null` for a charge. — Depends: T011. Verification: ledger-row cases in `tests/Integration/Finance/FinanceReadQueryTest.php`: row date and balance equal the persisted values; exactly one non-null monetary side per row. (FR-008-012, FR-008-014, FR-008-017, FR-008-018, FR-008-019, FR-008-024; SC-008-005, SC-008-018, SC-008-020.)

- [x] T013 [US2] Movement → revision → transaction → Cost Sheet provenance with server-side bounded enrichment: add `FinanceReadQuery::ledgerContextFor()` in `lib/Infrastructure/Persistence/Finance/FinanceReadQuery.php` — one batched resolution per page of movement → `pettycash_transaction_revisions` → `pettycash_transactions` → `pettycash_sheets`, emitting the sheet public UUID as `reference` (the existing public identifier; no new reference format is invented) and the sheet `title` as the primary consumption description, with the movement's stored description for charges — plus one batched sheet-level count read emitting `approved: {approvedCount, totalCount}` from `pettycash_transactions.final_outcome` for sheet-linked consumption rows; a consumption whose chain does not resolve raises the existing `UnreadableRecordData` data fault (mapped through the existing `OcsErrorMapper`), never an `approved: null` row; the ledger payload contains no rejected-count field. — Depends: T012. Verification: provenance and count cases in `tests/Integration/Finance/FinanceReadQueryTest.php`: consumption row carries sheet id/UUID reference/title and the correct count pair; charge row carries its stored description with `sheet: null`, `approved: null`; a dangling consumption yields the data fault. (FR-008-015, FR-008-016, FR-008-017, FR-008-020; SC-008-008.)

- [x] T014 [US2] Ledger endpoint: add `GET /api/v1/finance/accounts/{accountId}/ledger` to `lib/Controller/FinanceManagementController.php` and the ledger wire shape (account summary + rows + `page`/`pageSize`/`total`) to `lib/Infrastructure/Http/FinancePayloadFormatter.php`, gated by `FinanceAccess` with the common error semantics; the payload carries no action, capability, or mutation field; a negative `balance` or `balanceAfter` is transmitted verbatim — no clamping to zero, no error substitution. — Depends: T009, T013. Verification: ledger endpoint cases in `tests/Contract/Controller/FinanceManagementControllerTest.php` (403/404/422/200 shapes) green after `scripts/dev/opcache-settle`. (FR-008-001, FR-008-012, FR-008-022, FR-008-025; SC-008-006.)

- [x] T015 [US2] Ledger integrity, pagination, and query-budget verification: complete the ledger section of `tests/Integration/Finance/FinanceReadQueryTest.php` — page-2 balances equal the persisted values independently of page 1 (loading another page never recalculates previously displayed historical balances); unrelated financial movements interleaved between one Cost Sheet's postings are preserved in chronological order; each authoritative posting of the same Cost Sheet remains its own row with its own timestamp and resulting balance (no artificial consolidation into one Cost Sheet financial row); negative persisted balances appear verbatim; the per-request query count matches the plan's six-query constant ledger budget (account read + account activity aggregate + movement count + paged movement read + one batched ledger provenance/context read + one batched sheet approved/total count read; no per-row query, no N+1). — Depends: T014. Verification: `scripts/dev/phpunit tests/Integration/Finance/FinanceReadQueryTest.php` green. Plus `tests/Integration/Persistence/FinanceLedgerRoundTripTest.php` proving `queryCount <= 6` on the mixed populated page against real MariaDB via `QueryCountingConnection`, page-size independence, page-2 constancy, and no per-row growth. (FR-008-021, FR-008-022, FR-008-023, FR-008-024, FR-008-028, FR-008-029; SC-008-007, SC-008-016, SC-008-018, SC-008-020.)

**Checkpoint**: the ledger backend answers authoritative dates, balances, monetary sides, provenance, and counts under the Finance gate, proven by contract and integration tests including query-budget and page-boundary cases.

---

## Phase C — Completed History and Evidence

**Purpose**: the read-only completed Cost Sheet projection (list + detail) and the narrowly scoped, content-only Finance evidence-read authorization integrated at the existing evidence content endpoint. Evidence storage is not modified and no parallel evidence API is added.

- [x] T016 [P] [US4] Additive completed-status and batched association reads: extend `lib/Domain/Expense/Repository/ExpenseSheetRepository.php` and `lib/Infrastructure/Persistence/Expense/DbExpenseSheetRepository.php` (page and count of sheets with status `COMPLETED`/`COMPLETED_WITH_REJECTIONS`, ordered `completed_at DESC, id DESC`), `lib/Domain/Expense/Repository/ExpenseTransactionRepository.php` (sheet transaction-status projection), `lib/Domain/Workflow/Repository/WorkflowDecisionRepository.php` (`findByTransactionIds()`), `lib/Domain/Evidence/Repository/EvidenceRepository.php` (`findByTransactionIds()`), and `lib/Infrastructure/Persistence/Expense/SheetSummaryQuery.php` (batched per-sheet total/approved/rejected counts from `final_outcome`; batched actual posted amounts joining movements → revisions → transactions). All additions are additive; existing reads are unchanged. — Depends: T002. Verification: batched-read cases exercised by `tests/Integration/Finance/CompletedSheetHistoryTest.php`. (FR-008-031, FR-008-032, FR-008-035.)

- [x] T017 [US4] Completed Cost Sheet list: implement `CompletedSheetHistoryService::page(page, pageSize)` in `lib/Application/Finance/CompletedSheetHistoryService.php` — only the two terminal sheet statuses; per sheet: `reference` (public UUID), `title`, purchaser `{userId, displayName}` (bounded display-name resolution), total/approved/rejected transaction counts with approved + rejected = total, `postedAmount` summed from the consumption movements actually linked to the sheet and `postingCount` counted from them (never a projection assuming every approved transaction posted; a completion with rejections keeps its honest zero or partial posted amount), `completedAt`, `status`. — Depends: T016. Verification: list cases in `tests/Integration/Finance/CompletedSheetHistoryTest.php`: a sheet awaiting a Finance decision is absent; both completed statuses are listed; counts and posted amount match the authoritative data. (FR-008-032, FR-008-033, FR-008-035; SC-008-011.)

- [x] T018 [US4] Completed Cost Sheet detail with terminality validation: implement `CompletedSheetHistoryService::detail(sheetId)` — 404 unless the sheet is terminal and every transaction is `COMPLETED_APPROVED`/`COMPLETED_REJECTED`, reusing `CompletedSheetScope` as the shared predicate; header carries reference, title, purchaser, counts, actual posted amount, currency/minor unit, account id, and lifecycle timestamps (`createdAt`, `firstSubmittedAt`, `financeStartedAt`, `completedAt`, `status`); each transaction emits reference, revision number, description, expense date (labelled as the expense date — a business date never presented as the posting timestamp), amount/currency, final outcome and status, the latest decision per stage (Cost Center, Senior Manager, final Finance Manager) plus the full chronological decision history, evidence metadata (id, name, media type, size, active, uploader, uploaded timestamp — no content, URL, or token), and `posting {occurredAt, amount, balanceAfter}` from the actually linked movement, `null` for a rejected transaction. — Depends: T005, T016. Verification: detail cases in `tests/Integration/Finance/CompletedSheetHistoryTest.php`: non-terminal sheet → 404; no pending-Finance-review state appears; a rejected transaction has `posting: null`; the posting timestamp is visibly distinct from the expense date. (FR-008-027, FR-008-034, FR-008-036; SC-008-011, SC-008-013.)

- [x] T019 [US4] Completed-history endpoints: add `GET /finance/completed-sheets` and `GET /finance/completed-sheets/{sheetId}` to `lib/Controller/FinanceManagementController.php` with the completed sheet summary/detail wire shapes in `lib/Infrastructure/Http/FinancePayloadFormatter.php`, gated by `FinanceAccess`, same pagination and error semantics as the holder reads; the responses contain no decision, mutation, or capability field. — Depends: T009, T017, T018. Verification: completed-history endpoint cases in `tests/Contract/Controller/FinanceManagementControllerTest.php` green after `scripts/dev/route-settle`, `scripts/dev/route-cache-clear`, and `scripts/dev/opcache-settle`. (FR-008-001, FR-008-032, FR-008-033, FR-008-034, FR-008-038.)

- [x] T020 [P] [US4] Evidence content access — content-only and completed-history-scoped: create `lib/Application/Evidence/EvidenceContentAccess.php`: `mayRead(actorUid, transaction)` returns true when the existing `EvidenceVisibility::mayRead()` rule already admits the actor, otherwise true only when `FinanceAccess` confirms an effective `FINANCE` assignment and `CompletedSheetScope` confirms the transaction/sheet is exposed by completed history; the class has no upload, delete, link, or other mutation method; `EvidenceVisibility` itself and `AuditQueryService` are not modified — and compose it only at the existing content delivery point, `lib/Controller/EvidenceController.php` `content()` action (`GET /evidence/{evidenceId}/content`): no new route, no request/response shape change, out-of-scope callers keep the existing 404 non-disclosure answer, deactivated evidence keeps its existing 403. Register the service in `lib/AppInfo/Application.php`. — Depends: T004, T005. Verification: `scripts/dev/phpunit tests/Unit/Application/Evidence/EvidenceContentAccessTest.php` (predicate unit scope); full contract coverage lands in T021. (FR-008-037; SC-008-013.)

- [x] T021 [US4] Finance evidence-read authorization tests — minimum five cases: add `tests/Unit/Application/Evidence/EvidenceContentAccessTest.php` and `tests/Contract/Controller/EvidenceContentAccessTest.php`: (1) Finance read — an effective Finance Manager receives evidence content for a terminal transaction in a completed sheet exposed by Finance history; (2) non-Finance cross-user denial — a non-Finance user cannot read another user's evidence through this branch; the existing non-disclosure response is returned with no data; (3) no mutation — the Finance Manager cannot upload, replace, delete, deactivate, or otherwise mutate evidence; the upload/delete routes keep their existing guards and refusals; (4) scope denial — evidence for a draft, in-progress, Finance-pending, non-terminal-transaction, or otherwise out-of-scope sheet is refused even when the evidence id is known; (5) existing behavior unchanged — purchaser, Cost Center Manager, and Senior Manager evidence reads return exactly their previous results — plus `tests/Unit/Application/Audit/AuditQueryServiceVisibilityTest.php` proving audit/revision evidence visibility is unchanged. — Depends: T020. Verification: `scripts/dev/phpunit tests/Contract/Controller/EvidenceContentAccessTest.php`, `scripts/dev/phpunit tests/Unit/Application/Evidence/EvidenceContentAccessTest.php`, `scripts/dev/phpunit tests/Unit/Application/Audit/AuditQueryServiceVisibilityTest.php` green. (FR-008-037; SC-008-013.)

- [x] T022 [US4] Completed-history integration verification: complete `tests/Integration/Finance/CompletedSheetHistoryTest.php` — only fully completed Cost Sheets with terminal Finance outcomes appear (a completion with rejections included, with its honest zero/partial approved count and actual posted amount); a Cost Sheet with any transaction still awaiting a Finance decision is absent from list and detail; detail refuses a non-terminal sheet with 404; every transaction shows its Cost Center, Senior Manager, and final Finance Manager decisions and its financial posting information; each authoritative posting of a multi-posting Cost Sheet keeps its own timestamp, amount, and resulting balance with no artificial consolidation; the payload exposes no decision or mutation path. — Depends: T019. Verification: `scripts/dev/phpunit tests/Integration/Finance/CompletedSheetHistoryTest.php` green. (FR-008-003, FR-008-026, FR-008-028, FR-008-029, FR-008-030, FR-008-031, FR-008-032, FR-008-036, FR-008-038; SC-008-011, SC-008-012, SC-008-016.)

**Checkpoint**: completed history is a read-only terminal-only projection and the evidence-content extension is proven content-only, Finance-scoped, and completed-history-scoped, with existing evidence and audit behavior unchanged.

---

## Phase D — Frontend Data Layer and Recharge

**Purpose**: the typed Finance API client, Finance-specific store slices with explicit states, the bounded amount parser, and the recharge flow (draft → review → explicit confirmation) over the existing charge write.

- [x] T023 [P] Additive Finance frontend types: add the Finance projection types to `src/types/api.ts` per `data-model.md` (holder summary, holder account summary, ledger row/page, completed sheet summary/detail, decision, evidence metadata), aligned with the regenerated `src/generated/api.ts`; additive only — no existing type is edited. — Depends: T002. Verification: `npm run typecheck` clean.

- [x] T024 Typed Finance API client: create `src/services/financeApi.ts` with `listHolders({ page, pageSize, search })`, `listHolderAccounts(holderUid, { page, pageSize })`, `fetchLedger(accountId, { page, pageSize })`, `listCompletedSheets({ page, pageSize })`, `getCompletedSheet(sheetId)`; the recharge reuses `src/services/accountsApi.ts` `chargeAccount` unchanged — same `AccountCharge` body (`amountMinor`, `description`, `occurredAt`, `expectedVersion`) and the existing `X-PettyCash-Command-ID` header; no parallel write client, no second recharge endpoint. — Depends: T023. Verification: `npm run typecheck` clean; client behavior covered by the component suites in Phase F. (FR-008-002, FR-008-046.)

- [x] T025 Finance store and per-surface state: create `src/stores/finance.ts` with independent slices — holders (page/search/loading/error), `selectedHolder` + holder accounts (page/loading/error), `selectedAccount` + ledger (page/items/total/loading/error), completed sheet list (page/loading/error), completed sheet detail (loading/error); every slice exposes explicit loading, empty, denied, and error states, resets its error before a request, and keeps the last successful page visible during a retry; no slice stores a derived or locally computed balance. — Depends: T024. Verification: store coverage inside `tests/Frontend/Component/Finance/` (Phase F); `npm run typecheck` clean. (FR-008-050, FR-008-051; SC-008-019.)

- [x] T026 [P] Bounded amount parser: create `src/utils/moneyInput.ts` — major-unit → minor-unit conversion for the recharge draft: positive only, at most `minorUnit` fraction digits, safe-integer result, explicit rejection reasons; a client-side usability aid only — the server validation stays authoritative. — Verification: parser unit tests under `tests/Frontend/` (targeted Vitest) pass. (FR-008-040.)

- [x] T027 [US3] Recharge flow over the existing write: create `src/components/finance/RechargeDialog.vue` with its draft/review state in `src/stores/finance.ts` — steps amount → review → submitting → success/failure/unknown; the review shows holder, destination account, account currency (read-only, never independently changeable, no conversion offered), the formatted amount, the current balance, and the description; no request is sent before the explicit confirm; the confirm control is disabled while submitting (duplicate-submit prevention); one `commandId` UUID per intended recharge, reused for an unknown-result retry and replaced only for a new intended recharge after a definitive failure; `expectedVersion` from the freshly loaded account payload; success and the resulting balance are shown only from the authoritative 201 response (`accountMutationResult.account.balance` / `movement.balanceAfter`), never from a locally computed sum; a failure or unknown result never renders as success and never shows a resulting balance as though the operation succeeded; after success the holder accounts and the ledger first page refresh from the server. — Depends: T025, T026. Verification: recharge state-machine tests in `tests/Frontend/Component/Finance/` (Phase F) plus the E2E journey in T046. (FR-008-002, FR-008-039, FR-008-040, FR-008-041, FR-008-042, FR-008-043, FR-008-044, FR-008-046, FR-008-051; SC-008-009.)

**Checkpoint**: the frontend can read every Finance surface through typed clients and recharge through the existing financial write with deliberate confirmation and honest outcomes.

---

## Phase E — Finance Manager UI

**Purpose**: the Finance module surfaces inside the existing Nextcloud shell — module route/navigation, searchable holder directory, holder account list, six-column ledger, completed Cost Sheet read-only detail with evidence viewing, owed-balance presentation — localized in English and Persian, RTL/LTR-safe, and accessible. The global Nextcloud navigation is not redesigned, and no redundant Open Sheet button is added.

- [x] T028 Finance module route and navigation: add `/finance/holders`, `/finance/completed-sheets`, and `/finance/completed-sheets/:sheetId` to `src/router/index.ts` and one Finance-only entry to `src/types/navigation.ts` visible with an effective `FINANCE` role; holder/account/page selection is reflected in query parameters so refresh and back/forward preserve context; the existing Nextcloud application shell, breadcrumbs, direction handling, and navigation behavior are preserved. — Verification: route-name integrity and navigation-visibility tests (the existing `tests/Frontend/routeNameIntegrity.spec.ts` pattern) pass; `npm run typecheck` clean. (FR-008-049; SC-008-015.)

- [x] T029 [US1] Searchable holder directory surface: create `src/views/finance/FinanceAccountsView.vue` and `src/components/finance/HolderDirectory.vue` — a search input (display name or UID) served by the server-side holder-population search, refresh, and pagination; rows show display name, uid, account count, active-account count, and status; explicit loading, error, and empty states; selecting a holder loads its account list and updates the query string. — Depends: T025, T028. Verification: directory component tests in `tests/Frontend/Component/Finance/`. (FR-008-001, FR-008-006, FR-008-008, FR-008-009, FR-008-050; SC-008-001, SC-008-019.)

- [x] T030 [US1] Holder account list surface: create `src/components/finance/HolderAccountList.vue` — per account: identifier (public UUID), currency, current balance (via the owed-aware balance component), active/inactive status, movement count, last movement timestamp (explicit absence marker when none); the Recharge action exists only for active accounts and is disabled with the reason for inactive ones; no other mutation control exists on the surface; loading, error, and empty states. — Depends: T029. Verification: account-list component tests in `tests/Frontend/Component/Finance/`. (FR-008-010, FR-008-011, FR-008-042, FR-008-050; SC-008-002, SC-008-017, SC-008-019.)

- [x] T031 [US2] Account ledger table: create `src/components/finance/AccountLedgerTable.vue` — exactly six user-visible columns in this order: **Date**, **Description**, **Approved**, **Recharge**, **Expense**, **Balance**; Date renders `occurredAt` via the existing `formatSheetDateTime` (no Cost Sheet creation date or expense date substitution); Description shows the Cost Sheet title and its public-UUID reference for consumption rows and the stored description for recharges; Approved renders the localized `{approvedCount} of {totalCount}` for a consumption and the localized not-applicable marker for a recharge; exactly one of Recharge/Expense holds a monetary figure per row; every Balance cell renders the server-provided `balanceAfter`, and page changes reload from the server — never a client-side running sum, and previously displayed historical balances are never recalculated from partial client data; the Cost Sheet reference interaction opens the read-only completed-sheet detail for that sheet; no mutation control exists anywhere in the ledger; loading, error, and empty states. — Depends: T030. Verification: ledger component tests in `tests/Frontend/Component/Finance/` asserting the exact column set and order and the single-sided amount rule. (FR-008-012, FR-008-013, FR-008-014, FR-008-015, FR-008-016, FR-008-017, FR-008-018, FR-008-019, FR-008-020, FR-008-023, FR-008-025, FR-008-050; SC-008-005, SC-008-006, SC-008-007, SC-008-008, SC-008-018.)

- [x] T032 [P] [US2] Owed-balance presentation: create `src/components/finance/BalanceAmount.vue` — a negative balance renders as its magnitude plus the approved localized label (English `Amount Owed to Petty Cash Holder`, Persian `طلب تنخواه‌دار`), never as an error, a zero, or an unexplained negative number; used by the account header and every ledger balance cell; non-negative values render normally via the existing `formatMoney`. — Depends: T023. Verification: component tests in `tests/Frontend/Component/Finance/` covering both languages and both signs. (FR-008-022, FR-008-051; SC-008-010, SC-008-020.)

- [x] T033 [P] [US4] Completed Cost Sheet list surface: create `src/views/finance/CompletedSheetsView.vue` and `src/components/finance/CompletedSheetTable.vue` — columns reference (public UUID), title, purchaser, total/approved/rejected counts, actual posted amount, completed date; pagination with explicit loading, error, and empty states; row activation opens the read-only detail route; the surface is strictly read-only — no decision or historical mutation control. — Depends: T025, T028. Verification: list component tests in `tests/Frontend/Component/Finance/`. (FR-008-032, FR-008-033, FR-008-038, FR-008-050; SC-008-011, SC-008-012, SC-008-019.)

- [x] T034 [US4] Completed Cost Sheet read-only detail surface: create `src/views/finance/CompletedSheetDetailView.vue` and `src/components/finance/CompletedSheetDetail.vue` — header (reference, title, purchaser, counts, actual posted amount, completion date) and transaction table (reference, description, expense date labelled distinctly from the posting timestamp, amount/currency, Cost Center decision, Senior Manager decision, final Finance Manager decision, evidence metadata, posting timestamp/amount/resulting balance); decisions render display-only — no Approve, Reject, Return for Correction, decision-editing, or other mutation control exists; posted values come from the detail payload and are never recomputed from transaction amounts; loading and error states; this detail route is the read-only modal/detail target reached from the ledger's Cost Sheet reference interaction. — Depends: T033. Verification: detail component tests in `tests/Frontend/Component/Finance/` asserting the read-only DOM (no decision controls). (FR-008-003, FR-008-034, FR-008-036, FR-008-038, FR-008-051; SC-008-012, SC-008-013.)

- [x] T035 [US4] Evidence viewing in completed history: reuse `src/components/evidence/EvidencePreview.vue` / `src/components/review/EvidenceCard.vue` with the existing content endpoint under the new authorization — evidence metadata list per transaction, read-only content preview through the existing delivery path, no upload, replacement, deletion, deactivation, or link-creation control anywhere in the module, no fabricated content; the preview overlay restores focus to its originating control on close. — Depends: T020, T034. Verification: evidence-view component tests in `tests/Frontend/Component/Finance/`. (FR-008-037, FR-008-038; SC-008-013.)

- [x] T036 [US5] Localization — English primary, Persian complete: add every new user-facing key to `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js` (module title and classification note, directory/search labels, holder fields, account fields, the six ledger column headings, the approved `of` pattern, the not-applicable marker, the owed label English `Amount Owed to Petty Cash Holder` / Persian `طلب تنخواه‌دار`, recharge steps/review/confirm and all outcome states, completed-history labels, loading/error/empty texts); every string goes through `t('pettycash', ...)`; no hardcoded user-facing text. — Depends: T027, T029–T035. Verification: `tests/Frontend/l10nCatalog.spec.ts` and the EN/FA key-parity gate pass. (FR-008-022, FR-008-047; SC-008-010, SC-008-014.)

- [x] T037 [US5] RTL/LTR-safe layout, responsiveness, and accessibility: logical CSS properties (`margin-inline`, `padding-inline`, `inset-inline`) and direction from `src/utils/direction.ts` (`:dir="direction"`, no hardcoded `rtl`); tables remain usable at the approved responsive breakpoints (stacked rows retain every column label and value, including the Approved/Recharge/Expense distinction); semantic table markup with header scope and captions, labelled inputs, focus-visible states; keyboard operability for the recharge dialog and the evidence overlay (Escape close, focus trap while open, focus restoration to the originating control); `aria-live` announcements for async errors and success. — Depends: T036. Verification: direction/keyboard/focus assertions in `tests/Frontend/Component/Finance/` plus the responsive checks in the E2E pass (T046). (FR-008-048, FR-008-050; SC-008-014, SC-008-019.)

**Checkpoint**: the Finance module is fully usable in English and Persian, with honest states, read-only history, owed-aware balances, and accessible dialogs inside the existing shell.

---

## Phase F — Verification and Owner-Gate Preparation

**Purpose**: cross-layer verification, existing-behavior regressions, the targeted Chrome journey, visual evidence, documentation consistency, and the explicit manual owner gate. Nothing in this phase substitutes for owner approval; no automated check, test pass, or screenshot completes the owner gate.

- [x] T038 Contract, route, and controller consistency: `npm run check:openapi` green (no canonical-contract drift); every Finance route in `appinfo/routes.php` matches the canonical contract paths and query parameters exactly (`page`, `pageSize`, `search` present only where the contract declares them); the controller methods accept exactly the contracted parameters; route-cache and opcode handling per the wrapper rules (`scripts/dev/route-settle`, `scripts/dev/route-cache-clear`, `scripts/dev/opcache-settle`) before any live-HTTP evidence. — Depends: T014, T019. Verification: exit-0 checks plus a reviewed route/contract matrix. (FR-008-004 at the route layer.)

- [x] T039 Backend unit suites: complete `tests/Unit/Application/Finance/` and run it green via `scripts/dev/phpunit tests/Unit/Application/Finance` — `FinanceAccessTest` (effective-assignment gate), `CompletedSheetScopeTest` (terminal predicate), holder status derivation (`ACTIVE`/`INACTIVE`/`MIXED`), ledger row formatting (single monetary side, `approved: null` for a charge, persisted date/balance passthrough). — Depends: Phases A–C. Verification: targeted `scripts/dev/phpunit` run green. (FR-008-005, FR-008-009, FR-008-016, FR-008-017, FR-008-018, FR-008-019.)

- [x] T040 Backend integration suites: `scripts/dev/phpunit tests/Integration/Finance/FinanceReadQueryTest.php` and `scripts/dev/phpunit tests/Integration/Finance/CompletedSheetHistoryTest.php` green in full — directory scope and population-wide search, account listing, ledger integrity/pagination/query budget, completed list/detail terminality and posted amounts. — Depends: T015, T022. Verification: both targeted runs green.

- [x] T041 Backend contract suites: `scripts/dev/phpunit tests/Contract/Controller/FinanceManagementControllerTest.php` and `scripts/dev/phpunit tests/Contract/Controller/EvidenceContentAccessTest.php` green in full (all five Finance reads plus the evidence-content authorization matrix). — Depends: T010, T014, T019, T021. Verification: both targeted runs green.

- [x] T042 Existing-behavior regressions, all via `scripts/dev/phpunit` on the named existing suites with no assertion weakened or deleted: purchaser ownership regression — the existing account controller/charge suites and the purchaser own-account flow are unchanged, and a purchaser cannot reach another user's holder record or accounts through the Finance module (FR-008-004; SC-008-004); recharge regression — the existing charge suite proves the actor gate, cross-user allowance, inactive refusal, optimistic version guard, idempotent replay (one movement, one balance change, one audit event for a repeated command id), and audit behavior unchanged (FR-008-042, FR-008-043, FR-008-045, FR-008-046; SC-008-017); Finance settlement regression — the existing workflow/settlement suites prove approved transactions still post individually and immediately under existing financial rules, with Cost Sheet completion remaining the separate lifecycle event (FR-008-026, FR-008-027, FR-008-028, FR-008-029, FR-008-030); Cost Center and Senior Manager regression — the existing review suites are unchanged; evidence authorization regression — the existing evidence-visibility suites pass unchanged beside the T021 additions. — Depends: Phases A–C. Verification: the named existing suites green.

- [x] T043 Frontend component/unit suites: `npm test -- --run tests/Frontend/Component/Finance` green — holder directory (search/pagination/states), account list (fields, recharge gating by active status, no mutation control), ledger (exact six columns and order, Approved `of`/not-applicable rendering, single-sided amounts, server balances preserved across page changes, owed label in both languages), recharge dialog (review contents, confirm gating, duplicate-submit prevention, failure and unknown states, no success before the 201, post-success refresh), completed list/detail read-only, evidence read-only preview — plus `npm run typecheck`, targeted ESLint and Stylelint. No frontend test asserts a fabricated financial value. — Depends: Phase E. Verification: the targeted Vitest/lint/typecheck runs green. (SC-008-001…013, SC-008-018, SC-008-020 at the UI layer.)

- [x] T044 [US5] Localization parity: `tests/Frontend/l10nCatalog.spec.ts` and the EN/FA key-parity gate green for every Feature 008 key across `l10n/en.json`, `l10n/en.js`, `l10n/fa.json`, `l10n/fa.js`; spot-check rendered strings in both catalogs, including the owed label pair and the not-applicable marker. — Depends: T036. Verification: targeted Vitest green. (FR-008-047; SC-008-010, SC-008-014.)

- [x] T045 Accessibility checks: explicit assertions in `tests/Frontend/Component/Finance/` for keyboard interaction (dialog open/confirm/cancel/Escape), focus trap and focus restoration for the recharge dialog and the evidence overlay, accessible names/roles/labels, `aria-live` announcements for async errors/success, and focus-visible styling; reviewed again in the E2E pass. — Depends: T037. Verification: targeted Vitest green. (FR-008-050; SC-008-019.)

- [x] T046 Targeted Chrome Playwright journey: add `tests/E2E/finance-manager.spec.ts` and run `npx playwright test tests/E2E/finance-manager.spec.ts --project=chrome-desktop` in official Google Chrome. E2E framework: Playwright; fixture/reset: `tests/E2E/seed-e2e-fixtures.php` with `pettycash-e2e` principal (FINANCE role). Holder discovery and display-name search, account inspection, ledger paging with unchanged previously displayed balances, recharge confirmation/duplicate-submission/unknown-outcome paths, completed history read-only with evidence preview, and English/Persian direction switching inside the existing shell, at the approved desktop viewport and the approved responsive viewport. — Depends: T040, T041, T042, T043, T044, T045. Verification: the targeted E2E run green with report. (SC-008-001…020 at the journey layer.)

- [x] T047 [P] English desktop visual evidence: capture screenshots with official Google Chrome at the approved desktop viewport and store them under `artifacts/visual-review/<task>/<iteration>/` with repository-relative citations — holder directory with search, account list, ledger (six columns, including a negative owed balance when obtainable), recharge review/confirm/success, completed list/detail, evidence preview. — Depends: T046. Verification: the evidence files exist at the cited paths. (SC-008-019; constitution visual-evidence rule.)

- [x] T048 [P] [US5] Persian localization/direction evidence: capture Persian RTL screenshots with official Google Chrome at the approved desktop and responsive viewports under `artifacts/visual-review/<task>/<iteration>/` — the same surfaces with RTL layout via logical properties, the localized owed label `طلب تنخواه‌دار`, localized Approved/not-applicable markers, and no broken direction. — Depends: T046. Verification: the evidence files exist at the cited paths. (FR-008-047, FR-008-048; SC-008-010, SC-008-014.)

- [x] T049 Feature 008 documentation and contract consistency review: verify `tasks.md` ↔ `spec.md` ↔ `plan.md` ↔ `contracts/finance-manager-api.md` ↔ canonical `specs/001-pettycash-v1-core/contracts/ocs-api.yaml` alignment (the five Finance paths present, the evidence-content condition recorded, no drift), and record the delivery report under `docs/reports/` per AGENTS.md §11/§12, including the code-intelligence evidence line and the exact checks executed versus deferred. — Depends: T038–T048. Verification: the report exists and cites executed evidence only.

- [ ] T050 Owner visual acceptance gate — **manual and owner-controlled**: stop and present the review URL, the repository-relative screenshot paths from T047/T048, the executed checks with their results, supported and unavailable functionality, and open blockers; approval is recorded only by the owner in the ledger with the gate identifier, date, and authorized scope. No automated check, test pass, or screenshot may mark this task complete, and no later work starts before the recorded approval. — Depends: T047, T048, T049. (Constitution §V/§VII; SC-008 gate for all surfaces.)

**Checkpoint**: all verification layers executed and evidenced; the feature waits at the owner gate.

---

## Dependencies & Execution Order

### Phase dependencies

- **Phase A blocks every public route**: T001 → T002 → T003 is the contract/generated-source/validation chain and must complete before T009 and every later endpoint task (T014, T019); T004/T005 are the shared primitives for B/C gates.
- **Phase B** depends on A (ledger reads build on the aligned contract and the controller/formatter skeleton of T009).
- **Phase C** depends on A (T005 feeds T018/T020; T009 feeds T019); within C, T016 and T020 are mutually parallel.
- **Phase D** depends on T002 (types from the regenerated source); it is backend-independent except for E2E, so it may interleave with B/C.
- **Phase E** depends on D (store/client/dialog exist; T029 and T033 consume the T025 Finance store); T035 additionally depends on T020.
- **Phase F** depends on the completing phases per task: T038–T042 on A–C, T043–T045 on E, T046 on T040–T045, T047/T048 on T046, T049 on T038–T048, T050 last.

### Key prerequisite edges

- T001 → T002 → T003 → T009 (contract alignment precedes public routes; not a product phase).
- T004 → T009/T014/T019 (Finance gate) and T004 + T005 → T020 (evidence branch).
- T006 → T007, T006 → T008, {T004, T006, T007, T008} → T009 → T010.
- T011 → T012 → T013 → T014 → T015 (ledger chain).
- T016 → T017, {T005, T016} → T018, {T009, T017, T018} → T019 → T022; T020 → T021.
- T023 → T024 → T025, {T025, T026} → T027 (recharge).
- {T025, T028} → T029 → T030 → T031; {T025, T028} → T033 → T034 → T035; T023 → T032; {T027, T029–T035} → T036 → T037.
- {T014, T019} → T038; {T010, T014, T019, T021} → T041; T040–T045 → T046 → {T047, T048} → T050; T049 → T050.

### Parallel opportunities

- Phase A: T004 ∥ T005 ∥ T006 (different files); then T007 and T008 may proceed together once T006 lands (different concerns, different methods — sequence within one service file if staffed singly).
- Phase B start: T011 is parallel with Phase C's T016 (different files) and with Phase D's T023/T026.
- Phase C: T016 ∥ T020; then T017/T018 within `CompletedSheetHistoryService.php` are sequenced.
- Phase E: T032 ∥ T033 ∥ the T029→T031 chain (different files).
- Phase F: T047 ∥ T048 (independent evidence captures).

## Implementation Strategy

### MVP first (US1)

1. Complete Phase A (T001–T010). 2. Validate the holder journey end-to-end (contract + integration tests; SC-008-001/002/003). 3. Stop at the recorded owner checkpoint before expanding.

### Incremental delivery

1. Phase A → holder directory + accounts (US1). 2. Phase B → ledger (US2). 3. Phase D + T027/T030 → recharge (US3). 4. Phase C → completed history + evidence (US4). 5. Phase E polish + Phase F verification → EN/FA + accessibility (US5). Each increment keeps the previous stories independently testable; every increment ends at the owner gate semantics of T050.

## Notes

- Implementation of any task requires the owner's separate authorization; this ledger authorizes no code change by itself.
- Preserve all pre-existing working-tree changes; no Git stage/commit/push/reset/clean is authorized by this ledger.
- A discovered need for a schema change, migration, or new index stops the affected task and returns to the owner; it is never absorbed silently.
- A discovered need to change purchaser-owned reads, the existing charge semantics, or `EvidenceVisibility`/audit visibility stops the affected task and returns to the owner.
- Visual evidence uses official Google Chrome only; a missing Chrome installation is a reported blocker, never a substitute engine.
- Prefer affected-area checks per task; the agreed broader gates (`npm run check`, full `scripts/dev/phpunit`) run once at the feature boundary, not after every task.

## Out of Scope (do not start)

- Any database schema change, migration, backfill, or new index; any replacement of existing schema objects.
- A new Cost Sheet reference format or reference sequence beyond the existing public UUID.
- Unrestricted Nextcloud user-directory search or enumeration of non-holder users; page-local search presented as holder search.
- A second recharge command, endpoint, service, or response envelope; any change to the existing charge write semantics or its audit behavior.
- Any financial decision action (Approve, Reject, Return for Correction, decision editing) in this module; any mutation of historical decisions, transactions, movements, or completed sheets.
- Artificial consolidation of authoritative movements into one Cost Sheet financial row; any new settlement deferral, batching, or retroactive posting behavior (FR-008-030 guards this).
- Currency conversion or independent recharge-currency selection.
- Evidence upload, replacement, deletion, deactivation, link creation, or any other evidence mutation; a parallel evidence storage, delivery, or download mechanism; any widening of `EvidenceVisibility` or audit/revision visibility.
- Changes to purchaser-owned account behavior; purchaser workflow redesign.
- Mobile-specific surface work (deferred by the specification's Out of Scope); Vuetify or any UI library other than `@nextcloud/vue` 9.x.
- Git stage/commit/push/reset/clean authorized by this ledger.

## Requirement Coverage

Every functional requirement and success criterion has at least one implementation or verification path; the per-task inline tags carry the same mapping.

| Requirement | Task paths |
|---|---|
| FR-008-001 | T009, T010, T014, T019, T029, T031, T033 |
| FR-008-002 | T024, T027, T030 |
| FR-008-003 | T022, T034 |
| FR-008-004 | T003, T011, T038, T042 |
| FR-008-005 | T004, T009, T010, T039 |
| FR-008-006 | T006, T007, T010, T029 |
| FR-008-007 | T009, T010, T042 |
| FR-008-008 | T006, T007, T010, T029 |
| FR-008-009 | T006, T029, T039 |
| FR-008-010 | T008, T030 |
| FR-008-011 | T008, T030 |
| FR-008-012 | T011, T012, T014, T031 |
| FR-008-013 | T031, T043 |
| FR-008-014 | T012, T015, T031 |
| FR-008-015 | T013, T031 |
| FR-008-016 | T013, T031, T039, T043 |
| FR-008-017 | T012, T013, T031 |
| FR-008-018 | T012, T031 |
| FR-008-019 | T012, T031 |
| FR-008-020 | T013, T031, T043 |
| FR-008-021 | T015, T022 |
| FR-008-022 | T014, T015, T032, T036 |
| FR-008-023 | T015, T031, T043 |
| FR-008-024 | T011, T012, T015 |
| FR-008-025 | T014, T031 |
| FR-008-026 | T022, T042 |
| FR-008-027 | T005, T018, T022, T042 |
| FR-008-028 | T015, T022, T042 |
| FR-008-029 | T015, T022, T042 |
| FR-008-030 | T022, T042 |
| FR-008-031 | T016, T017, T022 |
| FR-008-032 | T005, T016, T017, T019, T022, T033 |
| FR-008-033 | T017, T019, T033 |
| FR-008-034 | T018, T019, T034 |
| FR-008-035 | T016, T017, T022 |
| FR-008-036 | T005, T018, T022, T034 |
| FR-008-037 | T020, T021, T035 |
| FR-008-038 | T019, T022, T033, T034, T035 |
| FR-008-039 | T027, T046 |
| FR-008-040 | T026, T027 |
| FR-008-041 | T027 |
| FR-008-042 | T027, T030, T042 |
| FR-008-043 | T027, T042, T046 |
| FR-008-044 | T027, T046 |
| FR-008-045 | T042 |
| FR-008-046 | T024, T027, T042 |
| FR-008-047 | T036, T044, T048 |
| FR-008-048 | T037, T048 |
| FR-008-049 | T028, T046 |
| FR-008-050 | T029, T030, T031, T033, T037, T045 |
| FR-008-051 | T012, T027, T032, T034, T043 |
| SC-008-001 | T006, T007, T010, T029, T046 |
| SC-008-002 | T008, T030 |
| SC-008-003 | T004, T010 |
| SC-008-004 | T042 |
| SC-008-005 | T012, T014, T031 |
| SC-008-006 | T014, T031, T043 |
| SC-008-007 | T015, T031, T046 |
| SC-008-008 | T013, T031, T043 |
| SC-008-009 | T027, T043, T046 |
| SC-008-010 | T032, T036, T044, T048 |
| SC-008-011 | T005, T017, T018, T022, T033 |
| SC-008-012 | T022, T033, T034, T046 |
| SC-008-013 | T018, T021, T034, T035 |
| SC-008-014 | T036, T037, T044, T048 |
| SC-008-015 | T028, T046 |
| SC-008-016 | T015, T022 |
| SC-008-017 | T027, T030, T042 |
| SC-008-018 | T012, T015, T031 |
| SC-008-019 | T029, T033, T037, T045, T047 |
| SC-008-020 | T012, T027, T032, T043 |
