# Tasks: Senior Manager Cost Sheet Review (Phase B)

**Input**: Design documents from `/specs/007-senior-manager-cost-sheet-review/` — `spec.md` (user stories + priorities), `plan.md` (tech stack, structure, batches), `data-model.md` (TS interfaces, invariants), `contracts/senior-review-api.md` (additive, not ratified), `research.md`, `quickstart.md`. Constitution: `.specify/memory/constitution.md`.

**Prerequisites**: `spec.md` and `plan.md` (both present). Owner decisions D1–D9 were recorded 2026-11-08; the narrow 001 `FR-037` Senior-queue visibility amendment is **recorded in Feature 001 on 2026-11-08** (`specs/001-pettycash-v1-core/spec.md`, `FR-037`) and is **not re-opened** here. The task-generation workflow identified **no genuine blocking inconsistency**.

**Status**: Implementation ledger. The backend capability batch is recorded as landed and verified in T024–T029; T037 (owner visual acceptance) remains open. This file authorizes no code change by itself. Working-tree changes are preserved; no Git writes are authorized by this ledger.

**Organization**: Tasks are grouped by user story so each story can be implemented and tested independently. The task IDs are the canonical ledger for this feature; check a task only when its stated evidence exists. Tags inside descriptions follow Feature 006 convention: `[BI]` = backend-independent (builds/works on connected data without the backend batch), `[BD←BE1]` = reconnects the Senior sheet-read / sheet-gate backend capability, `[BD←BE2]` = reconnects the Senior XLSX export backend capability.

**Toolbar reconciliation (2026-11-15)**: The owner approved the Senior modal toolbar simplification: transaction search and expense-type filtering only; transaction-date ascending as the default sort; clicking the active criterion toggles direction with no separate direction control; XLSX export beside the modal close button. T003/T004 and T020–T023 are reconciled below; their existing completion evidence is preserved. Mobile UX corrections remain deferred technical debt, and T037 (owner visual acceptance) remains open.

## Format

`- [ ] T### [P] [US#] Description — evidence`

- `[P]` = can run in parallel with other `[P]` tasks in the same phase.
- `[US#]` = the user story this task belongs to (US1–US4). Setup, Foundational, Backend and Polish tasks carry no story label.
- Every task names the exact file path(s) and the evidence that proves it.
- Tests are included because the feature specification and plan explicitly require them (spec §"User Scenarios & Testing", plan §Testing Strategy).

## Phase 1 — Setup (Shared Infrastructure)

**Purpose**: additive catalogue keys and additive data-model types that every user story reads; no behavior change.

- [X] T001 [P] Add the Feature 007 catalogue keys (queue, sheet modal, decision, preview, export, states, unavailable labels) to `l10n/en.json`, `l10n/fa.json`, `l10n/en.js`, `l10n/fa.js`, keeping key order and the JSON/JS parity gate — `tests/Frontend/l10nCatalog.spec.ts` passes. No vendor phrasing is introduced.
- [X] T002 [P] Add the additive Senior TS interfaces to `src/types/api.ts` per `data-model.md` §"Frontend TS interfaces": `SeniorManagerTransactionState` (`'AWAITING_SENIOR_DECISION' | 'DECIDED_BY_SENIOR'`); `SeniorSheetSummary` whose `allTransactionsCostCenterDecided: boolean | null` is documented "Authoritative only when the backend proves the sheet gate; null = cannot prove (this phase)" and `reviewStatus: 'PENDING_REVIEW' | 'REVIEW_COMPLETED'`; `SeniorManagerSheetReview`; `SeniorFilterState`; `SeniorSortKey` ('amount' | 'expenseDate' | 'expenseType') / `SeniorSortDirection` / `SeniorSort`; and the derived `SeniorSheetQueueItem` ("de-duplicated senior-pending transactions", per-currency `totals`, `latestActivityAt`, `costCenters`). Additive only — no existing type is edited.

## Phase 2 — Foundational (Blocking Prerequisites)

**Purpose**: the single shared grouping/completeness/filter/sort derivation and senior queue state every user story builds on. MUST be complete before any user story starts.

