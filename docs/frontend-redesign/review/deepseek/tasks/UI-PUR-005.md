# UI-PUR-005 — Sheet detail workspace: the large overlay over the list

- **Priority:** P1 · **Status:** PROPOSED · **Size:** L (the largest card in the set; split only if a reviewer asks)
- **Risk:** medium–high (navigation model + focus management + mobile)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-017 (shell half), GAP-062, GAP-070, GAP-073 · **Backend impact:** NONE

## 1. User-visible outcome

Selecting a sheet opens a large workspace **over** the list — Overview / Approvals / History — that the purchaser can close back to exactly where they were. On a phone it becomes a full-screen layer, and drilling into a transaction replaces the workspace's body rather than stacking a second dialog on top of the first.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| Sheet detail is a route view, not an overlay | `src/router/index.ts:57`; opened by `SheetListView.vue:405` |
| No modal primitive exists for it | the only `NcDialog`s in the purchaser flow are `src/components/shared/DeleteSheetDialog.vue:3,86` and `src/views/transactions/TransactionDetailView.vue:404` |
| No tabs on the detail view | `src/views/sheets/SheetDetailView.vue` (totals card `:355-390`, transactions table `:403-425`) |
| `NcActions` is unused anywhere in `src/` | so no overflow/menu pattern exists to copy |
| Design references | `references/desktop/` (11 refs); `references/dialogs/unsaved-changes-en-desktop-v1.png`; mobile refs in `references/mobile/` |
| Prototype: one overlay shell, desktop modal → ≤680px full screen, drill-down as an internal view | `prototype/manus-purchaser-v1/styles.css:123-124,234-235`; `app.js:228-234` |
| Prototype a11y defects that must not be inherited | `aria-modal` with no focus trap, no focus return, tabs as plain buttons without `role="tablist"`/`aria-selected`, no Escape while confirming — `app.js:214,233,244,248,351` |
| Accessibility baseline to preserve | `tests/E2E/keyboard-accessibility.spec.ts`; `tests/Frontend/Component/NextcloudVueContract.spec.ts` |

## 3. Authoritative references

- Accepted direction: "Selecting a sheet opens a large detail workspace/modal over the list, preserving scroll/filter state on close. In mobile, adapt the workspace accessibly without a nested modal stack."
- **FR-088** `spec.md` — saving a draft must return the purchaser to the sheet's detail view where the status, transactions and Submit control are presented together. **This is the strongest anchor for the workspace: it names what must be visible in one place.** The current route-based detail satisfies FR-088 today (`TransactionFormView.vue:212-216`); the overlay must keep satisfying it.
- `spec.md:1049-1057` (FR-063) — the detail must still carry identity, owner, currency, status, count, dates, current and submitted totals, summary amounts and the latest active revision number.
- `contracts/ocs-api.yaml` — `SheetDetail` = `SheetSummary` + `allowedActions` + `transactions`.
- `handoff/manus/01_PROJECT_CONTRACT_EN.md` — Overview/Approvals/History structure and the "no sheet-level audit is invented" disclaimer the History tab must display.

## 4. Allowed scope / non-goals

**In scope**
- An overlay workspace presented over `/sheets`, with Overview / Approvals / History, opened from a row, closed back to the preserved list state (`UI-PUR-004`).
- Focus management to the WAI-ARIA dialog pattern: `role="dialog"`, `aria-modal="true"`, an accessible name from the sheet title, focus moved in on open, contained while open, **returned to the invoking row** on close, Escape closes (when nothing is dirty — see `UI-PUR-012`).
- Tabs as a real tablist (`role="tablist"`, `role="tab"`, `aria-selected`, arrow-key navigation) or as `NcAppSidebar`-style navigation with equivalent semantics — one of the two, documented.
- Mobile: at the project's existing narrow breakpoints (`src/styles/_responsive.scss`: ≤640px cards, ≤480px tightening) the workspace becomes a full-screen layer; the transaction drill-down replaces the workspace body. **No dialog above a dialog.**
- Present the sheet's status and the transactions' stages/statuses with the existing label vocabulary; no new status names.
- Loading/empty/error states for the workspace and for a sheet that vanished while open (the current view already handles the deleted-record case — `SheetDetailView.vue:69` — and the overlay must not regress it).

**Non-goals**
- No change to what the sheet detail fetches — one `GET /sheets/{sheetId}` (`SheetDetailView.vue:86`, docblock) — and no new endpoint.
- No sheet-wide history API. The History tab aggregates per-transaction endpoints or is omitted; `spec.md:240` documents why the aggregate does not exist, and the prototype's disclaimer (`app.js:226`) must be reproduced honestly.
- No stage/decision metric cards (that is `UI-PUR-014`).
- No transaction table rework (that is `UI-PUR-006`).
- No metadata inline editing (that is `UI-PUR-007`).
- No visual redesign of the manager/finance/admin surfaces.
- No navigation-library addition; `vue-router` + the existing component set is sufficient.
- Do not copy the prototype's token system (`styles.css:3-47`): re-express every colour, radius, shadow and focus ring through Nextcloud design tokens / `@nextcloud/vue` 9.12.0 (D-002; `NextcloudVueContract.spec.ts` is the guard).

