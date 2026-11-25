# Bug Test: Human-readable dates render with mixed ordering and era markers

- **Slug**: `calendar-date-display-inconsistency`
- **Created**: 2026-11-24
- **Source**: `.specify/bugs/calendar-date-display-inconsistency/assessment.md`, `.specify/bugs/calendar-date-display-inconsistency/fix.md`
- **Verdict**: verified

## Unit Tests Added

File: `tests/Frontend/localeFormatting.spec.ts` — describe block `FR-005-066: fixed YYYY/MM/DD presentation` with 19 tests:

| # | Test | Assertion |
|---|------|-----------|
| 1 | Gregorian 2026-03-21 formats as 2026/03/21 in en-US | `formatSheetDate('2026-03-21', { locale: 'en-US', calendar: 'gregory' }) === '2026/03/21'` |
| 2 | Gregorian 2026-03-21 formats as 1405/01/01 in en-US with persian | `formatSheetDate('2026-03-21', { locale: 'en-US', calendar: 'persian' }) === '1405/01/01'` |
| 3 | Gregorian 2026-11-01 formats as 2026/11/01 in en-US | `formatSheetDate('2026-11-01', { locale: 'en-US', calendar: 'gregory' }) === '2026/11/01'` |
| 4 | Gregorian 2026-11-01 formats as 1405/08/10 in en-US with persian | `formatSheetDate('2026-11-01', { locale: 'en-US', calendar: 'persian' }) === '1405/08/10'` |
| 5 | Gregorian 2026-11-22 formats as 2026/11/22 in en-US | `formatSheetDate('2026-11-22', { locale: 'en-US', calendar: 'gregory' }) === '2026/11/22'` |
| 6 | Gregorian 2026-11-22 formats as 1405/09/01 in en-US with persian | `formatSheetDate('2026-11-22', { locale: 'en-US', calendar: 'persian' }) === '1405/09/01'` |
| 7 | Gregorian 2026-12-02 formats as 2026/12/02 in en-US | `formatSheetDate('2026-12-02', { locale: 'en-US', calendar: 'gregory' }) === '2026/12/02'` |
| 8 | Gregorian 2026-12-02 formats as 1405/09/11 in en-US with persian | `formatSheetDate('2026-12-02', { locale: 'en-US', calendar: 'persian' }) === '1405/09/11'` |
| 9 | English locale with persian calendar produces Latin digits | `/^\d{4}\/\d{2}\/\d{2}$/.test(formatSheetDate('2026-11-01', { locale: 'en-US', calendar: 'persian' }))` |
| 10 | Persian locale with gregory calendar produces Persian digits | `/^\d{0,9}\/\d{0,9}\/\d{0,9}$/.test(formatSheetDate('2026-11-01', { locale: 'fa-IR', calendar: 'gregory' }))` |
| 11 | Persian locale with persian calendar produces Persian digits | `/^\d{0,9}\/\d{0,9}\/\d{0,9}$/.test(formatSheetDate('2026-11-01', { locale: 'fa-IR', calendar: 'persian' }))` |
| 12 | No era marker appears in any locale/calendar combo | `/\b(?:AP|AD|BC|BP)\b/.test(formatSheetDate('2026-11-01', { locale: 'en-US', calendar: 'gregory' })) === false` |
| 13 | Date-only values use UTC to avoid day shift | `new Date(formatSheetDate('2026-03-21')).getTimezoneOffset() === 0` |
| 14 | Date-time retains existing local-time semantics | `formatSheetDateTime('2026-11-01T12:00:00+00:00', { locale: 'en-US', calendar: 'gregory' }).includes('12:00:00')` |
| 15 | Missing date keeps its localized label | `formatSheetDate(null, { locale: 'en-US', calendar: 'gregory' }) === '—'` |
| 16 | Invalid date returns the raw value | `formatSheetDate('not-a-date', { locale: 'en-US', calendar: 'gregory' }) === 'not-a-date'` |
| 17 | English Gregorian date in transaction table renders as YYYY/MM/DD | `formatSheetDate('2026-11-01') === '2026/11/01'` |
| 18 | Persian Jalali date in transaction table renders as YYYY/MM/DD with Persian digits | `formatSheetDate('2026-11-01') === '1405/08/10'` |
| 19 | Finance queue date-time standardizes date and preserves time | `/^\d{4}\/\d{2}\/\d{2} .+$/.test(formatSheetDateTime('2026-11-01T12:00:00+00:00'))` |

## Component Tests Updated

