<!--
Sync Impact Report
- Version change: unratified template -> 1.0.0
- Modified principles:
  - Placeholder Principle 1 -> I. Specification Before Implementation
  - Placeholder Principle 2 -> II. Preserve Contracts and Existing Behavior
  - Placeholder Principle 3 -> III. Nextcloud-Native, Boundary-Conscious Architecture
  - Placeholder Principle 4 -> IV. Domain Integrity, Security, and Auditability
  - Placeholder Principle 5 -> V. Verified and Documented Delivery
- Added sections:
  - Architecture and Quality Constraints
  - Delivery and Change Management
- Removed sections: none; template placeholder sections were concretized
- Follow-up TODOs: none
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
8.1+ baseline unless a planned compatibility change is approved. Backend code MUST remain within
the `OCA\PettyCash` PSR-4 boundary. Browser-facing functionality MUST use the established Vue 3 and
TypeScript direction and MUST preserve the Persian RTL, responsive user experience required for
V1. MariaDB compatibility MUST be retained through Nextcloud-supported database and migration
mechanisms; application code MUST NOT depend on environment-specific credentials or direct
container access.

Nextcloud facilities MUST remain authoritative for authentication, user identity, file access,
security controls, and technical logging. PettyCash MUST NOT introduce a parallel authentication
system, an independent technical-log subsystem, or unmanaged document storage. Domain modules
MUST keep business rules, persistence, transport, and presentation concerns separable so they can
be tested and changed without bypassing access control or workflow rules. Detailed internal
architecture, schemas, APIs, and component structures MUST be decided in feature plans, not
assumed from the skeleton or legacy application.

Rationale: using platform services preserves compatibility and security while separation of
concerns keeps still-open architecture decisions reversible.

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
why automation is not yet feasible, list repeatable test steps, and create follow-up work for the
missing coverage.

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
- Compatibility targets are Nextcloud 34.x, PHP 8.1+, MariaDB, Vue 3, and TypeScript 5 unless an
  approved plan includes impact analysis, migration, and compatibility verification.
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

## Governance

This constitution governs engineering and delivery practices for PettyCash. It supersedes
conflicting informal conventions, while business behavior remains governed by the applicable
requirements and approved feature specifications. When artifacts conflict, work MUST stop long
enough to reconcile the conflict in the authoritative artifact rather than choosing silently.

Amendments require a documented proposal, rationale, affected principles or sections, impact on
active specifications and plans, and approval by the project maintainer. Amendments that weaken
security, audit immutability, historical preservation, or specification-first delivery require an
explicit migration and risk-acceptance record. The amendment MUST update this file's Sync Impact
Report, version, and amendment date.

Constitution versions follow semantic versioning:

- MAJOR for removing or redefining a principle in a backward-incompatible way.
- MINOR for adding a principle or materially expanding mandatory governance.
- PATCH for clarifications that do not change required behavior.

Every feature specification, implementation plan, task list, review, and release readiness check
MUST include a constitution compliance review. Exceptions MUST be written, scoped, risk-assessed,
approved by the project maintainer, and assigned an expiry or follow-up task. Unrecorded exceptions
are invalid.

**Version**: 1.0.0 | **Ratified**: 2026-09-19 | **Last Amended**: 2026-09-19
