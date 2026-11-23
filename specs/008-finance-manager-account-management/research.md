# Research Notes — Finance Manager Account Management (Phase 0)

**Feature**: `specs/008-finance-manager-account-management/` | **Date**: 2026-11-16 | **Status**: research complete; planning phase only.

## Evidence route used

Per `AGENTS.md` §2, the evidence was classified before exploration:

- **OCI semantic discovery**: **not needed**. Every target was already a known path, symbol, route, or literal from the corrected specification and the project handoff. There was no unknown behavior or location to discover.
- **CGC structural graph**: **not needed**. The feature is additive: it adds new read routes and projection queries and does not rewire an existing call graph. The material call sites (route → controller → service → repository) were verified directly in source; no cross-file caller/callee question could change the design.
- **Serena symbol navigation**: **used** to confirm the exact signatures/locations of the reused write and the authorization predicates: `AccountService::charge()` (`lib/Application/Account/AccountService.php:361-499`), `PettyCashAccount::consume()` (`lib/Domain/Account/PettyCashAccount.php:67-86`), `FinanceSettlementService::recordConsumption()` (`lib/Application/Workflow/FinanceSettlementService.php:538-587`), `EvidenceVisibility::mayRead()` (`lib/Application/Evidence/EvidenceVisibility.php:62-85`), `AuthorizationService::canReview()` (`lib/Application/Security/AuthorizationService.php:33-77`), the `UserDirectory` port (`lib/Application/Port/UserDirectory.php:1-5`), `NextcloudUserDirectory` (`lib/Infrastructure/Nextcloud/NextcloudUserDirectory.php:6`), and `EvidenceController` methods.
- **Context7**: **used** to verify the Nextcloud 34 platform user-search surface. `IUserManager::searchDisplayName(string $pattern, ?int $limit = null, ?int $offset = null)` exists, and `IUserManager::search()` is deprecated in its favour. It was deliberately not adopted because it searches the unrestricted directory; see R3.
- **Source reads (authoritative)**: the PHP, migration, TypeScript, and l10n files cited below.
- **Exact text search**: route names, table/column names, status/outcome values, and i18n keys.
- **Tests**: none executed. This is planning only; no implementation exists to verify. The test strategy is defined in `plan.md` and the commands are repeated in `quickstart.md`.

## Key verified sources (repository-relative, with line anchors)

