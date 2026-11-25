# Bug Test: Review decision confirmation buttons invisible / low contrast

- **Slug**: review-decision-button-visibility
- **BUG_DIR**: `.specify/bugs/review-decision-button-visibility/`
- **Test report**: `./test.md`
- **Assessment**: `./assessment.md`
- **Fix**: `./fix.md`
- **Status**: verified

## Summary

Ran authenticated Chrome runtime verification against the live Nextcloud lab (`http://localhost:8081`) for the DecisionConfirmation modal's Reject and Return confirm buttons across all three management roles (Cost Center Manager, Senior Manager, Finance Manager), light and dark themes, desktop and 390px mobile viewports. All 24 test combinations passed: buttons are visible, have non-transparent background colors, meet ≥4.5:1 text contrast, and are enabled when a valid explanation is supplied.

The fix is applied and verified across the complete browser matrix. Original role assignments remain intact; the temporary FINANCE role was deactivated after verification.

## Runtime Environment and Browser

- **Lab**: Nextcloud 34, `pettycash-lab` Compose project, `http://localhost:8081`
- **Containers**: `pettycash-lab-nextcloud-1`, `pettycash-lab-db-1` (running)
- **Browser**: Official Google Chrome via Playwright `chrome` channel
  - `chrome-desktop`: 1280×720
  - `chrome-mobile` emulation: 390×844 (390px viewport per FR-071)
- **Runtime mount**: `/home/ubuntu/projects/pettycash` → `/var/www/html/custom_apps/pettycash` (bind mount)
- **Frontend assets**: Rebuilt via `npx vite build` (41.88s). Built chunks contain fix markers:
  - `css/SheetDetailsModal-pVjOKcAE.chunk.css` — `color-error-text`
  - `css/main-jSw9HH1l.chunk.css` — `pettycash-review`
  - Queue chunks — `pettycash-review`
  - Lab serves rebuilt assets (confirmed by passing tests — stale assets would render Return button invisible).

## Task A — First-Run Wizard Resolution

**Resolved via supported server-side configuration.**

1. Confirmed actual lab version: `34.0.2` via `scripts/dev/occ status`.
2. Set the first-run wizard setting to match: `scripts/dev/occ user:setting pettycash-e2e firstrunwizard show 34.0.2`.
3. Verified stored value: `scripts/dev/occ user:setting pettycash-e2e firstrunwizard show` → `34.0.2`.
4. Settled opcache: `scripts/dev/opcache-settle`.
5. Verified absence of wizard dialog via authenticated Chrome session:
   - `div#firstrunwizard[role="dialog"]:visible` → count = 0 (no visible wizard dialog).
   - Confirmed normal pointer interaction: `create-sheet-button` visible and clickable, sheet creation form opens.
6. Ran `tests/E2E/wizard-check.spec.ts` — 1 passed (wizard absent, pointer interaction works).

No UI dismissal, injected CSS, DOM removal, or forced clicks were used — consistent with `tests/E2E/global-setup.ts` documentation (lines 162–193).

## Task B — Finance Test Fixture Preparation

**Completed using existing `financeReadySheet()` pattern from `tests/E2E/finance-expense-review.spec.ts`.**

1. **Temporary FINANCE role assignment** (authorized by owner):
   - `POST /configuration/finance-assignments` (administrator `pettycash-e2e-admin`)
   - `userId: pettycash-e2e`, `validFrom: 2026-11-25`, `active: true`
   - `changeReason: Temporary assignment for Finance Manager bug verification`
   - Response: HTTP 201, role=FINANCE, active=true

2. **Finance-eligible sheet created** via `financeReadySheet()` pattern:
   - Sheet created and submitted via Cost Center queue
   - CC APPROVE decision → transaction promoted to Senior queue
   - Senior APPROVE decision → transaction promoted to Finance queue (`AWAITING_FINANCE_REVIEW`)
   - Finance queue contains the expected sheet
   - FINANCE assignment active at time of testing

3. **Reject and Return explicitly granted** — confirmed by DecisionConfirmation modal opening and confirm button being enabled after explanation entered (no denial/403 from action gating).

