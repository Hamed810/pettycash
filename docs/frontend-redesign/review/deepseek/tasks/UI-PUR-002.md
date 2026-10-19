# UI-PUR-002 — Purchaser sheet list: the six-column layout, title above ID, and honest amounts

- **Priority:** P1 · **Status:** PROPOSED · **Size:** M · **Risk:** low–medium (touches the primary purchaser screen and FR-062's stage columns)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-009, GAP-010, GAP-048, GAP-047 (constraint), GAP-057 (acceptance)
- **Backend impact:** NONE · **Conditional on:** none — D-04 is resolved: FR-062's stage-approved figures stay, each with its own label and cell (FR-062 is contractual; see `OWNER_DECISION_RECONCILIATION.md` §3)

## 1. User-visible outcome

`/sheets` reads as a purchaser's own working list: **Sheet (title above its ID) · Status · Transactions · Recorded Amount · Latest Approved Amount · First Submitted**, with the sheet's identity immediately visible and absent values shown as absent instead of as zero.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| 11 columns declared | `src/views/sheets/SheetListView.vue:140-158` — `title, owner, currency, status, transactionCount, activeRevision, totals, latestEffective, stageApproved, keyDates, actions` |
| No sheet ID rendered in any cell | the id is only a row key / navigation target: `SheetListView.vue:199,405` |
| Recorded and Submitted share one cell | `SheetListView.vue:274-285` |
| Latest-approved cell | `SheetListView.vue:286-294` |
| Stage-approved cell | `SheetListView.vue:295-307` |
| A never-submitted sheet's `submitted` total is `0` | `lib/Infrastructure/Http/SheetPayloadFormatter.php:253` |
| Two overlapping list regions (open sheets + full page) | `SheetListView.vue:178-185,521-536` → main table `:549-559`; overlap is test-pinned at `tests/Frontend/Component/PurchaserFlow.spec.ts:2655-2661` |
| Design reference | `docs/frontend-redesign/references/desktop/01_sheets_list_desktop_EN_approved.png` |
| Prototype layout (6 columns, 5/page) | `docs/frontend-redesign/prototype/manus-purchaser-v1/app.js:200` |
| Payload semantics | `totals.current` = all active revisions; `totals.effectiveApproved` = effective approved; `costCenterApproved/seniorApproved/financeApproved` = per-stage approvals (`lib/Infrastructure/Http/SheetPayloadFormatter.php:246-261`) |

## 3. Authoritative references

- **FR-063** `spec.md:1049-1057` — identity, owner, currency, status, transaction count, key dates, current/submitted totals, summary amounts, latest active revision number.
- **FR-062** `spec.md:1046-1048` — the latest-effective figure **and** three separate stage-approved figures. *The list must not silently lose these six numbers; only their prominence is being changed.*
- FR-064 `spec.md:1058-1076` — filters.
- Accepted direction: `handoff/manus/01_PROJECT_CONTRACT_EN.md` (list description).

## 4. Allowed scope / non-goals

**In scope**
- Re-cut the main table's columns to the six accepted columns, with the sheet title rendered above the sheet's public id in one cell.
- Render **Recorded Amount** from `totals.current` and **Latest Approved Amount** from `totals.effectiveApproved`, as separate columns.
- Render **First Submitted** from `submittedAt` (which the server maps from `firstSubmittedAt` — `SheetPayloadFormatter.php:76`).
- Keep FR-062's stage-approved figures reachable without leaving the list (see D-04 for the choice: a second line inside the amounts cell, or a disclosure row).
- Render a never-submitted sheet's submitted figure as absent (dash), never as `0` — consistent with `src/utils/date.ts:1-31`.
- Adopt one async-state idiom for loading/empty/error on this screen (the shared `AsyncState.vue` exists and is currently imported by nobody — `tests/Frontend/Component/AsyncState.spec.ts:65-67`), or delete the component in favour of the surviving idiom. Pick one; do not add a fourth.
- Status pill styling driven by design tokens (see `UI-PUR-015` for the token decision); `VISUAL_REVIEW_NEEDED` for the final colours.

**Non-goals**
- **No removal of FR-062 data.** Dropping the stage-approved columns is an owner decision (D-04), not an implementation choice.
- No change to filters, paging or sorting (`UI-PUR-003`), no change to the detail workspace (`UI-PUR-005`).
- No removal of the "open sheets" region — its correctness is `UI-PUR-003`'s job (GAP-002); this card only restyles the main table.
- No new columns beyond the six plus FR-062's retained figures.
- No currency symbol invention: render the sheet's own currency code (`currency` is ISO-shaped).
- No `$`, no client-side currency conversion, no float arithmetic — the prototype's `money()` (`app.js:34`) and float sums (`:139-142`) must not be ported.

## 5. Implementation behaviour

- Column order exactly: Sheet, Status, Transactions, Recorded Amount, Latest Approved Amount, First Submitted. The five FR-062 figures (latest effective + three stage approvals) are preserved per D-04.
- **Sheet cell:** title as the primary line (still the navigation/activation target, keyboard-operable as today), public id beneath it in a secondary/monospace style, wrapping rather than overflowing at 390px.
- **Amounts:** minor units through the shared money helper (`UI-PUR-001`); tabular numerals; a missing value is a dash.
- **Status:** the existing status label mapping stays the single source (catalogued labels, raw-identifier fallback) — do not introduce a second status vocabulary.
- **Loading / empty / error:** the chosen idiom, with an explicit empty state that distinguishes "no sheets at all" from "no sheets match the filters" (today the second case risks reading as the first once filters are applied).
- Localization: every new label is a catalog key under `sheet`-scoped names; no literal strings.
- Theming: only existing design tokens / `@nextcloud/vue` 9.12.0 components; no local colour constants.

## 6. Acceptance criteria

1. The main table renders exactly the six columns in the stated order, plus FR-062's retained figures per D-04.
2. Each row shows the sheet's public id under its title.
3. Recorded Amount equals `totals.current` and Latest Approved Amount equals `totals.effectiveApproved` for a fixture with a mixed approved/pending/rejected set — asserted with a fixture whose two numbers differ (a sheet where they are equal proves nothing).
4. A never-submitted sheet shows no submitted figure (dash), not `0`; a submitted sheet whose recorded total is genuinely `0` shows `0` (negative case: the two must not collapse into one rendering).
5. A sheet with 0 transactions renders `0` transactions, not a blank.
6. At 390px: no horizontal scroll, no clipped identifiers, title and id both legible (FR-071).
7. With the English locale active, all six headers and all status labels come from the catalog (no literal fallback reaching the DOM).
8. Existing purchaser-list tests that assert the previous column set are updated deliberately — a reviewer must see which assertion changed and why (do not blanket-edit `PurchaserFlow.spec.ts`).
9. Keyboard: the sheet cell remains reachable and activatable by keyboard as it is today.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | six columns, id under title, the two amount columns with a divergent fixture, dash vs zero |
| Component | `npx vitest run tests/Frontend/Component/RtlAccessibility.spec.ts` | 390px floor and RTL case unchanged |
| Component | `npx vitest run tests/Frontend/unrenderedColumnFallback.spec.ts` | a payload value with no label still renders honestly |
| Unit | `npx vitest run tests/Frontend/l10nCatalog.spec.ts` | new keys are catalogued (gate already exists at `:90-92`) |
| E2E | `npm run test:e2e` (TO_VERIFY: lab availability) | open `/sheets`, screenshot both breakpoints for owner review |
| Gate | `npm run typecheck && npm run lint && npm run stylelint && npm run build` | clean |

## 8. Dependencies / rollback

- **Depends on:** `UI-PUR-001` (shared formatting helpers) — or implement with the current helpers and let `UI-PUR-001` retrofit.
- **Blocked by:** D-04 (column-set decision) for the stage figures only; the six accepted columns can be built regardless.
- **Rollback:** single-file revert of `SheetListView.vue` (plus the shared state component if adopted).

## 9. Definition of Done

- [ ] Six columns shipped; FR-062 figures retained per the owner's decision, with the decision recorded.
- [ ] Screenshots at desktop and 390px attached to the completion report for owner comparison against `references/desktop/01_sheets_list_desktop_EN_approved.png`.
- [ ] No literal user-facing string added.
- [ ] Tests above executed with pasted output.

## 10. Approval gate

**PROPOSED.** Not approved. Owner review required — in particular the D-04 column decision and the visual comparison, which no automated check can settle.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Current status:** `READY_FOR_OWNER_APPROVAL` (the header's `PROPOSED` means exactly this; nothing here is approved).
- **D-04 resolved from the specification:** FR-062 (`spec.md:1046-1048`) is contractual and requires the stage-approved figures "as separate columns … alongside the latest effective outcome column". The redesign may re-group them visually, but **every figure keeps its own label and its own cell**; deleting them is a spec amendment that has not been made.
- **Added criterion `REC-06`:** the date filter must name the date it filters. It is the sheet's **creation** date; today the labels are generic (`sheets.filter.from` / `.to` at `src/views/sheets/SheetListView.vue:475,484`).
- **Tier-2 carrier:** the shared row primitives (status pill, amount cell, date cell, async-state idiom) are extracted as this card's first step so `UI-PUR-008` and the workspace can reuse them.
- Sequenced first among the list cards, after `UI-PUR-001`.
