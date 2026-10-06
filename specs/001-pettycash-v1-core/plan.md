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
reports, exports, and compatibility hardening. V1 tables use the logical app-scoped `pettycash_`
prefix, with indexes and constraints named `pettycash_<table>_<subject>_<kind>`; the instance
database prefix is applied by the Nextcloud database abstraction and is never hardcoded. V1 is a
fresh application, so legacy database import, schema migration, and table preservation are out of
scope, and prototype tables in the shared environment are left untouched and unmanaged.

**Status of the schema-namespace work (updated 2026-09-30): delivered, not planned.** The V1
baseline migrations `Version010000Date20260920000000` through `Version010400Date20260929000000`
**already create** the `pettycash_*` namespace; every repository, fixture and schema assertion has
**already been** rewritten to match (T260, T261); the approval-gated development-only reset of
PettyCash state is **already prepared** (T262); and the index and constraint names have **already
been** re-checked against the declared backends' identifier limits (T264). No `pc_`-prefixed table or
index name remains in V1. The earlier framing of this work as the `pc_*` → `pettycash_*` rename plus a
planned reset is superseded by that delivery and by FR-078, FR-080, FR-081 and SC-014. What remains
open is **T263** alone: replacing the legacy-database acceptance framing in `quickstart.md`, the
migration scenarios and the migration test suite, and — scope extended 2026-09-30, after this note was
first written and found stale — restating the two `oc_pcash_*` decision records in `spec.md` against the
measured reality, which is that the `oc_pcash_%` prefix matches 0 rows while the discarded
prototype's tables are `oc_pc_*`. Both `spec.md` references are decision records rather than schema
declarations, so the reconciliation preserves the decision they carry — that residual prototype tables
are unmanaged artifacts V1 MUST NOT read, write, migrate, rename or drop.

## Technical Context

**Language/Version**: PHP 8.2–8.5 for the Nextcloud runtime, recorded as compatibility change
**ACC-001** (evidence: `nextcloud/ocp` `dev-stable34` and Nextcloud 34 core both floor at 8.2);
TypeScript 5 and Vue 3 for the browser application; Node.js 20 LTS or a later toolchain explicitly
verified with the pinned frontend dependencies at build time

**Primary Dependencies**: Nextcloud 34 AppFramework and public OCP APIs; Nextcloud database,
identity, logging, localization, routing, and AppData services; Vue 3; `@nextcloud/vue` 9.x;
`@nextcloud/axios`; `@nextcloud/router`; `@nextcloud/l10n`; Pinia; Vue Router; Vite through the
official Nextcloud configuration; PhpSpreadsheet for XLSX; tc-lib-pdf for PDF; native CSV and ZIP
streaming; `morilog/jalali` ^3 for Jalali↔Gregorian period resolution per FR-068 (pure PHP, MIT,
no `ext-intl` requirement — see **Jalali Conversion Strategy (FR-068)**)

**Storage**: the declared V1 backends `mysql`, `pgsql` and `sqlite` (ACC-002) through Nextcloud
migrations, query builder, and database connection, with MariaDB 11.4 as the local development
backend only; private evidence and transient export artifacts through Nextcloud `IAppData`; no direct
container database access and no independent storage service

**Testing**: PHPUnit unit, integration, migration, storage, workflow, and controller-contract
tests; Psalm and Nextcloud coding standards; Vitest, Vue Test Utils, and strict type checking;
Playwright against the real Nextcloud 34 environment; manual Persian RTL browser and mobile
acceptance checks

**Target Platform**: Nextcloud 34.x web application in the existing Apache/MariaDB deployment;
Chrome 120+, Edge 120+, Firefox 115+ (ESR); responsive desktop and mobile-sized browser layouts

**Project Type**: Single deployable Nextcloud modular-monolith web application with PHP backend,
OCS JSON contracts, and an embedded Vue single-page interface

