# UI-PUR-015 — Accessibility and theming parity for the redesigned purchaser surfaces

- **Priority:** P2 · **Status:** PROPOSED · **Size:** M · **Risk:** low
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-058, GAP-059, GAP-070 · **Backend impact:** NONE

## 1. User-visible outcome

The redesigned purchaser screens behave for keyboard and screen-reader users the way the rest of the application already does: motion can be turned off, invalid fields are announced, focus is always visible, and every colour comes from the theme rather than from the design mock-up's palette.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| A real accessibility baseline exists | 61 `aria-label`s, 39 `role=` attributes, 9 `aria-live` regions, 9 `:focus-visible` rules under `src/`; `tests/E2E/keyboard-accessibility.spec.ts`; `tests/Frontend/Component/NextcloudVueContract.spec.ts` |
| No reduced-motion support | `prefers-reduced-motion`: 0 occurrences under `src/`; the prototype implements it (`prototype/manus-purchaser-v1/styles.css:264-266`) |
| Field errors are visual-only | `aria-invalid`: 0 occurrences; `aria-describedby`: 1; errors flow through `error`/`helper-text` props (`src/views/transactions/TransactionFormView.vue:388-389`) |
| The prototype carries its own token set | `styles.css:3-47` (light + dark values for ~16 semantic colours, `--radius:16px`, `--focus`, an Inter-first font stack) |
| The design system is pinned | `@nextcloud/vue` 9.12.0 (`package.json`); D-002 requires its use; the contract test guards against substitutes |
| FR-071's narrow-viewport floor is test-pinned | `tests/Frontend/fr071NarrowViewport.spec.ts:61,98,126`; `src/styles/_responsive.scss` |

## 3. Authoritative references

- `docs/frontend-redesign/DECISIONS.md` D-002 (Nextcloud design system and components) and D-006 (RTL/LTR per Nextcloud).
- FR-071 `spec.md:1156` — 390px floor without horizontal scroll.
- Accepted direction: light and dark both supported; no parallel UI framework (Vuetify explicitly not part of the approved stack).

## 4. Allowed scope / non-goals

**In scope**
- Add `prefers-reduced-motion` handling for every transition/animation the redesign introduces (workspace open/close, tab changes, dialogs, toasts), in one place, so future screens inherit it.
- Associate validation errors with their inputs: `aria-invalid` on the invalid control plus `aria-describedby` pointing at the error text, for the sheet form and the transaction form (the two editable purchaser forms).
- Represent the mock-up's semantic colour decisions with existing Nextcloud theme variables and `@nextcloud/vue` components: status pill colours, amount emphasis, surface elevations, radii, focus ring. **No new token file, no hardcoded hex values, no Inter import.**
- Only if the owner wants one: a short documented mapping table from each prototype token to the Nextcloud equivalent used instead — this is the audit trail for the visual comparison, and prevents a future contributor from "restoring" the mock-up's palette.

**Non-goals**
- No accessibility rework of the manager/finance/admin surfaces (out of the purchaser redesign's scope, even though some of the same gaps exist there).
- No change to the existing keyboard journeys' assertions.
- No new dependency (no `vue-a11y` plugin, no focus-trap library — the trap needed by `UI-PUR-005` is small and local).
- No colour-scheme invention beyond what the theme provides; if the design needs a colour the theme does not have, raise it rather than adding a constant.

## 5. Implementation behaviour

- Reduced motion: when the media query matches, transitions collapse to no-op (0s) and any spinning affordance is replaced by a static state; nothing may rely on animation to convey meaning.
- Error association: the visible error text and the `aria-describedby` target must be the same element (not a duplicate string), so the association cannot drift.
- Focus visibility: the focus ring uses the theme's focus colour and remains visible on both light and dark backgrounds and on top of the workspace overlay.
- Theme: the redesign renders correctly in the app's dark theme without any component-level overrides; where the mock-up's light palette differs, the theme wins.
- Notifications/toasts announce politely (`aria-live="polite"`) as the existing pattern does.

## 6. Acceptance criteria

1. With `prefers-reduced-motion: reduce` emulated, opening/closing the workspace and switching tabs performs no animation, and no information is conveyed only by motion (negative case: the state is still readable).
2. Submitting an invalid sheet form or transaction form sets `aria-invalid="true"` on each invalid control and `aria-describedby` resolving to the visible message (assert the id resolves, not just that the attribute exists).
3. No hex colour, no `px` radius constant and no font-family declaration introduced by the redesign survives into the shipped styles (grep-based negative assertion over the changed files).
4. All redesigned surfaces render correctly in dark theme (screenshot evidence for the owner at both themes).
5. The focus ring is visible on: the list row action menu, the workspace close control, the tab strip, and every form control — verified in both themes.
6. `tests/Frontend/Component/NextcloudVueContract.spec.ts` passes with no exemption added.
7. FR-071's floor remains green (`fr071NarrowViewport.spec.ts` unchanged).

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/NextcloudVueContract.spec.ts` | no substitute component or token introduced |
| Component | `npx vitest run tests/Frontend/Component/RtlAccessibility.spec.ts` | direction + 390px floor unchanged |
| E2E | extend `tests/E2E/keyboard-accessibility.spec.ts` and `browser-matrix.spec.ts` (TO_VERIFY: lab) | reduced-motion emulation, dark theme, focus rings |
| Gate | `npm run lint && npm run stylelint && npm run typecheck` | clean |

## 8. Dependencies / rollback

- **Depends on:** the surfaces it audits (`UI-PUR-002`, `UI-PUR-005`, `UI-PUR-006`) — run it as the closing task of the first batch, not before them, or the assertions will describe screens that have moved.
- **Rollback:** additive CSS/markup; a revert removes only the added rules.

## 9. Definition of Done

- [ ] Reduced motion, error association and theme mapping done for all redesigned purchaser surfaces.
- [ ] Screenshots at light + dark for the owner.
- [ ] The token mapping table (if written) committed under `docs/frontend-redesign/review/` — not in `DECISIONS.md`, which this audit must not modify.

## 10. Approval gate

**PROPOSED.** Not approved. The one item needing a decision is whether the owner wants the prototype→theme mapping table; everything else is standard project practice (D-002, FR-071) and does not expand scope.
