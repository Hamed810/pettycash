# Bug Fix: Review decision confirmation buttons invisible / low contrast

- **Slug**: review-decision-button-visibility
- **Fixed**: 2026-11-24T21:45:00Z
- **Assessment**: ./assessment.md
- **Status**: applied

## Summary

Restored visible, readable Reject and Return confirmation buttons across Cost Center, Senior, and Finance Manager review surfaces. The Return button was invisible because `SheetDetailsModal` teleports its content (including `DecisionConfirmation`, rendered in the `#overlays` slot by all three stage views) to `body`, outside `.pettycash-shell`, where `--pettycash-review` was declared — so `var(--pettycash-review)` resolved to nothing and white text had no background. Promoted the token to `:root` (light) and `html[data-theme-dark]` (dark) so it survives the Teleport boundary. Corrected the Return dark-theme foreground (white on light `#b18cff` was ~2.6:1, below 4.5:1) to a dark foreground, and switched the Reject button foreground to the semantic `--color-error-text` token paired with its `--color-error` background. Action gating (`:disabled="!actionGranted || submitting"`), explanation validation inside `confirm()`, focus restoration, and duplicate-submission protection were left untouched.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `src/styles/app.scss` | Modified | Moved `--pettycash-review` (#6f43c9 light, #b18cff dark) from `.pettycash-shell` / its single-purpose dark rule into `:root` and `html[data-theme-dark]`, so the token is inherited across the `SheetDetailsModal` `<Teleport to="body">` boundary. Type scale and `box-sizing` preserved; status-badge/chip consumers unaffected. |
| `src/components/review/DecisionConfirmation.vue` | Modified | Return button: added `html[data-theme-dark]` dark foreground (`#222`) so dark theme meets 4.5:1. Reject button: changed `color: #fff` to `color: var(--color-error-text, #fff)` for the semantic error-text/background pairing (falls back to previous behavior if token absent). `:disabled`, `confirm()`, Teleport, focus, and submit logic unchanged. |

## Diff Highlights

`src/styles/app.scss` — token relocated to document root:
```scss
:root {
	--pettycash-review: #6f43c9;
}

html[data-theme-dark] {
	--pettycash-review: #b18cff;
}
```
(`--pettycash-review` removed from `.pettycash-shell` and from `html[data-theme-dark] .pettycash-shell`; type scale and `box-sizing` retained in `.pettycash-shell`.)

`src/components/review/DecisionConfirmation.vue` — Return dark-theme foreground + Reject semantic text:
```css
.decision-modal__footer-group :deep(.decision-modal__confirm--return.button-vue) {
	background-color: var(--pettycash-review);
	border-color: var(--pettycash-review);
	color: #fff;
}

html[data-theme-dark] .decision-modal__footer-group :deep(.decision-modal__confirm--return.button-vue) {
	color: #222;
}

.decision-modal__footer-group :deep(.decision-modal__confirm--danger.button-vue) {
	background-color: var(--color-error);
	border-color: var(--color-error);
	color: var(--color-error-text, #fff);
}
```

## Tests Added or Updated

No new test files were added. Existing suites exercise the changed paths:
- `tests/Frontend/Component/DecisionConfirmation.spec.ts` — pins `actionGranted` gating, explanation guard, one-command submission, focus restoration.
- `tests/Frontend/Component/ManagementReview.spec.ts`, `SeniorReview.spec.ts`, `FinanceQueue.spec.ts`, `QuickReviewWorkflow.spec.ts`, `QuickReviewAccessibilityConformance.spec.ts` — cover the three stage surfaces that mount `DecisionConfirmation`.

Computed-color / contrast assertions are not reliably measurable in jsdom and are deferred to browser-level regression coverage (`/speckit.bug.test`).

## Local Verification

- `npx vitest run tests/Frontend/Component/DecisionConfirmation.spec.ts ManagementReview.spec.ts SeniorReview.spec.ts FinanceQueue.spec.ts QuickReviewWorkflow.spec.ts QuickReviewAccessibilityConformance.spec.ts` → 6 files, 159 passed.
- `npx vue-tsc --noEmit` → passed (no errors).
- `npx eslint src/components/review/DecisionConfirmation.vue` → passed (no errors; `app.scss` is not ESLint-configured).
- `npx stylelint src/components/review/DecisionConfirmation.vue src/styles/app.scss` → passed (no errors).
- `npx vitest run` (full suite) → 1387 passed, 2 failed.
- `npx vite build` → built successfully in 41.88s; rebuilt CSS/JS chunks contain `color-error-text` and `pettycash-review` markers (e.g. `css/SheetDetailsModal-pVjOKcAE.chunk.css`, `css/main-jSw9HH1l.chunk.css`).

## Deviations from Assessment

None. The fix followed the proposed remediation exactly: token inheritance restored via `:root`/`html[data-theme-dark]`, Return dark-theme contrast corrected with a theme-aware foreground, Reject switched to the semantic `--color-error-text` pairing, and all gating/explanation/focus/duplicate-submission behavior preserved.

## Follow-ups / Remaining Blockers

- **Runtime Chrome verification is deferred.** The lab at `http://localhost:8081` requires Nextcloud authentication, which cannot be performed headless without credentials, so computed-style/contrast-ratio evidence (Resolved `--pettycash-review`, `--color-error`, `--color-error-text`, foreground/background colors, contrast ratios, `disabled`/`aria-disabled`, visibility/opacity/display/pointer-events across light/dark × desktop/390px × LTR/RTL × the three roles) could not be captured in this session. This is the intended scope of the separate `/speckit.bug.test slug=review-decision-button-visibility` workflow. Do not report the bug as verified until that browser evidence is gathered.
- **Full-suite pre-existing failures:** `tests/Frontend/typography.spec.ts` has 2 failures about `CalendarDatePicker.vue` font sizes (`var(--default-font-size)`, `13px`). `CalendarDatePicker.vue` is pre-existing staged dirty state (`A`) and was not modified by this fix; these failures are unrelated to the change.
- Generated frontend outputs under `js/` and `css/` were regenerated by `vite build`. Not staged or committed (git write operations are prohibited in this workflow).

Final Git state: working tree retains pre-existing dirty/staged changes; this fix modified only `src/styles/app.scss` and `src/components/review/DecisionConfirmation.vue` (both `M`, unstaged). `assessment.md` was not edited. `fix.md` written at `.specify/bugs/review-decision-button-visibility/fix.md`. No stage, commit, push, reset, or clean performed.

## Code intelligence

CGC=not needed; Serena=not needed; Context7=not needed; source=verified (`app.scss`, `DecisionConfirmation.vue`, `SheetDetailsModal.vue`, `types/api.ts`, three queue views); tests=executed (targeted suites 159/159 passed; full suite 1387 passed with 2 pre-existing unrelated failures; build passed).

## Next step

`/speckit.bug.test slug=review-decision-button-visibility`
