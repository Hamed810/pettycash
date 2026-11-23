# Quickstart — Finance Manager Account Management

**Feature**: `specs/008-finance-manager-account-management/` | **Status**: validation guide for the planning artifacts and, when separately authorized, the implementation.

This guide defines observable scenarios, commands, and expected outcomes. It contains no implementation code and no complete test suite. Implementation details belong in `tasks.md` and the implementation phase.

## Prerequisites

- Feature 008 is the active Spec Kit feature:

  ```bash
  cat .specify/feature.json
  # {"feature_directory":"specs/008-finance-manager-account-management"}
  ```

- The implementation batch is **explicitly authorized**. This planning mission does not run the application, tests, or browser checks.
- The PettyCash lab is available for runtime checks:

  ```bash
  /home/ubuntu/projects/nextcloud-lab/bin/lab pettycash up
  ```

- PHP work uses only the repository wrappers:

  ```bash
  scripts/dev/phpunit <test>
  scripts/dev/occ <args>
  scripts/dev/opcache-settle
  scripts/dev/route-settle
  scripts/dev/route-cache-clear
  ```

  Host PHP, Composer, PHPUnit, and OCC are prohibited.

- Frontend work runs on the Ubuntu host with the existing npm scripts.
- Browser evidence uses official Google Chrome only. If Chrome is unavailable, stop and report the blocker; do not substitute another engine.

## Planning-artifact validation (safe to run now)

```bash
test -f specs/008-finance-manager-account-management/plan.md
test -f specs/008-finance-manager-account-management/research.md
test -f specs/008-finance-manager-account-management/data-model.md
test -f specs/008-finance-manager-account-management/contracts/finance-manager-api.md
test -f specs/008-finance-manager-account-management/quickstart.md
test ! -f specs/008-finance-manager-account-management/tasks.md

git status --short
```

**Expected**:

- all five planning artifacts exist;
- `spec.md` contains the corrected FR-008-037/SC-008-013 evidence requirement;
- the holder display-name/uid search contract is defined in `plan.md`, `research.md`, `data-model.md`, and `contracts/finance-manager-api.md`;
- `tasks.md` does **not** exist;
- `specs/001-pettycash-v1-core/contracts/ocs-api.yaml` is unchanged;
- only `.specify/feature.json` and the Feature 008 directory are new/modified by this mission (plus any pre-existing untracked files that must be preserved).

## Server validation (when implementation is authorized)

### 1. Authorization boundary

**Setup**: one Finance-assigned user, one authenticated user without a Finance assignment, one holder with an account, one user with no account.

**Run**:

```bash
scripts/dev/phpunit tests/Contract/Controller/FinanceManagementControllerTest.php
```

**Expected**:

- non-Finance caller → `403 FORBIDDEN`, body contains no holder identity, account, balance, or movement;
- Finance caller → `200` for all five reads;
- unknown holder/account/sheet → `404 NOT_FOUND`;
- invalid `page`/`pageSize` → `422 VALIDATION_FAILED` with field errors;
- empty page → `200` with `items: []`, `total: 0`.

### 2. Holder directory scope and pagination

**Setup**: holder A (display name “Alice Example”) owns two accounts (one active, one inactive); holder B owns no account; holder C (display name “Alice Smith”) owns an account on a later page than the first result page.

**Expected**:

- A appears exactly once with `accountCount: 2`, `activeAccountCount: 1`, `status: "MIXED"`;
- B is absent for every search term, including searches for B's display name and uid;
- `search` matches the holder **display name** and the holder uid, case-insensitively;
- searching “Alice” returns both A and C regardless of which page they would occupy without a search — the filter is over the complete holder population, not the loaded page;
- `total` equals the number of matching holders, not the page length;
- no Nextcloud directory call enumerates non-holders or performs a directory-wide search.

### 3. Holder account inspection

**Expected**:

- both active and inactive accounts are listed;
- each row carries id, currency, balance, active flag, version, movement count, last movement timestamp;
- an unused account has `lastMovementAt: null`;
- no mutation control is present in the payload.

