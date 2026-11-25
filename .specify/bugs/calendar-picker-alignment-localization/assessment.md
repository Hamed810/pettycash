# Bug Assessment: Calendar picker input alignment and localization defects

- **Slug**: `calendar-picker-alignment-localization`
- **Created**: 2026-11-24T18:01:03Z
- **Source**: pasted text (user-provided bug report)
- **Verdict**: **valid** (grounded in current source code; both defects reproducible from code inspection)
- **Severity**: **medium**

## Report (verbatim or summarized)

Two defects in the transaction expense-date picker (`CalendarDatePicker.vue`):

1. **Date input height and visual alignment mismatch** — the Expense date input is taller than adjacent Currency (`NcTextField`) and Amount (`NcTextField`) controls; its lower edge extends below them and its border appearance differs, even when the picker is closed and not focused.
2. **English calendar labels under Persian UI** — when Nextcloud's interface language is Persian (`fa`) and Pettycash uses the Jalali calendar, the calendar popup still displays English labels (e.g. `Khordad`, `Sat`, `Sun`).

The report references `FR-005-059`, `FR-005-064`, `C-24`, feature `005-purchaser-ui-redesign` phase 18, tasks `T082`-`T084`, and prior verified corrections that must be preserved.

## Symptom

**Defect 1**: `CalendarDatePicker.vue` renders an `<input>` whose rendered height and vertical edge positions do not match the adjacent `NcTextField` controls (Currency, Amount). The date input's lower edge extends below the sibling fields and its border/box-shadow appearance differs. This is visible with the popup closed and the input unfocused.

**Defect 2**: With Persian interface language (`fa-IR`) and Jalali calendar, the `Calendar` popup renders English month names (`Khordad`) and English weekday short labels (`Sat`, `Sun`, ...) instead of Persian equivalents (`خرداد`, `ی`, `د`, ...). The surrounding transaction form is correctly localized into Persian.

## Reproduction

1. Open the Pettycash transaction form (CREATE or EDIT surface) in official Chrome.
2. **Defect 1**: inspect the Currency, Expense date, and Amount controls — compare rendered `height`, `top`/`bottom` edges, border-radius, border thickness, and vertical padding. The Expense date input should match but does not.
3. **Defect 2**: set Nextcloud language to Persian (`fa`), calendar preference to Persian (`persian`/`jalali`), open the expense-date picker — observe month title, month-selector options, and weekday headings. English text appears where Persian is expected.

[NEEDS CLARIFICATION: exact rendered pixel values before correction — to be captured during browser verification in official Chrome.]

## Suspected Code Paths

- `src/components/common/CalendarDatePicker.vue:239-254` — `<input>` styling: `height: var(--default-clickable-area) !important` applied directly on the input without the padded `.input-field__main-wrapper` that `NcInputField`/`NcTextField` uses (`padding: var(--border-width-input-focused, 2px)` on wrapper, `height: 100% !important` on input). This structural difference is the suspected cause of the 1-2px rendered height mismatch.
- `src/components/common/CalendarDatePicker.vue:183-188` — `<Calendar :locale="mapLocale(props.locale)" />` — the `mapLocale` function (line 28-32) maps Nextcloud locale tags (e.g. `fa-IR`) to `LocaleCode` (`'fa'`). The `Calendar` component from `@jalali-js/vue@0.4.3` internally calls `localePackFor(o.locale)` and reads `monthNames[o.system].long` / `weekdayNames.short` for display. The i18n package ships `fa` with Persian Jalali and Gregorian month names, Persian weekday names, and Persian UI strings.
- `src/utils/locale.ts:70-74` — `activeLocale()` resolves Nextcloud's `data-locale` (`fa_IR`) → `fa-IR`, falling back to `activeLanguage()` → `en`.
- `src/utils/calendar.ts:57-58` — `defaultCalendarFor()` maps Persian language to `persian` calendar preference.

## Root Cause Hypothesis

**Defect 1 — confidence: medium/high.**

`CalendarDatePicker.vue` applies `height: var(--default-clickable-area)` directly on the `<input>` element. `NcTextField`/`NcInputField` wraps the `<input>` in `.input-field__main-wrapper` (`height: var(--default-clickable-area)`, `padding: var(--border-width-input-focused, 2px)`, `box-sizing: border-box`) and sets `height: 100% !important` on the input, so the input element itself renders at `clickable-area - 4px` inside a `clickable-area` tall padded wrapper. The date picker's input renders at `clickable-area` directly with no surrounding wrapper padding. This missing padded-wrapper structure causes a 1-2px rendered height difference and different border/shadow/edge positioning versus `NcTextField`. `box-sizing: border-box` is set on `.pettycash-shell` (src/styles/app.scss:51), so the difference is in the input element's border-box height, not padding/border addition.

**Defect 2 — confidence: medium.**

