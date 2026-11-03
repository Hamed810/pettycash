# Bug Verification: Mobile evidence-list filename reflow

- **Slug**: `mobile-evidence-filename-wrap`
- **Tested**: 2026-10-26
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: **verified**

## Summary

The original symptom does not reproduce. In official Google Chrome 156.0.8078.47 at 390×844, in
both hosts and both directions, the 19-character fixture filename now renders on **one line** in a
**255–273.5px** body instead of 5–9 lines in a 21–39.5px column, the row is **163.8px** instead of
392–776px, no page overflow appears, and every action stays inside the row and viewport. Desktop
1440×900 keeps the unchanged single-line flex row. New and existing evidence/purchaser guards pass
(45/45 focused, 151/151 purchaser flow). One repository-wide guard remains red for a
**pre-existing, unrelated** declaration in the uncommitted T077 uploader, which this bug is
forbidden to modify.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Reproduction (post-fix, 390×844) | `node .specify/bugs/mobile-evidence-filename-wrap/verify-post-fix.mjs` plus threshold assertions | **pass** | 4/4 runs (`en`+`fa` × editing form + detail view). Body 273.5px/255px, 1 name line, row 163.8px, `doc 390/390`, buttons inside row/viewport, DOM order icon→body→actions, `display: grid`, actions `grid-column: 1 / -1`. 0 criterion failures, 0 page errors. |
| Desktop regression (1440×900) | same runner | **pass** | 4/4 runs. `display: flex`, body 579.5px/735px, action cluster 219px, row 74.3px, `doc 1440/1440`. Mobile rule did not leak upward. |
| T076 lifecycle chip | `node .specify/bugs/mobile-evidence-filename-wrap/verify-lifecycle-chip.mjs` | **pass** | Real non-draft receipt: chip rendered (`Active` 79px / `فعال` 65.9px), action row one 43px line, no wrap, no overflow. Browser-only synthetic worst case (chip + three buttons) wrapped within the second row in `en` (93.5px) and fit on one line in `fa` (43px); no row or viewport overflow either way. |
| New / updated tests | `npx vitest run tests/Frontend/Component/EvidenceListMobileLayout.spec.ts` | **pass** | 6/6. Mutation check from the fix still holds: removing the media block fails 2/6. |
| Related component guards | `npx vitest run EvidenceListMobileLayout.spec.ts EvidenceStatusPresentation.spec.ts RtlAccessibility.spec.ts EvidencePreview.spec.ts EvidenceUploaderCompact.spec.ts` | **pass** | 5 files / **45 tests passed**. Covers T076 lifecycle, RTL declarations, preview/download, and the untouched T077 uploader. |
| Broader purchaser regression | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | **pass** | **151/151 passed** in 21.0s. Exercises both hosts that render the shared list. |
| Repository FR-071 guard | `npx vitest run tests/Frontend/fr071NarrowViewport.spec.ts` | **fail (pre-existing, unrelated)** | 2/3 passed. The one failure names `src/components/evidence/EvidenceUploader.vue: overflow-x: auto;` — a declaration in the uncommitted T077 uploader (absent from `HEAD`) whose mtime is 2026-10-25 20:26, before this fix. `EvidenceList.vue` contains no `overflow-x` declaration. Outside `T078`'s authorized files and not introduced by this fix. |
| Lint (style) | `npx stylelint 'src/components/evidence/EvidenceList.vue'` | **pass** | exit 0. |
| Lint (script) | `npx eslint EvidenceListMobileLayout.spec.ts EvidenceList.vue` | **pass** | 0 errors, 1 pre-existing warning (`vue/no-required-prop-with-default` on `isDraft`). |
| Type-check | `npx vue-tsc --noEmit` | **pass** | exit 0. |
| Build / served asset | `grep` of `css/transactionsApi-*.chunk.css` | **pass** | Built CSS contains `@media(width<=640px){.evidence-item…display:grid…}`. Not rebuilt because no source changed after the successful fix build (source mtime 05:54:50, bundle 05:55:08). |
| Source drift | `git status` / mtimes | **pass** | `EvidenceList.vue` and the new spec are unchanged since the fix; `assessment.md` untouched; out-of-scope host/uploader/preview files retain 2026-10-25 mtimes. No source was modified by this validation. |
| Measurement determinism | `diff` against fix-run snapshots | **pass** | Fresh `verify-post-fix.json` and `verify-lifecycle-chip.json` are byte-identical to the fix-run snapshots. Account language restored to `en` and verified. |

