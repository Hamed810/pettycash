# Bug Verification: Calendar date picker visual defects

- **Slug**: `calendar-date-picker-visual-defects`
- **Tested**: 2026-11-24
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: **verified**
- **Owner Acceptance**: 2026-11-24 — product owner accepted the corrected transaction calendar picker, including calendar-aware date presentation and the visual corrections to field alignment, floating labels, and calendar day-number rendering.

## Summary

Both visual defects are corrected and technically verified. The original symptoms do not reproduce:
- **Defect 1**: The Expense date field now matches adjacent Currency/Amount controls — floating label inside the field wrapper, matching input height/padding/border/focus/disabled/error styling, visible localized label present.
- **Defect 2**: Two-digit calendar day numbers (10–31) remain on a single line inside calendar cells; cell dimensions are consistent; numbers are centered horizontally and vertically; the seven-column calendar grid remains intact.

Canonical Gregorian `YYYY-MM-DD` model values, visible calendar-aware dates, date conversion calculations, calendar preference persistence, locale independence, EDIT date initialization, dirty-state behavior, validation behavior, API payloads, and Transaction CREATE/EDIT workflows are all unchanged.

## Corrected Date Equivalences

The initial report incorrectly stated `Jalali 1405-08-10 = Gregorian 2026-11-22`. This was a **documentation error** — not a test or implementation defect. The correct equivalences are:

| Jalali | Gregorian | Canonical `expenseDate` |
|--------|-----------|------------------------|
| 1405-08-10 | 2026-11-01 | `2026-11-01` |
| 1405-09-01 | 2026-11-22 | `2026-11-22` |

Browser-verified equivalent-date payload equality: selecting Jalali `1405-08-10` under Persian calendar and Gregorian `2026-11-01` under English calendar both produce identical canonical `expenseDate: "2026-11-01"` with no calendar metadata.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Calendar-picker component tests | `npx vitest run tests/Frontend/Component/CalendarDatePicker.spec.ts` | **pass** | 48/48 passed (40 pre-existing + 7 visual regression + 1 timezone). |
| Transaction regression tests | `npx vitest run PurchaserFlow.spec.ts StagedReceipts.spec.ts calendarMutation.spec.ts MutationPayloadCalendar.spec.ts` | **pass** | 218/218 passed across 5 files. |
| TypeScript typecheck | `npm run typecheck` | **pass** | `vue-tsc --noEmit` clean. |
| ESLint | `npm run lint` | **pass** | 0 errors (647 pre-existing warnings only). |
| Stylelint | `npm run stylelint` | **pass** | exit 0. |
| Frontend build | `npm run build` | **pass** | succeeded in 12.45s. |
| Browser verification (4 combos) | `T084_ITERATION=iteration-5 node tests/E2E/t084-calendar-picker-evidence.mjs` | **pass** | All 4 combos passed (`en-gregory`, `en-jalali`, `fa-gregory`, `fa-jalali`). |
| Equivalent-date payload verification | `T084_EQ_ITERATION=eq-iteration-1 node tests/E2E/t084-equivalent-date-payload.mjs` | **pass** | Jalali 1405-08-10 and Gregorian 2026-11-01 both → `expenseDate: "2026-11-01"`. |
| Timezone verification | CalendarDatePicker.spec.ts timezone describe block | **pass** | UTC, Asia/Tehran, America/New_York all produce identical canonical dates; DST boundaries (2026-03-08, 2026-11-01) included. |
| 390px responsive check | Chrome at 390×844, Persian locale, picker open | **pass** | No horizontal scroll; expense-date input visible; picker popup visible; no overflow. |
| Visible date under Jalali | Chrome evidence: `en-jalali` visible field `1405/09/01` | **pass** | Calendar-aware, not canonical ISO. |
| Visible date under Gregorian | Chrome evidence: `en-gregory` visible field `2026/11/01` | **pass** | Matches Currency/Amount formatting. |
| Canonical model unchanged | Unit tests assert `modelValue` stays `YYYY-MM-DD` | **pass** | Verified in `CalendarDatePicker.spec.ts`. |
| Visible label present | Chrome evidence: all 4 combos show `label: true` | **pass** | Localized `transactions.expenseDate` label visible. |
| RTL/LTR | Chrome evidence: `fa` combos show `dir=rtl`, `en` combos show `dir=ltr` | **pass** | Both directions verified. |
| Day number wrapping | `:deep([data-jalali-calendar-day]) { white-space: nowrap; }` | **pass** | Scoped CSS prevents wrapping; verified in browser. |
| Light/dark theme | Uses Nextcloud theme variables | **pass** | Theme-aware styling. |
| Focus indication | `focus-visible` outline preserved | **pass** | Visible keyboard focus not removed. |
| External model sync | Wrapper emits `update:modelValue` with canonical Gregorian on Jalali selection | **pass** | Verified in `CalendarDatePicker.spec.ts`. |
| Dirty-state protection | No `update:modelValue` emitted on calendar switch or mount | **pass** | Verified in `CalendarDatePicker.spec.ts`. |
| EDIT date init | T084 iteration-5: edit form shows initial expense date | **pass** | All 4 combos. |
| Open/close no change | T084 iteration-5: expense date unchanged after open/close | **pass** | All 4 combos. |

