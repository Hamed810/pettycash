# Bug Assessment: Persian decision-modal mixed-direction values render incorrectly

- **Slug**: `persian-decision-modal-bidi`
- **Created**: 2026-11-10T21:30:00Z
- **Source**: Feature 007 owner report, defect **BUG-04**
- **Verdict**: **valid** (reproduced in official Google Chrome at 390×844, Persian RTL)
- **Severity**: **medium**

## Report (summarized)

> In the Persian (RTL) quick-decision confirmation modal, mixed-direction values — the sheet UUID
> reference and the money amount — render in the surrounding RTL ordering instead of as LTR-isolated
> tokens. The parent senior sheet modal renders the same reference inside `<bdi dir="ltr">`, keeping
> the UUID readable; the decision modal wraps them in a bare `<bdi>` (no direction), so the value is
> re-ordered and reads incorrectly in Persian.

Expected: the sheet reference and the amount are isolated LTR tokens in the RTL modal (matching the
parent modal's `senior-modal__reference` pattern), with the surrounding Persian text still RTL.

## Symptom

Measured on the live lab at 390×844 in `fa` (RTL), decision modal opened from the senior queue card:

| element | textContent | markup `<bdi …>` dir attr | computed `unicode-bidi` |
|---|---|---|---|
| eyebrow (`decision-modal__eyebrow`) | `بازبینی سریع · 630a6e00-…` | `<bdi>630a6e00-…</bdi>` — **no `dir`** | isolate |
| summary-meta (`decision-modal__summary-meta`) | `630a6e00-… · 7/9/1405 AP` | `<bdi>630a6e00-…</bdi>` — **no `dir`** | isolate |
| amount (`[data-testid="decision-modal-amount"]`) | `50,000 IRR` | `<bdi>50,000 IRR</bdi>` — **no `dir`** | isolate |
| transaction id (`decision-modal__meta-id`) | `2d671926-…` | `<bdi dir="ltr" class="decision-modal__meta-id">` | isolate |

The only correctly-isolated LTR value is the transaction id. The eyebrow and summary reference (same
UUID shown LTR in the parent modal's `senior-modal-reference`) and the amount get only the
`<bdi>` default RTL isolation, so within the RTL modal the embedded LTR string is presented under the
RTL base direction and the token order/rendering is wrong. Evidence: `probe-report.json`
(FA-390-decision-modal-bidi): `bdis` entries 1, 3, 4 have `dirAttr: null`; only entry 2 (meta-id) has
`dirAttr: "ltr"`.

## Reproduction

1. Sign in as `PETTYCASH_E2E_USER`; set user language to `fa` (RTL).
2. Open `#/reviews/senior` at 390×844; open the first sheet; tap the first card's Approve decision.
3. Inspect the decision modal's `<bdi>` elements and their `dir` attributes as above. Read-only; no
   decision is submitted.

Reproduction artifacts: `.tmp-repro/bugbatch-probe.mjs`, `probe-report.json` in
`artifacts/visual-review/bugbatch-001/`.

## Suspected Code Paths

- `src/components/review/QuickDecisionModal.vue:22` — eyebrow `<bdi>{{ transaction.sheetReference }}</bdi>` (no `dir`).
- `src/components/review/QuickDecisionModal.vue:48` — summary-meta `<bdi>{{ transaction.sheetReference }}</bdi>` (no `dir`).
- `src/components/review/QuickDecisionModal.vue:54` — amount `<bdi>{{ amountLabel }}</bdi>` (no `dir`).
- Contrast with the parent modal `src/components/review/SeniorSheetModal.vue:26`
  `<bdi dir="ltr" class="senior-modal__reference">…</bdi>` and the drawer
  `src/components/review/TransactionDetailsDrawer.vue:31,35` (`dir="ltr"` on id/reference) — the
  established pattern for LTR tokens inside RTL surfaces.

## Root Cause Hypothesis

**Confidence: high.** `formatMoney` and the UUID are LTR content, but the modal wraps them in a bare
`<bdi>`, which only establishes bidi isolation with the base direction (RTL) rather than forcing the
embedded value's own direction. `<bdi dir="ltr">` (as the sibling sheet modal and the drawer already
use for the same reference) pins the isolate to LTR, so the value is read left-to-right inside the
RTL modal while the surrounding Persian text stays RTL. The defect is purely presentational/bidi; no
data or logic changes.

## Proposed Remediation

**Preferred**: add `dir="ltr"` to the three bare `<bdi>` elements in `QuickDecisionModal.vue` — the
eyebrow reference (line 22), the summary-meta reference (line 48), and the amount (line 54). This
mirrors the parent modal's `senior-modal__reference` and the shared drawer pattern exactly, is
three-attribute-change, and touches no copy, logic, or API. Optionally guard the CSS with
`unicode-bidi: isolate` on the amount's `<bdi>` (UA stylesheet already isolates `<bdi>`), so no new
CSS rule is required.

**Files likely to change**:
- `src/components/review/QuickDecisionModal.vue` — template only (three `dir="ltr"` additions).

**Tests to add or update**:
- Extend `tests/Frontend/Component/QuickReviewWorkflow.spec.ts` (which mounts the decision modal) or
  a component spec: mount the modal in RTL with a UUID sheetReference and money amount, assert the
  three `<bdi>`s carry `dir="ltr"` (and that `meta-id` still does). jsdom can assert attributes even
  though it cannot measure bidi layout; the RTL rendering is then covered by the Chrome probe.
- Live Chrome guard (390×844, `fa`): every `<bdi>` in the decision modal has `dir="ltr"` for
  reference/amount, and the surrounding text stays RTL. Reuse the existing decision-modal open flow.

## Risks & Considerations

- LTR rendering of money: existing `details-drawer__amount` uses bare `<bdi>` too
  (`TransactionDetailsDrawer.vue:104-106`) but the owner-reported defect is scoped to the decision
  modal; do not expand scope unless the owner asks. The drawer's reference/id already use `dir="ltr"`.
- No behavior/API/persistence change; labels and data unchanged.
- `formatsMoney` output (e.g. `50,000 IRR`) stays identical; only its directional isolation changes.

## Relevant Spec Kit Requirements

- Feature 007 report acceptance for FA/EN, RTL/LTR correctness of the review surfaces; the parent
  modal already establishes the `<bdi dir="ltr">` convention this restores.

## Open Questions

- None blocking. (Whether the drawer's `details-drawer__amount` bare `<bdi>` should also gain
  `dir="ltr"` is out of the reported scope; flagged as a possible follow-up only.)
