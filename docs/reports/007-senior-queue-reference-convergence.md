# Feature 007 — Senior Manager Queue UI: visual convergence to the Manus reference

**Date:** 2026-11-11
**Scope:** Bounded visual convergence of `src/views/reviews/SeniorQueueView.vue` to the owner-approved reference `docs/frontend-redesign/references/senior-managment/Pettycash_Senior_Manager_Queue_Reference`. Implementation + technical verification only. **T037 is NOT marked complete** and no Git writes were performed.
**Update (2026-11-15):** this queue-convergence report is preserved as recorded. The Senior **modal** toolbar was separately reconciled to the owner-approved 2026-11-15 simplification (transaction search + expense-type filtering; transaction-date ascending default; click-to-toggle sort with no separate direction control; XLSX export beside close; currency/amount/attachment filters retired). Mobile-specific UX corrections remain deferred technical debt and are not an acceptance claim. The reference directory is committed as a design reference; the `.tmp-repro/` probes mentioned in §2 are transient investigation artifacts and are deliberately excluded from the committed tree.
**Intended-behavior authority:** `specs/007-senior-manager-cost-sheet-review/`, contracts, D1–D9. The Manus reference is a **visual** reference only; no placeholder data, fake logic, or Vuetify was copied.

## 1. Reference → production mapping

| Reference element | Production implementation | Source of truth for content |
| --- | --- | --- |
| Header eyebrow / title / subtitle / role badge | `reviews.seniorSheet.eyebrow` ("Second-stage review queue"), page title "Senior queue", subtitle (summary of scope), role badge "Senior Review" | New l10n keys + existing route title |
| Responsibility banner (title + body, 12px radius) | `.responsibility` block with `reviews.seniorSheet.banner.title/body` + `banner.caption` | New l10n keys |
| Two stats cards | `.summary` grid with two `.stat` cards — sheets awaiting decision (count + "Across {n} transactions") and transactions in queue (count + "In {n} sheets") | `awaitingSheetCount` / `awaitingTransactionCount` computed from `fetchSeniorQueue` response |
| Toolbar: search + 3 tabs | Search-by-sheet-reference input + All / Awaiting / Completed tabs (T019, local-only over the proven-complete set) | existing queue tab/search logic |
| Columns: Sheet, Purchaser, Pending, Amount, Latest activity, Status, Actions | `sheetColumns()` in exactly this order via `ResponsiveTable` (FR-071 / T389) | reference order adopted |
| Title-first row identity | `sheet-title` (human-readable title, 16px/600) over `sheet-reference` (shortened id, muted) with purchaser initials-avatar + owner name | `SeniorManagerSheetReview.sheet.title` / `ownerDisplayName` from `GET /queues/senior/sheets/{id}` (see §4) |
| Owner column | initials avatar (26px circle, inherited 16px) + display name | `ownerDisplayName` |
| Badges / status | `status-badge` pending/completed; completed sheet renders read-only + no open action | `reviewStatus` server-supplied only (owner D5, FR-007-017) |
| Responsive narrow cards + `≤ 640px` stacking, 44px tap targets | `@media (width <= 640px)` single-column stacking + existing `:deep()` touch-target rule | FR-071 guard |
| RTL | `:dir="$direction"` binding (same policy as `CostCenterQueueView`); Latin id isolated with `direction: ltr; unicode-bidi: isolate` (T030) | `direction.ts` / UI-PUR-001 |

## 2. Files changed (this mission)

Tracked, modified:
- `src/views/reviews/SeniorQueueView.vue` — header/banner/stats/toolbar, title-first identity row + owner column, badge rendering, media query, RTL `:dir`; bounded 4-worker review prefetch; row-cell/status styles moved to `:deep()` so the `h()`-rendered `ResponsiveTable` cells are actually styled; FR-071-compliant font sizes.
- `l10n/en.json`, `l10n/fa.json`, `l10n/en.js`, `l10n/fa.js` — ten new keys (1:1 parity; `fa.js` plural rule `n > 1` preserved):
  `reviews.seniorSheet.eyebrow`, `.owner`, `.banner.title`, `.banner.body`, `.banner.caption`, `.stats.label`, `.stats.sheetsAwaiting.label`, `.stats.sheetsAwaiting.hint`, `.stats.transactionsPending.label`, `.stats.transactionsPending.hint`.

