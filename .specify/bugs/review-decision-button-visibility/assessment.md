# Bug Assessment: Review decision confirmation buttons invisible / low contrast

- **Slug**: review-decision-button-visibility
- **Created**: 2026-11-24T21:20:08Z
- **Source**: pasted text (bug report); no URL supplied
- **Verdict**: valid
- **Severity**: high

## Report (verbatim / summarized)

The product owner reports two defects in the transaction decision confirmation modal:

1. **Reject transaction** — the confirmation button is extremely faint and barely readable; entering an explanation does not resolve it.
2. **Return transaction for correction** — the confirmation button remains invisible or appears disabled after an explanation is entered; in the supplied screenshot Cancel is the only clearly visible footer action.

The same symptoms are reported across Cost Center Manager review, Senior Manager review, and Finance Manager review (features 006, 007, 009). The supplied screenshots show the Senior Manager review surface in English. The owner cautions not to assume a visually disabled button is actually disabled at the DOM level.

The transaction cancellation navigation bug is a separate, already-verified issue and is not in scope.

## Symptom

The Reject and Return confirmation buttons in the shared `DecisionConfirmation` modal are visually unusable (Reject faint/low-contrast, Return invisible) across all three management review stages, even though the underlying permission gating is intact. Expected: both buttons visible and readable when the server grants the action and a valid non-whitespace explanation is supplied; Approve keeps its optional-explanation behavior; unauthorized actions remain unavailable.

## Reproduction

1. Open the lab (`http://localhost:8081`) as a user with Cost Center Manager, Senior Manager, and Finance Manager roles respectively.
2. Open a transaction's decision confirmation (Reject and Return) at each stage via the sheet modal's overlays.
3. Inspect the confirmation button before and after entering a valid non-whitespace explanation:
   - presence in the DOM (`[data-testid="decision-confirm-btn"]`),
   - text content,
   - native `disabled` property,
   - `aria-disabled`,
   - server-provided action grant (`allowedActions.canReject` / `canReturn`),
   - computed `background-color` / `color` / `opacity` / `visibility` / `display` / `pointer-events`,
   - resolved CSS custom property values (`--pettycash-review`, `--color-error`),
   - text-contrast ratio,
   - browser console errors.
4. Repeat for English LTR and Persian RTL, desktop and 390px mobile, light and dark theme.

[NEEDS CLARIFICATION: exact role/credential set and lab fixture transactions that expose Reject + Return at each stage; exact computed `--color-error` value in the installed Nextcloud theme.]

## Suspected Code Paths

- `src/components/review/DecisionConfirmation.vue:591-595` — Return confirm styling: `background-color: var(--pettycash-review); border-color: var(--pettycash-review); color: #fff;` (class `.decision-modal__confirm--return`).
- `src/components/review/DecisionConfirmation.vue:601-605` — Reject confirm styling: `background-color: var(--color-error); border-color: var(--color-error); color: #fff;` (class `.decision-modal__confirm--danger`).
- `src/components/review/DecisionConfirmation.vue:118` — `:disabled="!actionGranted || submitting"`.
- `src/components/review/DecisionConfirmation.vue:204-208` — `actionGranted = decisionAllowed(props.allowedActions, props.decision ?? 'APPROVE')`.
- `src/components/review/DecisionConfirmation.vue:206,319-328` — `requiresExplanation` and `confirm()` explanation guard (FR-033).
- `src/components/review/SheetDetailsModal.vue:1-2` — `<Teleport to="body">` wrapping the whole modal including the `#overlays` slot (line 190) that renders `DecisionConfirmation`.
- `src/styles/app.scss:49` — `--pettycash-review: #6f43c9` declared only on `.pettycash-shell`; `app.scss:67-69` dark-theme re-key `#b18cff` also scoped to `html[data-theme-dark] .pettycash-shell`.
- `src/types/api.ts:788-792` — `DECISION_CAPABILITY` mapping; `api.ts:802-804` — `decisionAllowed` requires explicit `=== true`.
- `src/views/reviews/CostCenterQueueView.vue:400`, `SeniorQueueView.vue:429`, `FinanceQueueView.vue:305` — all three stages render `DecisionConfirmation` inside `SheetDetailsModal`'s `#overlays` slot.

