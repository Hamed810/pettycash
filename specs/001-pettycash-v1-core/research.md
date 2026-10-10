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
- The legacy backup was rejected as an implementation base. PettyCash V1 is a fresh application, so
  it is reference material only: no legacy database import, migration, or data-compatibility path is
  in V1 scope (FR-080).

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

## Decision 4: Use Nextcloud Database APIs and Forward-Only App-Scoped `pettycash_*` Migrations

**Decision**: Use Nextcloud schema migrations, database connection, query builder, and explicit
repositories. Create V1 tables under the logical app-scoped `pettycash_*` prefix with internal numeric
keys and public UUIDs, and let the Nextcloud database abstraction apply the instance prefix; never
hardcode an instance prefix such as `oc_`. Name every index, unique constraint, foreign key, and
check constraint `pettycash_<table>_<subject>_<kind>` (`idx`, `uniq`, `fk`, `chk`) so that no object
can collide with another app in the shared database. Migrations are forward-only.

PettyCash V1 is a fresh application: there is no legacy database import, schema migration, table
preservation, or data-compatibility contract, and none is planned. The `pc_*` tables already present
in the current development database are provisional V1 development schema, not legacy data; the
baseline migrations and all repositories, tests, and fixtures are rewritten to the `pettycash_*`
namespace so `pc_*` never enters V1 migration history, and that disposable development data is
discarded by design. Because the discarded prototype shared the `pettycash` app ID, stale
migration-state rows can cause Nextcloud to skip a rewritten baseline; clearing the `pettycash`
app-ID migration state is therefore part of an approval-gated development-only reset that affects
PettyCash only and never touches Docker volumes or the shared database. Residual prototype tables
in a shared development database are left untouched and unmanaged.

**Rationale**: This is portable within the supported Nextcloud database abstraction, and the
app-scoped namespace is what actually prevents collisions with other Nextcloud apps on the shared
instance — a short `pc_` prefix and unprefixed identifier assumptions do not. Forward-only
migrations under that namespace keep the V1 baseline clean because no pre-V1 data is preserved.

**Alternatives considered**:

- A separate ORM was rejected because it duplicates platform facilities and complicates upgrade
  compatibility.
- Reusing the previous installation's tables was rejected because no legacy import or data
  compatibility is in V1 scope.
- Adding a rename migration from `pc_*` to `pettycash_*` was rejected because V1 is unreleased and
  keeps the provisional name out of migration history entirely.
- Retaining `pc_*` for existing development tables was rejected because it would leave a mixed,
  non-app-scoped namespace in the V1 baseline.
- Dropping residual prototype tables in the shared environment was rejected because it would touch
  data outside the V1 contract and is unnecessary for a clean V1 install.

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
disabled until its security tests pass. **Amended 2026-10-07 (Decision 19):** *disabled by default*
governs **creation**, not consumption. A link that an administrator creates is consumed
unauthenticated — see **Decision 19**, which is the settled rule and supersedes any reading of this
entry that treats a link as inheriting the requester's role scope.

**Rationale**: The specification permits rather than requires public links. Deferring their
activation reduces early exposure while preserving the approved capability.

**Alternatives considered**:

- Exposing AppData paths was rejected because they are not public resources.
- Enabling anonymous links by default was rejected due to financial-document sensitivity. **This
  rejection stands for *default creation*.** It was not a rejection of anonymous *consumption* of an
  explicitly created link, which is what the source requirement §11.8 contemplates and what Decision
  19 settles. The two must not be conflated again: a link is never created implicitly, and once one
  exists deliberately, it is consumed without authentication.

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

## Decision 16: Treat Database Portability as an Explicit V1 Requirement

**Status**: Accepted 2026-09-25 (clarification session)

**Context**: The project develops against MariaDB 11.4, and the constitution and the original plan
named MariaDB as the compatibility target. That risks MariaDB-specific behavior becoming an
undeclared application dependency, and a MariaDB-only green test run would not demonstrate
portability.

**Authoritative sources consulted** (not inferred):

1. *Nextcloud 34 Administration Manual — System requirements*
   `https://docs.nextcloud.com/server/34/admin_manual/installation/system_requirements.html`
   (docs footer marks 34 as "stable"; last updated for 34 in 2026)
2. *Nextcloud 34 Developer Manual — Database access*
   `https://docs.nextcloud.com/server/34/developer_manual/basics/storage/database.html`
   (last updated 2026-07-07)