### 4. Unified ledger — six columns and authoritative values

**Setup**: an account with at least one recharge, at least one approved expense posting, and at least two pages of movements.

**Run**:

```bash
scripts/dev/phpunit tests/Integration/Finance
```

**Expected**:

- each row has exactly one of `recharge`/`expense` non-null;
- the consumption row resolves a `sheet` with a title and public-UUID reference;
- `approved` is `{approvedCount, totalCount}` for a consumption and `null` for a charge;
- `occurredAt` equals the persisted `pettycash_account_movements.occurred_at`;
- `balanceAfter` equals the persisted `balance_after_minor`, including on page 2;
- no rejected-count field exists;
- no action/mutation field exists.

**Manual check**: load page 1, note a balance, load page 2, return to page 1. The previously displayed balances are unchanged; no client-side running sum is used.

### 5. Negative balance presentation

**Setup**: an account whose persisted balance is below zero.

**Expected (English)**: the magnitude is shown with `Amount Owed to Petty Cash Holder`.

**Expected (Persian)**: the magnitude is shown with `طلب تنخواه‌دار`, RTL layout intact.

- The value is not shown as zero, as an error, or as a bare unexplained negative.
- Ledger rows with a negative `balanceAfter` use the same rule.

### 6. Recharge — deliberate confirmation and server-confirmed success

**Setup**: an active holder account with a known `version`.

**Run (browser or Playwright)**:

1. Select the account → Recharge.
2. Enter a positive amount and a description.
3. Continue to review.
4. Confirm the review.

**Expected**:

- review shows holder, destination account, account currency (read-only), amount, current balance, description;
- no request is sent before the explicit confirm;
- the submit button is disabled while the request is in flight;
- success is shown only after the 201;
- the displayed resulting balance comes from the response, not from local addition;
- the movement appears in the ledger with the account currency and the confirmation timestamp.

### 7. Recharge refusal — inactive or ineligible account

**Expected**:

- the recharge action is disabled with the reason for an inactive account;
- if forced, the server refuses and no financial effect occurs;
- no success state and no balance change is shown.

### 8. Recharge duplicate/retry safety

**Setup**: a recharge request with a fixed command id.

**Run**: repeat the same submission (double-click, browser retry, or replayed request).

**Expected**:

- exactly one movement and one balance change;
- the repeated call returns the original result;
- no second audit event.

### 9. Recharge unknown result

**Setup**: interrupt the response after the request is sent (network drop or server timeout).

**Expected**:

- the UI shows an unknown/failed outcome, never success;
- no resulting balance is presented as though the recharge succeeded;
- “Retry with the same command” reuses the command id;
- a definitive 4xx failure allows a corrected attempt with a new command id after reload.

### 10. Completed Cost Sheet history

**Setup**: one fully completed sheet (with or without rejections) and one sheet still awaiting a Finance decision.

**Run**:

```bash
scripts/dev/phpunit tests/Integration/Finance/CompletedSheetHistoryTest.php
```

**Expected**:

- only the terminal sheet appears;
- the pending Finance sheet is absent;
- the list shows reference, title, purchaser, total/approved/rejected counts, and actual posted amount;
- `approved + rejected = total`;
- the posted amount equals the sum of the actual consumption movements, not an assumed approved total;
- a completed sheet with rejected transactions shows its real zero/partial posted amount.

### 11. Completed detail — read-only and traceable

**Expected**:

- every transaction shows reference, description, expense date, amount/currency, Cost Center/Senior/final Finance decisions, evidence metadata, and posting information;
- `expenseDate` and `posting.occurredAt` are visibly distinct;
- a rejected transaction has `posting: null`;
- no Approve/Reject/Return, decision-edit, or historical mutation control exists in the DOM or payload;
- a non-terminal sheet returns 404;
- evidence metadata is listed, and an authorized Finance Manager can open its content through the existing viewer/content path;
- the viewer offers no upload, replacement, deletion, deactivation, or link-creation control.

### 12. Evidence authorization (read-only, completed-history scoped)

