# Domain facts recovered by user's GLM report (not independently reverified)

Source order for correctness: approved Spec Kit and contracts > domain model > implementation > tests > frontend > prototype image placeholders.

- Sheet has title, owner, account, currency, derived status, submitted snapshot, createdAt, firstSubmittedAt (set only once), completion metadata, lock version. No Sheet Cost Center, no monthly Sheet period.
- Transaction belongs to a Sheet; business fields live in TransactionRevision: expenseDate, amountMinor, description, costCenterId and historical snapshots, expenseTypeId and snapshots; evidence; stage-specific decisions; versioning.
- A Sheet can have many transactions with DIFFERENT Cost Centers. Managers review transaction-by-transaction in their assigned scope; role-specific screens are OUT of current purchaser design.
- Sheet OPEN = never submitted, its transactions all DRAFT, and purchaser can create/update/delete while authorized. Submitting moves it to staged workflow. Multiple concurrently OPEN sheets allowed.
- Transaction statuses reported: DRAFT; AWAITING_COST_CENTER_REVIEW; COST_CENTER_APPROVED; COST_CENTER_REJECTED; AWAITING_SENIOR_REVIEW; SENIOR_APPROVED; SENIOR_REJECTED; AWAITING_FINANCE_REVIEW; AWAITING_PURCHASER_CORRECTION; COMPLETED_APPROVED; COMPLETED_REJECTED. Sheet status is derived from outstanding stage(s), with COMPLETED or COMPLETED_WITH_REJECTIONS as terminal variants.
- APPROVE/REJECT/RETURN are categorical per transaction/revision/stage, no amount partial approvals. A rejected transaction may continue to a later stage; finance cannot approve senior-rejected. Returns create correction and later new revision resubmits from Cost Center; prior approval/decision/audit kept immutable.
- Transaction create via POST and patch via PATCH independently; evidence upload after a transaction exists; expectedVersion and command-id required in actual API, backend errors 409/422 etc.
- `totals.current` is sum of active revision amounts. `totals.effectiveApproved`, `effectiveRejected`, `effectivePending` depend on latest effective decision across stages; submitted snapshot stays from first submission. Stage-specific sums exist and differ from effective totals.
- Real Expense Type (`expense_type_id`), not Category. Vendor and Notes and arbitrary attachment MIME whitelist from mockups are unverified/unapproved and should not be incorporated without an explicit source.
- `First Submitted` first submission date; no sheet monthly range. Reports' Jalali months are a distinct business requirement; UI calendar preference does not modify report semantics.
- Existing `GET /api/v1/configuration/cost-centers` lists available Cost Centers. The 'previous transaction cost center' default is NEW owner-approved frontend behavior, not existing backend.
- No sheet-wide audit aggregate endpoint; per-transaction revisions and audit endpoints exist.

**Do not treat this condensed report as an independently verified implementation audit.** When building the production feature, reread current Spec Kit and code. Manus is building an independent illustrative prototype here, not binding APIs.
