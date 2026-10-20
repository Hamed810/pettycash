# Tasks: Purchaser UI Redesign

**Input**: Design documents from `/specs/005-purchaser-ui-redesign/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/purchaser-ui-contract.md`, `quickstart.md`

**Status of this ledger**: **canonical**. It is the single execution-status source for this feature (`research.md` Decision 2, `contracts/purchaser-ui-contract.md` §3 rule 2). The DeepSeek audit package and the Batch A report are **evidence**, not parallel status sources.

## Owner directive (2026-10-20)

Implement the approved Purchaser frontend redesign from `specs/005-purchaser-ui-redesign` in the real Nextcloud app, continuing through the existing plan and cards, and verify it in the running lab. This authorizes the **frontend** cards recorded below only. Backend changes (`lib/`, `appinfo/`, API/contracts) and **all Git writes** remain blocked; the English catalog remains deferred (`REC-01`); no visible calendar toggle is authorized (`FR-005-004` / T017).

**Tests**: Included. Repository constitution Principle V requires automated coverage for UI behavior; each implementation task names its affected specs. The repository runs targeted tests during implementation and aggregate gates at batch boundaries.

**Organization**: Grouped by phase. Every imported card keeps its original ID; a task that belongs to a card names it inline.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallelizable (different files, no dependencies)
- **[Story]**: US1–US5 from `spec.md`
- Status line format: `Status: <vocabulary value> · Gate: <exact unblocking condition>`
- Status vocabulary is closed to the six values in `research.md` Decision 4.

---

## Phase 1: Setup — management artifacts

**Purpose**: Create the Spec Kit management baseline for the redesign. No application source is touched.

- [x] T001 Create `specs/005-purchaser-ui-redesign/spec.md` — 5 user stories, `FR-005-001…034`, 10 success criteria, edge cases, blocked journeys, 22-card traceability matrix.
      Status: DONE_VERIFIED · Evidence: file present.
- [x] T002 [P] Create `specs/005-purchaser-ui-redesign/research.md` — management decisions 1–10 (identity, traceability, vocabulary, blocked discipline, evidence tiers, governance policy, Git boundary, ledger layout, completion meaning).
      Status: DONE_VERIFIED · Evidence: file present.
- [x] T003 [P] Create `specs/005-purchaser-ui-redesign/plan.md` — technical context, constitution check (I–V), structure, phases/gates G-1…G-9, risks R1–R9, rollback.
      Status: DONE_VERIFIED · Evidence: file present.
- [x] T004 [P] Create `specs/005-purchaser-ui-redesign/data-model.md` — presentation/management entities; no new persisted entity; domain relationship table.
      Status: DONE_VERIFIED · Evidence: file present.
- [x] T005 [P] Create `specs/005-purchaser-ui-redesign/contracts/purchaser-ui-contract.md` — C-01…C-12 presentation contract, stack contract, management contract, guard inventory.
      Status: DONE_VERIFIED · Evidence: file present.
- [x] T006 [P] Create `specs/005-purchaser-ui-redesign/quickstart.md` — automated + manual validation, prohibitions, completion-claim rules.
      Status: DONE_VERIFIED · Evidence: file present.
- [x] T007 [P] Create `specs/005-purchaser-ui-redesign/checklists/requirements.md` — requirements-quality review incl. traceability, blocked-item, and evidence discipline items.
      Status: DONE_VERIFIED · Evidence: file present.
- [x] T008 Point `.specify/feature.json` at `specs/005-purchaser-ui-redesign` (was `specs/004-in-app-navigation`). This file is **gitignored** by `.specify/.gitignore` (machine-local state) and is therefore not a commit candidate.
      Status: DONE_VERIFIED · Evidence: `cat .specify/feature.json`.

---

## Phase 2: Foundational — ledger, governance reconciliation, evidence baseline

**Purpose**: Establish the canonical ledger, reconcile the four governance documents with evidence, and record the re-executed verification baseline. All later phases depend on this.

