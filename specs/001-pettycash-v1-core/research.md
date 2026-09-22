# Phase 0 Research: PettyCash V1 Core Workflow

**Date**: 2026-09-20

All technical-context unknowns are resolved for planning. The running Docker container was stopped
during planning, so the quickstart retains an explicit runtime-version verification gate before
implementation dependencies or migrations are installed.

## Decision 1: Preserve a Single Nextcloud Modular Monolith

**Decision**: Keep `pettycash` as one Nextcloud application in the `OCA\PettyCash` namespace. Use
thin controllers, application commands/queries, domain rules, and infrastructure adapters within
`lib/`.

**Rationale**: This preserves the existing application boundary and deployment model while making
workflow, authorization, money, revision, and audit rules independently testable.

**Alternatives considered**:

- A flat controller/service structure was rejected because the workflow and audit invariants would
  become coupled to transport and persistence details.
- Microservices were rejected because V1 scale and the constitution do not justify distributed
  transactions, extra deployment units, or duplicated platform integration.
- The legacy backup was rejected as an implementation base; it remains migration-reference only.

## Decision 2: Correct the PHP Runtime Floor for Nextcloud 34

**Decision**: Plan for PHP 8.2–8.5 and update the implementation-time Composer constraint from
`^8.1` to a compatible 8.2+ range after verifying the active container.

**Rationale**: Nextcloud 34's supported PHP range starts at 8.2. Keeping `^8.1` would permit an
unsupported runtime despite the application's declared Nextcloud 34 dependency.

**Alternatives considered**:

- Retaining PHP 8.1 was rejected as incompatible with the target host version.
- Raising the floor above 8.2 was rejected because the project has not approved dropping a valid
  Nextcloud 34 runtime without need.

## Decision 3: Use Explicit OCS v1 Contracts and a Thin Page Controller

**Decision**: Keep `appinfo/routes.php`; add one authenticated page route and versioned OCS routes
under `/api/v1`. Controllers return OCS `DataResponse` or streaming responses and delegate all
business rules to application services.

**Rationale**: Explicit routes match the existing skeleton, are easy to audit, and give the Vue
client a stable, versioned boundary. OCS preserves Nextcloud authentication, CSRF handling, and
response conventions.

**Alternatives considered**:

- Direct PHP-rendered feature pages were rejected because the declared frontend direction is Vue
  3 and the workflow needs shared interactive state.
- Unversioned ad hoc endpoints were rejected because they would make compatibility and contract
  tests harder.

## Decision 4: Use Nextcloud Database APIs and Forward-Only `pc_*` Migrations

**Decision**: Use Nextcloud schema migrations, database connection, query builder, and explicit
repositories. Create new `pc_*` V1 tables with internal numeric keys and public UUIDs. Inspect
existing migration records before the first migration and leave `oc_pcash_*` legacy tables intact.

**Rationale**: This is portable within the supported Nextcloud database abstraction and prevents
the reused app identity from accidentally colliding with legacy schema or migration history.

**Alternatives considered**:

- A separate ORM was rejected because it duplicates platform facilities and complicates upgrade
  compatibility.
- Reusing old tables was rejected because the requirements explicitly state the legacy app is not
  the V1 design base.
- Destructive cleanup was rejected because it would violate history and shared-environment rules.

## Decision 5: Represent Money in Minor Units

**Decision**: Store all amounts and balances as signed 64-bit integer minor units paired with an
ISO-style currency code and configured fraction digits. Never persist or calculate money as a
floating-point number.

**Rationale**: Exact integer arithmetic supports rial and foreign-currency accounts, consistent
totals, ledger reconciliation, and deterministic audit values.

**Alternatives considered**:

- Floating-point values were rejected because rounding can corrupt balances and totals.
- Formatted localized strings were rejected as storage because presentation rules must not define
  accounting values.

## Decision 6: Centralize Workflow and Sheet Aggregation