| Claim | Evidence |
|---|---|
| Purchaser account list is owner-scoped; a `userId` naming another user is refused | `lib/Controller/AccountController.php:40-56` |
| Purchaser movement list is owner-scoped; “not yours” and “missing” are answered identically | `lib/Application/Account/AccountService.php:111-149` |
| Existing charge is cross-user by design, role-gated, version-guarded, inactive-refusing, and idempotent | `lib/Application/Account/AccountService.php:361-499` (actor gate 400-402, cross-user comment 406-410, stale version 412-414, inactive 416-418, idempotent execute 420-499) |
| A movement persists its authoritative timestamp and resulting balance | `lib/Domain/Account/AccountMovement.php:14-34`; `lib/Infrastructure/Persistence/Account/DbAccountMovementRepository.php:99-143` |
| A consumption stores the public revision id but the generic description `Expense consumption` | `lib/Domain/Account/PettyCashAccount.php:67-86`; `lib/Application/Workflow/FinanceSettlementService.php:538-587` |
| Movement reads join revision/decision public ids and raise a data fault for a dangling consumption revision | `lib/Infrastructure/Persistence/Account/DbAccountMovementRepository.php:28-63`, `275-319` |
| Account rows are unique per `(user_uid, currency_id)` and carry `active`, `balance_minor` | `lib/Migration/Version010000Date20260920000000.php:68-78` |
| Movement rows have `occurred_at`, `balance_after_minor`, `source_revision_id`, and an `(account_id, occurred_at)` index | `lib/Migration/Version010000Date20260920000000.php:79-96` |
| Sheets store `status`, `completed_at`, and a `(owner_uid, status, created_at)` index | `lib/Migration/Version010100Date20260920000000.php:15-28` |
| Transactions store `final_outcome` (`APPROVED` / `REJECTED` / `NULL`) | `lib/Migration/Version010100Date20260920000000.php:31-40`; `lib/Domain/Expense/ExpenseTransaction.php:140-200` |
| Sheet completion is recalculated only when every transaction is terminal | `lib/Domain/Expense/ExpenseSheet.php:156-208` |
| The sheet aggregate has no separate business-reference field | `lib/Domain/Expense/ExpenseSheet.php:13-31`; sheet migration above |
| Existing Finance queue/senior sheet reads are additive precedents | `lib/Application/Workflow/ManagementReviewService.php:437-718`; `appinfo/routes.php:52-63` |
| Finance evidence content at `FINAL` is not admitted by the current rule | `lib/Application/Evidence/EvidenceVisibility.php:62-85`; `lib/Application/Security/AuthorizationService.php:33-77` |
| The user directory port resolves only known uids; it has no search method | `lib/Application/Port/UserDirectory.php:1-5`; `lib/Infrastructure/Nextcloud/NextcloudUserDirectory.php:6` |
| Evidence content is the only read path to add the Finance branch; upload and delete are separate guarded methods | `lib/Controller/EvidenceController.php:58-160`, `347-440`, `444-544` |
| Audit reads reuse the same evidence visibility rule | `lib/Application/Audit/AuditQueryService.php:51-67` |
| A sheet's completion requires every transaction to be terminal | `lib/Domain/Expense/ExpenseSheet.php:156-208`; `lib/Domain/Workflow/TransactionStatus.php`; `lib/Domain/Workflow/SheetStatus.php` |
| Existing account/finance routes and their HTTP verbs | `appinfo/routes.php:79-89` |
| Existing charge frontend call and its command-id header | `src/services/accountsApi.ts:65-76` |
| Existing funding view composes a charge from a form and a loaded version | `src/views/admin/FundingView.vue:40-70` |
| Existing account view renders movements but has no recharge control | `src/views/accounts/AccountView.vue:91-180`, `195-240` |
| Existing Finance routes are queue/review only; no holder or history route exists | `src/router/index.ts:13-89` |
| Navigation entries are role-predicated and route names are checked | `src/types/navigation.ts:72-81`; `tests/Frontend/routeNameIntegrity.spec.ts` |
| Money/date/direction helpers are shared and payload-driven | `src/utils/money.ts:21-29`; `src/utils/date.ts:103-123`; `src/utils/direction.ts:40-50` |
| The account payload carries balance, active, and version but not currency/activity | `lib/Application/Account/AccountPayloadFormatter.php:54-78`; `src/types/api.ts:636-644` |
| The l10n catalogs are `en.json/en.js` and `fa.json/fa.js` | `tests/Frontend/l10nCatalog.spec.ts:1-20` |

## Decisions

### R1 — Use a Finance-scoped read surface, not widened purchaser endpoints

**Decision**: Add `GET /api/v1/finance/holders`, `/holders/{holderUid}/accounts`, `/accounts/{accountId}/ledger`, `/completed-sheets`, and `/completed-sheets/{sheetId}`, all gated by an effective `FINANCE` assignment.

**Rationale**: The existing owner-only reads encode the purchaser privacy boundary in their shape, not just in a branch. Keeping that shape and adding a separate role-gated surface makes FR-008-004/005/007 structural. It also lets the Finance responses carry the fields the module needs (currency, activity, approved counts, posting detail) without changing the purchaser contract.

**Alternatives considered**: (a) add `userId`/`accountId` parameters to the purchaser reads and branch on role — rejected because it makes authorization depend on a request field and risks the purchaser regression the spec forbids; (b) reuse the reports endpoint — rejected because reports are a different projection, are administrator-oriented, and do not expose the required holder/ledger/history shapes.

### R2 — Derive holders from `pettycash_accounts`, never from the directory

**Decision**: Group `pettycash_accounts` by `user_uid`; resolve display names only for uids already selected from that table through the existing `UserDirectory::displayName()` port.

**Rationale**: FR-008-006 forbids unrestricted directory enumeration. The account table is the authoritative definition of a holder (“owns at least one petty-cash account”). A grouped query uses the existing `(user_uid, currency_id)` index prefix. Display-name resolution for known holder uids matches the existing `SheetPayloadFormatter::displayNameOf()` precedent and cannot disclose a non-holder.

**Alternatives considered**: `IUserManager::searchDisplayName()` / `IUserManager::search()` or a general user picker — rejected as directory-wide searches that would have to be intersected with the holder set; a `user` table join — rejected because account ownership is the definition and the app already treats the directory as a display-name resolver, not a list source.

