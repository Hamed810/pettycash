# Bug Assessment: Calendar picker closes prematurely on month/year navigation

- **Slug**: `calendar-picker-navigation-premature-close`
- **Created**: 2026-11-24T14:10:00Z
- **Source**: pasted text
- **Verdict**: **valid** (root cause confirmed by code inspection of `CalendarDatePicker.vue`)
- **Severity**: **high**

## Report (verbatim or summarized)

The product owner reported a functional defect in the running application:

1. Open the transaction expense-date picker.
2. Click the previous-month or next-month arrow.
3. The entire calendar popup closes immediately.
4. The user cannot freely navigate between months and years.

The defect is not limited to Jalali mode — both Gregorian and Jalali calendar systems must be verified. The previously approved visual design must remain unchanged.

## Symptom

When the user clicks a calendar navigation control (previous-month arrow, next-month arrow, month selector, year selector, or any interactive element inside the popup), the entire calendar popup closes immediately. The user cannot navigate between months or years without the popup dismissing. Expected: the popup remains open during internal navigation; only clicking outside the picker, pressing Escape, or intentionally selecting a day should close it.

## Reproduction

1. Open the transaction expense-date picker (CREATE or EDIT).
2. The input is focused and the popup is open (`isOpen = true`).
3. Click the next-month or previous-month arrow inside the calendar popup.
4. Focus moves from the input to the clicked arrow/button, firing a `blur` event on the input.
5. `onInputBlur` schedules `closePicker()` after 150 ms (`setTimeout`).
6. The popup closes before or immediately after the navigation action completes.
7. Repeated navigation requires reopening the picker each time.

[NEEDS CLARIFICATION: Confirm the exact timing of the close relative to the navigation action in the live lab.]

## Suspected Code Paths

- `src/components/common/CalendarDatePicker.vue:120-123` — `onInputBlur` calls `setTimeout(() => closePicker(), 150)` on every input blur, including blur caused by focus moving to internal calendar controls.
- `src/components/common/CalendarDatePicker.vue:125-129` — `onInputKeydown` handles Escape/Enter/Tab on the input; Escape calls `e.preventDefault()` but not `e.stopPropagation()`, which may allow the Escape event to reach parent modal handlers in the EDIT modal.
- `src/components/common/CalendarDatePicker.vue:165` — `@click.self="closePicker"` on the popover div handles clicks on the popover background but not on child calendar controls (correct by design).
- `src/views/transactions/TransactionFormView.vue:785-795` — hosts `CalendarDatePicker` with `v-model="expenseDateModel"`; no additional popup lifecycle handling.

## Root Cause Hypothesis

**Confidence: high.**

The `onInputBlur` handler at `CalendarDatePicker.vue:120-123` unconditionally closes the picker 150 ms after the input loses focus. When the user clicks any interactive control inside the calendar popup (month/year navigation arrows, month selector, year selector, day cells), focus leaves the input and moves to the clicked control. The `blur` event fires, `onInputBlur` runs, and after 150 ms `closePicker()` sets `isOpen = false`, removing the popover from the DOM (`v-if="isOpen"`). This closes the popup before the user can complete navigation.

The `@click.self="closePicker"` on the popover does not cause this: it only fires when the click target is the popover div itself, not its children. Internal calendar controls are children, so `.self` correctly ignores them. The blur handler is the sole mechanism that closes the popup on internal focus transitions.

## Proposed Remediation

**Preferred**: Modify `onInputBlur` in `CalendarDatePicker.vue` to inspect `event.relatedTarget` (the element receiving focus). If the related target is inside the date picker component root (i.e., focus is moving to an internal calendar control), do not close the picker. Only close when focus leaves the component boundary entirely (related target is outside the component or null).

Also add `e.stopPropagation()` for the Escape key in `onInputKeydown` so that pressing Escape in the EDIT modal closes the picker without propagating to parent modal/dialog handlers that might dismiss the transaction modal.

**Alternatives**:
- Remove the blur-based close entirely and rely only on `@click.self` on the popover, Escape, and date selection for dismissal. Trade-off: clicking outside the component (e.g., on the page background) would no longer close the picker unless a document-level click listener is added. This is a larger change and less preferred.
- Add a `mousedown` handler on calendar controls to prevent blur. Trade-off: fragile, suppresses focus transitions, and does not address keyboard focus movement inside the popup.

**Files likely to change**:
- `src/components/common/CalendarDatePicker.vue` — `onInputBlur` and `onInputKeydown`
- `tests/Frontend/Component/CalendarDatePicker.spec.ts` — popup lifecycle regression tests

**Tests to add or update**:
- Clicking next month keeps the popup open.
- Clicking previous month keeps the popup open.
- Repeated month navigation works.
- Navigation across year boundaries works.
- Month selection keeps the popup open.
- Year selection keeps the popup open.
- Moving focus between internal calendar controls does not dismiss the popup.
- Month/year navigation does not emit a date-model mutation.
- Selecting a day updates the canonical value correctly.
- Selecting a day closes the popup.
- Clicking outside closes the popup.
- Escape closes the popup.
- Internal navigation does not submit the transaction form.

## Risks & Considerations

- **Canonical model unchanged**: the fix does not alter `toCanonical()`, `formatVisible()`, or the `update:modelValue` emission path. Only intentional day selection emits a model change.
- **No new dependencies**: no dependency changes, no `node_modules` patches.
- **No global styling**: all changes are scoped to `CalendarDatePicker.vue` script logic.
- **RTL/LTR**: no directionality impact; the fix is purely event-handling logic.
- **Focus indication preserved**: `isFocused` state and floating-label behavior are unaffected.
- **Disabled/read-only**: `openPicker()` already returns early for disabled/readonly; no change needed.
- **Escape in EDIT modal**: adding `stopPropagation()` prevents Escape from reaching parent modal handlers. Must verify this does not break the unsaved-changes guard or other Escape handlers higher in the tree.
- **Blur-based outside-click dismissal**: preserved for actual outside clicks where `relatedTarget` is outside the component or null.

## Open Questions

- [NEEDS CLARIFICATION] Is there a document-level or modal-level Escape handler in the EDIT modal path that might still dismiss the transaction modal after `stopPropagation()` is added?
- [NEEDS CLARIFICATION] Does the 150 ms blur timeout serve any purpose other than outside-click dismissal (e.g., debouncing rapid focus changes)? If so, the fix must preserve that behavior.

## Code intelligence path used

Source inspection of `src/components/common/CalendarDatePicker.vue` (full file read), `src/views/transactions/TransactionFormView.vue` (relevant sections), `@jalali-js/vue` type definitions (`Calendar.vue.d.ts`, `index.d.ts`), data-attribute inventory from `node_modules/@jalali-js/vue/dist/index.js`, and existing test files (`CalendarDatePicker.spec.ts`, `PurchaserFlow.spec.ts`, `MutationPayloadCalendar.spec.ts`, E2E evidence scripts `t084-calendar-picker-evidence.mjs` and `t084-equivalent-date-payload.mjs`). CGC and Serena were not needed because the relevant symbols and file paths are known exactly.
