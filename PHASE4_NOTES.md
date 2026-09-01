# Phase 4 implementation notes

## Audit and revisions

Submitted financial data is not destructively edited. A substantive transaction change creates a revision. Approval actions are append-only and reference the revision where applicable.

## Returned transactions

A purchaser can modify only the returned transaction while the Cost List is in Manager 1 review. Saving the correction creates a new revision and routes the transaction to Manager 1 again.

## Manager 2 edits

Manager 2 can correct financial data, but doing so invalidates the old Manager 1 decision. The changed revision returns to `PENDING_M1`. Comments alone do not invalidate approval.

## Evidence

Receipts and supporting evidence are stored in private application data rather than a purchaser-controlled Files folder. Submitted evidence is retained for audit. When evidence on a returned transaction is replaced/removed from the current revision, its database/history record and stored file are preserved rather than silently destroyed.

## Sensitive attendance evidence

The application stores attendance/fingerprint evidence documents, not reusable biometric fingerprint templates. Access is limited to the purchaser who owns the transaction and authorized project managers/accountants/admins.

## Concurrency

Transactions and Cost Lists use version fields. Approval/edit calls carry the version observed by the client so stale decisions can be rejected rather than overwriting newer work.

## Money and dates

Money is persisted as integer minor units. Business dates are shown in Jalali; normalized purchase dates are stored Gregorian. Event timestamps are UTC and displayed using `Asia/Tehran` business time.

## OCR

OCR database fields/settings remain reserved, but OCR is intentionally deferred until the accounting workflow is operational. The future provider must support Persian + English, Persian/Arabic digit normalization, Jalali dates, and Rial/Toman detection with purchaser confirmation.