## Root Cause Hypothesis

Confirmed for Return (H1); likely for Reject (H2); ruled out as the cause of invisibility for gating (H3).

**H1 — Return invisible (CONFIRMED by static inspection):** All three views mount `DecisionConfirmation` inside `SheetDetailsModal`'s `#overlays` slot, and `SheetDetailsModal` teleports its entire subtree to `document.body` (`SheetDetailsModal.vue:1-2`). The element therefore leaves `.pettycash-shell`, where `--pettycash-review` is declared (`app.scss:49,67-69`). CSS custom properties inherit through the DOM, so after Teleport `var(--pettycash-review)` is undefined. `background-color: var(--pettycash-review)` becomes invalid/resolves to transparent while `color: #fff` stays, yielding white text on a transparent/invisible background — exactly the "Cancel is the only clearly visible footer action" symptom. The scrim is unaffected because it uses a hardcoded fallback (`color-mix(in srgb, black 55%, transparent)`, `DecisionConfirmation.vue:397`), and Cancel uses the library's secondary variant, so they remain visible.

**H2 — Reject faint (plausible, needs runtime confirmation):** Reject uses `var(--color-error)` with `#fff` text (`DecisionConfirmation.vue:601-605`). `--color-error` is a Nextcloud host-level token (not declared in this repo; only a fallback `#b42318` is referenced for a different token at `_responsive.scss:92`), so it survives the Teleport to body. The faint appearance is therefore a contrast question: white text on the installed theme's resolved `--color-error` may fall below 4.5:1. Exact computed value/contrast ratio requires Chrome + Playwright against the lab; not yet measured.

**H3 — Action gating (RULED OUT as the cause of invisibility):** `actionGranted` derives from `decisionAllowed`, which returns `true` only on an explicit server grant (`api.ts:802-804`); `:disabled="!actionGranted || submitting"` (`DecisionConfirmation.vue:118`). Empty/whitespace explanations are rejected inside `confirm()` (`DecisionConfirmation.vue:319-328`) and never produce a decision request. Unit suite passes (13/13), including "keeps the confirm control disabled without an explicit server grant." The button is not actually disabled when granted + explanation supplied; the defect is visual (CSS), not a permission/functional gating bug. Runtime confirmation that `disabled === false` and `actionGranted === true` when granted + explanation entered remains a lab verification step.

## Proposed Remediation

**Preferred:** Make the Return confirmation's color self-contained so it does not depend on a shell-scoped custom property that is lost across Teleport. Promote `--pettycash-review` to a globally-inheritable location (e.g. `:root` with a matching `html[data-theme-dark]` re-key) while keeping the existing `.pettycash-shell` declarations for non-teleported consumers. This restores the purple Return button in light and dark themes without touching gating, explanation validation, submitting state, or any permission boundary. Separately, verify Reject contrast at runtime; if white-on-`--color-error` is below 4.5:1 in the installed theme, adjust the `.decision-modal__confirm--danger` treatment (background/text token) to meet 4.5:1 while preserving the error semantic.

**Alternatives:**
- Inline a static fallback (`background-color: var(--pettycash-review, #6f43c9)`) plus a dark-theme media query override in the scoped style. Trade-off: duplicates theme values, does not cover the warning treatment consistently, and dark-theme detection is awkward outside the shell.
- Scope a wrapper/token provider inside the teleported modal. Trade-off: larger change, more surface area.

