# Bug Assessment: Senior sheet modal close control truncated at 390px

- **Slug**: `senior-sheet-modal-close-truncated`
- **Created**: 2026-11-10T21:30:00Z
- **Source**: Feature 007 owner report, defect **BUG-03**
- **Verdict**: **not reproduced** (already correct in the current build at 390×844, EN and FA)
- **Severity**: **n/a** (defect not present)

## Report (summarized)

> At 390px the senior sheet modal's close ("Close sheet") control is truncated/clipped (owner
> reported it partially cut off, e.g. by the dialog edge or another element).

Expected: the close control is fully visible and operable inside the modal header at 390px.

## Investigation

Measured on the live lab at 390×844, official Chrome (EN and FA), senior sheet modal open:

| metric | EN (LTR) | FA (RTL) |
|---|---|---|
| close button rect | x 298.6 → 365, w 66.4, **h 44** | x 25 → 85.7, w 60.7, **h 44** |
| modal header rect | x 9, w 372 (fits within 390) | x 9, w 372 |
| dialog rect | x 8, w 374 | — |
| `overflow-x` on button | hidden | — |
| clipped by header | **false** | **false** |
| clipped by viewport | **false** | — |
| fully inside dialog | yes (right edge 365 < dialog 374+8) | yes |

`elementFromPoint` at the button's centre and 80% points resolves to the button's own label span —
nothing covers it. Evidence: `probe-report.json` (EN-390-modal-close (BUG-03),
FA-390-modal-close (BUG-03)).

The source already guarantees the 44px floor at ≤640px —
`src/components/review/SeniorSheetModal.vue:1134-1136`
`.senior-modal :deep(.senior-modal__close) { min-block-size: 44px; }` — and this T030-era floor is
live (measured height 44px in both directions). The header (`space-between`) leaves the button fully
inside its box; no clipping, covering, or viewport overflow occurs.

## Root Cause

**No defect.** The reported truncation is not observable in the current build at the stated viewport
and does not reproduce. It may correspond to an earlier build (before the T030 44px floor landed) or
to a different surface; the live measurement shows the control intact, 44px, covered by nothing.

## Proposed Remediation

- **None.** Record as **not reproduced** with the measurement evidence; make no source change.
- Keep the existing T030 floor rule (do not remove or alter it — it is the guard that prevents this
  class of defect).

**Files likely to change**: none.

**Tests to add or update**: none required. The existing
`QuickReviewAccessibilityConformance.spec.ts` already asserts the 44px floor rule text
(`senior-modal__close … min-block-size: 44px`); optionally the live Chrome probe can be retained as a
permanent guard, but no new test is mandated.

## Risks & Considerations

- Removing or weakening the `min-block-size: 44px` floor would reintroduce the class of defect; it is
  deliberately untouched.
- If the owner reproduces truncation on a different viewport/orientation or with a long localized
  label, that is a new report needing its own assessment.

## Relevant Spec Kit Requirements

- T030 mobile touch-target floor (44px) — already satisfied and measured.

## Open Questions

- Whether the owner's report predates the T030 floor (report is dated 2026-11-10, same day as T030;
  the current lab build already shows the floor). Not a blocker for closing.
