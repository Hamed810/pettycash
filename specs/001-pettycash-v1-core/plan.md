# Implementation Plan: PettyCash V1 Core Workflow

**Branch**: `001-pettycash-v1-core` | **Date**: 2026-09-20 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-pettycash-v1-core/spec.md`

## Summary

Implement the full PettyCash V1 workflow as one modular Nextcloud application. Preserve the
existing application identity (`pettycash`), `OCA\PettyCash` namespace, AppFramework entry point,
explicit route file, and Vue 3/TypeScript direction. Extend the skeleton with thin Nextcloud page
and OCS controllers, application command/query services, explicit domain policies and workflow
state transitions, Nextcloud-backed persistence and private evidence storage, and a single
Nextcloud-mounted Vue application.

Delivery follows vertical slices: platform foundation; configuration and accounts; purchaser
capture; management review; correction/revisions; finance settlement; audit and visibility; then
reports, exports, and compatibility hardening. New V1 tables use the `pc_` family and MUST neither
drop nor reinterpret the legacy `oc_pcash_*` tables found in the shared environment.

## Technical Context

**Language/Version**: PHP 8.2–8.5 for the Nextcloud runtime; TypeScript 5 and Vue 3 for the browser
application; Node.js 20 LTS or a later toolchain explicitly verified with the pinned frontend
dependencies at build time

**Primary Dependencies**: Nextcloud 34 AppFramework and public OCP APIs; Nextcloud database,
identity, logging, localization, routing, and AppData services; Vue 3; `@nextcloud/vue` 9.x;
`@nextcloud/axios`; `@nextcloud/router`; `@nextcloud/l10n`; Pinia; Vue Router; Vite through the
official Nextcloud configuration; PhpSpreadsheet for XLSX; tc-lib-pdf for PDF; native CSV and ZIP
streaming

**Storage**: MariaDB through Nextcloud migrations, query builder, and database connection; private
evidence and transient export artifacts through Nextcloud `IAppData`; no direct container database
access and no independent storage service

**Testing**: PHPUnit unit, integration, migration, storage, workflow, and controller-contract
tests; Psalm and Nextcloud coding standards; Vitest, Vue Test Utils, and strict type checking;
Playwright against the real Nextcloud 34 environment; manual Persian RTL browser and mobile
acceptance checks

**Target Platform**: Nextcloud 34.x web application in the existing Apache/MariaDB deployment;
Chrome 120+, Edge 120+, Firefox 115+ (ESR); responsive desktop and mobile-sized browser layouts

**Project Type**: Single deployable Nextcloud modular-monolith web application with PHP backend,
OCS JSON contracts, and an embedded Vue single-page interface

**Performance Goals**: At the V1 dataset of up to 100 active users and 10,000 annual expense
transactions, at least 95% of routine list, detail, history, and evidence views become usable within
3 seconds (p95 <3s), error rate <0.1%, and zero data loss; all list/report queries are
server-paginated and indexed

**Constraints**: Persian RTL V1 UI with all listed elements (sheet status, transaction status, current stage, prior decisions, history, evidence) visible without horizontal scroll at 390px viewport, status color/icon meeting WCAG AA contrast, text ≥12pt Persian font; server-authoritative authorization with explicit multi-role decision matrix: self-review permitted when user holds required review role + scope + stage permission, ownership grants purchaser rights only; append-only business audit with minimum 7-year retention; immutable prior revisions; no destructive migration of historical data; atomic workflow and ledger changes; shared finance queue; no automatic escalation or mutation retry; no independent authentication, logging, backup, or file system; evidence file size limit 100 MB per file (host file service limit, no separate PettyCash limit); zero-active-account purchasers get default rial account auto-created on first sheet creation (idempotent provisioning per FR-020); public evidence links have configurable TTL (default 30 days, max 1 year) with per-access audit logging in configuration audit per FR-031; reporting periods for group exports are predefined calendar periods (month, quarter, year) plus custom date range per FR-068

**Scale/Scope**: Eight user journeys, 76 functional requirements, five business roles, three review
stages, approximately 15 persistent entity families, a role-aware dashboard, reports, and Excel,
CSV, PDF, and grouped-evidence export

## Constitution Check

*GATE: Passed before Phase 0 research and re-checked after Phase 1 design.*

- **I. Specification Before Implementation — PASS**: The finalized specification supplies
  priorities, acceptance scenarios, requirements, success criteria, assumptions, and resolved
  business decisions. This plan resolves architecture, data, workflow, API, migration, security,
  and testing decisions before tasks are generated.
- **II. Preserve Contracts and Existing Behavior — PASS**: The plan retains the application ID,
  namespace, Nextcloud boundary, PHP/Vue split, and existing shared environment. Migrations are
  forward-only, use new `pc_*` tables, and explicitly leave legacy tables and other applications
  untouched.
- **III. Nextcloud-Native Architecture — PASS**: Authentication, users, files, technical logging,
  routing, dependency injection, database access, localization, and security remain on supported
  Nextcloud services. The PHP floor is corrected to 8.2 because that is the minimum compatible
  runtime for the declared Nextcloud 34 target; this narrows rather than replaces the existing
  PHP 8.1+ declaration.
- **IV. Domain Integrity, Security, and Auditability — PASS**: The design centralizes state
  transitions and object authorization, snapshots historical actor context, creates immutable
  revisions and audit records with minimum 7-year retention per FR-057, scopes every query, and
  makes finance settlement atomic and idempotent.
- **V. Verified and Documented Delivery — PASS**: The plan defines unit, integration, contract,
  end-to-end, performance, browser, responsive, and manual acceptance coverage. The quickstart
  provides repeatable validation steps.
- **Architecture constraints — PASS**: One Nextcloud app and one database boundary are retained.
  No microservice, parallel authentication system, independent logger, or speculative distributed
  component is introduced.

### Post-Design Re-check

Phase 1 artifacts remain compliant. The data model preserves historical records with minimum 7-year
audit retention per FR-057, contracts expose only role-scoped resources with configuration audit
visibility per FR-055/FR-056, evidence uses Nextcloud private storage with 100 MB/file size limit
per FR-074, UI meets measurable Persian RTL criteria (390px viewport, WCAG AA, ≥12pt font),
performance targets include error rate <0.1% and zero data loss, browser targets are Chrome 120+,
Edge 120+, Firefox 115+ (ESR) per FR-072, advanced evidence restrictions (classification, redaction,
watermarking, extended logging, time-limited access, type-specific permissions) deferred to V2 per
FR-030, public evidence links have configurable TTL (default 30 days, max 1 year) with per-access
audit logging in configuration audit per FR-031, zero-active-account purchasers get default rial
account auto-created on first sheet creation (idempotent provisioning per FR-020), reporting periods
for group exports are predefined calendar periods (month, quarter, year) plus custom date range per
FR-068, sheet summaries show stage-approved totals as separate columns (Cost Center Approved | Senior
Approved | Finance Approved) alongside latest effective outcome column per FR-062, and the source
layout extends the existing skeleton without replacing its application boundary. No constitutional
exception is required.

## Project Structure

### Documentation (this feature)

```text
specs/001-pettycash-v1-core/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── ocs-api.yaml
│   ├── workflow.md
│   └── exports.md
├── checklists/
│   └── requirements.md
└── tasks.md                  # Created later by /speckit-tasks
```

### Source Code (repository root)

```text
appinfo/
├── info.xml
└── routes.php

