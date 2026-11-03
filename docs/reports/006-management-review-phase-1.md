# 006-management-review — Phase 1: sheet-first Cost Center queue

**Date:** 2026-10-29, refined 2026-10-30
**Spec:** `specs/006-management-review/` (`spec.md` → `plan.md` → `tasks.md`)
**Gate:** `T022` — Phase 1 owner visual gate
**Status:** **Phase 1 implemented, refined and re-verified; awaiting explicit owner visual approval.** No Phase 2 work has started. No Git write was performed.

Code intelligence: OCI=`index_status` only (11,871 chunks, hybrid `qwen3-embedding:8b`, usable, 141 failed embedding batches remain; no semantic lookup was needed for already-identified files); CGC=not needed for this UI slice; Serena=not re-invoked in the refinement pass (no PHP signature changed); source=`CostCenterQueueView.vue`, `useSheetQueue.ts`, `StatusBadge.vue`, `ManagementReviewService.php` inspected directly; runtime=live OCS responses plus Chrome.

## What was delivered

| Path | Change |
|---|---|
| `specs/006-management-review/spec.md` | New. 35 `FR-006-*` requirements, 4 user stories, 7 success criteria, capability classification, workflow-conflict record, Phase 1/2 gate. |
| `specs/006-management-review/plan.md` | New. Complete-set rule, de-duplication, presentation decisions D1–D5, visual/test plan. |
| `specs/006-management-review/tasks.md` | New. `T001`–`T022`; `T001`–`T021` checked with their evidence, `T022` open at the gate. |
| `src/composables/useSheetQueue.ts` | New. Completeness guard, transaction de-duplication, sheet projection, per-currency totals, latest activity, search, sort, aggregate totals. Pure functions; no network calls. Unchanged by the refinement. |
| `src/views/reviews/CostCenterQueueView.vue` | Sheet-first queue, refined 2026-10-30: six Manus column positions, warning status pill, honest unavailable field states, compact scope banner, denser desktop table, 2 × 2 mobile metrics, RTL-safe alignment, and the preserved transaction fallback. |
| `l10n/{en,fa}.{json,js}` | 550 keys per locale, parity true. The 125 new keys were inserted beside their existing sections; the `fa` catalogue keeps the HEAD order for every pre-existing key and the `nplurals=2; plural=(n > 1);` rule. |
| `tests/Frontend/Composables/SheetQueue.spec.ts` | New. 19 unit tests: completeness, duplicates, truncation, threshold, blank sheet reference, multi-currency, latest activity, search/sort, aggregate totals. |
| `tests/Frontend/Component/ManagementReview.spec.ts` | Updated to the sheet-first default and, in the refinement, to the six row fields, warning badge, and removal of the latest-activity column. 46 tests, none deleted or skipped. |
| `.specify/bugs/cost-center-queue-duplicate-transactions/assessment.md` | New bug assessment. Confirms the duplicated queue response and the pagination defect, with a remediation proposal that was **not** applied. |

No file under `lib/`, `appinfo/`, `tests/Backend/`, `specs/001-pettycash-v1-core/`, or any contract was modified. No backend, API, financial, or permission behavior changed.

## Review

**URL:** <http://localhost:8081/apps/pettycash/#/reviews/cost-center>

Sign in as the seeded Cost Center Manager (`pettycash-e2e`; credentials in `.env.testing`). The interface language was restored to English after the Persian capture. The **Transaction list** button in the header opens the preserved fallback.

Lab state observed during capture: the queue returned **144 rows for 72 unique transaction IDs**; the sheet view de-duplicates to **72 sheets / 72 manager-relevant pending transactions**, total **4,004,450,000 IRR**. The fallback table still renders the server's rows unchanged. See “Duplicate response and pagination” below.

## 2026-10-30 refinement

The first Phase 1 build was compared against the Manus queue references and adjusted without changing functional boundaries.

