# Tasks: In-App Navigation

**Input**: Design documents from `/specs/004-in-app-navigation/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Included — constitution V requires automated coverage for UI behavior; route-integrity + new nav spec are the acceptance mechanism.

**Organization**: Grouped by user story; each story independently testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallelizable (different files, no dependencies)
- **[Story]**: US1/US2/US3 from spec.md
- Exact file paths in every implementation task.

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Entry type + test scaffold used by all stories.

- [x] T001 [P] Create `src/types/navigation.ts` entry table type per `data-model.md` (name/labelKey/testId/visibleFor, no new routes).
- [x] T002 [P] Scaffold `tests/Frontend/Component/Navigation.spec.ts` mounting the nav with stubbed `useAuthStore` states (admin / reviewer / purchaser / session-failure).

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The nav shell every story depends on. No story work until this is green.

- [x] T003 Create `src/components/navigation/AppNavigation.vue` per `research.md` Decision 2 + `contracts/navigation.md` rules 1–3,5–6 (semantic `<nav><ul>`, `RouterLink :to="{ name }"`, `@nextcloud/vue` 9.x only, `t('pettycash','navigation.*')` labels, `data-test` hooks).
- [x] T004 Render `AppNavigation` in `src/AppRoot.vue` above `RouterView` (session failure degrades to dashboard+sheets only).
- [x] T005 [P] Add `navigation.*` label keys to the Persian/German catalog used by `t()` (every entry named; fallback key never shown). — DONE BY CONVENTION: no translation catalog exists in this repo (`t()` keys resolve via Nextcloud tooling; suite mocks `t`). New keys follow the existing `navigation.*` convention (`navigation.dashboard` precedent); no catalog file to edit.
- [x] T006 Run `routeNameIntegrity` + `NextcloudVueContract` + `Navigation.spec.ts`; all green before story work. — plus seeded `routeNameIntegrity` reachability walk with `src/AppRoot.vue` (shell is a render root; without it the new component reads as orphan).

**Checkpoint**: Foundation ready — nav renders, role filter works, no invented route names, no Vuetify drift.

---

## Phase 3: User Story 1 - Admin reaches administration (Priority: P1) 🎯 MVP

**Goal**: Admin sees Administration (configuration + funding) and reaches both in one click; non-admin never sees them; back-link to dashboard.

**Independent Test**: Mount as admin → both admin entries visible and resolve; mount as purchaser → neither visible; open each admin view → dashboard back-link present. Then manual quickstart step 1.

### Tests for User Story 1

- [x] T007 [P] [US1] Extend `Navigation.spec.ts`: admin session shows `nav-admin-configuration` + `nav-admin-funding`; non-admin session shows neither; every rendered name exists in `routes`. — plus session-load-on-mount test (see T003 note).

### Implementation for User Story 1

- [x] T008 [US1] Add `admin-configuration` + `admin-funding` entries to the nav table with `visibleFor = capabilities.canAdminister === true` (contracts rule 3). — implemented in `NAVIGATION_ENTRIES` with the foundation (single table covers US1/US2/US3 entries; one file, one commit).
- [x] T009 [P] [US1] Add dashboard back-link to `src/views/admin/ConfigurationView.vue` + `src/views/admin/FundingView.vue` (pattern: existing `RouterLink dashboard` breadcrumbs; contracts rule 4).

**Checkpoint**: US1 independently functional — admin 1-click administration, non-admin clean, back-links present.

---

## Phase 4: User Story 2 - Reviewer reaches queues (Priority: P1)

**Goal**: Each reviewer role reaches its queues/returns from nav + dashboard, with queue↔detail↔dashboard back-links.

**Independent Test**: Mount as COST_CENTER_MANAGER / SENIOR_MANAGER / FINANCE → only in-scope queues visible; queue detail shows owning-queue + dashboard links. Then manual quickstart step 2.

### Tests for User Story 2

- [x] T010 [P] [US2] Extend `Navigation.spec.ts`: role→queue visibility matrix (incl. finance-never-by-admin-alone); queue-detail back-links resolve. — visibility matrix covered (cost-center vs senior vs finance; FR-007 admin-alone case); detail back-links verified present in `TransactionReviewDetailView.vue:6-11` + `FinanceReviewView.vue:6-11` (no edit needed).

### Implementation for User Story 2

- [x] T011 [US2] Add the four queue + returns entries with role-based `visibleFor` (data-model.md rules; finance requires `FINANCE`). — in `NAVIGATION_ENTRIES` (see T008 note).
- [x] T012 [P] [US2] Add missing owning-section/dashboard back-links to any review view lacking them (copy existing breadcrumb idiom; contracts rule 4). — VERIFY-ONLY, no edit: every queue view (`CostCenter/Senior/Finance/ReturnsQueueView.vue:6`) and both detail views already carry the dashboard breadcrumb, and both detail views link their owning queue. The global shell additionally covers all views.
- [x] T013 [P] [US2] Add per-role queue action links in `src/views/DashboardView.vue` (cards stay presentational; links are separate focusable controls). — implemented as `quickLinks` reusing `visibleEntries` (one role table, two surfaces).

**Checkpoint**: US1 + US2 both work — admin and reviewer journeys complete independently.

---

## Phase 5: User Story 3 - Purchaser reaches sheets and accounts (Priority: P2)

**Goal**: Purchaser reaches sheets + accounts from nav + dashboard; every sheet/transaction view links back.

**Independent Test**: Mount as purchaser → sheets + accounts entries visible; dashboard shows sheets/accounts actions; sheet/transaction views show list + dashboard back-links. Then manual quickstart step 3.

### Tests for User Story 3

- [x] T014 [P] [US3] Extend `Navigation.spec.ts`: purchaser sees `sheets` + `accounts` + `dashboard`; entries resolve; back-links present. — covered by hides-from-non-admin test (base entries asserted) + resolve test.

### Implementation for User Story 3

- [x] T015 [US3] Add `sheets` + `accounts` (+ `dashboard`) entries with `visibleFor = any authenticated user`. — in `NAVIGATION_ENTRIES` (see T008 note); returns entry likewise (server-scoped to caller's own returns).
- [x] T016 [P] [US3] Add sheets/accounts action links in `src/views/DashboardView.vue` alongside US2 links (one edit total if combined with T013 — keep separate commits). — combined with T013 as `quickLinks` (single nav-table source); separate task checkbox retained for traceability.
- [x] T017 [P] [US3] Add missing list/dashboard back-links to sheet/transaction/account views that lack them (contracts rule 4). — SATISFIED VIA SHELL, no per-view edit: `AppNavigation` renders above `RouterView` on every route, so each of `SheetList/SheetDetail/SheetForm/TransactionList/TransactionDetail/AccountView` now offers dashboard + owning-section links without touching those files (several carry unrelated uncommitted changes that must be preserved).

**Checkpoint**: All three stories independently functional; no address-bar navigation needed for any primary journey.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: RTL/narrow/keyboard + docs + full-gate validation.

- [x] T018 [P] Verify Persian RTL + 390px narrow (no horizontal scroll) + keyboard Tab order for the nav and new links (`RtlAccessibility`, `fr071NarrowViewport`, `keyboard-accessibility` green; fix in nav CSS only). — full `npm test` green (436 tests, 19 files); nav uses wrapping flex + native links, no new CSS beyond that.
- [x] T019 [P] Run `npm run lint`, `npm run typecheck`, targeted Vitest files, then `npm run check` frontend gate at the milestone boundary (per AGENTS.md testing policy). — `eslint` clean on all touched files, `vue-tsc --noEmit` clean, full `npm test` green. Production `vite build` deliberately NOT run: it regenerates tracked `js/`+`css/` chunks and the tree already carries unrelated build dirt; typecheck+lint+vitest cover the SFC change.
- [ ] T020 Run `quickstart.md` manual validation (admin + reviewer + purchaser, desktop + 390px + RTL) and record results in the completion report. — MACHINE-CHECKABLE SUBSET VERIFIED HEADLESS 2026-10-10 (13/13 vs live lab, see report; helper at `test-results/nav-smoke-004.mjs`). Still owed to a person: visual RTL feel + by-person keyboard walkthrough.
- [x] T021 Write `docs/reports/004-in-app-navigation.md` completion report (change set via `git status/diff`, verification evidence, code-intelligence path line).

## Dependencies & Execution Order

- Phase 1 → Phase 2 (blocks everything) → US1 → US2 → US3 → Polish.
- US2/US3 depend on the Phase-2 shell but not on each other; may parallelize after US1 if staffed.
- Within a story: spec extension first (T007/T010/T014 written to FAIL), then entries, then view links.
- Same-file edits (DashboardView T013/T016, AppNavigation table T008/T011/T015) run sequentially; different-file `[P]` tasks may parallelize.
- `src/router/index.ts`: READ-ONLY — any edit there is a defect; stop and return to spec/plan.
- `lib/` (PHP): out of scope — no backend change; authorization stays server-side by design.

## Implementation Strategy

### MVP First (US1 only)

1. Phase 1 + Phase 2 → shell green.
2. Phase 3 (US1) → admin journey independently testable → STOP and VALIDATE per quickstart step 1.

### Incremental Delivery

1. + US2 → reviewer journeys → validate quickstart step 2.
2. + US3 → purchaser journeys → validate quickstart step 3.
3. Phase 6 polish → full gate → report.

## Phase 7: Convergence

**Purpose**: Resolve the one code-vs-artifact gap found by converge (2026-10-10). All FR/SC/AC and plan decisions verified satisfied; T020's human remainder stays tracked by T020 itself.

- [x] T022 Decide and align the session-failure nav behavior per T004 (missing) — DECIDED FOR T004's fallback: failed load (error set, unauthenticated) renders dashboard+sheets only via `sessionFailed` (`src/types/navigation.ts`); signed-out-with-no-error renders nothing; `logout()` clears stale errors. Covered by new `Navigation.spec.ts` failure test + rewritten no-session test (14 tests green).
