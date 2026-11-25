# Bug Test: Calendar picker closes prematurely on month/year navigation

- **Slug**: `calendar-picker-navigation-premature-close`
- **Created**: 2026-11-24T14:50:00Z
- **Source**: `.specify/bugs/calendar-picker-navigation-premature-close/assessment.md`, `.specify/bugs/calendar-picker-navigation-premature-close/fix.md`
- **Verdict**: verified
- **Severity**: high

## Regression tests added

File: `tests/Frontend/Component/CalendarDatePicker.spec.ts` — new describe block `CalendarDatePicker: popup lifecycle on internal navigation` with 12 tests:

| # | Test | Assertion |
|---|------|-----------|
| 1 | keeps the popup open when focus moves to a next-month control | Popover still visible after blur with `relatedTarget` = next-month button |
| 2 | keeps the popup open when focus moves to a previous-month control | Popover still visible after blur with `relatedTarget` = previous-month button |
| 3 | keeps the popup open across repeated month navigation | Popover stays open across 3 sequential blur+advanceTimers cycles |
| 4 | keeps the popup open when focus moves to a month selector | Popover still visible after blur with `relatedTarget` = month selector |
| 5 | keeps the popup open when focus moves to a year selector | Popover still visible after blur with `relatedTarget` = year selector |
| 6 | does not dismiss when focus moves between internal calendar controls | Popover stays open when focus moves from one internal control to another |
| 7 | does not emit update:modelValue during internal navigation | `emitted(EMITTED_UPDATE)` unchanged after internal blur |
| 8 | closes the popup when focus leaves the component boundary | Popover closes after blur with `relatedTarget = null` + 150ms timer |
| 9 | closes the popup on Escape | Popover closes after Escape keydown on input |
| 10 | closes the popup and emits update:modelValue on day selection | Day selection emits canonical date and closes popover |
| 11 | cancels a pending close when focus returns to the input | Popover stays open when focus returns before timeout elapses |

Tests use `vi.useFakeTimers({ toFake: ['setTimeout'] })` to control the 150 ms close delay, and dispatch real `FocusEvent` objects with controlled `relatedTarget` to simulate focus moving to/from internal calendar controls.

## Existing tests preserved

All 47 pre-existing CalendarDatePicker tests continue to pass (calendar conversion, visible date formatting, model binding, validation, styling, disabled/readonly, etc.).

## Browser verification

File: `scripts/verify-calendar-navigation.mjs`

Mandatory browser journey executed in official Google Chrome (`{ channel: 'chrome' }`) via Playwright, all 4 combos passed:

| Combo | Locale | Calendar | Direction | CREATE | EDIT | Outside-click | Escape |
|-------|--------|----------|-----------|--------|------|---------------|--------|
| en-gregory | en | gregory | LTR | pass | pass | pass | pass |
| en-jalali | en | persian | LTR | pass | pass | pass | pass |
| fa-gregory | fa | gregory | RTL | pass | pass | pass | pass |
| fa-jalali | fa | persian | RTL | pass | pass | pass | pass |

Each combo verified:
- Open date picker, record selected date
- Click next month 3× — popup remains open
- Click previous month 3× — popup remains open
- Select different year via year selector — popup remains open
- Select different month via month selector — popup remains open
- Expense date unchanged throughout navigation
- Select a valid day — popup closes, canonical date updates
- CREATE/EDIT payload has `expenseDate`, no calendar metadata
- Outside click closes popup
- Escape closes picker (EDIT: without closing parent modal)

Evidence: `artifacts/visual-review/calendar-picker-navigation-premature-close/iteration-1/evidence.json` and per-combo `evidence.json` files (Git-ignored).

## Commands executed

```
npx vitest run tests/Frontend/Component/CalendarDatePicker.spec.ts        → 59 passed
npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts             → 161 passed
npx vitest run tests/Frontend/Component/MutationPayloadCalendar.spec.ts   → 3 passed
npx vitest run tests/Frontend/http.spec.ts                                → 32 passed
npx vue-tsc --noEmit                                                       → passed
npx eslint src/components/common/CalendarDatePicker.vue tests/...         → no errors
npx stylelint 'src/components/common/CalendarDatePicker.vue'              → passed
npm run build                                                              → passed
VERIFY_ITERATION=iteration-1 node scripts/verify-calendar-navigation.mjs  → 4/4 combos passed
```

## Final status

**verified** — all acceptance criteria met:

- Popup remains open during previous-month, next-month, repeated, and year-boundary navigation.
- Popup remains open during month selector and year selector use.
- Popup remains open during keyboard focus movement inside the popup.
- Internal navigation does not change the canonical expense date, emit `update:modelValue`, mark the form dirty, submit the form, or trigger an API mutation.
- Popup closes on intentional day selection, outside click, and Escape.
- Escape in the EDIT modal closes the picker without closing the parent transaction modal.
- Both Gregorian and Jalali calendar systems verified in English LTR and Persian RTL.
- CREATE and EDIT forms verified.
- Previously approved visual design preserved.
