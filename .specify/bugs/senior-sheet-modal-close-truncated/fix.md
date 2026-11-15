# Bug Fix: Senior sheet modal close control truncated at 390px

- **Slug**: `senior-sheet-modal-close-truncated`
- **Fixed**: 2026-11-10
- **Assessment**: ./assessment.md
- **Status**: not-applied (not reproduced; already correct)

## Summary

The reported truncated close control does not reproduce in the current build at 390×844. The close
button measures 44px tall, sits fully inside the modal header in both directions (EN x 298.6→365,
FA x 25→85.7), is covered by nothing at its centre/edge points, and has no viewport or header
clipping. The T030 44px floor (`src/components/review/SeniorSheetModal.vue:1134-1136`) is live. No
source change was made; the guard that prevents this defect is deliberately kept.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `src/` | none | Defect not present in the measured build (official Chrome `{ channel: 'chrome' }`, 390×844, EN + FA). |

## Tests Added or Updated

- None. The existing 44px-floor source assertion in
  `tests/Frontend/Component/QuickReviewAccessibilityConformance.spec.ts`
  (`senior-modal__close … min-block-size: 44px`) remains the permanent guard; the live probe
  (`.tmp-repro/bugbatch-probe.mjs`) is retained as measurement evidence.

## Local Verification

- `artifacts/visual-review/bugbatch-001/probe-report.json` → EN-390-modal-close (BUG-03):
  `clippedByHeader:false`, `clippedByViewport:false`, height 44; FA-390-modal-close (BUG-03):
  x 25→85.7, h 44, `clippedByHeader:false`. No `elementFromPoint` coverer outside the button's own
  label.

## Deviations from Assessment

None (nothing to fix).

## Follow-ups

- If the owner reproduces truncation on another viewport/orientation or with a longer localized
  label, file a new report for a fresh assessment rather than treating this as open.
