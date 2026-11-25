# Bug Assessment: Calendar date picker visual defects

- **Slug**: `calendar-date-picker-visual-defects`
- **Created**: 2026-11-24T10:29:55Z
- **Source**: owner visual review of running application
- **Verdict**: **valid** (both defects reproduced in official Google Chrome at desktop, English LTR and Persian RTL)
- **Severity**: **medium**

## Report (verbatim or summarized)

Defect 1 — Field visual misalignment:

> In both CREATE and EDIT forms:
> - The Expense date label appears above the input rather than following the floating-label appearance of adjacent fields.
> - The date input is vertically misaligned with Currency and Amount.
> - Its border appearance differs from surrounding inputs.
> - The focused input displays multiple competing border/focus outlines.

Defect 2 — Calendar day number wrapping:

> Two-digit day numbers wrap onto two lines inside calendar cells. For example, day 25 appears as:
> ```
> 2
> 5
> ```
> The defect is visible across multiple calendar rows in the desktop EDIT modal.

The report is based on owner visual review of the running application at `http://localhost:8081`. The protected behavior named by the owner — canonical Gregorian `YYYY-MM-DD` model values, calendar-aware visible values, calendar preference persistence, locale independence, EDIT date initialization, dirty-state behavior, validation behavior, API payloads, Transaction CREATE/EDIT workflows — is out of scope for the fix and is treated as a regression boundary.

## Symptom

**Defect 1**: `CalendarDatePicker.vue` renders:
- A block `<label>` positioned **above** the input (`.calendar-date-picker__label`, `display: block`, `margin-bottom: 4px`), while adjacent `NcTextField` controls (Currency, Amount) use a floating label positioned **inside** the input wrapper (`input-field__label`, absolutely positioned).
- A plain `<input>` with no height, padding, border, or box-shadow styling matching `NcTextField`'s `input-field__input` conventions (`height: var(--default-clickable-area)`, `box-shadow` borders, `padding-block: 0`, `padding-inline: var(--border-radius-element)`, `font-size: var(--default-font-size)`).
- No disabled appearance (`opacity: 0.4; filter: saturate(0.4)`) matching `NcTextField`.
- No error presentation matching `NcTextField`'s error box-shadow and helper-text color.

**Defect 2**: Calendar day cells (`[data-jalali-calendar-day]`) in the third-party `@jalali-js/vue` Calendar component render two-digit day numbers that wrap onto two lines. The third-party CSS sets `aspect-ratio: 1` and `place-items: center` but does not declare `white-space: nowrap`, so global or inherited styles can cause wrapping.

## Reproduction

Environment: official Google Chrome through Playwright `{ channel: 'chrome' }`, official lab at `http://localhost:8081`, T060 fixture.

1. Sign in as the T060 fixture owner.
2. Open `#/sheets/<sheet-id>`, activate create or edit transaction.
3. **Defect 1**: observe the Expense date field — label sits above the input (not floating inside), input height/padding/border differ from Currency/Amount, focus shows competing outlines.
4. **Defect 2**: open the expense-date picker, observe day cells in the calendar grid — two-digit numbers (10–31) wrap onto two lines.

## Suspected Code Paths

- `src/components/common/CalendarDatePicker.vue` — template renders block label above input; input has no `NcTextField`-matching styling; scoped CSS lacks `input-field`-equivalent height, padding, border-radius, box-shadow, disabled, and error declarations.
- `src/components/common/CalendarDatePicker.vue` scoped CSS — no `white-space: nowrap` override for `[data-jalali-calendar-day]`, allowing day-number wrapping.

The layout declarations **already exist at HEAD**. This is a presentational defect in the newly implemented `CalendarDatePicker.vue` component, not a regression from earlier phases.

## Root Cause Hypothesis

**Confidence: high.**

**Defect 1 root cause**: `CalendarDatePicker.vue` does not mirror `NcTextField`'s visual conventions. Its `<label>` is a block element above the input instead of a floating label inside an `input-field`-equivalent wrapper. Its `<input>` lacks `NcTextField`'s height (`var(--default-clickable-area)`), padding (`padding-block: 0`, `padding-inline: var(--border-radius-element)`), border (`box-shadow`-based), border-radius (`var(--border-radius-element)`), font-size (`var(--default-font-size)`), focus indication (`box-shadow` + `outline`), disabled appearance (`opacity: 0.4; filter: saturate(0.4)`), and error presentation (error box-shadow + helper-text color).