3. *Nextcloud server `OCP\IDBConnection`* — `https://github.com/nextcloud/server/blob/master/lib/public/IDBConnection.php`

**Documented Nextcloud 34 backends** (verbatim from source 1):

| Backend | Documented versions | Nextcloud's own qualification |
|---|---|---|
| MySQL | 8.0 / 8.4 | supported |
| MariaDB | 10.6 / 10.11 / **11.4** / 11.8 | **recommended** |
| Oracle | 19c, 21c, 23ai | *only as part of an enterprise subscription* |
| PostgreSQL | 14 / 15 / 16 / 17 / **18** | **recommended** |
| SQLite | 3.24+ | *only recommended for testing and minimal-instances* |

**Decision**: V1 declares three Nextcloud database types in `appinfo/info.xml`:

| Declared `<database>` | Concrete engines covered | Nextcloud's own qualification |
|---|---|---|
| `mysql` | MySQL 8.0/8.4 and MariaDB 10.6/10.11/11.4/11.8 | MariaDB is the **recommended** engine; Nextcloud documents that "MariaDB is backwards compatible with MySQL" and that one configuration covers both |
| `pgsql` | PostgreSQL 14/15/16/17/18 | **recommended** |
| `sqlite` | SQLite 3.24+ | *only recommended for testing and minimal-instances* — declared here for **compatibility verification**, not as a production recommendation |

Oracle/`oci` is explicitly **outside V1 scope**. It may be added later only as an explicit
Enterprise compatibility target. MariaDB 11.4 remains the local development backend only and is
not a declared supported backend in its own right.

**Note on SQLite**: Nextcloud qualifies SQLite for testing and minimal instances only. PettyCash
declares it to verify compatibility, not to recommend it for production financial data. The
developer manual (source 2) warns that on SQLite a result cursor must be closed after custom
`execute` calls "to prevent database lock problems", which is stricter than the other backends.

**Consequences**: because `appinfo/info.xml` now declares `mysql`, `pgsql`, and `sqlite` explicitly,
the stricter Oracle schema tests that Nextcloud applies when Oracle is supported — **and when no
database is declared at all** (source 2, "Supporting more databases") — do not bind this project.
The project must nevertheless not exceed the portable limits by accident.

**Portable rules this project adopts as V1 requirements** (each is required by a *declared*
backend, not by Oracle):

- Use `OCP\IDBConnection`, `OCP\DB\QueryBuilder\IQueryBuilder`, `OCP\AppFramework\Db\QBMapper`
  and `OCP\DB\Types`; never reference `Doctrine\DBAL\Types\Type::*` constants directly. The
  developer manual gives a concrete PostgreSQL failure here: PHP casts `false` to an empty string,
  which PostgreSQL rejects.
- Prefer `getTypedQueryBuilder()` over `getQueryBuilder()` — introduced and preferred in
  Nextcloud 34.
- Migrations use the Nextcloud migration/schema APIs with `Types::*` and are forward-only. They never
  read, write, or drop prototype tables that may exist in the shared database (FR-080).
- Tables use the logical app-scoped `pettycash_` prefix and indexes, unique constraints, foreign
  keys, and check constraints use `pettycash_<table>_<subject>_<kind>`, because the instance prefix
  is applied by the framework and schema object names are database-wide global on some platforms.
  No `pc_`-prefixed table or index name remains in V1.
- Every table carries an auto-increment integer `id` primary key; indexes are explicitly named
  because index names are database-wide global on some platforms.
- `GROUP BY` queries must aggregate every non-aggregated selected column, because MySQL/MariaDB
  `ONLY_FULL_GROUP_BY` rejects otherwise. No `GROUP BY` exists in PettyCash today, so this binds
  the still-open totals and reporting queries (T105, T106, T101, T102).
- A delete affecting many rows must not use a `LIMIT` clause: MySQL/MariaDB accept it, other
  backends do not.
- `IN` lists are chunked where a backend limit is known, detected via
  `IDBConnection::getDatabaseProvider()`.
- `info.xml` declares `<database>mysql</database>`, `<database>pgsql</database>`, and
  `<database>sqlite</database>` so the supported set is explicit rather than implied.
- Because the `mysql` Nextcloud type covers two distinct concrete engines, CI must exercise MySQL
  and MariaDB as separate jobs rather than treating a passing MariaDB run as evidence for MySQL.
- On SQLite, a result cursor must be closed after custom `execute` calls "to prevent database lock
  problems" (source 2) — stricter than the other declared backends.

