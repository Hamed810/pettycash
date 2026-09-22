---

description: "Dependency-ordered implementation tasks for PettyCash V1 core workflow"
---

# Tasks: PettyCash V1 Core Workflow

**Input**: Design documents from `specs/001-pettycash-v1-core/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/`,
`quickstart.md`, and `.specify/memory/constitution.md`

**Tests**: Automated tests are mandatory because the specification and constitution require
repeatable verification of deterministic business rules, authorization, workflow, persistence,
audit, migrations, frontend behavior, and end-to-end acceptance. Within each story, test tasks are
listed before implementation tasks and must fail for the intended reason before implementation.

**Organization**: Tasks are grouped by user story so each increment has an explicit goal and an
independent validation path. Shared infrastructure appears only in Setup and Foundational phases.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: May run in parallel because it targets separate files and does not depend on another
  incomplete task in the same phase.
- **[Story]**: Maps the task to a specification user story.
- Every task names the file or directory it changes.

## Phase 1: Setup and Toolchain

**Purpose**: Establish a reproducible Nextcloud 34, PHP, Vue, TypeScript, build, and test baseline
without introducing business behavior.

- [ ] T001 Create a non-destructive runtime and migration preflight script that verifies Nextcloud 34.x, PHP 8.2–8.5, the `pettycash` mount, existing `pettycash` migration history, and presence of legacy `oc_pcash_*` tables without changing them in `scripts/preflight.sh`
- [ ] T002 Update the PHP runtime constraint to a Nextcloud-34-compatible PHP 8.2+ range, add PSR-4 test autoloading, and add locked development dependencies for PHPUnit, `nextcloud/ocp:dev-stable34`, Psalm, and Nextcloud coding standards in `composer.json`
- [ ] T003 [P] Replace placeholder frontend dependency ranges with pinned Vue 3, TypeScript, `@nextcloud/vue` 9.x, `@nextcloud/axios`, `@nextcloud/router`, `@nextcloud/l10n`, Pinia, Vue Router, Vite, Vitest, Vue Test Utils, ESLint, Stylelint, and Playwright scripts in `package.json`
- [ ] T004 [P] Configure Vue/TypeScript compilation from `src/main.ts` into deployable `js/` and `css/` assets using `vite.config.ts`, `tsconfig.json`, and `src/env.d.ts`
- [ ] T005 [P] Configure PHP linting, formatting, static analysis, and unit/integration/contract test suites in `.php-cs-fixer.dist.php`, `psalm.xml`, `phpunit.xml`, and `tests/bootstrap.php`
- [ ] T006 [P] Configure frontend linting, style linting, unit/component tests, and browser projects in `eslint.config.js`, `stylelint.config.js`, `vitest.config.ts`, and `playwright.config.ts`
- [ ] T007 Create the planned modular-monolith and frontend source directories with namespace-safe placeholders in `lib/Application/`, `lib/Domain/`, `lib/Infrastructure/`, `lib/Migration/`, `src/`, `templates/`, `l10n/`, and `tests/`
- [ ] T008 Add the authenticated Nextcloud page route, thin page controller, mount template, and generated asset registration in `appinfo/routes.php`, `lib/Controller/PageController.php`, and `templates/main.php`
- [ ] T009 Implement the lightweight `IBootstrap` application shell and service-registration conventions while preserving `Application::APP_ID = 'pettycash'` in `lib/AppInfo/Application.php`
- [ ] T010 Add consolidated local quality commands and safe development instructions in `Makefile` and `README.md`

**Checkpoint**: Dependency installation, empty application build, and empty test suites run on the
verified host without changing shared volumes, other apps, or legacy tables.

---

## Phase 2: Foundational Architecture and Persistence

**Purpose**: Build the shared database, domain, authorization, idempotency, audit, storage, error,
and client foundations that block every user story.

**⚠️ CRITICAL**: No user story implementation begins until this phase passes its tests.

