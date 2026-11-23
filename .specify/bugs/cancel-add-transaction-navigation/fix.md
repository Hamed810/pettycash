# Bug Fix: Cancel Add Transaction Navigation

- **Slug**: cancel-add-transaction-navigation
- **Fixed**: 2026-11-23
- **Assessment**: ./assessment.md
- **Status**: applied

## Summary

The original assessment classified this issue as `REQUIREMENT_GAP` because no requirement defined where Cancel from a full-page CREATE form should navigate. The canonical Feature 001 specification was subsequently amended with `FR-093`, which defines CREATE Cancel destination as the owning `sheet-detail`. The approved implementation was then applied against `FR-093` (commit `9f1d959`, pushed to `origin/main` on `main`), changing CREATE-mode cancellation navigation to the owning `sheet-detail` while preserving EDIT cancellation → `transactions`, embedded behavior, and FR-088 post-save navigation. `assessment.md` is not the final requirement source; `specs/001-pettycash-v1-core/spec.md` (`FR-093`) is the authoritative requirement for the implemented behavior.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `src/views/transactions/TransactionFormView.vue` | Changed the non-embedded full-page CREATE fallback from `{ name: 'transactions', params: { sheetId } }` to `{ name: 'sheet-detail', params: { id: sheetId.value } }`, matching the embedded branch | Application fix; scoped to CREATE-mode cancellation |
| `tests/Frontend/Component/PurchaserFlow.spec.ts` | Added three FR-093 regression cases: clean CREATE Cancel → `sheet-detail`; dirty CREATE Cancel → Discard → `sheet-detail`; dirty CREATE Cancel → Save → existing FR-088 save destination | Regression coverage; EDIT → `transactions` assertions preserved |
| `specs/001-pettycash-v1-core/spec.md` | Amended with `FR-093` defining CREATE Cancel destination as the owning `sheet-detail` | Specification amendment; distinct from the application fix |
| generated frontend assets (`js/`, `css/`) | Rebuilt assets deployed/verified against the implementation | Generated build output; not hand-edited |

## Tests Added or Updated

The following FR-093 regression cases were added to `tests/Frontend/Component/PurchaserFlow.spec.ts`:

1. Clean CREATE Cancel → owning `sheet-detail` (no create request).
2. Dirty CREATE Cancel → Discard → owning `sheet-detail` (no create request).
3. Dirty CREATE Cancel → Save → existing FR-088 save destination (`sheet-detail`, one create request).

Existing full-page EDIT → `transactions` assertions (PurchaserFlow.spec.ts:2514, 2534) were preserved unchanged as a regression boundary.

## Local Verification

`npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts`

Result: `161 passed, 0 failed`

`npx vue-tsc --noEmit`

Result: pass

`npx eslint src/views/transactions/TransactionFormView.vue tests/Frontend/Component/PurchaserFlow.spec.ts`

Result: pass, zero errors/warnings

Final browser verification succeeded against rebuilt frontend assets for:

- `sheet-detail` → Add transaction → Cancel → same `sheet-detail` (zero transaction-create POSTs, zero evidence-upload POSTs)
- `sheet-detail` → Add transaction → modify → Cancel → Discard → same `sheet-detail` (zero transaction-create POSTs, zero evidence-upload POSTs)

## Deviations from Assessment

- The initial assessment classified the issue as `REQUIREMENT_GAP` because no requirement defined CREATE Cancel destination at that time.
- The product requirement was subsequently added canonically as `FR-093` in `specs/001-pettycash-v1-core/spec.md`.
- Implementation was authorized only after that specification change.
- Therefore the implementation occurred against `FR-093`, not against an assumed requirement from the original assessment.

The original assessment was not incorrect; it accurately recorded the gap that existed at the time.

## Follow-ups

Run the canonical Spec Kit command:

`/speckit.bug.test slug=cancel-add-transaction-navigation`

If that command writes `test.md` with `Result: verified`, the Spec Kit bug workflow is complete.
