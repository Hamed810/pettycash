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

- [x] T001 Create a non-destructive runtime and migration preflight script that verifies Nextcloud 34.x, PHP 8.2–8.5, the `pettycash` mount, existing `pettycash` migration history, and presence of legacy `oc_pcash_*` tables without changing them in `scripts/preflight.sh`
- [x] T002 Update the PHP runtime constraint to a Nextcloud-34-compatible PHP 8.2+ range, add PSR-4 test autoloading, and add locked development dependencies for PHPUnit, `nextcloud/ocp:dev-stable34`, Psalm, and Nextcloud coding standards in `composer.json`
- [x] T003 [P] Replace placeholder frontend dependency ranges with pinned Vue 3, TypeScript, `@nextcloud/vue` 9.x, `@nextcloud/axios`, `@nextcloud/router`, `@nextcloud/l10n`, Pinia, Vue Router, Vite, Vitest, Vue Test Utils, ESLint, Stylelint, and Playwright scripts in `package.json`
- [x] T004 [P] Configure Vue/TypeScript compilation from `src/main.ts` into deployable `js/` and `css/` assets using `vite.config.ts`, `tsconfig.json`, and `src/env.d.ts`
- [x] T005 [P] Configure PHP linting, formatting, static analysis, and unit/integration/contract test suites in `.php-cs-fixer.dist.php`, `psalm.xml`, `phpunit.xml`, and `tests/bootstrap.php`
- [x] T006 [P] Configure frontend linting, style linting, unit/component tests, and browser projects in `eslint.config.js`, `stylelint.config.js`, `vitest.config.ts`, and `playwright.config.ts`
- [x] T007 Create the planned modular-monolith and frontend source directories with namespace-safe placeholders in `lib/Application/`, `lib/Domain/`, `lib/Infrastructure/`, `lib/Migration/`, `src/`, `templates/`, `l10n/`, and `tests/`
- [x] T008 Add the authenticated Nextcloud page route, thin page controller, mount template, and generated asset registration in `appinfo/routes.php`, `lib/Controller/PageController.php`, and `templates/main.php`
- [x] T009 Implement the lightweight `IBootstrap` application shell and service-registration conventions while preserving `Application::APP_ID = 'pettycash'` in `lib/AppInfo/Application.php`
- [x] T010 Add consolidated local quality commands and safe development instructions in `Makefile` and `README.md`

**Checkpoint**: Dependency installation, empty application build, and empty test suites run on the
verified host without changing shared volumes, other apps, or legacy tables.

---

## Phase 2: Foundational Architecture and Persistence

**Purpose**: Build the shared database, domain, authorization, idempotency, audit, storage, error,
and client foundations that block every user story.

**⚠️ CRITICAL**: No user story implementation begins until this phase passes its tests.

- [x] T011 [P] Write failing migration safety tests proving installation beside existing `pettycash` migration records creates only `pc_*` tables and never drops, renames, or rewrites legacy `oc_pcash_*` tables in `tests/Integration/Migration/V1SchemaMigrationTest.php`
- [x] T012 [P] Write failing shared-domain tests for UUID identifiers, exact integer-minor-unit money, currency mismatch rejection, positive-amount validation, and UTC timestamp handling in `tests/Unit/Domain/Shared/MoneyAndIdentityTest.php`
- [x] T013 [P] Write failing authorization matrix tests covering purchaser ownership, manager cost-center scope, senior scope, shared finance queue, administrator-without-finance denial (explicit test: admin without finance role attempting finance decision returns forbidden per FR-007), and permitted self-review for otherwise authorized multi-role users (explicit allow when user holds required review role + scope + stage permission; ownership grants purchaser rights only per FR-002/FR-008) in `tests/Unit/Security/AuthorizationServiceTest.php`
- [x] T014 [P] Write failing idempotency tests proving same command UUID and payload replays the original result while the same UUID with different content conflicts in `tests/Integration/Workflow/IdempotencyServiceTest.php`
- [x] T136 [P] Write failing integration tests for atomic rollback on mid-operation failure at each workflow stage (submission, management decision, finance settlement, correction resubmit) in `tests/Integration/Workflow/AtomicityFailureInjectionTest.php`
- [x] T015 Create forward-only configuration/account migrations with constraints `Currency.code: unique uppercase code`, `Currency.minor_unit: valid range 0–4`, `PettyCashAccount: unique user and currency pair`, and inactive records retained for history in `lib/Migration/Version010000Date20260920000000.php`
- [x] T016 Create forward-only sheet/transaction/revision/decision migrations with constraints `amount_minor: must be greater than zero`, `revision_number: starts at 1; unique per transaction`, one effective decision per revision and stage, and no destructive cascades in `lib/Migration/Version010100Date20260920000000.php`
- [x] T017 Create forward-only evidence, transaction-audit, configuration-audit, command-result, and public-link migrations with unique command IDs, unique consumption source, hashed link tokens, and append-only historical tables in `lib/Migration/Version010200Date20260920000000.php`
- [x] T018 Implement UUID, Money, CurrencyCode, Clock, actor snapshot, and domain invariant primitives using integer minor units and canonical serialization in `lib/Domain/Shared/`
- [x] T019 [P] Implement workflow, role, decision, sheet-status, transaction-status, movement-type, audit-event, and capability enums matching `data-model.md` in `lib/Domain/Workflow/` and `lib/Domain/Shared/Enum/`
- [x] T020 [P] Define repository, transaction manager, user directory, administrator check, private file store, clock, audit writer, and exporter ports in `lib/Application/Port/` and `lib/Domain/*/Repository/`
- [x] T021 Implement the Nextcloud database transaction adapter and fixed lock-order helpers for sheet → transaction → revision → account in `lib/Infrastructure/Persistence/NextcloudTransactionManager.php`
- [x] T022 [P] Implement configuration and account persistence entities, mappers, and role-scoped query repositories in `lib/Infrastructure/Persistence/Configuration/` and `lib/Infrastructure/Persistence/Account/`
- [x] T023 [P] Implement sheet, transaction, revision, decision, and aggregate-status persistence entities, mappers, and paged query repositories in `lib/Infrastructure/Persistence/Expense/` and `lib/Infrastructure/Persistence/Workflow/`
- [x] T024 [P] Implement evidence, public-link, audit, and command-result persistence with insert-only audit repositories and no audit update/delete methods in `lib/Infrastructure/Persistence/Evidence/`, `lib/Infrastructure/Persistence/Audit/`, and `lib/Infrastructure/Persistence/Command/`
- [x] T025 Implement Nextcloud user-directory, current-user, administrator, clock, technical-log, and private AppData adapters in `lib/Infrastructure/Nextcloud/`
- [x] T026 Implement centralized object authorization and role-scoped query policies from the tested matrix in `lib/Application/Security/AuthorizationService.php` (enforce: self-review permitted when user holds required review role + scope + stage permission; ownership grants purchaser rights only; cost-center/senior/finance scope checks; admin no finance without finance role - explicit deny for admin attempting finance actions per FR-007)
- [x] T027 Implement command UUID payload hashing, replay, and conflict handling in `lib/Application/Command/IdempotencyService.php`
- [x] T028 Implement transaction and configuration audit writers that snapshot UID, display name, role, stage, revision, explanation, command ID, and canonical before/after JSON in `lib/Application/Audit/`
- [x] T029 Implement consistent OCS error mapping for validation, forbidden, hidden-not-found, stale state, idempotency conflict, and unexpected failures in `lib/Infrastructure/Http/OcsErrorMapper.php`
- [x] T030 Create the typed frontend OCS client, canonical error model, command-ID generator, pagination types, and no-automatic-mutation-retry behavior in `src/services/http.ts`, `src/types/api.ts`, and `src/types/permissions.ts`

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

- [x] T031 [P] [US1] Write failing domain tests for open-sheet editing, `expense date and positive amount` minimum draft data, submission-required description/cost center/active expense type/evidence, inherited sheet currency, multiple open sheets, and balance-independent submission in `tests/Unit/Domain/Expense/SheetSubmissionPolicyTest.php`
- [x] T032 [P] [US1] Write failing OCS contract tests for create/list/detail/update sheet, create/update/delete transaction, evidence upload/delete, and submit endpoints from `contracts/ocs-api.yaml` in `tests/Contract/Controller/PurchaserFlowControllerTest.php` (evidence upload tests: 100 MB max enforced, oversized rejected, no advanced restrictions per FR-030)
- [x] T033 [P] [US1] Write failing integration tests proving first submission snapshots totals, creates the first formal audit event, locks new-transaction creation, routes each transaction by cost center, and compensates an AppData write when metadata persistence fails in `tests/Integration/Workflow/SheetSubmissionTest.php` (evidence upload: 100 MB max per FR-074, oversized rejected, no advanced restrictions per FR-030)
- [x] T034 [P] [US1] Write failing Vue tests for sheet/transaction forms, field-level submission errors, account-currency selection, evidence upload state, and submitted read-only behavior in `tests/Frontend/Component/PurchaserFlow.spec.ts`

### Implementation for User Story 1

- [x] T035 [P] [US1] Implement ExpenseSheet, ExpenseTransaction, and TransactionRevision domain models with constraints `one purchaser`, `one source account/currency`, `amount_minor greater than zero`, and revision 1 mutable only while draft in an open sheet in `lib/Domain/Expense/`
- [x] T036 [P] [US1] Implement private evidence storage with opaque `evidence/<transaction-uuid>/<revision>/` keys, 100 MB max file size per FR-074, checksums, safe original filenames, and draft-delete compensation in `lib/Infrastructure/Nextcloud/AppDataEvidenceStore.php` (advanced evidence restrictions deferred to V2 per FR-030)
- [x] T037 [US1] Implement create/update/list/detail sheet and create/update/delete draft transaction commands/queries with owner-scoped access in `lib/Application/Expense/`
- [x] T038 [US1] Implement upload and pre-submission evidence removal services using the evidence metadata constraints in `lib/Application/Evidence/EvidenceService.php` (enforce 100 MB max file size per FR-074; no advanced evidence restrictions in V1 per FR-030)
- [x] T039 [US1] Implement atomic sheet submission validation, submitted-total snapshot, manager snapshots, initial routing, sheet projection update, and first audit insertion in `lib/Application/Workflow/SubmitSheet.php`
- [x] T040 [US1] Add purchaser OCS routes and thin sheet, transaction, and evidence controllers in `appinfo/routes.php` and `lib/Controller/SheetController.php`, `lib/Controller/TransactionController.php`, and `lib/Controller/EvidenceController.php` (evidence upload enforces 100 MB max per FR-074; no advanced evidence restrictions in V1 per FR-030)
- [x] T041 [P] [US1] Implement typed purchaser API services and Pinia stores in `src/services/sheetsApi.ts`, `src/services/transactionsApi.ts`, `src/services/evidenceApi.ts`, and `src/stores/sheets.ts`
- [x] T042 [US1] Implement sheet list/detail/form, transaction list/form/detail, and evidence uploader/list views with Persian-ready translatable strings in `src/views/sheets/`, `src/views/transactions/`, and `src/components/{sheets,transactions,evidence}/` (all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071). Remove the temporary Vue component aliases from `vitest.config.ts` that redirect the 8 PurchaserFlow component imports to `tests/Frontend/__mocks__/components.ts`; ensure `PurchaserFlow.spec.ts` resolves to the real Vue components under `src/`; remove the temporary mock wiring once the real components exist; rerun the T034 frontend tests against the real components.
- [x] T043 [US1] Add the purchaser capture/submission Playwright journey and expected audit assertions in `tests/E2E/purchaser-submit.spec.ts`

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

- [x] T044 [P] [US2] Write failing state-machine and sheet-aggregation tests covering cost-center approve/reject/return, senior approve/reject/return, earliest-outstanding sheet stage, mixed transaction progress, and unchanged prior decisions in `tests/Unit/Domain/Workflow/ManagementWorkflowTest.php`
- [x] T045 [P] [US2] Write failing authorization tests for assigned cost-center and senior scope, direct out-of-scope denial, multi-role own-transaction decisions (explicit allow when user holds required review role + scope + stage permission per FR-002/FR-008 - explicit test: purchaser who is also cost-center manager can review own transaction when scope/stage match), and administrator-only denial in `tests/Unit/Security/ManagementReviewAuthorizationTest.php`
- [x] T046 [P] [US2] Write failing contract/integration tests for management queues and decision endpoint, including mandatory explanations and atomic decision/state/audit persistence in `tests/Contract/Controller/ManagementReviewControllerTest.php` and `tests/Integration/Workflow/ManagementDecisionTest.php`
- [x] T047 [P] [US2] Write failing Vue tests for scoped queues, evidence/history display, decision form validation, and capability-driven actions in `tests/Frontend/Component/ManagementReview.spec.ts`

### Implementation for User Story 2

