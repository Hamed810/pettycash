# Implementation Plan: Nextcloud App-Menu Entry

**Branch**: `003-app-menu-entry` | **Date**: 2026-10-09 | **Spec**: `specs/003-app-menu-entry/spec.md`

**Input**: Feature specification from `/specs/003-app-menu-entry/spec.md`

## Summary

Add a `<navigations>` block to `appinfo/info.xml` pointing at the existing `pettycash.page.index` route, and ship the referenced `img/app.svg` icon. Metadata-only change: no PHP, no routes, no migration, no version bump.

## Technical Context

**Language/Version**: None (XML + SVG static assets); PHP runtime untouched (container-only boundary respected — no PHP executed for this change)

**Primary Dependencies**: Nextcloud 34 app-metadata parsing (`info.xsd`, `InfoParser`); reference patterns read from the same runtime (`files`, `activity` apps)

**Storage**: N/A — no schema or migration change

**Testing**: Python XML well-formedness check + file-existence check (host-safe, no container PHP needed); manual browser-menu click as acceptance

**Target Platform**: Nextcloud 34 app menu

**Project Type**: Nextcloud application metadata fix

**Performance Goals**: N/A

**Constraints**: `info.xsd` element order must hold (dependencies order already has a comment about `xs:sequence`); `<version>` stays `1.3.1`; icon must be a valid SVG in `img/`

**Scale/Scope**: 1 file edited (`appinfo/info.xml`), 1 file added (`img/app.svg`)

## Constitution Check

- I. Specification before implementation: PASS — `spec.md` states outcome, scope, acceptance criteria before the edit.
- II. Preserve contracts: PASS — no OCS/API contract, route, or behavior change; the change only declares the existing page to the platform menu. No migration, no version change.
- III. Nextcloud-native/boundaries: PASS — uses the platform's own `info.xml` navigation mechanism, following the `activity` app pattern from the same runtime; no parallel navigation system.
- IV. Domain integrity/security/audit: PASS — no authorization change; page access rules (`#[NoAdminRequired]`, server-side OCS checks) untouched.
- V. Verified delivery: PASS — XML parse check + icon check + enabled-state check; browser-menu click is the documented manual acceptance.

Register conformance: no compatibility target asserted or changed (ACC-001/ACC-002 untouched).

## Project Structure

```text
specs/003-app-menu-entry/
├── spec.md
├── plan.md              # this file
└── tasks.md

appinfo/info.xml          # + <navigations> block
img/app.svg               # new menu icon
```

**Structure Decision**: Standard Nextcloud app layout; `img/` is the conventional icon location the `<icon>` element references.

## Design

### info.xml placement and content

Insert before the closing `</info>`, after `<dependencies>` (matching `files`/`activity` apps where navigations come after dependencies):

```xml
<navigations>
    <navigation>
        <name>Petty Cash</name>
        <route>pettycash.page.index</route>
        <icon>app.svg</icon>
        <order>50</order>
    </navigation>
</navigations>
```

- `<route>pettycash.page.index</route>`: app id `pettycash` + `page#index` from `appinfo/routes.php:7` = `pettycash.page.index` (Nextcloud `{appid}.{controller}#{method}` → `{appid}.{controller}.{method}` convention, as in `activity.Activities.index`).
- `<order>50</order>`: places the entry after core entries (`files` 0, `activity` 1) without claiming a core slot.
- `<icon>app.svg</icon>`: resolved relative to the app's `img/` directory (same as `activity.svg` in the activity app).

### Icon

Minimal single-path wallet/banknote SVG on transparent background, white (`#fff`) fill to match Nextcloud's monochrome menu-icon style. No text, no embedded fonts, no external references.

### Alternatives rejected

- Singular `<navigation>` (old docs): the live runtime's own apps (`files`, `activity` on this exact Nextcloud) use plural `<navigations>`; follow the runtime, not stale docs (Context7 was unavailable — invalid API key — so runtime evidence is authoritative here).
- Pointing at an OCS route: menu routes must be page routes; OCS URLs cannot be navigation targets.
- Version bump: nothing migration-gated changed; the `<version>` ≥ migration-prefix rule (`Version010300` → 1.3.0) is unaffected at 1.3.1.

## Complexity Tracking

No constitution violations to justify.

## Verification

1. Host-side: Python `xml.etree` parses `appinfo/info.xml`, asserts the navigation block (name/route/icon/order), asserts `img/app.svg` exists and parses as SVG.
2. Container-side (read-only): `occ app:list` still shows `pettycash` enabled.
3. Manual acceptance: signed-in browser → app menu shows "Petty Cash" → click → dashboard shell 200.