**Decision**: Model current transaction stage separately from immutable stage decisions. A single
workflow service validates transitions, inserts decisions and audit events, and recalculates sheet
status. Transactions may be ahead of others; sheet status is the earliest outstanding stage among
active nonterminal revisions. `SUBMITTED` is an atomic milestone, not a long-lived stage.

**Rationale**: Decisions remain transaction-level while the sheet accurately summarizes mixed cost
centers, returns, corrections, and already-finalized transactions.

**Alternatives considered**:

- Making the sheet the decision unit was rejected by the business requirements.
- Storing only a mutable status without decisions was rejected because it loses stage opinions and
  auditability.
- Rewinding every transaction when one is returned was rejected because unaffected outcomes and
  financial movements must remain unchanged.

## Decision 7: Make Financial Mutations Atomic and Idempotent

**Decision**: Use database transactions with a fixed lock order of sheet, transaction, revision,
then account. Require a command UUID for non-repeatable submissions, decisions, corrections,
charges, and finance approvals. Store command payload hashes and results in the same transaction;
add unique decision and consumption-source constraints.

**Rationale**: Network retry, double-click, and concurrent finance actions must never duplicate a
decision, movement, or balance change. Finance approval, movement, balance, state, and audit either
all commit or all roll back.

**Alternatives considered**:

- Client-only duplicate prevention was rejected because it cannot protect direct or concurrent
  requests.
- Distributed locks were rejected because database locking and uniqueness are sufficient at V1
  scale.

## Decision 8: Store Evidence in Private Nextcloud AppData

**Decision**: Store evidence binaries under opaque keys in private `IAppData`; store metadata,
transaction/revision links, state, and audit references in the database. Serve content only through
authorization-checked controllers. Pre-submit removal deletes drafts; post-submit removal only
deactivates metadata and retains the file.

**Rationale**: AppData uses managed Nextcloud storage, survives ordinary user-role changes, avoids
exposing physical paths, and keeps access tied to transaction permissions.

**Alternatives considered**:

- Direct filesystem paths were rejected as environment-specific and unmanaged.
- Purchaser-owned folders were rejected because lifecycle and cross-role access would be coupled to
  the user's personal files.
- External object storage was rejected as an unnecessary V1 service.

## Decision 9: Treat Public Evidence Links as Optional and Disabled by Default

**Decision**: Model administrator-created, revocable public-link records with hashed opaque tokens,
optional expiry, and audit events. Implement this after authenticated evidence flows and keep it
disabled until its security tests pass.

**Rationale**: The specification permits rather than requires public links. Deferring their
activation reduces early exposure while preserving the approved capability.

**Alternatives considered**:

- Exposing AppData paths was rejected because they are not public resources.
- Enabling anonymous links by default was rejected due to financial-document sensitivity.

## Decision 10: Use a Vue 3 Source Tree and Generated Nextcloud Assets

**Decision**: Add `src/` for Vue/TypeScript source, build through the official Nextcloud Vite
configuration, and emit deployable assets into the existing `js/` and `css/` boundaries. Use Vue
Router hash history, Pinia feature stores, a typed `@nextcloud/axios` client, `@nextcloud/vue` 9.x,
and Nextcloud localization/router/dialog packages.

**Rationale**: This formalizes the existing Vue 3/TypeScript direction without creating a separate
frontend project or requiring PHP catch-all routing for browser refreshes.

**Alternatives considered**:

- Treating `js/main.ts` as deployable source was rejected because TypeScript must be bundled.
- HTML5 router history was deferred because it requires a carefully scoped server fallback.
- Vuex was rejected for a new Vue 3 application; composables alone were rejected for the number of
  shared role queues and detail views.

## Decision 11: Keep Backend Rules Authoritative

**Decision**: Centralize role, ownership, cost-center scope, finance-stage, and record-state checks
in backend authorization policies and role-scoped repository queries. Frontend capabilities only
control presentation. V1 explicitly permits self-review when the actor otherwise has the required
role and scope; finance officers share one queue; administrators do not gain finance authority
without the finance role.