The code path appears structurally correct: `mapLocale('fa-IR')` → `'fa'`, `localePackFor('fa')` returns the Persian `LocalePack` with Persian `monthNames.jalali.long` (`خرداد` etc.), `monthNames.gregorian.long` (`ژانویه` etc.), `weekdayNames.short` (`ی`, `د`, ...), and Persian `ui` strings. The `Calendar` component consumes `localePackFor(o.locale)` for month title, month selector, and weekday grid. The observed English labels ("Khordad", "Sat") match the `en` locale pack's English transliterations, which suggests the `Calendar` is receiving `locale='en'` rather than `locale='fa'` at runtime. Possible causes: `mapLocale` not returning `'fa'` for the actual `props.locale` value, prop-passing/reactivity timing in the `Calendar` component, or `localePackFor` receiving an unmapped value and falling back to English. Requires runtime verification of actual rendered labels.

## Proposed Remediation

**Preferred** (scoped to `CalendarDatePicker.vue`):

1. **Defect 1**: Restyle the `<input>` to match `NcTextField`'s `input-field__input` conventions exactly: mirror the padded-wrapper structure (or equivalent direct-input styling) so rendered height, top/bottom edges, border-radius, border thickness (box-shadow), internal vertical padding, and floating-label position all match `NcTextField`. Preserve `focus-visible` outline for keyboard focus indication. Do not modify Currency or Amount. Do not change form column widths.

2. **Defect 2**: Ensure the `Calendar` component receives and applies the correct locale. Verify `mapLocale(props.locate)` returns `'fa'` for Persian and `'en'` for English at runtime. If the `Calendar` component does not localize all internal controls (month selector, weekday headings, navigation labels), use its supported localization mechanisms (locale prop + `localePackFor`) without installing new dependencies. Calendar choice must remain independent of interface language.

**Alternatives**:
- For Defect 1: wrap the input in an `.input-field__main-wrapper`-equivalent container instead of restyling the input directly. Trade-off: more template restructuring but closer to `NcTextField`'s actual DOM structure.
- For Defect 2: bypass the `Calendar` component's internal locale handling and render month/weekday labels directly using `format()` from `@jalali-js/i18n`. Trade-off: more code, duplicates library behavior, risks divergence.

## Files likely to change

- `src/components/common/CalendarDatePicker.vue` — scoped CSS and possibly template for Defect 1; locale prop handling for Defect 2.
- `tests/Frontend/Component/CalendarDatePicker.spec.ts` — add regression tests for input height consistency and localized calendar labels.
- `.specify/bugs/calendar-picker-alignment-localization/fix.md` — fix record.
- `.specify/bugs/calendar-picker-alignment-localization/test.md` — test record.
- `artifacts/visual-review/` — Chrome evidence screenshots (Git-ignored).

## Tests to add or update

- Date input structural/styling consistency: rendered height matches `NcTextField` within 1px, top/bottom edges align, border-radius, border thickness, vertical padding.
- Persian Jalali month names: rendered month title and month-selector options in Persian (`خرداد` etc.).
- Persian Gregorian month names: rendered Gregorian month names in Persian (`ژانویه` etc.).
- Persian weekday labels: rendered weekday headings in Persian short form (`ی`, `د`, `س`, `چ`, `پ`, `ج`, `ش`).
- English Jalali month names: English transliterations (`Khordad` etc.).
- English Gregorian month names: English names (`January` etc.).
- Independent calendar and locale selection: all four combinations produce correct labels.
- Preservation of existing navigation/dismissal behavior: month/year navigation keeps popup open, day selection closes, Escape closes, outside-click dismisses.

## Risks & Considerations

- **Canonical model unchanged**: Defect 1 is presentational only; Defect 2 affects display labels only. `toCanonical()`, `formatVisible()`, API payloads remain Gregorian `YYYY-MM-DD`, calendar-unaware.
- **No new dependencies**: `@jalali-js/vue@0.4.3` and `@jalali-js/i18n@0.4.3` already ship all required locale data.
- **No global styling**: all changes scoped to `CalendarDatePicker.vue`.
- **RTL/LTR**: uses logical properties (`inset-block-start`, `inset-inline`, `padding-inline`).
- **Focus indication preserved**: `focus-visible` outline must remain.
- **Regression boundary**: all previously verified behavior (navigation keeps popup open, day selection closes, outside-click, Escape, two-digit day wrapping, canonical model, API payloads) must continue to pass.

## Open Questions

- [NEEDS CLARIFICATION] Exact rendered pixel heights of Currency, Expense date, and Amount inputs before correction — to be captured in official Chrome during verification.
- [NEEDS CLARIFICATION] Whether `mapLocale(props.locale)` returns `'fa'` at runtime for Persian locale — to be verified by rendering actual calendar labels in Chrome.