**Performance Goals**: At the V1 dataset of up to 100 active users and 10,000 annual expense
transactions, the design target is at least 95% of routine list, detail, history, and evidence views
usable within 3 seconds (p95 <3s), with error rate <0.1% and zero data loss; all list/report queries
are server-paginated and indexed. The three bounds are reported separately and are not
interchangeable (FR-073, clarified 2026-10-02): **p95** is a design target whose measurement is deferred
to post-V1; **error rate** is 5xx responses over all acceptance-suite HTTP requests — 4xx, 409 and 422
excluded — recorded as a V1 release-readiness figure by T135; **zero data loss** is a correctness
invariant established by the atomicity and failure-injection suites. **Verification of the p95 bound is deferred to post-V1** (SC-008,
clarified 2026-10-02): the target governs how queries are built, but the measurement at the stated
scale is not a V1 acceptance gate, V1 release readiness records SC-008 as *not measured* with that
reason, and any V1 latency run is informational rather than evidence of the criterion

**Constraints**: Persian RTL V1 UI with all listed elements (sheet status, transaction status, current stage, prior decisions, history, evidence) visible without horizontal scroll at 390px viewport, status color/icon meeting WCAG AA contrast, text ≥12pt Persian font; server-authoritative authorization with explicit multi-role decision matrix: self-review permitted when user holds required review role + scope + stage permission, ownership grants purchaser rights only; append-only business audit with minimum 7-year retention; immutable prior revisions; no destructive migration of historical data; atomic workflow and ledger changes; shared finance queue; no automatic escalation or mutation retry; no independent authentication, logging, backup, or file system; evidence file size capped at 100 MB per file by a ceiling PettyCash enforces itself, refusing anything larger regardless of the host file service limit, with a host limit below 100 MB still rejecting first and reported separately as a host-limit failure (FR-074, clarified 2026-10-02); zero-active-account purchasers get default rial account auto-created on first sheet creation (idempotent provisioning per FR-020); public evidence links have configurable TTL (default 30 days, max 1 year) with per-access audit logging in configuration audit per FR-031; reporting periods for group exports are **Jalali in-progress periods** (current month, quarter and year), resolved at query time to inclusive Gregorian date ranges in the requesting account's time zone, and are mutually exclusive with an explicit custom date range, per FR-068 as designed under **Jalali Reporting Periods (FR-068)**

**Scale/Scope**: Eight user journeys, **84** functional requirements, five business roles, three review
stages, **15** persistent entity families, a role-aware dashboard, reports, and Excel,
CSV, PDF, and grouped-evidence export

## Constitution Check

*GATE: Passed before Phase 0 research, re-checked after Phase 1 design, and re-checked against the
Approved Compatibility Change register on 2026-09-30 after constitution v1.1.0 introduced ACC-001
and v1.2.0 introduced ACC-002.*

- **I. Specification Before Implementation — PASS**: The finalized specification supplies
  priorities, acceptance scenarios, requirements, success criteria, assumptions, and resolved
  business decisions. This plan resolves architecture, data, workflow, API, migration, security,
  and testing decisions before tasks are generated.
- **II. Preserve Contracts and Existing Behavior — PASS**: The plan retains the application ID,
  namespace, Nextcloud boundary, PHP/Vue split, and existing shared environment. Migrations are
  forward-only, use the app-scoped `pettycash_*` namespace, and explicitly leave prototype tables
  and other applications untouched. The development-only reset discards never-released V1
  development schema and data under explicit approval; it is not a migration, rewrites no
  historical or audit record, and preserves every V1 business invariant, so it does not conflict
  with the prohibition on destructive migrations and history rewriting. The legacy backup remains
  reference material only, and no constitutional amendment is required.
- **III. Nextcloud-Native Architecture — PASS**: Authentication, users, files, technical logging,
  routing, dependency injection, database access, localization, and security remain on supported
  Nextcloud services. The PHP floor is **8.2**, recorded as a registered compatibility change by
  **ACC-001**, which is the version this plan is written against; the earlier wording that raising
  the floor "narrows rather than replaces" the PHP 8.1+ declaration is withdrawn as superseded by
  that entry. ACC-001's evidence basis is the pinned platform rather than a project preference:
  `nextcloud/ocp` `dev-stable34` declares `"php": "~8.2 || ~8.3 || ~8.4 || ~8.5"` and Nextcloud 34
  core declares `"php": "8.2"`, so PHP 8.1 was never a satisfiable runtime for this application.
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