- [x] T009 Create this ledger `specs/005-purchaser-ui-redesign/tasks.md` (`T001…T052`), mapping every imported card to tasks, requirement IDs, status, and gate (`SC-005-001`).
      Status: DONE_VERIFIED · Evidence: this file.
- [x] T010 Update `docs/frontend-redesign/CHARTER.md` with an evidence-only execution addendum (no principle rewritten, no gate relaxed).
      Status: DONE_VERIFIED · Evidence: file edit.
- [x] T011 Update `docs/frontend-redesign/DECISIONS.md` with new entries; unresolved owner items recorded as `Proposed`/`Open`, never `Accepted`.
      Status: DONE_VERIFIED · Evidence: file edit.
- [x] T012 Update `docs/frontend-redesign/STATUS.md` — replace the obsolete F1 state with the evidence-backed current state (Batch A partial, Batch B not authorized at that time, BE-CRs blocked, Git unwritten).
      Status: DONE_VERIFIED · Evidence: file edit.
- [x] T013 Update `docs/frontend-redesign/ROADMAP.md` — phase statuses and the redesign's sub-track positions updated to evidence; nothing marked accepted without owner input.
      Status: DONE_VERIFIED · Evidence: file edit.
- [x] T014 Create `docs/reports/005-purchaser-ui-redesign.md` — migration report with evidence tiers and the Git plan scope.
      Status: DONE_VERIFIED · Evidence: file present.
- [x] T015 Re-verify the Batch A surface and run a spec/plan/tasks/governance consistency check.
      Status: DONE_VERIFIED · Evidence: `npx vitest run tests/Frontend/localeFormatting.spec.ts tests/Frontend/Component/PurchaserFlow.spec.ts tests/Frontend/Component/SheetDeletion.spec.ts tests/Frontend/Component/RtlAccessibility.spec.ts` → 4 files / 168 tests passed (2026-10-19).

**Checkpoint**: the ledger exists, the governance files no longer state a false status, and the re-executed evidence tier is recorded.

---

## Phase 3: Batch A closure — `UI-PUR-001` (US1)

**Goal**: Locale/calendar/direction foundation is complete to its authorized scope, with its two named residuals closed on owner authorization.

- [x] T016 [US1] **`UI-PUR-001`** — mechanism reconciliation: `direction.ts`, `locale.ts`, `calendar.ts`, `date.ts`, `number.ts`, `money.ts` exist; direction derived once; helper-driven formatting; no stored value read by a formatter.
      Status: DONE_VERIFIED (PARTIAL card) · Evidence: `localeFormatting.spec.ts` 13 tests; source verified.
- [ ] T017 [US1] **`UI-PUR-001` AC2** — decide and, if authorized, expose a live calendar-change surface without reload or network request.
      Status: NOT_STARTED · Gate: G-10 (`P-008` in `DECISIONS.md`) + G-8 — product-owner authorization (no visible toggle is authorized today; `FR-005-004`).
- [ ] T018 [US1] **`UI-PUR-001` AC5** — mutating negative test: submit the same form under both preferences and diff the payloads; requires the live lab.
      Status: NOT_STARTED · Gate: lab availability; card AC5.
- [x] T019 [US1] **`UI-PUR-001`** — absence/edge contract: missing renders `—`, unparsable renders the raw value, locale-accurate digits, cross-combinations (en+Persian, fa+Gregorian).
      Status: DONE_VERIFIED · Evidence: `localeFormatting.spec.ts` cases (absence, raw value, digits, cross-combinations).
- [ ] T020 [US1] **`UI-PUR-001`** — card closure: mark the card complete once T017 (authorized or explicitly declined) and T018 pass, and owner visual acceptance is recorded.
      Status: NOT_STARTED · Gate: T017 + T018 + G-8.

---

## Phase 4: Batch A closure — list, menu, context

**Goal**: The six-column list, the row action menu, and the list context are complete to their authorized scope; residuals named.