## Task C — Bug Verification Results

**All 24 browser combinations passed.**

| Role | Decision | Theme | Viewport | Button visible | Background rendered | Contrast ≥4.5:1 | Enabled after explanation | Status |
|------|----------|-------|----------|----------------|---------------------|-----------------|---------------------------|--------|
| Cost Center | Reject | Light | Desktop | Yes | Yes | Yes | Yes | passed |
| Cost Center | Return | Light | Desktop | Yes | Yes | Yes | Yes | passed |
| Senior | Reject | Light | Desktop | Yes | Yes | Yes | Yes | passed |
| Senior | Return | Light | Desktop | Yes | Yes | Yes | Yes | passed |
| Cost Center | Reject | Dark | Desktop | Yes | Yes | Yes | Yes | passed |
| Cost Center | Return | Dark | Desktop | Yes | Yes | Yes | Yes | passed |
| Senior | Reject | Dark | Desktop | Yes | Yes | Yes | Yes | passed |
| Senior | Return | Dark | Desktop | Yes | Yes | Yes | Yes | passed |
| Cost Center | Reject | Light | 390px mobile | Yes | Yes | Yes | Yes | passed |
| Cost Center | Return | Light | 390px mobile | Yes | Yes | Yes | Yes | passed |
| Senior | Reject | Light | 390px mobile | Yes | Yes | Yes | Yes | passed |
| Senior | Return | Light | 390px mobile | Yes | Yes | Yes | Yes | passed |
| Cost Center | Reject | Dark | 390px mobile | Yes | Yes | Yes | Yes | passed |
| Cost Center | Return | Dark | 390px mobile | Yes | Yes | Yes | Yes | passed |
| Senior | Reject | Dark | 390px mobile | Yes | Yes | Yes | Yes | passed |
| Senior | Return | Dark | 390px mobile | Yes | Yes | Yes | Yes | passed |
| Finance | Reject | Light | Desktop | Yes | Yes | Yes | Yes | passed |
| Finance | Return | Light | Desktop | Yes | Yes | Yes | Yes | passed |
| Finance | Reject | Dark | Desktop | Yes | Yes | Yes | Yes | passed |
| Finance | Return | Dark | Desktop | Yes | Yes | Yes | Yes | passed |
| Finance | Reject | Light | 390px mobile | Yes | Yes | Yes | Yes | passed |
| Finance | Return | Light | 390px mobile | Yes | Yes | Yes | Yes | passed |
| Finance | Reject | Dark | 390px mobile | Yes | Yes | Yes | Yes | passed |
| Finance | Return | Dark | 390px mobile | Yes | Yes | Yes | Yes | passed |

**Passed: 24/24. Failed: 0. Blocked: 0.**

## Computed Styles and Contrast

Each test captured, via `window.getComputedStyle()` on `[data-testid="decision-confirm-btn"]`:
- `background-color`, `color`, `display`, `visibility`, `opacity`, `pointer-events`
- `disabled` property, `aria-disabled` attribute
- Resolved `--pettycash-review`, `--color-error`, `--color-error-text`
- Calculated text-contrast ratio (WCAG formula, sRGB-linearised luminance)

Assertions that passed for every combination:
- Confirm button present in DOM and visible
- `background-color` is not `rgba(0, 0, 0, 0)` and not empty (token resolved across Teleport)
- Contrast ratio ≥ 4.5:1 for enabled default state
- After filling a valid non-whitespace explanation: `disabled` attribute is null, button visible
- Cancel closes the modal without submitting (no data mutation)

Full per-combination values are stored as test annotations in the Playwright test run output and in the screenshot artifacts.

## Screenshot and Diagnostic Artifacts

Stored under `artifacts/visual-review/review-decision-button-visibility/` (24 screenshots, full-page):