**Rules deliberately NOT adopted as V1 requirements** (Nextcloud applies them for Oracle, which is
*not* a declared V1 backend, and they were **not** adopted as blanket constraints per the
2026-09-25 clarification):

| Oracle-derived rule | NC 34 text | Why not adopted for V1 |
|---|---|---|
| Table names ≤ 27 incl. `oc_` prefix | "Table names can not be longer than 27 characters" | Every declared backend allows far more (MySQL 64, PostgreSQL 63, SQLite effectively unlimited). `pettycash_transaction_revisions` is 29 and compliant with the declared set, so the app-scoped name is kept unabbreviated. |
| Boolean columns never `NOT NULL` | "Boolean columns can not be NotNull" | Not required by `mysql`/`pgsql`/`sqlite`. Current schema happens to comply, but it is not a V1 gate. |
| String columns ≤ 4,000 chars | "String columns can not have a length longer than 4.000 characters" | Not required by the declared set; current maximum is 1,024. |
| No `NOT NULL` string with empty default in later migrations | "String columns can not be NotNull and have an empty string as default value when being added in a later migration" | Not required by the declared set. |
| Column / index / FK / sequence names ≤ 30 | "…can not be longer than 30 characters" | Not required by the declared set; names follow the `pettycash_<table>_<subject>_<kind>` convention and are abbreviated only when a full descriptive name would exceed the declared limits. |
| Galera Cluster: all tables need a primary key | "All tables must have a primary key" | Already satisfied and harmless; retained as good practice, not as an Oracle gate. |

**Deferred architecture constraint — Oracle / Enterprise compatibility.** Recorded in `plan.md`.
If Oracle support is ever added it MUST first re-verify the exact requirements against the targeted
Nextcloud and Oracle versions (the table above reflects Nextcloud 34 documentation and may change),
and MUST be given an explicit compatibility and migration plan. No existing table is renamed and no
migration is added in anticipation of it.
- Because the `mysql` Nextcloud type covers two distinct concrete engines, CI must exercise MySQL
  and MariaDB as separate jobs rather than treating a passing MariaDB run as evidence for MySQL.

## Decisions 17–21: Settled by the 2026-10-07 Clarification Session

Decisions 1–16 above resolve the Technical Context unknowns of the original Phase 0 pass.
**Decisions 17–21** resolve the five ambiguities the clarification session of 2026-10-07 settled
after that pass, each recorded with the alternatives weighed so implementation does not re-open them.
They are appended rather than interleaved so the original pass's reasoning stays readable in order.

## Decision 17: Exclude Environment-Induced Responses from the Error-Rate Figure

**Decision**: An **environment-induced response** — a brute-force `429`, an infrastructure or platform
`5xx`, a transport failure — is excluded from both the numerator and the denominator of FR-073's error
rate, the excluded count is published beside the figure, and **if the excluded share exceeds 1% of the
requests the run issued the figure is recorded as *not measured*** rather than reported. Deliberately
provoked 4xx/409/422 remain excluded as a separate, narrower class already settled on 2026-10-02.

**Rationale**: A contended run does not merely add noise to the ratio, it **inverts** it. Throttled
responses inflate the denominator with traffic that never exercised application code while suppressing
the successful requests that would have exercised it, so an unusable run yields a number that looks
better than a clean one. A single contamination ceiling is needed because publishing the excluded count
alone still invites a reader to compute a ratio from a run that was mostly throttled.

**Alternatives considered**:

- Counting every request the suite issued was rejected: it makes the metric sensitive to the test
  harness's environment rather than to the application, which is not what a defect threshold is for.
- Keeping the 2026-10-02 rule verbatim and merely noting contamination as a caveat was rejected: a
  caveat beside a number does not stop the number being quoted.
- Replacing the measurement with a dedicated quiet run was rejected as the *sole* remedy because it
  makes the figure unobtainable whenever the shared instance is imperfect, and this project shares one
  instance with other applications by design.

**Consequence**: This is the first rule in the feature that makes a **contended** run explicitly
non-evidence rather than merely noisy. `tasks.md` T313's record of four full-suite attempts producing
2, 7, 30 and 151 failures — every one a `429` assertion, from two concurrent sessions on one shared
instance — is exactly the case this decides.

## Decision 18: Treat a Public Evidence Link as Unauthenticated Access