### R3 — Holder search matches display name or user ID over the complete holder population

**Decision**: `search` is a trimmed, ≤64-character, case-insensitive substring. With no term, use the paged grouped read and resolve names for that page. With a term, read the complete grouped holder population (`user_uid`, `COUNT(*)`, `SUM(active)`), resolve display names for those uids with a per-request cache, match on `userId` or `displayName`, sort by `userId`, then slice the requested page. `total` is the filtered holder count. The search is server-side and never searches only the loaded page.

**Rationale**: The approved product requirement is a searchable directory of people who hold petty-cash accounts, using the user-facing identity. Restricting display-name resolution to the account-owning uids preserves FR-008-006 and cannot enumerate non-holders. The platform's `IUserManager::searchDisplayName()` searches the unrestricted directory and would require intersecting (and potentially truncating) a directory-wide result, so it is not used. The search is complete over holders and bounded by H, the number of petty-cash holders (<100 at the documented V1 scale).

**Alternatives considered**: UID-only search — rejected as insufficient for the approved UX; Nextcloud `searchDisplayName()`/`search()` — rejected as unrestricted directory search; filtering the currently loaded page — rejected as incomplete and misleading; loading all holders into the browser — rejected as unbounded and a second disclosure surface.

**Scale note**: the search path is O(H) in display-name resolutions at V1 scale. A materially larger holder population would need an indexed display-name projection, which is a separately authorized schema/design change (R15).

### R4 — Finance authorization is an effective `FINANCE` assignment

**Decision**: A dedicated `FinanceAccess` checks active, effective `Role::Finance` assignments. It does not treat platform-administrator status as Finance authority and does not use `ReportScope::seesOthers()` (administrator-only).

**Rationale**: FR-008-005 requires Finance authorization. Existing finance decision powers already require an assignment row (the code comment in `AccountService::hasRole()` states that an admin-alone caller gains no finance decision power). The read surface follows that rule. The recharge write keeps its existing gate, which also admits Administrators for funding; that is a preserved write behavior, not a read authorization.

**Alternatives considered**: reuse `ReportScope` — rejected because its `seesOthers()` is administrator-only and its cost-centre scope is irrelevant to Finance; allow administrators implicitly — rejected because it would widen the module beyond its stated role.

### R5 — Ledger date is the persisted movement timestamp

**Decision**: Emit `movement.occurredAt` as the ledger Date. Never substitute `sheet.createdAt`, `revision.expenseDate`, or a display date.

**Rationale**: FR-008-014/018/SC-008-018. The movement aggregate is the accounting record; its timestamp is the only authoritative posting time. `AccountPayloadFormatter::movement()` already reads it (`lib/Application/Account/AccountPayloadFormatter.php:82-104`).

**Alternatives considered**: expense date — rejected as a different business date; sheet completion date — rejected because postings may occur at different times (FR-008-028).

### R6 — Balance is `balance_after_minor`, never a page sum

**Decision**: Every ledger row carries the movement's `balanceAfter`; the frontend renders it verbatim. No client-side running balance.

**Rationale**: FR-008-019/023/024. The repository persists the resulting balance and the aggregate carries it. A page sum cannot survive pagination or unrelated interleaved movements.

**Alternatives considered**: compute balances from the current account balance and reverse-sum the page — rejected as fragile, page-dependent, and a second source of truth.

### R7 — The six columns are a wire/UI invariant

**Decision**: The ledger row exposes `occurredAt`, `description`, `approved` (pair or null), `recharge` (Money or null), `expense` (Money or null), and `balanceAfter`. The UI renders exactly those six user-visible columns in the approved order. `type` is retained for semantics/styling but is not a column.

**Rationale**: FR-008-013/016/017/018/020. Explicit nullable monetary sides make it structurally impossible to show a charge in the Expense column or vice versa. A separate `type`-only amount would push the mapping back into the frontend.

**Alternatives considered**: one signed amount plus a type column — rejected because the specification requires separate Recharge/Expense columns and no movement-type column; client-side mapping of `type` to a column — rejected because it duplicates a financial presentation rule in the view.

### R8 — Description and reference are resolved from the sheet server-side

