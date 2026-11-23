# Implementation Plan: Finance Manager Account Management

**Branch**: `008-finance-manager-account-management` (logical feature directory; worktree branch `main` unchanged — no Git writes authorized) | **Date**: 2026-11-16 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/008-finance-manager-account-management/spec.md` (requirements corrected 2026-11-16), plus the authoritative domain, authorization, financial, lifecycle, and evidence rules in `specs/001-pettycash-v1-core/spec.md` and its approved contracts.

**Decision status**: This document is a **plan**, not an implementation authorization. It defines the technical approach, the additive read surface, the reused recharge write, the projection shapes, and the verification strategy. It does not create `tasks.md`, change the canonical `specs/001-pettycash-v1-core/contracts/ocs-api.yaml`, change schema, or implement code. Every section marked **Backend-required** records a capability that must be built, not one that is claimed to exist.

**Scope reminder**: five surfaces — holder directory (read-only), holder account inspection (read-only), unified account ledger (read-only), completed Cost Sheet history (read-only), and account recharge (the one authorized financial write). No financial decision is granted by this module.

---

## Summary

Build a Finance-only management surface over existing petty-cash data. Finance officers discover holders from the **account ownership table itself** (never from the Nextcloud user directory), search that account-scoped population by the holder's display name or user ID, inspect a holder's accounts and their activity, read a six-column chronological ledger whose dates and balances come from persisted account movements, recharge an eligible account through the **existing** idempotent Finance charge command, and read a completed Cost Sheet history whose list, detail, counts, postings, and evidence metadata are projected from authoritative records and whose supporting evidence is readable through the existing content path under a read-only Finance rule.

The work is predominantly additive:

- five new Finance-scoped read endpoints under `/api/v1/finance/...`;
- no change to the existing purchaser-owned `GET /accounts` or `GET /accounts/{id}/movements` behavior;
- no new recharge command, service, table, column, or financial rule — the existing `POST /accounts/{accountId}/charges` is reused unchanged;
- no database migration; every projection reads existing tables and existing indexes;
- server-side batching so holder, ledger, and history pages do not become N+1 request or query fan-outs.

Two authorizations are deliberately narrow. Holder discovery remains rooted in `pettycash_accounts`; display-name search is resolved only for uids that already own an account, so no Nextcloud directory search or enumeration is introduced. Evidence content for completed history is admitted by a **content-only, read-only** Finance branch: the general transaction-visibility rule (`EvidenceVisibility`, also used by audit reads) is left unchanged, and only `GET /evidence/{evidenceId}/content` composes the new completed-history scope. Upload, replacement, deletion, and all existing purchaser/Cost Center/Senior behavior remain untouched.

---

## Technical Context

**Language/Version**: PHP 8.2+ (Nextcloud 34 application), TypeScript 5.x, Vue 3.

**Primary Dependencies**: Nextcloud app framework / OCS controllers, `@nextcloud/vue` 9.x, Pinia, Vue Router, Vite, Vitest, Playwright; PHPUnit for the server.

**Storage**: Existing Nextcloud database tables only — `pettycash_accounts`, `pettycash_account_movements`, `pettycash_currencies`, `pettycash_sheets`, `pettycash_transactions`, `pettycash_transaction_revisions`, `pettycash_workflow_decisions`, `pettycash_evidence`. **No schema change.**

**Testing**: `scripts/dev/phpunit <test>` (containerized PHPUnit; host PHP is prohibited), targeted Vitest, `vue-tsc`, ESLint/Stylelint, and Playwright in official Google Chrome for the visual gate.

**Target Platform**: Nextcloud 34 web application at the approved desktop breakpoints, responsive at the existing breakpoints; English LTR and Persian RTL.

**Project Type**: Web application — PHP/DDD service boundary plus a Vue 3 single-page frontend.

**Performance Goals**: Every list is paginated and bounded (`pageSize` 1–100). A holder page, ledger page, and completed-history page each uses a fixed number of set-based queries independent of the returned page size (see “Query budgets”). Holder display-name search resolves names for the account-owning holder population, not for every Nextcloud user, and is O(H) in those resolutions; it never filters only the loaded page. No client-side reconstruction of authoritative balances.

**Constraints**: No schema change; no new financial write; no unrestricted Nextcloud user search or directory enumeration; holder search is scoped to account owners; no mutation of completed history; no artificial consolidation of movements; existing authorization and audit rules preserved except the explicitly scoped read-only evidence-content extension.

**Scale/Scope**: V1 documented scale — fewer than 100 active users and fewer than 10,000 transactions. The ledger and history reads are bounded regardless; the completed-list status filter is noted as an index follow-up risk because the existing sheet index is owner-prefixed.

---

## Constitution Check

*GATE: evaluated before Phase 0 research; re-evaluated after Phase 1 design.*

| Gate | Assessment | Evidence / condition |
|---|---|---|
| **I. Specification Before Implementation** | **PASS** | `spec.md` is corrected and validated (51 FRs, 20 SCs); this plan is the next prescribed step. No implementation is authorized by this document. |
| **II. Preserve Contracts and Existing Behavior** | **PASS with condition** | All new routes are additive; purchaser-owned account reads and the existing charge semantics are unchanged. The canonical `ocs-api.yaml` must gain the additive routes in a **separately authorized contract change** before implementation; this mission does not edit it. No schema change, so no migration or rollback is required. |
| **III. Nextcloud-Native, Boundary-Conscious Architecture** | **PASS** | PHP stays in `OCA\PettyCash`; the frontend uses Vue 3, TypeScript, `@nextcloud/vue` 9.x, and the existing shell. No Vuetify, no parallel authentication, no host-runtime work. |
| **IV. Domain Integrity, Security, and Auditability** | **PASS with condition** | The new read surface is gated by an effective `FINANCE` assignment; cross-user reads never widen purchaser ownership. The recharge reuses the existing audit-producing command. Completed history is a projection, never a mutation. The evidence-content extension is read-only, Finance-role scoped, limited to completed-history transactions, and does not widen the general visibility rule or audit access. Condition: the branch stays content-only with its five authorization tests; widening `EvidenceVisibility` or audit visibility requires separate authorization. |
| **V. Verified and Documented Delivery** | **PASS** | Verification is defined per layer in “Verification strategy”; no test is claimed as run in this planning phase. The visual gate remains owner-controlled. |
| **VI. Small, Independently Reviewable Task Slices** | **PASS** | The implementation is sequenced into narrow batches (backend read foundation, holder/ledger, completed history, frontend directory/ledger, recharge, completed-history UI, localization/visual gate). `tasks.md` will own the executable slices. |
| **VII. Early Owner Approval and Explicit Stop Gates** | **PASS** | Planning stops here. Backend, contract, persistence, and security work require explicit authorization; the plan does not claim approval. |
| **VIII. Project-Local Visual Evidence** | **PASS** | Visual evidence, when authorized, will be stored under `artifacts/visual-review/` and cited repository-relatively; no `/tmp` evidence is planned. No screenshots are produced by this planning phase. |

No gate violation requires Complexity Tracking.

---

## Project Structure

### Documentation (this feature)

```text
specs/008-finance-manager-account-management/
├── spec.md              # authoritative feature specification (corrected 2026-11-16)
├── plan.md              # this file (/speckit.plan output)
├── research.md          # Phase 0 decisions and evidence
├── data-model.md        # Phase 1 projections, validation rules, no schema change
├── quickstart.md        # Phase 1 validation guide
├── contracts/
│   └── finance-manager-api.md   # additive Finance API + UI contract
└── tasks.md             # Phase 2 output — NOT created by /speckit.plan
```

### Source Code (implementation projection — NOT changed by this planning mission)

```text
lib/
├── Controller/
│   ├── FinanceManagementController.php          # new: five Finance-scoped GET endpoints
│   └── EvidenceController.php                    # content() composes EvidenceContentAccess; no new route
├── Application/
│   ├── Finance/
│   │   ├── FinanceAccess.php                    # new: effective FINANCE-assignment gate
│   │   ├── HolderDirectoryService.php           # new: holder page + account-scoped name/uid search
│   │   ├── FinanceLedgerService.php             # new: account page + ledger page composition
│   │   ├── CompletedSheetHistoryService.php      # new: completed list + detail composition
│   │   └── CompletedSheetScope.php               # new: shared terminal-sheet/transaction scope
│   ├── Evidence/
│   │   └── EvidenceContentAccess.php             # new: existing rule + completed-history evidence branch
│   └── Account/
│       ├── AccountService.php                    # unchanged purchaser paths; recharge reuses charge()
│       └── AccountPayloadFormatter.php           # unchanged; Finance formatter composes around it
├── Infrastructure/
│   ├── Http/
│   │   └── FinancePayloadFormatter.php           # new: Finance wire mapping; composes existing formatters
│   └── Persistence/
│       ├── Finance/
│       │   └── FinanceReadQuery.php              # new: set-based holder/ledger/history projections
│       ├── Account/
│       │   └── DbAccountMovementRepository.php   # additive paged ledger/context reads
│       └── Expense/
│           ├── DbExpenseSheetRepository.php      # additive completed-status reads
│           └── SheetSummaryQuery.php             # additive batched approval-count read
├── Domain/
│   ├── Account/Repository/AccountRepository.php  # additive holder/account aggregate reads as needed
│   ├── Account/Repository/AccountMovementRepository.php # additive countByAccountId()
│   ├── Expense/Repository/ExpenseSheetRepository.php # additive completed-status reads
│   ├── Expense/Repository/ExpenseTransactionRepository.php # additive sheet transaction-status projection
│   ├── Workflow/Repository/WorkflowDecisionRepository.php  # additive findByTransactionIds()
│   └── Evidence/Repository/EvidenceRepository.php          # additive findByTransactionIds()
└── AppInfo/Application.php                       # new services registered; EvidenceVisibility unchanged

