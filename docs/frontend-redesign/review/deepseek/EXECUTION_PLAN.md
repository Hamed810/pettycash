# EXECUTION_PLAN — sequencing the purchaser redesign for an implementation agent

**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe` · recorded 2026-10-19
**Assumption:** a human owner has approved a set of cards from `PROPOSED_TASK_BACKLOG.md`. This plan sequences **approved** work; it does not approve it.

---

## 1. The rule that shapes every batch

The owner's stated minimum viable path is **Sheets List → Sheet Detail (OPEN) → Transaction Draft**, "while keeping correctness foundational". Two kinds of work compete for the same files:

- **Correctness first** — items where the current behaviour is wrong independently of the redesign (`UI-PUR-010` evidence-on-create, `UI-PUR-011` draft delete, `UI-PUR-009` cost-center default, `UI-PUR-012` unsaved guard). They are small, they do not depend on the visual target, and they survive any later restyle.
- **Surface rebuild** — items that will be rewritten again if the design changes (`UI-PUR-002`, `UI-PUR-005`, `UI-PUR-006`).

Putting correctness first is not a preference here: `UI-PUR-010`, `UI-PUR-011` and `UI-PUR-005` touch the same two views (`TransactionFormView.vue`, `SheetListView.vue`, `SheetDetailView.vue`), and doing them in the wrong order produces conflicts and re-review.

**File-collision map** (who writes what) — use this to keep batches disjoint:

| File | Tasks that modify it |
|---|---|
| `src/views/sheets/SheetListView.vue` | 002, 003, 004, 008 (+ and its `NcActions` used by 007) |
| `src/views/sheets/SheetDetailView.vue` | 005, 006, 007, 008, 014, 016 |
| `src/views/transactions/TransactionFormView.vue` | 009, 010, 012, 013 |
| `src/views/sheets/SheetFormView.vue` | 007, 012 |
| `src/router/index.ts` | 004, 005, 012 |
| `src/stores/sheets.ts` | 003, 004, 016 |
| `src/utils/date.ts`, `money.ts` | 001 |
| `src/styles/*` | 001 (direction), 015 (tokens/motion) |

---

## 2. Batches

### Batch 0 — Foundations and correctness (no backend, no design dependencies)

Order inside the batch matters; the tasks do not overlap in files except where noted.

| # | Task | Why now | Files | Notes |
|---|---|---|---|---|
| 0.1 | `UI-PUR-001` | Every later task inherits direction and formatting; doing it last means touching every screen twice | `src/utils/*`, `src/l10n.ts`, all view roots | Not gated on D-02 — ships the mechanism only |
| 0.2 | `UI-PUR-011` | FR-025 requirement violation, self-contained, no design dependency | `TransactionFormView.vue`, workspace row menu | If `UI-PUR-006` is not approved yet, place the control on the transaction screen only and note the follow-up |
| 0.3 | `UI-PUR-010` | Removes a broken affordance; touches the same form as 0.2 → do both in one branch and one review | `TransactionFormView.vue` | Coordinate with 0.2 to avoid a second edit of the same template |
| 0.4 | `UI-PUR-009` | Highest-frequency interaction; small; no design dependency | `TransactionFormView.vue` | Needs the ordering-source question answered (card §8) |
| 0.5 | `UI-PUR-012` | Safety feature; independent of the visual target | forms + router | Gated on D-01 (dialog approval) — if D-01 is unresolved, ship the `beforeunload`+guard mechanics without the branded dialog |

**Exit criteria for Batch 0:** the four correctness items are closed with tests; no design regression; `npm run check` clean. This batch can proceed **without** any `BE-CR` approval and without D-04/D-05/D-06/D-09.

### Batch 1 — The list (minimum viable, first half)

| # | Task | Depends on | Files |
|---|---|---|---|
| 1.1 | `UI-PUR-002` | D-04 answer for the stage columns (the six accepted columns can ship regardless) | `SheetListView.vue` |
| 1.2 | `UI-PUR-004` | — (land it before 1.3 so the workspace's acceptance test has a real state to preserve) | `SheetListView.vue`, `router`, store |
| 1.3 | `UI-PUR-008` | — (build the `NcActions` pattern here; `UI-PUR-007` reuses it) | `SheetListView.vue` |

**Do not** start `UI-PUR-003` in this batch: it is blocked on D-09 + `BE-CR-002`, and half-implementing it client-side is the specific defect it exists to fix.

### Batch 2 — The workspace (minimum viable, second half)

| # | Task | Depends on | Files |
|---|---|---|---|
| 2.1 | `UI-PUR-005` | 1.2, `UI-PUR-012` for its Escape criterion | `SheetDetailView.vue`, `router`, a new workspace component |
| 2.2 | `UI-PUR-006` | 2.1 | `SheetDetailView.vue`, history component |
| 2.3 | `UI-PUR-015` | 1.1, 2.1, 2.2 — run it **last** in the batch so it audits the shipped surfaces | styles + markup |

`UI-PUR-005` is the size outlier. If the owner wants smaller review units, split it as: (a) overlay shell + focus management; (b) the three tabs' content; (c) mobile full-screen behaviour — each with its own acceptance subset, in that order. Do not split the focus-management half from the shell: an overlay without focus containment is a regression against the codebase's existing accessibility baseline.

### Batch 3 — Backend-gated work (only after owner approval)

| # | Task | Blocked by | Notes |
|---|---|---|---|
| 3.1 | `BE-CR-004` → `UI-PUR-016` | owner approval | The submit-readiness surface is meaningless without the server enforcing the same rule |
| 3.2 | `BE-CR-005` | owner approval | Improves the list and workspace status display; no frontend task needed beyond re-testing |
| 3.3 | `BE-CR-002` → `UI-PUR-003` | D-09 + owner approval | Backend first, then the sort control and the server-driven OPEN region |
| 3.4 | `BE-CR-001` → `UI-PUR-007` | D-05 + owner approval | Do not start `UI-PUR-007`'s inline editing before the payload exposes the account |
| 3.5 | `BE-CR-003` → `UI-PUR-014` | D-06; only if Shape 2 | If Shape 1 is chosen, mark `BE-CR-003` withdrawn |
| 3.6 | `BE-CR-006` | owner approval (lowest priority) | Independent; can be scheduled whenever a backend slot exists |
| 3.7 | `UI-PUR-013` | — (no backend dependency) | Can be pulled forward into Batch 0 if a slot is free; listed here because it touches revision semantics |

### Deferred / explicitly out

| Item | Why |
|---|---|
| Page-size control, list search, virtualization, N+1 totals | optional, no user outcome, or spec-deferred (see `PROPOSED_TASK_BACKLOG.md` §3) |
| Draft recovery after accidental loss | a new capability, not the accepted guard |
| Manager / finance / admin redesign | outside the purchaser scope |
| Anything requiring a new endpoint | no `BE-CR` exists for it, and none was proposed |

---

## 3. Test strategy per batch

| Layer | What runs | Command |
|---|---|---|
| Component / unit (every task) | the task's own new cases plus the affected existing specs | `npx vitest run tests/Frontend/Component/<spec>.spec.ts` |
| Type / lint / style (every task) | — | `npm run typecheck && npm run lint && npm run stylelint` |
| Full frontend gate (batch exit) | everything the repo already runs | `npm run check` (openapi → portability → error-rate → typecheck → lint → stylelint → vitest → build → notices) |
| PHP contract/integration (any `BE-CR`) | the affected suites | `scripts/dev/phpunit <test>` — **container only** per `AGENTS.md`; never host PHP |
| E2E (batch exit, when the lab is available) | the named journeys | `npm run test:e2e` (its `pretest:e2e` settles the opcode cache; for any live-HTTP probe after editing `lib/` or `appinfo/`, settle first with `scripts/dev/opcache-settle`; route changes need `scripts/dev/route-settle` / `route-cache-clear`) |
| Visual | screenshots at desktop and 390px, light and dark, compared against the accepted references | manual/Playwright screenshots attached to the completion report |

**Rules the implementing agent must respect** (repo binding): targeted tests during implementation, full gates only at batch boundaries; no `npm install`; no container-side frontend tooling; no Vuetify; no new dependency; no commit without the owner's instruction; `git status` enumerated in every completion report.

---

## 4. Ready-for-Bunny short list (after owner approval)

Recommended first hand-off, in this order — all frontend-only, no `BE-CR`, no product decision pending:

1. `UI-PUR-001` — locale/direction/formatting foundation
2. `UI-PUR-011` — draft transaction deletion (FR-025)
3. `UI-PUR-010` — evidence only after the transaction exists
4. `UI-PUR-009` — cost-center preselect
5. `UI-PUR-012` — unsaved-changes guard + 409 reload
6. `UI-PUR-002` — the six-column list
7. `UI-PUR-004` — list state preservation
8. `UI-PUR-008` — overflow menu

Then Batch 2 (`UI-PUR-005`, `UI-PUR-006`, `UI-PUR-015`).

**Not ready, and why:** `UI-PUR-003` (D-09 + `BE-CR-002`), `UI-PUR-007` (D-05 + `BE-CR-001`), `UI-PUR-014` (D-06), `UI-PUR-016` (Submit-behaviour decision + `BE-CR-004`), and every `BE-CR-*` (owner approval).

---

## 5. What a completed batch must report

Every task's completion evidence must contain: the changed paths; the tests executed with their output; which existing assertions were changed and why (there are several: `TransactionCorrection.spec.ts:735`, the list column assertions in `PurchaserFlow.spec.ts`, and any submission test affected by `BE-CR-004`); screenshots for visual tasks; an explicit statement of anything left `TO_VERIFY` or blocked; and a `git status --short` enumeration proving no unrelated file was touched.

---

*Companion documents: `PROPOSED_TASK_BACKLOG.md` (the index), `DECISIONS_AND_BLOCKERS.md` (what blocks what), `tasks/*.md` (the cards), `FINAL_HANDOFF.md`.*