- [ ] T011 [P] Write failing migration safety tests proving installation beside existing `pettycash` migration records creates only `pc_*` tables and never drops, renames, or rewrites legacy `oc_pcash_*` tables in `tests/Integration/Migration/V1SchemaMigrationTest.php`
- [ ] T012 [P] Write failing shared-domain tests for UUID identifiers, exact integer-minor-unit money, currency mismatch rejection, positive-amount validation, and UTC timestamp handling in `tests/Unit/Domain/Shared/MoneyAndIdentityTest.php`
- [ ] T013 [P] Write failing authorization matrix tests covering purchaser ownership, manager cost-center scope, senior scope, shared finance queue, administrator-without-finance denial (explicit test: admin without finance role attempting finance decision returns forbidden per FR-007), and permitted self-review for otherwise authorized multi-role users (explicit allow when user holds required review role + scope + stage permission; ownership grants purchaser rights only per FR-002/FR-008) in `tests/Unit/Security/AuthorizationServiceTest.php`
- [ ] T014 [P] Write failing idempotency tests proving same command UUID and payload replays the original result while the same UUID with different content conflicts in `tests/Integration/Workflow/IdempotencyServiceTest.php`
- [ ] T136 [P] Write failing integration tests for atomic rollback on mid-operation failure at each workflow stage (submission, management decision, finance settlement, correction resubmit) in `tests/Integration/Workflow/AtomicityFailureInjectionTest.php`
- [ ] T015 Create forward-only configuration/account migrations with constraints `Currency.code: unique uppercase code`, `Currency.minor_unit: valid range 0–4`, `PettyCashAccount: unique user and currency pair`, and inactive records retained for history in `lib/Migration/Version010000Date20260920000000.php`
- [ ] T016 Create forward-only sheet/transaction/revision/decision migrations with constraints `amount_minor: must be greater than zero`, `revision_number: starts at 1; unique per transaction`, one effective decision per revision and stage, and no destructive cascades in `lib/Migration/Version010100Date20260920000000.php`
- [ ] T017 Create forward-only evidence, transaction-audit, configuration-audit, command-result, and public-link migrations with unique command IDs, unique consumption source, hashed link tokens, and append-only historical tables in `lib/Migration/Version010200Date20260920000000.php`
- [ ] T018 Implement UUID, Money, CurrencyCode, Clock, actor snapshot, and domain invariant primitives using integer minor units and canonical serialization in `lib/Domain/Shared/`
- [ ] T019 [P] Implement workflow, role, decision, sheet-status, transaction-status, movement-type, audit-event, and capability enums matching `data-model.md` in `lib/Domain/Workflow/` and `lib/Domain/Shared/Enum/`
- [ ] T020 [P] Define repository, transaction manager, user directory, administrator check, private file store, clock, audit writer, and exporter ports in `lib/Application/Port/` and `lib/Domain/*/Repository/`
- [ ] T021 Implement the Nextcloud database transaction adapter and fixed lock-order helpers for sheet → transaction → revision → account in `lib/Infrastructure/Persistence/NextcloudTransactionManager.php`
- [x] T022 [P] Implement configuration and account persistence entities, mappers, and role-scoped query repositories in `lib/Infrastructure/Persistence/Configuration/` and `lib/Infrastructure/Persistence/Account/`
- [x] T023 [P] Implement sheet, transaction, revision, decision, and aggregate-status persistence entities, mappers, and paged query repositories in `lib/Infrastructure/Persistence/Expense/` and `lib/Infrastructure/Persistence/Workflow/`
- [x] T024 [P] Implement evidence, public-link, audit, and command-result persistence with insert-only audit repositories and no audit update/delete methods in `lib/Infrastructure/Persistence/Evidence/`, `lib/Infrastructure/Persistence/Audit/`, and `lib/Infrastructure/Persistence/Command/`
- [ ] T025 Implement Nextcloud user-directory, current-user, administrator, clock, technical-log, and private AppData adapters in `lib/Infrastructure/Nextcloud/`
- [ ] T026 Implement centralized object authorization and role-scoped query policies from the tested matrix in `lib/Application/Security/AuthorizationService.php` (enforce: self-review permitted when user holds required review role + scope + stage permission; ownership grants purchaser rights only; cost-center/senior/finance scope checks; admin no finance without finance role - explicit deny for admin attempting finance actions per FR-007)
- [ ] T027 Implement command UUID payload hashing, replay, and conflict handling in `lib/Application/Command/IdempotencyService.php`
- [x] T028 Implement transaction and configuration audit writers that snapshot UID, display name, role, stage, revision, explanation, command ID, and canonical before/after JSON in `lib/Application/Audit/`
- [ ] T029 Implement consistent OCS error mapping for validation, forbidden, hidden-not-found, stale state, idempotency conflict, and unexpected failures in `lib/Infrastructure/Http/OcsErrorMapper.php`
- [ ] T030 Create the typed frontend OCS client, canonical error model, command-ID generator, pagination types, and no-automatic-mutation-retry behavior in `src/services/http.ts`, `src/types/api.ts`, and `src/types/permissions.ts`

**Checkpoint**: All foundational tests pass; migrations install safely; shared services resolve from
the Nextcloud container; no feature endpoint exists without centralized authorization and error
mapping.

---

## Phase 3: User Story 1 — Capture and Submit Expenses (Priority: P1) 🎯 MVP

**Goal**: Purchasers create multiple open sheets, add valid draft transactions and evidence, and
submit a complete sheet into formal cost-center review.

**Independent Test**: Using fixture configuration, create two sheets, validate fixed/selectable
currencies, save a minimal draft, reject incomplete submission, upload evidence, and successfully
submit without requiring a positive balance.

### Tests for User Story 1

- [ ] T031 [P] [US1] Write failing domain tests for open-sheet editing, `expense date and positive amount` minimum draft data, submission-required description/cost center/active expense type/evidence, inherited sheet currency, multiple open sheets, and balance-independent submission in `tests/Unit/Domain/Expense/SheetSubmissionPolicyTest.php`
- [x] T032 [P] [US1] Write failing OCS contract tests for create/list/detail/update sheet, create/update/delete transaction, evidence upload/delete, and submit endpoints from `contracts/ocs-api.yaml` in `tests/Contract/Controller/PurchaserFlowControllerTest.php` (evidence upload tests: 100 MB max enforced, oversized rejected, no advanced restrictions per FR-030)
- [ ] T033 [P] [US1] Write failing integration tests proving first submission snapshots totals, creates the first formal audit event, locks new-transaction creation, routes each transaction by cost center, and compensates an AppData write when metadata persistence fails in `tests/Integration/Workflow/SheetSubmissionTest.php` (evidence upload: 100 MB max per FR-074, oversized rejected, no advanced restrictions per FR-030)
- [ ] T034 [P] [US1] Write failing Vue tests for sheet/transaction forms, field-level submission errors, account-currency selection, evidence upload state, and submitted read-only behavior in `tests/Frontend/Component/PurchaserFlow.spec.ts`

### Implementation for User Story 1

