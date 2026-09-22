# Quickstart and Validation Guide: PettyCash V1 Core Workflow

**Date**: 2026-09-20

This guide defines the repeatable validation path the implementation must support. Commands that
refer to scripts not yet present are planned interfaces for the implementation tasks; they are not
evidence that code already exists.

## 1. Environment Prerequisites

- Project mounted from `/home/ubuntu/projects/pettycash` to
  `/var/www/html/custom_apps/pettycash`.
- Nextcloud target: 34.x.
- PHP target: 8.2–8.5. Do not install dependencies while the runtime reports PHP 8.1.
- MariaDB is the existing shared Nextcloud database.
- Node.js 20 LTS or a later version verified against the committed lockfile.
- The shared Nextcloud and database volumes must not be deleted or recreated.

The Docker container was stopped during plan creation. Start the existing environment through its
normal compose workflow before executing runtime checks; do not create a replacement environment
or remove volumes.

Verify the active runtime:

```bash
docker exec --user www-data docker-nextcloud-1 php -v
docker exec --user www-data docker-nextcloud-1 php occ status
docker exec --user www-data docker-nextcloud-1 php occ app:list | grep pettycash
```

Expected result: Nextcloud reports 34.x, PHP is within 8.2–8.5, and the `pettycash` application is
visible.

## 2. Migration Safety Gate

Before creating the first migration:

1. Inspect the host migration history for application ID `pettycash`.
2. Inspect existing table names without modifying them.
3. Record the highest prior migration version.
4. Confirm all new V1 tables use the `pc_*` family.
5. Confirm no migration drops, renames, or reads legacy `oc_pcash_*` tables as V1 entities.

The implementation must provide an installation test that begins with legacy tables present and
proves the new migration creates only the new schema.

## 3. Planned Dependency and Build Commands

From the repository root after the implementation establishes manifests and lockfiles:

```bash
composer install
npm ci
npm run typecheck
npm run lint
npm run stylelint
npm run build
```

Expected result:

- Composer resolves against the verified PHP/Nextcloud 34 baseline.
- TypeScript and Vue compile without errors.
- Deployable assets are emitted under `js/` and `css/`.
- The build does not write into other Nextcloud applications or shared volumes.

## 4. Planned Automated Quality Gates

```bash
composer run lint
composer run psalm
composer run test:unit
composer run test:integration
composer run test:contract
npm run test:unit
npm run test:e2e
```

Minimum expected coverage by suite:

- Unit: money, validation, state transitions, latest-effective totals, sheet status aggregation,
  authorization policy, immutable revision rules, export escaping.
- Integration: migrations, database indexes and locks, idempotency, AppData evidence lifecycle,
  workflow/audit atomicity, account movement reconciliation.
- Contract: every OCS route, error mapping, paging/filtering, permission denial, and streaming
  response described in `contracts/ocs-api.yaml`.
- Frontend: stores, service error mapping, validation, permission-driven presentation, RTL
  formatting, correction flow, evidence state, audit views.
- End-to-end: real Nextcloud authentication, CSRF/OCS behavior, all role journeys, exports, and
  direct unauthorized requests.

Any temporarily unavailable integration harness must be documented with repeatable manual steps
and a tracked task; it is not silently skipped.

## 5. Deterministic Acceptance Dataset

The test fixture must create, through approved application fixtures or APIs rather than direct
production-like database editing:

- an administrator who is not a finance officer;
- a purchaser-only user;
- a cost center manager who is also the purchaser for one self-review case;
- a senior manager;
- two finance officers sharing the finance queue;
- at least two cost centers with distinct manager assignments;
- rial plus one foreign currency with appropriate minor units;
- active and inactive expense types, including one that requires evidence;
- multiple petty-cash accounts, including an account whose valid consumption produces a negative
  balance;
- transactions spanning draft, each review stage, correction, approved, and rejected outcomes;
- inactive evidence, multiple revisions, disabled historical users, and complete audit history.

For performance acceptance, generate a separate non-production fixture approximating 100 active
users and 10,000 annual transactions.

## 6. End-to-End Business Validation

### Scenario A: Configuration and Funding

1. As administrator, create currencies, cost centers, manager assignments, and expense types.
2. Create or activate user petty-cash accounts.
3. As finance, charge a selected account.
4. Verify the movement, resulting balance, and configuration audit.
5. Verify the administrator cannot make a finance decision unless separately assigned finance.

### Scenario B: Purchaser Capture and Submission

1. Create two open sheets simultaneously.
2. Verify a user with one active account receives its fixed currency.
3. Verify a user with multiple accounts can select only active account currencies.
4. Save a transaction using only expense date and positive amount.
5. Attempt submission with missing description, cost center, expense type, or required evidence;
   verify field-level errors and no state change.
