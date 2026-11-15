# Bug Verification: Evidence preview shows no visible content (1×1 fixture PNG)

- **Slug**: `evidence-preview-content-blank`
- **Tested**: 2026-11-11
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: **verified-as-fixture** (reproduction artifact; no application defect)

## Summary

The preview overlay renders whatever the evidence endpoint serves, and the lab was serving a
degenerate image: all three evidence files on the reported transaction are **70-byte, 1×1 PNGs**,
byte-identical to the `PNG_1X1` placeholder uploaded by the T026 E2E fixture
(`tests/E2E/t026-quick-review-evidence.mjs:54-58`). A faithful 1-pixel render therefore looks blank.

No app change was made. The verification guard route-substitutes a real **320×240** PNG for the
content request (a browser-only interception — no lab write, no new record, no decision) and asserts
the same preview path renders it visibly. In official Chrome at 390×844, English LTR and Persian RTL,
the `<img data-test="evidence-preview-image">` reports `naturalWidth=320`, `naturalHeight=240`, a
rendered `320×240` rect, and no error / download-only / loading state in both directions
(`servedRoutes=1` each). This proves the defect is fixture data, not the preview pipeline.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Fixture identification | Byte + PNG-header inspection (`probe-report.json`, `.tmp-repro/evidence-content-probe.mjs`) | **fixture artifact** | All three files exactly 70 bytes, valid PNG `1×1`, equal to `PNG_1X1` at `t026-quick-review-evidence.mjs:54-58`; metadata `sizeBytes=70`. |
| Serving path | Source read: `lib/Controller/EvidenceController.php` `content()` (~444) and `AppDataEvidenceStore` | **pass (no serving bug)** | Returns the stored bytes unchanged; the 70-byte 1×1 PNG is what the app is given. |
| Un-substituted live preview | `probe-report.json` → EN-390-preview-content (BUG-01) | **faithful 1×1** | `complete=true`, `naturalWidth=1`, `naturalHeight=1`, ~1×1 rect, no error/loading state. The image path is active; there is simply 1 pixel of content. |
| Live preview with a real image, EN (390×844) | `node .tmp-repro/bugbatch-verify.mjs` → `artifacts/visual-review/bugbatch-001/bugtest-report.json` | **pass** | `hasImage=true`, natural **320×240**, rect `{x:35, y:105.3, w:320, h:240}`, `servedRoutes=1`, error/download-only/loading all `false`. |
| Live preview with a real image, FA (390×844, RTL) | same runner | **pass** | Identical 320×240 natural + rendered rect, `servedRoutes=1`, no fallback state. |
| Console / page errors | same runner (EN + FA) | **pass** | No console errors, no page errors in either pass. |
| Targeted component guards | `npx vitest run TransactionDetailsDrawer.spec.ts SeniorSheetModal.spec.ts QuickDecisionModal.spec.ts QuickDecisionModalBidi.spec.ts` | **pass** | 4 files / 51 tests. No preview component spec was changed; no app source changed for this bug. |
| Type-check / lint / style lint | `npm run typecheck`, `npx eslint <touched>`, `npx stylelint <touched>` | **pass** | Exit 0, no diagnostics (shared gate for the batch). |
| Production build | `npm run build` | **pass** | Succeeded on the batch. |
| Scope | `git status --porcelain` | **pass** | No application source change for BUG-01; only untracked bug artifacts and read-only probes. |

## Output Excerpts

```text
EN-390-bug01-preview-realsub  servedRoutes=1 hasImage=true naturalWidth=320 naturalHeight=240
  rect={x:35, y:105.3, width:320, height:240}
  errorVisible=false downloadOnlyVisible=false loadingVisible=false

FA-390-bug01-preview-realsub  servedRoutes=1 hasImage=true naturalWidth=320 naturalHeight=240
  rect={x:35, y:105.3, width:320, height:240}
  errorVisible=false downloadOnlyVisible=false loadingVisible=false

EN-console  errors=[]
FA-console  errors=[]
```

The three stored fixtures, from the byte probe:

```text
b4-receipt-1.png  70 bytes  89504e470d0a1a0a0000000d49484452...  width=1 height=1
b4-receipt-2.png  70 bytes  89504e470d0a1a0a0000000d49484452...  width=1 height=1
b4-receipt-3.png  70 bytes  89504e470d0a1a0a0000000d49484452...  width=1 height=1
PNG_1X1 (t026:54-58) == served bytes
```

## Residual Risks

- **The live fixture still looks blank.** Nothing in this bug batch changed the T026 `PNG_1X1`
  upload, so re-opening the same seed still shows an apparently empty preview. That is expected
  fixture data, not a regression. Replacing the T026 placeholder with a real image belongs to a
  separately authorized E2E-fixture change (the assessment explicitly forbids touching/reseeding
  the fixture here).
- **Image path only.** The real-image substitution exercises the image branch of the preview. PDF /
  text / other document branches were not re-measured in this run; the assessment's byte trace only
  established the image path. If the owner's report ever involves a non-image attachment, re-test
  that branch.
- **Client path vs server bytes.** The route substitution proves the client `<img>`/overlay path
  renders non-degenerate bytes. The server-side claim (that the app returns the stored 1×1 bytes
  unchanged) rests on the controller/source read plus the byte probe, not on the substituted run.
- **T037 untouched.** No E2E suite was run; the T037 ledger item stays pending as instructed.

## Recommendation

**Close BUG-01 as a reproduction artifact — no application fix.** Document for the owner that the
T026 fixture's `PNG_1X1` placeholder is why previews look empty, and that real receipts render through
the same path. If a permanent non-blank lab demo is wanted, authorize a separate one-line E2E fixture
change (upload a real PNG instead of `PNG_1X1`); do not weaken the preview UI for degenerate images.
