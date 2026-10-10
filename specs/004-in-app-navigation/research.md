# Research: In-App Navigation

**Feature**: `004-in-app-navigation` | **Date**: 2026-10-09

## Decision 1: Where the admin/role signal comes from

- **Decision**: Reuse `useAuthStore` (`src/stores/auth.ts`) fed by `fetchSessionContext()` (`src/services/sessionApi.ts` → OCS `GET /me`), specifically `capabilities.canAdminister` and `roles` (ADMINISTRATOR / FINANCE / COST_CENTER_MANAGER / SENIOR_MANAGER).
- **Rationale**: `SessionController::me` already emits `roles` + `capabilities` + `costCenterCodes`; `ConfigurationController` and queue controllers enforce the same server-side. No new endpoint, no new auth system, no duplication of the role source.
- **Alternatives considered**: New `isAdmin` endpoint (rejected — duplicates `NextcloudAdministratorCheck` exposure); route-meta `requiresAdmin` with `beforeEach` redirect (rejected as sole mechanism — a guard without visible nav still leaves the duty undiscoverable; a guard may be added as defense-in-depth only).

## Decision 2: Navigation component shape

- **Decision**: One new `src/components/navigation/AppNavigation.vue`: a semantic `<nav>` list of `RouterLink :to="{ name }"` entries, filtered by a `src/types/navigation.ts` entry table (`{ name, params?, label key, test id, visibleFor(session) }`). Rendered in `AppRoot.vue` above/beside `RouterView`; session load failure degrades to dashboard+sheets links only (never admin).
- **Rationale**: Single source of truth for entries keeps `routeNameIntegrity.spec.ts` meaningful (literal names stay greppable); role filter is a pure function of session state, trivially unit-testable; `<nav>` + list is keyboard/RTL-friendly and matches existing `nav.sheet-pager` precedent.
- **Alternatives considered**: `NcAppNavigation` full sidebar (rejected unless `@nextcloud/vue` 9.x contract test proves it fits the mount — heavier API surface, same outcome); per-view ad-hoc links only (rejected — recreates the island problem).

## Decision 3: Dashboard cards

- **Decision**: Cards stay presentational (`DashboardCard.vue` unchanged); `DashboardView.vue` adds per-role action links beneath/beside cards (e.g. purchaser → sheets, manager → own queue, finance → finance queue, admin → configuration). No click handler on the whole card (keyboard/AT-hostile).
- **Rationale**: Preserves `DashboardCard` contract tests; links are explicit, focusable, translatable controls rather than div-click behavior.

## Decision 4: Back-links

- **Decision**: Admin views (`ConfigurationView.vue`, `FundingView.vue`) and any queue/detail view missing one get a single `RouterLink dashboard` (+ owning-section link where a queue detail exists). Pattern copied from existing `RouterLink :to="{ name: 'dashboard' }"` breadcrumbs in review views.
- **Rationale**: Satisfies FR-004 with the codebase's own idiom; zero new patterns to learn.

## Decision 5: Verification strategy

- **Decision**: (1) New `tests/Frontend/Component/Navigation.spec.ts`: admin sees admin entries / non-admin does not; every rendered `name` exists in `routes`; back-links present; keyboard focus order sane. (2) Existing `routeNameIntegrity`, `NextcloudVueContract` (no Vuetify), `RtlAccessibility`, `fr071NarrowViewport`, `keyboard-accessibility` stay green. (3) Manual: admin + non-admin click-through at desktop and 390px, RTL.
- **Rationale**: Mirrors constitution V (repeatable automation + documented functional check); T359/T360 lessons (press the button, mount the shipped surface) are encoded in the new spec.
