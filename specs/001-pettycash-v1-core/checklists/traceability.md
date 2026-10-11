# Requirement-to-Test Traceability: PettyCash V1 Core Workflow

**Scope:** FR-001 through FR-088 and SC-001 through SC-016 (T134, delivered 2026-10-09).
**Method:** one row per requirement. *Owner* is the delivering task — a task whose prose merely
cites an identifier does not count. *Test evidence* names files verified to exist; status is
`DONE` (delivered + green), `PARTIAL` (delivered in part, open remainder named), or `OPEN`.
Backend-done/frontend-open splits are `PARTIAL`, not `DONE`: an unrendered capability is not a
delivered requirement. Counts below are point-in-time (2026-10-09) and shift as open tasks close.

## Roles and Access (FR-001–FR-009)

| Key | Owner task(s) | Test evidence | Status |
|-----|---------------|---------------|--------|
| FR-001 | T013, T026 | `tests/Unit/Security/AuthorizationServiceTest.php`, `tests/Unit/Security/ManagementReviewAuthorizationTest.php` | DONE |
| FR-002 | T013, T026, T045 | AuthorizationServiceTest, `tests/Integration/Workflow/ManagementDecisionTest.php` (multi-role self-review) | DONE |
| FR-003 | T037, T174 | `tests/Contract/Controller/PurchaserFlowControllerTest.php`, `ManagementReviewControllerTest.php` (out-of-scope refusal) | DONE |
| FR-004 | T045, T050 | ManagementDecisionTest, ManagementReviewControllerTest (scoped queues) | DONE |
| FR-005 | T045, T050 | ManagementDecisionTest, ManagementReviewControllerTest (scoped queues) | DONE |
| FR-006 | T055, T062 | `tests/Unit/Domain/Workflow/FinanceDecisionPolicyTest.php`, `tests/Contract/Controller/FinanceReviewControllerTest.php` | DONE |
| FR-007 | T013, T174 | ManagementReviewAuthorizationTest, ManagementDecisionTest (admin-without-finance denial) | DONE |
| FR-008 | T013, T045 | ManagementReviewAuthorizationTest, ManagementDecisionTest (self-review allow) | DONE |
| FR-009 | T026 (+T108/T104 for application) | AuthorizationServiceTest; report/export enforcement rides open US7 | PARTIAL — exports/reports application open (T104, T108) |

## Configuration and Accounts (FR-010–FR-017)

| Key | Owner task(s) | Test evidence | Status |
|-----|---------------|---------------|--------|
| FR-010 | T077, T087 | `tests/Contract/Controller/ConfigurationControllerTest.php` | PARTIAL — admin-form Vue tests open (T080) |
| FR-011 | T077, T082 | ConfigurationControllerTest | PARTIAL — admin-form Vue tests open (T080) |
| FR-012 | T083 | ConfigurationControllerTest | PARTIAL — admin-form Vue tests open (T080) |
| FR-013 | T078, T409 | `tests/Contract/Controller/AccountWriteControllerTest.php` (create/PATCH refusal + success paths) | DONE |
| FR-014 | T037, T085 (provisioning service) | `tests/Contract/Controller/SheetProvisioningContractTest.php` | DONE |
| FR-015 | T063, T291 | `tests/Integration/Account/AccountChargeAuthorizationTest.php`, `tests/Contract/Controller/AccountChargeActorGateTest.php` (HTTP 403 gate-first) | DONE |
| FR-016 | T062 | FinanceReviewControllerTest (shared queue) | DONE |
| FR-017 | T294, T295, T296 | `tests/Integration/Account/AccountFundingAuditTest.php`, `AccountOpenAuditTest.php`, `tests/Integration/Audit/ConfigurationMutationAuditTest.php` | DONE |

## Sheets, Transactions, Evidence (FR-018–FR-031, FR-086)

