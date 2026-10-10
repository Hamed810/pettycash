<!--
Sync Impact Report
- Version change: 1.2.0 -> 1.3.0 (MINOR)
- Bump rationale: this amendment corrects a stale normative sentence in Principle III so that it names
  the declared compatibility targets (`mysql`, `pgsql`, `sqlite`) rather than MariaDB alone. It is a
  correction toward the target ACC-002 already registered, not a change of target: no requirement is
  added, removed, widened or narrowed, so PATCH -- which the policy reserves for clarifications that
  do not change required behaviour -- would understate that a MUST's text changes. MINOR is correct
  because no principle is removed or redefined and no support is withdrawn, so MAJOR does not apply.
  It also makes the file internally consistent: as of 1.2.0 the Architecture and Quality Constraints
  bullet and ACC-002 named three backend families while Principle III still said "MariaDB
  compatibility MUST be retained", so two normative sentences in one file disagreed and the one an
  agent reads first was the wrong one.
- MAJOR counter-argument, recorded because it was considered and rejected: Principle III is a core
  principle, and a principle's normative text changes. Rejected because the change is a *correction
  toward* the already-approved target, not a redefinition: the set of backends this application must
  remain compatible with is unchanged from ACC-002 and unchanged from the 1.1.0 wording's intent, and
  nothing previously conformant becomes non-conformant. MAJOR is reserved for removals and
  redefinitions that break something. The prior 1.2.0 amendment's reasoning on the same bullet --
  recorded in git history -- is carried forward rather than restated as new.
- Preserved unchanged: ACC-001 (PHP runtime floor) and ACC-002 (database target set) are
  byte-identical, including their own outstanding follow-up notes. No register entry is added,
  removed or altered by this amendment.
- Modified principles:
  - III. Nextcloud-Native, Boundary-Conscious Architecture -- MODIFIED in this amendment. The 1.2.0
    report recorded it as NOT modified on the reasoning that "its normative text already required any
    compatibility-target change to be registered first, which is what made ACC-002 necessary rather
    than optional". That reasoning was correct about the *registration* requirement and was applied,
    but it was mistaken about the state of the rest of the sentence: the same paragraph retained a
    separate, unregistered "MariaDB compatibility MUST be retained" clause, so registering ACC-002
    did not by itself bring Principle III into conformance. The registration requirement is unchanged
    and remains what made ACC-002 necessary.
- Modified sections:
  - III. Nextcloud-Native, Boundary-Conscious Architecture (the database-compatibility sentence:
    "MariaDB compatibility MUST be retained" replaced by compatibility with the declared V1 backends
    per ACC-002, with MariaDB identified as the local development backend only)
- Added sections: none
- Removed sections: none
- Follow-up TODOs:
  1. T239 is unchecked and no CI configuration exists (.github/ contains only skills), so ACC-002's
     verification plan is NOT satisfied and SC-013 cannot be claimed. The declaration in
     appinfo/info.xml is stated intent until T239 lands. [carried from 1.2.0, still open]
  2. appinfo/info.xml declares no <php> element, so the 8.2 floor rests on composer.json alone.
     Whether info.xsd permits <php> there is unverified. [carried from 1.2.0 item 6, still open]
  3. CLOSED at 1.2.0 item 2 -- plan.md now carries a "Compatibility Target Register Conformance"
     section citing ACC-001 and ACC-002 by entry.
  4. CLOSED -- plan.md's Constitution Check no longer passes ACC-001 on "narrows rather than
     replaces" reasoning; it records that reasoning as withdrawn as superseded by the entry.
     [1.2.0 item 3]
  5. CLOSED -- plan.md's Scale/Scope states 83 functional requirements, reconciled to spec.md on
     2026-09-30. [1.2.0 item 4, which recorded the count as 76]
  6. CLOSED -- plan.md's Summary carries a "delivered, not planned" status note for the
     pc_* -> pettycash_* namespace work. [1.2.0 item 5]
  7. OPEN, new in this amendment: plan.md's Technical Context "Storage" line still read "MariaDB"
     alone while the same plan's register-conformance table certified the three-type set, which is a
     Delivery-rule-9 violation (a plan asserting a superseded target). Corrected in plan.md on
     2026-10-02; recorded here because the register is where target-assertion defects are tracked.
