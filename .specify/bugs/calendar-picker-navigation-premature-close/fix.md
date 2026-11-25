# Bug Fix: Calendar picker closes prematurely on month/year navigation

- **Slug**: `calendar-picker-navigation-premature-close`
- **Created**: 2026-11-24T14:45:00Z
- **Source**: `.specify/bugs/calendar-picker-navigation-premature-close/assessment.md`
- **Verdict**: valid
- **Severity**: high

## Correction

**Root cause**: `onInputBlur` in `CalendarDatePicker.vue:120-123` unconditionally scheduled `closePicker()` after 150 ms whenever the input lost focus. Clicking any interactive control inside the calendar popup (next/previous month arrows, month selector, year selector, day cells) moved focus away from the input, firing `blur`. After 150 ms, `closePicker()` set `isOpen = false`, removing the popover from the DOM (`v-if="isOpen"`). This closed the popup before the user could complete navigation.

The `@click.self="closePicker"` on the popover was not the cause — it only fires when clicking the popover div itself, not its children. Internal calendar controls are children, so `.self` correctly ignored them. The blur handler was the sole mechanism closing the popup on internal focus transitions.

**Fix** (smallest correction, `src/components/common/CalendarDatePicker.vue` only):

1. Added `blurCloseTimer` ref to track the pending close timeout.
2. `onInputFocus(_e)`: cancels any pending close timeout when focus returns to the input.
3. `onInputBlur(e)`: inspects `event.relatedTarget`. If focus is moving to an element inside `.calendar-date-picker` (the picker boundary), the close is skipped. Only when `relatedTarget` is outside the component or null (genuine focus loss) is `closePicker()` scheduled after 150 ms.
4. `onInputKeydown(e)`: added `e.stopPropagation()` for Escape so Escape closes the picker without propagating to parent modal/dialog handlers in the EDIT modal.
5. `onPopoverEscape(e)`: new handler on the popover div (`@keydown.escape`) that closes the picker, prevents default, and stops propagation. This covers Escape pressed when focus is on internal calendar controls (not the input).

## Files changed

- `src/components/common/CalendarDatePicker.vue` — `blurCloseTimer` ref; `onInputFocus` cancels pending timer; `onInputBlur` checks `relatedTarget` before scheduling close; `onInputKeydown` adds `stopPropagation()` for Escape; new `onPopoverEscape` handler; `@keydown.escape="onPopoverEscape"` on popover div.
- `tests/Frontend/Component/CalendarDatePicker.spec.ts` — added 12 regression tests (see test record).
- `scripts/verify-calendar-navigation.mjs` — browser verification script (4 calendar/locale combos × CREATE + EDIT).
- `.specify/bugs/calendar-picker-navigation-premature-close/` — assessment, fix, and test records.
- `artifacts/visual-review/calendar-picker-navigation-premature-close/` — Chrome evidence screenshots and `evidence.json` (Git-ignored).

## What was NOT changed

- No dependency installation, upgrades, or `node_modules` patches.
- No package manifest or lockfile changes.
- No calendar redesign, global styling, or visual changes.
- No changes to unrelated date pickers, Personal Settings, backend, database, API contracts, financial rules, or authorization.
- No transaction workflow changes.
- Canonical model representation (`toCanonical()`, `formatVisible()`, `update:modelValue` path) unchanged.
- Previously approved visual styling preserved.

## Preserved behavior

- Calendar preference (gregory/persian) — unchanged.
- Gregorian and Jalali date calculations — unchanged.
- Canonical `YYYY-MM-DD` model values — unchanged.
- Visible date formatting — unchanged.
- Existing CREATE and EDIT values — unchanged.
- Floating labels — unchanged.
- Calendar grid layout — unchanged.
- Selected-day and current-day styling — unchanged.
- Keyboard accessibility — unchanged.
- Focus indication — unchanged.
- RTL/LTR behavior — unchanged.
- Disabled/read-only behavior — unchanged.
- Validation and error presentation — unchanged.
- Outside-click dismissal — preserved.
- Escape dismissal — preserved (with `stopPropagation` for EDIT modal).
- Day selection closes popup — preserved.

## Verification performed

- 59 CalendarDatePicker unit tests (including 12 new regression tests) — all passed.
- 161 PurchaserFlow tests — all passed.
- 3 MutationPayloadCalendar tests — all passed.
- 32 http tests — all passed.
- TypeScript typecheck (`vue-tsc --noEmit`) — passed.
- ESLint on changed files — no errors (1 pre-existing warning).
- Stylelint on changed file — passed.
- Frontend build (`vite build`) — passed.
- Chrome browser verification: 4 combos (en-gregory, en-jalali, fa-gregory, fa-jalali) × CREATE + EDIT — all 30 checks passed per combo.