| Key | Owner task(s) | Test evidence | Status |
|-----|---------------|---------------|--------|
| FR-018 | T037, T171 | PurchaserFlowControllerTest, `tests/E2E/purchaser-submit.spec.ts` (7/7 green) | DONE |
| FR-019 | T035, T037 | `tests/Unit/Domain/Expense/SheetSubmissionPolicyTest.php`, PurchaserFlowControllerTest | DONE |
| FR-020 | T085, T358, T410, T428 | SheetProvisioningContractTest, `tests/Contract/Controller/SheetCreationInactiveAccountTest.php` (exists-but-inactive 422) | DONE |
| FR-021 | T035 | SheetSubmissionPolicyTest, `DataIntegritySheetCurrencyTest.php` | DONE |
| FR-022 | T031, T037 | SheetSubmissionPolicyTest, purchaser-submit E2E (minimal draft) | DONE |
| FR-023 | T083, T419, T422 | `tests/Contract/Controller/ExpenseDateWindowTest.php`, currency scale-guard tests | DONE |
| FR-024 | T039 | PurchaserFlowControllerTest, `tests/Integration/Workflow/SheetSubmissionTest.php` | DONE |
| FR-025 | T037 (+T134 traceability for concurrency rule) | PurchaserFlowControllerTest, `tests/Contract/Controller/StaleVersionConflictTest.php` (409 on stale draft save) | DONE |
| FR-026 | T039, T044 | SheetSubmissionTest, `tests/Contract/Controller/CompletedSheetLockTest.php` | DONE |
| FR-027 | T055, T066 | FinanceDecisionPolicyTest (negative balances permitted) | PARTIAL — E2E journey open (T066) |
| FR-028 | T044, T048 | `tests/Unit/Domain/Workflow/ManagementWorkflowTest.php` | DONE |
| FR-029 | T036, T038 | `tests/Contract/Controller/EvidenceLifecycleControllerTest.php` | DONE |
| FR-030 | T036, T038 (no-restrictions rule) | PurchaserFlowControllerTest, `tests/Contract/Controller/EvidenceContentDelegationTest.php` | DONE |
| FR-031 | T095, T166, T399, T403, T407 | `tests/Contract/Controller/PublicLinkLifecycleContractTest.php`, `PublicEvidenceRouteContractTest.php` | PARTIAL — service/route completion open (T095, T166) |
| FR-086 | T417, T423 | `tests/Contract/Controller/DraftSheetDeletionTest.php`, `tests/Frontend/Component/SheetDeletion.spec.ts` | DONE |

## Review Workflow and Finance (FR-032–FR-046, FR-085)

| Key | Owner task(s) | Test evidence | Status |
|-----|---------------|---------------|--------|
| FR-032 | T039, T044 | SheetSubmissionTest, ManagementDecisionTest (routing) | DONE |
| FR-033 | T048, T426 | `tests/Contract/Controller/SingleTransactionDecisionTest.php` (bulk refusal, no supersession) | DONE |
| FR-034 | T048, T050 | ManagementWorkflowTest, ManagementDecisionTest (advisory visibility) | DONE |
| FR-035 | T048, T050 | ManagementWorkflowTest, ManagementDecisionTest (senior independence) | DONE |
| FR-036 | T050 | ManagementDecisionTest | DONE |
| FR-037 | T048 | ManagementWorkflowTest (sheet-stage derivation, CORRECTION ranking) | DONE |
| FR-038 | T055, T061 | FinanceDecisionPolicyTest, `tests/Integration/Workflow/FinanceSettlementTest.php` | DONE |
| FR-039 | T055, T056 | FinanceDecisionPolicyTest, FinanceSettlementTest (senior-rejected matrix) | DONE |
| FR-040 | T056, T061, T398 | FinanceSettlementTest (exactly-one consumption; unsettleable 422) | DONE |
| FR-041 | T056 | FinanceSettlementTest (no balance effect pre-approval) | DONE |
| FR-042 | T027, T136 | `tests/Integration/Workflow/IdempotencyServiceTest.php`, `AtomicityFailureInjectionTest.php` | DONE |
| FR-043 | T061 | FinanceSettlementTest (sheet completion states) | DONE |
| FR-044 | T044 | CompletedSheetLockTest | DONE |
| FR-045 | T137 | `tests/Integration/Workflow/NoAutoEscalationTest.php` | DONE |
| FR-046 | T076 (attempt refused) | `tests/Integration/Workflow/TransactionCorrectionTest.php` | PARTIAL — E2E journey open (T076) |
| FR-085 | T397 | `tests/Integration/Workflow/SupersededDecisionAttemptTest.php`, SingleTransactionDecisionTest | DONE |

