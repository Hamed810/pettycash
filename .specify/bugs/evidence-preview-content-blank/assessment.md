# Bug Assessment: Evidence preview shows no visible content (1×1 fixture PNG)

- **Slug**: `evidence-preview-content-blank`
- **Created**: 2026-11-10T21:30:00Z
- **Source**: Feature 007 owner report, defect **BUG-01**
- **Verdict**: **reproduction artifact (fixture data); no app defect reproduced.**
- **Severity**: **low** (verification-artifact issue, not an app source defect)

## Report (summarized)

> In the senior review flow, opening an evidence item's preview shows no visibly rendered content —
> the preview layer appears blank.

Expected: a real evidence image/pdf/text renders visibly inside the preview overlay.

## Investigation

Byte-level and DOM traces (live lab, `http://localhost:8081`, official Chrome, 390×844, transaction
`2d671926-372f-48ca-81f1-1865386c28c2`, sheet `630a6e00-f7ca-43d7-9bb4-002ce19bfc6b`):

- The three evidence files served by the preview endpoint (`b4-receipt-1/2/3.png`) are each
  **exactly 70 bytes**, valid `image/png` with **width=1, height=1** (header
  `89504e470d0a1a0a0000000d49484452`), matching `sizeBytes=70` in the transaction metadata.
- Those bytes are exactly `PNG_1X1` — `tests/E2E/t026-quick-review-evidence.mjs:54-58`, a base64
  **1×1 red placeholder PNG** uploaded by the T026 fixture at `:194,:207`.
- The preview pipeline itself works: the browser fetches the content, decodes the blob to an
  object URL, and the `<img data-test="evidence-preview-image">` reports `complete:true`,
  `naturalWidth:1`, `naturalHeight:1`, and a 1×1 rendered rect (`probe-report.json`,
  EN-390-preview-content (BUG-01)). No loading/error/download-only state is showing; the image path
  is the active path.

`EvidenceController::content()` (`lib/Controller/EvidenceController.php`) returns the stored bytes
(the 70-byte PNG) unchanged; `AppDataEvidenceStore` preserves them. There is no serving, fetch,
decode, or rendering defect in the app.

## Root Cause

**Confidence: high.** The stored fixture is a degenerate 1×1 placeholder image. The preview renders
it faithfully — 1 pixel wide — which to a human is "blank". The blank preview is an artifact of the
shared E2E fixture data in this lab, not a defect in `EvidencePreview.vue`'s rendering pipeline. Real
attachments (normal dimensions) would render visibly through the exact same path.

## Proposed Remediation

- **No app source change.** The rendering path is proven correct end-to-end.
- **Correct the verification artifact**: verify the preview against a real-dimension image instead of
  the 1×1 fixture, without creating new persistent lab records. Concretely, in the browser probe
  route-intercept the evidence-content request and substitute a real PNG (e.g. the 1054-byte
  `receipt-real.png` already present in `.tmp-repro/`), then assert the preview `<img>` has
  `naturalWidth > 1 && naturalHeight > 1` and a visible (>5px) rendered rect — in EN and FA. That
  proves the same app path renders real evidence visibly.
- Document in the owner report that the T026 `PNG_1X1` placeholder is the fixture that makes current
  lab previews look empty; real receipts are unaffected. Optionally replace the T026 fixture with a
  real image in a separately-authorized E2E change (out of scope here — do not touch the E2E fixture
  set or reseed unless the owner approves).

**Files likely to change**:
- None in `src/`. Only the untracked probe runner and this bug artifact set.

**Tests to add or update**:
- Chrome guard (read-only, runtime route substitution): open preview with a routed real PNG and
  assert `naturalWidth>1`, `naturalHeight>1`, visible rect, no error state, in EN and FA.
- No jsdom geometry test can add value here; keep the existing component specs untouched.

## Risks & Considerations

- **No T026/T037 impact**: no E2E run, no reseed, no new records, no decision; T037 ledger state
  stays pending. The route-substitution probe writes nothing to the lab.
- If the owner later insists the *preview UI* should also show a placeholder/non-blank state for
  degenerate images, that is a new feature request (design decision), not this bug.

## Relevant Spec Kit Requirements

- FR-007 evidence preview acceptance requires authorized evidence to render; satisfied for real
  bytes. No requirement that 1×1 placeholder fixtures render as "content".

## Open Questions

- None blocking. (Whether T026's fixture should become a real image is a separate E2E-fixture change
  for the owner to authorize.)
