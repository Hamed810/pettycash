# Feature Specification: In-App Navigation

**Feature Branch**: `004-in-app-navigation`

**Created**: 2026-10-09

**Status**: Draft

**Input**: User description: "Missing navigation in this project for different sections — admin cannot reach /admin/configuration without typing it in the address bar. Find and report them, then fix them."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Admin reaches administration without typing URLs (Priority: P1)

An administrator signed in to Nextcloud opens the Petty Cash app and reaches the administration sections (configuration: currencies, cost centers with manager/senior-manager assignment, expense types, finance assignments; funding) by visible navigation, without typing a hash URL in the address bar.

**Why this priority**: This is the reported defect. Configuration management (FR-010/FR-012/FR-013) is an admin-only duty; today `admin-configuration` and `admin-funding` are URL-only routes with zero inbound links, so the duty is undiscoverable.

**Independent Test**: Sign in as an administrator, open `/apps/pettycash/`, click a visible Administration entry, and land on the configuration view showing the four reference-data sections. No manual URL entry.

**Acceptance Scenarios**:

1. **Given** an administrator on any app view, **When** they use the app navigation, **Then** they see an administration entry (configuration + funding) and reach it in one click.
2. **Given** a non-administrator on any app view, **When** they use the app navigation, **Then** they do not see the administration entry (server remains the enforcer; the client simply does not advertise it).
3. **Given** an administrator on the configuration view, **When** they navigate back, **Then** there is a visible path back to the dashboard (no dead end).

---

### User Story 2 - Reviewer reaches their queues from the dashboard (Priority: P1)

A cost-center manager, senior manager, or finance officer sees their work entry points (review queues, returns) from the dashboard/app navigation instead of typing queue URLs.

**Why this priority**: All four queue routes plus the two detail routes are today reachable only by URL or from inside another queue view. Dashboard cards show metrics but carry no links, so reviewers cannot discover work.

**Independent Test**: Sign in with a reviewer role, open the dashboard, and reach each applicable queue (cost-center, senior, finance, returns) by clicking.

**Acceptance Scenarios**:

1. **Given** a reviewer on the dashboard, **When** they look at navigation/cards, **Then** each queue in their role scope is one click away.
2. **Given** a reviewer inside a queue detail, **When** they finish, **Then** a visible link returns them to the owning queue and to the dashboard.

---

### User Story 3 - Purchaser reaches sheets, transactions, and accounts (Priority: P2)

A purchaser reaches sheets, sheet detail/creation, transactions, and their accounts/balance from visible navigation rather than by discovering one flow from another.

**Why this priority**: Sheet/transaction routes are meshed with each other but have no entry from the dashboard; `accounts` has no inbound link at all. The purchaser journey starts at a dead-end dashboard.

**Independent Test**: From the dashboard, reach sheets list, create a sheet, open sheet detail, and open accounts — all by clicking.

**Acceptance Scenarios**:

1. **Given** a purchaser on the dashboard, **When** they use navigation, **Then** sheets and accounts are one click away.
2. **Given** a purchaser on any sheet/transaction view, **When** they use navigation, **Then** they can return to sheets list and dashboard without the address bar.

---

### Edge Cases

- Non-administrator must never gain an admin capability through navigation; hidden links are usability only, every action stays server-authorized (existing `AdministratorCheck` behavior unchanged).
- Direct-URL access must keep working for every route (deep links, bookmarks, tests).
- Persian RTL layout and 390px mobile viewport must keep navigation usable with no horizontal scroll.
- Existing route names/paths must not change (tests and deep links depend on them).
- Finance-assignment roster and role-gated queues must not leak membership to unauthorized callers through navigation labels.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The app MUST provide visible in-app navigation from every primary view to: dashboard, sheets, each applicable review queue, returns, accounts, and (administrators only) administration.
- **FR-002**: The administration entry MUST be visible only to administrators (role/capability from the existing session context) and MUST cover both configuration and funding sections.
- **FR-003**: The dashboard MUST offer one-click entry to the purchaser flow (sheets) and to each reviewer queue in the caller's scope; metric cards MUST NOT be dead ends for actionable work.
- **FR-004**: Every view reachable by navigation MUST offer a visible path back (dashboard and owning section) without using the browser address bar or back button.
- **FR-005**: All existing routes, route names, and direct-URL behavior MUST be preserved; navigation adds links, it does not move or rename destinations.
- **FR-006**: Navigation MUST NOT become an authorization mechanism: hidden links MUST NOT grant access, and shown links MUST NOT bypass existing server-side role/scope checks.
- **FR-007**: Navigation MUST remain usable in Persian RTL on desktop and at 390px mobile width with no horizontal scroll.

### Key Entities

- **Navigation entry**: a visible link/button to a route, optionally gated by caller role (administrator, reviewer scope, purchaser).
- **Session context**: the existing caller identity/roles/capabilities source that decides which entries are shown (no new auth system).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An administrator reaches configuration and funding from the app UI in 1 click each, with zero manual URL entry.
- **SC-002**: A reviewer reaches each in-scope queue and returns to the dashboard in 1 click each, with zero manual URL entry.
- **SC-003**: A purchaser reaches sheets list and accounts from the dashboard in 1 click each, with zero manual URL entry.
- **SC-004**: 100% of existing frontend routes remain directly addressable by their current URL; no route is removed or renamed.

## Assumptions

- Existing session endpoint (`GET /api/v1/me`-family) already exposes administrator status and roles; navigation reuses it rather than building a new identity source.
- Server-side authorization (`AdministratorCheck`, per-controller role/scope checks) is correct and unchanged; this feature adds discoverability only.
- No new top-level Nextcloud app-menu entry is needed; this is in-app navigation inside the existing `/apps/pettycash/` page.
- The approved UI library remains `@nextcloud/vue` 9.x; no new UI framework is introduced for navigation.
