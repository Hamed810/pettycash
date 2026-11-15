# Bug Fix: Evidence preview shows no visible content (1×1 fixture PNG)

- **Slug**: `evidence-preview-content-blank`
- **Fixed**: 2026-11-10
- **Assessment**: ./assessment.md
- **Status**: not-applied (no app source change; fixture-data artifact)

## Summary

The blank preview is caused by the lab fixture, not the application: the three stored evidence files
are 70-byte 1×1 `PNG_1X1` placeholders uploaded by `tests/E2E/t026-quick-review-evidence.mjs:55-58`
(`b4-receipt-1/2/3.png`). The preview pipeline (fetch → blob → object URL → `<img>` decode) works and
faithfully renders that 1×1 image — a pixel is "blank" to a human. No source fix is warranted; the
verification artifact is corrected instead.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `src/` | none | Rendering path proven correct end-to-end. |
| `.tmp-repro/evidence-content-probe.mjs` | amended probe | `Accept: application/json` so the OCS detail/evidence content respond JSON; confirmed the served bytes decode to PNG width=1,height=1. |
| `.tmp-repro/bugbatch-probe.mjs` | existing probe | Already captured preview `img` state (complete, natural 1×1, rendered). |

## Tests Added or Updated

- None in the suite (no app code changed). A browser guard with runtime route substitution (serve a
  real PNG and assert `naturalWidth>1`/`naturalHeight>1` + visible rect) is run in `test.md` to prove
  real evidence renders visibly through the same path, without writing to the lab.

## Local Verification

- `.tmp-repro/evidence-content-probe.mjs` → all three `CONTENT … name=b4-receipt-*.png actualBytes=70
  dims={"type":"png","width":1,"height":1}` — matches the fixture authoring.

## Deviations from Assessment

None.

## Follow-ups

- If the owner wants T026's fixture to be a real image (so future lab captures show content), that is
  a separate E2E-fixture change to authorize; it was not run here (T037 must stay pending, and no
  reseed/write was performed).
