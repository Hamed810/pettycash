# GAP_MATRIX — Pettycash purchaser redesign audit

**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe` · recorded 2026-10-19
**Scope:** every finding in `AUDIT_REPORT.md` (47), plus the verified controls and the open questions, in one table.
**Owner codes:** `FE` frontend · `BE` backend · `OWNER` product decision required · `FE+BE` both.
**Disposition codes:** `TASK:<id>` mapped to a proposed task · `BLOCKED_OWNER_DECISION:<D-nn>` needs a product decision first · `NOTED` no action proposed (documented reason in the audit report) · `VERIFIED_OK` checked and correct · `OUT_OF_SCOPE` outside purchaser UI scope.

> Nothing in this document is approved for implementation. `PROPOSED` means "prepared for owner review".

| ID | Area | Class | Pri | Confidence | Expected → Actual (abbreviated) | Key evidence | Owner | Disposition |
|---|---|---|---|---|---|---|---|---|
| GAP-001 | A | MISSING_BACKEND_CAPABILITY | P1 | confirmed_in_source | OPEN-first ordering asc/desc across server pagination → no `sort` param, single `ORDER BY created_at DESC`, no ordering clause in contract | `contracts/ocs-api.yaml:364-381`; `DbExpenseSheetRepository.php:58`; `SheetController.php:93-100` | BE | BLOCKED_OWNER_DECISION:D-09 · `BE-CR-002` · `UI-PUR-003` |
| GAP-002 | A | MISSING_FRONTEND_CAPABILITY | P1 | confirmed_in_source | the OPEN working set is complete → region filters only the loaded page | `src/stores/sheets.ts:15,30`; `SheetListView.vue:521-536` | FE | TASK:UI-PUR-003 (after BE-CR-002) |
| GAP-003 | A | MISSING_FRONTEND_CAPABILITY | P2 | confirmed_in_source | a direction control exists → no sort UI at all | `SheetListView.vue` (no sort control); contrast `SheetDetailView.vue:114-121` | FE | TASK:UI-PUR-003 |
| GAP-004 | A | OPTIONAL_IMPROVEMENT | P3 | confirmed_in_source | page size selectable → fixed 25 | `src/stores/sheets.ts:11`; `ocs-api.yaml:987` | FE | NOTED |
| GAP-005 | A | VERIFIED_DEFECT | P2 | confirmed_in_source (tie occurrence inferred) | stable page membership → single sort key + offset paging can duplicate/skip on tied timestamps | `DbExpenseSheetRepository.php:58`; `SheetService.php:141-149` | BE | TASK:BE-CR-002 |
| GAP-006 | A | SPEC_AMBIGUITY | P2 | confirmed_in_source (intent unverified) | FR-064 "date" filter → applied to `created_at` while the list shows expense dates | `DbExpenseSheetRepository.php:121-133`; `SheetListFilterFactory.php:148`; `spec.md:1058-1076` | OWNER | BLOCKED_OWNER_DECISION:D-03 |
| GAP-007 | A | OPTIONAL_IMPROVEMENT | P3 | confirmed_in_source | — → no search on the purchaser list (not required by FR-064) | `SheetListView.vue`; `sheetsApi.ts`; prototype `app.js:194` | FE | NOTED |
| GAP-008 | A | OPTIONAL_IMPROVEMENT | P3 | confirmed_in_source | — → totals queried per sheet (N+1); SC-008 deferred | `SheetPayloadFormatter.php:164-177`; `SheetSummaryQuery.php:45-52`; `spec.md:1163-1164` | BE | NOTED |
| GAP-009 | A | DESIGN_INCONSISTENCY | P2 | confirmed_in_source | 6-column purchaser list with title above ID and separate Recorded/Latest-Approved → 11 columns, no ID rendered, Recorded+Submitted share one cell | `SheetListView.vue:140-158,274-285,199,405`; FR-062 `spec.md:1046-1048` | OWNER+FE | TASK:UI-PUR-002 · BLOCKED_OWNER_DECISION:D-04 (column set) |
| GAP-010 | A | DESIGN_INCONSISTENCY | P3 | VISUAL_REVIEW_NEEDED | status pills per lifecycle state → cannot be judged from source | `SheetListView.vue:272-273` | FE | TASK:UI-PUR-002 (token mapping) |
| GAP-011 | B | VERIFIED_DEFECT | P1 | confirmed_in_source (data consequence inferred) | a retitle changes only the title → it can repoint the sheet to the caller's first account, unvalidated, without reconciling the stored currency | `SheetFormView.vue:113-127`; `SheetPayloadFormatter.php:22-24`; `SheetController.php:147-158`; `SheetService.php:180-186`; `ExpenseSheet.php:23-24,65-66`; `PettyCashAccount.php:53,73` | FE+BE | TASK:UI-PUR-007 + BE-CR-001 · BLOCKED_OWNER_DECISION:D-05 |
| GAP-012 | B | DESIGN_INCONSISTENCY | P2 | confirmed_in_source | inline title/account edit while OPEN → separate `/sheets/:id/edit` route | `src/router/index.ts:58`; `SheetDetailView.vue:250-253` | FE | TASK:UI-PUR-007 |
| GAP-013 | B | DESIGN_INCONSISTENCY | P2 | confirmed_in_source | destructive actions behind a 3-dot menu → permanently visible Delete buttons; no `NcActions` anywhere | `SheetListView.vue:247-252,361-367`; `SheetDetailView.vue:269-276` | FE | TASK:UI-PUR-008 |
| GAP-014 | B | SPEC_AMBIGUITY | P3 | confirmed_in_source | first-submitted timestamp immutable → holds by "no other writer" rather than by construction | `ExpenseSheet.php:89`; `DbExpenseSheetRepository.php:181,201,259` | BE | NOTED |
| GAP-015 | B | PROTOTYPE_SIMPLIFICATION | P3 | confirmed_in_source | — → prototype delete copy claims "reversible" though deletion persists; real deletion is an irreversible cascade | `app.js:308,323,129-130`; `spec.md:201` | — | NOTED (copy must not be ported) |
| GAP-016 | B | — | — | confirmed_in_source | Submit only when OPEN and non-empty → correct, no disabled placeholder | `src/stores/sheets.ts:14`; `SheetDetailView.vue:258-261` | — | VERIFIED_OK |
| GAP-017 | C | MISSING_FRONTEND_CAPABILITY | P1 | confirmed_in_source | large detail workspace over the list, preserving scroll/filter state → route navigation; page resets to 1; no modal primitive; no scroll restoration | `SheetListView.vue:405`; `router/index.ts:57`; `AppRoot.vue:41`; `src/stores/sheets.ts:24` | FE | TASK:UI-PUR-005 + UI-PUR-004 |
| GAP-018 | C | MISSING_FRONTEND_CAPABILITY | P2 | confirmed_in_source | stage/decision summary on the sheet → totals card + table only | `SheetDetailView.vue:355-390` | FE | TASK:UI-PUR-014 |
| GAP-019 | C | OPTIONAL_IMPROVEMENT | P3 | confirmed_in_source | — → 30+ transactions in one plain table, no virtualization (no measured problem) | `SheetDetailView.vue:403-425` | FE | NOTED (acceptance in UI-PUR-006) |
| GAP-020 | C | MISSING_BACKEND_CAPABILITY | P2 | confirmed_in_source | per-stage approved/rejected/pending/returned counts → only per-stage approval **amounts** exist; `TransactionSummary` carries one `latestDecision` | `SheetTotals.php:30-32`; `SheetPayloadFormatter.php:246-261`; `ocs-api.yaml` TransactionSummary | BE | BLOCKED_OWNER_DECISION:D-06 · `BE-CR-003` · `UI-PUR-014` |
| GAP-021 | C | VERIFIED_DEFECT | P1 | confirmed_in_source | sheet status derived from the earliest outstanding stage → recomputed only inside finance settlement, so the list can show a stale status after cost-center/senior decisions and after returns | `ExpenseSheet.php:133-179`; `FinanceSettlementService.php:408,524-536`; `contracts/workflow.md:110-113` | BE | TASK:BE-CR-005 |
| GAP-022 | C | VERIFIED_DEFECT | P2 | confirmed_in_source (behaviour unverified) | a decision on a correction-stage transaction is impossible → it is accepted, persisted, audited, and changes nothing | `AuthorizationService.php:68-70`; `ManagementReviewService.php:221-223`; `ExpenseTransaction.php:184-186` | BE | TASK:BE-CR-006 |
| GAP-023 | D | VERIFIED_DEFECT | P1 | confirmed_in_source | FR-025: delete a draft transaction → API + store action exist, no UI calls them; a test pins their absence | `spec.md:832-833`; `transactionsApi.ts:32-34`; `stores/transactions.ts:117-130,215`; `TransactionCorrection.spec.ts:735`; server guards `TransactionService.php:514-539` | FE | TASK:UI-PUR-011 |
| GAP-024 | D | MISSING_FRONTEND_CAPABILITY | P1 | confirmed_in_source | preselect the cost center of the last created+saved transaction in the sheet → field always starts empty; concept exists only in the prototype | `TransactionFormView.vue:26-32,187-200`; prototype `app.js:98,282` | FE | TASK:UI-PUR-009 |
| GAP-025 | D | VERIFIED_DEFECT | P1 | confirmed_in_source | evidence attaches to an existing transaction → the uploader renders on the create form with an empty id and would POST to `/transactions//evidence` | `TransactionFormView.vue:24,71-77,400-403`; `evidenceApi.ts:25`; `PurchaserFlow.spec.ts:1876-1879` | FE | TASK:UI-PUR-010 |
| GAP-026 | D | — | — | confirmed_in_source | currency inherited and read-only per transaction → correct | FR-021 `spec.md:792-793`; `TransactionService.php:116-118`; `ExpenseSheet.php:65-66` | — | VERIFIED_OK |
| GAP-027 | D | — | — | confirmed_in_source | partial draft save vs complete submission → correct | `TransactionFormView.vue:155-162`; `TransactionController.php:66-98` | — | VERIFIED_OK |
| GAP-028 | D | MISSING_FRONTEND_CAPABILITY | P2 | confirmed_in_source | `POST /correction` is the correction entry point → the client omits the required version and has no caller; the UI PATCHes instead | `transactionsApi.ts:58-60`; `TransactionController.php:261-267`; `TransactionDetailView.vue:109`; `TransactionService.php:309-312,426-431` | FE | TASK:UI-PUR-013 |
| GAP-029 | D | VERIFIED_DEFECT | P2 | confirmed_in_source | FR-025: after a 409 the client MUST reload before saving again → detection is correct, the reload is missing | `spec.md:832-841`; `SheetService.php:171-178`; `TransactionFormView.vue:204-208` | FE | TASK:UI-PUR-012 |
| GAP-030 | E | VERIFIED_DEFECT | P1 | confirmed_in_source | FR-023: submission requires "all required evidence" → `assertSubmittable()` is called with the default `false` and no caller passes `true`; enforced only on the transaction form | `spec.md:796-797`; `SubmitSheet.php:83`; `TransactionRevision.php:124-129`; `data-model.md:122,267` | BE | TASK:BE-CR-004 |
| GAP-031 | E | MISSING_FRONTEND_CAPABILITY | P2 | confirmed_in_source | the Submit affordance reflects readiness → it reflects only status + count | `src/stores/sheets.ts:14`; `SheetDetailView.vue:442-451` | FE | TASK:UI-PUR-016 (after BE-CR-004) |
| GAP-032 | E | VERIFIED_DEFECT | P2 | confirmed_in_source | evidence required only when the expense type requires it → resubmission demands it unconditionally, inverting FR-023 relative to GAP-030 | `ResubmitCorrection.php:108-110`; `spec.md:796-797` | BE | TASK:BE-CR-004 |
| GAP-033 | E | — | — | confirmed_in_source | 100 MB per file, own ceiling → correct (server + UI mirror) | FR-074 `spec.md:1187`; `EvidenceService.php:29`; `EvidenceController.php:101-107`; `EvidenceUploader.vue:29,55-62` | — | VERIFIED_OK |
| GAP-034 | E | — | — | confirmed_in_source | no invented MIME allowlist → correct; serving forces unsafe types to attachment | `EvidenceController.php:111-114,503-512`; `EvidenceUploader.vue:113` | — | VERIFIED_OK |
| GAP-035 | E | — | — | confirmed_in_source | multiple attachments; upload after the transaction exists → correct server-side | FR-029 `spec.md:852-854`; `EvidenceController.php:118-146`; `EvidenceList.vue:88` | — | VERIFIED_OK |
| GAP-036 | E | PROTOTYPE_SIMPLIFICATION | P3 | confirmed_in_source | — → prototype wording implies per-revision evidence; the model is per transaction (spec-consistent) | `TransactionPayloadFormatter.php:156-171`; prototype `app.js:251` | — | NOTED |
| GAP-037 | F | — | — | confirmed_in_source | categorical decisions, never partial amounts → correct | `Decision.php:4`; `src/types/api.ts:221-225`; `ManagementReviewService.php:63-70`; `FinanceSettlementService.php:74-80` | — | VERIFIED_OK |
| GAP-038 | F | — | — | confirmed_in_source | Cost Center → Senior → Finance → Final enforced server-side; client-supplied stage ignored → correct | `ExpenseTransaction.php:114-191`; `ManagementReviewService.php:221-223`; `ReviewController.php:211,227-243` | — | VERIFIED_OK |
| GAP-039 | F | — | — | confirmed_in_source | return → new revision → restart at Cost Center → correct | `ExpenseTransaction.php:124-127,208-214,271-279`; `ResubmitCorrection.php:90-143`; `contracts/workflow.md:67-74` | — | VERIFIED_OK |
| GAP-040 | F | — | — | confirmed_in_source | terminal sheet ⇒ only terminal transactions → correct, but rests on a single writer (see GAP-021) | `ExpenseSheet.php:165-179` | BE | NOTED (hardening inside BE-CR-005) |
| GAP-041 | F | SPEC_AMBIGUITY | P3 | confirmed_in_source | every published status can occur → `AWAITING_SENIOR_REVIEW`/`AWAITING_FINANCE_REVIEW` are declared but never produced | `TransactionStatus.php:7-17`; `ExpenseTransaction.php:119-153`; `ocs-api.yaml` TransactionStatus | OWNER | BLOCKED_OWNER_DECISION:D-07 · NOTED |
| GAP-042 | F | — | — | confirmed_in_source | each stage decides independently → correct (a cost-center rejection does not block a later approval) | `ExpenseTransaction.php:121-123`; `ManagementWorkflowTest.php:119`; `ManagementDecisionTest.php:185-208` | — | VERIFIED_OK |
| GAP-043 | F | — | — | confirmed_in_source | no business rule lives only in the frontend → correct; decision capabilities come from the server | `DecisionForm.vue:111-115`; `TransactionPayloadFormatter.php:303-313`; `FinanceDecisionPolicy.php:37-40` | — | VERIFIED_OK |
| GAP-044 | F | SPEC_AMBIGUITY | P3 | unverified | explanation length bounded on both sides → `maxlength=500` is UI-only; no server maximum found | `DecisionForm.vue:36` | BE | NOTED |
| GAP-045 | G | — | — | confirmed_in_source | integer minor units end to end → correct, no floats in the application | `SheetSummaryQuery.php:191`; `SheetTotals.php:38-66`; `SheetPayloadFormatter.php:273-283`; `src/types/api.ts:140-144` | — | VERIFIED_OK |
| GAP-046 | G | — | — | confirmed_in_source | `current` vs `effectiveApproved` semantics → correct, including "returned stays pending while earlier approvals still count" | `SheetTotals.php:38-84`; `SheetTotalsTest.php:13-86` | — | VERIFIED_OK |
| GAP-047 | G | PROTOTYPE_SIMPLIFICATION | P2 | confirmed_in_source | — → floats, hardcoded `$`, USD fixed, `returned` folded into `pending` in stage summaries | `app.js:34,56,60-64,98,139-150` | — | NOTED (constraints in UI-PUR-002 / UI-PUR-014) |
| GAP-048 | G | DESIGN_INCONSISTENCY | P3 | confirmed_in_source | absent values render as absent → a never-submitted sheet's `submitted` total renders as `0` | `SheetPayloadFormatter.php:253`; contrast `src/utils/date.ts:1-31` | FE | TASK:UI-PUR-002 (acceptance) |
| GAP-049 | G | — | — | — | placeholder — per-stage counts are GAP-020, rolled-over totals are GAP-050 | — | — | NOTED |
| GAP-050 | G | — | — | confirmed_in_source | no rolled-over or cross-sheet totals → confirmed absent | `SheetDetailView.vue:355-390`; `DashboardController.php:220-222` | — | VERIFIED_OK |
| GAP-051 | H | — | — | confirmed_in_source | per-transaction history only, no sheet-wide endpoint → deliberate non-goal | `routes.php:31-32`; `spec.md:240` | — | VERIFIED_OK (constraint in UI-PUR-006) |
| GAP-052 | H | DESIGN_INCONSISTENCY | P2 | confirmed_in_source | an audit entry identifies its decision → the top-level field is the literal string `'DECISION'`; the value is nested in `after` | `AuditController.php:115`; `ManagementReviewService.php:286-291`; `FinanceSettlementService.php:353-359` | FE | TASK:UI-PUR-006 (acceptance); API hygiene D-10 |
| GAP-053 | H | — | — | confirmed_in_source | bounded history payloads → revisions is an unpaged array, audit is paged | `AuditController.php:48-64,76-101` | FE | TASK:UI-PUR-006 (acceptance) |
| GAP-054 | I | MISSING_FRONTEND_CAPABILITY | P1 | confirmed_in_source | accepted Unsaved Changes guard (save/discard/stay, close, Escape, back) → no guard exists anywhere | `src/` (0 hits for `beforeunload`/`onBeforeRouteLeave`/`isDirty`); `router/index.ts` (no guards); `SheetFormView.vue:265`; `TransactionFormView.vue:411-413`; prototype `app.js:275-279,301-305,354-355` | FE | TASK:UI-PUR-012 |
| GAP-055 | I | — | — | confirmed_in_source | optimistic locking on every write path → correct and wired in the UI | `SheetService.php:171-178`; `DeleteSheet.php:137-144`; `SubmitSheet.php:67-74`; `TransactionService.php:525-532`; `TransactionFormView.vue:204-208` | — | VERIFIED_OK |
| GAP-056 | I | OPTIONAL_IMPROVEMENT | P3 | confirmed_in_source | — → no draft recovery after accidental loss | form path (no persistence) | FE | NOTED (explicit non-goal) |
| GAP-057 | I | OPTIONAL_IMPROVEMENT | P3 | confirmed_in_source | one shared async-state idiom → `AsyncState.vue` is tested but imported by nobody; three idioms across surfaces | `components/shared/AsyncState.vue`; `AsyncState.spec.ts:65-67`; `SheetListView.vue:533,554`; `SheetDetailView.vue:403-404` | FE | NOTED (acceptance inside UI-PUR-002/005/006) |
| GAP-058 | J | MISSING_FRONTEND_CAPABILITY | P3 | confirmed_in_source | honour reduced-motion → 0 occurrences in `src/`, while the redesign adds overlay motion | `src/` (0 hits); prototype `styles.css:264-266` | FE | TASK:UI-PUR-015 |
| GAP-059 | J | MISSING_FRONTEND_CAPABILITY | P3 | confirmed_in_source | invalid fields announced → `aria-invalid` unused; errors passed as visual props only | `src/` (`aria-invalid` 0); `TransactionFormView.vue:388-389` | FE | TASK:UI-PUR-015 |
| GAP-060 | J | — | — | confirmed_in_source | table→cards at ≤640px, 390px floor → correct and test-pinned | `styles/_responsive.scss`; `fr071NarrowViewport.spec.ts:61,98,126`; `RtlAccessibility.spec.ts:140-141` | — | VERIFIED_OK |
| GAP-061 | J | — | — | confirmed_in_source | accessibility baseline present → 61 aria-labels, 39 roles, 9 live regions, keyboard E2E, NC component contract test | `tests/E2E/keyboard-accessibility.spec.ts`; `NextcloudVueContract.spec.ts` | — | VERIFIED_OK (gaps = GAP-058/059) |
| GAP-062 | J | PROTOTYPE_SIMPLIFICATION | P1 | confirmed_in_source | — → prototype declares `aria-modal` with no focus trap, no focus return, no tablist semantics, no Escape while confirming | `app.js:214,233,244,248,351` | FE | TASK:UI-PUR-005 (mandatory acceptance) |
| GAP-064 | K | VERIFIED_DEFECT | P1 | confirmed_in_source | direction follows the user's language (D-006) → `dir="rtl"` hardcoded on 20 view roots | `SheetListView.vue:427,452`; `SheetDetailView.vue:203`; `SheetFormView.vue:170`; +17 | FE | TASK:UI-PUR-001 |
| GAP-065 | K | MISSING_FRONTEND_CAPABILITY | P1 | confirmed_in_source | Persian and English from day one → `l10n/` ships `fa.js`/`fa.json` only; the completeness gate checks one locale | `l10n/`; `src/l10n.ts`; `l10nCatalog.spec.ts` | OWNER+FE | BLOCKED_OWNER_DECISION:D-02 · TASK:UI-PUR-001 (mechanism only) |
| GAP-066 | K | MISSING_FRONTEND_CAPABILITY | P1 | confirmed_in_source | a Gregorian/Jalali display preference independent of language → every site hardcodes `fa-IR`; Jalali exists only as a server-side reporting period | `src/utils/date.ts:36-38`; `src/utils/money.ts:19-25`; +12 sites; `JalaliReportingPeriods.php` (FR-068) | FE | TASK:UI-PUR-001 |
| GAP-067 | K | — | — | confirmed_in_source | conversion is display-only → no conversion utility exists | `JalaliReportingPeriods.php`; prototype `app.js:37-43` | FE | TASK:UI-PUR-001 (hard constraint) |
| GAP-068 | K | PROTOTYPE_SIMPLIFICATION | P1 | confirmed_in_source | — → saving under the Jalali toggle writes a hardcoded `2023-12-27` as the Gregorian date; free-text date field | `app.js:244,290,293,36-43` | FE | TASK:UI-PUR-001 ("do not port") |
| GAP-069 | K | — | — | confirmed_in_source | all purchaser strings catalogued → correct, with three marker strings remaining | `SheetListView.vue:141-157,429-601`; `date.ts:31`; `stores/sheets.ts:34` | FE | TASK:UI-PUR-002/005 (acceptance) |
| GAP-070 | L | PROTOTYPE_SIMPLIFICATION | P2 | confirmed_in_source | — → parallel token system (24 colour tokens, radius, focus ring, font stack) must be re-expressed in Nextcloud tokens | `styles.css:3-47,111,114-118,140,163-169`; D-002; `NextcloudVueContract.spec.ts` | FE | TASK:UI-PUR-005/015 |
| GAP-071 | L | PROTOTYPE_SIMPLIFICATION | P2 | confirmed_in_source | — → vanilla full re-render per keystroke with manual caret restoration; `state.confirm` used but never initialised | `app.js:191,257,115-122,339-346` | FE | NOTED (constraint) |
| GAP-072 | L | PROTOTYPE_SIMPLIFICATION | P3 | confirmed_in_source | — → prototype config references an external CDN logo | `app.config.ts` | — | NOTED (informational) |
| GAP-073 | L | MISSING_FRONTEND_CAPABILITY | P2 | confirmed_in_source | mobile workspace without a nested modal stack → no stack exists today; the production workspace must not create one | prototype `styles.css:234-235`; `app.js:230` | FE | TASK:UI-PUR-005 |