- Note: this report is temporary scratch material for human review of the amendment and is expected
  to be removed before the amended constitution file is committed.
-->
# PettyCash Constitution

## Core Principles

### I. Specification Before Implementation

Every material feature or behavior change MUST begin with a specification that states the user
outcome, scope, acceptance criteria, affected roles, and relevant business rules. Significant work
MUST proceed in the order specification, plan, tasks, then implementation. Plans MUST resolve or
explicitly defer architecture, data-model, workflow, API, migration, security, and testing
decisions. Implementations MUST remain traceable to approved tasks and acceptance criteria.

The constitution and feature specifications under `specs/` govern delivery. Existing requirements
under `DOC/Requirements/` are the current business-domain baseline and MUST be consulted when
writing or changing a specification. Because those documents contain draft and open decisions,
ambiguities MUST be made explicit in the feature specification rather than silently resolved in
code. Changes to business processes, rules, roles, or lifecycle states MUST first be recorded and
reviewed in the applicable requirements or specification artifact.

Rationale: the project is intentionally at the skeleton stage; premature implementation would
turn unresolved business and architecture decisions into accidental contracts.

### II. Preserve Contracts and Existing Behavior

Changes MUST preserve existing behavior, data meaning, and public contracts unless an approved
specification explicitly authorizes a change and provides a compatibility or migration plan. The
application identity `pettycash`, PHP namespace `OCA\PettyCash`, Nextcloud application boundary,
supported runtime baseline, and documented business invariants MUST NOT change incidentally.

Refactoring MUST be behavior-preserving and MUST include evidence appropriate to the risk. Data
migrations MUST be forward-safe, preserve historical records, and define rollback or recovery
steps before execution. The legacy backup is a reference for migration analysis only and MUST NOT
be treated as the architecture or implementation base for V1. Shared Nextcloud infrastructure,
Docker volumes, other applications, and unrelated projects MUST NOT be deleted or modified as a
side effect of PettyCash work.

Rationale: PettyCash operates inside an existing Nextcloud environment and manages financial and
historical information for which silent contract or data changes are unacceptable.

### III. Nextcloud-Native, Boundary-Conscious Architecture

PettyCash MUST remain a Nextcloud application compatible with the declared Nextcloud 34.x and PHP
8.2+ baseline. Any change to a declared compatibility target MUST be recorded as an entry in the
**Approved Compatibility Change** register before it is relied upon, and MUST satisfy that entry's
approval, impact-analysis, migration, and compatibility-verification conditions; a plan asserting
that a change "narrows" or "supersedes" a declared target is not by itself an approval. Backend
code MUST remain within the `OCA\PettyCash` PSR-4 boundary. Browser-facing functionality MUST use
the established Vue 3 and TypeScript direction and MUST preserve the Persian RTL, responsive user
experience required for V1. Compatibility with every declared V1 database backend (`mysql`, `pgsql`,
`sqlite`, per the compatibility-targets list and ACC-002) MUST be retained through
Nextcloud-supported database and migration mechanisms; MariaDB is the local development backend
only and is not itself a declared target. Application code MUST NOT depend on
environment-specific credentials or direct container access.

Nextcloud facilities MUST remain authoritative for authentication, user identity, file access,
security controls, and technical logging. PettyCash MUST NOT introduce a parallel authentication
system, an independent technical-log subsystem, or unmanaged document storage. Domain modules
MUST keep business rules, persistence, transport, and presentation concerns separable so they can
be tested and changed without bypassing access control or workflow rules. Detailed internal
architecture, schemas, APIs, and component structures MUST be decided in feature plans, not
assumed from the skeleton or legacy application.

Rationale: using platform services preserves compatibility and security while separation of
concerns keeps still-open architecture decisions reversible. The PHP floor is stated as 8.2+
because that is the minimum the declared Nextcloud 34 platform itself requires, not because
PettyCash chose a narrower range; see ACC-001 for the evidence and approval.

### IV. Domain Integrity, Security, and Auditability

