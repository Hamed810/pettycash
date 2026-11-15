# Bug Verification: Persian decision-modal mixed-direction values render incorrectly

- **Slug**: `persian-decision-modal-bidi`
- **Tested**: 2026-11-11
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: **verified**

## Summary

In the Persian RTL quick-decision confirmation, every mixed-direction value is now an explicitly
directional LTR isolate. The live DOM shows the modal layer with `dir="rtl"` and **all four** `<bdi>`
elements carrying `dir="ltr"`: the sheet reference in the eyebrow, the sheet reference in the summary
meta, the transaction id, and the amount (`50,000 IRR`). Before the fix only the transaction id had
`dir`; the other three were bare `<bdi>` inheriting the RTL base direction.

The fix is template-only: three `dir="ltr"` additions in `QuickDecisionModal.vue`, matching the parent
senior modal's established `<bdi dir="ltr">` convention. No copy, logic, API, or persistence change.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Live DOM, FA RTL (390×844) | `node .tmp-repro/bugbatch-verify.mjs` → `artifacts/visual-review/bugbatch-001/bugtest-report.json` | **pass** | layer `dir=rtl`; 4/4 `<bdi>` report `dir=ltr` (eyebrow ref, summary ref, transaction id, amount `50,000 IRR`). |
| Pre-fix reproduction | `probe-report.json` (FA-390-decision-modal-bidi) | **reproduced** | Entries 1, 3, 4 had `dirAttr: null` (bare `<bdi>`); only the transaction id was `ltr`. |
| Attribute guard, RTL mount | `npx vitest run tests/Frontend/Component/QuickDecisionModalBidi.spec.ts` | **pass** | 2/2: layer `dir=rtl`; both reference `<bdi>`s plus the amount and the meta-id are `dir=ltr` (reference/amount are the fix; meta-id was already correct). |
| All-isolates guard | same spec | **pass** | Every `<bdi>` under the layer has an explicit direction; the bare-RTL default set is empty. |
| Existing modal suite preserved | `npx vitest run tests/Frontend/Component/QuickDecisionModal.spec.ts` | **pass** | 13/13 pre-existing T027/T029 tests (copy, capability gate, FR-033 guard, submit/escape, conflict/forbidden wording, command identity, focus trap) restored and green. The BUG-04 cases live in the separate `…Bidi.spec.ts` so they add coverage without replacing that suite. |
| Targeted component guards | `npx vitest run QuickDecisionModal.spec.ts QuickDecisionModalBidi.spec.ts TransactionDetailsDrawer.spec.ts SeniorSheetModal.spec.ts` | **pass** | 4 files / 51 tests (includes the 2 above and the 13 restored). |
| Type-check | `npm run typecheck` | **pass** | `vue-tsc --noEmit`, exit 0. |
| Script lint | `npx eslint` on the touched component + specs | **pass** | Exit 0, no output. |
| Style lint | `npx stylelint src/components/review/QuickDecisionModal.vue` | **pass** | Exit 0, no output. |
| Production build | `npm run build` | **pass** | Succeeded on the batch. |
| Console / page errors | `bugtest-report.json` (EN + FA) | **pass** | None in either pass. |
| Scope | `git status --porcelain` | **pass** | Template-only change in `QuickDecisionModal.vue`; no CSS, script, data, or business rule touched. |

## Output Excerpts

Live FA decision modal:

```text
FA-390-bug04-decision-modal-bidi
  layerDir="rtl"
  bdis=[
    {dir:"ltr", text:"630a6e00-f7ca-43d7-9bb4-"},   // eyebrow sheet reference
    {dir:"ltr", text:"2d671926-372f-48ca-81f1-"},   // transaction id
    {dir:"ltr", text:"630a6e00-f7ca-43d7-9bb4-"},   // summary sheet reference
    {dir:"ltr", text:"50,000 IRR"}                  // amount
  ]

BUG-04 fa  PASS
```

Component guard:

```text
tests/Frontend/Component/QuickDecisionModalBidi.spec.ts  (2 tests)  ✓
  ✓ renders the LTR sheet reference and amount as LTR-isolated tokens inside the RTL modal
  ✓ keeps every `<bdi>` an explicit direction so no bare RTL default remains

tests/Frontend/Component/QuickDecisionModal.spec.ts      (13 tests) ✓  (pre-existing T027/T029 suite, preserved)
```

The applied template change:

```html
<bdi dir="ltr">{{ transaction.sheetReference }}</bdi>          <!-- eyebrow -->
<bdi dir="ltr">{{ transaction.sheetReference }}</bdi>          <!-- summary meta -->
<bdi dir="ltr">{{ amountLabel }}</bdi>                         <!-- amount -->
```

## Residual Risks

- **Geometric ordering is inferred, not OCR'd.** jsdom cannot measure bidi layout, and the live guard
  asserts the authoritative `dir` attributes plus the RTL layer. Correct `dir="ltr"` isolates are the
  standard, and the surrounding Persian text remains RTL, but the check does not render-compare glyph
  positions pixel-by-pixel.
- **One modal, three surfaces.** The shared drawer's amount remains a bare `<bdi>` (the assessment
  explicitly scoped the defect to the decision modal); if the owner later reports the drawer amount,
  that is a new assessment, not a regression of this fix.
- **Locale digits.** The measured amount used Latin digits (`50,000 IRR`). With Persian digits the
  isolate would still be LTR because the attribute is what pins direction; not separately measured.
- **T037 untouched.** No E2E suite was run.

## Recommendation

**Close BUG-04 — verified.** The three bare `<bdi>`s now pin `dir="ltr"`, matching the parent modal's
convention, and the surrounding Persian surface stays RTL. The component spec prevents a silent
revert to bare isolates.
