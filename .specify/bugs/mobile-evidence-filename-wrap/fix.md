# Bug Fix: Mobile evidence-list filenames collapse to a character column

- **Slug**: `mobile-evidence-filename-wrap`
- **Fixed**: 2026-10-26
- **Assessment**: ./assessment.md
- **Status**: applied

## Summary

At 390px the shared evidence row could not reflow: `.evidence-item__actions` held its max-content
width (219px, three 68px buttons) because of `flex-shrink: 0`, so the body absorbed the deficit and
`overflow-wrap: anywhere` broke an ordinary 19-character filename into 5–9 lines in a 21–39.5px
column. A scoped `@media (width <= 640px)` rule now turns the row into a two-column grid and gives
the action cluster a full-width second row. The change is CSS-only inside `EvidenceList.vue`; no
template, script, prop, host view, uploader, transport, permission, persistence, or audit code
changed.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `src/components/evidence/EvidenceList.vue` | modified | Scoped `@media (width <= 640px)` grid rule only, plus its explanatory comment. Template, script, props, `data-test` hooks, and handlers untouched. |
| `tests/Frontend/Component/EvidenceListMobileLayout.spec.ts` | added | Committed declaration guard: mobile grid, full-width direction-aware action row, shrinkable body, desktop flex row, template order, and breakpoint parity with `EvidencePreview.vue`. |
| `specs/005-purchaser-ui-redesign/tasks.md` | modified | New Phase 16 / `T078` record, the Phase 14 follow-up status update, the Phase 16 dependency bullet, and the file-collision entries. |
| `.specify/bugs/mobile-evidence-filename-wrap/fix.md` | added | This report. |
| `.specify/bugs/mobile-evidence-filename-wrap/verify-post-fix.mjs` | added | Read-only Chrome runner: 390×844 and 1440×900, both hosts, `en` + `fa`; writes `verify-post-fix.json` and screenshots. |
| `.specify/bugs/mobile-evidence-filename-wrap/verify-lifecycle-chip.mjs` | added | Read-only T076 chip check at 390px in both directions; writes `verify-lifecycle-chip.json`. |
| `.specify/bugs/mobile-evidence-filename-wrap/probe-lifecycle-fixture.mjs`, `probe-inactive-evidence.mjs` | added | Read-only fixture probes used to locate a non-draft evidence row and to confirm no inactive-row fixture exists. |

Supporting measurements: `verify-post-fix.json`, `verify-lifecycle-chip.json`, and
`artifacts/visual-review/t078/iteration-1/` (Git-ignored per `.gitignore:66`).

## Diff Highlights

```css
@media (width <= 640px) {
	.evidence-item {
		display: grid;
		grid-template-columns: auto minmax(0, 1fr);
		align-items: start;
	}

	.evidence-item__actions {
		grid-column: 1 / -1;
		justify-content: flex-end;
	}
}
```

DOM order stays icon → body → actions, so grid auto-placement puts the icon and body on row 1 and
the actions on row 2. `minmax(0, 1fr)` keeps the body's shrinking track; `align-items: start` aligns
the icon with the first name line; `justify-content: flex-end` is direction-aware. Above 640px the
existing `display: flex` row is unchanged.

## Tests Added or Updated

- `tests/Frontend/Component/EvidenceListMobileLayout.spec.ts::reflows the evidence row to a grid at the evidence mobile breakpoint` — pins `display: grid`, `auto minmax(0, 1fr)`, and `align-items: start` inside the 640px query.
- `...::gives the action cluster a full-width, direction-aware second row` — pins `grid-column: 1 / -1` and `justify-content: flex-end`.
- `...::keeps the body shrinkable and long names wrapping instead of scrolling the page` — pins `min-width: 0` and `overflow-wrap: anywhere`, the pair that keeps very long names inside the column.
- `...::leaves the desktop row as the unchanged single-line flex layout` — pins the base `display: flex` / `align-items: center` so the mobile rule cannot leak upward.
- `...::keeps the template in icon -> body -> actions order for grid auto-placement` — pins the order the auto-placement depends on.
- `...::uses the same breakpoint as the sibling evidence preview surface` — prevents the two evidence surfaces drifting to different breakpoints.

Existing guards re-run unchanged: `EvidenceStatusPresentation.spec.ts` (T076),
`RtlAccessibility.spec.ts`, and `fr071NarrowViewport.spec.ts`.

## Local Verification

