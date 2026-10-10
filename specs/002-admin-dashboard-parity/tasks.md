# Tasks: Admin Dashboard Parity for Nextcloud Admins

**Input**: `specs/002-admin-dashboard-parity/spec.md`, `plan.md`

## Phase 1: Scope fix (P1) 🎯 MVP

**Goal**: Nextcloud admin with no assignment rows resolves to administrator scope; `/dashboard` emits the `admin` card.

**Independent Test**: Unit scope assertions below; manual `GET /dashboard` shows `admin` + `purchaser` for the platform admin.

- [x] T001 [US1] Extend `ReportScope::forActor()` in `lib/Application/Reporting/ReportScope.php` with `bool $isPlatformAdministrator = false`; when true, append `Role::Administrator` and set `administrator=true` (cost-centre ids unchanged)
- [x] T002 [US1] Inject `AdministratorCheck` into `DashboardQuery` (`lib/Application/Reporting/DashboardQuery.php`, appended last) and pass `isAdministrator($actorUid)` through `scopeFor()` to `forActor()`
- [x] T003 [US1] Register the new dependency in `lib/AppInfo/Application.php` (`DashboardQuery` service, append `$c->query(AdministratorCheck::class)`)

**Checkpoint**: Scope resolution agrees with `/me` for platform admins.

## Phase 2: Regression tests (P1)

**Goal**: Prove the parity rule and the no-widening rule.

- [x] T004 [P] [US1] Update `tests/Unit/Reporting/ReportingQueryTest.php` setUp for the new `DashboardQuery` constructor (configurable admin double; default non-admin)
- [x] T005 [US1] Add platform-admin scope test: no assignments + `isAdministrator=true` → has `Administrator` role, `administrator=true`, `seesOthers()=true`, `canDecide()=false`, `sections()` contains `administration`
- [x] T006 [US1] Keep/extend the negative: same actor with `isAdministrator=false` and no assignments → `administrator=false`, no `Administrator` role (existing `testAPurchaserWithNoAssignmentsResolvesToTheirOwnScope` covers the shape; assert role absence explicitly if missing)

**Checkpoint**: `scripts/dev/phpunit` targeted reporting suite green.

## Phase 3: Cross-surface confirmation (P2)

**Goal**: Reports/exports inherit the fix (no code change, verification only).

- [x] T007 [US2] Confirm `ReportController`/`ExportController` both resolve scope only via `DashboardQuery::scopeFor()` (already true: `lib/Controller/ReportController.php:146`, `lib/Controller/ExportController.php:174`) — no edit; record outcome in the completion report
- [x] T008 Targeted verification: run the affected PHPUnit scope tests + `git status`/`git diff` review that only intended files changed

## Dependencies & Execution Order

- T001 → T002 → T003 (same call chain, sequential)
- T004 → T005, T006 (double first, then assertions)
- Phase 1 before Phase 2; Phase 3 verification last

## Notes

- Constructor arg appended last (T367 positional-DI lesson).
- `InMemoryAdministratorCheck` returns true only for `test-admin`; tests needing both branches need a configurable double (inline anonymous class or extended double) rather than editing the shared double's contract silently.
- Full `scripts/dev/phpunit`, `npm run check`, and E2E are milestone gates, not per-edit gates — not run here.