- [X] T003 Create `src/composables/useSeniorQueue.ts` extending `src/composables/useSheetQueue.ts` (NEVER re-implemented in parallel): senior grouping/completeness via the shared guard (`checkSheetQueueCompleteness`: `total <= items.length && items.length < 500`), de-duplication by transaction id, `buildSheetQueue`, per-currency totals (amounts group by `currency` + `minorUnit`; never a cross-currency total — 006 `FR-006-014`), and — for the modal — per-transaction filter (query by id/description, `expenseTypeIds`) and sort (amount / expenseDate / expenseType, default expenseDate ascending, click-active toggle, stable tiebreak by id). Currency, amount-range, and attachment filters are not modeled. Pure exported functions, no network calls; no vendor field, filter, or placeholder anywhere (FR-007-015, owner D7) — `npm run typecheck` + targeted Vitest pass.
- [X] T004 [P] Add `tests/Frontend/Composables/SeniorSheetQueue.spec.ts` covering: completeness guard (truncation, `total > returned`, at-threshold); one row per sheet and duplicate ids counted once; blank sheet reference separated; eligibility honesty — no client-derived "all CC decided" claim and no "Waiting for Cost Center Review" surface in any state (FR-007-013); completed-sheet "Review Completed" read-only/non-actionable (owner D5); CC decision rendered per transaction only (filter `stage === 'COST_CENTER'`, never rolled up); filter model limited to search/description/expense-type; no obsolete currency/amount/attachment fields; no-vendor (owner D7); sort model stable; grouping failure → honest unavailable/error with retry, never a partial flat fallback table (owner D8) — targeted Vitest passes.
- [X] T005 [P] Add senior queue state and guarded re-fetch plumbing to `src/stores/reviews.ts` (senior queue page, `seniorSheetReviews` cache keyed by sheet id, per-transaction detail cache/read-state reuse — `ManagerTransactionReadState` loading/loaded/forbidden/notFound/unavailable — and senior export state) and extend `src/services/reviewsApi.ts` (keep `fetchQueue('senior')`; add the guarded `fetchSeniorSheet(sheetId)` helper modeled on `fetchCostCenterSheet` (`reviewsApi.ts:34`) with result classification so unavailable fields render when the backend read is absent `[BD←BE1]`) — `npm run typecheck` + targeted Vitest pass.

**Checkpoint**: Foundation ready — user story implementation can now begin (US1 → US2 → US3 → US4; stories can also proceed in parallel if staffed).

## Phase 3 — User Story 1: A Cost-Sheet-based Senior queue (Priority: P1) 🎯 MVP

**Goal**: A Senior Manager sees sheets grouped from the senior-pending transaction set rather than a flat table; opening a sheet opens a modal with the queue mounted behind it. Entry point of the Senior journey.

**Independent Test**: With a Senior Manager holding at least one assigned cost center and a senior-pending set provably complete in one response, the queue renders one row per sheet with the pending count and per-currency amount, and no transaction row appears outside the manager's cost-center scope; no sheet whose CC review is not final appears. With a set that cannot be proven complete, it renders an honest unavailable/error state with a retry — never a partial flat transaction table (owner D8).

### Implementation for User Story 1

- [X] T006 [US1] Rebuild `src/views/reviews/SeniorQueueView.vue` to the sheet-first presentation: keep the breadcrumb shell and the `review-queue-senior` route (`src/router/index.ts:73`, `/reviews/senior`) unchanged; render one `SeniorSheetQueueItem` row per sheet (reference, senior-pending transaction count, per-currency amount, latest activity, `reviewStatus`), no flat transaction table as the primary surface (FR-007-001). Visual language consistent with `src/views/reviews/CostCenterQueueView.vue` and Pettycash tokens — `@nextcloud/vue` 9.x only, no Vuetify.
- [X] T007 [US1] Add the honest queue states to `SeniorQueueView.vue`: loading (`NcLoadingIcon`), empty (no gate-satisfying sheets), and unavailable/error with a retry action whenever the senior set is not proven complete by the shared completeness guard — never a partial flat fallback table (FR-007-016, owner D8); a sheet whose every senior-pending transaction is decided renders "Review Completed", stays listed, read-only and non-actionable, and cannot be re-decided (FR-007-017, owner D5) — statuses derive only from the loaded response or explicit unavailable (FR-007-011/012).
- [X] T008 [US1] Create `src/components/review/SeniorSheetModal.vue` shell: a sheet-row activation control opens it (no route navigation), the queue remains mounted and inert behind the scrim (single overlay, `role="dialog"`, `aria-modal="true"`, labelled by the sheet identity); it renders the sheet identity header (title/owner/currency/submitted where the backend proves them — otherwise unavailable placeholders, FR-007-014; `[BD←BE1]` reconnects the real fields) and lists the sheet's senior-pending transactions from the proven-complete queue set; close via Escape, scrim (never during a submitting decision), close button, or a decision-toast, restoring focus to the originating sheet row (FR-007-002/009) — `src/components/review/SeniorSheetModal.spec.ts` stage is US2/T015; the shell is covered here by T010.
- [X] T009 [US1] Update the existing `SeniorQueueView` describe block inside `tests/Frontend/Component/ManagementReview.spec.ts` (around lines 531–617) **in place** to the sheet-first presentation; keep every remaining assertion (table/card/pagination/RTL/font/status on the existing fallback paths if retained) — no test deleted or skipped; targeted Vitest passes.
- [X] T010 [US1] Add `tests/Frontend/Component/SeniorReview.spec.ts` US1 component assertions: one sheet row per sheet; per-currency count/amount correctness; gate-satisfying sheets only with no "Waiting for Cost Center Review" label; no transaction row outside the manager's scope; honest unavailable/error + retry on an incomplete set; "Review Completed" read-only and non-actionable; empty state; sheet modal opens over the mounted queue and closes with focus restored to the originating row — targeted Vitest passes.