The expense transaction is the primary decision unit; an expense sheet groups transactions and
derives its state from them. Implementations MUST enforce workflow transitions, role and scope
authorization, ownership, required evidence, and financial effects on the server side regardless
of client behavior. Sensitive multi-record operations MUST be atomic: they complete fully or leave
no partial business-state change.

After formal submission, corrections MUST create a new transaction revision; prior revisions and
decisions MUST remain intact. Business audit records MUST be append-only, non-editable, and
non-deletable, including for administrators, and MUST remain distinct from technical logs.
Historical actor identity and role-at-event MUST survive account disablement or deletion. Document
and export access MUST follow transaction visibility and MUST not expose data beyond the caller's
role and responsibility scope. Any deliberate change to these invariants requires a constitution
amendment in addition to a feature specification and migration plan.

Rationale: financial workflow correctness depends on authorization, immutable history, and
reconstructable decisions rather than only on the current record state.

### V. Verified and Documented Delivery

Every change MUST define verification before implementation and MUST produce evidence that the
acceptance criteria pass. Deterministic business rules and pure domain logic MUST have automated
unit tests when introduced. Workflow transitions, permissions, persistence, revisions, audit
records, migrations, and Nextcloud integration boundaries MUST have automated integration tests
when a suitable harness exists. A plan that temporarily relies on manual verification MUST state
why automation is not yet feasible, list repeatable test steps, and create follow-up work for
the missing coverage.

V1 acceptance MUST also include documented functional checks for the complete affected workflow.
UI changes MUST be checked in current Chrome, Edge, and Firefox and at desktop and mobile
viewports, including Persian RTL layout. Fixes MUST include a regression test or a repeatable
regression procedure. Documentation, requirements, specs, plans, task status, operational notes,
and migration instructions MUST be updated in the same change whenever their claims are affected.

Rationale: the current requirements identify manual functional testing as the V1 baseline, while
repeatable automation is necessary as executable business behavior is added.

## Architecture and Quality Constraints

- The repository's current skeleton is the starting architecture: Nextcloud app metadata and
  routes in `appinfo/`, PHP application code in `lib/`, TypeScript in `js/`, and styles in `css/`.
  New top-level layers or frameworks require justification in the approved plan.
- Compatibility targets are Nextcloud 34.x, PHP 8.2+, and TypeScript 5; the browser application uses
  Vue 3; and the supported database backends are the Nextcloud types `mysql`, `pgsql` and `sqlite`.
  PHP 8.2 is set by ACC-001 and the database set by ACC-002; neither is a PettyCash preference, and
  both were established by verification against the platform rather than by choice. A change to any
  target in this list MUST first be recorded in the register below and MUST NOT be applied only by
  editing `plan.md`. Declaring a backend in `appinfo/info.xml` is a **stated intent**; it becomes a
  verified target only when the owning entry's verification plan has actually been executed and its
  result recorded.
- Application identifiers and namespaces MUST be consistent across metadata, routes, dependency
  injection, PHP autoloading, assets, migrations, and tests.
- Authentication, authorization, input validation, and business invariants MUST be enforced at
  trusted backend boundaries. Client-side checks are usability aids and MUST NOT be the only
  enforcement.
- Database and workflow changes MUST preserve revision, audit, and historical-reference integrity.
  Destructive migrations and history rewriting are prohibited.
- Technical errors MUST use standard Nextcloud logging. Business audit events MUST use the domain
  audit model and MUST NOT include secrets or rely on technical logs as the historical record.
- The UI MUST remain usable in Persian RTL on desktop and mobile and MUST be structured so future
  localization is possible without embedding translatable text in domain or persistence logic.
- Designs MUST target the documented V1 scale of fewer than 100 active users and fewer than
  10,000 expense transactions per year without introducing speculative distributed systems or
  services. Added complexity MUST be justified by a measured or specified requirement.

### Approved Compatibility Change

Every approved change to a compatibility target is recorded here as an `ACC-NNN` entry. The
register is the authoritative record of which compatibility decisions are approved, on what
evidence, and what remains owed. An entry is binding on all specifications, plans, and tasks; a
plan or task that contradicts an entry is non-conformant, and a plan or task that merely *asserts*
a change is approved without an entry is non-conformant.