6. Complete the transaction, attach multiple evidence files, and submit.
7. Verify submission succeeds without requiring a positive account balance, creates the first
   formal audit event, and prevents new transactions in the submitted sheet.

### Scenario C: Management Review and Allowed Self-Review

1. Verify cost center managers see only assigned transactions.
2. Verify an authorized manager may decide their own transaction when scope and stage match.
3. Record approve, reject, and return decisions; verify reject/return require explanations.
4. Verify manager rejection remains visible and still routes to senior review.
5. Verify the senior manager may disagree without changing the earlier decision.
6. Verify sheet status remains the earliest outstanding stage while individual transactions may
   progress.

### Scenario D: Correction and Immutable Revision

1. Return a transaction from a management or finance stage.
2. Verify only the purchaser can edit the correction draft.
3. Change amount, date, description, classification, and evidence as permitted.
4. Resubmit and verify exactly one new revision becomes active at cost-center review.
5. Verify every earlier revision, decision, evidence state, actor, and audit value remains visible.

### Scenario E: Shared Finance Queue and Atomic Settlement

1. Verify both finance officers see the same finance-stage queue.
2. Verify a senior-approved transaction may be approved, rejected, or returned.
3. Verify a senior-rejected transaction cannot be approved by finance.
4. Approve an eligible transaction and verify exactly one consumption movement and balance change.
5. Replay the same command ID and verify the original result is returned without duplication.
6. Retry with the same command ID and different payload and verify conflict.
7. Force a failure between decision and movement persistence and verify no partial effect commits.
8. Submit simultaneous approvals and verify one succeeds while the other safely conflicts or
   replays.

### Scenario F: Evidence, Audit, and Disabled Users

1. Delete draft evidence and verify its metadata and binary are removed before submission.
2. Deactivate submitted evidence and verify the binary/history remain accessible to authorized
   users.
3. Verify unauthorized users cannot list, preview, download, link, or export the evidence.
4. Disable a historical actor and verify the stored UID, display name, role, decisions, and events
   remain unchanged.
5. Attempt to edit/delete audit events as administrator and verify no supported operation exists.
6. If public links are enabled, verify only administrators create/deactivate them, expired or
   inactive tokens fail, and create/deactivate events are audited.

### Scenario G: Reports and Exports

1. Verify purchaser, manager, senior, finance, and administrator reports contain exactly their
   permitted records.
2. Verify headline totals follow the latest effective outcome and stage totals remain separate.
3. Generate CSV, XLSX, PDF, and grouped evidence ZIP exports.
4. Verify spreadsheet formula injection is escaped.
5. Verify the XLSX sheet layout and ZIP manifest match `contracts/exports.md`.
6. Verify Persian RTL text, mixed-direction identifiers, amounts, and dates render correctly in
   PDF.

## 7. Browser, RTL, Responsive, and Accessibility Matrix

Run each primary role journey at minimum in:

| Browser | Desktop viewport | Mobile-sized viewport |
|---------|------------------|-----------------------|
| Chrome | 1440 × 900 | 390 × 844 |
| Edge | 1440 × 900 | 390 × 844 |
| Firefox | 1440 × 900 | 390 × 844 |

Validate:

- Persian strings and right-to-left direction;
- logical alignment of navigation, tables/cards, dialogs, filters, and actions;
- no page-level horizontal scrolling or clipped primary action;
- readable mixed-direction IDs, filenames, currencies, and numbers;
- keyboard navigation, visible focus, dialog containment/return, labels, error association, and
  live mutation feedback;
- light, dark, and high-contrast host themes;
- prohibited actions are absent in the UI and rejected by direct API calls.

Record browser/version, viewport, role, dataset, result, and failure evidence. Automated Playwright
Firefox results do not replace the final manual smoke in the current installed Firefox browser.

## 8. Performance Validation

Using the target-volume fixture:

1. Measure navigation-to-usable time for sheet list, sheet detail, transaction detail, audit
   history, and evidence list.
2. Exercise common filters and pagination.
3. Record at least 20 runs per view after warm-up and calculate the 95th percentile.
4. Pass when at least 95% of these routine views are usable within 3 seconds under normal local
   operating conditions.
5. Profile any failure before adding caches; first verify query scope, indexes, bounded pages, and
   avoidance of per-row queries.

## 9. Recovery and Non-Destructive Operations

- Never remove the shared Nextcloud or database volumes as part of testing.
- Migrations are forward-only and must be tested on a database containing legacy table names.
- If evidence write succeeds but database persistence fails, compensation removes the new orphan.
- A reconciliation diagnostic reports orphan AppData files and missing binaries without deleting
  anything automatically.
- Technical failures are written through host logging; they never substitute for business audit.
- Rollback of an application release means restoring the prior app package and compatible schema
  backup according to the host's established process, not rewriting audit or revision history.