lib/
├── AppInfo/Application.php
├── Controller/              # Thin page and OCS transport adapters
├── Application/
│   ├── Command/             # State-changing use cases
│   ├── Query/               # Role-scoped read use cases
│   ├── Dto/
│   └── Port/                # Identity, clock, file, export, transaction ports
├── Domain/
│   ├── Account/
│   ├── Configuration/
│   ├── Expense/
│   ├── Workflow/
│   ├── Audit/
│   └── Shared/              # Money, identifiers, domain errors
├── Infrastructure/
│   ├── Nextcloud/           # User, admin, logging, clock, AppData adapters
│   ├── Persistence/         # Database repositories and projections
│   ├── Export/              # CSV, XLSX, PDF, evidence archive adapters
│   └── Http/                # Stream responses and error mapping
└── Migration/               # Forward-only Nextcloud schema migrations

src/
├── main.ts
├── AppRoot.vue
├── router/
├── services/                # Typed OCS client; components never call HTTP directly
├── stores/                  # Session and feature stores; no business-rule authority
├── composables/
├── formatters/
├── components/
├── views/
└── styles/                  # Logical properties, RTL, responsive, theme variables

templates/main.php           # Nextcloud page mount point only
l10n/                        # Persian source and generated translation artifacts
js/                          # Generated deployable browser bundles
css/                         # Generated/served application styles