Each entry MUST record, at minimum: the old and new target, the approval date and approving role,
the evidence basis that made the change necessary, an impact analysis, a migration path, and a
compatibility verification plan. An entry MAY record follow-up work that the approval does not
itself discharge; such work is a plan-side deliverable and its absence does not make the approved
target provisional, but it MUST remain tracked until closed.

Rationale: a compatibility target that can be moved by editing a plan is not a governance
constraint. Recording the decision, its evidence, and its approval separately is what makes a
narrowing reviewable instead of self-certifying.

#### ACC-001 -- PHP runtime floor 8.1 -> 8.2

- **Old target**: PHP 8.1+ (declared in Principle III and in the compatibility-targets list).
- **New target**: PHP 8.2+.
- **Approved**: 2026-09-30, by the project maintainer.
- **Evidence basis** (verified against the pinned platform, not inferred):
  `nextcloud/ocp` `dev-stable34` declares `"php": "~8.2 || ~8.3 || ~8.4 || ~8.5"`, and Nextcloud 34
  core in the runtime declares `"php": "8.2"`. Every declared runtime and development dependency
  floors at 8.2 as well: `phpoffice/phpspreadsheet` `^8.2`, `tecnickcom/tc-lib-pdf` `^8.2`,
  `phpunit/phpunit` `>=8.2`. `composer.json` already requires `>=8.2 <8.6`.
- **Impact analysis**: PHP 8.1 was never a satisfiable runtime for this application. A PHP 8.1
  host could not load the declared Nextcloud 34 platform, so no conformant deployment, artifact,
  or test run of this constitution ever required it. The 8.1 declaration was stale text inherited
  from the skeleton, and it was **inconsistent with this same constitution's Nextcloud-34
  requirement**, which is the defect this entry corrects. Because 8.1 was already impossible, the
  change withdraws no working support: it removes a claim that could not be honoured. Blast radius
  is confined to documentation and declared metadata; no stored data, schema, migration,
  identifier, or contract is affected, so Principles II and IV are untouched. The repository
  requires `>=8.2 <8.6`; the upper bound is unchanged by this entry.
- **Migration path**: none is required, because no conformant environment needs to move and no
  persisted state changes. Deployments must already satisfy 8.2 to run Nextcloud 34. `composer.json`
  is the enforcing declaration; see follow-up 5 in the Sync Impact Report about whether
  `appinfo/info.xml` can also carry the floor.
- **Compatibility verification plan**: the floor is verified by three independent signals, all of
  which MUST hold for a change to be considered verified: (1) the platform's own requirement --
  `nextcloud/ocp` `dev-stable34` and Nextcloud 34 core -- floors at 8.2; (2) the dependency closure
  resolves under `"php": ">=8.2 <8.6"` with `composer install` succeeding; (3) the PHPUnit and
  contract suites execute green on the declared runtime. Signal (3) is the container runtime,
  because PHP is a container-only execution environment in this project; a PHP version observed on
  a development host MUST NOT be used as evidence. Verification results are recorded per change in
  the task report, not asserted by the plan.
- **Outstanding, tracked as plan-side follow-up** (does not make 8.2 provisional): `plan.md`'s
  Constitution Check currently passes this change on the reasoning that raising the floor "narrows
  rather than replaces" the 8.1+ declaration. That reasoning is superseded by this entry and MUST
  be corrected in `plan.md`.

#### ACC-002 -- Database target MariaDB -> `mysql` / `pgsql` / `sqlite`

- **Old registered target**: "MariaDB" -- a single concrete engine, named in the
  compatibility-targets list without a declared Nextcloud database type. It was under-specified
  in kind, because MariaDB is not itself a Nextcloud `<database>` type (the type covering MariaDB
  is `mysql`), and over-narrow in scope, because it excluded two backend families the platform
  documents as supported.