- [x] T021 [US2] **`UI-PUR-002`** — six columns in accepted order, title above ID, `totals.current` / `totals.effectiveApproved`, `—` vs `0`, zero-transaction `0`, FR-062 figures with own labels/cells, catalogued status labels, `AsyncState`.
      Status: DONE_VERIFIED (PARTIAL card) · Evidence: `PurchaserFlow.spec.ts` (137 tests) + Batch A report browser checks.
- [x] T022 [US2] **`UI-PUR-002` / `REC-06`** — date-filter labels must name the sheet **creation** date (today generic `sheets.filter.from` / `.to`).
      Status: DONE_VERIFIED · Evidence: `SheetListView.vue` uses `sheets.filter.createdFrom` / `.createdTo` and `sheet-filter-*-hint`; live fa browser shows «ایجادشده از تاریخ / تا تاریخ»; calendar check shows `۲۰۲۶/۱۰/۲۰` (fa+Gregorian) vs `۱۴۰۵/۷/۲۸` (fa+Jalali); `l10nCatalog` and `PurchaserFlow` green. Owner directive 2026-10-20.
- [ ] T023 [US2] **`UI-PUR-002`** — card closure: English labels from a catalog (`REC-01`) and product-owner visual acceptance.
      Status: BLOCKED_PENDING_OWNER_APPROVAL · Gate: G-1 (`REC-01` sourcing) + G-8.
- [x] T024 [US2] **`UI-PUR-008`** — one status-gated overflow trigger per row, accessible name containing the sheet title, confirmation dialog, cancel-without-request, keyboard path.
      Status: DONE_VERIFIED · Evidence: `PurchaserFlow.spec.ts`, `SheetDeletion.spec.ts` (9 tests) + Batch A report browser checks.
- [x] T025 [US2] **`UI-PUR-004`** — page/filter/scroll restoration, fresh-entry reset, refetch on return, last-page clamp, scroll-container resolution.
      Status: DONE_VERIFIED · Evidence: `PurchaserFlow.spec.ts` + Batch A report browser checks.

**Checkpoint**: Batch A closure status is honest; `UI-PUR-001`/`002` remain PARTIAL with T017/T018/T023 outstanding; T022 is closed.

---

## Phase 5: Owner-gate actions (no code)

- [x] T026 Prepare and submit the **`REC-01`** decision request (English catalog sourcing: author `l10n/en.*` vs defer English review to static references), including the measured English raw-key layout consequence.
      Status: DONE_VERIFIED (request delivered) · Evidence: `docs/reports/005-purchaser-ui-redesign.md` Batch B §English; live `en` desktop/mobile shows raw keys, `dir=ltr`, no horizontal overflow. The owner decision `P-005` remains **Open**; T023 stays blocked.
- [x] T027 Submit the **Batch B scope request** and record the owner response: the 2026-10-20 directive authorizes the Purchaser **frontend** cards T029–T038 plus T022. Backend cards and Git writes remain blocked.
      Status: DONE_VERIFIED · Evidence: owner directive recorded in this ledger and the report; `DECISIONS.md` `D-021`; `P-006` frontend scope **Accepted 2026-10-20**.

---

## Phase 6: Correctness cards and backend-gated cards (US2/US4/US5)

**Goal**: Implement the small, self-contained correctness fixes and the server-tier readiness surface under the 2026-10-20 frontend directive; keep every backend-dependent card blocked.

- [ ] T028 [US2] **`UI-PUR-003`** — sort control + server-driven OPEN-first ordering across pages.
      Status: BLOCKED_BY_BACKEND · Gate: G-4 — `BE-CR-002` approved **and** intra-OPEN order + tie-breaker answered (T042). No client-side sort of the loaded page may be added first (`FR-005-012`).
- [x] T029 [US4] **`UI-PUR-009`** — cost-center preselect from the most recently saved transaction's `activeRevision.createdAt`, only if still active; never array order or expense date; never overwrite an existing transaction's own center.
      Status: DONE_VERIFIED · Evidence: `PurchaserFlow.spec.ts` — preselect, retired-center blank, save-time tie ambiguous, never overwrite; live frontend build. Owner directive 2026-10-20.