- [ ] T035 [P] [US1] Implement ExpenseSheet, ExpenseTransaction, and TransactionRevision domain models with constraints `one purchaser`, `one source account/currency`, `amount_minor greater than zero`, and revision 1 mutable only while draft in an open sheet in `lib/Domain/Expense/`
- [ ] T036 [P] [US1] Implement private evidence storage with opaque `evidence/<transaction-uuid>/<revision>/` keys, 100 MB max file size per FR-074, checksums, safe original filenames, and draft-delete compensation in `lib/Infrastructure/Nextcloud/AppDataEvidenceStore.php` (advanced evidence restrictions deferred to V2 per FR-030)
- [ ] T037 [US1] Implement create/update/list/detail sheet and create/update/delete draft transaction commands/queries with owner-scoped access in `lib/Application/Expense/`
- [ ] T038 [US1] Implement upload and pre-submission evidence removal services using the evidence metadata constraints in `lib/Application/Evidence/EvidenceService.php` (enforce 100 MB max file size per FR-074; no advanced evidence restrictions in V1 per FR-030)
- [ ] T039 [US1] Implement atomic sheet submission validation, submitted-total snapshot, manager snapshots, initial routing, sheet projection update, and first audit insertion in `lib/Application/Workflow/SubmitSheet.php`
- [ ] T040 [US1] Add purchaser OCS routes and thin sheet, transaction, and evidence controllers in `appinfo/routes.php` and `lib/Controller/SheetController.php`, `lib/Controller/TransactionController.php`, and `lib/Controller/EvidenceController.php` (evidence upload enforces 100 MB max per FR-074; no advanced evidence restrictions in V1 per FR-030)
- [ ] T041 [P] [US1] Implement typed purchaser API services and Pinia stores in `src/services/sheetsApi.ts`, `src/services/transactionsApi.ts`, `src/services/evidenceApi.ts`, and `src/stores/sheets.ts`
- [ ] T042 [US1] Implement sheet list/detail/form, transaction list/form/detail, and evidence uploader/list views with Persian-ready translatable strings in `src/views/sheets/`, `src/views/transactions/`, and `src/components/{sheets,transactions,evidence}/` (all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071)
- [ ] T043 [US1] Add the purchaser capture/submission Playwright journey and expected audit assertions in `tests/E2E/purchaser-submit.spec.ts`

**Checkpoint**: User Story 1 is independently usable as the first MVP slice with seeded reference
data; all its tests pass before proceeding.

---

## Phase 4: User Story 2 — Perform Management Review (Priority: P1)

**Goal**: Cost-center and senior managers review scoped transactions, record immutable approve,
reject, or return decisions, and advance workflow without erasing earlier opinions.

**Independent Test**: Submit transactions for two cost centers, verify scoped queues and permitted
self-review, require explanations for reject/return, and complete both management stages with
preserved differing decisions.

### Tests for User Story 2

- [ ] T044 [P] [US2] Write failing state-machine and sheet-aggregation tests covering cost-center approve/reject/return, senior approve/reject/return, earliest-outstanding sheet stage, mixed transaction progress, and unchanged prior decisions in `tests/Unit/Domain/Workflow/ManagementWorkflowTest.php`
- [ ] T045 [P] [US2] Write failing authorization tests for assigned cost-center and senior scope, direct out-of-scope denial, multi-role own-transaction decisions (explicit allow when user holds required review role + scope + stage permission per FR-002/FR-008 - explicit test: purchaser who is also cost-center manager can review own transaction when scope/stage match), and administrator-only denial in `tests/Unit/Security/ManagementReviewAuthorizationTest.php`
- [ ] T046 [P] [US2] Write failing contract/integration tests for management queues and decision endpoint, including mandatory explanations and atomic decision/state/audit persistence in `tests/Contract/Controller/ManagementReviewControllerTest.php` and `tests/Integration/Workflow/ManagementDecisionTest.php`
- [ ] T047 [P] [US2] Write failing Vue tests for scoped queues, evidence/history display, decision form validation, and capability-driven actions in `tests/Frontend/Component/ManagementReview.spec.ts`

### Implementation for User Story 2

- [ ] T048 [P] [US2] Implement the centralized workflow state machine and transition guards from `contracts/workflow.md` in `lib/Domain/Workflow/WorkflowStateMachine.php` (cost-center decision advisory: visible to senior with full details; senior decides independently without forced acknowledgment per FR-034)
- [ ] T049 [US2] Implement immutable WorkflowDecision creation with `one effective decision per revision and stage` and explanation required for reject/return in `lib/Domain/Workflow/WorkflowDecision.php`
- [ ] T050 [US2] Implement manager/senior queue queries, decision orchestration, actor snapshots, sheet-stage recalculation, and audit writes in `lib/Application/Workflow/ManagementReviewService.php` (cost-center decisions visible to senior with full details; senior may approve/reject/return independently without forced acknowledgment)
- [ ] T051 [US2] Add management queue and decision OCS routes/controllers in `appinfo/routes.php` and `lib/Controller/ReviewController.php`
- [ ] T052 [P] [US2] Implement typed review API services and review Pinia state in `src/services/reviewsApi.ts` and `src/stores/reviews.ts`
- [ ] T053 [US2] Implement cost-center and senior queue views, transaction review detail, decision form, and prior-decision history components in `src/views/reviews/` and `src/components/review/` (all elements visible without horizontal scroll at 390px viewport; status color/icon WCAG AA contrast; text ≥12pt Persian font per FR-071)
- [ ] T054 [US2] Add management scope, self-review, disagreement, rejection, and return Playwright journeys in `tests/E2E/management-review.spec.ts`

**Checkpoint**: Both management stages work independently against submitted fixtures; all prior
opinions and audit records remain immutable.

---

## Phase 5: User Story 3 — Complete Financial Review and Settlement (Priority: P1)

**Goal**: Every active finance officer uses the shared queue, applies the senior-decision rules,
and atomically settles approved expenses exactly once against the matching currency account.

**Independent Test**: Route senior-approved and senior-rejected transactions to finance, exercise
all permitted decisions, retry and race approvals, force a mid-operation failure, and verify exact
movements, balances, final states, and rollback.

### Tests for User Story 3