## Summary by owner

Counts over the **49 classified findings** (the 23 verified-control records are excluded).

| Owner | Findings | Of which P1 |
|---|---|---|
| FE (frontend only) | 30 | 10 |
| BE (backend only) | 10 | 3 |
| FE+BE (both) | 1 | 1 |
| OWNER (product decision required) | 2 | 0 |
| OWNER+FE | 2 | 1 |
| none required (prototype/constraint records) | 4 | 0 |

Priority totals across the 49 findings: **P1 = 15 · P2 = 18 · P3 = 16**. By class: MISSING_FRONTEND_CAPABILITY 13 · VERIFIED_DEFECT 10 · PROTOTYPE_SIMPLIFICATION 8 · OPTIONAL_IMPROVEMENT 6 · DESIGN_INCONSISTENCY 6 · SPEC_AMBIGUITY 4 · MISSING_BACKEND_CAPABILITY 2.

## Mapping: finding → task

| Task | Findings addressed |
|---|---|
| `UI-PUR-001` | GAP-064, GAP-065 (mechanism), GAP-066, GAP-067, GAP-068 |
| `UI-PUR-002` | GAP-009, GAP-010, GAP-048, GAP-069 (list half) |
| `UI-PUR-003` | GAP-001 (consumes), GAP-002, GAP-003 |
| `UI-PUR-004` | GAP-017 (state half) |
| `UI-PUR-005` | GAP-017 (shell half), GAP-062, GAP-070, GAP-073 |
| `UI-PUR-006` | GAP-019, GAP-051, GAP-052, GAP-053 |
| `UI-PUR-007` | GAP-011 (frontend half), GAP-012 |
| `UI-PUR-008` | GAP-013 |
| `UI-PUR-009` | GAP-024 |
| `UI-PUR-010` | GAP-025 |
| `UI-PUR-011` | GAP-023 |
| `UI-PUR-012` | GAP-054, GAP-029 |
| `UI-PUR-013` | GAP-028 |
| `UI-PUR-014` | GAP-018, GAP-020 (consumes) |
| `UI-PUR-015` | GAP-058, GAP-059, GAP-070 |
| `UI-PUR-016` | GAP-031, GAP-030 (consumes) |
| `BE-CR-001` | GAP-011 (server half) |
| `BE-CR-002` | GAP-001, GAP-005 |
| `BE-CR-003` | GAP-020 |
| `BE-CR-004` | GAP-030, GAP-032 |
| `BE-CR-005` | GAP-021, GAP-040 |
| `BE-CR-006` | GAP-022 |

