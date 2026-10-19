# Remaining design corrections — اصلاحات پیش از Prototype

These are **not an invitation to redesign** accepted visuals. Address only contradictions that prevent accurate implementation.

## P0 — Business rules, consistency

- **Sheet status vs transaction status.** OPEN = never submitted, all transactions DRAFT. Submitted sheet enters actual derived stage status; transaction statuses may diverge after individual reviews. COMPLETED/COMPLETED_WITH_REJECTIONS has no current `AWAITING_PURCHASER_CORRECTION` or other pending transaction.
- **Per-stage accounting.** Each workflow stage displays the number and amount **actually approved, rejected, and still pending at that stage** (not copied from final/latest outcome). Stage-specific historical and latest effective decisions need not equal final approval. Owner requested **no returned summary line under stage boxes**, though RETURN is still a genuine transaction status/decision.
- **Financial arithmetic.** E.g. a 5-transaction illustrative set `25.00 + 18.50 + 42.00 + 15.00 + 25.00 = 125.50 USD`. If transaction `15.00` is finally rejected and all else approved, `effectiveApproved=110.50`, `effectiveRejected=15.00`, `effectivePending=0`. Ensure counts add to 5; no `125.50 approved` when a rejected item remains.
- **Temporal correctness.** Prior images mix Jalali `1402` and 2024 dates inconsistently. Calendar preference must be global, independent of language, and all Gregorian/Jalali pairs correct. No date-period fields on Sheet (monthly refers to reports).
- **State-aware actions.** No pencil on submitted/complete sheet title; no `Add Transaction` for any non-OPEN sheet; no enabled or disabled `Submit Sheet` placeholder on completed or submitted views. Show `Submit Sheet` only when actionable. Sheet delete in overflow only while OPEN, authorized owner, never submitted. No sheet-global Save button.
- **Cost Center per transaction**, not sheet; `Expense Type` not `Category`.
- **Transaction form:** spec-supported fields only. Separate POST/PATCH save from evidence upload; evidence requirements depend on Expense Type. Remove unsupported vendor/notes fields and arbitrary restrictive 10MB/filetype helper text until verified in current spec.
- **Correction:** RETURN ≠ REJECT. Correction is returned transaction revision; no direct editing of submitted/approved transactions. Submission/resubmission are separate commands.
- **Approver permissions:** single-transaction decisions, no bulk decision UI, no partial amount approvals; admin not automatically approver.

## P1 — Visual/interaction polish

- Fixed tabs and transaction list header; scroll only transaction list body for 30+ rows, keep footer actions accessible.
- Row hover (2–3px, 150–200ms) and keyboard activation; inner transaction form transitions instead of stacked modals.
- When leaving dirty transaction/metadata form, require Unsaved Changes confirmation and restore previous list location. Save failure keeps edit state.
- Compact per-stage stacked statistics; no standalone Total card. Overview should avoid repeating header metadata.
- Mobile at 390px and tablet: table → labeled cards; no orphan numbers or horizontal overflow, support long titles and dates.
- Approved visual style must be kept, not replaced wholesale.

## Needs owner review after PNG

- Unsaved guard dialog: placement, copy, and three actions.
- Mobile card and modal layouts, sticky headers and navigation, collapsed stage-summary design.
