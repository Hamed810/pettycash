# Bug Assessment: Mobile evidence-list filenames collapse to a character column

- **Slug**: `mobile-evidence-filename-wrap`
- **Created**: 2026-10-25T21:06:30Z
- **Source**: pasted text
- **Verdict**: **valid** (reproduced in official Google Chrome at 390px, both hosts, Persian RTL and English LTR)
- **Severity**: **medium**

## Report (verbatim or summarized)

> At a 390px mobile viewport, filenames in the existing evidence list are compressed into extremely
> narrow columns and wrap character by character. This makes existing attachments difficult to
> identify and their actions difficult to use.
>
> Expected: filenames remain readable on mobile; long filenames truncate or wrap at sensible
> boundaries; full filenames remain accessible; evidence actions remain visible and usable; no
> horizontal page overflow; Persian RTL and English LTR remain supported.

The report is pasted text (no URL). The protected behavior named by the owner — T074 frozen pages
and tabs, T075 editing, T076 lifecycle presentation, T077 compact uploader, evidence
preview/download, and all backend behavior — is out of scope for the fix and is treated as a
regression boundary below.

## Symptom

At a 390px viewport the shared evidence row is a single non-wrapping flex line. Its action cluster
keeps its full intrinsic width (219px), so the filename body is squeezed to **21px** in the
transaction detail view and **39.5px** in the transaction editing form. A 19-character filename
(`t060-receipt-01.txt`) breaks into 5 lines in the editor and 9 lines in the detail view, and the
metadata column breaks the same way. The row becomes 392–776px tall — one evidence row nearly fills
the 844px viewport — while the page itself has **no** horizontal overflow.

## Reproduction

Environment: official Google Chrome `156.0.8078.47` through Playwright `{ channel: 'chrome' }`,
viewport `390 × 844`, official lab at `http://localhost:8081`, T060 fixture draft
`T060 receipt fixture 30 · transaction 01` with its single receipt `t060-receipt-01.txt`.
Read-only: no upload, removal, edit, save, or fixture reseed. Account language was `en` before and
after; `fa` was set only for the RTL pass and restored (verified).

1. Sign in as the T060 fixture owner.
2. **Editing host** — open `#/sheets/<sheet-30>`, activate the row-action for
   `T060 receipt fixture 30 · transaction 01`, and scroll to `[data-test="edit-evidence-list"]`.
   **Detail host** — open `#/transactions/<draft-id>` and scroll to the Evidence card.
3. Inspect the evidence row. Measured results (identical widths in `en` and `fa`):

| Host | List width | Icon | Actions (fixed) | Name/body width | Name lines | Row height | Page overflow |
|---|---|---|---|---|---|---|---|
| Editing form (`fa` and `en`) | 345px | 34px | 219px | **39.5px** | 5 | 392px (`fa`) / 440px (`en`) | none (390/390) |
| Detail view (`fa` and `en`) | 326.5px | 34px | 219px | **21px** | 9 | 704px (`fa`) / 776px (`en`) | none (390/390) |

- Each action button renders **68px** wide; three buttons plus two 0.5rem gaps produce the 219px
  cluster.
- All three buttons stay inside the row and the viewport, so they remain operable — but they sit at
  the vertical centre of a 700–776px row, visually detached from the filename.
- The metadata line (`text/plain · 42 B · <date>`) is squeezed into the same column and wraps to
  246–534px of height.
- The existing T077 mobile capture independently shows the same defect below the uploader:
  `artifacts/visual-review/t077/iteration-1/mobile/02-compact-uploader-long-filename-390.png`.

Reproduction artifacts: `repro-390.mjs` (runner) and `repro-390.json` (raw measurements) in this
directory.

## Suspected Code Paths

- `src/components/evidence/EvidenceList.vue:218` — `.evidence-item` is
  `display: flex; align-items: center; gap: 1rem` with the default `flex-wrap: nowrap`. The icon,
  body, and action cluster must share one 345px/326.5px line.
- `src/components/evidence/EvidenceList.vue:261` — `.evidence-item__actions` is
  `display: flex; flex-shrink: 0; flex-wrap: wrap`. `flex-shrink: 0` keeps its **max-content** width
  (219px), so its own `flex-wrap` never engages; it consumes the row instead of yielding to the
  filename.
- `src/components/evidence/EvidenceList.vue:231` — `.evidence-item__body` is `flex: 1; min-width: 0`,
  which correctly permits shrinking. That is why the body, not the row, absorbs the deficit.