**Checkpoint**: User Story 1 is fully functional and testable independently (MVP, if approved as the first increment).

## Phase 4 — User Story 2: Sheet modal with quick decision and full detail (Priority: P1)

**Goal**: From a sheet row the Senior Manager opens the modal and records decisions per transaction with quick decision and full detail; CC decisions appear at transaction level only.

**Independent Test**: From a sheet, open its modal, open a transaction's full detail, and record each decision type; verify exactly one transaction decision request is issued, the CC decision remains visible and unmodified, and the card re-renders in its decided state.

### Implementation for User Story 2

- [X] T011 [P] [US2] Wire the Senior variant into the shared card, `src/components/review/QuickReviewCard.vue`: accept `seniorState`/`seniorDecision` and stage-tagged decisions (additive props/types only), reusing the existing card/action-bar semantics; the Cost Center surface is unchanged (FR-007-051) — unit-tested with no fabricated value; existing `QuickReviewCard.spec.ts` assertions preserved.
- [X] T012 [P] [US2] Quick-decision wiring in `SeniorSheetModal.vue`: card action (or drawer footer) opens the shared `QuickDecisionModal.vue` (per-kind copy; `requiresExplanation` = REJECT ‖ RETURN — Approve comment optional, Reject/Return mandatory, 001 `FR-033`, 006 `FR-006-043`); submitting disables controls and blocks scrim close; sends exactly one `POST /transactions/{id}/decisions` (`DecisionWrite { decision, explanation, expectedVersion }`) with the `X-PettyCash-Command-ID` idempotency header (FR-085/FR-084); on success the card re-renders from the server's `result.transaction`, the per-transaction detail cache is cleared as stale, a toast confirms, and the sheet's progress derives from the refetched set (FR-007-021) — no sheet-level decide/decide-all (FR-007-005); queue refetches after a decision.
- [X] T013 [P] [US2] Full-detail wiring: open the shared `TransactionDetailsDrawer.vue` from a modal card one overlay level above the modal (single-overlay, 006 `FR-006-037`), Description + History tabs, read-only fields with unavailable states, and footer "Decide this transaction" controls; CC decisions render per transaction only from `decisions[]` filtered to `stage === 'COST_CENTER'` — never rolled up to a sheet verdict (FR-007-004, 001 `FR-034`); Senior decision history renders just as clearly (FR-007-024).
- [X] T014 [US2] Capability gating and decision error honesty: enable an action only when the guarded detail read grants it explicitly (`decisionAllowed(allowedActions, kind) === true`, 006 `FR-006-039`), never on button presence; render FR-085 `409`/`SUPERSEDED_ATTEMPT`, stale version (`fieldErrors.expectedVersion`), and explanation-less reject/return (`fieldErrors.explanation`) distinctly, and never fabricate a local status after a refusal (FR-007-011) — verified in the review workflow and tests.
- [X] T015 [US2] Add `tests/Frontend/Component/SeniorSheetModal.spec.ts`: US2 component tests for single-decision exactness (exactly one request per action), reject/return-without-comment refusal with no decision issued, approve comment optional, CC decision unchanged and byte-identical after a Senior decision (no-overwrite invariant), decided-state card re-render from the server response, error/refused/superseded rendering, and modal close restoring focus to the originating sheet row — targeted Vitest passes.