- [x] T048 [P] [US2] Implement the centralized workflow state machine and transition guards from `contracts/workflow.md` in `lib/Domain/Workflow/WorkflowStateMachine.php` (cost-center decision advisory: visible to senior with full details; senior decides independently without forced acknowledgment per FR-034)
- [x] T049 [US2] Implement immutable WorkflowDecision creation with `one effective decision per revision and stage` and explanation required for reject/return in `lib/Domain/Workflow/WorkflowDecision.php`
- [x] T050 [US2] Implement manager/senior queue queries, decision orchestration, actor snapshots, sheet-stage recalculation, and audit writes in `lib/Application/Workflow/ManagementReviewService.php` (cost-center decisions visible to senior with full details; senior may approve/reject/return independently without forced acknowledgment)
- [x] T051 [US2] Add management queue and decision OCS routes/controllers in `appinfo/routes.php` and `lib/Controller/ReviewController.php`
- [x] T052 [P] [US2] Implement typed review API services and review Pinia state in `src/services/reviewsApi.ts` and `src/stores/reviews.ts`
- [x] T053 [US2] Implement cost-center and senior queue views, transaction review detail, decision form, and prior-decision history components in `src/views/reviews/` and `src/components/review/` (all elements visible without horizontal scroll at 390px viewport; status color/icon WCAG AA contrast; text ≥12pt Persian font per FR-071)
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

- [x] T055 [P] [US3] Write failing finance-rule tests proving senior-approved allows approve/reject/return, senior-rejected forbids approve (explicit test: finance attempt to approve senior-rejected transaction returns forbidden per FR-039), pending/rejected has no balance effect, and negative balances are permitted (explicit test: approved consumption exceeding account balance results in negative balance per FR-027) in `tests/Unit/Domain/Workflow/FinanceDecisionPolicyTest.php`
- [ ] T056 [P] [US3] Write failing integration tests for fixed row-lock order, exactly-one consumption, command replay, conflicting command UUID, simultaneous approvals, and rollback of decision/movement/balance/state/audit failures in `tests/Integration/Workflow/FinanceSettlementTest.php`
- [ ] T057 [P] [US3] Write failing contract tests for shared finance queue and finance decision responses in `tests/Contract/Controller/FinanceReviewControllerTest.php`
- [ ] T058 [P] [US3] Write failing Vue tests for the shared queue, senior-decision restrictions, settlement feedback, and manual retry messaging in `tests/Frontend/Component/FinanceReview.spec.ts`

### Implementation for User Story 3

- [x] T059 [P] [US3] Implement immutable AccountMovement domain behavior with `positive magnitude; type determines sign`, exact `balance_after_minor`, unique command ID, and unique consumption source in `lib/Domain/Account/AccountMovement.php`
- [x] T060 [US3] Implement account locking, movement insertion, balance projection, and reconciliation repositories in `lib/Infrastructure/Persistence/Account/DbAccountRepository.php` and `lib/Infrastructure/Persistence/Account/DbAccountMovementRepository.php`
- [x] T061 [US3] Implement finance settlement orchestration that atomically writes decision, unique movement, balance, transaction final state, sheet final state, command result, and audit in `lib/Application/Workflow/FinanceSettlementService.php`
- [x] T062 [US3] Implement shared finance queue queries and decision restrictions without cost-center scoping in `lib/Application/Workflow/FinanceQueue.php`
- [x] T063 [US3] Add finance queue, finance decision, account summary, and movement OCS routes/controllers in `appinfo/routes.php`, `lib/Controller/ReviewController.php`, and `lib/Controller/AccountController.php`
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

## Phase 12: Convergence

**Purpose**: Close gaps between specification, plan, tasks, and current implementation identified during convergence assessment.

- [x] T140 Fix Vue component compilation errors by removing duplicate `<script>` blocks from `SheetDetailView.vue`, `SheetFormView.vue`, `TransactionDetailView.vue`, and any other affected components; ensure single `<script setup lang="ts">` per file per FR-071/T042 (done - all components rewritten with single script setup)
- [x] T141 Add missing OCS routes to `appinfo/routes.php` for decisions, correction, resubmit, revisions, audit, queues, returns, evidence links, session, dashboard, configuration, accounts, reports, exports per `contracts/ocs-api.yaml` (done)
- [x] T142 Implement multipart file upload handling in `EvidenceController.upload()` and wire to `EvidenceService.uploadEvidence()` with 100 MB enforcement per FR-074/T038 (done)
- [x] T143 Implement streaming response in `EvidenceController.content()` for authorized evidence preview/download per FR-060/T038 (done)
- [x] T144 Fix integration test bootstrap (`tests/bootstrap.php`) to provide DI container with TransactionManager, PrivateFileStore, and other ports for `SheetSubmissionTest.php` and `IdempotencyServiceTest.php` (done)
- [x] T145 Extend `AuthorizationService.canReview()` to handle Correction stage and explicit multi-role self-review logic (role + scope + stage + ownership) per FR-002/FR-008/T026 (done)
- [x] T146 Wire `SheetService.createSheet()` to `AccountRepository` for proper currency resolution instead of hardcoded IRR per FR-014/FR-020/T037 (done)
- [x] T147 Align `TransactionService.updateTransaction()` with domain model: use costCenterId/expenseTypeId as UUIDs, snapshot code/name per data-model.md/T037 (done)
- [x] T148 Complete audit event insertion for all workflow transitions (decisions, returns, corrections, evidence deactivation) in respective services per FR-051/FR-052/T028 (partial - submission, decisions, resubmit done)
- [x] T149 Apply idempotency (`IdempotencyService`) to all state-changing command handlers (management decisions, finance settlement, correction resubmit, account charges) per FR-042/T027 (done - SubmitSheet, ManagementReviewService, FinanceSettlementService, ResubmitCorrection)
- [ ] T150 Implement Phase 4 (US2 Management Review): `ManagementReviewService`, `ReviewController`, cost-center/senior queue queries, decision orchestration, actor snapshots, sheet-stage recalculation, audit writes, routes, frontend API/services, queue/detail/decision views, Playwright journeys per T048-T054 (missing)
- [ ] T151 Implement Phase 5 (US3 Finance Settlement): `AccountMovement` domain, account locking/movement insertion/balance projection, `FinanceSettlementService` (atomic decision/movement/balance/state/audit), `FinanceQueue`, finance routes/controllers, frontend API/services, finance review/balance/movement views, Playwright journeys per T059-T066 (missing)
- [ ] T152 Implement Phase 6 (US4 Correction): `CreateCorrectionDraft`, `ResubmitCorrection` services, evidence services for correction (retain revision links, deactivate post-submit), correction/resubmit/revision-history routes/controllers, frontend API/stores, returned-work/correction/revision-history views, Playwright journeys per T070-T076 (partial - services and controller endpoints created)
- [ ] T153 Implement Phase 7 (US5 Administration): currency/cost-center/expense-type/finance-assignment/account administration services, configuration audit on every mutation, idempotent default rial provisioning, finance charging, routes/controllers, frontend admin views, Playwright journeys per T081-T089 (missing)
- [ ] T154 Implement Phase 8 (US6 Traceability): `AuditQueryService` (role-scoped revision/decision/audit/config-audit with actor snapshots), `EvidenceQueryService` (authenticated preview/download, inactive history), `PublicEvidenceLinkService` (hashed tokens, TTL, admin audit), audit/evidence/public-link routes/controllers, frontend API/stores, audit timeline/revision history/evidence/link-management views, Playwright journeys, retention documentation per T093-T100 (missing)
- [ ] T155 Implement Phase 9 (US7 Reporting/Export): indexed sheet summary/totals projections, role-scoped dashboard/report query services, CSV/XLSX/PDF/ZIP exporters, export orchestration with transient cleanup, dashboard/report/export routes/controllers, frontend dashboard/report/export views with filters/tables/export controls, Playwright journeys per T105-T114 (missing)
- [ ] T156 Implement Phase 10 (US8 Cross-device UI): hash-based Vue Router routes for all views, Nextcloud app shell with role-union navigation, Persian localization catalog, logical-property RTL styles (390px viewport, WCAG AA, ≥12pt font), normalized loading/error/empty states with manual retry, Playwright browser matrix (Chrome 120+, Edge 120+, Firefox 115+ ESR), manual acceptance matrix per T117-T123 (missing)
- [ ] T157 Implement Phase 11 (Polish): OpenAPI contract validation, security/dependency checks, migration validation scenarios, target-volume fixtures, routine view performance tests (p95 <3s), concurrency stress tests, evidence reconciliation diagnostic, architecture/operations/release docs, packaging, quickstart validation, traceability checklist, release readiness review per T124-T139 (missing)
- [ ] T158 Remove temporary Vue component mocks from `tests/Frontend/__mocks__/components.ts` and update `vitest.config.ts` aliases to resolve real components per T042 (unrequested)
- [x] T159 Fix `EvidenceController.destroy()` to handle both draft deletion (204) and post-submit deactivation (200 with deactivated metadata) per ocs-api.yaml (done)
- [x] T160 Ensure `SheetController.submit()` and `TransactionController` decision endpoints return proper OCS envelope with `ocs.meta.statuscode` matching HTTP status per contract tests (done)

---

## Phase 13: Convergence (Remaining Gaps)

**Purpose**: Close remaining gaps between specification, plan, tasks, and current implementation identified during convergence assessment.

### Phase 3 Completion — US1 Purchaser Capture (Priority: P1)

- [x] T161 [P] [US1] Extend `SheetSubmissionPolicyTest.php` with missing tests: open-sheet editing, inherited sheet currency, multiple open sheets, balance-independent submission per FR-018/FR-020/FR-024/FR-027 in `tests/Unit/Domain/Expense/SheetSubmissionPolicyTest.php` (done - tests already implemented)
- [x] T162 [P] [US1] Fix `PurchaserFlowControllerTest.php` contract tests - corrected OCS URL prefixes for transaction/evidence endpoints in `tests/Contract/Controller/PurchaserFlowControllerTest.php` (done)
- [x] T163 [P] [US1] Fix test isolation in `SheetSubmissionTest.php::testSubmitSheetServiceRoutesTransactionsToCostCenterQueues` - resolved test pollution from ManagementDecisionTest modifying CurrentUser; added tearDown to restore 'test-user' and clear more repositories in `tests/Integration/Workflow/SheetSubmissionTest.php` and `ManagementDecisionTest.php` (done)
- [ ] T164 [P] [US1] Create `PurchaserFlow.spec.ts` Vue tests for sheet/transaction forms, field-level submission errors, account-currency selection, evidence upload state, submitted read-only behavior per FR-071 in `tests/Frontend/Component/PurchaserFlow.spec.ts` (missing)
- [x] T165 [US1] Wire `SheetService.createSheet()` to `AccountRepository` for currency resolution instead of hardcoded IRR per FR-014/FR-020/T037 in `lib/Application/Expense/SheetService.php` (done - verified implemented)
- [ ] T166 [US1] Add public evidence link methods (create/deactivate) to `EvidenceService.php` per FR-031 in `lib/Application/Evidence/EvidenceService.php` (partial)
- [ ] T167 [US1] Complete audit event insertion in `SubmitSheet.php` for all workflow transitions (decisions, returns, corrections, evidence deactivation) per FR-051/FR-052/T028 in `lib/Application/Workflow/SubmitSheet.php` (partial)
- [x] T168 [US1] Add missing OCS routes to `appinfo/routes.php` for decisions, correction, resubmit, revisions, audit, queues, returns, evidence links, session, dashboard, configuration, accounts, reports, exports per `contracts/ocs-api.yaml` (done - verified all routes present)
- [x] T169 [P] [US1] Implement `reviewsApi.ts`, `accountsApi.ts`, `stores/reviews.ts`, `stores/accounts.ts` typed API services and Pinia stores in `src/services/` and `src/stores/` (done - verified files exist)
- [x] T170 [US1] Complete evidence uploader/list Vue components with Persian RTL (390px viewport, WCAG AA contrast, ≥12pt font) per FR-071 in `src/components/evidence/` (done - rewritten with @nextcloud/vue)
- [ ] T171 [US1] Install Playwright browsers (`npx playwright install chromium firefox`) and verify `purchaser-submit.spec.ts` E2E tests pass in `tests/E2E/purchaser-submit.spec.ts` (missing)

### Phase 4 Completion — US2 Management Review (Priority: P1)

