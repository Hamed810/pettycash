# Bug Fix: Calendar picker input alignment and localization defects

- **Slug**: `calendar-picker-alignment-localization`
- **Fixed**: 2026-11-24
- **Assessment**: ./assessment.md
- **Status**: applied

## Summary

Two defects in `CalendarDatePicker.vue`, both corrected with scoped changes to that component only. No canonical model, conversion, payload, or workflow behavior changed.

**Defect 1 — Input height and visual alignment.** `CalendarDatePicker.vue:242` set `height: var(--default-clickable-area)` directly on the `<input>`, while `NcTextField` wraps its `<input>` in a padded `.input-field__main-wrapper` (`height: var(--default-clickable-area)`, `padding: var(--border-width-input-focused, 2px)`) with `height: 100%` on the input. The date picker's input border-box rendered at `clickable-area` (4px taller than NcTextField's `clickable-area - 4px`) and picked up a stray `margin-top: 3px` from a global rule that NcTextField overrides via its scoped attribute selector. Restructured the field to mirror `NcTextField`: the `<label>` and `<input>` now live inside a `.calendar-date-picker__input-wrapper` (`height: var(--default-clickable-area)`, `padding: var(--border-width-input-focused, 2px)`, `position: relative`), and the input uses `height: 100% !important`, `min-height: unset`, `margin: 0`. Floating label, focus indication, disabled and error presentation are preserved.

**Defect 2 — Calendar localization.** `CalendarDatePicker.vue:185` passed `mapLocale(props.locale)` to `<Calendar>`, where `props.locale` came from `activeLocale()` — which reads `<html data-locale>` first. In Persian UI, `data-locale` stays `en_US` while `lang` is `fa`, so `mapLocale('en_US')` → `'en'` and the Calendar rendered English labels. The Calendar now receives `mapLocale(activeLanguage())`, where `activeLanguage()` reads `document.documentElement.lang` (the interface language). `props.locale` is still used by `formatVisible()` for visible-date numerals, so numeral formatting is unchanged. Calendar preference (`system`) remains independent of interface language.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `src/components/common/CalendarDatePicker.vue` | modified | Template: `<label>` and `<input>` wrapped in `.calendar-date-picker__input-wrapper` inside `.calendar-date-picker__field`. CSS: added `.calendar-date-picker__input-wrapper` rule mirroring `NcTextField`'s `.input-field__main-wrapper`; input uses `height: 100% !important`, `min-height: unset`, `margin: 0`. Script: import `activeLanguage` from `@/utils/locale`; `<Calendar :locale="mapLocale(activeLanguage())" />` so display labels follow the interface language while `props.locale` still drives `formatVisible` numerals. |
| `tests/Frontend/Component/CalendarDatePicker.spec.ts` | added | 2 new tests: input wrapped in padded input-wrapper matching NcTextField geometry; Calendar display labels sourced from `activeLanguage()` (interface language) not the date/number `locale` prop. |
| `.specify/bugs/calendar-picker-alignment-localization/assessment.md` | added | Assessment record. |
| `.specify/bugs/calendar-picker-alignment-localization/fix.md` | added | This report. |
| `.specify/bugs/calendar-picker-alignment-localization/test.md` | added | Verification record (below). |

## Diff Highlights

Template:
```vue
<div class="calendar-date-picker__field" :class="{...}">
  <div class="calendar-date-picker__input-wrapper">
    <label v-if="label" class="calendar-date-picker__label">{{ label }}</label>
    <input ... />
  </div>
</div>
```

CSS (key rules):
```css
.calendar-date-picker__input-wrapper {
  height: var(--default-clickable-area);
  padding: var(--border-width-input-focused, 2px);
  position: relative;
}
.calendar-date-picker input {
  height: 100% !important;
  min-height: unset;
  margin: 0;
  /* padding-block: 0, padding-inline, border-radius, box-shadow unchanged */
}
```

Script:
```ts
import { activeLanguage } from '@/utils/locale'
// ...
<Calendar
  :system="props.system"
  :locale="mapLocale(activeLanguage())"
  :value="selectedCalendarDate"
  @select="onDateSelect"
/>
```

## Tests Added or Updated

- `tests/Frontend/Component/CalendarDatePicker.spec.ts` — 61 tests total (59 pre-existing + 2 new):
  - wraps the input in a padded input-wrapper matching NcTextField geometry
  - renders Calendar display labels from the interface language (activeLanguage), not the date/number locale prop
- Existing guards re-run: PurchaserFlow.spec.ts — 161 tests passed.
- Combined: 222 tests passed across both files.

## Local Verification

- `npx vitest run tests/Frontend/Component/CalendarDatePicker.spec.ts` → **61/61 passed**.
- `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` → **161/161 passed**.
- `npx vue-tsc --noEmit` → clean.
- `npx eslint src/components/common/CalendarDatePicker.vue` → 0 errors (1 pre-existing warning only).
- `npx stylelint 'src/components/common/CalendarDatePicker.vue'` → exit 0.
- `npm run build` → succeeded.

Browser verification (official Chrome, `channel: 'chrome'`) captured actual rendered measurements and labels — see test.md.

## Deviations from Assessment

- None. Both corrections are the assessment's preferred fixes, applied in `CalendarDatePicker.vue` only. TransactionFormView.vue was NOT changed (the fix is inside CalendarDatePicker, keeping `props.locale` for `formatVisible` numerals and using `activeLanguage()` only for Calendar display labels).

## Follow-ups

- Owner visual acceptance remains pending (deferred per mission scope).
- No other follow-ups; both defects are corrected and technically verified.

## Risks & Considerations

- **Canonical model unchanged**: `toCanonical()`, `formatVisible()`, API payloads remain Gregorian `YYYY-MM-DD`, calendar-unaware.
- **No new dependencies**: `@jalali-js/vue@0.4.3` and `@jalali-js/i18n@0.4.3` already ship all required locale data.
- **No global styling**: all changes scoped to `CalendarDatePicker.vue`.
- **RTL/LTR**: uses logical properties; `dir` follows `lang`.
- **Focus indication preserved**: `focus-visible` outline kept.
- **Numeral formatting unchanged**: `props.locale` (from `activeLocale()`) still drives `formatVisible` numerals; only Calendar display labels use `activeLanguage()`.
- **Calendar preference independent of interface language**: `system` prop is untouched.
