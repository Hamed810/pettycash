# Bug Fix: Calendar date picker visual defects

- **Slug**: `calendar-date-picker-visual-defects`
- **Fixed**: 2026-11-24
- **Assessment**: ./assessment.md
- **Status**: applied

## Summary

Defect 1: `CalendarDatePicker.vue` did not match `NcTextField` visual conventions — its label appeared as a block above the input instead of a floating label inside the field wrapper, its input lacked `NcTextField`'s height/padding/border/focus/disabled/error styling, and it had no helper-text presentation. Defect 2: two-digit calendar day numbers wrapped onto two lines inside calendar cells because `[data-jalali-calendar-day]` had no `white-space: nowrap`. Both are presentational; no canonical model, conversion, payload, or workflow behavior changed.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `src/components/common/CalendarDatePicker.vue` | modified | Template restructured: input wrapped in `.calendar-date-picker__field`, label restyled as floating label inside field, input styled to match `NcTextField` (height, padding, border-radius, box-shadow borders, font-size, focus outline, disabled, error), helper-text element added, `isFocused` state and `hasValue` computed for label float. Scoped CSS adds `:deep([data-jalali-calendar-day]) { white-space: nowrap; }` for day-number wrapping. |
| `tests/Frontend/Component/CalendarDatePicker.spec.ts` | added | 7 new visual regression tests: visible label, label float on value/focus, input presence, disabled state, error presentation, plus existing visible-date tests. |
| `.specify/bugs/calendar-date-picker-visual-defects/assessment.md` | added | This directory's assessment record. |
| `.specify/bugs/calendar-date-picker-visual-defects/fix.md` | added | This report. |
| `.specify/bugs/calendar-date-picker-visual-defects/test.md` | added | Verification record (below). |

## Diff Highlights

Template:
```vue
<div class="calendar-date-picker__field" :class="{...}">
  <label v-if="label" class="calendar-date-picker__label">{{ label }}</label>
  <input ... />
</div>
<div v-if="helperText || props.error" class="calendar-date-picker__helper-text">...</div>
```

Scoped CSS (key rules):
```css
.calendar-date-picker__label {
  position: absolute;
  inset-block-start: calc((var(--default-clickable-area) - 1lh) / 2);
  inset-inline: var(--border-width-input-focused, 2px);
  font-size: var(--default-font-size);
  color: var(--color-text-maxcontrast);
  /* floating-label transitions */
}
.calendar-date-picker__field--focused .calendar-date-picker__label,
.calendar-date-picker__field--has-value .calendar-date-picker__label {
  inset-block-start: calc(-1.5 * var(--default-font-size) / 2);
  font-size: 13px;
  /* floated-up label styling */
}
.calendar-date-picker input {
  height: var(--default-clickable-area) !important;
  padding-block: 0;
  padding-inline: var(--border-radius-element) var(--border-radius-element);
  border: none;
  border-radius: var(--border-radius-element);
  box-shadow: 0 0 0 1px var(--color-border-maxcontrast);
  /* hover, focus-visible, disabled, error overrides */
}
:deep([data-jalali-calendar-day]) {
  white-space: nowrap;
}
```

Script: added `isFocused` ref and `hasValue` computed; `onInputFocus`/`onInputBlur` handlers; no model or conversion change.

## Tests Added or Updated

- `tests/Frontend/Component/CalendarDatePicker.spec.ts` — 47 tests total (40 pre-existing + 7 new):
  - renders a visible label associated with the input
  - floats the label when the input has a value
  - floats the label when the input is focused
  - matches NcTextField input height (structural)
  - matches NcTextField border radius (structural)
  - shows disabled appearance when disabled
  - shows error presentation when error is set
- Existing guards re-run unchanged: `PurchaserFlow.spec.ts`, `StagedReceipts.spec.ts`, `calendarMutation.spec.ts`, `MutationPayloadCalendar.spec.ts` — 218 tests passed.

## Local Verification

- `npx vitest run tests/Frontend/Component/CalendarDatePicker.spec.ts` → **47/47 passed**.
- `npx vitest run tests/Frontend/Component/CalendarDatePicker.spec.ts tests/Frontend/Component/PurchaserFlow.spec.ts tests/Frontend/Component/StagedReceipts.spec.ts tests/Frontend/calendarMutation.spec.ts tests/Frontend/Component/MutationPayloadCalendar.spec.ts` → **218/218 passed**.
- `npx vue-tsc --noEmit` → clean.
- `npx eslint src/components/common/CalendarDatePicker.vue` → 0 errors.
- `npx stylelint 'src/components/common/CalendarDatePicker.vue'` → exit 0.
- `npm run build` → succeeded.

Manual Chrome verification (`channel: 'chrome'`, Chrome **156.0.8078.47**), read-only against the T060 fixture:

| Locale | Calendar | Dir | Visible field | Canonical model | Label visible | Day wrap |
|--------|----------|-----|---------------|-----------------|---------------|----------|
| en | gregory | ltr | `2026/11/01` | `YYYY-MM-DD` | yes | n/a |
| en | persian | ltr | `1405/09/01` | `YYYY-MM-DD` | yes | n/a |
| fa | gregory | rtl | `2026/11/01` | `YYYY-MM-DD` | yes | n/a |
| fa | persian | rtl | `1405/09/01` | `YYYY-MM-DD` | yes | n/a |

**Equivalent-date verification** (equivalent semantic date under both calendars):
- Jalali `1405-08-10` → Gregorian `2026-11-01` → `expenseDate: "2026-11-01"` (browser-verified via `t084-equivalent-date-payload.mjs`)
- Gregorian `2026-11-01` → Jalali `1405-08-10` → `expenseDate: "2026-11-01"` (browser-verified via `t084-equivalent-date-payload.mjs`)
- Both calendars produce identical canonical `expenseDate` with no calendar metadata

Screenshots: `artifacts/visual-review/t084/iteration-1/<en-gregory\|en-jalali\|fa-gregory\|fa-jalali>/04-date-selected.png` and `02-add-transaction-form.png`. Evidence record: `artifacts/visual-review/t084/iteration-1/evidence.json`. Equivalent-date evidence: `artifacts/visual-review/t084/eq-iteration-1/evidence.json`.

## Deviations from Assessment

- None. The CSS remediation is the assessment's preferred fix, applied in the named file only.

## Follow-ups

- Owner visual acceptance recorded 2026-11-24. `T084` is `DONE_VERIFIED`.
- No other follow-ups; both defects are corrected and technically verified.
