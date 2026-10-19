# Pettycash Purchaser Prototype

This repository contains an independent, editable, illustrative purchaser prototype. It is deliberately separate from the production Nextcloud/Pettycash repository and does not call a live API, modify a backend, or implement manager, finance, or administrator screens.

## Run locally

Requirements: Node.js 18 or newer.

```bash
cd /home/ubuntu/pettycash
npm install
npm start
```

Open `http://localhost:3000` in a browser. `npm install` is intentionally dependency-free for this prototype; it validates the package setup without requiring an external account.

## Preview and demo controls

The Manus Preview uses the project's configured port 3000. On the Sheets screen, use **Demo state** and **Open demo** to jump to OPEN, mixed Cost Center review, returned correction, completed approved, or completed with rejections. Select a sheet row to open the desktop modal; at 390px the same workspace becomes full-screen. Use Overview, Approvals and History tabs, then select transaction rows for their state-aware workspace.

- OPEN: add a transaction, edit drafts, attach evidence after save, and submit the sheet.
- Submitted/review: inspect per-transaction Cost Center decisions; add/edit/delete controls are hidden.
- Returned correction: save a new revision, then use the separate Resubmit transaction action.
- Terminal sheets: inspect read-only transaction details, decisions and revision history.
- Form guard: change a field, then use X, Escape, Cancel or Back. The approved Unsaved Changes confirmation keeps the form open or discards explicitly. Check **Simulate a save error** to verify that a failed individual save keeps the form open.
- The header toggles the independent Jalali/Gregorian date preference and light/dark theme. Preferences persist in local storage.

## Tested viewport targets

The interface is designed and checked for desktop widths around 1440px and mobile width 390px. The mobile cards use the approved full-screen workspace pattern without uncontrolled horizontal overflow. The transaction table switches to readable cards below 680px. It includes a 32-transaction Receipt Archive fixture to exercise a scrollable/sticky transaction body.

## Source map

- `index.html`: semantic app shell.
- `styles.css`: responsive light/dark UI system.
- `app.js`: synthetic domain fixtures, rendering, state transitions, local persistence, dirty guard and interactions.
- `server.js`: zero-dependency static server with SPA fallback.
- `public/manus-routes.json`: route manifest.
- `assets/generated-mobile/`: the three approved-scope generated mobile references plus the existing approved Unsaved Changes reference.
- `docs/`: domain mapping, known gaps, export mapping.
- `exports/`: PNG captures of the built app after preview verification.

## Important prototype boundary

Labels such as `(MOCK)` and the documentation below make clear that persistence is in-memory/localStorage only. The source intentionally models the approved business contract but does not claim to be production API behavior. Dates use validated example pairs such as Jalali `1402/10/06` and Gregorian `2023-12-27`.
