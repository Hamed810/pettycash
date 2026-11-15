# Bug Fix: Full-details drawer action controls overflow the 390px viewport

- **Slug**: `details-drawer-actions-overflow-390`
- **Fixed**: 2026-11-10
- **Assessment**: ./assessment.md
- **Status**: applied

## Summary

The drawer footer at ≤640px was `flex-direction: column` but kept the desktop `flex-wrap: wrap`, and
with a height-constrained column the actions wrapped into overflow columns past the 390px viewport
(left→559 in LTR, −153→209 in RTL). Added `flex-wrap: nowrap` (written as `flex-flow: column nowrap`)
to the mobile footer rule so the actions stack full-width inside the drawer.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `src/components/review/TransactionDetailsDrawer.vue` | modified (scoped CSS in `@media (width <= 640px)`) | `.details-drawer__footer` now `flex-flow: column nowrap`. No template/script/API change. |
| `tests/Frontend/Component/TransactionDetailsDrawer.spec.ts` | added test | Untouched by this CSS change; kept green as a regression anchor. |

## Diff Highlights

```css
@media (width <= 640px) {
	.details-drawer__footer {
		flex-flow: column nowrap;   /* BUG-02: no column-wrap overflow past the 390px viewport */
		align-items: stretch;
		padding: 12px 14px;
	}
}
```

## Tests Added or Updated

- No new unit test (jsdom cannot measure geometry). The fix outcome was validated by a runtime
  injection probe (`.tmp-repro/bug02-fix-injection.mjs`) before the source change: forcing nowrap
  dropped footer `scrollWidth` 559→362 (EN) / 543→390 (FA), moved every button to x=14→376, and
  cleared `buttonBeyondWindow` in both directions.
- Existing `TransactionDetailsDrawer.spec.ts` (13) and `SeniorSheetModal.spec.ts` (23) re-run green.

## Local Verification

- `npx vitest run tests/Frontend/Component/TransactionDetailsDrawer.spec.ts` → 13 passed.
- `npx vitest run tests/Frontend/Component/SeniorSheetModal.spec.ts` → 23 passed.
- `npx stylelint src/components/review/TransactionDetailsDrawer.vue` → clean.
- `npm run typecheck` (full) → clean.
- Live Chrome re-verification with the built bundle is in `test.md`.

## Deviations from Assessment

None (the preferred one-declaration remediation was applied verbatim; the `flex-flow` shorthand is
an equivalent, stylelint-compliant spelling).

## Follow-ups

- The shared drawer also hosts the Cost Center queue; the same rule heals that host's 390px footer —
  covered by the shared live guard in `test.md`.