- **Row identity:** the sheet title and identifier now occupy the first column as two lines — “Sheet title unavailable” with the short UUID and cost-centre code beneath. The title position is no longer replaced by the identifier.
- **Purchaser:** rendered in its own column with an avatar placeholder and “Purchaser unavailable” instead of being omitted.
- **Manager-specific work:** column labels are “Your transactions” and “Relevant amount”; only the manager's assigned pending transactions and per-currency sums are shown.
- **Review status:** a warning-style pill labelled “Needs attention” (`StatusBadge status="COST_CENTER"`), visually distinct from the neutral page chrome.
- **Submitted:** rendered as “Date unavailable”. The previous “Latest activity” column was removed because it is not a submission date.
- **Banner:** reduced to an icon, “Focused on your responsibility”, the assigned-only explanation, and the cost-centre scope chips. The Senior Review rule and the technical field-availability list were removed from the UI; the limitations live in this report instead.
- **Summary cards:** “Sheets needing attention”, “Transactions to review”, “Relevant amount”, and a visibly disabled “Reviewed this period” card.
- **Density and typography:** tighter table padding, balanced seven-column proportions, smaller card padding and type scale, 2 × 2 summary cards and stacked metric labels on mobile, and start-aligned values in RTL.
- **Preserved boundaries:** the transaction fallback, inline sheet disclosure, transaction-level handoff, disabled Reviewed tab, completeness guard, and client-side de-duplication are unchanged. No sheet-level Approve/Reject/Return control was added.

## Visual evidence

Official Google Chrome via Playwright `chrome` channel. All paths are repository-relative and Git-ignored.

### Four approval screenshots

| File | Shows |
|---|---|
| `artifacts/visual-review/T022/001/queue-desktop-en.png` | Desktop, English LTR, 1440 × 1100: header, compact banner and scope chips, summary cards, toolbar, and the full six-field sheet row. |
| `artifacts/visual-review/T022/001/queue-desktop-fa.png` | Desktop, Persian RTL, 1440 × 1100: mirrored columns, Persian digits, Jalali dates, and the same six fields. |
| `artifacts/visual-review/T022/001/queue-mobile-en.png` | Mobile, English LTR, 390 × 844: header, banner, 2 × 2 summary cards, toolbar, and the first rows. |
| `artifacts/visual-review/T022/001/queue-mobile-fa.png` | Mobile, Persian RTL, 390 × 844: mirrored layout and stacked metrics. |

### Supplementary evidence

| File | Shows |
|---|---|
| `artifacts/visual-review/T022/001/queue-mobile-en-overview.png` | 390 × 1600 overview of the full mobile stack. |
| `artifacts/visual-review/T022/001/queue-mobile-fa-overview.png` | 390 × 1600 Persian overview. |
| `artifacts/visual-review/T022/001/queue-mobile-en-rows.png` / `queue-mobile-fa-rows.png` | Sheet cards scrolled into view. |
| `artifacts/visual-review/T022/001/queue-desktop-en-transactions.png` | Preserved transaction fallback, page 1 of 2. |
| `artifacts/visual-review/T022/001/queue-desktop-en-expanded.png` | Inline sheet disclosure and the single transaction-level action. |

Measured in Chrome:

- **72 sheet rows** in every capture; `dir="ltr"` for English and `dir="rtl"` for Persian.
- **No horizontal scroll**: app container and body `scrollWidth == clientWidth` at 1440 and 390.
- Long amounts fit their mobile cards: summary value 148/148 px, row amounts 82/82 px (English) and 80/80 px (Persian).
- No console or page errors.
- Live assertions: all six row fields present; warning chip present; banner text contains only the manager-scope explanation and chips; fallback shows 25 rows with `1 / 2` pagination; expanded sheet shows one transaction review button and no sheet-level Approve/Reject/Return.

## Duplicate response and pagination

The duplication visible in the fallback is a **backend response artifact**, not a Vue rendering problem.

- `ManagementReviewService::getUserRoles()` appends one role entry per active assignment.
- `getCostCenterQueue()` then iterates that list and, for every `CostCenterManager` entry, re-fetches **all** assignments and appends every managed cost-centre ID again. With two assignments the ID list becomes `[DEV-T060, E2E-CC-1, DEV-T060, E2E-CC-1]`, so each cost centre's transactions are fetched twice.
- `total` is the duplicated row count; the page slice is applied after the merge. The offset is also applied per cost centre inside the repository query and again globally.

Live evidence:

```json
{
  "total": 144,
  "items": 144,
  "uniqueIds": 72,
  "duplicateGroups": 72,
  "multiplicity": 2,
  "uniqueSheets": 72
}
```

Pagination at the fallback's 25-row page size:

```json
{
  "page1": { "total": 50, "length": 25, "uniqueIds": 13 },
  "page2": { "total": 50, "length": 25, "uniqueIds": 13 },
  "overlapBetweenPages": 0
}
```

Only **two pages** are advertised, so **26 of the 72 unique transactions** are reachable; **46 never appear**. Each page also repeats rows internally (25 rows, 13 unique IDs).