- [ ] T055 [P] [US3] Write failing finance-rule tests proving senior-approved allows approve/reject/return, senior-rejected forbids approve (explicit test: finance attempt to approve senior-rejected transaction returns forbidden per FR-039), pending/rejected has no balance effect, and negative balances are permitted (explicit test: approved consumption exceeding account balance results in negative balance per FR-027) in `tests/Unit/Domain/Workflow/FinanceDecisionPolicyTest.php`
- [ ] T056 [P] [US3] Write failing integration tests for fixed row-lock order, exactly-one consumption, command replay, conflicting command UUID, simultaneous approvals, and rollback of decision/movement/balance/state/audit failures in `tests/Integration/Workflow/FinanceSettlementTest.php`
- [ ] T057 [P] [US3] Write failing contract tests for shared finance queue and finance decision responses in `tests/Contract/Controller/FinanceReviewControllerTest.php`
- [ ] T058 [P] [US3] Write failing Vue tests for the shared queue, senior-decision restrictions, settlement feedback, and manual retry messaging in `tests/Frontend/Component/FinanceReview.spec.ts`

### Implementation for User Story 3

- [ ] T059 [P] [US3] Implement immutable AccountMovement domain behavior with `positive magnitude; type determines sign`, exact `balance_after_minor`, unique command ID, and unique consumption source in `lib/Domain/Account/AccountMovement.php`
- [ ] T060 [US3] Implement account locking, movement insertion, balance projection, and reconciliation repositories in `lib/Infrastructure/Persistence/Account/DbAccountRepository.php` and `lib/Infrastructure/Persistence/Account/DbAccountMovementRepository.php`
- [ ] T061 [US3] Implement finance settlement orchestration that atomically writes decision, unique movement, balance, transaction final state, sheet final state, command result, and audit in `lib/Application/Workflow/FinanceSettlementService.php`
- [ ] T062 [US3] Implement shared finance queue queries and decision restrictions without cost-center scoping in `lib/Application/Workflow/FinanceQueue.php`
- [ ] T063 [US3] Add finance queue, finance decision, account summary, and movement OCS routes/controllers in `appinfo/routes.php`, `lib/Controller/ReviewController.php`, and `lib/Controller/AccountController.php`
- [ ] T064 [P] [US3] Implement finance/account typed API services and stores in `src/services/reviewsApi.ts`, `src/services/accountsApi.ts`, `src/stores/reviews.ts`, and `src/stores/accounts.ts`
- [ ] T065 [US3] Implement finance review, purchaser balance, and account movement views with explicit settled-versus-management labels in `src/views/reviews/FinanceReviewView.vue`, `src/views/accounts/AccountView.vue`, and `src/components/review/` (all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071)
- [ ] T066 [US3] Add shared-queue, restriction, negative-balance (explicit test: approved consumption exceeding account balance results in negative balance per FR-027), idempotency, concurrency, and forced-rollback Playwright/API journeys in `tests/E2E/finance-settlement.spec.ts` (verify p95 <3s, error rate <0.1%, zero data loss per FR-073)

**Checkpoint**: Financial settlement is atomic, idempotent, concurrency-safe, and independently
verified before correction or reporting expands it.

---

## Phase 6: User Story 4 — Correct a Returned Transaction (Priority: P1)

**Goal**: Purchasers correct returned transactions by activating a new immutable revision that
restarts the full review path while retaining all prior history.

**Independent Test**: Return from each review stage, create and edit a correction draft, resubmit,
and verify one new active revision at cost-center review with all previous revisions and decisions
unchanged.

### Tests for User Story 4

- [ ] T067 [P] [US4] Write failing revision tests for `revision_number starts at 1; unique per transaction`, submitted revision immutability, correction ownership, supersedes linkage, and active-revision switching in `tests/Unit/Domain/Expense/TransactionRevisionTest.php`
- [ ] T068 [P] [US4] Write failing integration tests for return-to-correction, new-revision activation, manager snapshot refresh, full-path restart, retained completed sibling transactions, and atomic audit insertion in `tests/Integration/Workflow/TransactionCorrectionTest.php`
- [ ] T069 [P] [US4] Write failing contract and Vue tests for correction creation/update/resubmit, returned queue, read-only prior revisions, and field/evidence validation in `tests/Contract/Controller/CorrectionControllerTest.php` and `tests/Frontend/Component/TransactionCorrection.spec.ts`

### Implementation for User Story 4

- [ ] T070 [US4] Implement correction-draft creation, owner-only editing, immutable prior revision cloning, and supersedes linkage in `lib/Application/Expense/CreateCorrectionDraft.php`
- [ ] T071 [US4] Implement atomic correction validation, active-revision activation, current-manager snapshots, cost-center rerouting, state projection, command result, and audit in `lib/Application/Workflow/ResubmitCorrection.php`
- [ ] T072 [US4] Update evidence services so correction uploads retain originating revision links and post-submission removal deactivates rather than deletes in `lib/Application/Evidence/EvidenceService.php`
- [ ] T073 [US4] Add correction, resubmit, revision-history, and purchaser-return OCS routes/controllers in `appinfo/routes.php`, `lib/Controller/TransactionController.php`, and `lib/Controller/ReviewController.php` (correction evidence upload enforces 100 MB max per FR-074; no advanced evidence restrictions in V1 per FR-030)
- [ ] T074 [P] [US4] Implement correction and revision-history API/store behavior in `src/services/transactionsApi.ts`, `src/services/reviewsApi.ts`, and `src/stores/sheets.ts`
- [ ] T075 [US4] Implement returned-work view, correction form, revision selector, and immutable decision/history presentation in `src/views/transactions/TransactionDetailView.vue` and `src/components/transactions/` (all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071)
- [ ] T076 [US4] Add manager/senior/finance return and corrected full-path restart Playwright journeys in `tests/E2E/transaction-correction.spec.ts` (explicit test: attempt to correct finance-approved transaction returns forbidden per FR-046)

**Checkpoint**: Correction never mutates a submitted revision and never repeats settled sibling
movements.

---

## Phase 7: User Story 5 — Administer Business Configuration (Priority: P2)

**Goal**: Administrators manage reference data and assignments, while finance officers fund
currency-specific accounts, with complete configuration audit.

**Independent Test**: Configure currencies, cost centers/managers, expense types, finance officers,
accounts, and a charge; verify selection/routing changes only for future records and historical
snapshots remain unchanged.

