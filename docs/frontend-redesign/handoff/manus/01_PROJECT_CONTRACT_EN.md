# Pettycash UI Design Contract — Approved direction / last updated 2026-10-19

> **Superseded behavior note (2026-10-27):** the sheet's source account is **immutable after creation**; only the title may change while the sheet is `OPEN`, and the account/currency are displayed read-only. Any statement below that a sheet's **Account** is editable is a 2026-10-19 prototype-direction statement and is withdrawn. Normative policy: 001 `FR-019`/`FR-021`; decision record `D-026`; delivery 001 `T438`–`T441` (all implemented and verified 2026-10-28). This document remains design direction only and creates no behavior authority.

## Scope and process
- Redesign existing **Nextcloud Pettycash frontend**, never rebuild product; no new business capabilities.
- Purchaser view **only** in this phase. Manager (Cost Center, Senior), Finance, Administrator views intentionally later.
- Preserve the approved Pettycash/Budget-inspired clean cards, green accent, spacing, typography, responsive layout, sensible dark theme, and visual character. No redesign from zero.
- Visual PNGs are authoritative for accepted *look*, Spec Kit and domain contracts for *behavior*. When they conflict, contracts win, and flag the issue for the owner.
- PNG first for any *new/unapproved* screen → owner approves → only then prototype code. Existing approved screens can proceed to prototype without re-approval.
- Prototyping in **English LTR only** for now; no new Persian/RTL design. Language and calendar are separate preferences, so the English UI can show Jalali dates. End product remains EN/FA bilingual.
- Prototype only: never write to production repository, never invent an API or modify backend.

## Purchaser Sheets List
- Only own sheets; no Creator or row Actions column.
- Title ABOVE sheet ID, no decorative last-two-digits badges, no chevron, no decorative date next to New Sheet.
- Six information columns: Sheet, Status, Transactions, Recorded Amount, Latest Approved Amount, First Submitted.
- Recorded = `totals.current`; Latest Approved = `totals.effectiveApproved`.
- `First Submitted` = time of FIRST-ever submission (not latest resubmission). An unsubmitted OPEN sheet has no submittedAt and displays an em dash.
- OPEN never-submitted sheets always FIRST in either sort direction; then submitted sheets sort by First Submitted asc/desc. Server pagination global sort requires an approved separate change ticket `BE-CR-01` (NO backend modification now). In mock prototype make sorting accurate for its complete fixture dataset.
- Subtle row hover raise/shadow (about 2–3px, 150–200 ms), clickable whole row, keyboard focus and Enter, opening Sheet Detail **modal over the same list**, preserving search/filter/sort/scroll position upon close.

## Sheet Detail workspace
- Responsive wide modal on desktop and full-screen workspace on mobile; do not stack modal upon modal. Drill down to transaction form/detail in the same workspace with internal Back behavior.
- Header displays actual SheetStatus, title and ID, account, currency derived from account, relevant created/first submitted/completed dates.
- Only when owner+OPEN+never-submitted: contextually edit Title and Account; Delete Sheet in 3-dot overflow with explicit confirmation; Add Transaction one round `+` in fixed transaction table header; Submit Sheet when prerequisites met. No global Save All.
- OPEN sheet means never submitted and **every transaction DRAFT**. Once submitted the sheet leaves OPEN, independent transaction decisions can yield mixed states; purchaser cannot casually edit/add/delete; only returned transaction correction via revision/resubmit.
- Hide irrelevant/disabled add and submit controls on submitted and completed views; no edit pencil on submitted or completed titles, no sheet-level Cost Center.
- Two top summary amounts from existing API: Recorded (`totals.current`) and Latest Approved (`totals.effectiveApproved`). In terminal state approved is final; do not invent partial approvals or misleading zero/pending confusion.
- Transactions: # (display index, NOT transaction ID), Expense Date, Description, Cost Center, Expense Type, Amount, Status; latest decision where relevant. Expense Type, NOT Category.
- Scroll transaction body for 30+ items while keeping header/tabs and footer reachable; sticky table column labels; on mobile use readable cards, no awkward horizontal overflow.
- Hover/click transaction opens state-aware editable Draft / Correction Revision / read-only detail inside same workspace.
- Workflow: Cost Center → Senior → Finance → Completed; under **each** stage display compact stage-specific approved/rejected/pending counts and amounts; NOT final results pasted under each stage, not one free-floating Total card; no dedicated RETURNED row under workflow stage summaries. Returned state still matters at transaction level.
- Terminal states must only contain terminal transactions; `COMPLETED_WITH_REJECTIONS` if any final rejection. A sheet with any returned/pending transaction cannot be shown as fully completed.
- Approvals tab per-transaction stages and actual decisions, not bulk. Overview emphasizes cost center financial aggregates rather than redundant header metadata. History uses real per-transaction revisions/audit as conceptual data source (no invented sheet-wide history API).

## Transaction forms
- Save one transaction independently through existing transactional operations. No sheet-level Save All. Submit Sheet and resubmit correction are **separate domain actions**.
- Cost Center selected PER TRANSACTION, multiple Cost Centers in one Sheet supported. New transaction Cost Center defaults to the last **created and persisted** transaction's center in the same Sheet, independent of visual sort; overridable, blank if none or invalid/inactive.
- Currency comes from Sheet account (read-only), not a user-selectable transaction currency.
- Domain fields: Expense Date, Amount, Description, Cost Center, Expense Type, Evidence/Attachments, and existing IDs/status/revisions as read-only; no unverified Vendor, Notes, custom Category.
- When a type requires evidence, enforce its requirement according to approved spec. Save the transaction first, THEN attach evidence to the existing transaction/revision. Do not fabricate `PDF/JPG/PNG only` or `10MB maximum` restrictions.
- Correction = RETURN + new revision + dedicated Resubmit; previously submitted or approved transactions cannot simply be edited.
- Read-only transaction view includes immutable decision history, revisions, evidence and allowed actions. No nested modal stacks.

## Unsaved change guard
- APPROVED visual and behavior; do not redesign it.
- Dirty forms warn on ×, Escape, cancel/back, navigation or browser Back. Continue editing / Discard changes / Save changes (only where active individual form has valid saving semantics). A failed save never closes the form. No fake global sheet save.

## Permissions and sample data
- Existing permissions, owner scope, current status, allowedActions govern UI. Administrator is not automatically an approver.
- Every decision is APPROVE, REJECT or RETURN for ONE transaction/revision/stage. No partial amount approval, bulk approval, revision overwrite or fake resubmission dates.
- Keep amounts/counts consistent across stage summaries, totals, list, sheet detail and transaction detail. Use accurately converted Jalali/Gregorian examples.
