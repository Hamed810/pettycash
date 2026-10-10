# Implementation Plan: In-App Navigation

**Branch**: `004-in-app-navigation` | **Date**: 2026-10-09 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/004-in-app-navigation/spec.md`

## Summary

Every primary frontend route is reachable by URL but most have no visible entry point: `AppRoot.vue` has no navigation shell, the dashboard renders metrics with no links, `accounts`/`admin-configuration`/`admin-funding` have zero inbound links, and review queues are reachable only from inside each other. The plan adds one role-aware in-app navigation (dashboard, sheets, queues, returns, accounts, administration for admins only) plus back-links from deep views, reusing the existing session context (`GET /api/v1/me` → roles/capabilities) and existing route names. No route is renamed, no server authorization changes, no migration.

## Technical Context

**Language/Version**: TypeScript 5, Vue 3 (script setup), vite build

**Primary Dependencies**: `vue-router` (hash history), `pinia` (`useAuthStore`), `@nextcloud/vue` 9.x (`NcAppNavigation`-family or plain `RouterLink` list — decided in research), `@nextcloud/l10n` `t()`

**Storage**: N/A (no persistence change; session context already in `useAuthStore` via `fetchSessionContext`)

**Testing**: Vitest (`npm test` / `vitest run`), existing guards `tests/Frontend/routeNameIntegrity.spec.ts` (route names must exist), `NextcloudVueContract.spec.ts` (no Vuetify/`v-*`), `RtlAccessibility.spec.ts` / `fr071NarrowViewport.spec.ts` (RTL + 390px), `keyboard-accessibility.spec.ts`

**Target Platform**: Nextcloud 34 app page (`/apps/pettycash/` → hash routes), Chrome/Edge/Firefox desktop + mobile, Persian RTL

**Project Type**: Nextcloud app frontend (Vue SPA inside `templates/main.php` mount `#pettycash-app`)

**Performance Goals**: Navigation renders with session already loaded; no extra OCS round-trip per click (reuse `auth` store state)

**Constraints**: Approved UI library is `@nextcloud/vue` 9.x — no Vuetify, no `v-*` components; navigation is usability only, never an authorization boundary (server `AdministratorCheck` + per-controller scope checks unchanged); FR-071: usable at 390px without horizontal scroll

**Scale/Scope**: ~19 routes in `src/router/index.ts`, 1 new nav component + ~6 edited views, ~3 new/edited specs; V1 scale <100 users unaffected

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Specification Before Implementation**: PASS — `spec.md` (FR-001..007, SC-001..004) governs; plan traces to it; no code before tasks.
- **II. Preserve Contracts and Existing Behavior**: PASS — no route renamed/removed, no OCS contract change, no migration; deep links keep working; auth behavior unchanged.
- **III. Nextcloud-Native, Boundary-Conscious**: PASS — Vue 3 + TS + `@nextcloud/vue` 9.x retained; Persian RTL + responsive preserved; no new framework; no compat-target change (Nextcloud 34, PHP untouched).
- **IV. Domain Integrity, Security, Auditability**: PASS — navigation adds links only; all workflow/role/scope enforcement stays server-side; hiding admin entry from non-admins is disclosure hygiene, not a grant (explicit FR-006). No audit/revision surface touched.
- **V. Verified and Documented Delivery**: PASS — verification defined before implementation (route-integrity, contract, RTL/narrow, keyboard, new nav specs); docs updated in-change (spec/plan/tasks + report).

Post-design re-check: no new violations introduced (still frontend-only, no backend boundary crossed).

## Project Structure

### Documentation (this feature)

```text
specs/004-in-app-navigation/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output (navigation contract)
└── tasks.md             # Phase 2 output (NOT created by /speckit.plan)
```

### Source Code (repository root)

```text
src/
├── components/
│   └── navigation/
│       └── AppNavigation.vue      # NEW: role-aware nav list
├── AppRoot.vue                    # EDIT: render nav shell + RouterView
├── router/index.ts                # READ-ONLY (no route changes; referenced by contract test)
├── stores/auth.ts                 # READ-ONLY (session source; no change expected)
├── views/
│   ├── DashboardView.vue          # EDIT: entry links / clickable cards
│   ├── admin/ConfigurationView.vue# EDIT: back-link to dashboard (minor)
│   ├── admin/FundingView.vue      # EDIT: back-link to dashboard (minor)
│   └── reviews/...                # EDIT only if back-link missing (minor)
└── types/
    └── navigation.ts              # NEW: entry type (route name + required role)

tests/Frontend/
├── Component/Navigation.spec.ts   # NEW: role-gated entries, links resolve, back-links
└── routeNameIntegrity.spec.ts     # EXISTING guard (must stay green)
```

**Structure Decision**: Single-project frontend slice. No backend, migration, or OCS contract change; PHP `lib/` is out of scope except as the unchanged authorization authority.

## Complexity Tracking

> No constitution violations to justify. No entry.