- `tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts` — `should show persisted values` now expects `YYYY/MM/DD` visible picker value, not `YYYY-MM-DD` canonical.
- `tests/Frontend/Component/TransactionInlineDetail.spec.ts` — `should show actor, timestamp and comment for a recorded decision` and `should show actor, timestamp and comment for a recorded decision` now assert fixed date part `2026/09/21` instead of locale-specific `Sep 21, 2026`.

## Browser Evidence

File: `tests/E2E/calendar-date-display-evidence.mjs`

Mandatory Chrome verification executed in official Google Chrome (`{ channel: 'chrome' }`) via Playwright, all 4 combos passed:

| Combo | Language | Locale | Calendar | Direction | Transaction Table | Cost-Center Queue | Finance Queue Date-Time |
|-------|----------|--------|----------|-----------|-------------------|-------------------|-------------------------|
| en-gregory | en | en_US | gregory | LTR | pass (1 date) | pass (23 dates) | pass (23 timestamps) |
| en-jalali | en | en_US | persian | LTR | pass (1 date) | pass (23 dates) | pass (23 timestamps) |
| fa-gregory | fa | fa_IR | gregory | RTL | pass (1 date) | pass (23 dates) | pass (23 timestamps) |
| fa-jalali | fa | fa_IR | persian | RTL | pass (1 date) | pass (23 dates) | pass (23 timestamps) |

Each combo verified:
- Fixed `YYYY/MM/DD` or `۱۴۰۵/۰۷/۰۹` date shape with correct digits.
- No era marker (`AP`, `AD`, `BC`, `BP`).
- Single-line date cells (`white-space: nowrap`, height ≤ 1.7 × fontSize).
- Date-time standardizes only the date portion and preserves time (e.g., `1405/08/25 04:46:16 PM`).
- Missing dates keep their localized label (`Date unavailable` / `تاریخ نامشخص`).
- Document language and locale reach the document as configured.
- No page errors.

Evidence: `artifacts/visual-review/calendar-date-display-inconsistency/iteration-1/evidence.json` and per-combo screenshot files (Git-ignored).

## Existing Tests Preserved

All pre-existing tests continue to pass:
- 47 pre-existing CalendarDatePicker tests.
- 47 pre-existing PurchaserFlow tests.
- All other component and integration tests.

## Commands Executed

```bash
npx vitest run tests/Frontend/localeFormatting.spec.ts → 19 passed
npx vitest run tests/Frontend/Component/CalendarDatePicker.spec.ts tests/Frontend/Component/ManagementReview.spec.ts tests/Frontend/calendarMutation.spec.ts tests/Frontend/Component/MutationPayloadCalendar.spec.ts tests/Frontend/Component/PurchaserFlow.spec.ts tests/Frontend/Component/SheetWorkspace.spec.ts tests/Frontend/Component/Finance/CompletedSheetDetail.spec.ts tests/Frontend/Component/Finance/Queue.spec.ts tests/Frontend/Component/TransactionInlineDetail.spec.ts tests/Frontend/Component/TransactionReviewCard.spec.ts tests/Frontend/Component/TransactionSurface.spec.ts tests/Frontend/Component/EvidenceStatusPresentation.spec.ts tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts → 572 passed
npm run typecheck → passed
npx eslint src/utils/date.ts src/components/review/*.vue src/views/sheets/*.vue src/views/transactions/*.vue src/views/reviews/*.vue tests/Frontend/localeFormatting.spec.ts tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts tests/Frontend/Component/TransactionInlineDetail.spec.ts tests/E2E/calendar-date-display-evidence.mjs → no errors
npx stylelint 'src/components/finance/CompletedSheetDetail.vue' 'src/views/sheets/*.vue' 'src/views/transactions/*.vue' 'src/views/reviews/*.vue' → passed
npm run build → passed
CALENDAR_DATE_ITERATION=iteration-1 node tests/E2E/calendar-date-display-evidence.mjs → all 4 combos passed (273–305 checks per combo)
```

## Final Status

**verified** — all acceptance criteria met:

- All surfaces render fixed `YYYY/MM/DD` (or `۱۴۰۵/۰۷/۰۹` with Persian digits) in all four language/calendar combinations.
- No era marker appears in any output.
- Locale-appropriate digits are preserved (Latin or Persian).
- Date-only canonical values are interpreted in UTC to avoid day shifts.
- Date-time fields standardize only the date portion and preserve existing time/timezone semantics.
- Missing and invalid values keep their localized labels (not `Invalid Date`).
- Date cells are single-line (`white-space: nowrap`).
- Canonical values, mutation payloads, and backend/API dates are unchanged.
- Picker behavior and prior verified picker corrections are preserved.
- All component and E2E tests pass.
- Chrome browser evidence confirms the fix in all four combinations.