The sheet view remains correct because `useSheetQueue` de-duplicates by transaction ID before grouping, and the completeness guard refuses to group an incomplete response. The backend fix is recorded in `.specify/bugs/cost-center-queue-duplicate-transactions/assessment.md` and is **blocked pending separate authorization**. The same role-loop pattern exists in the Senior queue; it was not changed.

## Comparison with the Manus references

References: `docs/frontend-redesign/references/managment/cost-center/01-queue-desktop-en.png` and `01-queue-mobile-en.png`.

### Matches

- **Hierarchy:** eyebrow → title → subtitle → compact responsibility banner → four summary cards → “Your sheets” panel → search/tabs/sort → sheet rows.
- **Field positions:** sheet title + identifier, purchaser, manager-specific transaction count, manager-specific relevant amount, review status, submission date — in the Manus order at desktop and as stacked cards on mobile.
- **Copy:** “MANAGER WORKSPACE”, “Sheets for your review”, the assigned-only explanation, “Your sheets”, “Relevant amount”, and “Needs attention” follow the reference's intent.
- **Structure:** 4-up summary cards on desktop, 2 × 2 on mobile; table on desktop, cards on mobile; status pill per row; count and amount emphasized; footnote under the list.
- **Visual language:** the approved Nextcloud/Pettycash shell, type scale, colour tokens, status badge and focus treatment replace the prototype's branding, as required by `FR-006-007`/`FR-006-010`.
- **No sheet-level action:** every row only expands; decisions remain on the transaction screen.

### Deliberate deviations (backend or scope driven)

| Manus | Delivered | Reason |
|---|---|---|
| Dark prototype rail, “pettycash” brand | Nextcloud app shell and existing navigation | `FR-006-007`, `FR-006-010`; prototype branding must not be reproduced. |
| Readable sheet title + `SHEET-0251` | “Sheet title unavailable” + short UUID + cost-centre code (full id in `title`) | The queue publishes `sheetId` only; no sheet title/readable reference endpoint for managers. The position is preserved rather than substituted. |
| Purchaser/owner name | “Purchaser unavailable” with a placeholder avatar | Not in `TransactionSummary`; `GET /sheets/{id}` is owner-only. |
| “Submitted” date | “Date unavailable” | No sheet submission date in the queue. Latest activity was removed rather than mislabelled as submission. |
| “Reviewed this period” with a count | Card and **Reviewed** tab visibly disabled with a reason | No manager-scoped reviewed-sheet history endpoint. |
| “All / Needs attention / Reviewed” tabs | “Awaiting your decision” + disabled “Reviewed” | Every returned row is pending; an “All” tab would be the same set under a second name. |
| Dark status treatment | Warning-style “Needs attention” pill | The state is actionable review work, not a neutral workflow stage; colour is paired with text. |
| Large explanatory banner | Compact banner + two scope chips | Kept the manager-specific explanation; moved Senior eligibility and field-limitation prose to documentation. |
| arrow → next screen | chevron → inline disclosure → transaction links | The Sheet Review Workspace is Phase 2 (`FR-006-025`); Phase 1 still reaches a real transaction decision in two activations. |
| purchaser search | “Search sheets or cost centers” | The response carries neither titles nor purchasers. |
| prototype annotation cards at the bottom | Not implemented | They are design-document annotations, not application UI. |
| single library amount | per-currency totals | `FR-006-014`; mixing currencies is not permitted. |

The Manus “Eligible/Complete” implications are not shown anywhere. The UI no longer repeats the Senior Review rule, because the server does not expose that state; the rule remains recorded in `spec.md`.

## Capability honesty

**Supported and connected:** manager-scoped pending queue, transaction detail, Approve/Reject/Return, session scope, transaction fallback and pagination.

**Frontend adaptation with a completeness guard:** sheet projection, per-manager sheet/transaction counts, per-currency pending sums, latest activity for sorting, local search/sort.

**Unavailable, rendered as explicit unavailable states:** sheet title, purchaser, submitted date, sheet total, all-transaction count, reviewed history and the Reviewed filter, sheet-wide Cost Center completion, Senior Review eligibility. The title/purchaser/submitted positions remain visible and say the server does not provide the field; technical reasons are in this report.

**Completeness rule:** the view groups only when `total <= items.length` and `items.length < 500`; otherwise it shows “Sheet grouping unavailable” with a working link to the transaction list. Duplicate transaction IDs are removed before any count or sum.

## Verification

