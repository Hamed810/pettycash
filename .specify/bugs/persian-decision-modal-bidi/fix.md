# Bug Fix: Persian decision-modal mixed-direction values render incorrectly

- **Slug**: `persian-decision-modal-bidi`
- **Fixed**: 2026-11-10
- **Assessment**: ./assessment.md
- **Status**: applied

## Summary

The quick-decision modal wrapped its LTR values (sheet UUID reference, money amount) in bare `<bdi>`
elements, which only isolate under the RTL base direction instead of rendering the embedded LTR token
correctly. Added `dir="ltr"` to the three value `<bdi>`s (eyebrow reference, summary reference,
amount), matching the parent senior modal's `senior-modal__reference` and the drawer's
`dir="ltr"` pattern; the surrounding Persian text stays RTL.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `src/components/review/QuickDecisionModal.vue` | modified (template only) | `dir="ltr"` on the eyebrow reference (line 22), summary reference (line 48), and amount (line 54). No copy/logic/API change. |
| `tests/Frontend/Component/QuickDecisionModal.spec.ts` | added spec | Pins every `<bdi>` in the modal to an explicit `dir="ltr"` and the two references to the exact UUID. |

## Diff Highlights

```html
{{ t('pettycash', 'reviews.quickReview.eyebrow') }} · <bdi dir="ltr">{{ transaction.sheetReference }}</bdi>
...
<bdi dir="ltr">{{ transaction.sheetReference }}</bdi>
...
<bdi dir="ltr">{{ amountLabel }}</bdi>
```

## Tests Added or Updated

- `tests/Frontend/Component/QuickDecisionModal.spec.ts` — new file: mounts the modal in RTL and
  asserts the eyebrow/summary references carry `dir="ltr"` with the exact UUID, the amount `<bdi>` is
  `dir="ltr"`, and the transaction-id isolate keeps `dir="ltr"` (no bare-RTL `<bdi>` remains).

## Local Verification

- `npx vitest run tests/Frontend/Component/QuickDecisionModal.spec.ts` → 2 passed.
- `npx eslint …QuickDecisionModal*` → clean.
- `npm run typecheck` (full) → clean.
- Live Chrome re-verification (RTL) is in `test.md`.

## Deviations from Assessment

None.

## Follow-ups

- The drawer's `details-drawer__amount` still uses a bare `<bdi>` (`TransactionDetailsDrawer.vue`),
  out of the owner-reported scope; flagged in the assessment as an optional later alignment.