## Correction, Revision, Audit (FR-047–FR-057)

| Key | Owner task(s) | Test evidence | Status |
|-----|---------------|---------------|--------|
| FR-047 | T067–T076 | TransactionCorrectionTest, `tests/Frontend/Component/TransactionCorrection.spec.ts` | PARTIAL — E2E journey open (T076) |
| FR-048 | T067 | TransactionCorrectionTest, `tests/Integration/Workflow/TransactionRevisionPersistenceTest.php` | DONE |
| FR-049 | T067 | TransactionCorrectionTest (full-path restart) | DONE |
| FR-050 | T067 | TransactionRevisionPersistenceTest | DONE |
| FR-051 | T028, T148 | `tests/Integration/Audit/AuditEventCoverageTest.php`, `tests/Integration/Persistence/RepositoryRoundTripTest.php` | DONE |
| FR-052 | T028 | AuditEventCoverageTest (actor/stage/payload snapshots) | DONE |
| FR-053 | T028 | RepositoryRoundTripTest (append-only, no update/delete surface) | DONE |
| FR-054 | T090 | `tests/Contract/Controller/DeletedUserRetentionContractTest.php` | DONE |
| FR-055 | T093, T139, T294 | `AuditQueryService` scope tests; integration scope test | PARTIAL — integration scope test open (T139) |
| FR-056 | T093, T139 | Separation tests (transaction vs configuration streams) | PARTIAL — open with T139 |
| FR-057 | T100, T138 | `tests/Integration/Audit/RetentionPolicyTest.php` (7-year floor) | DONE |

## Evidence Lifecycle (FR-058–FR-060)

| Key | Owner task(s) | Test evidence | Status |
|-----|---------------|---------------|--------|
| FR-058 | T038 | EvidenceLifecycleControllerTest (pre-submission removal) | DONE |
| FR-059 | T038 | EvidenceLifecycleControllerTest (post-submission deactivation) | DONE |
| FR-060 | T275 | EvidenceContentDelegationTest (inline-safe vs attachment) | DONE |

## Dashboards, Search, Reports, Exports (FR-061–FR-070, FR-082)

| Key | Owner task(s) | Test evidence | Status |
|-----|---------------|---------------|--------|
| FR-061 | T105–T108, T214 | `tests/Contract/Controller/DashboardControllerTest.php` | PARTIAL — dashboard/report views + E2E open (T113, T114) |
| FR-062 | T101, T365 | SheetTotals conformance tests, `tests/Unit/Domain/Expense/SheetTotalsTest.php` | DONE |
| FR-063 | T385 | `tests/Contract/Controller/SheetSummaryConformanceTest.php` | DONE |
| FR-064 | T309, T310 | `tests/Contract/Controller/SheetListFilterContractTest.php` | PARTIAL — `period` E2E + remaining facets ride open T104/T114 |
| FR-065 | T106, T368 | Report scope tests | PARTIAL — report views + E2E open (T113, T114) |
| FR-066 | T107–T111 | Exporter tests (`tests/Unit/Export/ExportersTest.php`) | PARTIAL — Persian-font vendoring open (T109), E2E open (T114) |
| FR-082 | T302, T306 | Selection-refusal tests | DONE |
| FR-067 | T108 | `tests/Contract/Controller/CompleteSheetExportContractTest.php` | DONE |
| FR-068 | T312 | `tests/Contract/Controller/ReportingPeriodContractTest.php` | DONE |
| FR-069 | T110 | Grouped-evidence export tests | PARTIAL — E2E open (T114) |
| FR-070 | T135 (+T429 correction) | Release-readiness review (open T135); negative requirements pinned by NoAutoEscalationTest (FR-045), SingleTransactionDecisionTest (bulk), E2E revision-history (sheet audit) | PARTIAL — review itself open (T135) |

## Experience and Operations (FR-071–FR-077, FR-083, FR-084, FR-087, FR-088)