- [x] T030 [US4] **`UI-PUR-010`** — no file input/attach control on the create route (+ catalogued hint); uploader kept on the edit route for DRAFT/awaiting-correction; empty-ID uploader fails closed.
      Status: DONE_VERIFIED · Evidence: `PurchaserFlow.spec.ts` — create route has no file control, only the save-first hint; uploader fails closed with an empty ID; edit route retained. Owner directive 2026-10-20.
- [x] T031 [US4] **`UI-PUR-011`** — DRAFT-only delete with confirmation naming the transaction; cancel sends nothing; confirm sends one `DELETE` with `expectedVersion` (FR-025).
      Status: DONE_VERIFIED · Evidence: `PurchaserFlow.spec.ts` — confirmation names the transaction, cancel sends nothing, confirm sends one versioned DELETE; non-DRAFT has no delete control. Owner directive 2026-10-20.
- [x] T032 [US4] **`UI-PUR-012`** — approved save/discard/stay guard on both editable forms (cancel, Escape, in-app navigation, browser back, tab close/reload) + 409 refetch-with-no-merge.
      Status: DONE_VERIFIED · Evidence: `UnsavedChangesGuard.spec.ts` (10 tests) + `PurchaserFlow` guard/409 cases; Save/Discard/Stay, Escape, `beforeunload`, route guard, 409 refetch without merge. Owner directive 2026-10-20.
- [x] T033 [US4] **`UI-PUR-013`** — canonical correction path (PATCH flow per `REC-04`); non-zero `expectedVersion`; dead wrapper that omits it removed or repaired; prior revision retained.
      Status: DONE_VERIFIED · Evidence: `TransactionCorrection.spec.ts` (18 tests) + `PurchaserFlow` PATCH/live-version case; no second correction draft. Owner directive 2026-10-20.
- [x] T034 [US5] **`UI-PUR-016`** — submission-readiness surface, **server tier only** (description, cost center, expense type, ≥1 transaction); evidence shown as `unknown`; Submit reachable; 422 as backstop.
      Status: DONE_VERIFIED (server tier) · Evidence: `SheetWorkspace.spec.ts` readiness cases and live workspace; the evidence tier remains BLOCKED on G-6 (`BE-CR-004`). Owner directive 2026-10-20.

---

## Phase 7: Workspace and remaining surfaces (Batch B — AUTHORIZED 2026-10-20)

**Goal**: The workspace, its tabs, accessibility parity, and the two backend-dependent surfaces. T035–T038 are implemented and verified under the 2026-10-20 directive; T039/T040 remain backend-blocked.

- [x] T035 [US3] **`UI-PUR-005`** — sheet workspace overlay: dialog semantics, focus entry/containment/return, Escape with the guard, Overview/Approvals/History tablist, ≤640px full-screen with no nested dialog, addressable `sheet-detail` route.
      Status: DONE_VERIFIED · Evidence: `SheetWorkspace.spec.ts`; live fa RTL overlay shows `role="dialog"`, focus return to `data-sheet-open`, 4 tabs, 3 approval stages, 390×844 full-screen, zero console/page errors. Owner directive 2026-10-20.
- [x] T036 [US3] **`UI-PUR-006`** — workspace transaction table: render from the server payload; row actions a subset of the server capability set; no purchaser edit for submitted transactions; never render the two unreachable statuses.
      Status: DONE_VERIFIED · Evidence: `SheetWorkspace.spec.ts`; live workspace table fits the dialog content with 9 columns, localized `StatusBadge`, and `—` for unreachable statuses; no horizontal scroll. Owner directive 2026-10-20.