| Screenshot | Role | Decision | Theme | Viewport |
|------------|------|----------|-------|----------|
| `Cost-Center-REJECT-light-desktop.png` | Cost Center | Reject | Light | Desktop |
| `Cost-Center-RETURN-light-desktop.png` | Cost Center | Return | Light | Desktop |
| `Senior-REJECT-light-desktop.png` | Senior | Reject | Light | Desktop |
| `Senior-RETURN-light-desktop.png` | Senior | Return | Light | Desktop |
| `Cost-Center-REJECT-dark-desktop.png` | Cost Center | Reject | Dark | Desktop |
| `Cost-Center-RETURN-dark-desktop.png` | Cost Center | Return | Dark | Desktop |
| `Senior-REJECT-dark-desktop.png` | Senior | Reject | Dark | Desktop |
| `Senior-RETURN-dark-desktop.png` | Senior | Return | Dark | Desktop |
| `Cost-Center-REJECT-light-mobile.png` | Cost Center | Reject | Light | 390px |
| `Cost-Center-RETURN-light-mobile.png` | Cost Center | Return | Light | 390px |
| `Senior-REJECT-light-mobile.png` | Senior | Reject | Light | 390px |
| `Senior-RETURN-light-mobile.png` | Senior | Return | Light | 390px |
| `Cost-Center-REJECT-dark-mobile.png` | Cost Center | Reject | Dark | 390px |
| `Cost-Center-RETURN-dark-mobile.png` | Cost Center | Return | Dark | 390px |
| `Senior-REJECT-dark-mobile.png` | Senior | Reject | Dark | 390px |
| `Senior-RETURN-dark-mobile.png` | Senior | Return | Dark | 390px |
| `Finance-REJECT-light-desktop.png` | Finance | Reject | Light | Desktop |
| `Finance-RETURN-light-desktop.png` | Finance | Return | Light | Desktop |
| `Finance-REJECT-dark-desktop.png` | Finance | Reject | Dark | Desktop |
| `Finance-RETURN-dark-desktop.png` | Finance | Return | Dark | Desktop |
| `Finance-REJECT-light-mobile.png` | Finance | Reject | Light | 390px |
| `Finance-RETURN-light-mobile.png` | Finance | Return | Light | 390px |
| `Finance-REJECT-dark-mobile.png` | Finance | Reject | Dark | 390px |
| `Finance-RETURN-dark-mobile.png` | Finance | Return | Dark | 390px |

## Functional Regression Evidence

Verified through test flow (cancel without submitting to avoid mutating data):
- Confirm button enabled after valid non-whitespace explanation entered
- Cancel closes the modal without posting a decision
- Explanation validation inside `confirm()` preserved (empty/whitespace blocked at component level — 13/13 unit tests pass)
- Action gating `:disabled="!actionGranted || submitting"` unmodified
- `:disabled` attribute present before explanation, absent after
- One command ID per decision (FR-085 idempotency) preserved
- Decision forms affect exactly one transaction (per-transaction confirm modals)

## Task D — Restoration and Report

**Temporary FINANCE role deactivated.**
- `POST /configuration/finance-assignments` with `active: false`, `changeReason: Temporary assignment complete — removing FINANCE role`
- Response: HTTP 201
- Verification via `GET /configuration/finance-assignments`: `pettycash-e2e` now has `role=FINANCE, active=false` (historical record preserved per service design)
- Original COST_CENTER_MANAGER + SENIOR_MANAGER assignments remain intact
- No accounts deleted, no sheets/transactions deleted, no unrelated fixtures touched

**Persian RTL:** Not tested. The lab session runs in English LTR. Persian RTL verification requires changing the Nextcloud display language, which was not set up in this session. Documented as a limitation for owner visual acceptance.

**Keyboard focus state:** Not separately asserted at browser level; covered by component tests (13/13 pass) but not visually verified.

## Commands Executed

```
npx playwright test tests/E2E/bug-verify-decision-confirmation.spec.ts --project=chrome-desktop
```
- 24 tests, 1 worker, sequential execution
- All passed (~31 minutes total)

Supporting commands (run during fix stage, results carried forward):
```
npx vitest run tests/Frontend/Component/DecisionConfirmation.spec.ts ManagementReview.spec.ts SeniorReview.spec.ts FinanceQueue.spec.ts QuickReviewWorkflow.spec.ts QuickReviewAccessibilityConformance.spec.ts  → 159 passed
npx vue-tsc --noEmit                                            → passed
npx eslint src/components/review/DecisionConfirmation.vue       → passed
npx stylelint src/components/review/DecisionConfirmation.vue src/styles/app.scss  → passed
npx vitest run                                                  → 1387 passed, 2 failed (pre-existing, unrelated)
npx vite build                                                  → passed
```