| Key | Owner task(s) | Test evidence | Status |
|-----|---------------|---------------|--------|
| FR-071 | T053, T065, T120, T400 | `tests/Frontend/typography.spec.ts`, `RtlAccessibility.spec.ts`, keyboard journey tests | PARTIAL — browser-measured clauses ride T122/T123 |
| FR-072 | T122 | Browser matrix (open) | PARTIAL — open (T122) |
| FR-073 | T122, T128, T135 | Error-rate procedure (`quickstart.md` §8.2, measurement script); p95 deferred per SC-008 | PARTIAL — measurement open (T122, T135) |
| FR-074 | T032, T033, T036 | PurchaserFlow + uploader specs (100 MB ceiling) | DONE |
| FR-075 | T030, T121 | `http.ts` no-retry client tests | PARTIAL — shared-state/retry UX open (T121) |
| FR-076 | T135 | Deferral record (V2 items named, not built) | PARTIAL — review open (T135) |
| FR-077 | T239, T240 | Four-backend CI matrix (green 2026-10-09), static portability gate | DONE |
| FR-083 | T313, T314, T425 | DataIntegrityReportTest, `tests/Contract/Controller/DashboardDataFaultTest.php`, `ErrorCodeVocabularyTest.php` | DONE |
| FR-084 | T027, T317, T318 | IdempotencyServiceTest, StaleVersionConflictTest, SingleTransactionDecisionTest | DONE |
| FR-087 | T418, T424 | Fault-logging tests (five named fields, identifier channel) | DONE |
| FR-088 | T171 | purchaser-submit E2E 7/7 (post-save lands sheet detail) | DONE |

## Schema Ownership and Migrations (FR-078–FR-081)

| Key | Owner task(s) | Test evidence | Status |
|-----|---------------|---------------|--------|
| FR-078 | T260, T261 | `tests/Integration/Migration/V1SchemaMigrationTest.php` (`pettycash_*` namespace) | DONE |
| FR-079 | T264 | Identifier-limit assertions in V1SchemaMigrationTest | DONE |
| FR-080 | T262, T263 | Clean-install acceptance (quickstart §2) | PARTIAL — legacy-framing reconciliation open (T263) |
| FR-081 | T262 | Forward-chain migration acceptance | DONE |

## Success Criteria (SC-001–SC-016)

| Key | Owner task(s) | Test evidence | Status |
|-----|---------------|---------------|--------|
| SC-001 | T039, T171 | SheetSubmissionTest, purchaser-submit E2E | DONE |
| SC-002 | T174, T045 | Scope/refusal tests across queues | DONE |
| SC-003 | T044, T056 | ManagementWorkflowTest, FinanceSettlementTest | DONE |
| SC-004 | T067 | TransactionRevisionPersistenceTest, TransactionCorrectionTest | DONE |
| SC-005 | T056, T061 | FinanceSettlementTest (exactly-one movement; zero partial effects) | DONE |
| SC-006 | T028, T148 | AuditEventCoverageTest, `tests/Contract/Controller/AuditTimelineOrderTest.php` | DONE |
| SC-007 | T102, T104 | Report scope tests | PARTIAL — E2E + views open (T113, T114) |
| SC-008 | T128, T304 | Deferred post-V1 by decision; T128 informational run | DONE (as deferred-measurement rule) |
| SC-009 | T122, T400 | Keyboard/focus journey tests | PARTIAL — browser matrix open (T122) |
| SC-010 | T135 | Recorded-not-measured rule; release-readiness record | PARTIAL — review open (T135) |
| SC-011 | T044 | CompletedSheetLockTest | DONE |
| SC-012 | T090 | DeletedUserRetentionContractTest | DONE |
| SC-013 | T239, T240 | Four-backend matrix green (acceptance record itself is T135's) | PARTIAL — acceptance record open (T135) |
| SC-014 | T260, T261 | V1SchemaMigrationTest (100% `pettycash_`, clean install) | DONE |
| SC-015 | T418, T424 | Fault-logging tests | DONE |
| SC-016 | T425, T427 | ErrorCodeVocabularyTest (two-way), regenerated typed client | DONE |

## Open rollup (what keeps rows PARTIAL/OPEN)

E2E journeys T054/T066/T076/T089/T099/T114; browser/manual T122/T123; US6 stack T092–T099 (service/routes/UI); US7 reporting/exports T102–T104/T109/T113; US8 shell/l10n T115/T117–T121; hardening T125/T127–T133; release review T135; config-audit integration T139; public-link completion T095/T166; migration-safety T251/T254; legacy framing T263; reconciliation T060/T130; projections T315; export replay T346.