| Check | Result |
|---|---|
| `npm run typecheck` | Pass |
| `npx vitest run tests/Frontend/Component/ManagementReview.spec.ts` | **46 passed** |
| `npx eslint` on the view and component spec | Pass |
| `npx stylelint` on the view | Pass |
| `npm run build` | Pass |
| l10n catalogs | 550 keys each; parity true; no duplicates; Persian `nplurals=2; plural=(n > 1);` preserved |
| Live lab, Chrome | 72 sheet rows; six fields and warning chip present; no errors; no horizontal scroll at 1440 and 390; Persian `dir=rtl`; fallback 25 rows, `1 / 2` |

**Not run:** full PHPUnit, full Vitest, `npm run check`, E2E suite, dark/forced-colours capture. These are milestone-level gates and were not required for this visual slice; the change touches no backend or runtime PHP.

## Spec Kit progress

| Step | Status | Evidence |
|---|---|---|
| specify | Complete | `spec.md` — 35 FRs, 4 user stories, capability classification, workflow reconciliation. |
| plan | Complete | `plan.md` — completeness rule, D1–D5, phases, risks. |
| tasks | Complete | `tasks.md` — `T001`–`T022`. |
| analyze | Run at the gate (read-only) | 0 critical, 0 high, 2 medium, 2 low. Findings below; no artifact was edited by the analysis. |
| implement | Complete for Phase 1 scope, including the 2026-10-30 visual refinement | Source, catalogue, tests, build, screenshots. |
| converge | **Not run — deferred** | Converge appends remaining unbuilt work to `tasks.md`; running it now would add Phase 2 tasks that `FR-006-025` forbids starting before this gate. It belongs after approval, before Phase 2 implementation. |

### Analysis findings (read-only)

| ID | Severity | Location | Finding | Recommendation |
|---|---|---|---|---|
| A1 | MEDIUM | `spec.md` `FR-006-003` vs US1 #8 | `FR-006-003` requires selecting a sheet to open the workspace; US1 #8 allows an explicit Phase 2 handoff until it exists. Phase 1 expands inline. | Scope `FR-006-003` to Phase 2, or amend it to name the Phase 1 inline handoff. Owner decision before Phase 2. |
| C1 | MEDIUM | `spec.md` line 30 vs `tasks.md` Out of Scope | The spec says Phase 2 is planned in the task ledger; `tasks.md` has no Phase 2 tasks. | Add gated Phase 2 task stubs at approval, or correct the spec wording. |
| C2 | LOW | `FR-006-022/023`, `SC-006-006` | No Phase 1 task by design (deferred by `FR-006-025/026`). | Keep deferred; add Phase 2 tasks when the workspace is authorized. |
| I1 | LOW | `tasks.md` T003/T019 | The composables folder and capture height were initially written imprecisely. | Corrected in the ledger; evidence now matches. |

Coverage: 35 requirements, 31 mapped to Phase 1 tasks, 4 deferred to Phase 2/out of scope (`FR-006-022`, `FR-006-023`, `FR-006-026`, `FR-006-027`); 7 success criteria, 6 covered in Phase 1, `SC-006-006` deferred with the workspace. No constitution violation: evidence is stored under `artifacts/visual-review/` and Git-ignored (Principle VIII), Chrome-only evidence was used (Principle V), and the gate is unapproved so no completion claim is made (Principle VII).

## Blockers and open items

1. **Owner visual approval (this gate).** Nothing is marked accepted; `T022` stays open until the owner records approval for the refined sheet-first queue at desktop and mobile.
2. **Phase 2 workspace** is blocked by the gate. No workspace route, sheet-level decision, or eligibility badge exists.
3. **Backend duplicate and pagination defect** is confirmed and recorded, but the fix requires separate authorization. No backend code was changed. The Senior queue shares the same role-loop pattern and was not investigated beyond source reading.
4. **Backend field gaps** (sheet title/purchaser/submitted date/history/completion/eligibility) remain as recorded in `spec.md`; the UI renders honest unavailable states instead of omitting the Manus positions.
5. **OCI coverage:** the index is usable at 11,871 chunks, but 141 failed embedding batches remain. The refinement relied on current source for edited files, as allowed during an active slice; no reindex or embedding-provider change was performed.
6. **Git:** no stage, commit, push, or branch operation was performed. The working tree remains dirty with unrelated changes from earlier work.

## Requested decision

Please review the four approval screenshots and the live URL and record one of:

- **Approved** — Phase 1 is visually accepted; authorize the Phase 2 Sheet Review Workspace (desktop + mobile, its own gate).
- **Approved with changes** — name the changes; they will be made before Phase 2.
- **Not approved** — name the blocking visual or interaction issue.

No Phase 2 implementation begins before that record exists.
