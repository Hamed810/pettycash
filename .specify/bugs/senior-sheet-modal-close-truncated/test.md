# Bug Verification: Senior sheet modal close control truncated at 390px

- **Slug**: `senior-sheet-modal-close-truncated`
- **Tested**: 2026-11-11
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: **not-reproduced** (current build already correct; no source change)

## Summary

The reported truncation does not occur in the current build. In official Chrome at 390×844 the
senior sheet modal's close control measures **66.4×44px** in English LTR (x 298.6 → right edge 365)
and **60.7×44px** in Persian RTL (x 25 → right edge 85.7). In both directions it is fully inside the
372px header, has a 44px block size (the T030 mobile floor), sits 25px inside the viewport's trailing
edge in EN, and is reported as clipped by neither the header nor the viewport. No source change was
made, and the existing 44px floor rule was deliberately left intact.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Live measurement, EN (390×844) | `node .tmp-repro/bugbatch-verify.mjs` → `artifacts/visual-review/bugbatch-001/bugtest-report.json` | **pass (not reproduced)** | `rect={x:298.6, width:66.4, height:44}`, `rightEdge=365`, `clippedByHeader=false`, `clippedByViewport=false`, `viewport=390`. |
| Live measurement, FA (390×844, RTL) | same runner | **pass (not reproduced)** | `rect={x:25, width:60.7, height:44}`, `rightEdge=85.7`, `clippedByHeader=false`, `clippedByViewport=false`, `viewport=390`. |
| Containment | same runner | **pass** | EN right edge 365 < 390; FA right edge 85.7 < 390. Both inside the modal header (x 9, w 372) with no viewport overflow. |
| Touch-target floor | Source read: `SeniorSheetModal.vue` `.senior-modal__close { min-block-size: 44px; }` | **pass** | The T030-era 44px floor is present and measured live (44px in both directions); unchanged by this bug. |
| Targeted component guards | `npx vitest run TransactionDetailsDrawer.spec.ts SeniorSheetModal.spec.ts QuickDecisionModal.spec.ts QuickDecisionModalBidi.spec.ts` | **pass** | 4 files / 51 tests. |
| Type-check / lint / style lint | `npm run typecheck`, `npx eslint <touched>`, `npx stylelint <touched>` | **pass** | Exit 0, no diagnostics (shared gate for the batch). |
| Production build | `npm run build` | **pass** | Succeeded on the batch. |
| Scope | `git status --porcelain` | **pass** | No source change for BUG-03; the close-control CSS is untouched. |

## Output Excerpts

```text
EN-390-bug03-close  present=true rect={x:298.6, width:66.4, height:44}
                   rightEdge=365 clippedByHeader=false clippedByViewport=false viewport=390

FA-390-bug03-close  present=true rect={x:25, width:60.7, height:44}
                   rightEdge=85.7 clippedByHeader=false clippedByViewport=false viewport=390
```

The duplicate assertion in the batch runner (EN + FA) both resolved `PASS`:

```text
BUG-03 en  PASS
BUG-03 fa  PASS
```

## Residual Risks

- **Emulation, not a physical device.** 390×844 is a Chrome mobile-emulation viewport. A real device
  with different font fallback, zoom, or a browser-injected toolbar could in principle alter the
  header width; nothing in the measured layout is near the edge in EN (25px of headroom).
- **Report may predate the T030 floor.** The assessment notes the report and the 44px floor are the
  same day; an earlier build could have shown the truncation. The current build is intact, so there
  is nothing to patch now.
- **Long localized labels are a new report.** The measured label is short in both `en` and `fa`. A
  future locale with a much longer "Close sheet" translation could produce a different header split;
  that would need its own assessment, not a speculative change here.
- **No screenshot diff.** Verification is DOM geometry (`getBoundingClientRect`) plus containment
  booleans, not pixel comparison. The metric directly answers "truncated?" but does not capture
  cosmetic anti-aliasing.
- **T037 untouched.** No E2E suite was run.

## Recommendation

**Close BUG-03 as not reproduced — no code change.** Keep the existing
`min-block-size: 44px` guard exactly as it is (removing or weakening it would reintroduce this bug
class). If the owner still sees truncation, request the device, browser build, zoom level, and a
screenshot on that surface before reopening.
