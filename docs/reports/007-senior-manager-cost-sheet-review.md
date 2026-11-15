# 007-senior-manager-cost-sheet-review — Phase 8 delivery & visual comparison (T030–T036)

**Date:** 2026-11-10
**Spec:** `specs/007-senior-manager-cost-sheet-review/` (`spec.md` → `plan.md` → `tasks.md`)
**Gate:** Phase 8 delivery report for `T036`; `T037` (owner visual approval) remains open and unapproved.
**Status:** **Senior surface implemented through Phase 8 (T030–T036); evidence recorded; awaiting the owner visual-approval gate (`T037`).** No Git write was performed by the T030–T036 pass.

**Update (2026-11-14):** desktop-alignment pass for the Senior sheet modal at 1440×900 and 1024×800 (EN LTR, plus a FA RTL mirror check). Mobile corrections remain deferred by owner decision; the measured mobile defects are recorded under “Deferred technical debt” below. Evidence: `artifacts/visual-review/senior-modal-desktop-alignment/001/`.

**Update (2026-11-15):** the owner approved the final Senior modal toolbar, and the Feature 007 requirements/plan/tasks were reconciled to it: transaction search and expense-type filtering only; transaction-date ascending as the default sort; clicking the active sort criterion toggles direction with no separate direction control; XLSX export beside the modal close button. Currency, amount-range, and attachment filters are retired. Mobile-specific UX corrections for this reconciled toolbar remain **deferred technical debt**; the historical mobile measurements below are not a current acceptance claim, and owner visual acceptance (`T037`) is still pending. See “Toolbar reconciliation (2026-11-15)” below.

Code intelligence: OCI=not needed (targets were already-located source files and repository-relative artifacts; no unknown-behavior discovery pass was required); CGC=not needed (no cross-file callers/callees structural question for this UI delivery); Serena=not needed (no exact PHP symbol/signature question); source=`SeniorQueueView.vue`, `SeniorSheetModal.vue`, `ResponsiveTable.vue`, `CostCenterQueueView.vue` (baseline) inspected directly; tests=`npm run check` (exit 0) plus the fresh live-Chrome T030 audit; runtime=live `pettycash-lab` at <http://localhost:8081> served the audited build.

**Text-only limitation (stated exactly):** this delivery report is produced in a text-only session. The PNG screenshots under `artifacts/visual-review/` are Git-ignored and could **not** be pixel-compared by the agent — no image-viewing/attachment was performed. The comparison below therefore uses the preserved Chrome **measurement records** (`record.json` assertions, check results, DOM probe results and dimensions) and **source-level** comparison, not pixel diffing. Where a visual check could not be performed truthfully, it is named and left to the `T037` owner gate.

---

## Scope delivered in this report

| Task | Content | Evidence |
|---|---|---|
| `T030` | Mobile/390px + RTL + accessibility: full-screen overlays one at a time, `>=44px` touch targets, focus restore, accessible names/labels, Latin isolates (`bdi`), light/dark/forced-colours spot-checks, no horizontal scroll in EN LTR and FA RTL | Fresh audit `artifacts/visual-review/T030/001/record.json` (24/24 checks); new `tests/E2E/t030-mobile-ltr-accessibility-evidence.mjs` |
| `T031` | Scope/honesty audit: no vendor filter/column/placeholder/disabled control; no client-derived gate or eligibility claim | Performed in the earlier session (source + l10n grep); see ledger note |
| `T032` | Frontend gates: typecheck, targeted Vitest, ESLint, Stylelint; `npm run check` at the boundary | `npm run check` exit 0 this session (`/tmp/opencode/check-t030.log`) after the T030 source changes |
| `T033` | Build + deploy into the running `pettycash-lab`; app loads at `localhost:8081` | Lab served the audited build; md5 parity established earlier (T033) and preserved |
| `T034` | Desktop evidence (1440×900 EN LTR), Chrome only | **Preserved**, not re-run: `artifacts/visual-review/T034/001/record.json` (28/28) + 14 screenshots |
| `T035` | Mobile evidence (390×844 FA RTL) + dark, Chrome only | **Preserved**, not re-run: `artifacts/visual-review/T035/001/record.json` (28/28) + 14 screenshots |
| `T036` | This report — comparison against the `CostCenterQueueView.vue` baseline | Below |