**Rationale**: Direct requests must not bypass hidden or disabled UI actions, and the resolved role
rules must be testable through a complete authorization matrix.

**Alternatives considered**:

- Controller-local conditionals were rejected because they drift across endpoints.
- Frontend-only protection was rejected as insecure.

## Decision 12: Derive Totals from Current Revisions and Decisions

**Decision**: Calculate current sheet total from active revisions, preserve the first submitted
total as a snapshot, and calculate headline approved/rejected/pending amounts from each
transaction's latest effective decision. Calculate cost-center, senior, and finance approved totals
independently from their immutable decisions.

**Rationale**: This implements the clarified business rule and keeps differing stage opinions
visible without rewriting history.

**Alternatives considered**:

- Persisting all totals as independent mutable facts was rejected due to drift risk.
- Showing only final finance totals was rejected because the requirements require stage totals.

## Decision 13: Use Synchronous, Role-Scoped Reports and Exports for V1

**Decision**: Build paged read projections and synchronous exports. Stream CSV natively; use
PhpSpreadsheet XLSX writers; use tc-lib-pdf with an approved Persian font for PDF; use ZIP plus a
manifest for grouped evidence. Create transient export artifacts in AppData and delete them after
streaming. Escape spreadsheet formulas.

**Rationale**: The target volume does not require a job service, while dedicated adapters keep
third-party export details outside domain logic.

**Alternatives considered**:

- Scheduled/background export was rejected as explicitly outside V1.
- Depending on non-public libraries bundled inside the host was rejected as an unstable contract.
- A custom spreadsheet or PDF implementation was rejected as high risk.

## Decision 14: Establish Automated Tests Before Business Slices

**Decision**: Add PHPUnit unit/integration/contract suites, Psalm, Nextcloud coding standards,
Vitest and Vue Test Utils, strict TypeScript checks, and Playwright. Run full workflow E2E tests
against the real Nextcloud 34/MariaDB environment and retain manual Chrome, Edge, Firefox, desktop,
mobile, Persian RTL, keyboard, and contrast evidence for release.

**Rationale**: The repository currently has no functional build or test harness, while the
constitution makes authorization, workflow, audit, and regression evidence release gates.

**Alternatives considered**:

- Manual-only testing was rejected for deterministic workflow and permission rules.
- Mock-only browser tests were rejected because platform routing, CSRF, files, and migrations need
  real integration coverage.

## Decision 15: Keep Localization at the Presentation Boundary

**Decision**: Store canonical identifiers, ISO dates, UTC timestamps, and integer money values.
Translate every user-facing string through Nextcloud localization; use logical CSS properties,
theme variables, `Intl` presentation formatters, and bidirectional-isolation markup. Persian is the
only supported V1 locale, but source strings remain translatable.

**Rationale**: This satisfies Persian RTL requirements without embedding localized values in APIs,
domain rules, or storage.

**Alternatives considered**:

- Hardcoded Persian strings were rejected because they prevent later localization and complicate
  testing.
- Defining Jalali date storage was rejected because requirements specify Persian UI but do not
  approve a different business-date calendar; date input formatting remains encapsulated for a
  later explicit decision.

## Primary Sources Consulted

- Nextcloud 34 Developer Manual: app architecture, routing, controllers, security, database,
  migrations, files, translations, and upgrade guidance
- Official Nextcloud app-template and Nextcloud Vue, Vite config, Axios, router, localization, and
  initial-state packages
- Vue 3 and Vue Test Utils testing guidance
- Playwright browser-project and device testing guidance

## Research Risks Carried into Tasks

- Verify the stopped local container's exact Nextcloud/PHP versions before dependency installation.
- Inspect migration history for the reused `pettycash` app ID before choosing the first migration
  version.
- Prove AppData upload compensation and public-link behavior with an early integration spike.
- Load-test XLSX/PDF memory behavior and Persian PDF rendering at the 10,000-transaction fixture.
- Test concurrent decisions and finance approvals with real MariaDB locking.
- Clearly label latest-effective totals versus financially final totals in UI and exports.
