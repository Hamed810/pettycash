# Bug Fix: Human-readable dates render with mixed ordering and era markers

- **Slug**: `calendar-date-display-inconsistency`
- **Created**: 2026-11-24
- **Source**: `.specify/bugs/calendar-date-display-inconsistency/assessment.md`
- **Verdict**: verified

## Root Cause

`Intl.DateTimeFormat#format()` is locale-data-driven: it chooses field order, inserts literals, and appends an era where the locale/calendar pair requires one. Passing `year/month/day: 'numeric'` therefore cannot produce a fixed `YYYY/MM/DD` presentation, and there is no supported option to suppress only the era.

The shared formatter must assemble the date from `formatToParts()`, selecting the `year`, `month`, and `day` parts and ignoring `era` and literal separators. Padding must use the locale's zero digit so Persian numerals remain Persian. Date-only canonical values (`YYYY-MM-DD`) must be interpreted in UTC to avoid a timezone day shift; full timestamps keep their existing local-time semantics and append a time-only rendering.

## Correction

1. **Rewrote `src/utils/date.ts`** to assemble `${year}/${month}/${day}` from `formatToParts()`:
   - Uses `formatToParts()` to extract year/month/day parts.
   - Pads month/day to two digits and year to four using the locale's zero digit (`Intl.NumberFormat(...).format(0)`).
   - Never emits era, weekday, or month names.
   - Treats canonical date-only `YYYY-MM-DD` as UTC to avoid day shifts.
   - Keeps full timestamps with local-time semantics and appends locale-formatted time.
   - `DateFormat` interface no longer accepts `options` to prevent bypasses.

2. **Removed local `options` overrides** in:
   - `src/components/review/EvidenceCard.vue`
   - `src/components/review/RevisionInfo.vue`
   - `src/components/review/PriorDecisionHistory.vue`

3. **Fixed `CompletedSheetDetail.vue`**:
   - Changed expense-date cell from `formatSheetDateTime` to `formatSheetDate` (date-only).
   - Added `white-space: nowrap` rule for the expense-date cell.

4. **Added narrowly scoped `white-space: nowrap` rules**:
   - `SheetDetailView.vue` transaction date cell
   - `SheetListView.vue` submitted date cell
   - `TransactionListView.vue` expense date cell
   - `FinanceQueueView.vue` submitted date cell
   - `CompletedSheetDetail.vue` expense-date cell

5. **Added focused tests** in `tests/Frontend/localeFormatting.spec.ts`:
   - All four required equivalences (en-US/en-GB × gregory/persian).
   - Era-free output verification.
   - Persian digits and padding verification.
   - Date-time retention verification.
   - Canonical date-only stability.

6. **Updated component tests** that checked rendered dates:
   - `tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts`
   - `tests/Frontend/Component/TransactionInlineDetail.spec.ts`

7. **Created Chrome evidence runner** `tests/E2E/calendar-date-display-evidence.mjs`:
   - Verifies transaction table and a representative review queue.
   - Tests all four language/calendar combinations.
   - Confirms fixed `YYYY/MM/DD` shape, no era, locale digits, single-line cells.
   - Confirms date-time only standardizes the date portion and preserves time.

## Files Changed

- `src/utils/date.ts` — fixed assembly, era suppression, locale zero-padding, date-only UTC handling, time retention.
- `src/components/review/EvidenceCard.vue` — removed local `options` override.
- `src/components/review/RevisionInfo.vue` — removed local `options` overrides.
- `src/components/review/PriorDecisionHistory.vue` — removed local `options` overrides.
- `src/components/finance/CompletedSheetDetail.vue` — date-only expense fix + nowrap.
- `src/views/sheets/SheetDetailView.vue` — transaction table nowrap.
- `src/views/sheets/SheetListView.vue` — submitted date nowrap.
- `src/views/transactions/TransactionListView.vue` — expense date nowrap.
- `src/views/reviews/FinanceQueueView.vue` — submitted date nowrap.
- `tests/Frontend/localeFormatting.spec.ts` — focused formatter equivalences and four-combination tests.
- `tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts` — pinned `YYYY/MM/DD` visible value.
- `tests/Frontend/Component/TransactionInlineDetail.spec.ts` — pinned fixed date part.
- `tests/E2E/calendar-date-display-evidence.mjs` — new Chrome evidence runner.

## What Was NOT Changed

- No dependency installation, upgrades, or `node_modules` patches.
- No package manifest or lockfile changes.
- No calendar redesign, global styling, or visual changes.
- No changes to unrelated date pickers, Personal Settings, backend, database, API contracts, financial rules, or authorization.
- No transaction workflow changes.
- Canonical model representation unchanged.
- Exports and machine-readable dates remain canonical.
- Picker behavior and prior verified picker corrections preserved.

## Preserved Behavior

- Locale and calendar are independent preferences (UI-PUR-001).
- Persian digits with Gregorian calendar (`fa-IR` + `gregory`) remains valid.
- English digits with Jalali calendar (`en-US` + `persian`) remains valid.
- Missing/invalid values render their existing localized label (not `Invalid Date`).
- Date-time fields standardize only the date portion and preserve time/timezone semantics.
- Calendar switching does not alter canonical values or mutation payloads.
- Bidi direction (LTR/RTL) and word wrapping preserved.

## Verification Performed

- 572 frontend component tests (17 files) — all passed.
- TypeScript typecheck (`vue-tsc --noEmit`) — passed.
- ESLint on changed files — no errors (pre-existing warnings).
- Stylelint on changed Vue files — passed.
- Frontend build (`vite build`) — passed.
- Chrome browser evidence: 4 combos (en-gregory, en-jalali, fa-ggregory, fa-jalali) × transaction table + cost-center queue + finance queue date-time — all checks passed (273–305 checks per combo).
- `artifacts/visual-review/calendar-date-display-inconsistency/iteration-1/evidence.json` — full technical evidence captured.

## Commands Executed

```bash
npx vitest run tests/Frontend/localeFormatting.spec.ts → 19 passed
npx vitest run tests/Frontend/Component/CalendarDatePicker.spec.ts tests/Frontend/Component/ManagementReview.spec.ts tests/Frontend/calendarMutation.spec.ts tests/Frontend/Component/MutationPayloadCalendar.spec.ts tests/Frontend/Component/PurchaserFlow.spec.ts tests/Frontend/Component/SheetWorkspace.spec.ts tests/Frontend/Component/Finance/CompletedSheetDetail.spec.ts tests/Frontend/Component/Finance/Queue.spec.ts tests/Frontend/Component/TransactionInlineDetail.spec.ts tests/Frontend/Component/TransactionReviewCard.spec.ts tests/Frontend/Component/TransactionSurface.spec.ts tests/Frontend/Component/EvidenceStatusPresentation.spec.ts tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts → 572 passed
npm run typecheck → passed
npx eslint src/utils/date.ts src/components/review/*.vue src/views/sheets/*.vue src/views/transactions/*.vue src/views/reviews/*.vue tests/Frontend/localeFormatting.spec.ts tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts tests/Frontend/Component/TransactionInlineDetail.spec.ts tests/E2E/calendar-date-display-evidence.mjs → no errors
npx stylelint 'src/components/finance/CompletedSheetDetail.vue' 'src/views/sheets/*.vue' 'src/views/transactions/*.vue' 'src/views/reviews/*.vue' → passed
npm run build → passed
CALENDAR_DATE_ITERATION=iteration-1 node tests/E2E/calendar-date-display-evidence.mjs → all 4 combos passed
```