### Compatibility Target Register Conformance

The Governance section of the constitution requires every compliance review to confirm that each
compatibility target this plan and its artifacts assert matches a registered `ACC-NNN` entry, and
that no artifact asserts an unregistered change. Delivery rule 9 additionally forbids changing a
compatibility target in a feature plan. This is that confirmation.

| Target asserted by this plan | Registered as | Entry | Status |
|---|---|---|---|
| Nextcloud 34.x | stated directly in the constitution's Architecture constraints | — | Matches |
| PHP 8.2+ (this plan says 8.2–8.5) | **ACC-001** | `ACC-001 — PHP runtime floor 8.1 -> 8.2` | Matches |
| Database backends `mysql`, `pgsql`, `sqlite` | **ACC-002** | `ACC-002 — Database target MariaDB -> mysql / pgsql / sqlite` | Matches |
| Vue 3 | stated directly in the constitution's Architecture constraints | — | Matches |
| TypeScript 5 | stated directly in the constitution's Architecture constraints | — | Matches |

**No unregistered compatibility target is asserted.** The one artifact that previously disagreed was
this plan's own database wording, which named MariaDB alone while `spec.md` FR-077 and
`appinfo/info.xml` declared three backend families. ACC-002 now registers the three-family set, which
is what FR-077, SC-013, `research.md` Decision 16 and `appinfo/info.xml` already stated.

**Two register entries remain unsatisfied, which this plan records rather than hides:**

1. **ACC-002's verification plan is not yet executed.** It requires the four-job ephemeral database
   CI matrix (T239). T239 is unchecked and no CI configuration exists, so the three declared
   backends are a **stated intent, not a verified target**, and SC-013 cannot be claimed. Per ACC-002's
   own wording this does not make the targets provisional, but V1 release readiness must not treat
   portability as proven until T239 lands.
2. **ACC-001's plan-side artifacts are outstanding.** ACC-001 defers the full impact analysis,
   migration path, and compatibility verification plan to `plan.md`. ACC-001's evidence basis and
   verification signals are recorded here and in the constitution; the PHP range in **Technical
   Context** below is the plan-side statement of the floor.

### Post-Design Re-check

Phase 1 artifacts remain compliant. The data model preserves historical records with minimum 7-year
audit retention per FR-057, contracts expose only role-scoped resources with configuration audit
visibility per FR-055/FR-056, evidence uses Nextcloud private storage with 100 MB/file size limit
per FR-074, UI meets measurable Persian RTL criteria (390px viewport, WCAG AA, ≥12pt font),
performance targets include error rate <0.1% and zero data loss, with the p95 bound a design target
whose verification is deferred to post-V1 per SC-008 (a V1 latency run is informational, not
acceptance evidence), and with error rate defined as 5xx over acceptance-suite requests and owned by
T135 per FR-073, browser targets are Chrome 120+,
Edge 120+, Firefox 115+ (ESR) per FR-072, advanced evidence restrictions (classification, redaction,
watermarking, extended logging, time-limited access, type-specific permissions) deferred to V2 per
FR-030, public evidence links have configurable TTL (default 30 days, max 1 year) with per-access
audit logging in configuration audit per FR-031, zero-active-account purchasers get default rial
account auto-created on first sheet creation (idempotent provisioning per FR-020), group-export
reporting periods are **Jalali** in-progress periods resolved at query time per FR-068 as designed
in **Complexity Tracking → Jalali Reporting Periods (FR-068)**, sheet summaries show stage-approved
totals as separate columns (Cost Center Approved | Senior Approved | Finance Approved) alongside
latest effective outcome column per FR-062, and the source layout extends the existing skeleton
without replacing its application boundary. No constitutional exception is required.