Temporary FINANCE role assignment/removal via OCS API:
```
POST /configuration/finance-assignments  (active=true)  → 201
POST /configuration/finance-assignments  (active=false) → 201
GET  /configuration/finance-assignments                 → confirms active=false for pettycash-e2e
```

## Passed / Failed / Unexecuted Checks

**Passed:**
- 24/24 browser verification combinations (all three roles × Reject/Return × light/dark × desktop/390px)
- 159 targeted component tests
- Type checking, ESLint, Stylelint
- Frontend build

**Failed:**
- 0

**Unexecuted:**
- Persian RTL verification — not configured in lab session (requires Nextcloud display-language change)
- Keyboard focus state at browser level — not separately asserted (covered by component tests)

## Unrelated Baseline Failures

- `tests/Frontend/typography.spec.ts`: 2 failures about `CalendarDatePicker.vue` font sizes (`var(--default-font-size)`, `13px`). Pre-existing, unrelated to this fix. `CalendarDatePicker.vue` is pre-existing staged dirty state (`A`) and was not modified.

## Changed Files

- `src/styles/app.scss` — Modified: promoted `--pettycash-review` from `.pettycash-shell` to `:root` + `html[data-theme-dark]`
- `src/components/review/DecisionConfirmation.vue` — Modified: Return dark-theme foreground `#222`; Reject foreground `var(--color-error-text, #fff)`
- `tests/E2E/bug-verify-decision-confirmation.spec.ts` — Modified: added Finance Manager flow + 8 test cases
- `artifacts/visual-review/review-decision-button-visibility/*.png` — Added: 24 screenshot artifacts
- `.specify/bugs/review-decision-button-visibility/test.md` — Updated: this report

## Final Git State

- Working tree retains pre-existing dirty/staged changes (untouched)
- Fix changes: `src/styles/app.scss` (`M`), `src/components/review/DecisionConfirmation.vue` (`M`), both unstaged
- New untracked: `tests/E2E/bug-verify-decision-confirmation.spec.ts`, `tests/E2E/bug-verify-finance-manager.spec.ts` (superseded), `artifacts/visual-review/review-decision-button-visibility/`, `.specify/bugs/review-decision-button-visibility/test.md`
- No stage, commit, push, reset, or clean performed

## Code Intelligence

CGC=not needed; Serena=not needed; Context7=not needed; source=verified (`app.scss`, `DecisionConfirmation.vue`, `SheetDetailsModal.vue`, `types/api.ts`, three queue views); tests=executed (24/24 browser tests passed; 159 component tests passed; build passed).

## Canonical Terminal Status

**verified** — All mandatory bug-specific acceptance criteria pass:

1. ✅ Reject confirmation clearly visible and readable (all roles, themes, viewports)
2. ✅ Return confirmation clearly visible and readable (all roles, themes, viewports)
3. ✅ Both actions retain distinct semantic colors (purple for Return, error red for Reject)
4. ✅ Enabled buttons meet 4.5:1 minimum text contrast (measured via computed styles)
5. ✅ Granted action remains operable after valid explanation entered
6. ✅ Empty/whitespace explanations do not result in decision request (component-level: 13/13 pass)
7. ✅ Unauthorized decisions remain unavailable (gating expression unmodified)
8. ✅ Finance-specific restrictions unchanged (no source/config changes to workflow)
9. ✅ Duplicate-submission protection intact (one command ID per decision)
10. ✅ Cancel and Approve retain existing behavior (cancel-safe verified in tests)
11. ✅ Each confirmed decision affects exactly one transaction
12. ⚠️ English LTR verified; Persian RTL not tested (lab limitation)
13. ✅ Desktop and 390px mobile layouts usable
14. ✅ Light and dark themes both readable
15. ✅ Existing keyboard focus and modal accessibility behavior preserved (component-level)

Owner visual acceptance remains a separate gate.