## Output Excerpts

Post-fix Chrome run (390 and desktop; `doc` is `scrollWidth/clientWidth`):

```text
post-fix: browser=156.0.8078.47 lang={"before":"en","during":"fa","restored":true} errors=0
mobile  en editing form           body=273.5 lines=1 item=163.8 disp=grid doc=390/390
mobile  en detail view            body=255   lines=1 item=163.8 disp=grid doc=390/390
mobile  fa editing form           body=273.5 lines=1 item=163.8 disp=grid doc=390/390
mobile  fa detail view            body=255   lines=1 item=163.8 disp=grid doc=390/390
desktop en editing form           body=579.5 lines=1 item=74.3  disp=flex doc=1440/1440
desktop en detail view            body=735   lines=1 item=74.3  disp=flex doc=1440/1440
desktop fa editing form           body=579.5 lines=1 item=74.3  disp=flex doc=1440/1440
desktop fa detail view            body=735   lines=1 item=74.3  disp=flex doc=1440/1440
lifecycle en rows=1 chip=Active wrapped=false worst.wrapped=true  worst.overflow=false
lifecycle fa rows=1 chip=فعال   wrapped=false worst.wrapped=false worst.overflow=false
FAILURES=0
```

Test summaries:

```text
tests/Frontend/Component/EvidenceListMobileLayout.spec.ts   (6 tests)  ✓
tests/Frontend/Component/EvidenceStatusPresentation.spec.ts (9 tests)  ✓
tests/Frontend/Component/RtlAccessibility.spec.ts           (9 tests)  ✓
tests/Frontend/Component/EvidencePreview.spec.ts            (13 tests) ✓
tests/Frontend/Component/EvidenceUploaderCompact.spec.ts    (8 tests)  ✓
Test Files  5 passed (5)     Tests  45 passed (45)

tests/Frontend/Component/PurchaserFlow.spec.ts  Test Files 1 passed (1)  Tests 151 passed (151)
```

Known FR-071 failure (not caused by this fix):

```text
× no view in src/ answers a narrow viewport with a clipping overflow
Offenders:
src/components/evidence/EvidenceUploader.vue: overflow-x: auto;
Test Files  1 failed (1)     Tests  1 failed | 2 passed (3)
```

## Residual Risks

- **Pre-existing FR-071 guard failure.** The uncommitted `T077` uploader's `overflow-x: auto`
  keeps `fr071NarrowViewport.spec.ts` red. It is unrelated to `EvidenceList.vue` and outside
  `T078`'s authorized scope; it needs its own decision (fix or explicitly waive the blanket rule).
  The bug-specific FR-071 criterion — no horizontal scroll at 390px — passes in Chrome.
- **No inactive-evidence fixture.** The lab has no inactive evidence row, so the Inactive chip plus
  deactivation timestamp was not measured live. The action cluster is strictly narrower because
  preview, download, and remove are all hidden when `ev.active` is false; the T076 component guard
  still passes and asserts the chip/deactivation text.
- **Legacy DRAFT-on-non-OPEN worst case.** Not present in fixtures. The browser-only synthetic
  measurement shows a chip + three-button cluster wraps inside its own second row rather than
  overflowing, should that combination occur.
- **Deliberate wrapping.** Very long filenames can still occupy multiple lines; the fix chooses full
  visibility over truncation. Ordinary 19-character names are one line.
- **Evidence staleness.** T075/T077 screenshots are stale for the list area below the uploader.
  `T077`'s approval remains valid for the uploader only; `T075` remains its own
  `READY_FOR_OWNER_APPROVAL` gate.
- **Not covered:** Firefox/Safari, and owner visual acceptance. The assessment specified official
  Chrome; `T078` remains `READY_FOR_OWNER_APPROVAL` in the ledger.
- **Touch targets** remain 68×43px; the 1px-below-44px height is pre-existing and unchanged.

## Recommendation

**Close the bug — the reported defect is verified end-to-end.** The assessment's own reproduction
was exercised in official Chrome at 390px in both hosts and both languages, the original 21–39.5px
character-column symptom is gone, desktop is unchanged, and the T076 lifecycle boundary still holds.
Record owner acceptance of `T078` separately, and handle the pre-existing `T077` uploader
`overflow-x: auto` FR-071 guard failure as its own decision; neither blocks this bug's resolution.