**Files likely to change:**
- `src/styles/app.scss` — promote/declare `--pettycash-review` globally with light + dark-theme values.
- `src/components/review/DecisionConfirmation.vue` — possibly add a fallback and/or adjust Reject contrast treatment (only after runtime contrast measurement).
- `tests/Frontend/Component/DecisionConfirmation.spec.ts` — presence/disabled-state assertions (computed colors/contrast are not reliably measurable in jsdom).
- New Playwright e2e (Chrome `chrome` channel) against the lab — computed background/foreground colors, contrast ratio, `disabled`/`aria-disabled`, action grant, and `pointer-events`/`opacity`/`visibility`/`display` per role × decision × theme × viewport.

**Tests to add or update:**
- Unit: confirm the confirm button is present and not `disabled` when `allowedActions` grants the decision and a non-whitespace explanation is entered, for Reject and Return (guarding `confirm()` still blocks empty/whitespace).
- E2E: for each of the three stages and each of Reject/Return, before and after a valid explanation: button in DOM, `disabled === false`, `aria-disabled` absent/false, computed `background-color` and `color`, resolved `--pettycash-review` / `--color-error`, contrast ratio ≥ 4.5:1 for the enabled confirm button, no console errors; confirm empty/whitespace does not post a decision; confirm Approve still posts with empty explanation; confirm unauthorized action remains disabled. Cover light/dark, desktop, 390px mobile, LTR/RTL.

## Risks & Considerations

- Promoting `--pettycash-review` to `:root` could affect any other consumer that currently relies on it being shell-scoped; audit usages (`StatusBadge.vue`, queue views) before changing. The token is currently only consumed by `DecisionConfirmation.vue` and `StatusBadge.vue`/`ReviewStageMarkers.vue`-adjacent chip styling.
- Reject contrast depends on the host theme's `--color-error`, which the project does not control; a client-side override must be chosen to survive both light and dark host themes and forced-colours mode.
- Do not alter `decisionAllowed`, `:disabled`, `confirm()`, or FR-033 explanation behavior; unauthorized actions must remain gated off.
- Computed colors and contrast cannot be asserted in jsdom; visual/contrast evidence must come from Chrome e2e against the live lab.
- Preserve submitting/duplicate-submission protection (FR-085 first-write-wins via `newCommandId`), focus restoration (T036), error handling, and RTL/LTR behavior.

## Open Questions

- [NEEDS CLARIFICATION: exact lab roles/credentials and fixture transactions exposing Reject + Return at Cost Center / Senior / Finance stage.]
- [NEEDS CLARIFICATION: resolved `--color-error` value and measured contrast ratio for white text in the installed Nextcloud theme, light and dark.]
- [NEEDS CLARIFICATION: whether the returned `--pettycash-review` value is truly `undefined` after Teleport in the live runtime (confirmable via computed style in Chrome).]

## Implementation defect vs requirement gap

This is an **implementation defect**: the permission/explanation gating (FR-033, T026, `decisionAllowed`) is correct and unit-tested; the defect is that the Return confirmation's color token (`--pettycash-review`) is scoped to `.pettycash-shell` yet the modal is teleported to `body`, so the token is lost and the button renders invisible. Reject's faintness is a secondary, theme-contrast aspect of the same presentation problem. No specification, contract, schema, or authorization change is required.

## Diagnostic artifacts

None captured yet (runtime computed-style/contrast evidence deferred to lab e2e; screenshots to be stored under `artifacts/visual-review/review-decision-button-visibility/`).

## Git state

Working tree contains pre-existing uncommitted/staged changes (staged `.specify/bugs/*` fix/test/assessment files and deleted/rebuilt `css/`/`js/` assets) that are **not** part of this task and were left untouched. This assessment writes only `.specify/bugs/review-decision-button-visibility/assessment.md`; no application source was modified. No commit/stage/push performed.

## Code intelligence

CGC=not needed; Serena=not needed; Context7=not needed; source=verified (`DecisionConfirmation.vue`, `SheetDetailsModal.vue`, `app.scss`, `types/api.ts`, three queue views); tests=executed (`DecisionConfirmation.spec.ts` 13/13 passed).

## Next step

`/speckit.bug.fix slug=review-decision-button-visibility` (after runtime H2/H3 confirmation if pursued).
