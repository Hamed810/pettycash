# Feature Specification: Nextcloud App-Menu Entry for PettyCash

**Feature Branch**: `003-app-menu-entry`

**Created**: 2026-10-09

**Status**: Approved for implementation

**Input**: User description: "why I can not see pettycash in app menu of nextcloud"

## Problem

The app is installed and enabled (`occ app:list` shows `pettycash: 1.3.1`), and the page route works (`page#index` → `/apps/pettycash/`, `PageController::index()`, `templates/main.php`). But `appinfo/info.xml` contains no `<navigations>` block, and Nextcloud builds the app menu from that block — so PettyCash is reachable only via direct URL, never via the menu. There is also no `img/` icon directory, which a navigation entry needs.

Reference (read-only, from the same runtime): `files` app uses `<navigations><navigation><name>/<route>/<order>`; `activity` app adds `<icon>activity.svg</icon>` with the file in its `img/` directory.

## User Scenarios & Testing

### User Story 1 — App appears in the Nextcloud app menu (Priority: P1)

A signed-in user opens the Nextcloud app menu and sees "Petty Cash"; clicking it opens `/apps/pettycash/` (the existing `page#index` shell). All in-app authorization is unchanged: the page shell is already `#[NoAdminRequired]` and every action is authorized server-side by the OCS controllers.

**Why this priority**: This is the reported defect. Without the menu entry the app is effectively hidden.

**Independent Test**: Open the app menu in a signed-in browser → "Petty Cash" entry present → click lands on the dashboard shell with HTTP 200.

**Acceptance Scenarios**:

1. **Given** the app installed and enabled, **When** a signed-in user opens the app menu, **Then** a "Petty Cash" entry is listed.
2. **Given** the menu entry, **When** it is clicked, **Then** the browser lands on the existing `pettycash.page.index` page (HTTP 200, dashboard shell).
3. **Given** a non-administrator (e.g. purchaser), **When** the page loads, **Then** it loads (no 403) — already true via `#[NoAdminRequired]`, unchanged by this fix.

### Edge Cases

- Icon missing or unreadable: the entry must ship with its `img/app.svg` icon so it never renders broken.
- Route typo: `<route>` must exactly match the registered `page#index` route (`pettycash.page.index`); verified against `appinfo/routes.php`.
- Version number: no migration involved, so `<version>` stays `1.3.1` (the `>= migration prefix` rule is unaffected).

## Requirements

### Functional Requirements

- **FR-001**: `appinfo/info.xml` MUST declare a `<navigations><navigation>` entry with `<name>Petty Cash</name>` and `<route>pettycash.page.index</route>`.
- **FR-002**: The entry MUST reference an icon file shipped in the app (`img/app.svg`), following the `activity` app pattern.
- **FR-003**: No route, controller, template, contract, migration, or version change: the entry points at the existing page.
- **FR-004**: `info.xml` MUST remain well-formed XML conforming to the existing `info.xsd` element order (navigation block placed where the schema expects it).

### Key Entities

- **`appinfo/info.xml`**: the only metadata file changed (navigation block added).
- **`img/app.svg`**: the only new file (menu icon).

## Success Criteria

- **SC-001**: `info.xml` parses as well-formed XML and contains the navigation entry pointing at `pettycash.page.index`.
- **SC-002**: `img/app.svg` exists, is a valid SVG, and is the file the entry references.
- **SC-003**: App remains enabled and the page URL still answers 200 (no regression from a metadata-only change).

## Assumptions

- Menu rendering itself is Nextcloud platform behavior; the app's obligation is the declaration plus the icon.
- Browser-menu visual confirmation is manual (one screenshot/click); automated suites (PHPUnit/Vitest/Playwright) do not assert platform menu rendering.
