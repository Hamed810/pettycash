# Pettycash Purchaser Prototype Plan

## Scope
This is an independent, illustrative purchaser-only prototype. It does not modify the real Pettycash repository, backend, API, or manager/finance/admin interfaces. UI copy is English LTR. The implementation preserves room for future EN/FA and RTL support through semantic labels and centralized state.

## Implementation approach
- Use a dependency-light vanilla HTML/CSS/JavaScript app served by a small Node HTTP server on port 3000.
- Keep synthetic fixtures in `src/data.js`-equivalent inline data so the prototype can run without authentication or external services.
- Use one modal/workspace shell: desktop sheet detail is a large modal over the list; mobile turns the same shell into a full-screen workspace. Transaction detail/edit/correction is an internal view with Back, never a nested modal.
- Persist demo edits, calendar preference, theme, list filters and created transactions in localStorage. All write actions are labeled or described as MOCK behavior.
- Model domain rules explicitly: Cost Center per transaction, Expense Type, immutable revisions, categorical decisions, stage-specific summaries, OPEN-only add/edit/delete, separate Save and Submit/Resubmit.
- Include demo state controls for OPEN, Cost Center Review, Returned Correction, Completed Approved, and Completed with Rejections.
- Use accessible buttons, labels, focus-visible styling, Escape handling, reduced motion, and a dirty-form guard for close, back, cancel and browser navigation.

## Design direction
- Design movement: Pettycash/Budget-inspired operational dashboard with calm card surfaces and green action emphasis.
- Core principles: status-first hierarchy, compact but breathable cards, transaction-level truth, and responsive continuity between desktop and 390px mobile.
- Color philosophy: pale blue-gray surfaces reduce visual noise; ink navy is used for durable reading; green signals allowed action/approval; amber and red are reserved for returned/rejected states.
- Layout paradigm: a focused list-to-workspace transition rather than a page maze; content expands from a row into a modal/workspace while preserving context.
- Signature elements: green wallet mark, stage rail with compact metrics, and transaction cards that expose Cost Center and Expense Type together.
- Interaction philosophy: actions appear only when the current purchaser/status permissions allow them; every meaningful save is individual and mock-persistent.
- Animation: 150–200ms hover lift and restrained transitions; respect `prefers-reduced-motion`.
- Typography: system UI stack with strong navy headings, 14–16px body copy, and high-contrast focus rings.
- Brand essence: a trustworthy, low-friction expense sheet workspace for purchasers; calm, explicit, auditable.

## Project structure
- `index.html`: app shell and semantic regions.
- `styles.css`: responsive light/dark theme and component system.
- `app.js`: fixtures, state machine, rendering, interactions, dirty guard, local persistence.
- `server.js`: zero-dependency static server with SPA fallback.
- `public/manus-routes.json`: route manifest.
- `docs/`: contract mapping and known gaps.
- `exports/`: screenshots captured from the built prototype.
- `assets/`: approved/generated visual references used for handoff traceability.

## Build workflow
1. Run `npm install` (no third-party runtime dependency is required; this creates a lockfile if desired).
2. Run `npm start`.
3. Open `http://localhost:3000` or the Manus Preview URL.
4. Use the demo state selector, open a row, inspect tabs, open a transaction, edit a draft/correct a returned transaction, and toggle theme/calendar.
5. Run the smoke checks in README and capture major screen exports.
