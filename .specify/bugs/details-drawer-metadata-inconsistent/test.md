# Bug Verification: Full-details drawer sheet metadata inconsistent with parent modal

- **Slug**: `details-drawer-metadata-inconsistent`
- **Tested**: 2026-11-11
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: **verified**

## Summary

The drawer now shows the same sheet identity as the senior modal that hosts it. In official Chrome at
390×844, after opening the first sheet and then its first transaction's **Full details** drawer, the
parent header and the drawer agree in both languages:

| | EN (LTR) | FA (RTL) |
|---|---|---|
| parent title | `B4 quick review mw3x4qfm` | `B4 quick review mw3x4qfm` |
| parent owner | `PettyCash E2E` | `PettyCash E2E` |
| drawer sheet field | contains the title + the `630a6e00-…` reference | same |
| drawer purchaser field | `PettyCash E2E (Purchaser)` | `PettyCash E2E (خریدار)` |
| drawer meta header | `B4 quick review mw3x4qfm` | `B4 quick review mw3x4qfm` |

The `Sheet title unavailable` / `Purchaser unavailable` fallbacks no longer appear in either
direction. The fix is the two-part minimal change from the assessment: widen the drawer's `sheet`
prop to `ManagerSheetSummary | SeniorSheetSummary | null`, and pass
`:sheet="review?.sheet ?? null"` from `SeniorSheetModal`.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Live, EN (390×844) | `node .tmp-repro/bugbatch-verify.mjs` → `artifacts/visual-review/bugbatch-001/bugtest-report.json` | **pass** | parent `{title:"B4 quick review mw3x4qfm", owner:"PettyCash E2E"}`; drawer sheet contains title, purchaser contains owner, meta header equals title; no `*Unavailable` fallback. |
| Live, FA (390×844, RTL) | same runner | **pass** | same parent header; drawer purchaser `PettyCash E2E (خریدار)`; localized `unavailable` sentinel absent; meta header matches. |
| Component: Senior shape | `npx vitest run tests/Frontend/Component/TransactionDetailsDrawer.spec.ts` | **pass** | 13/13. New case mounts a `SeniorSheetSummary`-shaped sheet and asserts sheet/purchaser/meta render it, not the fallbacks. Existing `ManagerSheetSummary` cases and the honest-empty fallback case stay green. |
| Component: senior flow | `npx vitest run tests/Frontend/Component/SeniorSheetModal.spec.ts` | **pass** | 23/23. New case seeds the senior review, opens the drawer from a card, and asserts the drawer fields match the modal header title/owner. |
| Type relationship | `npm run typecheck` | **pass** | `vue-tsc --noEmit`, exit 0. The union accepts the `SeniorSheetSummary` value at the `SeniorSheetModal` call site while the Cost Center call site still passes `ManagerSheetSummary`. |
| Script lint | `npx eslint` on the two components + two specs | **pass** | Exit 0, no output. |
| Style lint | `npx stylelint` on the touched `.vue` files | **pass** | Exit 0, no output. |
| Production build | `npm run build` | **pass** | Succeeded on the batch. |
| Console / page errors | `bugtest-report.json` (EN + FA) | **pass** | None in either pass. |
| Cost Center compatibility | Type + existing drawer suite | **pass** | `CostCenterQueueView` still passes `ManagerSheetSummary`; the drawer never reads `sheet.status`. No Cost Center source change. |
| Scope | `git status --porcelain` | **pass** | Prop type/import/doc + one template binding + two test files; no API, persistence, or business-rule change. |

## Output Excerpts

```text
EN-390-bug05-drawer-metadata
  parent = {title:"B4 quick review mw3x4qfm", owner:"PettyCash E2E"}
  drawer = {
    sheetField:     "B4 quick review mw3x4qfm630a6e00-f7ca-43d7-9bb4-002ce19bfc6b",
    purchaserField: "PettyCash E2E (Purchaser)",
    metaSheet:      "B4 quick review mw3x4qfm"
  }

FA-390-bug05-drawer-metadata
  parent = {title:"B4 quick review mw3x4qfm", owner:"PettyCash E2E"}
  drawer = {
    sheetField:     "B4 quick review mw3x4qfm630a6e00-f7ca-43d7-9bb4-002ce19bfc6b",
    purchaserField: "PettyCash E2E (خریدار)",
    metaSheet:      "B4 quick review mw3x4qfm"
  }

BUG-05 en  PASS
BUG-05 fa  PASS
```

Applied change:

```ts
// TransactionDetailsDrawer.vue — only title/ownerDisplayName are consumed
sheet: ManagerSheetSummary | SeniorSheetSummary | null
```

```html
<!-- SeniorSheetModal.vue -->
<TransactionDetailsDrawer ... :sheet="review?.sheet ?? null" ... />
```

## Residual Risks

- **Adjacent title + reference text.** The drawer's sheet field is `title` followed by a
  `<bdi>` reference with no separating text node, and `.details-drawer__reference` sets only
  monospace/isolate (no margin). `textContent` therefore concatenates
  (`…mw3x4qfm630a6e00-…`) and the two can render visually adjacent. This predates the fix (the
  unavailable label had the same adjacency) and is not the reported defect; it is recorded as a
  possible polish item, not a regression.
- **Timing-sensitive live check.** The parent header and drawer fields are read in one `page.evaluate`
  after the drawer footer is visible, so they reflect the same loaded review. The earlier standalone
  parent-header read raced the async senior read; that was a probe artifact, not an app defect, and is
  fixed in the runner. The component tests independently pin the rendered equality.
- **Cost Center live pass not repeated.** The union leaves the manager path byte-identical and the
  existing drawer suite covers it, but only the Senior host was exercised live in this batch.
- **Reactive refresh.** If the senior review reloads while the drawer is open, the template binding
  reads the same reactive `review` and updates; not exercised live.
- **T037 untouched.** No E2E suite was run.

## Recommendation

**Close BUG-05 — verified end-to-end.** The drawer and its host modal now present one sheet identity in
both languages, and the widened prop keeps the Cost Center host type-safe and unchanged.