**Checkpoint**: User Stories 1 AND 2 work independently.

## Phase 5 — User Story 3: Attachment thumbnails and the preview layer (Priority: P1)

**Goal**: Each modal transaction shows a thumbnail strip of its attachments; a clicked thumbnail opens a separate preview layer (images large, PDFs in the existing viewer, unknown types show metadata + open/download only).

**Independent Test**: From a transaction with several attachments (image, PDF, unknown type), open each thumbnail and verify the correct preview or fallback and the `1 / N` navigation.

### Implementation for User Story 3

- [X] T016 [P] [US3] Add the attachment thumbnail strip per transaction in the modal cards: first two documents plus a `+N more` tile from the guarded detail read's `evidence[]` metadata (reuse `src/components/review/EvidenceCard.vue` / `QuickReviewCard` thumbnail pattern, 006 `FR-006-040`); clicking any thumbnail opens the preview layer at exactly that document (FR-007-031); inactive evidence renders metadata only, no content.
- [X] T017 [P] [US3] Reuse the preview layer verbatim: `src/components/evidence/EvidencePreview.vue` + `src/utils/evidencePreview.ts` inline-safe policy (001 `FR-060`, 005 `FR-005-042`: `application/pdf` → iframe viewer; PNG/JPEG/GIF/WebP/BMP → large image; `text/plain` → text; every other media type → metadata note + open/download only, never inline), Previous/Next + `1 / N` counter disabled at the ends, Escape/scrim close, focus restore, accessible dialog name = file name, object-URL revocation on close/unmount (006 `FR-006-041`); one layer at a time above the modal (FR-007-032) — verify `src/components/evidence/EvidencePreview.vue` is the canonical path reused.
- [X] T018 [US3] Add US3 tests to `tests/Frontend/Component/SeniorSheetModal.spec.ts`: image large preview, PDF in the existing viewer, unknown type metadata + open/download with no inline render, `1 / N` navigation disabling at the ends, empty/one-item/all-unknown/inactive evidence sets — targeted Vitest passes.

**Checkpoint**: User Stories 1–3 work independently.

## Phase 6 — User Story 4: Two-level filter/sort and XLSX export (Priority: P2)

**Goal**: Two-level filtering (owner D2): the queue exposes Cost Sheet search/filter only; the modal exposes transaction search (ID/description) and expense-type filtering, plus sorting. Currency, amount-range, and attachment filters are retired. Export is XLSX only (D3) and contains exactly the filtered + sorted selection in on-screen order with separate Cost Center and Senior decision fields (D4). The modal opens with transaction-date ascending as the default; clicking the active criterion toggles direction with no separate direction control. The XLSX export control sits beside the modal close button. No vendor filter/column/placeholder appears anywhere (D7).

**Independent Test**: Apply a filter and a sort in the modal, then export; verify the artifact contains exactly the filtered + sorted rows in on-screen order with sheet identification, one row per transaction, and both CC and Senior decision fields — or an explicit refusal naming the missing Senior-scoped capability if it is unavailable (FR-007-044).

### Implementation for User Story 4