Untracked (Feature 007 tree, unchanged additions argued here only where touched):
- `tests/Frontend/Component/SeniorReview.spec.ts` — completed-review test reworked to deliver `REVIEW_COMPLETED` through the OCS mock of `GET /queues/senior/sheets/sheet-1` (see §5); 7 senior-queue tests pass.
- `tests/E2E/senior-queue-visual-convergence.mjs` — official-Chrome evidence runner (36 checks, 4 screens, `record.json`; `waitForIdentity()` waits for the prefetched title; RTL banner-within-viewport fixed to compute width in-page).
- `artifacts/visual-review/senior-queue-converge/001/` — screenshots + `record.json`.
- `.tmp-repro/senior-row-scope-probe.mjs`, `.tmp-repro/senior-sheet-probe.mjs` — read-only live probes documenting the scope-id and landed-backend findings.

## 3. Layout values adopted / adapted

Adopted: responsibility banner radius `12px`; two stat cards in a 2-column grid on desktop (`565px 565px`) stacking to one column on mobile (`366px`); header eyebrow → title → subtitle → role badge hierarchy; title-first row (title over reference) with owner avatar+name; column order `[Sheet, Purchaser, Pending, Amount, Latest activity, Status, Actions]`; `max-width: 640px` breakpoint.

Adapted (documented deviations from the reference's exact pixels):
- Title type: 16px/600 (reference 15px/400) — kept ≥ 16px floor and stronger weight to preserve hierarchy.
- Reference line: 16px muted, weight 400 (reference rendered it smaller) — the FR-071 floor forbids sub-16px user-facing text; hierarchy is carried by weight + `--color-text-maxcontrast`, matching the approved `CostCenterQueueView` baseline, which has **no** sub-16px declarations.
- Owner-avatar initial: no explicit `font-size` (inherits 16px) instead of ~11px — FR-071 floor; the avatar is `aria-hidden` decorative (the real name is adjacent at 16px).

## 4. Production data behind each KPI / field

All values come from the approved contract; the reference's static mock numbers were never copied.
- Sheets awaiting decision: **73** (lab) = `awaitingSheetCount`. Hint "Across 76 transactions" pairs the count with `awaitingTransactionCount`.
- Transactions in queue: **76** = `awaitingTransactionCount`. Hint "In 73 sheets".
- Row title: `SeniorManagerSheetReview.sheet.title` (real seeded value, e.g. **"B4 quick review mw3x4qfm"**) — never the raw UUID.
- Reference: shortened `sheetId` prefix (e.g. `630a6e00…`), the readable reference per the same policy as `CostCenterQueueView`.
- Owner: `ownerDisplayName` (e.g. **"PettyCash E2E"**) + initials avatar.
- Pending: `transactionCount`. Amount: `submittedTotal` formatted. Latest activity: `latestActivityAt` formatted (`formatSheetDateTime`).
- Status: server-supplied `reviewStatus` only → `PENDING_REVIEW` (actionable) vs `REVIEW_COMPLETED` (read-only, `readonly-note`, no open action).
- Actions: "Open sheet" → `SeniorSheetModal`; completed sheets expose no action.

Prefetch gap resolved in-scope: titles/owners were blank at render because `seniorSheetReviews[sheetId]` populated only on modal open. `refresh()` now calls `clearSeniorSheetReviews()` then a bounded 4-worker `prefetchSeniorDetails` over the existing store `fetchSeniorSheetReview` (in-flight de-duplicated, per-sheet failure isolated — FR-007-014), mirroring the approved `CostCenterQueueView` `loadManagerDetail`/`prefetchManagerDetails`. **No API widening; one unavailable sheet is isolated.**

## 5. Verification

Gates (all executed, results as listed):
- `npm run check` — **GREEN end-to-end**: `check:openapi`, `check:portability`, `check:error-rate`, `typecheck`, `lint`, `stylelint`, full vitest (46 files / **818 passed**), production build, `check:notices`.
- Typography floor (`tests/Frontend/typography.spec.ts`, part of FR-071): was **red** after convergence (15px reference, 11px avatar); fixed to green (5/5) by raising both to the 16px token/inheritance. This gate only runs under full vitest — targeted stylelint had missed it; now closed.
- Targeted vitest (updated regression set): SeniorReview (7), SeniorSheetModal (23), ManagementReview (47), fr071NarrowViewport (3), l10nCatalog (en/fa JSON↔JS parity + static keys), localeFormatting, SeniorSheetQueue (33), RtlAccessibility — **8 files / 142 passed**.
- Live official-Chrome runner `tests/E2E/senior-queue-visual-convergence.mjs` — **36/36 checks passed**, after `npm run build` so the served bundle was current (stale bundle caused earlier false failures). Fresh bundle rebuilt for final run.
- Runtime probes: `GET /ocs/v2.php/apps/pettycash/api/v1/queues/senior/sheets/630a6e00-…` returns 200 with real `title`/`ownerDisplayName`; live row DOM confirmed cells carry `ResponsiveTable`'s scope id `data-v-0a09ebbd` vs the view's `data-v-8a8d9245`, proving the need for `:deep()`.

## 6. Screenshots (filesystem artifacts)

`artifacts/visual-review/senior-queue-converge/001/`
- `queue-desktop-en.png` — desktop EN LTR
- `queue-desktop-fa.png` — desktop FA RTL
- `queue-mobile-fa.png` — mobile 390×844 FA RTL (stats single column)
- `sheet-modal-desktop-en.png` — Senior modal opened over the converged queue (modal was not reshaped; open-path capture)
- `record.json` — all measurements (header/banner/stats/toolbar/headers/rowCount/firstRow; 0 console/page/asset errors; `writes=8` GET-excluded).

## 7. Remaining deviations (known + accepted)

- Reference line and owner-avatar initial are rendered at 16px rather than the reference's ~15px/11px, forced by the approved FR-071 floor. Hierarchy preserved via weight/color.
- Static mock copy from the reference is replaced by localized production strings; reference's illustrative transaction rows are not reproduced as fake data.
- No transaction-level control in the queue (production constraint FR-007-041: queue is sheet-level; per-transaction decisions live in the modal).
- Stat-card/banner decorative flourishes may differ in exact spacing from the reference (not pixel-perfect, visually converged).

## 8. Git status (read-only snapshot, no mutation)

Working tree is dirty with pre-existing Feature 007 work (uncommitted `SeniorSheetModal.vue`, `useSeniorQueue.ts`, senior backend tests, `.specify/bugs/*`, `.tmp-repro/*`, `docs/reports/007-senior-manager-cost-sheet-review.md`, reference docs) plus expected `npm run build` churn (old-hash `js/`/`css/` chunks deleted, new-hash chunks untracked) and `THIRD_PARTY_NOTICES.md` build sync. This mission's tracked edits: `SeniorQueueView.vue` + 4 l10n files. **No stage, commit, push, reset, or clean was run; all working-tree changes and untracked artifacts are preserved.**

## 9. Status of T037

**T037 remains pending.** Implementation and technical verification for the visual-convergence objective are complete per the granted scope; marking the task complete / closing it was explicitly out of scope and was not performed.

## Code intelligence

Code intelligence: OCI=not needed (target and analogous source already located); CGC=not needed (no cross-file structural question remained unresolved; prefetch mirrored verified in-source `CostCenterQueueView`); Serena=not needed (no exact PHP symbol/signature question); source=`src/views/reviews/SeniorQueueView.vue`, `src/views/reviews/CostCenterQueueView.vue`, `src/stores/reviews.ts`, `src/services/reviewsApi.ts`, `src/components/review/SeniorSheetModal.vue`, `src/types/api.ts`, `src/composables/useSeniorQueue.ts`, `tests/Frontend/Component/SeniorReview.spec.ts`, `tests/Frontend/typography.spec.ts`; tests=`npm run check` (818/818), targeted vitest (142/142), live E2E 36/36 (official Chrome) — all executed.
