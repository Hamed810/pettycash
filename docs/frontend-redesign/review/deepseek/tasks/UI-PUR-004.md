# UI-PUR-004 — Resume where the purchaser left: list state across the sheet detail

- **Priority:** P1 · **Status:** PROPOSED · **Size:** S · **Risk:** low
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-017 (state half) · **Backend impact:** NONE

## 1. User-visible outcome

A purchaser who pages to sheet 3, opens a sheet, and comes back is on page 3 with the same filters and roughly the same scroll position — instead of being thrown back to page 1.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| Opening a sheet is a route push | `src/views/sheets/SheetListView.vue:405` → `src/router/index.ts:57` |
| The view remounts and reloads with defaults | `SheetListView.vue:388-393`; store default `page = 1` `src/stores/sheets.ts:24` |
| Filters survive in the store but the page does not | `src/stores/sheets.ts:25,29`; draft re-seeded from them `SheetListView.vue:34` |
| No `KeepAlive`, no `scrollBehavior` | `src/AppRoot.vue:41`; whole-`src/` search for `scrollBehavior` returns nothing |
| Existing pager assertions | `tests/Frontend/Component/PurchaserFlow.spec.ts:1031,1051,1069,1086,1100,1119,1138` |

## 3. Authoritative references

- Accepted direction: "Selecting a sheet opens a large detail workspace/modal over the list, **preserving scroll/filter state on close**."
- Accepted direction: sheets-list-first ordering and filtering must match real server semantics; this task does not change either.
- `spec.md:832-841` (FR-025) — the concurrent-edit/reload rule; this card must not make a stale list look fresh (see §5, criterion 5).

## 4. Allowed scope / non-goals

**In scope**
- Preserve the list's page index, sort direction (once `UI-PUR-003` exists), filters and scroll offset across a sheet-detail visit and back.
- Implement it in a way that survives **both** navigation models: today's route push and `UI-PUR-005`'s overlay. Recommended: keep the query state in the Pinia store (page included) and restore scroll on list mount via the router's scroll behaviour or a stored offset.
- Add a regression test that pins "page N survives a detail round trip".

**Non-goals**
- No change to how the list is fetched, filtered or paged.
- No caching of sheet rows across visits beyond what the store already holds (the list must refetch — a stale list after a metadata edit is worse than a lost scroll offset).
- No browser-history manipulation beyond the existing routes.
- No `KeepAlive` of the whole view tree as a shortcut: it would freeze stale row data. If `KeepAlive` is used, only around the list **as a presentation shell** with an explicit refresh on return — justify the choice in the completion note.

## 5. Implementation behaviour

- Page index lives in the store alongside filters; `loadSheets` on mount uses the stored page unless the user's own navigation implies page 1 (a fresh entry into `/sheets` from the dashboard should behave as today).
- Scroll restoration: capture the offset before navigating away, restore after the list has rendered its rows (not before, or the restore is overwritten).
- A deleted sheet still reloads the current page (existing behaviour, `src/stores/sheets.ts:117-123`) and must not resurrect the offset to a row that no longer exists.
- **Honesty rule:** after returning from a detail where the sheet was edited (title/account), the list must refetch rather than render the store's previous rows.
- Error state: if the restored page no longer exists (e.g. sheets were deleted elsewhere), the list falls back to the last valid page and says so, rather than showing an empty page next to a pager reading "page 3 of 1" (this exact contradiction is already documented as a concern at `SheetListView.vue:93`).

## 6. Acceptance criteria

1. Filter, page to N, open a sheet, close/back → the same page N, the same filters, the same scroll offset (±one row height).
2. The same holds for a sheet opened from the "open sheets" region.
3. A fresh visit to `/sheets` from another route still lands on page 1 with default filters (negative case: the preservation must not become a sticky global).
4. After editing a sheet's title, returning to the list shows the new title (refetch, not a stale cache).
5. Deleting the last sheet on the last page falls back to a valid page with a non-contradictory pager.
6. Existing pager tests (`PurchaserFlow.spec.ts:1031-1119`) pass unchanged except where they assert the reset-to-1 behaviour that this task deliberately changes — those edits must be called out in the completion note.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | new: page survives a detail round trip; fresh entry resets to page 1; refetch after edit |
| E2E | `npm run test:e2e` (TO_VERIFY: lab availability) | filter → page 3 → open → back: assert URL, pager text and scroll offset |
| Gate | `npm run typecheck && npm run lint` | clean |

## 8. Dependencies / rollback

- **Depends on:** nothing hard. **Sequencing:** land before or together with `UI-PUR-003` (shared query-state object) and consider it a prerequisite for `UI-PUR-005`'s acceptance test.
- **Rollback:** revert; the failure mode of a partial revert is the current reset-to-page-1 behaviour.

## 9. Definition of Done

- [ ] Page, filters, sort and scroll survive a detail round trip.
- [ ] Stale-row honesty rule implemented and tested.
- [ ] Tests executed with pasted output; changed pre-existing assertions listed.

## 10. Approval gate

**PROPOSED.** Not approved. Small, low-risk, and independently reviewable — a reasonable first item for a batch, provided `UI-PUR-001`/`UI-PUR-002` do not also rewrite the same file in the same branch (coordinate to avoid three-way churn on `SheetListView.vue`).