- `npx vitest run tests/Frontend/Component/EvidenceListMobileLayout.spec.ts --reporter=verbose` → **6/6 passed**.
- Mutation check: removed the `@media` block, re-ran the guard → **2/6 failed** (the two media-query assertions); restored the file from a byte-for-byte backup and verified the SHA-256 matched the pre-mutation file.
- `npx vitest run tests/Frontend/Component/EvidenceListMobileLayout.spec.ts tests/Frontend/Component/EvidenceStatusPresentation.spec.ts tests/Frontend/Component/RtlAccessibility.spec.ts tests/Frontend/fr071NarrowViewport.spec.ts` → **26/27 passed**. The single failure is **pre-existing and outside this fix**: `fr071NarrowViewport.spec.ts` reports `src/components/evidence/EvidenceUploader.vue: overflow-x: auto;`, a declaration that exists only in the uncommitted `T077` uploader work (not at `HEAD`) and lives in a file `T078` is explicitly forbidden to modify. It is unrelated to `EvidenceList.vue`.
- `npx stylelint 'src/components/evidence/EvidenceList.vue'` → exit 0.
- `npx eslint tests/Frontend/Component/EvidenceListMobileLayout.spec.ts src/components/evidence/EvidenceList.vue` → 0 errors, 1 pre-existing warning (`vue/no-required-prop-with-default` on `isDraft`).
- `npx vue-tsc --noEmit` → clean.
- `npm run build` → succeeded; the built CSS contains the media rule (`css/transactionsApi-*.chunk.css`).

Manual Chrome verification (`channel: 'chrome'`, Chrome **156.0.8078.47**), read-only against the
T060 fixture and a non-draft T076 receipt, account language restored to `en` and verified:

| Viewport | Host | Dir | Body width | Name lines | Row height | Document | Buttons outside |
|---|---|---|---|---|---|---|---|
| 390×844 | Editing form (`en`/`fa`) | ltr/rtl | **273.5px** | **1** | 163.8px | 390/390 | no |
| 390×844 | Detail view (`en`/`fa`) | ltr/rtl | **255px** | **1** | 163.8px | 390/390 | no |
| 1440×900 | Editing form (`en`/`fa`) | ltr/rtl | 579.5px | 1 | 74.3px | 1440/1440 | no |
| 1440×900 | Detail view (`en`/`fa`) | ltr/rtl | 735px | 1 | 74.3px | 1440/1440 | no |

Pre-fix the same fixture measured 39.5px / 21px bodies and 5 / 9 name lines. Post-fix the actions
occupy a 322.5px (edit) / 304px (detail) second row; in RTL they sit on the left edge and the icon
on the right, which is the correct physical placement. The action buttons remain 68×43px.

T076 chip boundary, measured on a real non-draft receipt at 390px: the Active chip is 79px (`en`) /
65.9px (`fa`) and the action row stays one 43px line with no wrap. A browser-only synthetic worst
case (the download button cloned to make chip + three actions) wraps inside the second row in `en`
(93.5px) and fits on one line in `fa` (43px); neither produces row or viewport overflow. No
inactive-row fixture exists in the lab, so that variant is covered by the T076 component guard
(chip and deactivation text still render) and by the fact that every inactive row hides preview,
download, and remove — its action cluster is strictly narrower.

Screenshots reviewed: `mobile/01-edit-evidence-en-390.png`, `mobile/02-detail-evidence-fa-390.png`,
`mobile/03-lifecycle-chip-{en,fa}-390.png`, `desktop/02-detail-evidence-en-1440.png`.

## Deviations from Assessment

- **Row-height expectation.** The assessment estimated ~130–150px after the fix; the measured row is
  **163.8px** because the metadata line wraps to two lines at the new 255–273.5px body width. The
  filename itself is one line and the row is far below the 200px browser-guard ceiling proposed in
  the assessment. No further change was made.
- **Browser guard shape.** The assessment described a browser guard as the only layer that can
  measure layout. The committed CI guard pins the declarations and structure; the geometry
  assertions are delivered as a read-only Chrome runner plus recorded JSON/screenshots, matching the
  repository's T077 evidence pattern. No new Playwright project or lab-dependent test was added, so
  `npm run check` remains runnable without the lab.
- **No other deviation.** The CSS remediation is the assessment's preferred fix, applied in the
  named file only.

## Follow-ups

- Run `/speckit.bug.test slug=mobile-evidence-filename-wrap` for the formal resolution report; `T078` stays `READY_FOR_OWNER_APPROVAL` until then.
- Owner acceptance of the mobile presentation, and refreshed list-area evidence for `T075` if its owner review relies on the old T077 screenshots. `T075` itself is unchanged.
- The pre-existing `fr071NarrowViewport.spec.ts` failure caused by `EvidenceUploader.vue`'s `overflow-x: auto` needs its own decision; it is outside `T078` and the approved `T077` uploader must not be modified here.
- The separate mobile preview-header defect remains a tracked follow-up, explicitly outside this bug.
- If an inactive-evidence fixture is seeded later, re-check the chip + deactivation row at 390px; today no such row exists to measure.
