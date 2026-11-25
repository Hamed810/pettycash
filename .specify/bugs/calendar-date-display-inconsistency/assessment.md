# Bug Assessment: Human-readable dates render with mixed ordering and era markers

- **Slug**: `calendar-date-display-inconsistency`
- **Created**: 2026-11-24
- **Source**: owner-provided bug report and approved presentation standard
- **Verdict**: **valid** — confirmed from current source and reproduced with the runtime `Intl` implementation
- **Severity**: **medium** (presentation only; no stored value, payload, or workflow effect)

## Report (summarized)

Human-readable dates across Pettycash are inconsistent and, in English interfaces, carry
an era marker. Observed examples include `8/10/1405 AP` and `AP 9/1/1405`. The approved
standard is a fixed `YYYY/MM/DD` order with a four-digit year and two-digit zero-padded
month and day, without an era prefix or suffix:

- Gregorian: `2026/11/01`
- Jalali: `1405/08/10`

The same standard must hold in all four language/calendar combinations, while the locale
may still choose numeral shapes (Latin or Persian digits). Table date cells must remain
single-line. Canonical backend/API/stored dates must not change.

## Classification

This is a **requirement gap plus an implementation bug**:

1. The specification did not state a fixed field order, zero-padding rule, or era-marker
   prohibition. The owner has now approved that requirement; it is recorded in
   `spec.md` as `FR-005-066` / `SC-005-031` and `contracts/purchaser-ui-contract.md`
   as `C-25` before implementation.
2. Independently, the shared formatter produces locale-dependent ordering and an era
   marker. This is an implementation defect against the newly recorded standard.

## Symptom

- English interface + Persian calendar renders month-first US ordering and an `AP` era
  marker: `8/10/1405 AP`.
- A right-to-left or otherwise localized rendering can place the era marker before the
  date: `AP 9/1/1405`.
- Different locales produce different field orders (`en-US`: month/day/year;
  `en-GB`: day/month/year), so the same stored date reads differently across locales.
- The transaction expense-date picker already displays `YYYY/MM/DD`, so the table and the
  picker disagree on the same date.

## Reproduction

1. Sign in to the lab at `http://localhost:8081` in official Google Chrome.
2. Set the interface language to English and the Pettycash calendar preference to Persian.
3. Open a transaction table (purchaser transaction list, sheet workspace, Cost Sheet detail,
   a review queue, review/approval/revision history, or a completed-sheet table).
4. Observe the rendered expense date or timestamp: for `2026-11-01` the shared formatter
   produces `8/10/1405 AP` under `en-US` instead of `1405/08/10`.
5. Repeat with the Gregorian preference: `2026-11-01` renders `11/1/2026` instead of
   `2026/11/01`.

Runtime confirmation with the installed Node `Intl` implementation:

```text
en-US + persian  → "8/10/1405 AP"
en-GB + persian  → "10/08/1405 AP"
en-US + gregory  → "11/1/2026"
en-GB + gregory  → "01/11/2026"
fa-IR + persian  → Persian digits, year/month/day order, no era
```

The era marker and ordering are not CSS artifacts; they are the formatter's output.

## Suspected Code Paths

- `src/utils/date.ts` — `render()` builds an `Intl.DateTimeFormat` with
  `year/month/day: 'numeric'` and the active locale plus calendar preference, then calls
  `.format()`. `Intl` supplies the locale's field order and, for the Persian calendar
  under non-Persian locales, an era part (`AP`).
- `src/components/common/CalendarDatePicker.vue` — renders its visible value as
  `YYYY/MM/DD` through `@jalali-js/i18n` `format(..., { template: 'YYYY/MM/DD' })`; this
  is the reference presentation, not the defect.
- `src/components/review/EvidenceCard.vue`, `RevisionInfo.vue`, and
  `PriorDecisionHistory.vue` — bypass the shared shape with local `options`
  (`month: 'short'` / `month: 'long'`). These would keep month names after the shared
  formatter is corrected.