**One new dependency is proposed by this revision** (a Jalali conversion library for FR-068, decided
under **Complexity Tracking → Jalali Conversion Strategy**). It is a plain Composer dependency under
MIT licence with no PHP extension requirement, so it does not alter any compatibility target above
and requires no register entry. It is nevertheless a new runtime dependency and is brought under the
existing static dependency-security gate (T125).

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
│   ├── Port/                # Identity, clock, file, export, transaction ports
│   ├── Account/             # Accounts, charges, and the account payload shapes
│   ├── Audit/               # Audit writing, event types, retention policy
│   ├── Configuration/       # Currencies, cost centers, expense types, assignments
│   ├── DataIntegrity/
│   ├── Evidence/
│   ├── Expense/             # Sheets, transactions, corrections, submission
│   ├── Reporting/           # Role-scoped read use cases, filters, projections
│   ├── Security/            # Authorization policies
│   └── Workflow/            # Management review, finance settlement, commands
├── Domain/
│   ├── Account/
│   ├── Audit/
│   ├── Configuration/
│   ├── Evidence/
│   ├── Expense/
│   ├── Workflow/
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
├── composables/             # Reusable view logic (useAsyncAction — T121)
├── utils/                   # Currency and presentation formatting (formatMoney)
├── components/
├── views/
└── styles/                  # Logical properties, RTL, responsive, theme variables

templates/main.php           # Nextcloud page mount point only
l10n/                        # Persian source and generated translation artifacts (T119)
js/                          # Generated deployable browser bundles
css/                         # Generated/served application styles