appinfo/routes.php                                # additive Finance routes only

src/
├── router/index.ts                               # Finance module routes
├── types/navigation.ts                           # one Finance-only navigation entry
├── types/api.ts                                  # additive Finance projection types
├── services/financeApi.ts                        # new: Finance reads; recharge reuses accountsApi.chargeAccount
├── stores/finance.ts                             # new: directory/accounts/ledger/history state
├── views/finance/
│   ├── FinanceAccountsView.vue                   # holder directory + accounts + ledger + recharge entry
│   ├── CompletedSheetsView.vue                   # completed history list
│   └── CompletedSheetDetailView.vue              # completed history detail (read-only)
├── components/finance/
│   ├── HolderDirectory.vue
│   ├── HolderAccountList.vue
│   ├── AccountLedgerTable.vue
│   ├── RechargeDialog.vue
│   ├── CompletedSheetTable.vue
│   ├── CompletedSheetDetail.vue
│   └── BalanceAmount.vue                         # negative balances as amount owed
└── utils/moneyInput.ts                           # new: bounded major-unit → minor-unit parsing (tested)

l10n/
├── en.json / en.js                               # additive keys
└── fa.json / fa.js                               # additive Persian keys

tests/
├── Contract/Controller/FinanceManagementControllerTest.php
├── Contract/Controller/EvidenceContentAccessTest.php
├── Integration/Finance/FinanceReadQueryTest.php
├── Integration/Finance/CompletedSheetHistoryTest.php
├── Unit/Application/Finance/CompletedSheetScopeTest.php
├── Unit/Application/Evidence/EvidenceContentAccessTest.php
├── Unit/Application/Audit/AuditQueryServiceVisibilityTest.php
├── Unit/Application/Finance/*.php
├── Frontend/Component/Finance*.spec.ts
└── E2E/FinanceManager.spec.ts
```

**Structure Decision**: The repository is a single Nextcloud application with a PHP DDD backend and a Vue frontend. New reads belong in an application-level Finance slice (`Application/Finance`) with a dedicated infrastructure read query; no new domain aggregate is introduced. The recharge path stays in `Application/Account/AccountService::charge()` — no parallel write slice. Frontend work stays in the existing `src/views`, `src/components`, `src/stores`, and `src/services` conventions.

---

## Key Design Decisions

### D1 — Additive Finance read routes; purchaser reads untouched

The existing account endpoints are owner-scoped by construction:

- `AccountController::index()` refuses a `userId` naming anyone else (`lib/Controller/AccountController.php:40-56`).
- `AccountService::listMovements()` answers “not yours” and “does not exist” identically (`lib/Application/Account/AccountService.php:111-149`).

The module therefore gets its own `/api/v1/finance/...` read surface. It does not accept a `userId` on the purchaser endpoints and does not loosen their ownership predicate. This satisfies FR-008-004, FR-008-005, and FR-008-007 without converting an owner-only endpoint into a role-dependent one.

**Alternative rejected**: add `?userId=` to `GET /accounts` and branch on role. That makes the purchaser endpoint's authorization depend on a request parameter and makes every future reader re-derive the scope; it also risks a regression in the purchaser path the specification explicitly protects.

### D2 — Holder identity and search come from account ownership, not the directory

`pettycash_accounts` already carries `user_uid`, `currency_id`, `balance_minor`, and `active`, with a unique `(user_uid, currency_id)` index (`lib/Migration/Version010000Date20260920000000.php:68-78`). A holder is therefore a grouped `user_uid` with at least one row in that table. This is the only candidate source. `UserDirectory` (`lib/Application/Port/UserDirectory.php:1-5`) exposes only `exists()` and `displayName()`, both keyed by a known uid (`lib/Infrastructure/Nextcloud/NextcloudUserDirectory.php:6`); it is used exactly as `SheetPayloadFormatter::displayNameOf()` uses it for sheet owners (`lib/Infrastructure/Http/SheetPayloadFormatter.php:138-151`).

The directory is searchable by **display name or user ID** without leaving that scope:

- **No search term** — one grouped, paged read + count; resolve display names for the returned page only.
- **Search term present** — one grouped read of the complete holder population (`user_uid`, `COUNT(*)`, `SUM(active)`), resolve display names for those holder uids with a per-request cache, apply a case-insensitive substring match against `userId` and `displayName`, then sort by `userId` and slice the requested page. `total` is the filtered holder count. This is server-side, complete over the holder population, and never page-local.

The Nextcloud platform does offer `IUserManager::searchDisplayName(string $pattern, ?int $limit = null, ?int $offset = null)` (Nextcloud 34; `IUserManager::search()` is deprecated in its favour). It is **not** used: it searches the unrestricted directory, and a holder-scoped result would require intersecting (and potentially truncating) a directory-wide result. Resolving names only for known holder uids keeps the candidate set and the directory access identical to today's.

**Alternative rejected**: `IUserManager::searchDisplayName()`, `IUserManager::search()`, or a general user picker — each searches outside the account-owning population and would require a broader directory integration. **Alternative rejected**: filtering the currently loaded page by display name — incomplete and misleading.

**Scale note**: the search path is O(H) in display-name resolutions, where H is the number of petty-cash holders. At the documented V1 scale (<100 active users) this is bounded and small; if H grows materially, an indexed display-name projection would be a separately authorized schema/design change (see Risks).

### D3 — Ledger date is `account_movements.occurred_at`; balance is `balance_after_minor`

The movement aggregate persists its authoritative posting timestamp and resulting balance (`lib/Domain/Account/AccountMovement.php:14-34`), and the repository's projection carries them unchanged (`lib/Infrastructure/Persistence/Account/DbAccountMovementRepository.php:114-143`). The Finance ledger formatter must emit those fields directly. It must not use the sheet's `created_at`, the revision's `expense_date`, or any prototype date. It must not derive a balance by summing the visible page.

The existing `AccountPayloadFormatter::movement()` already emits `occurredAt` and `balanceAfter` from the aggregate; the Finance row formatter composes around it rather than recomputing either.

**Alternative rejected**: reconstruct a running balance from page rows. That breaks at every page boundary and contradicts FR-008-023/024.

### D4 — Ledger description and sheet reference are resolved server-side

A consumption movement stores `source_revision_id` but its stored `description` is the generic literal `Expense consumption` (`lib/Domain/Account/PettyCashAccount.php:67-86`). The Finance ledger therefore resolves the chain movement → `pettycash_transaction_revisions` → `pettycash_transactions` → `pettycash_sheets` for the page's movements and emits:

- the sheet's title as the primary description;
- the sheet's public UUID as its **reference** (the authoritative record has no separate business-reference column — verified in `lib/Domain/Expense/ExpenseSheet.php` and the sheet migration);
- a fallback to the movement's stored description when no sheet resolves (a charge, or a data-integrity case).

This is a server-side enrichment, not a frontend lookup per row. It satisfies FR-008-015 without fabricating a numbering scheme.

**Alternative rejected**: derive a human-readable `PC-YYYY-NNN` reference. No such sequence exists; inventing one would violate FR-008-051.

### D5 — “Approved” is a sheet-level count pair; recharge rows are `N/A`

For a consumption movement linked to a sheet, the Approved cell is the sheet's **approved transaction count relative to its total transaction count**. The authoritative source is `pettycash_transactions.final_outcome` (`APPROVED` / `REJECTED` / `NULL`) plus the row count per sheet; the migration stores the column and the sheet/state index (`lib/Migration/Version010100Date20260920000000.php:31-40`). A charge has no sheet association and is emitted with `approved: null`, rendered as the localized “not applicable” marker.

The count is a **current sheet fact**, not a snapshot captured at posting time. This matches the specification's wording (“the Cost Sheet's approved transaction count relative to its total transaction count”) and is the only count the authoritative record holds. The plan does not add a rejected-count column; rejected counts remain available in completed history, where FR-008-033 explicitly requires them.

**Alternative rejected**: count only the visible ledger rows, or count the sheet's amount totals. Neither answers “approved transactions out of total transactions”, and the first is page-dependent.

### D6 — Completed history is a terminal-state projection with a defensive terminal check

Sheet completion is recalculated from the transaction set: `ExpenseSheet::recalculateStatusFrom()` sets `COMPLETED` or `COMPLETED_WITH_REJECTIONS` only when every transaction is `CompletedApproved` or `CompletedRejected` (`lib/Domain/Expense/ExpenseSheet.php:156-208`). The completed list filters on those two statuses and orders by `completed_at DESC, id DESC`; the detail read rejects a sheet that is not in a terminal status or that still contains a non-terminal transaction. That refusal is a 404, not a partial historical view.

Counts and the actual posted amount are derived from the transactions and the movements that actually settled them, never from a “every approved transaction must have posted” assumption. No movement is collapsed: each posting keeps its own timestamp, amount, and resulting balance (FR-008-021/029/031/035).

### D7 — Recharge reuses `POST /accounts/{accountId}/charges` unchanged

`AccountService::charge()` already:

- admits `FINANCE` or `ADMINISTRATOR` and refuses everyone else (`lib/Application/Account/AccountService.php:400-402`);
- deliberately allows a cross-user account, because FR-015 funds “a selected user” account (`lib/Application/Account/AccountService.php:406-410`);
- refuses an inactive account (`lib/Application/Account/AccountService.php:416-418`);
- requires an `expectedVersion` and refuses a stale one (`lib/Application/Account/AccountService.php:412-414`);
- executes through `IdempotencyService` with the command id and the account/intent payload (`lib/Application/Account/AccountService.php:420-499`);
- records the movement, the account balance, and the `AccountFunded` configuration audit event inside the idempotent closure.

No new recharge command, service, table, or route is planned. The frontend composes a deliberate two-step review and reuses `chargeAccount()` (`src/services/accountsApi.ts:65-76`). A retry after an unknown result reuses the same command id; a new intended recharge uses a new one.

**Alternative rejected**: a Finance-specific recharge service that “wraps” charge to add confirmation. Confirmation is presentation, not a financial rule; duplicating the write would create a second financial workflow and a second audit path.

### D8 — One Finance navigation entry, two read routes, deep-linkable completed detail

Add one navigation entry visible to the effective `FINANCE` role, pointing at `/finance/holders` (name `finance-holders`). The module has two top-level routes:

- `/finance/holders` — directory, selected holder accounts, ledger, and the recharge entry point;
- `/finance/completed-sheets` and `/finance/completed-sheets/:sheetId` — history list and read-only detail.

Holder and account selection are reflected in query parameters so a refresh keeps the context. The existing Nextcloud shell, breadcrumbs, direction handling, and navigation remain.

**Alternative rejected**: a single page with modal-only history. It makes completed history unshareable and loses the back/forward behavior the existing shell provides.

### D9 — Negative balances are “amount owed”, not an error

Any balance below zero is rendered as its magnitude plus the approved localized label: English `Amount Owed to Petty Cash Holder`, Persian `طلب تنخواه‌دار` (spec “Localization Requirements”). The new `BalanceAmount` component owns this rule for the account header and every ledger balance cell. It does not turn the value into zero, hide the negative, or style it as an error. `formatMoney()` already formats at the payload's `minorUnit` (`src/utils/money.ts:21-29`); the component supplies the magnitude and label.

### D10 — No schema change; stop if a mandatory requirement truly needs one

Every projection uses existing tables and indexes:

- holder grouping uses the `(user_uid, currency_id)` unique index;
- ledger pages use `pettycash_account_movements_account_time_idx` (`(account_id, occurred_at)`);
- sheet transaction state uses `pettycash_transactions_sheet_state_idx`;
- decisions use `(revision_id, stage)` and the command unique index.

No migration, column, table, or index is planned. If implementation proves that an acceptance criterion cannot be met without schema change, that batch must stop and report the blocker rather than widening the plan.

### D11 — Completed-history evidence is a separate, read-only content authorization

The existing general rule is `EvidenceVisibility::mayRead()` (`lib/Application/Evidence/EvidenceVisibility.php:62-85`). It admits the owner, an Administrator, or a reviewer admitted by `AuthorizationService::canReview()` at the transaction's current stage. `canReview()` does not handle `WorkflowStage::Final`, and completed transactions are `Final`, so a Finance Manager cannot read their evidence today. The rule is also reused by `AuditQueryService::mayRead()` (`lib/Application/Audit/AuditQueryService.php:51-67`); widening it would widen audit evidence visibility.

**Decision**: keep `EvidenceVisibility` unchanged and add `Application/Evidence/EvidenceContentAccess`, composed only by `EvidenceController::content()` (`lib/Controller/EvidenceController.php:444-544`). It admits the actor when **either**:

1. the existing `EvidenceVisibility::mayRead()` already admits them; **or**
2. the actor has an effective `FINANCE` assignment (resolved by `FinanceAccess`) and `CompletedSheetScope` confirms the transaction is terminal (`COMPLETED_APPROVED` or `COMPLETED_REJECTED` at `WorkflowStage::Final`), its sheet is `COMPLETED` or `COMPLETED_WITH_REJECTIONS`, and every transaction in that sheet is terminal.

`CompletedSheetScope::isExposedByCompletedHistory()` is the same predicate the completed-history detail read uses, so evidence cannot be exposed for a sheet the history read would refuse. The content branch is evaluated after the evidence and transaction are loaded, and out-of-scope callers receive the existing 404 non-disclosure response.

**Boundaries**:

- **Content only.** Upload (`lib/Controller/EvidenceController.php:58-160`), deletion (`347-440`), links, and every other evidence operation keep their existing guards; no route, storage path, or second delivery mechanism is added.
- **Read-only.** The branch cannot upload, replace, delete, deactivate, or otherwise mutate evidence, and it does not touch decisions, revisions, movements, or sheets.
- **Resource-scoped.** The transaction must belong to a terminal completed sheet. Draft, in-progress, Finance-pending, or unresolvable evidence is not admitted.
- **No broader access.** `AuditQueryService`, link services, and the existing purchaser/Cost Center/Senior results are unchanged; the new branch requires an effective `FINANCE` assignment and does not apply to any other caller.

---

## Backend Design

### Endpoint surface (additive; canonical OpenAPI amendment pending)

| Method | Path | Authorization | Purpose |
|---|---|---|---|
| `GET` | `/api/v1/finance/holders` | effective `FINANCE` | Paginated holder directory; `search` matches holder display name or uid, account-scoped |
| `GET` | `/api/v1/finance/holders/{holderUid}/accounts` | effective `FINANCE` | Paginated accounts for one holder, including inactive |
| `GET` | `/api/v1/finance/accounts/{accountId}/ledger` | effective `FINANCE` | Paginated six-column ledger for one account |
| `GET` | `/api/v1/finance/completed-sheets` | effective `FINANCE` | Paginated completed Cost Sheet history |
| `GET` | `/api/v1/finance/completed-sheets/{sheetId}` | effective `FINANCE` | Read-only completed detail with transactions, decisions, evidence metadata, postings |
| `POST` | `/api/v1/accounts/{accountId}/charges` | existing `FINANCE`/`ADMINISTRATOR` gate | **Reused unchanged** for recharge |

All reads accept `page` (≥1, default 1) and `pageSize` (1–100, default 25). The holder directory additionally accepts `search` (trimmed, ≤64 characters; empty means no filter). The holder search is a case-insensitive substring match on `displayName` or `userId`, executed server-side over the complete account-owning holder population; it is never a page-local filter and never a directory search. Invalid pagination is a 422 with field errors, following the existing account service pattern. An empty page is a 200 with `items: []`, not an error.

### Authorization boundary

`FinanceAccess` resolves the actor's **effective** role assignments once per request using the same `RoleAssignmentRepository::findActiveByUserUid($uid, $now)` shape the existing services use, and answers whether a `FINANCE` assignment is active. It does not use `ReportScope::seesOthers()` (administrator-only) and does not treat platform-administrator status as Finance authority. A caller without the assignment receives 403 and no holder data. The controller reads the actor uid from `CurrentUser`, never from a request parameter.

The recharge path keeps its existing gate: `AccountService::charge()` admits `FINANCE` or `ADMINISTRATOR`. This is a deliberate preservation of FR-013/FR-015 and is documented rather than “harmonized” into the new read gate. A platform administrator without a Finance assignment can therefore recharge through the existing admin funding surface but cannot read the Finance module; that is the safest reading of FR-008-005/FR-008-006 and does not change the write rule.

Evidence content uses a separate composition at `GET /evidence/{evidenceId}/content`. The existing `EvidenceVisibility::mayRead()` rule is evaluated first (`lib/Application/Evidence/EvidenceVisibility.php:62-85`); then a Finance-only completed-history branch is evaluated. That branch requires an effective `FINANCE` assignment, a terminal transaction (`COMPLETED_APPROVED` or `COMPLETED_REJECTED` at `WorkflowStage::Final`), a sheet in `COMPLETED` or `COMPLETED_WITH_REJECTIONS`, and every transaction of that sheet terminal. Out-of-scope callers receive the existing 404 non-disclosure answer. The general `EvidenceVisibility` rule and `AuditQueryService` (`lib/Application/Audit/AuditQueryService.php:51-67`) are **not** changed, so this does not grant audit or revision access. Evidence upload and delete routes keep their existing owner/draft guards (`lib/Controller/EvidenceController.php:58-160`, `347-440`) and are not reachable through this branch.

### Services and queries

**`FinanceAccess`** — role gate only; no persistence.

**`HolderDirectoryService`**

- `page(search, page, pageSize)`:
  - no `search`: one grouped paged read over `pettycash_accounts` (`user_uid`, `COUNT(*)`, `SUM(active)`) plus one count; display-name resolution for the returned page only.
  - with `search`: one grouped read of the complete holder population (`user_uid`, `COUNT(*)`, `SUM(active)`), display-name resolution for those holder uids with a per-request cache, case-insensitive matching on `userId` or `displayName`, sort by `user_uid`, then slice the requested page. No page-local filtering; no Nextcloud directory search or enumeration.
  - Returns `userId`, `displayName`, `accountCount`, `activeAccountCount`, and a derived `status` (`ACTIVE` / `INACTIVE` / `MIXED`).

**`FinanceLedgerService`**

- `accountsForHolder(holderUid, page, pageSize)`:
  - verifies the uid owns at least one account through the holder read (otherwise 404);
  - loads all accounts through `AccountRepository::findAllByUserUid()` (active and inactive);
  - slices the requested page and batches activity via `FinanceReadQuery::accountActivityFor($dbIds)` — movement count and `MAX(occurred_at)` per account, one grouped query;
  - emits account id, currency, minor unit, balance, active, version, movement count, last movement timestamp.
- `ledger(accountId, page, pageSize)`:
  - loads the account by public id; 404 if absent;
  - loads `total` with a count and the page with `AccountMovementRepository::findByAccountIdPaged()` (add a deterministic `(occurred_at DESC, id DESC)` tie-break in the read);
  - collects the page's `sourceRevisionId` values and calls `FinanceReadQuery::ledgerContextFor()` once to resolve revision → transaction → sheet (id, public reference, title) and once for the page's sheet-level approval counts;
  - formats each row with exactly one non-null monetary side: `recharge` for `CHARGE`, `expense` for `CONSUMPTION`, never both;
  - emits `approved: null` for a charge and `{approvedCount, totalCount}` for a sheet-linked consumption.

**`CompletedSheetHistoryService`**

- `page(page, pageSize)`:
  - one page read of sheets whose status is `COMPLETED` or `COMPLETED_WITH_REJECTIONS`, ordered `completed_at DESC, id DESC`;
  - one count;
  - one batched transaction-count read over the page's sheet ids: total, approved, rejected;
  - one batched posted-amount read over the page's sheet ids: join movements → revisions → transactions, sum consumption amounts and count postings;
  - bounded purchaser display-name resolution for the page.
- `detail(sheetId)`:
  - sheet by public id; 404 unless terminal status;
  - all transactions by sheet id; refuse (404) if any transaction is not terminal;
  - one batched decisions read for the sheet's transaction ids;
  - one batched evidence read for the sheet's transaction ids;
  - one batched posting read joining movements to the sheet's revisions/transactions;
  - counts and actual posted total from those same sets;
  - each transaction emits its latest decision per stage and a full chronological decision list; each posting emits `occurredAt`, `amount`, and `balanceAfter`.

**`CompletedSheetScope`** — the single terminal-history predicate shared by the detail read and evidence content. `isExposedByCompletedHistory(ExpenseTransaction $transaction)` requires the transaction's status to be `COMPLETED_APPROVED` or `COMPLETED_REJECTED` at `WorkflowStage::Final`, its sheet to be `COMPLETED` or `COMPLETED_WITH_REJECTIONS`, and every transaction in that sheet to be terminal. It performs no write and returns false rather than throwing for an out-of-scope transaction.

**`EvidenceContentAccess`** — content-only composition used only by `EvidenceController::content()`. `mayRead(actorUid, ExpenseTransaction $transaction)` returns true when the existing `EvidenceVisibility::mayRead()` admits the actor; otherwise it returns true only when `FinanceAccess` confirms an effective `FINANCE` assignment and `CompletedSheetScope` confirms the completed-history scope. It has no upload, delete, link, or mutation method.

**`FinancePayloadFormatter`** (`Infrastructure/Http`) — pure mapping from already-loaded aggregates/projections to the wire shapes. It does not query, decide authorization, or invent a value. The application services return projections; the controller applies this formatter. It composes `AccountPayloadFormatter` and `TransactionPayloadFormatter` where their shapes already fit, and adds Finance-only fields such as `approved`, `recharge`, `expense`, `sheet`, and `posting`. Keeping it in the presentation layer prevents an application service from depending on an HTTP formatter.

### Query budgets (no N+1)

| Read | Queries per request |
|---|---|
| Holder page (no search) | 1 grouped page + 1 count + 1 page-bounded display-name pass |
| Holder page (search) | 1 grouped holder-population read + 1 holder-population display-name pass (per-request cache) + in-memory filter/sort/slice |
| Holder accounts | 1 ownership check + 1 account read + 1 batched activity |
| Ledger page | 6 constant queries: 1 account read + 1 batched account activity aggregate + 1 movement count + 1 paged movement read + 1 batched ledger provenance/context + 1 batched sheet approved/total counts |
| Completed list | 1 page + 1 count + 1 batched counts + 1 batched posted totals + 1 bounded display-name pass |
| Completed detail | 1 sheet + 1 transaction read + 1 batched decisions + 1 batched evidence + 1 batched postings |

No query is issued per row, per transaction, or per evidence item. The page size is capped at 100. Display-name resolution is the only per-holder lookup: page-bounded when no search term is present, and bounded by the account-owning holder population when a search term is present. Neither path enumerates the Nextcloud directory. The ledger page's six queries are constant regardless of page size; its account-activity aggregate supplies the authoritative header `lastMovementAt` for any page and is never reconstructed from the loaded movement slice.

### Data-integrity behavior

- A movement whose consumption revision cannot resolve remains a data fault, not a fabricated row: the existing `DbAccountMovementRepository::mapRow()` raises `UnreadableRecordData`, and the controller maps it through `OcsErrorMapper`.
- A completed sheet whose transaction set is not terminal is not shown as completed history (404 at detail; excluded at list).
- A holder uid with no account is not a holder (404).
- A public account/sheet id that does not resolve is 404, never a placeholder.
- An account with no movements is a valid empty ledger.
- A completed sheet with zero approved transactions is valid; its posted amount is zero and its counts are honest.

### Error semantics

| Case | Status |
|---|---|
| No authenticated user | 403 |
| Authenticated but no effective `FINANCE` assignment | 403, no holder data |
| Unknown holder/account/sheet, or a sheet not in a terminal completed state | 404 |
| Invalid `page` / `pageSize` / over-long search | 422 with field errors |
| Unreadable stored data (e.g. dangling consumption revision) | mapped data fault (`OcsErrorMapper`), not 404/403 |
| Recharge validation/actor/inactive/stale version | existing `AccountController::charge()` answers (422/403/404/409) |

---

## Frontend Design

### Information architecture

**Finance accounts route (`/finance/holders`)**

- Header with module title and a short read-only/recharge classification note.
- Holder directory card:
  - search input (display name or user ID, server-side over the account-owning holder population), refresh, pagination;
  - rows: display name, uid, account count, active count, status;
  - explicit loading, error, and empty states;
  - selecting a holder loads the account list and updates the query string.
- Holder accounts card:
  - each account shows identifier (public UUID), currency, current balance, active/inactive status, movement count, last movement timestamp, and a recharge action only for active accounts;
  - inactive accounts are visible but the recharge action is disabled with the reason;
  - no mutation control other than the authorized recharge entry point.
- Ledger card:
  - exactly six columns: **Date**, **Description**, **Approved**, **Recharge**, **Expense**, **Balance**;
  - Date uses `formatSheetDateTime(movement.occurredAt)`; no fallback to sheet/expense dates;
  - Description shows the sheet title and its reference for consumption rows, and the charge description for recharges;
  - Approved shows `approvedCount of totalCount` (localized number formatting) or the localized “N/A” for a charge;
  - Recharge and Expense are separate cells; only the applicable one contains a figure;
  - Balance uses `BalanceAmount`; a negative value is the owed label, never a raw unexplained negative;
  - pagination with page indicator; page changes reload from the server and do not recalculate prior balances;
  - no edit/delete/decision action anywhere in the ledger;
  - explicit empty, loading, error, and retry states.

**Completed Cost Sheets route (`/finance/completed-sheets`)**

- List columns: reference, title, purchaser, total count, approved count, rejected count, actual posted amount, completed date.
- Filter: completed statuses are implicit; no pending Finance sheet appears.
- Pagination, loading, error, empty states.
- Row opens `/finance/completed-sheets/:sheetId`.

**Completed detail route (`/finance/completed-sheets/:sheetId`)**

- Header: reference, title, purchaser, counts, posted amount, completion date.
- Transaction table: reference, description, expense date (labelled as expense date, not posting date), amount/currency, Cost Center decision, Senior decision, final Finance decision, evidence metadata, posting timestamp/amount/balance.
- Decisions are display-only: no Approve/Reject/Return controls, no DecisionForm, no edit affordance.
- Evidence uses the existing `EvidencePreview`/`EvidenceCard` components and existing content endpoint. Authorization for that content is the content-only `EvidenceContentAccess` composition in D11; the UI adds no second rule and offers no upload, replace, or delete affordance.
- Posting information comes from the detail payload; the UI does not compute a posted amount from transaction amounts.

### State and data flow

- `src/services/financeApi.ts` — typed OCS reads:
  - `listHolders({ page, pageSize, search })`
  - `listHolderAccounts(holderUid, { page, pageSize })`
  - `fetchLedger(accountId, { page, pageSize })`
  - `listCompletedSheets({ page, pageSize })`
  - `getCompletedSheet(sheetId)`
- `src/stores/finance.ts` — one store with independent slices:
  - `holders` page/search/loading/error;
  - `selectedHolder`, `holderAccounts` page/loading/error;
  - `selectedAccount`, `ledger` page/items/total/loading/error;
  - `completedSheets` page/loading/error;
  - `completedSheetDetail` loading/error.
  Each slice resets its error before a request and keeps the last successful page visible while a retry is in flight where the existing views do the same.
- Selection is written to the route query (`holder`, `account`, `page`) so refresh and back/forward preserve context. Route query changes do not mutate financial values.
- Recharge reuses `chargeAccount()` from `src/services/accountsApi.ts`; no parallel API client.

### Recharge interaction and state machine

The dialog is a controlled sequence, not a one-click write:

1. **Amount** — positive amount in the account's major units, required description; the currency is displayed read-only.
2. **Review** — holder display name/uid, destination account reference, account currency, amount formatted with `formatMoney`, current balance, description.
3. **Confirm** — explicit “Confirm recharge” action; the button is disabled while submitting.
4. **Submit** — one `commandId` generated at confirmation and reused for retries of the same intent; `expectedVersion` comes from the freshly loaded account payload; `occurredAt` is the confirmation moment.
5. **Success** — only after the authoritative 201; show the resulting balance from the server's `accountMutationResult.account.balance` (and the movement's `balanceAfter`), then refresh the holder accounts and the ledger’s first page.
6. **Failure** — validation/actor/inactive/stale-version errors are shown with the server message; no success state and no balance change.
7. **Unknown** — a network/5xx/timeout result shows “outcome unknown”, keeps the dialog open, offers “Retry with the same command”, and never displays a success balance. A retry reuses the command id; abandoning and starting a new recharge generates a new one.

Client-side amount validation is a usability aid; the server remains authoritative (`amountMinor ≥ 1`, description 1–1000, active account, version guard, idempotency). The parser is a small tested utility; it does not alter wire or domain semantics.

### Localization, RTL, accessibility

- Every string uses `t('pettycash', '...')`; English keys and Persian translations are added to both `.json` and `.js` catalogs so the catalog-completeness test and the runtime catalog agree.
- English is the primary language; Persian is required. Direction comes from `src/utils/direction.ts` (`:dir="direction"`), never a hardcoded `rtl`.
- Layout uses logical CSS properties (`margin-inline`, `padding-inline`, `inset-inline`) and tested RTL behavior.
- Tables remain usable at the approved responsive breakpoints; on narrow widths the table may become stacked rows but must retain every column label and value, including the Approved/Recharge/Expense distinction.
- Semantic table markup, column headers, `scope`, captions, labelled inputs, focus-visible states, keyboard-operable dialogs with focus restoration, and `aria-live` for async errors/success.
- Negative balances use the approved localized wording in every surface.
- No fabricated placeholder amount, balance, count, or date; absent values use `ABSENT_MARKER` (`—`).

### Reuse map

| Need | Reuse |
|---|---|
| Money formatting | `src/utils/money.ts::formatMoney` |
| Date/time formatting | `src/utils/date.ts::formatSheetDateTime` |
| Locale digits | `src/utils/number.ts::formatNumber` |
| Direction | `src/utils/direction.ts::direction` |
| Recharge write | `src/services/accountsApi.ts::chargeAccount` |
| Existing evidence preview | `src/components/evidence/EvidencePreview.vue`, `src/components/review/EvidenceCard.vue` |
| Empty/loading/error patterns | existing account/queue views |
| Nav shell | `src/components/navigation/AppNavigation.vue`, `src/types/navigation.ts` |

---

## Requirement Coverage Map

| Requirement group | Design coverage |
|---|---|
| FR-008-001…007 — read-only classification, authorized recharge, additive cross-user access, holder-only scope | D1, D2, D7; “Authorization boundary”; endpoint table |
| FR-008-008…011 — holder directory and account inspection | D2, D3; endpoints 1–2; frontend holder/account cards |
| FR-008-012…025 — six-column ledger, authoritative date/balance, approved counts, pagination, negative balance, read-only | D3, D4, D5, D9; endpoint 3; data-model ledger invariants; UI contract |
| FR-008-026…031 — individual posting and completed lifecycle | D6; “Completed history” query design; no lifecycle change |
| FR-008-032…038 — completed list/detail, counts, posted amount, terminal decisions, evidence, read-only | D6, D11; endpoints 4–5; `CompletedSheetScope`/`EvidenceContentAccess`; UI contract |
| FR-008-039…046 — recharge flow, positive amount, fixed currency, inactive refusal, idempotency, unknown result, audit | D7; recharge state machine; existing charge reuse |
| FR-008-047…051 — English/Persian, RTL, shell, states, authoritative values | D8, D9; localization/accessibility section; no-fabrication rule |
| SC-008-001…020 | The verification matrix in “Verification strategy” and the scenarios in `quickstart.md`; each SC is checked at the layer that can prove it |

---

## Contract Impact

### Existing canonical contract

`specs/001-pettycash-v1-core/contracts/ocs-api.yaml` is **not modified by this planning mission** and no existing operation changes shape. The purchaser `GET /accounts`, `GET /accounts/{accountId}/movements`, and `POST /accounts/{accountId}/charges` keep their current schemas and authorization.

### Additive contract (feature-level)

`contracts/finance-manager-api.md` defines the five Finance-scoped reads, their authorization, pagination, response shapes, and error mapping. The canonical OpenAPI file must gain these paths and schemas in a **separately authorized contract batch** before implementation. Until then, the feature contract is the planning authority for the additive surface, and implementation must not claim the canonical contract already describes it.

### Recharge contract

No change. The frontend sends the existing `AccountCharge` body (`amountMinor`, `description`, `occurredAt`, `expectedVersion`) and the existing `X-PettyCash-Command-ID` header. The response remains `AccountMutationResult` (`account`, `movement`). The idempotent behavior is the existing `IdempotencyService` behavior; no second command, endpoint, or response envelope is introduced.

### Evidence content authorization

No route, request shape, or response shape changes. `GET /evidence/{evidenceId}/content` keeps its canonical operation and its existing success/error shapes. The feature adds only an internal authorization composition (`EvidenceContentAccess`) that admits an effective Finance Manager for evidence on a transaction in a terminal completed sheet. The existing 404/403 responses remain the non-disclosure answers for out-of-scope and deactivated cases. The canonical `ocs-api.yaml` is unchanged; the feature contract records the added authorization condition because it is observable behavior even without a schema change.

### UI contract

The six columns are an invariant, not a styling preference: Date, Description, Approved, Recharge, Expense, Balance. The UI must not add a seventh user-visible column (in particular, no rejected-count column) and must not repurpose Approved as a movement-type column. Recharge rows show Approved as the localized “not applicable”. Every row shows the server-provided `balanceAfter`.

---

## Data Model Impact

**No persisted entity, table, column, index, or migration is introduced.** `data-model.md` defines presentation projections only:

- `HolderSummary` (derived from `pettycash_accounts` grouped by `user_uid`);
- `HolderAccountSummary` (from `PettyCashAccount` plus batched movement activity);
- `LedgerRow` (from `AccountMovement` plus batched sheet context and approval counts);
- `CompletedSheetSummary` (from `ExpenseSheet` plus batched transaction counts and posted totals);
- `CompletedSheetDetail` (from the sheet, its transactions, batched decisions/evidence, and postings);
- `RechargeDraft` (ephemeral frontend state; the write is the existing `AccountCharge`).

Every projected value has an authoritative source. No projection stores a second copy of a balance, total, count, or decision.

---

## Verification Strategy

### Server

- **Contract tests** for `FinanceManagementController`: unauthenticated/without-Finance 403 with no holder data; Finance 200; unknown holder/account/sheet 404; invalid pagination 422; empty pages 200.
- **Integration tests** for `FinanceReadQuery`/services against the existing database harness:
  - holder directory excludes users with no account and lists a holder once;
  - holder search matches display name and uid across the complete holder population, is case-insensitive, is not page-local, and never returns a non-holder;
  - holder accounts include inactive accounts and correct activity;
  - ledger page order/date/balance are movement facts; page 2 balances do not depend on page 1;
  - consumption rows resolve sheet title/reference and approved/total counts; charge rows have `approved: null` and only the recharge side;
  - completed list excludes a sheet awaiting Finance and includes both completed statuses;
  - completed detail refuses a non-terminal sheet and returns counts, actual posted amount, stage decisions, evidence metadata, and posting information;
  - no N+1 regressions on a multi-row page (assert query count or use the existing query-count harness if available).
- **Unit tests** for `FinanceAccess`, holder status derivation, ledger row formatting, amount parser, and negative-balance presentation helper.
- **Evidence authorization tests** (planned; minimum five cases):
  1. an effective Finance Manager can read evidence content for a terminal transaction in a `COMPLETED`/`COMPLETED_WITH_REJECTIONS` sheet that the completed-history read exposes;
  2. a non-Finance user (including a purchaser or reviewer without a completed-history relationship) cannot gain cross-user evidence access through this branch;
  3. a Finance Manager cannot upload, replace, delete, deactivate, or otherwise mutate evidence through the capability, and upload/delete guards are unchanged;
  4. a Finance Manager cannot read evidence for a draft, in-progress, Finance-pending, non-terminal-transaction, or otherwise out-of-scope sheet (including evidence outside the completed-history scope), receiving the existing non-disclosure response;
  5. existing purchaser, Cost Center Manager, and Senior Manager evidence reads still produce exactly the previous results, and `AuditQueryService` visibility is unchanged.
- **Existing regression tests**: the account controller/charge suites, the workflow/settlement suites, and the evidence visibility suites must remain green; the purchaser account behavior is explicitly covered by a regression check (SC-008-004).

### Frontend

- Vitest component tests for holder directory, account list, ledger six columns, approved/N-A rendering, negative balance/owed label, pagination behavior, completed list/detail read-only state, and recharge review/confirm/failure/unknown states.
- `src/l10n` catalog completeness for every new key; English/Persian key parity.
- Direction tests for LTR/RTL and logical layout; keyboard/focus tests for the recharge dialog and evidence overlay.
- Route-name integrity and navigation visibility tests for the Finance-only entry.
- No frontend test may assert a fabricated financial value.

### End-to-end and visual gate

- Playwright in official Google Chrome (desktop and the approved mobile viewport) for: holder discovery, account inspection, ledger paging, recharge confirmation/duplicate/unknown, completed history read-only, and EN/FA direction.
- Screenshots, when authorized, under `artifacts/visual-review/<task>/` and cited repository-relatively. Owner visual approval remains a separate gate; this plan does not claim it.
- The containerized PHPUnit wrapper is the only PHP test entry point; no host PHP/Composer/PHPUnit/OCC.

### Commands (when implementation is authorized)

```bash
scripts/dev/phpunit tests/Contract/Controller/FinanceManagementControllerTest.php
scripts/dev/phpunit tests/Integration/Finance
npm test -- --run tests/Frontend/Component/Finance
npm run typecheck
npm run lint
npm run stylelint
npm run test:e2e -- tests/E2E/FinanceManager.spec.ts
```

`npm run check` remains the frontend milestone gate; PHP tests are separate. No command in this list is executed by the planning phase.

---

## Risks and Open Items

1. **Completed-history evidence is a deliberate, narrow authorization extension.** `EvidenceVisibility::mayRead()` does not admit Finance at `WorkflowStage::Final`, and it is shared with `AuditQueryService`, so it is left unchanged. The plan adds a content-only branch in `EvidenceContentAccess`, used only by `EvidenceController::content()`, admitting an effective Finance Manager only for a terminal transaction in a terminal completed sheet. Residual risk: a future edit could wrongly widen `EvidenceVisibility` or reuse the branch elsewhere; the planned five evidence authorization tests and the shared `CompletedSheetScope` predicate are the guard. Audit/revision visibility and every evidence mutation path remain out of scope.

2. **No sheet business-reference column.** The authoritative public UUID is used as the reference. A human-readable sequence would be fabricated and is explicitly not planned. If the owner requires one, it is a schema/product decision, not a formatter change.

3. **Holder search resolves display names for the account-owning population.** The candidate set remains `pettycash_accounts`; display names are resolved only for those uids through the existing `UserDirectory` port, with a per-request cache. With a search term the path is O(H) in name resolutions, where H is the number of petty-cash holders, and the complete holder population is filtered server-side rather than the loaded page. At the documented V1 scale (<100 active users) this is bounded and small. If H grows materially, an indexed display-name projection would require separate schema/design authorization; directory-wide `searchDisplayName()` is not a fallback because it would enumerate non-holders.

4. **Completed-list status filter has no status-leading index.** The existing index is `(owner_uid, status, created_at)` (`lib/Migration/Version010100Date20260920000000.php:28`), while the Finance list is status-wide. At V1 scale the bounded page/count is acceptable. Do not add a schema index in this feature; measure first, then propose a separately authorized index change if needed.

5. **Canonical contract lag.** The five additive reads are defined in the feature contract but not yet in `ocs-api.yaml`. Implementation must not treat the canonical contract as already updated. A separate contract batch is required before the routes are considered part of the public OCS surface.

6. **Approved count is current, not historical.** It reflects the sheet's current terminal outcomes. This is the specification's wording and the only authoritative count. The UI must not label it as a snapshot at posting time.

7. **Recharge timestamp is client-supplied.** The existing command requires `occurredAt`; the UI sends the confirmation moment. The persisted movement timestamp is authoritative for the ledger. Clock skew is an existing accepted behavior; no new server-time field is introduced.

8. **Administrator/Finance asymmetry.** The write gate admits Administrators, while the new reads require a Finance assignment. This preserves existing write semantics and the specification's Finance Manager scope; it must be stated in the UI copy and not “fixed” by widening reads.

9. **No schema change is a hard constraint.** A discovered need for a new column/index/table stops the affected batch and returns to the owner; it is not absorbed into this plan.

---

## Implementation Sequencing (plan-level; `tasks.md` owns executable slices)

1. **Read foundation** — `FinanceAccess`, `FinanceReadQuery` and additive batched repository reads, formatter skeletons, query-budget tests.
2. **Holder directory and accounts** — endpoints, pagination/search, display-name resolution, activity, controller contract tests.
3. **Ledger** — paged movement read with deterministic ordering, batched sheet context/approval counts, six-column payload, negative-balance rule, integration tests.
4. **Completed history** — terminal list/detail, counts, actual posted amount, decisions/evidence metadata/postings, shared `CompletedSheetScope`, content-only `EvidenceContentAccess` composition, read-only guards and the five authorization tests.
5. **Frontend shell** — routes, navigation entry, types, API client, store, holder/account/ledger views with explicit states.
6. **Recharge** — two-step dialog over the existing charge API, command-id retry semantics, success/failure/unknown handling, duplicate-submission tests.
7. **Completed history UI** — list/detail, read-only assertions, evidence metadata and existing preview integration against the new content authorization.
8. **Localization, RTL, accessibility, visual gate** — catalogs, direction/layout, keyboard/focus, responsive checks, Playwright evidence under `artifacts/visual-review/`, owner approval.

Each batch must stop at its boundary and report evidence; no batch may absorb unrelated financial, contract, or schema work.

---

## Out of Scope

- Any change to Finance decision semantics, account lifecycle, posting rules, revision handling, audit rules, or currency rules.
- Deferred, batch, retroactive, or artificially consolidated settlement.
- Currency conversion or independent recharge-currency selection.
- General Nextcloud user search or enumeration of non-holder users.
- Purchaser-owned account redesign or purchaser workflow changes.
- Mobile-specific redesign.
- Mutation of historical decisions, transactions, movements, or completed sheets.
- Evidence upload, replacement, deletion, deactivation, link creation, or any other evidence mutation through the completed-history read path.
- A parallel evidence storage, delivery, or download mechanism; unrestricted Nextcloud directory search or enumeration.
- `tasks.md`, implementation, tests, migrations, generated bundles, deployment artifacts, or canonical OCS contract edits in this planning mission.

---

## Constitution Check (post-design)

Re-evaluated after the Phase 1 design above:

- The design remains additive; no existing contract shape or purchaser read changes. The canonical contract gap is explicitly conditioned, not hidden.
- The new read surface is role-gated; the recharge write is reused, not redefined; audit behavior is preserved.
- No schema change is introduced, and the plan names the stop condition if one becomes necessary.
- Verification covers the domain, authorization, financial, ledger, history, localization, RTL, accessibility, and visual gates; no test is claimed as executed.
- The evidence access extension is explicitly content-only, read-only, Finance-role scoped, and limited to terminal completed history; the general visibility rule, audit access, and all mutation guards are unchanged.

**Result: PASS.** No Complexity Tracking entries are required.

---

## Completion State

- Planning artifacts generated: `plan.md`, `research.md`, `data-model.md`, `contracts/finance-manager-api.md`, `quickstart.md`.
- Planning artifacts corrected 2026-11-16 for holder display-name search and read-only Finance evidence access in completed history; all affected artifacts updated together.
- `tasks.md`: **not created** (Phase 2 is a separate command).
- Source, tests, migrations, generated assets, and `specs/001-pettycash-v1-core/contracts/ocs-api.yaml`: **not modified**.
- Feature activation: `.specify/feature.json` now resolves `specs/008-finance-manager-account-management`.