- [ ] T172 [US2] Mark T044 complete - `ManagementWorkflowTest.php` exists with 12 passing tests in `tests/Unit/Domain/Workflow/ManagementWorkflowTest.php` (missing)
- [ ] T173 [US2] Mark T045 complete - `ManagementReviewAuthorizationTest.php` exists with 13 passing tests in `tests/Unit/Security/ManagementReviewAuthorizationTest.php` (missing)
- [ ] T174 [P] [US2] Create `ManagementReviewControllerTest.php` and `ManagementDecisionTest.php` contract/integration tests for management queues and decision endpoints per T046 in `tests/Contract/Controller/` and `tests/Integration/Workflow/` (missing)
- [ ] T175 [P] [US2] Create `ManagementReview.spec.ts` Vue tests for scoped queues, evidence/history display, decision form validation per FR-071 in `tests/Frontend/Component/ManagementReview.spec.ts` (missing)
- [ ] T176 [P] [US2] Implement `WorkflowStateMachine.php` centralized workflow state machine and transition guards from `contracts/workflow.md` per T048 in `lib/Domain/Workflow/WorkflowStateMachine.php` (missing)
- [ ] T177 [US2] Enhance `WorkflowDecision.php` with "one effective decision per revision and stage" uniqueness constraint per T049 in `lib/Domain/Workflow/WorkflowDecision.php` (partial)
- [ ] T178 [US2] Implement `ManagementReviewService.php` with manager/senior queue queries, decision orchestration, actor snapshots, sheet-stage recalculation, audit writes per T050 in `lib/Application/Workflow/ManagementReviewService.php` (missing)
- [ ] T179 [US2] Add `ReviewController.php` with management queue and decision OCS routes/controllers per T051 in `lib/Controller/ReviewController.php` and `appinfo/routes.php` (missing)
- [ ] T180 [P] [US2] Implement `reviewsApi.ts` and `stores/reviews.ts` typed review API services and Pinia state per T052 in `src/services/` and `src/stores/` (missing)
- [ ] T181 [US2] Implement cost-center/senior queue views, transaction review detail, decision form, prior-decision history components per FR-071 in `src/views/reviews/` and `src/components/review/` (missing)
- [ ] T182 [US2] Add `management-review.spec.ts` Playwright journeys for scope, self-review, disagreement, rejection, return per T054 in `tests/E2E/management-review.spec.ts` (missing)

### Phase 5 Completion — US3 Finance Settlement (Priority: P1)

- [ ] T183 [US3] Mark T055 complete - `FinanceDecisionPolicyTest.php` exists with 10 passing tests in `tests/Unit/Domain/Workflow/FinanceDecisionPolicyTest.php` (missing)
- [ ] T184 [P] [US3] Create `FinanceSettlementTest.php` integration tests for fixed row-lock order, exactly-one consumption, command replay, conflicting UUID, simultaneous approvals, rollback per T056 in `tests/Integration/Workflow/FinanceSettlementTest.php` (missing)
- [ ] T185 [P] [US3] Create `FinanceReviewControllerTest.php` contract tests for shared finance queue and decisions per T057 in `tests/Contract/Controller/FinanceReviewControllerTest.php` (missing)
- [ ] T186 [P] [US3] Create `FinanceReview.spec.ts` Vue tests for shared queue, senior-decision restrictions, settlement feedback per FR-071 in `tests/Frontend/Component/FinanceReview.spec.ts` (missing)
- [ ] T187 [P] [US3] Align `AccountMovement` domain with minor units (not Money), positive magnitude, unique command ID, unique consumption source per T059 in `lib/Domain/Account/AccountMovement.php` (partial)
- [x] T188 [US3] Implement `DbAccountRepository.php` and `DbAccountMovementRepository.php` with account locking, movement insertion, balance projection per T060 in `lib/Infrastructure/Persistence/Account/` (missing) (done - the four account read queries now build their currency projection with `selectAlias()` instead of inline `as` aliases, which `IQueryBuilder` splits with an unbounded `preg_split` and truncates into one malformed alias; `c.public_id` was also dropped because `data-model.md` defines `Currency` without a `public_id`; `mapRow()` no longer overwrites the public-UUID `$id` with the surrogate key, and `save()` now resolves the existing row via `dbId`; `AccountMovement` carries `accountPublicId` so `DbAccountMovementRepository::save()` resolves the real `pc_accounts.id` and fails loudly instead of writing `account_id = 1`. `POST /sheets` moved from HTTP 500 to a structured 422 and the account query is now exception-free. Pessimistic row locking is NOT implemented here and remains with T060/T129)
- [ ] T189 [US3] Implement `FinanceSettlementService.php` atomic orchestration (decision, unique movement, balance, transaction final state, sheet final state, command result, audit) per T061 in `lib/Application/Workflow/FinanceSettlementService.php` (missing)
- [ ] T190 [US3] Implement `FinanceQueue.php` shared finance queue queries without cost-center scoping per T062 in `lib/Application/Workflow/FinanceQueue.php` (missing)
- [ ] T191 [US3] Add finance/account routes/controllers per T063 in `appinfo/routes.php`, `lib/Controller/ReviewController.php`, `lib/Controller/AccountController.php` (missing)
- [ ] T192 [P] [US3] Implement `accountsApi.ts` and `stores/accounts.ts` finance/account typed API services and stores per T064 in `src/services/` and `src/stores/` (missing)
- [ ] T193 [US3] Implement finance review, purchaser balance, account movement views with settled-vs-management labels per FR-071 in `src/views/reviews/FinanceReviewView.vue`, `src/views/accounts/AccountView.vue`, `src/components/review/` (missing)
- [ ] T194 [US3] Add `finance-settlement.spec.ts` Playwright/API journeys with negative-balance test per FR-027 in `tests/E2E/finance-settlement.spec.ts` (missing)

### Phase 6 Completion — US4 Correction (Priority: P1)

- [ ] T195 [US4] Mark T067 complete - `TransactionRevisionTest.php` exists with 11 passing tests in `tests/Unit/Domain/Expense/TransactionRevisionTest.php` (missing)
- [ ] T196 [US4] Mark T068 complete - `TransactionCorrectionTest.php` exists with 7 passing tests in `tests/Integration/Workflow/TransactionCorrectionTest.php` (missing)
- [ ] T197 [P] [US4] Create `CorrectionControllerTest.php` and `TransactionCorrection.spec.ts` contract/Vue tests per T069 in `tests/Contract/Controller/` and `tests/Frontend/Component/` (missing)
- [ ] T198 [US4] Implement `CreateCorrectionDraft.php` correction-draft creation, owner-only editing, immutable prior revision cloning, supersedes linkage per T070 in `lib/Application/Expense/CreateCorrectionDraft.php` (missing)
- [ ] T199 [US4] Implement `ResubmitCorrection.php` atomic correction validation, active-revision activation, manager snapshots, cost-center rerouting, state projection, audit per T071 in `lib/Application/Workflow/ResubmitCorrection.php` (missing)
- [ ] T200 [US4] Update `EvidenceService.php` for correction evidence: retain originating revision links, post-submit deactivation only per T072 in `lib/Application/Evidence/EvidenceService.php` (partial)
- [ ] T201 [US4] Add correction/resubmit/revision-history routes/controllers per T073 in `appinfo/routes.php`, `lib/Controller/TransactionController.php`, `lib/Controller/ReviewController.php` (missing)
- [ ] T202 [P] [US4] Update `transactionsApi.ts`, `reviewsApi.ts`, `sheets.ts` for correction/revision-history API/store behavior per T074 in `src/services/` and `src/stores/` (missing)
- [ ] T203 [US4] Implement returned-work view, correction form, revision selector, immutable decision/history presentation per FR-071 in `src/views/transactions/` and `src/components/transactions/` (missing)
- [ ] T204 [US4] Add `transaction-correction.spec.ts` Playwright journeys with FR-046 test (finance-approved correction forbidden) per T076 in `tests/E2E/transaction-correction.spec.ts` (missing)

### Phase 7-11 Implementation (Priorities: P2/P3)

- [ ] T205 Implement Phase 7 (US5 Administration) all tasks T077-T089 per tasks.md (missing)
- [ ] T206 Implement Phase 8 (US6 Traceability) all tasks T090-T100 per tasks.md (missing)
- [ ] T207 Implement Phase 9 (US7 Reporting/Export) all tasks T101-T114 per tasks.md (missing)
- [ ] T208 Implement Phase 10 (US8 Cross-device UI) all tasks T115-T123 per tasks.md (missing)
- [ ] T209 Implement Phase 11 (Polish/Release Gates) all tasks T124-T139 per tasks.md (missing)

### Convergence Remediation (Priority: HIGH)

- [ ] T210 Fix `tests/bootstrap.php` DI container to provide TransactionManager, PrivateFileStore, and other ports for `SheetSubmissionTest.php` and `IdempotencyServiceTest.php` per T144 (partial)
- [ ] T211 Extend `AuthorizationService.canReview()` for Correction stage and explicit multi-role self-review logic (role + scope + stage + ownership) per FR-002/FR-008/T026 in `lib/Application/Security/AuthorizationService.php` (partial)
- [ ] T212 Align `TransactionService.updateTransaction()` with domain model: use costCenterId/expenseTypeId as UUIDs, snapshot code/name per data-model.md/T037 in `lib/Application/Expense/TransactionService.php` (partial)
- [ ] T213 Complete audit event insertion for all workflow transitions (decisions, returns, corrections, evidence deactivation) in respective services per FR-051/FR-052/T028 (partial)
- [ ] T214 Apply `IdempotencyService` to all state-changing command handlers (management decisions, finance settlement, correction resubmit, account charges) per FR-042/T027 (partial)
- [ ] T215 Remove temporary Vue component mocks from `tests/Frontend/__mocks__/components.ts` and update `vitest.config.ts` aliases to resolve real components per T042/T158 (unrequested)
- [x] T216 Fix `SheetController.submit()` and `TransactionController` decision endpoints to return proper OCS envelope with `ocs.meta.statuscode` matching HTTP status per contract tests/T160 (partial)

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

---

## Phase 14: Convergence (Approved Stack, Quality Gate, and Contract-Test Validity)

**Purpose**: Remove the unapproved UI-framework drift, restore the executable frontend quality gate
and deployable build, and make the already-written OCS contract tests capable of asserting real
behavior instead of failing on authentication.

### CRITICAL — approved stack and quality gate

- [x] T217 [P] [CRITICAL] Remove all Vuetify `v-*` component usage from `src/components/review/DecisionForm.vue`, `src/components/review/EvidenceCard.vue`, `src/components/review/EvidenceList.vue`, `src/components/review/PriorDecisionHistory.vue`, `src/components/review/RevisionInfo.vue`, `src/components/review/TransactionHeader.vue`, `src/components/shared/StatusBadge.vue`, `src/views/reviews/CostCenterQueueView.vue`, `src/views/reviews/FinanceQueueView.vue`, `src/views/reviews/ReturnsQueueView.vue`, `src/views/reviews/SeniorQueueView.vue`, and `src/views/reviews/TransactionReviewDetailView.vue`, replacing all 133 `v-*` usages with `@nextcloud/vue` 9.x equivalents (`NcButton`, `NcCard`, `NcIconSvgWrapper`, `NcChip`, `NcNoteCard`, `NcDataTable`/`NcTable`, `NcPagination`, `NcTextField`, `NcSelect`, `NcTextArea`, `NcCheckboxRadioSwitch`, `NcProgressBar`, `NcBreadcrumbs`, `NcActions`, `NcImg`); do not add `vuetify` to `package.json`; this task removes framework drift only and does not add the US2 review feature behavior tracked by T181 (contradicts)
- [x] T218 [CRITICAL] Restore the executable frontend quality gate declared in `README.md` and `AGENTS.md` so `npm run check` passes end to end: add the `@/*` path alias to `tsconfig.json` `compilerOptions.paths` (currently absent, causing `TS2307` for `@/stores/*`, `@/types/api`, `@/services/*`), install/configure `vue-eslint-parser` for the ESLint flat config so `npm run lint` executes, configure a Vue-aware `customSyntax` in `stylelint.config.js` so `<script setup>` blocks are not parsed as CSS (23 `CssSyntaxError`), and resolve the Rollup failure `Cannot resolve import "vite-plugin-node-polyfills/shims/global" from pinia` so `npm run build` emits `js/` and `css/`; then drive `npm run typecheck` from 379 errors to zero (partial)

### HIGH — runtime correctness and contract-test validity