### Tests for User Story 5

- [ ] T077 [P] [US5] Write failing domain tests for `Currency.code unique uppercase`, `minor_unit valid range 0–4`, cost-center validity, exactly one effective manager and senior manager, expense-type evidence rule, and unique user/currency account in `tests/Unit/Domain/Configuration/ConfigurationRulesTest.php`
- [ ] T078 [P] [US5] Write failing authorization/integration tests proving administrators manage configuration but cannot charge or decide finance without finance role, finance assignments share one queue, default rial provisioning is idempotent, and every sensitive change creates configuration audit in `tests/Integration/Configuration/ConfigurationAdministrationTest.php`
- [ ] T079 [P] [US5] Write failing OCS contract tests for currency, cost-center, expense-type, finance-assignment, account, and charge endpoints in `tests/Contract/Controller/ConfigurationControllerTest.php`
- [ ] T080 [P] [US5] Write failing Vue tests for admin forms, active/inactive choices, effective dates, manager selection, finance assignment, account creation, and funding validation in `tests/Frontend/Component/ConfigurationAdministration.spec.ts` (verify all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071)

### Implementation for User Story 5

- [ ] T081 [P] [US5] Implement currency administration with immutable historical references and rial default identification in `lib/Application/Configuration/CurrencyService.php`
- [ ] T082 [P] [US5] Implement cost-center and effective-dated manager/senior assignment administration with future-routing behavior in `lib/Application/Configuration/CostCenterService.php`
- [ ] T083 [P] [US5] Implement expense-type administration including `evidence_required` and inactive historical retention in `lib/Application/Configuration/ExpenseTypeService.php`
- [ ] T084 [P] [US5] Implement shared-queue finance-role assignment administration and host-user validation in `lib/Application/Configuration/FinanceAssignmentService.php`
- [ ] T085 [US5] Implement idempotent default rial account provisioning, additional currency accounts, activation state, and finance account charging in `lib/Application/Account/AccountAdministrationService.php`
- [ ] T086 [US5] Ensure every configuration/account mutation writes before/after configuration audit in the same database transaction in `lib/Application/Audit/ConfigurationAuditService.php`
- [ ] T087 [US5] Add configuration, account, movement, and charge OCS routes/controllers with password confirmation for sensitive operations in `appinfo/routes.php`, `lib/Controller/ConfigurationController.php`, and `lib/Controller/AccountController.php`
- [ ] T088 [P] [US5] Implement configuration/account API services, stores, and administrator/funding views in `src/services/configurationApi.ts`, `src/services/accountsApi.ts`, `src/stores/configuration.ts`, and `src/views/admin/` (all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071)
- [ ] T089 [US5] Add configuration, role-boundary, default-rial, funding, deactivation, and historical-preservation Playwright journeys in `tests/E2E/configuration-administration.spec.ts`

**Checkpoint**: Operational setup is fully manageable through authorized interfaces and every
sensitive change is traceable.

---

## Phase 8: User Story 6 — Trace Decisions and Evidence (Priority: P2)

**Goal**: Authorized users inspect immutable revisions, decisions, audit, and evidence lifecycle;
administrators optionally manage revocable public evidence links.

**Independent Test**: Execute a multi-revision workflow, deactivate evidence, disable an actor,
attempt audit mutation, and verify scoped timeline/table history and preserved actor snapshots.

### Tests for User Story 6

- [ ] T090 [P] [US6] Write failing audit immutability and disabled-user tests proving no update/delete operation exists and UID/display name/role-at-event remain unchanged (service-layer enforcement: admin mutation attempt returns forbidden per FR-053/Constitution IV; explicit test: disabled user historical actor identity, roles, decisions, revisions, and audit events remain unchanged and accessible per FR-054/SC-012) in `tests/Integration/Audit/AuditImmutabilityTest.php`
- [ ] T091 [P] [US6] Write failing evidence lifecycle and security tests for authenticated preview/download, post-submit deactivation, inactive-history visibility, AppData path secrecy, admin-only public-link creation/deactivation, expiry, and audit in `tests/Integration/Storage/EvidenceLifecycleTest.php` (100 MB max file size enforced per FR-074; no advanced evidence restrictions in V1 per FR-030)
- [ ] T092 [P] [US6] Write failing contract and Vue tests for revisions, transaction audit, evidence stream, evidence deactivation, and public-link administration in `tests/Contract/Controller/HistoryEvidenceControllerTest.php` and `tests/Frontend/Component/AuditEvidence.spec.ts` (verify all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071; evidence 100 MB max per FR-074; no advanced restrictions in V1 per FR-030)

### Implementation for User Story 6

- [ ] T093 [P] [US6] Implement role-scoped revision, decision, transaction-audit, and configuration-audit queries with actor snapshots and canonical before/after payloads in `lib/Application/Audit/AuditQueryService.php` (config audit visibility: admins see all; finance see charged accounts; cost-center/senior managers see assigned cost centers per FR-055/FR-056)
- [ ] T094 [US6] Implement authenticated evidence preview/download streaming, inactive-state history, and safe content disposition in `lib/Infrastructure/Http/AppDataStreamResponse.php` and `lib/Application/Evidence/EvidenceQueryService.php` (enforce 100 MB max file size per FR-074; no advanced evidence restrictions in V1 per FR-030)
- [ ] T095 [US6] Implement hashed-token, optional-expiry, disabled-by-default public evidence links with administrator create/deactivate audit in `lib/Application/Evidence/PublicEvidenceLinkService.php` (max 100 MB per file per FR-074; no advanced evidence restrictions in V1 per FR-030)
- [ ] T096 [US6] Add revision, audit, evidence-content, evidence-deactivation, public-link, and public-token routes/controllers in `appinfo/routes.php`, `lib/Controller/AuditController.php`, and `lib/Controller/EvidenceController.php` (evidence endpoints enforce 100 MB max per FR-074; no advanced evidence restrictions in V1 per FR-030)
- [ ] T097 [P] [US6] Implement audit/evidence API services and stores in `src/services/evidenceApi.ts`, `src/services/auditApi.ts`, and `src/stores/audit.ts`
- [ ] T098 [US6] Implement audit timeline/table, revision history, active/inactive evidence list, preview/download, and link-management components in `src/components/audit/`, `src/components/evidence/`, and `src/views/audit/AuditView.vue` (all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071)
- [ ] T099 [US6] Add disabled-actor, immutable-history, evidence-deactivation, authorization, and optional public-link Playwright journeys in `tests/E2E/audit-evidence.spec.ts`
- [ ] T100 [US6] Document audit retention as archival/availability policy that never deletes or rewrites business history in `docs/audit-retention.md`

