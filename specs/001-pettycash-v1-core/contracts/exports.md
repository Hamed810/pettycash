# Report and Export Contract

## General Rules

- Export authorization is identical to interactive view authorization.
- Every report query is role-scoped before filtering, aggregation, or file generation.
- Exports use active revisions for current business data and include immutable historical records
  only when the selected export type calls for them.
- Spreadsheet cells beginning with formula control characters are escaped.
- Generated artifacts use private transient application storage and are removed after streaming.
- V1 exports are synchronous. Scheduled delivery and external-system integration are excluded.

## Formats

| Format | Use | Rules |
|--------|-----|-------|
| CSV | Flat report or table | UTF-8, explicit column header row, locale-neutral machine values |
| XLSX | Multi-table reports and complete sheets | One or more named sheets; typed numeric/date cells |
| PDF | Human-readable standard report | Persian RTL-capable embedded font; no custom letterhead or signature |
| ZIP | Grouped evidence | Original files plus UTF-8 manifest linking sheet, transaction, revision, and evidence metadata |

## Complete Sheet XLSX Layout

1. `Sheet`: owner, currency, status, dates, current/submitted/effective/stage totals.
2. `Transactions`: current active revision fields and final/current state.
3. `Decisions`: one row per immutable stage decision.
4. `Revisions`: every authorized revision and its snapshot fields.
5. `Audit`: authorized event chronology with before/after values.
6. `Evidence`: metadata, active state, uploader, transaction, and originating revision.

## Group Evidence Manifest

Required columns:

- sheet public ID and title;
- transaction public ID;
- revision number and public ID;
- evidence public ID, original name, media type, size, checksum, and active state;
- uploader UID/display name and upload time;
- archive-relative path.

Evidence outside the requester's scope is omitted before the archive is built. The manifest lists
only files actually present in the archive.

## Totals

- Current total: active revision amounts.
- Submitted total: first-submission snapshot.
- Effective approved/rejected/pending: active revision grouped by latest effective decision.
- Stage approvals: independent manager, senior, and finance approval sums.
- Reports label the effective stage and do not describe pre-finance approval as settled.

## Idempotency (T346)

`POST /exports` requires `X-PettyCash-Command-ID` and stamps it on refusals, but
does not replay through `IdempotencyService`: FR-084 covers replay of mutating
commands, and an export leaves no durable state (the transient artifact is
deleted after streaming), so there is no stored outcome to return. Persisting
the base64 artifact bytes for replay would put up to 100MB (FR-074) into a TEXT
result column. A retried export regenerates; the command id still correlates
the attempts in logs and refusals.

## Acceptance Checks

- Purchaser exports contain only the purchaser's records.
- Cost-center and senior exports contain only assigned centers.
- Finance exports contain only finance-stage or historical finance-scope records permitted by the
  report contract.
- Administrator exports may include all records but do not grant decision authority.
- CSV opens without formula execution from user-controlled text.
- XLSX contains the promised sheets and numeric values.
- PDF renders Persian RTL text, money, dates, and mixed-direction identifiers correctly.
- ZIP filenames are safe and the manifest-to-file mapping is complete.
