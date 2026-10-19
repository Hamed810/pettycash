# Pettycash final design rules

## Scope

This review set covers only Dashboard, Sheets List, and Create/Edit Sheet. It is a static design prototype with synthetic data, not production app code and not a financial-system integration.

## Visual system

English LTR is the master layout. Persian RTL mirrors text, inline controls, row content, and action alignment while keeping the familiar Nextcloud left navigation rail. The green accent marks active and approved work; amber marks review; red marks correction/error; neutral surfaces carry completed or informational content.

The shell uses a 246px sidebar, compact top bar, fluid content capped at 1320px, quiet borders, restrained shadows, and modest radii. Final surfaces do not show a prototype/debug strip, UI prototype labels, workspace switchers, account mock controls, or custom locale/theme controls.

## Workflow rules

**Dashboard** shows purchaser-scoped existing workflow buckets: Open sheets, Awaiting review, Needs correction, and Completed. Quick links go to existing sheet browsing and create-sheet actions. No charts, invented balances, fake financial statistics, or new workflows are included.

**Sheets List** shows creator scope as informative text. Supported filters are Status, Currency, Date from, and Date to. The desktop table retains Creator, Currency, Status, Transactions, Active revision, Current/Submitted totals, Latest effective outcome, Stage-approved totals, Key dates, and View/Edit/Delete actions. At 390px, the same fields reflow into labelled cards rather than being silently dropped.

**Create/Edit Sheet** keeps Title, Account, inherited Currency, selected-account Balance, Cancel, and Create/Update. With zero accounts, no account balance is shown and the existing default-provisioning path remains valid. With one account, it is selected automatically. With many accounts, the form starts unselected; currency and balance remain blank until a user selects an account.

## Bidi and responsive rules

Money, currency codes, sheet IDs, and dates use `dir="ltr"`/`bdi` isolation. The 390px layout uses an off-canvas navigation rail, stacked form controls, and labelled sheet cards. Create/Edit's desktop side note is hidden on mobile so explanatory content is not duplicated.

## Review assumptions

The governing contract file was not present in the active filesystem during this refinement. The inspected Pettycash Vue views, stores, API types, and the user's written constraints were used as the binding sources. The purchaser role and dashboard buckets are the reviewed role-sensitive assumption; exact server-side role visibility remains for product-owner confirmation.