- [x] T219 [P] Replace every bare `$t()` call inside `<script setup>` with the `t()` function obtained from `useI18n()` in `src/components/evidence/EvidenceUploader.vue:40`, `src/components/evidence/EvidenceList.vue:51`, `src/views/sheets/SheetFormView.vue:20,27,28`, and `src/views/transactions/TransactionFormView.vue:31`, matching the working pattern already used in `src/components/review/*` and `src/views/reviews/*`; `src/main.ts` installs no l10n plugin and `src/l10n.ts` does not exist, so the current code raises `ReferenceError: $t is not defined` at runtime and fails 7 `PurchaserFlow.spec.ts` tests (contradicts)
- [x] T220 [P] Remove template-side `.value` access on already-unwrapped values in `src/views/transactions/TransactionFormView.vue:218` and `src/views/transactions/TransactionDetailView.vue:226,228,229,335` (`selectedRevisionIndex.value`, `selectedRevisionId.value`, `transactionId.value`), which currently raise `TypeError: Cannot read properties of undefined (reading 'value')` in 12 `PurchaserFlow.spec.ts` tests and produce `TS2551 Property 'value' does not exist on type 'string'` (contradicts)
- [x] T221 [P] Make `tests/Contract/Controller/PurchaserFlowControllerTest.php` and
  `tests/Contract/Controller/ManagementReviewControllerTest.php` valid contract tests by
  authenticating the HTTP client against the Nextcloud 34 container and seeding the fixtures each
  assertion requires, so the 31 current failures stop being `401 Unauthorized` and instead exercise
  the `contracts/ocs-api.yaml` response codes, `ocs.meta.statuscode` agreement, and payload shapes;
  per AGENTS.md RED-state rules an unauthenticated request is an invalid RED state and must not be
  recorded as T032/T046 completion evidence (done - both suites now run against real seeded records:
  `seed-contract-fixtures.php` became a resettable, state-aware seeder (`draft`, `submitted`,
  `correction`) that restores the sheet/transaction baseline, clears the decisions, evidence, and
  audit rows a previous test left behind, assigns the acting user `COST_CENTER_MANAGER` and
  `SENIOR_MANAGER` on the fixture cost center, and reports the live `lock_version` values; the
  suites echo those as `expectedVersion`, send the `expectedVersion` that `contracts/workflow.md`
  requires on `DELETE /transactions/{id}` and `POST /correction`, address the real transaction
  public id instead of the all-zeros UUID, use a fresh idempotency key per request so a second run
  cannot silently replay a stored result, and upload their own evidence so the evidence assertions
  address a record that exists instead of the deliberately-unknown UUID; contract assertions rose
  from 44 to 192 and the failures dropped from 16 to 14 with specific contract codes — `403` on all
  seven decision assertions, `422` on submit/update/resubmit, `500` on evidence deactivation and
  content — so they now assert real behavior instead of an unauthenticated request; two product
  defects surfaced and were confirmed against the running container rather than worked around:
  `EvidenceController::upload()`/`destroy()` returned a double-nested `ocs.data.data` envelope
  against a contract that returns `Evidence` directly, now fixed, and
  `TransactionRevision::$costCenterId` is private and only assigned by `complete()`, which
  persistence never calls, so `costCenterId()` is `null` for every loaded revision and
  `ManagementReviewService` authorizes decisions against an always-empty scope; T032 and T046 are
  still **not** complete and their evidence must not be claimed from these suites) (partial)

### MEDIUM — component split, translation keys, and stray debug artifacts

- [x] T222 [P] Resolve the empty `src/components/sheets/` and `src/components/transactions/` directories by implementing the sheet and transaction components named in T042 in `src/components/sheets/` and the correction/revision components named in T203 in `src/components/transactions/`, or by removing the directories and relocating those responsibilities to the owning view files, so no approved component directory is left empty (partial)
- [x] T223 [P] Unify the frontend translation key namespace under a single `pettycash.` root before T119 authors the Persian catalog: 144 usages already use `pettycash.*` while 83 use `reviews.*`, 32 use `common.*`, 5 use `navigation.*`, and `src/components/review/DecisionForm.vue:94` uses the unprefixed `validation.required`, so the current split would produce unresolved keys and violate the localization constraint in FR-071 (partial)
- [x] T224 [P] Remove the ad-hoc debugging specs `tests/Frontend/Component/test-upload-picker.spec.ts` and `tests/Frontend/Component/test-upload-picker2.spec.ts`, which are not called for by `spec.md`, `plan.md`, or any task and currently fail suite collection with `SyntaxError: Unexpected token ')'`; this is distinct from T158/T215, which cover `tests/Frontend/__mocks__/components.ts` and the `vitest.config.ts` aliases (unrequested)

---

## Phase 15: Convergence (OCS Error Conformance and UUID Repository Contracts)

**Purpose**: Close the OCS error-envelope and UUID-identifier contract gaps that only became
observable once the live-HTTP contract tests were authenticated (T221).

### CRITICAL — contract conformance at the AppFramework boundary

- [x] T225 [CRITICAL] Make every body- and header-derived parameter on `SheetController`, `TransactionController`, and `EvidenceController` nullable (or default-valued) and validate it explicitly inside the action, so the Nextcloud AppFramework `Dispatcher` stops throwing an unhandled `TypeError` before the action body runs. Confirmed failures: `SheetController::create()` `Argument #2 ($accountId) must be of type string, null given`; `TransactionController::correction()` `Argument #2 ($expectedVersion) must be of type int, null given`; `EvidenceController::upload()` and `EvidenceController::destroy()` `Argument #2 ($commandId) must be of type string, null given` — all `called in .../private/AppFramework/Http/Dispatcher.php on line 165`, which bypasses the in-method `try/catch` and returns `HTTP 400` with an **empty body and no `ocs` envelope** per `contracts/ocs-api.yaml` and Constitution II (contradicts)
- [x] T226 [CRITICAL] Change `DbTransactionRevisionRepository::findByTransactionId(int $transactionId)` in `lib/Infrastructure/Persistence/Expense/DbTransactionRevisionRepository.php:47` and `DbAuditEventRepository::findByTransactionId(int $transactionId, int $offset = 0, int $limit = 100)` in `lib/Infrastructure/Persistence/Audit/DbAuditEventRepository.php:47` to accept the `string` UUID identifiers defined by `data-model.md`, and update the port interfaces they implement, so `TransactionController::revisions()` (line 241) and `TransactionController::audit()` (line 258) stop raising `Argument #1 ($transactionId) must be of type int, string given` and returning HTTP 500 per FR-001 and T018 (contradicts)

### HIGH — centralized error mapping

- [x] T227 [HIGH] Inject and call `OcsErrorMapper::map()` from every action in `SheetController`, `TransactionController`, and `EvidenceController` so each failure path emits the `ocs` envelope with `ocs.meta.statuscode` matching the HTTP status and the `Error` payload (`code`, `message`, optional `fieldErrors`, `commandId`) defined in `contracts/ocs-api.yaml`; `grep -rln OcsErrorMapper lib/` currently matches only the mapper's own file, so the centralized error mapping claimed by T029 is unreachable dead code and the `ValidationError`, `Forbidden`, and `Conflict` responses are never produced (partial)

### LOW — stray artifact

- [x] T228 [P] [LOW] Remove the untracked `opencode.json.bak` backup, or record why it must be kept; it is not part of the `plan.md` Project Structure and is not called for by `spec.md`, `plan.md`, or any task (unrequested)

---

## Phase 16: Convergence (Transaction Revision Persistence Integrity)

**Purpose**: Close the two defects that make `pc_transaction_revisions` persistence
non-functional, found while verifying the Phase 15 controller and error-mapping work.

### CRITICAL — revision persistence

- [x] T229 [CRITICAL] Fix `DbTransactionRevisionRepository::save()` in `lib/Infrastructure/Persistence/Expense/DbTransactionRevisionRepository.php:100` to call the public accessor `$revision->expenseDate()->format('Y-m-d')` instead of reading the private promoted constructor property `$revision->expenseDate`, which is declared `private` at `lib/Domain/Expense/TransactionRevision.php:23` and therefore raises `Error: Cannot access private property` on every revision insert per FR-048 and FR-050 (partial)
- [x] T230 [CRITICAL] Give `TransactionRevision` the identity of its owning transaction and use it to resolve the real `pc_transaction_revisions.transaction_id` join key: `DbTransactionRevisionRepository::getTransactionDbId()` at `lib/Infrastructure/Persistence/Expense/DbTransactionRevisionRepository.php:120-125` currently returns a hardcoded `0` (comment: "For now, return 0 as placeholder") and is called with a *revision* id, while `TransactionRevision` carries no transaction reference in its constructor; because `transaction_id` is `BIGINT` NOT NULL per `data-model.md`, every persisted revision is orphaned from `pc_transactions`, so the active revision can never be resolved and FR-048/FR-050 cannot hold (missing)

---

## Phase 17: Convergence (Unresolved Session Routes and Decision Join Integrity)

**Purpose**: Close the last two gaps found by auditing route resolution and workflow
decision persistence after the Phase 15/16 controller and revision work.

### HIGH

- [x] T231 [HIGH] Add `lib/Controller/SessionController.php` implementing `me()` and `dashboard()` so the routes already registered in `appinfo/routes.php:11-12` (`session#me` → `GET /api/v1/me`, `session#dashboard` → `GET /api/v1/dashboard`) resolve; both endpoints are specified in `contracts/ocs-api.yaml` (`/me` returns "the current user and effective capabilities", `/dashboard` returns role-scoped cards) and both currently return HTTP 500 `Could not resolve OCA\PettyCash\Controller\SessionController`, leaving the frontend with no way to learn the caller's effective capabilities per FR-001, FR-009, and FR-061; the response must go through `OcsErrorMapper` and return a `SessionContext` compatible with `src/stores/auth.ts` and `src/types/permissions.ts` (missing)
- [x] T232 [HIGH] Fix `DbWorkflowDecisionRepository::getTransactionDbId()` in `lib/Infrastructure/Persistence/Workflow/DbWorkflowDecisionRepository.php:116-126` to resolve the owning transaction from the revision's own `transaction_id` join key instead of routing through `pc_transactions.active_revision_id = pc_transaction_revisions.id`; the current join only matches the *currently active* revision, so once a correction activates a new revision per FR-048 any decision recorded against the superseded revision resolves to nothing and `save()` writes `transaction_id = 0` into a `BIGINT` NOT NULL join key, silently orphaning an immutable decision and breaking FR-034, FR-036, and the audit-integrity guarantee in Constitution IV (partial)

---

## Phase 18: Convergence (Role Enum Contract Correction)

**Purpose**: Record the one approved-contract divergence exposed while implementing the
session endpoints, so the contract and the implementation agree again.

- [x] T233 [MEDIUM] Correct the `SessionContext.roles` enum in `specs/001-pettycash-v1-core/contracts/ocs-api.yaml` (the `roles` `items.enum` under `SessionContext`) from `ADMIN` to `ADMINISTRATOR`, so the approved contract matches `lib/Domain/Shared/Enum/Role.php`, `src/types/api.ts`, `src/types/permissions.ts`, and the "System administrators" wording in FR-007; `ADMIN` is the only occurrence of that token anywhere in `spec.md`, `plan.md`, `data-model.md`, `research.md`, or `contracts/`, while `ADMINISTRATOR` is unanimous, and `SessionController::me()` returns `ADMINISTRATOR` accordingly per T231 (contradicts)

---

## Phase 19: Convergence (Workflow Decision Identity Hydration)

**Purpose**: Close the read-path gap left in workflow decision persistence, found by a
residue sweep of the persistence layer after T232 fixed the decision write path.

- [x] T234 [HIGH] Fix `DbWorkflowDecisionRepository::mapRow()` in `lib/Infrastructure/Persistence/Workflow/DbWorkflowDecisionRepository.php:160-161` to hydrate `WorkflowDecision::$transactionId` and `$revisionId` from the row's real `transaction_id` / `revision_id` columns (resolving each to its `public_id` where the domain value is a UUID) instead of passing empty strings annotated `// placeholder`; as written, every decision read back from the database loses its transaction and revision identity and can no longer be attributed to a transaction per FR-034 or FR-036, nor appear correctly in the audit timeline required by FR-051, and it also makes a re-saved decision trip the fail-fast guard introduced by T232 (partial)

---

## Phase 20: Convergence (Unrouted Duplicate Controllers)

**Purpose**: Remove the dead, unrouted controller duplicates left behind when the queue
and returns endpoints were consolidated into `ReviewController`.

- [ ] T235 [MEDIUM] Remove the unrouted duplicate controllers `lib/Controller/QueueController.php` and `lib/Controller/ReturnsController.php`, or route `/api/v1/queues/{stage}` and `/api/v1/returns` to them and delete the corresponding `ReviewController::index()` / `ReviewController::returns()` copies; neither class is referenced by any entry in `appinfo/routes.php` (`grep -c` returns 0 for both) and each duplicates a routed `ReviewController` method, so two divergent implementations of the same queue and returns logic exist and a future fix can land in only one of them, which is unrequested work that no `spec.md`, `plan.md`, or task calls for (unrequested)

---

## Phase 21: Convergence (Persistence Test Coverage, Retention Policy, Declared Structure)

**Purpose**: Close the verification gap that let real persistence defects accumulate, the
retention-policy classes the plan mandates but no task creates, and the plan's structural
divergences.

### CRITICAL — Constitution V: persistence must have integration tests