- [x] T037 [US3] **`UI-PUR-006`** — History tab: per-transaction aggregation, decision value from the `after` payload, never the literal `DECISION`, honest paging, visible non-sheet-wide disclaimer.
      Status: DONE_VERIFIED · Evidence: `SheetWorkspace.spec.ts`; live history shows 3 events and the non-sheet-wide disclaimer; decisions read from `AuditEvent.after.decision`. Owner directive 2026-10-20.
- [x] T038 [US5] **`UI-PUR-015`** — accessibility/theming parity on the shipped surfaces: reduced motion, `aria-invalid` + resolving `aria-describedby`, theme-variable-only colours, visible focus in both themes, no hardcoded hex/radius/font.
      Status: DONE_VERIFIED · Evidence: `RtlAccessibility`, `typography`, `fr071NarrowViewport`, and live focus/RTL/mobile checks; shipped surfaces use theme variables only. Owner visual acceptance remains T051. Owner directive 2026-10-20.
- [ ] T039 [US3] **`UI-PUR-007`** — inline title/account edit while OPEN: never guess the account; account-changing half blocked.
      Status: BLOCKED_BY_BACKEND · Gate: G-3 — `BE-CR-001` approved with the currency rule and permission reading answered (T041).
- [ ] T040 [US3] **`UI-PUR-014`** — per-stage counts and amounts in the workspace, from a server aggregation only; no client-side derivation.
      Status: BLOCKED_BY_BACKEND · Gate: G-5 — `BE-CR-003` approved with `REC-03` semantics stated (T043).

**Checkpoint**: T035–T038 are implemented and verified; T039/T040 remain blocked on G-3/G-5; owner visual acceptance (T051) is still open.

---

## Phase 8: Backend change requests (ALL BLOCKED)

**Purpose**: Keep the six requests visible, exact, and unapproved. No task here may be implemented, and no specification or contract may be edited to unblock one.

- [ ] T041 **`BE-CR-001`** — expose the sheet's `accountId` and/or treat an absent `accountId` on update as "unchanged"; validate the adopted account (owned, active, currency rule). Endpoints: `GET /sheets`, `GET /sheets/{id}`, `PATCH /sheets/{id}`; `SheetController`, `SheetService`, `SheetPayloadFormatter`.
      Status: BLOCKED_PENDING_OWNER_APPROVAL · Gate: G-3 + open answers: currency rule for a differently denominated account; confirm the permission reading (owns sheet **and** target account, account active).
- [ ] T042 **`BE-CR-002`** — deterministic server ordering: OPEN-first always, then the requested direction on First Submitted, valid across pages, with a documented tie-breaker. Endpoint: `GET /sheets`.
      Status: BLOCKED_PENDING_OWNER_APPROVAL · Gate: G-4 + open answers: intra-OPEN ordering (open sheets have no first-submission timestamp); tie-breaker when several sheets share a First-Submitted instant.
- [ ] T043 **`BE-CR-003`** — per-stage decision counts **and** amounts (Approved/Rejected/Pending, Returned when applicable) in the sheet payload; an aggregate + payload change, not new persistence.
      Status: BLOCKED_PENDING_OWNER_APPROVAL · Gate: G-5 + open answers: confirm `REC-03` (active-revision semantics; a superseded Return disappears; a resubmitted transaction re-enters as Pending at Cost Center) and the payload shape.
- [ ] T044 **`BE-CR-004`** — one consistent evidence policy at submit and resubmit (FR-023 currently never enforced; resubmit inverted).
      Status: BLOCKED_PENDING_OWNER_APPROVAL · Gate: G-6 + open answer: is FR-023's evidence rule in the current release scope at all?
- [ ] T045 **`BE-CR-005`** — keep the sheet's status a live-derived projection instead of recomputing it only at finance settlement (no payload change).
      Status: BLOCKED_PENDING_OWNER_APPROVAL · Gate: G-7 + open answer: lock-version behaviour on recomputation. The frontend MUST NOT re-derive the rule.
- [ ] T046 **`BE-CR-006`** — refuse a review decision recorded against a transaction awaiting purchaser correction (lowest priority).
      Status: BLOCKED_PENDING_OWNER_APPROVAL · Gate: G-7 + open answer: is such a decision ever legitimate?

