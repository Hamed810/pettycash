# Bug Verification: Full-details drawer action controls overflow the 390px viewport

- **Slug**: `details-drawer-actions-overflow-390`
- **Tested**: 2026-11-11
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: **verified**

## Summary

The drawer footer no longer wrap-divides into overflow columns. In official Chrome at 390×844, with
the fix built and served, the footer is `flex-direction: column` **and** `flex-wrap: nowrap`, its
`scrollWidth` equals `clientWidth` (**390 / 390**), and all four controls (Reject / Return / Approve /
Close) land at **x=14 → right edge 376**, **362px wide**, **44px tall** — fully inside the viewport and
the 14px inline padding — in **both** English LTR and Persian RTL. The pre-fix injection probe
(`bug02-fix-injection-report.json`) had already shown the same declaration collapsing the overflow
(EN 559→362, FA 543→390 scrollWidth); this run confirms it end-to-end in the built app.

No template, script, API, persistence, or business-rule change was made. The desktop row rule is
untouched because the declaration lives inside the existing `@media (width <= 640px)` block.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Live, EN (390×844) | `node .tmp-repro/bugbatch-verify.mjs` → `artifacts/visual-review/bugbatch-001/bugtest-report.json` | **pass** | footer `scrollWidth=390`, `clientWidth=390`, `flexWrap=nowrap`, `flexDirection=column`. All 4 buttons `x=14`, `right=376`, `width=362`, `height=44`. |
| Live, FA (390×844, RTL) | same runner | **pass** | footer `390/390`, `nowrap`, `column`; all 4 buttons identical to LTR (`x=14`, `right=376`, `362×44`). The fix is direction-neutral. |
| No-regression: desktop rule | Source read of the scoped media block | **pass (by construction)** | The change is inside `@media (width <= 640px)`; ≥641px keeps the original row/wrap footer. Not re-measured at desktop in this run. |
| Pre-fix control | `artifacts/visual-review/bugbatch-001/bug02-fix-injection-report.json` | **reproduced** | Runtime `nowrap` injection before the source change: EN `scrollWidth` 559→362, FA 543→390; every button moved to x=14→376; `buttonBeyondWindow` emptied in both directions. |
| Targeted component guards | `npx vitest run TransactionDetailsDrawer.spec.ts SeniorSheetModal.spec.ts QuickDecisionModal.spec.ts QuickDecisionModalBidi.spec.ts` | **pass** | 4 files / 51 tests (drawer 13, senior modal 23, decision modal 13, bidi 2). |
| Type-check | `npm run typecheck` | **pass** | `vue-tsc --noEmit`, exit 0. |
| Script lint | `npx eslint` on the three touched components + three specs | **pass** | Exit 0, no output. |
| Style lint | `npx stylelint` on the three touched `.vue` files | **pass** | Exit 0, no output. |
| Production build | `npm run build` | **pass** | Succeeded on the batch (the verified bundle is the built one). |
| Console / page errors | `bugtest-report.json` (EN + FA) | **pass** | No console or page errors during either pass. |
| Scope | `git status --porcelain` | **pass** | One CSS declaration in `TransactionDetailsDrawer.vue`; no template/script change. Existing drawer tests kept green. |

## Output Excerpts

```text
EN-390-bug02-drawer-actions
  footerScrollWidth=390 footerClientWidth=390
  footerFlexWrap=nowrap footerFlexDirection=column
  viewport=390
  drawer-decide-reject   x=14 right=376 width=362 height=44
  drawer-decide-return   x=14 right=376 width=362 height=44
  drawer-decide-approve  x=14 right=376 width=362 height=44
  drawer-close-btn       x=14 right=376 width=362 height=44

FA-390-bug02-drawer-actions
  footerScrollWidth=390 footerClientWidth=390
  footerFlexWrap=nowrap footerFlexDirection=column
  viewport=390
  drawer-decide-reject   x=14 right=376 width=362 height=44
  drawer-decide-return   x=14 right=376 width=362 height=44
  drawer-decide-approve  x=14 right=376 width=362 height=44
  drawer-close-btn       x=14 right=376 width=362 height=44
```

Source change (scoped mobile footer rule):

```css
@media (width <= 640px) {
	.details-drawer__footer {
		flex-flow: column nowrap;   /* BUG-02: no column-wrap overflow past the 390px viewport */
		align-items: stretch;
		padding: 12px 14px;
	}
}
```

## Residual Risks

- **One shared component, two hosts.** The drawer is also opened from the Cost Center queue. The
  declaration is global within the drawer, so the Cost Center host receives the same healing; only
  the Senior host was exercised live in this batch. The Cost Center path is structurally identical
  (same component, same footer markup) and the existing component suite covers its rendering.
- **CSS geometry is not unit-testable in jsdom.** The regression anchor is the live Chrome measurement
  plus the retained `TransactionDetailsDrawer.spec.ts`; a future refactor that reintroduces
  `flex-wrap: wrap` on the mobile footer would not fail a jsdom test. Keep the live drawer probe in the
  feature's manual/visual gate.
- **Viewport edges.** Verified at exactly 390px. Widths between 391 and 640px were not swept; the
  column layout is width-agnostic, but only 390 is evidenced.
- **Desktop not re-measured.** Claimed by media-query scoping, not by a 1440×900 run in this batch.
- **T037 untouched.** No E2E suite was run.

## Recommendation

**Close BUG-02 — verified end-to-end in both directions.** The footer no longer overflows, every
action is reachable and 44px tall, and the shared drawer heals the Cost Center host by construction.