**Decision**: The unguessable token is the **whole authority**. A link is consumed without signing in
and without any role or responsibility scope; abuse control is delegated to Nextcloud's existing
brute-force limiter and PettyCash MUST NOT add a second authentication, rate-limiting, or access-control
mechanism; every access records configuration audit with the viewer as `anonymous` plus timestamp and
IP; and **expired, deactivated and never-existing tokens are refused identically**. FR-031 governs the
capability, and this decision supplies the access rule it previously lacked.

**Rationale**: The source requirement §11.8 authorises a *public* link and constrains only its creation;
§11.9 places passwords, login/logout and authentication policy in Nextcloud's hands. Inventing a
PettyCash-side authorization layer for links would therefore create the second authentication system
Principle III forbids, while requiring a signed-in holder would silently narrow a capability the
requirement grants. Recording the viewer as `anonymous` rather than leaving the UID field empty is
required for the audit to serve the access investigation it exists for.

**Alternatives considered**:

- Requiring a signed-in holder with independent view rights was rejected as the recommendation because
  it makes the link a convenience for a user who could already open the file, which is a narrower
  capability than §11.8 grants.
- A per-link administrator choice between anonymous and authenticated access was rejected as the
  recommendation: two security paths to implement, test and audit, chosen before any link exists.
- Token-only access with no throttle and no per-access identity was rejected outright; it discards the
  audit FR-031 requires.

**Consequence**: `contracts/ocs-api.yaml` has **no route that consumes a token** — it defines
`POST /evidence/{evidenceId}/links` and `POST /evidence-links/{linkId}/deactivate` only. That route is
now required, not optional, and it is the one place in V1 reachable without authentication.

## Decision 19: Adopt Keyboard Operability as the V1 Accessibility Floor

**Decision**: Each **primary journey** (the journeys exercised by User Stories 1–7) MUST be completable
**using the keyboard alone**, with a visible focus indicator on every interactive element reached,
including under dark and high-contrast themes. **Screen-reader semantics — label association, error
association, and announced status for mutations — are deferred to V2** under FR-076 and struck from
`quickstart.md`.

**Rationale**: The keyboard floor is what makes the workflow usable without a pointer and is the
accessibility promise the team had already written into its own release checklist; a focus indicator
the active theme erases leaves a keyboard user with no indication of position, so theme coverage is
part of the criterion rather than a nicety. The three semantic items were deferred on **scale, not
importance**: naming every control and announcing every mutation is a compliance-sized surface to add
after 71 of 276 ledger tasks are complete, and deferring it explicitly is honest where silence would
have read as satisfied.

**Alternatives considered**:

- Adopting the full `quickstart.md` list including semantics was rejected on the same scale argument.
- Scoping V1 to contrast, font size and no-horizontal-scroll only was rejected because it would delete
  a check the project had already committed to, losing real usability for no saving.
- Leaving the quickstart items advisory was rejected because an advisory check that testers record is
  indistinguishable from one nobody reads.

**Consequence**: FR-071 and SC-009 now state the criterion; the removed items must not be reported as
met in V1 release readiness.

## Decision 20: Resolve a Concurrent Decision First-Write-Wins with a Superseded Attempt

**Decision**: A decision submitted against state that no longer accepts it applies **no** business
effect, **overwrites nothing**, is answered `409 Conflict` naming that a decision is already recorded,
and **is still audited as a `SUPERSEDED_ATTEMPT`** — its own event type, neither an approval nor a
rejection — naming the transaction, its active revision, the stage, the actor, the time, the decision
attempted, and the decision already in force.

**Rationale**: First write wins is chosen over last-write-wins specifically to make **double-booking
structurally impossible**: accepting a second approval is the only outcome that could produce two
consumption movements against one transaction and decrement the account twice, and a rejected or
returned transaction could be silently promoted to approved. Recording the loser is what distinguishes
a reviewer's real action from a request that never happened; recording it *as* an approval or rejection
would falsify the very decision history Principle IV protects. This completes, rather than repeats,
Decision 7: `lock_version` was designed in for optimistic checks but no behaviour was ever specified
for what happens when the check fails.

**Alternatives considered**:

- Last-write-wins was rejected on double-booking grounds.
- Silently ignoring the loser was rejected because it is indistinguishable from a request that never
  happened.
- Server-side serialization with transparent client retry was rejected because a blocking lock buys no
  correctness property refusal does not already provide, and the retry it implies contradicts FR-075's
  prohibition on automatic resumption.