---

## Phase 9: Verification and polish gates

- [x] T047 Run targeted frontend verification for the Batch A surface on the current tree.
      Status: DONE_VERIFIED · Evidence: 4 files / 168 tests passed (2026-10-19).
- [x] T048 Run the aggregate frontend gate (`npm run check`) at the next batch or milestone boundary.
      Status: DONE_VERIFIED · Evidence: `npm run check` passed on rerun (portability, typecheck, lint, stylelint, full suite 524/524, build, notices). A first run hit two 5s `PurchaserFlow` timeouts under full-suite load; the targeted `PurchaserFlow` run passed 150/150 and the rerun passed.
- [ ] T049 Run the PHP suites (`scripts/dev/phpunit`) if and only if a future approved change touches `lib/` or `appinfo/`.
      Status: NOT_STARTED · Gate: a PHP-touching approved change. None exists; `git status` shows no `*.php` entry.
- [x] T050 Obtain browser/Playwright evidence for the next authorized implementation slice (including the lab prerequisites recorded in the Batch A report §9 item 6).
      Status: DONE_VERIFIED · Evidence: live `fa` RTL desktop/mobile and `en` LTR desktop/mobile checks at `http://localhost:8081`, plus the calendar cross-check; screenshots in `/tmp/opencode`; zero console/page errors and no horizontal overflow.
- [ ] T051 Record product-owner acceptance per completed visual card in this ledger and in the completion report.
      Status: NOT_STARTED · Gate: G-8 — explicit owner statement per card.
- [ ] T052 Final traceability audit: 22/22 cards mapped, statuses match evidence, no unmapped task (`SC-005-001`).
      Status: NOT_STARTED · Gate: after all owner gates close; the mapping itself is present today.

---

## Dependencies & Execution Order

### Phase dependencies

- Phase 1 → Phase 2 → (Phase 3–4 closure recorded) → Phase 5 gates.
- Phase 6 frontend tasks were authorized on 2026-10-20; the backend cards remain blocked.
- Phase 7 frontend T035–T038 were authorized and completed on 2026-10-20; T039/T040 remain blocked behind Phase 8.
- Phase 9 runs at boundaries; T052 runs last.

### File-collision map (from the audit's execution plan — kept because it prevents re-review)

| File | Tasks that modify it |
|---|---|
| `src/views/sheets/SheetListView.vue` | T021 (`002`), T022 (`002`), T028 (`003`), T025 (`004`), T024 (`008`) |
| `src/views/sheets/SheetDetailView.vue` | T035 (`005`), T036/T037 (`006`), T039 (`007`), T040 (`014`), T034 (`016`) |
| `src/views/transactions/TransactionFormView.vue` | T029 (`009`), T030 (`010`), T031 (`011`), T032 (`012`), T033 (`013`) |
| `src/views/sheets/SheetFormView.vue` | T039 (`007`), T032 (`012`) |
| `src/router/index.ts` | T025 (`004`), T035 (`005`), T032 (`012`) |
| `src/stores/sheets.ts` | T028 (`003`), T025 (`004`), T034 (`016`) |
| `src/utils/date.ts`, `money.ts` | T016 (`001`) |
| `src/styles/*` | T016 (`001`, direction), T038 (`015`, tokens/motion) |

### Within each task

- Tests first where the task changes behavior; the affected existing specs must stay green.
- No `npm install`; no new dependency; no container-side frontend tooling; no host PHP.
- `git status --short` enumerated in every completion report.

---

## Traceability summary (22/22)