- [X] T019 [P] [US4] Add the queue-level (sheet-level) search/filter surface to `SeniorQueueView.vue`: Cost Sheet search (sheet reference/title where the backend supplies it) and pending/eligibility-status filter tabs over the proven-complete set — no transaction-level controls are duplicated at the queue (FR-007-041, owner D2); no network request per change; no vendor control of any kind (owner D7).
- [X] T020 [P] [US4] Add the modal transaction filter surface to `SeniorSheetModal.vue` over the proven-complete set: free-text search by transaction id or description (placeholder "Search by transaction ID or description" exactly — no vendor phrasing, FR-007-015/041, owner D7) and expense-type multi-select with derived counts; currency, amount-range, and attachment filters are absent. Local pure-function filters, no per-action network; counts derive from the loaded set.
- [X] T021 [US4] Add the modal sort control: amount (minor units within one currency, then by currency code), transaction date (`activeRevision.expenseDate`, ISO8601 string compare), expense type (name); transaction-date ascending is the default, one criterion is active at a time, clicking the active criterion toggles ascending/descending, switching criteria starts ascending, and there is no separate direction control; stable secondary tiebreak by transaction id (FR-007-042, plan §Sorting Model) — local sort over the proven-complete set; the resulting order feeds both the UI and the export selection/order.
- [X] T022 [US4] Add the XLSX export control to the `SeniorSheetModal.vue` header beside the close button, submitted via `src/services/reportsApi.ts` (`requestExport`/`exportArtifactBlob`) + `src/stores/reports.ts`: the filtered + sorted selection is submitted as exact ordered transaction `ids` in `ExportRequest.ids`; the guarded request renders the server's refusal naming the missing Senior-scoped capability if it is unavailable — never an empty artifact presented as measured, never an owner-scoped fallback (FR-007-044, owner D3/D4). The Senior-scoped row source and `senior-queue` resource are recorded as landed in T026/T028/T029; `[BD←BE2]` denotes the reconnect enabled by that backend batch.
- [X] T023 [US4] Add US4 tests to `tests/Frontend/Composables/SeniorSheetQueue.spec.ts` and `tests/Frontend/Component/SeniorSheetModal.spec.ts`: two-level placement (queue exposes sheet search/filter only; modal owns transaction search/expense-type filter/sort); search/expense-type filter model and absence of currency/amount/attachment controls; no-vendor; default transaction-date ascending and click-to-toggle sort with no separate direction control; sort model incl. filter+sort together; export refusal path renders the capability reason with no artifact presented as measured; export selection/order asserted against the filtered + sorted `ids`; export control grouped beside the close control — targeted Vitest passes.

**Checkpoint**: All user stories now work independently.

## Phase 7 — Backend Capability Batch (separately authorized; blocks every [BD] reconnect) ⚠️

**Purpose**: the three additive backend capability families the plan repeatedly marks Backend-required (plan §Summary/§Missing Capabilities). **This whole phase is gated behind a separate owner backend authorization** (plan §Information Still Required; checks `CHK039`); it is NOT authorized by this ledger alone and MUST NOT start until that authorization is recorded. Backend work is executed only through `scripts/dev/phpunit`, `scripts/dev/occ`, `scripts/dev/opcache-settle`, `scripts/dev/route-settle` and `scripts/dev/route-cache-clear` wrappers (AGENTS.md §5). Until it lands, the frontend renders honest unavailable/refusal states (T007/T008/T022 + `[BD]` tags).

**Status note (2026-11-15)**: The batch is recorded as landed and verified in T024–T029. The gating text above preserves the authorization boundary under which it ran; it does not reopen the backend work or claim owner visual acceptance (T037).