- `src/components/evidence/EvidenceList.vue:239` — `.evidence-item__name` declares
  `overflow-wrap: anywhere`, so the residual 21–39.5px is filled by breaking the filename at
  arbitrary characters instead of overflowing.
- `src/views/transactions/TransactionFormView.vue:862` — the editing host supplies 345px of width
  inside the transaction surface; it is not the cause.
- `src/views/transactions/TransactionDetailView.vue:504,522` — the detail card supplies 326.5px; it
  is not the cause either. Both hosts reproduce, which localises the defect to the shared component.

The layout declarations **already exist at HEAD** (`git show HEAD:src/components/evidence/EvidenceList.vue`).
The uncommitted diff to this file adds T075's preview emit and T076's `neverSubmitted` chip
condition only; it contains no CSS layout change. This is a long-standing defect, not a regression
from T074–T077. The ledger already records it as the tracked narrow-screen follow-up
(`specs/005-purchaser-ui-redesign/tasks.md:335`), explicitly "not fixed, not accepted by the owner,
and not part of `T076`", requiring its own separately authorized task.

## Root Cause Hypothesis

**Confidence: high.** The row cannot reflow. `.evidence-item__actions` is incompressible
(`flex-shrink: 0`) and sizes to the max-content width of three 68px `NcButton`s (219px). Together
with the 34px icon, two 1rem gaps, and 0.75rem padding, that consumes 307px of the 345px editing
row and 307px of the 326.5px detail row, leaving **39.5px** and **21px** for the body. Because the
body is allowed to shrink (`min-width: 0`) and the name is allowed to break anywhere
(`overflow-wrap: anywhere`), the filename is rendered in a 21–39.5px column instead of overflowing.
The defect is purely presentational: no transport, permission, persistence, or audit path is
involved, and no horizontal overflow occurs.

## Proposed Remediation

**Preferred**: make the row reflow at the mobile breakpoint already used by the component's
sibling, `EvidencePreview.vue` (`@media (width <= 640px)`), by switching the row to a two-column
grid with the actions on their own second row. CSS-only change in `EvidenceList.vue`'s scoped
style block:

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

DOM order is unchanged (icon, body, actions), so auto-placement puts the icon and body on row 1 and
the full-width action cluster on row 2. Expected result at 390px: the body becomes ~272px in the
editor and ~254px in the detail view; the 19-character fixture fits on one line; the row becomes
~130–150px tall; the three buttons remain 68×43px inside the viewport. Desktop (>640px) keeps the
current single-row flex layout untouched. `justify-content: flex-end` is direction-aware, so the
actions stay on the same physical side in RTL and LTR, and no physical `left`/`right` property is
introduced.

The full filename is never truncated by this fix, so "full filenames remain accessible" is satisfied
without a new hover/toggle affordance; long names wrap within ~250–272px instead of 21–39.5px.

**Alternatives**:
- *Ellipsis plus the T077 full-name toggle*: visually tidier for very long names, but adds a new
  interaction, new strings, and a second pattern for the same problem. Not minimal; only if the
  owner prefers truncation over wrapping.
- *Let the actions shrink and wrap*: removing `flex-shrink: 0` would fold the three 68px buttons
  into a 2–3 row stack ~140px wide, leaving the body ~100px. Still 8–12 characters per line and it
  worsens the action layout. Rejected.
- *Container query on `.evidence-list`*: would trigger on the component's own width rather than the
  viewport, which is more precise across hosts. It introduces size containment the repository does
  not currently use; defer unless the 640px viewport rule proves insufficient.

**Files likely to change**:
- `src/components/evidence/EvidenceList.vue` — scoped CSS only (the media query above). No template,
  script, prop, or API change.
- `specs/005-purchaser-ui-redesign/tasks.md` — a new, separately authorized ledger entry for this
  bug fix, as the existing follow-up note requires. Do not fold it into T074–T077.
- `artifacts/visual-review/<new-task>/iteration-1/` — the Chrome evidence runner, `record.json`, and
  screenshots (Git-ignored per Constitution VIII). No other production file is expected to change.

**Tests to add or update**:
- Browser guard (the only layer that can measure layout): official Chrome at 390×844 in `fa` and
  `en`, in **both** hosts — assert body width ≥ 200px, name line count ≤ 2 for the 19-character
  fixture, row height ≤ 200px, `document.scrollWidth === clientWidth`, all three buttons inside the
  row and viewport, and the DOM order icon → body → actions.
- Desktop regression at 1440×900: the row must remain a single flex line with a wide body (≥ 400px)
  and the current height; the media query must not leak upward.