## Findings with no proposed task (and why)

| Finding | Reason |
|---|---|
| GAP-004 page size | cosmetic; no independent user outcome |
| GAP-006 date-filter semantics | needs a product decision (D-03); then either a contract change or nothing |
| GAP-007 search | new capability, not required by FR-064; revisit after the list is rebuilt |
| GAP-008 N+1 | performance only; SC-008 explicitly deferred by the spec |
| GAP-014 first-submitted hardening | holds by construction today; changing the domain model is not justified by a hypothetical writer |
| GAP-015 prototype copy | the prototype is not production code; recorded so the copy is not ported |
| GAP-019 virtualization | no measured problem at the observed scale; verified by acceptance instead |
| GAP-036 per-revision evidence | the model is spec-consistent; the prototype's wording is the error |
| GAP-040 terminal invariant | correct today; hardened as part of BE-CR-005 rather than separately |
| GAP-041 dead status values | contract hygiene; needs a change-control decision (D-07), and no purchaser surface depends on it |
| GAP-044 explanation length | UI-only constraint on a reviewer surface (outside purchaser scope) |
| GAP-046 / GAP-047 | constraints for the redesign, encoded as acceptance criteria rather than tickets |
| GAP-050 / GAP-051 | confirmed-good constraints; encoded as "do not invent" rules |
| GAP-056 draft recovery | new capability; explicit non-goal of UI-PUR-012 |
| GAP-057 async-state idiom | too small for an independent outcome; folded into three acceptance criteria |
| GAP-071 / GAP-072 | constraints on implementation style; no user-visible outcome |
| GAP-049 | placeholder row — no finding |

---

*Companion documents: `AUDIT_REPORT.md` (narrative + evidence), `PROPOSED_TASK_BACKLOG.md` (task index), `EXECUTION_PLAN.md` (sequence), `DECISIONS_AND_BLOCKERS.md` (D-01…D-10).*
