# UI-PUR-008 — Sheet actions behind a 3-dot overflow menu

- **Priority:** P2 · **Status:** PROPOSED · **Size:** S · **Risk:** low
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-013 · **Backend impact:** NONE

## 1. User-visible outcome

Destructive and secondary sheet actions live behind a per-sheet overflow ("3-dot") menu with proper labels, instead of three permanently visible Delete buttons competing with the row's primary action.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| Delete is a plain button in the open-sheets region | `src/views/sheets/SheetListView.vue:247-252` |
| …and in the main table | `SheetListView.vue:361-367` |
| …and in the detail view | `src/views/sheets/SheetDetailView.vue:269-276` |
| No `NcActions` anywhere under `src/` — the pattern does not exist yet in this codebase | unique `Nc*` component scan over `src/**/*.vue` |
| The confirm dialog is correct and must be kept (names the sheet; required because deletion cascades evidence files irreversibly) | `src/components/shared/DeleteSheetDialog.vue:86-121`; `spec.md:201` |
| Server guards: owner only, OPEN only, stale version → 409, FR-084 replay | `lib/Application/Expense/DeleteSheet.php:92-107,121-144` |
| Existing deletion tests | `tests/Frontend/Component/SheetDeletion.spec.ts:229-388` |

## 3. Authoritative references

- Accepted direction: "open-sheet deletion via a 3-dot menu and confirmation".
- FR-086 `spec.md:885` — delete own never-submitted sheet; the confirmation and cascade reasoning at `spec.md:201`.
- Design reference: `references/desktop/01_sheets_list_desktop_EN_approved.png` (row overflow affordance).

## 4. Allowed scope / non-goals

**In scope**
- Introduce `NcActions`/`NcActionButton` (from `@nextcloud/vue` 9.12.0 — already a dependency) as the sheet's action menu.
- Move **Delete** into it, keeping the existing confirmation dialog and its copy.
- Put **Edit** (once `UI-PUR-007` lands) and the row's read action into the same menu so there is one control per row rather than three.
- Menu button carries an accessible name naming the sheet (e.g. "Actions for ‹title›") — a list of identically-labelled buttons is a screen-reader trap.
- Menu keyboard semantics: opens with Enter/Space, arrow navigation, Escape closes and returns focus to the trigger.

**Non-goals**
- No change to the delete behaviour, confirmation content, or cascade.
- No new actions invented (no duplicate, no archive, no export from the list).
- No removal of the detail view's own action set (it is the workspace's primary header, not a row).
- No change to `SheetDeletion.spec.ts`'s behavioural assertions — only its selectors, and only with a stated reason.

## 5. Implementation behaviour

- Exactly one action menu per row, in the same column position in the main table and in the open-sheets region.
- Delete remains available **only** for OPEN sheets (the server refuses otherwise; the UI must not offer it).
- The menu's items must be keyboard reachable and announced with a name that includes the sheet's title.
- The confirm dialog keeps naming the sheet and keeps its current optimistic-locking behaviour (`expectedVersion`).
- If a delete races with another change, the existing 409 path applies — the menu closes and the error is surfaced (no silent failure).

## 6. Acceptance criteria

1. Each row exposes exactly one overflow trigger; no standalone Delete button remains in the list.
2. The trigger's accessible name contains the sheet's title (negative case: two rows' triggers must not have identical accessible names).
3. Opening the menu and choosing Delete shows the existing confirmation; cancelling issues no request (negative case: the delete request count stays 0).
4. Confirming deletes the sheet, reloads the list (existing behaviour, `src/stores/sheets.ts:117-123`) and surfaces a 409 as an error rather than a silent no-op.
5. A submitted sheet's menu offers no Delete item.
6. Keyboard: trigger → menu → item → confirm is completable without a mouse; Escape returns focus to the trigger.
7. No new dependency; light/dark themes both legible with existing tokens.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/SheetDeletion.spec.ts` | trigger presence, unique accessible names, cancel = no request, 409 surfaced |
| Component | `npx vitest run tests/Frontend/Component/NextcloudVueContract.spec.ts` | component usage allowed by the pinned contract |
| E2E | extend `tests/E2E/keyboard-accessibility.spec.ts` (TO_VERIFY: lab) | keyboard-only deletion journey |
| Gate | `npm run check` | clean |

## 8. Dependencies / rollback

- **Depends on:** nothing. **Coordinated with:** `UI-PUR-007` (Edit in the same menu) — if both land in one batch, build the menu once.
- **Rollback:** revert one component region; the dialog and server are untouched.

## 9. Definition of Done

- [ ] One overflow menu per row; Delete and Edit inside it; confirmation preserved.
- [ ] Keyboard journey demonstrated; test output pasted.
- [ ] `git diff` limited to the list/workspace views.

## 10. Approval gate

**PROPOSED.** Not approved. Low risk, but it changes the affordance the owner explicitly specified — visual comparison against the desktop reference is part of the review.