- **New target**: the Nextcloud database types `mysql` (MySQL 8.0/8.4 and MariaDB 10.6/10.11/11.4/
  11.8), `pgsql` (PostgreSQL 14/15/16/17/18), and `sqlite` (SQLite 3.24+, declared for compatibility
  verification rather than as a production recommendation). Oracle/`oci` is explicitly **outside**
  scope and MUST NOT be declared in `appinfo/info.xml`.
- **Approved**: 2026-09-30, by the project maintainer.
- **Evidence basis**: `specs/001-pettycash-v1-core/research.md` Decision 16 (accepted 2026-09-25),
  which consulted the Nextcloud 34 Administration Manual *System requirements*, the Nextcloud 34
  Developer Manual *Database access*, and `OCP\IDBConnection`, and recorded the documented backends
  and Nextcloud's own qualifications for each: MariaDB and PostgreSQL recommended, MySQL supported,
  SQLite recommended only for testing and minimal instances, Oracle only under an enterprise
  subscription. The supporting requirement is FR-077, and the supporting acceptance criterion is
  SC-013.
- **Rationale**: naming one concrete engine as the target made MariaDB-specific behaviour a silent
  application dependency and made a MariaDB-only green test run indistinguishable from proof of
  portability. Declaring the types explicitly makes the supported set auditable at
  `appinfo/info.xml` and forces the persistence layer onto the portable Nextcloud query APIs that
  work across all three. The declared set is a widening: it adds obligations and withdraws nothing.
- **Impact analysis**: the blast radius is the persistence layer
  (`lib/Infrastructure/Persistence/`), the forward-only migrations (`lib/Migration/`), and the
  test suites -- not the domain, the application services, the OCS contracts, or the frontend.
  `appinfo/info.xml` already declares `mysql`, `pgsql` and `sqlite`, so no metadata change is
  required; the constitution was the outlier.
  Three concrete portability hazards are recorded in Decision 16 and MUST hold: PostgreSQL rejects
  the empty string that PHP produces when casting `false`, so `Doctrine\DBAL\Types` constants MUST
  NOT be referenced and `getTypedQueryBuilder()` MUST be preferred; MySQL/MariaDB
  `ONLY_FULL_GROUP_BY` rejects non-aggregated `GROUP BY` projections, which binds the totals and
  reporting queries; and SQLite requires a result cursor to be closed after custom `execute` calls,
  which is stricter than the other declared backends. Multi-row deletes MUST NOT rely on a `LIMIT`
  clause, and `IN` lists MUST be chunked where a backend limit is detectable through
  `IDBConnection::getDatabaseProvider()`. Oracle-derived limits (27-character table names, boolean
  columns never `NOT NULL`, 4,000-character strings, 30-character identifiers) are **not** adopted
  as V1 requirements, because no declared backend requires them; they MUST NOT be used to justify
  renaming a table or adding a migration. MariaDB 11.4 remains the local development backend only,
  and is not a declared supported backend in its own right.
- **Migration implications**: none for stored data. No schema changes, no table renames, no data
  rewrites, and therefore no rollback path is required for state -- the forward-only migrations in
  `lib/Migration/` are unchanged by this entry, and the prohibition on destructive migrations and
  history rewriting is unaffected. The real obligation is **verification infrastructure**: the
  application MUST pass its migrations, persistence tests and representative workflow queries on
  every declared backend. The local MariaDB 11.4 Docker development environment MUST remain
  unchanged and unsupplanted; the additional engines exist only as CI-only ephemeral test services,
  and no CI job may modify or replace the shared Nextcloud database, Docker volumes, or another
  application's data.
- **Compatibility verification plan**: continuous integration MUST provide a matrix of **four**
  ephemeral database services -- MySQL, MariaDB, PostgreSQL and SQLite -- in which each job runs
  the forward-only migrations and schema creation, the persistence and repository integration tests,
  the representative transaction and workflow queries, and the migration and schema compatibility
  checks. **MySQL and MariaDB MUST be separate jobs**, because the `mysql` Nextcloud type covers two
  distinct concrete engines and a MariaDB pass is not evidence for MySQL. On top of those
  per-backend execution passes, a static portability check MUST run on every build and reject the
  vendor-specific constructs catalogued in Decision 16. A green MariaDB run alone MUST NOT be
  accepted as evidence of portability. Verification results MUST be recorded in the task report of
  the work that produced them, not asserted by a plan; T239 owns the CI matrix and T240 owns the
  static check.