**Decision**: For a consumption, resolve revision → transaction → sheet and emit the sheet title plus its public UUID as the reference. Fall back to the movement description only when no sheet resolves. For a charge, emit the stored charge description.

**Rationale**: FR-008-015. The stored consumption description is generic. Server-side batching avoids a per-row request. The public UUID is the only authoritative stable reference; the schema has no business-reference column. Inventing a sequence would violate FR-008-051.

**Alternatives considered**: derive a formatted `PC-...` reference — rejected as fabricated; use the transaction description only — rejected because FR-008-015 asks for the sheet reference/title.

### R9 — Approved counts are sheet-level `final_outcome` counts

**Decision**: For a sheet-linked ledger row, emit `{approvedCount, totalCount}` from a batched count over the sheet's transactions (`final_outcome = 'APPROVED'` vs all rows). Recharge rows emit `null`, rendered as the localized “not applicable”. Do not add a rejected-count column.

**Rationale**: FR-008-016/017/020 and SC-008-008. `final_outcome` is the authoritative terminal outcome and the count is independent of the loaded ledger page. The count is current sheet state, which is the specification's wording.

**Alternatives considered**: count only ledger rows on the page — rejected as page-dependent; use amount totals — rejected because the requirement is a transaction count; add a rejected column — explicitly forbidden.

### R10 — Pagination is server-side, deterministic, and bounded

**Decision**: `page`/`pageSize` on every list; `pageSize` 1–100; ledger order `occurred_at DESC, id DESC`; completed order `completed_at DESC, id DESC`; holder/account order `user_uid`/`currency` ascending. `total` is a count query, not the page length.

**Rationale**: FR-008-023/024 and the repository's existing pagination conventions. A tie-break on `id` prevents a row from appearing twice or disappearing across pages when timestamps tie. A count query makes `total` honest.

**Alternatives considered**: load-all-then-slice — rejected for unbounded growth; timestamp-only ordering — rejected because ties break page stability.

### R11 — Completed history filters terminal statuses and rechecks terminality

**Decision**: List sheets with `COMPLETED` or `COMPLETED_WITH_REJECTIONS`; detail 404s unless the sheet is terminal and every transaction is `CompletedApproved`/`CompletedRejected`.

**Rationale**: FR-008-032/036 and SC-008-011. `ExpenseSheet::recalculateStatusFrom()` only sets those statuses when all transactions are terminal, but a defensive detail check prevents a stale/corrupt status from exposing a pending Finance state as history.

**Alternatives considered**: trust the sheet status alone — rejected because a projection over potentially stale state should verify the invariant it claims; include `FINANCE` sheets with no pending rows — rejected because the specification requires final completion, not “nearly complete”.

### R12 — Actual posted amount is derived from settled movements

**Decision**: Sum the consumption movements linked to the sheet's revisions/transactions; count them; do not assume every approved transaction posted.

**Rationale**: FR-008-031/035. A rejected transaction has no movement; a posting is the authoritative financial effect. The ledger and history must agree, and no artificial consolidation is allowed.

**Alternatives considered**: sum approved transaction amounts — rejected because it assumes posting and can disagree with the ledger; sum the sheet's `effectiveApproved` total — rejected as a projection of intent, not actual postings.

### R13 — Recharge reuses the existing charge command and its idempotency

**Decision**: Two-step UI over `POST /accounts/{accountId}/charges`; one command id per intended recharge, reused on an unknown-result retry; `expectedVersion` from the freshly loaded account; `occurredAt` at confirmation; success only from the 201 response.

**Rationale**: FR-008-039/040/042/043/044/046. The existing service already implements the actor gate, cross-user access, inactive refusal, optimistic locking, idempotent replay, movement + balance persistence, and audit. Reusing it preserves all of those and avoids a second financial workflow. The command-id rule is the existing replay mechanism.

**Alternatives considered**: a new Finance recharge endpoint/service — rejected as a parallel financial workflow; generate a fresh command id on every retry — rejected because it defeats replay safety; show a locally computed new balance — rejected by FR-008-044/051.

### R14 — Completed-history evidence content uses a content-only, read-only Finance branch

