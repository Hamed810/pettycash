# Design to Domain Mapping

## Authority order

The supplied approved desktop/mobile visual references control look and hierarchy. The approved project contract and recovered domain rules control behavior, statuses, actions and data semantics. Where a visual reference contains contradictory sample data or controls, this prototype preserves the visual language but corrects the behavior.

| Visual direction | Domain implementation in this prototype |
|---|---|
| Clean Pettycash/Budget-inspired cards, pale background and green CTA | Implemented in `styles.css` with a restrained light theme and a coherent dark theme. |
| Sheets list with six columns and whole-row click | List uses Sheet, Status, Transactions, Recorded Amount, Latest Approved Amount and First Submitted. Rows are keyboard-focusable and preserve list state underneath the modal. |
| Desktop modal / mobile full-screen detail | One workspace shell switches from centered modal to `100dvh` mobile view. Transaction drill-down is an internal view, not a nested modal. |
| Two summary amounts | Recorded uses the active revision sum. Latest Approved uses the effective approved terminal decision sum. No partial amount approval is invented. |
| Per-stage workflow rail | Cost Center, Senior and Finance cards each show their own approved/rejected/pending counts and amounts. There is no independent global workflow Total card and no Returned stage row. |
| Transaction table/cards | Rows contain display index, expense date, description, Cost Center, Expense Type, amount and status. Mobile switches to cards. |
| Add Transaction visual | Available only for an OPEN, never-submitted sheet; every OPEN transaction is DRAFT. Save is individual. |
| Returned correction visual | Correction updates a new immutable revision. Save correction and Resubmit transaction are separate actions. |
| Read-only detail/revision visuals | Terminal or approved transactions show immutable detail, evidence state, decisions and revision audit without edit controls. |
| Unsaved Changes visual | The already-approved guard is implemented for close, Escape, Cancel, Back and browser navigation. |

## Synthetic fixture decisions

`Office Supplies` uses the reconciled example amounts `$25.00 + $18.50 + $42.00 + $15.00 + $25.00 = $125.50`. The completed-with-rejections fixture terminally rejects the `$15.00` transaction, so its effective approved amount is `$110.50` and rejected amount is `$15.00`. `Cost Center` is stored on every transaction. `Expense Type` is used in every form and table; there are no Vendor, Notes, arbitrary Category or invented MIME/size restrictions.

The calendar switch changes presentation only. Fixture pairs are stored together, including Jalali `1402/10/06` / Gregorian `2023-12-27`. First Submitted is only populated once on submitted fixtures. LocalStorage is a demo persistence layer, not a production data store.

## Intentionally illustrative behavior

The prototype uses synthetic actor labels, in-memory/localStorage mutation, local filtering/sorting/pagination and buttons marked or described as MOCK. It does not implement expectedVersion, command-id, API error envelopes, authentication, permission service, file transfer, or real evidence storage. These are documented further in `KNOWN_GAPS.md`.