- [X] T024 [P] BE1 — Senior-scoped sheet reads: `ManagementReviewService::getSeniorSheetForManager` + `ReviewController::seniorSheet` with the additive `review#seniorSheet` route in `appinfo/routes.php`; authorization = the acting user's active `SENIOR_MANAGER` cost-center assignments only; unknown/out-of-scope → same `404` (FR-007-056); payload shaped via `SheetPayloadFormatter::seniorSummary`. **Verified**: `scripts/dev/phpunit tests/Integration/Workflow/SeniorManagerSheetReviewTest.php` (8 pass) + the `GET /queues/senior/sheets/{sheetId}` tests in `tests/Contract/Controller/SeniorManagerReviewControllerTest.php`.
- [X] T025 [P] BE1 — Sheet-wide CC-completion gate + completed-sheet status: backend-computed `allTransactionsCostCenterDecided` (`ManagementReviewService::hasFinalCostCenterDecision`, whole-sheet by default, current-revision rule incl. pending-Return-not-final per the recorded 001 `FR-037` amendment); `reviewStatus: 'PENDING_REVIEW' | 'REVIEW_COMPLETED'` (REVIEW_COMPLETED only when `awaitingSenior === 0`), never client-derived (owner D5, FR-007-017). **Verified**: gate sheet-wide/Rejection-satisfies/pending-Return-then-re-decided tests in `SeniorManagerSheetReviewTest` + the sheet-gate assertions in the senior sheet-read contract tests.
- [X] T026 [P] BE1 — Additive contract delta: added `SeniorSheetSummary` (with `allTransactionsCostCenterDecided` gate and `reviewStatus`), `SeniorManagerSheetReview`, the `/queues/senior/sheets/{sheetId}` path, and `senior-queue` in `ExportRequest.resource` to `specs/001-pettycash-v1-core/contracts/ocs-api.yaml`; additive only — the CC decision surface and existing public contracts unchanged (FR-007-051/052). **Verified**: `ExportServiceTest::testTheResourceEnumIsTheContractsSet` now pins the app enum == the contract enum including `senior-queue`.
- [X] T027 BE1 — Backend acceptance tests through `scripts/dev/phpunit`: **Verified** — `tests/Integration/Workflow/SeniorManagerSheetReviewTest.php` (8 tests, 60 assertions: awaiting+gate, unknown/out-of-scope same-null, no-senior-assignment null, another senior's decision undisclosed, decided-by-this-senior + REVIEW_COMPLETED, sheet-wide gate with a CC-200 sibling, CC-rejection satisfies the gate, pending Senior Return fails the gate until corrected + re-decided).
- [X] T028 [P] BE2 — Senior-scoped XLSX row source + contract delta: additive `resource: 'senior-queue'` (xlsx only, enforced in `ExportService::authorization()` before row resolution) via `lib/Application/Reporting/SeniorQueueSelection.php`, `ReportProjection::seniorQueueRow` + `SENIOR_QUEUE_COLUMNS`/`SENIOR_QUEUE_NUMERIC_COLUMNS`, and the `ExportController` senior-queue branch resolving each id in submitted `ids` order with per-id Senior-scope validation before generation (FR-007-056); empty `ids` → header-only XLSX `rowCount: 0`; out-of-scope/unresolvable → `UnsupportedSelection` 422 with no artifact; existing owner-scoped row sources not widened. **Verified**: `tests/Contract/Controller/SeniorManagerReviewControllerTest.php` export tests.
- [X] T029 BE2 — Export acceptance tests through `scripts/dev/phpunit`: **Verified** — `tests/Contract/Controller/SeniorManagerReviewControllerTest.php` export tests pass over the live endpoint: xlsx single-id with all declared columns; `ids`-order row resolution + numeric-typed `amountMinor` cells (finance state, two CC-100 rows); non-xlsx (`csv`/`pdf`) refused 422 with no artifact; empty selection measured-zero header-only; out-of-scope/unknown id refused via `UnsupportedSelection` identically with no artifact. (Same fixture user holds `SENIOR_MANAGER` for CC-100 in every state.)

**Checkpoint**: When the backend batch lands (and only after the separate authorization is recorded), all `[BD←BE1]`/`[BD←BE2]` reconnects become active: sheet header/eligibility fields (T008/T012/T013) and the real XLSX artifact (T022).

## Phase 8 — Polish & Cross-Cutting Concerns

**Purpose**: cross-story quality, visual evidence, and the owner approval gate.

- [X] T030 Mobile/390px + RTL + accessibility: full-screen overlays one at a time (006 `FR-006-037`), `>=44px` touch targets, focus restore, accessible names/labels, Latin isolates (`bdi`), locale digits/dates/amounts via existing utilities (`formatMoney`/`formatSheetDate`/`formatNumber`), Gregorian/Jalali independent preference, light/dark/forced-colours spot-check, no horizontal scroll at 390px in English LTR and Persian RTL (FR-007-008). **Verified**: fresh audit `artifacts/visual-review/T030/001/record.json` (24/24 checks; 6 touch targets all 44px including sheet-row open + modal close; focus restore to `open-sheet-btn`; `bdi dir=ltr`; no horizontal scroll incl. forced-colours/dark; 0 console/page/failed-request errors) via `tests/E2E/t030-mobile-ltr-accessibility-evidence.mjs`; two real 34px touch targets fixed this session (`SeniorQueueView.vue` row-action floor via `:deep` on `senior-queue-cell-actions`/`senior-queue-card-field-actions`; `SeniorSheetModal.vue` `senior-modal__close` floor) and focus restore updated for the 390px card layout.
- [X] T031 Scope/honesty audit: grep-verify no vendor filter, column, search placeholder, or disabled vendor control appears anywhere in `SeniorQueueView.vue`/`SeniorSheetModal.vue`/`l10n/*` additions (FR-007-015, owner D7) and no client-derived sheet-wide CC-completion or Senior-eligibility claim exists without the backend gate flag (FR-007-013); the l10n additions contain no vendor phrasing. **Verified**: grep audit run; matches are rule-comment prose and a pre-existing 006 `quickReview.drawer.merchant` key; placeholders are exactly "Search by sheet reference" / "Search by transaction ID or description"; `reviewStatus` derives only from the server response; no client-derived eligibility claim (recorded in `docs/reports/007-senior-manager-cost-sheet-review.md`).
- [X] T032 Run the minimal frontend gates and fix only regressions caused by this feature: `npm run typecheck`, targeted Vitest, targeted ESLint/Stylelint — all pass; `npm run check` at the milestone boundary (constitution §V/§VII — a green run is not owner approval). **Verified**: after the T030 source fixes, `npm run check` exit 0 (`/tmp/opencode/check-t030.log`: openapi/portability/error-rate/typecheck/lint/stylelint/814 Vitest/build/notices all OK); targeted Vitest 86/86 across `SeniorReview`, `SeniorSheetModal`, `QuickReviewAccessibilityConformance`, `ManagementReview`.
- [X] T033 Build and deploy into the running `pettycash-lab` Nextcloud (`npm run build`) — app loads at `http://localhost:8081` without a build error; route cache/opcode handling only if any `appinfo/` PHP change was authorized (none in the frontend phases). **Verified**: `npm run build` succeeds; repo is bind-mounted into the lab and the served Senior queue CSS carries the current 44px rules; served build md5 == tree md5 (established T033 and preserved); app logs-in and renders (proven by T030 audit + page load).
- [X] T034 Capture desktop evidence (1440×900, English LTR) with official Google Chrome only (Playwright `chrome` channel): queue, sheet modal, decision modal, details drawer, preview, filtering/sorting, export refusal — stored under `artifacts/visual-review/T034/001/` (Git-ignored per constitution §VIII). **Verified** (preserved, not re-run): `artifacts/visual-review/T034/001/record.json` 28/28 checks, 14 screenshots, 73 queue sheets, real senior-scoped XLSX (6514 B), full-screen overlay geometry, 0 console/page/failed-request errors.
- [X] T035 Capture mobile evidence (390×844, Persian RTL) with official Google Chrome only: queue + modal + full-screen overlays, no horizontal scroll (`scrollWidth <= clientWidth`), dark-theme spot-check — `artifacts/visual-review/T035/001/`; honesty assertions (no eligibility claim, no fabricated amounts) per plan §Visual Regression Strategy. **Verified** (preserved, not re-run): `artifacts/visual-review/T035/001/record.json` 28/28 checks, 14 screenshots, `dir="rtl"`, full-screen overlays, `"50,000 IRR"` formatted amount, 0 errors.
- [X] T036 Compare fresh screenshots to the `CostCenterQueueView.vue` visual baseline; record matches, deliberate deviations and unavailable fields (incl. which backend capabilities have landed) in the delivery report — the comparison section. **Verified**: comparison recorded in `docs/reports/007-senior-manager-cost-sheet-review.md` (matches: shell/tokens, two-level placement, ResponsiveTable, RTL/LTR + no-scroll, 44px policy, honesty placeholders, `bdi`; deviations: modal-overlay vs inline accordion, no summary cards, dedicated row action button, landed senior XLSX export, T030 touch-target/focus polish; landed backend: BE1 senior sheet reads + gate + `reviewStatus`, BE2 `senior-queue` XLSX). Pixel comparison not performed — text-only session; stated exactly in the report and deferred to the T037 gate.
- [ ] T037 Stop for owner visual approval and report the review URL, screenshots (repository-relative paths), supported/unavailable functionality, Spec Kit progress and blockers — the Feature 007 visual approval gate (constitution §VII; 006 `FR-006-025`). No further phase starts before a recorded owner approval; a screen without recorded approval is never reported as accepted (FR-007-055).

**Deferred technical debt (2026-11-15)**: Mobile-specific UX corrections for the reconciled modal toolbar remain deferred. T030/T035 records above are preserved as their original mobile/RTL/accessibility audit evidence; this reconciliation does not claim the deferred corrections or complete T037.

## Dependencies & Execution Order

### Phase dependencies

- Setup (Phase 1) → Foundational (Phase 2) → User stories (Phases 3–6) → Polish (Phase 8).
- Phase 2 BLOCKS all user stories. Backend batch (Phase 7) is separately authorized and blocks the `[BD←BE1]`/`[BD←BE2]` reconnects only; it does NOT block the `[BI]` frontend core.
- User stories run in priority order (US1 → US2 → US3 → US4) or in parallel when staffed; each must remain independently testable.

### User story dependencies

- **US1 (P1)**: T001–T005 → T006/T007; T006/T007 → T008 → T009/T010. No cross-story prerequisites beyond Phase 2.
- **US2 (P1)**: T001–T005 → T011/T012/T013 → T014 → T015. Reuses US1's modal shell (T008); independent of US3/US4.
- **US3 (P1)**: T001–T005 → T016/T017 → T018. Depends on US2's modal card surface (T012/T013) for the strip; no other preconditions.
- **US4 (P2)**: T001–T005 → T019/T020/T021 → T022 → T023. Depends on US2 (modal) and US3 (cards); the export control works (refusal path) without the backend batch.

### Within each user story

- Models/types before services; services before endpoints; core surface before integration.
- Feature 007 tests are written and must be green for the task's stated evidence; existing coverage is updated in place, none deleted or skipped.

### Parallel opportunities

- All `[P]` tasks marked in Phases 1–2 and within a story run in parallel (different files).
- US1/US2 core shares the modal shell (T008): if staffed separately, sequence US1 then US2; otherwise T008 is the hand-off point.
- BE1 tasks (T024/T025/T026) and BE2 tasks (T028/T029) are parallel within the backend batch; T027 follows T024/T025 and T029 follows T028.
- Full stories can proceed in parallel per the Implementation Strategy.

## Parallel Example: Modal review (US2 + US3)

```bash
# Launch the US2 reusable wiring together (different components):
Task: "T011 — QuickReviewCard Senior variant"
Task: "T012 — QuickDecisionModal quick-decision wiring"
Task: "T013 — TransactionDetailsDrawer full-detail wiring"

# Launch the US3 preview surface together:
Task: "T016 — attachment thumbnail strip"
Task: "T017 — EvidencePreview preview-layer reuse"
```

## Implementation Strategy

### MVP First (User Story 1 only)

1. Complete Phase 1 (Setup) + Phase 2 (Foundational).
2. Complete Phase 3 (US1) — sheet-first queue + honest states + modal shell.
3. **STOP and VALIDATE**: test US1 independently (T009/T010), then the recorded owner approval gate (T037 semantics apply to the MVP pass too).
4. Only then continue to US2 → US3 → US4.

### Incremental Delivery

1. Setup + Foundational → foundation ready.
2. Add US1 → test independently → owner gate → next.
3. Add US2, US3 (P1 decision surface) → test → owner gate.
4. Add US4 (P2 filter/sort + export control with refusal) → test → owner gate.
5. Backend batch (Phase 7) only after the separate authorization is recorded; reconnects land as `BE1`/`BE2` verify without changing the information architecture.

### Parallel team strategy

With multiple developers: team completes Setup + Foundational together; then Developer A: US1, Developer B: US2/US3, Developer C: US4; stories complete and integrate independently; the backend batch is a separately owned, separately authorized workstream.

## Notes

- `[P]` tasks = different files, no dependencies. `[US#]` maps the task to its user story. Backend/POLISH tasks carry no story label.
- Each user story is independently completable and testable; verify the required tests pass before moving on.
- The backend batch (Phase 7) is gated by the separate owner authorization; starting it without that recorded authorization is out of scope for this ledger.
- Working-tree user changes and untracked files are preserved; no Git stage/commit/push/reset/clean is authorized by this ledger.
- Visual evidence uses official Google Chrome only; a missing Chrome installation is a reported blocker, never a substitute engine (constitution §III/§V).
- Avoid: vague tasks, same-file conflicts, cross-story dependencies that break independence, reintroducing vendor phrasing (owner D7), and any change to the Cost Center Manager workflow (FR-007-051).

## Out of Scope (do not start)

- Anything in `specs/006-management-review/` (006 `FR-006-027`; FR-007-051/053) and any change to Cost Center Manager behavior or the Finance screens.
- Sheet-level Approve/Reject/Return or "decide all" (FR-007-005); client-derived sheet-wide CC-completion or Senior eligibility (FR-007-013); a "Waiting for Cost Center Review" state (owner D1).
- Existing public contract changes beyond the explicitly additive Senior reads/export under a separate authorization (FR-007-052, plan §Missing Capabilities).
- Vendor/merchant master data and any vendor filter/column/search placeholder/disabled control (owner D7).
- New dependencies, new workflow states, or changes to domain decision semantics (`Decision`, `WorkflowStage`, FR-033/035/036/037 stage-transition semantics).
