# Bug Fix: Full-details drawer sheet metadata inconsistent with parent modal

- **Slug**: `details-drawer-metadata-inconsistent`
- **Fixed**: 2026-11-10
- **Assessment**: ./assessment.md
- **Status**: applied

## Summary

`SeniorSheetModal` rendered its details drawer with `:sheet="null"` because the drawer's `sheet` prop
was typed `ManagerSheetSummary | null` while the senior read produces `SeniorSheetSummary` (no
`status`, extra review fields) — so the drawer showed "Sheet title unavailable"/"Purchaser
unavailable" next to a parent header that had the real title/owner. Widened the prop to the union and
pass the review's sheet header into the drawer.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `src/components/review/TransactionDetailsDrawer.vue` | modified | `sheet` prop type → `ManagerSheetSummary \| SeniorSheetSummary \| null`; imported `SeniorSheetSummary`; doc comment updated. The component only reads `title`/`ownerDisplayName`, present in both shapes. |
| `src/components/review/SeniorSheetModal.vue` | modified | `:sheet="null"` → `:sheet="review?.sheet ?? null"` (line 266 area). |
| `tests/Frontend/Component/TransactionDetailsDrawer.spec.ts` | added test | Drawer renders a `SeniorSheetSummary` header's title/owner, not the unavailable fallbacks. |
| `tests/Frontend/Component/SeniorSheetModal.spec.ts` | added test | Seeded senior read → header and the drawer's sheet/purchaser fields show the same title/owner. |

## Diff Highlights

```ts
// TransactionDetailsDrawer.vue
sheet: ManagerSheetSummary | SeniorSheetSummary | null
```

```html
<!-- SeniorSheetModal.vue -->
:sheet="review?.sheet ?? null"
```

## Tests Added or Updated

- `TransactionDetailsDrawer.spec.ts::renders the sheet header from the Senior review shape, not the
  unavailable fallbacks (BUG-05)` — mounts with a `SeniorSheetSummary` object, asserts
  `drawer-field-sheet`/`drawer-field-purchaser` carry its title/owner.
- `SeniorSheetModal.spec.ts::shows the parent modal sheet header in the full-details drawer metadata
  (BUG-05)` — seeds `reviewsStore.seniorSheetReviews`, opens the drawer from the modal, asserts the
  drawer fields match the header (`Senior travel review` / `User One`) with no unavailable fallback.

## Local Verification

- `npx vitest run tests/Frontend/Component/TransactionDetailsDrawer.spec.ts` → 13 passed.
- `npx vitest run tests/Frontend/Component/SeniorSheetModal.spec.ts` → 23 passed.
- `npx eslint …` on both specs and both components → clean.
- `npm run typecheck` (full) → clean (the Cost Center host's `ManagerSheetSummary` call sites remain
  valid under the union).
- Live Chrome re-verification (metadata equality) is in `test.md`.

## Deviations from Assessment

None. The Cost Center path stays byte-for-byte identical (union widened, rendering logic untouched).

## Follow-ups

- None.