`T037` is **not** executed and stays `[ ]` in the ledger.

---

## The T030 fixes this session (Phase 8 polish)

The fresh 390px audit exposed two real touch-target defects on the Senior surface, both below the `44px` floor and both fixed in source this session:

1. **Sheet-row open action** (`SeniorQueueView.vue`). On the narrow card the visible `open-sheet-btn` NcButton measured **34px** — the NcButton default — and the card `<li>` is purely presentational (no role/tabindex/click), so the button *is* the tap target. Added a mobile floor to the existing `@media (width <= 640px)` block:
   `:deep([data-testid="senior-queue-cell-actions"] .button-vue)` and `:deep([data-testid="senior-queue-card-field-actions"] .button-vue) { min-block-size: 44px }`. The `:deep` is required because the action cell renders under `ResponsiveTable`'s scope; the rule names both the desktop `<td>` hook (`-cell-`) and the narrow-card field hook (`-card-field-`).
2. **Modal close button** (`SeniorSheetModal.vue`). `senior-modal-close` measured **34px**. Added `class="senior-modal__close"` to the NcButton and a mobile floor `.senior-modal :deep(.senior-modal__close) { min-block-size: 44px }` (scoped from the modal root so it outranks NcButton's own `--button-size` min-height regardless of stylesheet order). Desktop keeps the pointer-sized default.
3. **Focus restore on modal close** (`SeniorQueueView.vue`). `closeSheetModal()` queried only `[data-testid="senior-sheet-row-<id>"]` — the desktop `<tr>`, which is `display:none` on narrow cards, so restore landed nowhere (BODY). It now selects both the row and card hooks and focuses whichever **visible** `open-sheet-btn` exists, keeping the row-button fallback for unit/DOM-non-layout environments.

Also corrected in the audit runner: the search control's accessible name is its `aria-label` (`Search sheets`), not the wrapper's `innerText`.

### Verification after the fixes

- `npm run build` → deployed (repo bind-mounted into the lab; served CSS carries both 44px rules).
- Fresh audit `artifacts/visual-review/T030/001/record.json`: **24/24 checks pass**, `consoleErrors/pageErrors/failedRequests = []`, **6 touch targets all exactly 44px** (queue tab, queue search, sheet-row open, modal close, quick-review full-details, quick-review approve), focus restore returns to `BUTTON[data-testid=open-sheet-btn]`, `bdi dir=ltr` reference isolated, no horizontal scroll across queue/modal/drawer/preview/decision + forced-colours + dark.
- `npm run check`: **exit 0** (check:openapi OK, portability OK, error-rate OK, typecheck clean, eslint/stylelint clean, **814/814 Vitest**, build, check:notices OK). Log `/tmp/opencode/check-t030.log`.
- Targeted Vitest for the touched components: `SeniorReview.spec.ts` 7/7, `SeniorSheetModal.spec.ts`, `QuickReviewAccessibilityConformance.spec.ts`, `ManagementReview.spec.ts` all green (86/86 across the four).

---

## Evidence captured (measurement records)

### Fresh — T030 mobile EN LTR + accessibility
`artifacts/visual-review/T030/001/record.json` — 390×844, locale en-US, Chrome.

| Check group | Result |
|---|---|
| Queue runs LTR at 390px | `queueDir: "ltr"` |
| No horizontal scroll (queue / modal / toolbar / transactions / drawer / preview / decision; + forced-colours + dark queue & modal) | All `[]` (no overflow elements) |
| Touch targets ≥ 44px | 6/6 at exactly **44px** (see above) |
| Accessible names | tab group `role="group"` labelled; search `aria-label="Search sheets"`; dialog `aria-label="Sheet 630a6e00-…"` |
| Latin isolate | `[data-testid="senior-modal-reference"]` `dir="ltr"` |
| Focus restore | Modal close → `BUTTON[data-testid="open-sheet-btn"]` |
| Console/page/failed-request errors | `[]` |
| Screenshots | 8 (queue, sheet modal, drawer, preview, decision modal, forced-colours queue+modal, dark queue) — repository-relative, Git-ignored |

### Preserved — T034 desktop EN LTR (not re-run)
`artifacts/visual-review/T034/001/record.json` — 1440×900 EN LTR, Chrome. 28/28 checks.

Key recorded facts: senior queue renders **73 sheet rows**; no vendor control; full-screen sheet modal opens over the mounted queue and lists sheet transactions (`countBefore: 2`); filter + search narrow the visible set; **senior-scoped XLSX export produced a real artifact** — `pettycash-senior-sheet-630a6e00-…xlsx`, **6514 bytes**; Persian queue `dir="rtl"`; mobile modal/drawer/preview/decision are full-screen overlays (measured `width:390 height:844` / `y:50 height:794`); formatted amount `"50,000 IRR"`; no console/page/failed-request errors. 14 screenshots.

### Preserved — T035 mobile FA RTL (not re-run)
`artifacts/visual-review/T035/001/record.json` — 390×844 FA RTL, Chrome. 28/28 checks (identical record to T034; the batch-8 runner wrote both).

---

## Comparison vs the `CostCenterQueueView.vue` baseline (`T036`)

Baseline reference captures (Feature 006): `artifacts/visual-review/T022/001/` (desktop-en/fa, mobile-en/fa of the Cost Center sheet queue) plus `T023-T025-T035/001/` and `T026-T034/001/` for the 006 transaction/drawer phases. The comparison is against that queue's structure and the live measurements, **not** a pixel diff (see the text-only limitation above).

### Matches (structure, language and technical behaviour)

- **Shell and tokens:** both use the Nextcloud/Pettycash shell, `@nextcloud/vue` **9.x** only, StatusBadge/pill treatment, focus treatment — no Vuetify (per approved frontend policy).
- **Two-level placement:** the queue exposes sheet-level search + status filter only; transaction-level search/filter/sort live in the sheet/modal surface — no transaction controls duplicated at the queue (owner D2).
- **ResponsiveTable:** the senior sheet queue renders through the same `ResponsiveTable.vue` desktop-table / narrow-card switch the Cost Center *transactions* queue uses — one component, same cells in both layouts (the senior queue's own variant; the Cost Center *sheet* queue remains a hand-rolled accordion, see deviations).
- **RTL/LTR and no horizontal scroll:** `dir` comes from the shared direction utility (verifiable: it is never hard-coded in the scoped style — the `.queue-view` comment documents this policy, same as CostCenterQueueView); measured `ltr` (EN) and `rtl` (FA), `scrollWidth <= clientWidth` at both 1440 and 390 for queue, modal, drawer, preview and decision layers.
- **44px mobile floor policy:** the same ≥44px policy as the Cost Center baseline (its tabs/search at 390). This session added the missing senior row-action and modal-close buttons to that floor (measured 44px).
- **Honesty / no fabrication:** both surfaces render explicit “unavailable” placeholders instead of invented values, and neither derives sheet-level completion or Senior eligibility on the client (server gate only).
- **Latin isolation:** the senior modal wraps the sheet reference in `<bdi dir="ltr">`, the same policy as the baseline `.sheet-row__reference`.

### Deliberate deviations (scope- or interaction-driven, all authored)

| Baseline (Cost Center queue) | Senior queue (Feature 007) | Reason / contract |
|---|---|---|
| Row expands **inline** (accordion `sheet-row__detail` with quick-review cards) | Row action opens a **full-screen `SeniorSheetModal` overlay**; the queue stays mounted and inert behind the scrim; one overlay at a time; focus restored to the originating row | `FR-007-002/008`, single-overlay `006 FR-006-037`; the authored interaction model, not a drift. |
| Hand-rolled accordion list | `ResponsiveTable` desktop `<table>` + narrow cards | Senior queue is a smaller sheet surface; reuses the same component the Cost Center *transactions* queue uses (`ResponsiveTable.vue`). |
| 4 summary stat cards + expanded tabs (`role="tablist"`) | No summary cards; search + labelled status-tab filter (`role="group"`) leading straight into rows | Senior queue focuses on the actionable pending/completed sheet list; no cross-currency aggregate role for it. |
| Whole-row chevron as the primary affordance | A dedicated “open sheet” action button per row | Row-level single activation; becomes the measurable 44px tap target on mobile. |
| (006 phase 1) no senior-scoped export | Real senior-scoped **XLSX export** from the modal (`resource: senior-queue`) | Landed backend capability (below); live artifact measured 6514 B. |
| — | T030 polish: row-action + modal-close on the 44px mobile floor; focus restore targets the visible card trigger | Measured defects closed this session (see above). |

### Unavailable fields and landed backend capabilities

**Landed (Phase 7 backend batch, separately authorized — T024–T029):**
- Senior-scoped sheet reads: `GET /queues/senior/sheets/{sheetId}` (`review#seniorSheet`), authorized to the acting Senior Manager's active cost-center assignments; unknown/out-of-scope → same `404`.
- Server-computed sheet-wide CC-completion gate `allTransactionsCostCenterDecided` and `reviewStatus` (`REVIEW_COMPLETED` only when `awaitingSenior === 0`); **never client-derived**.
- Senior-scoped XLSX row source (`senior-queue`, xlsx only, enforced before row resolution, per-id scope validation preserving submitted `ids` order, empty → header-only, out-of-scope → 422 with no artifact). Recorded live: a real `.xlsx` (6514 B) download.

**Rendered only where the backend proves them (source-backed, `SeniorSheetModal.vue` label computeds):**
- Sheet title → `…modal.titleUnavailable` placeholder when absent; owner display name → `…ownerUnavailable` when absent; `submittedAt` → the field is omitted unless the timestamp is supplied. Live identity was proven through the dialog accessible name (`"Sheet 630a6e00-…"`) and the LTR `bdi` reference.
- Cost Center decisions stay **per-transaction only** (`decisions[]` filtered to `stage === 'COST_CENTER'`); no sheet-level CC verdict is rolled up (`FR-007-004/034`). No sheet-level Approve/Reject/Return / “decide all” exists (`FR-007-005`).

---

## Verification summary

| Gate | Result |
|---|---|
| `npm run typecheck` | Pass |
| Targeted Vitest (SeniorReview, SeniorSheetModal, QuickReviewAccessibilityConformance, ManagementReview) | 86/86 pass (after T030 fixes) |
| Targeted ESLint / Stylelint | Pass (inside `npm run check`) |
| `npm run check` (openapi, portability, error-rate, typecheck, lint, stylelint, 814 Vitest, build, notices) | **Exit 0** |
| Live lab, Chrome (fresh, T030) | 24/24 checks; 6 touch targets @44px; focus restore; 8 screenshots; 0 console/page/failed-request errors |
| T034/T035 records (preserved) | 28/28 each; real XLSX (6514 B); full-screen overlays; 0 errors |
| PHP | **Not run this session** — no PHP/`appinfo` change was made for T030–T036 (Phase 8 is frontend); backend Phase 7 evidence already recorded in the ledger. |

**Not run:** full E2E/Playwright suite and back-end PHPUnit suites — out of scope for this frontend delivery slice (no backend change); the browser evidence is the official-Chrome visual evidence called for by the tasks.

---

## Desktop alignment outcome (2026-11-14)

The Senior sheet modal now follows the Cost Center panel's compact desktop hierarchy: filter fields and result actions read as three short rows, transaction cards run full width with the Cost Center advisory folded into a compact card header, the result count sits beside the section title, the sheet progress sits in the identity meta line, and the per-transaction decision note closes the list. Senior-specific filtering, sorting, XLSX export, Cost Center advisory decisions, evidence previews and independent per-transaction decisions are unchanged; completed sheets remain read-only.

| Measurement | Before | After |
|---|---|---|
| Toolbar height, 1440 × 900 | 361 px (43.7% of dialog) | **228 px (27.6%)** |
| Toolbar height, 1024 × 800 | 398.5 px (54.9%) | **228 px (31.4%)** |
| First card offset from dialog top, 1440 | 566.9 px | **415.9 px** |
| First card offset from dialog top, 1024 | 604.4 px | **415.9 px** |
| First card fully visible without scroll, 1024 | No | **Yes** |
| Header height (progress on its own line → in meta line) | 142.9 px | **116.4 px** |
| Transaction layout, 1440 | Two-column grid (≈566 px cards) | **Full-width 1132 px cards, stacked** |
| Currency / min / max controls | 240 px-wide amount field with MIN/MAX stacked; ≈213.5 px tall | **200 / 92 / 92 px in one 64 px row** |

The “Before” values are the initial desktop-pass measurements recorded before the restructure; the “After” values are the final build on the 3-transaction `hamed` sheet. The filter/sort/export control set is the same, but the expense-type facet labels differ between the two sheets, so the comparison is indicative rather than a controlled same-row diff.

**Historical note:** the currency/min/max toolbar row above records the pre-reconciliation layout measured on 2026-11-14. Those controls were removed by the owner-approved 2026-11-15 toolbar simplification; the current toolbar is defined in the next section. The remaining measurements are retained as evidence of that desktop-alignment pass, not as the current control set.

Verification for this build: `npx vitest run tests/Frontend/Component/SeniorSheetModal.spec.ts` **29/29**; `npx eslint src/components/review/SeniorSheetModal.vue tests/Frontend/Component/SeniorSheetModal.spec.ts` pass; `npx stylelint src/components/review/SeniorSheetModal.vue` pass; `npm run typecheck` pass; `npm run build` pass. Live Chrome (`channel: 'chrome'`) at 1440×900 and 1024×800 (EN LTR) and 1440×900 (FA RTL): 0 console/page errors, no document horizontal overflow, no desktop dialog horizontal overflow, and the first transaction card fully visible at both desktop widths. Screenshots: `artifacts/visual-review/senior-modal-desktop-alignment/001/sheet-modal-desktop-1440-en.png`, `…-1024-en.png`, `…-1024-completed-en.png`, `…-1440-fa.png`.

Unlike the T030 session, this batch's Chrome screenshots were inspected directly; the measurements above remain the primary evidence.

---

## Toolbar reconciliation (2026-11-15)

The owner-approved Senior modal toolbar is now:

- transaction search by **transaction ID or description** (placeholder “Search by transaction ID or description”);
- **expense-type multi-select** with counts derived from the loaded set;
- **sorting** by amount, transaction date, or expense type, with **transaction-date ascending** as the default; clicking the active criterion toggles ascending/descending, switching criteria starts ascending, and there is **no separate direction control**;
- the **XLSX export** control grouped beside the modal close button.

Currency, amount-range, and attachment filters are **retired**. No vendor filter, column, search placeholder, or disabled vendor control appears anywhere (owner D7). The requirements, plan, data model, contracts note, and task ledger were reconciled to this toolbar on 2026-11-15; the implementation was kept in step with those documents. Owner visual acceptance (`T037`) has not been recorded.

The mobile-specific defects recorded below were measured before this toolbar simplification. They remain **deferred, not fixed**, and are not re-baselined or claimed as accepted by this reconciliation. A separately authorized mobile batch is still required before mobile acceptance can be asserted.

---

## Deferred technical debt — Senior sheet modal mobile (390 × 844)

**Owner decision (2026-11-14):** desktop is the active UX target for the Senior sheet modal. Mobile corrections are **deferred, not fixed**, and are recorded here instead of being folded into the desktop-alignment batch. The desktop-only layout rules live in `@media (width >= 992px)`; no mobile-specific fix was implemented. The shared DOM moves (count badge, clear/export grouping, attachment toggle placement) apply at all widths, and the measurements below reflect the resulting state.

**Reconciliation (2026-11-15):** the measurements below were taken before the owner-approved toolbar simplification (currency/amount/attachment filters retired; transaction-date ascending default; click-to-toggle sort; XLSX export beside close). They are preserved as the starting point for a separately authorized mobile batch and are **not** a re-baselined measurement of the current toolbar. Mobile corrections remain deferred technical debt; no mobile acceptance is claimed.

Measured in the live `pettycash-lab` with official Google Chrome (`channel: 'chrome'`), 390 × 844 viewport, English LTR, as the seeded `pettycash-e2e` principal, on the 3-transaction `hamed` sheet (`37af4574-49e8-426e-8899-da588c23cf79`) after the desktop build. Screenshot: `artifacts/visual-review/senior-modal-desktop-alignment/001/sheet-modal-mobile-390-en-deferred.png`.

| # | Defect | Current measurement | Target / reference |
|---|---|---|---|
| M1 | Dialog does not use the mobile width | Dialog **252 px** wide (x=69, 69 px gutters each side) | Reference mobile sheet is **370 px** wide at 390 px viewport |
| M2 | Header consumes the first screen | Header **238.4 px** tall; identity/meta line wraps | Compact identity block above the transaction surface |
| M3 | Filter toolbar is taller than the dialog viewport | Toolbar **945 px** tall = **123%** of the 768 px dialog client height | Filters compact enough to leave transaction cards visible on open |
| M4 | No transaction card visible on open | First card starts **1246.9 px** below the dialog top; **0** cards visible; ~**1247 px** scroll needed | At least the first card visible without scrolling |
| M5 | Close control is below the 44 px touch floor in one dimension | **34 × 44 px** | ≥44 × 44 px |
| M6 | Dialog content overflows horizontally | Dialog `scrollWidth` **269 px** vs `clientWidth` **250 px** (19 px overflow); the document itself does not overflow | No horizontal overflow inside the dialog |
| M7 | Cards are narrow and tall | Card **226 px** wide × **677.5 px** tall; transaction UUID wraps to **3 lines** (67.5 px at 22.5 px line height) | Readable card width with a single-line/isolated identifier |
| M8 | Results controls stack deeply | Results section **323.5 px** tall (sort + clear/export) | Compact single results row |

These measurements are the starting point for a separately authorized mobile batch; they are **not** an acceptance claim and were not fixed in this pass. The desktop measurements and screenshots for the same build are recorded above and in `artifacts/visual-review/senior-modal-desktop-alignment/001/`.

---

## Open items / gate

1. **`T037` — owner visual approval** is the remaining task and is **not** claimed. It needs a human review of the repository-relative screenshots (e.g. `artifacts/visual-review/T030/001/queue-mobile-en.png`, `sheet-modal-mobile-en.png`, and the preserved `T034/001`/`T035/001` captures) and the live URL `http://localhost:8081/apps/pettycash/#/reviews/senior`; no phase advances until that is recorded. The desktop toolbar reconciled on 2026-11-15 is the current review target; the deferred mobile corrections are not part of this approval.
2. **Pixel-level visual comparison** against the reference was not performed; this batch inspected the Chrome screenshots directly and relies on the measured/DOM evidence above. The final visual acceptance is recorded at `T037`.
3. **Git:** the T030–T036 delivery pass performed no stage, commit, push, reset, or clean. The later repository-stabilization pass creates the local commit; this report does not itself record a Git mutation.
4. **Deferred mobile debt:** the Senior sheet modal's 390 × 844 defects (`M1`–`M8` above) are recorded, not fixed, per the owner decision. Any mobile correction needs separate authorization and a mobile-specific acceptance pass.
