# Data Model: In-App Navigation

**Feature**: `004-in-app-navigation` | **Date**: 2026-10-09

No persisted entities. The only model is the in-memory navigation entry table.

## Entity: NavigationEntry (client-side, `src/types/navigation.ts`)

| Field | Type | Rule |
|---|---|---|
| `name` | existing `RouteRecordRaw['name']` from `src/router/index.ts` | MUST be a declared route name (enforced by `routeNameIntegrity.spec.ts`); no new routes |
| `labelKey` | i18n key under `navigation.*` | resolved via `t('pettycash', key)`; Persian catalog must name every entry |
| `testId` | string | stable `data-test`/`data-testid` hook per entry (e.g. `nav-admin-configuration`) |
| `visibleFor` | `(session) => boolean` over `{ roles, capabilities.canAdminister }` | admin entries require `canAdminister`; queue entries require owning role; dashboard/sheets visible to any authenticated user |

## Visibility rules (from session, not new state)

- `dashboard`, `sheets`: any authenticated user.
- `review-queue-cost-center` / `review-queue-senior` / `review-queue-finance` / `review-queue-returns`: owning role present in `roles` (finance queue additionally requires `FINANCE`, never admin-alone per FR-007 precedent).
- `accounts`: any authenticated user (server scopes the ledger).
- `admin-configuration`, `admin-funding`: `capabilities.canAdminister === true` only.
- Session load failure / logged out: entries requiring roles are hidden; dashboard link remains.

## State transitions

None. Visibility recomputes from `useAuthStore` state on `loadSession()`; navigation holds no state of its own.