**Checkpoint**: Complete history is viewable only within scope, evidence is never physically
removed after submission, and no supported interface mutates audit.

---

## Phase 9: User Story 7 — Monitor, Search, Report, and Export (Priority: P2)

**Goal**: Every role receives scoped dashboards, filters, reports, totals, and secure CSV/XLSX/PDF
or evidence-ZIP exports.

**Independent Test**: Populate mixed roles, centers, currencies, stages, outcomes, revisions, and
evidence; verify every report/export contains all permitted and zero out-of-scope records.

### Tests for User Story 7

- [ ] T101 [P] [US7] Write failing total-calculation tests for current, submitted, latest-effective approved/rejected/pending, and independent cost-center/senior/finance approval totals including return-to-pending behavior in `tests/Unit/Domain/Expense/SheetTotalsTest.php`
- [ ] T102 [P] [US7] Write failing role-scoped report/filter/pagination tests covering sheet, transaction, cost-center, account, process, evidence, and audit reports in `tests/Integration/Reporting/ReportScopeTest.php` (verify transaction audit and configuration audit remain separately identifiable per FR-056 - explicit test: transaction audit queries never return configuration audit events and vice versa; config audit visibility per role scope)
- [ ] T103 [P] [US7] Write failing exporter tests for UTF-8 CSV, formula escaping, six-sheet XLSX, Persian RTL PDF, ZIP manifest-to-file integrity, transient cleanup, and out-of-scope omission in `tests/Unit/Export/ExportersTest.php` (grouped evidence ZIP respects 100 MB/file limit per FR-074)
- [ ] T104 [P] [US7] Write failing contract and Vue tests for dashboard, report, filter, paging, and export interfaces in `tests/Contract/Controller/ReportingControllerTest.php` and `tests/Frontend/Component/DashboardReports.spec.ts` (verify all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071)

### Implementation for User Story 7

- [ ] T105 [US7] Implement indexed sheet summary and totals projections using active revisions and immutable decisions without storing independent mutable totals in `lib/Infrastructure/Persistence/Expense/SheetSummaryQuery.php`
- [ ] T106 [P] [US7] Implement role-scoped dashboard and report query services with server-side filters and maximum page size 100 in `lib/Application/Reporting/DashboardQuery.php` and `lib/Application/Reporting/ReportQuery.php` (config audit visibility per role scope: admins all, finance charged accounts, managers assigned cost centers)
- [ ] T107 [P] [US7] Implement native streaming CSV with spreadsheet-formula escaping in `lib/Infrastructure/Export/CsvExporter.php`
- [ ] T108 [P] [US7] Implement XLSX export with the six-sheet complete-sheet layout from `contracts/exports.md` in `lib/Infrastructure/Export/XlsxExporter.php`
- [ ] T109 [P] [US7] Implement standard Persian RTL PDF export with an approved embedded font and no custom letterhead/signature in `lib/Infrastructure/Export/PdfExporter.php`
- [ ] T110 [P] [US7] Implement grouped evidence ZIP export with safe archive paths and a UTF-8 relationship manifest in `lib/Infrastructure/Export/EvidenceArchiveExporter.php` (enforce 100 MB max file size per FR-074; no advanced restrictions per FR-030)
- [ ] T111 [US7] Implement export orchestration, transient AppData cleanup, and authorization-before-generation in `lib/Application/Reporting/ExportService.php`
- [ ] T112 [US7] Add dashboard, report, and export OCS routes/controllers in `appinfo/routes.php`, `lib/Controller/DashboardController.php`, `lib/Controller/ReportController.php`, and `lib/Controller/ExportController.php`
- [ ] T113 [P] [US7] Implement dashboard/report API services, stores, filters, tables/cards, and export controls in `src/services/dashboardApi.ts`, `src/services/reportsApi.ts`, `src/stores/dashboard.ts`, `src/stores/reports.ts`, `src/views/DashboardView.vue`, and `src/views/reports/ReportsView.vue` (all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071)
- [ ] T114 [US7] Add dashboard, filtering, totals, report-scope, CSV/XLSX/PDF, and grouped-evidence export Playwright journeys in `tests/E2E/reporting-export.spec.ts` (verify p95 <3s, error rate <0.1%, zero data loss per FR-073)

**Checkpoint**: Every interactive and exported result applies authorization before aggregation and
generation, and latest-effective totals are visibly distinct from final settlement.

---

## Phase 10: User Story 8 — Use the Workflow Across Supported Devices (Priority: P3)

**Goal**: Deliver the complete role workflow through an accessible Persian RTL interface on
desktop and mobile-sized Chrome, Edge, and Firefox.

**Independent Test**: Run every primary role journey at 1440×900 and 390×844, verify RTL layout,
keyboard behavior, theme compatibility, mixed-direction content, capability presentation, and
manual retry after communication failure.

### Tests for User Story 8

