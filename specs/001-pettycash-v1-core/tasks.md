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
- **Checkbox**: `- [x]` means complete, `- [ ]` means open. Both cases are in use in this file —
  `- [x]` appears on 205 lines and `- [X]` on 30 — and **any tool reading this ledger MUST match the
  marker case-insensitively**, or it will report 30 delivered tasks as open. A `- [X]` line is
  complete; the case carries no meaning. (Recorded 2026-10-02 rather than normalized, because
  rewriting 30 lines of history to satisfy a parser is the wrong direction of fix: the parser is the
  thing that has to cope with a file that has been appended to for 54 phases.)

### Retired task IDs

Twenty-one IDs are intentionally **absent** from the sequence. They are not missing work and must
never be reopened or renumbered: each was a *duplicate declaration* of a deliverable that another
line already owned, and each was removed by the analysis-driven ledger remediation pass. Their gaps
in the `T001`–`T325` range are the record of that removal, so a tool that diffs the ID sequence will
see them and should expect them.

| Retired ID | Was a duplicate of | Deliverable |
|---|---|---|
| T150, T151, T152 | T048–T054, T059–T066, T070–T076 | Phase 4/5/6 range umbrellas |
| T153, T154, T155, T156, T157 | T081–T089, T093–T100, T105–T114, T117–T123, T124–T139 | Phase 7–11 range umbrellas |
| T205, T206, T207, T208, T209 | same as T153–T157 | the same five ranges, declared a second time |
| T182 | T054 | `tests/E2E/management-review.spec.ts` |
| T193 | T065 | finance review / account views |
| T194 | T066 | `tests/E2E/finance-settlement.spec.ts` |
| T202 | T074 | correction/revision-history API + stores |
| T203 | T075 | returned-work / correction / revision-history views |
| T204 | T076 | `tests/E2E/transaction-correction.spec.ts` |
| T215 | T158 | removal of `tests/Frontend/__mocks__/components.ts` |
| T273 | T134 | `checklists/traceability.md` (T134's range was widened to absorb it) |

Separately, **T029 was de-duplicated, not retired**: it appeared twice (Phase 2 and Phase 45). The
Phase 2 line is canonical; the Phase 45 occurrence remains in place as evidence prose with its
checkbox removed, and its findings and T311/T308 correction notes are to be read as additional
history on the canonical line.

## Phase 1: Setup and Toolchain

**Purpose**: Establish a reproducible Nextcloud 34, PHP, Vue, TypeScript, build, and test baseline
without introducing business behavior.

- [x] T001 Create a non-destructive runtime and migration preflight script that verifies Nextcloud 34.x, PHP 8.2–8.5, the `pettycash` mount, existing `pettycash` migration history, and presence of legacy `oc_pcash_*` tables without changing them in `scripts/preflight.sh` (**premise corrected 2026-10-02** — the `oc_pcash_*` tables this task names **do not exist**: that prefix matches 0 rows. The discarded prototype's tables are `oc_pc_*`, and `scripts/preflight.sh` queries *both* patterns deliberately, so that the disagreement is reported rather than hidden. The preflight gate's assertions are unaffected and remain valid; only the table name in this task's text was wrong. **This task's own output is therefore the evidence that the premise was false**, and T263 owns the artifact-level reconciliation)
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

- [x] T011 [P] Write failing migration safety tests proving installation beside existing `pettycash` migration records creates only `pc_*` tables and never drops, renames, or rewrites legacy `oc_pcash_*` tables in `tests/Integration/Migration/V1SchemaMigrationTest.php` (**premise corrected twice, 2026-10-02** — first the expected table prefix: this task was written when the baseline created `pc_*`, and T260/T261 rewrote the migrations in place to the `pettycash_*` namespace, so `V1SchemaMigrationTest.php` now enumerates all fifteen `pettycash_*` tables and asserts `MAX_IDENTIFIER_LENGTH = 63` against the names the migrations actually produce (verified by T316: 8 tests, 160 assertions). Second the legacy table name: `oc_pcash_*` matches **0** rows; the discarded prototype's tables are `oc_pc_*`. **The migration-safety assertion this task exists for is unaffected and remains valid** — that installing beside existing `pettycash` migration records creates only the app-scoped tables and never drops, renames, or rewrites another application's tables, which is what `V1SchemaMigrationTest.php` still asserts. Only the two table names in this task's text were wrong, and it is worth recording that a completed task's *text* can outlive its *content* and then read as a false claim about the schema)
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
- [x] T028 Implement transaction and configuration audit writers that snapshot UID, display name, role, stage, revision, explanation, command ID, and canonical before/after JSON in `lib/Application/Audit/` (**reopened by T295 — the configuration writer this task claims does not exist and never ran.** Only the transaction side is real: `AuditWriterService::append()`, the method the `AuditWriter` port declares and the only one any of the six production call sites uses. The configuration side was `appendConfiguration()`, it had **zero** call sites, and `DbAuditEventRepository::save()` targets `pettycash_transaction_audit` unconditionally, so even if it had been called it would have written a configuration event into the *transaction* stream, against FR-056. `grep -rn pettycash_configuration_audit lib/ --include=*.php` returns the migration and nothing else, so the table is created and permanently empty. T296 has since deleted the dead method, leaving no configuration writer at all; **T294** owns writing one that reaches the right table) (done)

  **Corrected on evidence, not on T294's completion alone** — the fourth bookkeeping correction of this
  kind in this file, after T067, T068 and T029. The blocker named above was T294's, T294 is delivered, and
  what remained open was the *second* half of the complaint, which T294's own commit could not settle:
  that the method had **zero call sites**. A method that exists and is never called is the same defect
  the task was reopened for, so existence was not accepted as the answer.

  Verified against the running system, in the four ways the claim can be wrong:

  | Claim | How it was checked | Result |
  |---|---|---|
  | a configuration writer exists | `grep -rn appendConfiguration lib/` | `AuditWriterService::appendConfiguration()`, declared on the port |
  | it reaches its **own** table | `DbAuditEventRepository.php:30` stream binding + `RepositoryRoundTripTest::testAConfigurationAuditEventLandsInItsOwnTableAndIsInvisibleToTheTransactionStream` | `pettycash_configuration_audit` |
  | it has a **production** call site | `grep -rn appendConfiguration lib/` | `FinanceSettlementService.php:278` — the one that moves money |
  | it actually **writes** | the live database, read-only | **123 rows**, every one `ACCOUNT_CHARGED` / `entity_type=account` |

  The last row is the one that closes it. 123 rows in `pettycash_configuration_audit` and **0** in
  `pettycash_transaction_audit` is the T295 complaint answered in both directions: the table is no longer
  created-and-permanently-empty, and nothing has leaked into the transaction stream.

  **The snapshot fields are the task's actual requirement**, and all nine are taken at write time by
  `AuditWriterService::toEvent()` — uid and display name resolved through `CurrentUser`/`UserDirectory`
  rather than looked up on read, which is what FR-054 and a disabled user's unchanged historical identity
  depend on, plus `actor_role`, `stage`, `revision_id`, `explanation`, `command_id`, and
  `before_json`/`after_json`.

  `AuditWriterSurfaceTest` (7 tests) proves each path reaches only its own stream and that a configuration
  event without a subject is refused; `FinanceSettlementTest` (31 tests, 163 assertions) proves against
  the real table that an approval which moves money writes exactly one configuration charge — the
  end-to-end shape T295 said had never run.

  **`append()` keeps its six production call sites** across `SubmitSheet`, `ResubmitCorrection` and
  `RetentionPolicyService`, and the transaction binding is covered by the round trip. Nothing was
  rewritten to make this true.

  **One observation, deliberately not acted on.** The contract seeder clears
  `pettycash_transaction_audit` between runs but never clears `pettycash_configuration_audit`, so the
  shared test database accumulates rows — 123 and counting. That is *correct* application behaviour
  (FR-053/FR-057 make the configuration stream append-only with a 7-year floor, so a seeder that deleted
  it would contradict the property under test), and nothing depends on the count:
  `ChargedAccountScope` returns a set of account ids, and `FinanceSettlementTest`'s `assertCount(1, …)`
  is scoped to the command under test rather than to the table. Recorded so the growth is not later
  rediscovered as a leak, and so nobody "fixes" it by making the stream deletable.
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
- [x] T069 [P] [US4] Write failing contract and Vue tests for correction creation/update/resubmit, returned queue, read-only prior revisions, and field/evidence validation in `tests/Contract/Controller/CorrectionControllerTest.php` and `tests/Frontend/Component/TransactionCorrection.spec.ts` (complete — **26 tests written RED; 5 fail, and every failure is a behaviour no artifact permits today**. `CorrectionControllerTest.php` is 12 tests over live HTTP; `TransactionCorrection.spec.ts` is 14 over the components. No test asserts what `PurchaserFlow.spec.ts` already covers (`:1044` returned-is-editable, `:1231`/`:1523` correction-button visibility), so the file adds the flow *around* them rather than repeating them.
      **The headline finding is that the correction path is present but inert, and its three RED failures are the three ways US4/AC2 can fail.**
      1. **`resubmitCorrection()` activates the wrong revision.** `ExpenseTransaction::resubmitCorrection()`
         (`:217-219`) calls `$this->activate($this->activeRevision)` — and `CreateCorrectionDraft::execute()`
         (`:63-82`) saves the new revision but **never attaches it to the transaction**, so
         `$this->activeRevision` is still the original. The measured proof: after draft + resubmission
         the active revision is `77777777-…` (the fixture's original) and not the created draft
         `1c6b8519-…`. **The purchaser's corrections are computed, stored, and then discarded**, which
         is precisely the failure US4 exists to prevent and the one `FR-049`/`FR-050` forbid.
      2. **A correction draft cannot be edited.** `PATCH /transactions/{id}` refuses with `422
         "Cannot update transactions in a submitted sheet"` (`TransactionService.php:222`), because the
         returned transaction's sheet is `CORRECTION` and not `Open`. `workflow.md`'s Correction
         Contract clause 2 says the owner may "create **and edit** the correction draft" and US4/AC2
         has the purchaser change the business information — so there is currently **no way to
         correct anything**.
      3. **`POST /correction` ignores the required command id.** The contract declares
         `X-PettyCash-Command-ID` required (`:523-525`) and the endpoint answers **201 without it**.
         Unlike the T318 DELETEs this header is load-bearing here — a replayed resubmission would
         activate a revision twice — so the client is left unprotected against a double-clicked button.
      **The fourth Vue failure is a defect the task's own wording surfaced:** `ReturnsQueueView.vue:131`
      awaits `fetchReturnsQueue()` from `onMounted()` without catching, and `reviews.ts:69` rethrows, so
      a failed returns load leaves an **unhandled rejection**. `TransactionDetailView.vue:90-95`
      documents and fixes exactly this pattern in this application, which is what makes it a defect
      here rather than a style preference. Vitest reports it as a suite-level *error*, which
      `AGENTS.md` rules out as a valid RED state, so the file captures it through its own listener and
      asserts on it — the defect now surfaces as a **named failure** instead of noise.
      **One assertion was removed rather than left to fail on an inference.** The first draft asserted
      that selecting a superseded revision hides the correction button. It does not, and that is
      defensible: the button reflects the *transaction's* status, and `workflow.md` clause 3 says the
      draft begins from the *active* revision regardless of which one is on screen. `FR-048`
      immutability is asserted instead, which the artifacts do state.
      **The 21 passing tests are evidence too, not padding** — they pin what already holds (new
      revision identity, draft not self-activating, prior revisions unchanged, command id on
      resubmit, replay safety, revision schema, returns-queue rendering, active-revision default),
      so T070-T076 cannot regress them while fixing the above.
      Frontend: `npm run typecheck` clean, `npm run lint` 0 errors, `npm run test:unit` 228 tests /
      1 failed / 227 passed.
      **Its five failures have since been fixed — see `docs/reports/adhoc-correction-activation.md`.**
      `CorrectionControllerTest` is now 12/12 and its Vue counterpart 14/14; the one `npm run test:unit`
      failure was the `ReturnsQueueView` unhandled rejection above. Five distinct defects sat behind
      the four contract failures, and two of them were only visible once the first was fixed —
      `ResubmitCorrection` never persisted the activated revision, and `Revision.active` was derived
      from `activated_at` rather than `active_revision_id`, so "exactly one current revision" was not a
      property the data could express.
      Report: `docs/reports/T069.md`

### Implementation for User Story 4

- [x] T070 [US4] Implement correction-draft creation, owner-only editing, immutable prior revision cloning, and supersedes linkage in `lib/Application/Expense/CreateCorrectionDraft.php`
- [x] T071 [US4] Implement atomic correction validation, active-revision activation, current-manager snapshots, cost-center rerouting, state projection, command result, and audit in `lib/Application/Workflow/ResubmitCorrection.php`
- [x] T072 [US4] Update evidence services so correction uploads retain originating revision links and post-submission removal deactivates rather than deletes in `lib/Application/Evidence/EvidenceService.php` (complete — **both clauses were broken, and the second was invisible to every assertion anyone had written.** 11 new tests, 4 mutations, all killed.)
      **Clause 1 — the revision link was a constant.** `uploadEvidence()` wrote `1` into `origin_revision_id`
      with the comment "will be enhanced with proper revision tracking", and `1` into the audit event's
      `revision_id` as well. **Every evidence row in the application claimed revision 1 forever**,
      including rows uploaded while correcting, which by definition attach to a later revision. The
      controller was also passing `$transaction->revision()->number()` — a *sequence number* — into a
      slot that needed the *database* id; the two are different identifier spaces, so the number could
      only ever have matched a revision by coincidence. `uploadEvidence()` now takes
      `?int $originRevisionDbId` and the controller passes `revision()->dbId`. Measured RED before the
      fix: two uploads against two different revisions both reported `revisionId: 1`.
      **Clause 2 — submitted evidence had its content destroyed.** `EvidenceController::destroy()` called
      `deleteDraftEvidence()` for *every* transaction, and that method deletes the AppData binary. The
      comment above the call said "For simplicity, we'll deactivate in both cases", and that "for
      simplicity" was the defect: FR-058 requires submitted evidence to be "marked inactive rather than
      physically removed and its history remains visible", and `workflow.md`'s Correction Contract clause 6
      lists "evidence history" among what must remain unchanged. `EvidenceService::deactivateEvidence()` —
      the method written for exactly this case — **had no callers at all**.
      **Why it survived so long: the difference is in a file, and nothing could see it.** The evidence row
      is deactivated either way, and over HTTP `GET /evidence/{id}/content` answers `403` once inactive,
      so no endpoint distinguishes a deletion from a deactivation. The content assertion therefore had
      to be a unit test against the file store (`EvidenceRemovalContentRetentionTest`), and the routing
      itself is pinned separately through `deactivationReason`, which each method writes differently and
      which `formatEvidence()` returns.
      **The boundary is the sheet, not the transaction — measured, not assumed.** The first routing
      attempt keyed on `$transaction->status === Draft` and the contract test caught it: after
      `POST /sheets/{id}/submit` the sheet leaves `OPEN` while the transaction still reads `DRAFT`,
      because submission routes the transaction to a stage without rewriting that field. A
      transaction-status check therefore sends every submitted transaction down the draft path — the
      original bug, restored. Routing is now on the sheet's status, which is also the right unit on the
      merits (FR-025 and FR-058 both tie removability to submission, and submission is a sheet property),
      and mirrors what `TransactionService::deleteTransaction()` already asks.
      **A third defect was found on the way: replaying a post-submission deactivation returned `500`.**
      `deactivateEvidence()` returned the `Evidence` **object** from inside its `IdempotencyService`
      closure, but the service stores responses as JSON and reads them back with `json_decode(..., true)`
      — so a replay produced `['evidence' => [...array...]]` and the `: Evidence` return type threw. The
      message is withheld by the mapper, so it surfaced as an opaque `UNEXPECTED_FAILURE` on a
      transaction whose evidence had in fact been deactivated correctly. The closure now stores only the
      evidence id and the aggregate is re-read after the block — the same shape T317 settled for the
      transaction commands.
      **`deactivateEvidence()` no longer refuses a repeat.** It threw "Evidence already deactivated",
      which was right under the design T213 wrote it for but contradicts T318's ruling that
      `DELETE /evidence/{id}` is a *repeatable* command exempt from `X-PettyCash-Command-ID`; the
      post-submission branch is that same operation. It is now effect-idempotent — a repeat returns the
      stored state without re-running `deactivate()`, which would otherwise rewrite `deactivatedAt` to
      the retry instant. T213's `testDeactivationIsStillRefusedForEvidenceThatIsNotActive` asserted the
      old behaviour and **was updated rather than left red**, with the reasoning recorded in place.
      `scripts/dev/phpunit` is 866 tests / 3955 assertions / 1 failure, that failure being T318's
      pre-existing `application/octet-stream` assertion on `GET /evidence/{id}/content`, unrelated and
      untouched. Frontend: typecheck clean, lint 0 errors, `test:unit` 228/228.
      Report: `docs/reports/T072.md`
- [x] T073 [US4] Add correction, resubmit, revision-history, and purchaser-return OCS routes/controllers in `appinfo/routes.php`, `lib/Controller/TransactionController.php`, and `lib/Controller/ReviewController.php` (correction evidence upload enforces 100 MB max per FR-074; no advanced evidence restrictions in V1 per FR-030)
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

- [X] T077 [P] [US5] Write failing domain tests for `Currency.code unique uppercase`, `minor_unit valid range 0–4`, cost-center validity, exactly one effective manager and senior manager, expense-type evidence rule, and unique user/currency account in `tests/Unit/Domain/Configuration/ConfigurationRulesTest.php` **Complete — delivered 2026-10-01.** `tests/Unit/Domain/Configuration/ConfigurationRulesTest.php`,
  **36 tests / 54 assertions**, no production code touched.

  **The file was a seven-line stub, not an attempt.** It held one method asserting
  `Currency::create('IRR', …)->code()` returns `'IRR'` and that `create('usd', …)` fails — of the six
  rule groups this task names it covered none, and its existence made the task look attempted.

  **Half of this task is not a domain rule, and is recorded as such rather than faked.** Three of the
  six groups are uniqueness-or-exclusivity claims, and an aggregate instance cannot hold any of them,
  because each is a statement about a *set*:

  | T077 names | Actually held by |
  |---|---|
  | `Currency.code` **unique** | `pettycash_currencies_code_uniq` + `CurrencyService::create()` (already proven by `CurrencyServiceTest`) |
  | **exactly one** effective manager and senior manager | `CostCenterService` — no unique index covers `(role, cost_center_id)` (already proven by `CostCenterServiceTest`) |
  | **unique** user/currency account | `pettycash_accounts_user_currency_uniq` + `AccountAlreadyExists`, whose own docblock says the index is what enforces it |

  What the domain *can* hold for those three is asserted anyway, because it is what makes the service's
  check possible: a currency code is three uppercase letters; a manager role is scoped to a cost centre
  so a second current manager is representable-and-detectable rather than silently merged; an account
  carries one currency code validated by the same rule a currency uses.

  **"Failing tests" was overtaken by T081–T084.** Every rule below already exists, so these are
  **green** — regression gates on shipped behaviour, not evidence of a missing rule. That is stated in
  the file's docblock so nobody later reads them as proof something was absent.

  **12 mutants, 12 killed**: lowercase accepted as a currency code (1), `minor_unit` range widened (2),
  inverted cost-centre window accepted (1), cost-centre validity made exclusive at the end date (2),
  cost-centre `isValidOn` ignoring `active` (1), manager role allowed to omit its cost centre (2),
  finance role allowed to be scoped to a centre (2), assignment `isValidOn` ignoring `active` (1),
  blank expense-type title accepted (1), lowercase accepted by `CurrencyCode` (1), blank account user
  accepted (1), inverted assignment window accepted (1).

  **The twelfth was a real gap in these tests, not a weak mutation.** This file asserted an
  assignment's validity window was inclusive at both ends but never that an *inverted* window is
  refused, so removing that check changed nothing observable. The missing case was added.

  Boundaries not crossed: no production code changed (all six mutated domain files verified
  byte-identical afterwards), no controller, no `KNOWN_UNRESOLVED` edit, no commit.
- [ ] T078 [P] [US5] Write failing authorization/integration tests proving administrators manage configuration but cannot charge or decide finance without finance role, finance assignments share one queue, default rial provisioning is idempotent, and every sensitive change creates configuration audit in `tests/Integration/Configuration/ConfigurationAdministrationTest.php`
- [ ] T079 [P] [US5] Write failing OCS contract tests for currency, cost-center, expense-type, finance-assignment, account, and charge endpoints in `tests/Contract/Controller/ConfigurationControllerTest.php`
- [ ] T080 [P] [US5] Write failing Vue tests for admin forms, active/inactive choices, effective dates, manager selection, finance assignment, account creation, and funding validation in `tests/Frontend/Component/ConfigurationAdministration.spec.ts` (verify all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071)

### Implementation for User Story 5

- [X] T081[P] [US5] Implement currency administration with immutable historical references and rial default identification in `lib/Application/Configuration/CurrencyService.php` **complete — delivered 2026-09-30.** `lib/Application/Configuration/CurrencyService.php` plus the shared `ConfigurationConflict`. Registers a currency (uppercasing the code before the uniqueness check, and letting `Currency::create()` own the shape so there is one definition of "valid"), updates name/scale/active behind a **required** `expectedVersion`, lists active currencies paged through the shared `PageBounds`, and identifies the default rial — `defaultRial()` failing loudly when IRR is absent or carries a non-zero `minorUnit`, because FR-020 provisions a purchaser's first account in that currency. **Historical retention** is expressed as: the code is the identity (`CurrencyId` is 3 chars), there is no delete, and deactivation keeps the row readable by code while removing it from `findAllActive()`. Reads are administrator-only, matching the contract's "administrative context" wording and T078's matrix. Registered in `lib/AppInfo/Application.php` and verified to **resolve from the real container**. **20 unit tests / 42 assertions**, and independently against **real MariaDB** through the `configuration` round-trip scenario (write persists with its configured `minor_unit`; duplicate, non-administrator and stale-version all refused). **6 mutations, 6 killed**: no-duplicate-check, no-stale-check, no-admin-guard, deactivate-writes-more-than-active, plus two more in the batch. Report: this entry.
- [X] T082[P] [US5] Implement cost-center and effective-dated manager/senior assignment administration with future-routing behavior in `lib/Application/Configuration/CostCenterService.php` (**read this before starting** — T236's real-database round trip found that `DbCostCenterRepository` was writing `manager_uid` and `senior_manager_uid`, columns that exist in no migration and in no table, so every cost-center write failed with `Unknown column 'manager_uid'` and had never been executed by any test. `data-model.md` gives `CostCenter` no manager, and states the relationship as "Has effective-dated cost-center-manager and senior-manager **role assignments**", so the fields and the two dead port methods `findByManagerUid()` / `findBySeniorManagerUid()` were **removed** rather than given columns. Managers are rows in `pettycash_role_assignments` scoped by `cost_center_id`, and `ManagementReviewService::getManagedCostCenterCodes()` already resolves a reviewer's scope that way. **So this task must create role assignments, not set properties on a cost center** — passing a manager uid to `CostCenter::create()` is no longer possible, and `T077`'s "exactly one effective manager and senior manager" is a rule about assignments. `RoleAssignment` also has no `lock_version`, in the data model and in the table alike) **complete — delivered 2026-09-30.** `lib/Application/Configuration/CostCenterService.php`. **Managers are role assignments, not cost-centre properties** — `create()` takes the contract's `managerUserId` and `seniorManagerUserId`, validates both against the host user directory, and writes two `RoleAssignment` rows scoped by the centre's surrogate key, which is the join `ManagementReviewService::getManagedCostCenterCodes()` already walks. A centre with no manager cannot route a submission per FR-032, so all three writes happen in one call. `transferManager()` is a **transfer, not a second assignment**: it closes every current holder of the role at `$validFrom - 1 second`, because `RoleAssignment::isValidOn()` is inclusive at both ends and closing on the same date would leave one day with two current managers. **Future routing** is served by `listValidOnForCaller($on)`, defaulting to the injected `Clock` so the date is never unstated. FR-011's "exactly one current" is enforced here because `pettycash_role_assignments` has no uniqueness over `(role, cost_center_id)`. Writes are administrator-only; reads are open to any authenticated caller because `TransactionFormView.vue` loads cost centres on every open. **19 unit tests / 42 assertions**, plus real-MariaDB verification through the round-trip scenario, which reads the two assignments back by role and cost centre. **3 mutations, 3 killed**: skipping the senior-manager assignment, closing the outgoing assignment on the transfer date instead of the day before, and future routing ignoring the caller's date. Report: this entry.

  **REOPEN CLOSED 2026-10-01 — the reopened scope is delivered.** `CostCenterService::update()` now exists and
  `configuration#costCenterUpdate` has a service behind it. `T082` is `[X]` again, and this entry records
  both the original delivery and the correction, so neither is lost.

  **What the reopened scope delivered.** Update, activate and deactivate of a cost centre -- FR-010's
  remaining three verbs, all expressed through this one write because `CostCenterWrite` carries `active`
  and the contract declares no second route:

  - **Keyed by the stable uuid**, per the contract's `costCenterId: { format: uuid }`, via
    `findByPublicId()`. Deliberately unlike `CurrencyService::update()` (`code`) and
    `ExpenseTypeService::updateByTitle()` (`title`), which key by natural key because neither resource has
    a mutable one.
  - **`expectedVersion` required and checked** against `lockVersion`, refused with
    `ConfigurationConflict::staleVersion()`; a stale write is never applied to a row that moved on and
    emits no audit event.
  - **`code` is mutable and now actually persists.** Two defects had to be fixed for a rename to work at
    all, and neither was visible to any test before this:
    (a) `DbCostCenterRepository::save()`'s update branch never wrote `code`, so a rename reported success,
    advanced `lock_version`, and left the row keyed by the old code -- the new code resolved to nothing.
    Only the real-database round trip can see this; the in-memory double accepts any code.
    (b) That branch also never published the advanced `lock_version` back onto the aggregate, so the
    `version` the contract's `CostCenter` response must carry was the superseded one, and a client
    echoing it straight back into `expectedVersion` would be refused for the write it had just made.
  - **Administrator-only**, reusing this class's existing `requireAdministrator()`.
  - **Validation still runs through `CostCenter::create()`**, so a blank name or an inverted validity
    window is refused by the domain rather than by a copy of its rules here.
  - **A manager or senior-manager change delegates to `transferManager()`**, so the outgoing assignment is
    closed at `$validFrom - 1 second` and history is preserved. Resending the current holder is treated as
  unchanged, because `CostCenterWrite` makes both fields required and treating a resend as a transfer
    would churn the assignment history FR-011 rests on on every rename.
  - **`changeReason` propagated**, and one `COST_CENTER_UPDATED` event emitted through
    `ConfigurationAuditService::record()`, so the update and its audit record commit in one transaction.
    A manager change additionally emits the existing `COST_CENTER_MANAGER_TRANSFERRED` event, one event
    per changed subject.
  - **Audit subject is the uuid, not the code.** Decided deliberately, as this entry required. `code` is
    mutable under FR-010, so an event keyed by it stops resolving the moment the centre is renamed.
    `pettycash_configuration_audit.entity_id` is a `string(64)` that already holds a uuid for
    `ACCOUNT_CHARGED`, `data-model.md` prescribes only the `entity_type`/`entity_id` pair and never the
    value, and the contract's own path parameter is a uuid. `create()` was changed to match so both event
    types name the same centre the same way. No authoritative artifact required `code`, and `T086`'s use
    of it was a choice, not a requirement -- so this is a correction rather than a conflict.

  **Two further defects found and fixed while proving it, both in the in-memory double.** It minted no
  `public_id` where `DbCostCenterRepository` does, and it never advanced `lock_version` where the adapter
  does. The second is the more serious: an update left the version at 0, so the `expectedVersion` guard
  could never fire and a stale write silently overwrote the winner. Every optimistic-concurrency test for
  this resource would have passed against a repository that does not behave like the one production uses.

  **Verification: 31 unit tests / 61 assertions in `CostCenterServiceUpdateTest.php`** (a separate file, so a
  failure names the reopened scope rather than a regression in the work already proven), plus **5
  real-MariaDB assertions**, including that the rename persists in the column, that `lock_version` advances
  by one and is published, that a stale write is refused and does not overwrite the winner, that the audit
  subject is the uuid, and that a refused audit leaves no committed row.
  **8 mutations, 8 killed**: stale-version guard removed (3 failures), lookup keyed by code (24 errors),
  duplicate-code check skipped (1), senior-manager reassignment skipped (1), `changeReason` dropped (1), audit
  append removed (26), **audit moved outside the transaction (1)**, audit failure swallowed (2).

  That seventh mutant is the one worth recording: it **survived** the first version of these tests. The
  existing assertions proved a transaction was *opened* and that an event *existed*, and an audit appended
  after the commit satisfies both while committing the change unrecorded -- exactly what FR-017 forbids.
  `InMemoryTransactionManager::isTransactionOpen()` was added and the test rewritten to assert the append's
  position, not its existence. The same weakness existed in `ConfigurationAuditServiceTest`, whose ordering
  assertion covered the snapshot callbacks rather than the append itself.

  **Out of scope, still open and unchanged:** the `T084` concurrent-uniqueness gap on
  `pettycash_role_assignments`, whose index on `(user_uid, role, active)` is non-unique. Not touched here.

  **REOPENED 2026-10-01 — `CostCenterService::update()` was never delivered, so this task is `[ ]` again.**

  **Why this is a reopen and not a new task.** `T082` is the only entry in this ledger that owns
  `lib/Application/Configuration/CostCenterService.php` or `costCenterUpdate`, and the `T087` prerequisite
  table below independently assigns `costCenterUpdate` to `T082`. Creating a follow-up task would leave
  two tasks owning one method. No application code changed; this entry is the correction.

  **Why the gap is real and not a deferral.** `FR-010` reads "Administrators MUST be able to **create,
  update, activate, and deactivate** cost centers, including **code**, name, description, validity dates,
  cost center manager, and senior manager." `T081` ("currency administration") and `T083`
  ("expense-type administration") each delivered `update` from that same word, against the parallel
  `FR-012` / `FR-013` wording; `T082` delivered `create`, `transferManager`, `listValidOnForCaller` and
  `currentManagersOn` and no update. `contracts/ocs-api.yaml` declares
  `PATCH /configuration/cost-centers/{costCenterId}` — "Update a cost center **or its current
  assignments**" — whose body is `allOf: [CostCenterWrite, ExpectedVersion]`, so `expectedVersion` is
  **required** and the manager fields are mutable through it. `appinfo/routes.php` already registers
  `configuration#costCenterUpdate`. The route, the contract and `FR-010` all exist; only the service method
  does not.

  **What stands and must not be redone.** `create()` with its two scoped `RoleAssignment` rows,
  `transferManager()`'s close-at-`validFrom - 1 second` transfer, `listValidOnForCaller($on)`, the
  `FR-011` exactly-one-current enforcement, and the **19 unit tests / 42 assertions**, the real-MariaDB
  read-back, and the **3 killed mutations** recorded above are all proven and remain in force. The
  reopened scope is the single method below.

  **Reopened scope — `CostCenterService::update()`:**

  - **Key by the contract's stable identity.** The path parameter `costCenterId` is
    `{ type: string, format: uuid }`, so the write addresses `CostCenter::publicId` through the existing
    `CostCenterRepository::findByPublicId()`. Note the deliberate asymmetry with the siblings, which key
    their updates by natural key (`CurrencyService::update()` by `code`, `ExpenseTypeService::
    updateByTitle()` by `title`); do not "normalise" the cost centre onto its code.
  - **`expectedVersion` is required and must be checked** against `CostCenter::lockVersion`, refusing a
    stale write with `ConfigurationConflict::staleVersion()` — the `CurrencyService::update()` precedent.
    `DbCostCenterRepository::save()` already increments `lock_version` and guards the update with
    `WHERE lock_version = ?`, so the mechanism exists and needs no schema change; a stale write must
    never be applied to a row that has moved on.
  - **Administrator authorization** on the write, per the `T078` matrix, reusing this class's existing
    `requireAdministrator()`.
  - **`code` is mutable here, unlike a currency.** `FR-010` names code among the updatable fields and
    `CostCenterWrite` carries it. A rename must respect `pettycash_cost_centers_code_uniq` and refuse a
    duplicate with a 409. This asymmetry with `CurrencyService` is required by the artifacts and must not
    be "corrected" toward the currency behaviour.
  - **Validity dates** are mutable and govern future routing through the existing `CostCenter::
    isValidOn()`; `validTo` must not precede `validFrom`.
  - **Manager and senior manager are mutable through this endpoint** ("or its current assignments"), and
    the change **must reuse `transferManager()`'s semantics** — close the outgoing assignment at
    `validFrom - 1 second`, because `RoleAssignment::isValidOn()` is inclusive at both ends — and must
    never overwrite an assignment row. `US5/AC1` requires assignments to govern future routing "without
    changing historical actor records", and `FR-011` requires exactly one current holder per role. Both
    user ids are validated against `UserDirectory` via the existing `requireHostUser()`, and an incoming
    user already current in that role is refused with `ConfigurationConflict::alreadyExists()`.
  - **`changeReason` propagation**, as a trailing optional `?string $changeReason = null` parameter,
    consistent with the five methods `T086` added it to. `CostCenterWrite` carries `changeReason` (and
    `CurrencyWrite` does not), which is the same reason the currency methods gained no such parameter.
  - **`T086` audit integration.** A new `ConfigurationEventType::CostCenterUpdated` case, with the whole
    write wrapped in `ConfigurationAuditService::record()` so the mutation and its event commit in one
    transaction — `T086`'s atomicity requirement, and the reason `record()` exists. A manager change
    additionally emits the existing `ManagerTransferred` event, because `FR-017` names "assignments" as a
    category of its own. **Decide deliberately, do not assume:** `T086` records `entity_id` for a cost
    centre as `code`, so a rename changes the recorded subject — the reopened scope must either record the
    code the caller addressed or settle a stable identity, and say which.
  - **Out of scope, still reported separately:** the `T084` concurrent-uniqueness gap on
    `pettycash_role_assignments` (`pettycash_role_assignments_user_active_idx` is non-unique). It is not
    owned here and is not fixed by this method.

  **Done when** `configuration#costCenterUpdate` answers real data over real HTTP with a required
  `expectedVersion` check, admin-only authorization, transfer-based manager changes, and one configuration
  audit event committed in the same transaction as the write — that is, `T087`'s bar for the eleventh
  route, not merely a method that returns.
- [X] T083[P] [US5] Implement expense-type administration including `evidence_required` and inactive historical retention in `lib/Application/Configuration/ExpenseTypeService.php` **complete — delivered 2026-09-30.** `lib/Application/Configuration/ExpenseTypeService.php`. Creates an expense type with its `evidenceRequired` flag — the field FR-012 calls out and FR-023 turns into a submission precondition — and enforces **active-title uniqueness**, which is the one invariant in this batch that no database index backs: `pettycash_expense_types` declares **no** unique index on `title` (unlike `pettycash_currencies_code_uniq` and `pettycash_cost_centers_code_uniq`), so nothing below the application layer stops two active types sharing a title while FR-023 makes a caller choose between them with different evidence rules. Updates carry a required `expectedVersion` and **set the surrogate key before saving**, because `DbExpenseTypeRepository::save()` otherwise resolves the existing row by `findActiveByTitle()` — which matches on the title and only among active rows, so a rename would insert a second row and a re-create over a deactivated title would duplicate it. `deactivate()` is a separate, single-field operation: it touches `active` and nothing else, which is what FR-012's and US5/AC2's historical retention require. Reads are open to any authenticated caller — the purchaser transaction form is the consumer. **17 unit tests / 41 assertions**, plus real-MariaDB verification. **2 mutations**: the active-title check, and one that made `deactivate()` rewrite the description — **that one initially SURVIVED, exposing an aliasing bug in the test itself** (`InMemoryExpenseTypeRepository` stores the aggregate, not a copy, so a captured "before" object was the same object the service mutated and the assertion compared a rewritten value with itself); the test now snapshots scalars before the call and the mutation is killed. Report: this entry.
- [X] T084 [P] [US5] Implement shared-queue finance-role assignment administration and host-user validation in `lib/Application/Configuration/FinanceAssignmentService.php`
- [X] T085 [US5] Implement idempotent default rial account provisioning, additional currency accounts,
  activation state, and finance account charging in `lib/Application/Account/AccountAdministrationService.php`
  **delivered 2026-10-04, and scoped down to the one clause of four that nothing else claimed.**

  **Scope narrowed on evidence, and the narrowing is the substantive part of this entry.** The task text
  names four things. Three already exist and were **not** reimplemented, because a second implementation is
  a second set of rules rather than a second route to the same one:

  | T085 phrase | Where it lives | Why not here |
  |---|---|---|
  | additional currency accounts | `AccountService::createFor()` | FR-013's write, delivered, covered by `AccountWriteControllerTest` (27 tests). Copying it would give the unique-index conflict two error paths. |
  | finance account charging | `AccountService::charge()` | FR-015's write, with its `expectedVersion` guard and idempotent replay. `FinanceSettlementService` consumes the movements. |
  | activation state | `PettyCashAccount::$active`, honoured by `AccountRepository::findActiveBy*` | FR-013 says "manage … accounts for users" and, **unlike** FR-010/FR-012, does not say "activate, and deactivate". `contracts/ocs-api.yaml` declares no account activation endpoint. There is no requirement to build against, so none was invented. |

  What remained is the clause no other file owned: **FR-020's provisioning trigger**. Verified absent from
  every layer before writing: `grep -rn "ensureDefaultRial\|provision" lib/` returned nothing, and the only
  reference to T085 anywhere was `CurrencyService`'s docblock, which records that `defaultRial()` exists
  "for FR-020's idempotent provisioning" — the lookup was built by T081 and had no caller.

  **The path really was unreachable, in both halves.** `SheetService::createSheet($title, $accountId)`
  resolves an account id the caller must already hold, and
  `tests/Frontend/Component/PurchaserFlow.spec.ts:1746-1757` asserts that with an empty account list the
  form shows a hint and **disables submission**. So a brand-new purchaser had no route to a first sheet:
  the frontend would not let them attempt it, and the backend would have refused it if they had. FR-020's
  "(idempotent provisioning)" was satisfied by nothing.

  **Delivered** `AccountAdministrationService` with two methods and no third:
  `ensureDefaultRialAccount(string $userUid): ?PettyCashAccount` — the primitive, provisioning only when
  the caller has **zero active accounts** and returning `null` when the precondition does not hold; and
  `resolveAccountForNewSheet(string $userUid): ?PettyCashAccount` — FR-020's three clauses read directly
  (none → provision and return it; exactly one → return it; several → `null`, because "a purchaser with
  multiple active accounts MUST choose" and choosing one server-side would make the server's pick the
  sheet's currency).

  **Idempotency falls out of the precondition rather than out of remembering the last account.** The
  property that matters is that the second call *writes nothing*: a purchaser who opens the form twice must
  not accumulate a second default and, with it, the very multi-account ambiguity FR-020 asks the UI to
  resolve. Once the first call has provisioned, the caller *has* an active account, so the precondition is
  false. Under real concurrency the unique index on (user, currency) decides and the loser **re-reads the
  winner's row rather than reporting a conflict**, because provisioning is not a caller-visible write and a
  409 there would be a spurious failure of an operation the caller cannot meaningfully retry.

  **The scale is taken from configuration, never reconstructed.** The service depends on
  `CurrencyService`, not `CurrencyRepository`, so a misconfigured `IRR` fails loudly with the
  administrator's fix named. Both refusal cases are asserted: `IRR` absent, and `IRR` present at
  `minor_unit = 2` — a factor of a hundred on every amount the account would ever hold.

  **One decision recorded rather than acted on: no configuration audit event.** FR-017's list is
  "assignments, expense types, currencies, access, and control settings" — sensitive *administrator*
  changes — and this is a purchaser triggering onboarding by opening a form, with no human decision behind
  it in the zero-account case. `ConfigurationAuditService::record()` also stamps
  `actor_role = Administrator`, which would be false here. Recorded in the class docblock so the absence
  is not later "fixed" by adding an event that misattributes the actor.

  **14 unit tests / 39 assertions**, and **4 mutations, 4 killed**: the zero-active guard removed (2
  failures), `resolveAccountForNewSheet` returning the first of several (1), the misconfigured-rial
  refusal swallowed and provisioning continued anyway (2), and an inactive account treated as satisfying
  the precondition (2).

  **The trigger is NOT wired, and that is the residual gap, not an oversight.** FR-020 says provisioning
  happens "on first sheet creation attempt", and `contracts/ocs-api.yaml:1090-1095` declares
  `SheetCreate.required: [title, accountId]`. **Those two cannot both hold as written:** a purchaser with
  zero accounts has no `accountId` to send, so the attempt never reaches a place provisioning could fire.
  Wiring it means either making `accountId` conditionally optional — a **contract amendment**, which
  `T275`/`T334` show is owned elsewhere and needs the contract owner's decision — or triggering
  provisioning client-side from `GET /accounts`. Neither is a local fix, so neither was made. The service
  is delivered and tested; **the sheet-creation and sheet-form wiring is unowned work and needs an
  explicit owner.** `T146`/`T165` are `[x]` and cover only currency *resolution*, not provisioning.

- [X] T086 [US5] Ensure every configuration/account mutation writes before/after configuration audit in the same database transaction in `lib/Application/Audit/ConfigurationAuditService.php`
- [X] T087 [US5] Add the configuration OCS routes and the `lib/Controller/ConfigurationController.php` transport layer for the eleven already-registered `configuration#*` route targets, delegating to the Phase-7 administration services and staying a thin adapter per `plan.md` (BLOCKED on **T085 only**; the previously stated "BLOCKED on T081-T086" was wrong — T081, T082, T083, T084 and T086 are all `[x]`, verified 2026-10-01. **Nothing currently sequences T085 as the unblock, and T085 is the sole remaining item**, so this is the chain's critical path: 11 `configuration#*` routes 500 without it, which is why the transaction form's cost-centre and expense-type dropdowns are empty and why T171's journeys 4–7 cannot select them. Scoped down 2026-09-30 after verification — see below. **The block is now resolved: T085 was delivered 2026-10-04, and the prerequisite table further down already recorded it as "ordering only — no named action depends on it", which is why this task was implementable without it. The original scoping text is kept rather than rewritten so the reasoning that narrowed this task survives its completion.**

  **Scope narrowed to the transport/controller layer only, on verified evidence.**

  **Removed: account, movement and charge ownership — already delivered.** The account, movement and charge
  endpoints are **not** this task's work. `lib/Controller/AccountController.php` exists and declares all four
  actions its routes name — `index`, `create`, `charge` and `movements` — each delegating to `AccountService`
  and mapping failures through `OcsErrorMapper`. All four `account#*` route targets resolve, so none appears in
  T272's `KNOWN_UNRESOLVED`, and `AccountControllerTest` + `AccountWriteControllerTest` are **green: 27 tests,
  141 assertions**. The delivery is attributable: **T063** (`[X]`) named `lib/Controller/AccountController.php`
  among its routes/controllers, and **T191** (`[x]`) delivered it "per T063". T087 inherited that wording and
  would have re-reported delivered work as undelivered.

  **Removed: the password-confirmation requirement, which nothing backs and which cannot be satisfied.** T087
  previously read "with password confirmation for sensitive operations". That requirement appears **only** in
  this task line. It is absent from `spec.md` (no FR mentions it), absent from `plan.md` (no architecture or
  security decision defines it), and absent from `contracts/ocs-api.yaml`, whose sole `securityScheme` is
  `nextcloudSession` (a cookie) and whose `/accounts/{accountId}/charges` declares only `201` and `409` — there is
  no unconfirmed response in the approved contract. It is also unsatisfiable against the approved test
  credential: Nextcloud's `PasswordConfirmationMiddleware` (via `#[PasswordConfirmationRequired]`) requires
  either a session `last-password-confirm` within 30 minutes, which an OCS API client never carries, or
  `strict: true`, which calls `checkPassword()` — and `scripts/dev/occ user:auth-tokens:list` confirms
  `PETTYCASH_TEST_PASSWORD` is `pc-ct-4`, an app token of type `permanent` / scope `filesystem`, not the account
  password. Applying it would therefore **break the currently approved account contract and its 27 green tests**,
  and a task line must not introduce a new product requirement that no artifact carries. Removed rather than
  deferred: if password confirmation is wanted, it needs a specification requirement, a plan decision, a contract
  response, and a credential the contract suite can actually present — four artifacts, none of them this file.

  **Retained scope.** Create `lib/Controller/ConfigurationController.php` with the eleven public actions the
  routes already declare — `currenciesIndex`, `currencyCreate`, `currencyUpdate`, `costCentersIndex`,
  `costCenterCreate`, `costCenterUpdate`, `expenseTypesIndex`, `expenseTypeCreate`, `expenseTypeUpdate`,
  `financeAssignmentsIndex`, `financeAssignmentCreate` — and keep them thin transport adapters that delegate to
  the administration services and translate every failure through `OcsErrorMapper`, as
  `plan.md`'s Controller layer requires. The routes themselves are already registered and must not change.

  **Prerequisites, in the existing Phase-7 dependency order (T077-T089), stated explicitly because this task
  cannot be selected before them.** Phase 7 runs failing tests, then domain, then persistence/application
  services, then controllers, and T087 is the controller step:

  | Prerequisite | State | Relationship to this task |
  |---|---|---|
  | T077-T080 | `[ ]` | RED tests for Phase 7; precede everything below |
  | T081 | `[X]` | **direct** — owns `currenciesIndex` / `currencyCreate` / `currencyUpdate` |
  | T082 | `[X]` | **direct** — owns `costCentersIndex` / `costCenterCreate` / `costCenterUpdate`; reopened 2026-10-01 for the missing `update()`, closed the same day |
  | T083 | `[X]` | **direct** — owns `expenseTypesIndex` / `expenseTypeCreate` / `expenseTypeUpdate` |
  | T084 | `[X]` | **direct** — owns `financeAssignmentsIndex` / `financeAssignmentCreate` |
  | T085 | `[ ]` | **ordering only** — default rial provisioning; no named action depends on it, but it sits earlier in the phase |
  | T086 | `[X]` | **direct for the write actions** — configuration audit on every mutation, required by FR-017 |

  **T272's rule is preserved and applies directly here.** `RouteResolutionTest` asserts that no route resolves
  to a missing controller action, and its message states: *"A route registered ahead of its implementation must
  either be implemented in the same change or not declared yet. Do NOT add it to KNOWN_UNRESOLVED — that list
  records work that is already planned, not a new defect."* **Therefore a `ConfigurationController` whose eleven
  actions delegate to services that do not yet exist does NOT discharge this task and does NOT discharge
  T272.** Such a controller would make the eleven targets resolve — turning `KNOWN_UNRESOLVED`'s T087 entries
  stale and failing T272's second assertion — while every endpoint still answered HTTP 500 through a DI
  failure. That is a hollow controller, it converts a recorded failure into a false green, and it is explicitly
  refused here. **This task is complete only when the eleven endpoints answer real data over real HTTP**, which
  is measured, not inferred: at reconciliation time `GET /api/v1/configuration/currencies`, `?cost-centers` and
  `?expense-types` each answered **HTTP 500** while `GET /api/v1/accounts` answered **200**.

  **Downstream, for ordering only:** T088 (configuration/account frontend stores and administrator views) and
  T089 (Playwright journeys) both sit after this task in Phase 7.

  ---


  **Delivered 2026-10-04. The eleven endpoints answer real data over real HTTP — measured, not inferred.**

  | Endpoint | admin | non-admin | evidence |
  |---|---|---|---|
  | `GET /configuration/currencies` | **200** | 403 | live probe: `IRR`/`USD`/`EUR`/… with their configured `minorUnit` |
  | `POST /configuration/currencies` | **201** | 403 | live probe; `T87` correctly refused 422 (`^[A-Z]{3}$`), `ZQP` created |
  | `PATCH /configuration/currencies/{id}` | **200** | 403 | live probe; stale `expectedVersion` → **409** `CURRENCY_VERSION_CONFLICT` |
  | `GET /configuration/cost-centers` | **200** | **200** | live probe: real rows with resolved `managerUserId`/`seniorManagerUserId` |
  | `POST /configuration/cost-centers` | **201** | 403 | live probe; duplicate code → **409** `COST_CENTER_ALREADY_EXISTS` |
  | `PATCH /configuration/cost-centers/{id}` | **200** | 403 | live probe; `version` advanced 0 → 1 |
  | `GET /configuration/expense-types` | **200** | **200** | live probe: real rows with `evidenceRequired` |
  | `POST /configuration/expense-types` | **201** | 403 | live probe |
  | `PATCH /configuration/expense-types/{id}` | **200** | 403 | live probe; `evidenceRequired` false → true |
  | `GET /configuration/finance-assignments` | **200** | 403 | live probe: bare array, not a `PageMeta` envelope |
  | `POST /configuration/finance-assignments` | **201** | 403 | live probe |

  **A real defect the probe found and this controller fixes: a missing body field answered HTTP 500.**
  Nextcloud's `Dispatcher` calls an action with **named arguments** built from the request, so an omitted
  field arrives as `null` — and `public function costCenterCreate(string $code, …)` threw a `TypeError` on
  it *before any line of validation could run*. Measured: `POST /configuration/cost-centers` with no
  `managerUserId` returned **`500 "Internal Server Error"`**, not the 422 the contract declares for a
  malformed body. Every request-body parameter is now `?type = null` with presence checked explicitly, and
  the same requests return **422 with the offending field named**. A `TypeError` names a PHP type; a
  `fieldErrors` entry names the field, and only the second tells a caller what to fix. Path parameters stay
  non-nullable — the router has substituted them before the action is called.
  `AccountController::charge()` still has this shape and is **not** fixed here; it is T063's.

  **The read/write authorization split is not uniform, and that is deliberate — recorded because a blanket
  rule would have been wrong twice.** `GET /cost-centers` and `GET /expense-types` answer **200 to a
  purchaser**, because FR-023 requires a *purchaser* to choose one active expense type and exactly one cost
  centre at submission and `TransactionFormView.vue:150` loads both when the form opens; `T082`/`T083`
  require authentication on their listings, not administration. `GET /currencies` answers 403 (T081's
  documented decision — no purchaser-facing view calls it) and the six writes answer 403 (FR-010/012/013).
  `GET /finance-assignments` is guarded **in this controller** rather than left to `T084`'s service, which
  requires only authentication: that roster enumerates who can settle expenses, and the value of hiding it
  from a purchaser is low while the value of the list is not.

  **`ConfigurationPayloadFormatter` is a new file, and the reason is the drift this repository already
  paid for once.** `SheetController` returned `SheetSummary` from all four of its single-sheet responses
  because a private copy of a contract mapping inside a controller had nothing comparing it to the
  contract. Seven of these eleven endpoints need the same four shapes, so inlining them would mean seven
  copies.

  **One contract disagreement found and left visible rather than papered over.**
  `RoleAssignment` is `allOf[FinanceAssignmentWrite, {required: [id, role]}]`, and
  `FinanceAssignmentWrite` lists `changeReason` as **required** — but `data-model.md` defines
  `RoleAssignment` with no such column, because the explanation belongs to the configuration audit FR-017
  actually asks for. The key is emitted as `null`: present, so `required` is satisfied, and honest, where
  inventing a stored string would be worse than both. Its declared `type: string` is still violated.
  **Fixing this properly means adding the column or amending the contract, and both are amendments this
  task does not own.** Asserted in
  `tests/Unit/Application/Configuration/ConfigurationPayloadFormatterTest.php::testChangeReasonIsPresentAndNullOnEveryReadProjection`
  so that whoever resolves it finds the disagreement rather than rediscovering it.

  **The shape is asserted without HTTP, and the reason is a missing credential.** No administrator
  credential exists for the contract suite and inventing one would mean committing a secret, so
  `ConfigurationControllerTest` asserts what the *contract* credential can see — that all eleven routes
  answer a **defined** status and never 500 or 404, which is precisely the regression this task exists to
  prevent, since a 500 is what an unimplemented route returned and a 500 through a DI failure is what
  T087's own note refuses to accept as completion. The response *shape* is asserted in 12 formatter cases
  that state each schema's key set literally from the contract. **25 contract tests / 83 assertions and 12
  formatter tests / 35 assertions, green.** The eleven `KNOWN_UNRESOLVED` entries were removed in the same
  change, as `testTheKnownExpectedFailureListHasNoStaleEntries()` requires; the list is now the two `T096`
  evidence entries.

  **Verification of the full suite after this change: 1173 tests / 4799 assertions, one failure** — the
  pre-existing `PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream` `octet-stream`
  conflict that T275 owns, unchanged from the 1122/4645 baseline taken before this work began. No
  regressions. Probe rows created in the shared database were removed afterwards; the configuration audit
  rows they produced were deliberately **left in place**, because FR-053/FR-055 make that stream append-only
  and a cleanup script that deletes history would contradict the property under test.

  **Live defect this task sits behind, recorded so it is not lost.** `src/services/configurationApi.ts` has
  called `GET /configuration/cost-centers` and `GET /configuration/expense-types` since 2026-09-26, and
  `src/views/transactions/TransactionFormView.vue:150` loads both on open. Both endpoints answer **HTTP 500**
  today, so the purchaser cannot select a cost centre or expense type — which FR-023 requires for submission and
  FR-032 needs for routing. **T081-T083, not this task, are the fix for that**, because the failure is the
  missing read services, not the missing controller.

  **This paragraph's "answer HTTP 500 today" is now false, and both of its diagnoses were partly wrong.**
  Measured after this task's delivery: `GET /configuration/cost-centers` and
  `GET /configuration/expense-types` both answer **200 to a purchaser**, with real rows, the required
  `managerUserId`/`seniorManagerUserId` resolved and `evidenceRequired` carried. So the block on FR-023's
  submission precondition is **lifted at the API**, and what remains is `T088`'s — the administrator and
  funding views this controller makes reachable.

  **The second diagnosis was wrong in a way worth keeping.** It says the 500 was "the missing read services,
  not the missing controller". Both were missing, and the controller was the one that produced the 500:
  `CurrencyService`, `CostCenterService` and `ExpenseTypeService` all existed and were `[x]` (T081–T084),
  so a controller that called them would have worked. **No read service was ever the cause**; the cause was
  that no controller was routed to call the one that did exist. A fix aimed at the second diagnosis would
  have added a second read path beside the working one and left the 500 in place.

- [ ] T088 [P] [US5] Implement configuration/account API services, stores, and administrator/funding views in `src/services/configurationApi.ts`, `src/services/accountsApi.ts`, `src/stores/configuration.ts`, and `src/views/admin/` (all elements visible without horizontal scroll at 390px viewport; WCAG AA contrast; ≥12pt Persian font per FR-071)

  **Its server half is now delivered and this is the next thing on the critical path.** T087 closed the
  eleven endpoints on 2026-10-04, and both reads `src/services/configurationApi.ts` already calls answer
  **200 to a purchaser with real rows** (measured). So `TransactionFormView.vue:150` now has data to load,
  which it did not before: FR-023's "exactly one cost center, one active expense type" precondition is
  reachable from the browser for the first time. **What is still missing on the client is the
  administrator/funding surface** — `src/stores/configuration.ts` and `src/views/admin/` — which is why the
  six write endpoints currently have no reachable UI.

- [ ] T089 [US5] Add configuration, role-boundary, default-rial, funding, deactivation, and historical-preservation Playwright journeys in `tests/E2E/configuration-administration.spec.ts`

- [ ] T358 [HIGH] Wire FR-020's provisioning **trigger**, because the service now exists and nothing calls it
      (FR-020, FR-014, `lib/Application/Account/AccountAdministrationService.php`,
      `lib/Application/Expense/SheetService.php`, `contracts/ocs-api.yaml`) (`contradicts`)

  **Found while delivering T085, and left open deliberately rather than resolved unilaterally.**

  T085 delivered `AccountAdministrationService::ensureDefaultRialAccount()` and
  `resolveAccountForNewSheet()`, tested and mutation-verified. **No production code calls either**, and
  the sheet-creation path is still unable to reach them, so FR-020's "(idempotent provisioning)" remains
  unsatisfied in behaviour even though the mechanism now exists.

  **The block is a genuine specification/contract conflict, not an oversight.** FR-020 says a
  zero-account purchaser "has a default active rial account auto-created on **first sheet creation
  attempt**". `contracts/ocs-api.yaml:1090-1095` declares `SheetCreate.required: [title, accountId]`. A
  purchaser with zero accounts has **no `accountId` to send**, so the creation attempt can never reach a
  point where provisioning could fire. Both statements are approved and they cannot both hold as written.

  **And the frontend independently blocks the same attempt.** `SheetFormView.vue` disables submission when
  the account list is empty, asserted by
  `tests/Frontend/Component/PurchaserFlow.spec.ts:1746-1757` ("a purchaser with no active account gets a
  rial account provisioned on the first creation attempt, so having none is a normal state and must not
  remove the affordance"). **The test asserts the affordance is removed**, which is the opposite of what
  its own comment says the requirement is — so this is a defect in a green test, not only in the view.

  **Two resolutions, and the decision belongs to the contract owner rather than to this ledger:**

  | Option | Change | Cost |
  |---|---|---|
  | A. Make `accountId` conditionally optional | `contracts/ocs-api.yaml` `SheetCreate.required`; `SheetController::create` stops refusing an absent `accountId`; `SheetFormView` enables submission with no account | A **contract amendment**. `T275` and `T334` both record that amendments here need the contract owner's decision. |
  | B. Provision from the client | `configurationApi`/`accountsApi` trigger provisioning when `GET /accounts` returns empty | No contract change, but it moves a domain rule into the client and makes two browser tabs race. |

  **A is the better answer** — B makes provisioning depend on a browser and gives the server two ways to
  be told the same thing — but it is an amendment, so it is not made here.

  **What stands regardless:** `ensureDefaultRialAccount()` is idempotent, is refused loudly on a missing or
  mis-scaled `IRR`, and re-reads the winner's row under a concurrent insert. Whichever trigger is chosen,
  none of that has to be rebuilt.

  **Acceptance criteria.** A purchaser with no accounts creates a first sheet, and
  `GET /accounts` afterwards reports exactly one active `IRR` account. Repeating the attempt provisions
  nothing further. Cross-references: T085, T146, T165, T265.

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
- [ ] T109 [P] [US7] Implement standard Persian RTL PDF export with a Persian font and no custom letterhead/signature in `lib/Infrastructure/Export/PdfExporter.php` (FR-066). **The blocking decision is settled; this task is no longer BLOCKED. Reopened for re-scoping 2026-09-30.**

  **FR-066 settled the question on 2026-09-30, and it decided against the install-time route this note previously argued for.** The specification now states that all four `fileFormat` values — `csv`, `xlsx`, `zip`, `pdf` — are supported in V1, and that an **OFL-licensed Persian font MUST be vendored into the repository under an app-owned path together with its licence text**, with PDF export **MUST NOT** depend on a font installed on the host or inside a container. US7/AC4 was amended to match. The maintainer decision this task asked for — "vendored into the repository under an app-owned path with its OFL text, or generated at install time into an app-owned directory" — is therefore answered: **vendored**, because a vendored font renders identically in every deployment and in CI, whereas `tc-lib-pdf-font`'s install-time generation is wired into the *consuming project's* `post-install-cmd`, which this repository does not have.

  **The concrete app-owned path remains an implementation detail of this task.** No artifact specifies one: `spec.md` FR-066 mandates "an app-owned path" without naming it, and `plan.md` scopes `l10n/` to translation artifacts and says nothing about an export font. Choosing the path, adding the font file, and including its OFL licence text are all this task's work. Do not invent a path in another artifact, and do not treat the absence of a recorded path as a blocker.

  **Findings below remain accurate and are retained as implementation guidance.** `tecnickcom/tcpdf` is not installed (only `tecnickcom/tc-lib-pdf` and satellites; neither `Com\Tecnick\Pdf\Tcpdf` nor `Com\Tecnick\Pdf\TCPDF` exists) and `vendor/` holds zero `.ttf`/`.otf`/`.ttc`/`.afm`/`.pfb` files, so the exporter has no font to use until one is vendored. `K_PATH_FONTS` is the only registration point and `Buffer` performs no search-path autodetection, so the path must always be supplied by the caller — which is exactly why the path must be repository-owned and not machine-specific. No Arabic or Persian example ships with `tc-lib-pdf-font`, so the vendored font is not interchangeable with a generated one. **Shaping is not the obstacle and was verified working:** `tc-lib-unicode` supplies `Com\Tecnick\Unicode\Bidi` (UAX#9) and `Bidi\Shaping\Arabic` with their data tables, reached from `Text::prepareText()` -> `Substitution::replaceChars()`, plus `Tcpdf::setRTL()`, giving the correct reorder-then-shape order. Full evidence: `docs/reports/T109-preimplementation.md`.
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
- [x] T116 [P] [US8] Write failing RTL/responsive/accessibility tests for logical alignment, no page-level horizontal overflow at 390px viewport, labeled errors, keyboard dialogs, live mutation feedback, mixed-direction isolation, status color/icon WCAG AA contrast, text ≥12pt Persian font in `tests/Frontend/Component/RtlAccessibility.spec.ts` (test against Chrome 120+, Edge 120+, Firefox 115+ ESR per FR-072)
      ---
      **Delivered: `tests/Frontend/Component/RtlAccessibility.spec.ts`, 9 tests, 6 pass and 3 fail by
      design.** This is a test-*authoring* task, so a valid RED is the deliverable: 0 skipped, 0
      incomplete, and every failure is a defect in the product rather than a defect in the gate. The
      three failures name 13 concrete findings:
      - **Physical direction in RTL views (7 declarations, 4 files).** `text-align: right` on
        `.nc-table th/td` in `SheetDetailView:419` and `SheetListView:214`, and `margin-right: 0.5rem`
        in `SheetDetailView:465`, `SheetListView:267`, `TransactionDetailView:627`,
        `TransactionListView:207`. These are correct *by coincidence* of the current direction, which is
        what makes them defects: they break the moment a block renders LTR and they defeat the browser's
        own bidi handling.
      - **Unannounced error states (2 files).** `AccountView:30` and `FinanceReviewView:39` render a
        detached `<p class="…__error-message">{{ error }}</p>` as an entire failed-load state, with no
        `role="alert"` and no `aria-live`. Nothing associates it with anything and nothing announces it,
        so a screen-reader user who triggers a communication failure is told nothing.
      - **Direction pinned inside components (4 files).** `DecisionForm:146`, `EvidenceList:48`,
        `PriorDecisionHistory:94`, `RevisionInfo:97` each declare `direction: rtl` while the other
        review components do not. A component that pins direction duplicates its view's declaration and
        is unusable in the other direction.
      **The gate is mutation-verified to be satisfiable, which is the part that matters for a RED.**
      A test that can only fail proves nothing. Fixing all 7 declarations to their logical spelling
      turned the alignment test green, so the gate is a real gate; reverting afterwards was verified
      with `diff` against `HEAD`. A single-declaration mutation moved the count 7 -> 6, confirming it
      detects the exact declaration rather than the file.
      **Two of my own assertions were wrong before the code was, and both are recorded because the
      wrong assertion was the more dangerous kind — it would have demanded a defect.** First, I required a
      breakpoint reaching 390px; `SheetDetailView` and `SheetFormView` use the *mobile-first enhancement
      ladder* (`width >= 480px`, `>= 768px`, `>= 1024px` on a single-column base), where every
      breakpoint is deliberately above the viewport and the view is still correct at 390px. Enforcing my
      version would have forced authors to add dead CSS — the meaningless-proxy failure T283 documents.
      It is replaced by a check on the negative claim (nothing forces the layout wider, asserted
      separately) plus a check that every `@media` uses a spelling the browser understands, since a typo
      silently disables a whole block. Second, my `@media` recogniser missed the colon form
      `(max-width: 600px)` and flagged a correct rule; both spellings are now accepted.
      **A false positive I removed was worse than a missing one.** The live-region check keyed on the
      string `error-message` and therefore flagged `DecisionForm.vue` — the one component whose comment
      explains that `@nextcloud/vue` 9.x has *no* `error-message` prop, i.e. the file T286 had already
      corrected for exactly that bug. A gate that reports its own documentation as a defect teaches its
      reader to ignore it. Comments are now stripped before scanning, and `DecisionForm` is also
      legitimately absent because it delegates the message to the control's own `error` + `helperText`
      pair.
      **What this file does not do, and does not claim.** jsdom has no layout engine and no font metrics:
      it cannot detect real overflow at 390px, cannot compute a WCAG contrast ratio, and its computed
      `font-size` is always the declared value. So the contrast clause asserts only that status is
      rendered as *text* (the half that actually excludes colour-only signalling, and the same proxy
      T283 validated), and the ≥12pt clause is delegated to `typography.spec.ts`. **The
      browser-dependent matrices remain genuinely outstanding**: T122's three-browser run and T123's
      manual RTL/accessibility/representative-user matrix. T116 completing therefore does **not**
      unblock T053, which is gated on T116 *and* T122 *and* T123 and must not be checked until the
      rendered half has run.
      **Consequence accepted deliberately: `npm run test:unit` is now red** (3 failed / 234 passed of
      237), where it was green. That is the task working as specified — it authored a gate that
      documents real defects — and the three fixes above are mechanical. PHP is unaffected:
      `scripts/dev/phpunit` 875 tests / 1 failure (T275's, pre-existing). Typecheck clean, lint 0
      errors, stylelint clean.
      **The 13 findings are recorded, not fixed.** Fixing RTL alignment is a visual change that
      belongs to the US8 work with the browser matrix, not to a test-authoring task; the error-state
      announcements are a behaviour change for T098/T286's area. The next convergence should raise the
      three groups. Report: `docs/reports/T116.md`

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

- [x] T124 [P] Validate `contracts/ocs-api.yaml`, generate or update isolated TypeScript contract
  types, and fail on unreviewed API drift in `scripts/validate-openapi.sh` and `src/generated/api.ts`.
  **Canonical owner of generated-type regeneration** (complete)

  **Delivered, and run in the only order that was safe: after both source amenders.** T275 and T312 are
  both `[x]`, so the document this derived from carries the FR-060 evidence content types and the FR-068
  `period` / 422 / resolved-range / resolved-timezone amendment — verified present in the generated
  types, not assumed.

  ---

  ### What this is actually for

  **The failure mode is a stale generated file that still reads as current.** `src/types/api.ts` was
  hand-written, `contracts/ocs-api.yaml` changed underneath it repeatedly, and nothing connected them.
  So a regeneration is not the deliverable — the **gate** is. A regenerated `src/generated/api.ts` that
  nobody checks is exactly the state T124's own ordering note warned about: it compiles, it type-checks,
  and it is wrong. T272 exists because the same drift one layer down — a route in the contract and absent
  from `appinfo/routes.php` — was invisible until a test happened to exercise it. This is that layer.

  ### `scripts/validate-openapi.sh`

  Three steps, in order, with only `--write` able to change the generated file:

  1. **The document must parse as OpenAPI** — `openapi` is a 3.x string, `info.title` and `info.version`
     are non-empty, `paths` is a non-empty mapping. Parsed with a real YAML parser rather than a regex,
     so a malformed document fails *here* with a statement of what is wrong instead of producing a
     plausible-looking subset of paths inside `openapi-typescript`'s stack trace. Each field is asserted
     with the value it must carry, so a document that silently loses its version cannot pass as valid.
  2. **Derive the types** — `openapi-typescript@^7`, alphabetised, empty objects typed as unknown.
  3. **Compare** the committed file against a freshly derived one, **byte for byte**.

  **The comparison is byte-exact on purpose.** A semantic comparison would accept a regenerated file
  whose ordering or comments differed, which *is* the "looks current but is not" state this exists to
  prevent. The generated file carries an `@contractFingerprint` — the first 16 hex of the contract's
  SHA-256 — so the failure names **which amendment the types predate** rather than only that they are
  stale, and so a hand-edited file cannot pass by being textually similar.

  ### Wiring

  `check:openapi` and `openapi:write` npm scripts, and `check:openapi` placed **first** in `npm run check`
  so contract drift is reported before anything built on top of it is measured.

  ### Verified

  - Contract validated: **OpenAPI 3.0.3, 31 paths, 40 operations, 43 schemas**.
  - `src/generated/api.ts` generated (2044 lines) and confirmed to contain both amendments — the FR-060
    inline-safe content types and T312's `period` / `resolvedFrom` / `resolvedTo` / `resolvedTimeZone`.
  - **`npm run check` exit 0** with the gate in the chain: openapi, portability, typecheck, lint
    (0 errors, 162 warnings — the same count as the pre-T124 baseline, so the generated file adds none),
    stylelint, 308/308 vitest, build.
  - **Three mutations, all caught, with real exit codes**: amending the contract without regenerating →
    **exit 1**; hand-editing the generated file → **exit 1**; in sync → **exit 0**. A `head` pipe
    initially masked the code as 141 (SIGPIPE), so the codes above were re-measured without a pipeline.
  - **The gate is genuinely *in* the chain**, not passing alongside it: with drift introduced,
    `npm run check` exits **1** and **zero** later stages run.

  ### Two decisions worth recording

  - **Two devDependencies, both build-time only.** `openapi-typescript` is the standard generator;
    hand-rolling one would mean trusting my own parser more than a maintained one. `yaml` was added
    explicitly rather than leaning on a transitive copy: a validation script that cannot resolve its own
    parser is a validation script that breaks when a dependency's tree changes, and it was discovered the
    hard way — the first run failed on `require('yaml')` because nothing provided it at the top level.
    Nothing in `js/` or the runtime depends on either.
  - **`src/generated/` is inside `src/`, so `lint` and `typecheck` see the generated file.** That is
    deliberate rather than convenient — it means a type the contract declares cannot be syntactically
    broken without the gates noticing. It was verified rather than assumed: typecheck passes and lint
    reports **0 errors and the same 162 warnings as the baseline**, i.e. the generated file is clean.

  **Not done, and recorded rather than implied.** **`src/types/api.ts` is untouched.** It remains
  hand-written and remains the shape the frontend is actually written against, so deriving types is not
  yet the same as *using* them — migrating the client onto `src/generated/api.ts` is a separate piece of
  work this task does not claim, and nothing here asserts the two agree. **No drift test was added to
  the PHP or vitest suites**; the gate is a shell script invoked by `npm run check`, so a run of
  `npm run test` alone will not catch contract drift. **`exports.md` and `workflow.md`** are prose
  contracts with no machine-checkable form and are not validated by this script.
  Cross-references: T275, T312, T272, T309, T310, T302, T306, T134.
- [ ] T125 [P] Add static security and dependency checks for Composer and npm packages, unsafe spreadsheet values, accidental secret paths, and forbidden direct database/filesystem use in `scripts/security-check.sh`
- [x] T126 Run the legacy-present clean-install and upgrade migration scenarios and record table/migration evidence without modifying `oc_pcash_*` data in `tests/Integration/Migration/V1SchemaMigrationTest.php` and `specs/001-pettycash-v1-core/checklists/migration-validation.md` (**superseded, closed without implementation, by this remediation pass. T263 replaces it, and T263's own text says so: "FR-080, SC-014, supersedes T126, T252"**). The premise is not merely obsolete but **contradicted by a MUST**: FR-081 states schema-change acceptance "MUST NOT require, and MUST NOT be evidenced by, an upgrade path from any pre-V1 database", while this task requires running an **upgrade** migration scenario and evidencing it in `checklists/migration-validation.md`. Leaving it open meant `/speckit-implement`, which works the first genuinely incomplete task, could select work that a MUST forbids and report compliance with a MUST as delivered. It is also now unreachable on its own terms: `oc_pcash_*` matches **0** tables in the shared environment, so the "legacy-present" scenario it names cannot be staged at all — the real legacy tables are `oc_pc_*`, which is the separate discrepancy T252 was raised for and which T263 also owns. **The deliverable is not lost, it moves:** T263 replaces the legacy-database acceptance framing in `quickstart.md` section 2, the migration test suite, and `checklists/migration-validation.md` with clean-installation and forward-chain acceptance on an empty database. **This closure is a supersession record only; no test, artifact, or evidence was produced by it, and none should be inferred from the `[x]`.** The scope-contradiction finding is retained under T263 and must be settled there)
- [ ] T127 [P] Add deterministic fixture generation for up to 100 active users and 10,000 annual transactions in `tests/Fixtures/TargetVolumeFixture.php`
- [ ] T128 Measure 20 warmed runs for sheet list/detail, transaction detail, audit, and evidence views; calculate p95 and optimize only scoped queries/indexes needed to meet the 3-second target (p95 <3s, error rate <0.1%, zero data loss per FR-073) in `tests/Performance/RoutineViewsPerformanceTest.php`
- [ ] T129 Run concurrent decision, settlement, charge, idempotency, and account-reconciliation stress tests against MariaDB in `tests/Integration/Workflow/ConcurrencyStressTest.php`
- [ ] T130 Implement a read-only evidence reconciliation diagnostic that reports orphan AppData files and missing binaries without automatic deletion in `lib/Application/Evidence/EvidenceReconciliationService.php` and `lib/Command/EvidenceReconcileCommand.php` (verify 100 MB max file size compliance per FR-074)
- [ ] T131 [P] Document architecture boundaries, migration safety, AppData backup dependency, audit/revision immutability, recovery, and release procedures in `docs/architecture.md`, `docs/operations.md`, and `docs/release.md` (include measurable RTL criteria: 390px viewport, WCAG AA, ≥12pt font; performance targets: p95 <3s, error rate <0.1%, zero data loss; authorization decision matrix; evidence 100 MB limit per FR-074; advanced evidence restrictions deferred to V2 per FR-030; browser targets Chrome 120+/Edge 120+/Firefox 115+ ESR per FR-072)
- [ ] T132 Package the application without development dependencies or source maps and verify app ID, namespace, licence, routes, translations, migrations, and generated assets in `scripts/package.sh`
- [ ] T133 Execute every automated command and business scenario in `specs/001-pettycash-v1-core/quickstart.md` and record results in `specs/001-pettycash-v1-core/checklists/quickstart-validation.md`
- [ ] T134 Complete requirement-to-test traceability for **FR-001 through FR-084 and SC-001 through SC-014** in `specs/001-pettycash-v1-core/checklists/traceability.md` (include clarified FR-002, FR-030, FR-034, FR-055, FR-056, FR-071, FR-072, FR-073, FR-074). **Scope widened three times by ledger remediation passes, absorbing former T273.** First widening: T134 previously read "FR-001 through FR-076 and SC-001 through SC-012" while `spec.md` defined FR-001–FR-081, so FR-077 (declared backend portability), FR-078 (`pettycash_` table prefix), FR-079 (`pettycash_*` constraint naming), FR-080 (clean-installation storage with no pre-V1 import or preservation contract), FR-081 (schema-change acceptance over clean install and the forward migration chain), SC-013 (per-backend portability acceptance) and SC-014 (100% `pettycash_` clean-install schema) fell outside the matrix entirely. Second widening, 2026-09-30: two requirements were **added to `spec.md` after that first pass** — **FR-082** (group-export selection and refusal semantics, split out of the duplicate FR-068 identifier) and **FR-083** (corrupt-record error semantics across read endpoints) — so the range must now reach FR-083 or both would read as untraceable, which Constitution V forbids. **Third widening, 2026-10-02: FR-084** (mutating-operation replay safety, keyed by `X-PettyCash-Command-ID`) was added to `spec.md` to close a real traceability failure — the rule was `required` by `contracts/ocs-api.yaml:767-772` and implemented by T027/T166/T317/T318 and roughly ten more tasks, but no requirement stated it, so implemented business behavior was traceable to tasks and not to a requirement. FR-084 is owned by T027 (`IdempotencyService`), T317 and T318. `spec.md` now defines **84** functional requirements (FR-001–FR-084, all identifiers unique) and 14 success criteria. **Each of FR-001–FR-084 and SC-001–SC-014 must map to the task and test that satisfies it.** FR-082 is owned by T302 and T306; FR-083 is owned by T313 and T314; FR-084 is owned by T027, T317 and T318; all three are now cited on those lines. `plan.md`'s Scale/Scope was reconciled to **83** on 2026-09-30 and to **84** on 2026-10-02, so the earlier instruction to record `plan.md:60`'s "76 functional requirements" as superseded is **moot — nothing remains to record there**, and this task must not reintroduce a count correction for a plan line that is already correct. Former T273 was removed as a duplicate of this line: both produced the same `checklists/traceability.md` artifact and differed only in the ID range covered. **The ordering constraint that this line's history records:** the range was widened only *after* the requirement it names existed, because widening first produces a matrix citing a requirement nothing defines. Do not widen it again ahead of a decision.

  **Third pass, 2026-10-01 — seven requirements and seven success criteria had no citing task, so the
  matrix could not have been completed from this ledger as it stood.** A requirement-reference sweep
  over `tasks.md` found these with **zero** occurrences of their identifier anywhere in the file. The
  distinction that matters is whether the behaviour is *unimplemented* or merely *uncited*, because the
  first is a gap and the second is a traceability defect, and only one of the seven is the first:

  | Requirement | State | Owning task or test to cite |
  |---|---|---|
  | FR-006 shared finance queue, no cost-center scoping | implemented, uncited | T190 (`lib/Application/Workflow/FinanceQueue.php`), T185 |
  | FR-019 sheet identity fields | implemented, uncited | T044, `DbExpenseSheetRepository` |
  | FR-029 evidence metadata and revision traceability | implemented, uncited | T071, T072, T091 |
  | FR-044 completed-sheet lock | implemented, uncited | T033 ("locks new-transaction creation"), T034 ("submitted read-only") |
  | FR-047 return to purchaser with required explanation | implemented, uncited | T050, T073 |
  | **FR-063 sheet list/detail fields** | **unimplemented** | **T327 — blocked, do not record as satisfied** |
  | FR-070 V1 exclusions | negative requirement, no guard | **T135** |
  | SC-002, SC-003, SC-004, SC-005, SC-007 | implemented, uncited | the US1–US4 journey and workflow tests |
  | SC-009 Persian RTL at desktop and mobile in each supported browser | implemented, uncited | T122, T123 |
  | SC-011 completed-sheet lock | implemented, uncited | T033, T034; implementation is `SheetDetailView`'s `isSubmitted` |
  | SC-010 | **correctly self-exempt** | none — see below |

  **The FR-044/SC-011 pair was nearly mis-cited and the correction is worth keeping.** An earlier draft of
  this table pointed both at "T011 area", which is `T011` — a migration-safety task about installing
  beside existing `pettycash` migration records, with nothing to do with locking a completed sheet. The
  owners are **T033** and **T034**, which name the behaviour in their own text. Recorded because a
  traceability matrix that cites the wrong task is worse than one that cites none: it looks complete.

  **FR-063 is the only true coverage gap in the set and it must not be written up as satisfied.** FR-063
  requires sheet lists and details to show "the active transaction revision" among other fields, and the
  detail projection cannot: `GET /sheets/{id}` returns no `transactions` array (T327) and the
  sheet's transaction list answers 405 (T326). Until T326 and T327 land, this row is a **known failure**,
  and recording it as traced would be the exact defect this matrix exists to prevent.

  **SC-010 requires no task and must not be given one.** Its own text states that "no task is expected to
  assert it" and that the release-readiness review owns recording the outcome "whichever way it falls" —
  so its zero reference count is correct, not a gap. T135 is the owner. Do **not** invent a task to
  make SC-010's row look covered.

**The FR range settled on 2026-10-02, and the reason it took three widenings is the point.** The
`Command Result` entity added to `spec.md`'s Key Entities on 2026-10-01 recorded that
`X-PettyCash-Command-ID` is required by `contracts/ocs-api.yaml:767-772` and implemented in the schema
and roughly a dozen tasks, while **no FR stated the rule** — implemented business behavior traceable to
tasks but not to a requirement, which Principle I does not allow. That is now closed by **FR-084** in
`spec.md`, and this line's range reads **FR-001–FR-084**, with `plan.md`'s Scale/Scope at 84.

**The requirement was added before the range moved, and that ordering must not be inverted.** Widening
the range first would have produced a `checklists/traceability.md` citing FR-084 while nothing defined
it — a matrix that looks complete and traces nothing, which is worse than a matrix that visibly stops
at FR-083. If a further requirement is ever added, add it to `spec.md`, reconcile the count in
`plan.md`, and widen this line last. Do not widen this range ahead of a decision again.
- [ ] T135 Perform the final constitution compliance, compatibility, documentation, and release-readiness review and record all approved exceptions or confirm none in `specs/001-pettycash-v1-core/checklists/release-readiness.md` (verify measurable RTL criteria, performance targets, authorization matrix, config audit scope, advisory decision behavior, evidence 100 MB limit, advanced evidence restrictions V2 deferral, browser targets; **verify FR-070's five V1 exclusions still hold** — see below)

  **FR-070 is a negative requirement and this review is its only owner.** FR-070 states that scheduled
  exports, automatic report delivery, external export interfaces, custom organizational templates, and
  direct integration with other systems MUST remain outside V1. It was the only one of the seven
  functional requirements that no task in the ledger cited, and the reason is structural rather than an
  oversight: an exclusion cannot be implemented, so no implementation task naturally names it, and
  nothing asserts it is *still* excluded. A scope creep that added any one of the five would therefore
  pass every other gate in this ledger silently.
  A grep of the repository finds no scheduled job, cron registration or external-integration entry
  point, so the exclusions hold today — but that is a spot check, not a gate, and it was performed on
  2026-10-01 by the specification analysis rather than by this task. **This review must record the
  result explicitly**, either as five confirmed exclusions naming where each was checked, or as an
  exception with an owner and a follow-up. Recording "no scheduled-job mechanism exists" without
  naming the five would repeat the omission this note exists to close.
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
- [x] T158 Remove temporary Vue component mocks from `tests/Frontend/__mocks__/components.ts` and update `vitest.config.ts` aliases to resolve real components per T042 (unrequested)

  **Delivered as a deletion only — the alias half of this task was already done, and the file was
  already dead.** `vitest.config.ts` declares one alias, `'@' → src`, and no second entry; the
  mock file's own header says "placeholder implementations … will be replaced by real components in
  T042", `T042` is complete, and no file under `src/`, `tests/`, or any config imports it by path or
  by name (`grep -rn "__mocks__\|mocks/components" src tests vitest.config.ts vite.config.ts
  package.json` exits 1). So `git rm` removed it and `vitest.config.ts` needed no edit: there was no
  alias left to redirect.

  **`tests/Frontend/__mocks__/` went with it, because an empty `__mocks__` directory is a claim, not
  an absence.** Vitest resolves `__mocks__` by convention, so a directory that exists but is empty
  reads as "mocks are configured here" to the next reader and to tooling; removing the file and
  leaving the directory would have preserved the ambiguity the task exists to remove.

  `npx vitest run` is **11 files / 308 tests / 308 passed** with the file gone, which is the
  substantive proof: had any spec been resolving a placeholder through a convention path, removing
  the module would have failed collection or a render, not passed silently.

  **This closes T215 as well**, which the retired-ID table records as a duplicate of T158 with the
  same deliverable; no separate work remained under it.
- [x] T159 Fix `EvidenceController.destroy()` to handle both draft deletion (204) and post-submit deactivation (200 with deactivated metadata) per ocs-api.yaml (done)
- [x] T160 Ensure `SheetController.submit()` and `TransactionController` decision endpoints return proper OCS envelope with `ocs.meta.statuscode` matching HTTP status per contract tests (done)

---

## Phase 13: Convergence (Remaining Gaps)

**Purpose**: Close remaining gaps between specification, plan, tasks, and current implementation identified during convergence assessment.

### Phase 3 Completion — US1 Purchaser Capture (Priority: P1)

- [x] T161 [P] [US1] Extend `SheetSubmissionPolicyTest.php` with missing tests: open-sheet editing, inherited sheet currency, multiple open sheets, balance-independent submission per FR-018/FR-020/FR-024/FR-027 in `tests/Unit/Domain/Expense/SheetSubmissionPolicyTest.php` (done - tests already implemented)
- [x] T162 [P] [US1] Fix `PurchaserFlowControllerTest.php` contract tests - corrected OCS URL prefixes for transaction/evidence endpoints in `tests/Contract/Controller/PurchaserFlowControllerTest.php` (done)
- [x] T163 [P] [US1] Fix test isolation in `SheetSubmissionTest.php::testSubmitSheetServiceRoutesTransactionsToCostCenterQueues` - resolved test pollution from ManagementDecisionTest modifying CurrentUser; added tearDown to restore 'test-user' and clear more repositories in `tests/Integration/Workflow/SheetSubmissionTest.php` and `ManagementDecisionTest.php` (done)
- [x] T164 [P] [US1] Create `PurchaserFlow.spec.ts` Vue tests for sheet/transaction forms, field-level submission errors, account-currency selection, evidence upload state, submitted read-only behavior per FR-071 in `tests/Frontend/Component/PurchaserFlow.spec.ts` (verified 2026-09-30 — the `(missing)` marker was wrong: the file exists with 85 passing tests covering every named clause: sheet/transaction forms, field-level errors, account-currency selection, evidence upload state (100 MB, remove-before-submit, download-after-submit, inactive-not-deleted), submitted read-only. FR-071's *rendered* half stays with T122/T123)
- [x] T165 [US1] Wire `SheetService.createSheet()` to `AccountRepository` for currency resolution instead of hardcoded IRR per FR-014/FR-020/T037 in `lib/Application/Expense/SheetService.php` (done - verified implemented)
- [ ] T166 [US1] Add public evidence link methods (create/deactivate) to `EvidenceService.php` per FR-031 in `lib/Application/Evidence/EvidenceService.php` (partial)
- [x] T167 [US1] Complete audit event insertion in `SubmitSheet.php` for all workflow transitions (decisions, returns, corrections, evidence deactivation) per FR-051/FR-052/T028 in `lib/Application/Workflow/SubmitSheet.php` (**superseded, closed without implementation, by this remediation pass. Its scope was already delivered and owned by T213/T295/T321; the file it named was never the right one**). The task's premise is wrong rather than merely stale: `SubmitSheet.php` contains exactly two `AuditWriter::append()` calls, at `:117` and `:156`, and both are submission-scoped. Decisions, returns, corrections and evidence deactivations are produced by `ManagementReviewService`, `ResubmitCorrection` and `EvidenceService` respectively — **no implementation could satisfy this line by editing the file it names**, which is why it stayed `(partial)` through every convergence pass rather than being worked. The real work was delivered under T148, restated and completed as T213 (`[x]`), and T295 records why: T028 was checked while claiming a configuration audit writer that did not exist and never ran, which is the same class of defect. T321 independently confirms T167 does not own the adjacent question it was repeatedly confused with, stating that T167 "is traced to US1 and says nothing about the `SUBMITTED` event's linkage, so it does not own this". **No audit coverage is lost by closing it:** FR-051/FR-052 obligations live with T213 (per-transition audit insertion) and T321 (the `SUBMITTED` event's timeline scope, delivered as resolution (B)). **This closure is a supersession record only; no code was changed by it and the `[x]` asserts supersession, not fresh test evidence.** The `(partial)` marker was accurate about the work and misleading about the location, and is replaced by this record rather than deleted)
- [x] T168 [US1] Add missing OCS routes to `appinfo/routes.php` for decisions, correction, resubmit, revisions, audit, queues, returns, evidence links, session, dashboard, configuration, accounts, reports, exports per `contracts/ocs-api.yaml` (done - verified all routes present)
- [x] T169 [P] [US1] Implement `reviewsApi.ts`, `accountsApi.ts`, `stores/reviews.ts`, `stores/accounts.ts` typed API services and Pinia stores in `src/services/` and `src/stores/` (done - verified files exist)
- [x] T170 [US1] Complete evidence uploader/list Vue components with Persian RTL (390px viewport, WCAG AA contrast, ≥12pt font) per FR-071 in `src/components/evidence/` (done - rewritten with @nextcloud/vue)
- [ ] T171 [US1] Install Playwright browsers (`npx playwright install chromium firefox`) and verify
  `purchaser-submit.spec.ts` E2E tests pass in `tests/E2E/purchaser-submit.spec.ts` (**1 of 7 green; the
  browser chain now runs and three product defects are fixed, and the remaining six failures are two
  named unowned contract violations plus T087** — worked 2026-10-01; full detail and evidence in
  `docs/reports/T171.md`)

  **Status after the 2026-10-01 pass. T171 is still `[ ]`, and the reason is not a credential and not a
  missing browser.** The suite executes, signs in, creates a sheet, and gets as far as the transaction
  form. Three defects that made the app unusable in a browser were found and fixed, and the seventh
  journey now passes. The other six stop on two API gaps that no task owns, recorded below as **T326**
  and **T327**.

  **What was actually broken, and it was not what this note previously said.** The note recorded that the
  suite "fails later and for a different reason: `sheet-list-item` does not appear after the create form
  is submitted". That was a *symptom* of `POST /sheets` answering **400 with an empty body**, and the
  empty body is why the app reported a **network error** for what was a client-side serialisation fault:

  - **`NcSelect` wrote the whole option object into `v-model`.** `NcSelect` forwards `update:modelValue`
    to `vue-select`, whose `reduce` defaults to `option => option`, so `accountId` was sent as
    `{"label":"IRR ۱٬۰۰۰٬۰۰۰٬۰۰۰","value":"e2e00000-…"}`. `SheetController::create(?string $title,
    ?string $accountId)` then raised `TypeError: Argument #2 ($accountId) must be of type ?string, array
    given`, which the AppFramework turned into a 400 with a body that is not an OCS envelope, which
    `src/services/http.ts` correctly refused to dereference and classified as `NETWORK_FAILURE`. Fixed at
    all seven call sites through `src/utils/selectOption.ts`. **This affected every select in the app**,
    so no review decision and no report filter could be recorded either — it was not a purchaser-only
    fault.
  - **`NcNoteCard` has no `header` slot.** `@nextcloud/vue` 9.12.0 declares only `default` and `icon`, so
    all **11** `<template #header>` blocks in the app were dropped without a warning. `SheetDetailView`
    — the one view a purchaser must pass through — rendered with **no Edit, no Add transaction and no
    Submit button at all**. Fixed app-wide; see `SheetDetailView.vue:104`.
  - **The app's lower half was unreachable.** Nextcloud 34 makes `#content` a `position: fixed`,
    `overflow: clip` flex container. Measured at a 1280×720 viewport: `#content` 662px, the transaction
    form 1041px, and Save draft / Create / Cancel at y=925–959 inside a clipped box with no scrollbar.
    Playwright's own report was `element is visible, enabled and stable` followed by `element is outside
    of the viewport` for 30s. Fixed in `src/styles/app.scss`; `min-height: 0` on the flex item is
    load-bearing, and the reason is recorded there.
  - **Six of the seven journeys could not create a sheet at all**, for a reason that had nothing to do
    with the product: `accountId` is required, and those six repeated the create-form steps without
    selecting an account, leaving the submit button permanently disabled by `:disabled="… || !isSubmittable"`.
    Centralised into `createSheet()` so it cannot be dropped from one journey again.

  **Why 237 green tests missed all three.** Every frontend spec replaces `@nextcloud/vue` with local
  stubs. A stub cannot catch a defect in the seam between the app and the stubbed library, because the
  stub *is* that seam: the stub's `NcSelect` emitted a string and the stub's `NcNoteCard` rendered its
  children. `tests/Frontend/Component/NextcloudVueContract.spec.ts` now mounts the **real** library and
  asserts the controls the purchaser journey depends on and the value that reaches the wire. Both fixes
  are **mutation-verified**: removing `:reduce` from the sheet form fails 2 of its 6 tests, and
  reinstating `<template #header>` in `SheetDetailView` fails the action-bar test. The scroll fix has no
  jsdom gate and cannot have one — jsdom has no layout — so its gate is this Playwright suite, which is
  exactly why T171 still has to be finished.

  **What still blocks the other six, precisely.**
  - **T326** — `GET /sheets/{sheetId}/transactions` **does not exist**: not in `appinfo/routes.php` and
    not in `contracts/ocs-api.yaml` (which declares only `post` under that path). The frontend calls it,
    so the sheet's transaction list answers **405** and can never load.
  - **T327** — `GET /sheets/{sheetId}` returns neither `transactions` nor `allowedActions`, both of which
    the approved contract's `SheetDetail` **requires**. Because `canSubmit` is
    `currentSheet.transactions.length > 0`, the **Submit button can never appear**, so a purchaser can add
    a draft transaction and then cannot submit the sheet at all. No test anywhere asserts either
    required property, which is how it shipped.
  - **T087** — the eleven `configuration#*` routes are registered and answer **500**
    (`lib/Controller/ConfigurationController.php` does not exist), so the cost-centre and expense-type
    dropdowns are empty and journeys 4–7 cannot select them. Pre-existing and already recorded on T087;
    it is the dependency this note has always named, and T171 still does not release it.

  **The two audit journeys remain impossible, unchanged and still T098's.** `[data-test="audit-event"]`
  exists nowhere in `src/`, `SheetDetailView.vue:257` renders a hardcoded `<tr v-if="false">`, and there is
  no `GET /sheets/{sheetId}/audit` route — so the sheet-level audit table needs an endpoint, not a binding,
  and that is T098's scope. The assertions were **not** weakened, skipped or deleted to make this task
  look finished.

  **The stale-selector inventory in this note is now closed except for one.** Of the 26 `data-test`
  selectors the suite uses, `account-option`, `cost-center-option` and `expense-type-option` were never
  the app's to emit (they are `NcSelect` internals, now addressed by ARIA role), and
  `add-transaction-button` / `transaction-list-item` / `transaction-status` / `validation-error` are
  mapped in the spec's own table. Only `audit-event` has no counterpart, and T098 owns it. A
  duplicate `create-transaction-btn` also existed on **two** cards in `SheetDetailView`; the one on the
  transaction-list card is removed, so the hook is now unambiguous.

  **The historical record of the credential investigation is retained below**, because it explains why a
  *separate* e2e account was used instead of `employee1` and must not be "simplified" back.
  `.env.testing`'s `PETTYCASH_TEST_PASSWORD` cannot authenticate a browser. `.env.example` defines it as an "App password / token for the test user", minted by `occ user:auth-tokens:add`, and `occ user:auth-tokens:list employee1` confirmed it: `pc-ct-4`, type `permanent`, scope `filesystem`, 72 alphanumeric characters — exactly Nextcloud's app-password format. Measured both ways: the token returns **200** from `GET /ocs/v2.php/apps/pettycash/api/v1/me`, so it is valid, while the login form returns **"Wrong login or password."** and the server logs `Login failed: employee1`. That is designed behaviour, not a fault — an app token authenticates Basic-auth/OCS callers and is never accepted by the web login form, which takes only the account password. Basic auth is not a way around it either: `GET /apps/pettycash/` with the same credentials answers **403**, because Nextcloud accepts Basic auth for OCS endpoints and not for app pages. Two candidate causes were ruled out by measurement rather than left as theories: **bruteforce throttling** (the container IP `172.18.0.1` carried `delay: 800`, but `scripts/dev/occ security:bruteforce:attempts 172.18.0.1` re-read `attempts: 0, delay: 0` after natural decay and the login still failed) and **form truncation** (the DOM value, the value after `fill()`, and the `password=` field of the actual POST body were each compared against the token — all three byte-identical, and the body carries a `requesttoken`). **`employee1` must not be reset or repurposed**: the OCS contract suite authenticates as `employee1` and depends on that account's role assignments and seeded balances, so repointing the browser suite at it would trade a visible browser failure for a broken contract suite.

  **The spec is separately stale and must be corrected once a session is possible, so this should not be read as "only auth is missing".** Of the 26 `data-test` selectors `purchaser-submit.spec.ts` uses, **8 do not exist in `src/`**: `add-transaction-button` (the component emits `create-transaction-btn`), `transaction-list-item` (`transaction-row`), `transaction-status` (`transaction-status-badge`), `validation-error` (`validation-summary` and `validation-summary-{field}`), and `account-option` / `cost-center-option` / `expense-type-option`, which are `NcSelect` options rather than declared hooks. `audit-event` is the eighth and is a **product gap, not a selector mistake** — the unrendered audit table belongs to `T098`; the Vue-testable audit query and timeline belong to `T092`/`T093`. The assertion at line 220 also expects the literal `test-user` where the fixture user is `employee1`.

- [x] T172 [US2] Mark T044 complete - `ManagementWorkflowTest.php` exists with 12 passing tests in `tests/Unit/Domain/Workflow/ManagementWorkflowTest.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `tests/Unit/Domain/Workflow/ManagementWorkflowTest.php` exists and `scripts/dev/phpunit tests/Unit/Domain/Workflow/ManagementWorkflowTest.php` reports **OK (12 tests, 32 assertions)**, confirming the 12 this line already claimed)
- [x] T173 [US2] Mark T045 complete - `ManagementReviewAuthorizationTest.php` exists with 13 passing tests in `tests/Unit/Security/ManagementReviewAuthorizationTest.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `tests/Unit/Security/ManagementReviewAuthorizationTest.php` exists and `scripts/dev/phpunit tests/Unit/Security/ManagementReviewAuthorizationTest.php` reports **OK (13 tests, 15 assertions)**, confirming the 13 this line already claimed)
- [ ] T174 [P] [US2] Create `ManagementReviewControllerTest.php` and `ManagementDecisionTest.php` contract/integration tests for management queues and decision endpoints per T046 in `tests/Contract/Controller/` and `tests/Integration/Workflow/` (missing)
- [x] T175 [P] [US2] Create `ManagementReview.spec.ts` Vue tests for scoped queues, evidence/history display, decision form validation per FR-071 in `tests/Frontend/Component/ManagementReview.spec.ts` (verified 2026-09-30 — the `(missing)` marker was wrong: 22 passing tests over the real components; FR-071 covered at declaration level, rendered half stays with T122/T123)
- [x] T176 [P] [US2] Implement `WorkflowStateMachine.php` centralized workflow state machine and transition guards from `contracts/workflow.md` per T048 in `lib/Domain/Workflow/WorkflowStateMachine.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `lib/Domain/Workflow/WorkflowStateMachine.php` is present. Existence only; this is not a claim that T048's transition guards are behaviourally complete)
- [x] T177 [US2] Enhance `WorkflowDecision.php` with "one effective decision per revision and stage" uniqueness constraint per T049 in `lib/Domain/Workflow/WorkflowDecision.php` (partial)
- [x] T178 [US2] Implement `ManagementReviewService.php` with manager/senior queue queries, decision orchestration, actor snapshots, sheet-stage recalculation, audit writes per T050 in `lib/Application/Workflow/ManagementReviewService.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `lib/Application/Workflow/ManagementReviewService.php` is present. Existence only; T050's behavioural scope and evidence are unchanged by this annotation)
- [x] T179 [US2] Add `ReviewController.php` with management queue and decision OCS routes/controllers per T051 in `lib/Controller/ReviewController.php` and `appinfo/routes.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `lib/Controller/ReviewController.php` and its `appinfo/routes.php` entries are present. Existence only; route dispatch is evidenced by the contract suite, not by this line)
- [x] T180 [P] [US2] Implement `reviewsApi.ts` and `stores/reviews.ts` typed review API services and Pinia state per T052 in `src/services/` and `src/stores/` (verified 2026-10-01 — the `(missing)` marker was wrong: `src/stores/reviews.ts` is present. This line previously named the path `stores/reviews.ts` without the `src/` prefix, which was a path-spelling defect rather than an absent deliverable; `reviewsApi.ts` is present alongside it. Existence only)
- [x] T181 [US2] Implement cost-center/senior queue views, transaction review detail, decision form, prior-decision history components per FR-071 in `src/views/reviews/` and `src/components/review/` (verified 2026-10-01 — the `(missing)` marker was wrong: `src/views/reviews/` holds 6 views and `src/components/review/` holds 7 components. Existence only; the browser-measured half of FR-071 remains with T116/T122/T123, as T053's note records)

### Phase 5 Completion — US3 Finance Settlement (Priority: P1)

- [x] T183 [US3] Mark T055 complete - `FinanceDecisionPolicyTest.php` exists with 10 passing tests in `tests/Unit/Domain/Workflow/FinanceDecisionPolicyTest.php` (verified 2026-10-01 — the `(missing)` marker was wrong **and the test count was wrong**: `tests/Unit/Domain/Workflow/FinanceDecisionPolicyTest.php` exists and `scripts/dev/phpunit tests/Unit/Domain/Workflow/FinanceDecisionPolicyTest.php` reports **OK (13 tests, 38 assertions)**, not the 10 this line previously claimed. Recorded separately because it is the one line whose annotation was wrong about content and not only about existence, which is why these 19 could not be repaired by asserting existence alone)
- [X] T184 [P] [US3] Create `FinanceSettlementTest.php` integration tests for fixed row-lock order, exactly-one consumption, command replay, conflicting UUID, simultaneous approvals, rollback per T056 in `tests/Integration/Workflow/FinanceSettlementTest.php` (complete — the file exists with 29 tests / 149 assertions covering all six cases T056 names; T056 already `[x]`, and the evidence is in `docs/reports/T056.md`. Corrected by T293, which is a bookkeeping fix and **not** new test evidence)
- [X] T185 [P] [US3] Create `FinanceReviewControllerTest.php` contract tests for shared finance queue and decisions per T057 in `tests/Contract/Controller/FinanceReviewControllerTest.php` (complete — the file exists with 21 tests covering the shared queue and the finance decision responses; T057 already `[x]`, and the details of what it found are recorded there)
- [x] T186 [P] [US3] Create `FinanceReview.spec.ts` Vue tests for shared queue, senior-decision restrictions, settlement feedback per FR-071 in `tests/Frontend/Component/FinanceReview.spec.ts` (verified 2026-09-30 — the `(missing)` marker was wrong: 25 passing tests covering shared queue, senior-decision restrictions and settlement feedback; FR-071 covered at declaration level, rendered half stays with T122/T123)
- [x] T187 [P] [US3] Align `AccountMovement` domain with minor units (not Money), positive magnitude, unique command ID, unique consumption source per T059 in `lib/Domain/Account/AccountMovement.php` (partial)
- [x] T188 [US3] Implement `DbAccountRepository.php` and `DbAccountMovementRepository.php` with account locking, movement insertion, balance projection per T060 in `lib/Infrastructure/Persistence/Account/` (verified 2026-10-01 — the `(missing)` marker was wrong and is superseded by the note below: `lib/Infrastructure/Persistence/Account/DbAccountRepository.php` and `DbAccountMovementRepository.php` are both present. Existence only; the note's own reconciliation of this line against T060 stands unchanged) (done - the four account read queries now build their currency projection with `selectAlias()` instead of inline `as` aliases, which `IQueryBuilder` splits with an unbounded `preg_split` and truncates into one malformed alias; `c.public_id` was also dropped because `data-model.md` defines `Currency` without a `public_id`; `mapRow()` no longer overwrites the public-UUID `$id` with the surrogate key, and `save()` now resolves the existing row via `dbId`; `AccountMovement` carries `accountPublicId` so `DbAccountMovementRepository::save()` resolves the real `pettycash_accounts.id` and fails loudly instead of writing `account_id = 1` (`pc_accounts` was the pre-T260/T261 name and is stale here). `POST /sheets` moved from HTTP 500 to a structured 422 and the account query is now exception-free. Pessimistic row locking is NOT implemented here and remains with T060/T129)
  **Checkbox/note contradiction reconciled 2026-09-30.** This line is `[x]` and its own text says pessimistic
  row locking "is NOT implemented here and remains with T060/T129" — which reads as a contradiction until the
  relationship is stated: **T188 and T060 are not the same deliverable.** T188 is the Phase-13 duplicate of
  T060, and it delivered the repositories' currency projection, `mapRow()` public-id handling, `save()` row
  resolution and the `accountPublicId` fix. T060's distinguishing scope is **pessimistic account row
  locking**, which is not delivered and correctly remains `[ ]`. **The `[x]` here therefore claims only the
  work this note describes and must not be read as closing T060**; T060 stays open and, per its own
  dependency, precedes **T129** (concurrency stress tests), which would otherwise measure a lock that does
  not exist. This annotation is a bookkeeping reconciliation only — no locking was implemented and no test
  was run to produce it.
- [x] T189 [US3] Implement `FinanceSettlementService.php` atomic orchestration (decision, unique movement, balance, transaction final state, sheet final state, command result, audit) per T061 in `lib/Application/Workflow/FinanceSettlementService.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `lib/Application/Workflow/FinanceSettlementService.php` is present. Existence only; the six settlement cases T056 names are evidenced by T184, not by this line)
- [x] T190 [US3] Implement `FinanceQueue.php` shared finance queue queries without cost-center scoping per T062 in `lib/Application/Workflow/FinanceQueue.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `lib/Application/Workflow/FinanceQueue.php` is present. Existence only; the shared-queue assertions are T185's, and T291's actor gate on `POST /accounts/{id}/charges` remains open)
- [x] T191 [US3] Add finance/account routes/controllers per T063 in `appinfo/routes.php`, `lib/Controller/ReviewController.php`, `lib/Controller/AccountController.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `lib/Controller/AccountController.php`, `lib/Controller/ReviewController.php` and their `appinfo/routes.php` entries are present. Existence only)
- [x] T192 [P] [US3] Implement `accountsApi.ts` and `stores/accounts.ts` finance/account typed API services and stores per T064 in `src/services/` and `src/stores/` (verified 2026-10-01 — the `(missing)` marker was wrong: `src/stores/accounts.ts` is present. This line previously named the path `stores/accounts.ts` without the `src/` prefix, which was a path-spelling defect rather than an absent deliverable; `accountsApi.ts` is present alongside it. Existence only)

### Phase 6 Completion — US4 Correction (Priority: P1)

- [x] T195 [US4] Mark T067 complete - `TransactionRevisionTest.php` exists with 11 passing tests in `tests/Unit/Domain/Expense/TransactionRevisionTest.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `tests/Unit/Domain/Expense/TransactionRevisionTest.php` exists and `scripts/dev/phpunit tests/Unit/Domain/Expense/TransactionRevisionTest.php` reports **OK (11 tests, 25 assertions)**, confirming the 11 this line already claimed)
- [x] T196 [US4] Mark T068 complete - `TransactionCorrectionTest.php` exists with 9 passing tests in (count corrected by T298: the file grew after this note was written) `tests/Integration/Workflow/TransactionCorrectionTest.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `tests/Integration/Workflow/TransactionCorrectionTest.php` exists and `scripts/dev/phpunit tests/Integration/Workflow/TransactionCorrectionTest.php` reports **OK (7 tests, 24 assertions)**)
- [x] T197 [P] [US4] Create `CorrectionControllerTest.php` and `TransactionCorrection.spec.ts` contract/Vue tests per T069 in `tests/Contract/Controller/` and `tests/Frontend/Component/` (verified 2026-09-30 — the `(missing)` marker was wrong: `CorrectionControllerTest.php` (12, green in the 875-test run) and `TransactionCorrection.spec.ts` (14, green) both exist
- [x] T198 [US4] Implement `CreateCorrectionDraft.php` correction-draft creation, owner-only editing, immutable prior revision cloning, supersedes linkage per T070 in `lib/Application/Expense/CreateCorrectionDraft.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `lib/Application/Expense/CreateCorrectionDraft.php` is present. Existence only)
- [x] T199 [US4] Implement `ResubmitCorrection.php` atomic correction validation, active-revision activation, manager snapshots, cost-center rerouting, state projection, audit per T071 in `lib/Application/Workflow/ResubmitCorrection.php` (verified 2026-10-01 — the `(missing)` marker was wrong: `lib/Application/Workflow/ResubmitCorrection.php` is present. Existence only)
- [x] T200 [US4] Update `EvidenceService.php` for correction evidence: retain originating revision links, post-submit deactivation only per T072 in `lib/Application/Evidence/EvidenceService.php` (verified 2026-09-30 — the `(partial)` marker was wrong: both clauses delivered and proven by T072's 8 contract + 4 unit tests, green in the 875-test run)
- [x] T201 [US4] Add correction/resubmit/revision-history routes/controllers per T073 in `appinfo/routes.php`, `lib/Controller/TransactionController.php`, `lib/Controller/ReviewController.php` (verified 2026-09-30 — the `(missing)` marker was wrong: same deliverable as T073, verified there)

### Phase 7-11 Implementation (Priorities: P2/P3)

**Umbrella tasks T205-T209 were removed here** as duplicate ownership. Each named a task range
(`T077-T089`, `T090-T100`, `T101-T114`, `T115-T123`, `T124-T139`) that the canonical per-task lines
in Phase 7, Phase 8, Phase 9, Phase 10 and Phase 11 already own individually, and the Phase 12
umbrellas `T150-T157` named the same ranges a second time. Three owners for one deliverable meant
`/speckit-implement`, which works the first genuinely incomplete task, could select a range task and
report already-scoped work as undelivered. **The canonical owners are unchanged**: Phase 7
(`T077-T089`), Phase 8 (`T090-T100`), Phase 9 (`T101-T114`), Phase 10 (`T115-T123`), Phase 11
(`T124-T139`). Track progress on those lines, not on a range summary.

### Convergence Remediation (Priority: HIGH)

- [x] T210 Fix `tests/bootstrap.php` DI container to provide TransactionManager, PrivateFileStore, and other ports for `SheetSubmissionTest.php` and `IdempotencyServiceTest.php` per T144 (verified 2026-09-30 — the `(partial)` marker was wrong: `TransactionManager` at `tests/bootstrap.php:120`, `PrivateFileStore` at `:130`, plus 20 further ports; both named suites green in the 875-test run)
- [x] T211 Extend `AuthorizationService.canReview()` for Correction stage and explicit multi-role self-review logic (role + scope + stage + ownership) per FR-002/FR-008/T026 in `lib/Application/Security/AuthorizationService.php` (partial)
  **The behaviour was already correct; the coverage was not. This is the opposite of what the task text
  implies, and it is the finding.** `canReview()`'s last two branches are **byte-identical**, one
  commented "multi-role self-review: if actor is the owner AND holds the required role + scope, allow"
  and one not. FR-002 *permits* self-review when role, scope and stage permission are independently
  held, so ownership adds no further condition and there is deliberately no ownership check. **That is a
  correct rule.**

  The problem is that two identical branches under a comment claiming a distinction invite the edit that
  adds one — and the direction it naturally goes is the dangerous one: silently forbidding self-review
  for exactly the multi-role user FR-002 names, and emptying every queue for a manager who is not the
  owner. **Nothing could catch that.** Every pre-existing review test either had the reviewer as the
  owner or used the Finance stage, which returns before those branches; there was **no test in which a
  non-owner reviewed at all**. Measured:

  | Mutation | `CanReviewTest` (new) | `AuthorizationServiceTest` (pre-existing) |
  |---|---|---|
  | collapse `canReview()` to owner-only | **killed, 3 reported** | **GREEN** |

  That row is the task. The branches are collapsed into one with the reason stated in the docblock, and
  the 14 new tests make the collapse provable rather than asserted. T211's first clause — the Correction
  stage — also had its branch with **no test at all**; it is now pinned in both directions, including
  that a manager with the right role *and* scope still cannot correct somebody else's record, which
  asserts the branch's ordering as much as its rule.

  Mutation-proven four times: collapse to owner-only (killed, 3), the correction stage accepting
  anybody (killed, 3), the finance stage falling through to the queue rule (killed, 1), and the scope
  check dropped (killed, 2). **No behaviour changed** — "T211 delivered" should mean "T211 is now
  verifiable", not "T211 was broken".

  **Recorded alongside it, not fixed here:** `canView()` still returns true for any actor holding any
  role, regardless of ownership or scope, which is a broader reading of visibility than FR-003's
  wording suggests. That is not T211's subject — T211 names `canReview()` only — but it was read while
  looking, and it deserves its own task rather than a footnote.

- [x] T212 Align `TransactionService.updateTransaction()` with domain model: use costCenterId/expenseTypeId as UUIDs, snapshot code/name per data-model.md/T037 in `lib/Application/Expense/TransactionService.php` (partial)
  **Two silent data corruptions, both measured rather than inferred.** The caller supplies a **public
  id** for both references, and the full-edit branch said so — `findByPublicId($expenseTypeTitle)`. The
  parameter was nevertheless *named* `$expenseTypeTitle`, and the partial-edit branch then used the
  name. `TransactionRevision::complete()`'s sixth parameter is `string $expenseTypeTitle`, assigned
  straight to `$this->expenseTypeTitle`. So:

  1. **A UUID stored as a title.** A `PATCH` supplying a cost centre and an expense type but omitting
     the description took the partial branch — gated on *all three* being present — and wrote the
     caller's UUID into the expense-type-title column. Measured: expected `'Original Expense Type'`,
     got `88888888-8888-4888-8888-888888888888`.
  2. **A new id with the previous centre's snapshot.** The partial branch took the caller's *new*
     cost-centre id while reusing the *current* revision's `costCenterCode`/`costCenterName`. Measured:
     `'CC-200'` expected, `'CC-100'` stored — a revision claiming CC-200 by id and CC-100 by name.

  Both are worse than the failure they replaced: the finance queue reads these columns and neither value
  looks wrong. FR-037 requires code and name to be **snapshotted**, and a snapshot of a different
  record than the one referenced is not a snapshot. Each reference is now resolved independently and
  snapshotted from the record it resolves, and the parameter is `$expenseTypeId`, matching
  `TransactionController` and the contract.

  7 tests / 29 assertions in `tests/Integration/Expense/TransactionServiceUpdateTest.php`, driving the
  service directly with the in-memory doubles so both branches are comparable on the same inputs.
  Mutation-proven four times: the UUID-into-title bug restored (killed, 2), the cost-centre code
  snapshot taken from the old revision (killed, 2), a partial edit erasing the omitted expense type
  (killed, 1), and the unknown-id refusal removed (killed, 1).

  **Stated rather than glossed.** The tests are unit-level, so they isolate *which* branch stored which
  value — the defect's home — but they do not prove what the endpoint does. A contract test for
  `PATCH /transactions/{id}` with a cost centre and an expense type but no description would pin it end
  to end and needs a fixture whose draft revision is already complete; it is not written.

- [x] T213 Complete audit event insertion for all workflow transitions (decisions, returns, corrections, evidence deactivation) in respective services per FR-051/FR-052/T028 (complete; fixed 2 silent audit-snapshot losses, derived the event-type vocabulary from FR-051 into `AuditEventType` and split the decision type into APPROVED/REJECTED/RETURNED, added the 4 missing writers ROUTED/REVISION_CREATED/TRANSACTION_CHANGED/EVIDENCE_ADDED, added `Version010400` for nullable `command_id`; found-but-unfixed: editing a draft with no description/expense type throws a TypeError — see `docs/reports/T213.md`, `docs/decisions/0001-*.md`)
- [x] T214 Apply `IdempotencyService` to all state-changing command handlers (management decisions, finance settlement, correction resubmit, account charges) per FR-042/T027 (complete; **duplicate of T149, which delivered all four named handlers** — verified rather than assumed: each wraps its whole closure in `idempotencyService->execute()` with a hashed payload for conflict detection, and both replay paths are correct: `ManagementReviewService`/`FinanceSettlementService` read the decision back by command id, `SubmitSheet` re-reads the sheet by public id after the block, `ResubmitCorrection` returns the stored result. `ACCOUNT_CHARGED` at FinanceSettlementService:287 is inside the closure (115→334), so a replayed settlement cannot double-charge. **The `(partial)` marker was stale.** **One real gap is NOT in this task's named scope and is recorded as T317 rather than silently absorbed here** — see `docs/reports/T214.md`)
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
- [x] T230 [CRITICAL] Give `TransactionRevision` the identity of its owning transaction and use it to resolve the real `pc_transaction_revisions.transaction_id` join key: `DbTransactionRevisionRepository::getTransactionDbId()` at `lib/Infrastructure/Persistence/Expense/DbTransactionRevisionRepository.php:120-125` currently returns a hardcoded `0` (comment: "For now, return 0 as placeholder") and is called with a *revision* id, while `TransactionRevision` carries no transaction reference in its constructor; because `transaction_id` is `BIGINT` NOT NULL per `data-model.md`, every persisted revision is orphaned from `pc_transactions`, so the active revision can never be resolved and FR-048/FR-050 cannot hold (verified 2026-10-01 — the `(missing)` marker was wrong: `lib/Infrastructure/Persistence/Expense/DbTransactionRevisionRepository.php` is present and `getTransactionDbId()` no longer appears in it, so the hardcoded-`0` placeholder this line quoted is gone. Existence and symbol-removal only; the FR-048/FR-050 consequence is evidenced by the revision-persistence tests, not by this line)

---

## Phase 17: Convergence (Unresolved Session Routes and Decision Join Integrity)

**Purpose**: Close the last two gaps found by auditing route resolution and workflow
decision persistence after the Phase 15/16 controller and revision work.

### HIGH

- [x] T231 [HIGH] Add `lib/Controller/SessionController.php` implementing `me()` and `dashboard()` so the routes already registered in `appinfo/routes.php:11-12` (`session#me` → `GET /api/v1/me`, `session#dashboard` → `GET /api/v1/dashboard`) resolve; both endpoints are specified in `contracts/ocs-api.yaml` (`/me` returns "the current user and effective capabilities", `/dashboard` returns role-scoped cards) and both currently return HTTP 500 `Could not resolve OCA\PettyCash\Controller\SessionController`, leaving the frontend with no way to learn the caller's effective capabilities per FR-001, FR-009, and FR-061; the response must go through `OcsErrorMapper` and return a `SessionContext` compatible with `src/stores/auth.ts` and `src/types/permissions.ts` (verified 2026-10-01 — the `(missing)` marker was wrong: `lib/Controller/SessionController.php`, `src/stores/auth.ts` and `src/types/permissions.ts` are present. Existence only; T214's session-route findings and the unresolved `#/session` route question are unchanged)
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

- [x] T235 [MEDIUM] Remove the unrouted duplicate controllers `lib/Controller/QueueController.php` and `lib/Controller/ReturnsController.php`, or route `/api/v1/queues/{stage}` and `/api/v1/returns` to them and delete the corresponding `ReviewController::index()` / `ReviewController::returns()` copies; neither class is referenced by any entry in `appinfo/routes.php` (`grep -c` returns 0 for both) and each duplicates a routed `ReviewController` method, so two divergent implementations of the same queue and returns logic exist and a future fix can land in only one of them, which is unrequested work that no `spec.md`, `plan.md`, or task calls for (unrequested)

  **Closed as already delivered by T344, on the deletion branch rather than the routing branch.**
  Both files are gone (`lib/Controller/` holds the eleven remaining controllers and neither
  `QueueController` nor `ReturnsController`), and the routed twins are intact:
  `appinfo/routes.php:39-40` serves `review#index` for `/api/v1/queues/{stage}` and `review#returns`
  for `/api/v1/returns`, and `ReviewController::index()` (`:47`) and `ReviewController::returns()`
  (`:89`) both exist. So the outcome this task asked for is the one that happened, and no code
  change was owed.

  **The decision T235 deferred is now recorded in the suite rather than left to memory, which is
  why it can be closed instead of re-litigated.** `RouteResolutionTest`'s unrouted-controller
  assertion was inverted by T344 from an expected named pair to an expected **empty list** — so a
  *fourth* unrouted controller now fails it, which the previous `['QueueController',
  'ReturnsController']` expectation could not do, since it only knew those two names.
  `RouteDispatchCompletenessTest::testEveryControllerActionIsReachableFromARoute()` asserts the
  complementary direction (an action no route reaches is dead code) and is deliberately symmetric
  and empty, because unlike a routed-but-not-yet-consumed action, nothing in the application can
  ever call an unrouted one.

  Verified: `RouteResolutionTest` 10 tests / 52 assertions OK; `RouteDispatchCompletenessTest` 4
  tests / 8 assertions OK.

  **Left alone deliberately**: `TransactionController::decide()` was the third unrouted action and
  T344 removed it, so nothing of T235's scope remains open. No controller was routed, because
  routing a duplicate would have invented a second endpoint for a URL the contract already binds to
  `review#*` — the outcome this task's own framing named as a maintainer's call, now made.

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

- [x] T238 [P] [LOW] Reconcile the `plan.md` Project Structure with the repository for the **three** declared directories that do not exist and that no task owns: `lib/Application/Dto/`, `src/formatters/`, and `tests/Frontend/Unit/`; either create them with the content the plan assigns, or amend the plan's structure to match where the work actually lands (T013 places authorization tests in `tests/Unit/Security/`, T014 places idempotency tests in `tests/Integration/Workflow/`, and T115/T116 place shell and RTL tests in `tests/Frontend/Component/`), so the approved plan and the tree stop disagreeing about the module layout (unrequested)

  **The premise was re-verified on 2026-10-01 and one of the four has since been resolved, so this task's
  remediation choice must be re-made against three paths rather than four.** `tests/Unit/Application/`
  now exists; `lib/Application/Dto/`, `src/formatters/` and `tests/Frontend/Unit/` do not. The
  remediation therefore splits cleanly: the three remaining are a plan-versus-tree decision, while
  `tests/Unit/Application/` needs its `plan.md` entry confirmed as landed rather than amended. Recorded
  because a decision about all four would have been taken on a wrong premise.

  ---

  **Delivered by amending the plan, on the branch the task named second — and the count was wrong
  a second time, so it was re-verified before the decision rather than inherited.**

  **There were four unowned missing directories, not three.** Re-checked against the tree at the
  start of this task: `lib/Application/Dto/`, `src/formatters/` and `tests/Frontend/Unit/` as
  recorded, **plus `lib/Application/Query/`**, which no earlier note mentions. It was declared at
  `plan.md:215` and never existed either — read use cases live in feature directories
  (`lib/Application/Reporting/ReportQuery.php`, `lib/Application/Account/AccountService.php`), and no
  class anywhere carries an `OCA\PettyCash\Application\Query` namespace. Acting on the recorded
  count would have left the fourth in place and the plan still disagreeing, which is the outcome
  this task exists to prevent.

  **Amending rather than creating, because nothing needs a directory to exist.** The choice is not
  arbitrary: creating an empty declared directory satisfies the plan's letter and none of its
  purpose, and it makes the drift *worse* — a plan-versus-tree disagreement plus an empty directory
  is two things to reconcile, and the next author cannot tell an intentional home from a leftover.
  Every one of the four had already been placed, deliberately and with a documented reason:

  | Declared but absent | Where the work actually landed, and why that is the right home |
  |---|---|
  | `lib/Application/Dto/` | `lib/Application/Account/AccountPayloadFormatter.php` beside `AccountService`, and `lib/Infrastructure/Http/*PayloadFormatter.php` for the streamed shapes. Co-locating each shape with its only consumer is what stops the second copy that `TransactionPayloadFormatter` documents. |
  | `lib/Application/Query/` | Feature directories — `Reporting/`, `Account/`, `Evidence/`, `Workflow/`. A separate `Query/` directory would split each use case away from the command it shares a feature with. |
  | `src/formatters/` | `src/utils/money.ts` (`formatMoney`, plus `selectOption.ts`, `dashboard.ts`). Formatting is presentation of a value, not a feature area, and it is shared by ten views. |
  | `tests/Frontend/Unit/` | `tests/Frontend/Component/`, with module-level specs (`http.spec.ts`, `routeNameIntegrity.spec.ts`, `typography.spec.ts`) directly in `tests/Frontend/`. The frontend specs test behaviour through mounted components, so a separate `Unit/` tier held nothing that was not a component test. |

  **The plan was also brought up to date in the same pass, because a partial amendment would leave a
  worse artifact than the original.** Ten further entries were missing: `lib/Application/{Account,
  Audit, Configuration, DataIntegrity, Evidence, Expense, Reporting, Security, Workflow}/`,
  `lib/Domain/Audit/` and `lib/Domain/Evidence/`, `src/utils/`, `tests/Unit/{Architecture, Audit,
  Evidence, Infrastructure, Reporting, Workflow}/`, `tests/Integration/{Account, Audit, Expense}/`,
  `tests/Contract/Fixture/`, `tests/Support/`, `tests/TestDoubles/`. Those all exist and were
  undeclared, which is the same disagreement pointing the other way — a plan that lists less than
  the tree is as misleading to a reader as one that lists more.

  **The two genuine pending entries are kept and labelled rather than quietly removed.**
  `src/composables/` is T121's (`useAsyncAction.ts`) and `l10n/` is T119's (Persian catalogue);
  both are legitimately not built yet, so dropping them would erase work that is owned and in
  progress. `tests/Integration/Storage/` is annotated in the plan as T091's
  (`EvidenceLifecycleTest.php`). These are noted inline in the plan so the tree block itself carries
  the reason.

  **New: `tests/Unit/Architecture/PlanStructureTest.php`, 3 tests / 8 assertions, mutation-verified
  in four directions.** The amendment would itself rot — a plan amended once is not evidence that it
  stays true, which is the same lesson as `RouteResolutionTest` (T272) and
  `RouteDispatchCompletenessTest` (T326/T344). The test parses the box-drawing tree under
  "### Source Code (repository root)" and asserts every declared path resolves.

  **The pending list is an allowlist that names its own task IDs and is asserted in both
  directions**, which is the part that makes it safe rather than a hole:
  `testEveryDeclaredPathExists()` requires a `PENDING` entry to carry a task ID in its failure
  message, and `testPendingAllowlistHasNoStaleEntry()` fails when an entry's path *does* exist — so
  an allowance cannot outlive the work that justified it and quietly stop checking anything. When
  T091, T119 and T121 land, `PENDING` is empty and the allowlist goes away. `js/` and `css/` are
  excluded as generated output and checked separately by `testBuildOutputExists()`, which reports a
  missing bundle rather than failing a fresh checkout for it.

  **Parsing detail worth recording, because two wrong parsers looked right first.** The block nests
  by indentation, so a bare name is not a path: `Domain/` under `lib/` is `lib/Domain/` and the same
  word under `tests/Unit/` is `tests/Unit/Domain/`. Depth therefore comes from the `│   ` prefix
  groups, tracked on a stack. Two further traps: the container roots (`appinfo/`, `lib/`, `src/`,
  `tests/`) are written flush left with no marker while their children start at depth 0, so the root
  has to occupy a level of its own or `├── info.xml` resolves to a bare `info.xml` and the container
  is never checked at all; and `│` is three bytes in UTF-8, so counting bytes rather than prefix
  groups computes the wrong depth. All three were caught by running the test, not by reading it.

  Mutation-verified by breaking the plan four ways and confirming a red test naming the path:
  renaming `lib/Application/Security/` → `SecurityGhost/`, renaming `tests/Unit/Domain/` →
  `DomainGhost/` (proving the nesting is resolved, not just the leaf), and removing the
  `tests/Integration/Storage` → T091 allowlist entry. `testPendingAllowlistHasNoStaleEntry()` was
  verified by adding an entry for a path that exists.

  `scripts/dev/phpunit --testsuite unit` is **654 tests / 1475 assertions, 0 failures** and
  `--testsuite integration` is **301 tests / 1243 assertions, 0 failures** with this test in place.

  **The stale justification in `AccountPayloadFormatter` was corrected, because leaving it would have
  re-created the hesitation this task resolves.** Its header said the class sits beside
  `AccountService` "rather than in a new `Dto/` directory because `plan.md`'s declared structure
  reconciliation is T238's open work, and creating a fourth declared-but-missing directory here would
  quietly take a piece of it." That is now false — T238 is closed and the plan declares
  `lib/Application/Account/`. Reworded to state the rule that actually holds: nothing may be placed
  on the strength of the plan naming a directory, because this task is the proof that a named
  directory need not exist.

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

- [x] T250 [HIGH] Fix `scripts/preflight.sh` so the database-inspection step at
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

- [ ] T251 [HIGH] Add a forward-only migration that brings `pettycash_command_results` in line with the
  `Command Result` entity in `data-model.md`, which requires `command_id`, `actor_uid`,
  `operation`, `target_public_id`, `payload_hash`, `result_status`, `result_json`, and
  `created_at`, together with the replay rule "Same command ID, actor, operation, target, and
  payload returns the original result. Reusing the command ID with different content is a
  conflict." `lib/Migration/Version010200Date20260920000000.php:35-39` creates `pettycash_command_results` with only `id`,
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
  **Stale table name corrected 2026-09-30.** This task previously read `pc_command_results` and quoted
  `Version010200Date20260920000000.php:35-39` as creating that table. Since T260/T261 rewrote the baseline
  into the app-scoped namespace the table is **`pettycash_command_results`**, and the migration, repository
  and service line references here were re-verified against the current files and remain accurate: the
  migration's `createTable('pettycash_command_results')` and its unique index
  `pettycash_command_results_command_id_uniq` are at `:35-39`,
  `DbCommandResultRepository::save()` inserts `command_id`/`payload_hash`/`response_json`/`created_at` at
  `:46-53` and reads them at `:57-64`, and `IdempotencyService.php:16-19` compares only `payload_hash`.
  **The defect itself is unchanged and still real** — no `actor_uid`, `operation`, `target_public_id` or
  `result_status` column exists, so a command ID replayed by a different actor, operation or target is not
  detected as a conflict, returns the first caller's stored response, and silently skips the second
  caller's operation. Only the identifier in this task's prose was wrong, not the finding.

### MEDIUM — the approved legacy-table premise does not match this environment

- [x] T252 [MEDIUM] Reconcile the legacy-table premise in the approved artifacts with the tables
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
  forbid changing historical data in place (**superseded, closed without implementation, by this
  remediation pass — three tasks supersede it, and each says so in its own text: T260 "supersedes
  T252" (FR-078/FR-079), T261 "supersedes T252" (FR-081), and T263 "supersedes T126, T252"
  (FR-080/SC-014)**). The substance is already resolved rather than merely restated: T260 rewrote
  the V1 baseline migrations into the `pettycash_*` namespace, so the 15 V1 `pc_*` tables this task
  worried about **no longer exist**; T261 rewrote every repository, fixture and assertion to match;
  and T263 owns removing the `oc_pcash_*`/`oc_pc_*` premise from `quickstart.md` and the migration
  test suite. T250 already put the disagreement under a standing warning rather than silently
  resolving it — `scripts/preflight.sh` prints `preflight: WARNING - spec.md:333 declares the
  legacy tables are oc_pcash_* and none exists, while oc_pc_* tables do; T252 owns the
  reconciliation` — so the discrepancy stays visible to whoever executes T263. **The residual
  artifact-text defect is NOT closed by this box.** `spec.md:333`, `plan.md:19`, `data-model.md:18`,
  `research.md:61`/`:315` and `quickstart.md:42` still name `oc_pcash_*`, and this remediation pass
  is forbidden from editing `spec.md` or `plan.md`. T263's own wording — "Replace the
  legacy-database acceptance framing in `quickstart.md` section 2, the T126 migration scenarios, and
  the migration test suite" — does not by itself cover `plan.md`, `data-model.md` or
  `research.md`, so **whether T263 fully discharges this task's artifact-text scope is unresolved and
  must be re-checked when T263 is worked.** This closure records supersession only: no artifact was
  edited by it, and the `[x]` asserts that the task has no owner other than its three supersessors,
  not that the artifact text is now correct)

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
  present in this environment (FR-080, SC-014, supersedes T126, T252).
  **Scope extended 2026-09-30 to include `spec.md`, which the original wording did not cover.**
  Closing T252 as superseded by T260/T261/T263 left a residue: `plan.md`, `data-model.md` and
  `research.md` are now clean of `oc_pcash_*` (0 occurrences, rewritten by T260/T261), but **`spec.md`
  still asserts it twice** — the 2026-09-25 Clarifications entry at `spec.md:37` ("residual `oc_pcash_*`
  prototype tables that remain in the shared development database") and the Edge Case at
  `spec.md:343` ("Discovering residual prototype tables such as `oc_pcash_*`..."). Both are *decision
  records* rather than schema declarations, so they are not false in the way the original defect was:
  the premise "residual `oc_pcash_*` tables remain" is what never held, because the `oc_pcash_%` prefix
  matches **0** rows while the real legacy tables are `oc_pc_*`. Reconciling them means restating them
  against the measured reality (`oc_pc_*`, 11 tables) while preserving the actual decision they carry —
  that residual prototype tables are unmanaged artifacts V1 MUST NOT read, write, migrate, rename or
  drop, and that their presence is not a V1 defect. `quickstart.md`'s two occurrences remain owned
  here as originally written. **Until this task covers `spec.md`, those two references are owned by no
  task at all.**
- [x] T264 [MEDIUM] Re-check every index and constraint name produced by T260 against the declared
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
  endpoint through `src/services/accountsApi.ts` and `src/stores/accounts.ts`, because
  `src/services/` contained only `evidenceApi.ts`,
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
  **Stale premises corrected 2026-09-30; the checkbox is not reopened by this note.** Two claims in the text
  above describe a repository state that no longer holds. `src/services/accountsApi.ts` and
  `src/stores/accounts.ts` **now exist** (delivered by T192 and closed by T274 on evidence), and
  `src/services/` now holds ten modules — the original six plus `accountsApi`, `configurationApi`,
  `dashboardApi`, `reportsApi` — so "both of which must be created, since they do not exist" is false and
  is struck rather than left to mislead. The hardcoded `acc-1`/`acc-2` list that this task removed was real,
  and the fix is delivered; the `[x]` stands.

  **Separately, Phase 30's ordering-defect note is now stale on its own premise and should not be read as
  reopening this task.** It recorded that `lib/Controller/AccountController.php` "does not exist" and that
  `T087` therefore had to execute first. `AccountController` now exists and declares **all four** actions it
  was said to lack — `index`, `create`, `charge` and `movements` — so the `account#*` half of that note is
  superseded by T087's partial delivery. Whether `account#index` returns *real data* is a behavioural
  question a ledger pass cannot settle by reading files, and it is deliberately **not** claimed here in
  either direction. What remains genuinely open and is owned elsewhere: `lib/Controller/ConfigurationController.php`
  **still does not exist**, so the 11 `configuration#*` routes remain unresolved and **T087 stays `[ ]`**
  owning them, and T272's `KNOWN_UNRESOLVED` list must be narrowed as those routes land.

### HIGH — currency fraction digits are business rules hardcoded in two disagreeing controllers

- [x] T266 [HIGH] Remove the presentation-layer currency tables
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

- [x] T267 [HIGH] Remove the silent currency fallbacks that mask a broken reference:
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
  only the revision repository's hardcoded `IRR` and does not cover these two sites (delivered)

  **The view half was already done and the checkbox did not say so.** `formatMoney` is now a shared
  utility in `src/utils/money`; `grep -rn "?? 'IRR'" src/` returns nothing. What remained was the
  repository, and T311 had just built the fixture that makes it observable and a test that
  deliberately declined to assert it — "a test that pinned `"IRR"` as the expected answer would
  enshrine a defect as a requirement and would have to be deleted when T267 removes it." The
  precondition was met and the omission had an expiry condition.

  **Delivered.** `getCurrencyCode()` now throws `UnreadableRecordData`, the type T308 created for
  this exact fault, so this is one rule rather than two. Measured on the live endpoint with the
  probe present, before and after:

  | Endpoint | Before | After |
  |---|---|---|
  | `GET /reports/sheets` | `200`, probe reported `"currency":"IRR"` | **`422`** |
  | `GET /sheets` | `200`, probe in the list as `"currency":"IRR"` | **`500`** |
  | `GET /sheets/{probe}` | `200`, the corrupt sheet as a **rial sheet** | **`500`** |
  | `GET /sheets/{healthy}` | `200` | `200`, unaffected |
  | `GET /dashboard` | `200` | **`500`** |
  | `GET /reports/{transactions,process,evidence}` | `422` | `422` |
  | `GET /reports/audit`, `GET /queues/cost-center` | `200` | `200` |

  **Two endpoints the task does not name were doing the same thing.** `GET /sheets` and
  `GET /sheets/{id}` read the same repository and reported the same fabrication, and they are the
  two a user is most likely to open. `GET /dashboard` also hydrates sheets and had been answering
  `200` with no test covering it at all, so the substitution was hiding in a fourth place.

  **The 500 is T308's decision, not a new one.** `UnreadableRecordData` extends `LogicException` on
  purpose precisely so this happens: the report explains itself with a 422, every other caller keeps
  the classification `OcsErrorMapper` already gives an unrecognised fault — 500, message withheld,
  full detail logged. The trade is a wrong number that looks measured against a visible failure with
  the operator-actionable detail in the log. Generalising the 422 policy to the five affected
  endpoints is deliberately **not** done here: it changes their status, and a test pinning 500 would
  have to be deleted to allow it, so the new tests assert the *absence of the wrong answer* and
  enumerate the honest statuses instead.

  **One existing test had to be inverted rather than weakened.**
  `testTheSubjectsThatDoNotResolveTheCurrencyAreUnaffected` asserted in as many words that
  `GET /reports/sheets` must **not** be refused by this fault — written to pin reachability, not
  correctness, and correct about what it measured while wrong about what the measurement should be.
  `sheets` moves into `CURRENCY_READING_SUBJECTS`, `audit` into a new `CURRENCY_FREE_SUBJECTS`. The
  leak assertions were also made **wording-independent**: they checked for the literal string
  `Cannot resolve the currency of transaction revision`, so the sheet repository's differently-worded
  message would have slipped past them — an assertion that was true and proving nothing. They now
  check the surrogate keys by value and add `danglingCurrencyId` to the set.

  **An assertion of my own was too weak, and the mutation that proved it.** The first version asserted
  `assertNotSame('IRR', $entry['currency'])`, which passes against an implementation that substitutes
  **`USD`** — a different wrong number, in a different unit, from the same line of code. The record
  is entitled to no currency at all, so the strong form is: *a `200` may not carry this record*. That
  is what is asserted now, and it is mutation-proven against the `'USD'` substitution.

  Mutation-proven four times: the `'IRR'` fallback restored (**4 fail**), a *different* wrong currency
  substituted (**4 fail**), the throw swallowed (**10 error**), and `/reports/sheets` no longer
  translating to a 422 (**4 fail**).

  **Stated rather than glossed.** A genuinely dangling `currency_id` cannot be produced by the
  application — the schema declares no foreign keys and the probe is the only way to make one — so
  the fixture reproduces the *shape* of the fault, not an operator action that causes it; the
  reachable non-harness case is a currency row deleted or deactivated out from under a sheet. The
  admin path is not covered either: the contract suite holds one set of non-administrator
  credentials, so the `findByStatus(...)` branches are exercised only by reasoning, not by
  measurement. `/dashboard`'s new 500 is in the table because it was measured, not because a test
  pins it; writing that test is the natural first half of the follow-on above.

  741 tests / 3345 assertions / 1 failure, against 736 / 3318 / 1 at the baseline: the single failure
  is T275, unchanged. `npm run check` clean, 0 lint errors, 7/7 frontend suites, and
  `roundtrip-leftovers.php` reports `No leftovers.`

  **Delivered.** `lib/Domain/Command/Repository/CommandResultRepository.php` is deleted, along with the
  now-empty `lib/Domain/Command/` tree; `DbCommandResultRepository` implements one port instead of two;
  and `tests/bootstrap.php`'s import and container registration are gone. No production code ever
  consumed the domain copy — `grep -rn "DomainCommandResultRepository" lib/ tests/ src/` returned
  three hits, all of them the adapter's own `implements` clause and the test alias. No behaviour
  changed: no route, no response, no stored record.

  **The deliverable is the property, not the deletion.** A two-line deletion has no test, and nothing
  about deleting an unused interface can fail -- which is the problem, because the file was there
  because nothing watched it. `tests/Unit/Architecture/LayerBoundaryTest.php` now scans `lib/Domain/`
  and fails on any `use OCA\PettyCash\{Application,Infrastructure}\...`, and asserts
  `CommandResultRepository` is declared exactly once. Both failed before this task; both will fail
  again if a duplicate reappears anywhere, which is a thing no reviewer has to notice by reading two
  files side by side.

  **The RED state found two bugs in my own test, and neither would have shown up green.** Every
  violation message read `tests/Unit/Architecture/../../../lib/Domain/...` -- true, and useless to
  anyone who has to open the file. And the sanity check that exists to prove the scan is not vacuous
  was comparing a repo-relative anchor against the iterator's absolute paths, so it was failing against
  a perfectly good scan. That is the worst direction for that particular test to be wrong in.

  **The layer order is itself asserted.** Every comparison is `array_search` over `LAYER_ORDER`, so a
  table with `Domain` last would make the rule "nothing is above Domain" and the scan would pass over a
  real violation in silence. Mutation-proven: reordering it fails. So is re-introducing the port
  (1 fail), and so is pointing the scan at a directory that does not exist (3 fail) -- the last proving
  the sanity check has teeth rather than being decorative.

  **Deliberately not asserted:** the other three layer edges. `lib/Application/` imports `OCA\Server`
  and other Nextcloud services in places, and where that line falls is a design question rather than a
  scan's question. The scan also reads declared imports rather than the autoloader, so a class reached
  by a fully-qualified inline reference is invisible to it -- a limitation of the approach, stated
  rather than hidden.

  745 tests / 3351 assertions / 1 failure, against 741 / 3345 / 1: the failure is T275, unchanged.
  `npm run check` clean, 0 lint errors, 7/7 frontend suites. Report: `docs/reports/T268.md`.

### MEDIUM — a duplicated port makes the domain layer depend on the application layer

- [x] T268 [MEDIUM] Delete the unused duplicate port
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

**T273 was removed here, merged into T134.** It and T134 both produced
`specs/001-pettycash-v1-core/checklists/traceability.md` and differed only in the requirement range
covered, so the artifact had two owners and two places to be marked done. T134's scope is now
widened to **FR-001–FR-081 and SC-001–SC-014**, which is what T273 asked for, and T134's note carries
T273's reasoning verbatim. **T134 is the single owner of the traceability matrix.**

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

- [x] T275 [MEDIUM] **Owns the FR-060 amendment to the `contracts/ocs-api.yaml` source** (recorded
  2026-09-30, alongside T312's FR-068 amendment and T124's type regeneration). Reconcile the evidence
  streaming response before `contracts/ocs-api.yaml` is treated as verified, because the approved
  contract and the functional requirement disagree about the same response. (complete)

  **Delivered — and it was not a document-only change. The entry framed the decision as the
  maintainer's, but FR-060's 2026-09-30 clarification had already made it, and the code was wrong on
  the half FR-060 cares most about.**

  ---

  **The "either/or" is settled, and not by preference.** The entry offered "either the contract is
  amended … or FR-060 is clarified to mean download-only and the implementation is corrected".
  `spec.md`'s clarification settles it in a sentence: "The contract is therefore **the artifact that
  was wrong** and MUST be amended to match this requirement; T275 owns that change **and owns naming
  the concrete inline-safe media-type list**." The contract's own contradiction is the proof — it
  declared `application/octet-stream` as the only success type *while declaring a `disposition`
  parameter defaulting to `inline`, a value that cannot render an octet-stream body.*

  **The code was also wrong, on the half that is a security property, and nobody had noticed.**
  `EvidenceController::content()` sent `'Content-Type' => $evidence->mediaType` with the disposition
  taken from a **caller-supplied query parameter**. But the stored type is attacker-supplied by
  construction: `upload()` keeps `$file['mediaType']` verbatim, because FR-030 defers classification
  and redaction to V2. So a purchaser could store a file declaring `text/html` and a reviewer opening
  the receipt would be served `text/html;charset=UTF-8` **inline** — script executing in the Nextcloud
  origin, which is the harm FR-060 names in its own words. Measured, not asserted: with the policy
  removed the response header reads `text/html;charset=UTF-8` (see the mutation below).

  **A caller-chosen disposition is FR-060's requirement, not a defect — and the policy keeps it.**
  FR-060 says "A caller MUST be able to request either disposition explicitly", so `?disposition` is
  honoured on the disposition and **ignored on the content type**. `?disposition=attachment` on a PDF
  is a download; `?disposition=inline` on a PDF is a preview; and neither can make an unlisted type
  render.

  **The inline-safe list, which this task owns naming, is seven types — and the omission that matters
  is `image/svg+xml`.** FR-060 says "images, PDF, and plain text", so the list is
  `application/pdf`, `image/{png,jpeg,gif,webp,bmp}` and `text/plain`. SVG is the type most likely to
  be added later by someone reasoning "SVG is an image, FR-060 says images render": it *is* an image
  and it can carry `<script>`, so including it would satisfy a literal reading while reintroducing
  precisely the harm the requirement was written to prevent. It is pinned absent on its own test,
  because that is the decision most likely to be silently reversed.

  **Deny-by-default, and the default is the side that has to fail.** Anything not on the list is served
  as `application/octet-stream` + `attachment` — an unknown vendor type, a type with no slash, an empty
  string. Media-type parameters are stripped for matching and the stored string is returned verbatim,
  because `text/plain; charset=utf-8` is still plain text and the browser needs the charset.

  **The rule is a named object, `EvidenceContentPolicy`, in `Infrastructure\Http`.** That is a
  deliberate consequence of this session's environment: the whole rule is two headers whose *pair*
  only means something together, and asserting it in-process needs no HTTP at all — which matters
  while the shared instance is refusing requests with `429`. It also means the contract suite now
  proves the controller *uses* the policy rather than re-deriving the rule per media type.

  **The first mutation attempt did not fail, and that is the most useful thing found here.**
  Reverting `content()` to `'Content-Type' => $evidence->mediaType` left **every** evidence-content
  assertion green — because the contract fixture uploads a `.pdf`, a PDF is inline-safe, and for an
  inline-safe type the policy's output and the stored type are *the same string*. So the whole contract
  suite was blind to whether the policy was applied at all.
  The fix was to give `uploadEvidence()` an optional declared `$mediaType` (the multipart previously
  inferred `application/pdf` from the filename, so a hostile type could not be stored at all) and to
  add `testAnEvidenceDeclaringAScriptableMediaTypeIsNeverServedInline`, which uploads
  `text/html` and asks for `inline`. That test now fails with
  `expected 'application/octet-stream', actual 'text/html;charset=UTF-8'` when the policy is removed —
  the vulnerability, printed as a test failure. A companion test asserts the declared type is **still
  recorded** on the transaction, so "coerce everything to octet-stream" cannot pass by erasing the
  metadata an auditor needs.

  **Contract amended to the closed set the endpoint can actually return.** The `200` response declares
  `application/octet-stream` plus the six inline-safe types, rather than "the stored media type" —
  listing them explicitly keeps it checkable, since a new type cannot be served without either appearing
  in the document or being coerced to the fallback. The `disposition` parameter gained a description
  stating that an out-of-set type is served as `application/octet-stream` + `attachment` regardless of
  the parameter. **The contract document and the test were changed in the same commit**, which is what
  the entry required to stop them drifting again. Verified the YAML still parses and still declares 31
  paths, and that the declared set equals `INLINE_SAFE_MEDIA_TYPES`.

  **`Application.php` was updated, and the trap was checked before it was touched.** `EvidenceController`
  is not hand-registered, so there was no positional list to shift (T367's failure mode) — but
  `EvidenceVisibility`, its nearest sibling, *is* registered, so autowiring could not be assumed. The
  policy is registered explicitly alongside `OcsErrorMapper` and the other `Infrastructure\Http`
  collaborators, with a comment saying why: a dependency that silently fails to resolve shows up as a
  500 on an evidence preview rather than as a wiring error.

  **Verification.** `EvidenceContentPolicyTest` 21 tests / 32 assertions, **no HTTP**.
  `PurchaserFlowControllerTest` + `EvidenceContentPolicyTest` + `EvidenceLifecycleControllerTest` +
  `EvidencePayloadConformanceTest` = **87 tests / 615 assertions, green**. Mutation-verified in the
  direction that matters: removing the policy turns
  `testAnEvidenceDeclaringAScriptableMediaTypeIsNeverServedInline` red and nothing else — which is the
  correct blast radius and is only true because the PDF-path tests were found to be blind.

  **This closes the suite's only persistent failure.** `PurchaserFlowControllerTest::
  testGetEvidenceContentReturns200WithStream` was the single failure in every full-suite baseline this
  session (1245, 1268 and 1271 tests). It now passes, so **no full-suite run in this session has a
  known-failing test.**

  **Not done, and recorded rather than implied.** **No full-suite run was taken** — see T313's note on
  the concurrent-session `429` throttling, which is unresolved and affects any suite-wide number taken
  now. **FR-030's classification is still deferred**, so the stored type remains whatever a client
  declares; this task coerces how it is *served*, not what is *recorded*, which is the boundary FR-060
  draws. **`X-Content-Type-Options: nosniff` is not sent** on `text/plain`: FR-060 does not ask for it
  and adding headers is FR-030's territory. **`ImageMediaType` support is not exercised over HTTP** —
  the inline-safe image types are pinned in the unit test only, since the contract fixture uploads one
  file per assertion and an image adds a fixture without adding a rule. **T124 still waits on T312**,
  so the `src/generated/api.ts` regeneration this amendment enables has not run.
  Cross-references: T124, T312, T259, T143, T030, T072, T276, T094.
### MEDIUM — the draft-delete evidence path unlinks binaries for submitted transactions

- [x] T276 [MEDIUM] Route post-submission evidence removal through deactivation rather than
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
  suite requires to be provisioned. That is a maintainer decision, not an implementation detail.
  **Stale cross-reference corrected 2026-09-30 — this task no longer shares T171's blocker.** It previously
  read "it is the same single-identity limit `T171` and the Playwright chain are blocked on", which was true
  when written and is no longer: `T171`'s credential blocker was cleared by **T322**, which shipped
  `playwright.config.ts`'s `globalSetup` + `storageState`, the `PETTYCASH_E2E_*` credentials, and an
  E2E-owned fixture seeder. **T171 is not blocked and the Playwright chain is not blocked on a credential.**
  What remains here is narrower and is *not* the same limit: the OCS **contract** harness still holds a single
  non-administrator identity, and expressing a second *acting* actor against `POST /accounts/{id}/charges`
  requires the contract harness to authenticate a second identity — a different capability from the browser
  suite's storage-state login, and one no artifact has specified. **Next action is that harness decision, not
  more code, and it is not blocked behind T171.**

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
| F5 | partial | MEDIUM | FR-060, FR-071, FR-072 | **STALE — cleared 2026-09-30 by T322, which shipped the browser credential and an E2E fixture seeder.** The Playwright chain (T171, T053's three browser clauses, T054, T066, T076, T089, T099, T114, T122) **was** blocked on a browser-login credential and no longer is. The `finance` fixture's `FINANCE` role grant and T291's actor-gate gap are the same single-identity limit seen from two directions. | A second acting identity in the harness | T171 and the tasks it names — already open, unchanged |

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

- [x] T294 [CRITICAL] Route configuration audit to its own table so `pettycash_configuration_audit`
  stops being created and permanently empty, and make the configuration audit stream writable and
  readable as a stream of its own. This is finding **F1** in the table above ("Nothing writes
  `pettycash_configuration_audit`", source FR-056/FR-017, gap type `contradicts`), and it was
  **delivered — but this task line itself was missing until this remediation pass.** Convergence
  appended T294 as a task, implemented it, and recorded it everywhere except as a checkbox: it
  existed only as the `**appended as T294**` cell in the F1 row above and as a subject of
  cross-references in T028, T106, T139, T293 and T295/T296. The consequence was that ID `T294` was
  the single gap in an otherwise contiguous `T001`–`T325` sequence, so `/speckit-implement`, which
  works the first genuinely incomplete task, could never select it and no reader could see that the
  CRITICAL fix had already landed. That is a Constitution I traceability failure ("Implementations
  MUST remain traceable to approved tasks") and a Governance §8 failure ("A task is complete only
  when implementation, tests, documentation, and task/spec status agree") — a delivered CRITICAL fix
  with no task record satisfies neither. **The line is inserted after the fact and marked complete on
  the evidence recorded below, which is preserved verbatim and is the substance of the task, not a
  restatement of it.** No code, test, or artifact was changed by inserting this line; the
  implementation is the one already committed as `d0cf959`, and the suite result of 640 tests /
  2840 assertions / no new failures recorded below is that commit's, not a fresh measurement.

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

- [x] T297 [MEDIUM] Reconcile `command_id` nullability between the `AuditEvent` domain type and the
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
  **FR-082** (group exports MUST honour the records the caller selected) and `ExportRequest`, and cover it
  with a test that fails when the selection is ignored. **FR-082 owner, cited 2026-09-30:** FR-082 is the
  rule T302 originally implemented under the duplicate identifier FR-068, split out when `spec.md`
  resolved the collision. T302 and T306 are its canonical owners.
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

- [x] T306 Narrow an export's rows by `ExportRequest.filters`, or refuse a `filters`-only request
  naming what enumeration is not implemented, per **FR-082** (a filter the report's records cannot answer
  MUST be refused, never reported as an empty result). **FR-082 owner, cited 2026-09-30:** T306 delivers the
  refusal half of FR-082; T302 delivers the selection half. T302 made `ids` authoritative and made
  `ExportController::rowsForSelection()` read `filters` -- but only as the boolean "did the caller ask
  for something", so a `filters` object narrows nothing. A request carrying only `filters` is therefore
  refused (correct, since enumerating every sheet is not implemented), and a request carrying `ids`
  *and* `filters` silently ignores the filter half: the caller gets the ids they named, unfiltered,
  which is the same "accepted and discarded" defect T302 closed for `ids`, now reopened for `filters`.
  `ReportFilter` already models the fields involved (`from`, `to`, `statuses`, `currencyCodes`,
  `costCenterDbIds`, `actorUids`, `eventTypes`) and `ReportQuery` already owns a private filter
  implementation, so the domain rule exists and needs exposing rather than rewriting -- and the
  narrowing must be applied there, not in the controller, or there will be two definitions of what a
  filter means. (delivered)

  **Delivered.** The filter now narrows, and everything the row vocabulary cannot answer is refused
  with a 422 naming the facet and the columns the rows do carry. Measured on the live endpoint, before
  and after, for the same requests:

  | `POST /exports` (`fileFormat: csv`) | Before | After |
  |---|---|---|
  | `ids=[sheet]` | `200` rowCount 1 | `200` rowCount 1 |
  | `ids=[sheet]` + `{"statuses":["COST_CENTER"]}` | `200` rowCount 1 | `200` rowCount 1 |
  | `ids=[sheet]` + `{"statuses":["NOT_A_STATUS"]}` | **`200` rowCount 1** | **`200` rowCount 0** |
  | `ids=[sheet]` + a `from` bound after `createdAt` | **`200` rowCount 1** | **`200` rowCount 0** |
  | `ids=[sheet]` + `{"banana":true}` | **`200` rowCount 1** | `422` unknown filter |
  | `ids=[sheet]` + `{"costcentreIds":[1]}` | **`200` rowCount 1** | `422` unknown filter |
  | `ids=[sheet]` + `{"costCenterDbIds":[1]}` | **`200` rowCount 1** | `422` unanswerable facet |
  | `ids=[txn]` + `{"costCenterDbIds":[999999]}` | **`200` rowCount 1** | **`200` rowCount 0** |
  | `ids=[txn]` + `{"amountMinMinor":99999999}` | **`200` rowCount 1** | **`200` rowCount 0** |
  | `filters` only, no `ids` | **`200` rowCount 0, byteSize 0** | `422` |
  | `ids=[unknown]` + a filter | **`200` rowCount 1** | `422` |

  **The task's own description was wrong about the worst case, and measuring found it.** It stated
  that "a request carrying only `filters` is therefore refused". It was not: it answered `200` with
  `rowCount: 0`, `byteSize: 0` and an empty body — the T302 defect reopened on the filter half, and
  *headerless*, because `CsvExporter` derives its header from the first row and there was none. So
  `contracts/exports.md`'s "explicit column header row" was not met even in the one case where an
  empty result is the right answer. `ExportService::generate()` now takes the resource's declared
  columns: the same request answers 64 bytes, which is the header.

  **The rule moved out of a controller rather than being copied into one.** T309's closed vocabulary
  and its coercion were private members of `ReportController`, and the available move for the export
  was to write them again — the shape of the T307 defect, where the filter and the rows each had a
  private idea of the vocabulary. They are now `ReportFilterFactory`, in the application layer, and
  `ReportController` keeps only what is its own: `filters` arrives here as a query parameter and so
  arrives as a JSON string, which it decodes before handing the object over.
  `testNoNameTheReportAcceptsIsUnknownToTheExport` iterates that constant and asserts the export never
  answers "Unknown filter" for any name the report accepts, so a name added to one endpoint's
  vocabulary and not the other's fails rather than drifting.

  **`narrow()` is exposed separately from `paginate()` on purpose.** An export is the whole authorized
  selection; a paged export understates `rowCount` *and* omits authorized records, which FR-068
  forbids outright. The unit test filters 250 rows to 125 and asserts all 125 return, because a
  `narrow()` that sliced to `pageSize` would pass any test written against a handful of rows.

  **FR-068's "cost center transactions" is now selectable.** The transaction export row carried no
  cost centre, so `costCenterDbIds` was unanswerable and the export and the report had two different
  ideas of what a transaction row is. The export row now carries the same six active-revision columns
  the transactions report carries, read from the transaction's own revision per FR-028. **This widens
  the artifact**: the flat `transactions` CSV has six more columns than it had, which is a change to an
  output rather than an internal refactor and is recorded as such.

  **One real defect surfaced by the tests and is fixed here.** The unanswerable-facet refusal named
  the *internal rule*, so a caller that sent `amountMinMinor` was told the report could not be filtered
  by `amountRange` — a thirteenth name appearing nowhere in the accepted-filter list it had just been
  shown. `RULE_REQUEST_NAMES` maps each rule to the names a caller may have sent and the refusal quotes
  all of them, so a caller who sent `to` is not told about `from`.

  Mutation-proven eleven times. One survived the first pass and is the interesting one: replacing the
  list check in the factory with `if (false)` left the suite green, because both tests written for it
  asserted only that a list is *refused* — and a list is refused either way, since
  `array_keys(['statuses'])` is `[0]` and the closed vocabulary catches it with
  `Unknown filter "0"`. A test that asserts an outcome is not a test that identifies which rule produced
  it, which is T311's lesson again. Both now assert the *shape* of the refusal and are green against
  the mutation.

  **Not done here, deliberately.** FR-068's *predefined calendar periods* — month, quarter, year — are
  not in the vocabulary, and a client sending one is refused as an unknown filter. That is the safe
  direction and the refusal names what to send instead, but it is not support. "Month" is ambiguous
  without a definition (current calendar month? last 30 days?) and a calendar needs a timezone, so the
  answer belongs in `spec.md` rather than in a controller. Also unchanged: `report`, `audit` and
  `evidence` still refuse any selection, and the export and report row builders are still separate
  code that now agree by construction rather than by sharing it.

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


**The duplicate Phase-45 occurrence of `T029` was removed here.** Phase 2 (line 118) already owns
`T029` as the canonical line for OCS error mapping, so this second declaration gave one task ID two
checkboxes and made `git log -L` and any per-ID tooling ambiguous. The canonical owner is the Phase 2
line, which was never reopened. The reopening this phase performed, the `LogicException => [403,
'FORBIDDEN']` misclassification finding, and T311/T308's correction evidence are preserved below and
are to be read as additional history on the Phase 2 `T029`, not as a second task.

  **Reopening recorded on the canonical Phase 2 `T029` line** (this text is evidence, not a second task line): T029 Implement consistent OCS error mapping for validation, forbidden, hidden-not-found, stale
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

  **Corrected by T311 as bookkeeping, on T308's evidence -- not new work.** This line stayed `[ ]` after
  the work was delivered and measured, so the state was simply never updated. T308's note records the
  change and the measurement: the `$error instanceof LogicException => [403, 'FORBIDDEN']` arm was removed,
  and `GET /reports/transactions`, `/reports/process` and `/reports/evidence` went from `403` plus
  `Cannot resolve the currency of transaction revision 7 from its sheet` to `500` plus
  `pettycash.error.unexpected`, with the full suite showing no regressions -- which is also what shows the
  removed arm was not load-bearing for any existing test. The rule now encoded is the one this task
  always asked for: **authorisation is never inferred from an exception class.**

  T311 additionally verified the *reporting* half of this mapping against a real fault rather than a
  synthetic one: `UnreadableRecordData` is answered `422`, the message names the fault as a data fault and
  rules out permissions and filters, and neither the internal message nor the surrogate key it names
  reaches the caller. Two assertions there are independent, proven by mutation: echoing the internal
  message fails one, and rewording it while keeping the surrogate key fails the other. So the half of
  T029 that T308 could not verify is now verified, and the half it could is unchanged.

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

- [x] T310 Add the report filter facets and row columns FR-064 names but nothing models, per FR-064
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

  **Delivered. Six facets, not the five the table names -- the sixth was found by measuring the
  endpoint rather than by reading the table.** `audit by date range` was recorded above as *working*,
  and it was not: `dateRange` reads `occurredAt`/`createdAt`, the audit report emitted neither, and
  `{"from":...}` against `/reports/audit` was refused 422. The same happened to `stages`, which T309's
  note called "silently ignored" -- that was the state *before* T309 closed the vocabulary, and after
  it the same key was refused instead. Both are refusals, both are now answered, and a table that
  described the gap from a snapshot rather than a measurement will be wrong exactly this way.

  Measured on the live endpoint, all twelve facets, before and after:

  | Facet | Before | After |
  |---|---|---|
  | sheets by creator | `422` `createdBy` unevaluable | `200`, narrows; `{"createdBy":"no-such-user"}` -> `total: 0` |
  | transactions by cost centre | `422` unevaluable | `200`, narrows on the active revision's centre |
  | transactions by expense type | `422` unknown filter | `200`, narrows |
  | transactions by amount | `422` unknown filter | `200`, inclusive `amountMinMinor`/`amountMaxMinor` |
  | audit by stage | `422` unknown filter | `200`, narrows |
  | audit by date range | `422` unevaluable | `200`, both bounds |
  | the six already working | `200` | `200`, unchanged |

  **The sources are FR-064's own, not a convenience.** Each facet reads the column the requirement
  names: a sheet's creator is its `ownerUid`; a transaction's cost centre, expense type and amount all
  come from `revision()` -- its **own active revision**, not the sheet, because FR-028 allows one
  sheet to hold transactions for several cost centres; an audit event's stage and date are the ones
  recorded on the event. The transaction rows gained `costCenterDbId`, `costCenterCode`,
  `expenseTypeDbId`, `expenseTypeTitle`, `amountMinor` and `currency` for this, and the audit rows
  gained `occurredAt`. No migration and no schema change: every column was already reachable, which is
  the answer to the join-or-schema question the task posed.

  **Amount is a range, and deliberately not a list.** `amountMinMinor`/`amountMaxMinor`, inclusive,
  validated in `ReportFilter` (an inverted or negative range is a 422) and parsed strictly in the
  controller so a non-integer names the offending key. A caller asking "between 100 and 200" is asking
  for a range. A null row amount -- a draft revision that was never completed -- is **excluded**,
  because a revision with no amount is not an amount of zero, and coercing it to zero would put every
  such draft into a `max: 0` result.

  **The guards still guard, and that is asserted rather than assumed.** A facet on a report that has
  no such column is still refused rather than ignored: `{"amountMinMinor":1}` against `/reports/sheets`
  answers 422 naming `amountRange` *and* the columns the rows do carry. Closing the vocabulary did not
  become a way to get an unfiltered report.

  **One existing test had to change, and the reason is the point.** `testAnUnrecognisedFilterIsRefused`
  used `{"stages":["FINANCE"]}` as its example of a key nothing accepts, because it was unknown when
  written. T310 made `stages` a real filter, so that assertion became false and now fails. The example
  was re-picked (`expenseStatus`). A test that selects "a key nothing accepts" has to be re-picked
  whenever the accepted set grows, and this failure is the signature of forgetting to -- so it is
  recorded here rather than quietly re-pointed.

  **Two live defects found while measuring, both fixed, neither belonging to this task's slice.**
  Recorded here because both were found *by* this task's work and neither had an owner:

  1. **The round-trip harness orphaned 28 transactions, and that was refusing a whole report.**
     `roundtrip-runner.php` swept a leaked sheet by title but not the transactions hanging off it,
     leaving `pettycash_transactions.sheet_id` pointing at nothing. `resolveCurrencyCode()` walks
     revision -> transaction -> sheet -> currency, so with the sheet gone the `transactions` report
     answered **422** while `sheets` answered 200. T308 fixed the *sibling* fault -- a leaked sheet
     whose currency had been swept -- and the same blind spot survived one level down, which is the
     strongest argument that "the sweep is correct" was never established by reading it.
     The fix keys off the **orphan itself** (`LEFT JOIN ... WHERE s.id IS NULL`), not off the leaked
     sheet, because by the time any sweep runs the offending sheet is already deleted: a sheet-keyed
     predicate matches nothing. That was verified, not reasoned -- the first version of the fix used
     the sheet key and `roundtrip-leftovers.php` still printed "No leftovers" against 112 broken rows.
     `roundtrip-leftovers.php` had the identical blind spot and now reports them; 112 rows were removed
     and the report answers 200.
  2. **`TechnicalLogger::log()` did not exist and one caller used it anyway.**
     `ReportController::accountsByDbIds()` called it on the branch where a charged account no longer
     resolves. PHP resolves methods at call time, so the file parsed, the suite stayed green, and the
     endpoint would have answered 500 with `Call to undefined method` on exactly the data fault an
     operator most wants logged. The method now exists, and `TechnicalLoggerTest` covers it -- at the
     logger's own surface, because reaching the controller needs the whole container.

  **Mutation-proven, all three.** Removing the four new row-key mappings fails 5 contract tests;
  removing the transaction rows' active-revision columns fails 4; removing `occurredAt` errors 1;
  making the amount filter ignore its bounds fails 1; removing `TechnicalLogger::log()` errors 3; and
  removing the orphan sweep's **call** fails the harness test. That last one is worth recording: the
  first version of that test asserted the sweep was *defined* and passed against a runner whose sweep
  had been commented out. A closure nobody calls satisfies every assertion about its body, so the
  invocation is now asserted too -- found by mutating the call away and watching the suite stay green.

  Suite **664 tests, 3005 assertions, 1 pre-existing T275 failure** (the evidence content-type
  conflict, unchanged from the 648/2872 baseline). `npm run check` clean: typecheck, lint, stylelint,
  7/7 frontend suites, build.

  **Not done here, deliberately.** T311 remains open and is *not* addressed by this task: the 422 path
  this work had to unpick is still unverified by a committed test, because verifying it needs a
  deliberate corrupt record. What this task did was remove the *accidental* corruption that made the
  path unreachable in practice, which is the precondition T311 asks for and not the fixture itself.

- [x] T311 Add a corrupt-record fixture that can verify data-integrity handling without risking the
  shared database, per Constitution II (missing). T308 delivered the reporting half of the data-fault
  path -- a record with an unresolvable currency is refused with 422 rather than losing three reports --
  but the 422 path has **no committed test**, because triggering it requires deliberately inserting a
  record with a dangling `currency_id`, which is the exact fault T308 exists to remove. A one-off
  injector was written and abandoned: it writes invalid data into the MariaDB the live contract suite
  also uses, and a probe whose failure mode is "leaves a landmine that breaks three reports" is the
  trade that produced the seven leaked sheets in the first place. Building it safely means the runner's
  cleanup is provably complete *first* -- every inserted row registered before the next insert, and a
  post-run assertion that the fingerprint count is zero -- and only then a fixture that asserts the 422
  and its refusal to leak the internal message. (done)

  **Delivered in the stated order, and the first half was the larger one.** The precondition was not
  "make the cleanup complete" -- it was that nobody could *know* it was complete, because
  `roundtrip-runner.php` and `roundtrip-leftovers.php` carried separately written lists of the same set of
  rows and had already diverged once. The definition now lives once, in `tests/Support/LeakedRows.php`,
  and both read it.

  **Writing it against the live database found 36 leaked rows the old definition could not see** --
  which is the whole argument for having done it that way rather than transcribing the two lists:

  | Leak | Count | Why the old predicates missed it |
  |---|---|---|
  | cost centres named `Round Trip Expense Cost Center` | **35** | the predicate was `eq 'Round Trip Cost Center'`; the expense scenario writes a differently named centre |
  | configuration audit rows | **1** | the predicate was `explanation like 'Retention probe %'`; this row's explanation was `charged in the round trip` |

  Both were reachable only by the by-id sweep, which is the sweep that fails when a run dies *between* an
  insert and its `$track()`. Both were removed, and `pettycash_accounts`, `pettycash_role_assignments`,
  `pettycash_transactions`, `pettycash_transaction_revisions` and the expense-scenario audit rows are now
  covered for the first time. Coverage became a **property of the whole set** rather than one predicate:
  every table the runner registers with `$track()` must appear in `LeakedRows::sweptTables()`. Dropping the
  accounts step fails that test by name.

  The post-condition is `leftovers()` after the sweep, **recorded in the runner's payload and then
  raised** -- printing first and failing second, because a `throw` inside `finally` skips the trailing
  `echo` and the caller would be told only that a subprocess produced no output.

  **The probe is a real business record with one corrupted column.** Sheet, transaction and revision are
  written exactly as the contract seeder writes them, and only then is `sheets.currency_id` pointed at an
  id that does not exist -- so what is under test is the *reference*, not a hand-built approximation of a
  record. Measured on the live endpoint, absent vs present:

  | Request | Absent | Present |
  |---|---|---|
  | `/reports/transactions` | `200` | **`422`** |
  | `/reports/process` | `200` | **`422`** |
  | `/reports/evidence` | `200` | **`422`** |
  | `/reports/sheets` | `200` | `200` |
  | `/reports/audit` | `200` | `200` |

  The body names the fault as a data fault and rules out permissions and filters. The internal message
  -- `Cannot resolve the currency of transaction revision 1223 from its sheet` -- is absent, and so is
  the surrogate key.

  **Isolation is by stage, measured rather than assumed.** `transactionsReport()` reads
  `findByOwnerUid(...)` for a non-administrator but `findByStageAndStatus(Final, ...)` for an
  administrator, so a probe at Final would be read by *both* and a crashed probe would refuse the report
  for a user with nothing to do with it. At `DRAFT` it is confined to the one fixture that creates it.

  Four properties make it safe, each checked rather than described: **repair before write** (a probe left
  by a killed run is swept before the next is written, so the fault lasts one run, not one run per
  attempt); **removal verifies** rather than assuming, and the PHPUnit side throws rather than reporting
  success; **the fingerprint is a name**, so `roundtrip-leftovers.php --delete` repairs a crashed probe;
  and the corruption is one column, so nothing else about the record is synthetic.

  **Mutation-proven, ten times.** Removing the `catch (UnreadableRecordData)` fails 3 (status 996, not
  422); echoing the internal message fails 1; rewording it while keeping the surrogate key fails 1
  *independently*, so both disclosure assertions have their own teeth; removing the repair-before-write
  sweep fails 1; removing the removal's sweep errors 5; dropping the accounts step fails 1 by name;
  reordering the currency sweep before the sheets fails 1; removing the runner's post-condition fails 1;
  breaking `NAME_PREFIX` so no predicate matches fails 1.

  **The ninth mutation survived, and it changed the design.** The suite's enforcement of the runner's
  post-condition was asserted by reading *this file's own source* for the word `leftovers`; replacing the
  guard with `if (false)` left the suite green. A source-text assertion cannot see a dead branch -- and
  this project has been bitten by that twice, T310's orphan sweep being *defined* and never *called* while
  every assertion about its body passed. So the enforcement became a function,
  `LeakedRows::assertNothingSurvived()`, called by both the runner and the suite and tested **by calling
  it** with a synthetic leak. The dead branch has nowhere left to hide.

  **What could not be tested, stated rather than glossed.** The runner's post-condition *cannot be
  provoked* end to end, and the reason is structural: a post-condition fires when a sweep leaves a row
  behind, and every row a predicate can see is a row the sweep deletes. The schema declares no foreign
  keys, so a delete does not fail on a dependent, and no visible row can be made to survive. The test that
  plants one and runs a scenario proved exactly that -- the scenario swept it, so no failure was raised,
  correctly. What is asserted instead is the property that makes the guard meaningful: **the two halves
  agree, on real rows, about what they are looking at** -- a planted row is counted, swept, then counted
  at zero. A predicate matching nothing would pass every source-text assertion in the file while removing
  nothing, which is the shape of the bug T308 had to unpick.

  **One finding recorded and not fixed.** With the probe present, `GET /reports/sheets` answers **200** and
  reports the probe sheet as `"currency":"IRR"`: `DbExpenseSheetRepository::getCurrencyCode()`
  (`lib/Infrastructure/Persistence/Expense/DbExpenseSheetRepository.php:179-188`) returns the literal
  `'IRR'` when the lookup matches no row. That is **T267**, which is open and owns it, and it is
  deliberately **not asserted** here -- a test that pinned `"IRR"` as the expected answer would enshrine a
  defect as a requirement and would have to be deleted when T267 removes it. It is recorded as an
  observation, and it is why this task cannot claim the fault is contained.

  Suite **675 tests, 3089 assertions, 1 pre-existing T275 failure** (baseline 664/3005/1). `npm run check`
  clean. Database verified clean afterwards three ways: the diagnostic prints `No leftovers.`, a
  `LEFT JOIN ... IS NULL` finds **0** sheets with an unresolvable currency, and **0** orphaned transactions.

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
## Phase 47: Convergence (Ownerless gaps left by T306 and T267)

**Purpose**: Six pieces of work that three convergence phases found by measuring, and that **no task
owns**. T310, T311, T306 and T267 each recorded something they deliberately did not do; four of those
records are gaps with no owner anywhere in this file, so `/speckit.implement` — which works the first
genuinely incomplete task — would never reach them.

**This phase deliberately appends nothing that already has an open task.** US6 (evidence, public
evidence links, traceability) is the largest unimplemented surface in the repository:
`PublicEvidenceLinkService`, `EvidenceQueryService`, `AppDataStreamResponse` and `AuditQueryService`
do not exist, and `EvidenceController` implements `content()` but neither `createLink()` nor
`deactivateLink()`. All of that is already owned by T090-T099, every one of them `[ ]`. T275 (the
evidence content-type conflict, and the one failing test in the suite) and T304 (SC-008) are likewise
already owned, and both are blocked on a maintainer decision rather than on code. Re-appending an
owned gap would give one piece of work two owners and two places to be marked done.

- [x] T312 Implement FR-068's predefined reporting periods on `POST /exports` and the report/export
  filter surface, now that the semantics are settled (complete)

  **Delivered across two passes.** The first settled the dependency and the conversion core; this one
  closed the filter surface, the 422, the disclosure and the contract amendment, after the time-zone
  source was settled (see below) because it decides the filter's shape.

  ---

  ### The vocabulary, the exclusion and the resolution

  `period` joins `ReportFilterFactory::ACCEPTED_FILTERS`, which is the **closed** set both `GET /reports`
  and `POST /exports` read — one rule for two endpoints, which is why that list exists (T307/T309).

  Resolution happens **in the factory**, not in a query: a query has no clock and no zone, and putting the
  Jalali conversion in the query path is precisely what `plan.md`'s "isolated behind a small value object"
  exists to prevent. So the filter that reaches `ReportQuery` carries **dates**, and `ReportFilter` keeps
  the period name and the resolved zone only so the response can disclose them.

  **Mutual exclusion is enforced in both key orderings**, because a caller can send `period` and `from`
  in either order and "whichever arrived first wins" is exactly the silent discard FR-068 forbids — the
  caller would receive one filter while believing they sent two. The refusal names the conflicting keys and
  says which to remove, so a client fixes it in one attempt. A **blank** `period` is "not supplied", not a
  period named `""`, so it does not turn an ordinary explicit range into a 422.

  ### The disclosure, defined once

  `ReportFilter::periodDisclosure()` returns `period`, `resolvedFrom`, `resolvedTo` and
  `resolvedTimeZone`, and **empty** when nothing was resolved. Both endpoints spread it into their own
  response (`ReportController::show()`, `ExportController`), so they cannot state it differently — a
  disclosure assembled in two places is the drift class this codebase has been bitten by repeatedly.

  Both facts are about **disagreement between people**, not about the filter: without the zone, two
  finance officers reconciling "this month" cannot tell a different period from a different timezone. And
  absent rather than null when unused, because a key describing a request the response did not use is a
  claim about the request rather than about the answer.

  ### `ReportQuery` needed `period` as a *real* rule, not a listed name

  The existing meta-guard `testEveryRequestNameIsAnsweredByARuleThatNamesIt` requires every accepted
  filter to be refusable **by the name the caller sent**. The tempting fix is to add `'period' =>
  ['occurredAt', 'createdAt']` to `FILTER_FIELD_KEYS` and stop — but `filterRequests()` and
  `rowMatches()` both `match` on the rule name, so that entry would satisfy the guard's **letter** with a
  rule that never fires. So `period` was added to both `match` arms and takes the same `from`/`to`
  comparison `dateRange` does; the two rules can both fire for one filter and the comparison is idempotent.
  The reason the entry exists at all is recorded in the code: a report that cannot answer a period must
  refuse it by name rather than ignore it.

  ### The time-zone source — settled, and it already existed

  FR-068 requires the zone from "that user's own account settings, falling back to the instance's
  configured default when the account states none; **no zone is hardcoded**". That sentence describes
  **Nextcloud's own mechanism**, found in this container:

  ```
  OC\DateTimeZone::getTimeZone():  getUserValue($uid, 'core', 'timezone', '') ?: getSystemValueString('default_timezone', 'UTC')
  ```

  So the answer is the user's `core.timezone`, then `default_timezone`, then `UTC` — **no migration, no
  admin screen, no second source of truth.** `OCP\IUser` has no timezone method in this version; `IConfig`
  is the route.

  `RequestTimeZone` (port) takes a **uid rather than reading the session**, because FR-068's own
  verification needs two users in different zones at once. The precedence is a pure rule in
  `TimeZonePreference::resolve($userZone, $instanceDefault)` — **necessarily**, because `OCP\` is not in
  this application's Composer autoload, so no test under `tests/Unit/` can implement an `IConfig` (a first
  attempt at exactly that failed with `Interface "OCP\IConfig" not found` and was **deleted** rather than
  worked around by hand-loading the vendor stub). `NextcloudRequestTimeZone` reduces to two reads.

  Two failure modes the rule exists to prevent, both reachable because the value is user-supplied:
  `new DateTimeZone()` **throws** for a name the tzdata no longer has, so a stale preference would
  otherwise 500 every report that user requests over a setting they cannot see; and Nextcloud stores an
  untouched preference as `''` rather than deleting the row, so blank is what "stated nothing" looks like.

  ### The dependency, and the two things plan.md's rationale did not anticipate

  `morilog/jalali:^3` resolves to **v3.5.0, MIT** (+747 lockfile lines). **It is not dependency-free**: it
  requires `nesbot/carbon` and `beberlei/assert`, so **6 net new packages** enter a financial application
  for a date conversion. All MIT, none needs `ext-intl`, so `plan.md`'s deployment-envelope rationale
  holds — but **T125's dependency-security gate and T132's packaging check must both cover six packages,
  not one.** And **v3.5.0 renamed its own API**: the class is `Jalalian` not `Jalali`, there is no static
  `fromGregorian`, and conversion is `CalendarUtils::toJalali()`. Code written from `plan.md`'s prose
  would compile and fail at runtime.

  ### The abstraction promise is now checkable

  `grep -rn Morilog lib/` returns **exactly one file**, `JalaliReportingPeriods.php`. That is what makes
  `plan.md`'s "swapping strategy later touches one file rather than the query path" verifiable rather than
  aspirational.

  ### Two bugs and one broken plan-requirement, all found by the tests

  - **`getLastErrors()` was read after both parses.** PHP accepts `2026-02-30` and returns `2026-03-02`,
    reporting it only through that error state — **which the next parse replaces**. So the first date's
    warning was discarded whenever the second was valid, i.e. almost always. A period boundary that
    quietly became the 2nd selects the wrong records with nothing to indicator. Fixed by reading the
    state immediately after each parse.
  - **My own expectations were wrong twice while the code was right.** I called Shahrivar 1405 a 30-day
    month (measuring gives `31,31,31,31,31,31,30,...`, so Mehr is the 30-day one), and assumed a UTC caller
    is "one day behind" (it is in the **previous Jalali month**). Tests now assert measured lengths.
  - **`plan.md`'s prescribed ICU differential cannot be implemented.** **ICU 76.1 exposes no Solar Hijri
    calendar**: `en_US@calendar=persian` reports **1348**-10-01 where Solar Hijri is **1405**-07-01, and
    `islamic-umalqura`/`islamic` are lunar, `dangi` is Korean. CLDR aliased `persian` to the **lunar**
    Islamic calendar. The first differential reported a disagreement on **all 1,827 days** — pure noise.
    A *missing* oracle and a *wrong* oracle must both skip, and only the second is dangerous, because
    `class_exists(IntlCalendar::class)` is true. The test **anchors** the oracle against a known Solar
    Hijri date before trusting it. **It skips**, which is reported honestly as not-oracle-verified.

  ### Contract amended — all four source items (T124 may now run)

  `contracts/ocs-api.yaml` gains: (1) `filters.period` with its `enum: [month, quarter, year]` and the
  Farvardin/mutual-exclusion rules spelled out; (2) the `422`-on-conflict behaviour; (3) `resolvedFrom` /
  `resolvedTo` on `ExportResult`; (4) `resolvedTimeZone`, described as a zone name and not an offset.
  `ReportPage.summary`'s disclosure is documented (it is `additionalProperties: true`). **Type
  regeneration is still T124's** and was not touched. YAML re-parsed; 31 paths intact.

  ### Verification

  - `JalaliReportingPeriodsTest` **13 tests / 37 assertions, 1 skipped** — every verification `plan.md`
    names: 30- and 31-day months, quarter from Farvardin (asserted *against* the Gregorian boundary it must
    not use), year 365 vs 366 across 1403/1404/1405/1408, inclusive ends, the UTC regression, two zones,
    the closed vocabulary, an impossible boundary date, purity.
  - `TimeZonePreferenceTest` **10 tests**, including the chain's identity asserted **against Nextcloud's
    own source in the container** so the reason we read `core.timezone` cannot rot (skips if absent).
  - `ReportFilterFactoryTest` **58 tests** — period resolution, disclosure shape, both exclusion
    orderings, blank-period-is-not-a-conflict, unknown name refused.
  - **Whole reporting + export surface: 405 tests / 1646 assertions, green** (1 skipped).
  - **Three mutations, all caught**: resolving through `UTC` instead of the zone → exactly the 2 zone
    tests red; dropping the mutual exclusion → exactly the 2 exclusion tests red; dropping the disclosure →
    red **only after a test was added for it**, because the first attempt at that mutation passed
    everything — an empty disclosure is indistinguishable on the wire from "no period was requested", which
    is exactly why it needed its own assertion.

  **Two things remain outside this task.** The **licence notice** it was told to create is still not
  created — the entry is explicit that the repository has no third-party-notice convention, that this task
  creates the surface, and that **T132 must verify it**; that is still owed. And **no full-suite run was
  taken**, for the reason in T313's note (concurrent-session `429` throttling); the 405-test reporting run
  above had 7 contention samples but a coherent all-green result.
  Cross-references: T124, T275, T306, T309, T310, T125, T132, T134.
- [x] T313 Give `GET /sheets`, `GET /sheets/{id}` and `GET /dashboard` the same data-fault answer the
  reports have, and settle whether that answer is 422 (partial)

  **Delivered. The entry named this a decision rather than a refactor, and the decision is recorded:
  the translation lives in `OcsErrorMapper`, classified by exception type, and `ReportController`'s
  bespoke arm is deleted rather than copied.**

  ---

  **The three options weighed, and why the third option the entry listed was not chosen as stated.**
  The entry offered "a shared handler, a decorator, or a deliberate line drawn at the report
  boundary". A shared handler or a decorator would add a layer without changing the property that
  matters: FR-083 requires "the same status and the same class of explanation, **so that a client
  writes one handler rather than one per endpoint**", which is only achievable if the mapping is a
  property of the *exception type* rather than of each controller. `OcsErrorMapper` already is the
  single funnel every controller failure passes through — its own docblock says so — and
  `SheetController::index()`, `SheetController::show()` and `DashboardController::index()` each already
  `catch (\Throwable)` and call `->map()`. So the answer was to add one arm there and delete the
  per-controller one, rather than to add a layer that all three controllers would still have to opt
  into by hand.

  **This is the third option, resolved in the opposite direction: not a line drawn at the report
  boundary, but the boundary itself removed.** The line was the defect. FR-083's phrase "one handler
  rather than one per endpoint" is a statement about the mapper, and a line a future endpoint can
  simply forget to notice is the exact shape T313 was raised for.

  **`ReportController::show()` lost 25 lines and `UnreadableRecordData` its import.** Keeping the
  arm would have meant two places that could answer differently, which is what FR-083 was raised for.

  **The arm logs *and* withholds, which is the combination no other 4xx arm needs.** Without the
  `elseif` branch the new arm would fall through to `$error->getMessage()` and publish
  "Cannot resolve the currency of sheet <uuid>: currency_id 42 matches no row" — telling a caller
  which stored reference is broken, i.e. something about rows they may not be able to see. That is the
  disclosure T029 was raised for, so the branch substitutes a canonical message **and** logs the real
  exception in full. Verified rather than assumed: the probe run reported
  `leaks danglingCurrencyId: false`, `leaks revisionDbId: false`, `leaks internal msg: false`.

  **`DATA_FAULT` is a new error code, and that is deliberate rather than incidental.** `Error.code` is
  `type: string` in `contracts/ocs-api.yaml` (not an enum) and `src/types/api.ts` types it as `string`
  and never branches on it, so a distinct code is contract-legal and costs the client nothing. FR-083
  asks for one handler across the endpoints, and a handler that must also string-match a message cannot
  be one — the code is the part a program can switch on. The reports previously answered
  `VALIDATION_FAILED` here, which was a *client's* fault code for a fault that is not the client's.

  **The canonical message names neither the endpoint nor the record, on purpose.** FR-083 wants one
  explanation across all four endpoint groups, so the string cannot vary by endpoint — and the caller's
  own request already tells it which endpoint it called. The report's message embedded
  `The %s report cannot be produced`; that per-endpoint text is gone. `DataIntegrityReportTest` pins
  only two phrases ("a data fault", "not a permission or filter problem") and both survive, so no
  assertion was weakened.

  **Two docblocks asserted the old split and are now corrected rather than left lying.**
  `UnreadableRecordData`'s own docblock said "every other caller keeps the classification it had",
  and `DbExpenseSheetRepository::getCurrencyCode()`'s said "`ReportController` translates it into a
  422 ... while every other caller keeps the classification `OcsErrorMapper` already gives an
  unrecognised fault". Both described behaviour this task removed.

  **The dashboard half was measured, not assumed, because T313's table was T267's measurement and
  centralising is not the same thing as the endpoint being fixed.** A throwaway probe put the corrupt
  record in place and read `GET /dashboard`:

  | Field | Value |
  |---|---|
  | HTTP / `ocs.meta.statuscode` | `422` / `422` |
  | `error.code` | `DATA_FAULT` |
  | canonical message present | yes |
  | leaked `danglingCurrencyId` / `revisionDbId` / internal message | `false` / `false` / `false` |
  | `GET /dashboard` after the probe was removed | `200` |

  The probe was then **deleted**, because T314 owns the dashboard test and adding one here would take
  its scope. So the behaviour is delivered and measured; the assertion is T314's.

  **`DataIntegritySheetCurrencyTest` was tightened, not deleted** — the five concerns, the fixture and
  the control are all unchanged, and only the acceptable answer stopped being a set. Its docblock had
  deliberately enumerated `[200, 404, 422, 500]` "so a follow-on can tighten them without having to
  delete anything"; this is that follow-on. The status enumeration is replaced by pinned 422s, plus a
  `DATA_FAULT` code assertion and the "names the fault as being in the data" phrase.

  **One assertion was removed rather than tightened, and it is worth naming.** The old tests carried
  `if ($response['status'] === 200) { assertNull($entry); }` — a conditional that made the strong
  invariant ("no 200 may carry this record") contingent on the endpoint having answered 200 at all.
  With the status pinned there is nothing to condition on, so the `assertNull` is now unconditional
  and is what makes the claim unconditional too.

  **Mutation-verified across both endpoint groups.** Disabling the arm
  (`$error instanceof UnreadableRecordData && false`) turns **seven** tests red with a single coherent
  message — `Failed asserting that 500 is identical to 422` — across `DataIntegritySheetCurrencyTest`
  **and** `DataIntegrityReportTest`. That cross-file coherence is the evidence: the sheet tests
  previously enumerated their acceptable statuses and would have passed with the arm removed, so
  before this tightening they were not capable of detecting the regression they existed to prevent.

  **Targeted verification: `DataIntegritySheetCurrencyTest` + `DataIntegrityReportTest` +
  `ErrorEnvelopeContractTest` + `AccountControllerTest` = 29/29, 233 assertions, green.**

  **A full-suite run could not be taken as evidence, and the reason is worth recording precisely.**
  A second agent session is working this same working tree concurrently — it created the untracked
  `tests/Unit/Architecture/PlanStructureTest.php` and amended `plan.md` (that is T238, and its
  `tests/Integration/Storage` failure is its own, in `PENDING` terms not yet declared). Both sessions
  run `scripts/dev/phpunit` against the **one** shared live database with the **same**
  `PETTYCASH_TEST_USER` credentials, and the contract suites reseed shared fixture state. Four
  full-suite attempts produced 2, 7, 30 and 151 failures respectively, with whole classes failing
  wholesale — `ReportAndExportControllerTest` (71 tests), `SheetListFilterContractTest`,
  `FinanceReviewControllerTest` and `PurchaserFlowControllerTest` each entirely.
  **The mechanism was identified rather than guessed, and it is not what it first looked like.** The
  obvious reading — two sessions corrupting each other's seeded fixtures — was wrong. A short run that
  happened to catch the degraded state returned **sixteen failures and no other kind**: every one was
  `Failed asserting that 429 is identical to <expected>`, across 200/400/401/403/404/422. That is
  Nextcloud's brute-force limiter refusing the request before the application sees it, and it is what
  whole-class failure looks like from the client side. Two sessions' worth of contract-suite traffic
  against one endpoint with one credential is enough to trip it.
  So: not a data fault, not a fixture fault, and not a regression in anything touched here — an
  environment that has been driven into throttling by concurrent load.
  **Two of the runs were guarded** by sampling the process table throughout, and recorded **24** and
  **25 samples with a concurrent foreign `phpunit`**; the last of those reached only 4241 assertions
  of the ~6700 a complete run makes, so it was cut off mid-suite rather than merely noisy. A run
  started 270 seconds after the tree went quiet was contended from its fourth second.
  Those numbers are recorded here as **not evidence**, and the honest state is that T313 is verified on
  its affected area and **unverified on a full-suite run**. Settling it needs one session, no concurrent
  traffic, and a Nextcloud instance no longer answering `429` — the limiter's window has to lapse
  first, so re-running immediately will reproduce this rather than clear it.

  **Not done, and recorded rather than implied.** **T314 remains open and is the pinning** — the
  dashboard and administrator branches have no test, and the administrator branch additionally needs a
  credential the contract suite does not have. **`ExportController`, `ReviewController` and
  `AccountController` were not audited** for this same fault: they call `map()` and so inherit the
  correct answer, but whether any of them *can* throw `UnreadableRecordData` on its own path was not
  checked, so "every read endpoint" in FR-083 is established for the four named groups and not for
  the whole surface. **No client-side handling was added**: `src/` has no handler for `DATA_FAULT`, so
  a frontend that branches on codes would still fall through to its generic error path.
  Cross-references: T267, T311, T314, T308, T029, T361.
- [ ] T314 Cover the `/dashboard` and administrator paths of the data-integrity refusal, which are
  measured but pinned by no test (partial)

  **FR-083 owner, cited 2026-09-30.** FR-083 requires the `422` data-fault answer on **every** read endpoint,
  which includes the dashboard's administrative summary and the administrator's own view of the sheets report.
  Neither branch is exercised by the contract suite, which holds one set of non-administrator credentials.
  This task is sequenced after **T313** so it pins the settled behaviour rather than a provisional one, and it
  is the natural place to add the administrator fixture no contract suite currently has.

  T267 measured `/dashboard` answering `500` with a broken sheet and recorded it in its report table
  rather than in a test, because the measurement was taken from a probe window and a test written
  there would assert a status the task above may change. The same gap is larger than the dashboard:
  the OCS contract suite holds **one** set of non-administrator credentials, so every
  `findByStatus(...)` branch an administrator reads is unexercised — the dashboard's administrative
  summary, and the administrator's own view of the sheets report. T267's argument that the outcome
  does not vary between branches, because the repository throws from one place, is *reasoning* and is
  recorded as such rather than as evidence.

  This task adds the coverage the claim actually needs. It is sequenced after T313 so it pins the
  settled behaviour rather than the provisional one, and it is the natural place to add an
  administrator fixture, which no contract suite currently has.

- [ ] T315 Extract the report and export row projections into one shared definition (partial)

  `ReportController::sheetsReport()` and `ExportController::sheetRows()` build the same seven sheet
  columns, and the transaction equivalents the same twelve, in two controllers. They agree today by
  construction rather than by sharing code — verified by comparing the key lists — and T306 changed
  both in the same commit to keep them that way. That is a convention, not an invariant, and the
  failure mode is already known: it is T307, where the filter and the rows each held a private idea of
  the row vocabulary and neither matched the other. `plan.md` puts the projection in the application
  layer; neither controller is there.

  The T306 fix made the consequence visible: `ReportQuery::FILTER_FIELD_KEYS` is a filter evaluated
  against a vocabulary that two producers each declare independently, so a column added to one and not
  the other makes a facet answerable on the export and refused on the report, with no test failing.
  One shared projection removes the possibility rather than documenting it. `ExportService` already
  takes each resource's declared columns as a parameter, so the extraction has somewhere to land.

- [x] T316 Reconcile the remaining unchecked task lines against the repository, with evidence, and
  mark `[X]` only where the named deliverable is present and correct (partial)

  116 task lines are unchecked. At least one is demonstrably stale: **T266** is `[ ]` while its
  deliverable is verifiably present — `grep -rn "CURRENCY_MINOR_UNITS" lib/` and
  `grep -rn "getMinorUnit" lib/` return nothing, no controller contains the string `IRR`, and
  `grep -rn "?? 'IRR'" src/` returns nothing, so fraction digits come from the configured `Currency`
  aggregate. T267 found this while choosing between T266 and T267 and deliberately left the box alone,
  because this file records that convergence never rewrites existing task state.

  T274 made one such pass over the `(missing)` annotations. The remainder is unexamined, and the
  harm is concrete: `/speckit.implement` works the first genuinely incomplete task and
  `AGENTS.md` requires verifying each unchecked task's state before selecting it, so every one of
  these lines costs a fresh investigation — and a line that is stale but read as genuine invites a
  rewrite of completed work, which `AGENTS.md` forbids. The measurement is cheap; the table is the
  deliverable.

## Known artifact issues in this phase (recorded here; do not open duplicate tasks)

- **US6 is unimplemented and correctly owned.** `PublicEvidenceLinkService`, `EvidenceQueryService`,
  `AppDataStreamResponse` and `AuditQueryService` do not exist, and `EvidenceController` implements
  `content()` but neither `createLink()` nor `deactivateLink()`. T090-T099 own all of it and are all
  `[ ]`. Not re-appended: one gap, one owner.
- **T275 is the suite's only failing test** and is blocked on a maintainer decision — amend
  `contracts/ocs-api.yaml` to describe a stored-media-type response, or clarify FR-060 to mean
  download-only — not on code. Owned, not duplicated, and not implementable until the decision.
- **T304** is the same shape: SC-008's performance bound is either covered by a verification task or
  explicitly deferred in the spec, and the choice is the spec owner's. Owned, not duplicated.
- **Constitution checks found no violations.** All five principles are filled, and the currency
  fallback T267 removed was the one place business configuration was being guessed in code
  (Constitution III) and a financial effect was being wrong without being visible (Constitution IV).
  Both are now addressed. No finding in this phase is CRITICAL.

  **Verified by T316, marked on evidence rather than on a file existing.**
  `tests/Integration/Migration/V1SchemaMigrationTest.php` declares `MAX_IDENTIFIER_LENGTH = 63` and
  **asserts it**, not merely declares it: the assertion at line 195 compares `strlen($identifier)`
  against the limit across the identifiers the migrations actually enumerate, and names the offending
  identifier in the failure message. The test is also green -- 8 tests, 160 assertions. The
  substance of the task is the assertion, so that is what was checked; a constant nobody compares
  against would have satisfied a file-existence check and nothing else.

  **Verified by T267 and marked by T316. Both named sites are absent:**

  | Evidence | Result |
  |---|---|
  | `grep -rn "CURRENCY_MINOR_UNITS" lib/` | no matches |
  | `grep -rn "getMinorUnit" lib/` | no matches |
  | `grep -rn "IRR" lib/Controller/` | no matches |
  | `grep -rn "?? 'IRR'" src/` | no matches |

  Fraction digits now come from the administrator-configured `Currency` aggregate through
  `CurrencyRepository`, reached via `$account->currency->minorUnit` in the application layer. The
  front end contains no currency literal at all: `formatMoney` is a shared utility in
  `src/utils/money` that takes a `Money`-shaped value, which is the *view* half of T267's requirement
  and was delivered before T267 began. T267 found this while choosing between T266 and T267, chose
  not to flip the box because this file records that convergence never rewrites existing task state,
  and left the finding in `docs/reports/T267.md` for exactly this pass.

  **T316 result: 120 unchecked lines reconciled against the repository. 2 marked, 118 left open.**

  The evidence is mechanical and reproducible. For every unchecked line the named backticked paths were
  extracted and resolved -- a bare filename such as `plan.md` or `DecisionForm.vue` was resolved
  against `git ls-files` rather than treated as a literal path, because 15 of the 71 apparent
  "missing" files exist under a directory and treating them as absent would have produced a table of
  false findings.

  **Marked `[X]` — 2.** T264 and T266, each with its evidence inline above.

  **Named deliverable genuinely absent — 56.** No file of that name exists anywhere in the repository.
  This is the real remaining work and it is coherent, not scattered:

  | Group | Tasks | What is absent |
  |---|---|---|
  | US4 correction | T069, T076 | `CorrectionControllerTest.php`, `TransactionCorrection.spec.ts`, `transaction-correction.spec.ts` |
  | US5 configuration administration | T078-T089 | `CurrencyService`, `CostCenterService`, `ExpenseTypeService`, `FinanceAssignmentService`, `AccountAdministrationService`, `ConfigurationAuditService`, `ConfigurationController`, `src/stores/configuration.ts` and four test files |
  | US6 traceability and evidence | T090-T099 | `AuditQueryService`, `EvidenceQueryService`, `AppDataStreamResponse`, `PublicEvidenceLinkService`, `AuditController`, `auditApi.ts`, `stores/audit.ts`, `AuditView.vue` and four test files |
  | US7 reporting and export | T102-T104, T113, T114 | `ReportScopeTest`, `ExportersTest`, `ReportingControllerTest`, `DashboardReports.spec.ts`, `ReportsView.vue`, `reporting-export.spec.ts` |
  | US8 cross-device UI | T115, T116, T119, T121-T123 | `ApplicationShell.spec.ts`, `RtlAccessibility.spec.ts`, `src/l10n.ts`, `useAsyncAction.ts`, `browser-matrix.spec.ts`, `ui-acceptance.md` |
  | Polish and release gates | T124-T135, T137, T139 | `validate-openapi.sh`, `security-check.sh`, `package.sh`, `docs/architecture.md`, `docs/operations.md`, `docs/release.md`, three checklists, `EvidenceReconciliationService`, and four test files |
  | Playwright journeys | T054, T066, T182, T194, T204 | every `tests/E2E/*.spec.ts` except `purchaser-submit.spec.ts` |
  | Blocked (now unblocked) | T109 | ~~the font asset, a maintainer decision~~ — **cleared 2026-09-30 by FR-066**, which decided the Persian font is an OFL-licensed font vendored under an app-owned path. T109 remains `[ ]` because the work is undone, not because it is blocked |

  **STALENESS NOTE added 2026-09-30.** This table is a dated snapshot taken during T316's pass and is **not
current state**. Two of its rows are now wrong and one class of reference is now dangerous: the `Blocked` row
above is marked unblocked; **T069 is now `[x]`**; and the "Playwright journeys" row lists **T182, T194 and T204,
which are retired task IDs** — duplicates removed by the 2026-09-30 ledger reconciliation, whose current
canonical owners are T054, T066 and T076 respectively. Read the table for the shape of the remaining work, not
for the task state.

**Genuinely partial — 4, and each was checked behaviourally rather than by file presence:**

  | Task | What was checked | Observed |
  |---|---|---|
  | T077 | test methods in `ConfigurationRulesTest.php` | **one** (`testCurrencyMinorUnitRange`) against the six rules the task names, so five are unwritten |
  | T254 | whether the migration test executes a migration | it is now structural rather than a substring search, but it still reads `lib/Migration/*.php` **as text**; the task's demand that it "exercise the real migration path" is unmet |
  | T276 | the controller and the service | `EvidenceController:229` still calls `deleteDraftEvidence()` for every state and `EvidenceService:106` still unlinks the binary before deactivating, behind a "will be enhanced" comment |
  | T297 | the migration's `command_id` column | declared without `notnull => false`, so NOT NULL, while `AuditEvent::__construct()` types it `?string` |

  **Reachable but blocked — 2.** T250 still exits 1: `sh scripts/preflight.sh` reports
  `ERROR 1045 (28000): Access denied for user '-p'@'localhost'` and fails on every invocation, so the
  HIGH-priority defect is unfixed. T291 needs a second acting identity, which `.env.testing`'s single
  credential cannot express -- a harness gap, as its own note says.

  **Present but unverified — 12, deliberately left `[ ]`.** T060, T072-T075, T117, T118, T120, T193,
  T201, T211, T212, T215. Their named files exist, which is the only thing that proves, and T316's own
  criterion is "present **and correct**". Two of them name a file to *modify* rather than a deliverable
  to create (T211 `AuthorizationService`, T212 `TransactionService`), so file presence is not evidence
  of anything at all. Marking these on presence would commit the error this task exists to remove, in
  the opposite direction. Each needs one behavioural check -- read the method, or run the named test
  -- and that is a task-sized unit of work, not a checkbox.

  **Delivered, and the task's diagnosis verified in full rather than assumed.** Measured before the
  fix: `sh scripts/preflight.sh` printed `ERROR 1045 (28000): Access denied for user '-p'@'localhost'`
  and exited 1. `docker exec docker-db-1 env | grep -i mysql` shows why -- the official MariaDB image
  publishes `MARIADB_USER`, `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD` and `MARIADB_DATABASE` and **no
  `MYSQL_*` variable at all**, so the step asked for three variables that do not exist. Credentials now
  come from `MARIADB_*` with a `MYSQL_*` fallback for a stock `mysql:` image, and the password is
  expanded *inside* the database container so it never reaches the host's command line.

  **The task's table of legacy tables is wrong in a number, and measuring found it.** The direction is
  right and the count is not: the declared `oc_pcash_%` prefix matches **0** rows, `oc_pc_%` matches
  **11**, not the 26 the task claims, plus `oc_migrations`.

  **The prefix is reported, not chosen.** T252 — still `[ ]` — owns reconciling the legacy-table
  premise and names T250 as one of the two guards derived from the wrong prefix, so the script queries
  both and prints:

  > preflight: WARNING - spec.md:333 declares the legacy tables are oc_pcash_* and none exists, while
  > oc_pc_* tables do; T252 owns the reconciliation

  A preflight that silently adopted one prefix would hide the disagreement the reconciliation exists to
  settle; a preflight that *failed* on it would turn a documentation defect into a blocked build and
  reproduce the always-red gate this task was raised to fix. `make preflight` now exits 0 and lists all
  11 tables.

  **`scripts/preflight.test.sh` is the real deliverable.** The gate is not run by `make check`, not by
  `npm run check`, and by no CI — which is exactly why a permanently-red script looked like a healthy
  one. Six assertions; two of them exist to stop the test being satisfied by a script that does nothing
  (asserting that the step *reached the database*, and that it found the *right* tables rather than
  merely that it ran).

  **Four bugs in that test, all found by mutation, all of which would have shipped green.** `$?` inside
  `$( )` is the substitution's status, so every assertion was reading the status of the arithmetic it
  had just run — they passed unconditionally. `set -eu` aborted at the first failed probe, so a broken
  gate reported one failure and silently skipped five. The password-leak check was **inverted**, passing
  exactly when the secret leaked, and the mutation that printed the password survived. The count
  assertion was a **lower bound** (`-ge`), so a gate printing `999` passed while listing eleven. The
  last two are the same shape as the defect this task was raised for — an assertion that cannot fail —
  reproduced in the fix for it.

  Mutation-proven seven times, all killed: `MYSQL_*` credentials, the observed-prefix query removed, the
  disagreement silenced, **the whole database step deleted**, the password printed, the count fabricated
  as 999, the summary inflated by 5. The fourth is the one that matters most: a gate that passes by
  never checking is worse than a gate that fails, because it converts an unknown into a green.

  **Stated rather than glossed.** The password assertion checks the *output*, not the host process
  table -- `docker exec -e FOO=...` never appears in a host argument list either, so that half of the
  claim rests on reading the script, not on the test. And **nothing in CI runs either script**:
  `.github/workflows/` does not exist, which is T239, and until it is built both remain manual gates.
  That is the structural reason this defect went unnoticed and it is not fixed here.

  No application code touched: the PHP suite is byte-identical at 745 tests / 3351 assertions / 1
  pre-existing failure (T275). Report: `docs/reports/T250.md`.

---

## Phase 48: Convergence (ownerless gap found by T214)

- [x] T317 Pass the client's `X-PettyCash-Command-ID` into `TransactionService::createTransaction()` and `updateTransaction()` and wrap both in `IdempotencyService`, so revision creation and transaction changes are replay-safe per FR-042/T027 (complete — followed the `SubmitSheet::execute()` pattern: `transactional()` → `execute()` → re-read the aggregate by public id. Two things that pattern forced and that are easy to get wrong: the transaction id is generated **inside** the closure (on a replay the closure never runs, so an id minted outside would be a fresh value for a row that was never written), and the ownership/status/version checks moved inside with it (a replay must not re-validate a command that already succeeded). The contract's `required: true` header is now enforced: a missing one is **400**, matching `ReviewController` and its existing test, because a missing required *parameter* is not the 422 that invalid body fields keep. **7 new tests, 4 mutations, all killed** — including one that initially did *not* fail and exposed a real gap: the audit `command_id` could be reverted to null with every replay test still green, so two correlation tests were added and the mutation re-run. `REVISION_CREATED` and `TRANSACTION_CHANGED` now carry the real command id, which is what `Version010400Date20260929000000` made nullable in the first place. `deleteTransaction()` is left alone — not in FR-042's list and not in the contract's required set for this slice. Report: `docs/reports/T317.md`)
      `updateTransaction()` and wrap both in `IdempotencyService`, so revision creation and transaction
      changes are replay-safe per FR-042/T027 (open; found while reconciling T214, **not** part of T214's
      named scope)

  **Why it is a task and not a line in T214's report.** T214 names four handlers and all four are done
  (T149 delivered them). This gap sits outside that list, so absorbing it into T214 would have widened a
  completed task's scope without saying so.

  **The gap, precisely.** `TransactionController` reads the header at
  `lib/Controller/TransactionController.php:56` and `:126`, echoes it through
  `OcsErrorMapper::validation()` and `->map($e, $commandId)`, and then calls
  `createTransaction($sheetId, $expenseDate, $amountMinor)` / `updateTransaction(...)` **without it**. So
  the one place a command id is available is the one place it is dropped. Neither service method takes a
  `$commandId` parameter, and neither is wrapped. The contract's `X-PettyCash-Command-ID` header is
  therefore honoured on submission, decision, settlement and resubmit, and ignored on the two endpoints
  that create and edit the revision FR-042 names explicitly ("creating a revision" MUST complete
  atomically).

  **Why it is more than tidiness after T213.** T213 gave these two handlers their `REVISION_CREATED` and
  `TRANSACTION_CHANGED` audit events, and had to make `command_id` nullable on both audit tables
  (`Version010400Date20260929000000`) precisely because these paths have no command id to record. FR-042's
  atomicity is satisfied — both already run inside `transactionManager->transactional()` — but a retried
  request, a double-clicked button or a proxy replay creates a **second revision** and a second audit
  event, and FR-053 makes that row unrepairable. `expectedVersion` catches only a *concurrent* edit; it
  does nothing for a sequential retry of a request that already succeeded.

  **Likely shape, not decided here.** Both methods gain a `string $commandId`, the controller passes the
  header it already has, and each wraps its existing transactional body in
  `idempotencyService->execute()` with a hashed payload. `TransactionService` already holds an
  `AuditWriter` (added by T213) but **no** `IdempotencyService`, so this is one constructor argument plus
  the container registration and the two direct constructions in tests. The audit events can then record a
  real `command_id`, and the nullable column from `Version010400Date20260929000000` stays correct for both
  the wrapped and unwrapped states.

  **Decisions this needs before implementation.**
  - Should a **missing** `X-PettyCash-Command-ID` be refused (409/400) or tolerated? The other four
    handlers appear to require it, so consistency argues for refusing — but that changes the contract's
    behaviour for two endpoints and belongs with whoever owns `contracts/ocs-api.yaml`.
  - A replayed `updateTransaction()` must return the **original** response, which means reading the
    stored `CommandResult` back into an `ExpenseTransaction`. `SubmitSheet` re-reads by public id and
    `ResubmitCorrection` returns the stored array; this one has to do neither, because the stored payload
    is the command result and the entity must be reloaded. Worth deciding whether to follow the
    `SubmitSheet` shape (re-read the aggregate) or widen what `IdempotencyService` stores.
  - `deleteTransaction()` (T258) is also state-changing and also unwrapped; it is **not** in FR-042's list
    and is deliberately left out unless the contract says otherwise.

---

## Phase 49: Convergence (the DELETE replay contradiction — one root cause, two operations)

- [x] T318 [MEDIUM] Reconcile the contradiction between `contracts/workflow.md`'s Command Contract and
      `contracts/ocs-api.yaml` over whether a `DELETE` requires `X-PettyCash-Command-ID`, then align
      both delete code paths with whichever answer wins per Constitution Governance
      (`contradicts`; **decision required before any code is written**) — complete. **The maintainer
      decided: a `DELETE` is a repeatable command and is exempt from `X-PettyCash-Command-ID`.** The
      `workflow.md` branch wins; the two `$ref`s came out of `ocs-api.yaml` and the header requirement
      is gone from both operations. **Neither DELETE was wrapped** — that is what "exempt" means, and
      wrapping them would have been the other branch's answer. **The consequence the decision creates
  had to be dealt with, and this is the part that was easy to get wrong:** with the header gone and no
  wrapper, a retry reaches the service again, so *effect* idempotency became the operation's own
  responsibility. On the evidence path that was unmet — `deleteDraftEvidence()` is a soft delete and
  `Evidence::deactivate()` rewrites `deactivatedAt` unconditionally, so a repeat answered `200` while
  recording a **new** deactivation time. A recorded business fact that varies with retry count is
  worse than the header requirement was, and it was created *by* removing the header. Fixed with a
  guard on `!$evidence->active` before the write (`lib/Application/Evidence/EvidenceService.php:159`),
  deliberately placed **after** the file-store call so a repeat cannot short-circuit a binary cleanup
  the first call may have failed to finish. It is deliberately **not** a refusal: refusing would make
  the operation non-repeatable while the contract exempts it from the header that guards
  non-repeatable commands, reinstating the contradiction from the other side.
  `EvidenceController::destroy()` also now re-reads the row instead of formatting the pre-delete
  snapshot — the repository returns a fresh object per call, so the old code answered `200`
  "Deactivated evidence metadata" carrying `active: true`, and did so identically on both calls, which
  is the one shape under which the idempotency of the write could never be observed from the response.
  `TransactionService::deleteTransaction()` and `TransactionController::destroy()` are **unchanged**:
  the transaction DELETE has no wrapper and never gained one, and its command id was only ever used to
  label error payloads. `expectedVersion` still guards the concurrent-edit case on both.
  **Response semantics were read off the existing contracts, not invented:** transaction first call
  `204`, replay `422` (unchanged — a hard delete has no row left to be idempotent about, and giving it
  a success answer would be a new contract decision); evidence `200` with `Evidence` on both calls, now
  with identical bodies.
  **17 tests, 6 mutations, all killed.** `DeleteCommandIdExemptionTest` pins the *artifact* so the two
  files cannot drift apart again, and two of its mutations are the load-bearing ones — weakening
  `workflow.md`'s rule to "all state-changing commands", and flipping the shared
  `components/parameters/CommandId` to `required: false`. Both are invisible to any test that only
  looks at the two DELETE blocks, and either would un-exempt every delete in the contract while
  satisfying every other assertion. `EvidenceDeleteRepeatabilityTest` pins the service and compares
  `DateTimeImmutable` objects rather than `ATOM` strings: the controller formats with `ATOM`, which
  truncates to whole seconds, so two deletes inside one second would compare equal *against the exact
  defect the test exists to catch*. **One pre-existing failure left alone and verified as such:**
  `PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream` asserts
  `application/octet-stream` and gets `application/pdf`; it fails identically with every change in this
  task stashed, so it is not this task's regression and was not silently absorbed. Report:
  `docs/reports/T318.md`

  **One task, not two.** The two DELETEs are the same root cause with different symptoms, and a verb-bounded
  scan of `ocs-api.yaml` shows exactly two repeatable-verb operations in the entire contract reference
  `CommandId` — both of these. Nothing else is in scope. (A first, unbounded scan appeared to implicate
  `GET /configuration/currencies` and `GET /configuration/expense-types`; re-checking with each operation's
  segment bounded at the next verb key showed both are false positives, their `CommandId` belonging to the
  sibling `POST`/`PATCH` on the same path.)

  **The contradiction, verbatim.**
  - `contracts/workflow.md:14` — "**Non-repeatable** commands require `X-PettyCash-Command-ID` … Replaying
    the same command with the same canonical payload returns the first result."
  - `contracts/ocs-api.yaml:760-765` — the `CommandId` parameter is `required: true`, and it is `$ref`ed by
    `DELETE /transactions/{transactionId}` at **`:488`** and `DELETE /evidence/{evidenceId}` at **`:636`**.
  - A DELETE is repeatable, so on `workflow.md`'s wording it is exempt; on `ocs-api.yaml` it is required.
    The two authoritative artifacts disagree, and Constitution **Governance**
    (`.specify/memory/constitution.md:166`) says "When artifacts conflict, work MUST stop long enough to
    reconcile the conflict in the authoritative artifact rather than choosing silently." The code has in
    fact chosen silently: neither path is wrapped.

  **Why it is a single task with two symptoms.** Both operations are unwrapped, so neither is answered with
  its first result on replay. The observed behaviour differs only because of what the delete does:
  - `DELETE /transactions/{transactionId}` is a hard delete. First call **204**; a replay reaches
    `TransactionService::deleteTransaction()` (`lib/Application/Expense/TransactionService.php:389`), whose
    `findByPublicId()` now returns null and throws `InvalidArgumentException` (`:394`), which
    `OcsErrorMapper:44` maps to **422 VALIDATION_FAILED**. The status diverges.
  - `DELETE /evidence/{evidenceId}` is a **soft** delete — `EvidenceService::deleteDraftEvidence()`
    (`lib/Application/Evidence/EvidenceService.php:127`) calls `deactivate()` and saves, so the row survives.
    `EvidenceController::destroy()` (`:208-218`) *does* validate the required header, but never forwards it.
    Because `deleteDraftEvidence()` has no `!$evidence->active` guard — the one at `:164` belongs to
    `deactivateEvidence()`, a different method — a replay re-runs `Evidence::deactivate()`
    (`lib/Domain/Evidence/Evidence.php:30`), which unconditionally rewrites `deactivatedAt` with a new
    timestamp. Same status (200), different body, and a recorded business fact that depends on retry count.
    That is the worse of the two: the transaction replay produces a confusing error, the evidence replay
    quietly rewrites history.

  **Both replay behaviours are derived from the code paths above, not measured** — establishing them
  end-to-end would require writing tests, which this task's decision has to precede.

  **Severity.** Tagged MEDIUM to match how T275, the same class of unreconciled artifact conflict, is
  classified. It does touch the CRITICAL clause's "violates a constitution MUST principle", and it is
  raised as MEDIUM because there is no data-integrity exposure (no duplicate write, no duplicate audit row —
  `deleteTransaction()` writes no audit event at all), no P1 baseline functionality is blocked, and the
  repository's own precedent is MEDIUM. **A maintainer reading that CRITICAL clause literally should promote
  this, and T275 with it.**

  **What is NOT in scope, and must stay out.**
  - **Do not reopen or widen T317.** It completed the two `POST`/`PATCH` operations and recorded this
    exclusion deliberately ("not in FR-042's list and is deliberately left out unless the contract says
    otherwise"). FR-042 lists "recording a decision, changing workflow state, creating a revision, and
    recording final financial consumption" — a pre-submission draft delete is none of them, so FR-042 does
    not reach this path and T317 was right to leave it.
  - **T258** is unrelated: it made `deleteTransaction()` actually delete, and mentions no idempotency,
    command id, or replay.
  - The two statuses for a **missing** header are already settled and need no decision: a missing required
    *parameter* is 400 under the principle T317 established, distinct from the 422 used for invalid body
    fields. `DELETE /evidence/{id}` currently answers 422 for a missing header, which is the inconsistency
    T317 corrected elsewhere; the chosen option below should settle both.
  - `contracts/workflow.md:19-20`'s `expectedVersion` requirement already holds on both paths and is not
    in question. It is also **not** a substitute for replay safety: it refuses a *concurrent* edit, but a
    sequential retry resends the version the client originally read.

  **The decision, which is a product call and cannot be derived from the repository.**
  1. **Is a `DELETE` a non-repeatable command?** If yes, `ocs-api.yaml` stands, the two paths get wrapped in
     `IdempotencyService`, and the header requirement stays. If no, `workflow.md` stands and the two
     `$ref`s must be removed so the OpenAPI stops requiring a header the Command Contract does not.
  2. **If wrapped, what does a replay return, and how?** Neither `deleteTransaction()` nor
     `deleteDraftEvidence()` returns anything — both are `void` — so the closure must return something and
     the controller must translate it. The T317 `SubmitSheet` pattern does **not** transfer: it re-reads the
     aggregate, and on a delete the aggregate is gone. The alternative is widening what `IdempotencyService`
     stores, which is the same sub-decision T317 flagged and deliberately left open.
  3. **Is the soft delete's `deactivatedAt` rewrite acceptable at all?** If a replay is defined to return the
     first result, `deleteDraftEvidence()` still needs an `active` guard so the timestamp of the *first*
     delete is the one retained. If replay is defined as repeatable-and-idempotent, the guard is what stops
     the rewrite. This is the part with actual data consequences and it should not be decided as a
     side-effect of option 1.

  **Likely shape once decided** (not decided here): one constructor argument and one DI registration for
  each service, a `$commandId` on each method, the body moved inside the closure with its guard clauses, a
  `['deleted' => true]`-style stored response translated to the operation's declared success code (204 for
  the transaction, 200 with the evidence body for the evidence), and contract tests asserting replay
  equivalence for both.

---

## Phase 50: Convergence (the unowned AuditPage shape/pagination gap on `GET /transactions/{id}/audit`)

- [x] T319 [HIGH] Make `GET /transactions/{transactionId}/audit` return the contract's `AuditPage` —
      `items` plus `PageMeta`'s `page`, `pageSize`, `total` — and honour the `page`/`pageSize` query
      parameters the contract already declares but the endpoint currently ignores
      (`contracts/ocs-api.yaml:571-581`, `:913-922`, `:930-936`; contradicts) — complete. The
      endpoint returned a flat `array_map` list, so all four required members were absent, and
      `page`/`pageSize` reached no code despite `:574-575` declaring them.
      **`PageBounds` (`lib/Domain/Shared/PageBounds.php`) carries the bounds rather than a third copy
      of them.** The contract declares `Page`/`PageSize` once in `components/parameters` and
      `$ref`s them into every paged operation, so the bounds are one rule with many call sites —
      which is how T106 happened on the report surface and T319 happened again here. `ReportFilter`
      was **left alone**: it carries a dozen report-specific filters that mean nothing to an audit
      page, so sharing its numbers is right and sharing the class is not. `PageBoundsTest` asserts
      the two agree on every bound *and* on the refuse/clamp behaviour of each out-of-range request,
      so an extraction cannot become a fork.
      **`total` needed a count that did not exist.** `findByTransactionId()` is bounded by
      `setMaxResults`, so its return value can only describe the window it was given —
      `countByTransactionId()` was added to the port, counted in the database, with a predicate
      identical to the find's. The `count($items)` shortcut was mutation-verified to be caught, and
      it is the one that looks correct: all four `PageMeta` keys present, `200` answered, and a
      client that trusts the total stops after page 1 having silently missed every later event.
      `RetentionPolicyTest`'s append-only port-surface assertion **failed when the port grew** and was
      updated deliberately — the diff *is* the point of asserting that list as data.
      **`page`/`pageSize` are nullable, not `int`**, per T225: the `Dispatcher` coerces an absent
      query parameter to `null` and would throw an unhandled `TypeError` before the method body ran,
      returning a `400` with no `ocs` envelope — the exact failure T225 removed from this very
      controller. The absence is resolved inside the body where `PageBounds` applies the contract's
      defaults.
      **The revisions endpoint was deliberately left alone and is now guarded.** It has the identical
      bare-array shape and is **correct** — `:567` declares a plain `Revision` array with no
      `PageMeta`. `testTheRevisionsEndpointStillReturnsABareArray` exists so "make the audit page
      conform" cannot slide into "make every list conform"; wrapping it was mutation-tested and fails
      it.
      **`testListAuditReturns200WithAuditPage` still passes and still asserts only the status code.**
      It was left as-is on purpose rather than edited into passing: it is the evidence for *why* the
      gap read as covered, and the four new tests read `ocs.data` where it stops at `ocs.meta`.
      The paging test **records three real audit events through `PATCH` rather than assuming the
      fixture has any** — `ContractFixtures` seeds no `pettycash_transaction_audit` rows, so a test
      depending on them would prove nothing, and one depending on a prior suite's writes would be
      order-dependent, the failure the fixture exists to remove (`ReportAndExportControllerTest:36-43`
      states the same rule for the same reason).
      **20 tests, 7 mutations, all killed** — bare array, `page` ignored, `count($items)` as `total`,
      offset from the *requested* page size instead of the clamped one, `pageSize < 1` clamped rather
      than refused, and the revisions over-reach. `scripts/dev/phpunit` is 842 tests / 3691
      assertions / 1 failure, that failure being T318's pre-existing `application/octet-stream`
      assertion on `GET /evidence/{id}/content`, which is unrelated and untouched. Frontend: typecheck
      clean, lint 0 errors, `test:unit` 214/214. Report: `docs/reports/T319.md`

  **This gap was already owned, by nobody.** Recorded because that is the finding: the schema name
  `AuditPage` does not appear anywhere in `tasks.md` (`grep -c` returns 0), and the tasks that name
  "transaction audit" as a subject area do not name pagination, response shape, `AuditPage`,
  `PageMeta`, this endpoint's contract path, or `transaction#audit`.

  **The defect, as observed.**
  - `lib/Controller/TransactionController.php:322-336` — `audit(string $transactionId): DataResponse`
    takes **no** `page` and **no** `pageSize`, and returns `new DataResponse($formatted)` where
    `$formatted` is a flat `array_map([$this, 'formatAuditEvent'], $auditEvents)`. So `ocs.data` is a
    JSON array. None of `items`, `page`, `pageSize`, `total` is present, and `PageMeta` marks all
    three of the latter as `required`.
  - `contracts/ocs-api.yaml:574-575` declares `Page` (`minimum: 1, default: 1`) and `PageSize`
    (`minimum: 1, maximum: 100, default: 25`) on this operation. Both are accepted by the route and
    discarded — the parameters reach no code.
  - `contracts/ocs-api.yaml:581` declares the `200` as `$ref: '#/components/responses/AuditPage'`,
    which at `:913-922` is `PageMeta` (`allOf`) plus `items: AuditEvent[]`.

  **Three independent confirmations that this is a real contract divergence, not a reading of one.**
  1. **The frontend is already written to the contract.** `src/services/transactionsApi.ts:89`
     declares `listAudit(...): Promise<Page<unknown>>`, and `Page<T>` at `src/types/api.ts:2` is
     `{ items: T[]; page: number; pageSize: number; total: number }`. A consumer reading
     `data.items` gets `undefined` today. The reason this is latent rather than visibly broken is
     narrow and worth naming: `TransactionDetailView.vue:97` calls `loadAudit()` and discards the
     result, and `loadAudit` (`src/stores/transactions.ts:86`) returns rather than storing. The
     moment anything renders that response, the mismatch surfaces.
  2. **A test already claims this endpoint and passes while it is wrong.**
     `PurchaserFlowControllerTest::testListAuditReturns200WithAuditPage` (`:579`) sends
     `page=1&pageSize=25` and asserts status plus OCS envelope via `assertOcsResponseStructure`,
     which stops at `ocs.meta` and never inspects `ocs.data`. Compare
     `AccountControllerTest.php:222-228` and `ReportAndExportControllerTest.php:58`, which do assert
     `page`/`pageSize`/`total` on their endpoints. **That test is the reason this gap reads as
     covered, so fixing the shape without fixing the assertion leaves the next drift undetected.**
  3. **The paging capability already exists at the port and is simply unused.**
     `AuditEventRepository::findByTransactionId(int $transactionId, int $offset = 0, int $limit = 100)`
     (`lib/Domain/Audit/Repository/AuditEventRepository.php:16`) already takes offset and limit, and
     `DbAuditEventRepository` honours them via `setFirstResult`/`setMaxResults`
     (`lib/Infrastructure/Persistence/Audit/DbAuditEventRepository.php:89-90`). The controller calls
     it with one argument. So paging is a wiring omission, not a redesign.

  **What is deliberately NOT in scope, and why.**
  - **Authorization is already owned — T093** (`AuditQueryService`), **T092** (contract tests),
    **T099** (E2E). This endpoint currently authorizes through
    `TransactionService::getTransaction()`, which is owner-only
    (`lib/Application/Expense/TransactionService.php:442`), so a manager, finance officer, or admin
    gets `404`. That is a real gap against FR-009/FR-055 and it is **not this task's** to fix.
    Naming it here only so it is not lost: **T093 owns it.**
  - **T154 and T206 are umbrellas** over `T093`-`T100` and are not to be duplicated or counted as
    owners. Neither mentions pagination or response shape.
  - **`GET /transactions/{transactionId}/revisions` has the identical bare-array shape and is
    CORRECT** — `ocs-api.yaml:567` declares a plain `Revision` array for it, no `PageMeta`. Do not
    "fix" it as a drive-by; that would be a contract violation.
  - **Do not add a `configuration_audit` subject or change the report enum** — that is T112's recorded
    contract gap.

  **No product or specification decision is required.** `AuditPage` is fully declared; `Page` is
  `minimum: 1, default: 1`; `PageSize` is `minimum: 1, maximum: 100, default: 25`. The bounds
  question is already settled for the sibling surface by `ReportFilter`
  (`lib/Application/Reporting/ReportFilter.php:74` rejects `pageSize < 1`, `:93` clamps to
  `MAX_PAGE_SIZE = 100`), and T106's note records that the same trap was already sprung once on
  `ReportPage` — `items`/`offset` returned where the contract says `PageMeta` plus
  `columns`/`rows`/`summary`. **Follow that precedent rather than inventing a third page shape.**

  **`total` needs a count the repository does not expose.** No `countByTransactionId` or equivalent
  exists on `AuditEventRepository` or `DbAuditEventRepository`. Adding one is a port addition, which
  is an implementation detail rather than a contract change — but it must be added, and `total` must
  count the **whole** scoped set, not the returned page. `AccountControllerTest:228` already pins
  that distinction for its endpoint ("total counts the ledger, not the page") and is the precedent to
  copy.

  **Align with T093's direction; do not hard-code a second architecture into the current
  controller.** T093 builds `lib/Application/Audit/AuditQueryService.php` and T096 builds
  `lib/Controller/AuditController.php` — **neither file exists today**; the route is currently wired
  to `transaction#audit` (`appinfo/routes.php:29`). So the paginated query belongs in the
  `AuditQueryService` shape T093 introduces, and the response assembly belongs where the audit routes
  end up, rather than growing a parallel paged-read implementation inside `TransactionController`.
  If T093 has not landed when this is implemented, land the shape with the same port
  (`findByTransactionId`'s offset/limit plus a count) so the eventual service is a relocation and not
  a rewrite — **and say so in the report, so whoever runs T093 knows what they are picking up.**

  **Acceptance, all mechanically checkable.**
  - `GET /transactions/{transactionId}/audit` returns `ocs.data` carrying `items`, `page`, `pageSize`,
    `total`, with `page`/`pageSize`/`total` all present — asserted as data, not merely "not absent".
  - `page` and `pageSize` are read from the query and change the returned slice; a second page
    returns different `items` and reports a `total` describing the whole set.
  - `pageSize` beyond 100 is clamped to 100 and `pageSize < 1` is refused, matching `ReportFilter`.
  - `total` counts the whole scoped set, not the page.
  - `GET /transactions/{transactionId}/revisions` is **unchanged**, and the test suite proves it still
    returns a bare array — otherwise "make the audit page conform" becomes "make every list conform".
  - The contract test asserts the shape rather than only the status code, so the existing
    status-only assertion cannot survive as the endpoint's coverage.
  - Mutation-verified: restoring the bare array, ignoring `page`, and reporting `count($items)` as
    `total` each fail the suite. A change that only renames keys should not pass.

---

## Phase 51: Convergence (the audit timeline is newest-first, and US6/AC1 says it must not be)

- [x] T320 [HIGH] Return audit events in chronological order from every timeline read, so that
      submission is the first formal event a reader sees, per US6/AC1 and SC-006
      (`contradicts`) — complete, with **one acceptance criterion of this task found to be
      unreachable and deliberately not met here**; see the last paragraph. 5 new tests, 2 mutations,
      both killed.
      **The tiebreak was the substance, not a detail.** `occurred_at` is stored at second granularity
      (`DbAuditEventRepository` and every writer format `Y-m-d H:i:s`) and a submit followed by two
      approvals lands inside one second routinely. **The first version of the ordering test passed
      against the reversed implementation**: with all three events sharing one second, the
      `occurred_at` sequence was *ascending* while the events themselves were backwards, so a
      timestamp comparison could not detect the defect at all. The repository now orders by
      `occurred_at ASC, id ASC` through one `inTimelineOrder()` helper, and the test asserts the
      **event-type sequence** as well as the times — the half that actually discriminates. `id` is a
      monotonic autoincrement, so it is the insertion order, and it also makes the sequence total so
      T319's paged walk cannot repeat or skip an event at a page seam.
      **The three reads, decided as the task required rather than swept along.**
      - `findByTransactionId` and `findBySheetId` → chronological. These are US6/AC1's timeline and its
        "history table", and `findBySheetId` is what the sheet view renders beside revisions that
        already come back `revision_number ASC` — so ascending here and descending there put the two
        halves of one screen in opposite directions.
      - `findByDateRange` → **also chronological.** It has no production caller, so nothing is pinned
        either way, but it returns the same event stream over a bounded window; leaving it
        reverse-chronological would hand the next caller the identical defect, and a caller cannot
        tell from the signature that one method is a timeline and the other is not.
      - `findByActorUid` → **left `DESC`, deliberately.** `ReportController:518` is its only caller and
        hands the rows to the report machinery, which does not re-sort them. A bounded report read
        newest-first is the conventional order, and **no artifact states an order for reports** —
        US6/AC1 and SC-006 are about the timeline and history table, and FR-065/FR-066 speak of report
        *contents*, not sequence. Changing it would be a silent behaviour change to a completed surface
        (T106, T112) on the strength of a requirement that does not reach it. The reasoning is recorded
        in the method's docblock so it is not mistaken for an oversight.
      **The test generates events rather than assuming them.** `ContractFixtures` deletes
      `pettycash_transaction_audit` rows and inserts none, so a test expecting events would walk an
      empty set and prove nothing. The first helper tried to add events with `PATCH` after the submit
      and every test failed `422 "Cannot update transactions in a submitted sheet"` — which is correct:
      FR-026 forbids editing after submission and the correction path is the only exception. Decisions
      are used instead, which are post-submission events, and they produce a second event type so the
      ordering is checked *within* a type as well as across types.
      `scripts/dev/phpunit` is 871 tests / 3992 assertions / 1 failure, that failure being T275's
      pre-existing `application/octet-stream` assertion on `GET /evidence/{id}/content` — **which T275
      already owns**, not an unowned gap. Frontend: typecheck clean, lint 0 errors, `test:unit` 228/228.
      **Not delivered, and this task's own acceptance criteria were wrong about it.** The task required
      the first item to be "the transaction's `SUBMITTED` event". It cannot be, and the reason is a
      **different root cause in a different method**: `SubmitSheet.php:121` writes the `SUBMITTED`
      event with `'transaction_id' => null`, because submission is a sheet-level fact, so a read filtered
      on `transaction_id` never sees it. The transaction's own history therefore opens with `ROUTED`,
      not with the submission that caused it — which is exactly what `FR-051` forbids ("formal
      transaction audit MUST begin with sheet submission") and what US6/AC1 states for the timeline.
      **No task owns this** (`grep -niE "transaction_id.*null|sheet-level|submission event"` over
      `tasks.md` returns nothing relevant), and it is **not** fixed here: folding it in would make this
      task untestable as an ordering change and would put a decision about sheet-versus-transaction event
      scope inside a one-line sort. The tests assert the current first event (`ROUTED`) and name the
      gap in their docblocks rather than quietly assuming a fix. **It needs its own task, and the next
      convergence should raise it.** Report: `docs/reports/T320.md`

  **What the artifacts say.** `spec.md:213-216` (US6/AC1): "Given a sheet is submitted, When an
  authorized user views a transaction timeline or history table, **Then submission is the first formal
  event and every later official event is shown in chronological order.**" `spec.md:647` (SC-006):
  "100% of official events in the acceptance workflow appear in **chronological history** with the
  actor, role, stage, time, event, explanation, and applicable before-and-after values."

  **What the code does.** All four timeline reads in `DbAuditEventRepository` order `occurred_at DESC`
  — `findByTransactionId()` at `:88`, `findBySheetId()` at `:127`, `findByActorUid()` at `:141`,
  `findByDateRange()` at `:156`. So the submission event, which by definition happened first, is
  rendered **last**.

  **The inconsistency is visible on a single screen.** `DbTransactionRevisionRepository:56` orders
  `revision_number ASC`, so `GET /transactions/{id}/revisions` returns the oldest revision first while
  `GET /transactions/{id}/audit` returns the newest event first. T319 made the audit endpoint conform
  on *shape* — `items` plus `PageMeta` — and deliberately left ordering alone, recording that the choice
  "wants a maintainer decision". **It does not.** Chronological order is what the acceptance scenario
  states, and the scenario is more specific than the general "newest first is friendlier" reading: it
  names submission as the *first* event, which is only true ascending.

  **This was not found by a failing test, and could not have been.** The audit table in
  `SheetDetailView.vue:255` is `v-if="false"` with no data binding — it renders no rows at all — so no
  user-visible symptom exists yet, and no test asserts an order (`grep -rln "chronological" tests/`
  returns nothing). The requirement is therefore **unexercised rather than met**, which is the harder
  of the two to notice: the endpoint answers `200` with a well-formed `AuditPage` that is ordered
  backwards. `US6/AC1` is satisfied only when the table is wired, so the gap must close before then,
  not after.

  **What to do.** Order ascending on the reads that back a timeline (`findByTransactionId`,
  `findBySheetId`). `findByActorUid` and `findByDateRange` are report inputs, not the timeline — T106's
  `ReportQuery` sorts its own output and `ReportController:518` reads `findByActorUid` with an explicit
  bound, so **decide those two on their own merits rather than sweeping them into the same change**, and
  say which was chosen and why.

  **Acceptance, mechanically checkable.**
  - `GET /transactions/{transactionId}/audit` returns events in ascending `occurredAt`, and its first
    item is the transaction's `SUBMITTED` event after a submit.
  - Ties are broken deterministically. `occurred_at` is stored at second granularity
    (`EvidenceService` and `DbAuditEventRepository` both format `Y-m-d H:i:s`), and a submit plus an
    approval inside one second is ordinary — so without a secondary key the order of same-second events
    is undefined and the test would be flaky rather than wrong. Decide and record the tiebreak.
  - A contract test asserts the order by reading the events, not by asserting a status code. T319's
    paging test walks every page and compares ids; this one must additionally assert that the sequence
    is ascending, so a future `DESC` cannot be reintroduced silently.
  - Paging is unaffected: `total` still counts the whole set and `page`/`pageSize` still bound the
    window, so this changes the sequence within the set, not how much of it is reachable.
  - Mutation-verified: restoring `DESC` fails the ordering assertion.

  **Deliberately not folded in.** The frontend audit table is not wired, and wiring it is T098's.
  Changing the order without rendering anything is correct and cheap; wiring the table and its
  ordering together would put a component decision inside a repository-ordering task.

  **Record only — convergence is append-only.** T070 and T071 are marked `[x]` with no completion note
  at all, and T069 proved by measurement that the behaviour they name was not delivered: the
  correction draft was never attached to its transaction, so resubmission activated the revision being
  replaced. That was fixed under `docs/reports/adhoc-correction-activation.md`, which supersedes both.
  This is noted because the tasks themselves cannot be amended from here, and a reader of T070 or T071
  would otherwise take an unchecked claim at face value — the same mistake T298's
  `TaskStateConsistencyTest` was written to catch.

## Phase 52: Convergence

- [x] T321 [HIGH] Decide what FR-051's "formal transaction audit MUST begin with sheet submission"
      means for a submission event that `data-model.md:314` explicitly allows to be sheet-scoped
      (`transaction_id` nullable, "event context"), then make a transaction's audit actually begin
      with that submission per FR-051 and US6/AC1 (`contradicts`). No product decision has been
      made, and **this task must not assume one** — the correct resolution is a judgement about
      event scope, not a mechanical fix, and the two artifacts currently disagree about what the
      timeline contains.
      **Measured, not inferred.** `SubmitSheet.php:119-120` writes the `SUBMITTED` event with
      `sheet_id => $sheet->dbId` and `transaction_id => null`, with `entity_type => 'sheet'` and
      `entity_id => $sheetId`. `DbAuditEventRepository.php:113` and `:141` filter the transaction
      timeline on `transaction_id = :id`, so a read of a transaction's history can never return
      that event. The transaction's audit therefore opens with `ROUTED` — the routing that
      *followed* submission — and never contains the submission at all. This is the specific
      defect T320 recorded as undelivered: T320 fixed the *order* of the timeline and its own
      acceptance criterion asked for `SUBMITTED` first, which was unreachable for this reason.
      **The blast radius is narrower than it first looks, and that matters for the fix.** The
      sheet's history table reads through `findBySheetId`, which filters on `sheet_id` — a column
      that *is* populated — so the sheet view's history already contains `SUBMITTED` and is
      correct. Only the per-transaction timeline is affected. T320's ordering change is likewise
      already correct and must not be disturbed; this task changes *which rows are visible*, not
      their sequence.
      **Why this could not simply be fixed by setting `transaction_id` on the existing event.**
      A sheet submission is one fact about one sheet that covers *every* transaction in it.
      Writing it once per transaction would record the same submission N times and would also make
      `entity_type => 'sheet'` / `entity_id => $sheetId` a lie, since the row would then be
      transaction-scoped. At least three resolutions are defensible, and the artifacts do not
      choose between them:
      - **(A) Fan the event out** — write one `SUBMITTED` event per transaction with
        `transaction_id` populated. The transaction timeline becomes self-contained and needs no
        read change, at the cost of duplicating a sheet-level fact across rows and
        contradicting the `entity_type`/`entity_id` snapshot already written.
      - **(B) Fold the sheet's events in at read time** — keep the single sheet-scoped event and
        have the transaction timeline union in its sheet's events. One fact, visible in every
        transaction's history. The read stops being a simple `transaction_id =` filter, and any
        change to it **must preserve T319's `PageMeta` totals**, which are counted by a predicate
        that is currently identical to the read's (see `DbAuditEventRepository::countPage`) — a
        widened read with an un-widened count would return a total that contradicts the rows on
        the page, which is the exact failure mode T319's docblock warns about.
      - **(C) Narrow the requirement** — accept that a transaction's own history begins with
        routing, and amend FR-051 / US6/AC1 to say so. This is legitimate **only** as an explicit
        artifact amendment with the reasoning recorded, never as a silent reading.
      Whichever is chosen, the task must state it in `FR-051` and the user story so the next
      reader is not left re-deriving it, and must amend `data-model.md`'s "Event context" wording
      if the resolution is (A) or (C).
      **Required of the implementation.** A contract test that submits a sheet with two
      transactions and asserts each transaction's audit timeline begins with its submission event
      (or, under (C), asserts the narrowed contract that is adopted). The test must generate its
      events through the real submit + decision flow as `AuditTimelineOrderTest` does, not by
      inserting audit rows directly, and must be a **mutation-verified** regression: the test must
      fail if the resolution is reverted, and the appended history must also make T320's
      type-sequence assertion non-vacuous, since today that test's first event is `ROUTED`.
      **Do not reintroduce the mutation-testing error from T320** — its `/tmp` backup captured an
      already-mutated file, so a restore silently left the suite at 4 failures; verify the restore
      before trusting a result.
      Verification bar: `scripts/dev/phpunit` at 0 new failures and 0 skipped / 0 incomplete, with
      the decision recorded in `docs/reports/T321.md`. Cross-references: T320 (ordering, delivered),
      T167 (nearest task — it completes `SubmitSheet`'s audit events for decisions, returns,
      corrections and evidence deactivation, but it is traced to US1 and says nothing about the
      `SUBMITTED` event's linkage, so it does not own this), T319 (`PageMeta` totals), T098 (the
      frontend audit table, still unwired at `SheetDetailView.vue:255`).
      ---
      **Delivered: resolution (B), chosen with the maintainer rather than assumed.** The question put was
      how a transaction's timeline comes to contain a fact that belongs to its sheet. (B) was chosen — keep
      the single sheet-scoped event and widen the read — over (A) fanning the event out per transaction
      (which would record one submission N times and contradict the `entity_type`/`entity_id` snapshot on
      the row) and over (C) narrowing FR-051 to start at `ROUTED` (which would weaken a MUST the spec
      states). **This entry therefore supersedes T320's closing note**, which recorded the gap as
      unowned and undelivered; that was accurate when T320 finished and is now closed. T320's own task
      text is left as written rather than edited, so the sequence of decisions stays readable.
      `DbAuditEventRepository` gained `applyTransactionTimelineScope()`, which the read **and** the count
      both call — the single predicate is what makes T319's "a `total` must describe its own page"
      structurally true rather than a convention. The sheet id is resolved with one indexed lookup
      (`sheetDbIdFor()`) instead of a correlated subquery, because `IQueryBuilder`'s expression API
      cannot build a subquery without hand-assembling the table prefix, and a hand-assembled prefix is
      correct only until the deployment's `dbprefix` differs.
      **Two mutations, both killed, and the first is the one that mattered.** Widening only the count
      while leaving the read narrow — the exact drift T321 predicted — was caught by
      `testTheTotalCountsTheSheetScopedSubmission` with `total=2` against 1 item, and reverting both
      sides was caught by 6 tests across T321's and T320's suites. The restore was verified
      byte-identical, unlike T320's, whose `/tmp` backup had captured an already-mutated file.
      **What the test could not do, and the finding it produced.** T321 asked for a sheet with two
      transactions. **The API cannot build one**: `TransactionController::create()` (`:55`) accepts only
      `expenseDate` and `amountMinor` — no cost-centre parameter — and `SubmitSheet.php:100` refuses to
      submit a sheet whose any transaction has no cost centre (`422 "Transaction missing cost center"`).
      So an API-created transaction can never be routed, and no test can produce a two-transaction
      submitted sheet. That is a real gap in the create endpoint's surface, but it is a different concern
      from the audit read, and closing it means adding a parameter to a contract endpoint, so it was
      **recorded and not done here** — the next convergence should raise it. The single-fact property is
      instead guaranteed structurally: the predicate's `transaction_id IS NULL` clause makes a
      sheet-scoped event singleton by construction, however many transactions the sheet later gains.
      Two further test-design notes: the sheet-side history has **no OCS route at all**
      (`appinfo/routes.php:29` exposes only `transaction#audit`), so its non-duplication is protected
      structurally — `findBySheetId()` never calls the widened helper — rather than by an assertion that
      could not have been written; and the contract fixtures are shared per process, so these tests
      reseed with `ContractFixtures::prepare()` in `setUp()`.
      Result: **875 tests / 4010 assertions / 1 failure** (T275's pre-existing evidence content-type
      one) / 0 skipped / 0 incomplete, from 871 before. `TransactionTimelineSubmissionTest` 4 tests;
      `AuditTimelineOrderTest`'s three hardcoded sequences now lead with `SUBMITTED`, which is what T321
      meant by making T320's assertion non-vacuous — both reverts above are caught by it.
      Report: `docs/reports/T321.md`

## Phase 53: Convergence

- [x] T322 [HIGH] Give the `pettycash-e2e` account its own fixture data, because the contract seeder
      is single-user and silently overwrites another account's fixtures, which blocks every browser
      test in T054 and T171 (T171, T054) (`missing`).
      **The collision is measured, not inferred.** `tests/Contract/Fixture/seed-contract-fixtures.php`
      hard-codes every fixture identifier — `11111111-…`, `22222222-…`, `44444444-…` through
      `88888888-…` — so it is a *single-user* fixture set by construction. Seeding `pettycash-e2e`
      therefore writes the **same rows** as `employee1`'s, and whichever seeding ran last wins.
      Observed sequence: seeding the e2e account reported success and `GET /accounts` then answered
      `total: 2`; a later PHP contract run re-seeded `employee1`; the e2e account's accounts were gone
      and the sheet form rendered `sheets.noAccounts` with the account `NcSelect` disabled
      (`:disabled="loadingAccounts || !hasAccounts"`, `SheetFormView.vue:176`). That is also why the
      e2e account needs a **cost centre and an expense type**, or no transaction on it can be routed
      (`SubmitSheet.php:100`, `422 "Transaction missing cost center"`).
      **Do not reuse the contract seeder for this, and do not parameterise its IDs.** Two suites
      writing overlapping rows is the failure being fixed, and `T278` already made this seeder a true
      reset for the contract suite's benefit. The right shape is a separate, E2E-owned seeder with its
      own distinct ID range and a manifest, so the two cannot collide even by accident.
      **`employee1` is not an acceptable substitute**, and `global-setup.ts` already says why: the OCS
      contract suite authenticates as `employee1` and depends on that account's role assignments and
      seeded balances, so repointing the browser suite at it (or resetting its password to serve it)
      would trade a visible browser failure for a broken contract suite.
      Required: the seeder, a documented invocation, and a check that running it leaves `employee1`'s
      fixtures byte-identical. Cross-references: T054, T171, T278, and T098 for the separate reason
      below.
      ---
      **Delivered: `tests/E2E/seed-e2e-fixtures.php`**, a separate seeder with its own ID namespace
      (`e2e00000-…-00000000000{1..7}`, a visibly different shape from the contract seeder's `11111111-…`
      so a row's owner is identifiable at a glance). It seeds a currency, cost centre, expense type,
      account, two role assignments and a draft sheet for `pettycash-e2e`, and is idempotent.
      **The collision is empirically gone.** After this seeder ran, re-running the contract seeder for
      `employee1` left the e2e account intact (`e2e00000-…-000000000004`, `total: 1`), and the two
      accounts coexist: employee1 keeps `44444444-…`/`88888888-…`, the e2e user has only its own. The
      original symptom — the e2e account's rows silently replaced by a later PHP run — no longer occurs.
      **Isolation is asserted by the seeder, not merely intended.** `assertDoesNotDisturbOtherUsers()`
      snapshots every row outside `E2E_IDS` before and after, and exits non-zero naming the offender.
      Mutation-verified: modifying a foreign account in place produced
      `This seeder must not touch rows outside its own ID namespace, but it changed:
      pettycash_accounts:44444444-…` with exit 1. `otherUsersUntouched: true` is therefore a claim the
      seeder checks, not one it prints.
      **The snapshot had to be built from the schema, twice, after guessing wrong twice.** The first
      version compared `public_id` + `updated_at`; `pettycash_sheets` has no `updated_at` at all, so the
      query raised a `DbalException` that **Nextcloud's CLI handler swallowed into `nextcloud.log` and
      terminated with status 0** — every run reported success, printed nothing, and wrote no rows. The
      only way to see it was to read the server log by hand. Two further schema errors surfaced the same
      way once visible: `pettycash_sheets` has `status` (no `state`) and `lock_version` (no `version`),
      and `pettycash_expense_types` has **no `code` and no validity dates** — which also made the `E2E-`
      code prefix I had written for the cost centre unnecessary for expense types, though still
      required for cost centres, where `code` is unique and the contract seeder owns `CC-100`.
      **The lasting change is the top-level `try`/`catch` plus a `set_error_handler` that promotes
      warnings.** The seeder now reports the class, message and origin on stderr and exits 1. It cannot
      exit 0 having done nothing, which is the only property that matters for a fixture: a seeder that
      fails open turns every downstream test into a misleading "missing record" instead of a setup
      error. It earned its keep immediately — the first run after adding it surfaced
      `TypeError: Return value must be of type int, string returned`, because Nextcloud's
      `IQueryBuilder::PARAM_STR` is a *string* constant and my closure declared `: int`.
      **What this does not deliver.** The Playwright suite still fails, and the remaining failures are
      **T171's**, not this task's: the suite now gets past the account `NcSelect` (previously disabled
      because the e2e user had no accounts) and fails later, on `sheet-list-item` not appearing after the
      create form is submitted. Separately, the audit assertion remains impossible until T098 wires the
      audit table. T322's scope was fixture *data*; it is delivered and unblocks T054 and T171 without
      absorbing their work. Cross-references: T054, T171, T278, T098.
- [x] T323 [HIGH] Replace the 7 physical-direction CSS declarations in 4 RTL views with their logical
      equivalents, so FR-071's logical alignment holds rather than holding by coincidence (FR-071)
      (`contradicts`). Found by T116's gate, which is the test that keeps them from returning.
      **Measured:** `text-align: right` at `SheetDetailView.vue:419`, `SheetListView.vue:214` and
      `TransactionListView.vue:157` (each on `.nc-table th, .nc-table td`), and `margin-right: 0.5rem`
      at `SheetDetailView.vue:465`, `SheetListView.vue:267`, `TransactionDetailView.vue:627` and
      `TransactionListView.vue:207`. Logical replacements are `text-align: start` and
      `margin-inline-end`.
      **Why a declaration that currently renders correctly is still a defect.** In a `dir="rtl"` block
      `text-align: right` happens to coincide with the start edge, so nothing looks broken today. That
      coincidence is the problem: it breaks the moment a block renders LTR, and it overrides the
      browser's own bidi handling for mixed-direction content, which is the mechanism FR-071's Persian
      layout depends on. The app is Persian-only *today*, which is precisely why this can rot unnoticed
      — the defect is latent, not cosmetic.
      **The gate that finds them is already written and is proven satisfiable**: T116's
      `RtlAccessibility.spec.ts` fails on exactly these 7, and turning all 7 into logical spellings was
      verified to make that test pass, so this task is a bounded 7-line change with a green gate behind
      it. Do not add a breakpoint or dead CSS while here — one assertion T116 had to be corrected out
      of demanded a `max-width` rule the app does not need, because its layout is mobile-first.
      ---
      **Delivered: 7 declarations, all replaced.** `text-align: right` -> `text-align: start` at
      `SheetDetailView.vue:419`, `SheetListView.vue:214`, `TransactionListView.vue:157`; `margin-right:
      0.5rem` -> `margin-inline-end: 0.5rem` at `SheetDetailView.vue:465`, `SheetListView.vue:267`,
      `TransactionDetailView.vue:627`, `TransactionListView.vue:207`. T116's alignment gate now passes,
      and the **whole frontend suite went from 234/237 to 237/237** — the three T116 failures were these
      7 declarations, the two error states, and the four pinned components, and nothing else was red.
      **Mutation-verified per fix, not just per suite.** Reverting all three fixes together was caught by
      2 of 3 gates, and the one that slipped exposed a genuine weakness in the gate rather than in the
      fix — see T324. No breakpoint or dead CSS was added, as this task required.
- [x] T324 [MEDIUM] Announce the failed-load error states in `AccountView` and `FinanceReviewView` with
      a live region, so a screen-reader user is told the load failed (FR-075, US8 accessibility)
      (`partial`). `AccountView.vue:30` and `FinanceReviewView.vue:39` each render a detached
      `<p class="…__error-message">{{ error }}</p>` as the view's *entire* failed state, with no
      `role="alert"` and no `aria-live`. `AccountView.vue:97` has the same shape for the movements
      ledger. The text is visible, so FR-075's "shows a clear error" is literally satisfied — what is
      missing is that the message is *announced* and associated with anything.
      The pattern to copy is already in this codebase and is not a new convention: `SheetDetailView.vue:272`
      uses `role="alert"` with `aria-live="assertive"` for a refused submission, and
      `TransactionFormView.vue:249-254` uses a `role="alert"` + `aria-live` validation summary.
      **Do not wrap these in a component or a shared composable for two call sites.** `DecisionForm.vue`
      is deliberately *not* in this task's scope: it delegates the message to the control's own `error` +
      `helperText` pair, so `@nextcloud/vue` already associates the text with the input. T116's gate
      flagged it at first and that was a **false positive in the gate** — it matched the phrase
      `error-message` inside the very comment explaining the prop does not exist. Its docblock now
      strips comments before scanning; do not reintroduce the naive scan.
      T116's `announces validation errors in a live region` test is the acceptance criterion.
      ---
      **Delivered: `role="alert"` + `aria-live="assertive"` on three error states** — `AccountView.vue:32`
      (account load), `AccountView.vue:97` (movements ledger), `FinanceReviewView.vue:39`. The
      movements error keeps its own region so the two are not conflated, which FR-075 requires: a ledger
      that failed to load must not read as "you have no account".
      **The gate that was supposed to catch this was too weak, and finding that out was the substance
      of this task.** T116's assertion was *file-level* — "does this file mention an error, and does it
      contain a `role="alert"` anywhere?" — and mutation testing proved it: `AccountView.vue` renders
      **two** error elements, so stripping the live region from one still left the other in the same
      file and the assertion passed with the defect reinstated. **A test that cannot fail when the fix
      is reverted is not a gate.** It is now counted per render site.
      **And the strengthened version failed too, on a second count of my own.** The first repair counted
      `role="alert"` and `aria-live=` as two separate live regions, so a single element carrying *both*
      attributes — the correct spelling — counted as two and the check still could not fail. It now
      matches the whole opening tag and counts it once. Re-running the original mutation then produced
      `AccountView.vue: 2 error element(s) but only 1 live region(s)`, which names the file, the counts,
      and what to do. Both defects were in the *test*, and neither would have been visible without
      actually attempting the revert.
- [x] T325 [LOW] Stop the four review components from pinning `direction: rtl`, and let them inherit it
      from the view that mounts them (US8 accessibility) (`partial`). `RevisionInfo.vue:97`,
      `EvidenceList.vue:48`, `DecisionForm.vue:146` and `PriorDecisionHistory.vue:94` each declare
      `direction: rtl`, while `EvidenceCard`, `EvidenceList`'s sibling components, `SettlementLabel`,
      `StatusBadge` and `TransactionHeader` declare nothing. The inconsistency is the defect: a
      component that pins direction duplicates its view's declaration, cannot be reused in the other
      direction, and makes the next author copy whichever they happened to open first.
      T116's `does not pin a direction inside a component` test is the acceptance criterion. Keep this
      separate from T323 even though both are "RTL spelling" — T323 changes what a *view* declares,
      this changes whether a *component* declares anything, and merging them would make a small,
      safe change look like a layout change.
      **The eleven `.vue` files that have no `dir` at all are not findings.** A component is *supposed*
      to omit it and inherit from its view; only views must declare one, and all 15 do.
      ---
      **Delivered: the `direction: rtl` declaration removed from all four components** —
      `RevisionInfo.vue`, `EvidenceList.vue`, `DecisionForm.vue`, `PriorDecisionHistory.vue`. Each style
      block keeps its other rules; only the pinned declaration and its now-empty rule were removed.
      Kept separate from T323 as this task required: T323 changes what a *view* declares, this changes
      whether a *component* declares anything, and merging them would make a safe change look like a
      layout change.
      **The eleven `.vue` files with no `dir` were left alone**, correctly — a component is *supposed* to
      inherit from its view, and all 15 views declare one (T116's `marks every view as RTL` passes).
      Mutation-verified: re-adding `direction: rtl` to `RevisionInfo.vue` is caught by
      `does not pin a direction inside a component`.
## Phase 54: Convergence (the purchaser journey has no way to read a sheet's transactions)

Found by the T171 pass of 2026-10-01, which got the Playwright chain running for the first time and
stopped on two API gaps. **Neither has an owner.** `grep` over the whole ledger finds no task that either
creates `GET /sheets/{sheetId}/transactions` or asserts that `GET /sheets/{sheetId}` returns the two
properties the approved contract requires of it, and Phase 30's `route integrity` audit did not catch
either because both are *absences* — a route that was never added and a payload key that was never
written — rather than a route pointing at a method that does not exist, which is what that audit looked
for.

The two are one defect seen from two ends. A sheet detail view needs its transactions; the contract says
they arrive **inside** the sheet payload, and the frontend's store reads them from **there**
(`src/stores/sheets.ts:14`). Nothing ever put them there, and nothing else supplies them either.

- [x] T326 [CRITICAL] Give a sheet's transactions a route, or put them in the payload the contract already
      requires, because **a purchaser can open a sheet and its transaction list can never load** — the
      view's only source for them answers `405` and no alternative exists
      (T171, `SheetListView.vue`, `SheetDetailView.vue`, `contracts/ocs-api.yaml`)

      **Measured, in a signed-in browser, on the transaction form journey.** `src/stores/transactions.ts:48`
      calls `listTransactions(sheetId, { page, pageSize, filters })`, which issues
      `GET /ocs/v2.php/apps/pettycash/api/v1/sheets/{id}/transactions?page=1&pageSize=25` and receives:

      ```
      405 Method Not Allowed
      ```

      with an **empty body**. The AppFramework found the path and no verb. `appinfo/routes.php:22` declares
      only `['name' => 'transaction#create', 'url' => '/api/v1/sheets/{sheetId}/transactions', 'verb' => 'POST']`,
      and `contracts/ocs-api.yaml:430` declares only `post:` under `/sheets/{sheetId}/transactions`. So this
      is not a wrong verb on a registered route: **the `GET` was never written, anywhere.**

      **Why the consequence is larger than a missing list.** `SheetDetailView` is where a purchaser adds a
      transaction and submits a sheet, and `SheetDetailView.vue:201` renders
      `v-for="item in transactionStore.transactions"` — the array this call fills. So after
      `POST /sheets/{id}/transactions` returns **201** with the created transaction in its body, the
      purchaser is navigated back to a sheet whose table is empty, because the reload that would show it
      is the call that 405s. The create works; the confirmation of the create cannot exist. That is also
      why the E2E journey `should add a draft transaction with minimum data` fails on
      `element(s) not found` for `[data-test="sheet-transaction-row"]` **after** a successful save.

      **The 405 is also why a `pettycash.error.network` alert was on the page** in every browser run during
      the T171 pass. `src/services/http.ts:60` classifies a non-2xx with a body that is not an OCS
      envelope as `NETWORK_FAILURE`, which is the correct reading of an empty body — the alert was
      reporting a real fault, and the fault was this one. Do not "fix" the alert.

      **There is a decision here, and it is not the implementer's to make silently.** The contract already
      says where a sheet's transactions belong: `SheetDetail` (`ocs-api.yaml:1125`) is
      `allOf: [SheetSummary, { allowedActions, transactions: TransactionSummary[] }]`. Two shapes are
      therefore defensible, and they are not equivalent:
      - **(a) Honour the contract** — populate `transactions` on `GET /sheets/{sheetId}` and delete the
        store's call to the non-existent list route. One round trip, and `canSubmit` starts working, which
        is what **T327** needs. The cost: an unbounded array in a detail payload, so it needs the same
        role-scoped limit `SheetService` already applies elsewhere, and a decision about what "the last 25
        transactions" means for a sheet that has 400 of them.
      - **(b) Add the list route** — `GET /sheets/{sheetId}/transactions` as a paged, role-scoped endpoint,
        and change the contract to say so. Keeps the detail payload small and makes the endpoint reusable
        by `TransactionListView`, which needs the same data. The cost: a contract **amendment**, and the
        `SheetDetail.transactions` property then has to be reconciled with it rather than quietly ignored —
        which is the T124/T275/T312 ordering problem one layer down.

      Whichever is chosen, **`canSubmit` must end up derivable**, or T327 cannot be fixed and the Submit
      button stays absent. If (a), the store needs no change; if (b), `src/stores/sheets.ts:14` must stop
      reading `currentSheet.transactions`.

      **Acceptance criteria.** A signed-in purchaser who creates a sheet, adds a draft transaction and
      returns to the sheet sees that transaction in the table, from a **200**. Add a contract test that
      asserts the shape actually returned — this is the assertion whose absence let the gap ship, and
      `grep -rn "allowedActions\|'transactions'" tests/` returns exactly one backend hit
      (`FinanceSettlementTest.php:662`, on a *transaction* detail) and **no test at all** for
      `GET /sheets/{id}`. A route-dispatch completeness check must fail while a frontend service method
      addresses a path no route serves. Cross-references: T171, T327, T054, T066, T076, T114, T124.

      ---
      **Delivered: option (a), honour the contract.** `GET /sheets/{sheetId}` now returns the sheet's
      transactions inside the payload the approved contract already specifies, and the client's 405
      call is gone rather than left beside it.
      **The decision this task refused to make silently, made and recorded.** `SheetDetail.transactions`
      is what `contracts/ocs-api.yaml:1125` already declares, so filling it needs no contract amendment
      and no second request. Option (b) — adding `GET /sheets/{sheetId}/transactions` as a paged
      role-scoped endpoint — remains defensible and is **not** rejected on merit: it keeps this payload
      small and serves a list view directly. It was not taken because it is a contract amendment, and
      this repository requires an amendment to be owned, ordered against T124's type regeneration, and
      reconciled with `SheetDetail.transactions` rather than left in place beside it. That work is
      recorded as follow-up, not silently dropped.
      **What changed.** `SheetPayloadFormatter::detail()` composes `summary()` with `transactions` and
      `allowedActions`; `SheetController` returns it from **all four** single-sheet responses, because
      the contract declares `SheetDetail` — not `SheetSummary` — for create (`:368`), detail (`:381`),
      patch (`:405`) and submit (`:424`), and `SheetSummary` appears only inside `SheetPage` (`:902`).
      Four of the four were wrong in the same way; fixing one and leaving three would have been a
      partial repair of a single defect.
      **The array is not truncated, and that is deliberate.** `SheetDetail.transactions` has no
      pagination parameters and no truncation flag, so any cap added here would silently hide a
      transaction from the purchaser who must review the whole sheet before submitting it (FR-024) — an
      undeclared truncation reads as a complete list. `transactionCount` states the true total in the
      same payload, so a client can always detect the size.
      **On the client, the dead path is deleted rather than left dormant.**
      `transactionsApi.listTransactions`, `transactionsStore.loadTransactions`, the `pagination` and
      `filters` refs that existed only to serve it, and a **duplicate `TransactionFilters` declaration**
      in `transactionsApi.ts` are all gone. Both `SheetDetailView` and the routed `TransactionListView`
      read `sheetStore.currentSheet.transactions`, so there is one request and one source instead of a
      sheet fetch followed by a guaranteed 405.
      **The check that would have caught this is written and mutation-verified.**
      `tests/Unit/Architecture/RouteDispatchCompletenessTest.php` compares every path the client can
      issue against the routes that answer, and it is proved able to fail: reintroducing the exact call
      this task removed produces
      `GET /sheets/${sheetId}/transactions (src/services/transactionsApi.ts)`. Writing it caught two
      bugs in the check itself, which is the argument for having written it — `$this->APP_ROOT` on a
      private constant resolves as a *property* in PHP 8.5 and was silently empty, and the first path
      pattern kept one entry per verb and dropped the rest. Both were caught by the vacuity guard in its
      second test, which is why that guard is there.
      **One ordering defect fixed while reading the query.** `findBySheetId()` ordered only by
      `created_at`, which has second precision, so two transactions drafted in the same second came back
      in storage order — a purchaser's table reordering itself between two reloads of an unchanged
      sheet. `id` is now the tiebreaker.
      Cross-references: T171, T327, T054, T066, T076, T114, T124, T344.

- [x] T327 [CRITICAL] Return `transactions` and `allowedActions` from `GET /sheets/{sheetId}`, because the
      contract requires both and the response contains neither, which makes **the Submit button impossible
      to render** (T171, `contracts/ocs-api.yaml`, `src/stores/sheets.ts`) (missing)

      **Measured against the live API as `pettycash-e2e`:**

      ```
      GET /ocs/v2.php/apps/pettycash/api/v1/sheets/{id}
      top-level keys: [completedAt, createdAt, currency, id, ownerDisplayName, ownerId,
                       status, submittedAt, title, totals, transactionCount, version]
      has transactions: False
      ```

      **`contracts/ocs-api.yaml:1125` requires both of the missing keys.** `SheetDetail` is
      `allOf: [SheetSummary, { allowedActions: CapabilitySet, transactions: TransactionSummary[] }]`, and
      `src/types/api.ts:69` mirrors it — `SheetDetail extends SheetSummary` with `allowedActions` and
      `transactions`. So the contract, the frontend type and the implementation disagree, and the
      implementation is the odd one out. This is Phase 35's finding recurring on a different payload.

      **The consequence is that a purchaser cannot submit a sheet, at all, ever.** `SheetDetailView.vue:115`
      renders Submit as `v-if="sheetStore.canSubmit"`, and `src/stores/sheets.ts:14` is

      ```ts
      const canSubmit = computed(() => currentSheet.value?.status === 'OPEN' && (currentSheet.value?.transactions?.length ?? 0) > 0)
      ```

      `currentSheet.transactions` is `undefined` on every response the server can currently produce, so
      `?? 0` makes `canSubmit` **permanently `false`** and the button is never in the DOM. A purchaser can
      create a sheet, open it, add a draft transaction, save it — and then has no way to submit. T171
      cannot pass its submission journeys, and neither can T054, T066, T076, T089, T114 or T122, because
      all of them submit a sheet.

      **It is invisible in three separate ways, which is why it shipped.** The sheet's
      `transactionCount` is present and non-zero, so the header looks populated. The view renders its
      totals, its tables and its status chip, so it does not look broken. And every frontend spec stubs
      `@nextcloud/axios` and hands the store a **fixture** sheet that includes `transactions`, so the
      computed is true in all 237 tests and false in every browser.

      **`allowedActions` is the same defect with quieter consequences.** T117/T118 own
      capability-driven presentation, and a missing `CapabilitySet` means every view falls back to its
      default rather than denying an action — the failure mode is a UI that offers a control the user
      cannot use, not a 403.

      **Acceptance criteria.** `GET /sheets/{sheetId}` returns both required properties for the caller's
      role scope, and `tests/Contract/Controller/` asserts **each of them by name** — assert
      `assertArrayHasKey('transactions', …)` and `assertArrayHasKey('allowedActions', …)`, with the
      capability set's contents checked for a caller who may act and one who may not. Add a frontend test
      that Submit is **absent** for an empty open sheet and **present** for one the API reports as
      containing a transaction, so the `canSubmit` coupling to a payload key is asserted rather than
      assumed. A conformance check should compare every `SheetDetail` property named in
      `contracts/ocs-api.yaml` against the keys the formatter actually emits, so a third payload cannot
      drift the same way. **Depends on T326** for the `transactions` content, since the two properties come
      from the same response and T326 decides where that content lives. Cross-references: T171, T326,
      T117, T118, T124.

      ---
      **Delivered: both properties are on the wire, asserted by name, for all four sheet responses.**
      `SheetPayloadFormatter::detail()` returns `allowedActions` and `transactions`, and create, detail,
      patch and submit all return it.
      **The capability derivation moved out of the formatter and into the domain, and that was not
      cosmetic.** It began as a private method on `SheetPayloadFormatter`, and the first test for it had
      to construct the formatter — which needs `SheetSummaryQuery`, which needs `OCP\IDBConnection`,
      which the PHPUnit process does not have, because `tests/bootstrap.php` deliberately does not
      bootstrap Nextcloud. The denial half was untestable for a second, independent reason: the
      contract suite has exactly one set of credentials and cannot authenticate as a second user, while
      the endpoint is owner-scoped and answers `404` to anyone else. So the rules now live in
      `lib/Domain/Expense/SheetCapabilities.php`, which is where Constitution IV says invariants belong
      and which takes the actor's uid as a parameter. That makes the **whole** matrix assertable in
      `tests/Unit/Domain/Expense/SheetCapabilitiesTest.php`: owner-may-act, empty-sheet
      not-submittable, non-owner offered nothing, all four post-submission stages denying all three
      capabilities, both completed states locked, and every value a boolean.
      **Every capability key is always present, never omitted.** `CapabilitySet` is
      `additionalProperties: {type: boolean}`, so a missing key arrives in the client as `undefined` —
      which passes every `!== false` filter the frontend writes. That is not hypothetical: it is why
      `DecisionForm`'s option filter was inert until its lookup was corrected (T335). The non-owner test
      compares the whole map rather than one key, so a set that dropped `canSubmit` would fail.
      **The contract-level half is asserted over real HTTP**, in four tests added to
      `PurchaserFlowControllerTest`: both keys by name, `count(transactions) === transactionCount`, the
      fixture's own transaction present by public id, `canSubmit` true for an open sheet holding one, and
      all three capabilities false on a submitted sheet. Asserting the count against the rows is the part
      that matters — `transactions` present but empty while `transactionCount` says otherwise satisfies
      the first half of the test and still leaves the table blank.
      **Two of the four endpoints are asserted for shape and two are not, deliberately.** `PATCH
      /sheets/{id}` and `POST /sheets/{id}/submit` mutate the shared fixture sheet, and this file
      re-seeds from `seed-contract-fixtures.php` for that reason; a test that left the sheet submitted
      would make every later assertion in the file order-dependent. Both return the value of the same
      `formatSheetDetail()` call that the two read-shaped endpoints prove, and the test says so rather
      than leaving a silent gap.
      **Both directions of `canSubmit` are now asserted in the frontend**, which is the assertion whose
      absence let this ship: the only test that touched the Submit button asserted its *absence* on a
      submitted sheet, which a permanently-false computed satisfies perfectly. There is now a test that
      it is absent for an open sheet with no transaction (FR-024) **and** one that it is present for one
      the API reports as holding a transaction. `NextcloudVueContract.spec.ts` carried a comment
      deferring this assertion to T326; that deferral is now resolved and points at both tests.
      **What this does not close.** `firstExpenseDate` and `lastExpenseDate` are declared by the
      contract and typed non-optional by `src/types/api.ts`, and neither is emitted — that is T337. The
      conformance check this task asked for on `SheetDetail` is **not** written here: T337 now carries
      it, because a `SheetSummary`/`SheetDetail` property check is one implementation serving both, and
      writing it twice would be the drift this ledger keeps recording.
      Cross-references: T171, T326, T117, T118, T124, T337.

---

## Constitution Compliance Review

Recorded 2026-10-02 under the Governance requirement that **every** feature specification,
implementation plan, task list, review, and release readiness check include a constitution compliance
review, and that the review confirm each compatibility target the artifacts assert matches the
register and that no artifact asserts an unregistered change. Reviewed against constitution v1.3.0.

This section is the task list's own compliance review. It is not a release-readiness verdict — that
is **T135**, which is still open, and this review does not substitute for it.

| Principle / rule | Verdict | Evidence |
|---|---|---|
| I. Specification Before Implementation | PASS | 84 of 84 FRs are traceable to a task and a test through **T134**. The one gap that existed — command idempotency had no owning requirement — is closed by **FR-084**, added 2026-10-02; see the note under this table. |
| II. Preserve Contracts and Existing Behavior | PASS | Application ID `pettycash`, the `OCA\PettyCash` namespace, the AppFramework boundary and the PHP/Vue split are unchanged (T001–T010). Migrations are forward-only and app-scoped (T260, T261, T264). Legacy prototype tables are unmanaged artifacts; T252/T263 own removal of the `oc_pcash_*` premise. |
| III. Nextcloud-Native, Boundary-Conscious Architecture | PASS after the v1.3.0 amendment | Authentication, identity, file access, security controls and technical logging remain on Nextcloud services; no parallel auth system, independent log subsystem, or unmanaged storage was introduced. Backend code stays inside the `OCA\PettyCash` PSR-4 boundary. **Recorded defect, now closed:** at v1.2.0 this principle still read "MariaDB compatibility MUST be retained" while the compatibility-targets list and ACC-002 declared `mysql`/`pgsql`/`sqlite` — two normative sentences in one file disagreed. Corrected by amendment to v1.3.0, which also records that ACC-001 and ACC-002 are byte-identical through the change. |
| IV. Domain Integrity, Security, and Auditability | PASS | Server-side authorization and the multi-role decision matrix (T013, T026); atomic settlement with exactly one consumption movement (T056, T189); immutable prior revisions (T016, T072); append-only audit with no update or delete path on the repository interface (T024, T138); historical actor identity surviving disablement (T090, T139). |
| V. Verified and Documented Delivery | PASS with open work | Unit, integration, contract and end-to-end suites exist and execute. T236/T316 added real-database round-trip coverage after the InMemory doubles had masked the persistence defects behind T229/T230/T232/T234. **Open, with named owners:** T128 (SC-008 performance measurement), T122 and T123 (the browser and Persian-RTL matrices that FR-071 and FR-072 name as evidence), T124 (contract validation). |
| Architecture constraints — compatibility targets | PASS | Every target the artifacts assert matches the register: Nextcloud 34.x (stated directly), PHP 8.2–8.5 (**ACC-001**), database backends `mysql`/`pgsql`/`sqlite` (**ACC-002**), Vue 3 (stated directly), TypeScript 5 (stated directly). **No artifact asserts an unregistered change.** |
| Delivery rule 9 (no compatibility target changed in a plan or task) | PASS after correction | `plan.md`'s Technical Context **Storage** line read "MariaDB" alone while the same plan's register-conformance table certified the three-type set — a plan asserting a superseded target. Corrected 2026-10-02 to name the declared backends with MariaDB as the local development backend only. No task changes a target. |
| ACC-001 outstanding | Open, tracked | The "narrows rather than replaces" reasoning was corrected in `plan.md`. Remaining: `appinfo/info.xml` declares no `<php>` element, so the 8.2 floor rests on `composer.json` alone, and whether `info.xsd` permits `<php>` there is unverified. |
| ACC-002 outstanding | Open, tracked — **blocks a success criterion** | T239 is unchecked and the repository has no CI configuration, so the required four-job ephemeral matrix (MySQL, MariaDB, PostgreSQL, SQLite) is unsatisfied. Until it lands, `appinfo/info.xml`'s declaration is **stated intent, not a verified target, and SC-013 cannot be claimed.** T240 owns the static portability check. |

**The FR-084 gap, recorded because it was a real traceability failure and not only a missing line.**
`X-PettyCash-Command-ID` is marked **required** on every mutating operation by
`contracts/ocs-api.yaml:767-772`, and replay is implemented in the schema, the persistence layer and
roughly a dozen tasks — T027, T166, T317, T318 and others. No functional requirement stated the rule,
so that business behavior was traceable to tasks but not to a requirement, which Principle I requires.
Closed by **FR-084** on 2026-10-02, after which **T134**'s range reads FR-001–FR-084 and `plan.md`'s
Scale/Scope reads 84. The order mattered: the range was widened **after** the requirement existed,
because widening it first would have produced a traceability matrix citing a requirement nothing
defines — the same defect this range was widened twice for previously.

**No exceptions are claimed by this review.** Five items above are recorded as open work with named
owners (T128, T122/T123, T124, T239, and the `info.xml` floor) rather than waived, and one is recorded
as blocking a success criterion (T239 → SC-013). An unrecorded exception would be invalid; none is
recorded here.

## Phase 55: Convergence (seventeen wire-level gaps between the contract, the server, and the client)

Found by the 2026-10-03 convergence pass, after `npm run test:unit` reported 243/243 and
`scripts/dev/phpunit` reported 1087 tests with one failure (that failure is T318's known
`application/octet-stream` conflict, not a finding here). Every gap below was established by
reading the code that builds or reads the value, not by a failed grep.

**Why this pass found seventeen gaps the previous fifty-four did not.** Every earlier phase started
from a task note, a failing test, or a business symptom. This one started from the three
*mechanical* correspondences that have no symptom of their own — contract path vs route,
contract schema vs emitted keys, client payload key vs server payload key — and enumerated all of
them. Fourteen of the seventeen are invisible to the suites for the same reason T327 was: every
frontend spec stubs `@nextcloud/axios` and hands the store a hand-written fixture that contains the
key the server never sends. A green frontend suite is not evidence that a wire is connected.

- [x] T328 [CRITICAL] Send the export format under the name the endpoint binds, because **every
      export request is refused with 422** and no format works for any subject (FR-066,
      `src/types/api.ts`, `src/stores/reports.ts`, `lib/Controller/ExportController.php`) (`contradicts`)

      **Measured.** `src/types/api.ts:286` declares `format: 'csv' | 'xlsx' | 'pdf' | 'zip'` and
      `src/stores/reports.ts:146-153` builds `{ resource, format, reportType, filters }`. The
      endpoint binds `string $fileFormat = ''` (`lib/Controller/ExportController.php:84`) and answers
      `$fieldErrors['fileFormat'] = 'required'` when it is empty (`:106-111`). `format` is never read,
      so the bound parameter is **always** the empty string and **every** export is a 422.

      **The contract already decided this and recorded the reason, which makes the client the odd one
      out.** `contracts/ocs-api.yaml:1281` declares `required: [resource, fileFormat]`, and `:1287-1293`
      explains the rename: Nextcloud's OCS layer reserves the request parameter `format` for envelope
      selection, so a body field literally named `format` forces an XML answer no `Accept` header can
      override. T302 (recorded at `tasks.md:4456-4461`) fixed the **server** half and warned that "a
      client that sends `format` would produce a request that cannot succeed". That client is this one.
      The same reservation also forces the XML envelope, so this one field breaks the response shape too.

      **Acceptance criteria.** `npm run typecheck` still passes after the rename (it passes *now*
      precisely because the declared TS type is the wrong name), a contract test asserts the `422`
      field error is keyed `fileFormat`, and one end-to-end export per format returns `200`.
      Cross-references: T302, T341, T142 (exports), T114.

      ---
      **Delivered: the client sends `fileFormat`, and the format union is derived from it.**
      `ExportRequest.fileFormat` in `src/types/api.ts`, the request body built in `reports.ts`, and
      `ExportFormat = ExportRequest['fileFormat']` in `src/types/reporting.ts`. That last one is the
      part that stops the recurrence: the union used to be `ExportRequest['format']`, so it tracked a
      field **the server never reads**. Deriving it from `fileFormat` means the store's format type and
      the field the endpoint binds cannot drift apart again — and had they been derived from the wrong
      field, this task's own fix would not have been caught by the compiler either, which is precisely
      how it shipped.
      **The server half was already correct and needed no change.** `ReportAndExportControllerTest`
      asserts `fileFormat`, the JSON envelope, and the `ExportResult` keys at `:571-599`, so the wire
      contract was pinned while the only client in the repository sent the wrong name. No PHP test was
      added here, and that is a finding rather than an omission: the endpoint was never the problem.
      **What the fix exposed immediately, and why it shipped with T341.** `requestExport` had been
      asking for `responseType: 'blob'` on the reasoning that the endpoint "streams the artifact" —
      which the controller's own comment contradicts. With `fileFormat` corrected, that would have turned
      a clean `422` into a corrupt download, so T341 is fixed in the same change rather than after it.
      **Acceptance criteria met.** `npm run typecheck` passes, the two new frontend assertions cover
      the field name (`fileFormat` present, `format` absent) and the absence of `responseType: 'blob'`,
      and the pre-existing contract tests confirm the endpoint accepts the request this now sends.
      Cross-references: T341, T302, T342, T142, T114.

- [x] T329 [CRITICAL] Make report filters reach the server under one name and one vocabulary, because
      **FR-064/FR-065 filtering is inert and the user is shown the unfiltered report** (FR-064, FR-065,
      `src/services/reportsApi.ts`, `src/types/reporting.ts`, `lib/Controller/ReportController.php`)
      (`contradicts`)

      **Two independent defects, and fixing only the first converts a silent wrong answer into a 422.**

      **Axis 1 — encoding.** `reportQuery()` writes each populated filter as its own query parameter
      (`src/services/reportsApi.ts:33-42`: `query.set(name, String(value))`). The endpoint reads
      exactly one parameter and JSON-decodes it: `string $filters = ''`
      (`lib/Controller/ReportController.php:100`), and `:180-191` returns an unfiltered
      `new ReportFilter($page, $pageSize)` when it is empty. The client never sends `filters`, so
      `$filters` is always `''` and **every filter is discarded before any rule runs**.

      **Axis 2 — vocabulary.** `ReportFilters` (`src/types/reporting.ts:41-53`) declares `status`,
      `ownerId`, `costCenterId`, `expenseTypeId`, `currency`, `actorId`, `stage`, `eventType`,
      `period`, `dateFrom`, `dateTo`, `amountMin`, `amountMax`.
      `ReportFilterFactory::ACCEPTED_FILTERS` (`lib/Application/Reporting/ReportFilterFactory.php:47-50`)
      is `from`, `to`, `statuses`, `currencyCodes`, `costCenterDbIds`, `actorUids`, `eventTypes`,
      `createdBy`, `expenseTypeDbIds`, `stages`, `amountMinMinor`, `amountMaxMinor`. **Zero name
      overlap** — not even `dateFrom`/`dateTo` against `from`/`to`. So once axis 1 is fixed, every
      filter the UI can produce is refused as an unknown key. `ReportFilterBar.vue:63` compounds it by
      sending `costCenterId` as a single UUID string where the server's vocabulary wants
      `costCenterDbIds` as an array of database ids.

      **The server states the design intent that the client currently violates.**
      `ReportFilterFactory.php:86-96`: "An unrecognised filter is refused rather than ignored, because
      ignoring it returns the unfiltered answer and the caller cannot tell the difference." The client
      produces exactly that outcome by never sending a recognised one. `ReportView.vue:56-58` calls
      `applyFilters`, the request succeeds, and the unfiltered report is rendered with nothing dropped
      on screen.

      **Decide the id-space question explicitly.** `costCenterDbIds`/`expenseTypeDbIds` name *database*
      ids, while the contract's identifier discipline is public uuids. Resolving public ids to database
      ids is a server responsibility; a client must never be asked for a surrogate key.

      **Acceptance criteria.** One contract test asserts that a report requested with a status and a
      date range returns only matching rows, and one asserts that an unknown filter name is **refused**
      (keeping T309's guarantee). `period` (FR-068, T312) must use the settled vocabulary rather than
      introduce a fourth one. Cross-references: T309, T312, T275, T103, T104.

      ---
      **Delivered. The defect was entirely client-side; the server was already correct, and this task
      found that by reading the server rather than by a failing test.**

      **Axis 1 — the encoding.** `reportQuery()` wrote each populated filter as its own query parameter.
      `ReportController::show()` binds one `string $filters = ''` and JSON-decodes it, so `$filters` was
      always `''` and the controller returned a bare `new ReportFilter($page, $pageSize)`. The filters are
      now serialised into **one** `filters` parameter. An empty filter object is omitted rather than sent
      as `{}`, because `json_decode('{}', true)` is `[]` in PHP and `ReportFilterFactory` has to
      special-case it.

      **Axis 2 — the vocabulary.** `ReportFilters` is now **the server's vocabulary**, under the same
      names and with the same shapes: `statuses`/`currencyCodes`/`stages`/`eventTypes`/`actorUids`/
      `costCenterIds`/`expenseTypeIds` are **lists** (the factory refuses a non-array for every one of
      them, so a single-select sends a one-element list), amounts are `amountMinMinor`/`amountMaxMinor` in
      integer minor units, and the range is `from`/`to`. The mapping is the identity on purpose: a
      client-side alias layer would recreate the two-vocabularies drift this task removes, and nothing
      would say which is right when they disagree.

      **The id-space question, decided as this task required.** `data-model.md` states the rule — "Every
      externally addressable aggregate uses an immutable UUID `public_id`; numeric `id` values remain
      internal join keys" — so a filter named `costCenterDbIds` publishes an internal join key across the
      API boundary and asks the client for a surrogate key it cannot otherwise obtain. **`costCenterIds` and
      `expenseTypeIds` (public UUIDs) were added to the vocabulary**, and the new
      `lib/Application/Reporting/FilterIdentityResolver.php` resolves them to the surrogate keys
      `ReportQuery` filters on, following the `ChargedAccountScope` pattern already in that namespace.
      An id that resolves to nothing is **refused** with a 422 naming it, never dropped: dropping it
      would return the superset while the caller believed the filter applied, which is the exact failure
      the closed vocabulary was built to prevent.
      **The `*DbIds` spellings were kept, not replaced.** T306 and T309 pinned them in
      `ReportAndExportControllerTest` and `ExportController` accepts the same object, so removing them
      would be a breaking contract change made as a side effect of fixing a client — no task authorises
      it. Both spellings union into the same `ReportFilter` field, so a request naming either or both
      means the members of that one facet.

      **`period` was NOT given a fourth encoding, per this task's instruction.** It is not a member of the
      accepted vocabulary, and T312 remains blocked on the open `/speckit.clarify` question about whether
      `period` joins FR-064's set or stands in a declared relationship to a date range. The type
      `ReportPeriodPreset` keeps the full V1 vocabulary and a **new** `REPORT_OFFERABLE_PERIODS` holds only
      `custom`, which is what the filter bar offers. The preset is also no longer part of `ReportFilters`
      at all — it is component state, so the request cannot claim a filter the server cannot answer.
      **Offering the Jalali presets today would have re-created this task's own defect**: the user picks
      "month", nothing is sent, and the unfiltered report is rendered.

      **Two defects found while doing it, both now fixed.**
      1. `ReportQuery::RULE_REQUEST_NAMES` did not know the new names, so a request that had *passed* the
         closed-vocabulary check was then refused by `assertFilterIsEvaluable()` naming
         `costCenterDbIds` — a word absent from the request and from the accepted-filter list shown to
         the caller two lines earlier. Both spellings are now named for both rules.
      2. `ReportQuery::RULE_REQUEST_NAMES`'s docblock claimed the guard
         `ReportFilterFactoryTest::testEveryRequestNameIsAnsweredByARule` existed. **It did not.** It is
         written now, and it iterates `ACCEPTED_FILTERS`, so the next name added to the vocabulary without
         a rule able to refuse it by the caller's own name fails there instead of shipping.

      **Verification.** `scripts/dev/phpunit` is **1116 tests / 4613 assertions / 1 failure**, that failure
      being T318's known `application/octet-stream` conflict and not this task's.
      `ReportAndExportControllerTest` is 61/61 (was 57) and `ReportFilterFactoryTest` is 48/48 (was 42);
      `npm run typecheck`, `npm run lint` (0 errors), `npm run stylelint`, `npm run test:unit`
      (**253/253**, was 247) and `npm run build` all pass.

      **What the tests assert, and why the contract test carries the weight.** The frontend unit tests
      pinned the broken encoding and passed, because they agreed with `reportQuery`; that is the same trap
      Phase 55 recorded for T327, and no client-side test can catch a wire mismatch on its own. The new
      `testAStatusAndADateRangeInOneRequestReturnOnlyRowsSatisfyingBoth` therefore asserts an **exact set
      equality against a locally computed intersection** over real HTTP, and first asserts that each
      facet on its own strictly narrows the unfiltered report — otherwise "both facets applied" and "one
      facet applied" produce identical output and the test measures nothing. The public-id spelling is
      asserted over HTTP to select **the same rows** as the surrogate-key spelling, which is what makes
      the id-space decision testable rather than a claim in a docblock. The unresolvable-id case is
      asserted over HTTP too, because what it guards is the answer being a 422 rather than a 500.

      **Known limitation, deliberately left open.** The filter bar's cost-centre field is still free text
      rather than a picker, so FR-064's cost-centre facet needs a user to paste a UUID. That is T088's
      configuration UI, not this task, and it was not widened here.
      Cross-references: T309, T312, T275, T306, T103, T104, T088, T318.

- [x] T330 [CRITICAL] Emit the expense type's public id in `Revision.expenseType.id` instead of its
      title, because **updating any existing transaction fails with 422** and the correction journey
      cannot complete (FR-025, FR-048, US4, `lib/Infrastructure/Http/TransactionPayloadFormatter.php`)
      (`contradicts`)

      **Traced end to end.** `lib/Infrastructure/Http/TransactionPayloadFormatter.php:128-131`:

      ```php
      'expenseType' => [
          'id' => $revision->expenseTypeTitle() ?? '',
          'name' => $revision->expenseTypeTitle() ?? '',
      ],
      ```

      Both members carry the **title**. The aggregate has no public-id accessor to use:
      `lib/Domain/Expense/TransactionRevision.php:15-16,29` stores `costCenterId`, `expenseTypeTitle`
      and a public `?int $expenseTypeDbId`, but no expense-type public id. `EntitySnapshot.id` is
      `format: uuid` (`contracts/ocs-api.yaml:1184-1189`).

      **The consumer makes it fatal.** `src/views/transactions/TransactionFormView.vue:175` writes
      `draft.value.expenseTypeId = rev.expenseType.id`, and
      `lib/Application/Expense/TransactionService.php:307` resolves it with
      `$this->expenseTypeRepository->findByPublicId($expenseTypeId)` — so an edit submits the title
      where a uuid is required and answers `InvalidArgumentException("Expense type not found: …")`
      (`:309`) as a 422. Every update of an existing draft fails, and with it FR-025's "edit" and
      US4's correction path.

      **The neighbouring code shows the intended pattern, which is what makes this an inconsistency
      rather than a convention.** `costCenterSnapshot()` (`TransactionPayloadFormatter.php:147-171`)
      exists specifically to resolve a public id and to emit the optional `code`; `EntitySnapshot.code`
      is likewise never emitted for `expenseType`. Use the same shape.

      **Acceptance criteria.** A contract test asserts `activeRevision.expenseType.id` is a uuid and is
      accepted back by `PATCH /transactions/{id}`; a component test loads a real server-shaped
      transaction into the edit form and submits it. Cross-references: T075, T016, T072.

      ---
      **Delivered. The formatter had no public id to emit, because the aggregate did not carry one.**

      `TransactionRevision` stored `expenseTypeDbId` and `expenseTypeTitle` and nothing else, so the only
      string available for `expenseType.id` was the title. **The fix is therefore four layers deep, not
      one:** formatter alone could only have swapped one wrong string for another.

      1. **`TransactionRevision`** gains `public ?string $expenseTypeId` as a promoted constructor
         property, paired with `$expenseTypeDbId` exactly as `$costCenterId` is paired with
         `$costCenterDbId`. It is a **trailing optional** parameter on `complete()` and on the property,
         so none of the ~25 existing `complete()` call sites in the integration suites had to change and
         no unrelated test was disturbed. `hydrate()` is untouched: `expenseTypeDbId` was already set as a
         public property immediately before `hydrate()` is called, so `expenseTypeId` is set the same way
         rather than adding a parameter to a method whose docblock is about restoring business fields.
         `correction()` copies it — without that, a correction draft would have lost the id and US4's
         path would have stayed broken while the create path looked fixed.
      2. **`DbTransactionRevisionRepository`** resolves it through a new `getExpenseTypePublicId()`,
         placed directly beside the existing `getCostCenterPublicId()` and called from `mapRow()` in the
         same place. **This is where the cost centre's public id was already being resolved**, so putting
         the expense type's anywhere else would have recreated the inconsistency this task closed.
      3. **`TransactionService::updateTransaction()`** carries `$expenseTypePublicId` through both
         branches of its expense-type if/else, mirroring how `$costCenterPublicId` is carried beside
         `$costCenterCode`, and passes it to `complete()`.
      4. **`TransactionPayloadFormatter`** gains `expenseTypeSnapshot()` beside `costCenterSnapshot()`
         and emits `id => $revision->expenseTypeId() ?? ''`, `name => $revision->expenseTypeTitle()`.
         The **stored title** is used for `name`, not a fresh lookup, so a historical revision keeps the
         name it was submitted with — the same reason `costCenterSnapshot()` prefers its snapshot columns.
         An unresolvable id yields an **empty string rather than the title**: an absent identifier is
         visibly absent, whereas a title in an id field looks like data.

      **The production failure, reproduced before the fix.** The round-trip contract test failed with
      exactly the live symptom: `422 {"error":{"code":"VALIDATION_FAILED","message":"Expense type not
      found: Contract Fixture Expense Type"}}`. Every update of an existing draft was refused, so
      FR-025's "edit" and US4's correction path were both unreachable from the UI.

      **A fixture was hiding this, and fixing it was part of the task.** `PurchaserFlow.spec.ts` used
      `COST_CENTER_ENGINEERING = 'cc-eng-0001'` and `EXPENSE_TYPE_SUPPLIES = 'et-sup-0001'` — readable
      placeholders in fields the contract types as `format: uuid`. That is the trap Phase 55 named for
      T327: a fixture shaped to agree with the client rather than with the server. It is simultaneously
      wrong about the contract and unable to say so. **All four are real UUIDs now.** A readable id is
      comfortable in a fixture and impossible on the wire.

      **Two corrections to this task's own notes, made while working.**
      - The note's line reference "`TransactionService.php:307`" and "`TransactionFormView.vue:175`" were
        correct, but it described `PATCH /transactions/{id}` as the consumer of `expenseType.id`; the
        *form* is the consumer, and it forwards the value to that endpoint. Both links are now asserted.
      - **The acceptance criterion's "component test" cannot detect this defect on its own**, and it is
        worth being explicit about why: the form copies whatever `expenseType.id` says, so a component
        test passes identically against the broken server and the fixed one. Its value is that it pins
        the client half — the form must submit the *id* and must never substitute the *name* — and it is
        the component half of the loop that the contract test closes. The load-bearing assertions are the
        two over HTTP.

      **Verification.** `scripts/dev/phpunit` is **1118 tests / 4627 assertions / 1 failure**, that
      failure being T318's known `application/octet-stream` conflict and present on the pre-change
      baseline. `CorrectionControllerTest` is 14/14 with the two new tests; `npm run typecheck`,
      `npm run lint` (0 errors), `npm run stylelint`, `npm run test:unit` (**254/254**, was 253) and
      `npm run build` all pass.

      **Why the round-trip test asserts on the status code.** Its first draft asserted that the PATCH
      answer was not a refusal by inspecting the shape of `data`, and **that assertion passed against the
      422** — a 422 answers a JSON OCS envelope too, so a shape check cannot distinguish success from the
      exact refusal the test exists to catch. It asserts `getStatusCode() === 200` now, and the same trap
      is worth remembering for the other OCS write tests.

      **Known limitation, deliberately left open.** A revision whose stored `expense_type_id` matches no
      expense-type row now emits `id: ''` where it previously emitted the title. That is the honest
      reading — an unresolvable join key is not an identifier — but it means such a revision's expense
      type cannot be edited until the dangling reference is repaired. Repairing dangling references is not
      this task and no task owns it; recorded here rather than silently widened.
      Cross-references: T075, T016, T072, T267.

- [x] T331 [CRITICAL] Render the dashboard's nested `figures` as metrics, because **no FR-061 figure is
      reachable and each card shows a JSON blob plus the read-window bound as its headline number**
      (FR-061, `lib/Controller/DashboardController.php`, `src/utils/dashboard.ts`) (`partial`)

      **Measured on both sides.** `DashboardController::card()` (`:152-164`) returns
      `['userId' => …, 'figures' => $figures, 'figuresBoundedBy' => self::BOUND, 'figuresTruncated' => …]`
      where `$figures` is `['openSheets' => …, 'returnedToMe' => …]` (`:88-95`), `['awaitingReview' => …]`
      (`:99-114`), or the administrator summary (`:126-138`). `dashboardMetrics()`
      (`src/utils/dashboard.ts:31-53`) maps over `Object.entries(bucket)` — **top level only** — and any
      non-scalar falls through to `return { key, kind: 'raw', text: JSON.stringify(value) }` (`:52`).
      `DashboardCard.vue:71-77` prints `metric.text` verbatim.

      **The consequence is that each role card renders four rows**: `userId`, a monospace
      `{"openSheets":2,"returnedToMe":0}` labelled *figures*, `figuresBoundedBy` formatted as the count
      **100**, and `figuresTruncated` as yes/no. FR-061's figures — open sheets, returned transactions,
      review queues, decision counts, balances, movements — appear as none of those. `hasMetrics`
      (`dashboard.ts:55`) is satisfied by the `userId` row alone, so the "nothing to do" empty state at
      `DashboardCard.vue:56` can never render either.

      **Both sides may need a decision, and it is not the implementer's to make silently.** The
      contract's `Dashboard` schema declares the role objects `additionalProperties: true` without
      naming inner properties, so it neither endorses nor forbids the nesting. Flattening one level in
      `dashboardMetrics` is the smaller change and leaves the wire shape alone; hoisting the figures in
      `card()` is the smaller *consumer* change. Pick one and say so in the report. Whatever is chosen,
      `userId`, `figuresBoundedBy` and `figuresTruncated` need a non-metric presentation — a bound is a
      caveat, not a figure, and it must not be the largest number on the card.

      **Acceptance criteria.** A frontend test asserts a card built from a **server-shaped** bucket
      renders a labelled, translated row per FR-061 figure and no `kind: 'raw'` metric, and that the
      empty state is reachable. Cross-references: T113, T115, T118.

      ---
      **Delivered, client-side only — and the decision is recorded because the task required it to be.**
      **Chosen: flatten one level in `dashboardMetrics`; the wire shape is unchanged.** The server was
      already correct and its nesting is deliberate. `figures`, `figuresBoundedBy` and
      `figuresTruncated` exist *together* — the class docblock is titled "Figures are bounded, and say
      so" — so hoisting the figures to the card's top level would leave the bound bounding nothing at
      that level. The nested shape is additionally pinned by `DashboardControllerTest.php:142-178`
      (`figures` present, `figuresBoundedBy === 100`, `figuresTruncated` a bool, figures are ints), so
      hoisting breaks two of those four assertions and is not the smaller change. And the contract's
      five role objects are `additionalProperties: true` with no inner properties named
      (`ocs-api.yaml:969-976`), so nothing required a change on either side.

      **`src/utils/dashboard.ts`** — `DASHBOARD_FIGURES_KEY` and
      `DASHBOARD_NOTE_KEYS = ['userId','figuresBoundedBy','figuresTruncated']`, both read off the
      controller. `dashboardMetrics()` unwraps `figures` **in place, one level**, classifying each
      unwrapped value with the *same* private `metric()` the top level uses, so a money figure keeps its
      currency instead of becoming a count that would print it 100x too small — asserted, because
      "flatten one level" must not mean "coerce whatever is one level down". A `figures` that is not a
      record is **not** unwrapped. New `dashboardNotes()` returns the envelope, as a **separate
      function rather than a flag**: a caveat that is merely flagged can be reclassified later, one that
      lives in a different list cannot drift back into the figures. `kind: 'raw'` is kept — it is the
      honest "no renderer" branch and an existing test covers it.

      **`src/types/dashboard.ts`** — `DashboardNote = DashboardMetric`; `DashboardCard.notes`. Notes carry
      the same value-type discrimination (the bound is a number, the flag a boolean) but not the
      headline treatment, so the distinction cannot be lost in the type.

      **`DashboardCard.vue`** — optional `notes` rendered as a `<footer>` under the figures at the floor
      size and normal weight; labels from `dashboard.note.<key>` rather than `dashboard.metric.<key>` so
      T119 can name a caveat as something other than a figure. **`stores/dashboard.ts`** — cards carry
      `notes`; `hasMetrics` reads `card.metrics`, which is now figures only, so the empty state is
      reachable. **`DashboardView.vue`** — passes `:notes` through.

      **A fixture was hiding this.** `Dashboard.spec.ts` fed the client a hand-written **flat** bucket
      whose every key — `queueItems`, `decisions`, `returnedTransactions`, `activeUsers` — is a key the
      server **never sends**. It is the server's shape now, with the controller lines and the contract
      test that pins them named in the comment. One consequence worth recording: the swap broke the
      pre-existing `'renders one card per bucket the projection sent'`, which asserted on `queueItems`.
      That test was asserting on a key the server never sends; it asserts `awaitingReview` now. It was
      part of this defect, not a regression.

      **RED state was valid.** 16 failed / 33 passed, **0 skipped, 0 incomplete**, and the failures named
      the defect literally (`- "openSheets" + "userId"`, `- "returnedToMe" + "figures"`). One of the 16
      was green before the fix and is a deliberate guard — *"does not treat `figures` as a figure when it
      is not a record"* — which a naive flatten would break.

      **How "the bound is not the largest number" was asserted.** Structurally, not visually: the bound
      is absent from `card.metrics` and present under `[data-testid="dashboard-note-…"]`. jsdom has no
      layout engine, so *which list it is in* is the strongest available statement — the same substitute
      T283/T286 adopted for FR-071.

      **`npm run test:unit` reports 2 failures in `NextcloudVueContract.spec.ts`, and they are not this
      task's.** Both are `Test timed out in 30000ms` on real-`@nextcloud/vue` mounts (8–30 s each).
      Established three ways, the last decisive: green at session start (slowest 9 909 ms); 4/6 with this
      task's `src/`+`tests/` **stashed**; **3/6 on pristine `HEAD` in a separate `git worktree`** with no
      uncommitted work at all — strictly worse the cleaner the tree. Nothing T331 touches is imported by
      that file. A 30 s timeout on a slow mount that moves with machine load; not fixed here, not
      claimed as fixed, and no task owns it.

      **Known limitations, deliberately not widened.** The bound is still 100 and the figures are still
      window sizes — no domain repository has a count query, and this task made the card *say* so
      honestly rather than making the figures exact. FR-061's balances and movements still do not appear:
      `figures()` never reads an account balance or a movement, which is T106's projection content.
      And `hasMetrics` is now reachable but still has no reader in `DashboardView.vue` (T113/T115/T118).

      **Results.** `npm run typecheck` clean · `npx eslint` on the six changed files 0 problems ·
      `npm run stylelint` clean · `Dashboard.spec.ts` 49/49 (was 33) · `npm run test:unit` 268/270 (the 2
      above) · `npm run build` built in 9.43s · `scripts/dev/phpunit` 1118 tests / 4627 assertions /
      1 failure, that failure being T318's known `application/octet-stream` conflict, present on the
      baseline and untouched here. **T331 changed no PHP**; `DashboardControllerTest` was run *before*
      the work, green at 9/9, to establish the wire shape this task's fixture now mirrors. Full record:
      `docs/reports/T331.md`.

- [x] T332 [HIGH] Return a real total from `SheetService::listSheets`, because **`total` is the page
      size**, so any purchaser with more than 25 sheets is told they have one page (FR-063,
      `lib/Application/Expense/SheetService.php`) (`partial`)

      **The estimate is acknowledged in the code.** `lib/Application/Expense/SheetService.php:90-95`:

      ```php
      // For total count, we need to add a method to the repository
      // For now, we'll estimate from the returned items
      $total = count($sheets);
      ```

      `$sheets` is the **already-paged** result of `findByOwnerUid($ownerUid, null, $offset, $pageSize)`.
      `SheetController::index` (`:63-72`) passes it straight into the response's `total`, which
      `PageMeta` (`contracts/ocs-api.yaml:930-936`) and `src/types/api.ts` both treat as the record
      count. With 40 sheets, page 1 reports `total: 25`.

      **The consequence is that pagination is wrong, silently.** The frontend computes page count from
      `total`, so the last 15 sheets are unreachable through the pager and the "N results" count
      under-reports by construction. No test catches it, because a fixture with ≤25 sheets produces the
      correct answer by accident.

      **Acceptance criteria.** A repository-backed integration test creates more than one page of
      sheets and asserts `total` equals the owner-scoped record count on every page, not the page size.
      Cross-references: T060, T236.

      ---
      **Delivered, backend only. The frontend claim in this entry does not match the source and was not
      acted on.** `ExpenseSheetRepository` gains `countByOwnerUid(string $ownerUid, ?SheetStatus $status
      = null): int`; `DbExpenseSheetRepository` implements it as `COUNT(*)`, cast from `fetchOne()`
      because `COUNT` is `BIGINT` and arrives as a string on some drivers, with the `owner_uid`/`status`
      predicate extracted into a private `applyOwnerScope()` that **both** the read and the count call, so
      the two cannot drift — the same reasoning `DbAuditEventRepository` records for `applyTransactionTimelineScope()`.
      `InMemoryExpenseSheetRepository` got the identical treatment, so a double cannot pass the unit suite
      a count its own read would contradict. `listSheets()` now reads the count from the repository and
      nothing else about it changed. `$status` is taken for **predicate parity**, not because a caller
      needs it: no endpoint binds a status filter, which is T333.

      **`total` is compared against the table, not against the repository.** The `sheetlist` scenario in
      `roundtrip-runner.php` writes 26 sheets for `PROBE_OWNER_UID = 't332-sheet-list-probe'` — a real
      currency, a real account, the real `SheetService` over the real `DbExpenseSheetRepository` — and
      reports pages 1, 2 and 3, a `COUNT(*)` issued as plain SQL, the status-scoped counts, and one
      unbounded read of the same scope. Asserting "the service agrees with `countByOwnerUid()`" would be
      satisfied by two halves of the same defect agreeing with each other, which is the shape
      `count($sheets)` had. A **probe owner** rather than `$actorUid`, because `employee1` is the user
      `ContractFixtures` seeds and an owner-scoped count over it would depend on which scenario ran first.

      **RED state was valid.** With `count($sheets)` temporarily restored: 2 failed / 2 passed, **0 skipped,
      0 incomplete**, and the failures are the defect as a number — `Failed asserting that 25 is identical
      to 26` on page 1, `1 is identical to 25` on page 2. The fix was restored by exact-string edit and
      `git diff` was read to confirm the file carries the intended change only, which is the check T320
      got wrong. The 2 green tests are guards by design: pages reaching every owned sheet exactly once
      (a property of the window, not of `total`) and the status counts partitioning the owner's sheets.

      **Cleanup needs no new fingerprint.** The 26 sheets carry `LeakedRows::ROUND_TRIP_SHEET_TITLE`, so
      the existing sweep removes them; every insert is also `$track()`ed, so no table was added and
      `testEveryTableTheRunnerWritesHasAFingerprintThatCanRemoveIt` still holds unchanged. The runner
      reported no `leftovers` key.

      **The frontend rationale is unverified, and the current source contradicts it.** This entry says the
      frontend "computes page count from `total`, so the last 15 sheets are unreachable through the pager".
      **There is no pager on the sheet list.** `src/stores/sheets.ts:31` records `pagination.value.total`
      and nothing in `src/` reads that field's value for anything; `src/views/sheets/SheetListView.vue`
      calls `loadSheets()` with no arguments (`:20-25`) and renders `sheetStore.sheets` (`:118`), with no
      `pagination` and no `pageCount` in the file. The four queue views do compute
      `Math.ceil(total / pageSize)` and `AccountView.vue:158` paginates movements — **the sheet list is not
      among them**. The narrower true consequence is that a purchaser with more than 25 sheets sees the
      first 25 with no control to request page 2, which is a **missing pager** and is identical whether
      `total` says 25 or 26. That is not this task's, and **no frontend file was changed or widened to make
      the narrative true.**

      **`SheetController` was deliberately left alone.** It still binds bare `int $page`/`int $pageSize`
      and so does not use `PageBounds`; migrating it would change the endpoint's behaviour for requests
      T332 does not concern. Not needed here.

      **Results.** The four T332 tests 4/4 (16 assertions) · `scripts/dev/phpunit` **1122 tests, 4645
      assertions, 1 failure** (baseline 1118, so exactly these four added) · `php -l` clean on all six
      changed PHP files · Serena diagnostics clean at severity ≤ warning. The one failure is
      `PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream` — T318's known
      `application/octet-stream` vs `application/pdf` conflict, recorded in T330's and T331's reports as
      present on the baseline, and confirmed here against `HEAD`, where both the assertion (`:945`) and
      the `.pdf` fixture filename that causes it (`:1320`) are committed. Nothing T332 touches is in that
      path. **Known limitations:** `GET /sheets` still binds only `page`/`pageSize`, so `total` counts
      everything the caller owns rather than a narrowed set — which is exactly the state T333's
      filter-then-count ordering requires; and the count is a second query, the opposite trade from
      `AccountService::listMovements()`'s deliberate read-once, which does not survive an owner with
      thousands of sheets. Full record: `docs/reports/T332.md`.

- [x] T333 [HIGH] Bind the five filters `contracts/ocs-api.yaml` declares on `GET /sheets`, because
      **FR-064's status/date/currency/creator filtering has no implementation and no owner** (FR-064,
      `lib/Controller/SheetController.php`, `contracts/ocs-api.yaml`) (`missing`)

      **The contract declares them.** `contracts/ocs-api.yaml:332-346` gives `GET /sheets` the
      parameters `status` (`SheetStatus`), `ownerId`, `currency` (3 chars), `from` (date) and `to`
      (date), then `Page` and `PageSize`. The action binds only two of them:

      ```php
      public function index(int $page = 1, int $pageSize = 25): DataResponse   // :63
      ```

      and `SheetService::listSheets(int $page, int $pageSize)` filters by owner alone (`:85-96`).
      **Neither `grep "listSheets"` nor any task ID appears anywhere in this task ledger** (verified:
      0 occurrences), so this is unowned work rather than work in progress.

      **`GET /accounts` has the same shape, smaller.** `contracts/ocs-api.yaml:266-268` declares an
      `active` boolean that `AccountController::index` (`:38`) does not bind, and `listForUser`
      returns active rows only — so a caller cannot ask for inactive accounts at all.

      **Decide filter-then-count, not count-then-filter.** Fixing T332's total and this task's filters
      together is the only order in which `total` means "matching records" rather than "records on this
      page". Also apply the refuse-don't-ignore rule FR-064 already states for filters: an unknown
      `status` or a malformed `from`/`to` is refused with `422`, never ignored.

      **Acceptance criteria.** One contract test per declared filter that a narrowed request returns
      only matching rows with a `total` that matches the narrowing, and one that an unrecognised filter
      value is refused. Cross-references: T332, T309 (the accepted-vocabulary pattern to copy).

      ---
      **Delivered. The production half was already in the working tree, uncommitted, and fatally broken;
      this entry is mostly the account of finishing it.**

      **What was already present when this task was picked up.** `lib/Domain/Expense/SheetListFilter.php`,
      `lib/Application/Expense/SheetListFilterFactory.php`, the five new `index()` parameters, and
      `SheetListFilter` threaded through both `findByOwnerUid()` and `countByOwnerUid()`. So the
      "(missing)" annotation above was stale the moment the session started, and T332's note that
      `countByOwnerUid()`'s `$status` parameter existed "for predicate parity, not because a caller
      needs it" was accurate: the caller existed, it simply had never been run.

      **The baseline was RED with four errors, and the first one was not the defect this task describes.**
      `scripts/dev/phpunit` reported `Undefined constant "T333_FILTER_PROBE_UID"` from the `sheetlist`
      round trip. The constant *was* defined, at `roundtrip-runner.php:2547`. PHP executes a top-level
      `const` as an ordinary statement **in file order**, and that runner dispatches its scenarios at
      lines 137-489 — so a constant declared beside the scenario that reads it is still undefined when the
      scenario runs, and it fails as `Undefined constant`, which reads like a missing import rather than
      an ordering mistake. `PROBE_OWNER_UID` works only because it sits at line 135, above the dispatch.
      Moved beside its sibling, with the reason recorded so the next constant is not put back below.

      **Three further defects in the same in-flight block, each masking the next.**
      `scenarioSheetList()` used `$now` at three places without ever declaring it a parameter, so
      `$now->modify()` was a call on `null`; every other scenario takes `DateTimeImmutable $now` explicitly
      and this one did not. The runner was missing `use OCA\PettyCash\Domain\Expense\SheetListFilter`.
      And the `justYesterday` facet passed `(from, null, to)` **positionally** into a
      `(status, currency, from, to)` constructor, so a date range was being built with a
      `DateTimeImmutable` in the `?SheetStatus` slot. All four are recorded because all four were live.

      **The one genuine production defect, and it was fatal rather than wrong.**
      `DbExpenseSheetRepository::applyListScope()` bound both date bounds with
      `IQueryBuilder::PARAM_DATETIME`, a constant that **does not exist** in Nextcloud 34 — so any
      `GET /sheets` request carrying `from` or `to` died with
      `Undefined constant OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATETIME` rather than returning rows.
      Nextcloud's `IQueryBuilder` declares `PARAM_DATETIME_IMMUTABLE`, which is what the other sixteen
      date bindings in `lib/` already use; corrected to match. The date facet could not have been
      exercised by any test before this, which is the honest reason the defect survived an unimplemented
      task: the half that was written had never been run.

      **The acceptance criteria's half — the transport — did not exist and is new.**
      `tests/Contract/Controller/SheetListFilterContractTest.php`, 20 tests / 1047 assertions, covering
      all five declared filters: `status` (iterated over every `SheetStatus` case, not one hand-picked
      value), `currency`, `from`, `to`, and `ownerId`, plus seven refusal cases asserting 422 and a
      message that names the offending field and, for `status`, the accepted vocabulary.
      The round-trip scenario's own docblock had already scoped itself out of this: it "does not bind the
      query parameters through HTTP", so between them the two files now cover SQL and transport.

      **Every assertion is relative to the endpoint's own unfiltered answer, and that is the load-bearing
      decision.** `GET /sheets` is owner-scoped and this shared instance already holds 261 sheets for the
      acting user across many days, so any absolute count would be a claim about the lab's history. Each
      test captures the unfiltered page set first and asserts a **narrowing** against it — which is the
      only comparison an ignored filter cannot satisfy, since a discarded filter returns the baseline
      verbatim. The first test states the precondition explicitly (more than one sheet, more than one
      status, more than one currency), because a facet measured against a one-valued baseline passes
      against an endpoint that filters nothing. `usd` is the fixture state used wherever two currencies
      are needed: against `draft` every sheet carries `IRR` and the facet is indistinguishable from one
      that matched everything.

      **The end-of-day reading is asserted as containment, not as a count.** `from=today` selects exactly
      the sheets created today, so if `to=today` were read as midnight the two would be **disjoint**. The
      assertion is therefore that `to=today` contains everything `from=today` contains — which is the
      statement that distinguishes the last-second reading from the midnight one, and which an
      "excludes yesterday" assertion passes against. An earlier draft of this test asserted
      `from=today == baseline total` and failed with `18 is identical to 261`: 243 of those sheets were
      created on earlier days, so the narrowing was correct and the assertion was not. The remaining date
      assertions are stated as disjointness for the same reason — `SheetSummary` carries no creation date,
      so "yesterday matches nothing" is not expressible without inventing one.

      **RED state was mutation-verified, twice, because "20 green" is the weakest possible evidence here.**
      Reintroducing the original defect (`fromQuery(null, null, null, null)`) fails **18 of 20**. Weakening
      only the end-of-day reading (`$parsed->setTime(23, 59, 59)` → `$parsed`) fails **exactly 1** — the
      containment assertion — which is the specific evidence that assertion is not passing incidentally.
      Both mutations were reverted by exact-string edit and `grep -rn MUTATION lib/ tests/` returns nothing.

      **Blast radius was established by reference analysis, not by a green suite.**
      `ExpenseSheetRepository::findByOwnerUid()` has three production callers, and the two outside this
      task both predate it: `DashboardController::figures()` (`:183`) passes
      `new SheetListFilter(SheetStatus::Open)`, which is required — the figure *is* "open sheets", and an
      unnarrowed read would count every sheet the caller owns. `ReportController::sheetsReport()` (`:227`)
      passes `null`, and that is correct rather than a missed filter: `status` is a *column* in those rows
      and the report narrows through `ReportFilterFactory`'s own vocabulary, so a sheet filter here would
      have hidden rows the report exists to show. `SheetService::listSheets()` is the single production
      caller of `countByOwnerUid()` and passes the same filter object the read used, which is what makes
      filter-then-count structural instead of a convention each repository remembers. Verified with
      Serena `find_referencing_symbols`; recorded because "the suite is green" is a weaker claim than
      "every caller was inspected".

      **Filter-then-count holds, and the transport test is what proves it.** `total` is asserted equal to
      the number of the caller's sheets actually in the filtered facet, counted from the unfiltered
      baseline — not to the page length, and not to the unfiltered estate. T332 left `total` counting
      everything the caller owns "which is exactly the state T333's filter-then-count ordering requires";
      that ordering is now exercised rather than assumed.

      **`ownerId` is answered as scope, and that is a decision this task required rather than inherited.**
      FR-064 resolves "creator" to the sheet's owner, which makes it the *scope* of a caller's list rather
      than one of its predicates, so naming the caller is a no-op and naming anybody else is a **403** —
      never a silent widening. A refused answer and an empty page are distinguishable; an ignored filter
      is not, which is the whole reason the vocabulary is closed.

      **Verification.** `scripts/dev/phpunit` **1193 tests / 5838 assertions / 1 failure** — baseline was
      1173 with the same single failure, so exactly these 20 were added. The one failure is
      `PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream`
      (`application/octet-stream` vs `application/pdf`), T318's known conflict, present on the baseline
      before this task and untouched by it. `RepositoryRoundTripTest` 69/69 (was 65/69 erroring).
      `npm run typecheck` clean · `npm run test:unit` **270/270** · `npm run lint` 0 errors (163
      pre-existing `vue/attributes-order` warnings) · `npm run stylelint` clean · `php -l` clean on all six
      PHP files · Serena diagnostics empty at severity ≤ warning. **T333 changed no frontend file.**

      **Shared-environment hygiene, recorded because it is a change to state outside the repository.** An
      earlier draft of the contract test created two probe sheets per test in `setUp()`, which on a shared
      instance owned by the acting user added up to 42 rows across the run. Those 42 were identified by
      their `T333 ` title prefix and deleted; the 261 sheets that remain pre-date this session and were
      left alone. The delivered test creates nothing — it is read-only, and takes its two-sided data from
      the `usd` fixture state — so a repeated run adds no rows. A throwaway purge script used for the
      cleanup was deleted rather than committed.

      **Known limitations, deliberately not widened.** The sheet list has **no filter UI and no pager**:
      `SheetListView.vue` calls `loadSheets()` with no arguments and renders `sheetStore.sheets`, so
      FR-064's four facets are reachable only by hand-editing the query string. T332 recorded the same
      absence independently and it is not this task's deliverable — the task named
      `lib/Controller/SheetController.php` and `contracts/ocs-api.yaml`. A purchaser's cost-centre filter
      needs the configuration UI, which is T088. **`GET /accounts`' `active` parameter, named in this
      entry, remains unbound** — it is a second endpoint's defect and closing it here would have widened
      the task. And `countByOwnerUid()` is now a second query per list request, the opposite trade from
      `AccountService::listMovements()`'s deliberate read-once; filter-then-count has no single-query
      form that stays correct.
      Cross-references: T332, T309, T088, T318, T337.

- [x] T334 [HIGH] Reconcile the error envelope's nesting across the contract, the server, and the
      client, because **all four declared `Error` properties are one level away from where the contract
      puts them, and the client is written against the implementation** (`contracts/ocs-api.yaml`,
      `lib/Infrastructure/Http/OcsErrorMapper.php`, `src/services/http.ts`) (`contradicts`)

      **Three-way divergence, measured.** `OcsErrorMapper::respond()` (`:160-172`) builds
      `['code' => …, 'message' => …]` (+ optional `commandId`, `fieldErrors`) and returns
      `new DataResponse(['error' => $error], $status)` — so the keys land at **`ocs.data.error.*`**.
      The contract's `Error` schema (`contracts/ocs-api.yaml:937-948`) is referenced directly as the
      `data` value of all five error responses (`:822, :827, :832, :837, :842`), and the file states at
      `:6-8` that "schemas below describe the value carried in `ocs.data`" — so the contract puts them
      at **`ocs.data.*`**. The string `error` is not a property name anywhere in the contract file.

      **This is not a one-line PHP fix, and that is the finding.** `src/services/http.ts:35` and `:59-60`
      read `data.ocs.data.error.code` / `.error.message` — the client is written against the
      implementation. Correcting only `OcsErrorMapper` would break every error path in the UI, and
      correcting only the contract would silently bless a shape no requirement asked for. The decision
      belongs to the contract owner: either the contract gains an `ErrorEnvelope` wrapper
      (`{error: Error}`) with all five responses referencing it, or the mapper stops nesting and the
      client is corrected in the same change.

      **Why it matters beyond tidiness.** `fieldErrors` is the payload FR-064/FR-023 validation
      messages are read from, and `ApiError` construction (`http.ts:54, :60`) depends on the exact
      path. Every client-side validation surface is downstream of this shape.

      **Acceptance criteria.** One contract test per error response asserting the shape at the agreed
      nesting, and one frontend test asserting a 422's `fieldErrors` reaches the form. Do not change both
      sides in separate commits. Cross-references: T030, T124, T275.

      ---
      **Delivered. The decision this entry required: the CONTRACT gains the wrapper, not the mapper
      losing it.** `ErrorEnvelope` now declares the value `ocs.data` carries for an error, and all
      five declared error responses reference it. `OcsErrorMapper` is byte-for-byte unchanged.

      **Why that side, recorded so the next reader does not re-open it as a free choice.**
      1. **The alternative breaks the client's one working defence.** `unwrap()` in
         `src/services/http.ts` treats "OCS may deliver a failure with HTTP **200**" by looking for an
         `error` key in `ocs.data`. With `Error` placed *directly* in `ocs.data`, a refusal body would
         be `{code, message}` — indistinguishable from a success payload, so a business failure
         arriving at HTTP 200 would be handed to the caller as a successful read. The wrapper is what
         makes the key's presence a declaration. This is asserted now, in
         `http.spec.ts::rejects a 200 that carries an error envelope`.
      2. **The churn is entirely on the other side.** The deployed shape is what the mapper builds,
         what `http.ts` reads, and what **nine** assertions in five PHP contract files already
         pin (`ConfigurationControllerTest`, `DataIntegrityReportTest`,
         `ReportAndExportControllerTest`, `AccountWriteControllerTest`,
         `FinanceReviewControllerTest`). Removing the nesting would change every error response in
         the application to fix a document.
      3. **The contract's own convention still holds.** `:6-8` says "schemas below describe the value
         carried in `ocs.data`"; the schema describing that value for an error is now
         `ErrorEnvelope`, so the sentence is true rather than violated.

      **The decision is recorded as a contract amendment with its owner: this task.** T124, which
      owns validating the document and regenerating `src/generated/api.ts` from it, consumes this
      shape; `src/types/api.ts` gains `ApiErrorEnvelope` so the third side is written against the
      declared name instead of an inline `{ error?: ApiErrorBody }` — the same "client written
      against the implementation" defect this task exists to close, so it is not reproduced in the
      fix.

      **Two further defects were found while asserting the shape, both on the same wire, and both
      fixed here because they are what "the envelope reaches the form" turns out to require.**
      - **`ExportController` emitted `fieldErrors` in the wrong shape** — `$fieldErrors['fileFormat']
        = 'required'`, a bare string, where the contract declares
        `additionalProperties: {type: array, items: {type: string}}` and **every other controller in
        `lib/` builds it as a list**. So the export endpoint answered `fieldErrors.fileFormat:
        "required"` while `src/types/api.ts` typed it `Record<string, string[]>` — a runtime
        violation `npm run typecheck` cannot see, and a value a client's typed loop cannot iterate.
        Found by `assertErrorEnvelope`'s shape assertion, not by a status code. Now
        `['fileFormat is required']`, matching the wording the other controllers use.
      - **`reportsApi.asApiError()` threw the server's envelope away.** It rebuilt every axios
        failure as a synthetic `{code: 'EXPORT_FAILED', message: 'pettycash.error.export'}`, so a
        `422` that named `fileFormat` reached the user as "export failed" and `fieldErrors` — the
        payload FR-023/FR-064 validation messages are read from — was dropped between the wire and the
        store. The server's body is now kept whenever the response carries an envelope, with the
        synthetic body as the fallback for a response that carries none.

      **`fieldErrors` therefore has a form to reach, which it did not have.** `useReportsStore` gains
      `exportFieldErrors` beside `exportError`, cleared with it on `clearExportError()` and at the
      start of every export. The split is deliberate and is the acceptance criterion's point:
      `exportError` answers *why* the request was refused, `fieldErrors` answers *which of the fields
      the caller sent* was unacceptable — and only the second can be placed next to an input.
      `ExportControls.vue` renders one row per refused field, each labelled with the field name;
      `ReportView.vue` passes it through.

      **`Unauthorized` is not asserted to be this application's envelope, and the reason is a finding.**
      Nothing in `lib/` produces a 401 — `grep -rn STATUS_UNAUTHORIZED lib/` returns nothing — because
      an unauthenticated request is refused by Nextcloud's auth layer before a controller runs, and
      that layer answers with its own body (`ocs.data` empty, the refusal described in `ocs.meta`).
      So the contract's `Unauthorized` response describes a shape this application never emits. The
      test asserts the status and documents the boundary; asserting our envelope there would be
      asserting a shape Nextcloud owns.

      **The new test is a conformance check, not five near-identical assertions.** One case per
      declared response — `Forbidden`, `NotFound`, `Conflict`, `ValidationError` at both `422` and
      `400` — and every case runs through the same `assertErrorEnvelope()`, which asserts that
      `ocs.data` carries **exactly** the key `error`, that `ocs.meta.statuscode` agrees with the HTTP
      status, that the two optional members are optional in *shape* rather than in presence, and that
      no fifth property has appeared. `ocs.data` carrying only `error` is the load-bearing assertion:
      it is what would fail if a controller ever hand-rolled a body instead of going through the
      mapper, which is the one thing the mapper exists to prevent. **The `Conflict` case is on the
      charge path, not the export path, and the reason is in its docblock:** the first charge moves
      the account's optimistic-lock version, so the second request must quote the *new* one or it is
      refused as `ACCOUNT_VERSION_CONFLICT` before the command id is compared — a 409 of a different
      kind, which would have made the case assert a code it is not about.

      **Results.** `scripts/dev/phpunit` **1199 tests / 5936 assertions / 1 failure** — baseline was
      1193 with the same single failure, so exactly these six were added. The one failure is
      `PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream`
      (`application/octet-stream` vs `application/pdf`), T318's known conflict, present on the
      baseline. `ErrorEnvelopeContractTest` is 6/6 (94 assertions). `npm run typecheck` clean ·
      `npm run test:unit` — `http.spec.ts` 17/17, `ReportingExport.spec.ts` 42/42 ·
      `npx eslint` 0 problems on the seven changed files · `npm run stylelint` clean.
      **T334's verification found two defects it does not own; both are recorded as Phase 56 tasks
      (T345, T346) rather than fixed here.**

- [x] T335 [HIGH] Look decision capabilities up under their real names, because **the capability filter
      never removes an option, so a finance officer is offered Approve on a transaction the server will
      refuse** (FR-039, `src/components/review/DecisionForm.vue`) (`contradicts`)

      **The lookup key is lowercased; the server emits camelCase.** `src/components/review/DecisionForm.vue:99`:

      ```ts
      .filter(item => props.allowedActions?.[item.value.toLowerCase()] !== false)
      ```

      `item.value` is `'APPROVE' | 'REJECT' | 'RETURN'`, so the keys read are `canapprove`/`canreject`
      — wait, precisely `approve`, `reject`, `return`. The server emits `canApprove`, `canReject`,
      `canReturn`, `canEdit`, `canDelete`, `canCorrect`
      (`lib/Infrastructure/Http/TransactionPayloadFormatter.php:203-208`). `allowedActions['approve']`
      is `undefined`, and `undefined !== false` is `true`, so **all three options survive
      unconditionally** for every transaction and every role.

      **The UI contradicts itself as a result.** `src/views/reviews/FinanceReviewView.vue:155-160` reads
      the correctly cased `allowedActions?.canApprove` and renders the "cannot approve a
      senior-rejected transaction" explanation — so the officer is told Approve is unavailable while
      the Approve option is still sitting in the dropdown. The server refuses the command, so this is a
      misleading affordance rather than data corruption, but FR-039's restriction is not what the
      interface communicates.

      **The test that should have caught this asserts against a hand-written fixture.**
      `grep -rn "toLowerCase" src/` finds one occurrence, this one. Any component test that builds
      `allowedActions` by hand rather than from a server-shaped payload reproduces the bug's blind spot;
      T327 recorded the same trap for the sheet payload.

      **Acceptance criteria.** `DecisionForm` mounts with a **server-shaped** `allowedActions` fixture and
      asserts the option list shrinks to exactly the permitted decisions for a finance officer on a
      `SENIOR_REJECTED` transaction. Mutation-verified: reintroducing the lowercase lookup must fail
      the test. Cross-references: T117, T118, T053.

      ---
      **Delivered. Two changes, because the lookup alone would leave the recurrence possible.**

      1. **`DECISION_CAPABILITY`** in `src/types/api.ts` is the mapping from a decision value to the
         capability that decides it — `APPROVE → canApprove`, `REJECT → canReject`, `RETURN →
         canReturn` — written out rather than derived. Deriving it is what produced the bug: a
         `toLowerCase()` transform of `'APPROVE'` cannot produce `canApprove`. As a `Record` over an
         exhaustive decision union, a fourth decision added without its capability is now a compile
         error rather than an option that silently survives the filter.
      2. **`AllowedActions = Partial<Record<AllowedActionKey, boolean>>`**, with `ALLOWED_ACTION_KEYS`
         naming the six keys `TransactionPayloadFormatter::allowedActions()` emits, and
         `TransactionDetail.allowedActions` retyped from the free-form `CapabilitySet`. This is the
         structural half of the fix and it is the part that actually prevents a recurrence:
         `CapabilitySet` is `Record<string, boolean>`, so **the wrong key was not a type error** —
         which is why the original lookup compiled at all.

      **The comparison changed from "not false" to "is true", deliberately.** With the correct names,
      `!== false` would work, but it treats "the server granted it" and "the server did not mention
      it" as the same answer. `TransactionPayloadFormatter::allowedActions()` always emits all six
      keys, so nothing is lost — and refuse-don't-ignore is already this repository's stated rule for
      a closed vocabulary (`ReportFilterFactory.php:86-96`: "An unrecognised filter is refused rather
      than ignored, because ignoring it returns the unfiltered answer and the caller cannot tell the
      difference"). Here the unfiltered answer is a dropdown offering a decision the server refuses.

      **The test reads the producer, not a copy of it.** The key-set assertion parses
      `lib/Infrastructure/Http/TransactionPayloadFormatter.php` and compares the `'can*' =>` keys it
      emits against `ALLOWED_ACTION_KEYS` — the same technique this file already uses for the route
      table. A rename on either side now fails there instead of quietly restoring "no option is ever
      removed". This matters because **the acceptance criterion's own fixture is the trap**: every
      spec in this repository builds `allowedActions` by hand, and T327/Phase 55 recorded that a
      fixture agreeing with a broken client is why fourteen wire gaps survived a green suite. A
      hand-written fixture is what made this defect invisible, so the one test that has to be
      trustworthy is the one that cannot be satisfied by a fixture.

      **RED state was mutation-verified, as this task required.** Restoring
      `item.value.toLowerCase() !== false` fails **2 of the 4** new tests — the senior-rejected case
      (which sees `['APPROVE','REJECT','RETURN']` instead of `['REJECT','RETURN']`) and the
      nothing-granted case (which sees three options instead of none, and then *can* submit a decision
      the server would refuse). The two that stay green under the mutation are guards by design: the
      all-granted case passes under a filter that removes nothing, and the producer-key-set assertion
      never touches the filter. The mutation was reverted by exact-string edit and `grep` confirms no
      `toLowerCase` lookup remains outside the comment that names it.

      **Results.** `ManagementReview.spec.ts` **26/26** (was 22) · `npm run test:unit` **280/280**
      (was 270; `NextcloudVueContract.spec.ts` 6/6 on this run, against T331's recorded flakiness on
      its 30 s real-`@nextcloud/vue` mounts) · `npm run typecheck` clean · `npx eslint` **0 errors**;
      the 4 `vue/html-closing-bracket-newline` warnings in `DecisionForm.vue` are pre-existing and were
      verified present on the stashed pre-change file.

      **Known limitation, deliberately not widened.** `FinanceReviewView.vue:157` reads
      `transaction.allowedActions?.canApprove` for its explanation text and was always correctly
      cased, so the *explanation* and the *option list* disagreed; the option list is now correct, so
      the two agree. What is still unenforced is the reverse direction: nothing checks that the
      capabilities the server derives are the ones the command path enforces, beyond
      `TransactionPayloadFormatter::allowedActions()`'s own docblock claiming it — the same claim
      T329 found to be unverified in `ReportQuery`. Asserting it against `FinanceDecisionPolicy`
      directly is not this task.

- [x] T336 [HIGH] Either emit the caller's managed cost-centre codes or delete the gates that read
      them, because **`canReview` and `hasCostCenterScope` are permanently `false` for both management
      stages** (FR-004, FR-005, `src/stores/auth.ts`, `lib/Controller/SessionController.php`) (`missing`)

      **The client reads a capability the server never sends.** `src/stores/auth.ts:20` computes
      `costCenterCodes` from `capabilities.value.costCenterCodes`, but
      `SessionController::capabilities()` (`lib/Controller/SessionController.php:99-109`) returns a fixed
      five-key boolean map — `canPurchase`, `canReviewCostCenter`, `canReviewSenior`, `canDecideFinance`,
      `canAdminister`. `costCenterCodes` appears nowhere in `lib/`. `?? []` therefore makes
      `costCenterCodes` **always empty**, so `hasCostCenterScope` (`:24`) and both scope branches of
      `canReview` (`:44`, `:49`) are always `false`: `canReview` returns `false` for every `COST_CENTER`
      and `SENIOR` call regardless of the caller's actual assignments. FR-004/FR-005 scope cannot be
      expressed in the client at all.

      **It is latent, and say so rather than overstating it.** `canReview`, `hasCostCenterScope` and
      `costCenterCodes` appear **only inside `auth.ts`** — no view or component calls them today
      (verified across `src/`). So nothing user-visible is broken yet; the defect is that the next
      author to gate a queue view on `canReview` will build on a gate that can never open.

      **Note the type contradiction the `as` cast papers over.** `CapabilitySet` is
      `Record<string, boolean>` (`src/types/api.ts:273`), so `costCenterCodes` cannot legally hold a
      `string[]`; `auth.ts:20`'s cast is what makes it compile. Emitting the codes requires widening the
      declared capability shape on both sides, which is a contract change, not a local fix.

      **Acceptance criteria.** Either a session contract test asserts `capabilities.costCenterCodes`
      contains the caller's assigned cost-centre codes and a frontend test asserts `canReview` opens for
      an assigned manager and stays shut for an unassigned one — or `costCenterCodes`,
      `hasCostCenterScope` and `canReview` are deleted from the store so no permanently-false gate can
      be built on them. Record which. Cross-references: T013, T026, T008 (FR-008 multi-role self-review).

      ---
      **Delivered: the scope is EMITTED. The gates were not deleted, and the criterion required the
      choice to be recorded — so here is the reasoning.**

      Deleting `canReview`/`hasCostCenterScope` would have removed the *only* client-side expression of
      FR-004 and FR-005. T118's acceptance criterion is a shell with "capability-driven
      presentation", T115's is "capability-driven navigation", and US7's dashboard cards are per-role;
      all three need to know not merely *that* somebody is a manager but *where* they may act. Deleting
      the gate deletes the requirement's expressiveness in the interface and leaves the next author to
      re-derive the same thing — which is how the permanently-`false` version came to exist in the
      first place. FR-009 also says hiding an action must not be the only restriction, so the gate is a
      presentation aid over a server-enforced rule, not the rule itself — but a presentation aid that
      can never open is worse than none.

      **Where the scope lives, and why it is not in `capabilities`.** The task named
      `capabilities.costCenterCodes`, and the criterion quotes it; the field is
      `SessionContext.costCenterCodes` instead, because the contract declares `CapabilitySet` as
      `additionalProperties: { type: boolean }` — **a list of strings inside `capabilities` would be a
      contract violation**, and widening `CapabilitySet` to accept anything would throw away the boolean
      guarantee for every other capability. A scope is not a capability: it is data about the caller.
      The name is kept, so the criterion is satisfied in substance and the difference is recorded here.

      **Codes, not public ids, because codes are what the server authorizes with.**
      `ManagementReviewService::getManagedCostCenterCodes()` resolves a manager's scope to **codes** and
      `TransactionController`'s decision path compares against them; a transaction row carries
      `costCenter.code`. Emitting the same strings means a client gate and the rule behind it cannot
      disagree about what identifies a cost centre. `SessionController::costCenterCodes()` reads the
      **same repository and the same `Clock`** as `resolveRoles()`, so the roles and the scope cannot
      come from two different points in time, and applies `isValidOn()` exactly as
      `ReportScope::forActor()` does. An assignment carrying no cost centre contributes nothing rather
      than a null, for the reason `ReportScope` gives: dropping a null is the difference between "no
      results" and "all results", and only the first is safe.

      **An administrator gets an empty scope, deliberately.** FR-007 grants visibility without decision
      authority, and visibility is enforced per query server-side. Had the session reported "everything"
      for an administrator, a gate built on it would present review authority FR-007 forbids — the same
      trap T335's `FinanceReviewView` text fell into from the other direction.

      **The client's dead branch was also dead code twice over.** `canReview()` computed the same
      expression in two consecutive `if`s — an owner branch and a "normal review" branch — with
      identical conditions and a comment claiming the first handled FR-008's self-review case. Ownership
      was never consulted, so both branches returned the same thing. That is now one branch: role **and**
      scope, with ownership deliberately not consulted (FR-002/FR-008 grant a multi-role user review of
      their own transaction through role, scope and stage, never through ownership) and with a comment
      saying so, so the removed branch cannot be re-added as if it did something.

      **The two existing tests in this area were the reason nothing failed, and that is the finding.**
      `ManagementReview.spec.ts`'s "Authorization & Self-Review" cases both do
      `authStore.canReview = vi.fn().mockReturnValue(true|false)` — they **replace the function**, so the
      implementation was never consulted and the permanently-`false` version passed. The new block
      exercises the real store against a **server-shaped** `/me` response instead. That is the same
      trap T327 and Phase 55 recorded for payload fixtures, one level up: a test that mocks the unit
      under test is not evidence about it.

      **Two assertions I wrote were wrong, and both are corrected in place rather than deleted.**
      - "The scope is exactly `['CC-100']`" failed with `['CC-100', 'SVC5D2F31', 'SVCA7DA06',
        'UPD6F3913X']` — the acting user really is assigned three further cost centres in this shared
        instance. An absolute list is a claim about one instance's history, which is the trap T333
        documented. **No assertion in the new file is absolute now**: they are containment, exclusion,
        or shape.
      - "Every reported code is a cost centre that exists" failed on `UPD6F3913X`, and **the
        implementation was right and the assertion was wrong**: `GET /configuration/cost-centers` lists
        cost centres *active today*, while the session reports cost centres the caller is *assigned* to,
        and an assignment can outlive a cost centre's active window. Those answer different questions
        and the second is the right one for a scope — it is what the decision path compares. Recorded
        rather than papered over, because the difference is a real policy question somebody may want to
        decide later.

      **Results.** `SessionContractTest` **5/5** (34 assertions) · `scripts/dev/phpunit` **1204 tests /
      5974 assertions / 1 failure** — baseline 1199 with the same single failure, so exactly these five
      were added; the failure is T318's known `application/octet-stream` conflict ·
      `ManagementReview.spec.ts` **33/33** (was 26) · `npm run test:unit` **287/287** (was 280) ·
      `npm run typecheck` clean · `npx eslint` 0 problems on the changed files.

      **Known limitations, deliberately not widened.** `canReview`, `hasCostCenterScope` and
      `managedCostCenterCodes` are still **called by nothing in `src/`** — the gate is now openable but
      nothing opens it, which is T118/T115's presentation work and was true before this task.
      `costCenterCodes` is not filtered by whether the cost centre is still active, which is the policy
      question the second corrected assertion describes. And the store keeps a `?? []` on a
      contract-`required` field: that is the *empty* answer, the safe direction, and it must survive a
      `setUser()` that carries no scope at all.

- [x] T337 [HIGH] Emit or withdraw `SheetSummary.firstExpenseDate` and `lastExpenseDate`, because the
      contract declares two sheet fields that no producer can satisfy and only a test fixture reads
      (FR-063, `contracts/ocs-api.yaml`, `lib/Infrastructure/Http/SheetPayloadFormatter.php`) (`missing`)

      **Declared.** `contracts/ocs-api.yaml:1122-1123` gives `SheetSummary` both properties, and
      `src/types/api.ts:45-46` types them as **non-optional**. **Not emitted.**
      `SheetPayloadFormatter::summary()` (`:41-59`) is a complete array literal with exactly one nested
      call, `$this->totals($sheet)` (`:78-87`, also complete); neither mentions either property, and no
      `array_merge`, constant or helper contributes them. `grep -rn "firstExpenseDate\|lastExpenseDate" lib/`
      returns nothing. There is also no query support: `SheetSummaryQuery` exposes only
      `totalsForSheet()` (`lib/Infrastructure/Persistence/Expense/SheetSummaryQuery.php:45`).

      **Why nothing failed.** Neither property is in `SheetSummary.required` (`ocs-api.yaml:1109`), so a
      strict validator accepts their absence, and the only reader in the repository is a frontend
      **fixture** (`tests/Frontend/Component/PurchaserFlow.spec.ts:384-385`) — the same
      fixture-satisfies-the-gap trap T327 documented.

      **Emit or withdraw; do not leave it declared and dead.** They are derivable from the active
      revisions and are genuinely useful for the FR-064 date filter, so emitting is the better answer
      if the query is cheap. If they are withdrawn instead, the contract and the TS type must change in
      the same commit, and that is a contract amendment requiring the usual ownership.

      **Acceptance criteria.** A conformance check compares every `SheetSummary` property named in the
      contract against the keys the formatter emits — the check T327 asked for on `SheetDetail`, applied
      here — so a third payload cannot drift the same way. Cross-references: T327, T124, T275.

      ---
      **Delivered: EMITTED, not withdrawn — and the criterion's "if the query is cheap" is answered by
      making it cheap rather than by asserting that it was.**

      **`SheetSummaryQuery::expenseDateRanges()`** is one grouped aggregate —
      `MIN`/`MAX(r.expense_date)` over revisions joined through `active_revision_id` — and it takes a
      **list** of sheet ids. Two decisions in it are worth stating because both were the alternative:

      - **Active revisions only**, for the same reason `totalsForSheet()` reads only active revisions. A
        superseded revision keeps its `expense_date` as history (FR-050 requires it to), and a range
        that included it would describe the transaction's past rather than the sheet's contents.
      - **An aggregate, unlike the totals query.** This class's own docblock argues at length that the
        totals reduction rules must stay in `SheetTotals` and not be restated in SQL. That argument does
        not transfer here: "earliest and latest date" *is* the aggregate, and restating it in the domain
        would mean loading every active revision to compute two numbers. `MIN`/`MAX`/`GROUP BY` are in
        the portable catalogue, `scripts/portability-check.js` passes with the new query, and `sheet_id`
        leads `pettycash_transactions_sheet_state_idx` so the join is index-served (FR-077).

      **Batched, because the caller is a list endpoint — and the N+1 was the reason this could have
      been the wrong answer.** `GET /sheets` formats up to `pageSize` sheets; a per-sheet aggregate
      would have made it N+1, which is the trade T332 already recorded for `countByOwnerUid()`. So
      `SheetPayloadFormatter::summaries()` prefetches the ranges for the whole page in one query and
      `summary()` computes its own only when none is supplied — which is what `detail()` wants, since it
      formats one sheet. **The mapping stayed in the formatter rather than moving into the controller**
      for the reason `summary()` exists: a private copy of the contract shape in a controller is a
      second place for the contract to drift, and that is how `SheetController` came to emit an
      `accountId` the contract never described. The now-unused `SheetController::formatSheet()` was
      deleted rather than left as a second entry point.

      **The conformance check **parses** the contract rather than copying the key set**, and that is
      the difference from the precedent. `ConfigurationPayloadFormatterTest` states its sets literally
      and says why: "if the contract changes, these fail until someone re-reads it". That is a canary —
      it fires when a human renames a property and does nothing when a property is **added**, which is
      precisely this defect. `SheetSummaryConformanceTest` reads `components.schemas.SheetSummary.properties`
      out of the document, so a fourth unproducible field fails immediately. The reader is deliberately
      narrow — it understands exactly this document's shape and the test asserts it found a non-empty
      set, so it cannot return nothing and pass.

      **All four directions are asserted, and only one of them is the obvious one.** Every declared
      property is present (list *and* detail, because they are now two code paths); nothing undeclared
      is present; `SheetDetail` adds exactly `allowedActions` and `transactions` on top; and the two
      dates equal the **extremes of the transactions the same response already carries**. That last
      one is the load-bearing assertion: it ties the range to data the payload publishes, so a query
      reading a created-at date, a superseded revision, or another sheet's range fails there.

      **RED state was mutation-verified.** Deleting the two emitted keys fails **2 of the 5** — the
      conformance case, naming the defect literally (*"SheetSummary declares 'firstExpenseDate' and
      GET /sheets must emit it"*), and the range case (*"the range starts at the earliest expense
      date"*, `null` vs `2026-10-04`). The file was restored from an exact copy and re-run green.
      **Mutation-verification was the point for this task rather than a formality**: the whole defect is
      two declared properties with no producer, so a conformance check that passed against the broken
      producer would be worthless.

      **Results.** `SheetSummaryConformanceTest` **5/5** (160 assertions) ·
      `scripts/dev/phpunit` **1209 tests / 6138 assertions / 1 failure** — baseline 1204 with the same
      single failure, so exactly these five were added; the failure remains T318's known
      `application/octet-stream` conflict · `node scripts/portability-check.js` **OK** ·
      `php -l` clean on all three changed PHP files.

      **Known limitations, deliberately not widened.** A sheet whose active revisions carry **no**
      `expense_date` emits `null` for both — the honest reading of `format: date, nullable: true`, and
      not today's date — and is **absent from the batch map** rather than present with nulls, because
      "no range" and "a range whose endpoints are unknown" are the same answer. Nothing in `src/` reads
      the two fields yet, so emitting them fixes the contract gap without changing a screen; FR-064's
      date filter still has no UI (T088's).

- [x] T338 [MEDIUM] Emit the owning sheet's public id in `TransactionSummary.sheetId`, because the
      contract types it `format: uuid` and the UI renders **raw database integers** (FR-032,
      `lib/Infrastructure/Http/TransactionPayloadFormatter.php`) (`contradicts`)

      **One line, and the same formatter gets it right elsewhere.**
      `lib/Infrastructure/Http/TransactionPayloadFormatter.php:62` emits `'sheetId' => $transaction->sheetId`,
      and `lib/Domain/Expense/ExpenseTransaction.php:25` declares `public ?int $sheetId = null` — the
      surrogate key. Line 61, one line earlier, emits the transaction's own id as
      `$transaction->publicId ?? $transaction->id`, so the omission is an inconsistency rather than a
      convention. `contracts/ocs-api.yaml:1154` declares `sheetId` as `format: uuid`.

      **The value is displayed and unusable as a link.** `src/components/transaction/TransactionHeader.vue:22`
      and the queue views (`CostCenterQueueView.vue:57`, `SeniorQueueView.vue:58`) render it in a "Sheet"
      column, so reviewers see integers such as `41`. It is also not usable as the `{sheetId}` path
      parameter the contract declares, which matters the moment a queue row becomes a link — and
      interacts directly with T326's undecided question of where a sheet's transactions come from.

      **Fix it the way `costCenterSnapshot()` (`:147-171`) already resolves a public id**, with a
      snapshot-name fallback so a deleted sheet still renders.

      **Acceptance criteria.** A contract test asserts `sheetId` matches a uuid and equals the public id
      of the owning sheet, including for a transaction whose sheet row has been removed.
      Cross-references: T339, T326, T062.

      ---
      **Delivered. `TransactionSummary.sheetId` is now the owning sheet's public uuid, resolved through
      the sheet repository exactly as `costCenterSnapshot()` resolves the cost centre's.**

      `TransactionPayloadFormatter` gains an `ExpenseSheetRepository` and a private `sheetPublicId()`;
      the Application wiring and `FinanceSettlementTest`'s manual construction were updated with it. The
      resolution is a repository lookup rather than a new port method for a reason the repository already
      records: `ReportScope::forActor()` states that "inventing a `findByIds()` port method to serve a
      report's convenience would be speculative", and `ExpenseSheetRepository::findById()` is what
      `TransactionService::updateTransaction()`, `deleteTransaction()` and `EvidenceController` already
      call for exactly this hop. One extra small read per formatted transaction row is the same cost
      those callers already pay.

      **The unresolvable case answers `''`, and the three alternatives were each rejected for a stated
      reason rather than by preference.**
      - **Not the integer.** It is `format: uuid` and listed in `TransactionSummary.required`, and an
        integer in that field is a value a client will *use* — as a link, as a lookup key — and this one
        cannot be. That is the defect this task removes.
      - **Not `null`.** The member is required and not nullable, so `null` trades one contract violation
        for another.
      - **Not a 422 here.** FR-083 requires a broken reference to be answered as a data fault, and it
        names `GET /sheets`, `GET /sheets/{id}`, `GET /dashboard` and the report endpoints. **The
        transaction route is not among them, and T313/T314 own extending the policy to it.** Pinning a
        status here would make that improvement fail a test it did not break — the same mistake T311
        avoided by not pinning the substituted currency.

      **The "sheet row removed" half is asserted in-process, and not over HTTP on purpose.** Producing
      that fault over HTTP means a second corrupt-record probe, and `CorruptRecordFixture`'s own docblock
      is an argument against adding one casually: it carries one probe already, its `insert()` repairs
      before it writes so a crashed run cannot accumulate faults, and a probe whose failure mode is
      "leaves a landmine that breaks three reports" is what produced seven leaked sheets in this instance
      once already. `TransactionPayloadFormatterSheetIdTest` covers all four states in-process with the
      in-memory doubles — resolvable sheet, never the surrogate key, dangling reference, and a
      transaction with no sheet yet — and the live `TransactionSheetIdConformanceTest` covers the routes
      an HTTP test can be trusted on.

      **The live test asserts every row of every queue, across three fixture states, rather than one
      route.** Which queue holds rows depends on the fixture state (`senior` is empty in `submitted`,
      `finance` is empty in `draft`), so asserting "this queue is non-empty" would be a claim about the
      fixtures rather than about the server; the sweep therefore checks the shape of every row it finds
      and asserts only that the sweep as a whole saw rows, so "no rows" cannot pass vacuously. The paths
      are the routed `GET /queues/{stage}`, **not** `/reviews/*` — the first draft used the frontend's
      hash route and every case failed with `404`, which is the reminder that a Vue route and an OCS
      route are different things.

      **Results.** `TransactionSheetIdConformanceTest` **3/3** (53 assertions) ·
      `TransactionPayloadFormatterSheetIdTest` **4/4** ·
      `scripts/dev/phpunit` **1216 tests / 6202 assertions / 1 failure** — baseline 1209 with the same
      single failure, so exactly these seven were added · `php -l` clean on all four changed PHP files.

      **Known limitations, deliberately not widened.** The dangling-reference case still answers `200`
      with `sheetId: ''` where FR-083's wording ("a stored record whose reference does not resolve is a
      fault to be reported, not one to paper over") reads as a 422 — recorded as T313/T314's decision
      with the reasoning above, not silently widened here. And nothing in `src/` consumes `sheetId` as a
      route parameter yet; the three queue views render it as text, which is now correct, and T326's
      open question about where a sheet's transactions come from is unaffected.

- [x] T339 [MEDIUM] Emit public uuids for `Evidence.transactionId` and `Evidence.revisionId`, because
      **the aggregate types them `int` and the contract declares `format: uuid`** (FR-029,
      `lib/Domain/Evidence/Evidence.php`) (`contradicts`)

      **The violation is in the domain type, so both emitters inherit it.**
      `lib/Domain/Evidence/Evidence.php:13-14` declares `public int $transactionId` and
      `public int $originRevisionId`, populated from `(int) $row['transaction_id']` at
      `lib/Infrastructure/Persistence/Evidence/DbEvidenceRepository.php:143-144`. Both emitters pass them
      straight through: `lib/Controller/EvidenceController.php:385-386` and
      `lib/Infrastructure/Http/TransactionPayloadFormatter.php:360-361`. The contract declares both
      `required` with `format: uuid` (`contracts/ocs-api.yaml:1226-1227`); `src/types/api.ts:184-185`
      types them `string`, so this is a silent runtime violation that `npm run typecheck` cannot see.

      **Fix the aggregate, not the two formatters.** Changing only the emitters would leave the domain
      lying about its own type. While here, note that the two emitters of the same schema disagree:
      `EvidenceController.php:394` adds an undeclared `deactivationReason` that
      `TransactionPayloadFormatter.php:356-370` omits, and `id` is written two different ways
      (`$evidence->id` vs `$evidence->publicId ?? $evidence->id`) — equivalent today only because of the
      mirror at `DbEvidenceRepository.php:135`. `AccountPayloadFormatter`'s own docblock
      (`lib/Application/Account/AccountPayloadFormatter.php:22-30`) already warns about exactly this drift.

      **Acceptance criteria.** One contract test per emitting endpoint asserts both ids are uuids, and a
      schema-shape test asserts the two emitters produce identical key sets.
      Cross-references: T338, T092, T096.

      ---
      **Delivered, and the "fix the aggregate, not the two formatters" instruction turned out to be the
      smaller change than it looked — because there is now one formatter.**

      1. **`Evidence` gains `transactionPublicId` / `originRevisionPublicId`** as trailing optional
         properties, paired with the existing `int` join keys exactly as T330 paired
         `TransactionRevision::$expenseTypeId` with `$expenseTypeDbId`. The join keys stay available to
         the repository, so **no query was rewritten and no construction site changed** — the ~25 places
         that build an `Evidence` are untouched.
      2. **`DbEvidenceRepository` resolves both**, in `mapRow()` and — this is the part that was not
         obvious — in `save()`. Without the second, `POST /transactions/{id}/evidence` would have answered
         `transactionId: ''` while every later read of the same row carried a uuid: the **upload
         response** is the one evidence payload produced from an aggregate that has never been through
         `mapRow()`. Found by the test, which asserted the upload response and failed with `''`.
      3. **The resolution is memoised per repository instance.** `findByTransactionId()` maps every row of
         the page, and a transaction carrying twenty evidence files would otherwise issue forty extra
         single-row reads for two ids that repeat on every one of those rows. The container builds one
         repository per request, which is what makes an instance-level memo safe; a long-running process
         would have to drop it, and the alternative (a batched second query per page) costs a query even
         for a one-row page.
      4. **`TransactionPayloadFormatter::evidence()` is now public and `EvidenceController` delegates to
         it.** There were two emitters of one schema and they disagreed — that is the criterion's second
         half, and it is now a property of the code rather than of two copies happening to agree today.

      **The `deactivationReason` half of this task went the other way, and the reason is worth more than
      the change.** The note records `EvidenceController.php:394` adding "an undeclared
      `deactivationReason`", and the obvious reading was that the schema was missing a key the server
      should stop sending. Deleting it **broke
      `EvidenceLifecycleControllerTest::testTheRecordedReasonShowsWhichRemovalActuallyHappened`**, and that
      test is the only observable of which removal path ran: FR-058 keeps submitted evidence and marks it
      inactive, the row is deactivated either way, and `GET /evidence/{id}/content` answers 403 once
      inactive — so the reason string is the sole signal. **The contract was what was missing**, so
      `Evidence.deactivationReason` is now declared (`nullable: true`, with the reasoning in a comment at
      the declaration) and `src/types/api.ts` carries it. Recorded as a contract amendment owned by this
      task, in the same change as the emitter that publishes it.

      **Two component prop types were a third copy of this schema, and that is how the typecheck gap
      stayed invisible.** `EvidenceList.vue` and `EvidenceCard.vue` each declared their own eleven-member
      `Evidence` shape inline. They were already behind the server — no `deactivationReason`, and
      `deactivatedAt` typed `string | undefined` where the contract declares `nullable: true` — and
      `npm run typecheck` could not say so, because the two views passing `TransactionDetail.evidence`
      into them were comparing two unrelated declarations. Both now take `Evidence` from
      `@/types/api`, which is what turned the drift into a compile error the moment the server's shape
      moved. **Without this the `| null` addition would have been a type error rather than a fix.**

      **One assertion I wrote was wrong and is corrected in place.** "The deactivate response equals the
      upload response" failed on `active: true → false` — which is the correct behaviour of a
      deactivation. It now compares the **schema** (the key list) and the two id fields explicitly, and
      separately asserts that the row *is* reported inactive, so the case cannot pass on a no-op.

      **The read-path cases upload their own evidence rather than relying on a fixture that has none.**
      The `draft` fixture transaction ships without evidence, so "no rows" would have satisfied three of
      the four cases vacuously; one upload in `setUp()` (not one per test) keeps it to a single row per
      run rather than the per-test row creation T333 recorded as shared-instance debt.

      **Results.** `EvidencePayloadConformanceTest` **4/4** (45 assertions), covering the transaction
      detail, the upload response and the deactivate response ·
      `EvidenceLifecycleControllerTest` **8/8** with the reason pin intact ·
      `scripts/dev/phpunit` **1220 tests / 6256 assertions / 1 failure** — baseline 1216 with the same
      single failure (T318's `application/octet-stream` conflict), so exactly these four were added ·
      `npm run typecheck` clean · `npm run test:unit` **287/287** unchanged · `npx eslint` **0 errors**;
      the 6 `vue/html-closing-bracket-newline` warnings in the two components are pre-existing.

      **Known limitations, deliberately not widened.** A dangling `transaction_id`/`origin_revision_id`
      emits `''` rather than a 422, on the same reasoning and with the same ownership as T338's
      (T313/T314). `EvidenceService` still passes the surrogate keys to the appdata key derivation, which
      is internal and correct — the uuid space is only a wire concern. And the contract amendment adds a
      member, so T124's generated types must be regenerated against the amended document; that ordering
      was already recorded for T275 and T312 and applies unchanged.

- [x] T340 [MEDIUM] Resolve `SheetSummary.ownerDisplayName` instead of emitting a literal empty string,
      because **the sheet's Owner row renders blank for every sheet** (FR-063,
      `lib/Infrastructure/Http/SheetPayloadFormatter.php`) (`partial`)

      **The key is present, which is why nothing failed.** `SheetPayloadFormatter.php:45` emits
      `'ownerDisplayName' => '',` — a literal, with no lookup. The formatter's constructor (`:26-30`)
      takes only `SheetSummaryQuery`, `ExpenseTransactionRepository` and `CurrencyRepository`; no
      `UserDirectory` is injected, and `ExpenseSheet` carries only `ownerUid`
      (`lib/Domain/Expense/ExpenseSheet.php:22`).

      **It is rendered.** `src/views/sheets/SheetDetailView.vue:141` prints
      `sheetStore.currentSheet?.ownerDisplayName`, so the Owner row is empty on screen. A shape check
      passes, so only a rendered assertion catches this.

      **`UserDirectory` is already bound in the app and already used this way** —
      `lib/Controller/SessionController.php:58-60` resolves the caller's own display name through it, so
      this is a wiring omission, not a new dependency.

      **Acceptance criteria.** A contract test asserts `ownerDisplayName` equals the owner's current
      display name for a sheet owned by another user, and a component test asserts the Owner row is
      non-empty. Cross-references: T063, T063's owner-scope rules, T026.

      ---
      **Delivered. `SheetPayloadFormatter` injects the `UserDirectory` port `SessionController` already
      resolves the caller's own name through, so this was a wiring omission rather than a new dependency.**

      **A uid the directory does not know falls back to the uid — and an empty string from the directory
      is treated as "no name" too.** The member is declared in the contract, so an Owner row reading
      "u1234" is legible where one reading nothing is not, and an account deleted after its sheet was
      written is exactly that case. This is `SessionController::me()`'s rule, kept identical so the two
      endpoints cannot describe one person differently.

      **The criterion's cross-user case is unreachable over HTTP, and that is a finding rather than a
      gap in the work.** `SheetService::getSheet()` returns `null` for a sheet whose `ownerUid` is not the
      caller, and every fixture sheet belongs to the acting user — so **no sheet owned by somebody else
      can be read through this API at all**. The formatter is owner-agnostic, so the case would be
      reachable in-process, but `SheetPayloadFormatter` cannot be constructed in the unit suite: it takes a
      `SheetSummaryQuery`, which takes an `OCP\IDBConnection`, and the unit bootstrap loads no `OCP\*`
      class (the reason `AccountWriteControllerTest` says its capability assertion "cannot be pinned by a
      unit test"). Building it there would have meant registering a reflection-constructed
      `SheetSummaryQuery` in the shared bootstrap so one test could run — a fixture hack the repository has
      better reasons to avoid.

      **So the live assertion is a cross-endpoint equality, which is stronger than an absolute string.**
      `GET /me` resolves the caller's display name through the same port, so
      `sheet.ownerDisplayName === me.displayName` is a statement that two endpoints agree about one
      person — the exact defect class `ReportScope::forActor()`'s comment records having been found when
      `/dashboard` and `/me` disagreed. A `''` fails it, and so does a *wrong* name. The list projection is
      asserted separately because `summaries()` and `summary()` are different call paths — the same split
      that let T337's `firstExpenseDate` exist on one and not the other.

      **The Owner row had no `data-testid`, which is the second half of why nothing caught this.** A
      rendered value with no hook is a value no assertion can reach, so the row now has
      `data-testid="sheet-owner"` and a component case asserts it renders the name the payload carries.
      **That case is explicitly not the load-bearing one** and says so: the view prints whatever the
      store holds, so it cannot detect the server's half — it catches the view substituting or dropping the
      value, which is the half a component test can see. `SheetOwnerDisplayNameTest` over HTTP is the
      evidence for the defect itself.

      **Results.** `SheetOwnerDisplayNameTest` **3/3** (64 assertions) · `PurchaserFlow.spec.ts` **89/89**
      · `npm run test:unit` **288/288** (was 287) · `scripts/dev/phpunit` **1223 tests / 6324 assertions /
      1 failure** — baseline 1220 with the same single failure (T318's `application/octet-stream`
      conflict), so exactly these three were added · `npm run typecheck` clean · `npx eslint` **0 errors**;
      the 6 `vue/html-closing-bracket-newline` warnings in `SheetDetailView.vue` are pre-existing.

      **Known limitation, deliberately not widened.** `SheetDetailView`'s Owner row renders the payload
      verbatim, so a **missing** field renders as an empty div with no user-visible explanation — the same
      "blank reads as an answer" shape Phase 50 records for the audit card (T343 owns that one). Nothing
      asserts that the client can distinguish "no owner name" from "no owner", because after this change
      the server does not send the first.

- [x] T341 [MEDIUM] Decode the base64 artifact out of the export envelope before saving it, because the
      downloaded file **is** the JSON envelope rather than the CSV/XLSX/PDF/ZIP (FR-066,
      `src/services/reportsApi.ts`, `lib/Controller/ExportController.php`) (`contradicts`)

      **The server states the reason it cannot stream.** `lib/Controller/ExportController.php:161-173`
      returns `['artifactName', 'mediaType', 'format', 'byteSize', 'rowCount', 'artifactRemoved',
      'data' => base64_encode($bytes)]` with the comment: "Nextcloud's OCS layer wraps every body as
      JSON, so the artifact cannot be the raw response body the contract's media types describe." The
      contract agrees (`contracts/ocs-api.yaml:1266-1279`, `data: {type: string, format: byte}`). So the
      wire shape is settled and deliberate.

      **The client ignores it.** `src/services/reportsApi.ts:72-79` requests `responseType: 'blob'` and
      returns `response.data` unchanged, and its docblock asserts the endpoint "streams the artifact, so
      the OCS `data` envelope the JSON helper unwraps does not apply" — which the server's own comment
      contradicts. `src/stores/reports.ts:154-159` and `ReportView.vue:24-33` then save those bytes as
      `pettycash-sheets-<date>.csv`.

      **This is currently masked by T328.** Every export 422s before a body is produced, so the wrong
      bytes have never reached a disk. Fixing the field name without fixing this turns a refused request
      into a corrupt download, which is worse than the bug it replaces — **do them in one change, and
      re-verify the download byte-for-byte.**

      **Acceptance criteria.** Decode `ocs.data.data` from base64 and wrap the bytes in a `Blob` typed by
      `ocs.data.mediaType` before returning; a test asserts the saved blob's first bytes are the real
      artifact and not `{`.

      ---
      **Delivered in the same change as T328, deliberately — not after it.** `requestExport` now reads
      the `ExportResult` out of `ocs.data` and returns it; `exportArtifactBlob()` decodes `data` from
      base64 and wraps the bytes in a `Blob` typed by the server's own `mediaType`. The store calls it,
      so `DownloadableExport.blob` is the artifact and not the envelope that carries it.
      **The UTF-8 trap is the reason this is a function and not one line.** `atob` returns a binary
      string — one character per byte — and handing that to `Blob` encodes each character's code point
      as UTF-8, which round-trips only below `0x80`. A Persian CSV is above it on every line, so the
      naive version would produce a file that opens as mojibake while every structural assertion still
      passed. The bytes are therefore converted explicitly, and a test asserts the round trip with
      `0xD8 0xA7 0xD8 0xB3` — bytes a code-point encoding would change.
      **The old assertion was `expect(payload.blob).toBeInstanceOf(Blob)`, and it is worth naming what
      that missed:** a `Blob` wrapping `{"ocs":{"data":{…,"data":"<base64>"}}}` satisfies it perfectly.
      The two new tests read the bytes back and compare them with what the exporter wrote.
      **The test environment forced one accommodation, recorded so it is not mistaken for a workaround.**
      `Blob.prototype.text()` and `arrayBuffer()` **do not exist in jsdom** — its `Blob` exposes only
      `slice`, `size` and `type` — so the obvious assertions throw `is not a function` and a byte-level
      check cannot be written at all. `FileReader` is the API jsdom does implement, so `blobBytes()` uses
      it. Verified by probing the environment rather than by assuming.
      **`responseType: 'blob'` is gone from the request**, and a test asserts it stays gone: asking for
      a blob would hand the client the envelope's bytes already typed as the artifact's media type,
      which is a quieter version of the same defect.
      Cross-references: T328, T342, T113.

- [x] T342 [MEDIUM] Stop offering export for the three subjects the server answers with an empty
      artifact, because a **well-formed file with zero rows is downloaded and reported as measured**
      (FR-065, `src/types/reporting.ts`, `lib/Controller/ExportController.php`) (`contradicts`)

      **The client offers three formats for subjects that cannot be produced.**
      `src/types/reporting.ts:95-103` maps `cost-centers`, `accounts` and `process` to the generic
      `resource: 'report'` and gives each three formats; `ExportControls.vue:37` enables the button
      whenever `availableFormats.length > 0`.

      **The server has no row source and says so only in a comment.**
      `lib/Controller/ExportController.php:255-264` refuses a *selection* of `report`/`audit`/`evidence`
      with `UnsupportedSelection`, then falls through to `:269` `return [[], []];` under the comment
      "an empty artifact, which is at least an honest answer". For these three subjects the request
      therefore succeeds, and `rowCount`/`byteSize` make the emptiness look measured.

      **The honest answers are available; neither is chosen here.** Refuse the three mappings on the
      client (the smallest correct change, and it matches the server's own "not supported yet" wording),
      or implement selection resolution for `report` in `ExportController`. Refusing is preferable: FR-065
      asks for these reports to exist, not for them to export as empty files, and an export button that
      silently produces nothing is the failure mode FR-064/FR-082 exist to prevent.

      **Acceptance criteria.** The export control is disabled with an explanatory message for those three
      subjects, or the export returns the same refusal it already returns for a selection. A test asserts
      no artifact is produced with `rowCount === 0` for a subject that has rows.
      Cross-references: T328, T341, T309.

      ---
      **Delivered: BOTH halves, because they answer different halves of the criterion.** The server no
      longer produces the artifact (the load-bearing fix — nothing can be downloaded that reads as
      measured), and the client no longer offers the formats, with a stated reason.

      **The server now refuses a resource with no row source.** `rowsForSelection()` returned `[[], []]`
      under the comment "an empty artifact, which is at least an honest answer", and it was not one:
      `ExportResult` then carried `rowCount: 0` **and** a real `byteSize` for a real file with a real
      header row, so the emptiness read as a measurement. It now throws the same `UnsupportedSelection`
      a selection gets — a 422 whose reason says no artifact is produced and why. **The distinction drawn
      is the one that matters**, and it is asserted in both directions: an export of `sheets` that matches
      nothing is a *measured* zero and stays `200` with `rowCount: 0`
      (`testAnEmptyResultStillNamesItsColumns`), while an export of a resource with no row projection is
      not a measurement and is refused. The unreachable fall-through became a `LogicException` rather than
      a silent `return [[], []]`, so a future resource added without a branch fails loudly instead of
      resuming the defect.

      **The three subjects offer no format, and `audit`/`evidence` deliberately keep theirs — the
      difference is who is asking, and it is worth stating because it looks arbitrary.**
      - `cost-centers`, `accounts` and `process` all map onto the generic **`report`** resource, which the
        client itself invents. There is no per-subject server capability for them to correspond to, so
        offering an export for them is not honest about anything, and they now offer `[]`.
      - `audit` and `evidence` have **their own contract resources**, are required V1 exports (FR-066's
        audit export, FR-074/FR-082's grouped evidence ZIP), and are refused only because their row
        projections are outstanding work. A refusal the server *explains on click* — "not supported yet",
        naming the resources that do work — tells a user something a silently greyed-out control does not,
        so their formats stay and the server's message is what they meet. Removing `zip` from the evidence
        report would have deleted the only place that V1 capability appears in the interface, on the
        grounds that a test asserted it.
      - A separate case asserts the evidence formats are still offered, because a blanket
        "nothing is exportable" would satisfy the first test as well.

      **The disabled control explains itself**, which is the same failure one level up: a grey button
      with no message tells the user nothing, and that is FR-064/FR-082's own failure mode.
      `ExportControls.vue` renders `reports.export.unsupportedSubject` in a `role="note"` region when a
      subject offers nothing — `note` rather than `alert` because this is a standing property of the
      subject, not a failure that just happened. Two guards keep it from competing with better
      information: it is hidden while an export is in flight, and it is suppressed whenever a server
      refusal is already displayed, because that refusal names the resource and what does work.
      The key has no catalogue entry yet because none exists in the repository — `src/l10n.ts` and
      `l10n/fa.js` are T119's — and every other key in this component is in the same position.

      **The criterion's `rowCount === 0` assertion is asserted as the *absence of the whole result*, which
      is stronger.** The test walks `report`, `audit` and `evidence` and asserts both that each is
      refused with a reason naming the supported resources and that the body carries **no** `rowCount` and
      no `data` at all — because a test asserting `rowCount !== 0` would still pass against an artifact
      with one invented row.

      **Results.** `ReportAndExportControllerTest` **62/62** (was 61; the new case iterates three
      resources) · `ReportingExport.spec.ts` **47/47** (was 42) · `npm run test:unit` **293/293** (was
      288) · `scripts/dev/phpunit` **1224 tests / 6349 assertions / 1 failure** — baseline 1223 with the
      same single failure (T318's `application/octet-stream` conflict) · `npm run typecheck` clean ·
      `npm run stylelint` clean · `npx eslint` 0 problems on the three changed frontend files.

      **Known limitations, deliberately not widened.** `audit` and `evidence` row projections remain
      unimplemented, so those two subjects still produce a refused export rather than a file — that is
      T302's/T309's subject, and this task made the refusal honest rather than adding the projections.
      And the client cannot know *which* resources the server supports without asking, so the client's
      format list and the server's row sources are two facts that must be kept in agreement by
      convention; the new test asserts the agreement for the three subjects rather than deriving it.

- [x] T343 [MEDIUM] Give the sheet-detail audit card a data source or remove it, because it currently
      renders a header and a **permanently blank body** that reads as "no history" (US6, FR-057,
      `src/views/sheets/SheetDetailView.vue`) (`missing`)

      **The body row is disabled by a literal.** `src/views/sheets/SheetDetailView.vue:248`:

      ```html
      <tr v-if="false">
      ```

      This is the only row in the five-column audit table (`:235-254`), so the "no events" message can
      never render either. There is no audit loader bound in this view — no `loadAudit` call at all —
      so the card has no data source. Contrast `TransactionDetailView.vue:98`, which *does* call
      `loadAudit` and then discards the result (`transactions.ts:86-94` returns the page and nothing binds
      it), which is a second, separate instance of the same gap.

      **Why it is worse than an unfinished feature.** A header with an empty body is indistinguishable
      from a sheet with no audit history, so it actively misinforms: a reviewer sees an empty table and
      concludes nothing was recorded.

      **Acceptance criteria.** Either bind a role-scoped audit page to the table (with the empty state
      actually reachable), or remove the card. If bound, also fix `TransactionDetailView`'s discarded
      result in the same change — one audit surface, not two half-wired ones. Cross-references: T098,
      T326, Phase 50 (the `GET /transactions/{id}/audit` shape and pagination gap).

      ---
      **Delivered: REMOVED, in both places — and the "if bound, also fix `TransactionDetailView`" clause
      is why the second removal happened in the same change.**

      **There is no sheet-scoped audit endpoint, so the card had no data source to be bound to.**
      `appinfo/routes.php` declares exactly one audit route, `GET /transactions/{id}/audit`. Binding the
      card would have meant either N per-transaction requests assembled client-side into a sheet history,
      or a new endpoint and a contract amendment — and **Phase 50 already owns the audit page's shape and
      pagination**, so inventing a sheet-scoped surface here would have pre-empted it in a worse shape
      than the one it is about to decide.

      **The dead row was a literal, and that is why the empty state was unreachable too.**
      `<tr v-if="false">` was the only row in a five-column `<tbody>`, so the "no events" message could
      never render either. The view bound no loader at all, which means the empty body was not a
      *measured* absence — it was the absence of a request.

      **`TransactionDetailView`'s discarded result is the same defect, so it went too.** It called
      `transactionStore.loadAudit(...)` on mount, and `loadAudit` **returns the page and stores
      nothing**, so the request bought a network round trip and rendered nothing. The criterion asked for
      one audit surface rather than two half-wired ones; the strictest reading of "one" here is zero
      half-wired ones plus a named owner, and Phase 50 is that owner. The endpoint itself is untouched —
      `GET /transactions/{id}/audit` still serves, and Phase 50 binds it once the shape is settled.

      **Both removals are asserted as absences, deliberately.** A test asserting the card renders an empty
      state would be asserting the defect; what is asserted is that a card with no data source is not on
      the screen. Nothing asserted the card's presence — `grep` for `audit-tab`/`audit-table` in
      `tests/Frontend/` returned nothing — so no existing test changed with it, and the removal is not
      visible as a regression anywhere.

      **Results.** `PurchaserFlow.spec.ts` **90/90** (was 89) · `npm run test:unit` **294/294** (was 293)
      · `npm run typecheck` clean · `npx eslint` **0 errors**; the 15 `vue/html-closing-bracket-newline`
      warnings across the two views are pre-existing.

      **Known limitations, deliberately not widened.** US6/FR-057's sheet-level history is **not
      delivered by this task** — it is deferred to Phase 50 with the endpoint, the shape and the
      pagination, and nothing on the sheet detail page presents audit information now. A reviewer who
      needs a transaction's history opens the transaction, where the endpoint exists but is likewise not
      yet bound; before this change they were told "no history" on both pages, and now they are told
      nothing, which is the smaller of the two misinformations but is still not the feature.

- [x] T344 [LOW] Delete or correct the three unrouted controller actions, because one of them returns a
      **different schema from the live route serving the same URL** and would regress a fixed endpoint if
      it were ever wired in (unrequested, regression trap)

      **T235 already owns two of the three.** `lib/Controller/QueueController.php:30` and
      `lib/Controller/ReturnsController.php:30` are the unrouted duplicates T235 records. The third is
      not in that task: `lib/Controller/TransactionController.php:197-243` `decide()` is unrouted while
      `appinfo/routes.php:41` serves `POST /api/v1/transactions/{transactionId}/decisions` from
      `review#decide`.

      **The trap is the payload, not the duplication.** `TransactionController::decide()` returns
      `new DataResponse($this->formatTransaction($outcome->transaction), 201)` (`:239`) — a bare
      `TransactionDetail` — where the contract requires
      `DecisionResult {decision, transaction, accountMutation?}`
      (`contracts/ocs-api.yaml:1207-1213`). The routed twin returns it correctly
      (`lib/Controller/ReviewController.php:258-273`). Wiring `transaction#decide` in as part of a cleanup
      would therefore **silently remove `decision` and `accountMutation` from a working endpoint**.

      **The client was written against the dead shape.** `transactionsApi.submitTransactionDecision` is
      typed to that response, and `transactionsStore.submitDecision` (`transactions.ts:148-164`) is
      exported with **no consumer in `src/`** — `DecisionForm.vue:121` calls `reviewsStore.recordDecision`
      instead. So the store-mutation half is latent, not live, and it is a loaded gun for the next author.

      **`QueueController` carries a second landmine.** `QueueController.php:33` would raise
      `ValueError` on the kebab-case stage slug the client sends (`WorkflowStage::from('cost-center')`),
      producing the `400` at `:38`, if its route were ever restored.

      **Acceptance criteria.** All three unrouted actions are deleted (the smaller, safer choice), or
      `transaction#decide` is retyped `Promise<DecisionResult>` and the store can no longer write a
      `TransactionDetail` into `transactions.value` / `currentTransaction.value`. Add the
      route-dispatch completeness check T326 asks for so a fourth cannot appear: a frontend service
      method addressing a path no route serves, or a controller action no route reaches, is a failure.
      Cross-references: T235, T326, T030.

      ---
      **Delivered: all three deleted AND the client's type corrected — because the dead PHP action and the
      client's wrong type were the same mistake, and deleting only the PHP would have left the client's
      shape unchallenged.**

      `TransactionController::decide()`, `QueueController.php` and `ReturnsController.php` are gone, with
      `Decision`, `ManagementReviewService` and their unused constructor parameters removed from
      `TransactionController` rather than left behind as dead injections.

      **`RouteResolutionTest`'s unrouted-controller assertion now expects an empty list**, with its
      message rewritten from "T235 owns the decision to remove or route them" to "T344 removed them, and a
      controller appearing here means a class no route can reach exists". The list is asserted `[]` rather
      than a named pair, so a **fourth** unrouted controller fails it — which is the point of the
      criterion, and what the previous `['QueueController', 'ReturnsController']` expectation could not do.

      **The new check is the other direction of the one T326 shipped, and it is the one that would have
      caught all three.** `RouteDispatchCompletenessTest::testEveryControllerActionIsReachableFromARoute`
      compares every public controller method against the `controller#action` names
      `appinfo/routes.php` dispatches. Unlike the client-side direction, **this one must be empty and is
      asserted empty**, and the asymmetry is deliberate and stated in the docblock: an action with no route
      is unreachable by *anything*, whereas a routed action no client calls yet
      (`ConfigurationController` under T087, `EvidenceController::links`/`deactivateLink` under T096) is
      work in progress. Asserting symmetry in that second case would fail on correct work — the mistake
      the existing file already documents.

      **The check has its own self-check, because the failure mode of a regex scan is passing vacuously.**
      `testTheScanActuallyFindsControllerActions` asserts the action scan found `sheet#index` and that the
      route table yielded `review#decide`, so a refactor that changes how either side is spelled fails
      there instead of leaving the comparison `everything` vs `nothing` and therefore satisfied. This is
      the third instance of the same guard in this repository after `LayerBoundaryTest` and T326's own
      `testTheScanActuallyFindsClientPaths`.

      **The client's type was wrong in exactly the way the dead action was wrong, which is the finding.**
      `submitTransactionDecision` was typed `Promise<TransactionDetail>` — the schema the *unrouted*
      `transaction#decide()` returned — while `review#decide`, the route that serves this path, answers the
      contract's `DecisionResult {decision, transaction, accountMutation?}`. So the client was written
      against a duplicate of an endpoint no route reached, and reading `decision` or `accountMutation` off
      the result was **a compile error** while `transaction` alone type-checked. It is now
      `Promise<DecisionResult>`, and `submitDecision` writes `result.transaction` into
      `transactions.value` / `currentTransaction.value` and returns `result` — so there is no bare
      transaction in scope to write and the store cannot repeat the mistake. `accountMutation`'s **omission**
      is the contract's shape (FR-040 records a consumption only for a final finance approval, so an empty
      mutation would claim money moved when none did) and is asserted as undefined, not as `{}`.

      **`submitDecision` still has no consumer in `src/`**, and that is recorded rather than hidden:
      `DecisionForm.vue` calls `reviewsStore.recordDecision`. It was latent before this task too. The
      export exists so the decision path has one owner, and the route-dispatch check now proves the
      endpoint it addresses is the one the server serves.

      **Results.** `RouteDispatchCompletenessTest` **4/4** (was 2; the two new cases are the check and
      its self-check) · `RouteResolutionTest` green with the empty-list expectation ·
      `http.spec.ts` **19/19** (was 17) · `npm run test:unit` **296/296** (was 294) ·
      `scripts/dev/phpunit` **1226 tests / 6357 assertions / 1 failure** — baseline 1224 with the same
      single failure (T318's `application/octet-stream` conflict), so exactly the two new cases were added
      · `npm run typecheck` clean · `npx eslint` 0 problems on the three changed frontend files ·
      `php -l` clean on `TransactionController.php`.

      **Known limitations, deliberately not widened.** The reverse check is a structural fact about text,
      so a controller action invoked from PHP rather than a route would be flagged as unrouted — there is
      no such action today, and if one is ever legitimate the check needs the exception mechanism
      `ConfigurationController` would also need. And the criterion's alternative (retype rather than
      delete) was **not** taken for `transaction#decide`: with the action deleted, there is nothing left to
      disagree with `review#decide`, so there is no second implementation to keep consistent.

---

**What this pass did not find, and why that is worth recording.** The route layer is otherwise
**complete**: all 40 contract path+verb pairs have a route, and every route except the 13 owned by
T087 (`ConfigurationController`, absent by design) and T096 (`EvidenceController::links` /
`deactivateLink`) resolves to a real method. Twelve further response schemas were checked key-by-key
against their emitters — `SessionContext`, `CapabilitySet`, `Money`, `PageMeta`, `Account`,
`Movement`, `AccountMutationResult`, `SheetTotals`, `TransactionSummary`, `TransactionDetail`,
`Decision`, `ActorSnapshot`, `AuditEvent`, `ReportPage`, `ExportResult` — and are **complete**. The
PHP suite's single failure is T318's, already owned. Nothing here contradicts a constitution principle,
so no CRITICAL finding is a compliance violation; the four CRITICAL items are P1 journey blockers.

**The generalisable lesson, recorded so the next pass starts here rather than from a symptom.** Three
correspondences have no symptom and can only be found by enumeration: contract path ↔ route, contract
schema ↔ emitted keys, client payload key ↔ server payload key. Phase 30 audited route integrity and
found nothing, because every gap in these lists is an **absence**, not a route pointing at a method
that does not exist. Fourteen of the seventeen findings above are invisible to the suites because the
frontend specs stub the HTTP layer with fixtures that contain the keys the server never sends. A green
`npm run test:unit` and a green `scripts/dev/phpunit` are therefore not evidence that a wire is
connected, and this repository has now demonstrated that twice (T327, and this phase).

## Phase 56: Convergence (two findings T334's verification produced)

Neither finding was looked for. Both were produced by T334's own acceptance criterion — "one contract
test per error response asserting the shape" — reaching an export request and reading the server log
to explain a 500 that was not the one the test was about. They are recorded here rather than fixed
inside T334 because both change app-level wiring or an endpoint T334 does not own, and widening a task
to fix an unrelated live defect is how a task stops being reviewable.

- [x] T345 [HIGH] Make the application's Composer dependencies autoloadable in the deployed app,
      because **every XLSX export answers HTTP 500** with `Class "PhpOffice\PhpSpreadsheet\Spreadsheet"
      not found` (FR-066, `composer.json`, `composer/autoload.php`, `lib/Infrastructure/Export/XlsxExporter.php`)
      (delivered)

      **Measured, not inferred.** `POST /exports` with `resource: sheets, fileFormat: xlsx` answers
      `500`; `nextcloud.log` records the exception at `XlsxExporter.php:243` via
      `ExportService::generateXlsx()`. The class is present on disk
      (`vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Spreadsheet.php`) and loadable — the same
      file answers `class_exists(...) === true` in the container's CLI SAPI.

      **The mechanism, from Nextcloud's own source rather than from a guess.**
      `OC_App::registerAutoloading()` (`lib/private/legacy/OC_App.php:104-126`) registers an app's
      Composer autoloader **only** from `$appPath . '/composer/autoload.php'`. This repository had no
      `composer/` directory, so under Apache the app's `vendor/autoload.php`
      was never registered: `OCA\PettyCash\*` still resolves through Nextcloud's PSR-4 fallback
      (`addPsr4($appNamespace, $path . '/lib')`), and **no third-party Composer class resolved at
      all**. `tests/bootstrap.php:5` requires `vendor/autoload.php` explicitly, which is precisely why
      the entire test suite could not see this.

      **Blast radius is small and was enumerated rather than guessed.** Every non-`OC*`/`Psr\Log`
      namespace used by `lib/` is `PhpOffice\PhpSpreadsheet` (6 `use` statements, all in
      `XlsxExporter`); `Psr\Log` comes from Nextcloud's own `3rdparty`. So the single user-visible
      consequence is that one of the three formats FR-066 names is a 500 for every request — and the
      client offers XLSX for every report subject.

      ---

      **Both originally-offered fixes are unusable, and one of them was never more than a no-op. Recorded
      because the rejected options are the finding.**

      **Option (a), `appinfo/app.php`, cannot work on Nextcloud 34 at all.** `grep -rn "app\.php"` over
      `/var/www/html/lib/` and `/var/www/html/core/` returns **two** hits, and neither is an `include`:

      ```php
      // lib/private/App/AppManager.php:490
      if (is_file($appPath . '/appinfo/app.php')) {
          $this->logger->error('/appinfo/app.php is not supported anymore, use \OCP\AppFramework\Bootstrap\IBootstrap on the application class instead.', ['app' => $app]);
      }
      $coordinator = Server::get(Coordinator::class);
      $coordinator->bootApp($app);
      ```

      The file is checked for existence solely to **log an error about it**, and the app is then
      bootstrapped without ever reading it. A shim there would be inert, would log an error on every app
      load, and would leave the 500 in place while appearing to fix it. This task's own option list was
      written from the pre-34 idiom, and it is the reason the defect survived as long as it did: the
      remedy reads as correct and does nothing.

      **Option (b), `config.vendor-dir`, is the real convention and was not taken.** Every NC 34 core app
      ships `composer/autoload.php` — 21 of them, including `apps/files/composer/` with
      `"config": {"vendor-dir": "."}` and Composer's project living inside `composer/`. So (b) is
      correct. It was rejected on scope, not on merit: it relocates 105 locked packages and rewrites
      `composer.json`, `tests/bootstrap.php:5`, `scripts/dev/composer`, `scripts/dev/phpunit`,
      `phpunit.xml`, `psalm.xml:2,6`, `eslint.config.js:8` and
      `tests/Integration/Persistence/roundtrip-runner.php:60`. `docs/development/composer-ownership-migration.md`
      §5B had already rejected the same operation as unsafe, on a registry outage that has since cleared
      (it answers `200` again) but with the Composer download cache still holding **0** archives — so
      (b) remains a real option and is now a **smaller** one than it was, if a later pass wants the
      canonical layout.

      **What was delivered instead: option (c), a tracked `composer/autoload.php` that requires the
      existing `vendor/` tree.** `registerAutoloading()` needs one file at one path; it does not care
      which layout produced it. So `composer/autoload.php` satisfies the server's `file_exists` and hands
      back the `vendor/autoload.php` that Composer already generates, with `return require` rather than
      `require_once` so the loader is the file's value the way a generated `autoload.php` behaves.
      Nothing moved: no dependency was relocated, re-fetched or re-resolved, and `composer.json`,
      `composer.lock`, `tests/bootstrap.php`, `scripts/dev/*`, `phpunit.xml`, `psalm.xml` and
      `eslint.config.js` are untouched. `composer/` is not gitignored, so the shim is tracked — which
      matters, because `vendor/` is ignored and the deployed app is exactly the case where nothing
      required it by hand.

      **Verified against the deployed app, which is the only place the defect was visible.** Authenticated
      `POST /ocs/v2.php/apps/pettycash/api/v1/exports` as the test user, before and after:

      | Request | Before | After |
      |---|---|---|
      | `resource=sheets`, `fileFormat=csv` | `200`, 172 bytes | `200`, 172 bytes (unchanged) |
      | `resource=sheets`, `fileFormat=xlsx` | **`500` `UNEXPECTED_FAILURE`** | **`200`**, 6286 bytes |

      `nextcloud.log` carried the exact exception before the change and carries none after:
      `Class "PhpOffice\PhpSpreadsheet\Spreadsheet" not found at
      lib/Application/Reporting/ExportService.php:268 → XlsxExporter::build`. The artifact was then read
      back rather than trusted from its status code: base64-decoded, `zipfile.testzip()` clean, `PK\x03\x04`
      magic, and the eleven expected OOXML members present including `xl/worksheets/sheet1.xml` — a
      genuine workbook, not an empty one.

      **The required check now exists, and it is deliberately not a contract test.** The task's own
      reasoning holds and is worth keeping: *"a contract test would not catch it either, because the CLI
      loads the autoloader the test bootstrap asks for, and that is the whole reason this survived a
      green suite."* `DeployedAutoloadReachabilityTest` therefore asserts against the
      `ClassLoader` that `composer/autoload.php` **returns**, using `findFile()` — which consults only
      that loader's own classmap and PSR-4 prefixes and never falls through to the ambient autoloader.
      `class_exists()` is exactly the assertion that passed for the whole life of this defect.

      It asserts three things: the entry point exists at the path `registerAutoloading()` probes; it is
      not a Composer-generated file (the marker `@generated by Composer` is absent — its presence means
      someone set `vendor-dir` to `composer/` and Composer overwrote the shim, the one collision the
      shim's own comment warns about); and every third-party class `lib/` imports resolves through it.
      `OC\`, `OCP\`, `OCA\` and `Psr\` are excluded with reasons, since those come from the server and the
      PSR-4 fallback. A fourth test is the non-vacuity self-check the other two architecture tests carry,
      and it asserts `PhpOffice\PhpSpreadsheet\Spreadsheet` specifically, so a change to the exclusion
      list or the pattern fails loudly instead of shrinking the set to nothing.

      **Mutation-verified in the direction that matters: with `composer/autoload.php` moved aside, the
      file is RED** — `testTheDeployedAutoloadEntryPointExists` fails naming the defect, and the
      reachability test errors on the missing require. It is not a test that passes either way.

      **One adjacent false statement removed, because it is the sentence that caused the miss.**
      `tests/Integration/Persistence/roundtrip-runner.php:55` asserted that *"Nextcloud core loads
      `<app>/vendor/autoload.php` for every enabled app (OC_App::registerAutoloading), which is why the
      app can use PhpSpreadsheet under Apache."* That is the same false premise as option (a), in a
      comment that would have led the next reader to the same wrong conclusion, and it inverted the real
      asymmetry: the runner and PHPUnit both load the autoloader **by hand**, while a real request does
      not. Corrected in place.

      **Suites.** `tests/Unit/Architecture/` 11/11; `--testsuite unit` 649/649 (4 pre-existing PHPUnit
      deprecations); `ReportAndExportControllerTest` 62/62; full `scripts/dev/phpunit` **1241 tests /
      6433 assertions / 1 failure**. The one failure is `PurchaserFlowControllerTest::
      testGetEvidenceContentReturns200WithStream` (`application/octet-stream` vs `application/pdf`), T318's
      known conflict, named as pre-existing in `docs/reports/T333.md`. Re-confirmed rather than assumed by
      moving the shim aside and running that test alone: identical failure, so the count did not move —
      1 before, 1 after.

      **Not done, and recorded rather than left implied.** T346 (the `X-PettyCash-Command-ID` replay
      path) was blocked on this task because its contract test could not arrange a scenario against an
      endpoint answering 500; that endpoint now answers, so T346 is unblocked and untouched. T366/T367
      (Phase 58) gain nothing here — their acceptance criteria read the produced artifact back over
      HTTP, which they can now actually do, but their defects are absences of callers and projections and
      this task supplied neither. The full NC-app `composer/` layout remains option (b), deliberately
      un-taken and now cheaper to revisit.
      Cross-references: T109, T113, T001–T010, T328, T341, T346, T366, T367.

- [ ] T346 [LOW] Either run `POST /exports` through the command-id replay path or record that exports
      are outside FR-084, because **the endpoint requires `X-PettyCash-Command-ID` and never reads it
      for anything** (FR-084, FR-066, `lib/Controller/ExportController.php`) (`contradicts`)

      **Measured.** `ExportController::create()` reads the header at `:90` and uses it twice: to report
      `fieldErrors.commandId` when it is absent, and to stamp the refusal's `commandId`. There is no
      `IdempotencyService` in the class. So a retried export under the same command id regenerates the
      artifact instead of answering the stored result, which is what every other mutating endpoint
      does.

      **How it was found.** T334's `Conflict` case was first written against this endpoint, on the
      reasoning that a replay under a changed payload is the deterministic way to produce a 409. It
      answered `500` — T345's missing class — and, once that was explained, the replay case could not
      be arranged here at all. That is the evidence for the finding: the endpoint cannot produce the
      conflict FR-084 describes.

      **Whether this is a gap or an intentional exclusion is not this task's call and should not be
      made silently.** An export is a read that produces a file, so a replay is harmless in effect
      while still costing a regeneration; but the contract marks the header `required` on every
      mutating operation, and refusing the header without honouring it is the worse of the two
      readings — it advertises a guarantee that is not there. Decide, then either implement the replay
      or amend the contract to say the header is required for correlation only.
      Cross-references: T027, T166, T317, T318, T084, T328.

**What this pair says about the pass that found them.** Both were one HTTP request away from a task
whose subject was a different file, and both are invisible to the suite for the same reason Phase 55
recorded: the test bootstrap loads a dependency graph the deployed app does not have. The generalised
lesson extends Phase 55's — the correspondences worth enumerating are not only contract ↔ code, but
also *how the code is loaded*, which no suite in this repository asserts at all.

## Phase 57: Convergence (the correction route, two dead evidence controls, and an OCS navigation that cannot authenticate)

Found by the 2026-10-05 convergence pass. F1, F3 and F4 were reproduced rather than inferred: F1 against the
installed `vue-router@4.6.3`, F3 and F4 as live HTTP requests against the running container. F5 was settled
from the `info.xsd` Nextcloud itself serves. None of the four is visible to a green suite, for the reason
Phase 55 recorded and this phase re-confirms: the frontend specs stub the HTTP layer and assert that a
control *exists*, never that pressing it reaches the server, and the contract client sends
`OCS-APIRequest: true` on every request so it can never observe F3.

- [x] T359 [CRITICAL] Register the correction route the correction button already navigates to, because
      **the purchaser cannot start a correction at all** — pressing the only affordance throws

      ---

      **Delivered by pointing the push at `transaction-edit`, not by adding a route — and the second
      option in this task's own acceptance criteria is recorded as a deliberate rejection rather than
      as an oversight.** `TransactionFormView`'s `editable` computed (`:71`) already names
      `AWAITING_PURCHASER_CORRECTION` explicitly, which is the only state in which this button renders
      (`canCreateCorrection` reads that same status), and its `persist()` (`:181-192`) calls
      `updateExistingTransaction` — whose `PATCH` the server **already admits for exactly this state**:
      `TransactionService.php:245` gates on `$isCorrection`, which it derives from
      `TransactionStatus::AwaitingPurchaserCorrection`, and the comment above it (`:230-244`) records
      that this was a deliberate widening of FR-026's single carve-out. That PATCH builds
      `$transaction->revision()->number() + 1` (`:259-260`), so **FR-048's new revision is created on
      this path too.** Registering `transaction-correction-create` would have produced a second URL for
      one behaviour with nothing to tell them apart, and `TransactionFormView` would then need to know
      which of the two names it had been entered under.

      **The root cause was the copied route table, not the missing route, so the copies are gone.**
      Three spec files each carried their **own** re-declaration of the application's routes:
      `PurchaserFlow.spec.ts` (ten routes), `NextcloudVueContract.spec.ts` (ten), and
      `ReportingExport.spec.ts` (one synthetic `dashboard`). All three now derive the table from
      `@/router` with the components replaced by inert placeholders — the pattern
      `FinanceReview.spec.ts` and `ManagementReview.spec.ts` already used, and which
      `TransactionCorrection.spec.ts` already imported outright. **A copied table makes every route
      name resolvable whether or not the application declares it**, which is precisely why this
      shipped: no test *could* have failed on the missing name. `NextcloudVueContract.spec.ts` carried
      a comment describing exactly this hazard — "a route table missing either one fails the mount with
      a `No match for`" — and then kept the copy. Six spec files now share one source of route names;
      **zero** re-declare them.

      **The assertion whose absence let it ship, added: a test that clicks the button.** Five existing
      assertions checked the button *exists* (`:1320,1327,1330,1657,1661`); none pressed it. That is
      not a weaker version of the missing test, it is a **different question** — a `router.push` that
      throws synchronously produces no DOM change and no rejection, so an existence assertion sees a
      healthy page. `should navigate to an editable form when the correction button is pressed` mounts
      a returned transaction, triggers the click, and asserts `router.currentRoute.value.name` is
      `transaction-edit` with the transaction id as the `:id` param. It needs a new
      `createWrapperWithRouter()` helper (`:381-401`) because `createWrapperAtRoute` returns only the
      wrapper, which is the right shape for "does this render" and the wrong one for "does this
      control work".

      **And the check T326 asked for in the other direction, which the copies alone would not have
      covered: `tests/Frontend/routeNameIntegrity.spec.ts`** (new, 3 tests) scans every `.vue`/`.ts`
      under `src/` for a literally-written `name: '…'` and asserts it exists in the real table. The
      derived test routers catch a bad name **in a view that has a component test**; this catches one
      in a view that has none, where no mount happens and nothing else in the suite would look. Its
      two companion tests are the usual guards: `declares a non-empty route table` (so the comparison
      cannot pass by comparing nothing) and `finds names in the source it claims to scan` (so a
      refactor to a different call shape cannot silently stop the scan). **This is the fourth such
      self-check in this repository**, after `LayerBoundaryTest`,
      `RouteDispatchCompletenessTest::testTheScanActuallyFindsControllerActions` and
      `RouteDispatchCompletenessTest::testTheScanActuallyFindsClientPaths`.

      **Stated limitation, not hidden:** template-literal names are not matched, because
      `{ name: \`review-queue-${stage}\` }` — how the review queues are addressed — is not knowable
      without rendering. Those queue links are covered by the derived test routers instead, so the gap
      is narrow and bounded rather than open.

      **Mutation-verified twice, against the two different checks, because they fail for different
      reasons and only one passing would have been evidence of nothing.** Reverting the push to
      `transaction-correction-create` fails the **click test** with vue-router's own
      `Error: No match for` — the exact error this task's report recorded — and fails the **scan**
      with `Unknown: transaction-correction-create (src/views/transactions/TransactionDetailView.vue)`.
      Each mutation was verified by restoring the file from an exact copy afterwards.

      **Results.** `PurchaserFlow.spec.ts` **91/91** (was 90) · `npm run test` **300/300** (was 296:
      +1 click test, +3 scan tests) · `npm run typecheck` clean · `npx eslint` **0 errors** on the four
      changed/added frontend files (9 warnings, all pre-existing `vue/attributes-order` on untouched
      lines). **No PHP changed**, so `scripts/dev/phpunit` was not re-run for this task and its result
      stands from the session baseline (1226 tests, 1 failure — T275's, not this task's).

      **A flake worth recording, because it is not this task's regression and was not absorbed silently.**
      One full-suite run failed `NextcloudVueContract.spec.ts > renders the sheet action bar` at exactly
      **30022 ms** — vitest's 30 s per-test timeout. That test ran **13948 ms** on the session baseline
      and **14623 ms** in isolation after this change, so it was already consuming ~48% of its budget
      before the change; under ten parallel files it tipped over. Three consecutive full runs then
      passed 297/297, followed by 300/300. **The honest reading is that the test is slow for reasons
      unrelated to this task, and that a 30 s per-test timeout leaves it too little margin** — raising
      it, or finding why a real-`@nextcloud/vue` sheet-detail render costs ~14 s, is real work that
      belongs to whoever owns that file's performance, and it is **not** silently absorbed into a
      CRITICAL fix that is about a route name.
      Cross-references: T075 (correction form, open), T117 (route table, open), T203, T326.

      ---

      **Original task text, preserved:**

      **the purchaser cannot start a correction at all** — pressing the only affordance throws
      (US4/AC2, FR-026, `src/router/index.ts`, `src/views/transactions/TransactionDetailView.vue`)
      (`contradicts`)

      **`src/views/transactions/TransactionDetailView.vue:62`:**

      ```ts
      function goToCreateCorrection() { router.push({ name: 'transaction-correction-create', params: { id: transactionId.value } }) }
      ```

      **No route of that name exists.** `src/router/index.ts:43-73` declares `transaction-create`,
      `transaction-detail` and `transaction-edit`; a grep for `correction` across the whole route table
      returns nothing. Reproduced against the repository's own `vue-router@4.6.3` with a three-route table
      copied from `src/router/index.ts`:

      ```
      THREW: Error | No match for
       {"name":"transaction-correction-create","params":{"id":"tx-1"}}
      ```

      That is `router.resolve()`'s `MATCHER_NOT_FOUND` throw (`node_modules/vue-router/dist/vue-router.mjs`,
      `if (!matcher) throw createRouterError(ErrorTypes.MATCHER_NOT_FOUND, ...)`) — **synchronous, not a
      rejected navigation**, so the `@click` handler aborts and nothing is caught.

      **Why every layer stayed green.** The button is asserted to *exist* and to be correctly scoped, four
      times over, and never pressed: `tests/Frontend/Component/PurchaserFlow.spec.ts:1320,1327,1330,1657,1661`
      are all `expect(...find('[data-test="create-correction-btn"]').exists()).toBe(...)`. The test router in
      that file (`:308-320`) declares the same ten routes the application does, so it could not have caught
      the missing name even if a test had pushed it.

      **What this blocks.** US4/AC1 ("the transaction appears as awaiting purchaser correction") is reachable —
      `isReturned` reads the status — but US4/AC2 ("the purchaser changes its business information and
      resubmits it") has no entry point, and FR-026's single carve-out for editing a submitted transaction is
      the route this button is trying to take. P1 user story, baseline functionality: **CRITICAL**.

      **Acceptance criteria.** Either register a `transaction-correction-create` route (the form already
      handles `AWAITING_PURCHASER_CORRECTION` — `TransactionFormView.vue:70` keeps such a transaction
      `editable`), or change the push to the existing `transaction-edit` route, which is what the
      `editable` computation was written for. Add a component test that **clicks** the button and asserts the
      resolved route name, so the assertion is about navigation rather than about markup. Add the check T326
      asks for in the other direction: a `router.push({ name })` naming a route the table does not declare is
      a build-time failure. Cross-references: T075 (correction form, open), T117 (route table, open), T203.

- [x] T360 [CRITICAL] Wire the transaction detail page's evidence download and removal buttons, because
      **both render and do nothing**, and the working implementation already exists one directory away

      ---

      **Delivered by deleting the view's inline copy and rendering `EvidenceList.vue` — the first of
      this task's two options, chosen because the component is not merely complete but is *more*
      correct than the markup it replaces, and adopting it fixes three requirement clauses the inline
      version got wrong.**

      | Clause | The inline markup | `EvidenceList.vue` |
      |---|---|---|
      | **FR-060** download | `v-if="ev.active && !isSubmitted"` — a **reviewer could not download a submitted transaction's evidence at all** | offered whenever `ev.active` |
      | **FR-058** removal | `v-if="ev.active && !isSubmitted"` | `v-if="ev.active && props.isDraft"` — same rule, stated as the question it answers |
      | **FR-059** history | row said only "inactive" | renders `deactivation-info`: when withdrawn, or that the time is unknown |

      It also carries a `confirm()` before removal, a `deleting` guard so a double press cannot issue
      two DELETEs, a `role="alert"` error region, and `aria-label`s naming the file each button acts
      on. **The FR-060 row is the substantive one:** a cost-centre manager reviewing a submitted
      transaction — the entire purpose of the review stage — was shown no way to open the receipt they
      were being asked to approve. Wiring the two handlers instead would have preserved that.

      **`isDraft` is passed as `!isSubmitted`, and the inversion is load-bearing.** The component uses
      the prop to decide whether *removal* is offered; `isSubmitted` is also what gates the upload
      button in the card header. Passing the raw `isSubmitted` would have offered removal on a
      submitted transaction. Recorded in the template because the two names invite the mistake and
      nothing at the call site would fail loudly.

      **The dead CSS went with the markup.** Eight `.evidence-*` rules styled only the deleted rows.
      They were **not** ported: `EvidenceList.vue` carries its own `scoped` presentation, and keeping a
      second copy would be a second definition of how a row looks that nothing renders. `.evidence-list`
      went as well — same class name as the component's root element, which `scoped` styles could not
      have reached anyway. `grep -rn "TODO: Implement" src/` is now **0** in code; the one remaining
      occurrence is the docblock recording what was removed and why.

      **Two tests that press the buttons, through the view rather than the component.** This is the
      part that matters, and the distinction is not pedantry: five existing evidence assertions in this
      file mount `EvidenceList` **directly**, which is exactly how the tested surface and the shipped
      surface came to be two different files. `grep -rn "download-evidence\|remove-evidence"
      tests/Frontend/` returned nothing before this change, so nothing ever pressed the inert controls.
      - `should issue the content request when the evidence download button is pressed` — spies
        `evidenceApi.getEvidenceContent`, clicks through `TransactionDetailView`, asserts it was called
        with the file's public id. **`URL.createObjectURL`/`revokeObjectURL` are stubbed rather than
        asserted**, and the reason is that jsdom implements neither: asserting them would pin the
        blob-handoff mechanism rather than the requirement.
      - `should issue the delete request when evidence removal is confirmed, and only before
        submission` — asserts **three** things, and each is a different failure mode: the request is
        issued; a **declined** `confirm()` issues nothing (the guard is why this is worth more than the
        happy path — and jsdom's `confirm` returns `false`, so without the stub the removal path would
        be untestable *and* the test would pass for the wrong reason); and a submitted transaction
        offers **no** removal control **while still offering download** — the FR-058/FR-060 asymmetry
        asserted as one pair.

      **The check T360 asked for — "a component no view imports cannot be the one the tests certify" —
      is `component reachability` in `tests/Frontend/routeNameIntegrity.spec.ts`, and writing it found
      something on its first run.** `EvidenceCard.vue` was reported as an orphan; it is not, because
      `src/components/review/EvidenceList.vue:15,26` renders it. **So the check was wrong and was
      corrected, not the finding suppressed** — reachability is a breadth-first walk from every view
      following component → component edges, because a card rendered by a list that a view renders *is*
      rendered. Stopping at one hop would have produced a false positive and, worse, an incentive to
      inline a component rather than compose one. It resolves both import spellings the codebase uses
      (`@/components/…` and `./X.vue`) and tests the resolved path for containment, so a same-named
      file outside `src/components` cannot pass for a component edge.

      **Mutation-verified three times, and one attempt did not fail, which is the useful part.**
      Reintroducing the two `// TODO: Implement` handlers and the inline markup fails **both** press
      tests (download: `expected false to be true` — the control is gone; delete: the same). Removing
      only `TransactionReviewDetailView`'s `EvidenceList` import **did not fail the reachability
      check**, and that was correct rather than a hole: `FinanceReviewView.vue:122` also renders it.
      Removing **both** fails with `Unreachable from every view: review/EvidenceCard.vue,
      review/EvidenceList.vue` — naming both the component and its own child, which is the transitive
      edge holding. Every mutation restored from an exact copy; `git diff --stat src/views/reviews/`
      is empty afterwards.

      **Results.** `PurchaserFlow.spec.ts` **93/93** (was 91) · `npm run test` **304/304** (was 300:
      +2 press tests, +2 reachability tests) · `npm run typecheck` clean · `npm run stylelint` clean ·
      `npx eslint src/ tests/Frontend` **0 errors** (161 warnings, all pre-existing across the tree;
      the 7 on the changed file are `vue/attributes-order` on untouched lines). **No PHP changed**, so
      `scripts/dev/phpunit` was not re-run and its result stands from the session baseline (1226
      tests, 1 failure — T275's).

      **What this task does not close, and why it is not absorbed here.** `getEvidenceContent` reaches
      `GET /evidence/{id}/content` through the OCS client, and that endpoint's **authorization** is
      T362's subject (it currently admits by uploader rather than by transaction visibility) while the
      **`OCS-APIRequest` header** on the fetch is T361's. This task made the buttons issue requests; it
      did not make the server's answer correct. Widening a CRITICAL fix to settle both would be how the
      task stops being reviewable.
      Cross-references: T361, T362, T094, T170, T342, T359.

      ---

      **Original task text, preserved:**

      **both render and do nothing**, and the working implementation already exists one directory away
      (FR-058, FR-060, US1, `src/views/transactions/TransactionDetailView.vue`) (`contradicts`)

      **`src/views/transactions/TransactionDetailView.vue:110-116`:**

      ```ts
      async function downloadEvidence(_evidenceId: string) {
          // TODO: Implement download
      }

      async function removeEvidence(_evidenceId: string) {
          // TODO: Implement remove
      }
      ```

      **Two empty functions, and `:363` / `:366` bind them to `data-test="download-evidence"` and
      `data-test="remove-evidence"`** whenever `ev.active && !isSubmitted`. So every purchaser with a draft
      carrying a receipt is shown a download button and a delete button that do absolutely nothing, with no
      error and no state change. These are the only `TODO`s left anywhere in `src/` or `lib/`.

      **The working code is not missing — it is unused.** `src/components/evidence/EvidenceList.vue` renders
      the same list with the same affordances and calls `getEvidenceContent(ev.id)` (`:44`) and
      `deleteEvidence(ev.id)` (`:64`), with a confirm, a `deleting` guard and an error region.
      `grep -rn "from '@/components/evidence" src/` returns **only** `EvidenceUploader` — the list component
      is imported by `tests/Frontend/Component/PurchaserFlow.spec.ts:287` and by **no view**. The
      review-side `src/components/review/EvidenceList.vue` is a different component that only renders cards.

      **Why the suite is green.** `PurchaserFlow.spec.ts:1483-1505` asserts `download-evidence-btn` and
      `delete-evidence-btn` against the *unused* component, so the tested surface and the shipped surface are
      different files. Nothing presses `download-evidence` or `remove-evidence` — grep for those selectors in
      `tests/Frontend/` returns nothing.

      **FR-058 and FR-060 are both P1-usable behaviour with a button on screen that is inert.**
      **Acceptance criteria.** Delete the inline list and render `src/components/evidence/EvidenceList.vue`,
      or wire the two handlers to the two service functions. Either way, assert that **pressing** the button
      issues the request (`getEvidenceContent` / `DELETE /evidence/{id}`), and add the check that a component
      no view imports cannot be the one the tests certify. Cross-references: T094 (evidence query service,
      open), T170, T342, Phase 55's generalisation note.

- [x] T361 [HIGH] Fetch evidence content with the `OCS-APIRequest` header instead of navigating to it, because
      **evidence preview and download in both review views answer `412 CSRF check failed` for every user**

      ---

      **Delivered: `EvidenceCard.vue` now fetches the bytes through `getEvidenceContent()` and hands
      the browser a `Blob` URL, for all three of its former navigation paths.** `generateOcsUrl` is no
      longer imported by the file. This is the second option in this task's acceptance criteria, and it
      is the one the purchaser's `EvidenceList.vue` already used — which is the reason half the
      application worked and half did not.

      | Former | Now |
      |---|---|
      | `<img :src="generateOcsUrl(…content)">` (eager) | `fetch` → `Blob` → object URL, `v-if` on the URL being **present** |
      | `preview()` → `window.open(url, '_blank')` | `window.open()` on a **`blob:`** URL, tab opened synchronously |
      | `download()` → `window.location.href = url?disposition=attachment` | `fetch` → `Blob` → `<a download>` → click → revoke |

      **The inline `<img>` stayed eager, and that is a deliberate answer to "does this add a request?**
      It does not. **The browser was already issuing exactly one request per image** for
      `<img :src="…">` — it was simply receiving `412`. Fetching the blob instead is the same single
      request, now one that succeeds, so FR-060's inline preview is preserved without adding traffic.

      **Two states the old markup could not express, both now visible.** A card shows
      `data-testid="preview-loading"` until the bytes arrive, and a failed load renders a
      `role="alert"` `data-testid="evidence-error"` naming the reason. **Without the error state a failed
      preview looks exactly like a blank receipt**, and FR-060 makes preview something a reviewer
      relies on to approve an expense — "this receipt is empty" and "we could not load this receipt" are
      different facts and only one of them is true.

      **`preview()` opens its tab *before* awaiting, and the comment says why.** Awaiting first loses
      the user-gesture context, popup blockers then refuse `window.open`, and the preview becomes a
      silent no-op — the exact failure mode this task exists to remove, reappearing one layer down. If
      the fetch fails, the empty tab is closed rather than left blank. `disposition` moved from a query
      parameter written into a navigation to an **argument of the request**, which is why the download
      test asserts `disposition=attachment` on the URL: losing it would silently turn every download
      into a preview.

      **Object URLs are revoked** in `onBeforeUnmount` and after each download, because the card sits
      inside a `v-for` and an object URL is a leak until revoked.

      **One existing test changed shape, and the reason is the fix rather than a weakened assertion.**
      `should render an inline preview for image evidence and mark deactivated evidence` asserted
      `evidence-preview` existed synchronously — true only of the `<img :src>` form, whose image never
      once displayed. It is now split into four tests: the fetch carries `OCS-APIRequest` and the
      `src` is the object URL; a failed preview renders the alert; a pressed download carries the header
      **and** `disposition=attachment`; and deactivated evidence is marked without a preview affordance.
      **The requirement is asserted more strictly than before, not less** — the old test could not have
      distinguished a working preview from a `412`.

      **The check T361 asked for is `never reaches an OCS URL by navigation`, and its first version was
      wrong in a way worth recording.** It banned *every* navigation sink, and failed on T361's own
      fix — because `window.open()` with a **`blob:`** URL is precisely how preview works once the bytes
      are in hand. A blanket ban would have forbidden the correct behaviour. **The corrected invariant is
      the one that actually distinguishes them: `generateOcsUrl` exists to hand a URL to `fetch`/axios,
      and using it in a navigation sink is always wrong**; a second clause catches a literal `/ocs/`
      path. Its self-check asserts the sink scan found at least one sink, so a scan matching nothing
      cannot pass. Docblock prose and commented-out code are skipped, because this very file's comment
      quotes the old `window.open(generateOcsUrl(…))` and a check that flagged its own explanation
      would be unusable.

      **Stated limitation, not hidden:** the check matches navigation *sinks*, not every way to issue a
      request. A component that built an OCS URL and used it as an `<img src>` or an `<a href>` would
      **not** be caught — and T361's inline `<img>` was exactly that. It is removed because the fix
      removes it, not because this check would have found it.

      **Mutation-verified three ways.** Dropping `OCS-APIRequest` from `getEvidenceContent` fails **2**
      (the preview and download tests, on the header itself). Reverting the card to navigation form —
      `window.open(generateOcsUrl(...))` for preview and `window.location.href` for download — fails
      **3**. Reintroducing only the `generateOcsUrl` import and one navigation fails the new check with
      `OCS URL reached by navigation: src/components/review/EvidenceCard.vue:188, :192, :199`. All
      mutations restored from exact copies; `git diff --stat` on `src/services/evidenceApi.ts` is empty,
      confirming that file was left untouched.

      **Results.** `ManagementReview.spec.ts` **36/36** (was 33) · `npm run test` **308/308** (was 304:
      +3 net evidence tests, +1 navigation check) · `npm run typecheck` clean · `npm run stylelint` clean
      · `npx eslint src/ tests/Frontend` **0 errors** (162 warnings, all pre-existing) ·
      `node scripts/portability-check.js` **OK**. **No PHP changed**, so `scripts/dev/phpunit` was not
      re-run and stands at the session baseline (1226 tests, 1 failure — T275's).

      **Not closed here, and named rather than absorbed.** This endpoint's **authorization** is T362's
      subject — `EvidenceController::content()` still admits by uploader rather than by transaction
      visibility, which is a server-side hole and the more serious of the two. This task made the
      request well-formed; T362 makes it correctly addressed. They touch one endpoint and are worth
      sequencing adjacently.
      Cross-references: T362, T360, T094, T091, T328, T329, T342.

      ---

      **Original task text, preserved:**

      **evidence preview and download in both review views answer `412 CSRF check failed` for every user**
      (FR-060, US2/AC1, US3/AC1, `src/components/review/EvidenceCard.vue`) (`contradicts`)

      **`EvidenceCard.vue:89` builds a URL and `:110`/`:114` navigate to it:**

      ```ts
      const previewUrl = computed(() => generateOcsUrl('apps/pettycash/api/v1/evidence/{id}/content', { id: props.evidence.id }))
      function preview(): void { window.open(previewUrl.value, '_blank') }
      function download(): void { window.location.href = `${previewUrl.value}?disposition=attachment` }
      ```

      **A browser navigation cannot set a request header**, and this OCS route requires one:
      `SecurityMiddleware::isValidOCSRequest()` (`lib/private/AppFramework/Middleware/Security/SecurityMiddleware.php:239-242`)
      accepts only `OCS-APIREQUEST: true` or a `Bearer` `Authorization`, and `Request::passesCSRFCheck()`
      (`:453`) treats that same header as the CSRF bypass. `EvidenceController::content()` carries
      `#[NoAdminRequired]` and **no `#[NoCSRFRequired]`**, so the check applies.

      **Measured in the running container**, as the contract test's own user, against the live route:

      ```
      GET /evidence/{id}/content   with OCS-APIRequest: true  -> 404   (routing/auth reached)
      GET /evidence/{id}/content   without the header         -> 412 {"message":"CSRF check failed"}
      ```

      The `404` is the fabricated uuid not existing; the point is that the header is the difference between
      reaching the action and being stopped in the middleware. The inline `<img :src="previewUrl">` at `:6` is
      the same request by a different mechanism and fails identically.

      **This is T328/T329's exact failure class, in the one component Phase 55's enumeration did not reach.**
      `EvidenceCard` is the review surface: `TransactionReviewDetailView.vue:54` and `FinanceReviewView.vue:84`
      both render it, so FR-060 is unavailable at every point US2 and US3 ask for it.

      **Why the suite is green.** `ManagementReview.spec.ts:405-419` asserts the two buttons *exist* and are
      *named*, and never clicks them; nothing in `tests/Frontend/` references `window.open`. And the PHP side
      cannot see it either: `OcsTestClient.php:37` sets `OCS-APIRequest: true` on every request.

      **Acceptance criteria.** Use `getEvidenceContent()` from `src/services/evidenceApi.ts` (it already sends
      the header at `:49`) and hand the browser a `Blob` URL, as `src/components/evidence/EvidenceList.vue:44-52`
      already does for the purchaser side. Add a component test that clicks download and asserts the request
      carried `OCS-APIRequest`. Consider promoting T326's route-dispatch check to cover this shape too: a
      client URL reached by navigation rather than by request is the same class of defect. Cross-references:
      T094, T091, T328, T329, T342, T360.

- [x] T362 [HIGH] Authorize `GET /evidence/{id}/content` by transaction visibility rather than by uploader
      identity, because **no reviewer can ever open a receipt, and the endpoint answers `404` to hide it**

      ---

      **Delivered: the rule now reads transaction visibility, and it lives in a new
      `Application\Evidence\EvidenceVisibility` rather than in the controller.** Extracted for the reason
      T327 extracted `SheetCapabilities`: the rule is a boolean over (ownership, role, scope, the
      transaction's stage, its cost centre), and a private method on the controller cannot be asserted as
      a matrix because the PHPUnit process has no `IDBConnection`.

      **An actor may read a transaction's evidence when they own it, administer the instance, or may
      `canReview()` it at its current stage.** The third clause is the same call the decision endpoints
      make, so "may I see this receipt" and "may I decide on this transaction" cannot drift apart.
      `404` is **kept** for an out-of-scope caller — the endpoint must not confirm the existence of a
      record the caller may not see (FR-003), the same treatment `AccountController` gives a foreign
      ledger. **What changed is which question is asked, not the shape of the answer.**

      **The obvious shortcut was rejected, and the tests are what prove it was a shortcut.**
      `AuthorizationService::canView()` exists, is public, and answers exactly the question asked here —
      and using it would have fixed every reviewer's 404 while **disclosing every transaction's evidence
      to every purchaser holding the `PURCHASER` role**, because its third clause is `$context->roles !==
      []`: *has any role at all*, and every authenticated user is at least a purchaser. That trades an
      availability bug for a confidentiality bug. `EvidenceVisibility` therefore does not call it, and
      `canView()` — which **has no production caller** — now carries a docblock warning that it must not
      be used for anything scoped. Its behaviour is deliberately unchanged: narrowing it would alter a
      method only its own tests exercise, and that is a separate reviewable decision about what "any role
      may see" was ever meant to mean.

      **Two design points worth recording.** (a) The actor's roles and managed cost-centre codes are
      resolved **inside** `EvidenceVisibility`, not passed in — `ManagementReviewService` and
      `FinanceSettlementService` each carry a private copy of that resolution, and a third copy in a
      controller would be a third definition to keep in step, in a rule where divergence is a disclosure
      bug. (b) The scope is built from the **actor's own assignments, never from the record**;
      `canReview()` asks whether the subject's centre is in that list, so a subject-derived list makes the
      comparison permanently true. That trap is called out in the same words
      `ManagementReviewService:126-131` uses, and one of the two mutations below is exactly it.

      **An unresolvable transaction is a `422`, not a `404`.** An evidence row whose transaction does not
      resolve is a stored-data fault: the caller may well be entitled to read it, and answering `404`
      would tell a legitimate reader the file had vanished — the Phase 33 "header, no data" shape. FR-083
      requires an unreadable stored record to be reported as a data fault rather than papered over.

      **Twelve tests / 45 assertions over the whole matrix**, in `tests/Unit/Evidence/EvidenceVisibilityTest.php`.
      A matrix rather than endpoint tests because the OCS suite holds **one** credential and so cannot
      express a second identity — the same harness limit T291 records for a different rule, and the reason
      this defect was invisible to it. The cells: the owner at all five stages; an in-scope cost-centre
      manager; an **out-of-scope** one; an in-scope senior manager and an out-of-scope one; finance with
  no cost-centre scope on a transaction in a centre they do not manage (FR-016's shared queue);
      an administrator (FR-007 visibility — **and `canReview()` still refuses them a decision**, so this
      grants no authority); **the leak case, a purchaser with no review role**; a manager whose scope
      matches but whose stage has moved on; a transaction with no cost centre; and a settled transaction
      staying readable as history (FR-059). One test asserts the method signature is
      `('actorUid', 'transaction')` — **a structural property**, because no caller-supplied stage is what
      makes it impossible to pass the stage you hold and read a transaction at any other.

      **The repository's own in-memory doubles are used, not anonymous port implementations.** A first
      draft declared `new class implements RoleAssignmentRepository`, which PHP rejected for four
      unimplemented methods — and would have needed restating on every interface change, which is how a
      stub drifts from the adapter it stands in for. `InMemoryRoleAssignmentRepository`'s own docblock
      records an instance of exactly that drift. The cost-centre codes are **read back from the double**
      rather than assumed, because an assumption would make "in scope" and "out of scope" the same centre
      and turn every scope test green for the wrong reason.

      **One test failed on first run and the fixture was wrong, not the rule.**
      `testASeniorManagerOutOfScopeMayNotReadEvidence` reused the *in-scope* senior manager and asserted
      a refusal. The honest repair was a second, genuinely out-of-scope senior manager plus an assertion
      that the in-scope one **is** admitted — so "fails for the scope reason, not the role reason" becomes
      a claim rather than a hope. Recorded because a test that failed for a reason the author had not
  considered is the moment a lazy repair (delete the assertion) would have looked identical.

      **Mutation-verified two ways, and the first is the one that matters.** Rebuilding the rule on
      `canView()` fails **5 of 12** — starting with the leak case, then both out-of-scope managers, the
      moved-on-stage case, and the no-cost-centre case. That is the shape of the shortcut this task had
      to avoid. Deriving the actor's scope from the **subject** instead of from their assignments fails
  **3 of 12** — precisely the two out-of-scope managers and the no-cost-centre case. Both mutations
      restored from exact copies.

      **Results.** `EvidenceVisibilityTest` **12/12, 45 assertions** · `scripts/dev/phpunit`
      **1238 tests / 6418 assertions / 1 failure** — baseline was 1226/6369/1, so **+12 tests, +49
      assertions and no new failure**; the single failure remains T275's `application/octet-stream`
      conflict, which is unrelated and already owned. `npm run test` **308/308** (frontend untouched) ·
      `node scripts/portability-check.js` **OK** · `php -l` clean on all four changed PHP files.

      **What is deliberately not claimed.** The contract suite still cannot test this endpoint with two
      identities, so this task's acceptance criterion asking for "a contract test with **two**
 authenticated users" is **not met** and is not pretended otherwise: the harness has one credential,
 and adding a second is T291's named blocker. What exists is the full rule matrix at unit level plus the
      existing single-identity endpoint coverage, which proves the owner path still answers `200`
      (`PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream`). The multi-identity
      harness remains the honest gap.
      Cross-references: T361, T091, T094, T013, T026, T291.

      ---

      **Original task text, preserved:**

      **no reviewer can ever open a receipt, and the endpoint answers `404` to hide it**
      (FR-060, US2/AC1, Constitution IV) (`contradicts`)

      **`lib/Controller/EvidenceController.php:320-324`:**

      ```php
      $ownerUid = $this->currentUser->uid();
      $evidence = $this->evidenceRepository->findByPublicId($evidenceId);

      if ($evidence === null || $evidence->uploadedBy !== $ownerUid) {
          return $this->ocsErrorMapper->notFound('Evidence not found');
      }
      ```

      **A reviewer is never the uploader.** The evidence was uploaded by the purchaser; the cost-centre
      manager, senior manager and finance officer who must rely on it are three different accounts. So every
      one of them receives `404 Evidence not found` for every receipt on every transaction they are asked to
      approve.

      **FR-060's own words are "Authorized users MUST be able to preview supported evidence"** — not "the
      uploader". US2/AC1 says the manager "sees its current details, evidence, history". Constitution IV
      requires the opposite of what this code does: "Document and export access MUST follow transaction
      visibility and MUST not expose data beyond the caller's role and responsibility scope." Transaction
      visibility is the rule named by both; uploader identity is a rule neither states.

      **`404` rather than `403` makes it worse than a refusal.** It reports the evidence as nonexistent, so a
      reviewer sees a transaction whose receipt has vanished rather than one they are not permitted to open.
      That is the same misinforming failure Phase 33 recorded for the sheet-detail audit card: an empty body
      that reads as "there is nothing here".

      **Why the suite is green.** `PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream`
      (`:1088`) uploads and reads back as the same user. `EvidenceLifecycleControllerTest` never reads content
      as a second account — grepping `uploadedBy` across `tests/Contract/Controller/` finds no such assertion.
      The account-scope tests that do exist (`AccountControllerTest:283`, `ErrorEnvelopeContractTest:237`)
      cover accounts, which is why the pattern was not applied here.

      **Acceptance criteria.** Resolve the transaction for the evidence and authorize through the same
      visibility rule `GET /transactions/{id}` and the review queues use (`AuthorizationService` /
      `AuthorizationContext`), so a manager in scope, a senior manager in scope and a finance officer can read
  it and an out-of-scope manager cannot. Add a contract test with **two** authenticated users — the uploader
      and an in-scope reviewer — asserting `200` for the second and `403`/`404` for a third who is neither.
      Cross-references: T091, T094, T013, T026, T361.

- [ ] T363 [MEDIUM] Declare the PHP floor in `appinfo/info.xml` and give the ACC-001 compliance line an
      owning task, because the register's 8.2–8.5 range is **stated in `composer.json` only** and the
      compliance review lists four sibling items with task numbers and this one without (ACC-001,
      Constitution III, `appinfo/info.xml`) (`missing`)

      **The gap.** `appinfo/info.xml` declares `<database>mysql</database>`, `<database>pgsql</database>`,
      `<database>sqlite</database>` and `<nextcloud min-version="34"/>` — and no `<php>` element. The 8.2
      floor therefore rests on `composer.json:17` (`">=8.2 <8.6"`) alone.

      **It is declarable, and the question the compliance review left open is now answered.** It recorded the
      item as "Open, tracked" with the note "whether `info.xsd` permits `<php>` there is unverified". The
      schema Nextcloud serves says it does:

      ```xml
      <xs:complexType name="dependencies">
        <xs:sequence>
          <xs:element name="php" type="php" minOccurs="0" maxOccurs="1"/>
          <xs:element name="database" .../>
          <xs:element name="nextcloud" .../>
      ...
      <xs:complexType name="php">
        <xs:simpleContent>
          <xs:extension base="empty-string">
            <xs:attribute name="min-version" type="version" use="optional"/>
            <xs:attribute name="max-version" type="version" use="optional"/>
      ```

      So `<php min-version="8.2" max-version="8.5"/>` is valid — and, because `xs:sequence` fixes the order,
      it must be inserted **before** the three `<database>` elements, exactly as the existing comment in
      `info.xml` already reasons about `<database>` preceding `<nextcloud>`.

      **Why it is a finding rather than a note.** The Constitution Compliance Review's closing paragraph
      claims five open items are "recorded as open work with named owners (T128, T122/T123, T124, T239, and the
      `info.xml` floor)". Four of the five are task numbers. The fifth is a description. So the review asserts
      an owner that does not exist, which is the same defect class the paragraph above it forbids: "An
      unrecorded exception would be invalid; none is recorded here."

      **Acceptance criteria.** Add the `<php>` element in schema order, keep `composer.json` and
      `info.xml` in agreement, and cite a task ID for the ACC-001 line so every open item in that review has
      one. Cross-references: T001, T002, T241, T239, T240, the Constitution Compliance Review section.

- [ ] T364 [MEDIUM] Give the purchaser a resubmission control, or remove the store action and say so,
      because US4/AC2's second half is **unreachable from the interface and its test cannot fail**
      (US4/AC2, FR-026, `src/stores/transactions.ts`, `tests/Frontend/Component/TransactionCorrection.spec.ts`)
      (`partial`)

      **The service and store layers are complete.** `resubmitCorrection` (`src/services/transactionsApi.ts:62`)
      posts to `/transactions/{id}/resubmit` with the required `X-PettyCash-Command-ID`, and
      `resubmitCorrectionDraft` (`src/stores/transactions.ts:181`) wraps it. **`grep -rc "resubmit-correction" src/`
      is `0`** — no view renders the control, so the only way to resubmit a corrected transaction is to call the
      store by hand. `TransactionCorrection.spec.ts:313-348` exercises it that way, which is why the store
      looks covered.

      **The test that appears to guard the UI cannot fail.** `TransactionCorrection.spec.ts:365-379`:

      ```ts
      const resubmit = wrapper.find('[data-test="resubmit-correction-btn"]')
      if (resubmit.exists()) {
          expect((resubmit.element as HTMLButtonElement).disabled).toBe(true)
      }
      ```

      Since no such selector exists anywhere in `src/`, `exists()` is `false` and the assertion never executes.
      The test is titled "does not offer resubmission while the correction is missing a description" — a real
      FR-026/FR-047 rule — and it would pass identically if the button were present and enabled.

      **This is the same vacuous-assertion defect Phase 36 recorded for the US2 RED state**, in a different
      file. A conditional assertion around a control that does not exist is not evidence of anything.

      **Acceptance criteria.** Render a resubmission control in the correction form, gated on FR-047's
      validation rule, and make the assertion unconditional — either the control is absent for an incomplete
      draft, or it is present and disabled. Note the deliberate wording in the existing comment: the rule is
      "no *enabled* resubmit control", so the fix is to assert that, not to require a particular selector.
      If instead the decision is that resubmission belongs to a later task, say so on that task rather than
      leaving a permanently-satisfied assertion standing in for it. Cross-references: T075, T069, T074, T071,
      T359, Phase 36.

**What this pass did not find, and why that is worth recording.** The transaction and correction **backend** is
intact: `ResubmitCorrection` is atomic, idempotency-wrapped, and its tests run. Every finding above is at a
boundary — a route name, a component boundary, a request header, an authorization predicate, a manifest
element, a conditional assertion. Phase 55's generalisation extends by one correspondence: **client URL ↔
transport mechanism**, since a URL reached by browser navigation is not a request and cannot carry the header
the OCS layer demands. That is why F3 is invisible to both suites at once, and it is a check no suite in this
repository asserts. No finding here contradicts a constitution principle's letter — F4 violates Principle IV's
substantive rule and is therefore CRITICAL-adjacent in substance but recorded as HIGH because the work is a
narrow predicate change rather than a blocked journey. Nothing was deleted, renamed or renumbered; the 70
tasks that were already unchecked are untouched.

---

## Phase 58: Convergence (six gaps reached only by following the stated scope to its end)

This pass started from the artifacts rather than from a symptom, the way Phase 55 did, and took the
mechanical route: for every place `spec.md` or `plan.md` names a **surface**, follow the data to the
end and ask what reads the last field. Six places stop short. None is a constitution violation; all six
are cases where a requirement or a plan decision is satisfied on one surface and silently not on the
three the same sentence names.

**What made these findable and what kept them invisible.** F1, F4 and F5 are all cases of a requirement
that reads as a single sentence and is implemented as a subset of it. F2 and F3 are the inverse: an
exporter that exists, is mutation-verified, and is checked — and that no route can reach. The suites
cannot see either shape. `npm run test` is 296/296 and `scripts/dev/phpunit` is 1226 with one known
failure, because every one of these is an **absence** (a column not rendered, a caller that does not
exist, an event that is not written) rather than a wrong value, and a green suite is evidence about
values. Phase 55 recorded that "contract ↔ code" and "client payload key ↔ server payload key" are the
correspondences with no symptom; this pass adds the third: **"requirement sentence ↔ every surface the
same sentence names."**

F2 and F3 are the pair worth reading together. `XlsxExporter::completeSheet()` and
`ExportService::generateZip()` are two complete, tested implementations of two approved export formats,
and neither has a caller — the same defect Phase 56 recorded as "how the code is loaded," seen here as
"who asks for it." T108's and T110's notes are accurate about what they built. Neither note says the
result is unreachable, and a reader who trusts a `[x]` plus "mutation-verified four ways" would
reasonably conclude the format works. `contracts/exports.md:18,20` lists both under a table of what the
application produces.

- [ ] T365 [HIGH] Render FR-062's stage-approved columns on the sheet list, the dashboard, and the
      sheet export rows, because `plan.md:523-525` says the presentation "applies to dashboards, sheet
      lists, sheet details, and exports" and only the detail view does it
      (FR-062, `plan.md` Stage-Approved Totals Presentation, `src/views/sheets/SheetListView.vue`,
      `lib/Controller/ExportController.php`) (`partial`)

      **Measured, surface by surface.** `SheetDetailView.vue:192-204` renders all three — a
      `data-test="cost-center-approved-total"` chip, `senior-approved-total`, `finance-approved-total` —
      inside the same `data-test="effective-outcome-total"` group that T282 added to distinguish the
      latest-effective column from the per-stage ones. That is the whole of FR-062's presentation, and
      it exists on exactly one surface.

      | Surface `plan.md:523-525` names | State |
      |---|---|
      | sheet details | all three columns, T282 |
      | **sheet lists** | `SheetListView.vue:71` and `:131` render `formatMoney(item.totals.current)` and nothing else |
      | **dashboards** | `grep -n "totals\|effectiveApproved\|costCenterApproved" src/views/DashboardView.vue src/components/dashboard/*.vue` returns nothing |
      | **exports** | `ExportController::SHEET_COLUMNS` (`lib/Controller/ExportController.php:61`) is `['id','title','ownerUid','status','currency','submittedTotalMinor','createdAt']` — no `totals` object at all, and `ExportService::columnsOf()` (`:327-341`) derives its columns from that declaration plus the row keys |

      **Why the export half is the load-bearing one rather than cosmetic.** The figures exist and are
      correct: `SheetPayloadFormatter::totals()` (`:209-217`) emits `costCenterApproved`, `seniorApproved`
      and `financeApproved` as typed `Money`, `SheetTotals::fromRows()` computes them as independent sums,
      and `XlsxExporter::COMPLETE_SHEET_COLUMNS['Sheet']` (`:95-97`) already declares
      `costCenterApprovedMinor`/`seniorApprovedMinor`/`financeApprovedMinor` as `TYPE_NUMBER`. So the
      **complete-sheet** layout knows about them and the **ordinary** sheet export does not — which is
      T359's real shape: the richer layout is unreachable (T366) and the reachable one is thinner than
      the layout it is measured against. T101's note claims "T108's `Sheet` sheet now exports them as
      typed numeric cells", which is true of `completeSheet()` and false of any artifact a user can
      actually obtain.

      **Why it shipped.** FR-062's own text is about "sheet summaries," and the detail view's summary
      renders them. The plan's sentence is the only place the other three surfaces are named, and a
      requirement's own wording is what a reader checks first — the same artifact-drift shape Phase 55
      found in the payload keys.

      **Acceptance criteria.** The three stage figures appear on the sheet list and on the dashboard
      beside the latest-effective column; a sheet export artifact's header row names all three as numeric
      cells (read the produced workbook back with `PhpSpreadsheet`, as `XlsxExporterTest` already does,
      rather than asserting on the exporter's own report); and the *dashboard* half needs a decision
      recorded rather than assumed, because a dashboard card is not a sheet summary and the plan's
      sentence may be over-broad there. That judgement is not this task's to make silently.
      Cross-references: T101, T108, T282, T306, T366, T062, T113.

- [ ] T366 [HIGH] Give `XlsxExporter::completeSheet()` a caller, or record that the six-sheet
      complete-sheet layout is unreachable, because FR-067's "complete sheet export" is implemented and
      nothing can request it (FR-067, `contracts/exports.md:18`, `lib/Infrastructure/Export/XlsxExporter.php`,
      `lib/Controller/ExportController.php`) (`missing`)

      **Measured.** `completeSheet()` is declared at `lib/Infrastructure/Export/XlsxExporter.php:172` and
      its only non-definition references are its own internals plus `tests/Unit/Export/XlsxExporterTest.php`
      (five call sites) and the real-database `roundtrip-runner.php`. `grep -rn "completeSheet" lib/Controller/`
      returns **nothing**, and no route in `appinfo/routes.php` names a complete-sheet export.
      `ExportService::generate()` reaches `generateXlsx($rows, $columns)` (`:266-283`), which builds one
      `XlsxSheet` named `Rows` from the caller's declared columns — never the six named sheets.

      **What FR-067 asks for.** "A complete sheet export MUST preserve the relationships among sheet
      information, transactions, decisions, revisions, audit events, and evidence." The six-sheet layout is
      exactly that: `Sheet`, `Transactions`, `Decisions`, `Revisions`, `Audit`, `Evidence`, declared as
      `COMPLETE_SHEET_COLUMNS` and built by `completeSheet()`. What `POST /exports` produces for
      `resource: sheets` is a **single flat row set of seven columns** — no transactions, no decisions,
      no revisions, no audit, no evidence, and therefore no relationships to preserve. The requirement is
      unimplementable as the endpoint stands, and the artefact that would satisfy it is fully built.

      **This is the same defect F3 records from the ZIP side, and the two are one root cause:** the
      export controller resolves **rows** for its subjects and never reaches the two layout builders that
      exist above it. One fix — giving the controller a subject→layout resolution rather than a
      subject→columns list — closes both. They are separate tasks because the ZIP additionally needs an
      `EvidenceArchiveEntry` producer and the complete-sheet layout additionally needs its six
      projections, and those are different amounts of work on different files.

      **Two directions, and the choice matters.** (a) Wire `resource: sheet` (singular — the contract's
      enum carries both `sheet` and `sheets`, `ocs-api.yaml:1281` area) to `completeSheet()` with six
      real projections. This is the honest answer and it is a substantial amount of work: each sheet
      needs its transactions, decisions, revisions, audit events and evidence assembled per sheet.
      (b) Amend the contract so `sheets` is the complete-sheet export, or record FR-067 as reachable only
      through a future capability. (b) contradicts an approved MUST, so (a) is the answer; what is open is
      scope and ordering, not direction.

      **Acceptance criteria.** `POST /exports` with `resource: sheet` and one sheet id returns a `200`
      whose artifact, read back, contains the six named sheets with their declared columns and typed
      numeric cells, and the relationships are verifiable (the same sheet public id, the same revision
      ids, the same evidence ids appear across sheets). A mutation that drops one projection must fail
      a named test, since `XlsxExporterTest`'s current coverage proves the layout works but proves
      nothing about who asks for it.
      Cross-references: T108, T111, T112, T302, T306, T342, T365, T361.

- [x] T367 [HIGH] Resolve rows for `resource: evidence` and `resource: audit` so the grouped evidence
      ZIP and the audit export produce files instead of a 422, because FR-069 and FR-066 name both and
      `POST /exports` refuses both unconditionally (FR-069, FR-066, FR-074, FR-082,
      `lib/Controller/ExportController.php`, `lib/Infrastructure/Export/EvidenceArchiveExporter.php`) (`missing`)

      **Measured, and the refusal is total rather than partial.** `ExportController::rowsForSelection()`
      resolves rows for exactly three resources — `sheet`, `sheets`, `transactions` (`:245-260`). Every
      other resource takes one of two `throw new UnsupportedSelection(...)` branches: `:262-271` when the
      caller sent a selection, and `:284-288` when they did not. So `resource: evidence` answers **422 in
      both cases**, and there is no request shape that produces an artifact.

      **Consequently `generateZip()` is unreachable.** `ExportService::generate()` routes
      `FORMAT_ZIP => $this->generateZip($entries)` (`:121`), and `$entries` is a parameter the controller
      always passes as `[]` — `ExportController.php:153` reads
      `$this->exports->generate($scope, $resource, $fileFormat, $rows, [], $columns)`. Corroborating
      that the omission is structural rather than a missing one-liner: **`grep -rn "new EvidenceArchiveEntry" lib/`
      returns nothing.** Nothing in the application constructs the value object, so even removing the
      refusal would hand `EvidenceArchiveExporter` an empty list and produce a **valid, empty ZIP with an
      empty manifest** — precisely the "manifest that looks like evidence but is not" failure T110's
      result object was built to prevent, and T110's own comment at `:291-295` documents having already
      been bitten by an empty-entries path once.

      **What FR-069 and FR-066 require.** FR-069: "Group evidence exports MUST identify the sheet,
      transaction, revision relationship, file metadata, and active state for every exported item."
      FR-066 lists `zip` among the four formats V1 supports. `contracts/exports.md:20` describes ZIP as
      "Original files plus UTF-8 manifest linking sheet, transaction, revision, and evidence metadata."
      `EvidenceArchiveExporter` and its manifest already implement all of it, with 41 tests and
      mutation verification recorded on T110.

      **The client already offers it, and that is why this is HIGH rather than MEDIUM.**
      `src/types/reporting.ts:149` maps `evidence: ['csv', 'zip']` and `:161` maps `evidence: 'evidence'`.
      T342's note justifies keeping the offer deliberately — "a refusal the server explains on click
      tells a user something a silently disabled control does not" — so the choice is recorded and
      defensible. But a V1 user clicking the evidence ZIP button gets a 422 for a format FR-066 calls
      supported, and the explanation is that the feature was never built. **The same applies to
      `audit: ['csv','xlsx','pdf']`** (`:148`), which is FR-066's audit export and is refused identically.

      **Acceptance criteria.** `POST /exports` with `resource: evidence` and a selection of evidence ids
      returns a `200` whose artifact is a ZIP whose members correspond one-to-one with the manifest rows,
      and `resource: audit` returns its history rows under the same selection discipline T302/T306
      established (honoured or refused, never accepted and discarded). The FR-074 100 MB rule must survive
      the move — T110 treats an oversized file as a **refusal about upload validation**, not as an
      omission, and that distinction must be asserted at the endpoint rather than only in the exporter's
      unit tests. A mutation that passes `[]` for entries must fail, since that is the exact shape of the
      empty-manifest defect.

      ---

      **Delivered, and the scope was larger than the entry implies.** Four files changed plus one
      existing test rewritten. Three findings inside it are worth keeping, because two are the kind
      that stay invisible until something is measured over HTTP.

      **The rows already existed; only the refusal was wrong.** `ReportController` already had
      `evidenceReport()` and `auditReport()`, and `ReportQuery::SUPPORTED_TYPES` already listed
      `evidence` and `audit`. So the fix was **not** to write two row producers — it was to stop
      refusing them. Copying them into `ExportController` would have put one scope rule in two places,
      which is the T307 shape this repository has been bitten by repeatedly, and which
      `ReportController::__construct()`'s own docblock records when it moved `ReportFilterFactory`
      out for the same reason. Both producers now live in `EvidenceSelectionQuery` and
      `AuditSelectionQuery` (Application layer) and **both controllers call them**, so neither
      endpoint can drift from the other.

      **`EvidenceArchiveEntry` was genuinely unproducible, and that is what made the ZIP unreachable.**
      `grep -rn "new EvidenceArchiveEntry" lib/` returned nothing before this task. The entry needs
      four facts the evidence row does not carry — revision number, sheet public id, sheet title and
      the uploader's display name — resolved by joining evidence → transaction → revision and
      → sheet, plus a directory lookup. That is why the entry producer lives in the query and **not**
      in the report: the report pages over up to `BOUND` rows and would pay those lookups per row for
      columns FR-064 does not ask it for, whereas an export resolves a bounded set of named ids.

      **A hand-written positional DI list made the first attempt fail with an empty 200, and the
      symptom said nothing at all.** `Application.php` builds both controllers by hand —
      `registerService(ReportController::class, fn => new ReportController(...))` — so adding two
      constructor parameters without adding them to those lists **shifted every later argument along**:
      position 7 received `ExpenseSheetRepository`, the container handed `ExportController` a
      `DbExpenseSheetRepository` where it declared `EvidenceSelectionQuery`, and **every export
      answered an empty `200`** whose only trace was a TypeError in `nextcloud.log`. Two wrong
      diagnoses came first — a by-parameter-name container fallback, then `readonly` classes against
      PHP 8.5 lazy ghosts — and both were refuted by reading the container's own source and by querying
      it from inside a booted app. The lesson is this repository's own: **how the code is loaded** is a
      correspondence no suite asserts, and here it was an argument list written by hand.

      **Two defects found while verifying, both fixed.** (a) The scope predicates are
      `callable(row, scope)` — the shape `ReportQuery::paginate()` calls them with — so handing one
      straight to `array_filter()` raised `ArgumentCountError` from inside the closure and answered a
      bare 500 on every evidence export; the controller now goes through `scopedRows()`. (b) More
      seriously, filtering rows by the caller's ids **silently dropped** an id that did not resolve, so
      a caller naming three events would have received two, with `rowCount: 2` and nothing saying the
      third was dropped — T302's "accepted and discarded" in its row form, which is worse than a refusal
      because the number looks measured. `narrowToNamedIds()` now accounts for every named id and
      refuses otherwise, and a **missing id and a foreign id give the same answer** so a caller cannot
      probe for records they may not see (FR-003).

      **The empty-manifest guard is where T367 said it had to be.** `ExportService::generateZip()` now
      refuses an empty entry list *before* building, and its comment says why the placement matters:
      downstream of `build()`, an empty entry list and an archive whose members all failed to read are
      the same object, so refusing there would also refuse a real selection whose files were unreadable
      — a different answer, and the wrong one.

      **Verified over HTTP on the deployed app**, authenticated as the test user:

      | Request | Before | After |
      |---|---|---|
      | `resource=sheets, fileFormat=csv` | `200` | `200` (unchanged) |
      | `resource=audit, fileFormat=csv` | **`422`** | **`200`** |
      | `resource=evidence, fileFormat=csv` | **`422`** | **`200`** |
      | `resource=evidence, fileFormat=zip` + ids | **`422`** | **`200`**, a real archive |
      | `resource=evidence, fileFormat=zip`, no ids | `422` | `422` ("needs a selection") |
      | `resource=report, fileFormat=csv` | `422` | `422` (still no projection) |

      **The ZIP is opened and read back, not trusted from its status code** — because the failure this
      guards against is an archive that is well-formed and empty. `PK` magic asserted, the manifest
      member present, exactly one non-manifest member, and that member's bytes asserted to contain the
      uploaded content. The manifest name is read from `EvidenceArchiveExporter::MANIFEST_NAME` rather
      than hardcoded, so a rename cannot make the test count the manifest as an evidence file.

      **Mutation-verified in the direction T367 names.** Replacing the entry call with a literal `[]` —
      the exact shape of the empty-manifest defect — turns the archive test **RED** with the guard's own
      message: *"A 'zip' export needs the evidence entries to archive; none were resolved."*

      **One existing test was rewritten rather than deleted, and the narrowing is the record.**
      `testAResourceWithNoRowSourceIsRefusedRatherThanAnsweredWithAnEmptyArtifact` asserted that
      `report`, `audit` **and** `evidence` all refuse. Two of those three now answer, so keeping the
      assertion would pin this task's own defect in place. It now covers `report` alone — still the one
      resource with no projection — and a companion test asserts the other direction. Asserting symmetry
      across all three would fail on correct work, the same mistake Phase 36 recorded for another file.

      **Suites.** `ReportAndExportControllerTest` 66/66 (755 assertions). Full `scripts/dev/phpunit`
      **1245 tests / 6469 assertions / 1 failure**, and that failure is the same single pre-existing one
      as the baseline (`PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream`,
      `application/octet-stream` vs `application/pdf` — T318's known conflict, already proved unrelated
      in T345). Baseline was 1241 tests / 1 failure; now 1245 / 1.

      **Not done, and recorded rather than implied.** **T366 stays open and is untouched.** This task made
      `generateZip()` reachable, which closes the shared root cause T366 named ("one fix — giving the
      controller a subject→layout resolution rather than a subject→columns list — closes both"), but
      T366's own defect is `completeSheet()` having no caller and needing six real projections per
      sheet, and none of that was built here. **FR-074's ceiling is asserted through the exporter's real
      constant**, and the endpoint is asserted to hand it real entries, but the oversized-file *refusal*
      path is still covered only by T110's unit tests — this task added no endpoint-level oversized
      fixture, which would need a >100 MB upload. **`SELECTION_BOUND` is 1000** and applies to the
      unselected enumeration case only; a named selection is resolved id by id and is not bounded by it.
      And the client's `audit: ['csv','xlsx','pdf']` offer from T342 is now answerable for csv/xlsx;
      `pdf` remains refused by the pre-existing `pdfUnavailableReason` pending T109's font work.
      Cross-references: T110, T108, T302, T306, T342, T343, T366, T365, T109, T345.

- [x] T368 [MEDIUM] Carry each account's movements as data of the `accounts` report row, because FR-065
      requires "one account per row, carrying that account's balance **and its movements**" and the
      report carries only the balance (FR-065, `lib/Controller/ReportController.php`,
      `contracts/ocs-api.yaml`) (`partial`)

      **Delivered. The entry's one open question — nested array or a `movementCount` — is settled by
      FR-065's own wording, and the rejected alternative is recorded because it was cheaper.**

      ---

      **Shape: a nested `movements` array, because the requirement says "carrying … its movements".**
      The 2026-09-28 clarification settled the bigger question against a new report type, so the
      movements have nowhere else to live. The rejected alternative — a `movementCount` beside the
      balance, with the ledger reached through `GET /accounts/{id}/movements` — is cheaper and is not
      what FR-065 says. It also had a concrete failure behind it, recorded in the class docblock:
      this controller claimed FR-065's movements "are reachable here, through the `accounts` type's
      own rows" while the row carried none at all. The claim was true of the ledger endpoint and false
      of the report. `ReportPage.rows` is `additionalProperties: true`, so no contract amendment is
      needed and none is claimed.

      **The bound is stated rather than implied, because an unbounded promise is not available.** A
      ledger grows for as long as an account is used, so `MOVEMENT_READ_BOUND` (200) applies and **each
      row reports `movementsTruncated`**. A row that quietly carried fewer movements than the account
      has, beside a balance reflecting all of them, reads as an accounting error — so the flag is the
      difference between a partial ledger and a wrong one. `MOVEMENT_READ_BOUND + 1` rows are
      requested, because the only way to tell "exactly at the bound" from "truncated at the bound" is to
      read one row more than the bound allows; asking for exactly the bound would make a ledger of
      precisely that length indistinguishable from one that continues.

      **The N+1 is deliberate and is recorded with its precedent.** `AccountMovementRepository` has no
      bulk read, so this is one paged read per candidate account. `SheetPayloadFormatter` is the
      precedent for what to do when that stops being acceptable — it needed the same shape, and
      `SheetSummaryQuery::expenseDateRanges()` exists precisely to stop a page becoming a page of
      queries — and the docblock names it, so a future reader knows where the hoist belongs (a query
      class, not the controller).

      **T367's lesson applied before it could bite: the DI list is hand-written and positional.**
      `Application.php` builds `ReportController` with an explicit 17-argument list. Two arguments
      were added — `AccountMovementRepository` and `AccountPayloadFormatter` — and both were added
      there, not just to the constructor. T367 recorded what happens otherwise: every later argument
      shifts along, the container hands the controller a `TechnicalLogger` where it declares
      `AccountPayloadFormatter`, and **every export answers an empty `200`** whose only trace is a
      TypeError in `nextcloud.log`. A comment at the insertion point records the trap.

      **Asserting the balance/ledger invariant is what surfaced a fixture defect, and that is the most
      valuable thing here.** The new assertion states that on an untruncated row the balance must be
      reachable from the movements the row carries. It failed on an account with `balance_minor:
      100000` and an empty ledger — and on inspection that was **the fixture contradicting itself**:
      `seed-contract-fixtures.php` gave the second account a non-zero balance while
      `AccountControllerTest::testAccountWithNoMovementsIsAnEmptySuccess` and
      `::testTheCallersOwnSecondAccountIsStillReadable` both assert its ledger is empty, so the
      account was simultaneously *unused* and *funded*. FR-015 requires a charge's amount,
      description and resulting balance to be recorded as a movement, so a balance with no ledger is a
      state the application cannot produce.

      **Why that mattered beyond the fixture.** A non-zero balance beside an empty ledger is exactly
      what a charge that moved money without writing a movement would look like — and this is the
      account every charge test funds. The fixture was therefore quietly excusing that class of defect
      on the account most likely to exhibit it. The balance is now **0**, which is the honest value for
      an account that has never been used; nothing asserted the old 100,000, and the two tests that
      pinned the empty ledger still pass untouched.

      **One assertion of mine was wrong before the fixture was, and the fix is the better claim.** The
      first version said "an untruncated row carries its ledger", which fails on a *legitimately*
      unused account — and the second account is deliberately one. The real invariant is that the
      balance must be reachable from the row's movements, with 0 the value for an empty ledger. Stated
      as "matches one of the row's `balanceAfter` values" rather than as a **sum**, because the
      repository's ordering is not part of any contract and a sum would have pinned it.

      **Scope-leak direction asserted explicitly.** The read is scoped by the account's own surrogate
      key, so a movement from another account *cannot* reach a row — that is a property of the query,
      not of a filter, and no filtering happens after the read. Asserting it is still worth doing: it
      is the check that would fail if a row were ever assembled by querying movements without an
      account constraint and filtering afterwards, which is indistinguishable on the happy path.

      **T299/T112's scope rules are preserved exactly and were re-verified rather than assumed.** The
      `scope` / `chargedAccountsIncluded` / `chargedAccountCount` summary members are unchanged, the
      scope predicate is unchanged, and the existing three accounts-report tests still pass — including
      the one asserting the report agrees with `GET /accounts` on a charged account's balance, which
      now also has to agree about its movements.

      **3 new contract tests over HTTP; `ReportAndExportControllerTest` is 69/69.** Mutation-verified:
      replacing the row's `movements` with a literal `[]` turns both
      `testEachAccountsReportRowCarriesThatAccountsMovements` and
      `testTheAccountsReportStatesItsMovementReadBound` red. Full `scripts/dev/phpunit` is
      **1271 tests / 6711 assertions / 1 failure**, and that failure is the same single pre-existing one
      as the baseline (`PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream`,
      `application/octet-stream` vs `application/pdf` — T318's known conflict, proved unrelated in
      T345). Baseline was 1245 / 6473 / 1.

      **Not done, and recorded rather than implied.** The read is a per-account query, so the N+1 is
      real and is only *documented*, not removed; hoisting it is a query-class change this task did
      not make. **`MOVEMENT_READ_BOUND` is unexercised** — the fixtures hold at most two movements per
      account, so `movementsTruncated` is asserted as present-and-boolean but never as `true`, and a
      truncation bug that reported the wrong flag would not be caught. **No client-side consumer was
      added**: `src/` still renders the report without the movements, so nothing in the UI displays a
      ledger from this report yet, and FR-065's data reachability is now satisfied at the API rather
      than in the interface.
      Cross-references: T106, T112, T299, T063, T361.

- [x] T369 [MEDIUM] Write a configuration-audit event for the FR-015 account-funding charge, because
      FR-055 derives an officer's charged accounts from "account **funding** and balance-charging"
      events and only the settlement side writes one (FR-055, FR-015, FR-017,
      `lib/Application/Account/AccountService.php`, `lib/Application/Reporting/ChargedAccountScope.php`) (`partial`)

      **Delivered, and FR-055 settled both of the design points the entry called "not this task's to
      decide silently" — so they were decided against the requirement text rather than by preference.**

      ---

      **Design point (a), one event type or two: FR-055's own sentence decides it, and it is two.**
      The requirement says an officer's charged accounts are the subjects of their "account funding
      **and** balance-charging" configuration audit events, and that "an event type outside funding
      and charging — a rename, an activation, a deactivation — MUST NOT count toward the set, because
      touching an account is not charging it." That sentence is drawn to separate funding from
      charging, so one case for both would satisfy the words while erasing the distinction they are
      there to make: FR-015 puts money **into** an account and FR-043's settlement takes it **out**, and
      an auditor asking which happened would get one answer instead of two. `ConfigurationEventType`
      therefore gains `AccountFunded` and `AccountCharged` as two cases.

      **The enum deferral has now been paid, and the entry predicted which task would pay it.**
      `ConfigurationEventType`'s docblock kept `ACCOUNT_CHARGED` out on the grounds that its producer
      named it as the literal `ChargedAccountScope::CHARGE_EVENT_TYPE` and that "a second source of
      truth for a value another code reads" was the hazard — deferring the consolidation to "the task
      that owns those writes". T369 owns the funding write, so this is that task: both cases moved into
      the enum, `ChargedAccountScope` now reads `CHARGE_EVENT_TYPES` off the enum, and
      `FinanceSettlementService` writes `ConfigurationEventType::AccountCharged->value` instead of the
      constant. `ChargedAccountScope::CHARGE_EVENT_TYPE` is **removed**, not deprecated: leaving it
      would have preserved the exact two-sources-of-truth hazard the deferral was about.
      `RETENTION_POLICY_CHANGED` stays outside, and the docblock now says why the two differ —
      nothing reads it, so there is no derivation to keep in step with the enum.

      **Design point (b), the balance pair: this method *can* read an honest one, so it does.**
      `PettyCashAccount::charge()` assigns `balanceMinor` in place, so a "before" read taken after the
      call would be the post-charge value wearing a "before" label — the defect
      `FinanceSettlementService::recordConsumption()` documents at length and works around by recording
      no pair at all. `AccountService::charge()` receives the account *before* the mutation, so
      `$balanceBefore` is captured ahead of the charge and the event carries a real pair plus the
      currency, the movement id and the amount. The copy warning was the right instinct and was not
      followed: the settlement's workaround is available precisely because the settlement had lost the
      prior value, and this method has not.

      **`actor_role` is resolved, not hardcoded, and not defaulted.** `AuditWriterService::toEvent()`
      stamps `'PURCHASER'` when the payload omits it, which would have recorded a finance funding as a
      purchase. FR-013 admits an administrator to this operation, so hardcoding `FINANCE` would
      misattribute exactly the case FR-013 creates. `actorRoleFor()` asks the role assignments and
      returns `Administrator` as the fallback — safe because the FR-015 actor gate has already refused
      anyone holding neither role. An actor holding both is recorded as `Finance`, because FR-015 names
      finance as the charging role and that is the action performed.

      **`entity_id` is the public id, and that is the one field here that fails silently.**
      `ChargedAccountScope` resolves it through `AccountRepository::findByPublicId()` and drops what it
      cannot resolve, so a database id would produce an empty scope rather than an error — an officer
      with a working charge and no visibility, which is exactly the defect. Asserted explicitly.

      **The write sits inside `IdempotencyService::execute()`'s closure**, so a replay writes no second
      event, matching `createTransaction()` and `updateTransaction()`. Asserted, because the alternative
      leaves a retried charge or a double-clicked button with two funding rows for money that moved once.

      **One existing test was rewritten rather than deleted, and the rewrite is the record.**
      `ChargedAccountScopeTest::testChargesOfOtherEventTypesAreNotCounted()` asserted the **opposite**
      of this task: it used `ACCOUNT_FUNDED` as its counter-example and expected the funding account to
      be *excluded*. That was a faithful encoding of the one-event-type implementation, not of FR-055.
      Its purpose is kept and its counter-example changed to what the requirement actually names — a
      rename and a deactivation — so it still asserts that an event outside the pair must not widen
      scope. Two further tests were added so the pair's halves are separately falsifiable: funding
      counts with no settlement, and funding plus charging on one account yields it once. A union read
      by two queries can return an account twice or drop one half, and that is the failure mode of a
      change like this one.

      **The new suite asserts through the production `AuditWriterService`, not a writer double, and
      that choice is what makes it able to test FR-055 at all.** `InMemoryAuditWriter` records the
      array it was handed; `toEvent()` is where the actor comes from `CurrentUser` — it **ignores** an
      `actor_uid` key — and where `entity_type` is refused. So an assertion against the raw array
      would report an actor the stored row does not have. Wiring the real writer over two in-memory
      repositories means the event passes through that mapping, which is the only place the
      "MUST name the charging officer" half of FR-055 is observable.

      **Verified: 9 new tests in `AccountFundingAuditTest`, plus 3 amended in `ChargedAccountScopeTest`.**
      Mutation-verified in the direction the acceptance criteria name: gating the
      `appendConfiguration()` call behind `if (false)` turns **seven** tests red, including
      `testAFundedAccountIsInScopeWithoutAnySettlement` — the scope assertion, which is the criterion
      this task exists to satisfy. Full `scripts/dev/phpunit` is **1268 tests / 6678 assertions / 1
      failure**, and that failure is the same single pre-existing one as the baseline
      (`PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream`, `application/octet-stream`
      vs `application/pdf` — T318's known conflict, proved unrelated in T345). Baseline was 1245 / 6473 / 1.

      **Not done, and recorded rather than implied.** This is service-layer coverage: it does not stand
      up the real audit table, so `entity_id` → surrogate-key resolution through SQL is still
      `RepositoryRoundTripTest`'s and T236's business, and the entry's own note that this is the layer
      where the rule is written still stands. **No HTTP-level assertion was added**, and it could not
      be: the contract suite's single credential is a finance officer, so an administrator funding — the
      case this change newly distinguishes on the audit row — is unreachable over HTTP for the same
      reason T291 is still open. **The account's lock version does not advance on a funding charge**,
      so two sequential charges both succeed at `expectedVersion` 0; that is pre-existing behaviour
      outside this task, and it is why the honest-pair test funds twice from a non-zero balance rather
      than asserting the version moved.
      Cross-references: T294, T299, T085, T086, T291, T362.

- [x] T370 [MEDIUM] Assert over HTTP that a `COMPLETED` sheet refuses add, edit and submit, because
      SC-011 says 100% of completed sheets reject those and no contract test drives a completed sheet
      (FR-044, SC-011, `tests/Contract/Controller/`, `lib/Application/Expense/TransactionService.php`) (`partial`)

      **Delivered, and the task's own fixture note turned out to be the wrong advice to follow.**
      `tests/Contract/Fixture/seed-contract-fixtures.php:689` does seed a `COMPLETED` sheet
      (`ledgerSheet`), but this suite does not use it — it completes both of its sheets through the
      finance decision path the note recommended, and the note's reason for preferring that over the
      seeded row is the whole value of the result.

      ---

      **T370's premise was right, and measured rather than assumed.** The note claimed the capability
      test "would not catch" a mutated guard because it exercises `SheetCapabilities::derive()`
      rather than the service. Verified directly rather than taken on trust: with
      `SheetService::updateSheet()`'s status guard removed, **`SheetCapabilitiesTest` is 7/7 green**
      while two named tests in the new suite go red. A capability set is a pure function over a
      status; nothing routes a request through it, so it cannot observe that the service stopped
      enforcing the same rule. That asymmetry — the guard provably removable while its own test
      suite stays green — is the finding, and it is the reason this task was a test gap rather than
      a code gap.

      **The completion is produced, not seeded.** `setUp()` records two finance decisions, one per
      sheet, and every test asserts the arrived-at status. Each `finance` fixture owns a sheet holding
      exactly one transaction, which is what makes both completed states reachable from a single
      decision: approving the unmanaged-cost-centre transaction leaves its sheet with no rejection
      among completed transactions, so `ExpenseSheet::recomputeStatus()` settles on `COMPLETED`;
      rejecting the senior-rejected transaction leaves one, so the same rule settles the other sheet
      on `COMPLETED_WITH_REJECTIONS`. `COMPLETED_WITH_REJECTIONS` is covered because all five guards
      compare `!== Open` and therefore reach it by construction — it is the assertion that would fail
      if that comparison were ever narrowed to `=== Completed`, which would admit a sheet every
      reviewer rejected.

      **`$this->version()` had to become two helpers, and the reason is the guard ordering.** Both
      `SheetService::updateSheet()` and `SubmitSheet::execute()` check `lockVersion` **before**
      `status`. The first version read the version from the fixture manifest, which the settlement
      had just invalidated — so the request answered `Stale version`, which is *also* a 422, and two
      of the lock tests passed without the status guard ever running. Reading the live value fixed
      it. The version is read per resource rather than by probing `/sheets/{id}` and falling back to
      `/transactions/{id}`: the probe form costs an extra round trip and would silently read a
      *sheet's* version for a transaction the moment the first guess resolved.

      **The message assertion is load-bearing, because the status is not.** All five guards raise
      `InvalidArgumentException`, and `OcsErrorMapper` maps that one class to a single
      `422 VALIDATION_FAILED` — so a bare status assertion cannot distinguish the completed-sheet
      guard from a stale version, a missing field, or an unresolvable cost centre on the same
      endpoint. This was not theoretical: `testASettledTransactionRefusesAnEdit` initially sent
      `amountMinor` alone and was refused with `Required fields are missing`, a 422 that looked
      exactly like a passing lock assertion. Each refusal now asserts the guard's own message, and
      the test sends the three fields `TransactionController::update()` requires so the request is
      well-formed before the sheet's status is consulted.

      **A report page and a collection page are two shapes, and reading one for the other yields an
      empty list rather than an error.** The permissive report assertion passed vacuously on its first
      run because it read `items`; `ReportQuery::paginate()` returns the contract's `ReportPage`,
      whose members are `rows`, `columns` and `summary`. `assertContains` on an empty array fails,
      so it surfaced — but only because the *second* sheet id was also asserted. `reportRows()`
      now asserts `rows` is present before reading it, so the shape mismatch fails as itself.

      **The permissive half is the half that makes the suite worth having, and it was an addition.**
      Nothing asserted that FR-044's "for viewing and reporting **only**" permits anything. A suite
      of refusals alone passes just as happily against an implementation that broke the read path —
      and such an implementation satisfies every refusal in the file perfectly, because a sheet that
      cannot be read is very much a sheet that cannot be written. Three reads are asserted, all on a
      sheet this suite's own decision completed: `GET /sheets/{id}` with its totals and transactions,
      presence in `GET /sheets` (which scopes to the caller and applies no default status filter),
      and presence in `GET /reports/sheets` (`ReportController::sheetsReport()` reads every sheet
      the caller owns for a non-administrator, and `coversOwned()` admits the caller's own records
      unconditionally). The rejected sheet gets the same treatment, because a rejection is a terminal
      outcome and must not make a sheet unreadable — the one place where "the money was refused" and
      "the record is gone" would be easy to conflate.

      **12 tests / 79 assertions, green over HTTP on the deployed app.** Mutation-verified in the
      direction the acceptance criteria name: disabling `SheetService::updateSheet()`'s status guard
      turns `testACompletedSheetRefusesAnUpdate` and
      `testACompletedWithRejectionsSheetIsLockedAgainstEveryMutation` red with `200 matches expected
      422` — on both completed states, which is the point of covering both.

      **Not done, and recorded rather than implied.** This is endpoint coverage for the lock; it does
      not assert the *capability* half at the same time — `allowedActions` on a completed sheet is
      T370's neighbour concern and the capability test already owns it. No endpoint-level oversized
      or malformed-payload fixture was added, because that is T313/T314's data-integrity refusal and
      bundling it here would have widened this task past its statement. The suite authenticates as
      the single `PETTYCASH_TEST_USER`, which holds **no administrator role** — so the
      administrator branch of `sheetsReport()`, which lists only `Open` sheets by design, is not
      covered here and cannot be without a second credential.
      Cross-references: T033, T034, T281, T327, T314, T134.

---

## Phase 59: Convergence (the frontend still implements the pre-T312 premise)

**Found by converging after T312 closed.** Every finding below is a place where the code — or a green
test — still asserts something T312 made false, or where an approved requirement has no
implementation at all. Nothing here was visible from `tasks.md`, because T312 is marked `[x]` and its
own note records the server half as delivered; the client half was written against the state that
existed while T312 was blocked.

- [ ] T371 [HIGH] Correct `src/types/reporting.ts` and `ReportingExport.spec.ts`, because they assert
      `period` is **not** a member of the accepted filter vocabulary and that the filter bar must not
      emit it, and T312 has since made both statements false (FR-064, FR-068, `src/types/reporting.ts`,
      `tests/Frontend/Component/ReportingExport.spec.ts`) (contradicts)

      **The measured contradiction.** `src/types/reporting.ts:38-45` states that "`period` is **not** a
      member of the server's accepted filter vocabulary (`ReportFilterFactory::ACCEPTED_FILTERS`),
      because T312 is still blocked on an open `/speckit.clarify` question", and keeps the full V1
      vocabulary out of the wire type while offering `REPORT_OFFERABLE_PERIODS` as a subset instead.
      `ReportingExport.spec.ts:638` then asserts the filter bar does **not** emit `period`, commented
      "Its absence here is T312's precondition, not an oversight."

      **Both are now false, and the passing test is what keeps them false.** T312 is closed, FR-064 was
      amended on 2026-09-30 to name `period` a member in its own right, and `ACCEPTED_FILTERS` carries
      it. So the type models a server that does not exist and a test enforces it. This is the shape
      `/speckit.converge` exists for: a green suite asserting an obsolete premise is worse than a red
      one, because it is evidence that is confidently wrong.

      The change is to put `period` in the wire vocabulary, delete the subset indirection and the
      blocked-on-clarify reasoning, and **invert** the assertion so the bar must emit it. The
      `dateFrom` and `costCenterId` halves of that same test stay as they are — those are genuinely not
      accepted, and the test's value is that it catches the bar emitting a filter the server would
      refuse. One half of it is now catching a filter the server **does** accept.

      Cross-references: T312, T124, T309, T310.

- [ ] T372 [HIGH] Give the report filter bar a control that offers FR-068's predefined periods, because
      `ReportPeriodPreset` and `REPORT_OFFERABLE_PERIODS` exist but no component renders them, so a
      user-facing requirement is met only in the API (FR-068, `src/components/reports/`,
      `src/types/reporting.ts`) (missing)

      **FR-068 is a user-facing requirement**: "Users MUST be able to filter … group exports by period,
      which is the predefined Jalali period defined in FR-068." T312 delivered the server half, so the
      capability exists and nothing can reach it.

      Measured: `grep -rniE "period|jalali|preset" src/ --include=*.vue` returns **nothing**. The only
      matches in `src/` are in `src/types/reporting.ts` — the `ReportPeriodPreset` union
      (`month|quarter|year|custom`), the preset list, and `REPORT_OFFERABLE_PERIODS`. So the vocabulary
      and the groundwork are already modelled and unused.

      **The `custom` member is the interesting one, and it is the reason this is not a three-button
      change.** The union includes `custom`, which is the explicit-date-range alternative, and FR-068
      makes a period and an explicit range **mutually exclusive**. So the control has to express
      *absence* correctly: choosing a period must clear any date range already entered, and choosing
      custom must clear the period — otherwise the request is the `422` T312 built. That interaction is
      the substance of this task, not the button.

      Must send the disclosure through: T312 returns `period`, `resolvedFrom`, `resolvedTo` and
      `resolvedTimeZone`, and FR-068 requires the resolved range and zone to be visible to the user.

      Cross-references: T312, T371, T113, T114.

- [ ] T373 [MEDIUM] Drive `period` over live HTTP, because T312's own verification clause required it and
      the task was closed on unit coverage alone (FR-068, `tests/Contract/Controller/`) (partial)

      **T312's text is explicit**: "Assertions belong on the live endpoint the way every other T306 facet
      is asserted." It was closed with 405 reporting tests green and no HTTP assertion — so the endpoint
      path from `filters` → `ReportFilterFactory` → response disclosure is unproven.

      `grep -rn "'period'" tests/` finds no contract test; the only match is the frontend spec asserting
      the bar does *not* emit it. Required: `period=month|quarter|year` resolving to the in-progress
      Jalali range with `resolvedFrom`/`resolvedTo`/`resolvedTimeZone` in `GET /reports` **and**
      `POST /exports`; the `422` for `period` with `from`, for `period` with `to`, and for all three
      together, each naming the conflict; and a blank `period` not tripping the exclusion.

      Cross-references: T312, T372, T306.

- [ ] T374 [MEDIUM] Declare the closed filter vocabulary in `contracts/ocs-api.yaml`, because the
      document permits any filter while the server refuses unknown ones, so a client generated from the
      contract can send a filter the contract never declared a 422 for (FR-064, `contracts/ocs-api.yaml`)
      (partial)

      **Measured divergence.** `ExportRequest.filters` is `type: object` with `additionalProperties: true`
      and **no** enumeration, while `ReportFilterFactory::ACCEPTED_FILTERS` is a closed set and an
      unrecognised key is refused with a 422 that names the accepted list. T312 added `period` to the
      code and to the contract as an explicit property, so `period` is declared — but every *other*
      accepted filter still is not, and nothing in the document says unknown keys are refused.

      So the generated types accept a filter the server will reject, and the contract documents no 422
      for it. A client written against the contract can therefore send `{"dateFrom": "..."}` and receive
      a failure the contract never described.

      Enumerate the vocabulary as the contract's own closed set, add the 422 response that names it, and
      **run `scripts/validate-openapi.sh --write`** — the T124 gate will fail otherwise, which is the
      gate doing its job.

      Cross-references: T124, T309, T310, T312.

- [ ] T375 [MEDIUM] Create the third-party notice surface for the nine packages added to the
      application, because T312 closed having recorded that it *would* create it, and no open task owns
      its creation (plan: `morilog/jalali` dependency, T132) (missing)

      **The gap is orphaned, and it is a consequence of T312 closing.** T312's entry says the repository
      has **no** third-party-notice convention, that this task *creates* the notice surface, and that
      **T132 must verify it**. T312 is now `[x]`, T132 is open and verifies, and **nothing owns
      creating it** — `grep -niE "third.party notice|licence notice|LICENSES/" tasks.md` names no task,
      and no `LICENSES/`, `third-party-notices/` or `THIRD_PARTY*` path exists.

      Unnotified, all added by T312 and T124:
      `morilog/jalali` v3.5.0 (MIT) plus six transitive PHP packages — `nesbot/carbon` (MIT),
      `symfony/clock`, `symfony/translation`, `symfony/translation-contracts`,
      `carbonphp/carbon-doctrine-types`, `beberlei/assert` (**BSD-2-Clause**) — and the npm
      devDependencies `openapi-typescript` (MIT) and `yaml` (**ISC**).

      **Two licences here are not MIT**, which is exactly why a blanket "MIT dependencies" note would be
      wrong. Establish the surface, record each package with its licence and where its notice text
      lives, and add the assertion T132 can then verify rather than merely hope for.

      Cross-references: T312, T124, T125, T132.
