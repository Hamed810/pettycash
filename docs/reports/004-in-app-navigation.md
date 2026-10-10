# 004-in-app-navigation — role-aware in-app navigation

**Date:** 2026-10-09
**Spec:** `specs/004-in-app-navigation/` (spec → plan → tasks → implement → converge-assess → implement)
**Status:** complete; automated gates green, live-lab headless validation 13/13 (2026-10-10), manual walkthrough passed 2026-10-10 with no defects (T020)

## Problem

Every primary route was URL-only: `AppRoot.vue` had no navigation shell, the
dashboard rendered metrics with no links, `accounts`/`admin-configuration`/
`admin-funding` had zero inbound links, and review queues were reachable only
from inside each other. An admin could not reach senior-manager assignment
without typing `#/admin/configuration` in the address bar.

## What was delivered

| File | Change |
|---|---|
| `src/types/navigation.ts` (new) | `NAVIGATION_ENTRIES` table + `visibleEntries()`; route names stay literal for the integrity scanner |
| `src/components/navigation/AppNavigation.vue` (new) | Plain `<nav><ul><RouterLink>` shell; sole `loadSession()` caller (see below); renders on every route |
| `src/AppRoot.vue` | Renders `AppNavigation` above `RouterView` |
| `src/views/DashboardView.vue` | `quickLinks` reusing `visibleEntries()`; cards stay presentational |
| `src/views/admin/ConfigurationView.vue`, `FundingView.vue` | Dashboard breadcrumb back-links |
| `tests/Frontend/Component/Navigation.spec.ts` (new) | 7 tests: admin/non-admin, role→queue matrix, FR-007, signed-out, resolve, session-load |
| `tests/Frontend/routeNameIntegrity.spec.ts` | Reachability walk seeded with `src/AppRoot.vue` (shell is a render root) |
| `specs/004-in-app-navigation/` | spec, plan, research, data-model, contracts, quickstart, tasks |

Deliberately NOT touched: `src/router/index.ts` (read-only; no route renamed),
`lib/` (no backend change — authorization stays server-side), sheet/transaction
views (shell covers FR-004; several carry unrelated uncommitted changes), `t()`
catalog (none exists in-repo; keys follow the `navigation.*` convention).

## Latent defect found and fixed in passing

`useAuthStore.loadSession()` had **no production caller** — session roles were
permanently empty and every role-gated surface ran on its unknown-session
fallback. `AppNavigation` (mounted once per app lifetime) now issues the single
load; failure is absorbed per the mount idiom since the store records `error`.

## Verification evidence

- `npm test`: 19 files / 436 tests green (incl. new Navigation.spec, route-integrity, NextcloudVueContract, RTL, narrow-viewport, keyboard)
- `npm run typecheck` (`vue-tsc --noEmit`): clean
- `eslint` on all touched files: clean; `stylelint` on touched SFCs: clean
- Production `vite build` not run in this change (regenerates tracked `js/`+`css/` chunks; tree already carries unrelated build dirt) — but the lab-served bundle demonstrably contains the nav code (`nav-admin-configuration` in `js/pettycash-main.mjs`; repo bind-mounted at `/var/www/html/custom_apps/pettycash`), and the smoke below ran against it live.
- `quickstart.md` machine-checkable subset validated headless against the live lab on 2026-10-10 (`test-results/nav-smoke-004.mjs`, chromium, principals `pettycash-e2e-admin` + `pettycash-e2e`): **13/13** — admin 1-click configuration (4/4 sections) + funding with dashboard back-links, non-admin sees no admin entries, purchaser sheets+accounts + quicklinks, direct admin URL loads shell without advertising admin entries, 390px no-scroll with `dir=rtl`, Tab reaches all 6/6 visible nav entries.
- Still owed to a person (T020 remainder): visual RTL feel + by-person keyboard walkthrough at desktop and 390px.
- T022 (2026-10-10): session-failure fallback decided for T004 — failed load renders dashboard+sheets only (`sessionFailed` in `src/types/navigation.ts`, fed from `useAuthStore.error`; `logout()` clears stale errors); contract rule 2 updated. Note: the lab-served bundle predates this change (no rebuild per T019 rationale), so the fallback is unit-covered only until the next build.

## What this does not claim

Navigation is disclosure only (FR-006): hiding admin entries grants nothing;
every action remains enforced by `AdministratorCheck` and per-controller scope
checks. Direct-URL access is preserved for all routes.

Code intelligence: OCI unavailable (empty semantic index, `ocbi search` confirmed); Serena=PageController/SessionController/AdministratorCheck verification + manual init; evidence via exact grep/read of router/views/stores.