- [ ] T115 [P] [US8] Write failing router/navigation/session tests for all hash routes, multi-role navigation union, focus movement, and capability-driven presentation in `tests/Frontend/Component/ApplicationShell.spec.ts`
- [ ] T116 [P] [US8] Write failing RTL/responsive/accessibility tests for logical alignment, no page-level horizontal overflow at 390px viewport, labeled errors, keyboard dialogs, live mutation feedback, mixed-direction isolation, status color/icon WCAG AA contrast, text ≥12pt Persian font in `tests/Frontend/Component/RtlAccessibility.spec.ts` (test against Chrome 120+, Edge 120+, Firefox 115+ ESR per FR-072)

### Implementation for User Story 8

- [ ] T117 [US8] Implement hash-based Vue Router routes for dashboard, sheets, transactions, three review queues, accounts, reports, audit, and administration in `src/router/index.ts`
- [ ] T118 [US8] Implement the Nextcloud application shell with role-union navigation, content region, responsive detail behavior, route-heading focus, and session bootstrap in `src/AppRoot.vue`, `src/main.ts`, and `src/components/layout/`
- [ ] T119 [P] [US8] Move every user-facing string through Nextcloud localization and add the Persian V1 catalog while keeping canonical API/domain values untranslated in `src/l10n.ts` and `l10n/fa.js`
- [ ] T120 [P] [US8] Implement logical-property RTL styles, Nextcloud theme variables, desktop tables, mobile cards, status text/icons (WCAG AA contrast), and bidirectional isolation in `src/styles/app.scss`, `src/styles/rtl.scss`, and `src/styles/responsive.scss` (all elements visible without horizontal scroll at 390px viewport; text ≥12pt Persian font per FR-071)
- [ ] T121 [US8] Implement normalized loading/error/empty states and explicit user-triggered retry with no automatic mutation retry in `src/components/shared/` and `src/composables/useAsyncAction.ts`
- [ ] T122 [US8] Add Playwright projects and journeys for Chrome 120+, Edge 120+, and Firefox 115+ (ESR) at desktop and mobile-sized viewports, including dark/high-contrast themes and keyboard navigation in `tests/E2E/browser-matrix.spec.ts` (verify all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071; p95 <3s, error rate <0.1%, zero data loss per FR-073)
- [ ] T123 [US8] Execute and record the manual Persian RTL, current installed Firefox (115+ ESR), accessibility, and representative-user first-attempt acceptance matrix in `specs/001-pettycash-v1-core/checklists/ui-acceptance.md` (verify all elements visible without horizontal scroll at 390px viewport; status color/icon WCAG AA contrast; text ≥12pt Persian font; p95 <3s, error rate <0.1%, zero data loss; test Chrome 120+, Edge 120+ per FR-072)

**Checkpoint**: All primary workflows pass the required browser/viewport matrix and at least 90%
of representative users complete their primary role journey on the first attempt.

---

## Phase 11: Polish and Cross-Cutting Release Gates

**Purpose**: Validate compatibility, security, performance, recovery, documentation, packaging, and
full specification traceability after all selected story phases are complete.

- [ ] T124 [P] Validate `contracts/ocs-api.yaml`, generate or update isolated TypeScript contract types, and fail on unreviewed API drift in `scripts/validate-openapi.sh` and `src/generated/api.ts`
- [ ] T125 [P] Add static security and dependency checks for Composer and npm packages, unsafe spreadsheet values, accidental secret paths, and forbidden direct database/filesystem use in `scripts/security-check.sh`
- [ ] T126 Run the legacy-present clean-install and upgrade migration scenarios and record table/migration evidence without modifying `oc_pcash_*` data in `tests/Integration/Migration/V1SchemaMigrationTest.php` and `specs/001-pettycash-v1-core/checklists/migration-validation.md`
- [ ] T127 [P] Add deterministic fixture generation for up to 100 active users and 10,000 annual transactions in `tests/Fixtures/TargetVolumeFixture.php`
- [ ] T128 Measure 20 warmed runs for sheet list/detail, transaction detail, audit, and evidence views; calculate p95 and optimize only scoped queries/indexes needed to meet the 3-second target (p95 <3s, error rate <0.1%, zero data loss per FR-073) in `tests/Performance/RoutineViewsPerformanceTest.php`
- [ ] T129 Run concurrent decision, settlement, charge, idempotency, and account-reconciliation stress tests against MariaDB in `tests/Integration/Workflow/ConcurrencyStressTest.php`
- [ ] T130 Implement a read-only evidence reconciliation diagnostic that reports orphan AppData files and missing binaries without automatic deletion in `lib/Application/Evidence/EvidenceReconciliationService.php` and `lib/Command/EvidenceReconcileCommand.php` (verify 100 MB max file size compliance per FR-074)
- [ ] T131 [P] Document architecture boundaries, migration safety, AppData backup dependency, audit/revision immutability, recovery, and release procedures in `docs/architecture.md`, `docs/operations.md`, and `docs/release.md` (include measurable RTL criteria: 390px viewport, WCAG AA, ≥12pt font; performance targets: p95 <3s, error rate <0.1%, zero data loss; authorization decision matrix; evidence 100 MB limit per FR-074; advanced evidence restrictions deferred to V2 per FR-030; browser targets Chrome 120+/Edge 120+/Firefox 115+ ESR per FR-072)
- [ ] T132 Package the application without development dependencies or source maps and verify app ID, namespace, licence, routes, translations, migrations, and generated assets in `scripts/package.sh`
- [ ] T133 Execute every automated command and business scenario in `specs/001-pettycash-v1-core/quickstart.md` and record results in `specs/001-pettycash-v1-core/checklists/quickstart-validation.md`
- [ ] T134 Complete requirement-to-test traceability for FR-001 through FR-076 and SC-001 through SC-012 in `specs/001-pettycash-v1-core/checklists/traceability.md` (include clarified FR-002, FR-030, FR-034, FR-055, FR-056, FR-071, FR-072, FR-073, FR-074)
- [ ] T135 Perform the final constitution compliance, compatibility, documentation, and release-readiness review and record all approved exceptions or confirm none in `specs/001-pettycash-v1-core/checklists/release-readiness.md` (verify measurable RTL criteria, performance targets, authorization matrix, config audit scope, advisory decision behavior, evidence 100 MB limit, advanced evidence restrictions V2 deferral, browser targets)
- [ ] T137 [P] Write failing test verifying no scheduled job or background process advances stale transactions (no auto-escalation per FR-045) in `tests/Integration/Workflow/NoAutoEscalationTest.php`
- [ ] T138 [P] Write failing integration test verifying audit records younger than 7 years are never archived or deleted (FR-057 7-year retention floor) in `tests/Integration/Audit/RetentionPolicyTest.php`
- [ ] T139 [P] Write failing integration test verifying configuration audit visibility per role scope (admins see all, finance see charged accounts, managers see assigned cost centers per FR-055/FR-056) and transaction/config audit separation (explicit test: transaction audit queries never return configuration audit events and vice versa per FR-056) in `tests/Integration/Audit/ConfigAuditScopeTest.php`

