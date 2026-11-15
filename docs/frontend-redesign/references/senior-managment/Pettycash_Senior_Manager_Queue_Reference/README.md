# PettyCash Senior Manager Queue — Visual Reference Implementation

This is a small, dependency-free HTML/CSS/JavaScript reference implementation of the approved Senior Manager Queue design. It is intended for visual comparison and handoff to the production development team; it is not a production workflow or backend implementation.

## Entry point

Open [`index.html`](./index.html) directly in a browser, or serve the directory with any static HTTP server:

```bash
python3 -m http.server 4173
```

Then open `http://localhost:4173/`.

## File structure

```text
Senior_Manager_Queue_Reference/
├── index.html   # Page shell, RTL Persian markup, queue, modal, preview states
├── styles.css   # Explicit visual tokens, desktop layout, modal styles, responsive rules
├── app.js       # Placeholder data and lightweight reference interactions
└── README.md    # Handoff notes and state inventory
```

## Main UI sections

- `index.html`: sidebar, top bar, breadcrumbs, Senior Manager page header, responsibility banner, summary cards, Cost Sheet queue, sheet modal, and separate document preview overlay.
- `styles.css`: all spacing, sizing, typography, colors, border radii, grids, flex layouts, badges, cards, filters, actions, and mobile rules are explicit CSS values.
- `app.js`: realistic placeholder Cost Sheets and transactions, queue rendering, modal opening/closing, thumbnail preview opening/closing, and a minimal visual-only quick-decision interaction.

## Implemented visual states

- Desktop Persian RTL queue.
- Responsive/mobile layout: sidebar collapses into a compact navigation strip, sheet rows become stacked cards, and the modal becomes full-width within the viewport.
- Sheet queue with four placeholder Cost Sheets.
- Selected sheet modal with summary, transaction list, previous Cost Center decision badges, quick actions, mandatory-comment visual treatment, attachment thumbnail strips, advanced filter bar, category chips, sorting controls, report action, and Excel action.
- Separate attachment preview overlay for a selected image/PDF-type attachment.
- Close modal and close preview controls.
- LTR-safe identifiers and numeric values through explicit `direction:ltr` / `unicode-bidi:isolate` styles.

## Intentionally visual/reference-only areas

- Buttons such as filters, report generation, and Excel export are represented visually; no backend, API, file generation, or business persistence is implemented.
- Placeholder data is hard-coded in `app.js` and must be replaced by production services/types.
- The quick-decision interaction only changes the visible badge in the selected card; it does not submit a decision or implement audit history.
- The document preview uses a CSS receipt illustration rather than real files. Production code should connect the thumbnail to the project’s existing image/PDF viewer.
- The modal is initially visible to match the approved reference state. Set `openModal()` behavior differently if the production page should start with the modal closed.
- The design preserves the approved visual structure but does not invent routing, API contracts, permissions, or backend business rules.

## Production handoff notes

Reuse the production repository’s existing review components and visual tokens rather than copying this static implementation directly. The approved hierarchy is sheet-first: Cost Sheets appear in the queue, and Cost Center decisions appear only inside their transactions after the sheet modal opens. The Senior Manager’s own decision is a separate stage and must not overwrite the previous decision.