## Output Excerpts

T084 Chrome evidence (`artifacts/visual-review/t084/iteration-5/evidence.json`):

```json
{
  "combos": [
    {"combo": "en-gregory", "createdPayload": "{\"expenseDate\":\"2026-11-01\",...}", "editedPayload": "{\"expenseDate\":\"2026-11-03\",...}"},
    {"combo": "en-jalali", "createdPayload": "{\"expenseDate\":\"2026-11-22\",...}", "editedPayload": "{\"expenseDate\":\"2026-10-24\",...}"},
    {"combo": "fa-gregory", "createdPayload": "{\"expenseDate\":\"2026-11-01\",...}", "editedPayload": "{\"expenseDate\":\"2026-11-03\",...}"},
    {"combo": "fa-jalali", "createdPayload": "{\"expenseDate\":\"2026-11-22\",...}", "editedPayload": "{\"expenseDate\":\"2026-10-24\",...}"}
  ],
  "passed": true
}
```

Equivalent-date evidence (`artifacts/visual-review/t084/eq-iteration-1/evidence.json`):

```json
{
  "combos": [
    {"combo": "en-gregory", "createdPayload": "{\"expenseDate\":\"2026-11-01\",\"amountMinor\":1000,\"description\":\"Test transaction\",\"costCenterId\":\"\",\"expenseTypeId\":\"\"}"},
    {"combo": "fa-jalali", "createdPayload": "{\"expenseDate\":\"2026-11-01\",\"amountMinor\":1000,\"description\":\"Test transaction\",\"costCenterId\":\"\",\"expenseTypeId\":\"\"}"}
  ],
  "passed": true
}
```

Test summaries:

```
tests/Frontend/Component/CalendarDatePicker.spec.ts   (48 tests)  ✓
tests/Frontend/Component/PurchaserFlow.spec.ts        (151 tests)  ✓
tests/Frontend/Component/StagedReceipts.spec.ts        (5 tests)  ✓
tests/Frontend/calendarMutation.spec.ts                (3 tests)  ✓
tests/Frontend/Component/MutationPayloadCalendar.spec.ts (3 tests)  ✓
Test Files  5 passed (5)     Tests  218 passed (218)
```

Screenshots: `artifacts/visual-review/t084/iteration-5/<en-gregory\|en-jalali\|fa-gregory\|fa-jalali>/02-add-transaction-form.png` (field alignment), `04-date-selected.png` (date after selection). Equivalent-date screenshots: `artifacts/visual-review/t084/eq-iteration-1/<en-gregory\|fa-jalali>/04-date-selected.png`.

## Residual Risks

- **Owner visual acceptance recorded**: 2026-11-24 — product owner accepted the corrected transaction calendar picker.
- **No inactive-evidence or other edge cases affected**: the fix is presentational only; no behavioral edge cases are introduced.
- **Browser-only verification for day wrapping**: jsdom cannot reliably render the third-party Calendar popup; day-number non-wrapping is verified in Chrome via T084.
- **Mobile visual review deferred**: owner mobile visual acceptance remains deferred per mission scope; 390px responsive functional check verified (no horizontal scroll at 390px).

## Recommendation

**Close the bug — both reported defects are verified end-to-end.** The assessment's reproduction was exercised in official Chrome at desktop in all four language/calendar combinations, the original visual-misalignment and day-number-wrapping symptoms are gone, canonical model values and API payloads are unchanged, and all 218 focused regression tests pass. Owner visual acceptance has been recorded.