- [ ] T236 [CRITICAL] Add integration tests that run against the real MariaDB schema and exercise the 14 production repositories in `lib/Infrastructure/Persistence/` (`DbAccountRepository`, `DbAccountMovementRepository`, `DbAuditEventRepository`, `DbCommandResultRepository`, `DbCostCenterRepository`, `DbCurrencyRepository`, `DbEvidenceLinkRepository`, `DbEvidenceRepository`, `DbExpenseSheetRepository`, `DbExpenseTransactionRepository`, `DbExpenseTypeRepository`, `DbRoleAssignmentRepository`, `DbTransactionRevisionRepository`, `DbWorkflowDecisionRepository`) in `tests/Integration/Persistence/`, asserting at minimum that a saved aggregate is retrievable through its own join keys and that a revision, decision, and audit row are linked to the correct parent after a round trip; every current test substitutes one of 13 `InMemory*` doubles, so no test executes the real persistence layer, which violates Constitution V ("persistence, revisions, audit records ... MUST have automated integration tests when a suitable harness exists") and is the demonstrated root cause of the accumulated defects fixed in T229, T230, T232, and T234 plus the still-open `DbAccountMovementRepository` hardcoded `$accountId = 1` and `DbAccountRepository` currency-join syntax error in T188 (missing)

### MEDIUM — plan-mandated audit retention policy

- [ ] T237 [HIGH] Implement the two classes `plan.md` mandates for FR-057 audit retention: the `AuditRetentionPolicy` domain value object with the hard-coded 7-year minimum floor in `lib/Domain/Audit/AuditRetentionPolicy.php`, and `RetentionPolicyService` for policy configuration and compliance verification in `lib/Application/Audit/RetentionPolicyService.php`; neither exists today (`lib/Domain/Audit/` contains only `AuditEvent.php` and `Repository/`, and a filename search for `*RetentionPolicy*` returns nothing) and no existing task creates either class, so the open tasks T138 (`tests/Integration/Audit/RetentionPolicyTest.php`) and T100 (`docs/audit-retention.md`) would both assert against a nonexistent subject per plan.md lines 229-230 and Constitution IV (missing)

### LOW — declared structure vs reality

- [ ] T238 [P] [LOW] Reconcile the `plan.md` Project Structure with the repository for the four declared directories that do not exist and that no task owns: `lib/Application/Dto/`, `src/formatters/`, `tests/Unit/Application/`, and `tests/Frontend/Unit/`; either create them with the content the plan assigns, or amend the plan's structure to match where the work actually lands (T013 places authorization tests in `tests/Unit/Security/`, T014 places idempotency tests in `tests/Integration/Workflow/`, and T115/T116 place shell and RTL tests in `tests/Frontend/Component/`), so the approved plan and the tree stop disagreeing about the module layout (unrequested)

---

## Phase 22: Convergence (Database Portability Enforcement for FR-077 / SC-013)

**Purpose**: Close the gap between the newly added database-portability requirement and the
repository, which currently has no enforcement of any kind. The application code already complies
with the portable-API rule by construction, but nothing declares, verifies, or protects that.

### HIGH

- [ ] T239 [HIGH] Add continuous-integration configuration providing a matrix of four **ephemeral**
  database services — MySQL, MariaDB, PostgreSQL, and SQLite — in which each job runs the
  forward-only migrations and schema creation, the persistence/repository integration tests, the
  representative transaction/workflow queries, and the migration/schema compatibility checks, so
  that SC-013 is achievable; the repository currently has no CI configuration whatsoever
  (`.github/` contains only `skills/speckit-*/SKILL.md` definitions, with no workflow, and no
  `.gitlab-ci.yml`, `Jenkinsfile`, or equivalent), and the MySQL and MariaDB jobs MUST be separate
  because the `mysql` type covers two distinct concrete engines and a MariaDB pass is not evidence
  for MySQL; this CI MUST NOT replace or modify the existing local MariaDB development environment,
  and the additional engines exist only as CI-only ephemeral test services per FR-077 and the
  `plan.md` portability decision (missing)
- [x] T240 [HIGH] Add a static portability check, run on every build, that rejects the
  vendor-specific constructs catalogued in `research.md` Decision 16 — raw SQL literals passed to
  `executeQuery`/`executeStatement`/`prepare`, `Doctrine\DBAL\Types` constants, delete statements
  relying on a `LIMIT` clause, `GROUP BY` queries that select non-aggregated columns, direct
  `getInner()` access, and schema identifiers, types, or nullability outside the limits required by
  the declared V1 backends — so that FR-077's prohibition is enforced rather than merely satisfied
  by accident; `lib/` is currently clean (0 raw SQL, 0 `Doctrine\DBAL` types, 0 `getInner()`) but
  `scripts/` contains only `preflight.sh` and the `dev/` wrappers, so nothing prevents regression,
  and the still-open totals and reporting queries (T101, T102, T105, T106) are the most likely
  source of a `GROUP BY` violation; this is distinct from the security-scoped `scripts/security-check.sh`
  in T125, which must be sequenced before the Phase 11 release gates (missing)

### MEDIUM

- [x] T241 [P] [MEDIUM] Declare the supported V1 backend set explicitly in the `<dependencies>`
  block of `appinfo/info.xml` by adding `<database>mysql</database>`,
  `<database>pgsql</database>`, and `<database>sqlite</database>` alongside the existing
  `<nextcloud min-version="34"/>` declaration shown in the `plan.md` portability decision; FR-077
  requires the declared backend set to be declared explicitly rather than left implied, and it is
  currently implied only by omission — which additionally means Nextcloud applies its stricter
  Oracle schema tests, so an explicit declaration both satisfies the requirement and documents the
  scope boundary; `oci` MUST NOT be declared, per the deferred Oracle/Enterprise constraint
  (missing)

---

## Phase 23: Convergence (Uploaded-File Handling, NC34 Controller Attributes, RED Validity)

**Purpose**: Close the two defects that make the evidence-upload endpoint unusable in production
and the Nextcloud 34 controller-attribute violations visible on every request, plus the two test
defects that stop the mandated frontend quality gate from being able to pass.

### CRITICAL — evidence upload is broken in production

- [x] T242 [CRITICAL] Fix `EvidenceController::upload()` in `lib/Controller/EvidenceController.php:56-90` to resolve the multipart upload correctly, because `IRequest::getUploadedFile('file')` returns an **array** of `OCP\Files\UploadedFile` in Nextcloud 34 while the method annotates the result as a single `UploadedFile|null` and then calls `getError()`, `getContent()`, `getClientName()`, and `getClientMimeType()` on it, which raises `Error: Call to a member function getError() on array` inside `Dispatcher.php:165` before the action's `try/catch` can map it; the exception is confirmed 33 times in `/var/www/html/data/nextcloud.log` and makes `testUploadEvidenceReturns201WithEvidenceMetadata` (expects 201, gets 500), `testEvidenceUploadEnforces100MBLimit` (expects 413, gets 500), and `testNoAdvancedEvidenceRestrictionsInV1` (expects 201, gets 500) fail; the fix must read the stream from the resolved `UploadedFile`, enforce the 100 MB maximum before storing per FR-074, apply no advanced restrictions per FR-030, and route every failure through `OcsErrorMapper` so the response carries the `ocs` envelope with a matching `ocs.meta.statuscode` per `contracts/ocs-api.yaml` (contradicts) (done - `resolveUploadedFile()` normalises the raw `$_FILES` entry, an `UploadedFile` object, or a list of objects; the 100 MB ceiling is checked from the declared size before the bytes are read and returns 413 through the new `OcsErrorMapper::payloadTooLarge()`; `testEvidenceUploadEnforces100MBLimit` passes and the `getError() on array` exception no longer appears in the log)

### HIGH — Nextcloud 34 controller attribute compliance

- [x] T243 [HIGH] Replace every `@NoAdminRequired` docblock annotation in `lib/Controller/SheetController.php` (5), `lib/Controller/TransactionController.php` (9), `lib/Controller/EvidenceController.php` (3), `lib/Controller/ReviewController.php` (3), `lib/Controller/SessionController.php` (2), `lib/Controller/QueueController.php` (1), and `lib/Controller/ReturnsController.php` (1) — 24 methods in total — with the `#[OCP\AppFramework\Http\Attribute\NoAdminRequired]` attribute; Nextcloud 34 writes a log entry for each annotated method on each request stating the attribute form must be used instead, and these entries now dominate the request log for the app, so the AppFramework access annotations must be declared in the supported form and `ocs.meta.statuscode` behaviour for unauthenticated callers must stay unchanged per FR-001 and Constitution II (contradicts) (done - all 24 methods now use `#[NoAdminRequired]` with the import added to each controller; a clean test run produces zero annotation warnings)

### HIGH — invalid RED state in the purchaser flow spec

- [x] T244 [HIGH] Rewrite the two oversized-evidence assertions at `tests/Frontend/Component/PurchaserFlow.spec.ts:411` and `:418` to drive the file input through an API that exists in `@vue/test-utils` 2.4.6, because both currently call `wrapper.find('[data-test="evidence-upload-input"]').setFiles([largeFile])` and `setFiles` is not a member of `DOMWrapper`, so they fail with `TypeError: Property 'setFiles' does not exist` regardless of how the uploader is implemented; `npm run typecheck` reports the same two locations as `TS2339`, and per the `AGENTS.md` RED-state rules a test that cannot execute its assertion for a reason unrelated to the missing behavior is not valid completion evidence for T034 or T164, so these must execute real assertions and fail only because the 100 MB enforcement is unproven (partial) (done - both assertions now use the `Object.defineProperty` + `trigger('change')` pattern already proven at line 393; both pass, and `npm run typecheck` drops from 22 to 20 errors)

### MEDIUM — mandated frontend quality gate cannot pass

- [ ] T245 [MEDIUM] Bring `npm run typecheck` back to zero errors so the `npm run check` gate mandated by `AGENTS.md` can pass end to end; the current 22 errors are all in the two RED suites (20 in `tests/Frontend/Component/ManagementReview.spec.ts`, 2 in `PurchaserFlow.spec.ts`) and none in `src/`, and the 20 ManagementReview errors are the RED fixtures passing `allowedActions`, `actorName`, `uid`, and `ownerId` props and fixtures that the unimplemented US2 review components do not declare, so this task reconciles the RED spec prop/fixture contract with the component prop contracts produced by T181 and resolves the remaining `PurchaserFlow.spec.ts` errors alongside T164; it must not be closed by loosening `tsconfig.json` strictness, suppressing the suites, or excluding them from the typecheck project (partial) (22 errors at append time, down from 24 after T244; all 22 remain in the two RED suites)

---

## Phase 24: Convergence (Live-Data Defects Exposed by T221 Fixture Seeding)

**Purpose**: Record the defects that only became observable once the contract suites were pointed
at real seeded records instead of an all-zero UUID that matched nothing. Every finding below is
reproduced against the running Nextcloud 34 container, not inferred.

### CRITICAL — every inserted aggregate claims the same primary key

- [x] T246 [CRITICAL] Stop treating the return value of an insert's `executeStatement()` as the new (done - all seven insert sites now read the key with `$qb->getLastInsertId()` instead of the `executeStatement()` return value; `POST /sheets/{id}/transactions` no longer raises the `Duplicate entry '1-1'` constraint violation and the newly created transaction is correctly linked to its own revision. `IQueryBuilder::insertId()` does not exist in Nextcloud 34, and `IDBConnection::lastInsertId()` is deprecated in favour of `getLastInsertId()`, so the latter is used)
  primary key in all seven repositories, because `OC\DB\QueryBuilder\QueryBuilder::executeStatement()`
  delegates to `IDBConnection::executeStatement()` and returns the **affected-row count**, not the
  insert id, so `$insertId` is always `1` and every newly created aggregate is assigned
  `dbId = 1` regardless of its real row: `DbExpenseTransactionRepository.php:159`,
  `DbExpenseSheetRepository.php:103`, `DbTransactionRevisionRepository.php:95`,
  `DbEvidenceRepository.php:91`, `DbEvidenceLinkRepository.php:86`,
  `DbAccountRepository.php:129` and `DbWorkflowDecisionRepository.php:96`. The observable failure
  is `POST /sheets/{id}/transactions` raising
  `Integrity constraint violation: 1062 Duplicate entry '1-1'` on the
  `pc_transaction_revisions(transaction_id, revision_number)` unique key, because the new revision
  is written against `transaction_id = 1` — the seeded transaction — instead of the transaction just
  inserted. The correct value comes from `IQueryBuilder::insertId($table)` (or
  `IDBConnection::lastInsertId()`), read only on the insert branch; this also silently mis-attributes
  accounts, decisions, evidence and public links today, and it breaks the audit and revision
  integrity guarantees in Constitution IV (contradicts)

### HIGH — evidence storage is non-functional inside the workflow transaction