**Defect 2 root cause**: `[data-jalali-calendar-day]` elements in the third-party Calendar component have no `white-space: nowrap` declaration, so two-digit day numbers can wrap when the cell width is constrained by grid sizing or global styles.

## Proposed Remediation

**Preferred**: scoped CSS and template changes in `CalendarDatePicker.vue` only:

1. **Defect 1**:
   - Wrap the `<input>` in a `.calendar-date-picker__field` container with `position: relative` and `margin-block-start: 6px` (matching `input-field`).
   - Restyle `.calendar-date-picker__label` as an absolutely positioned floating label inside the field container (matching `input-field__label` positioning, font-size, color, transitions).
   - Restyle `<input>` to match `input-field__input`: `height: var(--default-clickable-area)`, `padding-block: 0`, `padding-inline: var(--border-radius-element)`, `border: none`, `border-radius: var(--border-radius-element)`, `box-shadow`-based borders, `font-size: var(--default-font-size)`, `appearance: textfield`, focus box-shadow + outline, disabled `opacity: 0.4; filter: saturate(0.4)`.
   - Add `.calendar-date-picker__field--error` modifier for error box-shadow and helper-text color.
   - Add `.calendar-date-picker__helper-text` for error/helper text presentation.
   - Track `isFocused` state and `hasValue` computed to float the label on focus/content (matching NcTextField behavior).
   - Preserve visible label and `aria-label` association.

2. **Defect 2**:
   - Add scoped `:deep([data-jalali-calendar-day]) { white-space: nowrap; }` to prevent day-number wrapping.

## Files likely to change

- `src/components/common/CalendarDatePicker.vue` — template + scoped CSS only. No script logic change beyond `isFocused` state and `hasValue` computed (both presentational; no model, conversion, or payload impact).
- `tests/Frontend/Component/CalendarDatePicker.spec.ts` — add visual regression tests for label, input styling, disabled, error, day wrapping.
- `specs/005-purchaser-ui-redesign/tasks.md` — status entries already exist for T082–T084; no new task needed (this is a correction within the authorized scope).
- `.specify/bugs/calendar-date-picker-visual-defects/` — assessment, fix, and test records (this directory).
- `artifacts/visual-review/t084/iteration-1/` — Chrome evidence screenshots and `evidence.json` (Git-ignored per Constitution VIII).
- `tests/E2E/t084-calendar-picker-evidence.mjs` — already captures visible date, label presence, and calendar system per combo; no change needed beyond re-running.

## Tests to add or update

- `tests/Frontend/Component/CalendarDatePicker.spec.ts` — add tests for:
  - Visible label presence and accessible association (`aria-label`)
  - Label floats when input has value or is focused
  - Input height/border-radius present (structural check)
  - Disabled state sets `disabled` property on input
  - Error state applies `calendar-date-picker__field--error` class and renders helper text
  - Day numbers do not wrap (verified in browser via T084; jsdom cannot render the Calendar popup reliably)

- Existing guards re-run unchanged: `PurchaserFlow.spec.ts`, `StagedReceipts.spec.ts`, `calendarMutation.spec.ts`, `MutationPayloadCalendar.spec.ts`.

## Risks & Considerations

- **Canonical model unchanged**: the fix is presentational only; `modelValue` remains canonical Gregorian `YYYY-MM-DD`, `toCanonical()` and `formatVisible()` are untouched.
- **No new dependencies**: no dependency changes, no `node_modules` patches.
- **No global styling**: all changes are scoped to `CalendarDatePicker.vue`.
- **RTL/LTR**: floating label uses `inset-inline` (logical properties), so direction is preserved.
- **Light/dark theme**: uses Nextcloud theme variables (`var(--color-main-background)`, `var(--color-main-text)`, `var(--color-border)`, `var(--color-primary-element)`, etc.).
- **Focus indication preserved**: `focus-visible` outline is kept; no focus removal.
- **Dirty-state behavior unchanged**: label floating is visual only; no `update:modelValue` is emitted by label movement.
- **No API, migration, performance, security, or observability risk.**

## Relevant Spec Kit Requirements

- **005 `FR-005-064`** — calendar-aware visible date presentation. Already satisfied by the visible-value computation; this fix makes the presentation match surrounding controls.
- **005 `C-24`** — visual consistency with existing form controls. This is the defect being corrected.
- **Constitution v1.4.0**: II (preserve contracts/existing behavior), V (risk-based focused verification; official Chrome desktop + 390px including Persian RTL), VII (explicit owner gate), VIII (visual evidence under `artifacts/visual-review/`).

## Open Questions

- None. Both defects are presentational, scoped to one component, and have unambiguous corrections.
