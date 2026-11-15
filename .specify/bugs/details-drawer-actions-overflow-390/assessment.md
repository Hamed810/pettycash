# Bug Assessment: Full-details drawer action controls overflow the 390px viewport

- **Slug**: `details-drawer-actions-overflow-390`
- **Created**: 2026-11-10T21:30:00Z
- **Source**: Feature 007 owner report, defect **BUG-02**
- **Verdict**: **valid** (reproduced in official Google Chrome at 390×844, English LTR and Persian RTL)
- **Severity**: **medium**

## Report (summarized)

> At a 390px mobile viewport, the transaction full-details drawer's footer action controls
> (Reject / Return / Approve / Close) overflow the drawer and the viewport horizontally. In LTR the
> controls span x≈197→559 while the drawer is only 390 wide; in RTL they span x≈−153→209, so part of
> every control is off-screen. The drawer can no longer horizontally scroll the footer and there is
> no reachable state for the off-screen portion.

Expected: all footer decision/close controls remain fully inside the 390px drawer in both directions,
stack 44px-high, and stay operable; desktop >640px is untouched.

## Symptom

Measured on the live lab (`http://localhost:8081`, official Chrome `{ channel: 'chrome' }`, viewport
390×844, sheet `630a6e00-f7ca-43d7-9bb4-002ce19bfc6b`, transaction
`2d671926-372f-48ca-81f1-1865386c28c2`, read-only):

| metric | EN (LTR) | FA (RTL) |
|---|---|---|
| footer `scrollWidth` / `clientWidth` | **559 / 390** | **543 / 390** |
| footer-group `rect.x`, width | 197.2, 362 | −153, 362 |
| each button right/left edge | 559.2 (right) | −153 (left) |
| buttons beyond viewport | all 4 (x:197→559) | all 4 (x:−153→209) |

Every control measures 362×44px (the T036 floor is applied), but the group is positioned past the
inline-start 14px padding: in LTR it starts at x=197.2 and ends at 559.2; in RTL it starts at
x=−153. `buttonBeyondWindow` lists all four in the FA pass. Evidence: `probe-report.json`
(EN-390-drawer-actions, FA-390-drawer-actions) and `bug02-computed-report.json` (computed styles).

## Reproduction

1. Sign in as `PETTYCASH_E2E_USER`; open `#/reviews/senior` at 390×844 (mobile emulation).
2. Open the first sheet, then the first transaction's **Full details** drawer.
3. Measure the footer/group/buttons. See the raw measurements above (both `probe-report.json` and
   the focused `bug02-computed-report.json` in `artifacts/visual-review/bugbatch-001/`).

Reproduction artifacts: `.tmp-repro/bugbatch-probe.mjs`, `.tmp-repro/bug02-computed-probe.mjs`
(untracked, read-only runners).

## Suspected Code Paths

- `src/components/review/TransactionDetailsDrawer.vue:810-819` — `.details-drawer__footer`
  `display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
  padding:12px 20px`.
- `src/components/review/TransactionDetailsDrawer.vue:854-858` — the `@media (width <= 640px)`
  footer rule sets `flex-direction: column; align-items: stretch; padding:12px 14px` but **never
  resets `flex-wrap`**; the desktop `flex-wrap: wrap` (line 815) survives into the narrow viewport.
- `src/components/review/TransactionDetailsDrawer.vue:860-871` — the group column rule and the
  `:deep(.button-vue)` full-width rule are correct in isolation.

## Root Cause Hypothesis

**Confidence: high (confirmed by computed styles + a fix-outcome injection).** At ≤640px the footer
is a **column** flex container (`flex-direction: column`), but `flex-wrap: wrap` is still active from
the desktop rule (only `flex-direction` and `align-items` were overridden). The drawer's
full-height layout constrains the footer's height (~260px measured), so the column children
(decide-label 234px, group 148px, close button 44px) cannot fit in one column line; with `wrap` they
form additional vertical columns placed side by side along the inline axis, overflowing the 390px
box (footer `scrollWidth` 559 LTR / 543 RTL). Because `align-items: stretch` makes each such column
the full 362px content width, the wrapped lines poke out to x≈559 (LTR) / x≈−153 (RTL).