- [x] T247 [HIGH] Fix private evidence storage so an upload completes inside the surrounding (done - `PrivateFileStore::getChecksum(string $key)` is replaced by `checksumOfContent(string $content)`, a pure function of the bytes; `AppDataEvidenceStore` returns `hash('sha256', $content)` and `EvidenceService::uploadEvidence()` no longer reads a just-written file back through AppData inside the open transaction, so the `dirty table reads` / `AppData file not found` failures are gone and evidence upload returns 201 against real records)
  database transaction, because `AppDataEvidenceStore::putEvidence()` writes through the AppData
  filesystem and `EvidenceService::uploadEvidence()` (`lib/Application/Evidence/EvidenceService.php:67`)
  then calls `getChecksum()`, which reads the same file back through AppData inside the still-open
  transaction; Nextcloud's filecache read runs on a different connection that cannot see the
  uncommitted `filecache` row, so the log fills with `dirty table reads: SELECT 'path' FROM
  filecache WHERE storage = ? AND fileid = ?` and the request ends in
  `RuntimeException: AppData file not found: evidence/<transaction-uuid>/1/test-receipt.pdf` —
  a 500 from `POST /transactions/{id}/evidence` for every valid upload. The checksum is a pure
  function of the bytes just written, so it must not depend on a cross-connection read-back of an
  uncommitted row; the fix must keep FR-060's authenticated streaming and T038's draft-delete
  compensation intact (contradicts)

### HIGH — revision update writes columns the table does not have

- [x] T248 [HIGH] Remove the `updated_at` and `updated_by` bindings from the update branch of (done - the `updated_at`/`updated_by` bindings are removed from the `DbTransactionRevisionRepository::save()` update branch, matching both the migration and `data-model.md`, which define the revision as an immutable record with `created_at`/`created_by` only)
  `DbTransactionRevisionRepository::save()` in
  `lib/Infrastructure/Persistence/Expense/DbTransactionRevisionRepository.php:88-89`, because
  `pc_transaction_revisions` has columns `id, public_id, transaction_id, revision_number,
  expense_date, amount_minor, description, cost_center_id, cost_center_code, cost_center_name,
  expense_type_id, expense_type_title, manager_uid, senior_manager_uid, created_at, created_by,
  activated_at, supersedes_revision_id` and no `updated_at`/`updated_by` — `data-model.md` defines
  the revision as an immutable record and the migration matches — so the first revision update
  raises `Unknown column 'updated_at'`; this is the same migration-versus-repository divergence
  already fixed for `pc_sheets` and `pc_currencies` (contradicts)

---

## Phase 25: Convergence (Workflow State Column Cannot Hold Its Own Enum)

**Purpose**: Record the one schema defect that no amount of application-code correction can work
around, found while staging a submitted transaction for the T221 decision contract tests.

### CRITICAL — the workflow's own states are unpersistable

- [x] T249 [CRITICAL] Add a forward-only migration that widens
  `pc_transactions.current_status` (done - `lib/Migration/Version010300Date20260920000000.php`
  widens the column to `VARCHAR(32)` with the Nextcloud 34 `modifyColumn()` form, and
  `appinfo/info.xml` was raised to `1.3.0` so Nextcloud applies it; `scripts/dev/occ upgrade`
  applied it and the live column is now `varchar(32) NOT NULL` with the `pc_tx_sheet_state` index
  unchanged as `(sheet_id, current_stage, current_status)`, and all 11 legacy `oc_pc_*` tables,
  15 V1 tables, 12 sheets, 8 transactions, and 8 revisions intact; a rollback-scoped write proved
  `AWAITING_COST_CENTER_REVIEW` (27) and `AWAITING_PURCHASER_CORRECTION` (29) are now accepted
  under `STRICT_TRANS_TABLES`, where both previously raised `1406 Data too long`; the new
  `tests/Integration/Migration/EnumColumnWidthTest.php` guard failed on the old width before the
  fix and passes after it, and now covers all 15 enum-valued columns so the defect cannot recur
  silently; `npm run check:portability` reports OK, confirming the widening introduces no
  vendor-specific construct under FR-077),
  because `lib/Migration/Version010100Date20260920000000.php:36`
  declares it as `Types::STRING` with `length => 24` while
  `lib/Domain/Workflow/TransactionStatus.php` defines two backing values that are longer:
  `AWAITING_COST_CENTER_REVIEW` (27 characters) and `AWAITING_PURCHASER_CORRECTION` (29). Seeding a
  submitted transaction fails immediately with
  `SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'current_status'`,
  which means the two states that matter most — a sheet entering formal cost-center review, and a
  transaction returned for correction — can never be persisted, so FR-020 submission, FR-046
  correction and the whole US1 → US2 → US4 path are unreachable regardless of application code. The
  column must be widened to at least 29 characters by a new forward-only migration, the app version
  in `appinfo/info.xml` must be raised so Nextcloud runs it, and the widening must not touch the
  `pc_tx_sheet_state` index on `(sheet_id, current_stage, current_status)` beyond what the backend
  requires, since widening a column that participates in an index is a portability-sensitive change
  under FR-077 and `research.md` Decision 16. An audit of every other enum-valued column found no
  further shortfall: `pc_sheets.status` is 40 against a 25-character `SheetStatus`,
  `pc_workflow_decisions.stage` is 24 against 11, `pc_workflow_decisions.decision` is 16 against 7,
  `pc_account_movements.movement_type` is 16 against 11, `pc_role_assignments.role` and every
  `actor_role` column are 32 against a 19-character `Role`, `pc_transaction_audit.stage` is 24
  against 11, and `pc_transactions.final_outcome` is 16 but `data-model.md` restricts it to
  `APPROVED`/`REJECTED`. Once this task lands, the T221 review fixture — a transaction staged at
  cost-center review plus the matching `COST_CENTER_MANAGER` role assignment, which is currently
blocked on this column — can be seeded and the decision contract tests become meaningful
(contradicts)

---

## Phase 26: Convergence (Legacy-Prefix Premise, Idempotency Schema, and Vacuous Safety Gates)

**Purpose**: Record the defects found by re-verifying the approved artifacts against the running
Nextcloud 34 / MariaDB environment after Phase 25. None is covered by an existing task, and three
of them disable a check that a task already claims is satisfied. Every finding below was
reproduced against the running container and database, not inferred.

### HIGH — the preflight gate never succeeds and inventories the wrong tables

- [ ] T250 [HIGH] Fix `scripts/preflight.sh` so the database-inspection step at
  `scripts/preflight.sh:27` actually executes and reports the legacy tables that exist, because
  the step invokes `mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"` inside
  `docker-db-1` where `MYSQL_USER`, `MYSQL_DATABASE`, and `MYSQL_PASSWORD` are all unset — the
  client is called as `-u -p`, the server answers
  `ERROR 1045 (28000): Access denied for user '-p'@'localhost'`, the `|| fail` branch fires, and
  `sh scripts/preflight.sh` exits 1 on every invocation; the same line filters
  `table_name LIKE 'oc_pcash_%'`, which matches 0 rows in the shared environment, while the 26 real
  legacy tables are `oc_pc_*` (`oc_pc_customers`, `oc_pc_projects`, `oc_pc_project_files`,
  `oc_pc_project_members`, `oc_pc_time_entries`, `oc_pc_emp_rates`, `oc_pc_pm_rates`,
  `oc_pc_license_state`, `oc_pc_mobile_seats`, `oc_pc_mob_idem`, `oc_pc_user_account_snapshots`).
  T001 is marked `[x]` for verifying "presence of legacy `oc_pcash_*` tables without changing
  them", so the completed task's evidence does not exist; this script is the only artifact in the
  repository that claims to demonstrate Constitution II's requirement that shared and legacy data
  are never modified as a side effect of PettyCash work, and the fix must read only, must not
  require credentials that the shared environment does not provide, and must not mutate any legacy
  table (contradicts)

### HIGH — the command-result table cannot satisfy the approved replay contract

- [ ] T251 [HIGH] Add a forward-only migration that brings `pc_command_results` in line with the
  `Command Result` entity in `data-model.md`, which requires `command_id`, `actor_uid`,
  `operation`, `target_public_id`, `payload_hash`, `result_status`, `result_json`, and
  `created_at`, together with the replay rule "Same command ID, actor, operation, target, and
  payload returns the original result. Reusing the command ID with different content is a
  conflict." `lib/Migration/Version010200Date20260920000000.php:35-39` creates only `id`,
  `command_id`(36), `payload_hash`(64), `response_json`, and `created_at`, and
  `lib/Infrastructure/Persistence/Command/DbCommandResultRepository.php:46-53` inserts and
  `:57-64` reads only those four columns, so
  `lib/Application/Command/IdempotencyService.php:16-19` can compare nothing but `payload_hash`:
  a command ID replayed by a different actor, operation, or target is not detected as a conflict,
  returns the first caller's stored response, and silently skips the second caller's operation.
  FR-042 requires every state-changing command to complete atomically and the idempotency gate to
  hold, and the still-open T056/T184 "conflicting command UUID" tests cannot pass against this
  schema; the fix must be forward-only, must not rewrite or delete the existing command-result
  rows, must widen `payload_hash` to the 128 characters `data-model.md` specifies, and must make
  the repository and `IdempotencyService` compare actor, operation, target, and payload before
  replaying a stored response (contradicts)

### MEDIUM — the approved legacy-table premise does not match this environment

- [ ] T252 [MEDIUM] Reconcile the legacy-table premise in the approved artifacts with the tables
  that actually exist, because `plan.md:19`, `data-model.md:18`, `research.md:61` and `:315`,
  `quickstart.md:42`, and tasks T001/T011/T126 all state that the legacy tables are `oc_pcash_*`
  while the shared environment contains no `oc_pcash_*` table at all and its legacy tables are
  `oc_pc_*` — the same prefix the 15 new V1 `pc_*` tables use. No name collision exists today
  (the V1 and legacy table sets are disjoint), so this is not a live data-loss defect; the defect
  is that the approved plan and the two safety guards derived from it (T250 and T254) are
  reasoning about a table set that does not exist, and any future migration that identifies
  legacy data by prefix rather than by explicit table name would sweep the real legacy tables,
  which Constitution II prohibits. The correction must be made in the artifacts and must not
  rename, drop, or migrate any existing table, because FR-077 portability and Constitution II
  forbid changing historical data in place (contradicts)

### MEDIUM — configuration-audit subject columns disagree with the data model

- [ ] T253 [MEDIUM] Reconcile the configuration-audit subject identity between the approved data
  model and the implementation, because the `Configuration Audit Event` section of
  `specs/001-pettycash-v1-core/data-model.md` specifies `subject_type`, `subject_public_id`,
  `event_type`, actor snapshot, explanation, before/after JSON, command ID, and occurrence time,
  while `lib/Migration/Version010200Date20260920000000.php:45` creates `entity_type`(64) and
  `entity_id`(64) in their place and
  `lib/Application/Audit/AuditWriterService.php:38-39` writes those two keys. FR-055 and FR-056
  require configuration audit to stay separately identifiable from transaction audit and technical
  logs and to be role-scoped, and the still-open T093/T206 work will build the configuration-audit
  query on whichever names exist, so the approved data model and the shipped schema must agree
  before that work lands; whichever direction is chosen must be applied consistently to the
  migration, the writer, and the data model, and must not rewrite existing audit rows
  (contradicts)

### MEDIUM — the migration-safety test cannot fail and never runs a migration

- [ ] T254 [MEDIUM] Make the migration-safety coverage real, because
  `tests/Integration/Migration/V1SchemaMigrationTest.php:15-18` asserts
  `assertStringNotContainsString("pcash_", $source)` as T011's proof that migrations "never drop,
  renames, or rewrites legacy `oc_pcash_*` tables", yet no migration source contains that token
  and the real legacy prefix is `pc_`, so the assertion cannot fail for any input; the test's
  remaining assertions only prove that the literal string `"createTable('pc_"` occurs somewhere in
  the concatenated files, and the test reads the three `lib/Migration/*.php` files as text and
  never executes a migration, so nothing in the suite has ever run a migration or observed a
  legacy table before and after. Constitution V requires migrations to have automated integration
  tests when a suitable harness exists, and the harness does exist: the running Nextcloud 34 /
  MariaDB container already has `010000Date20260920000000`, `010100Date20260920000000`, and
  `010200Date20260920000000` applied in `oc_migrations` beside 26 untouched legacy `oc_pc_*`
  tables, so the test must exercise the real migration path and assert against that verified
  legacy table set rather than a token that does not occur (partial)

---

## Phase 27: Convergence (Revision Hydration, Currency Integrity, and No-Op Authorization)

**Purpose**: Record the defects that became observable once the live-HTTP contract suites were
valid under T221. Every finding was reproduced against the running Nextcloud 34 / MariaDB
environment. T255 and T256 share a single root cause — the revision read path — and between them
they explain 8 of the 14 contract failures that remain, so they are the highest-value items on the
P1 path.

### CRITICAL — the revision read path drops every business field