**Decision**: Keep `EvidenceVisibility::mayRead()` unchanged. Add `Application/Evidence/EvidenceContentAccess`, composed only by `EvidenceController::content()`. It admits the actor when the existing rule already admits them, or when the actor has an effective `FINANCE` assignment and `CompletedSheetScope` confirms the transaction is terminal, its sheet is `COMPLETED`/`COMPLETED_WITH_REJECTIONS`, and every transaction in that sheet is terminal. Out-of-scope requests keep the existing 404 non-disclosure answer; deactivated evidence keeps its 403.

**Rationale**: FR-008-034 and corrected FR-008-037 require supporting evidence in completed history to be readable by an authorized Finance Manager through the existing delivery path. The current rule does not admit Finance at `Final`, and it is reused by `AuditQueryService`; widening it would grant audit/revision access that this feature is not authorized to change. A separate content-only composition confines the extension to the resource the completed-history read already exposes and leaves upload, deletion, links, and all existing reviewer permissions untouched. `CompletedSheetScope` is shared with the history detail read so the two cannot drift.

**Alternatives considered**: extend `canReview()`/`EvidenceVisibility` for `Final` — rejected because it widens audit/document access and every caller; add a parallel evidence download route — rejected as a second storage/delivery mechanism; show metadata only — rejected because it does not satisfy corrected FR-008-037; grant mutation along with read — explicitly forbidden.

### R15 — No schema change; projections only

**Decision**: No migration, column, table, or index. Use existing tables/indexes; add read methods/query objects only.

**Rationale**: The specification and mission forbid schema change. Every required value exists: account ownership/status/balance, movement timestamp/amount/balance-after, revision/transaction/sheet links, final outcomes, decisions, evidence metadata, currency minor units.

**Alternatives considered**: add a sheet business reference — rejected as a product/schema change; add a status-leading sheet index — deferred until measured, because V1 scale is small and schema change is not authorized; denormalize sheet id onto movements — rejected as a second source of truth.

### R16 — Frontend composition follows existing conventions

**Decision**: One Finance-only navigation entry; `/finance/holders` for directory/accounts/ledger/recharge; `/finance/completed-sheets` and `/:sheetId` for history. Use a `finance` Pinia store, a typed `financeApi`, and reusable existing components/helpers. Selection is reflected in query parameters.

**Rationale**: The app already uses role-predicated navigation and route-name integrity tests; a single module entry avoids nav clutter while deep-linked history preserves back/forward and shareability. Reusing helpers prevents formatting drift.

**Alternatives considered**: separate nav entries for every surface — rejected as clutter; modal-only history — rejected because it loses deep links and history navigation; a new state library — rejected as unnecessary.

### R17 — Localization/RTL/negative balance are design constraints

**Decision**: All strings through `t()` in both catalogs; direction from `direction`; logical CSS; negative balances as magnitude plus the approved owed label; explicit loading/error/empty states.

**Rationale**: FR-008-047/048/049/050 and SC-008-010/014/019. The repository has shared locale/date/money/direction helpers; the new surface must not reintroduce hardcoded RTL or `fa-IR` formatting.

**Alternatives considered**: English-only first with later translation — rejected because the specification requires Persian and the catalog test would fail; raw negative numbers — rejected by FR-008-022.

### R18 — Verification is layered and visual acceptance is owner-controlled

**Decision**: Contract tests for the new controller, integration tests for the read queries, unit tests for formatting/access, Vitest for components/state, Playwright in Chrome for the journey and RTL, with screenshots under `artifacts/visual-review/`.

**Rationale**: Constitution V/VIII and the repository's existing harness. Query-budget assertions protect the no-N+1 requirement. No test is claimed as run here.

**Alternatives considered**: rely on E2E only — rejected because authorization/query-shape defects are cheaper and more precise to catch below the browser; rely on unit tests only — rejected because the feature is a cross-layer read surface.

## Open questions

None requiring a specification clarification. Two items remain owner-decisions/risks rather than being silently resolved:

1. A human-readable sheet reference requires a schema/product decision; the plan uses the public UUID (R8).
2. A status-leading completed-sheet index is deferred until measured; no schema change is planned (R15).

Holder display-name search and Finance completed-history evidence are resolved by R3 and R14 within the approved scope; their residual scale and authorization risks are recorded in `plan.md`.

## Planning-only statement

No application code, test, migration, generated asset, canonical contract, or `tasks.md` was created or modified by this research. The authoritative implementation remains the current source; this document records design decisions for the planning phase.