| Card | Tasks | Current status | Gate |
|---|---|---|---|
| `UI-PUR-001` | T016–T020 | PARTIAL | T017/T018 + G-1 + G-8 |
| `UI-PUR-002` | T021–T023 | PARTIAL | T023 + G-1 + G-8 (T022 done) |
| `UI-PUR-003` | T028, T042 | BLOCKED_BY_BACKEND | G-4 |
| `UI-PUR-004` | T025 | DONE_VERIFIED | — |
| `UI-PUR-005` | T035 | DONE_VERIFIED | T051 owner acceptance |
| `UI-PUR-006` | T036–T037 | DONE_VERIFIED | T051 owner acceptance |
| `UI-PUR-007` | T039, T041 | BLOCKED_BY_BACKEND | G-3 |
| `UI-PUR-008` | T024 | DONE_VERIFIED | — |
| `UI-PUR-009` | T029 | DONE_VERIFIED | T051 owner acceptance |
| `UI-PUR-010` | T030 | DONE_VERIFIED | T051 owner acceptance |
| `UI-PUR-011` | T031 | DONE_VERIFIED | T051 owner acceptance |
| `UI-PUR-012` | T032 | DONE_VERIFIED | T051 owner acceptance |
| `UI-PUR-013` | T033 | DONE_VERIFIED | T051 owner acceptance |
| `UI-PUR-014` | T040, T043 | BLOCKED_BY_BACKEND | G-5 |
| `UI-PUR-015` | T038 | DONE_VERIFIED | T051 owner acceptance |
| `UI-PUR-016` | T034, T044 | DONE_VERIFIED (server tier) | T044/G-6 evidence tier; T051 |
| `BE-CR-001` | T041 | BLOCKED_PENDING_OWNER_APPROVAL | G-3 |
| `BE-CR-002` | T042 | BLOCKED_PENDING_OWNER_APPROVAL | G-4 |
| `BE-CR-003` | T043 | BLOCKED_PENDING_OWNER_APPROVAL | G-5 |
| `BE-CR-004` | T044 | BLOCKED_PENDING_OWNER_APPROVAL | G-6 |
| `BE-CR-005` | T045 | BLOCKED_PENDING_OWNER_APPROVAL | G-7 |
| `BE-CR-006` | T046 | BLOCKED_PENDING_OWNER_APPROVAL | G-7 |

---

## Batch B verification evidence (2026-10-20)

- **Aggregate gate**: `npm run check` passed on rerun — portability, typecheck, lint, stylelint, full suite 524/524, build, notices. A first run hit two 5s `PurchaserFlow` timeouts under full-suite load; the targeted `PurchaserFlow` run passed 150/150 and the rerun passed.
- **Targeted**: 9 files / 222 tests — `SheetWorkspace`, `PurchaserFlow`, `SheetDeletion`, `RtlAccessibility`, `typography`, `l10nCatalog`, `fr071NarrowViewport`, `UnsavedChangesGuard`, `TransactionCorrection`.
- **Live `fa` RTL**: overlay workspace dialog, 4 tabs, 3 approval stages, 3 history events; 0 console/page errors; 390×844 full-screen with no overflow; list shell 390px and one-column filters; workspace table fits the dialog content with no horizontal scroll.
- **Live `en` LTR**: `dir=ltr` on desktop and mobile, no horizontal overflow, 0 errors; raw keys are expected because no `l10n/en.*` exists (`REC-01` still open); date reads `10/20/2026`.
- **Calendar independence**: `fa` + Gregorian gives `۲۰۲۶/۱۰/۲۰`; `fa` + Jalali gives `۱۴۰۵/۷/۲۸`; filter hints match the active calendar. The language did not select the calendar.

---

## Notes

- `[P]` tasks touch different files and can run in parallel.
- Every unchecked task is unapproved or blocked with the named gate; checked tasks have the evidence recorded above (`FR-005-032`).
- The 2026-10-20 directive authorizes the frontend Batch B cards only; backend changes, the English catalog, and all Git writes remain blocked.
- No task in this ledger authorizes a Git write; the Git plan is delivered separately and requires its own approval.
- No task in this ledger authorizes an edit to `lib/`, `appinfo/`, `specs/001-*`, the OpenAPI contract, the workflow contract, or an `l10n/en.*` catalog.