- **Outstanding, tracked as plan-side follow-up** (does not make the three types provisional):
  T239 is unchecked and the repository has no CI configuration at all, so the verification plan
  above is **not yet satisfied** and V1 acceptance under SC-013 cannot be claimed until it is. Until
  then the declaration in `appinfo/info.xml` is a stated intent, not a verified one. `plan.md`'s
  Constitution Check asserts PASS without mentioning this register, which its own Governance section
  now requires; that MUST be corrected there.

## Delivery and Change Management

1. Before implementation, the change owner MUST identify the governing requirements and create or
   update the feature specification. Open questions that affect observable behavior MUST be
   resolved or explicitly deferred with a safe default.
2. The implementation plan MUST document affected architecture boundaries, data and migration
   impact, security and audit impact, compatibility risks, test strategy, documentation changes,
   and rollback or recovery steps where state can change.
3. Tasks MUST be dependency-ordered, independently verifiable, and linked to acceptance criteria.
   Implementation MUST follow approved tasks; discoveries that materially change scope MUST return
   to the specification and plan before code continues.
4. Reviews MUST verify constitution compliance, requirement traceability, preservation of existing
   behavior, server-side authorization, historical integrity, test evidence, and documentation
   accuracy. A failed mandatory check blocks merge or release.
5. Changes MUST be small enough to review safely. Unrelated refactors, dependency upgrades, and
   behavior changes MUST be separated unless the plan demonstrates that separation is impossible.
6. Breaking changes require explicit approval, a migration and compatibility strategy, release
   notes, and a MAJOR version decision for the affected public contract. Deprecations MUST state
   the replacement and removal conditions.
7. Operational actions MUST protect the shared development environment. Commands that can remove
   volumes, modify other Nextcloud apps, rewrite history, or destroy data require explicit human
   approval and verified backup or recovery instructions.
8. A task is complete only when implementation, tests or repeatable verification, documentation,
   and task/spec status agree. Known deviations MUST be recorded; they MUST NOT be hidden in code
   comments or informal messages alone.
9. A compatibility target named in the Architecture and Quality Constraints MUST NOT be changed in
   a feature plan or a task. Such a change MUST be approved as a constitution amendment, recorded
   as an `ACC-NNN` entry, and applied to the plans and specifications that assert the old target.

## Governance

This constitution governs engineering and delivery practices for PettyCash. It supersedes
conflicting informal conventions, while business behavior remains governed by the applicable
requirements and approved feature specifications. When artifacts conflict, work MUST stop long
enough to reconcile the conflict in the authoritative artifact rather than choosing silently.
Reconciling a conflict by reinterpreting one artifact's words -- for example by reading a narrowed
requirement as a clarification rather than as a change -- is itself a conflict and MUST NOT be used
to close one.

Amendments require a documented proposal, rationale, affected principles or sections, impact on
active specifications and plans, and approval by the project maintainer. Amendments that weaken
security, audit immutability, historical preservation, or specification-first delivery require an
explicit migration and risk-acceptance record. An amendment that changes a compatibility target
additionally MUST carry an `ACC-NNN` entry recording the evidence, impact analysis, migration path,
and compatibility verification plan. The amendment MUST update this file's Sync Impact Report,
version, and amendment date.

Constitution versions follow semantic versioning:

- MAJOR for removing or redefining a principle in a backward-incompatible way.
- MINOR for adding a principle or materially expanding mandatory governance.
- PATCH for clarifications that do not change required behavior.

Every feature specification, implementation plan, task list, review, and release readiness check
MUST include a constitution compliance review. That review MUST also confirm that every
compatibility target the reviewed artifacts assert matches the current register, and that no
artifact asserts an unregistered change. Exceptions MUST be written, scoped, risk-assessed,
approved by the project maintainer, and assigned an expiry or follow-up task. Unrecorded exceptions
are invalid.

**Version**: 1.3.0 | **Ratified**: 2026-09-19 | **Last Amended**: 2026-10-02
