# Bug Verification: Calendar picker input alignment and localization defects

- **Slug**: `calendar-picker-alignment-localization`
- **Tested**: 2026-11-24T20:17:55Z
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: verified

## Summary

Both defects are corrected and verified end-to-end. The original symptoms do not reproduce:

- **Defect 1**: The Expense date input now matches adjacent Currency/Amount controls exactly — identical rendered height (30px), identical top/bottom edges (y=204.25px), identical field height (34px), identical focus state, no layout movement on focus, in both LTR and RTL.
- **Defect 2**: The Calendar popup displays labels matching the active Nextcloud interface language in all four language/calendar combinations, with calendar preference independent of interface language.

No regressions were introduced; all previously verified behavior is preserved.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Reproduction (post-fix) | Playwright `{ channel: 'chrome' }` at `http://localhost:8081`; measured Currency / Expense date / Amount input bounding boxes | **pass** | All three inputs: 30px height, y=204.25, field 34px y=202.25, 0px difference; focused state identical. |
| Reproduction — localization | Playwright `{ channel: 'chrome' }`; inspected rendered calendar labels for all 4 combos | **pass** | English UI shows English labels; Persian UI shows Persian labels (Jalali `آذر`/`خرداد`, Gregorian `نوامبر`/`ژانویه`); weekday headings localized. |
| New / updated tests | `npx vitest run tests/Frontend/Component/CalendarDatePicker.spec.ts` | **pass** | 61/61 passed (59 pre-existing + 2 new regression tests: input wrapper geometry, Calendar locale from `activeLanguage`). |
| Regression suite | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | **pass** | 161/161 passed. |
| TypeScript typecheck | `npx vue-tsc --noEmit` | **pass** | clean, exit 0. |
| ESLint | `npx eslint src/components/common/CalendarDatePicker.vue tests/Frontend/Component/CalendarDatePicker.spec.ts` | **pass** | 0 errors (1 pre-existing warning on self-closing `<input/>`, unrelated to fix). |
| Stylelint | `npx stylelint 'src/components/common/CalendarDatePicker.vue'` | **pass** | exit 0. |
| Frontend build | `npm run build` | **pass** | succeeded. |
| Regression protection | Existing CalendarDatePicker popup lifecycle tests (navigation keeps popup open, day selection closes, Escape closes, outside-click dismisses) | **pass** | Included in the 61/61 CalendarDatePicker run. |

## Output Excerpts

Vitest final summary:
```
 Test Files  2 passed (2)
 Tests       222 passed (222)
```

Input measurements (unfocused and focused identical, all combos):
```
Currency:        field 34px @ y=202.25, input 30px @ y=204.25
Expense date:    field 34px @ y=202.25, input 30px @ y=204.25
Amount:          field 34px @ y=202.25, input 30px @ y=204.25
```

Rendered calendar labels (official Chrome):

| Combo | Month | Weekdays | Month selector (first 3) |
|-------|-------|----------|-------------------------|
| en-gregory | `November` | Sun–Sat | January, February, March |
| en-jalali | `Azar` | Sat–Fri | Farvardin, Ordibehesht, Khordad |
| fa-gregory | `نوامبر` | ی–ش | ژانویه, فوریه, مارس |
| fa-jalali | `آذر` | ش–ج | فروردین, اردیبهشت, خرداد |

## Residual Risks

- **Owner visual acceptance pending**: deferred per mission scope; owner will review these two corrections separately.
- **Mobile visual review deferred**: owner mobile visual acceptance remains deferred; responsive functional behavior preserved.
- **Browser-only verification for alignment**: jsdom cannot compute rendered bounding boxes; alignment verified in official Chrome.
- **No Git commit**: changes are not staged or committed (per instructions).

## Recommendation

Close the bug — verified end-to-end. All critical checks pass, the original symptoms no longer reproduce, no regressions were introduced, and all 222 focused regression tests pass plus typecheck/lint/stylelint/build are clean.