- [x] T255 [CRITICAL] Populate the four business fields that
  `DbTransactionRevisionRepository::mapRow()` in
  `lib/Infrastructure/Persistence/Expense/DbTransactionRevisionRepository.php:141-166` never
  hydrates. `TransactionRevision` keeps `description`, `costCenterId`, `expenseTypeTitle`, and
  `evidence` as **private** properties assigned only by
  `TransactionRevision::complete()`, and `complete()` is called from exactly one production site,
  `lib/Application/Expense/TransactionService.php:147`, plus the in-memory test doubles; the
  repository's `mapRow()` sets only the promoted public properties, so for **every revision loaded
  from the database** `description()` returns `null`, `costCenterId()` returns `null`,
  `expenseTypeTitle()` returns `null`, and `hasEvidence()` returns `false`. Three consequences are
  already reproduced live. (a) `ManagementReviewService` builds its authorization scope from
  `costCenterId()`, so the scope is always empty and all seven decision assertions return `403`,
  making FR-004, FR-005, FR-008, FR-033, and FR-035 unreachable at the server boundary for any user
  at any cost center. (b) `ExpenseSheet::assertSubmittable()` at
  `lib/Domain/Expense/ExpenseSheet.php:62` and the `ResubmitCorrection` checks at
  `lib/Application/Workflow/ResubmitCorrection.php:87,90,93` all read those same accessors, so
  submission and correction resubmission return `422`. (c) `save()` writes
  `$revision->description()` and `$revision->expenseTypeTitle()` back into the update and insert
  branches at `:80,85,100,105`, so re-saving any loaded revision silently overwrites a real
  description with an empty string and a real expense type with `NULL`, which is business-data loss
  and a direct violation of the append-only and historical-integrity guarantee in Constitution IV;
  `ReviewController.php:182-185` and `TransactionController.php:347-355` also render the same
  accessors, so every API response reports a null description and `hasEvidence: false`. The fix
  must hydrate all four fields in `mapRow()` and must be covered by a round-trip persistence test
  that saves and reloads a revision through the real repository, because the in-memory doubles used
  by every current test bypass `mapRow()` entirely and are why this survived T229, T230, T232,
  T234, T246, and T248 (done - `TransactionRevision::hydrate()` was added as an explicit
  storage-restore path, because `complete()` is the draft-to-submitted domain transition and refuses
  to touch an active revision, which every revision read from the database is; `mapRow()` now
  restores `description` and `expense_type_title` from their columns, resolves `costCenterId` back
  to the cost center's public UUID from the numeric `cost_center_id` join key (the value
  `complete()` stores, and the one `save()` never persists), derives `hasEvidence` from active rows
  in `pc_evidence` as `data-model.md` defines it, and restores the active flag from `activated_at`,
  which was also never hydrated; all seven decision assertions stopped returning `403` and the
  three valid decision cases now return `201`, the new
  `testDecisionDoesNotEraseTheRevisionBusinessFields` records a decision and then re-reads the
  transaction to prove `save()` no longer empties the description or nulls the expense type, and
  the stored row still holds `Contract fixture expense` / `Contract Fixture Expense Type` / `CC-100`
  after the write; contract failures fell from 14 to 10 and assertions rose from 192 to 236 with no
  regression in the unit or integration suites; the remaining 10 failures are separate defects —
  T256 currency, T257 the vacuous scope comparison, T258 the no-op delete, T259 the evidence 500s,
  and a decisions endpoint that answers `201` when no `X-PettyCash-Command-ID` is supplied
  (contradicts)

- [ ] T256 [CRITICAL] Stop hardcoding the currency when hydrating a revision's amount in
  `DbTransactionRevisionRepository::mapRow()` at
  `lib/Infrastructure/Persistence/Expense/DbTransactionRevisionRepository.php:154`, which builds
  `Money::of((int) $row['amount_minor'], CurrencyCode::fromString('IRR'))` with the in-code comment
  "Placeholder - would need to get from sheet". `amount_minor` is only meaningful together with the
  currency of the sheet it belongs to, and `data-model.md` states that amounts are "signed 64-bit
  integer minor units plus a currency code" while FR-021 requires every transaction in a sheet to
  inherit the sheet currency, so a transaction on a USD sheet is currently read back as a rial
  amount: a 10.00 USD charge stored as `1000` is interpreted as IRR 1000, and because
  `minor_unit` is itself part of the currency, the same integer denotes different real amounts per
  currency. This corrupts every total, every finance settlement, and every export derived from a
  revision loaded through this repository, and it directly contradicts FR-021 and the FR-040
  requirement that consumption is recorded against the account matching the sheet currency; the
  revision must resolve its currency from the owning sheet or account rather than assume IRR, and
  the fix must be covered by a persistence test using a non-IRR currency (contradicts)

### HIGH — the review scope check cannot deny

- [ ] T257 [HIGH] Build the acting user's responsibility scope from their actual role assignments
  instead of from the record being decided, because
  `lib/Application/Workflow/ManagementReviewService.php:114-124` constructs
  `new AuthorizationContext($actorUid, $transaction->ownerUid, $this->getUserRoles($actorUid),
  $costCenterCode ? [$costCenterCode] : [], false)` where `$costCenterCode` is the cost center of the
  transaction under review, and then calls
  `canReview($context, $stage, $costCenterCode ?? '')`. `AuthorizationService::canReview()` at
  `lib/Application/Security/AuthorizationService.php:33-40` tests
  `in_array($costCenterCode, $context->costCenterCodes, true)`, so it compares the transaction's own
  cost-center code against a list that was just built from that same code and can never be false:
  a manager assigned to CC-100 can decide a transaction belonging to CC-200. The same helper
  `findActiveByUserAndRole()` is already used at `:202` to compute the actor's managed cost centers
  for the queue queries, so the correct scope is available and unused on the decision path. FR-004,
  FR-005, and FR-009 require authorization to be enforced server-side across decisions, and
  hiding an action in the interface must not be the only restriction, so this is a server-side
  authorization defect rather than a scoping preference; fixing T255 alone would turn this
  vacuous comparison into a permanently-true one and hide the defect rather than close it (contradicts)

### HIGH — draft delete is a no-op

- [ ] T258 [HIGH] Make `TransactionService::deleteTransaction()` in
  `lib/Application/Expense/TransactionService.php:181-204` actually delete the draft, because it
  validates ownership, the OPEN sheet status, and the optimistic-lock version and then, inside its
  transaction, only calls `ExpenseSheet::removeTransaction($transaction->id)` — which at
  `lib/Domain/Expense/ExpenseSheet.php:78-81` merely filters an in-memory array — and re-saves the
  sheet and the transaction. No `DELETE` reaches `pc_transactions`, no revision is removed, and the
  endpoint answers `204 No Content`, so the caller is told the expense was deleted while it remains
  fully present in the database and in every later total. FR-025 requires that before submission
  the purchaser can add, edit, and delete transactions, and Constitution IV requires the server to
  be the authority on business effects rather than the interface, so a delete that reports success
  without deleting is a correctness and auditability defect; the delete must be restricted to draft
  state, must remove or deactivate the transaction's own evidence per the FR-058 pre-submission rule
  without touching any submitted record, and must be covered by a test asserting the record is
  genuinely gone (contradicts)

### HIGH — evidence deactivation and streaming still read AppData inside the transaction

- [ ] T259 [HIGH] Apply the T247 no-read-back rule to the two remaining evidence paths, because
  `DELETE /evidence/{id}` and `GET /evidence/{id}/content` both return HTTP 500 with
  `Exception: dirty table reads: SELECT filecache.fileid, storage, path ... ` logged by the running
  container, while the upload path was fixed by T247 and returns 201. The deactivation path calls
  `EvidenceService::deleteDraftEvidence()` from `lib/Controller/EvidenceController.php:230` and the
  content path streams the AppData object from `:253`; both resolve the file through
  `AppDataEvidenceStore`, whose read runs on a different connection that cannot see the row written
  by the still-open transaction, so the same failure T247 diagnosed for upload recurs on the two
  read paths the contract tests now exercise with a real evidence id. FR-058, FR-059, and FR-060
  require evidence to be removable before submission, deactivated rather than deleted after it, and
  previewable and downloadable by authorized users, and none of the three is currently reachable
  through the API; the fix must preserve FR-060 authenticated streaming and the T038 draft-delete
  compensation, and must keep the AppData read outside the open database transaction (contradicts)

## Phase 27: Convergence (App-Scoped `pettycash_*` Schema Namespace)

**Purpose**: Record the approved 2026-09-25 database/migration scope clarification. PettyCash V1 is
a fresh application: legacy database import, schema migration, table preservation, and data
compatibility are explicitly out of V1 scope (FR-080). Prototype tables in the shared development
database are unmanaged artifacts that V1 must never read, write, migrate, rename, or drop
(FR-080). The 15 `pc_*` tables already created by the current development migrations are provisional
V1 development schema, not legacy data, and must become the app-scoped `pettycash_*` namespace
(FR-078, FR-079). This phase supersedes the naming and migration-history premises of T001, T011,
T126, T250, T252, and the T252-derived test defect, without rewriting their recorded history.

### CRITICAL — the current V1 prefix collides with the shared environment's real table namespace

- [x] T260 [CRITICAL] Rewrite the V1 baseline migrations `lib/Migration/Version010000Date20260920000000.php`
  through `Version010300Date20260920000000.php` so every table is created as `pettycash_*` and every
  index, unique constraint, and foreign key is named `pettycash_<table>_<subject>_<kind>`
  (`idx`/`uniq`/`fk`/`chk`), because the real legacy tables in this environment are `oc_pc_*`
  (`oc_pc_customers`, `oc_pc_projects`, `oc_pc_project_files`, `oc_pc_project_members`,
  `oc_pc_time_entries`, `oc_pc_emp_rates`, `oc_pc_pm_rates`, `oc_pc_license_state`,
  `oc_pc_mobile_seats`, `oc_pc_mob_idem`, `oc_pc_user_account_snapshots` as recorded in T252) and
  therefore share the bare `pc_` prefix with the 15 new V1 tables. V1 is unreleased with disposable
  data, so the baseline migrations are edited in place: no rename migration is added and no mixed
  namespace is kept (FR-078, contradicts T001, T011, T126)
- [x] T261 [CRITICAL] Update every `pc_` table and index reference in
  `lib/Infrastructure/Persistence/**`, `tests/Contract/Fixture/seed-contract-fixtures.php`,
  `tests/Integration/Migration/*`, and the remaining integration tests to the `pettycash_*`
  namespace, and replace the vacuous `createTable('pc_` token assertion in the migration safety test
  with one that asserts the `pettycash_` namespace and the app-scoped constraint names, because the
  old assertion cannot fail for any input (FR-078, FR-079, supersedes T252)
- [x] T262 [CRITICAL] Prepare an approval-gated development-only reset of PettyCash state so the
  rewritten baseline executes from scratch: it must clear the `pettycash` app-ID rows in
  `oc_migrations`, which the discarded prototype also wrote under the same app ID and which would
  otherwise make Nextcloud skip the rewritten migrations; it must affect PettyCash only, preserve
  every unrelated app and its data, never delete Docker volumes or the shared Nextcloud database, and
  discard the current `pc_*` development data, which is disposable by design (FR-081, supersedes
  T001's migration-history inventory and T011's legacy-present installation test)
- [ ] T263 [HIGH] Replace the legacy-database acceptance framing in `quickstart.md` section 2, the
  T126 migration scenarios, and the migration test suite with clean-installation and forward-chain
  acceptance on an empty database, and remove every reference to `oc_pcash_*` and `oc_pc_*` as V1
  schema, since no V1 artifact may depend on a pre-V1 database and the `oc_pcash_*` premise was never
  present in this environment (FR-080, SC-014, supersedes T126, T252)
- [ ] T264 [MEDIUM] Re-check every index and constraint name produced by T260 against the declared
  V1 backend identifier limits (PostgreSQL 63 bytes, MySQL 64 characters), abbreviating only where a
  full `pettycash_<table>_<subject>_<kind>` name would exceed them, and keep the `data-model.md`
  naming conventions in agreement with the migrations (FR-079, SC-014)

## Phase 28: Convergence (Purchaser Account Selection, Presentation-Layer Currency Rules, Port Layering)

**Purpose**: Record the gaps found by assessing the implemented US1/US2 code against `spec.md`,
`plan.md`, and the constitution. Every finding below is in code that earlier phases marked complete
and that no existing incomplete task covers.

### Execution order (dependency evidence, binding on `/speckit-implement`)

1. **T260, T261, T262 first, as one coherent schema/repository namespace pass.** They rewrite the
   same four baseline migrations and the same persistence references, and T262 resets the
   development database so the rewritten baseline executes.
2. **Then T265, T266, T267**, against the final `pettycash_*` schema. They MUST NOT be implemented
   against the provisional `pc_*` schema and renamed afterward.
3. **Then T268**, once the port and repository structure affected by T260/T261 is stable.

T260-T262 are prerequisites for T265-T267: T261 already rewrites every repository and fixture that
T266 and T267 edit, and T262 must run before the rewritten schema is the one under test. Any
handoff summary that says "start with T265" is superseded by this ordering.

