# Tasks: Nextcloud App-Menu Entry

**Input**: `specs/003-app-menu-entry/spec.md`, `plan.md`

## Phase 1: Declaration + icon (P1) 🎯 MVP

**Goal**: "Petty Cash" appears in the Nextcloud app menu and opens the existing page.

**Independent Test**: XML-parse check + icon check (task T003); manual menu click as acceptance.

- [x] T001 [US1] Add `<navigations>` block to `appinfo/info.xml` (name `Petty Cash`, route `pettycash.page.index`, icon `app.svg`, order `50`), placed after `<dependencies>`
- [x] T002 [US1] Create `img/app.svg` menu icon (valid SVG, monochrome, no text/fonts/external refs)
- [x] T003 [US1] Verify: `info.xml` parses as XML with the expected navigation values; `img/app.svg` parses as SVG; `occ app:list` still shows `pettycash` enabled; confirm only the intended files changed (`git status`/`git diff`)

## Dependencies & Execution Order

- T001 → T002 → T003 (sequential; verification last)

## Notes

- No PHP/container execution needed for the change itself; verification uses host-side XML parsing plus read-only `occ app:list`.
- Full PHPUnit / `npm run check` / E2E are milestone gates, not run for a metadata-only change.
- Do not bump `<version>` (no migration involved).