## 5. Implementation behaviour

- **Entry:** row activation (mouse + keyboard) opens the overlay; the row's identity is exposed to the workspace for focus return.
- **URL:** the overlay must remain linkable/deep-linkable to the extent the current route allows (`/sheets/:id` already exists). Recommended: keep the route and render the list beneath it, so refresh and shared links keep working. **Decide and document** — a pure component-level overlay that loses deep links would be a regression against today's behaviour.
- **Close paths:** explicit close, Escape, and the browser back button. All three must agree with `UI-PUR-012`'s unsaved-changes guard: with a dirty form inside, closing prompts.
- **Nested content:** drilling into a transaction pushes an internal view (breadcrumb: sheet → transaction) with its own back control that returns to the workspace, not to the list.
- **Scroll:** the list beneath keeps its offset; the overlay body scrolls independently; the page behind must not scroll while the overlay is open.
- **Accessibility:** verify at least one complete keyboard journey: open → switch tabs → drill into a transaction → back → close → focus is on the originating row.
- **Theming:** light and dark both render legibly; `prefers-reduced-motion` handled in `UI-PUR-015` but the transitions added here must be written so that task can disable them in one place.

## 6. Acceptance criteria

1. Activating a row opens a workspace over the list; the list is not unmounted/lost and returns to the same state on close.
2. `role="dialog"` + `aria-modal="true"` + an accessible name equal to the sheet title; focus is inside on open.
3. Tab/Shift+Tab cannot leave the workspace while it is open (negative case: 30 iterations of Tab never reach the page behind).
4. On close (any path), focus is on the row that opened it.
5. Escape closes when nothing is dirty; with a dirty inner form it delegates to the unsaved-changes dialog (`UI-PUR-012` — this criterion is marked TO_VERIFY until that card lands, and must not be faked).
6. The three tabs are reachable and switchable by keyboard, with the selected tab announced.
7. At ≤640px the workspace is a full-screen layer with no horizontal scroll at 390px, and drilling into a transaction does **not** produce a second dialog element in the DOM stack (assert the count of `[role="dialog"]` elements is 1).
8. A sheet deleted elsewhere while the workspace is open produces a clear not-found state, not a blank page.
9. Saving a draft transaction still terminates on the sheet's detail view (FR-088) — and in the overlay model that means the workspace shows the new transaction and the Submit control without an extra navigation.
10. No new dependency added to `package.json`; no Vuetify; no local colour constants introduced (D-002).

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | open/close, tab switching, focus in/out, `[role=dialog]` count, not-found state |
| Component | `npx vitest run tests/Frontend/Component/NextcloudVueContract.spec.ts` | no non-approved component or token introduced |
| Component | `npx vitest run tests/Frontend/Component/RtlAccessibility.spec.ts` | 390px floor and RTL/LTR both |
| E2E | `npm run test:e2e` — extend `tests/E2E/keyboard-accessibility.spec.ts` and `tests/E2E/purchaser-submit.spec.ts` (TO_VERIFY: lab availability) | the full keyboard journey; screenshots at desktop and mobile |
| Gate | `npm run check` | clean |

## 8. Dependencies / rollback

- **Depends on:** `UI-PUR-004` (list state) for criteria 1 and 4; `UI-PUR-002` for the row's final markup; `UI-PUR-012` for criterion 5.
- **Blocks:** `UI-PUR-006`, `UI-PUR-014`, `UI-PUR-016` (they fill this shell).
- **Rollback:** keep the route-based detail reachable behind the same route name so a revert restores today's behaviour without touching bookmarks.

## 9. Definition of Done

- [ ] Overlay shell with three tabs, deep-linkable, list state preserved.
- [ ] Full keyboard journey demonstrated (test output + a short screen recording or screenshot set for the owner).
- [ ] Mobile full-screen behaviour with no nested dialog, evidenced.
- [ ] `npm run check` clean; no dependency changes.

## 10. Approval gate

**PROPOSED.** Not approved. This card changes the navigation model of the primary purchaser flow; it should be reviewed specifically for the deep-link decision in §5 and the mobile nesting rule before any implementation begins.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Current status:** `READY_FOR_OWNER_APPROVAL` (the header's `PROPOSED` means exactly this; nothing here is approved).
- **D-01 applied:** the Unsaved Changes dialog is approved, and the mobile purchaser screens under `docs/frontend-redesign/references/mobile/` are the current mobile visual references. The 9 images are listed in `BLOCKED_ITEMS.md` §4 — the mobile acceptance criteria of this card now have concrete references and are no longer waiting on a design decision.
- **D-11 applied:** the tabbed workspace is part of the design.
- **Sequencing:** this card consumes `UI-PUR-004` (preserved list state) and the shared primitives from `UI-PUR-002`; it must not implement its own competing state restoration.
- The prototype's missing focus trap must not be ported (`GAP-073`): focus entry, trap and return-to-row are part of this card's acceptance criteria.