**Setup**: a completed sheet visible in Finance history with evidence on a terminal transaction; a draft/in-progress (non-terminal) sheet with evidence; a purchaser, a Cost Center Manager, and a Senior Manager with their existing relationships; a non-Finance user.

**Run**:

```bash
scripts/dev/phpunit tests/Contract/Controller/EvidenceContentAccessTest.php
scripts/dev/phpunit tests/Unit/Application/Evidence/EvidenceContentAccessTest.php
scripts/dev/phpunit tests/Unit/Application/Audit/AuditQueryServiceVisibilityTest.php
```

**Expected** (minimum five cases):

1. **Finance read**: an effective Finance Manager receives the evidence content for a terminal transaction in a completed sheet exposed by Finance history.
2. **Non-Finance cross-user denial**: a non-Finance user cannot use the capability to read another user's evidence; the existing non-disclosure response is returned.
3. **No mutation**: the Finance Manager cannot upload, replace, delete, deactivate, or otherwise mutate evidence through this capability; upload/delete routes keep their existing guards and refusals.
4. **Scope denial**: evidence for a draft, in-progress, Finance-pending, non-terminal-transaction, or otherwise out-of-scope sheet is not readable through the Finance branch, even when the evidence id is known.
5. **Existing behavior unchanged**: purchaser, Cost Center Manager, and Senior Manager evidence reads return exactly their previous results; audit/revision visibility is unchanged; deactivated evidence keeps its existing 403.

### 13. Purchaser regression

**Run** the existing account controller/charge suites and the purchaser account flow.

**Expected**:

- a purchaser's own account list and movement ledger are unchanged;
- a purchaser cannot reach another user's holder record or accounts through the Finance module;
- the existing recharge/charge behavior and audit trail are unchanged.

## Frontend validation (when implementation is authorized)

```bash
npm test -- --run tests/Frontend/Component/Finance
npm run typecheck
npm run lint
npm run stylelint
```

**Expected**:

- the ledger renders exactly six columns in order;
- Approved shows `5 of 10` (localized) or the localized N/A;
- Recharge/Expense are separate; only one has a value per row;
- pagination preserves prior balances;
- negative balances use the owed label in both languages;
- completed history has no mutation actions and its evidence preview is read-only (no upload/replace/delete);
- recharge review/confirm/failure/unknown states are distinct;
- every new `t('pettycash', ...)` key exists in `en.json`, `en.js`, `fa.json`, and `fa.js`;
- RTL/LTR layout uses logical properties and `:dir="direction"`;
- dialogs trap focus, restore focus, close on Escape, and expose accessible names;
- loading, error, and empty states exist on every surface.

## Milestone gate

```bash
npm run check
scripts/dev/phpunit
```

`npm run check` covers the frontend gates only; PHP tests are separate. Do not rerun unchanged broad suites after every small change; run focused checks at each batch boundary and the agreed milestone gate once.

## Browser and visual evidence

- Playwright in **official Google Chrome** at desktop and the approved responsive viewport.
- Cover: holder discovery and display-name search, account inspection, ledger paging, recharge confirmation/duplicate/unknown, completed history read-only with evidence preview, EN/FA direction.
- Store screenshots under `artifacts/visual-review/<task>/` and cite repository-relative paths.
- Owner visual approval remains a separate gate. A green test run is not visual approval.

## Route/cache handling

After `appinfo/routes.php` changes:

```bash
scripts/dev/route-settle
scripts/dev/route-cache-clear
```

After `lib/` or `appinfo/` PHP edits, run `scripts/dev/opcache-settle` before direct live-HTTP checks. A stale-cache result is not evidence.

## Stop conditions

Stop and report if:

- a required wrapper is missing/non-executable;
- a mandatory requirement cannot be met without schema change;
- the evidence extension cannot be confined to content-only, completed-history-scoped authorization and would require widening `EvidenceVisibility`, audit visibility, mutation guards, or a new route;
- Chrome is unavailable for the visual gate;
- implementation would require changing purchaser-owned reads or the canonical contract without separate authorization.
