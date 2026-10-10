# Contracts: In-App Navigation

**Feature**: `004-in-app-navigation` | **Date**: 2026-10-09

No OCS/PHP contract change. The contract is between the navigation component and the router + session store.

## Navigation contract (`contracts/navigation.md` scope)

1. Every `NavigationEntry.name` MUST equal a `name` in `src/router/index.ts` `routes` (machine-checked by `tests/Frontend/routeNameIntegrity.spec.ts`).
2. Entries rendered for a session MUST equal `visibleFor(session)` over `{ roles, capabilities, session-load failure }` from `useAuthStore` — no other input may add or remove entries.
3. `admin-configuration` and `admin-funding` MUST NOT render when `capabilities.canAdminister !== true`.
4. Every primary view MUST render at least: link to `dashboard` + link to its owning section entry (admin views → administration entry; queue detail → owning queue).
5. All labels via `t('pettycash', 'navigation.*')`; every entry carries its documented `data-test` hook.
6. No `v-*` (Vuetify) elements; `@nextcloud/vue` 9.x components only where a component is needed at all (plain `<nav><ul><RouterLink>` is acceptable).

## Regression guards (existing, must stay green)

- `tests/Frontend/routeNameIntegrity.spec.ts` — unknown route names fail the build.
- `tests/Frontend/Component/NextcloudVueContract.spec.ts` — Vuetify ban.
- `tests/Frontend/Component/RtlAccessibility.spec.ts`, `fr071NarrowViewport.spec.ts`, `keyboard-accessibility.spec.ts` — RTL / 390px / keyboard.
