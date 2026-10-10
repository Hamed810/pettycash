# Implementation Plan: Admin Dashboard Parity

**Branch**: `002-admin-dashboard-parity` | **Date**: 2026-10-09 | **Spec**: `specs/002-admin-dashboard-parity/spec.md`

**Input**: Feature specification from `/specs/002-admin-dashboard-parity/spec.md`

## Summary

Inject the existing `AdministratorCheck` port into `DashboardQuery`, thread its verdict through `ReportScope::forActor()` as a platform-administrator signal, and register the new dependency in `Application.php`. One scope fix covers dashboard, reports, and exports because all three resolve scope via `DashboardQuery::scopeFor()`. No contract, migration, or frontend change.

## Technical Context

**Language/Version**: PHP 8.2+ (container-only runtime), TypeScript 5 / Vue 3 frontend (untouched)

**Primary Dependencies**: Nextcloud 34 (`IGroupManager::isAdmin()` via `NextcloudAdministratorCheck`), existing `AdministratorCheck` port

**Storage**: N/A — no schema or migration change; `role_assignments` reads unchanged

**Testing**: `scripts/dev/phpunit` targeted (`tests/Unit/Reporting/ReportingQueryTest.php`); contract suite `tests/Contract/Controller/DashboardControllerTest.php` unchanged (documents why admin card is unreachable from the finance-officer credential)

**Target Platform**: Nextcloud 34 app `pettycash`, container runtime; host runs only TS/Vitest/lint gates

**Project Type**: Nextcloud application (PHP backend + Vue frontend)

**Performance Goals**: N/A — one extra `isAdmin()` group lookup per scope resolution, same call `/me` already makes

**Constraints**: Layer boundaries preserved (domain stays Nextcloud-free; check enters at application layer via existing port); explicit DI registration style; positional constructor args appended last (T367 lesson)

**Scale/Scope**: 3 backend files + DI registration + unit tests; no frontend, no contract, no migration

## Constitution Check

- I. Specification before implementation: PASS — `spec.md` above states outcome, scope, acceptance criteria, roles (FR-007/FR-018/FR-061), and business rules before code.
- II. Preserve contracts: PASS — no `contracts/ocs-api.yaml` change; dashboard keys stay within the declared five; behaviour change is explicitly authorized by the spec (admin card for platform admins) with no migration needed.
- III. Nextcloud-native/boundaries: PASS — reuses the authoritative Nextcloud group facility through the existing `AdministratorCheck` port; domain (`Role` enum, entities) untouched; PHP/container boundary respected via `scripts/dev/*` wrappers.
- IV. Domain integrity/security/audit: PASS — administrator gains visibility only; `canDecide()` unchanged (false for pure admin); empty cost-centre scope still never widens; no audit/migration impact.
- V. Verified delivery: PASS — targeted PHPUnit scope tests first, affected-area frontend typecheck only if frontend touched (it is not); contract test noted as credential-limited, not re-run as proof.

Register conformance: no compatibility target asserted or changed (ACC-001/ACC-002 untouched).

## Project Structure

```text
specs/002-admin-dashboard-parity/
├── spec.md
├── plan.md              # this file
└── tasks.md             # Phase 2 output

lib/Application/Reporting/DashboardQuery.php   # + AdministratorCheck dependency
lib/Application/Reporting/ReportScope.php      # + platform-admin signal
lib/AppInfo/Application.php                    # DI: pass AdministratorCheck
tests/Unit/Reporting/ReportingQueryTest.php    # regression coverage
```

**Structure Decision**: Existing Nextcloud-app layout kept; no new layers, no new services, no frontend change.

## Design

### Change 1 — `ReportScope::forActor()` gains an opt-in platform signal

```php
public static function forActor(
    RoleAssignmentRepository $assignments,
    string $actorUid,
    DateTimeImmutable $on,
    bool $isPlatformAdministrator = false
): self
```

- After the assignment loop, if `$isPlatformAdministrator` is true: append `Role::Administrator` (deduped by the existing `array_unique`) and set `$administrator = true`.
- Cost-centre ids untouched: platform admin invents no managed cost centres.
- Default `false` keeps every existing direct caller (unit tests) at current meaning.

### Change 2 — `DashboardQuery` consults the port

```php
public function __construct(
    private RoleAssignmentRepository $roleAssignments,
    private Clock $clock,
    private AdministratorCheck $administrators
) {}
```

`scopeFor()` becomes:

```php
return ReportScope::forActor(
    $this->roleAssignments,
    $actorUid,
    $this->clock->now(),
    $this->administrators->isAdministrator($actorUid)
);
```

This mirrors `SessionController::resolveRoles()` (`lib/Controller/SessionController.php:141-145`), making the two agree by construction. `ReportController`/`ExportController` inherit the fix via the same `scopeFor()`.

### Change 3 — DI registration

`lib/AppInfo/Application.php:675-680`: append `$c->query(AdministratorCheck::class)` (port FQCN `OCA\PettyCash\Application\Port\AdministratorCheck`, already registered at line 134-136) as the last constructor argument.

### Alternatives rejected

- Duplicating the `isAdmin()` call inside `ReportScope` (static value object): would drag Nextcloud services into the application value layer and break the domain/application boundary.
- Patching `DashboardController` alone: leaves `ReportController`/`ExportController` disagreeing via the same `scopeFor()`.
- Frontend-only unhiding: the bucket is absent server-side; rendering cannot invent figures and authorization is server-authoritative per `plan.md`.

## Complexity Tracking

No constitution violations to justify.

## Verification

1. New/updated unit tests in `tests/Unit/Reporting/ReportingQueryTest.php` (platform admin with no assignments → `administrator=true`, has `Administrator` role, `canDecide()` false; non-admin unchanged).
2. `scripts/dev/phpunit <targeted>` for the reporting unit suite.
3. `git status`/`git diff` review confirming only the listed files changed.