**Consequence**: Distinct from FR-084. A **replay** is one command arriving twice under the same
`X-PettyCash-Command-ID` and returns the first outcome; a **superseded attempt** is a *different*
command issued against changed state, which no command identifier can detect and which therefore
requires a server-side state check. The `AuditEvent.eventType` field in the contract is an open string
and MUST gain the `SUPERSEDED_ATTEMPT` value.

## Decision 21: Refuse Finance Settlement When No Active Account Matches the Sheet Currency

**Decision**: At final finance approval, if no **active** petty-cash account in the sheet's currency
exists for the purchaser, the approval is **refused with `422`** naming the missing or inactive account;
the transaction stays at the finance stage and no balance movement, consumption record, workflow
transition or final outcome is recorded. **FR-020's idempotent provisioning does not extend to
settlement.**

**Rationale**: FR-040 requires one consumption movement against the account matching the sheet currency
and FR-042 requires atomic completion, so with no such account the requirement had no satisfiable
action and no stated outcome. Extending FR-020 into settlement would mean a finance decision silently
creates a funding object — booking approved expense against a balance that was never funded or
approved, which is exactly the silent financial effect FR-042 and Principle IV exist to prevent. The
remedy is instead an administrator's deliberate, audited act (restore, reactivate, or charge an
account under FR-015/FR-017), so the refusal must be actionable rather than merely valid. An
approved-but-unsettleable expense is an acceptable and **visible** intermediate state; an expense
booked against a balance conjured up at approval time is not.

**Alternatives considered**:

- Auto-provisioning a new account at settlement was rejected as the recommendation for the reason
  above — it makes money movement a side effect of a decision click.
- Settling against the purchaser's most recent account regardless of currency was rejected outright:
  it books an amount against a balance denominated in something else.
- Refusing the sheet earlier, at submission or at each stage transition, was rejected as the
  *sole* rule because it cannot cover an account deactivated **after** submission, which is the case
  that actually strands an approved expense.

**Consequence**: T095/T166 aside, this makes the settlement path fail **loudly and specifically** in a
case where it previously would have failed ambiguously. The refusal is not a system fault and must not
be presented as one; it is a correct refusal caused by a configuration gap.

## Primary Sources Consulted

- `DOC/Requirements/11-PettyCash_Requirements_Document_Section_11_RTL.md` §11.8 (public link, admin
  control, manageability, deactivation, audit) and §11.9 (authentication and login policy belong to
  Nextcloud) — the source for Decision 18
- `DOC/Requirements/` Sections 1–13 generally, as the business-domain baseline the constitution names
- `specs/001-pettycash-v1-core/checklists/requirements.md` (quickstart accessibility items) and
  `tasks.md` T313 (the `429`-contended full-suite record) — the sources for Decisions 17 and 19
- Nextcloud 34 Developer Manual: app architecture, routing, controllers, security, database,
  migrations, files, translations, and upgrade guidance
- Official Nextcloud app-template and Nextcloud Vue, Vite config, Axios, router, localization, and
  initial-state packages
- Vue 3 and Vue Test Utils testing guidance
- Playwright browser-project and device testing guidance

## Research Risks Carried into Tasks

- Verify the stopped local container's exact Nextcloud/PHP versions before dependency installation.
- Clear the migration state recorded for the `pettycash` app ID through the approved
  development-only reset before verifying a rewritten baseline, because the discarded prototype used
  the same app ID and its rows can cause Nextcloud to skip V1 migrations.
- Prove AppData upload compensation and public-link behavior with an early integration spike.
- Load-test XLSX/PDF memory behavior and Persian PDF rendering at the 10,000-transaction fixture.
- Test concurrent decisions and finance approvals with real MariaDB locking.
- Clearly label latest-effective totals versus financially final totals in UI and exports.
- **Settled 2026-10-07 — no longer open.** The risk that concurrent decisions could duplicate a
  financial effect is now decided: **Decision 20** (first write wins, `SUPERSEDED_ATTEMPT`, `409`).
  The risk that public-link behaviour needed an early integration spike is now partly decided:
  **Decision 18** settles the access rule, though the consumption route still needs the spike.
- **New, settled 2026-10-07 — recorded so it is not rediscovered as a gap.** An acceptance run
  degraded by Nextcloud's brute-force limiter is **not evidence of an error-rate figure in either
  direction** (**Decision 17**). Because this project shares one Nextcloud instance with other
  applications, contended runs are an expected operational condition rather than a rare accident, and
  the full-suite evidence in `tasks.md` T313 is currently unverified for exactly this reason.
