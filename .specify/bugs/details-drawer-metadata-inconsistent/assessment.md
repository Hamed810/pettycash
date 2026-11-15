# Bug Assessment: Full-details drawer sheet metadata inconsistent with parent modal

- **Slug**: `details-drawer-metadata-inconsistent`
- **Created**: 2026-11-10T21:30:00Z
- **Source**: Feature 007 owner report, defect **BUG-05**
- **Verdict**: **valid** (reproduced in official Google Chrome at 390×844, English LTR and Persian RTL)
- **Severity**: **medium**

## Report (summarized)

> The full-details drawer (opened from a senior sheet modal card) shows "Sheet title unavailable"
> and "Purchaser unavailable" for the sheet metadata, while the parent senior sheet modal above it
> shows the real sheet title (`B4 quick review mw3x4qfm`) and owner (`PettyCash E2E`). Two
> presentations of the same transaction disagree.

Expected: the drawer's Sheet title and Purchased-by fields show the same sheet header the parent
modal shows when the drawer is embedded in the senior sheet review.

## Symptom

Measured on the live lab at 390×844 (EN pass shown; FA identical):

| field | parent senior modal | full-details drawer |
|---|---|---|
| sheet title | `B4 quick review mw3x4qfm` | `Sheet title unavailable` |
| sheet owner | `PettyCash E2E` | `Purchaser unavailable` |
| sheet reference | `630a6e00-f7ca-43d7-9bb4-002ce19bfc6b` (`dir=ltr`) | `630a6e00-…` (shown) |
| transaction id | — | `2d671926-…` |

Drawer markup for the sheet field:
`<span class="details-drawer__value--unavailable">Sheet title unavailable</span><bdi class="details-drawer__reference">630a6e00-…</bdi>`
and the purchaser field renders `Purchaser unavailable`. Evidence: `probe-report.json`
(EN-390-parent-metadata, EN-390-drawer-metadata (BUG-05)).

## Reproduction

1. Sign in as `PETTYCASH_E2E_USER`; open `#/reviews/senior` at 390×844.
2. Open the first sheet; read the modal header (title/owner).
3. Tap the first card's **Full details**; read the drawer's Sheet and Purchased-by fields.

Reproduction artifacts: `.tmp-repro/bugbatch-probe.mjs`, `probe-report.json`.

## Suspected Code Paths

- `src/components/review/SeniorSheetModal.vue:266` — the drawer is rendered with `:sheet="null"`, so
  every sheet-derived field falls back to its unavailable label.
- `src/components/review/TransactionDetailsDrawer.vue:32-34,126-129,136-137` — renders
  `sheet?.title` / `sheet?.ownerDisplayName` with an unavailable fallback
  (`sheetTitleUnavailable`, `purchaserUnavailable`) when `sheet` is `null`.
- `src/components/review/TransactionDetailsDrawer.vue:331` — the `sheet` prop is typed
  `ManagerSheetSummary | null`, but the senior modal only holds a `SeniorSheetSummary`
  (`src/types/api.ts:293`), which lacks `status` and adds senior review fields — hence the senior
  modal hand-passes `null` rather than a value that wouldn't type-check.

## Root Cause Hypothesis

**Confidence: high.** The drawer is a shared component whose `sheet` prop is typed for the Cost
Center manager read (`ManagerSheetSummary`). The senior sheet read returns `SeniorSheetSummary`, a
structurally different type (no `status`, extra `allTransactionsCostCenterDecided`/
`reviewStatus`), so `SeniorSheetModal` passes a literal `null` (line 266). The drawer then has no
sheet header to render and falls back to the two unavailable labels. The senior modal itself renders
the same information directly from `review.value?.sheet` (title `SeniorSheetModal.vue:556-559`, owner
`561-564`), so the two surfaces disagree. The drawer only ever reads `sheet.title` and
`sheet.ownerDisplayName` from the prop — both present on `SeniorSheetSummary` — so a type-wide union
fix is safe.

## Proposed Remediation

**Preferred**: widen the drawer's `sheet` prop to accept the Senior sheet shape, then pass it from
the senior modal.

1. In `src/components/review/TransactionDetailsDrawer.vue:331`, change the prop to
   `sheet: ManagerSheetSummary | SeniorSheetSummary | null` (import `SeniorSheetSummary`), and note
   that only `title`/`ownerDisplayName` are consumed.
2. In `src/components/review/SeniorSheetModal.vue:266`, pass `:sheet="review?.sheet ?? null"` instead
   of `:sheet="null"`, so the drawer shows the same title/owner as the parent header.

The Cost Center host (`CostCenterQueueView.vue:468`) still passes a `ManagerSheetSummary`; the union
leaves that path byte-for-byte identical. TypeScript coverage: `SeniorSheetSummary` is a strict
superset minus `status`; a `ManagerSheetSummary | SeniorSheetSummary` union keeps every accessed key
(`title`, `ownerDisplayName`) common to both arms.

**Alternatives**:
- Pass a structurally minimal object (`{ title, ownerDisplayName }`) and relax the prop to a
  `Pick<…>` type. Wider interface change, more churn; union is smaller.
- Keep `:sheet="null"` and duplicate the resolver logic inside the drawer — rejected (duplication and
  couples drawer to senior internals).

**Files likely to change**:
- `src/components/review/TransactionDetailsDrawer.vue` — prop type + import + doc comment.
- `src/components/review/SeniorSheetModal.vue` — one template binding.
- `tests/Frontend/Component/TransactionDetailsDrawer.spec.ts` — a case mounting a
  `SeniorSheetSummary`-shaped sheet to pin the widened prop.
- `tests/Frontend/Component/SeniorSheetModal.spec.ts` — assert the drawer's sheet field now renders
  the review sheet's title inside the senior modal flow.

**Tests to add or update**:
- Component: mount the drawer with a `SeniorSheetSummary`-shaped object and assert
  `drawer-field-sheet`/`drawer-field-purchaser` show its title/owner (not the unavailable labels);
  existing `ManagerSheetSummary` cases must stay green.
- Component (senior flow): open the drawer from the senior modal and assert its sheet field text
  equals the modal header title.
- Live Chrome guard: at 390×844 EN+FA, after opening the drawer from the senior modal, assert
  `details-drawer-sheet-title`/`drawer-field-sheet`/`drawer-field-purchaser` match the parent modal's
  title/owner text.

## Risks & Considerations

- **Type safety**: TS will now accept `SeniorSheetSummary`; ensure no other consumer relied on the
  absent `status` via the drawer (grep confirms the drawer never reads `sheet.status`).
- **Cost Center regression**: `CostCenterQueueView.vue:468` passes `ManagerSheetSummary`; the union
  keeps that call valid, and the drawer's rendering for managers is unchanged.
- **Read-state independence**: the drawer still gates fields on `readState === 'loaded'` and
  `transaction !== null`; the fix does not alter that logic.
- No API/persistence/business-rule change.

## Relevant Spec Kit Requirements

- Feature 007 review presentation acceptance: the drawer embedded in the senior sheet must present
  the same sheet identity as the modal that hosts it (single source of truth `review.sheet`).

## Open Questions

- None blocking.