tests/
├── Unit/
│   ├── Domain/
│   ├── Application/
│   ├── Security/
│   └── Export/
├── Integration/
│   ├── Persistence/
│   ├── Migration/
│   ├── Storage/
│   └── Workflow/
├── Contract/Controller/
├── Frontend/
│   ├── Unit/
│   └── Component/
└── E2E/
```

**Structure Decision**: Use one modular Nextcloud application. The current `appinfo/`, `lib/`,
`js/`, and `css/` roots remain. Add the already-documented `src/`, `templates/`, `l10n/`, and
`tests/` roots. PHP follows Domain/Application/Infrastructure separation inside the existing
`OCA\PettyCash` namespace. Vue/TypeScript source lives in `src/`; `js/` and `css/` become generated
or served assets rather than a second frontend project.

## Delivery Strategy

1. **Foundation**: Correct the PHP compatibility declaration, establish locked build/test tools,
   register the page shell and OCS conventions, add shared IDs/money/time types, and prove clean
   installation beside any legacy migration history.
2. **Configuration and accounts**: Add currencies, cost centers, role assignments, expense types,
   default rial accounts, charges, authorization policies, and configuration audit.
3. **Purchaser vertical slice**: Add sheets, draft transactions, evidence storage, validation,
   submission, purchaser lists/details, and submission audit.
4. **Management vertical slice**: Add cost-center and senior queues, decisions with cost-center decisions visible to senior as advisory (full details, senior decides independently without forced acknowledgment), state transitions, aggregate sheet status, and permission tests including allowed self-review.
5. **Correction vertical slice**: Add returned work, correction drafts, immutable revision
   activation, evidence history, and restart of the full review path.
6. **Finance vertical slice**: Add the shared queue, finance decision restrictions, account
   movements, atomic settlement, idempotency, concurrency protection, and balance reconciliation.
7. **Visibility vertical slice**: Add role dashboard, search/filter projections, latest-effective
    and stage totals, audit timeline/table with 7-year retention policy enforcement and configuration
    audit visibility per role scope (admins see all, finance see charged accounts, managers see
    assigned cost centers), account history, and responsive Persian views.
8. **Reporting and export**: Add role-scoped reports, CSV/XLSX/PDF exports, grouped evidence ZIPs,
   public evidence-link administration, and export-security tests.
9. **Hardening**: Run migration, failure-injection, concurrency, target-volume, browser, RTL,
   responsive, accessibility, and recovery checks; update operational and release documentation.

## Complexity Tracking

No constitution violations or exceptional architecture components require justification. The
layering is contained within one deployable application and is warranted by the number of workflow,
authorization, audit, persistence, and export invariants that must remain independently testable.

**Audit Retention Policy (FR-057)**: The 7-year minimum retention requirement is implemented as a
configurable policy with a hard-coded floor of 7 years. The policy governs archival (moving to cold
storage) and availability (query scope) but never permits deletion or rewriting of business audit
records. Implementation touches:
- Persistence: `pc_audit_event` and `pc_config_audit_event` tables retain all records indefinitely;
  retention policy metadata stored in configuration
- Domain: `AuditRetentionPolicy` value object with minimum 7-year floor
- Application: `RetentionPolicyService` for policy configuration and compliance verification
- Testing: Integration test verifies records younger than 7 years are never archived/deleted
- Documentation: `docs/audit-retention.md` (T100) documents the policy

**Configuration Audit Visibility (FR-055, FR-056)**: Configuration audit events follow role-based
scope separate from transaction audit. System administrators see all configuration audit events;
finance officers see configuration audit for accounts they charge; cost center and senior managers
see configuration audit for their assigned cost centers. This scope is enforced in
`AuditQueryService` and reflected in exports.

**Evidence Handling (FR-030, FR-074)**: Evidence files limited to 100 MB per file per host file
service limit (FR-074). Advanced evidence-specific restrictions (document classification levels,
redaction rules, watermarking, access logging beyond audit, time-limited evidence access,
evidence-type-specific permissions) explicitly deferred to V2 per FR-030. V1 permits all authorized
viewers of a transaction to access all its evidence without additional restrictions.

**Public Evidence Links (FR-031)**: Public evidence links are administrator-controlled with
configurable TTL (default 30 days, maximum 1 year) and per-access audit logging (viewer UID,
timestamp, IP) recorded in configuration audit. Links are disabled by default, require explicit
activation, and are fully audited on create/deactivate/access.

**Default Account Provisioning (FR-014, FR-020)**: Zero-active-account purchasers receive a default
active rial petty-cash account auto-created on first sheet creation attempt. This idempotent
provisioning ensures seamless onboarding while maintaining the invariant that every user has at
least one active account.

**Reporting Periods (FR-068)**: Group exports support predefined calendar periods (month, quarter,
year) plus custom date range. These periods are UI filter presets that map to date range queries;
no separate fiscal calendar configuration in V1.

**Stage-Approved Totals Presentation (FR-062)**: Sheet summaries display approved totals as separate
columns — Cost Center Approved, Senior Manager Approved, Finance Approved — alongside the latest
effective outcome column. This applies to dashboards, sheet lists, sheet details, and exports.