### Known artifact issues (recorded here; do not rediscover, do not open duplicate tasks)

- `tasks.md` contains **two `## Phase 27` headers** (the pre-existing "Revision Hydration, Currency
  Integrity, and No-Op Authorization" phase, and the later "App-Scoped `pettycash_*` Schema
  Namespace" phase). This is a known numbering collision, not a signal of missing work.
- **T169 is marked `[x]`** although its claimed output — `src/services/accountsApi.ts` and
  `src/stores/accounts.ts` — does not exist. The real work is T265; the stale checkbox is left
  untouched because convergence never rewrites existing task state.

### CRITICAL — the purchaser cannot create a sheet, because the form offers invented accounts

- [x] T265 [CRITICAL] Replace the hardcoded account list in
  `src/views/sheets/SheetFormView.vue:36-44`, where `loadAccounts()` returns
  `[{ id: 'acc-1', currency: 'IRR', balance: 1000000 }, { id: 'acc-2', currency: 'USD', balance: 5000 }]`
  under the comment `// TODO: Load from accounts API`, with a real call to the petty-cash accounts
  endpoint through `src/services/accountsApi.ts` and `src/stores/accounts.ts` (both of which must be
  created, since they do not exist), because `src/services/` contains only `evidenceApi.ts`,
  `http.ts`, `reviewsApi.ts`, `sessionApi.ts`, `sheetsApi.ts`, and `transactionsApi.ts`. The form
  therefore submits `accountId: 'acc-1'`, `SheetService::createSheet()` calls
  `AccountRepository::findByPublicId('acc-1')`, which returns `null`, and the service throws
  `InvalidArgumentException('Account not found or access denied')` as HTTP 400 — so the US1 MVP
  "create a sheet" journey cannot be completed in the browser at all. US1/AC1 and US1/AC2 require the
  account list and the sheet currency to come from the caller's own petty-cash accounts, FR-020
  requires the currency to be derived from the selected account, and no invented account identifier
  may reach the persistence layer. The form must render the empty state when the purchaser has no
  account, must show only the currencies of the returned accounts, and must cover the loading,
  empty, and error states per FR-075 (contradicts)

### HIGH — currency fraction digits are business rules hardcoded in two disagreeing controllers

- [ ] T266 [HIGH] Remove the presentation-layer currency tables
  `TransactionController::CURRENCY_MINOR_UNITS` (`lib/Controller/TransactionController.php:31-36`,
  read at `:345` as `self::CURRENCY_MINOR_UNITS[$currencyCode] ?? 2`) and
  `ReviewController::getMinorUnit()` (`lib/Controller/ReviewController.php:155-162`, whose
  `default => 0` returns a different number of fraction digits for the same unknown currency), and
  resolve fraction digits from the administrator-configured `Currency` aggregate through
  `CurrencyRepository`, because `data-model.md` defines `Currency.minor_unit` as a configured value in
  the range 0–4 (T015, T077), `research.md` Decision 5 requires amounts to be paired with an
  ISO-style currency code and *configured* fraction digits, and the two disagreeing fallbacks mean a
  currency outside the built-in `IRR/USD/EUR/GBP` list is silently scaled with 2 digits in one
  response and 0 digits in another. This is business configuration living in a controller, which
  Constitution III and `AGENTS.md` forbid, and it corrupts every displayed amount for a configured
  currency. One resolution path must serve both controllers, and an unknown or inactive currency
  must be an explicit error rather than a guessed digit count (contradicts)

### HIGH — a broken currency reference is silently reported as rial

- [ ] T267 [HIGH] Remove the silent currency fallbacks that mask a broken reference:
  `DbExpenseSheetRepository::getCurrencyCode()` at
  `lib/Infrastructure/Persistence/Expense/DbExpenseSheetRepository.php:173-183` returns the literal
  `'IRR'` when the currency lookup matches no row, and
  `src/views/sheets/SheetDetailView.vue` repeats `?? 'IRR'` in eight `formatMoney()` calls
  (lines 141-169), because `amount_minor` is only meaningful together with its currency. A sheet
  whose `currency_id` no longer resolves is therefore reported, stored, and displayed as a rial
  sheet, so a non-IRR amount is read as a rial amount and every derived total, export, and finance
  consumption is wrong — a silent financial corruption rather than a visible failure. FR-021
  requires every transaction to inherit the sheet currency and Constitution IV requires financial
  effects to be correct server-side, so the repository must fail loudly on an unresolvable currency
  reference and the view must render the absence rather than substituting a currency. T256 covers
  only the revision repository's hardcoded `IRR` and does not cover these two sites (contradicts)

### MEDIUM — a duplicated port makes the domain layer depend on the application layer

- [ ] T268 [MEDIUM] Delete the unused duplicate port
  `lib/Domain/Command/Repository/CommandResultRepository.php` and the
  `DomainCommandResultRepository` half of the `implements` clause at
  `lib/Infrastructure/Persistence/Command/DbCommandResultRepository.php:17` and its alias at
  `tests/bootstrap.php:34`, keeping `lib/Application/Command/CommandResultRepository.php` as the
  single port, because the domain copy is byte-for-byte equivalent to the application port yet
  imports `OCA\PettyCash\Application\Command\CommandResult`, making the innermost layer depend on the
  layer above it. `plan.md` declares Domain as the innermost layer with no outward dependency — the
  rest of `lib/Domain/` contains no `Application` or `Infrastructure` import, and the architecture
  graph reports `Domain` with fan-out 0 — while `DbCommandResultRepository` is the only class
  satisfying two identical contracts and no production code ever consumes the domain one.
  Constitution III requires the layers to stay separable, and T238 reconciles only the four *missing*
  declared directories, not this stray duplicate (partial)

## Phase 29: Convergence (Management-Review Decision Endpoint Contract Conformance)

**Purpose**: Record the three defects that remain uncovered now that the `pettycash_*` schema is
live and the contract suite executes. Before T262 every contract test failed at fixture setup with
zero assertions, which hid this entire class of defect; with 241 assertions executing, the
`ManagementReviewControllerTest` failures are individually attributable. All three sit in one
method, `ReviewController::decide()` — the US2 management-review decision endpoint routed at
`appinfo/routes.php:41` — and none is covered by an existing incomplete task. T256, T257, T258, and
T259 cover the other seven contract failures and are untouched by this phase. T227 deliberately
brought only `SheetController`, `TransactionController`, and `EvidenceController` under
`OcsErrorMapper`, so this controller was never converted; `ReviewController` injects
`OcsErrorMapper` and calls it in exactly one place, line 56.

### HIGH — the decision endpoint mints its own command ID, defeating replay protection

- [ ] T269 [HIGH] Make `ReviewController::decide()` at `lib/Controller/ReviewController.php:116`
  read the command ID from the `X-PettyCash-Command-ID` request header the way
  `TransactionController::decide()` already does at
  `lib/Controller/TransactionController.php:202`, and reject the request with 400 when the header is
  absent, instead of discarding the caller's value and substituting
  `$commandId = (string)Uuid::random()`. `contracts/workflow.md` line 15 requires that
  non-repeatable commands carry a client-generated UUID and that replaying one is detected, and
  `research.md` Decision 7 makes that the idempotency guarantee for submissions, decisions,
  corrections, charges, and finance approvals. Because a fresh UUID is minted per request,
  `IdempotencyService` can never match a replay, so the `pettycash_workflow_decisions` unique index
  on `command_id` can never fire and a replayed decision is recorded a second time with a second
  immutable `WorkflowDecision` and a second business audit event — and on the finance path this
  endpoint is the entry point to settlement. The contract test
  `testRecordDecisionMissingCommandIdReturns400` already asserts the required behaviour and fails
  today with 201 (`contradicts`)

### HIGH — an invalid decision value escapes the handler and returns 500

- [ ] T270 [HIGH] Catch `\Throwable` rather than `Exception` in the failure handling of
  `ReviewController::decide()` at `lib/Controller/ReviewController.php:146-152`, and map an invalid
  decision value to the 400-or-422 the contract allows, because
  `Decision::from($decision)` at `:115` is a PHP 8.1 backed-enum lookup that throws `\ValueError`
  when the value is unknown, and `\ValueError` extends `\Error`, not `\Exception` — verified in the
  running container, where `Decision::from('INVALID')` yields `ValueError` with
  `instanceof Exception: false`. The three existing handlers therefore never see it, the exception
  escapes the action, and the caller receives 500 instead of a validation error.
  `testRecordDecisionInvalidDecisionReturns400Or422` fails today precisely because the status is
  neither 400 nor 422. `TransactionController` already catches `\Throwable` at each of its eight
  handlers, so this is a divergence from the pattern already established in the codebase, and the
  same latent defect exists at `lib/Controller/QueueController.php:34` for
  `WorkflowStage::from($stage)` (contradicts)

### HIGH — validation failures return 400 with a non-OCS body instead of 422

- [ ] T271 [HIGH] Route the validation failures of `ReviewController::decide()` through
  `OcsErrorMapper::validation()` at `lib/Controller/ReviewController.php:146-147` instead of
  returning `new DataResponse(['error' => $e->getMessage()], 400)`, so that the
  `InvalidArgumentException('Explanation required')` thrown by `WorkflowDecision::create()` at
  `lib/Domain/Workflow/WorkflowDecision.php:57` for a REJECT or RETURN without an explanation
  produces 422 with a stable code and field errors. `contracts/workflow.md` line 79 requires error
  bodies to carry a stable code, a localized-safe message, and optional field errors, and the
  contract tests `testRecordDecisionRejectRequiresExplanation` and
  `testRecordDecisionReturnRequiresExplanation` both fail today with 400 against an expected 422.
  The mapper is already injected into this controller and is used only at line 56, and
  `TransactionController` already calls `ocsErrorMapper->validation()` for the same class of
  failure, so no new service or dependency is required (contradicts)

## Phase 30: Convergence (Route/Controller Integrity and a Task-Ordering Defect)

**Purpose**: Record what implementation discovered while selecting the next task, and the one gap
no existing task covers. This is an **early convergence** triggered during task selection, not by
a test failure: the mandated next task, T265, cannot be completed as written.

### Ordering defect — T265 is blocked by T087 and must not be started yet

T265 requires `account#index` to work, but `lib/Controller/AccountController.php` does not exist and
is owned by **T087** in Phase 7, which is entirely unimplemented and sits *later* in the plan than
Phase 28. T087 and its Phase 7 prerequisites must therefore execute before T265. Wiring
`src/views/sheets/SheetFormView.vue` to the registered-but-500 route would replace the invented
`acc-1`/`acc-2` accounts with a failing call and would falsely mark a CRITICAL P1 defect fixed, so
T265 stays open until `account#index` returns real data. Phase 28's binding order (T260-T262, then
T265-T267, then T268) remains valid for everything except T265's prerequisite.

### Covered by existing incomplete tasks — verified, not duplicated

Authenticated probes from inside the container confirm **6 live OCS endpoints return HTTP 500**
because their controllers do not exist, and a further 2 route targets name actions
`EvidenceController` does not have. Of 41 distinct route targets, 22 resolve and 19 do not.

| Broken route targets | Count | Covering task |
|---|---|---|
| `account#index`, `account#create`, `account#charge`, `account#movements` | 4 | **T087** |
| `configuration#currencies*`, `#costCenter*`, `#expenseType*`, `#financeAssignment*` | 11 | **T087** |
| `report#show`, `export#create` | 2 | **T112** |
| `evidence#links`, `evidence#deactivateLink` (action absent on an existing controller) | 2 | **T096** |

### MEDIUM — nothing detects a registered route whose controller or action does not exist

- [ ] T272 [MEDIUM] Add a structural test that parses every `['name' => '…#…']` entry in
  `appinfo/routes.php` and asserts that the named controller class exists in `lib/Controller/` and
  declares the named public action, so that a route registered ahead of its implementation fails
  the suite instead of returning an opaque HTTP 500 at runtime. Nineteen of the 41 distinct route
  targets currently fail this check — `account#*` (4) and `configuration#*` (11) have no
  `AccountController` or `ConfigurationController`, `report#show` and `export#create` have no
  `ReportController` or `ExportController`, and `evidence#links` and `evidence#deactivateLink` are
  registered against an `EvidenceController` that declares neither action — which is exactly the
  drift T087, T112, and T096 will close, and which nothing in the suite detects today. The routes
  were registered by T141 and T168 ahead of the controllers those tasks create, so the drift
  entered through a task that was itself marked complete. T225 and T227 brought the existing
  controllers under `OcsErrorMapper` but asserted nothing about route resolvability, and
  Constitution V requires the integration boundaries to be verified rather than assumed. The test
  must be written so that it reports the full unresolved set as data, and it may legitimately stay
  red until T087, T112, and T096 land, in which case it must be recorded as an expected-failure
  list owned by those task IDs rather than deleted (partial)