- `tests/Frontend/Component/EvidenceStatusPresentation.spec.ts` (T076) must stay green: chip and
  inactive-history text are unchanged; only their position at ≤640px moves.
- `tests/Frontend/Component/RtlAccessibility.spec.ts` and `tests/Frontend/fr071NarrowViewport.spec.ts`
  must stay green (no physical direction properties, no horizontal-scroll declaration).
- No jsdom test can prove the geometry; a declaration-only assertion is not a substitute for the
  Chrome measurement.

## Risks & Considerations

- **T074 freeze authorization.** The evidence list renders inside the frozen workspace and detail
  surfaces. The ledger explicitly requires a separate authorized task for this follow-up. The fix
  must be recorded as its own task; it must not be presented as part of T074, T075, T076, or T077.
- **T076 regression.** On mobile, the Active/Inactive chip and the deactivation timestamp move to
  the second row. Their presence, text, and color semantics are unchanged; the existing component
  guard asserts text, not position, so it should remain green. Verify a submitted transaction with
  an inactive row, where the chip makes the actions cluster even wider today.
- **T075/T077 evidence staleness.** T077's approval covers the uploader only, and T077 screenshots
  supersede T075's only for the uploader. This fix changes the evidence list *below* the uploader, so
  the list area of the T075/T077 captures will differ. T075 remains `READY_FOR_OWNER_APPROVAL`; its
  owner review should use refreshed list evidence, and the fix must not be treated as T075 approval.
- **T077 uploader untouched.** No change to `EvidenceUploader.vue`, its `compact` prop, or the form
  host's `compact` activation.
- **Behavior preserved.** The template, `data-test` hooks, preview/download/remove handlers,
  permissions, persistence, and audit behavior are unchanged; the fix is scoped CSS.
- **Very long filenames** still wrap over several lines at the new width. That is the chosen
  trade-off (full name always visible) rather than truncation; the owner may prefer an ellipsis plus
  an inspection affordance instead.
- **Touch targets** stay 68×43px (height is 1px below 44px today and is not worsened by this fix); if
  the owner wants WCAG 2.5.8 target size, that is a separate change to `NcButton` usage.
- **RTL/LTR**: widths were identical in both directions; the grid change uses `flex-end` and
  `minmax(0, 1fr)`, so direction cannot reintroduce the collapse.
- No API, migration, performance, security, or observability risk.

## Relevant Spec Kit Requirements

- **005 `C-07.4`** — no horizontal scroll at 390px, one dialog. Already satisfied (390/390) and must
  remain satisfied.
- **005 `C-19` / `T074`** — frozen sheet list and workspace/detail shell; the element-scoped fix
  requires explicit authorization and must not alter page layout outside the evidence row.
- **005 `FR-005-047` / `C-21` / `T076`** — lifecycle presentation: active/inactive badge and
  deactivation history must survive the reflow.
- **005 `FR-005-048` / `C-22` / `T077`** — the compact uploader and its approval are untouched; the
  fix must not cross into `EvidenceUploader.vue` or `TransactionFormView.vue`'s compact activation.
- **001 `FR-058`** — pre-submission removal must stay visible and operable.
- **001 `FR-059`** — inactive evidence history must stay visible with its deactivation time.
- **001 `FR-060`** — authorized preview/download must stay available and usable.
- **001 `FR-071`** — Persian RTL at 390px with evidence visible without horizontal scroll.
- **005 `tasks.md:335`** — the tracked narrow-screen evidence-layout follow-up: this is that defect;
  it requires its own authorized task and is not covered by T074–T077.
- **Constitution v1.4.0**: II (preserve contracts/existing behavior), V (risk-based focused
  verification; official Chrome desktop + 390px including Persian RTL), VII (explicit owner gate),
  VIII (visual evidence under `artifacts/visual-review/`).

## Open Questions

- Truncation vs. wrapping: the assessment assumes **wrapping with the full name always visible**.
  If the owner prefers ellipsis plus an inspect affordance, the fix grows a new interaction and new
  strings. `[NEEDS CLARIFICATION: owner preference]`
- Breakpoint: `640px` matches `EvidencePreview.vue` and the workspace's mobile rule. Confirm that the
  same breakpoint is acceptable for both the editing and detail hosts.
- Authorization: the fix needs a new ledger task under the bug workflow. Confirm the task ID/record
  to use so `T074`–`T077` statuses remain untouched.
- The separate mobile preview-header defect is explicitly outside this bug and is not assessed here.
