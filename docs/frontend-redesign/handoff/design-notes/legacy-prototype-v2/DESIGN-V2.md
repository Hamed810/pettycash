# Pettycash Sheets + Modal Workspace — Design v2 (review prototype)

**Scope:** English LTR only. This is an independent, synthetic HTML/CSS/JavaScript prototype based on the supplied Manus archive. The original Manus export is preserved separately. Nothing in the Pettycash repository, backend, or APIs was changed.

## Agreed layout

The purchaser's Sheets list shows **Sheet, Status, Transactions, Recorded Amount, Latest Approved Amount**. Creator and row Actions are removed. Sheet title is a keyboard-reachable link; selecting any row or mobile card opens a wide Sheet Workspace modal without navigating away from the list. On narrow screens the workspace is full-screen. Clicking Close returns to the same list position. Filter controls are local demonstrations.

**Recorded Amount** is the current total entered by the purchaser, regardless of submission status. **Latest Approved Amount** is the amount from the latest valid **completed** approval stage, or zero before any stage completes. Examples: Open / cost-center waiting → 0; senior waiting → cost-center figure; finance waiting → senior figure; completed → finance figure. Returned/corrected revisions need authoritative server-side validity checks in production; the current mock records are only illustrative.

All primary actions are inside the modal, never in list rows. An **Open** sheet shows Edit / Delete and transaction actions. A **Purchaser correction** sheet shows Correct transactions (not an unrestricted sheet edit). A sheet in finance review or completed is read-only. The backend must continue to enforce authorization.

Dirty edit or transaction forms prompt on Close / Escape / backdrop / in-modal Back. The prompt offers **Save changes**, **Discard changes**, and **Keep editing**. Invalid forms must not close on Save. A transaction detail sub-view reuses the same modal surface; no stacked sheet/transaction dialogs. Browser back closes the modal for normal, clean flows; production must use real Vue Router navigation guards for complete history handling.

## How to preview

From this directory: `python3 -m http.server 3000`, then visit:

- `http://localhost:3000/?page=sheets&lang=en&theme=light` (desktop light)
- `http://localhost:3000/?page=sheets&lang=en&theme=dark` (dark)
- `http://localhost:3000/?page=sheets&lang=en&theme=light&sheet=PC-2401` (deep-linked example)

**All data, filtering, editing, saving, correction, and deletion are simulated in browser memory.** No persistence, real user permissions, accounting validation, or API connection. There are no new commercial features here.

## Production integration tasks / decisions still pending

- Verify the meaning/validity of per-stage approved totals for returned and revised sheets in the real API before computing Latest Approved Amount.
- Render with the installed `@nextcloud/vue` and theme; remove all duplicate Nextcloud global chrome.
- Preserve existing Sheet Detail, transaction flows, data and permission contracts instead of copying this standalone JS into Vue.
- Implement nested interaction with Vue Router, focus restoration, accessible dialogs, keyboard trap, unsaved-change guards, plus server validation; write E2E.
- Persian/RTL redesign remains **paused** until the owner authorizes it.