tests/
├── Unit/
│   ├── Domain/
│   ├── Application/
│   ├── Architecture/        # Layer boundaries, route/dispatch completeness
│   ├── Audit/
│   ├── Evidence/
│   ├── Export/
│   ├── Infrastructure/
│   ├── Reporting/
│   ├── Security/
│   └── Workflow/
├── Integration/
│   ├── Account/
│   ├── Audit/
│   ├── Expense/
│   ├── Persistence/
│   ├── Migration/
│   ├── Storage/             # Evidence storage (T091)
│   └── Workflow/
├── Contract/
│   ├── Controller/
│   └── Fixture/
├── Support/                 # Shared helpers
├── TestDoubles/
├── Frontend/
│   └── Component/           # Component, shell, RTL and typography specs
└── E2E/
```

Frontend specs that exercise a whole module rather than one component — `http.spec.ts`,
`routeNameIntegrity.spec.ts`, `typography.spec.ts` — sit directly in `tests/Frontend/`.

**This block is asserted, not just documented.** `tests/Unit/Architecture/PlanStructureTest.php`
(T238) parses it and requires every declared path to exist, so the plan cannot drift from the tree
again. Two declared paths do not exist yet because an open task owns each — `src/composables/`
(T121) and `l10n/` (T119) — and are named as such above; the test keeps that allowlist in step with
the tree as well, so it cannot outlive the work that justified it. A path may only be added to the
allowlist together with the task ID that owns it.

**Structure Decision**: Use one modular Nextcloud application. The current `appinfo/`, `lib/`,
`js/`, and `css/` roots remain. Add the already-documented `src/`, `templates/`, `l10n/`, and
`tests/` roots. PHP follows Domain/Application/Infrastructure separation inside the existing
`OCA\PettyCash` namespace. Vue/TypeScript source lives in `src/`; `js/` and `css/` become generated
or served assets rather than a second frontend project.

## Delivery Strategy

1. **Foundation**: Correct the PHP compatibility declaration, establish locked build/test tools,
   register the page shell and OCS conventions, add shared IDs/money/time types, and prove clean
   installation on an empty database. **Delivered:** the `pc_*` tables were rewritten in place to the
   `pettycash_*` namespace — no rename migration and no mixed namespace — by T260 and T261, the index
   and constraint names were re-checked against the declared backends' identifier limits by T264, and
   the approval-gated development-only reset of the `pettycash` app-ID migration state is prepared by
   T262. The reset affects PettyCash only and MUST NOT delete Docker volumes, the shared Nextcloud
   database, or any unrelated application data. **Remaining:** T263 replaces the legacy-database
   acceptance framing in `quickstart.md`, the migration scenarios and the migration test suite with
   clean-installation and forward-chain acceptance on an empty database.
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
   public evidence-link administration, and export-security tests. Resolve FR-068's predefined
   reporting periods at query time as Jalali in-progress periods, adding `morilog/jalali` ^3 behind a
   domain value object so the strategy is swappable in one file (T312, which also owns the
   `composer.json` and licence-notice change), and amending `contracts/ocs-api.yaml` for the `period`
   filter, the `period`-plus-date-range `422`, and the resolved range and time zone in the response
   (T312). The generated TypeScript contract types are regenerated **after** both that amendment and
   T275's FR-060 amendment, never before (T124).
9. **Hardening**: Run migration, failure-injection, concurrency, target-volume, browser, RTL,
   responsive, accessibility, and recovery checks; update operational and release documentation.

**Database Portability (FR-077)**: The supported V1 backends are the Nextcloud database types
`mysql` (MySQL 8.0/8.4, MariaDB 10.6/10.11/11.4/11.8), `pgsql` (PostgreSQL 14–18), and `sqlite`
(SQLite 3.24+, declared for compatibility verification only). This set was verified from the
Nextcloud 34 Administration Manual *System requirements* and *Developer Manual — Database access*,
not inferred, and is recorded with the adopted portable rules in `research.md` Decision 16.
MariaDB 11.4 is the local development backend only; the existing MariaDB development and Docker
infrastructure is not replaced or modified, and any additional databases exist solely as CI-only
ephemeral test services.

Persistence goes through the Nextcloud database abstractions — `OCP\IDBConnection`,
`OCP\DB\QueryBuilder\IQueryBuilder`, `OCP\AppFramework\Db\QBMapper`, `OCP\DB\Types` — plus the
Nextcloud forward-only migration and schema APIs. Raw SQL, `Doctrine\DBAL\Types` constants,
delete-`LIMIT`, non-aggregated `GROUP BY` projections, and any other vendor-specific construct are
prohibited in application code and migrations.

`appinfo/info.xml` declares the supported set explicitly in its `<dependencies>` block so it is
never left implied:

```xml
<dependencies>
    <nextcloud min-version="34"/>
    <database>mysql</database>
    <database>pgsql</database>
    <database>sqlite</database>
