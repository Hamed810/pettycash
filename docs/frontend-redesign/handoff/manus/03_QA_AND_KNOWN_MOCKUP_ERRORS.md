# QA corrections required before declaring prototype complete

1. Some approved-looking desktop drawings show a Submitted Sheet with edit pencil/delete or Add Transaction. **Hide all unauthorized actions** for submitted/completed states.
2. Some Completed examples still show RETURNED or PENDING transactions. This is **invalid**. Completed has only terminal approved/rejected transactions, and the appropriate terminal SheetStatus label.
3. Sample financial totals must add up exactly: as an example expenses $25.00 + $18.50 + $42.00 + $15.00 + $25.00 = $125.50. If $15.00 is rejected terminally, final approved is $110.50 (not $125.50). Multiple-stage results must reflect each stage separately.
4. Prior mockups use invalid Gregorian ↔ Jalali pairs and inconsistent Created/Completed dates. Use library-validated exact calendar mapping, or show a single calendar with a correct preference switch; never hardcode wrong paired dates.
5. Prior transaction-create pictures contain a banner saying 'this sheet has been submitted', yet display Add Transaction. **A create form is only accessible while OPEN.**
6. The `Vendor` and `Notes` fields, '10 MB' limit, and strict PDF/JPG/PNG-only helper text in older mockups were not substantiated by approved specifications. **Do not implement invented fields or restrictions.**
7. No global Sheet Save action; save each transaction or editable sheet metadata independently and use Submit separately.
8. Approvals are per transaction; fake single bulk approval button would violate domain.
9. Per-stage cards: Approved/Rejected/Pending *of that stage*, NO independent total column/card and NO Returned stage-row, even though the underlying returned transaction is shown elsewhere.
10. Don't waste credits regenerating approved Unsaved Changes or past desktop visuals; resolve missing critical PNGs only.
11. Inconsistent name labels (e.g. `SUBMITTED` instead of actual derived stage) must be status-aware, not a false static SheetStatus.
12. Responsive 390px mobile: no illegible tiny fonts, clipped footer, orphan counts, or uncontrolled overflow; transaction detail should be full-screen within modal-workspace pattern.
