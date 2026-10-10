# Feature Specification: Admin Dashboard Parity for Nextcloud Admins

**Feature Branch**: `002-admin-dashboard-parity`

**Created**: 2026-10-09

**Status**: Approved for implementation

**Input**: User description: "petty cash dashboard shows nothing special for admin user — check why and fix it"

## Problem

A Nextcloud administrator (member of the `admin` group, `IGroupManager::isAdmin() === true`) with no pettycash `ADMINISTRATOR` role-assignment row sees no `admin` dashboard card — only the default `purchaser` card. `/me` reports `ADMINISTRATOR` for the same user, so the two endpoints disagree about the same person.

Root cause (verified in source):

* `lib/Controller/SessionController.php:141-145` (`resolveRoles`) appends `ADMINISTRATOR` when `AdministratorCheck->isAdministrator($uid)` is true.
* `lib/Application/Reporting/ReportScope.php:61-86` (`forActor`) sets `administrator=true` only from an assignment row with `role === Administrator`. No `AdministratorCheck` is consulted.
* `lib/Application/Reporting/DashboardQuery.php:45-48` (`scopeFor`) delegates to `ReportScope::forActor()`, so it inherits the gap.
* `lib/Controller/DashboardController.php:127-143` emits the `admin` card only when `$scope->administrator` is true.
* Frontend `src/stores/dashboard.ts:31-56` maps session `ADMINISTRATOR` to the `admin` bucket and renders only buckets the projection sent — so a missing backend bucket is a missing card, not a render bug.

## User Scenarios & Testing

### User Story 1 — Nextcloud admin sees the administrative summary (Priority: P1)

A Nextcloud admin opens the dashboard and sees the `admin` card (`totalSheets`, `totalTransactions`, `totalAccounts` exact counts) alongside their `purchaser` card.

**Why this priority**: This is the reported defect. `/me` already promises the role; the dashboard must honour it.

**Independent Test**: As a Nextcloud admin with no assignment rows, `GET /me` lists `ADMINISTRATOR` and `GET /dashboard` contains both `purchaser` and `admin` keys.

**Acceptance Scenarios**:

1. **Given** a Nextcloud admin with zero role-assignment rows, **When** `GET /dashboard` is called, **Then** the response contains `purchaser` and `admin` cards, and the `admin` card carries `figuresExact: true` with integer `totalSheets`, `totalTransactions`, `totalAccounts`.
2. **Given** a regular purchaser (not Nextcloud admin, no assignments), **When** `GET /dashboard` is called, **Then** the response contains only `purchaser` and no `admin` key (no widening).
3. **Given** a Nextcloud admin who also holds no other grant, **When** review/finance queues are listed, **Then** no review or finance decision affordance is offered (FR-007: visibility without authority — unchanged).

### User Story 2 — Reports/exports agree with the dashboard (Priority: P2)

`ReportController` and `ExportController` resolve scope through the same `DashboardQuery::scopeFor()`, so fixing scope there fixes all three surfaces at once with no per-controller patch.

**Why this priority**: Prevents fixing the dashboard while leaving reports/exports disagreeing about the same actor.

**Independent Test**: Same actor, `GET /dashboard`, `GET /reports`, `POST /exports` all apply administrator visibility consistently.

**Acceptance Scenarios**:

1. **Given** the US1 actor, **When** any of the three scope consumers runs, **Then** `ReportScope->administrator` is true in all of them.

### Edge Cases

- Assignment-based administrator (row exists, not a Nextcloud admin): still administrator — no regression.
- Nextcloud admin whose assignment rows are expired/future-dated: still administrator via platform check; cost-centre scope still comes only from valid assignment rows (empty stays empty, never widens).
- `ReportScope::forActor()` direct callers (unit tests, future code): new platform-admin input defaults to `false`, so existing call sites keep their current meaning.

## Requirements

### Functional Requirements

- **FR-001**: `DashboardQuery::scopeFor()` MUST treat a Nextcloud administrator (`AdministratorCheck->isAdministrator(uid)`) as administrator even with no `ADMINISTRATOR` assignment row.
- **FR-002**: `ReportScope::forActor()` MUST accept the platform-administrator signal and, when set, include the `Administrator` role and set `administrator=true`, while leaving cost-centre scope derived only from valid assignment rows.
- **FR-003**: `/me` and `/dashboard` MUST agree: every role `/me` reports for an actor MUST have its dashboard card present (union rule already asserted by `tests/Contract/Controller/DashboardControllerTest.php:90-123`).
- **FR-004**: FR-007 is unchanged: administrator scope MUST confer visibility only — `DashboardQuery::canDecide()` MUST remain false for a pure administrator.
- **FR-005**: No new top-level dashboard keys, no contract change: the fix emits the existing `admin` bucket (`contracts/ocs-api.yaml` `Dashboard` schema) for the newly covered actor, nothing else.

### Key Entities

- **ReportScope**: actor visibility (`roles`, `costCenterDbIds`, `administrator`) — the single scope value dashboard, reports, and exports share.
- **DashboardQuery**: scope resolution entry point (`scopeFor`) — the one place the platform check is added.
- **AdministratorCheck**: platform authority (`isAdministrator`) — already authoritative in `SessionController`, now also consulted in scope resolution.

## Success Criteria

- **SC-001**: A Nextcloud admin with no assignments receives `admin` + `purchaser` cards from `GET /dashboard`.
- **SC-002**: A non-admin purchaser with no assignments still receives only `purchaser` (no scope widening).
- **SC-003**: `tests/Unit/Reporting/ReportingQueryTest.php` covers the platform-admin scope path and passes; existing scope tests pass unmodified in intent.
- **SC-004**: No contract change: dashboard keys remain within `{purchaser, costCenter, senior, finance, admin}`.

## Assumptions

- `AdministratorCheck` (Nextcloud `admin` group) remains the definition of platform administrator, matching `SessionController`.
- Pettycash assignment rows remain the only source of cost-centre scope; platform admin does not invent managed cost centres.
- DI follows the existing explicit `Application.php` registration style (positional constructor args, appended last).
