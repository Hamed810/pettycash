# Project Petty Cash — Phase 4 source

Native Nextcloud application implementing the approved Project Petty Cash workflow through the two-manager approval stage.

## Baseline

- App ID: `pettycash`
- App version: `0.4.0`
- Nextcloud: `34–35`
- PHP: `8.3–8.5`
- Required PHP extensions: `intl`, `fileinfo`
- Frontend: Vue 3 + TypeScript + Vite + `@nextcloud/vue`
- Business timezone: `Asia/Tehran`
- Business calendar: Jalali / Persian
- Default currency: IRR
- Reserved OCR languages: Persian (`fa`) + English (`en`)

## Implemented

### Foundation and master data

- Native Nextcloud application bootstrap and attribute routes.
- Initial database schema and seed migration.
- Currencies: IRR (default), EUR, USD, AED, CNY; administrators can add/update currencies.
- Projects and project membership roles: Purchaser, Manager 1, Manager 2, Accountant.
- Manager 1 and Manager 2 separation validation.
- Configurable expense categories and category-specific requirements.
- Vehicle master data.
- Server-side authorization service.

### Purchaser workflow

- One open Cost List per purchaser/project.
- Jalali year/month Cost Lists and Tehran business timezone.
- Integer minor-unit money handling, including Persian/Arabic digit normalization.
- Category-aware transaction entry.
- Vehicle + odometer fields and lower-odometer warnings.
- Temporary/daily employee fields.
- Typed attachments: receipt, Hiring Permit, attendance/fingerprint evidence, other.
- Private application-controlled evidence storage using Nextcloud AppData.
- JPG/PNG/PDF MIME validation, 15 MB limit, SHA-256 attachment hashes.
- Transaction revisions on every substantive edit.
- Close & Submit validation and locking.
- Purchaser history and manager decision comments.
- Returned transactions can be corrected without reopening unrelated transactions.

### Approval workflow

- Manager 1 and Manager 2 review queues.
- One-by-one transaction review.
- Approve, Reject/Exclude, Return for Correction, and manager Edit.
- Reasons required for Return/Reject and manager edits.
- Purchaser cannot approve their own transaction.
- Manager 2 financial edits invalidate Manager 1 approval and route the new revision back to Manager 1.
- Append-only approval actions and audit events.
- Manager 1 approved totals and final approved totals.
- Optimistic version checks to avoid stale approval decisions.
- Authorized evidence preview/download route.

## Workflow status implemented

```text
OPEN
  -> M1_REVIEW
  -> M2_REVIEW
  -> ACCOUNTING
```

Transaction flow includes:

```text
DRAFT
PENDING_M1
APPROVED_M1 / REJECTED_M1 / RETURNED_M1
PENDING_M2
FINAL_APPROVED / REJECTED_M2 / RETURNED_M2
```

A corrected returned transaction creates a new revision and re-enters `PENDING_M1`.

## Not implemented yet

The schema anticipates these modules, but they are not complete in v0.4.0:

- Accountant queue and `PROCESSED` action.
- Monthly Jalali accounting report.
- CSV export and evidence ZIP package.
- Printable accounting report.
- OCR worker/provider integration.
- Persian receipt amount/date/vendor extraction and Rial/Toman assistance.
- Native Nextcloud notifications/activity timeline.
- Full automated integration/end-to-end test suite.

## Install into a Nextcloud development instance

Copy/extract the source as:

```bash
nextcloud/custom_apps/pettycash
```

or your configured Nextcloud app directory.

Install frontend dependencies and build assets:

```bash
cd custom_apps/pettycash
npm install
npm run build
```

The package follows the current Nextcloud template baseline and requests Node 24 / npm 11.

If Composer is used in your deployment/build pipeline:

```bash
composer install --no-dev --optimize-autoloader
```

Enable the app from the Nextcloud root:

```bash
php occ app:enable pettycash
```

For an upgrade from an older development copy:

```bash
php occ app:update pettycash
```

## Validation performed on this source

```bash
find . -name '*.php' -not -path './vendor/*' -not -path './build/*' -print0 | xargs -0 -n1 php -l
php tools/smoke.php
```

The Phase 4 archive was checked with PHP 8 syntax linting and the standalone Jalali/Persian-number/money smoke tests. The Vue bundle is source-ready but was not compiled in the packaging container because the project npm dependencies are not installed there.

## Next implementation phase

Phase 5 is the accountant/reporting module:

1. Accountant queue for `ACCOUNTING` Cost Lists.
2. Monthly Jalali project reports.
3. Final-approved transaction aggregation only.
4. CSV export.
5. Authorized evidence package download.
6. Mark-as-processed action and audit event.

OCR follows after the core accounting workflow is stable.
# pettycash