- `src/components/finance/CompletedSheetDetail.vue` — formats the date-only
  `expenseDate` with `formatSheetDateTime`, adding a spurious time and risking a
  timezone day shift.
- Table date cells that can fragment across lines because `ResponsiveTable` sets
  `white-space: normal`.

## Root Cause

`Intl.DateTimeFormat#format()` is locale-data-driven: it chooses field order, inserts
literals, and appends an era where the locale/calendar pair requires one. Passing
`year/month/day: 'numeric'` therefore cannot produce a fixed `YYYY/MM/DD` presentation,
and there is no supported option to suppress only the era.

The formatter must assemble the date from `formatToParts()`, selecting the `year`,
`month`, and `day` parts and ignoring `era` and literal separators. Padding must use the
locale's zero digit so Persian numerals remain Persian. Date-only canonical values
(`YYYY-MM-DD`) must be interpreted in UTC to avoid a timezone day shift; full timestamps
keep their existing local-time semantics and append a time-only rendering.

## Proposed Remediation

1. Rewrite `src/utils/date.ts` to assemble `${year}/${month}/${day}` from
   `formatToParts()`, padding month/day to two digits and year to four with the locale
   zero digit; never emit era, weekday, or month names.
2. Treat canonical date-only `YYYY-MM-DD` as UTC; keep full timestamp parsing and time
   formatting unchanged in semantics. `formatSheetDateTime` appends the locale time to
   the standardized date portion.
3. Remove the `options` escape hatch from `DateFormat` and the four local overrides so no
   surface can reintroduce a second date shape.
4. Change `CompletedSheetDetail.vue`'s `expenseDate` cell to `formatSheetDate`.
5. Add narrowly scoped `white-space: nowrap` rules for date cells only; no global nowrap
   and no table redesign.
6. Add focused tests for the four required date equivalences in all four
   language/calendar combinations, for absence/invalid handling, date-time retention,
   and canonical mutation payloads.

## Files Likely to Change

- `src/utils/date.ts`
- `src/components/review/EvidenceCard.vue`
- `src/components/review/RevisionInfo.vue`
- `src/components/review/PriorDecisionHistory.vue`
- `src/components/finance/CompletedSheetDetail.vue`
- Date-cell styles in the affected table surfaces
- `tests/Frontend/localeFormatting.spec.ts` and affected component specs
- `spec.md`, `contracts/purchaser-ui-contract.md`
- `.specify/bugs/calendar-date-display-inconsistency/{assessment,fix,test}.md`
- `artifacts/visual-review/` evidence (Git-ignored)

## Tests to Add or Update

- `2026-03-21` → `2026/03/21` Gregorian, `1405/01/01` Jalali.
- `2026-11-01` → `2026/11/01` Gregorian, `1405/08/10` Jalali.
- `2026-11-22` → `2026/11/22` Gregorian, `1405/09/01` Jalali.
- `2026-12-02` → `2026/12/02` Gregorian, `1405/09/11` Jalali.
- All four combinations of English/Persian × Gregorian/Jalali; no `AP`/`AD`, exact
  ordering, and zero-padding with the correct digits.
- Missing and invalid values unchanged; date-time retains time; canonical values and
  mutation payloads unchanged.

## Risks & Considerations

- **Presentation only**: no API, schema, database, financial, authorization, or workflow
  change. Exports and machine-readable dates remain canonical.
- **Locale independence**: numerals remain locale-driven; only the field order is fixed.
- **Timezone**: date-only values use UTC; timestamps keep local semantics. This avoids
  introducing a day shift.
- **No dependency or picker redesign**: the existing picker and dependency set are
  preserved.
- **Owner visual acceptance** of the corrected tables remains a separate gate; automated
  checks and Chrome screenshots are technical evidence, not visual approval.

## Open Questions

- None blocking. The owner-approved field order, padding, era prohibition, numeral policy,
  and date-time treatment are recorded in the specification and contract.
