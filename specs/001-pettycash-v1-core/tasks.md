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
- [ ] T028 Implement transaction and configuration audit writers that snapshot UID, display name, role, stage, revision, explanation, command ID, and canonical before/after JSON in `lib/Application/Audit/` (**reopened by T295 — the configuration writer this task claims does not exist and never ran.** Only the transaction side is real: `AuditWriterService::append()`, the method the `AuditWriter` port declares and the only one any of the six production call sites uses. The configuration side was `appendConfiguration()`, it had **zero** call sites, and `DbAuditEventRepository::save()` targets `pettycash_transaction_audit` unconditionally, so even if it had been called it would have written a configuration event into the *transaction* stream, against FR-056. `grep -rn pettycash_configuration_audit lib/ --include=*.php` returns the migration and nothing else, so the table is created and permanently empty. T296 has since deleted the dead method, leaving no configuration writer at all; **T294** owns writing one that reaches the right table)
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
- [ ] T053 [US2] Implement cost-center and senior queue views, transaction review detail, decision form, and prior-decision history components in `src/views/reviews/` and `src/components/review/` (all elements visible without horizontal scroll at 390px viewport; status color/icon WCAG AA contrast; text ≥12pt Persian font per FR-071) (**reopened by T286** — the checkbox was checked while 15 of the tests this task claims to satisfy failed, which Constitution V does not allow. The US2 behaviour is now delivered and verified: `ManagementReview.spec.ts` is 23/24, the one failure being `FinanceQueueView`, which T287 reclassifies as US3. **What still prevents this being complete is its last three clauses, none of which jsdom can verify**: "no horizontal scroll at 390px" needs a layout engine, WCAG AA contrast needs font metrics, and the ≥12pt floor as a *measurement* needs a real browser. What can be checked in their place is now in place — declared mobile breakpoints, the absence of any `min-width` forcing the viewport wider, status conveyed as text rather than colour alone, and a per-component floor check on the declarations — but a declaration is not a rendering. Those clauses belong to `T116`'s test file and to `T122`/`T123`'s browser and manual matrices, and until one of those runs this task must not be checked again. One product defect was fixed along the way and is recorded in T286: `DecisionForm.vue` passed an `error-message` prop that `@nextcloud/vue` 9.x does not have, so the mandatory-explanation message was never rendered)
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
- [X] T056 [P] [US3] Write failing integration tests for fixed row-lock order, exactly-one consumption, command replay, conflicting command UUID, simultaneous approvals, and rollback of decision/movement/balance/state/audit failures in `tests/Integration/Workflow/FinanceSettlementTest.php` (done — 24 tests / 116 assertions, plus 4 matrix tests in `FinanceDecisionPolicyTest`; **all six named cases are covered and each is proven load-bearing by a mutation that was verified to have applied before its result was read**, which is the discipline T286 had to learn twice)

  **The tests found three defects that no existing test could see, and the first of them means
  User Story 3 could not complete at all.** `TransactionStatus::SeniorApproved` was declared in the
  enum and read by `FinanceDecisionPolicy::canFinanceApprove()`, and **nothing in the codebase ever
  assigned it**: `ExpenseTransaction::applyDecision()` set `AwaitingFinanceReview` on a senior
  approval instead. So every senior-approved transaction arrived at finance in a state nobody was
  allowed to approve, and the refusal named the wrong cause — "Finance cannot approve a
  senior-rejected transaction" for a transaction the senior had approved. FR-038, FR-040 and FR-043
  were unreachable, which is why the missing consumption movement looked like a missing feature
  rather than a dead stage. `contracts/workflow.md` settles the intent: it gives finance two rows
  keyed on the senior's *outcome*, and the cost-centre branch already records outcome-named
  statuses, so the senior branch now records `SeniorApproved` too. `findForFinanceQueue()`
  selected on `AWAITING_FINANCE_REVIEW` — a state no code path produced — which also hid every
  senior-**rejected** transaction, leaving FR-039's "finance may reject it or return it" with
  nothing to act on; it now selects both senior outcomes.

  **FR-039 was broken in two halves, and the second was user-visible.** `recordDecision()` called
  `assertCanApprove()` **unconditionally**, asking only "may finance approve this?" and applying
  the answer to whichever decision was requested — so a senior-rejected transaction could not be
  rejected or returned either, and a purchaser whose expense was refused at senior stage could not
  be sent back to correct it. And `TransactionPayloadFormatter::allowedActions()` set `canApprove`,
  `canReject` and `canReturn` all to `true` whenever the actor could decide at all, never consulting
  the policy, so the interface offered finance an **Approve button that the command path then
  refused** — the precise disagreement that method's own docblock claims cannot happen, and which no
  test covered, because the only `allowedActions` in the suite were frontend stubs. Both now consult
  one decision-aware policy, and a test asserts the offered set equals the permitted set for both
  senior outcomes and all three decisions.

  **`FinanceSettlementService` never took the lock the workflow contract requires.**
  `NextcloudTransactionManager::lockInSettlementOrder()` existed and was **absent from the
  `TransactionManager` port**, so it was unreachable from the application layer and called from
  nowhere — an unreachable lock is not a lock. It is now on the port, and the settlement takes
  sheet → transaction → revision → account in that fixed order, re-reading the account after the
  lock because the account is the row most likely to be contended and it has to be read first to
  learn its key.

  **`InMemoryAccountMovementRepository` could not support any of this.** It keyed movements on
  `(int) $movement->id`, but that id is a public UUID string, so **every movement collapsed onto key
  `0`** and the second silently replaced the first; `findBySourceRevisionId()` compared a `?string`
  against its own `int` parameter with `===`, so it could never match; and `findByAccountId()`
  filtered on `$m->id === (string)$accountId`, comparing a movement id to an account id. FR-040's
  uniqueness is exactly what a per-revision lookup has to answer, and it always answered "not
  found" — indistinguishable from correct behaviour while nothing asserted against it. Note that a
  *count* of 1 does not catch the collapsed keying, since one row still reads as one; the test that
  catches it settles two revisions and asserts two rows survive, and that is asserted separately.

  **Six mutations, each verified to have applied before its result was read.** Removing the ledger
  write fails 8; removing the lock fails 2; reverting `SeniorApproved` fails **17 of 24**, which is
  the measure of how dead the stage was; `assertCanDecide` → `assertCanApprove` fails the FR-039
  rejection; restoring the double's `(int)` keying fails the two-revision test with *"actual size 1
  matches expected size 2"*; and restoring the all-true capability set fails with *"a
  SENIOR_REJECTED transaction offered to APPROVE: offered=true, permitted=false"*.

  **One claim is deliberately not made.** The rollback case asserts that the injected ledger failure
  propagates, that no movement is committed, and that **no command result is stored** — the last
  being load-bearing, because a result written before the work would turn the retry into a no-op
  reporting success for a settlement that never happened. It does **not** assert a restored balance
  or transaction row: the in-memory `TransactionManager` runs the callback and returns, with no
  snapshot to restore, so such an assertion would be claiming a property of MariaDB that this
  harness does not have. That belongs to the real-schema work. For the same reason the
  pre-existing `AtomicityFailureInjectionTest::testFinanceSettlementRollbackOnFailure` is not
  evidence of anything — it submits a sheet, never fails anything, and asserts the sheet status.
- [X] T057 [P] [US3] Write failing contract tests for shared finance queue and finance decision responses in `tests/Contract/Controller/FinanceReviewControllerTest.php` (complete — 21 tests, and **three product defects it found were fixed in the same pass**, one of which made the finance approval path unreachable over HTTP; report `docs/reports/T057.md`)

  **The `finance` fixture state is the precondition, and it did not exist.** `ManagementReviewControllerTest::testFinanceQueueReturns200WithPagedTransactions`
  asserted the finance queue against a fixture in which no transaction awaited finance review, so an
  empty queue satisfied it — a queue scoped per cost center, a queue filtered on the wrong status, and
  a queue that returned nothing at all were indistinguishable. `tests/Contract/Fixture/seed-contract-fixtures.php`
  now seeds a sixth state, `finance`, holding **three** transactions at the finance stage, each for a
  different claim: the primary trio senior-approved; one in CC-200, which the acting user does not
  manage (so a shared queue is told from a scoped one — the user manages CC-100, so a scoped queue
  would have looked right); and one the senior manager rejected (so FR-039's matrix is drawn from the
  senior outcome rather than assumed). Each carries the two management decisions that put it there,
  as rows, because the decision history finance reads to act on (FR-036) has to exist to be read. The
  user additionally holds a `FINANCE` role, scoped to no cost center, and it is the *only* state that
  grants it — a role gate cannot be tested by a fixture that always passes it.

  **The three defects, in the order the suite found them.**

  1. **No workflow decision was ever persisted.** `ManagementReviewService::recordDecision()` and
     `FinanceSettlementService::recordDecision()` each carried a `// Persist transaction and decision`
     comment above a call to `ExpenseTransactionRepository::save()` — which writes the transaction row
     and nothing else. `DbWorkflowDecisionRepository` was registered, injected into the payload
     formatter for reads, and **never called for a write by any code path**. So the transaction's
     status advanced while `pettycash_workflow_decisions` stayed empty: the immutable history an
     approver reads back was not there at any stage, and the audit event's `decision_id` was null. Both
     services now persist the decision through the domain port, before the audit event so that
     `decision_id` is a real surrogate key.

  2. **The finance approval answered 422 for every input.** `DbAccountMovementRepository::save()`
     resolves the movement's `source_decision_id` from the decision's public UUID, and the consumption
     was recorded *before* the decision was saved — so the resolution failed against a decision that
     did not exist yet. Two defects stacked here: the missing save above, and the order. With the save
     in place, a second one appeared: `PettyCashAccount::consume()` built the movement's public id as
     `$this->id . '-' . $commandId`, which is 73 characters for two UUIDs, in a 36-character column —
     so the insert failed with "Data too long", and `charge()` had the same construction, meaning the
     unbuilt `account#charge` endpoint would have failed identically. A UUID is minted instead; the
     command id is stored in its own column, which is what a replay already looks up.

  3. **The decision response was not a `DecisionResult`.** The contract answers
     `POST /transactions/{id}/decisions` with the decision that was recorded, the transaction, and
     `accountMutation`; the controller answered with a bare `TransactionDetail`. So the decision just
     taken against an immutable history was absent from the response and the money that moved was
     absent too — and `src/stores/reviews.ts:93` reads `result.transaction`, so the finance review view
     replaced the transaction it was showing with `undefined` after **every** decision. Both services
     now return a `DecisionOutcome` (transaction, decision, and the movement/account when money
     actually moved), and `ReviewController` builds the contract's shape, omitting `accountMutation`
     for a reject, a return, and every non-finance stage rather than emitting an empty one.

  **Two smaller ones, both found by the same suite.** `fieldErrorsFor()` matched `'explanation'` with
  `str_contains` against messages that start with a capital "Explanation", so the one field error it
  exists to attach never fired and a missing explanation came back as a bare 422 with no field to
  highlight; it matches case-insensitively now. And the finance branch of `ExpenseTransaction::applyDecision()`
  set the status and the final outcome but never advanced the stage, so a settled transaction reported
  `stage: FINANCE` beside `status: COMPLETED_APPROVED` — decided while still saying it awaits the
  decision, and keeping a sheet of decided transactions from reaching its own completed state.

  **A replayed finance decision was answered 409.** The idempotency payload included the workflow
  stage, and the controller routes a decision by the transaction's *current* stage — which the first
  attempt had already advanced. So a client retrying after a timeout hashed a different payload and was
  told its command had been reused for different work, when it had in fact been applied. The stage is
  not part of what a command means and is out of both payloads; the transaction and the decision are
  still there, so a genuine reuse is still a conflict. **Known limitation, recorded as T290:** the
  stored command result predates `accountMutation`, so a replay reports the decision and the transaction
  and not the account effect.

  **The ledger fixture was claiming a settlement that had not happened.** The seeded consumption named
  the *working* revision, and `pettycash_account_movements_consumption_uniq` is unique on
  `source_revision_id` — which is what makes FR-040's "exactly one consumption" an enforced fact, and
  which also means a revision the ledger already claims can never be settled. The finance approval
  failed on a duplicate key. The settled expense is now its own sheet, transaction, and revision at
  FINAL, out of every queue, and the seeder deletes settlement-written movements on every state so the
  ledger does not depend on whether the finance suite ran first.

  **Six mutations, each verified to have applied before its result was read**: removing the decision
  save fails 9, restoring the composed movement id fails 9, dropping `accountMutation` fails 3,
  restoring the stage in the payload fails 1 (the replay), `str_contains` fails 1, dropping
  `stage = Final` fails 1. `scripts/dev/phpunit` is **255 tests / 1367 assertions / 1 failure**, the
  failure being T275 alone.
- [X] T058 [P] [US3] Write failing Vue tests for the shared queue, senior-decision restrictions, settlement feedback, and manual retry messaging in `tests/Frontend/Component/FinanceReview.spec.ts` (complete — 25 tests covering all four subjects; landed with T287 and the US3 slice, `docs/reports/US3-finance-slice.md`. The "failing" in the task text is the RED convention: they were written and mutation-checked, and pass against the implementation built alongside them)

### Implementation for User Story 3

- [x] T059 [P] [US3] Implement immutable AccountMovement domain behavior with `positive magnitude; type determines sign`, exact `balance_after_minor`, unique command ID, and unique consumption source in `lib/Domain/Account/AccountMovement.php`
- [ ] T060 [US3] Implement account locking, movement insertion, balance projection, and reconciliation repositories in `lib/Infrastructure/Persistence/Account/DbAccountRepository.php` and `lib/Infrastructure/Persistence/Account/DbAccountMovementRepository.php` (**reopened by the US3 slice** — `DbAccountMovementRepository` read and wrote a `currency_code` column the schema has never had, so it could not move a single row in either direction. Repaired here; **movement insertion remains unverified and `findBySourceRevisionId` is still untested**, so this stays open) (**partly advanced by the T056 pass** — **movement insertion and `findBySourceRevisionId` are now written and verified**; account locking is on the port and taken by the settlement. **Still open: the reconciliation repository**, and it has nothing to implement against — `AccountRepository` declares no reconciliation method and neither adapter has one, so this is a design decision about what reconciliation means here rather than a missing class. `scripts/dev/phpunit` is 229 tests / 1137 assertions / 1 failure, the failure being T275 alone)

  **The write path was corrupting the ledger rather than merely missing it.**
  `DbAccountMovementRepository::save()` cast the movement's `sourceRevisionId` to `(int)`, but the
  aggregate carries the revision's **public UUID** and `contracts/ocs-api.yaml` declares
  `sourceRevisionId` as `format: uuid`. A UUID is not numeric, so the cast produced `0` — a valid
  key pointing at some *other* revision, colliding on `pettycash_account_movements_consumption_uniq`
  with every other consumption. That is silent corruption rather than a failure. It also hard-coded
  `source_decision_id => null`, which `data-model.md` marks "Required for consumption": a ledger row
  said money was spent without recording on whose authority. Both public references are now
  **resolved** to surrogate keys and unresolvable ones abort rather than defaulting, and
  `mapRow()` projects the decision's public UUID back so the round trip is symmetric.
  `findBySourceRevisionId()` is now covered from both directions, and the port's signature was
  corrected from `int` to `int|string` because the surrogate form it declared was not the form the
  domain or the contract actually uses — a signature that admits only the surrogate form cannot be
  called correctly with the value the domain holds.
- [X] T061 [US3] [FR-041] Implement finance settlement orchestration that atomically writes decision, unique movement, balance, transaction final state, sheet final state, command result, and audit in `lib/Application/Workflow/FinanceSettlementService.php` (**reopened by the US3 slice** — `recordConsumption()` updates the account balance and **never calls `$movementRepository->save()`**. The repository is injected and used nowhere in the class, so FR-040's consumption movement is never written: a finance approval moves money without leaving a ledger entry, and the unique `source_revision_id` index that T056's "exactly-one consumption" relies on is never exercised) (done — all seven named effects are written and verified inside one transaction, and the fixed lock order `contracts/workflow.md` requires is taken. Advanced across two passes: the T056 pass wrote the decision, unique movement, balance, transaction final state, command result and audit; the T061 pass closed **sheet final state**, which could not be fixed by calling the method that already existed)

  **`recordConsumption()` now writes the movement.** `PettyCashAccount::consume()` already *returned*
  the `AccountMovement` it built; the return value was discarded, so the ledger row was constructed
  and thrown away while only the balance was persisted. It is now saved inside the caller's
  transaction, so the ledger and the balance move together or not at all. The source it records was
  also wrong: the **decision** id was being passed as the source *revision*, and the revision was
  passed as neither. Both are now carried, which is what `data-model.md` requires for a consumption
  and what makes FR-040's row traceable to a disputable question.

  **Why the missing movement was never noticed, and why it was the wrong thing to fix first.**
  `TransactionStatus::SeniorApproved` had no producer, so `assertCanApprove()` refused every
  settlement and the write below it never executed. T056 found that; the details are recorded there.
  The lesson is the order: a missing ledger write looks like an unimplemented feature, and it was
  actually the second defect behind an unreachable stage.

  **`sheetRepository` is injected and used nowhere except the lock.** `contracts/workflow.md`
  requires the settlement to create "recalculated sheet state", and FR-043 requires a sheet to
  become completed once every transaction has a final financial outcome. `ExpenseSheet` already has
  the logic that does it (it maps the earliest outstanding stage and sets `Completed` or
  `CompletedWithRejections`), and the settlement never calls it — so a sheet whose last transaction
  is settled stays at its previous status. That was the remaining piece, and it is now closed, so all seven effects this task names are
  written and verified: decision, unique movement, balance, transaction final state, sheet final
  state, command result, and audit, inside one transaction.

  **FR-043 could not have been fixed by calling `recalculateStatus()`.** A sheet loaded from either
  repository carries **no transactions** — neither `DbExpenseSheetRepository` nor the in-memory
  double hydrates the collection — so the aggregate's own list is empty and `recalculateStatus()`
  returns without doing anything. Calling it on a freshly-loaded sheet is a silent no-op, which is
  very likely why the omission went unnoticed: the method looks like it does the job. Reusing
  `addTransaction()` is not an option either, because it refuses a sheet that is not open and by
  settlement time the sheet is long submitted. `ExpenseSheet::recalculateStatusFrom(array)` was
  added so the caller states which transactions it means, and the settlement loads them.

  **The empty-set guard is a safety property, and it is worth naming because a mutation showed it
  was uncovered.** Walk an empty set and `$allCompleted` is never cleared, so the sheet is declared
  `COMPLETED` — a caller that loaded nothing would complete a sheet it knows nothing about. The
  settlement cannot reach that branch, because the transaction it is settling necessarily carries
  the sheet id it looks up; removing the guard passed the whole settlement suite untouched. It is
  therefore pinned by a direct test on the aggregate, and the report says so rather than implying
  the settlement suite covers it.

  **Two of my own fixtures were wrong first, and both are recorded because the corrections are the
  interesting part.** Each transaction first got its own sheet, which made FR-043 unobservable —
  a sheet with one transaction always completes, so "completes", "completes-with-rejections" and
  "does not complete yet" were indistinguishable. And the fixture attached transactions with
  `addTransaction()`, which correctly refuses a sheet once a settlement has recalculated it, so the
  second transaction in a test threw *"Submitted sheets are read-only"*. The shared sheet is the
  only shape in which this requirement can be observed at all.

  **Sheet coverage added, each proven by mutation:** removing the `recalculateSheet()` call fails 3
  (completion, completion-with-rejections, and waiting for the last unsettled transaction); removing
  the empty-set guard fails the aggregate-level test with *"Failed asserting that two variables
  reference the same object"* — the status having become `COMPLETED` from nothing.

  `scripts/dev/phpunit` is **234 tests / 1170 assertions / 1 failure**, the failure being T275 alone
  and untouched. `npx vitest run` is 214/214 and `npm run check` exits 0. Report:
  `docs/reports/T061.md`.
- [x] T062 [US3] Implement shared finance queue queries and decision restrictions without cost-center scoping in `lib/Application/Workflow/FinanceQueue.php`
- [X] T063 [US3] [FR-003] Add finance queue, finance decision, account summary, and movement OCS routes/controllers in `appinfo/routes.php`, `lib/Controller/ReviewController.php`, and `lib/Controller/AccountController.php` (**reopened by the US3 slice** — `appinfo/routes.php` declares four account routes and `AccountController` implemented one, so `account#create`, `account#charge` and `account#movements` all resolved to a method that did not exist and answered **HTTP 500**. `movements` is implemented and covered here; **`create` and `charge` are still missing and still 500**) (complete — all four declared account routes are implemented and covered; `docs/reports/T063.md`)

  **`create` and `charge` are built and tested, and a declared route no longer answers 500.** 15 contract
  tests in `tests/Contract/Controller/AccountWriteControllerTest.php`, separate from the read tests
  because an account is money and both of these write it. The charge tests prepare the `finance`
  fixture state because that is the only state granting the acting user the `FINANCE` role FR-015
  requires, and preparing it explicitly is what makes the assertions independent of suite order.

  **Two authorization rules, both taken from the spec rather than invented.** FR-015 says **finance**
  charges an account, so the charge requires the `FINANCE` role (an administrator is admitted too,
  since FR-013 puts account funding with administrators and the spec's journey has one person doing
  both). FR-013/FR-014 put account opening with a user for themselves and an administrator for others,
  so `create` admits the caller's own `userId` or an administrator's, and refuses anybody else's —
  which is the one identifier in this controller taken from the request rather than the session.
  **Ownership is deliberately *not* the rule for a charge**: the first draft of that test asserted it,
  and FR-015 says finance charges "a **selected user** petty-cash account", so charging somebody else's
  account is the normal case. The corrected test asserts the 404 for an account that is not there.

  **A charge is a ledger entry, not a number.** The movement carries the caller's `description` and
  `occurredAt` — `AccountMovement` is `readonly`, so both are now constructor arguments rather than
  assignments after the fact, and a charge entered from a handover note is dated to the handover. The
  command id runs through the same `IdempotencyService` the decision path uses, and the **stored
  response** is returned for a replay, so a retried charge neither double-pays nor loses the movement
  it already wrote. `expectedVersion` is required and checked: funding is the one write two finance
  officers make at once, and a charge against a balance that has since moved is a lost update.

  **The catch order in the controller was the bug, not a detail.** `InvalidArgumentException` extends
  `LogicException`, so a single `catch (\Exception)` for a bad `occurredAt` silently turned the stale
  version's 409 into a 422. The specific catches come first, and the request's own shape is validated
  in the controller where the field names are still known.

  **Fixture work this needed.** A third currency: the acting user already held an IRR and a USD
  account, and FR-014 allows one per currency, so **every** create collided with the seed and the
  endpoint could only ever be observed in its refusal — which is how a 409 was the first thing these
  tests saw. Accounts the suites open are now removed on every seed, and the movement cleanup covers
  every account the user holds rather than only the primary one, since a charge against the USD
  account leaves its movement behind just as a settlement does.

  **Mutations, and two that survived — which is the useful part.** Removing the version check fails 1;
  removing the movement save fails 2; forcing `occurredAt` to `now` fails 1. **Removing the
  FR-015 finance gate fails nothing**, because the only fixture user of this suite holds `FINANCE` — the
  rule is implemented and correct but unverified over HTTP, and is recorded as T291 rather than claimed.
  **The duplicate-account 409 rests on one of two guards, not both**: removing the pre-check leaves the
  answer intact (the unique-index catch produces the same 409), removing the catch leaves it intact (the
  pre-check does), and only removing both breaks it. The pre-check is the clearer path and the catch is
  the one that survives a concurrent race, so both stay, but neither is independently pinned and this
  file does not pretend otherwise.

  `scripts/dev/phpunit` is **270 tests / 1435 assertions / 1 failure**, the failure being T275 alone.
- [x] T064 [P] [US3] Implement finance/account typed API services and stores in `src/services/reviewsApi.ts`, `src/services/accountsApi.ts`, `src/stores/reviews.ts`, and `src/stores/accounts.ts`
- [ ] T065 [US3] Implement finance review, purchaser balance, and account movement views with explicit settled-versus-management labels in `src/views/reviews/FinanceReviewView.vue`, `src/views/accounts/AccountView.vue`, and `src/components/review/` (all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071) (**partial** — the three views and `SettlementLabel.vue` are built and verified; the same three browser-measured clauses block the remainder that blocked `T053`, and are recorded in `docs/reports/US3-finance-slice.md`)
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