</dependencies>
```

Verification runs as a four-job CI matrix of ephemeral database services — **MySQL**, **MariaDB**,
**PostgreSQL**, and **SQLite** — because the `mysql` type covers two distinct concrete engines and
a MariaDB pass is never treated as evidence for MySQL. Each job runs the forward-only migrations and
schema creation, the persistence/repository integration tests, representative transaction/workflow
queries, and the migration/schema compatibility checks. On top of those per-backend execution
passes, static portability checks run on every build and reject the constructs catalogued in
`research.md` Decision 16 (raw SQL, `Doctrine\DBAL\Types` constants, delete-`LIMIT`, non-aggregated
`GROUP BY` projections, and out-of-budget schema assumptions). A green MariaDB run alone is not
accepted as proof of portability. None of this touches the local development environment: MariaDB
11.4 there remains the only database, and the additional engines exist solely as CI-only ephemeral
test services.

**Deferred architecture constraint — Oracle / Enterprise compatibility**: `oci` is explicitly
outside V1 scope and MUST NOT be declared in `appinfo/info.xml`. Oracle-specific identifier and
schema limits (table names ≤ 27 including the instance prefix, boolean columns never `NOT NULL`, string
columns ≤ 4,000 characters, and the 30-character index/column/sequence limits that Nextcloud applies
when Oracle is supported) are **not** V1 requirements and are **not** adopted as blanket constraints.
No schema is reshaped and no migration is added in anticipation of Oracle support; the
`pettycash_*` namespace already satisfies every limit required by the declared V1 backends. If Oracle
support is proposed later it MUST first re-verify the exact requirements against the targeted
Nextcloud and Oracle versions — the limits above reflect Nextcloud 34 documentation and may change —
and MUST be accompanied by an explicit compatibility and migration plan approved before any schema
change.

## Complexity Tracking

No constitution violations or exceptional architecture components require justification. The
layering is contained within one deployable application and is warranted by the number of workflow,
authorization, audit, persistence, and export invariants that must remain independently testable.

**Audit Retention Policy (FR-057)**: The 7-year minimum retention requirement is implemented as a
configurable policy with a hard-coded floor of 7 years. The policy governs archival (moving to cold
storage) and availability (query scope) but never permits deletion or rewriting of business audit
records. Implementation touches:
- Persistence: the `pettycash_transaction_audit` and `pettycash_configuration_audit` tables retain
  all records indefinitely;
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

**Evidence Handling (FR-030, FR-074)**: Evidence files are capped at **100 MB per file by a ceiling
PettyCash enforces itself**, refusing anything larger regardless of what the host file service allows
and naming the limit in the refusal. Where the host file service imposes a *lower* limit than 100 MB
the host rejects first, and that rejection is reported as a host-limit failure rather than as the
100 MB refusal, so a test or support report can tell which ceiling stopped the upload
(FR-074, clarified 2026-10-02). Advanced evidence-specific restrictions (document classification levels,
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

**Jalali Reporting Periods (FR-068)**: Group exports accept a `period` filter alongside an explicit
custom date range. The specification was clarified on 2026-09-30, and this is the resulting design.
No fiscal-calendar configuration exists in V1; a period is derived from the calendar, never configured.

- **Calendar**: periods are **Jalali (Solar Hijri)**. This is not a free choice: every date in the
  interface is already rendered with `toLocaleDateString('fa-IR')` at ten sites across six components,
  so a Gregorian preset would return a range the user cannot map onto what they just read. A period
  therefore spans **Farvardin through Esfand**, and a **quarter begins at Farvardin**, never at a
  Gregorian quarter boundary. A Jalali year is not a Gregorian year and MUST NOT be identified by a
  Gregorian year number anywhere in the request or the response.
- **Anchor**: `month`, `quarter` and `year` each denote the period **in progress at the moment of the
  request** — the current Jalali month, quarter or year — running from its first day through its last.
  Not the previous completed period, and not a trailing window of the same length. A previous or
  trailing span is requested with the explicit custom date range that FR-068 already supports, not
  with a second preset; V1 defines exactly three period names, not six.
- **Time zone**: boundaries are resolved in the **requesting account's own time zone**, taken from that
  user's account settings, falling back to the instance's configured default when the account states
  none. No zone is hardcoded. Because the zone belongs to the caller, the resolved range is
  **user-relative**: two users in different zones asking for the same period name may obtain different
  ranges, and the response discloses the zone so that difference is explainable rather than looking
  like missing records. This is a deliberate consequence of the specification decision, recorded here
  so it is not rediscovered as a defect.
- **Boundaries**: **inclusive at both ends**. Expense dates are whole calendar dates carrying no time
  of day, so no boundary can fall inside a record and there is no half-open interval to reason about.
  A preset and the equivalent explicit date range MUST select exactly the same records. A Jalali year
  is 365 or 366 days and MUST NOT be treated as a fixed length.
- **Stored data**: periods resolve **at query time only**. Stored expense dates remain Gregorian
  calendar dates without a time zone and MUST NOT be altered, re-encoded, re-interpreted or migrated.
  No schema change accompanies this feature.
- **Conflict**: `period` and an explicit custom date range are **mutually exclusive within one
  request**. Supplying both is refused with `422` whose message names the conflict and states which of
  the two to remove. The system MUST NOT resolve the conflict by honouring one and discarding the
  other, nor by intersecting them — the same refuse-don't-ignore rule FR-064 and FR-082 already apply
  to filters.
- **Disclosure**: every response that resolved a `period` MUST state the resolved Gregorian date range
  and the time zone it was resolved in. A period in progress is necessarily incomplete, so this is what
  prevents a partial month being mistaken for a whole one.

**A boundary trap this design must not fall into (verified during planning).** Tehran is UTC+3:30, so
resolving a Jalali period and then formatting the result through UTC shifts the boundary by a full day.
Measured: Jalali 1405/7 (Mehr) begins **2026-09-23** in Tehran and renders as `2026-09-22` when
formatted in UTC; Jalali 1405 begins **2026-03-21** in Tehran and renders as `2026-03-20` in UTC. The
resolved `from`/`to` pair MUST be derived as calendar dates **in the requester's zone** and carried as
dates, never re-derived by formatting an instant through UTC. This is the single most likely
off-by-one in the feature.

**Jalali Conversion Strategy (FR-068)**: V1 requires Jalali→Gregorian conversion for period
boundaries only — no Jalali arithmetic on stored data, and the front end performs no conversion of its
own (it already renders through `Intl` via `fa-IR`). Four approaches were evaluated.

| Approach | Correctness across Jalali leap years | PHP/runtime compatibility | Maintenance | Testability | Dependency/licensing |
|---|---|---|---|---|---|
| **`ext-intl` / ICU `persian` calendar** (chosen as an *oracle*, not as the dependency) | Verified correct in this environment: Mehr = 30 days, Q3 = 93 days, years 1403 and 1408 = 366 days, 1404 and 1405 = 365 | Available here (ICU 76.1) but **not** a Nextcloud 34 requirement — core lists 19 required extensions and `intl` is not among them, and core `lib/` contains zero `IntlDateFormatter`/`Locale::` users | None | Excellent as an oracle | No dependency, but adds a hard platform requirement Nextcloud itself does not impose |
| **`morilog/jalali` v3 (chosen)** | Borkowski algorithm, same lineage as ICU's `persian` calendar, so it agrees with the `fa-IR` rendering already shipped | Requires PHP >= 7.1, so it sits inside the ACC-001 floor of 8.2–8.5 | External; 921 stars, 67 dependents, release 2025-12-25, last update 2026-07-25 | Ordinary unit tests, plus differential testing against ICU | MIT; pure PHP; **no** `ext-intl` requirement |
| **Internal conversion arithmetic** | Would have to re-derive the 33-year leap cycle by hand — the exact place hand-written calendar code fails | No constraint | High and permanent: this project would own leap-year arithmetic forever | Highest burden of all options, since every boundary becomes our test matrix | No dependency |
| **`condorcet-php/php-calendars` v3** | Mature, a maintained fork of `fisharebest/ext-calendar` | **Requires PHP 8.5+**, which would force the floor above ACC-001's 8.2+ | External | Ordinary | Varies |

**Decision: add `morilog/jalali` ^3 as a Composer dependency, and use `ext-intl`/ICU only as a test
oracle where it is available.** The deciding factor is that `ext-intl` is not a Nextcloud requirement:
depending on it alone would narrow the deployment envelope beyond what the platform guarantees, which
is the same class of mistake ACC-001 corrected. `morilog/jalali` is pure PHP, MIT, compatible with the
ACC-001 floor, and widely adopted. Because it shares the Borkowski algorithm with ICU's `persian`
calendar, the two can be **differentially tested against each other** across a large date range, which
converts the oracle into a genuine correctness gate rather than an assumption. `eram/daynum` was
considered and rejected as `v1.0.0-beta.1` with no dependents — too new to carry a financial
correctness obligation, though it is the obvious candidate to revisit. `hanifhefaz/dcter` and
`jobmetric/multi-calendar` were rejected as Laravel-oriented and multi-calendar beyond need. The
conversion is isolated behind a small value object in the domain layer so that swapping strategy later
touches one file rather than the query path.

**API and contract impact (FR-068)**: implementing this requires changes to the approved contracts,
which are therefore no longer self-consistent until they are made:

1. `period` MUST be added to the accepted report and export filter vocabulary. The vocabulary is a
   closed set established by T309/T310 and asserted as data so drift fails the suite; adding a member
   is a contract change, not an implementation detail.
2. The `422` response for a `period` plus explicit date-range conflict MUST be declared, including the
   requirement that the message name which of the two to remove.
3. The resolved Gregorian date range and the resolving time zone MUST appear in the response schema of
   whichever response carries export/report filtering, so the disclosure is contract-enforced rather
   than incidental.
4. `contracts/ocs-api.yaml` MUST be updated for all three, and the generated TypeScript contract types
   regenerated from it, so the client cannot be built against the old shape.
5. `data-model.md` records that expense dates are Gregorian calendar dates without a time zone; it
   needs no change, but a reader may now ask which calendar a *period* is in, so the Jalali definition
   belongs here in the plan and in `spec.md` FR-068 rather than in the data model.

**Planned verification (FR-068)** — each item is a distinct, independently failing assertion:

- Jalali month boundaries resolve to the correct Gregorian dates, including a 30-day month and a 31-day month.
- Jalali quarter boundaries begin at Farvardin, not at a Gregorian quarter boundary.
- Jalali year length is 365 or 366 as the calendar dictates — 1403 and 1408 as leap controls, 1404 and 1405 as non-leap — and never asserted as a constant.
- Time-zone fallback: an account that states no zone resolves against the instance default.
- Two users in different time zones requesting the same period name each receive their own correct range and the zone is disclosed in both responses.
- Inclusive first-day and last-day semantics: a record dated on the period's first day and one dated on its last day are both included.
- `period` combined with an explicit date range returns `422` naming the conflict, for both orderings and for every affected resource.
- Stored Gregorian expense dates are byte-identical before and after period resolution, and no migration is produced.
- **Differential**: the chosen library's conversion agrees with ICU's `persian` calendar across a large date range wherever ICU is present, skipping rather than failing when it is not.
- **Regression guard for the boundary trap**: a case in a non-UTC zone asserting the resolved `from` is not shifted by UTC formatting.

**Persian PDF Export Font Provenance (FR-066)**: PDF export is **in V1**, and the font it uses is an
**OFL-licensed Persian font vendored into the repository under an application-owned path**, shipped together
with its licence text. This is an already-settled architectural decision, recorded here because the
constitution requires internal architecture to be decided in the feature plan rather than assumed. It was
settled in `spec.md` FR-066 and US7/AC4 on 2026-09-30 against the alternative of generating fonts at install
time, and three reasons decided it: a vendored font renders identically in every deployment and in CI, which
a generated one does not; it keeps PDF export off any host- or container-installed font, so the result cannot
vary with the machine; and it introduces **no PHP extension requirement** — `ext-intl` is present in the
development container (ICU 76.1) but **Nextcloud 34 does not require it** (its `composer.json` lists 19
required extensions and `intl` is absent, and core `lib/` contains no `IntlDateFormatter` or `Locale::` user),
so depending on one would narrow the deployment envelope beyond what the platform guarantees — the same class
of error ACC-001 corrected. Supporting facts already verified: bidirectional shaping is **not** the obstacle,
because `tc-lib-unicode` supplies `Com\Tecnick\Unicode\Bidi` (UAX#9) and `Bidi\Shaping\Arabic` plus
`Tcpdf::setRTL()`, giving the correct reorder-then-shape order. **The concrete directory that holds the font
is an implementation detail of T109 and is deliberately not fixed here** — no font is chosen, named or
downloaded by this plan, and `l10n/` remains scoped to Persian source and generated translation artifacts
only. Note the requirement that the font and its licence text are *vendored* is architectural and settled;
only the path, the specific typeface and the licence file placement remain open, all within T109.

**Stage-Approved Totals Presentation (FR-062)**: Sheet summaries display approved totals as separate
columns — Cost Center Approved, Senior Manager Approved, Finance Approved — alongside the latest
effective outcome column. This applies to dashboards, sheet lists, sheet details, and exports.