The computed styles prove the mobile rules are present and applied (`footer { flex-direction:
column; align-items: stretch; }`, group `{ flex-direction: column }`, buttons `{ width:362px;
min-block-size:44px }`) — `matchMedia('(width<=640px)')` is `true` — so this is **not** a stale
bundle. Injection verification: forcing `flex-wrap: nowrap` on the footer at runtime made every
control land at x=14→376 with `scrollWidth` dropping to 362 (EN) / 390 (FA) and `buttonBeyondWindow`
empty, in both directions.

## Proposed Remediation

**Preferred**: CSS-only, one declaration. In `TransactionDetailsDrawer.vue`'s
`@media (width <= 640px)` block, add `flex-wrap: nowrap;` to the `.details-drawer__footer` rule so the
column never wrap-divides into overflow columns:

```css
@media (width <= 640px) {
	.details-drawer__footer {
		flex-direction: column;
		align-items: stretch;
		flex-wrap: nowrap;   /* BUG-02: prevent column-wrap overflow past the 390px viewport */
		padding: 12px 14px;
	}
}
```

Everything else already works: the group is a stacked column, each button is `inline-size:100%` and
44px tall, and the close button is also full-width. The same `wrap` residue on `.details-drawer__
footer-group` (desktop line 831) is harmless because the group's height is unbounded and its three
44px children fit a single column (measured height 148 = 3×44 + 2×8) — leave it untouched for
minimality. `flex-wrap` is direction-neutral, so no physical `left`/`right`/LTR-RTL divergence is
introduced; desktop >640px keeps the current row layout (the media rule does not apply there).

**Alternatives**:
- Reset `flex-wrap: nowrap` on both the footer and the group. Redundant for the group (no current
  overflow) and non-minimal.
- `overflow-x: hidden` on the footer — hides the symptom but can still clip focus/gestures and does
  not fix reachability. Rejected.

**Files likely to change**:
- `src/components/review/TransactionDetailsDrawer.vue` — scoped CSS only (one declaration, no
  template/script/API change).

**Tests to add or update**:
- Browser guard (only layer that can measure layout): official Chrome at 390×844, EN + FA, assert
  footer `scrollWidth <= clientWidth`, every decision/close control within `[0, window.innerWidth]`,
  controls stacked full-width (x≈14, width≈362), 44px tall. Reuse the existing drawer-open flow.
- jsdom cannot measure geometry; no declaration-only assertion can substitute for the Chrome
  measurement (keep the existing accessibility source regex in
  `QuickReviewAccessibilityConformance.spec.ts` unchanged — the 44px floor rule is not touched).
- The existing `TransactionDetailsDrawer.spec.ts` and `SeniorSheetModal.spec.ts` drawer interactions
  must stay green (no DOM/API change).

## Risks & Considerations

- **Desktop untouched**: the media rule is `width <= 640px`; ≥641px keeps the row layout exactly as
  today.
- **RTL/LTR symmetric**: the fix is a flex-wrap reset; direction handles mirroring, verified by the
  injection in both languages.
- No business rule, API, persistence, or accessibility semantic change: buttons keep their labels,
  order, disabled state and 44px tap floor.
- **Other narrow hosts**: the drawer is shared with the Cost Center queue
  (`CostCenterQueueView.vue:468`) — the same overflow would exist there at 390px and is healed by the
  same rule; no separate change needed.

## Relevant Spec Kit Requirements

- Feature 007 report requirements FR-007-042 (no horizontal scroll at 390px) and the T036 44px mobile
  floor; the drawer's own `@media (width <= 640px)` mobile form ("the drawer becomes one full-screen
  sheet") is already the approved design — this fix makes that design actually fit the viewport.

## Open Questions

- None blocking. (Whether the Cost Center drawer's identical fix should carry its own regression
  guard or share the senior drawer probe is a test-scope choice; the shared-component guard covers
  both.)