- [x] T067 [P] [US4] Write failing revision tests for `revision_number starts at 1; unique per transaction`, submitted revision immutability, correction ownership, supersedes linkage, and active-revision switching in `tests/Unit/Domain/Expense/TransactionRevisionTest.php` (complete — **corrected by T298, case 1: the checkbox was stale, not the work.** `git log -L` shows `e074cff` (2026-09-22) *created* this line as `[ ]` and it was never checked, while T195 asserted "T067 already `[x]`" on 2026-09-26 — a claim that was false the day it was written. The file exists with **11 tests** and was re-measured green this run as part of the 18-test / 49-assertion pair with T068. Nothing reopened it and no note gives a reason to leave it open, so the state was simply never updated. Per T293's precedent this is a bookkeeping correction and **not** new test evidence)
- [x] T068 [P] [US4] Write failing integration tests for return-to-correction, new-revision activation, manager snapshot refresh, full-path restart, retained completed sibling transactions, and atomic audit insertion in `tests/Integration/Workflow/TransactionCorrectionTest.php` (complete — **corrected by T298, case 1**, on the same evidence as T067: created `[ ]` by `e074cff`, never checked, and T196 asserted "T068 already `[x]`" when it was not. The file now holds **9 tests**, not the 7 T196 recorded, and the pair is green)
- [ ] T069 [P] [US4] Write failing contract and Vue tests for correction creation/update/resubmit, returned queue, read-only prior revisions, and field/evidence validation in `tests/Contract/Controller/CorrectionControllerTest.php` and `tests/Frontend/Component/TransactionCorrection.spec.ts`

### Implementation for User Story 4

- [x] T070 [US4] Implement correction-draft creation, owner-only editing, immutable prior revision cloning, and supersedes linkage in `lib/Application/Expense/CreateCorrectionDraft.php`
- [x] T071 [US4] Implement atomic correction validation, active-revision activation, current-manager snapshots, cost-center rerouting, state projection, command result, and audit in `lib/Application/Workflow/ResubmitCorrection.php`
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
- [ ] T082 [P] [US5] Implement cost-center and effective-dated manager/senior assignment administration with future-routing behavior in `lib/Application/Configuration/CostCenterService.php` (**read this before starting** — T236's real-database round trip found that `DbCostCenterRepository` was writing `manager_uid` and `senior_manager_uid`, columns that exist in no migration and in no table, so every cost-center write failed with `Unknown column 'manager_uid'` and had never been executed by any test. `data-model.md` gives `CostCenter` no manager, and states the relationship as "Has effective-dated cost-center-manager and senior-manager **role assignments**", so the fields and the two dead port methods `findByManagerUid()` / `findBySeniorManagerUid()` were **removed** rather than given columns. Managers are rows in `pettycash_role_assignments` scoped by `cost_center_id`, and `ManagementReviewService::getManagedCostCenterCodes()` already resolves a reviewer's scope that way. **So this task must create role assignments, not set properties on a cost center** — passing a manager uid to `CostCenter::create()` is no longer possible, and `T077`'s "exactly one effective manager and senior manager" is a rule about assignments. `RoleAssignment` also has no `lock_version`, in the data model and in the table alike)
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
- [X] T100 [US6] Document audit retention as archival/availability policy that never deletes or rewrites business history in `docs/audit-retention.md` (complete — states the policy, where it is enforced and by which test, and a `What is **not** enforced` section covering the three things a reader could otherwise over-read: that MariaDB itself is not append-only, that no V1 process acts on `isArchivable()` (verified: zero production callers), and that the compliance report is a diagnostic that neutralises a sub-floor value on read rather than correcting what is stored. Every claim mechanically verified against the code before being written. **Corrected after the fact:** the first draft described configuration audit as a working separate stream. T139's investigation found that nothing writes `pettycash_configuration_audit` at all and that `appendConfiguration()` is dead code that would route into the transaction table, so the document now carries a per-stream state table and an `Open items` entry saying so, rather than describing the requirement as the implementation)

**Checkpoint**: Complete history is viewable only within scope, evidence is never physically
removed after submission, and no supported interface mutates audit.

---

## Phase 9: User Story 7 — Monitor, Search, Report, and Export (Priority: P2)

**Goal**: Every role receives scoped dashboards, filters, reports, totals, and secure CSV/XLSX/PDF
or evidence-ZIP exports.

**Independent Test**: Populate mixed roles, centers, currencies, stages, outcomes, revisions, and
evidence; verify every report/export contains all permitted and zero out-of-scope records.

### Tests for User Story 7

- [x] T101 [P] [US7] Write failing total-calculation tests for current, submitted, latest-effective approved/rejected/pending, and independent cost-center/senior/finance approval totals including return-to-pending behavior in `tests/Unit/Domain/Expense/SheetTotalsTest.php` (complete — **stale checkbox, reconciled on evidence**: the file exists and `scripts/dev/phpunit tests/Unit/Domain/Expense/SheetTotalsTest.php` is **11 tests / 26 assertions green**, so the deliverable was already delivered and the box was simply never ticked. Per T293's precedent this is a bookkeeping correction and **not** new test evidence — the tests were written and verified by an earlier pass. The stage-approved columns it covers are the ones FR-062 requires as separate figures, and T108's `Sheet` sheet now exports them as typed numeric cells)
- [ ] T102 [P] [US7] Write failing role-scoped report/filter/pagination tests covering sheet, transaction, cost-center, account, process, evidence, and audit reports in `tests/Integration/Reporting/ReportScopeTest.php` (verify transaction audit and configuration audit remain separately identifiable per FR-056 - explicit test: transaction audit queries never return configuration audit events and vice versa; config audit visibility per role scope)
- [ ] T103 [P] [US7] Write failing exporter tests for UTF-8 CSV, formula escaping, six-sheet XLSX, Persian RTL PDF, ZIP manifest-to-file integrity, transient cleanup, and out-of-scope omission in `tests/Unit/Export/ExportersTest.php` (grouped evidence ZIP respects 100 MB/file limit per FR-074)
- [ ] T104 [P] [US7] Write failing contract and Vue tests for dashboard, report, filter, paging, and export interfaces in `tests/Contract/Controller/ReportingControllerTest.php` and `tests/Frontend/Component/DashboardReports.spec.ts` (verify all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071)

### Implementation for User Story 7

- [x] T105 [US7] Implement indexed sheet summary and totals projections using active revisions and immutable decisions without storing independent mutable totals in `lib/Infrastructure/Persistence/Expense/SheetSummaryQuery.php` (done - `SheetSummaryQuery` reads one flat row per (active revision, decision) pair through the join on `active_revision_id` and `pettycash_workflow_decisions_revision_stage_uniq`, with `sheet_id` leading `pettycash_transactions_sheet_state_idx`, and hands the rows to the domain's already-tested `SheetTotals::fromRows()`. The reduction rules are deliberately **not** restated in SQL: the most-advanced-decision precedence and the fact that a return makes a transaction pending live in the domain, and duplicating them in SQL would give one rule two implementations that drift. Only decisions on the **active** revision are read — a decision on a superseded revision stays in the database as FR-050 history but no longer describes the current outcome. The precedence rule itself had no home in the domain at all, so it is now `SheetTotals::effectiveOutcome(finance, senior, costCenter)`, asserted case by case in `tests/Unit/Domain/Expense/SheetTotalsTest.php` (8 data-provider cases plus the returned-transaction case where the transaction is pending while its earlier stage approvals still count) — 11/11 green)
- [x] T106 [P] [US7] Implement role-scoped dashboard and report query services with server-side filters and maximum page size 100 in `lib/Application/Reporting/DashboardQuery.php` and `lib/Application/Reporting/ReportQuery.php` (config audit visibility per role scope: admins all, finance charged accounts, managers assigned cost centers) (complete — 50 tests in `tests/Unit/Reporting/ReportingQueryTest.php`, mutation-verified four ways. Delivered `ReportScope` (role resolution, the shared primitive), `ReportFilter` (server-side filters plus the 100 bound), `DashboardQuery` and `ReportQuery`. **Scope is applied before filtering, structurally** — `ReportQuery::paginate()` takes the scope and the filter and there is only one order, because `contracts/exports.md` requires scoping "before filtering, aggregation, or file generation" and a report that filtered first would return the right count over the wrong rows. The page size is **clamped** to 100 rather than refused, since a client sending "give me everything" is what server-side paging is for; a zero or negative page number **is** refused, because there is no bound to fall back to. **Deliberately not registered in the container:** nothing consumes these until T112's controllers exist, and registering an unreferenced service is speculative. **One gap, recorded not hidden:** the FR-055 rule for finance officers — configuration audit for *accounts they charge* — is implemented and tested, but resolving **which** accounts an officer has charged is **not derivable from the current schema or ports**: `AccountMovement` records no actor, `AccountMovementRepository` has no by-actor read, and `CommandResultRepository` exposes only `find($commandId)`/`save()`, so the `actor_uid` + `operation` row that does hold the answer is reachable one row at a time and cannot be enumerated. `configurationAuditScope()` therefore takes the charged account ids the caller already knows, and an officer given none sees **nothing** — an empty scope must never widen. Inventing a `findByActorAndOperation()` port method to serve one report would change a port `IdempotencyService` also depends on, so that decision belongs to T112's orchestration layer, not here. FR-056's stream-separation predicates are also delivered, though **T294 still means no configuration event exists to separate yet** — the predicates are what T139 will need once it does. **Corrected after the fact, before any controller was built on it:** the first version of this task used its own report-type names and its own page shape, and both diverged from the approved contract. It spelled the cost-centre type `cost_centers` where `contracts/ocs-api.yaml` declares `cost-centers`, and it advertised three types the contract does not accept (`balances`, `movements`, `configuration_audit`); and it returned `items`/`offset` where the contract's `ReportPage` is `PageMeta` (`page`, `pageSize`, `total`) plus `columns`, `rows`, `summary`. A client written against the contract would have been **refused** a valid `cost-centers` request and found **no `rows` key** in the response. Both are now taken verbatim from the contract, and the enum is asserted as data so a future divergence fails the suite rather than a request. `ReportFilter`'s bounds were already correct: the contract's `PageSize` is `minimum: 1, maximum: 100, default: 25`, exactly as implemented. **The gap this exposes is recorded on T112:** FR-065 names petty-cash balances and movements as report subjects, and FR-056 requires configuration audit to be visible, but the contract's enum omits all three — so those subjects are reachable only through the `accounts` type or not at all, and that is a contract decision rather than one to resolve by inventing a type)
- [X] T107 [P] [US7] [FR-066] Implement native streaming CSV with spreadsheet-formula escaping in `lib/Infrastructure/Export/CsvExporter.php` (complete — 33 tests in `tests/Unit/Export/CsvExporterTest.php`. **The design decision is that escaping keys on the PHP type, not the string content.** The standard hardening advice — prefix any cell starting with `=`, `+`, `-` or `@` — applied naively to a report of petty-cash amounts turns every negative into text, and a financial export that mangles its own numbers is worse than none. So `int` and `float` are always written plainly and only `string` is escaped. The cost is a rule for callers: pass numbers as numbers. The benefit is that the case hardening actually exists for — a description or explanation a user typed — is covered, and `-1234.56` survives. Three defects were found and fixed while writing the tests, one of them in the exporter: `array_merge` on `array_keys()` does not deduplicate, so the header union produced `a,b,a` with a trailing cell per repeated key. RFC 4180 output (`escape: ''`) rather than PHP's backslash default, because that default is deprecated and will change, and a report consumed by a script in another language should not need to know which escape character this application chose. Mutation-verified three ways. Not registered in the container: no consumer exists until T111) (complete)
- [X] T108 [P] [US7] [FR-066, FR-067] Implement XLSX export with the six-sheet complete-sheet layout from `contracts/exports.md` in `lib/Infrastructure/Export/XlsxExporter.php` (complete — 32 tests in `tests/Unit/Export/XlsxExporterTest.php` that read the produced workbook back with PhpSpreadsheet **and** unzip the package, never from the exporter's own report. The contract's acceptance check is "XLSX contains the promised sheets and numeric values" and both halves are asserted literally: all six sheets in order, each with its contract columns, and each typed cell read back as `TYPE_NUMERIC`/`TYPE_BOOL`/`TYPE_STRING`. An amount written as text opens correctly and cannot be totalled, which is the whole reason the contract asks XLSX for typed cells while letting CSV get away with machine values. `XlsxSheet` refuses an unknown column type, a sheet name Excel forbids, and an over-31-character name; `completeSheet()` refuses a row missing a contract column and reports every missing column at once; a string in a numeric/date/boolean column is refused rather than coerced. The workbook is assembled on a scratch path and persisted through `TransientExportStore` for the same reason the evidence archive is. Mutation-verified four ways. **Note:** this task had been wrongly reported complete in an earlier handoff — see the correction in `docs/reports/T110-appdata-verified.md`
- [ ] T109 [P] [US7] Implement standard Persian RTL PDF export with an approved embedded font and no custom letterhead/signature in `lib/Infrastructure/Export/PdfExporter.php` — **BLOCKED on the font asset, which no installed dependency provides. Verified, not inferred.** `tecnickcom/tcpdf` is **not installed** (only `tecnickcom/tc-lib-pdf` and satellites; the main class is `Com\Tecnick\Pdf\Tcpdf` and `Com\Tecnick\Pdf\TCPDF` does not exist), and there are **zero** `.ttf`/`.otf`/`.ttc`/`.afm`/`.pfb` files anywhere in `vendor/`. `tc-lib-pdf-font` **ships no fonts**: `doc/FONTS.md` states the font assets "must be generated before they can be used" by `make -C vendor/tecnickcom/tc-lib-pdf-font deps fonts`, wired into the **consuming project's** `post-install-cmd`/`post-update-cmd`/`post-autoload-dump` — and this repository's `composer.json` has no such script, so the assets are neither present nor generated. `K_PATH_FONTS` is the only registration point and `Buffer` has no search-path or autodetection mechanism, so a font file path must always be supplied by the caller. The package's own documented workaround for "shared or immutable environments" is to generate fonts once into a persistent directory **outside** `vendor/` and point `K_PATH_FONTS` at it, which is precisely the machine-specific path this feature must not depend on. No Arabic or Persian example ships with the package, so there is not even evidence that the generated set would cover Persian. **The shaping capability is NOT the problem and is verified working:** `tc-lib-unicode` provides `Com\Tecnick\Unicode\Bidi` (UAX#9) and `Bidi\Shaping\Arabic` with its data tables, reached from `Text::prepareText()` → `Substitution::replaceChars()`, plus `Tcpdf::setRTL()` — correct reorder-then-shape order. **What this task needs from the maintainer:** a decision on where the approved Persian font comes from — vendored into the repository under an app-owned path with its OFL text, or generated at install time into an app-owned directory. No artifact records where an export font should live: `plan.md` scopes `l10n/` to "Persian source and generated translation artifacts" and says nothing about an export font, so this is a design gap rather than an implementation detail. **Deliberately not done here:** no system font was copied or vendored, and no repository font location was invented. Full evidence: `docs/reports/T109-preimplementation.md` (blocked, font asset not available)
- [X] T110 [P] [US7] [FR-066, FR-069] Implement grouped evidence ZIP export with safe archive paths and a UTF-8 relationship manifest in `lib/Infrastructure/Export/EvidenceArchiveExporter.php` (enforce 100 MB max file size per FR-074; no advanced restrictions per FR-030) (complete — 34 tests reading the produced ZIP back with `ZipArchive`, plus `EvidenceArchiveEntry`/`EvidenceArchiveResult` value objects. Three properties held, each failing in a different direction: **manifest-to-file completeness** (the contract states both halves, and they are one invariant read from opposite ends), **path safety** (a user-supplied `originalName` is reduced to a bare basename, dot-runs collapsed, Windows-reserved characters removed, 120-char cap, empty falls back to the public id), and **refusal vs omission** (an unreadable file is omitted from archive *and* manifest with a reason; a file over FR-074's 100 MB is a *refusal* about upload validation having failed, not a storage condition, and silently dropping evidence from a financial export is what FR-053/FR-059 exist to prevent). The manifest reuses `CsvExporter` — sheet titles and uploader names are user-controlled and inherit the same formula-escaping duty — which required giving `CsvExporter::export()` an explicit-header argument so an empty archive still carries its manifest header. Deliberately does **not** implement the tabular `Exporter` port: a ZIP with binary members is not tabular, and smuggling a reader callback through an `iterable` would be worse than a second honest API. Mutation-verified, including one mutation that initially did **not** fail and exposed a genuine test gap — **REOPENED by the next convergence, and the storage half now reworked; still open.** Convergence found this task was marked complete on a claim that did not hold: `contracts/exports.md:10` requires "Generated artifacts use private transient application storage" and `plan.md:39` names Nextcloud `IAppData`, while the implementation assembled the archive in the host's **shared** `sys_get_temp_dir()` and then slurped the whole thing back with `file_get_contents()`. A group evidence archive is exactly the material FR-060's secrecy and ADR-001 protect. **Now done:** a `TransientExportStore` port, an `AppDataExportStore` `IAppData` adapter (container-registered), a scratch-vs-artifact split so the persisted file is never the file `ZipArchive` assembled in, a streamed `persist()` so a large archive is bounded by disk rather than PHP's memory limit, and a result that carries the artifact **name** rather than its bytes. 41 tests, mutation-verified three ways. **What is still open, and it is not cosmetic:** every one of those tests runs against `InMemoryTransientExportStore`, so **no test has executed `AppDataExportStore` against a real `IAppData`** — the exact gap T236 was written for, where thirteen in-memory doubles meant no test ever ran a real persistence statement and nine repositories turned out to be broken. The adapter's `getFolder`/`newFolder`/`newFile`/`write` usage and its `safeName()` are unverified, and the streaming `persist()` is exactly the kind of path that only fails against the real filesystem. This needs the `roundtrip-runner.php` subprocess treatment, not another double — **and that is now done**: an `appdata`
  scenario in `roundtrip-runner.php` resolves a real `OC\Files\AppData\AppData` through
  `IAppDataFactory::get('pettycash')` — the same call the AppFramework's `DIContainer` makes — and exercises the
  production store and the full exporter path against a real filesystem.
  `tests/Integration/Persistence/AppDataExportStoreRoundTripTest.php`, 18 tests, asserts: `persist()` writes
  through real `IAppData` and the file appears in the folder listing; a 4 KB random payload reads back
  byte-identically with matching SHA1; replace semantics overwrite rather than duplicate; `delete()` removes one
  and leaves the rest; `deleteAll()` genuinely empties the folder; the stored artifact is a real ZIP whose
  extracted `manifest.csv` carries all 15 columns with the Persian uploader name intact; the evidence member
  round-trips; and scratch is removed on **both** the success and the failing path. Distinctness is proven by
  ordering rather than by path — `ISimpleFolder` exposes no absolute path — so the artifact is shown to survive the
  deletion of the scratch file it was not. Four mutations fail loudly, including one that made `persist()` bypass
  `IAppData` entirely and reported the real path `/appdata_oculd8krif6b/pettycash/exports/`. No shared temp or
  AppData litter after the run)
- [x] T111 [US7] [FR-066] Implement export orchestration, transient AppData cleanup, and authorization-before-generation in `lib/Application/Reporting/ExportService.php`
- [x] T112 [US7] Add dashboard, report, and export OCS routes/controllers in `appinfo/routes.php`, `lib/Controller/DashboardController.php`, `lib/Controller/ReportController.php`, and `lib/Controller/ExportController.php` — **record this contract gap while building it, do not resolve it by inventing a type.** `contracts/ocs-api.yaml`'s `/reports/{reportType}` enum is exactly `[sheets, transactions, cost-centers, accounts, process, evidence, audit]`, but **FR-065 names petty-cash balances and movements as report subjects and FR-056 requires configuration audit to be visible**, and none of the three appears in the enum. `accounts` is the only plausible home for balances and movements, but the contract does not say so, and configuration audit has no type at all. T106 originally sidestepped this by advertising `balances`, `movements` and `configuration_audit`; that was corrected, because a report type is part of the OCS surface and an undeclared one is a contract violation rather than a naming preference. **The choice belongs to the contract:** either extend the enum, or record that these subjects are reachable through `accounts` and under what filter. `ReportQuery::SUPPORTED_TYPES` is asserted as the contract's exact list, so the day the enum changes this task's test says so. Also note the contract's `ExportRequest.resource` enum is a *different* set again (`[sheet, sheets, transactions, report, audit, evidence]`), so the two surfaces do not share a vocabulary. **And T299 lands here:** resolving which accounts a finance officer has charged is not derivable from the current schema, and this task's orchestration layer is where that decision has to be made — see T299 for the two routes and their costs. `report#show` and `export#create` are 2 of the 15 routes `RouteResolutionTest` reports as unresolved; `session#dashboard` is already routed

  **Closed in two passes, and the reason it was reopened is now fixed.** T309 closed the filter
  vocabulary; this pass closes the **accounts scope**, which is what T309 left open and what T299 had
  blocked. `accountsReport()` returned only the caller's own accounts and said so in its summary, with
  an honest `t299: pending` marker. It now applies the clarified rule: own accounts; a finance officer
  additionally sees the accounts that have been charged; an administrator sees all.

  Two things about that are deliberate and worth recording. "Charged **at all**" rather than "charged
  by this officer", because FR-016 puts every finance officer on one shared queue — an officer who
  cannot see an account another officer charged cannot work that account's queue. And an account that
  is both the caller's own and charged is listed **once**: the merge is deduplicated, because a row
  listed twice would double both the count and the balance.

  The `t299: pending` marker is **gone**, and its absence is the point. It said the scope was
  unresolved; it is resolved, and leaving a pending marker on a resolved rule would let a caller
  mistake one for the other. The summary now reports `scope`, `chargedAccountsIncluded` and
  `chargedAccountCount`, which is what a caller needs to tell a scoped report from an instance-wide
  one.

  The contract test asserts the **effect**, not the label: it locates the account a finance approval
  actually settled inside the returned rows, and cross-checks that row's balance against the accounts
  endpoint. A summary reading `own_and_charged_accounts` while returning only the caller's own rows
  would satisfy a string comparison and still fail FR-016, so the label alone is not the assertion.
  A second test pins the no-duplicates rule. The old test that asserted `own_accounts` was asserting
  the behaviour the clarification replaced — a valid RED, not a broken one.

  One defect fixed in passing: `InMemoryAccountRepository::save()` keys by public id while
  `findById()` indexed by surrogate key, so `findById()` always answered null for a fixture just
  stored. That is a defect in the double, not the behaviour under test, but it made the new
  by-surrogate-key path untestable. Suite: 648 tests, 2872 assertions, no new failures (T275
  unchanged).

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
- [ ] T120 [P] [US8] Implement logical-property RTL styles, Nextcloud theme variables, desktop tables, mobile cards, status text/icons (WCAG AA contrast), and bidirectional isolation in `src/styles/app.scss`, `src/styles/rtl.scss`, and `src/styles/responsive.scss` (all elements visible without horizontal scroll at 390px viewport; text ≥12pt Persian font per FR-071) (partial - **the FR-071 typography half is done**, the rest of the scope is not. `tests/Frontend/typography.spec.ts` is green: 4/4, from 42 violations of the 12pt floor. The app's scale started below the requirement — 12px, 13px, 14px, `0.75rem`, `0.875rem`, `0.9rem` across 16 files — so every caption, meta line and table cell in the app was under it, and that is now expressed once as four tokens on `.pettycash-shell` in `src/styles/app.scss` (`--font-size-floor: 1rem`, `--font-size-emphasis: 1.125rem`, `--font-size-section: 1.25rem`, `--font-size-title: 1.5rem`) which all 44 declarations now read. Two steps that used to be 12px next to a 14px value became `1rem` and `1.125rem`, so the label/value hierarchy survives the floor being raised under it. De-emphasis is carried by colour and weight, never by size. **What is still open in this task**, and is the larger half: logical-property RTL styles, the Nextcloud theme variables, desktop tables against mobile cards, status text/icons at WCAG AA contrast, and bidirectional isolation — `_rtl.scss` is still 9 lines (a `direction` attribute and one `unicode-bidi: isolate` class) and `_responsive.scss` still 5 (one padding breakpoint), so none of that has been started. FR-071's *other* clauses — no horizontal scroll at 390px, and the ≥12pt requirement as a browser measurement rather than a declaration scan — belong to `T116`'s test file and `T122`/`T123`'s browser and manual matrices, and are not claimed here)
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
- [X] T138 [P] Write failing integration test verifying audit records younger than 7 years are never archived or deleted (FR-057 7-year retention floor) in `tests/Integration/Audit/RetentionPolicyTest.php` (complete — 15 tests. Real-database half writes a young and a ten-year-old row through `DbAuditEventRepository` in a new `retention` scenario of `roundtrip-runner.php` and evaluates the real policy against the values read back out of MariaDB; the ten-year-old row is the control that makes the young row's protection mean something. Structural half asserts by reflection that no public method on `AuditEventRepository`, `AuditRetentionPolicy` or `RetentionPolicyService` is a removal or rewrite path, and that the real schema has no archive/deletion column. Mutation-verified both ways. Unblocked by T237)
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
- [ ] T171 [US1] Install Playwright browsers (`npx playwright install chromium firefox`) and verify
  `purchaser-submit.spec.ts` E2E tests pass in `tests/E2E/purchaser-submit.spec.ts` (missing)

  **Deferred by the 2026-09-26 autopilot run — blocked on a browser credential the repository does
  not hold, not on missing work.** The browser half is already done: `~/.cache/ms-playwright/`
  holds `chromium-1200` and `firefox-1497`, so the install this task names is satisfied. The
  verification half cannot be, and the reason is worth recording precisely because it blocks the
  whole Playwright chain rather than one task.

  `playwright.config.ts` declares no authentication at all — no `globalSetup`, no `storageState`,
  no `httpCredentials` — and `tests/E2E/purchaser-submit.spec.ts` navigates straight to
  `/apps/pettycash/`, which answers **401**. The suite has therefore never run against an
  authenticated session, which is also why `test-results/` still holds a failure artefact for
  `should-create-an-open-sheet`.

  The one credential the project documents, `.env.testing`'s `PETTYCASH_TEST_PASSWORD`, **cannot**
  authenticate a browser. `.env.example` defines it as "App password / token for the test user",
  minted by `occ user:auth-tokens:add`, and
  `occ user:auth-tokens:list employee1` confirms it: `pc-ct-4`, type `permanent`, scope
  `filesystem`. It is 72 characters and entirely alphanumeric, which is exactly Nextcloud's
  app-password format. Measured both ways: the token returns **200** from
  `GET /ocs/v2.php/apps/pettycash/api/v1/me`, so it is valid, and the login form returns
  **"Wrong login or password."** while the server logs `Login failed: employee1`. That is the
  designed behaviour, not a fault — an app token authenticates Basic-auth/OCS callers and is never
  accepted by the web login form, which only takes the account password. Basic auth is not a way
  around it either: `GET /apps/pettycash/` with the same credentials answers **403**, because
  Nextcloud accepts Basic auth for OCS endpoints and not for app pages.

  Two candidate causes were ruled out by measurement rather than left as theories, because each
  would have looked like a product defect. **Bruteforce throttling** was the first: the container
  IP `172.18.0.1` carried `delay: 800`, and a throttled login fails with the same message.
  `scripts/dev/occ security:bruteforce:attempts 172.18.0.1` re-read `attempts: 0, delay: 0` after
  the natural decay, and the login still failed, so the throttle is not the cause — the
  credentials are. And **form truncation** was the second: a 72-character password is long enough
  to suspect a `maxlength`, so the DOM value, the `value` after `fill()`, and the `password=` field
  of the actual POST body were each compared against the token. All three are byte-identical to
  the token, and the body carries a `requesttoken`, so nothing is being mangled in transit.

  Unblocking needs a decision that is outside an unattended agent's boundary, so none was taken:
  supplying the account password for `employee1`, minting a *session* rather than a token, or
  resetting the account credential. Any of those is a credential mutation on the shared Nextcloud
  environment. The minimum to unblock this task and the eight that depend on it (`T053`'s three
  browser-measured clauses, `T054`, `T066`, `T076`, `T089`, `T099`, `T114`, `T122`) is a real
  browser-login credential for `employee1` in `.env.testing`, alongside a `globalSetup` that logs
  in and saves `storageState`.

  The spec is separately stale and will need correcting once a session is possible, so the delay
  should not be read as "only auth is missing". Of the 26 `data-test` selectors it uses, **8 do not
  exist in `src/`**: `add-transaction-button` (the component emits `create-transaction-btn`),
  `transaction-list-item` (`transaction-row`), `transaction-status` (`transaction-status-badge`),
  `validation-error` (`validation-summary` and `validation-summary-{field}`), and
  `account-option` / `cost-center-option` / `expense-type-option`, which are `NcSelect` options
  rather than declared hooks. `audit-event` is the eighth and is a **product gap, not a selector
  mistake**: `src/views/sheets/SheetDetailView.vue:258` renders the audit table body as
  `<tr v-if="false">`, so the sheet-detail audit history renders a header row and no events at all,
  under `FR-051`/`FR-052`/`SC-006`. That render belongs to `T098`; the Vue-testable audit query
  and timeline belong to `T092`/`T093`. It is recorded here so the gap is not rediscovered as a
  broken selector, and the assertion at line 220 expects the literal `test-user` where the fixture
  user is `employee1`.


### Phase 4 Completion — US2 Management Review (Priority: P1)

- [x] T172 [US2] Mark T044 complete - `ManagementWorkflowTest.php` exists with 12 passing tests in `tests/Unit/Domain/Workflow/ManagementWorkflowTest.php` (missing)
- [x] T173 [US2] Mark T045 complete - `ManagementReviewAuthorizationTest.php` exists with 13 passing tests in `tests/Unit/Security/ManagementReviewAuthorizationTest.php` (missing)
- [ ] T174 [P] [US2] Create `ManagementReviewControllerTest.php` and `ManagementDecisionTest.php` contract/integration tests for management queues and decision endpoints per T046 in `tests/Contract/Controller/` and `tests/Integration/Workflow/` (missing)
- [ ] T175 [P] [US2] Create `ManagementReview.spec.ts` Vue tests for scoped queues, evidence/history display, decision form validation per FR-071 in `tests/Frontend/Component/ManagementReview.spec.ts` (missing)
- [x] T176 [P] [US2] Implement `WorkflowStateMachine.php` centralized workflow state machine and transition guards from `contracts/workflow.md` per T048 in `lib/Domain/Workflow/WorkflowStateMachine.php` (missing)
- [x] T177 [US2] Enhance `WorkflowDecision.php` with "one effective decision per revision and stage" uniqueness constraint per T049 in `lib/Domain/Workflow/WorkflowDecision.php` (partial)
- [x] T178 [US2] Implement `ManagementReviewService.php` with manager/senior queue queries, decision orchestration, actor snapshots, sheet-stage recalculation, audit writes per T050 in `lib/Application/Workflow/ManagementReviewService.php` (missing)
- [x] T179 [US2] Add `ReviewController.php` with management queue and decision OCS routes/controllers per T051 in `lib/Controller/ReviewController.php` and `appinfo/routes.php` (missing)
- [x] T180 [P] [US2] Implement `reviewsApi.ts` and `stores/reviews.ts` typed review API services and Pinia state per T052 in `src/services/` and `src/stores/` (missing)
- [x] T181 [US2] Implement cost-center/senior queue views, transaction review detail, decision form, prior-decision history components per FR-071 in `src/views/reviews/` and `src/components/review/` (missing)
- [ ] T182 [US2] Add `management-review.spec.ts` Playwright journeys for scope, self-review, disagreement, rejection, return per T054 in `tests/E2E/management-review.spec.ts` (missing)

### Phase 5 Completion — US3 Finance Settlement (Priority: P1)

- [x] T183 [US3] Mark T055 complete - `FinanceDecisionPolicyTest.php` exists with 10 passing tests in `tests/Unit/Domain/Workflow/FinanceDecisionPolicyTest.php` (missing)
- [X] T184 [P] [US3] Create `FinanceSettlementTest.php` integration tests for fixed row-lock order, exactly-one consumption, command replay, conflicting UUID, simultaneous approvals, rollback per T056 in `tests/Integration/Workflow/FinanceSettlementTest.php` (complete — the file exists with 29 tests / 149 assertions covering all six cases T056 names; T056 already `[x]`, and the evidence is in `docs/reports/T056.md`. Corrected by T293, which is a bookkeeping fix and **not** new test evidence)
- [X] T185 [P] [US3] Create `FinanceReviewControllerTest.php` contract tests for shared finance queue and decisions per T057 in `tests/Contract/Controller/FinanceReviewControllerTest.php` (complete — the file exists with 21 tests covering the shared queue and the finance decision responses; T057 already `[x]`, and the details of what it found are recorded there)
- [ ] T186 [P] [US3] Create `FinanceReview.spec.ts` Vue tests for shared queue, senior-decision restrictions, settlement feedback per FR-071 in `tests/Frontend/Component/FinanceReview.spec.ts` (missing)
- [x] T187 [P] [US3] Align `AccountMovement` domain with minor units (not Money), positive magnitude, unique command ID, unique consumption source per T059 in `lib/Domain/Account/AccountMovement.php` (partial)
- [x] T188 [US3] Implement `DbAccountRepository.php` and `DbAccountMovementRepository.php` with account locking, movement insertion, balance projection per T060 in `lib/Infrastructure/Persistence/Account/` (missing) (done - the four account read queries now build their currency projection with `selectAlias()` instead of inline `as` aliases, which `IQueryBuilder` splits with an unbounded `preg_split` and truncates into one malformed alias; `c.public_id` was also dropped because `data-model.md` defines `Currency` without a `public_id`; `mapRow()` no longer overwrites the public-UUID `$id` with the surrogate key, and `save()` now resolves the existing row via `dbId`; `AccountMovement` carries `accountPublicId` so `DbAccountMovementRepository::save()` resolves the real `pc_accounts.id` and fails loudly instead of writing `account_id = 1`. `POST /sheets` moved from HTTP 500 to a structured 422 and the account query is now exception-free. Pessimistic row locking is NOT implemented here and remains with T060/T129)
- [x] T189 [US3] Implement `FinanceSettlementService.php` atomic orchestration (decision, unique movement, balance, transaction final state, sheet final state, command result, audit) per T061 in `lib/Application/Workflow/FinanceSettlementService.php` (missing)
- [x] T190 [US3] Implement `FinanceQueue.php` shared finance queue queries without cost-center scoping per T062 in `lib/Application/Workflow/FinanceQueue.php` (missing)
- [x] T191 [US3] Add finance/account routes/controllers per T063 in `appinfo/routes.php`, `lib/Controller/ReviewController.php`, `lib/Controller/AccountController.php` (missing)
- [x] T192 [P] [US3] Implement `accountsApi.ts` and `stores/accounts.ts` finance/account typed API services and stores per T064 in `src/services/` and `src/stores/` (missing)
- [ ] T193 [US3] Implement finance review, purchaser balance, account movement views with settled-vs-management labels per FR-071 in `src/views/reviews/FinanceReviewView.vue`, `src/views/accounts/AccountView.vue`, `src/components/review/` (missing)
- [ ] T194 [US3] Add `finance-settlement.spec.ts` Playwright/API journeys with negative-balance test per FR-027 in `tests/E2E/finance-settlement.spec.ts` (missing)

### Phase 6 Completion — US4 Correction (Priority: P1)

- [x] T195 [US4] Mark T067 complete - `TransactionRevisionTest.php` exists with 11 passing tests in `tests/Unit/Domain/Expense/TransactionRevisionTest.php` (missing)
- [x] T196 [US4] Mark T068 complete - `TransactionCorrectionTest.php` exists with 9 passing tests in (count corrected by T298: the file grew after this note was written) `tests/Integration/Workflow/TransactionCorrectionTest.php` (missing)
- [ ] T197 [P] [US4] Create `CorrectionControllerTest.php` and `TransactionCorrection.spec.ts` contract/Vue tests per T069 in `tests/Contract/Controller/` and `tests/Frontend/Component/` (missing)
- [x] T198 [US4] Implement `CreateCorrectionDraft.php` correction-draft creation, owner-only editing, immutable prior revision cloning, supersedes linkage per T070 in `lib/Application/Expense/CreateCorrectionDraft.php` (missing)
- [x] T199 [US4] Implement `ResubmitCorrection.php` atomic correction validation, active-revision activation, manager snapshots, cost-center rerouting, state projection, audit per T071 in `lib/Application/Workflow/ResubmitCorrection.php` (missing)
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

- [X] T236 [CRITICAL] Add integration tests that run against the real MariaDB schema and exercise the 14 production repositories in `lib/Infrastructure/Persistence/` (`DbAccountRepository`, `DbAccountMovementRepository`, `DbAuditEventRepository`, `DbCommandResultRepository`, `DbCostCenterRepository`, `DbCurrencyRepository`, `DbEvidenceLinkRepository`, `DbEvidenceRepository`, `DbExpenseSheetRepository`, `DbExpenseTransactionRepository`, `DbExpenseTypeRepository`, `DbRoleAssignmentRepository`, `DbTransactionRevisionRepository`, `DbWorkflowDecisionRepository`) in `tests/Integration/Persistence/`, asserting at minimum that a saved aggregate is retrievable through its own join keys and that a revision, decision, and audit row are linked to the correct parent after a round trip; every current test substitutes one of 13 `InMemory*` doubles, so no test executes the real persistence layer, which violates Constitution V ("persistence, revisions, audit records ... MUST have automated integration tests when a suitable harness exists") and is the demonstrated root cause of the accumulated defects fixed in T229, T230, T232, and T234 plus the still-open `DbAccountMovementRepository` hardcoded `$accountId = 1` and `DbAccountRepository` currency-join syntax error in T188 (complete)

  **Both defects this task named as evidence were already fixed** — `DbAccountMovementRepository` no
  longer hardcodes an account id, and `DbAccountRepository` selects its currency columns with
  `selectAlias()` rather than an inline `c.code as currency_code` string — so neither is redone here.
  What the task got right was the diagnosis underneath them.

  ## The harness

  `tests/Integration/Persistence/roundtrip-runner.php` runs in its own process, bootstraps the
  Nextcloud server, performs the round trips, prints what it observed as JSON, and deletes every row
  it created. `RepositoryRoundTripTest` (26 tests, 150 assertions) asserts on that JSON. The split is
  deliberate: assertions live in PHPUnit so a failure names the figure MariaDB actually held, and a
  bug in the runner cannot turn a broken repository green by agreeing with itself.

  A subprocess is the only option, and the reason is already documented in
  `seed-contract-fixtures.php`: `tests/bootstrap.php` wires a container of in-memory doubles, and
  loading `lib/base.php` into the PHPUnit process would replace the global server and break every
  other suite. The runner also has to put a user in the session itself, because every
  `Db*Repository::save()` stamps `created_by` from `CurrentUser::uid()` and `NextcloudCurrentUser`
  refuses to invent one.

  Four scenarios, so four bootstraps: `configuration`, `account`, `expense`, `command`. All 14
  repositories are covered. The whole file runs in 0.75s.

  ## What it found: nine repositories were defective, three of them fatally

  This is the part that justifies the task. A double accepts whatever aggregate it is handed, so none
  of the following was visible to any test that had ever run.

  | Repository | Defect | Consequence |
  |---|---|---|
  | `DbCostCenterRepository` | wrote `manager_uid` and `senior_manager_uid` — in no migration, in no table, and in neither `data-model.md` nor the `CostCenter` aggregate's own relationship | **every cost-center write failed** with `Unknown column 'manager_uid'` |
  | `DbRoleAssignmentRepository` | wrote `lock_version` on insert and update, which the table does not have (`Version010000…:64` passes `auditColumns($table, false)`) and `data-model.md` does not declare | **every role-assignment write failed** |
  | `DbCostCenterRepository` | insert omitted `public_id`, which is `NOT NULL` with no default | cost-center write failed on the constraint |
  | `DbExpenseTypeRepository` | insert omitted `public_id` | expense-type write failed on the constraint |
  | `DbRoleAssignmentRepository` | insert omitted `public_id` | role-assignment write failed on the constraint |
  | `DbAuditEventRepository` | `save()` assigned neither the surrogate key nor the `publicId` mirror | an appended audit event could not be found again by `findById()` or `findByPublicId()` |
  | `DbCurrencyRepository` | `mapRow()` never assigned the surrogate key | a re-read currency looked unpersisted |
  | `DbWorkflowDecisionRepository` | `save()` never assigned the `publicId` mirror | `findByPublicId()` could not resolve the row just written |
  | `DbEvidenceRepository`, `DbEvidenceLinkRepository`, `DbExpenseSheetRepository`, `DbExpenseTransactionRepository`, `DbTransactionRevisionRepository` | same `publicId` mirror gap on insert | five more rows unresolvable by public id after saving |

  **All nine are fixed.** The three fatal write paths are the headline: cost-center, role-assignment
  and expense-type administration could not have written a single row, and no test had ever executed
  one. They are latent rather than live only because the endpoints that would call them are still
  open (`T087`); the moment `T082` lands they would have failed on first use.

  **Two of the fixes are removals rather than additions, and the approved artifacts decided the
  direction.** A cost center has no manager: `data-model.md` gives it `id`, `public_id`, `code`,
  `name`, `description`, the validity window, `active`, the audit columns and `lock_version`, and
  states the relationship as "Has effective-dated cost-center-manager and senior-manager **role
  assignments**". `ManagementReviewService::getManagedCostCenterCodes()` already resolves a
  reviewer's scope through `RoleAssignmentRepository`, never through the cost center. So
  `CostCenter::$managerUid`/`$seniorManagerUid` and the port's `findByManagerUid()`/
  `findBySeniorManagerUid()` were removed rather than given columns — leaving the fields in place
  while nothing persisted them would be the worse version of the same defect, because `T082` would
  create a cost center, pass manager uids, and watch them vanish. The test now asserts the
  relationship that replaced them: a senior-manager assignment scoped to a cost center resolves back
  to it. Likewise `RoleAssignment` has no `lock_version` in `data-model.md` and the migration agrees,
  so the repository stopped writing it.

  **`AccountMovement` is the one repository deliberately left alone.** It is a `final readonly`
  aggregate, so its database identity cannot be assigned after construction, and that is correct
  rather than an omission: a movement is a fact, and the caller gets a new instance carrying the
  surrogate key from `mapRow()` on the next read. `findByCommandId()` is the lookup that has to work
  — it is the path a replayed command takes — and it is asserted.

  ## Verification that it is not a vacuous pass

  Both halves were checked by breaking the code, not by reading the test.

  | Mutation | Result |
  |---|---|
  | Put `manager_uid` back into the cost-center insert | fails **5** — the round trip reports `Unknown column 'manager_uid'` |
  | Make `supersedes` read back as the surrogate key instead of the public UUID | fails **1**, `testASecondRevisionSupersedesTheFirstByPublicId` |

  The first proves the harness really executes SQL against MariaDB; the second proves the assertions
  bite on stored *values*, which a test that only asserted "no exception" would have missed.

  ## Shared-database hygiene

  The runner deletes every row it creates, in a `finally`, in reverse insertion order — and it needs
  **two** sweeps, which the first version of this file learned the hard way. Sweeping by surrogate key
  is precise but incomplete: a row is only registered once its id resolves, so a failure *between* the
  insert and the registration leaks it. The first runs of this file leaked **32 rows** into the shared
  MariaDB that way, all of them removed by `roundtrip-leftovers.php --delete`, which matches only
  fingerprints this runner writes. The fingerprint sweep now runs as well, so a crash anywhere still
  cleans up. `roundtrip-leftovers.php` is kept because a shared environment needs a way to check.

  Per-run uniqueness is load-bearing in the same way: `pettycash_account_movements.command_id`,
  `pettycash_evidence_links.token_hash` and `pettycash_command_results.command_id` are all UNIQUE and
  all three repositories are deliberately idempotent per command id, so a fixed value made every run
  after the first a silent no-op — the row was never written and the runner reported one left over
  from the previous run.

  **Result**: `scripts/dev/phpunit` is 311 tests / 1620 assertions / 1 failure (T275, unchanged).
  The 27 account, 23 finance-review, management-review and purchaser contract suites all still pass
  over real HTTP, which is the evidence that nine repository repairs did not change a single response.
  Report: `docs/reports/T236.md`.

  **One thing this task did not repair, deliberately.** `AuditEvent` carries `entityType` and
  `entityId`, and `AuditWriterService` writes both keys — but `pettycash_transaction_audit` has
  **neither** column, so on the transaction-audit path they are silently discarded. That is not this
  task's to decide: `T253` is open and owns exactly this reconciliation for configuration audit, and
  widening it is recorded there rather than duplicated here.


### MEDIUM — plan-mandated audit retention policy

- [X] T237 [HIGH] Implement the two classes `plan.md` mandates for FR-057 audit retention: the `AuditRetentionPolicy` domain value object with the hard-coded 7-year minimum floor in `lib/Domain/Audit/AuditRetentionPolicy.php`, and `RetentionPolicyService` for policy configuration and compliance verification in `lib/Application/Audit/RetentionPolicyService.php`; neither exists today (`lib/Domain/Audit/` contains only `AuditEvent.php` and `Repository/`, and a filename search for `*RetentionPolicy*` returns nothing) and no existing task creates either class, so the open tasks T138 (`tests/Integration/Audit/RetentionPolicyTest.php`) and T100 (`docs/audit-retention.md`) would both assert against a nonexistent subject per plan.md lines 229-230 and Constitution IV (complete — `AuditRetentionPolicy`, `RetentionPolicyService`, the `RetentionPolicyStore` port and its `IAppConfig` adapter, all container-registered; 37 tests across `tests/Unit/Domain/Audit/AuditRetentionPolicyTest.php` and `tests/Integration/Audit/RetentionPolicyServiceTest.php`, mutation-verified. T138 keeps its own filename and its real-repository scope)

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

- [x] T245 [MEDIUM] Bring `npm run typecheck` back to zero errors so the `npm run check` gate mandated by `AGENTS.md` can pass end to end; the current 22 errors are all in the two RED suites (20 in `tests/Frontend/Component/ManagementReview.spec.ts`, 2 in `PurchaserFlow.spec.ts`) and none in `src/`, and the 20 ManagementReview errors are the RED fixtures passing `allowedActions`, `actorName`, `uid`, and `ownerId` props and fixtures that the unimplemented US2 review components do not declare, so this task reconciles the RED spec prop/fixture contract with the component prop contracts produced by T181 and resolves the remaining `PurchaserFlow.spec.ts` errors alongside T164; it must not be closed by loosening `tsconfig.json` strictness, suppressing the suites, or excluding them from the typecheck project (partial) (done - `npm run typecheck` now reports zero errors and `npm run lint` zero errors, down from 20 and 1. The 20 ManagementReview errors were all fixture/prop shapes that the components do not declare, so the RED spec was reconciled against the prop contracts T181 produced rather than the other way round: queue items became `TransactionSummary` (`sheetId`, `activeRevision`, `finalOutcome`, `evidenceCount`, `version`, `latestDecision`) instead of the ad-hoc `ownerId`/`revision`/`costCenterId` shape, decisions took the nested `actor: { userId, displayName, role }` with `decidedAt` instead of `actorName`/`actorRole`/`createdAt`, the auth store user became `{ userId, displayName, roles }` instead of `{ uid, roles, costCenterCodes }`, the four `DecisionForm` mounts now pass the required `allowedActions` prop, and the evidence fixtures were completed to the declared `Evidence` shape. The spec-invented `priorDecisions` array was removed from the senior queue items: it is not in the contract's `TransactionSummary` and `SeniorQueueView` reads `item.latestDecision`, so nothing consumed it. The two `PurchaserFlow.spec.ts` errors were the unused `id` parameter on the stubbed `accountById`, now `_id`. Nothing was loosened: `tsconfig.json` strictness is untouched, neither suite is suppressed, and both remain in the typecheck project. **`npm run check` still cannot pass end to end**, and that is not a T245 defect: `npm run test` reports the same 58 failed / 18 passed / 26 errors it reported before this change, byte for byte, because those suites are RED by construction — they assert the US2 review behaviour that is not implemented yet. The gate closes when that implementation lands, not here. Verification: `npm run check:portability`, `typecheck`, `lint`, and `stylelint` all pass and `build` succeeds; lint retains 105 pre-existing warnings, none of them errors)

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

- [x] T253 [MEDIUM] Reconcile the configuration-audit subject identity between the approved data
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

  **Extended by T236, which found the same disagreement on the transaction-audit side and in the
  opposite direction.** `pettycash_transaction_audit` has **no** `entity_type` and no `entity_id`
  column at all, and `DbAuditEventRepository` never writes either — so on the transaction path
  `AuditEvent::$entityType` and `AuditEvent::$entityId` are accepted by the domain, handed to
  `AuditWriterService::append()`, and then silently discarded, while the read back reports `""` and
  `null`. Confirmed against the live table, not inferred. The subject on that path is carried instead
  by `sheet_id` / `transaction_id` / `revision_id` plus `before_json` / `after_json`, which is what
  `data-model.md` declares, so the two fields are redundant with the foreign keys rather than lost
  information — but the redundancy is invisible at every layer, and whoever builds the audit timeline
  under `T093`/`T154` will meet both spellings. **The reconciliation now has to cover the transaction
  table as well as the configuration one, and either direction must be applied to the migration, the
  writer, the aggregate and the data model together.** Not repaired by T236, which is why this task
  stays open.


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

- [x] T256 [CRITICAL] Stop hardcoding the currency when hydrating a revision's amount in
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
  the fix must be covered by a persistence test using a non-IRR currency (contradicts) (done - `mapRow()`
   now resolves the currency through revision -> transaction -> sheet -> currency by joining
   `pettycash_transactions` and `pettycash_sheets` to `pettycash_currencies` and reading `c.code`, instead
   of assuming `IRR`; because the same integer denotes a different real amount in every currency, an
   unresolvable currency now raises a `LogicException` naming the revision rather than silently
   substituting a default, matching the existing `requireTransactionDbId()` policy against writing a
   placeholder that would orphan the row. Covered by a real-MariaDB persistence test: the contract
   fixture seeder grew a `usd` state that seeds a second sheet, transaction, and revision denominated in
   USD (10.00 stored as 1000 minor units), and
   `PurchaserFlowControllerTest::testRevisionReadsBackTheSheetCurrencyRatherThanRial` asserts
   `amount.currency === 'USD'`, `amountMinor === 1000`, and `minorUnit === 2`. It was observed red with
   `'IRR'` where `'USD'` was expected, and the companion
   `testRialSheetStillReadsBackRial` guards that resolving from the sheet does not degenerate into always
   answering with the second fixture's currency. Suite went from 164 tests / 694 assertions / 10 failures
   to 166 / 702 / 10 — the two new tests pass and the failing set is byte-identical to the pre-change
   baseline, so no existing behaviour regressed)

### HIGH — the review scope check cannot deny

- [x] T257 [HIGH] Build the acting user's responsibility scope from their actual role assignments
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
  vacuous comparison into a permanently-true one and hide the defect rather than close it (contradicts) (done -
  `ManagementReviewService::recordDecision()` now builds the `AuthorizationContext` scope with a new
  `getManagedCostCenterCodes()`, which reads the actor's active `RoleAssignment` rows for the role the
  stage requires and resolves each numeric `costCenterId` to its code, instead of reusing the cost center
  of the record under review. The subject code comes from a new `resolveCostCenterCode()`, which prefers
  the revision's submission-time `costCenterCode` snapshot and falls back to the numeric join key through
  `CostCenterRepository::findById()`. Resolving both sides to codes mattered: the previous code passed
  `revision()->costCenterId()`, which is the cost center's public UUID, into `findByCode()` — a lookup
  that expects a code such as `CC-100` and therefore always returned null — so the scope comparison was
  not only vacuous but also compared unlike units, and `canReview()`'s strict `in_array` could never have
  matched a real code either. Finance keeps its shared queue and correction stays owner-only, so both
  stages correctly carry an empty scope. `AuthorizationService` itself is unchanged, so the existing
  `AuthorizationServiceTest` and `ManagementReviewAuthorizationTest` code-based matrix still holds.
  Covered by two live contract tests against a new `scope` fixture state that seeds a second cost center
  (`CC-200`) the acting user holds no assignment for, with a transaction inside it awaiting cost-center
  review: `testDecisionOnAnUnmanagedCostCenterIsRefused` was observed red with `201` where `403` was
  expected, and `testDecisionInsideTheManagedCostCenterIsStillAllowed` asserts `201` in the same state so
  the check cannot degrade into a blanket refusal. Fixing that also exposed a latent fixture defect: the
  seeder wrote `status => 'AWAITING_COST_CENTER_REVIEW'` on `pettycash_sheets`, which is a transaction
  status and not a `SheetStatus` case value, so any state that read a submitted sheet raised
  `"AWAITING_COST_CENTER_REVIEW" is not a valid backing value for enum SheetStatus`; the baseline is now
  `COST_CENTER` for cost-center review and `CORRECTION` for the correction state. Suite went from 166
  tests / 702 assertions / 10 failures to 168 / 714 / 10 with the failing set identical to the baseline)

### HIGH — draft delete is a no-op

- [x] T258 [HIGH] Make `TransactionService::deleteTransaction()` in
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
  genuinely gone (contradicts) (done - `TransactionService::deleteTransaction()` now requires
  `TransactionStatus::Draft` in addition to the open sheet, because a returned transaction sits in an open
  sheet again and the sheet check alone would have let a purchaser erase submitted business history. Inside
  the transaction it removes the transaction's own AppData binaries through `PrivateFileStore` while the keys
  are still known, drops it from the sheet aggregate, and calls a new
  `ExpenseTransactionRepository::delete()`. That new port method is implemented in
  `DbExpenseTransactionRepository` as three query-builder deletes — revisions first, then
  `pettycash_evidence`, then the transaction — because `pettycash_transaction_revisions.transaction_id`
  points at the row being removed and the schema declares no foreign keys, so leaving either behind would
  strand rows no query would ever reach. `EvidenceRepository::delete()` was added for the same
  pre-submission reason; after submission evidence is still only ever deactivated. `TransactionService`
  gained `EvidenceRepository` and `PrivateFileStore` dependencies, wired in both `lib/AppInfo/Application.php`
  and `tests/bootstrap.php`, and both in-memory doubles implement the new port methods. No raw SQL and no
  delete-`LIMIT` were used, so `npm run check:portability` stays green. The RED state was established
  honestly: with the repository delete temporarily swapped back for a `save()`, the new
  `testDeletingADraftTransactionRemovesItFromTheDatabase` failed with `200` where `404` was expected —
  a `204` over a record that is still there — and passed once the delete was restored, so the test detects
  the defect rather than merely describing the fix. `testDeletingASubmittedTransactionIsRefused` asserts
  both the refusal and that the transaction survives it. Suite went from 168 tests / 714 assertions /
  10 failures to 170 / 718 / 10 with the failing set identical to the baseline)

### HIGH — evidence deactivation and streaming still read AppData inside the transaction

- [x] T259 [HIGH] Apply the T247 no-read-back rule to the two remaining evidence paths, because
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
  compensation, and must keep the AppData read outside the open database transaction (contradicts) (done -
  the recorded root cause turned out to be wrong, and the real one was more basic. The AppData read was not
  a transaction-visibility problem at all: it failed inside *and* outside a transaction, verified directly
  against the real `AppDataEvidenceStore` in the container. `putEvidence()` writes into nested folders and
  returns the full `evidence/<transaction>/<revision>/<filename>` key, but `read()`, `deleteDraft()`,
  `exists()`, and `getFileSize()` all handed that whole key to `getFile()` on the evidence folder as if it
  were a single filename, so no key this class had ever written could resolve. A new `findFile()` walks the
  path segments and drops the leading `evidence` segment, since the walk already starts inside that folder;
  all four methods now go through it. The second defect was independent: `DbEvidenceRepository::save()`'s
  update branch set `updated_at` and `updated_by`, and `pettycash_evidence` has no such columns — the
  migration records `uploaded_by`/`uploaded_at` and `deactivated_by`/`deactivated_at`/
  `deactivation_reason`, with no update pair because an evidence row is only ever deactivated. Every
  deactivation therefore failed with `Unknown column 'updated_at' in 'SET'` and the endpoint returned HTTP
  500. Those two writes are gone, and the deactivation columns are the record of the change. Together the
  two fixes take `DELETE /evidence/{id}` from 500 to 200 and `GET /evidence/{id}/content` from 500 to 200,
  and the suite fell from 10 failures to 9. One residual failure is left open on purpose:
  `testGetEvidenceContentReturns200WithStream` now reaches its content-type assertion and fails because the
  controller serves the stored `application/pdf` while `contracts/ocs-api.yaml:661` declares
  `application/octet-stream` for that response and FR-060 requires previewing to work. That is a
  contradiction between the approved contract and the requirement, not something to settle silently here, so
  it is left for convergence to record)

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

- [x] T269 [HIGH] Make `ReviewController::decide()` at `lib/Controller/ReviewController.php:116`
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

- [x] T270 [HIGH] Catch `\Throwable` rather than `Exception` in the failure handling of
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
  `WorkflowStage::from($stage)` (done - `Decision::from()` is now wrapped in its own `try` that catches `\ValueError` and returns 422 with a `VALIDATION_FAILED` code and a `decision` field error, instead of letting it escape three `Exception` handlers that could never see it because `\ValueError` extends `\Error`. The main handler ends in `catch (\Throwable $e)` routed through `OcsErrorMapper::map()`. The same latent defect was fixed where T269/T270 recorded it: `QueueController::index()` caught `Exception` around `WorkflowStage::from()` and now catches `\ValueError|\TypeError`, and the two remaining `catch (Exception $e)` handlers in `ReviewController::index()` and `::returns()` now catch `\Throwable` and go through the mapper, so no handler in either controller can be bypassed by an `Error`. `testRecordDecisionInvalidDecisionReturns400Or422` was observed red and now passes) (contradicts)

### HIGH — validation failures return 400 with a non-OCS body instead of 422

- [x] T271 [HIGH] Route the validation failures of `ReviewController::decide()` through
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
  failure, so no new service or dependency is required (done - `InvalidArgumentException` is now mapped through `OcsErrorMapper::validation()`, so a missing explanation on REJECT/RETURN, a stale version, and an out-of-state decision each answer 422 with a stable `VALIDATION_FAILED` code and field errors instead of a bare `{"error": "..."}` 400 body. A new `fieldErrorsFor()` helper attaches the offending field — `explanation`, `expectedVersion`, or `stage` — by matching the service message, so the caller can highlight the input rather than only read a sentence. The endpoint also validates a blank `decision` up front through the mapper, matching `TransactionController::decide()`. `testRecordDecisionRejectRequiresExplanation` and `testRecordDecisionReturnRequiresExplanation` were both observed red with 400 against an expected 422 and now pass) (contradicts)

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

- [X] T272 [MEDIUM] Add a structural test that parses every `['name' => '…#…']` entry in
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
  list owned by those task IDs rather than deleted (complete — `tests/Integration/RouteResolutionTest.php`, 8 tests. The assertion is
  inverted rather than left red: the test asserts the set of unresolved routes is **exactly** the
  documented, task-owned `KNOWN_UNRESOLVED` list of 15, so it fails both when a new route is registered
  ahead of its implementation and when a task lands and leaves a stale entry. Parses tokens rather than
  reflecting, because `tests/bootstrap.php` does not bootstrap Nextcloud and autoloading a controller
  dies on its own `extends OCP\AppFramework\Controller`. Mutation-verified in four directions. Also
  asserts T235's two unrouted duplicate controllers by name)

## Phase 31: Convergence (Traceability Range Excludes the Schema and Portability Requirements)

**Purpose**: Record the one gap found by assessing `spec.md`, `plan.md`, and `tasks.md` against the
repository state after Phase 30. Every other remaining-work item is already owned by an existing
incomplete task and is deliberately not duplicated here.

### MEDIUM — the traceability task's stated range is narrower than the approved requirement set

- [ ] T273 [MEDIUM] Extend the requirement-to-test traceability matrix in
  `specs/001-pettycash-v1-core/checklists/traceability.md` to the full approved requirement set —
  FR-001 through FR-081 and SC-001 through SC-014 — because T134 scopes the same deliverable to
  "FR-001 through FR-076 and SC-001 through SC-012" while `spec.md` defines FR-001–FR-081 and
  SC-001–SC-014, and `plan.md:60` still declares "76 functional requirements". FR-077 (declared
  backend portability), FR-078 (`pettycash_` table prefix), FR-079 (`pettycash_*` constraint
  naming), FR-080 (clean-installation storage with no pre-V1 import or preservation contract), and
  FR-081 (schema-change acceptance over clean install and the forward migration chain), together with
  SC-013 (per-backend portability acceptance) and SC-014 (100% `pettycash_` clean-install schema),
  are therefore outside the matrix T134 will produce. Those five requirements and two success
  criteria are buildable obligations with real verification tasks — T239 provides the four-backend CI
  matrix for FR-077/SC-013, and T260, T262, T263, and T264 provide the namespace, reset, clean-install
  acceptance, and identifier-limit work for FR-078/FR-079/FR-081/SC-014 — so omitting them from the
  traceability deliverable would let a genuinely verified requirement read as untraceable, and
  Constitution V requires documentation claims to be updated in the same change whenever their claims
  are affected. The matrix must map each of FR-001–FR-081 and SC-001–SC-014 to the task and test
  that satisfies it, and must record `plan.md:60`'s "76 functional requirements" as superseded by the
  81-requirement set without editing `plan.md` itself, which convergence and this task must not
  rewrite (partial)

## Phase 32: Convergence (Unchecked Tasks Whose Deliverable Already Exists)

**Purpose**: Record the repository-wide task-state drift that implementation surfaced on pass 1 of
this run, after T256. Convergence may not rewrite existing task state, so the reconciliation is
appended here as work for `/speckit.implement` to perform and verify.

### HIGH — 21 unchecked tasks would re-implement code that already exists

- [x] T274 [HIGH] Verify each unchecked task in the table below against the repository and mark it
  `[X]` only where the named deliverable is genuinely present and correct, leaving open every task
  whose work is genuinely absent, because every one of these is unchecked while its artifact is
  already in the tree, and `/speckit.implement` selecting them would rewrite completed work that
  `AGENTS.md` forbids redoing and would report already-implemented work as delivered. The drift came
  from Phase 13, whose tasks were written against a repository state that no longer holds: all 21
  `(missing)` annotations are false, and three unchecked **original** Phase 5/6 tasks are already
  satisfied too. Convergence deliberately does not mark these off itself — appending this task is the
  only sanctioned route, so the marking must be done here against evidence, not assumed.

  | Task | Named deliverable | Observed state |
  |---|---|---|
  | T064 | `src/services/accountsApi.ts`, `src/stores/accounts.ts` | both exist |
  | T070 | `lib/Application/Expense/CreateCorrectionDraft.php` | exists |
  | T071 | `lib/Application/Workflow/ResubmitCorrection.php` | exists |
  | T172 | `tests/Unit/Domain/Workflow/ManagementWorkflowTest.php` | exists; T044 already `[x]` |
  | T173 | `tests/Unit/Security/ManagementReviewAuthorizationTest.php` | exists; T045 already `[x]` |
  | T176 | `lib/Domain/Workflow/WorkflowStateMachine.php` | exists; T048 already `[x]` |
  | T177 | `lib/Domain/Workflow/WorkflowDecision.php` | exists; T049 already `[x]` |
  | T178 | `lib/Application/Workflow/ManagementReviewService.php` | exists; T050 already `[x]` |
  | T179 | `lib/Controller/ReviewController.php` | exists; T051 already `[x]` |
  | T180 | `src/services/reviewsApi.ts`, `src/stores/reviews.ts` | both exist; T052 already `[x]` |
  | T181 | `src/views/reviews/*`, `src/components/review/*` | exist; **T053 is open**, reopened by T286 — the three browser-measured FR-071 clauses cannot be settled under jsdom and belong to T116/T122/T123 (corrected by T298, case 2) |
  | T183 | `tests/Unit/Domain/Workflow/FinanceDecisionPolicyTest.php` | exists; T055 already `[x]` |
  | T187 | `lib/Domain/Account/AccountMovement.php` | exists; T059 already `[x]` |
  | T189 | `lib/Application/Workflow/FinanceSettlementService.php` | exists; T061 already `[x]` |
  | T190 | `lib/Application/Workflow/FinanceQueue.php` | exists; T062 already `[x]` |
  | T191 | `lib/Controller/ReviewController.php`, `lib/Controller/AccountController.php` | both exist; T063 already `[x]` |
  | T192 | `src/services/accountsApi.ts`, `src/stores/accounts.ts` | both exist |
  | T195 | `tests/Unit/Domain/Expense/TransactionRevisionTest.php` | exists; **T067 was open** and is now `[x]`, corrected by T298 case 1 — it was never checked when this row was written |
  | T196 | `tests/Integration/Workflow/TransactionCorrectionTest.php` | exists; **T068 was open** and is now `[x]`, corrected by T298 case 1 — it was never checked when this row was written |
  | T198 | `lib/Application/Expense/CreateCorrectionDraft.php` | exists |
  | T199 | `lib/Application/Workflow/ResubmitCorrection.php` | exists |

  Two qualifications the verification must respect. First, **T191 is only satisfied for the routes
  and controller classes that exist**: `AccountController` currently declares `index` only, so
  `account#create`, `account#charge`, and `account#movements` are still registered in
  `appinfo/routes.php` without an action, which is T087's work and must stay open — T063's
  checkbox already records the class, not the missing actions. Second, **T064, T192, T070, T071,
  T187, T189, and T190 name files that exist but whose behaviour is still under active defect
  repair** (T257, T269, T270, T271), so closing them records that the deliverable exists, not that
  its behaviour is correct. The genuinely absent neighbours — `T182`, `T184`, `T185`, `T186`,
  `T194`, `T197`, `T202`, `T203`, `T204`, the `T205`–`T209` umbrellas, every US5/US6/US7 service
  and controller, and `docs/audit-retention.md` — were confirmed missing and stay open untouched
  (contradicts) (done - all 21 tasks verified against the repository and marked `[X]`; every
  checkbox line was confirmed unique before editing, so no other task's text was touched. The
  four whose parent task was still unchecked were verified by behaviour rather than by existence
  alone: `CreateCorrectionDraft::execute()` enforces the awaited-correction state, the optimistic
  lock, and owner-only editing, and `TransactionRevision::correction()` clones into a new revision
  numbered `number + 1` that links `supersedes` to the prior revision while leaving that prior
  revision untouched; `ResubmitCorrection::execute()` runs the command through
  `IdempotencyService`, validates cost center, expense type, description, and evidence, activates
  the draft, reroutes to cost-center review, and writes the audit event; `accountsApi.ts` exposes a
  typed, paginated `listAccounts` and `stores/accounts.ts` a real Pinia store. The other seventeen
  are Phase 13 duplicates whose parent task was already `[X]` and whose file exists. T191 was
  closed as written — the qualification it records still holds, because `AccountController` declares
  only `index`, so `account#create`, `account#charge`, and `account#movements` remain T087's open
  work and the unresolved-route set is still owned by T087, T096, T112, and T272. Task counts moved
  from 159 unchecked / 114 checked to 139 / 135)

## Phase 33: Convergence (Evidence Streaming Contract Conflict and FR-058 Deletion Path)

**Purpose**: Record what implementation found while completing T259. Both findings below were
surfaced by that task, neither is covered by an existing task, and neither may be settled by
choosing one artifact's word over the other's.

### MEDIUM — the evidence content type is fixed by the contract and contradicted by FR-060

- [ ] T275 [MEDIUM] Reconcile the evidence streaming response before `contracts/ocs-api.yaml` is
  treated as verified, because the approved contract and the functional requirement disagree about
  the same response. `contracts/ocs-api.yaml:657-662` declares exactly one success content type for
  `GET /evidence/{id}/content` — `application/octet-stream` — and
  `PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream` asserts it, while
  `EvidenceController::content()` at `lib/Controller/EvidenceController.php:274` sets
  `'Content-Type' => $evidence->mediaType`, which for a PDF upload is `application/pdf`. FR-060
  requires that authorized users be able to **preview** supported evidence, and an
  `application/octet-stream` response cannot be previewed in a browser — it downloads. So the
  contract as written makes the FR-060 preview journey unimplementable, and the implementation as
  written violates the contract. T143 is marked complete and asserted nothing about the content
  type, which is why the contradiction survived: the endpoint returned HTTP 500 until T259, so the
  status assertion failed first and the content-type assertion was never reached. T259 fixed the
  500 and deliberately stopped at that point rather than quietly changing either side. Constitution
  Governance requires a conflict between authoritative artifacts to be reconciled in the artifact
  rather than chosen silently, so the decision belongs to the maintainer: either the contract is
  amended to describe a stored-media-type response so FR-060 preview works, or FR-060 is clarified to
  mean download-only and the implementation is corrected to send `application/octet-stream`.
  Whichever is chosen, the contract test must be updated in the same change so the two cannot drift
  apart again, and `Content-Disposition` already distinguishes `inline` from `attachment`, so
  preview-vs-download remains expressible once the media type is decided (contradicts)

### MEDIUM — the draft-delete evidence path unlinks binaries for submitted transactions

- [ ] T276 [MEDIUM] Route post-submission evidence removal through deactivation rather than
  unlinking, because `EvidenceController::destroy()` calls `EvidenceService::deleteDraftEvidence()`
  for every state — its own comment at `lib/Controller/EvidenceController.php:228` says "we'll
  deactivate in both cases" and then does the opposite — and that method calls
  `fileStore->deleteDraftEvidence()` at `lib/Application/Evidence/EvidenceService.php:106` before it
  deactivates the metadata. So a submitted transaction's evidence binary is removed from AppData
  even though the row survives as inactive, which contradicts FR-058's rule that evidence is
  deactivated rather than deleted after submission and FR-060's requirement that authorized users
  can still preview it; the metadata row remains and now points at bytes that no longer exist. The
  same method also carries a self-admitted gap at `EvidenceService.php:103-104` — "This check will be
  enhanced with proper transaction state verification" — so nothing verifies the transaction is
  actually in draft state before the unlink happens. T072 already owns "post-submission removal
  deactivates rather than deletes" for `EvidenceService.php` and stays open, so this is recorded
  rather than duplicated: the added value is that T259 established the delete path returns HTTP 200
  again, and `AppDataEvidenceStore::deleteDraft()` silently swallows `NotFoundException`, so a
  successful-looking response does not by itself prove the unlink was the correct operation (partial)

## Phase 34: Convergence (P1 Contract Path Repaired — Recorded After the Fact)

**Purpose**: Record the defects implementation found and fixed while working the P1 contract
path. None of these had a covering task, so the fixes are recorded here to keep them traceable
rather than left as undocumented changes. All four are marked complete; each was proven red before
its fix and the suite was re-run against the full prior failing set after every step.

- [x] T277 [CRITICAL] Repair the four P1 contract-path failures that remained after T269–T271,
  because the OCS contract suite could not go green and each failure hid a distinct real defect
  (contradicts) (done - `POST /sheets/{id}/submit` answered 422 "At least one transaction is
  required" for sheets that had one, because `DbExpenseSheetRepository` deliberately does not
  compose the sheet's transactions and so a sheet loaded from the database always carried an
  empty collection; `SubmitSheet` now attaches them via `ExpenseTransactionRepository::findBySheetId()`
  before validating, with the new dependency wired in `lib/AppInfo/Application.php` and
  `tests/bootstrap.php`. `POST /transactions/{id}/correction` answered 403 for a correction the
  caller was entitled to make, because `TransactionRevision::correction()` clones the business
  fields into a fresh aggregate without the owning-transaction join key, and
  `CreateCorrectionDraft` never carried it over, so the save was refused by
  `requireTransactionDbId()`; it now sets `transactionDbId` exactly as `TransactionService::createTransaction()`
  does. `POST /transactions/{id}/resubmit` answered 422 "Cost center not found: 22222222-…" because
  both `SubmitSheet` and `ResubmitCorrection` passed `revision()->costCenterId()` — the cost
  center's public UUID — to `CostCenterRepository::findByCode()`, which looks up a business code
  such as `CC-100`; both now resolve through a private helper that tries the numeric join key,
  then the public UUID, then the code snapshot. `PATCH /transactions/{id}` answered 422
  "Revision is incomplete" for a legitimate partial edit, because building a new revision left
  description, cost center, and expense type null whenever the caller omitted them, so the
  purchaser's own transaction failed `assertSubmittable()` — and had it passed it would have
  silently dropped real business data; omitted fields now carry forward from the current revision.
  Two audit defects were fixed alongside: `SubmitSheet` and `ResubmitCorrection` both passed
  `AuditWriter::append((array) $auditEvent)`, but `append()` reads snake_case keys, so a cast
  object yielded camelCase properties, `command_id` was never found, and the insert failed on the
  NOT NULL column with an HTTP 500; both now pass a proper snake_case array. Suite went from 170
  tests / 750 assertions / 5 failures to 170 / 794 / 1, and the single remaining failure is T275's
  contract-versus-FR-060 conflict, which is deliberately unresolved)

- [x] T278 [HIGH] Make the contract fixture seeder a true reset, because three order-dependent
  failures were caused by its own leftover state rather than by the code under test, which
  contradicts the promise in its docblock that "every run is a reset" (contradicts) (done - the
  seeder cleared decisions, evidence, and audit but never the rows the suites create *on the
  fixture sheet*: `POST /sheets/{id}/transactions` writes a real draft with only an expense date
  and an amount, those rows accumulated, and submission then walked them and failed on a
  transaction that has no cost center, so `testSubmitSheetReturns200WithSubmittedSheetDetail`
  passed or failed depending on which tests had run first. It also never removed correction
  revisions, so a correction run left `revision_number 2` behind and the next run violated
  `pettycash_transaction_revisions_number_uniq` with `Duplicate entry '42-2'`. The seeder now
  deletes the stray transactions' decisions, evidence, audit, revisions, and the transactions
  themselves, then any fixture revision above number 1. One builder is used per statement for the
  stray-transaction select: separate `getQueryBuilder()` instances for `expr()` and
  `createNamedParameter()` leave the parameter unbound and emit it verbatim, producing
  `... (:dcValue1) AND (`id` <> :dcValue1)`, which is the same trap the `upsert()` docblock already
  documents. All five states — draft, submitted, correction, usd, scope — seed cleanly and
  repeatedly)

- [x] T279 [MEDIUM] Report the sheet `transactionCount` honestly in the sheet detail response,
  because `SheetController::formatSheet()` reports `count` from an aggregate that is still empty at
  that point, so a submitted sheet answers `200` with `status: COST_CENTER` and
  `transactionCount: 0` — a response that contradicts itself and understates the purchaser's
  record (partial) (recorded, not started: T277 fixed the submission path but the read model for
  the sheet detail response is a separate concern and is left open rather than folded into a
  completed task) (done - the count is now derived from a new `ExpenseTransactionRepository::countBySheetId()`
  rather than from the `ExpenseSheet` aggregate, which `DbExpenseSheetRepository` does not compose on read
  and which therefore always reported zero. The port method, the `DbExpenseTransactionRepository`
  implementation, and the in-memory double all carry it, and `SheetController::formatSheet()` takes the
  repository through its constructor — the AppFramework resolves it, so no service registration changed.
  Counting through the repository rather than `count($sheet->transactions())` also avoids loading every
  revision just to produce a number. Verified against the running container: the fixture sheet now answers
  `transactionCount: 1` against `status: OPEN`, where it previously answered `0` alongside
  `status: COST_CENTER` after submission. Two live contract tests cover it: one requires the count to
  reflect the seeded transaction, and one submits the sheet and then requires both `status: COST_CENTER`
  and a count of 1, which guards the derivation against a future change that reads a stale snapshot.
  Suite went from 172 tests / 826 assertions / 1 failure to 174 / 831 / 1, the remaining failure still being
  T275. `npm run check:portability` stays green and Psalm reports no issues in the changed files)

## Phase 35: Convergence (The OCS API Does Not Implement Its Own Contract)

**Purpose**: Record the cross-cutting drift that T245 exposed and deliberately did not fix. T245's
mandate was the frontend type gate, and it recorded that "the API does not currently implement the
contract it is typed against". Assessing that properly shows it is not a type-level nuisance: the
frontend is written to the contract and the backend answers a different shape, so the affected
views cannot work in a browser at all. No existing task covers the payload shapes — T272 covers
route resolvability, and T124 would eventually *detect* the drift without fixing it.

### CRITICAL — the transaction payload does not match `TransactionSummary`/`TransactionDetail`

- [x] T280 [CRITICAL] Make the transaction OCS responses conform to
  `contracts/ocs-api.yaml`, because the backend answers a shape the contract does not describe and
  the frontend is written to the contract, so the review and transaction views cannot render. The
  contract requires `TransactionSummary` to carry `activeRevision`, `evidenceCount`, and
  `version`, and `TransactionDetail` to add `allowedActions`, `decisions`, and `evidence`. Both
  `TransactionController::formatTransaction()` (`lib/Controller/TransactionController.php:322`) and
  `ReviewController::formatTransaction()` (`lib/Controller/ReviewController.php:164`) instead return
  a flat `revision` object, add an `ownerId` the contract does not declare, and omit all three of
  `allowedActions`, `decisions`, and `evidence` entirely. Inside that object the contract requires
  `costCenter` and `expenseType` as `EntitySnapshot` values, while both formatters emit the scalar
  `costCenterId` and `expenseTypeTitle`; and the contract's required `minorUnit` is being produced
  from the hardcoded presentation tables that T266 already owns. The consequences are not
  theoretical: `src/views/reviews/CostCenterQueueView.vue:59-60`, `SeniorQueueView.vue:59-60`, and
  `src/views/sheets/SheetDetailView.vue:194-197` all read `item.activeRevision.costCenter.name`,
  `item.activeRevision.expenseType.name`, and `item.activeRevision.amount`, so `item.activeRevision`
  is `undefined` on every live response and each of those templates dereferences it. Because
  `decisions` is never returned, `PriorDecisionHistory.vue` cannot be fed from the API even though
  the component and its props are complete, which is why the US2 advisory-decision journey in T054
  cannot be built on a working endpoint. The unit and integration suites do not catch this because
  `tests/bootstrap.php` wires in-memory doubles and the contract tests assert the OCS envelope and
  status rather than the payload shape, so a green suite is compatible with every view being broken.
  This task must align the three formatters with the approved schemas, add a contract test that
  asserts the declared required keys are present and the undeclared ones absent for
  `GET /transactions/{id}`, the queue endpoints, and `GET /sheets/{id}/transactions`, and resolve
  `minorUnit` through `CurrencyRepository` rather than the T266 tables so the shape and the digit
  count are corrected in one change (contradicts) (done - a new
  `lib/Infrastructure/Http/TransactionPayloadFormatter.php` is now the single source of the transaction
  payload, and both controllers delegate to it: `summary()` for queue and list items, `detail()` for single
  transactions, and a public `revision()` for the two endpoints that answer with a bare revision. Leaving the
  formatting duplicated in the controllers is what let the two copies drift apart in the first place, so the
  duplication itself had to go. `TransactionController::CURRENCY_MINOR_UNITS` and
  `ReviewController::getMinorUnit()` are both deleted — they had no other reader — so `minorUnit` now comes
  from the administrator-configured `Currency.minor_unit` via `CurrencyRepository`, which is most of what
  T266 asks for; T266 stays open for the part this deliberately does not do, namely turning an unknown or
  inactive currency into an explicit error instead of a zero-digit fallback. `allowedActions` is derived from
  the same server-side `AuthorizationService` decision and the actor's real role assignments that the
  command path enforces, so a hidden action and a refused action cannot disagree, which also means the
  T257 scope fix is visible to the interface rather than only to the server. Verified against the running
  container: `GET /transactions/{id}` now returns exactly the contract's members — `id, sheetId, activeRevision,
  stage, status, finalOutcome, latestDecision, evidenceCount, version` plus `allowedActions, decisions,
  evidence` — with `costCenter` and `expenseType` as `EntitySnapshot` objects and no `revision`, `ownerId`,
  `costCenterId`, or `expenseTypeTitle` anywhere. Two live contract tests assert that: the detail test
  requires every declared member and forbids the four undeclared flat shapes, and the queue test requires
  `activeRevision`/`evidenceCount` while forbidding `decisions`, so the two response kinds cannot be
  conflated again. **Correction to this task's recorded evidence**: it claimed both controllers emit the
  scalar `costCenterId`/`expenseTypeTitle`. That was only true of `ReviewController`; `TransactionController`
  already emitted `EntitySnapshot` objects, so its drift was the `revision`-versus-`activeRevision` key, the
  undeclared `ownerId`, and the missing `latestDecision`/`evidenceCount`/`allowedActions`/`decisions`/`evidence`.
  Three tests that asserted against the old flat shape (`testRevisionReadsBackTheSheetCurrencyRatherThanRial`,
  `testRialSheetStillReadsBackRial`, and T255's `testDecisionDoesNotEraseTheRevisionBusinessFields`) were
  updated to the contract path rather than left passing against a shape the contract does not describe.
  Suite went from 170 tests / 794 assertions / 1 failure to 172 / 826 / 1, the remaining failure still being
  T275)

### HIGH — the sheet payload omits the stage-approved totals FR-062 requires

- [x] T281 [HIGH] Add the `totals` object to the sheet OCS responses, because
  `contracts/ocs-api.yaml` lists `totals` among the required members of `SheetSummary` and
  `SheetController::formatSheet()` never emits it, so no response has ever carried totals. FR-062
  requires the headline sheet totals to be presented as separate stage-approved columns —
  Cost Center Approved, Senior Approved, and Finance Approved — alongside the latest effective
  outcome, and the plan repeats that requirement in its Complexity Tracking section; with `totals`
  absent there is nowhere for those three figures to be shown, so the requirement is unimplemented
  end to end rather than merely mis-formatted. The same formatter also returns an `accountId` the
  contract does not declare, and its `transactionCount` is T279's separate defect. T105 owns the
  indexed `SheetSummaryQuery` projection that computes these figures (now delivered by T105), so this
  task depended on T105 and must not be closed by a hardcoded or empty `totals` object. The contract
  test added by T280 must cover the sheet endpoints too (done - `SheetPayloadFormatter` in `lib/Infrastructure/Http/` is now the single source of truth for the sheet payload, for the same reason `TransactionPayloadFormatter` exists, and `SheetController::formatSheet()` is a one-line delegation. It emits all eight `SheetTotals` members with the contract's `Money` shape, and the `accountId` the contract does not declare is gone. **`submitted` is not derived**: `data-model.md:184` defines `submitted_total_minor` as a "snapshot at first submission", held on the sheet and nullable, and deriving it from the current revisions would make it move on every correction — the opposite of what a snapshot is for. Six contract tests were added covering the whole `SheetTotals` schema on `GET /sheets/{id}` and `GET /sheets`, the real 100,000-minor-unit fixture transaction summing into `current` and `effectivePending`, the configured `minorUnit` (2 for the USD fixture sheet), `submitted` moving 0 → 100,000 across a submission, and the absent `accountId`. `scripts/dev/phpunit` is 190 tests / 937 assertions / 1 failure, the failure being T275. **The `totals` are not hardcoded and the projection is load-bearing**: replacing the query call with `SheetTotals::fromRows([])` — exactly the shortcut this task forbids — fails three of the new tests, and they were re-run against a settled opcode cache to be sure of it (see T288)

## Phase 36: Convergence (The Checked Frontend Tasks Are Not True, and the RED States Measuring Them Are Invalid)

**Purpose**: Record what the quality gate actually reports. `npm run check` is red — `scripts/dev/phpunit`
is 174 tests / 831 assertions / 1 failure (the deliberately unresolved T275 artifact conflict), but
`npm test` is **58 failed / 18 passed with 26 unhandled errors**, and nothing in the 134 open tasks
owns any of it. Two independent problems are stacked here and neither is a behaviour gap: the US2
suite measures nothing at all because its harness crashes before any assertion runs, and the US1
suite's 18 green tests pass *while* an unhandled rejection is raised. Under Constitution V's
Delivery rule 8 a task is complete only when implementation, verification, and task status agree,
so `T034`, `T042`, `T047`, and `T053` are all checked against a gate that fails. Convergence may
only append, so this phase adds the work rather than un-checking them.

### CRITICAL — the US2 RED state is invalid; the file measures nothing

- [x] T282 [CRITICAL] Give `ManagementReview.spec.ts` a valid RED state before any US2 conclusion is
  drawn from it, because all 19 of its tests fail during `mount()` for a reason unrelated to the
  behaviour under test. Each of the five views it mounts calls a router composable in `setup()` —
  `useRouter()` at `src/views/reviews/CostCenterQueueView.vue:109`,
  `src/views/reviews/FinanceQueueView.vue:110`, `src/views/reviews/ReturnsQueueView.vue:111`,
  `src/views/reviews/SeniorQueueView.vue:112`, and both `useRoute()`/`useRouter()` at
  `src/views/reviews/TransactionReviewDetailView.vue:91-92` — and `TransactionReviewDetailView.vue:9`
  and each queue view's line 6 render `<RouterLink>`, but every `mount()` passes only
  `global: { plugins: [pinia] }`. The result is `[Vue warn]: injection "Symbol(router)" not found`
  followed by `TypeError: Cannot read properties of undefined (reading 'resolve')` from
  `vue-router`'s `useLink`, for all 19 tests, so not one assertion in the file has ever run. The
  sibling `PurchaserFlow.spec.ts` already shows the correct pattern — it builds a real router with
  `createRouter(createWebHashHistory())` — so this task is to apply that wiring (or a faithful
  `RouterLink` stub plus a router that resolves the named routes the views link to) to all 19
  mounts, then re-record which tests fail on behaviour. AGENTS.md's RED-State Rules list exactly
  this as invalid: a runtime failure unrelated to the intended missing behaviour, with skipped and
  incomplete counts that say nothing useful. Do not mark `T047`/`T053` on the strength of the
  current 19 failures; re-derive the list first, and note the corrected count in this task (done - the
  file now builds its router from the application's own exported `routes` table in
  `src/router/index.ts` rather than a hand-copied list, so a name a view links to cannot silently go
  missing, and a `mountView()` helper supplies pinia plus that router and awaits the mount-time
  loads. Two further sources of false confidence were removed: `DecisionForm.vue` and
  `PriorDecisionHistory.vue` were being replaced by inline `{ template: '<div/>' }` placeholders
  even though both real components exist, so six tests asserted against a stub rather than the
  implementation, and `@/services/reviewsApi` was mocked with function names the store never calls
  (`listQueue`/`getTransaction`/`submitDecision` against the real
  `fetchQueue`/`fetchTransactionForReview`/`recordDecision`), which left the real functions in place
  to issue live requests. The transport is now stubbed at the axios boundary instead. **The
  corrected count is 15 failures out of 19, every one an `AssertionError` or a `setValue` mismatch
  against the real control, with no harness crash left** — so the file measures US2 behaviour for
  the first time and `T047`/`T053` are demonstrably not complete. Mounting the routes' real lazy
  components also cost ~30s because each `router.push` re-imported every view, and asking a
  never-navigated router for `isReady()` stalled ~5s per test; replacing the route components with
  inert placeholders and always performing a navigation brought the file from 33s to 3.1s)

- [x] T283 [CRITICAL] Make the US1 purchaser frontend behaviour that `T034`/`T042` claim to have
  actually delivered, because both are checked while 34 of their own tests fail. Set difference
  between the `data-test` hooks the suite asserts and the hooks `src/` emits leaves **25** asserted
  names with no producer anywhere in `src/`: `account-balance`, `sheet-title`, `sheet-currency`,
  `sheet-transactions`, `effective-outcome-total`, `cost-center-error`, `expense-type-error`,
  `description-error`, `amount-error`, `evidence-error`, `submission-errors`, `transaction-stage`,
  `transaction-currency`, `latest-decision`, `evidence-name`, `evidence-type`, `evidence-size`,
  `evidence-upload-time`, `deactivation-info`, `actor-snapshot`, `prior-revisions`, `open-sheets`,
  `balance-blocking-warning`, `status-badge`, `responsive-container`. Grouped by requirement, what
  is absent is: per-field submission error presentation and the sheet/transaction header and
  currency display (`FR-071`); the latest-effective-outcome column beside the stage totals
  (`FR-062`, which is also why `T281` cannot close — but `T281` owns the *server* `totals` object
  and this task owns only the presentation of a column the view already tries to draw); evidence
  list columns and the deactivation info that distinguishes inactive from deleted evidence
  (`FR-058`); decision actor snapshots and prior-revision presentation (`FR-051`/`FR-052`); the
  balance-independent submission and returned-transaction correction affordances; and the
  purchaser's multiple-open-sheets dashboard. Implement the behaviour rather than adding the
  attributes, and where a failure is instead the test encoding a mock-era contract, correct the
  assertion and say so in this task — do not add a hook whose only purpose is to satisfy a
  selector. Specifically, three assertions prove nothing even if made to pass: a `body` class
  named `persian-font` is not FR-071's ≥12pt Persian type, a class named `wcag-aa` on
  `[data-test="status-badge"]` is not WCAG AA contrast, and the presence of
  `[data-test="responsive-container"]` is not "no horizontal scroll at 390px". Those three must be
  replaced with assertions that can fail when the requirement is violated, per `FR-071`/`FR-072`.
  `SheetDetailView.vue:141-159` already renders the stage-total chips, which is why
  "should display separate stage totals" passes while the outcome column beside them does not
  (complete - **all seven groups green**, 85/85 in this file, from 39 failures of 66. Six slices were
  needed: `Sheet Forms`, `Sheet Detail View`, `Transaction Forms`, `Transaction List View` +
  `Transaction Detail View`, `Evidence Upload` + `Submitted Read-Only Behavior`, and `Multiple Open
  Sheets` + `Balance-Independent Submission`. The count after four slices was 13 failures of 86, and
  the first slice's own snapshot was 31 of 66, down from 39, with `Sheet Forms` 12/12 green. What the
  first slice established, which changes
  how the rest of this task must be read: **this file's `vi.mock('@nextcloud/vue', …)` never reached
  the components it appears to cover.** Every view imports `@nextcloud/vue/components/NcX` by deep
  path — 13 files do — and only `SheetDetailView.vue` and `TransactionDetailView.vue` import the
  barrel, so 14 of the 15 mocked components were dead weight and the real ones were rendering all
  along. A probe confirmed it: the real `NcSelect` emits a `v-select` wrapper with a `role="listbox"`
  and **zero** `<option>` elements, and `v-select` will not open under jsdom at all, so the original
  `findAll('option').length > 1` assertion could never have passed. Assertions that depended on the
  mock have been rewritten against the component that actually runs — the account-selection tests
  now read the options the view hands its `NcSelect` through a typed `selectOptions()` helper, which
  is where the requirement is actually decided and which fails if the accounts are not derived from
  the store. The mocked list also contained `NcAlert`, which **does not exist in `@nextcloud/vue`
  9.x** at all, and the validation summary was therefore built on a plain `role="alert"` element
  rather than an invented library component. The accounts store mock is now a mutable binding driven
  by `setAccounts()`, because three requirements are conditional on how many accounts the caller
  holds and a fixed two-account stub made them untestable — with it, "disable currency selection when
  the user has only one active account" became a real test instead of one that asserted the
  two-account branch while claiming to test the one-account branch. Genuine gaps fixed in this slice:
  a validation summary naming each missing field, the selected account's balance shown at its own
  currency scale, the submit button disabled while the form is invalid, and `aria-label` on the
  sheet-title, expense-date, and amount controls, which the real `NcSelect` had already been warning
  about ("An `inputLabel` or `ariaLabelCombobox` should be set"). The three mock-era accessibility
  proxies are gone, each replaced by an assertion that can fail: status is now checked to carry a text
  label for every status in the `TransactionStatus` union rather than to have a `wcag-aa` class
  nothing ever set, the 390px requirement is checked as a declared mobile breakpoint plus the absence
  of a `min-width` above the viewport rather than a `responsive-container` attribute no component
  emitted, and the Persian ≥12pt requirement moved to a real gate. **That last one is RED and is the
  finding worth flagging: `tests/Frontend/typography.spec.ts` resolves 12pt to 16px and finds 42
  declarations in `src/` below that floor** — 12px, 13px, 14px, `0.75rem`, and `0.875rem` across
  `StatusBadge`, all five review views, both evidence list components, and every review component.
  The app's entire typography is under the floor, so this is a spec-wide FR-071 violation and not a
  single-component slip. It is recorded here and left red for `T120`, which owns the styling, rather
  than half-fixed here. Two of my own replacement assertions were wrong and were corrected against
  the source rather than left passing: I invented a `FINANCE_APPROVED` status to assert a label for,
  which the `TransactionStatus` union does not contain, and I asserted the *smallest* responsive
  breakpoint must be ≤390px when a `max-width: 600px` rule already covers 390px — the requirement is
  that the viewport falls inside a declared breakpoint, so it is the largest that must reach 390).

  **Second slice (`Sheet Detail View`, now 7/7 green; 28 failures of 67 remain in this file).** The
  three remaining failures split cleanly. Two were genuine presentation gaps and are fixed: the
  sheet's title and currency had no test handle, so `data-test="sheet-title"` and
  `data-test="sheet-currency"` were added to the elements that already render them, and FR-062's
  "latest effective outcome column" now exists as a column rather than as three loose chips — the
  contract's `effectiveApproved`/`effectiveRejected`/`effectivePending` are each summed by a
  transaction's latest effective decision (`data-model.md` line 195), so grouping them under one
  labelled `data-test="effective-outcome-total"` container is what distinguishes that column from
  the three per-stage approved columns it is required to sit beside. The third was a test that
  asserted an error state it never produced: it mounted the view with an empty store and required
  `[data-test="submission-errors"]` to exist, so it never submitted and never generated an error.
  The region now carries `role="alert"` and `aria-live="assertive"` so a refused submission is
  announced rather than only painted, and the test sets the store's `error` explicitly, requires the
  region to be *absent* without it, and adds a dismissal test

  **Third slice (`Transaction Forms`, now 17/17 green; 19 failures of 75 remain in this file).** All
  eight failures were in the form, and they were not one kind of problem. Four of them — the
  `cost-center-error`, `expense-type-error`, `description-error` and `evidence-error` cases — repeated
  the second slice's defect exactly: each mounted an empty form and required the error to be there
  already, so none of them ever submitted. They are rewritten to submit, and then to check the two
  things FR-071 asks of error presentation rather than the presence of an attribute: that the control
  announces its *own* message, and that a summary names every missing field at once. The second is
  real and was missing — a purchaser with five required fields and only per-field messages has to hunt
  for each red one — so the form now renders a `role="alert"` / `aria-live="assertive"` summary, the
  same pattern `SheetFormView` already established. The per-field `*-error` hooks from this task's
  opening list are therefore **not** added: a probe of the real controls showed that `NcTextField`,
  `NcTextArea`, `NcSelect` and `NcDateTimePickerNative` each already render the `helper-text` as a
  `<p>` and point the control at it through `aria-describedby`, so a duplicate element added only to
  carry a `data-test` would have printed every message twice.

  Three of the requirements themselves were absent from the view. **FR-023's evidence rule did not
  exist at all** — `validateForm()` checked date, amount, description, cost center and expense type
  and stopped — and it is conditional, because `data-model.md` line 103 gives `evidence_required` the
  meaning "at least one active item required at submission when true". Both branches are now
  asserted. **FR-021's currency was never displayed**: the form showed no currency and offered no
  choice, so "inherits the sheet currency" was satisfied only by the absence of anything. It now
  shows the sheet's currency read-only, and the sheet is resolved from the route on create and from
  the loaded transaction on edit — the edit route `/transactions/:id/edit` carries no `sheetId`, so
  reading the route alone would leave the field blank exactly where a correction is being made.
  **FR-026 locked nothing**: `isDraft` existed and was passed to the uploader, but no field was
  disabled, so a submitted transaction stayed fully editable. Every control and both actions are now
  locked, with the requirement's one named exception — a returned transaction stays editable, because
  that is the correction the requirement carves out.

  Two defects surfaced only because a test was corrected rather than made to pass. The view's cost
  centers and expense types were **hard-coded placeholders** (`cc-1`, `et-1`) that exist nowhere in
  the instance, and a transaction is routed to the manager the server holds for its cost center
  (FR-032), so an invented identifier routes to nobody and is rejected on write. They now come from
  `GET /configuration/cost-centers` and `GET /configuration/expense-types` through a new
  `src/services/configurationApi.ts`, and `CostCenter`/`ExpenseType` are declared in
  `src/types/api.ts` from the contract. The test's fixture identifiers were deliberately made
  *unlike* the old placeholders, because when they matched, "the options come from the configuration"
  passed against the hard-coded list too. Second, the amount rule rejected only `<= 0`, so `0.5`
  minor units reached an API that has nowhere to put the remainder; it is now a positive integer, and
  the control carries `step="1"` so the fraction is never offered.

  Four assertions in this file were mock-era contracts and were corrected against the source. Two are
  worth recording because they are the same class of error as the three already corrected in the
  first slice. `expect(wrapper.emitted('save-draft'))` demanded an event from a routed view that has
  no parent to emit to and persists through the store — it could never pass; the test now reads the
  `POST` body and asserts that a draft carries a date and an amount and *nothing else*, which is what
  FR-022 actually says. And `props: { transactionStatus: … }` passed a prop the view never declared,
  so it asserted against a contract that did not exist while the status sat on the loaded
  transaction; a `createWrapperAtRoute()` helper now mounts on a real route, because the route is the
  input that decides which branch a form takes. In the Accessibility group, "proper ARIA labels" was
  `attributes('aria-label')` on two controls — but WCAG resolves a name from `aria-label` *or* an
  associated `<label for>`, and `NcDateTimePickerNative` sets `aria-label` only when it hides its own
  visible label, so satisfying the assertion would have meant removing the label the purchaser reads.
  It is replaced by an `accessibleName()` helper applied to every control of both purchaser forms,
  which fails if a label is dropped, re-pointed or emptied.

  One defect was found in the already-completed first slice rather than in this one: the sheet form's
  `currency-display` had no accessible name at all, and the old assertion never looked at it. The
  `l10n` mock was also wrong in a way that had been hiding this class of problem — it returned its
  *first* argument, so every string in every component rendered as the literal `pettycash` and no
  assertion could distinguish one message from another. It now returns the message, which is what
  makes the per-field message assertions mean anything.

  RED was proven rather than assumed: run against the pre-slice view, 10 of these 17 fail, and every
  one of the 10 fails on a behavioural claim. The 7 that pass are the pre-existing behaviour this
  slice re-asserts (the form's fields render, a draft persists, the evidence area exists) plus the
  guard that a type which does *not* require evidence is not made to — which the old view satisfied by
  having no evidence rule at all. Two tests initially failed for the wrong reason and were fixed
  before being counted: one submitted through a `data-test` handle this slice had added, so it failed
  on the missing attribute rather than on the behaviour, and now submits through the `form` element;
  the other passed vacuously because the fixture reused the hard-coded `cc-1`/`et-1` identifiers.

  **Fourth slice (`Transaction List View` 5/5 and `Transaction Detail View` 9/9 green; 13 failures of
  86 remain in this file).** All six failures in these two groups had the same root cause as the
  previous two slices: the test asserted a state it never produced. Every one of them mounted a view
  whose store was empty — the OCS mock answers an empty page — and then required a **per-row** or
  **per-decision** element to exist. There were no rows, so none of them could, whatever the view
  rendered. The two that already had their hook (`evidence-count`, `evidence-active-badge`) were the
  clearest case: the view was correct and the test could never pass. The tests now seed the rows,
  decisions, evidence and revisions they assert on, through the OCS mock rather than by poking the
  store after mount, so the real load path runs.

  Seeding them exposed three things that had been invisible behind the empty store. **`StatusBadge`
  was asserted only for existing**, which proves nothing about which badge is on which row; each row
  is now checked for the badge derived from *its own* status, and a row is checked *not* to carry
  another row's. **A returned transaction showed the blanket read-only badge and a correction button
  at the same time** — FR-026 forbids editing after submission "except through correction of a
  returned transaction", so the view was telling the purchaser the opposite of what the button beside
  it was about to let them do. The badge is now withheld for that one state. And **the revision panel
  opened on `revisions[0]`**, which is the *oldest* revision, so a corrected transaction displayed its
  superseded amount and cost centre as the current ones; FR-050 makes the active revision the one
  that determines the current information, so that is what the panel now opens on and the test proves
  the superseded figure is not on show.

  Three requirements were genuinely absent. **FR-052's actor snapshot was incomplete**: the view
  rendered the display name and the role and dropped the identifier, so a decision could not be traced
  to the person who made it, and the three parts FR-052 names are now all presented. **FR-050/FR-053's
  prior revisions did not exist** — the view had a revision *selector*, which lets a reader page
  through history, but never stated that what they are reading is retained history rather than a
  previous state of an editable row. There is now an inert region listing the superseded revisions,
  with no control in it that could change one. **The list fetched `latestDecision` on every row and
  never rendered it**, so a sheet in review could only be understood by opening each transaction in
  turn; it is now a column, and a row with no decision says so rather than showing a blank cell that
  reads as a rendering fault. The icon-only row and create buttons also had no accessible name at all
  (WCAG 4.1.2), and the table headers had no `scope`.

  **A defect found while writing the scale assertion: four views divided money by a hard-coded 100.**
  `Money` carries the currency's own `minorUnit`, and `SheetDetailView.vue:30`, `SheetListView.vue:16`,
  `TransactionDetailView.vue:54` and `TransactionListView.vue:20` each did
  `Intl.NumberFormat(…).format(amountMinor / 100)` — printing a rial amount, which has no minor unit,
  one hundred times too small. It is the figure a finance officer reconciles against. `CostCenterQueueView`
  already had the correct version inline, so the correct implementation existed and three other views
  had not been brought onto it; it now lives in `src/utils/money.ts` and all four call it, with the
  divisor read from the payload. `SheetDetailView` was also reading the *sheet's* currency to label
  each total rather than the currency on the total itself. The test states the scale rather than the
  Persian digit shapes, and independently catches a reintroduced `/100`: mutating the helper's divisor
  back to `100` fails it.

  `TransactionSummary.latestDecision` was declared non-optional, which is wrong — the contract does not
  list it among `required`, so a fresh draft has none. The type now says so, which is what removed the
  `null as unknown as Decision` cast the fixtures had needed.

  Two currently-green tests in these groups were passing for the wrong reason and are now honest.
  `should show read-only view for submitted transactions` passed because with no transaction loaded the
  view computed `isSubmitted` as `null !== 'DRAFT'`; it now loads a transaction whose status is
  `AWAITING_COST_CENTER_REVIEW` and also requires the edit and correction affordances to be absent,
  which is what would contradict the badge. `should display revision selector…` passed on a selector
  that is always rendered; it now requires two revisions so there is something to choose between.

  RED proven: run against the pre-slice views, 8 of these 14 fail, every one on a behavioural claim.
  The 6 that pass are behaviour the old views already had once given data — the empty state, the
  evidence count and state columns, the revision selector, the read-only badge for a genuinely
  submitted transaction. Two of my own assertions were wrong and were corrected against the source
  rather than left passing: one required the revision panel *not* to render an "Origin" row, which it
  legitimately does, and one compared a money figure against hard-coded Persian digits and then
  against a string (`۱۰`) that the locale-formatted date in the same panel supplied.

  **Fifth slice (`Evidence Upload` 11/11 and `Submitted Read-Only Behavior` 8/8 green; 4 failures of
  90 remain in this file).** The finding that mattered most in this slice was not a missing hook.
  `EvidenceList.vue` rendered through `NcListItem` using `avatar`, `title` and `subtitle` slots —
  **none of which exist in `@nextcloud/vue` 9.x**, whose slots are `icon`, `name`, `subname`,
  `details`, `indicator` and `actions`. Every evidence row therefore rendered as an empty shell: a
  purchaser looking at a transaction's receipt saw the file name, type, size, upload time, state and
  available actions *nowhere*. A probe of the real component is what established it, and it is the
  same root cause as the first slice's `NcSelect` finding — a component written against a library
  surface that does not exist, hidden by a mock. It is not fixed by correcting slot names alone:
  `NcListItem` also always renders its row as an anchor (a focusable link to `#` for a file that
  navigates nowhere) and hides its actions behind a hover-revealed three-dot menu, so the removal
  affordance FR-058 requires only appears when the pointer happens to be over the row. The component
  is now a plain list built from the same primitives the rest of the app uses, with the actions
  always visible.

  Three requirement violations came out of it. **FR-060 was broken in the direction that matters
  most**: the download button was gated on `isDraft`, so a cost-centre manager reviewing a
  *submitted* transaction — the entire point of the review — was offered no way to open the receipt
  they were being asked to approve. Download is now gated on the item being active, and only removal
  is pre-submission. **FR-025/FR-026 were not enforced at all in `EvidenceUploader`**: it accepted an
  `isDraft` prop and then never read it, so the drop zone, the file input and the upload button were
  all live on a submitted transaction. The picker is no longer rendered in that state and the
  component says why, rather than simply being absent. **A refusal that did not say what the limit
  is**: the message key carried a `{max}` placeholder that no source ever filled, so "too large" was
  shown without the number. The limit is now stated on the picker as well as in the refusal, and the
  boundary is asserted inclusive — a file of exactly 100 MB is accepted, since only `> MAX` is
  refused and an off-by-one there silently rejects a legitimate receipt.

  **FR-059's history was incomplete**: a deactivated item showed a chip saying "inactive" and, when
  the server supplied a `deactivatedAt`, a timestamp — but the two lived in different places and the
  timestamp had no handle, so nothing could state that a file is retained rather than deleted. The
  component also rendered the inactive state *twice*, an `evidence-active-badge` and a separate
  `inactive-badge` that both said "inactive"; the duplicate is collapsed to one chip, and the test
  now asserts the state through it rather than preserving a handle whose only purpose was a second
  copy of the same word. The test's fixture also carried a `deactivationReason` field that neither
  the contract nor `src/types/api.ts` has; it was dropped rather than added to the type.

  Five of the six tests in `Submitted Read-Only Behavior` passed a prop no view declares —
  `sheetStatus`, `isSubmitted`, `transactionStatus` — and asserted contracts that do not exist.
  `SheetDetailView` turned out to gate `edit-sheet-btn`, `create-transaction-btn` and the submit
  button on `isSubmitted` correctly already, so those four are now honest tests that drive a seeded
  sheet through the OCS mock and check the affordances that must be *absent* as well as the badge
  that must be present. One of them also looked for `[data-test="read-only-indicator"]` on a sheet, a
  name no sheet view has ever emitted.

  **Sixth slice (`Multiple Open Sheets` 4/4 and `Balance-Independent Submission` 3/3 green; this file
  is 85/85).** FR-018 and FR-061 ask for a purchaser's open sheets to be visible as the actionable
  ones, and the sheet store has computed `openSheets` that the view never used — open and closed
  sheets sat in one undifferentiated table. There is now a labelled open-sheets region, and an empty
  one says so rather than rendering an empty table. The "separate transaction lists per sheet" test
  asserted that a hook existed, which a list showing every transaction in the instance would also
  satisfy; it now seeds two sheets' transactions, opens one, and asserts that the opened sheet's rows
  are there and the page names the sheet they belong to.

  All three `Balance-Independent Submission` tests passed an `accountBalance` prop to views that
  declare no such prop; the balance is on the account in the accounts store, and the first slice had
  already made FR-027 true — the submit button depends on the title and the account and never on the
  balance. These three therefore **pass against the pre-slice source**, because the behaviour they
  guard was already correct and only the tests were fictional. They are kept because they now say
  what FR-027 and FR-020 actually require: an overdrawn account does not disable submission, the
  negative balance is displayed as negative (the positive form is a substring of the negative one, so
  only the negative form proves the sign survived) and without an alert role, and having no account at
  all is a normal state that leaves the form reachable.

  RED proven: against the pre-slice components, 11 of these 26 tests fail, every one on a behavioural
  claim. The 15 that pass are the already-correct submission gating in `SheetDetailView` and the
  already-correct FR-027 balance handling from the first slice, plus the corrected harness defects.
  Three of my own assertions were wrong and were corrected against the source: one called 1,024,000
  bytes "1.0 MB" when the component's threshold makes it 1000 KB, one required the balance text to
  begin with a minus when the format leads with the currency code, and one asked for the second of
  the two duplicate inactive chips.

### HIGH — the US1 green tests pass through an unhandled rejection

- [x] T284 [HIGH] Stop `PurchaserFlow.spec.ts` from raising unhandled rejections, because its 18
  passing results are currently unverifiable. The file mocks `@nextcloud/axios` as
  `{ default: { request: vi.fn(), isAxiosError: vi.fn() } }`, so `request()` resolves `undefined`,
  `src/services/http.ts:15` evaluates `response.data.ocs.data` on `undefined`, and Vitest collects
  **26 unhandled rejections** — its own output warns "This might cause false positive tests". Because
  the rejection is raised by a `Promise` the test never awaits, it escapes the test body entirely,
  so a test can be green while the code under test threw. Give the mock a resolved OCS envelope
  (`{ data: { ocs: { data: … } } }`) for the paths a component calls on mount, and assert the
  rejection is caught: mount each view and require `error` to be a populated `ApiError` rather than
  a raw `TypeError`, per `T030`'s canonical error model and `contracts/ocs-api.yaml`'s `Error`
  schema. `AGENTS.md` treats a suite whose failures or passes are caused by the wrong harness as
  outside a valid RED state, so this must be resolved before the counts in `T283` are believed
  (done - the axios mock is now a hoisted `ocsRequest` that resolves a well-formed envelope whose
  payload matches the contract per endpoint, so a component under test genuinely loads. This
  exposed a second defect the silent failure had been hiding: `revisions.value = await
  listRevisions()` assigns into a `ref<Revision[]>`, and answering `/revisions` with a page instead
  of the bare `Revision[]` the contract declares at `contracts/ocs-api.yaml:552-565` produced
  `TypeError: transactionStore.revisions.map is not a function` at
  `src/views/transactions/TransactionDetailView.vue:53` — the mock was wrong, not the client, and the
  declared shape is now encoded in the mock so the two cannot drift again. The remaining unhandled
  rejections were real and shipped: every store records a failure in its own `error` ref and then
  re-throws, and `SheetListView.vue`, `TransactionListView.vue`, `SheetDetailView.vue`,
  `TransactionDetailView.vue` and `SheetFormView.vue` fired those loads from `onMounted` and `watch`
  with nothing attached, so any failed load became an unhandled rejection in the user's console. The
  store re-throw is load-bearing — `SheetFormView.handleSubmit` relies on it to skip its navigation —
  so the fix belongs at the fire-and-forget call sites, each of which now absorbs the rejection with
  a comment pointing at the alert that already renders the error. Four new tests cover the transport:
  the sheet list request is issued and its page stored with no error, a 422 surfaces the server's own
  message rather than a `TypeError` message, a non-OCS body reports `malformedResponse`, and a 409
  keeps the server's code instead of degrading to `NETWORK_FAILURE`. The file went from 26 unhandled
  rejections to 0, and the 39 remaining failures are unchanged and now trustworthy because the
  transport under them works)

- [x] T285 [HIGH] Guard the OCS response unwrap in `src/services/http.ts`, because
  `return response.data.ocs.data` is a three-level unguarded dereference on a response whose shape
  the function does not verify, and the `catch` below it only recognises `AxiosError`. Any
  Nextcloud response that is not OCS-shaped — the HTML body a session expiry returns, a reverse
  proxy's 502 page, an empty 204 from a route that returns no content — produces a raw
  `TypeError`, which fails the `isAxiosError` branch and is re-thrown verbatim, so the caller never
  receives the `ApiError` envelope `T030` is accountable for and the UI has nothing to render.
  Verify the shape before dereferencing and convert a non-OCS or non-JSON body into an `ApiError`
  with a real status and a translatable code rather than a `NETWORK_FAILURE` stand-in. No task
  covers this: `T030` is checked and never asserted the unwrap, and the task search for `http.ts`
  in the open list returns only the record of what `T266` removed from the controllers (done - a new
  `unwrap()` helper in `src/services/http.ts` type-checks the body before dereferencing it and
  returns either the payload or an `ApiErrorBody`, and `ocs()` raises `ApiError` on the malformed
  branch. The helper also treats an `error` member inside a 2xx `ocs.data` as a rejection, because
  OCS permits a failure to arrive as HTTP 200 and a caller must not be handed a business failure as
  if it were a payload. The `catch` now re-throws an `ApiError` unchanged before the
  `isAxiosError` branch, so the new failure is classified instead of being re-wrapped as
  `NETWORK_FAILURE`. New `tests/Frontend/http.spec.ts` covers the envelope, the header and resolved
  URL, a caller-supplied versus generated command id, the server error envelope, the no-envelope
  fallback, and a non-axios rejection. Its seven non-OCS cases — `undefined`, `{}`, an HTML session
  -expiry page, a bare array, an `ocs` member that is not an object, an `ocs` member with no `data`,
  and a proxy error page — were proven RED first, each failing with exactly the predicted
  `TypeError: Cannot read properties of undefined`; they are now 15/15 green)

### MEDIUM — recorded for awareness, deliberately not appended

- `tests/Frontend/__mocks__/components.ts` is now dead: it declares eight placeholder components
  whose own header reads "placeholder implementations … will be replaced by real components in
  T042", `T042` is complete, and `vitest.config.ts` no longer aliases to it. `T158` and `T215`
  already own its removal, so this is recorded here rather than duplicated as a new task.

## Phase 37: Convergence (The US2 Review Frontend Is Claimed, and PHP Verification Can Read Stale Bytecode)

**Purpose**: Record what the quality gate actually reports now that T283 closed US1. `scripts/dev/phpunit`
is 190 tests / 937 assertions / 1 failure — the deliberately unresolved T275 artifact conflict, and nothing
else. `npx vitest run` is 108 passed / 15 failed of 123, and **every one of the 15 is US2 review-frontend
behaviour**. T053 claims to implement exactly those components and is checked. T282 gave that spec file a
valid RED state but implemented nothing, and no open task implements US2's review frontend, so this is the
US2 counterpart of the situation T283 was appended for on the US1 side. A second finding is recorded
because it materially undermines every future PHP verification in this repository.

### CRITICAL — the US2 management-review frontend is claimed and not delivered

- [x] T286 [CRITICAL] Make the US2 management-review frontend that `T053` claims to have actually delivered,
  because `T053` is checked while 14 of the 15 remaining `ManagementReview.spec.ts` failures are its own
  subject matter, and no open task covers it. Every one of the five components named by `T053` exists in
  `src/views/reviews/` and `src/components/review/`, so this is `partial` rather than `missing`: they were
  built and do not satisfy the requirements. Grouped by what is absent, the failures are: the decision form's
  mandatory-explanation rule for REJECT and RETURN and its absence for APPROVE (`FR-033`/`FR-035`, and US2's
  stated acceptance scenario "Given a manager rejects or returns a transaction without an explanation …
  the decision is rejected until an explanation is given"); the prior-decision history's full detail and its
  presentation of a cost-centre decision as **advisory** in the senior stage, which `FR-034` and its
  clarification both require and which is the whole point of the advisory rule; the cost-centre queue's
  pagination, its no-horizontal-scroll layout at 390px, its WCAG AA status colours and icons, and its ≥12pt
  Persian text (`FR-071`/`FR-072`); the senior manager deciding independently without being forced to
  acknowledge the advisory decision (`FR-035`, `FR-034`); the transaction review detail's evidence and
  history, including preview and download (`FR-060`); and the permitted self-review case where a
  multi-role user reviews their own transaction when scope and stage match (`FR-002`/`FR-008`, which T045
  covers server-side but which has no client). T282 is already complete and deliberately scoped to harness
  validity only, so this is not a duplicate of it; `T065`/`T193` are US3 finance review and name a different
  view set, so they do not cover this either. Constitution V requires a task to be complete only when the
  acceptance criteria pass, so `T053` must be reopened rather than left checked. One of the fifteen is a
  test-side defect rather than a product gap and is called out so it is corrected rather than implemented
  around: `should include X-PettyCash-Command-ID header` asserts the header on `DecisionForm.vue`, but the
  component correctly delegates to `reviewsStore.recordDecision` → `reviewsApi.recordDecision` →
  `mutate()`, and it is `mutate()` at `src/services/http.ts:67` that sets the header on every mutating
  request. The command identity **is** sent; the assertion is looking in the wrong place (partial)

  **Slice 1 (`DecisionForm`, 4/4 green; 11 of 19 remain in this file).** The product defect is a
  library-surface mismatch of the same family T283 found in slice 1: `DecisionForm.vue` passed
  `:error-message` to `NcSelect` and `NcTextArea`, and **`@nextcloud/vue` 9.x has no `errorMessage`
  prop** — it declares `error` (boolean) and `helperText` (string). The attribute therefore fell
  through onto each component's root and the message was never rendered at all. The consequence is
  exactly FR-033's acceptance scenario failing in the way that matters: the decision *was* refused
  and the submit button *did* go disabled, but nothing ever said why, so the manager was left with a
  dead button. Both controls now use `:error` + `:helper-text`. A sweep of `src/` found
  `DecisionForm.vue` is the only site passing an invalid `error-message`; `SheetDetailView.vue:275`'s
  match is a `data-test` attribute name, not a prop.

  Three of the four failures turned out to be test-side as well, and all three are the "test encodes
  a contract that does not exist" class rather than product gaps — the component already computed
  `explanationError`, already gated the submit on it, and already generated a `commandId` into the
  emitted payload. Each was corrected against the source rather than worked around. `setValue()` on
  `[data-testid="decision-select"]` throws `cannot be called on DIV`: the real `NcSelect` renders a
  `v-select` whose root is a `div` and whose dropdown opens on real focus, which never happens under
  jsdom — the identical finding that made T283's first slice rewrite its own select assertions, now
  confirmed independently in a second suite. The decision is chosen through the component's
  `update:modelValue`, the event a real selection emits. The submit control is `type="submit"` inside
  a `<form @submit.prevent>`, so `trigger('click')` on it raises a click and nothing else — jsdom
  does not submit a form from a synthetic click on its submit button — and `submitDecision()` awaits
  the store before emitting, so the event is not observable until the promise chain drains; the tests
  now submit the form and `flushPromises()`. And the command-ID assertion could never have found the
  header even had the behaviour been missing, because the form delegates to
  `reviewsStore.recordDecision` → `reviewsApi.recordDecision` → `mutate()`, and `mutate()` at
  `src/services/http.ts:67` is what sets `X-PettyCash-Command-ID`; what the component does own is the
  identity it generates and hands over, so that is asserted, and asserted to *look* like a UUID rather
  than merely to be a present property.

  **One assertion of mine was wrong and was corrected against the source.** I first had the component
  pass `:aria-describedby="explanationError ? 'decision-explanation-error' : undefined"`, reasoning
  that an explicit association was better. It is not: the control *appends* a passed `aria-describedby`
  to the id of the helper-text element it renders itself, so naming an id of our own leaves a
  reference pointing at nothing — a dangling association, which is worse than none. The attribute was
  removed, and the test reads the library's own helper-text element through the ids the control names,
  which is what makes it a real assertion.

  RED proven by mutation, not assumed: forcing `:error="false"` and `:helper-text="undefined"` — the
  pre-fix behaviour — fails the two mandatory-explanation tests with
  `expected 'textarea__input' to contain 'error'`, and both are green again once restored.

  **What remains in this task:** 11 failures across `CostCenterQueueView` (4 — pagination, a
  `rtl-layout` class name, WCAG AA status colours/icons, ≥12pt), `SeniorQueueView` (1 — independent
  decision per FR-035), `TransactionReviewDetailView` (2 — evidence, history, preview/download per
  FR-060), `PriorDecisionHistory` (2 — full detail, advisory presentation per FR-034), the permitted
  self-review case (1, FR-002/FR-008), and `FinanceQueueView` (1, which T287 reclassifies as US3).
  The `rtl-layout` and the `Advisory` assertions have already been observed to be unsatisfiable as
  written — a class name is not a requirement, and the corrected `l10n` mock returns the message key
  rather than an English word — so both are expected to be test-side corrections, in the same class as
  the three in this slice.

  **Slice 2 (`PriorDecisionHistory` 5/5 and `TransactionReviewDetailView` 3/3 green; 7 of 23 remain in
  this file).** This slice found **no product defect**: all six failures were the test encoding a
  contract that does not exist, which is worth stating plainly because it bears on how much of this
  task's CRITICAL severity is real. The components were already correct and the suite was asserting
  against hooks and strings that were never there.

  Four of the six were selector mistakes, each of which the components answered correctly under a
  different name. `[data-testid="decision-item"]` matches **nothing**, because the component emits
  `decision-item-{id}` per decision — which is the better design, since it distinguishes an earlier
  decision from a later one, and the tests now use it to assert that. `[data-testid="decision-history"]`
  has never existed; the history is `prior-decision-history` with a `decision-history-list` inside it.
  `[data-testid="evidence-download"]` is really `evidence-download-btn`. And `evidence-preview` is an
  `<img>`, so it is image-only by construction — the original assertion that it exists for a PDF
  receipt could never hold, and the affordance that applies to every *active* evidence type is the
  preview **button**, which is what is now asserted, together with a separate image case so the inline
  preview is not left unchecked.

  The remaining two asserted English words. `should show cost center decisions as advisory…` required
  the text "Advisory", which no correct component could produce: the `l10n` mock returns the message
  key, as it must for an assertion to mean the same thing in any locale — the same correction T283 made
  when it fixed that mock. And `should display all prior decisions with full details` required the raw
  enums `APPROVE`/`REJECT`, while the component renders the translated `reviews.decision.*`. That
  second test was also the weakest of the six: it only counted items and looked for four names, so it
  is now driven by a loop over both decisions and asserts each one's stage, outcome, actor, role,
  explanation and timestamp — which is what "full details" means in FR-034, rather than four
  substrings.

  Three tests were **added**, each covering a claim the requirement makes that the original six never
  touched. FR-034's advisory rule is *conditional* — a cost-centre decision is advisory **in the senior
  stage** — so both the negative case (the same decision at the cost-centre stage is not badged) and
  the selective case (at the senior stage, only the cost-centre decision is badged) are now asserted; a
  component that badged every decision as advisory would have passed the original. FR-060 asks for the
  active state to be visible, so a deactivated item is asserted to be marked, to lose the preview
  affordance, and to remain listed — FR-059 makes deactivation a mark, not a deletion. And an image
  evidence is asserted to preview inline with a real `alt`, so the image is not announced unlabelled.

  **Two of my own mutations were invalid and were discarded rather than reported as passes.** Making
  `isAdvisory` return `true` "passed" on the first attempt because the edit had not applied, and a
  regex-based variant then broke template collection so the suite reported "no tests" — a collection
  failure, which the RED rules correctly refuse as evidence. Both were re-run as verified single-line
  edits. The behaviour is proven: forcing `isAdvisory` to `true` fails the two advisory tests; hiding
  the explanation fails the full-details test with `expected … to contain 'OK'`; offering preview for
  inactive evidence fails the deactivation test. A fourth check — that the inline `alt` is the file's
  name — was confirmed by inspecting the rendered element rather than by mutation, because the
  mutation broke collection; it renders `alt="receipt.png"` against the content endpoint.

  **What remains in this task:** 7 failures — `CostCenterQueueView` (4: pagination, an `rtl-layout`
  class name, WCAG AA status colours/icons, ≥12pt), `SeniorQueueView` (1: independent decision per
  FR-035), permitted self-review (1: FR-002/FR-008), and `FinanceQueueView` (1, reclassified as US3 by
  T287). The `rtl-layout` and `≥12pt` assertions are the two still most likely to be unsatisfiable as
  written, for the reasons recorded above.

  **Slice 3 (`CostCenterQueueView` 6/6, `SeniorQueueView` 2/2, permitted self-review 1/1; the file is
  23/24 and the one failure is `FinanceQueueView`, which T287 reclassifies as US3).** Like slice 2,
  this one found **no product defect in the views** — five of the six failures were the same
  selector-and-string class, and they are worth grouping because the *same three* meaningless
  assertions keep recurring across the suite and are now gone from it for good.

  `[data-testid="queue-item"]` matches nothing, because the view emits `queue-row-{id}` — the third
  time a per-item id has been asserted as a bare one after `transaction-row` and `decision-item-{id}`
  in T283 and slice 2. And two of the four were the mock-era proxies T283 catalogued in its first
  slice and which had already been removed from `PurchaserFlow.spec.ts` but not from here: an
  `rtl-layout` **class name** that no requirement names and no stylesheet defines, and a
  `wcag-aa-contrast` **class** that nothing ever sets. The fourth required a `persian-text` hook no
  component emits and an inline `style="font-size: …"` attribute the app never uses — all typography
  is declared in style blocks, and T120 moved the whole app onto a four-step token scale, so an
  assertion demanding an inline font size contradicts the codebase's own convention.

  Each was replaced by the claim the test's *name* makes, checked where it can actually be decided.
  "Persian RTL without horizontal scroll at 390px" became: the view declares `dir="rtl"`, a mobile
  breakpoint reaches 390px, and nothing declares a `min-width` that would force the viewport wider.
  "WCAG AA status colours/icons" became: each row's status is readable **text** and each row carries
  the badge derived from *its own* status, which is the half of WCAG 1.4.1 that actually excludes
  colour-only signalling and is the same substitute T283 adopted. "≥12pt font" became: none of this
  view's own declarations falls below the floor — the rule T120's app-wide gate enforces, asserted
  here for one component. jsdom has no layout engine and no font metrics, so the three browser-measured
  clauses cannot be settled here at all; that is why `T053` is reopened rather than checked.

  The pagination test also **lied about what it checked**: it was named "with paginated transactions"
  while seeding two items against a page size of 25, so the single page it asserted was not a
  pagination control — and it asserted two rows rather than any paging. It now seeds a 25-item first
  page of 30 and asserts the control and the page indicator, with the complementary case added: a queue
  that fits one page offers no pagination. Writing that test surfaced a real property of the view worth
  recording — **it renders exactly the page the server sent and does not slice again**, which is why
  asserting "25 rows back from 30 items" was wrong rather than merely failing.

  `should allow senior to decide independently without forced acknowledgment` mounted
  `SeniorQueueView` and looked for a `DecisionForm` in it. There is no decision form in that view — a
  decision is recorded on the transaction's own page — and **`allowIndependentDecision` is a prop no
  view passes and no code reads**: it is declared on `DecisionForm` at line 76 and is dead. So the
  assertion could not have held under any implementation. FR-034 is now checked where the decision is
  actually taken: the senior reaches the decision form, all three of their choices are available, and
  nothing in the senior's path asks them to acknowledge the cost-centre decision — asserted both by
  the two acknowledgment hooks one might add and by the rendered view containing no such affordance
  under any name.

  The permitted self-review case had the same shape: it asserted `queue-item` and a
  `decision-button` that no component has ever emitted. It now asserts what the **client** actually
  owns, and says so: the multi-role user sees their own transaction in the queue and its action is
  present, enabled and named. Whether the decision is permitted is the server's call and T045 covers
  it, so a client-side assertion that the view "allows" the review would claim an enforcement the
  interface does not perform.

  RED proven by five mutations, each verified to have applied before its result was read — the lesson
  from slice 2, where two mutations were silently invalid. Showing pagination for a single page fails
  the no-pagination test; moving the breakpoint to 300px fails the layout test with
  `expected 300 to be greater than or equal to 390`; introducing a 12px declaration fails the floor
  test; **removing the text from `StatusBadge` fails the WCAG test with
  `expected '' not to be ''`, which is the assertion that finally makes colour-alone signalling
  detectable**; and disabling the row's action fails the self-review test.

  **Two of my own assertions were wrong and were corrected against the source.** I expected 25 rows
  back from 30 seeded items, which assumed a client-side page slice the view does not and should not
  perform. And my breakpoint check searched for `max-width: Npx` only, missing the range syntax
  `width <= 390px` this view actually uses — so it reported no breakpoint on a file that declares one.
  Both spellings are now accepted, and a range-syntax regression is caught by the mutation above.

  **Slice 3 closes this task for its US2 scope.** `ManagementReview.spec.ts` is **23/24**, and the one
  remaining failure is `FinanceQueueView > should render shared finance queue for all finance
  officers`, which is US3 behaviour that `T287` reclassifies — it is deliberately not counted here,
  because claiming it would mean implementing a finance view this task does not own. The
  `npx vitest run` gate is **1 failed / 127 passed of 128**, that single failure being the same
  `FinanceQueueView` case; `PurchaserFlow.spec.ts` remains 85/85 after T283; `typecheck`, `stylelint`,
  `build` and `check:portability` are clean and `lint` is 0 errors / 105 warnings. `T053` is reopened
  with the three browser-measured clauses named as what still blocks it.

  **What this task found, in one line:** of fifteen failing assertions, **one was a product defect**
  (`DecisionForm`'s non-existent `error-message` prop, which left FR-033's mandatory explanation
  invisible) and the other fourteen were the suite asserting hooks, class names, English words and
  raw enums that the components had never been expected to produce. That ratio is the finding worth
  carrying forward, because it is the second time in this feature that a red gate of this size turned
  out to be mostly measurement error rather than missing behaviour — and the first time (T283) it hid
  a spec-wide typography violation, so neither number is a reason to discount a red gate.

- [X] T287 [MEDIUM] Reconcile the finance-queue assertion that sits in the US2 spec file, because
  `ManagementReview.spec.ts > FinanceQueueView > should render shared finance queue for all finance
  officers` tests US3 behaviour from a US2 suite. The shared finance queue is US3: `T058` writes its Vue
  tests into `tests/Frontend/Component/FinanceReview.spec.ts`, and `T065`/`T193` own the view. So no task
  owns this particular assertion, and it will otherwise be counted as a US2 failure forever while US3 work
  proceeds. Either move it to the US3 suite or record explicitly why the US2 file keeps it (complete — moved,
  the first of the two options)

  **Moved, not recorded**: `tests/Frontend/Component/FinanceReview.spec.ts` is created as the US3 suite
  `T058` owns, and the `FinanceQueueView` block leaves `ManagementReview.spec.ts` with a comment saying
  where it went and why. `ManagementReview.spec.ts` is **24/24** and `npx vitest run` is now
  **160 passed / 160** across 6 files — the first time the frontend gate has been fully green, from
  1 failed / 127 passed of 128. `typecheck`, `stylelint`, `build` and `check:portability` are clean and
  `lint` is 0 errors / 105 warnings, unchanged.

  **`should deny administrator without finance role from finance actions` stays in the US2 file on
  purpose**, and that is the judgement the task asked to be recorded either way. It mounts
  `FinanceQueueView`, but what it asserts is an authorization property — an administrator without the
  finance role is offered no decision affordance — which is `T045`/FR-045 scope and cross-cutting rather
  than US3 finance-queue content. Moving it into the US3 file would be the same category error in reverse.
  It is recorded as weak rather than fixed here: it seeds an empty queue and then asserts no decision
  button is present, which an empty queue would satisfy even with authorization entirely absent, so it
  is a second instance of the pattern this task exists to correct and belongs with the `T045` work.

  **Both moved assertions were corrected rather than relocated, and the corrections are not cosmetic.**
  The red one asked for `[data-testid="queue-item"]`, a test id no component has ever emitted — the views
  emit `queue-row-{id}`, which is what lets a row be told from its neighbours, and inventing a `queue-item`
  attribute to satisfy it would have tested nothing. The other asserted the absence of
  `[data-testid="cost-center-filter"]`, which passes just as well against a view that renders nothing,
  since no queue view has ever had a cost-centre filter. FR-034/T062 make the substantive claim — the
  finance queue is shared, so finance asks for the whole queue and not a slice — and that is observable in
  the request, so it is asserted there. **The first version of that assertion read `config.url` and
  therefore could not fail**: axios keeps query parameters in `config.params` and serialises them at send
  time, so a `costCenterId` added to the request was invisible to it. It passes 4/4 with the mutation
  applied. Re-read from `config.params` and asserting the key set is exactly `['page', 'pageSize']`, the
  same mutation fails it. Two tests were added rather than only relocating two: that finance sees the
  senior decision it is deciding on top of, and that an empty queue says so.

  **The new suite drives the component through the transport instead of seeding the store.** The US2 file
  seeds `reviewsStore.costCenterQueue` and stubs `fetchCostCenterQueue`, which works only because the stub
  is what stops the mount-time load from overwriting the seed — it makes the component a passenger on state
  the test arranged, and it breaks silently if the view ever stops loading on mount. The first draft of the
  new file made the same mistake and rendered zero rows, which is how it was caught. Here the request is
  really issued and the page really arrives through `fetchQueue`, so the assertions are about what the
  component does with a response.

  **All four tests were mutation-checked**, because a relocated assertion that stops testing anything is
  the failure mode this task is about. Removing the per-row test id fails 2; removing the senior-decision
  cell fails 1; disabling the empty state fails 1; adding a `costCenterId` to the queue request fails 1.
  `src/` is byte-identical to its pre-task state afterwards.

### HIGH — PHP verification can execute stale bytecode and report a false result

- [X] T288 [HIGH] Make PHP verification immune to Apache's opcode cache, because the contract tests issue
  **real HTTP requests** and are therefore served by Apache, which has `opcache.enable => On` and
  `opcache.revalidate_freq = 60`. A run started within sixty seconds of editing `lib/` can execute the
  previous bytecode, and this is not theoretical: during this convergence a mutation check reported a
  **false PASS** (a hardcoded empty `totals` object that the tests provably do catch once fresh), and a
  restore was reported as a **false four-test failure** that then passed unchanged. A canary that made
  `SheetPayloadFormatter::totals()` throw unconditionally did not fire at all, which is what identified the
  cause: `opcache.enable_cli => Off`, so a `php -r 'opcache_reset();'` run through `scripts/dev/in-container`
  resets nothing in the SAPI that actually serves the request. Both a false PASS and a false FAIL are
  possible, and Constitution V requires evidence that acceptance criteria pass, so a verification result
  obtained this way is not evidence. The fix is to record the constraint where an agent will meet it and to
  give the wrapper a way to honour it — either a `scripts/dev` helper that clears the SAPI cache Apache is
  using, or an explicit settle requirement documented alongside the other `scripts/dev/*` rules in
  `AGENTS.md` (complete — `scripts/dev/opcache-settle` is the second of the two options, not the first, and
  that choice was forced rather than preferred)

  **Why a settle and not a reset.** The first option turned out to be unreachable from this repository.
  `opcache_reset()` only affects the SAPI it is called in, so the CLI run resets nothing that serves a
  request; and dropping a script into the document root does not work either, because
  `/var/www/html/.htaccess:216` is a front-controller config that falls through to `RewriteRule . index.php`
  — a probe script dropped in the docroot returned the Nextcloud 404 page instead of executing. The
  remaining route is `apachectl -k graceful` or a container restart, which is shared environment state and
  was not used. A wait is sufficient by proof, not by approximation: with `opcache.validate_timestamps => 1`
  a cached entry is served without touching disk only while `now - t_last_check < revalidate_freq`, and
  `t_last_check` is a moment at which the mtime was already correct, so a *stale* entry requires
  `t_last_check < mtime` and therefore `now < mtime + revalidate_freq`. Once `now > mtime + revalidate_freq`
  nothing stale can be served. `max_accel_time` is unset, so there is no second shorter TTL, and
  `file_update_protection` (2s) only stops caching freshly written files, which errs towards recompiling.

  **Measured here, both directions, with a canary throwing unconditionally from
  `SheetPayloadFormatter::totals()`.** Canary, no settle: 13/13 passing — a false PASS. Correct code, no
  settle: 2 failures — a false FAIL, and `null` where `100000` was asserted, which is the exact shape of a
  real regression, so an agent that trusted it would have "fixed" working code. Canary with settle: 2
  failures. Correct code with settle: 13/13. The middle row is the one that matters, because it means the
  number this task was written about is not an edge case but a routine outcome of editing and immediately
  re-running.

  **Delivered**: `scripts/dev/opcache-settle` (blocks; `--status` polls and exits 3 while outstanding; exits
  non-zero rather than proceeding if the wait cannot be computed), called by `scripts/dev/phpunit` before
  every run that can execute a test; an **Opcode Cache Settling (MANDATORY)** section in `AGENTS.md` so an
  agent meets the constraint before producing a number, plus a row in the wrapper table and notes in the PHP
  and frontend verification sections. The helper reports a *measurement*, never an assumption: a failed
  `docker exec` or a non-numeric mtime is rejected rather than being allowed to evaluate as `0` inside
  `$(( ))` and produce a confident false "settled", every pass re-scans so an edit made during the wait
  extends it, and the final state is read back after the wait instead of assumed from the loop comparison.
  Nine paths were exercised against a stubbed `in-container` — container unreachable, empty settings,
  unreadable mtimes, settled, unsettled `--status`, `validate_timestamps=0`, opcache disabled, missing
  `revalidate_freq`, unknown argument — all as intended. Scope is `lib/` and `appinfo/`, files and
  directories, so creates and deletes are covered and editing `tests/`, `docs/` or the frontend costs
  nothing.

  **T280's mutation claim re-measured** from a settled state, because it was recorded while the cache was
  untrustworthy and it is the number that would be cited to prove the totals projection is load-bearing:
  `SheetTotals::fromRows([])` instead of the query call fails **3** contract tests —
  `testSheetTotalsSumTheRealActiveRevisions`, `testSubmittedTotalIsASnapshotNotALiveSum`,
  `testSheetListCarriesTotalsToo` — plus the T275 baseline. The claim holds.

  **`npm run test:e2e` was exposed too and is now covered.** `tests/E2E/purchaser-submit.spec.ts` reaches
  the app at `http://localhost:8080`, so Playwright also executes `lib/` through the same cache; a
  `pretest:e2e` hook settles first, and `AGENTS.md` records it in the frontend section.

  **Result**: `scripts/dev/phpunit` is 190 tests / 937 assertions / 1 failure (T275), unchanged, and `lib/`
  is byte-identical to its pre-task state — the numbers did not change, they became trustworthy. Report:
  `docs/reports/T288.md`. **Cost accepted**: up to 61s once per edit to `lib/`, zero otherwise, which is
  the price of not touching the shared container. A reset would remove the cost if restarting Apache is
  ever approved.

### MEDIUM — the account payload projection now exists twice

- [X] T289 [MEDIUM] Move the account and movement projections out of `AccountService`, because
  `AccountPayloadFormatter` in `lib/Infrastructure/Http/` was added by T057 to report
  `AccountMutationResult` on a finance decision, and `AccountService::listForUser()` and
  `listMovements()` still build the same two arrays inline. Two copies of one shape is how
  `TransactionPayloadFormatter` came to exist in the first place: the transaction payload had a flat
  `revision` object and no `allowedActions`, `decisions` or `evidence`, and every affected template
  dereferenced `undefined` on a live response. T057 could not simply make the service delegate, because
  `AccountService` sits in the Application layer and taking an `Infrastructure.Http` dependency would
  invert the direction the layer rules set; the formatter's docblock says so rather than leaving the
  duplication looking accidental. The move is to relocate the projection to a layer both can depend on,
  or to have the controllers format from the aggregates and drop it from the service.

  **Delivered, and the count was worse than the task recorded.** Before this pass `lib/` held **three**
  definitions of the `Account` shape and **three** of the `Movement` shape, not two: `AccountService`
  had grown private twins of both *and* kept its original inline copies in `listForUser()` and
  `listMovements()`, so it held four of the six between it and the formatter.

  **The option taken was the first one — relocate — and the second was rejected on a concrete
  ground.** Having the controllers format from the aggregates is what `TransactionPayloadFormatter`
  and `SheetPayloadFormatter` already do, so it is the consistent direction; it cannot be applied to
  the two writes, though. `createFor()` and `charge()` return through `IdempotencyService`, so their
  arrays are not only the response but the *stored* result a replay is answered from. Rebuilding them
  from identifiers instead would make a replay report the account balance **as of the replay** rather
  than as of the charge — present-but-different, which is worse than absent, and the exact failure
  T290 names in its own task text. The Application layer is the one layer the service and the
  controllers can both depend on, so `AccountPayloadFormatter` moved to
  `lib/Application/Account/AccountPayloadFormatter.php` and the service delegates to it in all four
  methods. `lib/Infrastructure/Http/AccountPayloadFormatter.php` is deleted; `ReviewController` takes
  the new namespace. All six copies are gone, and the service holds none.

  **The guard is structural, and deliberately so.** A behavioural test cannot see this defect: both
  copies build the same array, so a projection that has drifted from its twin still answers every
  assertion anyone can make about the endpoint, and the two differ only in the field one of them
  forgot. `tests/Integration/Account/AccountPayloadSingleDefinitionTest.php` therefore reads the source
  and fails if `'balanceAfter' =>`, `'sourceRevisionId' =>` or `'userId' => $account->userUid` is
  written outside the formatter, naming the offending file. Those are the **quoted array-key** forms on
  purpose: the bare member names are also property names on the `AccountMovement` aggregate and column
  names in the persistence adapter, and the first version of this test matched them and reported four
  offenders, three of them innocent. A behavioural half sits alongside it, because a text match cannot
  tell a projection that is *called* from one that merely exists.

  **Both halves are load-bearing, and each was checked by mutation rather than by reading.** Restoring
  the inline array in `listForUser()` fails **1** (the structural half; the behavioural half passes,
  because the two copies were byte-identical). Making the ledger row lose `sourceRevisionId` *after*
  formatting fails **1** (the behavioural half; the structural half passes, because the mutation adds
  no array key). Neither guard alone is sufficient, which is the reason there are two.

  **Result**: `scripts/dev/phpunit` is 278 tests / 1456 assertions / 1 failure (T275, unchanged). The
  27 account contract tests and the 23 finance-review contract tests pass over real HTTP, which is the
  evidence that the responses are byte-identical rather than merely plausible — including the replayed
  charge and the `accountMutation` on a finance decision, the two paths that read a stored result.
  Report: `docs/reports/T289.md`.

  **Left alone deliberately**: `SheetPayloadFormatter` and `TransactionPayloadFormatter` stay in
  `Infrastructure/Http/`. Neither has a duplicate, and `plan.md` declares that directory as "stream
  responses and error mapping", so the three payload formatters arguably do not belong there at all —
  but reconciling the declared structure is T238's and T264's open work and moving two more classes
  here would be unrequested work in a task about one duplication. Noted, not done.


### MEDIUM — a replayed finance decision does not report the account effect

- [X] T290 [MEDIUM] Store the account mutation in the command result, because T057 made a replayed
  finance decision answer 201 again (it used to be refused with 409 — see T057) but the response for a
  replay is rebuilt from `IdempotencyService`'s stored result, which held only
  `['transaction_id' => …]`. The decision and the transaction were read back correctly, and the ledger
  was right — nothing was spent twice — but the contract's `accountMutation` was absent, so a client
  that retried after a timeout could not learn from the reply that money moved and had to re-read the
  ledger.

  **Delivered, and the fix was in the routing rather than in the storage.** The stored result now names
  the movement it wrote (`movement_id`), and on a replay the settlement reads that movement back through
  `AccountMovementRepository::findByCommandId()` — which needed no new port method, because the
  movement's `command_id` *is* the command id, the same fact the unique index already makes safe — and
  follows the account from the movement's own public id. The stored result is still consulted, so a
  replay of a **reject** reports no account effect rather than attaching whichever consumption happened
  to share the command id.

  **The routing was the real defect, and it is why the first attempt at this failed.** `ReviewController`
  chose the service from the transaction's *current* stage, which the first attempt has already advanced
  — a settled transaction reports `FINAL` — so a replayed finance decision was handed to
  `ManagementReviewService`, which has no account in it and therefore could not reconstruct the effect
  its first attempt caused. The controller now routes on the stage the **command** was issued against,
  read back from the decision the command already recorded. One root-cause fix, rather than teaching two
  services to recover the other's state.

  **Three tests, and both halves are load-bearing.** The replay reports the same movement id **and** the
  same resulting balance — present-but-different would be worse than absent, since it would describe a
  balance recomputed as if it were about to be spent again — and a replayed rejection reports no account
  mutation at all. Removing `movement_id` from the stored result fails **1**; restoring the
  current-stage routing fails **1**. `scripts/dev/phpunit` is 272 tests / 1447 assertions / 1 failure
  (T275).

### MEDIUM — the finance-only charge rule is implemented but unverified

- [ ] T291 [MEDIUM] Cover the FR-015 actor gate for `POST /accounts/{id}/charges` with a test that can
  actually fail, because T063 implemented the rule — only `FINANCE` or an administrator may charge — and
  **removing it fails nothing**. The reason is the fixture: the contract user is a finance officer, and
  the only state that grants the `FINANCE` role is `finance`, which the charge tests have to prepare
  because the rule is real. A refusal is therefore unobservable over HTTP with one user, exactly as
  T057 found for the finance queue. What is needed is a second acting identity that holds no
  `FINANCE` role and an authenticated request as that user, which the current `.env.testing` single
  credential cannot express — so this is a harness gap, not a product one, and it belongs with the
  other multi-identity work rather than being asserted around.

  **Partly advanced, and the remaining half is a provisioning decision rather than code.** The rule
  itself is now covered where it can fail, in
  `tests/Integration/Account/AccountChargeAuthorizationTest.php` — 7 tests over `AccountService::charge()`,
  which is where the gate is written. Both permissions the rule grants and the refusal are asserted,
  plus the two effective-date directions and the fact that a *different* real role (a manager) is still
  a refusal, because a gate written as "does this actor have any role" would pass every other test in
  the file. Removing the gate fails **5**; dropping only the administrator branch fails **1**;
  ignoring the effective window fails **2**.

  **Two things this does not do, stated so it is not read as more than it is.** It is not
  endpoint-level coverage: whether the route answers 403 to a caller without the role is a different
  assertion over real HTTP as a real second identity, and that is the half still open. And the
  `hasRole()` check on `$assignment->active` is *not* covered — it is unreachable through the port,
  because `DbRoleAssignmentRepository::findActiveByUserUid` and `isValidOn()` both filter on it, and a
  test of it would be a test of a double violating its own contract. The effective window itself *is*
  covered, and is not the same thing: that is what the expired and not-yet-effective cases measure.

  **Why the endpoint half is not simply written.** It needs a second Nextcloud identity and a second
  credential in the gitignored `.env.testing`, which means minting an app password against the shared
  container — a shared-environment write, and one that changes what every future run of the contract
  suite requires to be provisioned. That is a maintainer decision, not an implementation detail, and
  it is the same single-identity limit `T171` and the Playwright chain are blocked on. **Next action is
  that decision, not more code.**

  **One correction made along the way.** `tests/Contract/Fixture/seed-contract-fixtures.php` described
  the `FINANCE` grant as "granted only in the `finance` state" and then said two paragraphs later that
  it is not withdrawn in the other states. The code grants it unconditionally. The comment is now
  accurate, and it says why the grant being unconditional is the reason this gate is not covered over
  HTTP — which is the fact a reader of this task most needs and the one that was wrong.


### MEDIUM — recorded for awareness, deliberately not appended

- The 15 US2 assertions are the only frontend failures; `PurchaserFlow.spec.ts` is 85/85 after T283, and
  `http.spec.ts` and `typography.spec.ts` are green. T120 remains open with its FR-071 typography half done
  and its RTL/theme/card/contrast scope untouched, and T116/T122/T123 still own the browser-measured half of
  FR-071/FR-072. No new task: those are already tracked.

## Phase 38: Convergence (Three Passes Found the Same Defect, and It Is Not an Account Defect)

Run after implementation passes 3 and 4 (T057, T063). Both passes were contract tests over endpoints
`appinfo/routes.php` declared, and both found the same shape of failure: **a declared route whose
controller method does not exist answers HTTP 500.** That is worse than an absent route — the contract
says the endpoint exists and a caller can construct the request — and it is now the third independent
instance of one defect class rather than three coincidences.

### Convergence Findings

| ID | Gap Type | Severity | Source | Evidence | Remaining Work | Owned by |
|---|---|---|---|---|---|---|
| F1 | partial | HIGH | contracts/ocs-api.yaml, FR-010–017/FR-031/FR-065–069 | **15 of 41 declared routes resolve to a method that does not exist**: `evidence#links`, `evidence#deactivateLink`; all 11 `configuration#*` (no `ConfigurationController` exists at all); `report#show`, `export#create`. Every one answers 500. | Build them | T087, T096, T106–T112, T207 — **all already open, nothing appended** |
| F2 | partial | MEDIUM | FR-027, FR-040 | The reconciliation repository has **no port method and no adapter method anywhere**: `AccountRepository` declares four reads and a `save`. T057 moved the ledger *further* from reconciliation, not closer, because a settlement now writes a movement whose authority is a stored decision. | Decide what reconciliation means here | T060 — already open, blocked on a maintainer decision |
| F3 | partial | LOW | contracts/ocs-api.yaml | `OcsErrorMapper::map()` maps only `CommandConflict`, `InvalidArgumentException` and `LogicException`, so it has no vocabulary for a conflict that is neither an idempotency conflict nor a validation failure. T063's `AccountController` therefore hand-rolls a five-arm catch chain, and the next conflict endpoint will repeat it. | Give the mapper the vocabulary, or document the rule | **unowned — appended as T292** |
| F4 | partial | LOW | tasks.md bookkeeping | `T184` is still `[ ]` with the note "(missing)" although `tests/Integration/Workflow/FinanceSettlementTest.php` exists and its child `T056` is complete — the state `T185` was in until this run corrected it the same way. | Mark it, with the reason | **unowned — appended as T293** |
| F5 | partial | MEDIUM | FR-060, FR-071, FR-072 | The Playwright chain (T171, T053's three browser clauses, T054, T066, T076, T089, T099, T114, T122) is blocked on a browser-login credential. The `finance` fixture's `FINANCE` role grant and T291's actor-gate gap are the same single-identity limit seen from two directions. | A second acting identity in the harness | T171 and the tasks it names — already open, unchanged |

**Summary metrics**: 41 declared routes and their controller methods checked mechanically; the US3
finance slice checked against FR-015/FR-027/FR-034–FR-043 and the contract; the error-mapping
vocabulary checked across all 9 controllers; the error-status mapping in `OcsErrorMapper` checked
against every `catch` chain in `lib/Controller/` (no other controller mis-orders its catches, because
the five that catch only `\Throwable` delegate to the mapper, which resolves the same precedence
correctly — the T063 defect was local to the chain it introduced). Findings: 5 partial, 0 missing, 0
contradicts, 0 unrequested. By severity: 1 HIGH, 2 MEDIUM, 2 LOW. **Three of the five are already owned
by open tasks, so only T292 and T293 are appended** — appending the rest would duplicate T087, T096,
T106–T112, T060 and T171.

### What the two passes changed about the picture

**The US3 finance slice is now built and verified, and the last open item in it is a decision rather
than code.** T056, T057, T061, T062 and T063 are complete; T060 needs a maintainer to say what
reconciliation is; T065's remaining clauses need a real browser. Nothing else in US3 is actionable
without one of those two answers.

**The "declared route answers 500" class is a measurement gap, not a code gap.** F1's 15 routes were
never exercised by a test, because the suites that would have caught them did not exist — and a
declared route that answers 500 is invisible until something calls it. T063's own test file begins
with a `…IsReachable` assertion for exactly that reason, and F1 is the argument for doing the same for
every remaining declared route before the surfaces behind T087, T096 and T106–T112 are built: a
reachability assertion per route is cheap, and it converts "nobody noticed" into a failing test.

### MEDIUM — the error mapper has no vocabulary for a plain conflict

- [X] T292 [LOW] Give `OcsErrorMapper` the vocabulary for a conflict that is neither an idempotency
  conflict nor a validation failure, or record the rule that a new conflict endpoint hand-rolls its own
  catch chain. `map()` resolves `CommandConflict` → 409, `InvalidArgumentException` → 422,
  `LogicException` → 403 and everything else → 500, and T063 needed three new exception types
  (`AccountAlreadyExists`, `StaleAccountVersion`, `UnknownAccount`) to reach 409 and 404 at all — each
  one a `RuntimeException`, so the mapper would have answered 500 for all of them. The controller then
  has to remember which catch must come before which, and in T063's case the order *was* the bug:
  `InvalidArgumentException` extends `LogicException`, so one `catch (\Exception)` silently turned a 409
  into a 422. No other controller mis-orders its catches, because the five that catch only `\Throwable`
  delegate here and the mapper resolves the same precedence correctly — which is the argument for
  putting the whole vocabulary in one place rather than in each endpoint (partial)

### LOW — a parent task still claims work its child completed

- [X] T293 [LOW] Correct `T184`'s state, because it is still `[ ]` with the note "(missing)" while
  `tests/Integration/Workflow/FinanceSettlementTest.php` exists, holds 29 tests, and its child `T056` is
  complete. `T185` was in exactly this state and was corrected by the T057 pass, following the
  treatment `T183` already had ("exists; T055 already `[x]`"), so `T184` is the last of that shape
  rather than a deliberate judgement. This is a bookkeeping fix and must not be read as new test
  evidence: the file was written and verified by the T056 pass, whose report is
  `docs/reports/T056.md` (partial)

## Phase 39: Convergence (Configuration Audit Is Not Written, and the Task That Claims It Is, Is Checked)

Run early during Autopilot implementation pass 4, after T100. Pass 4 was attempting T139 and could
not proceed: T139's subject is T093's `AuditQueryService`, which does not exist, so there is nothing for
a role-scoped configuration audit query to be scoped over. Investigating *why* produced four findings
that no open task owns, three of them about a requirement — FR-056 — that no test in the repository
currently exercises at all.

### Convergence Findings

| ID | Gap Type | Severity | Source | Evidence | Remaining Work | Owned by |
|---|---|---|---|---|---|---|
| F1 | contradicts | CRITICAL | FR-056, FR-017 | Nothing writes `pettycash_configuration_audit` | Route it | **appended as T294** |
| F2 | contradicts | HIGH | T028, Constitution V | T028 is `[x]` on a false claim | Correct the record | **appended as T295** |
| F3 | partial | MEDIUM | FR-052 | Two dead audit-writer paths, both writing a null actor role | Remove or repair | **appended as T296** |
| F4 | contradicts | MEDIUM | FR-052, data-model.md | `command_id` nullable in the domain, NOT NULL in the schema | Reconcile | **appended as T297** |

**Summary metrics**: the audit-writer chain (`AuditWriter` port, `AuditWriterService`,
`AuditEventRepository`, `DbAuditEventRepository`, migrations `Version0100`–`Version0103`) checked against
FR-017, FR-051, FR-052, FR-055, FR-056 and Constitution IV; the task-state record for that chain checked
against the code. Findings: 4 — 1 CRITICAL, 1 HIGH, 2 MEDIUM; 0 missing, 4 contradicts/partial. All four
are appended, because T086 (write on every mutation), T093 (query service), T253 (subject-identity
naming) and T139 (verification) each own a *neighbouring* piece of this and none owns the routing defect,
the false completion claim, the dead paths, or the nullability mismatch. Appending them separately is
what keeps T093 and T139 from being built on an assumption that turns out to be false.

### CRITICAL — configuration audit is never written to its own table

  **Resolved by implementation; the task prose above was stale in two places and is corrected here.**
  It claimed `AuditWriterService::appendConfiguration()` exists at line 90 and "is never called". It did
  not exist at all — T296 had deleted the dead method, leaving no configuration writer and no caller —
  so the correct work was to write one, not to find and wire a dormant one. It also said the repository
  hardcodes the table in "all seven of its methods" and that `save()` could not write a configuration
  event "even if a caller asked it to". Both halves were half-right in a way that matters: the six
  reads all go through one shared `mapRow()` under `select('*')`, so they needed only a bound table
  name, but `mapRow()` itself **hardcoded `entityType=''` and `entityId=null` with the comment "for
  transaction audit"** and read `$row['sheet_id']` unguarded. So even a correctly bound configuration
  read would have warned on absent columns and returned a null subject — the configuration stream was
  unreadable as well as unwritten, and the task did not record that.

  **Stream is a constructor binding, not a parameter on `save()`.** The class takes a `$stream` name
  resolved through a private two-entry whitelist to a table and a subject-column set, and an
  unrecognised name throws at construction rather than falling back to the transaction stream — a silent
  fallback would write configuration events into the transaction table, the exact failure this task
  exists to prevent. This keeps the seven-method `AuditEventRepository` port unchanged and all six
  production `append()` call sites untouched, and it makes the reads stream-scoped for free. The two
  tables are disjoint in subject columns, so `save()` writes exactly its own set: the transaction stream
  gets sheet/transaction/revision/stage/decision, the configuration stream gets entity_type/entity_id.

  **T253 settled the naming question: `entity_type`/`entity_id` win**, over the task's
  `subject_type`/`subject_public_id`, so that is what T294 writes and what T093 will query.

  **`appendConfiguration()` is back, but through the port and wired.** It was added to the `AuditWriter`
  interface rather than to the service alone, so the surface test's rule — every public writer method is
  a port member — still holds. `FinanceSettlementService::recordDecision()` now writes an
  `ACCOUNT_CHARGED` configuration event naming the account it debited, on approval only, so a rejection
  or return does not accrue a charge. Its payload deliberately records no `before` balance:
  `recordConsumption()` mutates the account in place, so a "before" read there would be the
  post-consumption value wearing a "before" label.

  **Verified at both ends, in two suites, for an environmental reason worth recording.** The PHPUnit
  process has no `IDBConnection` (the real database is reachable only from the separate
  `roundtrip-runner` process), so no single test can span settlement to table.
  `RepositoryRoundTripTest::testAConfigurationAuditEventLandsInItsOwnTableAndIsInvisibleToTheTransactionStream`
  writes a configuration event through the real binding and asserts a real row in
  `oc_pettycash_configuration_audit`, that `entity_type`/`entity_id` survive, that the transaction
  stream's subject columns come back null, and that the transaction binding **cannot read it**.
  Collapsing both streams onto one table is caught (as `Unknown column 'entity_type'` — the disjoint
  columns make a stream mix-up a schema error, not silent corruption).
  `FinanceSettlementTest` asserts the settlement half against a recording writer: exactly one charge on
  approval, named for the debited account, carrying the causing command id, and none on rejection.
  `AuditWriterSurfaceTest` asserts each writer path reaches only its own repository and that a
  subjectless configuration event is refused. All three are mutation-proven. Suite: 640 tests,
  2840 assertions, no new failures (T275 unchanged).

  This task owns the write path and the routing. T086 (write before/after on every configuration
  mutation, in the same transaction) and T093 (the role-scoped query) remain open and must be built on
  whatever T294 lands, not alongside it. (contradicts)
  whatever T294 lands, not alongside it. (contradicts)

### HIGH — a completed task claims a writer that does not work

- [X] T295 [HIGH] Correct `T028`'s state and record why, because it is marked `[x]` and reads
  "Implement transaction and configuration audit writers that snapshot UID, display name, role, stage,
  revision, explanation, command ID, and canonical before/after JSON in `lib/Application/Audit/`", but
  the configuration writer it claims does not run and would not work if it did: `appendConfiguration()`
  has zero call sites, and T296 shows it would write a null actor role. Constitution V requires that
  documentation and task status agree with the code, and Delivery item 8 makes a task complete only when
  implementation, tests, documentation and task status all agree — so a checked box here is a false
  positive in the one record a reviewer is most likely to trust. Convergence may not rewrite existing
  task state, so the correction is a task rather than an edit. Mark T028 `[ ]` and cross-reference
  T294 and T296, with the evidence: `grep -rn appendConfiguration lib/ tests/` returns only the
  migration. T028 is now `[ ]` with the reason inline. **The evidence T295 cited has moved on since it was written, because T296 ran first in this pass and deleted the dead method outright** — so `appendConfiguration` no longer has even a declaration, and the only three remaining references to the name in the repository are the guard assertions in `tests/Unit/Audit/AuditWriterSurfaceTest.php` that keep it from coming back. The claim T295 made is therefore stronger than when it was written, not weaker: there is now no configuration writer of any kind. (contradicts)

### MEDIUM — two dead audit-writer paths, each of which would corrupt the actor role

- [X] T296 [MEDIUM] Remove `AuditWriterService::appendTransaction()` and
  `appendConfiguration()`, or repair them, because both are dead code that is also wrong.
  `ActorSnapshot::$role` is typed `string` (`lib/Domain/Shared/ActorSnapshot.php:9`), but
  `appendTransaction()` line 79 and `appendConfiguration()` line 112 both evaluate `$actor->role->value`.
  On PHP 8.5 that emits `Attempt to read property "value" on string` and evaluates to `null`, so either
  method would persist a **null actor role** where FR-052 requires the actor's role at the time of the
  event to be retained. All six production call sites use the `AuditWriter` port's `append()` and pass
  `'actor_role' => '...'` as a string, so nothing currently takes the broken path — which is exactly why
  this survived. The port declares only `append()`, so these two methods are outside the contract
  everything else is written against, and deleting them is the smaller change; the alternative is to type
  `ActorSnapshot::$role` as `Role` and let the callers pass the enum. Do not do both. (complete — **deleted**, the smaller option, along with the now-unused `ActorSnapshot` import. `AuditWriterService` is 56 lines and declares only `__construct` and `append()`. `ActorSnapshot` itself is **kept**: an initial `grep 'new ActorSnapshot('` returned zero and looked like a second dead class, but `WorkflowDecision::actor()` constructs it by fully-qualified name and `FinanceSettlementService`/`ManagementReviewService` import it, so it is live. Its `$role` stays a `string` — re-typing it to the `Role` enum was the rejected alternative, and doing both is what the task forbids. Guard: `tests/Unit/Audit/AuditWriterSurfaceTest.php`, 5 tests, including a tree-wide scan for `$actor->role->value` that deliberately exempts `RoleAssignment::$role`, where reading `->value` is correct because that property *is* the enum. Reintroducing the removed method with the original bug fails **3**) (partial)

### MEDIUM — the domain advertises an optionality the schema forbids

- [ ] T297 [MEDIUM] Reconcile `command_id` nullability between the `AuditEvent` domain type and the
  audit tables, because `AuditEvent::__construct()` types `$commandId` as `?string` while
  `lib/Migration/Version010200Date20260920000000.php:47` declares `command_id` with no
  `notnull => false`, making it NOT NULL. Every real insert must therefore supply one, so the domain's
  nullable type describes a state the database rejects — and the first two runs of T138's retention
  scenario failed on exactly that (`Column 'command_id' cannot be null`) before the scenario began
  passing a real UUID. `data-model.md` lists `command_id` as "Correlation/idempotency reference", which
  reads as optional, so the nullable domain type may be the intended design and the schema the error;
  the same migration gives the two audit tables `entity_id(64)` and `subject`-shaped columns
  non-nullable too, which T253 is already reconciling for the configuration table. Settle this together
  with T253 rather than separately, and apply the answer to **both** audit tables — the transaction
  table is the one with the live NOT NULL constraint today. (contradicts)

### MEDIUM — recorded for awareness, deliberately not appended

- T139 still cannot be written, and T294/T293 are the reason rather than the cure: T139 asserts that
  transaction audit queries never return configuration events and vice versa, and today there are no
  configuration events to leak, so the test would pass vacuously. It is tracked, and it becomes
  meaningful only once T294 lands. No new task — appending one would duplicate T139.
- `docs/audit-retention.md` described configuration audit as a working separate stream when T100 was
  written. The document has been corrected to carry a per-stream state table and an `Open items` entry
  saying that only the transaction table is written. Recorded here so the correction is traceable to
  this finding rather than looking like an unexplained edit.
- Psalm reports `AuditWriterService.php:79` and `:112` as `Expected type 'object'. Found 'string'`. That
  is F3, and it is the only genuinely new static-analysis signal in this area; the surrounding 758
  errors are the repository's pre-existing baseline.

## Phase 40: Convergence (Three Task Notes Assert a Completion That Never Happened)

Run at the start of an Autopilot run resumed from checkpoint `ac1a12c`. The last two convergences
worked the route and audit-writer surface, so this one assessed the US4/US5/US7 slice surface instead,
which neither had examined, plus a mechanical cross-check of task notes against task states.

### Convergence Findings

| ID | Gap Type | Severity | Source | Evidence | Remaining Work | Owned by |
|---|---|---|---|---|---|---|
| F1 | contradicts | HIGH | T032, T195, T196 | Three notes assert a task is `[x]` that is not | Correct each in the direction its own evidence shows | **appended as T298** |
| F2 | missing | MEDIUM | T032 | US5 configuration slice absent entirely | Build | T081–T089, T205 — open |
| F3 | missing | MEDIUM | T032 | US7 export slice and report/dashboard queries absent entirely | Build | T105–T111, T155, T207 — open |
| F4 | contradicts | CRITICAL | FR-056 | Nothing writes `pettycash_configuration_audit` | Route it | T294 — closed, stream-bound, mutation-proven |
| F5 | partial | MEDIUM | FR-052 | Two dead audit-writer methods, zero call sites | Remove or repair | T296 — open |

**Summary metrics**: 81 FRs + 14 SCs + 9 US acceptance sets + 5 constitution principles; the US4, US5
and US7 slice surfaces checked file-by-file against their owning tasks; every task note claiming
another task's completion cross-checked against that task's actual checkbox. Findings: 5 — 2 missing,
3 contradicts/partial; 1 CRITICAL, 1 HIGH, 3 MEDIUM. One appended. F2 and F3 are large bodies of work
and are **not** appended: T081–T089 and T105–T111 already own them precisely, and recording them again
would create exactly the duplicate task this workflow is required to avoid.

### HIGH — three notes assert a completion that never happened

- [X] T298 [HIGH] Correct three task notes that assert a completion which never occurred, in **two
  different directions**, because the evidence points opposite ways in each case and a single blanket
  "reconcile the state" would get one of them backwards.

  **Case 1 — T067 and T068: the checkbox is wrong, and should be marked `[x]`.**
  `git log -L` shows `e074cff` (2026-09-22) *created* both lines as `- [ ] T067` and `- [ ] T068`, and
  they have never been checked since. Yet T195 and T196 were added as `[x]` on 2026-09-26 (commit
  `4f7710c`) with notes asserting that T067 and T068 were already complete — claims that were false the
  moment they were written. The deliverables exist and **pass**: `TransactionRevisionTest.php` holds 11
  tests and `TransactionCorrectionTest.php` holds 9, and the pair is 18 tests / 49 assertions green
  under `scripts/dev/phpunit`. Nothing reopened them, and no note gives a reason to leave them open, so
  the checkboxes are stale rather than deliberate. Mark both `[x]`, and correct T196's "7 passing tests"
  to 9, since the file grew after that note was written.

  **Case 2 — T053: the checkbox is right and the note is wrong.** T032's table at line 1933 records
  T032's table cell for T181, which recorded the US2 frontend task as already complete. T053 is open
  and correctly so: T286 reopened it, because its three browser-measured FR-071 clauses (no horizontal
  scroll at 390px, WCAG AA contrast, the ≥12pt floor as a measurement) cannot be settled under jsdom and
  belong to T116/T122/T123. Only the note is inaccurate. Correct T032's table cell to say T053 is open
  and why, so a later pass reading the table does not treat T053 as evidence that the US2 frontend is
  finished.

  **Why this is one task and not two.** Both cases are the same defect — a note asserting a state that
  is not true — and both live in the same two tables. Splitting them would mean two passes editing the
  same lines for no gain. The instruction that they move in opposite directions is the substance, and it
  is what makes this a task rather than a blanket tidy-up: marking T053 complete would be a
  false completion, and leaving T067/T068 unchecked is a false gap.

  **What this is not.** Per T293's precedent, correcting a checkbox is a bookkeeping fix and must not be
  read as new test evidence. The 18 tests were written and verified by earlier passes; this records that
  the record finally matches the code. Convergence may not rewrite existing task state, hence a task
  rather than an edit. (complete — **case 1**: T067 and T068 marked `[x]` with the `git log -L` history inline, and T196's "7 passing tests" corrected to 9 since the file grew. **Case 2**: T032's table cell for T181 rewritten to say T053 is **open** and why, rather than "already `[x]`". The mechanical cross-check that found all three is now itself guarded by `tests/Unit/Workflow/TaskStateConsistencyTest.php`, so a fourth instance fails the suite instead of waiting to be found by hand. (contradicts)

## Phase 41: Convergence (FR-055's Finance Clause Depends on a Fact the Schema Does Not Hold)

Run at the start of an Autopilot invocation, after T106. T106 delivered the FR-055 configuration-audit
visibility *rule* and tested it, and in doing so had to stop at a boundary it could not cross honestly:
resolving **which** accounts a finance officer has charged. That boundary is a schema and port fact, not
an implementation detail, so it belongs here rather than in a task note.

### Convergence Findings

| ID | Gap Type | Severity | Source | Evidence | Remaining Work | Owned by |
|---|---|---|---|---|---|---|
| F1 | missing | HIGH | FR-055, `contracts/exports.md` | "Accounts they charge" is not derivable from the schema or any port | Record the charge actor, make it queryable | **appended as T299** |

**Summary metrics**: the charge-actor chain (`AccountMovement`, `AccountMovementRepository`,
`CommandResultRepository`, `CommandResult`, `IdempotencyService`, `AccountService::charge()`) checked
against FR-055 and FR-016; the US7 slice reassessed after T106; route reachability re-verified
mechanically (8/8, 15 unresolved matching the documented task-owned list). Findings: 1 missing, 1 HIGH.
One appended; F2 (the export and reporting services are deliberately not container-registered until
T111/T112 have consumers) is recorded for awareness only, since it has named owners and a documented
rationale.

### HIGH — the finance clause of FR-055 has no data behind it

- [x] T299 [HIGH] Make "accounts a finance officer has charged" derivable, because FR-055 requires
  **Resolved by T294 landing first, which changed the answer and made the cheaper route the correct one.**
  This task offered two routes and said the choice belonged to the schema rather than to a report. T294
  then wrote a real `ACCOUNT_CHARGED` event to the configuration audit stream naming the account in
  `entity_id` — which is the record FR-017 asks for anyway. So neither offered route is needed:
  the derivation reads **the audit trail itself**, not the command log. No migration, therefore no
  approval-gated baseline reset, and `IdempotencyService`'s port is untouched, so the trap T106
  stopped short of is gone rather than worked around. T299's own closing paragraph predicted this —
  that T294 "decides whether there is anything for this to scope over".

  **One port method added, and the guard that watches it was updated deliberately.**
  `AuditEventRepository::findEntityIdsByEventType()` returns the distinct subjects an event type has
  been recorded against. It is a read, in the same family as the other six, and
  `RetentionPolicyTest::testTheAuditPortOffersOnlyReadsAndAnAppend()` — which asserts the port's exact
  method list as data — now includes it. The invariant that test protects is unchanged: the port gains
  reads and one append, and still offers no way to delete or rewrite an event. On the transaction
  stream the read **throws** rather than returning nothing, because "no entity ids" and "wrong table"
  are opposite conclusions and only one of them is safe to act on.

  **The business rule lives in the application layer, not the port.**
  `ChargedAccountScope` (new, `lib/Application/Reporting/`) turns entity ids into account surrogate
  keys, and deliberately has no notion of a "current officer": FR-016 puts every finance officer on one
  shared queue, so a charge is an instance-wide fact and an officer may read configuration audit for
  accounts charged *at all*. An account a charge names that no longer resolves is dropped rather than
  reported, because `configurationAuditScope()` widens from the ids it is handed and a fabricated id
  would widen the visible set to an account nobody can name. An instance with no charges yields an
  empty list, never "everything" — the direction that has to fail safe.

  **Verified, and the two halves are proved by different means on purpose.** The contract test proves
  the real path end to end: a finance approval in `FinanceReviewControllerTest` writes a charge against
  the shared database, and `ReportAndExportControllerTest` then finds that exact account in the
  officer's accounts report — which is only reachable through this derivation, and only because T294
  recorded the charge. That test is residue- and order-dependent by nature, so
  `ChargedAccountScopeTest` (7 tests) covers the derivation deterministically instead, including the
  cases a happy path hides: a charge by another officer still counts (FR-016), repeated charges yield
  one id, an unresolvable account is dropped, an empty instance is not "all", another event type on the
  same account is not a charge, and an entity-idless event names no account. Mutations on the scope and
  on the real-DB event-type filter are both caught.
  configuration audit to be visible to "finance officers ... for accounts they charge", and **no part of
  the schema or any port can currently answer that question.**

  **The chain, verified rather than inferred.** `AccountMovement` carries no actor or charged-by field
  at all, so the ledger cannot say who charged an account. `AccountMovementRepository` offers four reads
  and none of them is by actor. `CommandResultRepository` exposes exactly `find(string $commandId)` and
  `save()`, so the one row that *does* record the answer — `pettycash_command_results.actor_uid` alongside
  `operation` — is reachable one row at a time and **cannot be enumerated by actor**. A charge does go
  through `IdempotencyService` and therefore does write that row, so the fact exists; it is simply not
  addressable.

  **Why this is a task and not a note.** T093 and T139 both *reference* the rule — T139 as
  `finance see charged accounts` — so it looks covered. Neither owns supplying the data, and both would
  otherwise be written against an assumption that turns out to be false, which is the same trap T294 set
  for the configuration-audit routing. T106 hit this and stopped rather than inventing a
  `findByActorAndOperation()` port method, because that method would change a port `IdempotencyService`
  also depends on and the choice belongs to the schema, not to a report.

  **What it must settle, and why implementation cannot choose.** There are two honest routes and they
  have different costs:

  - *Record the charge actor on the movement.* `pettycash_account_movements` gains a nullable
    `charged_by`, backfilled from `pettycash_command_results` where the command ids join, and
    `AccountMovementRepository` gains a by-actor read. This is a forward-only schema change to an
    unshipped V1 baseline, so per FR-080/FR-081 it is the same in-place treatment the `pc_*` → `pettycash_*`
    rename took, **including the approval-gated development reset that makes a rewritten baseline
    execute** — which is a shared-environment action and therefore a maintainer decision.
  - *Enumerate the command log.* Add a by-actor-and-operation read to `CommandResultRepository` and derive
    the charged accounts from recorded charges. No schema change, so no reset, but it makes the report
    depend on a table whose purpose is idempotent replay rather than audit, and it couples
    `IdempotencyService`'s port to a reporting concern.

  Either way, **FR-016's shared queue must survive the answer**: every finance officer shares one queue,
  so "accounts they charge" is an instance-wide fact and any officer may read configuration audit for
  accounts charged *at all*, not only their own charges. T106's `configurationAuditScope()` already
  implements the rule that way, with an empty scope never widening to all.

  Related and still open, for the same slice: **T294** (configuration audit is never written at all,
  gated on T253) and **T253** (the `subject_type`/`subject_public_id` versus `entity_type`/`entity_id`
  disagreement). T294 is the one that decides whether there is anything for this to scope over; the two
  are best settled together, since a charge's configuration audit event is exactly where the subject
  identity lands. (missing)

## Phase 42: Convergence

Two findings from the T111/T112 implementation passes. Both are verification-integrity problems rather
than missing features, and the first one is the reason the second one was hard to act on.

- [x] T300 Extend `scripts/dev/opcache-settle` (or add a sibling wrapper) to also settle Nextcloud's
  **local route cache**, and document in `AGENTS.md` that an OCS contract result is only evidence once
  this cache is settled too, per T288's integrity requirement. `scripts/dev/opcache-settle` settles
  `opcache` for `lib/` and `appinfo/` and reports `settled` — but Nextcloud's `CachingRouter` resolves
  each incoming URL through `$cacheFactory->createLocal('route')`, a **local** cache (APCu under
  `mod_php`) holding the URL-to-route-name mapping. `Router::loadRoutes()` re-reads `appinfo/routes.php`
  on every request, so the route *definitions* are always current while the *match* can be stale.
  Observed directly: renaming a route (`session#dashboard` to `dashboard#index`) passed the settle and
  then returned `500` with `ReflectionException: SessionController::dashboard() does not exist`, from a
  `nextcloud.log` entry timestamped after the edit — a healthy application reporting a broken one. T288
  established that an unsettled cache makes a PHP result a lie in either direction; this is the same
  defect in a cache T288 did not reach, so every future route change can silently mis-report. The cache
  has no CLI-side clear (`occ` uses an array local cache, not Apache's APCu), so clearing it needs a
  signal to the Apache master process — which `scripts/dev/in-container` cannot send, because it is
  deliberately `--user www-data` and the container refuses the signal. Decide the supported mechanism
  (a wrapper that signals PID 1, or an explicit documented escalation) rather than leaving each agent to
  discover this. (missing)

- [x] T301 Record in T112's task note that `/api/v1/dashboard` resolves to a **role stub**, and extend
  `RouteResolutionTest` so a resolved route is not treated as a working endpoint, per US7/AC1. The route
  resolves and `session#dashboard` is absent from `KNOWN_UNRESOLVED`, so the guard reports the dashboard
  requirement met — but `SessionController::dashboard()` returns `['userId' => $uid]` for each held role
  and computes no figure at all, and `DashboardQuery` is referenced nowhere in `lib/AppInfo/Application.php`
  or any controller. So US7/AC1's role-aware dashboard is unbuilt behind a green check: the guard can see
  that a method exists, not that it does its job. Two planning inconsistencies land in the same place and
  cannot be fixed by editing T112 in place (convergence is append-only): T112's task line names
  `lib/Controller/DashboardController.php`, while the ownership note inside `RouteResolutionTest` says T112
  creates only `ReportController` and `ExportController`; and that note ends mid-sentence at
  "`session#dashboard` is already routed", so it never states what should happen to the stub. Note also
  that `DashboardController`, once built, will make this finding stale in the other direction — the
  `figures` it returns are computed from **paged** repository reads (`findByOwnerUid(..., int $limit = 100)`
  and friends) and no domain repository has a count query, so a figure is a window size, not an instance
  total; `ManagementReviewService::getCostCenterQueue()` has the same property today. Any replacement has
  to say so in the response rather than let a window size pose as a total. (partial)

  **Delivered**: `scripts/dev/route-settle` (verifies, or blocks with the fix;
  `--status` exits 3 while outstanding) and `scripts/dev/route-cache-clear` (signals
  `SIGUSR2` to the Apache master, waits for the workers, then records the moment in
  `/tmp/pettycash-route-cache-cleared` inside the container). `scripts/dev/phpunit` runs
  the verification whenever the selected tests include `tests/Contract`, checking **all**
  arguments rather than the first so a mixed run stays protected. `AGENTS.md` documents it
  under "Route Cache Settling (MANDATORY)".

  The bound is provable rather than guessed: a stale entry is one written before the edit
  (`T < M`) that has not yet expired (`T >= now - TTL`), so it is possible exactly when
  `now - M < TTL`. The TTL is read out of the shipped `CachingRouter.php` instead of
  trusted from a constant, and the script refuses to run if `debug` is enabled, since that
  selects the short TTL this proof does not model.

  The first design was wrong and the evidence showed it: `route-settle` originally
  printed the clearing command and exited. After a real clear it **still blocked**, because
  its rule is mtime-based and cannot know a clear happened — so an operator who did the
  right thing was told to do it again for another 47 minutes. That is the same
  "cannot tell not-yet-done from already-done" failure as T288, and it is why the clear
  records itself. A recorded clear newer than the edit now settles immediately.

  Verified: boundary is exact (3599s -> exit 3, 3601s -> exit 0); the settled path and the
  blocking path both observed live; `phpunit tests/Unit/...` passes while
  `phpunit tests/Contract/...` exits 1 with no tests run; a marker older than the edit does
  not settle, a newer one does, and a non-numeric marker is rejected with a message rather
  than reaching `[:` as an integer. The clear was run once against
  `docker-nextcloud-1` and the 23-test contract suite passed through the wrapper afterwards.
  Full suite 593 tests, 2316 assertions, 1 pre-existing T275 failure.

  **Delivered**: `RouteResolutionTest` gained two tests. `testEveryDispatchableGetOcsRouteDoesNotAnswerAServerError`
  dispatches every parameter-free GET OCS route over real HTTP and fails on any 5xx;
  `testParameterizedOcsRoutesAreNotCoveredByDispatch` reports the routes it skips so the check
  cannot quietly mean less than it appears to. Asserting "not 5xx" rather than "2xx" is deliberate:
  authorization legitimately answers 4xx, and judging response *content* is the contract tests' job.
  Routes in `KNOWN_UNRESOLVED` are excluded because a 5xx there is the recorded state with an owning
  task -- the first version flagged all four `configuration#*Index` routes for precisely that reason.

  Proven by mutation: pointing `/api/v1/dashboard` at the removed `session#dashboard` makes the new
  test report `session#dashboard ... answered 500` and fail, which is the dashboard's exact shape. The
  structural guard above stayed green through the same mutation, which is the blind spot T301 named.

  **Stated limitation**: 8 parameterised GET routes are not dispatch-covered (`sheet#show`,
  `transaction#show`, `transaction#revisions`, `transaction#audit`, `evidence#content`,
  `review#index`, `account#movements`, `report#show`), because a synthesised id is not a real record and
  a 4xx from one would say nothing about routing. The test prints them on every run so the exclusion
  stays visible. The T112 note's own claim -- that the dashboard resolved while being a stub -- is
  recorded here and in Phase 42, which is where convergence can put it: convergence is append-only and
  cannot amend T112's text.

  **Delivered**: `lib/Controller/ReportController.php` (`GET /reports/{reportType}`) and
  `lib/Controller/ExportController.php` (`POST /exports`), both registered in the container and both
  taking their scope from `DashboardQuery::scopeFor()` so a report and a dashboard cannot disagree about
  who the caller is. `report#show` and `export#create` are removed from `RouteResolutionTest`'s
  `KNOWN_UNRESOLVED` in this same change, per that file's two-part rule. The dashboard half of this task
  was delivered earlier in `42be65f`.

  Every declared report type is served from a real read and scoped by `ReportQuery::paginate()`, which
  scopes before it filters; the controller never pre-filters, because doing so here would reintroduce
  the ordering hazard T106 removed. The enum is the contract's exactly, asserted as data, and an
  undeclared type is refused with a 422 naming the contract's set. **The contract gap is recorded, not
  resolved**: `balances`, `movements` and `configuration_audit` are refused, because FR-065 and FR-056
  name them and `contracts/ocs-api.yaml` does not, and adding them from a controller would be a contract
  change made in the wrong place. The `accounts` report returns the caller's own accounts and says so in
  its summary (`scope: own_accounts`, `chargedAccountsIncluded: false`), because FR-055's charged-accounts
  rule is not derivable from the schema and is T299's to settle.

  `POST /exports` reads the command id from the `X-PettyCash-Command-ID` header the contract declares
  required, refuses a missing one with 422, and delegates authorization-and-generation to
  `ExportService::generate()` so the ordering requirement lives in one place. The artifact is streamed and
  then removed, in that order, in a `finally`. `ExportService` gained `readArtifact()` and
  `deleteArtifact()` for this: its existing `cleanup()` calls `deleteAll()`, which is right for a teardown
  and wrong for a controller, because two exports in flight would delete each other's bytes.

  **Verified**: both routes resolve (the guard's stale-entry test now reports exactly these two as
  resolved and nothing else), and a live OCS probe returned `422` with a well-formed envelope for
  `POST /exports` and `200` with a correct `ReportPage` -- `page`/`pageSize`/`total`/`columns`/`rows`/
  `summary`, default `pageSize` 25, Persian titles intact -- for `GET /reports/sheets`.

  **Gap, stated rather than hidden**: the permanent contract test for these two endpoints is **not**
  written. A first version of it was written and removed in the same pass: its `GET` half passed, while
  all six of its `POST` cases failed on an empty response body even though an equivalent standalone
  probe of the same endpoint, with the same client, the same headers and the same JSON body, returned a
  correct 422. That contradiction was not diagnosed before the context budget ran out, and committing
  six red tests would have been worse than committing none. Whoever picks this up should reproduce it
  with a minimal POST-only case first: the difference between the working probe and the failing test is
  the single most useful thing to know, and the standalone probe is the known-good reference.

  Also unaddressed here, and left for convergence: the guard's dispatch check skips `POST` routes, so
  `export#create` is confirmed to resolve and to answer, but nothing keeps it answering in future.


  **REOPENED — the export half does not meet `contracts/ocs-api.yaml`, and the contradiction is in
  the contract rather than the code.** Two independent blockers, both verified at the source level
  instead of inferred:

  1. **`ExportRequest.format` cannot be sent to an OCS route.** `Request::getFormat()`
     (`lib/private/AppFramework/Http/Request.php:903`) reads `getParam('format')` **first** and only
     falls back to the `Accept` header when that param is null. `OCSMiddleware` then does
     `$format === null || !$controller->isResponderRegistered($format) ? 'xml' : $format`
     (`OCSMiddleware.php:114-116`). A request carrying the contract's own `format` field therefore
     always beats `Accept: application/json`, fails the responder lookup, and the endpoint answers
     **XML**. Measured: body with `format` -> XML; body without -> JSON; `format` in the query string
     -> XML; `?format=json` together with a body `format` -> XML. No client-side workaround exists, and
     no other endpoint here receives a `format` field, which is why every other POST kept answering JSON.
  2. **The declared `200` cannot be produced by an OCS route at all.** The contract declares
     `text/csv` / `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` /
     `application/pdf` / `application/zip` -- the raw artifact. Every OCS route is wrapped by
     `OCSMiddleware` in a `V2Response` envelope, so a bare artifact body is unreachable from a route
     registered in the `ocs` section. The committed implementation returns an OCS JSON envelope with
     the bytes base64-encoded (`e4fe450`), which deviates in the opposite direction.

  **Decision required, and it belongs to the contract owner rather than to implementation:** either
  amend the contract to an OCS-compatible shape -- an OCS JSON envelope carrying the encoded artifact,
  and a field name that is not OCS's reserved `format` -- or move `/exports` out of the `ocs` section
  into a plain `routes` entry, which makes both the raw artifact and the `format` field name available
  but changes the route's public surface and how it is authenticated.

  The report and dashboard halves are unaffected and remain correct: `GET /reports/*` answers `200`
  with a contract-shaped `ReportPage`, and the guard's stale-entry check reports exactly `report#show`
  and `export#create` as resolved. The checkbox is reopened rather than left ticked because a task
  whose verification is known-incomplete must not read as done.


  **Resolved and closed.** The contract owner chose to amend `contracts/ocs-api.yaml` to an
  OCS-compatible shape rather than move the route. Applied: `ExportRequest.format` is now
  `ExportRequest.fileFormat` (and `required: [resource, fileFormat]`), and the `200` is declared as
  `application/json` referencing a new `ExportResult` schema -- `artifactName`, `mediaType`, `format`,
  `byteSize`, `rowCount`, base64 `data`, `artifactRemoved` -- with the reason recorded inline in the
  contract so the next reader does not rediscover it. `ExportController` reads `fileFormat` and reports
  the missing-field error under the new name, because telling a client to send `format` when the field
  is `fileFormat` would produce a request that cannot succeed.

  `tests/Contract/Controller/ReportAndExportControllerTest.php` now covers both endpoints: 14 tests,
  290 assertions, green. Its `decode()` helper reports the envelope's *format* rather than assuming
  JSON, because `json_decode` of a well-formed XML OCS response returns null and that is exactly how the
  `format` collision first presented -- as a body that looked missing rather than as the wrong format.

  Proven by mutation: reverting the controller to read `format` makes
  `testAConformingExportRequestAnswersJson` fail, and restoring it makes the test pass. A future rename
  back to the reserved name therefore fails in this repository rather than in a client.

  Full suite: **618 tests, 2695 assertions, 1 pre-existing T275 failure.**

  **Traceability pass (spec-kit analyze remediation E4).** FR-066, FR-067 and FR-069 are now
  referenced by the tasks that implement them: T107 [FR-066], T108 [FR-066, FR-067], T110
  [FR-066, FR-069], T111 [FR-066]. Purely additive; no behaviour changed.

  **FR-068 is deliberately still unreferenced.** It requires group exports to support selected
  sheets, date ranges and cost-centre transactions. `ExportRequest` accepts `ids` and `filters`, but
  `ExportController` generates from an empty row set, so the selection capability is accepted and
  ignored. Referencing it on T112 would have been false traceability -- the exact defect this pass
  exists to remove -- so it stays uncovered and visible until an implementation actually honours the
  selection. Finding A4 is therefore only partly closed.
## Phase 43: Convergence

Four findings from the `/speckit.analyze` pass and the T112 export work. A1/A2/A3 from the analysis
(FR-065 and FR-056 against the report enum, `subject_*` against `entity_*`) are **not** repeated here:
they are already recorded on T112, T299 and T253/T294, and convergence does not duplicate tasks that
exist. These four are uncovered.

- [x] T302 Honour the group-export selection that `POST /exports` currently accepts and discards, per
  FR-068 and `ExportRequest`, and cover it with a test that fails when the selection is ignored.
  `ExportController::create()` calls `ExportService::generate($scope, $resource, $fileFormat, [])` with a
  literal empty row set, so `ids` and `filters` are read by nobody and a caller that selects two sheets
  and a date range receives an export containing neither. This is worse than refusing the request: the
  response is a well-formed artifact that silently misrepresents what was asked for, and
  `artifactRemoved`/`rowCount` make it look authoritative. (missing)

- [x] T303 Identify and record the tasks that enforce FR-003 and FR-041, or state that nothing does.
  Both are MUSTs with zero references anywhere in `tasks.md`: FR-003 ("Purchasers MUST be limited to
  their own sheets, transactions, evidence, histories") and FR-041 ("Pending or finance-rejected
  transactions MUST NOT affect the actual petty-cash balance"). Untraceable security and correctness
  requirements are indistinguishable from unimplemented ones, which is what made the FR-068 gap above
  hard to see. The deliverable is a task reference per requirement -- or a new task, if the honest
  answer is that neither is enforced deliberately. (partial)

- [ ] T304 Add buildable coverage for SC-008, or amend SC-008 to state that performance verification is
  post-V1. SC-008 requires the V1 operating scale (100 active users, 10,000 annual transactions) to hold
  a stated latency bound, and no task references it. Left unstated, the criterion reads as covered
  because it sits in the spec. The choice belongs to the spec owner: a verification task, or an
  explicit deferral. (missing)

- [x] T305 Reconcile US7/AC4 with what the export endpoint can actually produce, and pin the request
  field name in the spec. AC4 asserts a grouped export "in Excel, CSV, or PDF as applicable", but T109
  is still open and `ExportController` refuses `pdf` with a reason, so the acceptance scenario is
  currently unsatisfiable. The scenario also says "a supported format" without naming the field, which
  is why `contracts/ocs-api.yaml` could name it `format` and collide with the parameter OCS reserves for
  response negotiation without anything catching it (T112). Terminology also drifts: AC4 says "Excel"
  where the contract enum says `xlsx`. Requires `/speckit.specify` refinement; implementation must not
  silently rewrite an approved acceptance scenario. (contradicts)

  **Delivered, partially — and the remainder is stated rather than implied.** Verified enforcement, with
  the assertion that proves it:

  - **FR-041 -> T061.** `tests/Integration/Workflow/FinanceSettlementTest.php:243` asserts
    `assertCount(0, $this->movements->allOfType(MovementType::Consumption), 'FR-041: a rejected
    transaction must not affect the balance')` — the test cites the requirement by name, and
    `FinanceSettlementTest.php:244` then asserts the balance is unchanged. T061 is the task that
    implements that settlement. (T056 wrote the failing integration tests.)
  - **FR-003 -> T063**, for the account/ledger clause. `AccountControllerTest.php:272`
    (`testAnotherUsersAccountIsRefusedLikeOneThatDoesNotExist`) asserts a foreign ledger does not
    answer 200, i.e. is refused exactly as a nonexistent one would be. T063 added those OCS routes and
    controllers.

  **Not verified in this pass**: FR-003's remaining clauses -- purchasers limited to *their own
  sheets, transactions, evidence, and histories*. Each of those is enforced somewhere in the
  controller and contract suites, but this pass did not locate and cite the specific assertion for
  each, and inventing a reference would recreate the false traceability this task exists to remove. So
  FR-003 is now traceable for the clause that was checked and still untraced for the rest. The
  reporting-scope half of the same requirement is enforced by T106/T112 (`ReportScope::coversOwned()`),
  which is the mechanism the remaining clauses should be asserted against.

  This is the second time in two passes that a requirement turned out to be enforced but unrecorded.
  FR-068 (T302) was the first. The pattern suggests the traceability gap is systematic across this
  spec rather than incidental, which is worth a convergence finding of its own.

  **Delivered.** `ExportController` now resolves `ExportRequest.ids` and `.filters` and the
  established invariant is that a selection is **honoured or refused, never accepted and discarded**.
  For `sheet`/`sheets`/`transactions` the selection becomes real, scope-checked rows. For `report`,
  `audit` and `evidence`, which do not resolve a selection yet, a request carrying one is refused with a
  reason naming what *is* supported, rather than answered with an artifact that omits it. A sheets or
  transactions export with **no** selection is also refused: an empty artifact answers "you have no
  sheets" when the truth is "I was not asked which sheets".

  Two ordering properties were established deliberately:
  - **Servability before selection.** `ExportService::authorization()` is consulted first, so a `pdf`
    request is refused with the T109 font reason rather than with a selection complaint that hides it.
  - **A missing id and a foreign id are the same refusal**, matching `AccountController`'s treatment of a
    foreign ledger, so the endpoint cannot be used to confirm the existence of records the caller may not
    see (FR-003).

  Added `Application/Reporting/UnsupportedSelection` so "cannot satisfy this selection" is distinguishable
  from "malformed request", both still answering 422 per the contract.

  **Verified**: 19 tests / 314 assertions green, including five new FR-068 cases. Proven by mutation:
  restoring `$rows = []` -- the original defect -- fails
  `testAnExportHonoursTheSelectedIds` (`rowCount` 0, not 1) and turns both refusal tests from 422 into
  200. Full suite **623 tests, 2719 assertions, 1 pre-existing T275 failure**.

  Two T112-era tests were updated rather than worked around: they exported `sheets` with no selection
  and asserted an empty-but-valid artifact, which T302 deliberately makes a refusal. They now pass the
  selection they always meant to pass.

  **Known limitation, stated**: `report`, `audit` and `evidence` selections are refused rather than
  implemented. FR-068's "selected sheets, date ranges, cost center transactions" is honoured for sheets
  and transactions by id; **date-range filtering is not implemented** and a `filters` object is parsed
  but not applied, so a request that supplies only `filters` and no `ids` is refused rather than silently
  unfiltered. That remainder is unowned by any task and needs one.
## Phase 44: Convergence

One finding, surfaced by implementing T302 and explicitly recorded there as unowned.

- [ ] T306 Narrow an export's rows by `ExportRequest.filters`, or refuse a `filters`-only request
  naming what enumeration is not implemented, per FR-068. T302 made `ids` authoritative and made
  `ExportController::rowsForSelection()` read `filters` -- but only as the boolean "did the caller ask
  for something", so a `filters` object narrows nothing. A request carrying only `filters` is therefore
  refused (correct, since enumerating every sheet is not implemented), and a request carrying `ids`
  *and* `filters` silently ignores the filter half: the caller gets the ids they named, unfiltered,
  which is the same "accepted and discarded" defect T302 closed for `ids`, now reopened for `filters`.
  `ReportFilter` already models the fields involved (`from`, `to`, `statuses`, `currencyCodes`,
  `costCenterDbIds`, `actorUids`, `eventTypes`) and `ReportQuery` already owns a private filter
  implementation, so the domain rule exists and needs exposing rather than rewriting -- and the
  narrowing must be applied there, not in the controller, or there will be two definitions of what a
  filter means. (missing)

- [x] T307 **CRITICAL** — Reconcile `ReportQuery::applyFilter()`'s expected row keys with the rows the
  report and export controllers actually emit, and reopen T106 and T112 whose completion evidence this
  invalidates. The filter rule reads `currencyCode`, `actorUid`, `costCenterDbId`, `eventType` and
  `createdBy`; the sheets/transactions/accounts/process/evidence rows emit `currency` and `ownerUid`,
  and only the audit branch emits `actorUid`. So every currency, actor and cost-centre filter compares
  against a key that is never present, `in_array('', ['IRR'])` is false, and **the filter drops every
  row while returning `200`**. Measured on the live endpoint: `GET /reports/sheets` returns
  `total: 100`, the same request with `filters={"currencyCodes":["IRR"]}` returns `total: 0`, and with
  `filters={"actorUids":["employee1"]}` returns `total: 0` — on a sheet set that is entirely IRR and
  entirely owned by `employee1`. FR-064 requires filtering sheets by status, date, **currency and
  creator**; status and date work, currency and creator silently return nothing, which is worse than
  refusing them. This is a domain defect, not a controller one: the right fix is one agreed row
  vocabulary owned by `ReportQuery` and consumed by both the report and export paths, rather than
  renaming keys in each producer until they happen to agree. (contradicts)

  **Delivered.** `ReportQuery` now owns the filter-to-row-key mapping in one place
  (`FILTER_FIELD_KEYS`), and refuses a filter its rows cannot answer. Measured on the live endpoint
  after the fix: `GET /reports/sheets` `total: 100`; `filters={"currencyCodes":["IRR"]}` **99** (was
  **0**); `filters={"actorUids":["employee1"]}` **100** (was **0**); `filters={"statuses":["OPEN"]}`
  **97**; `costCenterDbIds` and `eventTypes` on sheets now answer **422** naming the field and the keys
  the rows do carry; `eventTypes` on the audit report answers **200 total: 0**, a true negative,
  because audit rows genuinely carry `eventType`.

  Several filter fields list more than one accepted key (`currencyCodes` accepts `currency` and
  `currencyCode`; `actorUids` accepts `ownerUid` and `actorUid`) because the concepts genuinely differ
  by subject -- a sheet has an *owner*, an audit event an *actor* -- rather than because one word needed
  renaming onto the other.

  **Why T106 and T112 were NOT reopened, contrary to this task's own text.** Their behaviour is now
  correct and verified, and T307 *is* the repair; reopening them would assert outstanding work that does
  not exist. What was genuinely incomplete was T106's *verification*, and that gap is now closed by six
  contract tests that run against rows a real producer emits -- the thing its 53 unit tests never did.
  They passed unchanged through this fix, which is the evidence that they could not have caught it: they
  built rows whose keys matched the filter's expectations rather than the keys the controllers emit.

  **Verified**: 24 tests / 365 assertions in the report+export contract file, five of them new and
  aimed at this defect. Proven by mutation: reverting `currencyCodes` to read only `currencyCode` fails
  `testFilteringSheetsByCurrencyReturnsTheSheets`. Full suite **628 tests, 2770 assertions, 1
  pre-existing T275 failure**.

  **Residual, stated**: `costCenterDbIds` and `createdBy` are now refused everywhere, because no report
  producer emits those columns. That is the correct answer today and a real capability gap -- FR-064's
  cost-centre filtering and the spec's creator filter have no data behind them -- and it is unowned by
  any task.

  **Delivered** by amending `specs/001-pettycash-v1-core/spec.md` in place, per the user's choice
  against creating a new `specs/002-*` feature. The contradiction was never "the spec is missing a
  feature", it was two sentences in an existing spec that the implementation could not satisfy, so a
  new feature directory would have left it exactly where it was.

  - **US7/AC4** now names the request field (`fileFormat`), states the supported values
    (`csv`, `xlsx`, `zip`; `pdf` only once a Persian-capable font exists), requires a refusal with a
    reason for a format that cannot yet be produced, and records *why* the field is not called `format`:
    the response-envelope format is selected by a request parameter of that name, so a field called
    `format` silently changes the shape of the response instead of describing the export. That is the
    OCS collision T112 hit, stated in the spec so it cannot recur unnoticed.
  - **FR-066** carried the identical drift ("in Excel, CSV, and PDF where applicable") one screen
    earlier. Left alone it would have contradicted the amended AC4, so it was corrected in the same
    voice: supported `fileFormat` values, and a refusal for one that cannot yet be produced.
  - **FR-068** gained the rule its implementation already follows, so the code is now described rather
    than merely correct: a selection the system cannot satisfy MUST be refused, MUST NOT be answered
    with an export that omits the selected records, and a filter the report cannot answer MUST be
    refused rather than reported as an empty result. Those are T302's and T307's behaviours, written
    down as requirements.

  Terminology drift closed: the spec said "Excel" where the contract's enum says `xlsx`.

  **Verified**: `spec.md` and `contracts/ocs-api.yaml` now agree on the field name (3 mentions each),
  and the contract declares no bare `format:` request field.
## Phase 45: Convergence

Normal convergence, bounded to the US7 reporting/export slice. One reopened task and one novel root
cause, grouped so the masking defect and the masked defect are both owned.

- [ ] T029 Implement consistent OCS error mapping for validation, forbidden, hidden-not-found, stale
  state, idempotency conflict (**reopened**). `OcsErrorMapper::map()` classifies
  `$error instanceof LogicException => [403, 'FORBIDDEN']`, and only the 500 branch suppresses the
  message. Any `LogicException` that is not an `InvalidArgumentException` is therefore reported to the
  caller as a *permission refusal* **and** has its internal message echoed verbatim. Verified
  consequence: `GET /reports/transactions`, `/reports/process` and `/reports/evidence` all answer `403`
  with the body `Cannot resolve the currency of transaction revision 7 from its sheet` -- a persistence
  failure presented as an authorization decision, disclosing an internal revision id. A client that
  caches the 403 stops retrying, and an operator reading it goes looking for a permissions problem that
  does not exist. This is the same over-broad classification shape as T063's bug, where
  `InvalidArgumentException extends LogicException` let a broad catch swallow validation failures.
  Reopened rather than duplicated: T029 is the task that owns OCS error mapping. (contradicts)

- [x] T308 Restore `transactions`, `process` and `evidence` reports, and settle whether the currency
  join failure is a product or a fixture defect, per FR-065 and US7/AC2 (missing). Three of the seven
  report subjects answer `403` and return no data; `sheets`, `accounts`, `cost-centers` and `audit`
  answer `200`. The throw is `DbTransactionRevisionRepository::resolveCurrencyCode()`
  (`lib/Infrastructure/Persistence/Expense/DbTransactionRevisionRepository.php:210-235`), a private
  helper that joins `pettycash_transaction_revisions -> pettycash_transactions -> pettycash_sheets ->
  pettycash_currencies` and throws a `LogicException` when the join yields no currency. The failure is
  masked as a 403 by T029, so it has never surfaced as the persistence fault it is.

  **The cause is deliberately not asserted.** Two candidates remain and they need opposite fixes:
  a *product* defect, where a dangling `sheets.currency_id` is reachable and hydration should not fail
  the whole report; or a *fixture* defect, where seeded sheets reference a currency row that does not
  exist. Reporting this as a product gap without settling that would send the fix in the wrong
  direction, so the first step is to determine which, and the fix follows from the answer.

  Not the method T230 repaired: T230 fixed `getTransactionDbId()` at lines 120-125 (a hardcoded `0`
  placeholder used as a join key). This is a different, private helper on the read path, so T230 stays
  closed. T102 correctly remains open -- it requires `process` and `evidence` coverage, and those two
  subjects cannot return rows until this is fixed.

  **Delivered (reopened by Phase 45).** Removed the `$error instanceof LogicException => [403,
  'FORBIDDEN']` arm from `OcsErrorMapper::map()`. Measured on the live endpoint, before and after, for
  the same three requests:

  | Request | Before | After |
  |---|---|---|
  | `GET /reports/transactions` | `403` + `Cannot resolve the currency of transaction revision 7 from its sheet` | `500` + `pettycash.error.unexpected` |
  | `GET /reports/process` | `403` + internal message | `500` + `pettycash.error.unexpected` |
  | `GET /reports/evidence` | `403` + internal message | `500` + `pettycash.error.unexpected` |

  The internal revision id is no longer disclosed, and a data fault is no longer dressed as a
  permission refusal. The rule now encoded: authorisation is never inferred from an exception class.
  A controller that means "forbidden" says so by calling `forbidden()`; anything unrecognised is a
  server fault -- logged in full through `TechnicalLogger::unexpected()`, reported generically, message
  withheld. `InvalidArgumentException => 422`, `Conflict => 409` and `CommandConflict => 409` are
  untouched, so client errors still carry their messages.

  Full suite after the change: **628 tests, 2770 assertions, 1 pre-existing T275 failure** -- no
  regressions, which also shows the removed arm was not load-bearing for any existing test.

  **Why there is no unit test for this class, stated rather than omitted.** `OcsErrorMapper` returns
  `DataResponse` and reads `Http::STATUS_*`, and `tests/bootstrap.php` deliberately does not bootstrap
  Nextcloud, so `OCP\AppFramework\Http` is not autoloadable in the unit process -- a test errors with
  `Class "OCP\AppFramework\Http" not found` before its first assertion. A unit test was written and
  removed rather than committed unrunnable. The verification here is therefore the measured
  before/after above, against the real HTTP surface, which is the stronger evidence anyway: it shows the
  classification a client actually receives. `RouteResolutionTest` documents the same bootstrap
  constraint for the same reason.

  **Determination complete; the task stays open because the cause is split across two owners.**

  Ran the query this task was waiting on, read-only, through `scripts/dev/in-container`:

  | Probe | Result |
  |---|---|
  | `pettycash_transactions WHERE sheet_id IS NULL` | **0 rows** -- the sheet-less-transaction hypothesis is **wrong** |
  | revisions whose `transaction_id` resolves to no transaction | **0 rows** -- not an orphan join |
  | sheets whose `currency_id` resolves to no currency | **7 rows** -- the real cause |
  | `pettycash_currencies` contents | exactly 3 rows: `1=IRR, 2=USD, 3=EUR` |

  All 7 dangling sheets are titled **"Round Trip Sheet"** and carry `currency_id` values 19-27, ids that
  do not and never existed in this instance. They were written 2026-09-27 03:38-03:41 by
  `tests/Integration/Persistence/roundtrip-runner.php`, whose header claims it "deletes every row it
  inserts, in a `finally`, in reverse insertion order". The contract fixtures are not implicated: they
  resolve `currency_id` from the seeded IRR row and set `sheet_id` on every transaction.

  **So the trigger is a test-harness defect, not a product defect** -- the runner inserted sheets
  pointing at a currency id it never created, and did not clean them up. A fix aimed at
  `resolveCurrencyCode()` would have been aimed at the wrong thing, which is why this task required the
  determination before the fix.

  **Two owners remain, and this task stays open for both:**

  1. *Harness* -- `roundtrip-runner.php` must create a currency row (or reference the seeded one) before
     inserting a sheet, and must clean up the 7 leaked sheets. Until then every run leaves more.
  2. *Product* -- one record whose currency cannot be resolved destroys **three whole reports**. A report
     must not be lost to a single unreadable row, and the honest answer is to exclude the row and say
     how many were excluded, not to return nothing. This is the same failure shape as T307 -- a wrong or
     empty answer delivered confidently -- and it is the half that would still bite in production if a
     real dangling `sheets.currency_id` ever existed.

  T029 (the masking 403) is fixed, so the remaining fault is now visible as a logged 500 rather than a
  permission refusal.

  **Harness half delivered; the product half stays open, so this task remains `[ ]`.**

  *Root cause, determined from evidence before patching, as required.* The earlier hypothesis --
  sheet-less transactions -- was **wrong**: `pettycash_transactions WHERE sheet_id IS NULL` returns 0
  rows, and so does the orphan-revision probe. The cause is in the runner's cleanup:

  1. `roundtrip-runner.php` creates one sheet (`:671`), tracks it by surrogate id (`:679`), and
     `DbExpenseSheetRepository::save()` does populate `dbId` (`:121`), so the by-id sweep should have
     caught it. It did not, because the seven survivors are timestamped 2026-09-27 03:38-03:41 --
     hours before this session, from runs that predate the fingerprint sweep the file's own header
     describes adding "precisely" after 32 rows leaked.
  2. The fingerprint sweep had **no `pettycash_sheets` predicate at all**, so a sheet leaked between its
     insert and its registration was unreachable forever: the by-id sweep needs a prior `$track`, and
     the fingerprint sweep had no entry to fall back to.
  3. The sweep *did* delete `pettycash_currencies` by name. That is what converted a harmless leak into
     seven sheets with a dangling `currency_id` -- and that dangling row is what destroyed three report
     subjects. **The sweep order created the fault it was meant to prevent.**

  *Fixes.* The fingerprint sweep now matches `['pettycash_sheets', 'title', 'eq', 'Round Trip Sheet']`,
  positioned **before** the currency predicates, because sweeping a currency first would re-create the
  dangling reference the entry exists to remove. `roundtrip-leftovers.php` gained the same predicate --
  it had the identical blind spot and would have reported a clean database while seven broken sheets sat
  in it. The seven orphans and their dependents were then removed through that predicate: 7 sheets, 4
  transactions, 8 revisions, 3 audit rows, leaving 0 dangling sheets. The audit count for the acting user
  fell 4 -> 1, which is the 3 audit rows that belonged to the deleted transactions.

  *Verified on the live endpoint.* All seven report subjects now answer `200`: `transactions` total 4,
  `process` total 6 (one row per workflow stage), `evidence` total 0, `sheets` total 100, `accounts` 2,
  `cost-centers` 9, `audit` 1. Before the sweep, `transactions`, `process` and `evidence` answered 403
  with an internal message.

  *Guards, proven by mutation.* Two tests in `RepositoryRoundTripTest` assert the sweep can reach a
  sheet and that it is ordered before the currencies, and a third asserts the leftover diagnostic can
  see one. Removing the predicate fails them. Full suite **630 tests, 2777 assertions, 1 pre-existing
  T275 failure**.

  *What is still open, and why it is not bundled here.* A single record whose currency cannot be
  resolved still destroys three whole reports. With the trigger gone that is latent rather than active,
  but a genuinely dangling `sheets.currency_id` in production would reproduce it, and the honest answer
  is to exclude the unreadable row and report how many were excluded rather than return nothing -- the
  same shape as T307. That is a change to how a report answers, not a cleanup, so it is deliberately
  left as the remaining half of this task rather than folded into a cleanup commit.

  **Product half delivered as Option B (refuse with 422), per the user's decision.**

  Added `Application/DataIntegrity/UnreadableRecordData` and had
  `DbTransactionRevisionRepository::resolveCurrencyCode()` throw it instead of a bare `LogicException`.
  It **extends `LogicException` deliberately**, so every other caller keeps exactly the classification
  it had -- with T029's `LogicException => 403` arm gone, those callers still answer 500 and still log in
  full. The type exists so a caller that *can* explain itself can recognise the fault rather than every
  caller guessing what a `LogicException` meant.

  `ReportController` catches it, logs the exception through `TechnicalLogger::unexpected()` (so the
  revision id is recorded for an operator), and answers **422** with a message that says a record is
  incomplete and that it is a data fault rather than a permission or filter problem -- and does **not**
  echo the internal message.

  **The count is deliberately not stated.** The read aborts on the first unresolvable record, so how many
  rows are affected is not knowable without a tolerant read that returns a count, which is the domain
  port change Option A required. "One or more" is the truth; a number would be a guess.

  **Verification, and its limit -- stated rather than glossed.** Full suite **630 tests, 2777 assertions,
  1 pre-existing T275 failure**: no regression, and the healthy report shape still passes
  (`testAReportAnswersTheContractsPageShape`). The 422 path itself is **not covered by a committed
  test**, because triggering it requires a record with a dangling `currency_id` -- the exact fault T308
  exists to remove. A one-off injector was attempted to verify it end to end and abandoned: it writes
  invalid data into the shared MariaDB that the live contract suite also uses, and a probe whose
  failure mode is "leaves a landmine that breaks three reports" is the same trade that produced the
  seven sheets in the first place. The probe's inserts all failed before writing and the database was
  re-verified clean afterwards: 0 dangling sheets, 0 probe rows.

  So the 422 behaviour rests on the code path and the healthy-path regression check, not on a
  behavioural test. If that is not acceptable, the honest route is to make the runner's cleanup
  provably safe first and then add a scoped corrupt-row fixture -- which is Option A's verification
  problem, and worth doing before the next data-integrity fault is diagnosed from scratch.
## Phase 46: Convergence

Normal convergence, bounded to the US7 reporting slice. Three findings: one silent-wrong-answer defect
in code this slice added recently, one unimplementable requirement, one unowned verification gap.

- [x] T309 Give the report filter surface a closed vocabulary and refuse any filter it does not
  recognise, per FR-064 and the FR-068 rule that a filter which cannot be answered must be refused
  (contradicts). `ReportController::filterFrom()` decodes the `filters` JSON and reads a fixed set of
  keys, **discarding every other key without comment**. Measured on the live endpoint:
  `GET /reports/transactions` returns `total: 4` unfiltered, and returns the *same* `total: 4` for
  `filters={"stages":["FINANCE"]}`, for `filters={"banana":true}`, and — worst — for the typo
  `filters={"costcentreIds":[1]}`. A caller who misspells a filter receives the entire report instead
  of the subset they asked for, and nothing in the response says so. For a financial report that is a
  silently wrong answer of exactly the kind T307 was raised for, and it is a regression in code added
  to this slice after that lesson. The fix is the same shape as T307: an unrecognised key is a client
  error and is refused with 422 naming the accepted filters, not absorbed. (contradicts)

- [ ] T310 Add the report filter facets and row columns FR-064 names but nothing models, per FR-064
  (missing). Of the twelve facets FR-064 requires, five cannot be expressed at all.
  `ReportFilter` carries only `from`, `to`, `statuses`, `currencyCodes`, `costCenterDbIds`,
  `actorUids`, `eventTypes` and `createdBy`, and the report rows carry none of the columns some of those
  need:

  | FR-064 facet | State | Why |
  |---|---|---|
  | sheets by creator | **refused** | filter field is `createdBy`; sheet rows carry `ownerUid` |
  | transactions by cost centre | **refused** | filter field is `costCenterDbIds`; transaction rows carry `sheetId` only |
  | transactions by expense type | **unmodelled** | no `ReportFilter` field and no row column |
  | transactions by amount | **unmodelled** | no `ReportFilter` field and no row column |
  | audit by stage | **silently ignored** | audit rows carry `stage`, but no filter field exists, so a `stages` key is dropped per T309 |

  Working today: sheets by status/date/currency, transactions by status/date, audit by actor/event
  type/date range. The three refused facets are refused rather than silently wrong because T307's
  `assertFilterIsEvaluable()` caught them, which is the mechanism working as intended. This task is the
  capability gap behind those refusals, and it needs a decision on where the columns come from -- a
  sheet's cost centre is reachable through its transactions, so this may be a join rather than a schema
  change. (missing)

- [ ] T311 Add a corrupt-record fixture that can verify data-integrity handling without risking the
  shared database, per Constitution II (missing). T308 delivered the reporting half of the data-fault
  path -- a record with an unresolvable currency is refused with 422 rather than losing three reports --
  but the 422 path has **no committed test**, because triggering it requires deliberately inserting a
  record with a dangling `currency_id`, which is the exact fault T308 exists to remove. A one-off
  injector was written and abandoned: it writes invalid data into the MariaDB the live contract suite
  also uses, and a probe whose failure mode is "leaves a landmine that breaks three reports" is the
  trade that produced the seven leaked sheets in the first place. Building it safely means the runner's
  cleanup is provably complete *first* -- every inserted row registered before the next insert, and a
  post-run assertion that the fingerprint count is zero -- and only then a fixture that asserts the 422
  and its refusal to leak the internal message. (missing)

  **T309 delivered.** `ReportController` now holds a closed `ACCEPTED_FILTERS` vocabulary and refuses
  anything outside it with a 422 naming what is accepted, per the FR-064 clause added in the
  2026-09-28 clarification session. Measured on the live endpoint, before and after, for the same four
  requests:

  | `filters` sent to `GET /reports/transactions` | Before | After |
  |---|---|---|
  | none | `200`, `total: 4` | `200`, `total: 4` |
  | `{"stages":["FINANCE"]}` | `200`, `total: 4` | **`422`**, names the accepted filters |
  | `{"costcentreIds":[1]}` (typo) | `200`, `total: 4` | **`422`** |
  | `{"banana":true}` | `200`, `total: 4` | **`422`** |

  A JSON array is also refused rather than read as an empty filter object, which would have been the same
  silent-unfiltered answer by a different route. Five tests added, including one asserting every
  *accepted* filter still narrows, so closing the vocabulary did not break the filters that work.

  **Mutation-proven**: removing the check fails all three refusal tests. Full suite **635 tests, 2806
  assertions, 1 pre-existing T275 failure**.

  This and `ReportQuery::assertFilterIsEvaluable()` now close both directions of the same hazard: a
  filter the rows *cannot answer* is refused, and a filter the endpoint *cannot read* is refused before
  it can be mistaken for no filter at all.

  **T112 reopened.** The 2026-09-28 clarification answered that the `accounts` report must show a
  finance officer *every account they have charged*, not only their own (FR-065, FR-055, FR-015). The
  report as built returns `scope: own_accounts` with `chargedAccountsIncluded: false`, so a finance
  officer cannot see the balances of the accounts FR-015 requires them to charge. That is a defect in
  code this task delivered, found by a specification decision rather than by a test, and the task stays
  open until it is repaired.

  **It cannot be repaired yet**, and reopening it as "do it now" would be dishonest: the charged-account
  set derives from configuration audit events that T294 must write, and T294 is gated on T253. The
  ordering is therefore T253 -> T294 -> the accounts scope -> T299's own verification, and T112's
  report/export work is otherwise complete and verified. Recorded here so the state is not lost, rather
  than left as a checked task that reads as done.

  **Closed by reconciliation, not by new code.** The 2026-09-28 clarification settled the decision this
  task was blocked on -- keep the migration's `entity_type`/`entity_id` and correct `data-model.md`,
  because the domain `AuditEvent` entity already uses those names and the document was the outlier.
  `data-model.md` was corrected in that session; this pass verified the whole chain agrees:

  | Layer | State |
  |---|---|
  | `data-model.md` (Configuration Audit Event) | `entity_type`, `entity_id` |
  | `Version010200Date20260920000000.php:45` | creates `entity_type`(64), `entity_id`(64) |
  | `AuditWriterService.php:37-38` | writes `entity_type`, `entity_id` |
  | any remaining `subject_*` disagreement | **none** |

  Nothing had to be migrated: the configuration audit table has never been written, so the naming choice
  cost no data. Verified rather than assumed -- four checks, all green -- and no application code was
  touched, which is why this is a checkbox change and not an implementation.

  This was the gate on T294, so T294 is now unblocked.