**Checkpoint**: All mandatory checks pass, documentation matches behavior, no unresolved
constitution violation remains, and the application is ready for review rather than further
feature implementation.

---

## Dependencies and Execution Order

### Phase Dependencies

- **Phase 1 — Setup**: Starts immediately.
- **Phase 2 — Foundational**: Depends on Phase 1 and blocks every user story.
- **Phase 3 — US1 Purchaser capture**: Depends on Phase 2; this is the MVP slice using seeded
  reference configuration.
- **Phase 4 — US2 Management review**: Depends on US1 submission records.
- **Phase 5 — US3 Finance settlement**: Depends on US2 senior decisions.
- **Phase 6 — US4 Correction**: Depends on US1 and US2; finance-return coverage also uses US3.
- **Phase 7 — US5 Administration**: Depends only on Phase 2 and may run in parallel with US1–US4;
  fixtures provide configuration until its UI is complete.
- **Phase 8 — US6 Traceability**: Depends on the event-producing slices US1–US4; public-link work
  also depends on foundational evidence storage.
- **Phase 9 — US7 Reporting/export**: Depends on US1–US6 data and authorization behavior.
- **Phase 10 — US8 Cross-device UI**: Shell work may begin after Phase 2, but full acceptance
  depends on all selected story views.
- **Phase 11 — Polish**: Depends on all stories intended for the release.

### User Story Dependency Graph

```text
Setup → Foundation → US1 → US2 → US3
                       └──→ US4
                  ├────────→ US5
                  └────────→ US8 shell
US1 + US2 + US3 + US4 ─────→ US6
US1 + US2 + US3 + US4 + US5 + US6 ─→ US7
All selected stories ─────────────────→ US8 acceptance → Polish
```

### Within Each User Story

1. Write the listed tests and confirm they fail for the missing behavior.
2. Implement domain models and validation.
3. Implement persistence/application services.
4. Expose controllers/contracts.
5. Implement frontend service/store/components.
6. Run story-specific integration and end-to-end acceptance.
7. Stop at the checkpoint before advancing dependent workflow stages.

## Parallel Opportunities

- T003–T006 can run in parallel after T001 establishes the verified runtime assumptions.
- T011–T014 are independent failing-test tasks; T022–T024 are separate persistence modules.
- US5 configuration administration may run beside US1–US4 after the foundation is stable.
- US8 shell, localization, and base RTL styling may begin after foundation while feature views are
  delivered incrementally.
- Export adapters T107–T110 are independent after report contracts and shared ports exist.
- In every story, tasks marked `[P]` target separate test, backend, or frontend files.

## Parallel Execution Examples by User Story

- **US1**: Run T031–T034 together; after shared models exist, T036 and T041 can proceed in parallel.
- **US2**: Run T044–T047 together; T048 and T052 can proceed in parallel before integration.
- **US3**: Run T055–T058 together; T059 and T064 can proceed in parallel.
- **US4**: Run T067–T069 together; T072 and T074 can proceed after correction contracts settle.
- **US5**: Run T077–T080 together, then T081–T084 in parallel, followed by transactional audit and
  controllers.
- **US6**: Run T090–T092 together; T093, T094, and T097 can proceed in parallel.
- **US7**: Run T101–T104 together; T106–T110 can proceed in parallel before export orchestration.
- **US8**: Run T115–T116 together; T119–T120 can proceed in parallel while the shell is integrated.

## Implementation Strategy

### MVP First

1. Complete Phase 1 and Phase 2.
2. Complete Phase 3, User Story 1.
3. Stop and validate capture/submission independently with seeded configuration.
4. Demonstrate the first formal audit event, role-scoped purchaser views, and submission validation.

### Incremental Workflow Delivery

1. Add US2 management review and validate both management stages.
2. Add US3 finance settlement and validate atomic, idempotent balance effects.
3. Add US4 correction and validate immutable revision restart.
4. Complete US5 administration so fixtures are no longer needed for operational setup.
5. Add US6 traceability, US7 visibility/export, and US8 full compatibility acceptance.
6. Execute Phase 11 release gates.

### Safe Stopping Points

- After Foundation: architecture and migrations are reviewable without business rollout.
- After US1: purchaser MVP is demonstrable with fixtures.
- After US3: the straight-through workflow is complete.
- After US4: the complete P1 workflow, including correction, is complete.
- After US7: all business capabilities are present; US8 and Polish establish release quality.

## Notes

- Tasks modify only PettyCash project files and the existing mounted PettyCash app.
- Shared Docker volumes, other applications, and legacy tables are never deleted or repurposed.
- `[P]` means safe file-level parallelism, not permission to ignore phase dependencies.
- Generated assets and dependency lockfiles are committed according to the chosen release convention.
- Public evidence links remain disabled until US6 security tests pass.
- Any discovery that changes business behavior, architecture boundaries, schema meaning, or API
  contracts returns to `spec.md` and `plan.md` before implementation continues.
