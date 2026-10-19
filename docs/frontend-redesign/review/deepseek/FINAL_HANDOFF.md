# FINAL_HANDOFF — Manus → Spec Kit audit and implementation-task preparation

**Assignment:** autonomous audit of the purchaser UI/UX against Spec Kit and the implementation at HEAD, followed by authoring implementation-ready task cards. Documentation only.
**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe` (merge PR #9), recorded 2026-10-19. Verified `git rev-parse` at the start of this run; no other commit was made and none is proposed.
**Output location:** `docs/frontend-redesign/review/deepseek/` (this directory) — nothing outside it was written.

---

## 0. Reconciliation pass — read this first (2026-10-19)

After this audit was completed, the product owner answered six of the twelve open decisions and asked for the package to be reconciled into an execution-ready handoff. That pass produced four documents in this directory and changed **no** finding, no evidence and no `GAP-*` reference:

- **`OWNER_DECISION_RECONCILIATION.md`** — each owner clarification (D-01, D-02, D-05, D-06, D-09, D-11) mapped to what it resolves, the evidence re-verified for it, and its effect on the tasks; plus six reconciliation findings `REC-01…REC-06` and the full before/after status table for all 22 cards.
- **`BUNNY_READY_QUEUE.md`** — the implementation sequence, in batches, using the five delivery statuses (`READY_FOR_OWNER_APPROVAL`, `BLOCKED_BY_BACKEND`, `BLOCKED_BY_DESIGN`, `BLOCKED_BY_SPEC`, `OPTIONAL_DEFERRED`). **This file — not §4 below — is authoritative for task status and order.**
- **`BLOCKED_ITEMS.md`** — every blocked task and backend request with its exact unblock condition, the workaround analysis (including the two cases where no contractually-correct frontend-only path exists), and the record that no backend request is approved.
- **`TEST_STRATEGY.md`** — evidence taxonomy, tooling inventory, the seventeen verification areas the owner named, per-card test requirements and batch gates.

**Headline deltas after reconciliation:** 13 of the 16 frontend cards are `READY_FOR_OWNER_APPROVAL` and **3 are `BLOCKED_BY_BACKEND`** (`UI-PUR-003`, `UI-PUR-007`, `UI-PUR-014` — their product questions are now answered, but each needs a backend change that is not approved); all six `BE-CR-*` remain `BLOCKED_PENDING_OWNER_APPROVAL`; nothing is approved; and one new dependency (`REC-01` — no English catalog exists, so rendered English cannot be reviewed until the owner decides how one is sourced) gates rendered-English verification while leaving `UI-PUR-001`'s mechanism buildable.

---

## 1. What was done, in one paragraph

The purchaser surfaces (`/sheets`, the sheet detail, the transaction create/edit/read flows, evidence, and the correction path) were audited against `specs/001-pettycash-v1-core/**` (88 requirements, 1796-line OpenAPI contract, workflow contract, data model), against the actual PHP/Vue implementation, and against the accepted design direction in `docs/frontend-redesign/`. **49 findings** were recorded with file-and-line evidence and an exact classification; **23 further entries** record controls that were checked and found correct, so a later reader does not re-litigate them. The findings were converted into **16 frontend task cards** and **6 backend change requests**, sequenced into batches with a file-collision map and a test strategy. Nothing was implemented, nothing was committed, and no specification or application file was modified.

---

## 2. Coverage — all twelve audit areas

| Area | Findings | Area | Findings |
|---|---|---|---|
| A — Sheets list: columns, ordering, pagination, filters | GAP-001…010 | G — Money semantics | GAP-045…050 |
| B — Sheet create/open/edit/delete/submit | GAP-011…016 | H — Revision / audit / history | GAP-051…053 |
| C — Detail workspace, stage indicator, metrics, scrolling | GAP-017…022 | I — Unsaved changes, conflicts, recovery, states | GAP-054…057 |
| D — Transaction create/edit/defaults | GAP-023…029 | J — Layout, responsive, a11y, theming | GAP-058…062 |
| E — Evidence lifecycle | GAP-030…036 | K — Localization, direction, calendar | GAP-064…069 |
| F — Decisions, mixed stages, corrections, terminal state | GAP-037…044 | L — Prototype-to-production feasibility | GAP-070…073 |

Every area carries either findings or an explicit verified-correct conclusion with inspected evidence. No area was left unexamined.

**Headline verified-correct controls (23):** integer minor units end to end; `current` vs `effectiveApproved` semantics including "a returned transaction stays pending"; categorical decisions with no partial-amount approval anywhere; one immutable decision per (revision, stage); stage order enforced server-side with the client's stage ignored; return → correction revision → restart at Cost Center; terminal sheets contain only terminal transactions; 100 MB per-file evidence ceiling with no invented MIME allowlist; multiple attachments; no global "Save sheet"; no rolled-over or cross-sheet totals; no sheet-wide audit endpoint (a deliberate, documented non-goal); Submit hidden rather than disabled when not permitted; delete confirmation naming the sheet with an irreversible-cascade rationale; optimistic locking on every write path; save returns to the sheet detail (FR-088); a 390px no-horizontal-scroll floor; and no decision rule that lives only in the frontend.

---

## 3. Findings by class and severity

| Class | Count | P1 | P2 | P3 |
|---|---|---|---|---|
| MISSING_FRONTEND_CAPABILITY | 13 | 6 | 5 | 2 |
| VERIFIED_DEFECT | 10 | 6 | 4 | 0 |
| PROTOTYPE_SIMPLIFICATION | 8 | 2 | 3 | 3 |
| OPTIONAL_IMPROVEMENT | 6 | 0 | 0 | 6 |
| DESIGN_INCONSISTENCY | 6 | 0 | 4 | 2 |
| SPEC_AMBIGUITY | 4 | 0 | 1 | 3 |
| MISSING_BACKEND_CAPABILITY | 2 | 1 | 1 | 0 |
| **Total** | **49** | **15** | **18** | **16** |

Plus 23 verified-control records and 4 open visual/product questions that source inspection cannot settle (all in `DECISIONS_AND_BLOCKERS.md`).

### The five most consequential findings

1. **GAP-011 (VERIFIED_DEFECT, P1)** — renaming a sheet can silently repoint it to the purchaser's first account. The contract does not expose the sheet's account (`SheetSummary` has no member; `SheetPayloadFormatter.php:22-24` drops it deliberately), the update endpoint requires `accountId` (`SheetController.php:147-158`), the form therefore substitutes `accounts[0]` (`SheetFormView.vue:113-127`), and the server assigns it unvalidated (`SheetService.php:180-186`) without reconciling the sheet's stored currency (`ExpenseSheet.php:23-24`) against the account's (`PettyCashAccount.php:53,73`).
2. **GAP-030 (VERIFIED_DEFECT, P1)** — FR-023 requires "all required evidence" at submission, but `SubmitSheet` calls the gate with the default `$evidenceRequired=false` and **no call site under `lib/` passes `true`** (`SubmitSheet.php:83`, `TransactionRevision.php:124-129`). Meanwhile resubmission demands evidence unconditionally (`ResubmitCorrection.php:108-110`) — the two paths are inverted relative to each other.
3. **GAP-021 (VERIFIED_DEFECT, P1)** — the sheet's status is recomputed **only** during finance settlement (`FinanceSettlementService.php:408,524-536`), so the purchaser's list can show a stale status after cost-center or senior decisions and after early-stage returns, even though `contracts/workflow.md:110-113` requires a live-derived projection.
4. **GAP-054 (MISSING_FRONTEND_CAPABILITY, P1)** — the Unsaved Changes guard does not exist anywhere: zero hits for `beforeunload`/`onBeforeRouteLeave`/`isDirty`, no router guards, unguarded Cancel and Escape and browser back — for the one interaction whose visual design was already signed off.
5. **GAP-064/065/066 (P1)** — the application is Persian-only and RTL-hardcoded (`dir="rtl"` in 20 views, `l10n/` ships `fa` only, every date/number site hardcodes `fa-IR`) while the accepted design direction is English LTR, and no Gregorian/Jalali display preference exists although the owner's rule requires one that is independent of language.

---

## 4. Tasks prepared

**16 frontend tasks (`tasks/UI-PUR-001…016.md`)** and **6 backend change requests (`tasks/BE-CR-001…006.md`)**. Each card carries: id, priority, status, baseline SHA, user-visible outcome, screen(s), verified paths, spec/design references, allowed scope and non-goals, implementation behaviour, acceptance criteria including negative cases, tests with runnable commands, dependencies, size, risk, backend impact, rollback, DoD, and an explicit approval gate.

**Not blocked — ready for owner review and a first implementation batch (frontend only, no backend change, no pending product decision):** `UI-PUR-001`, `002`, `004`, `008`, `009`, `010`, `011`, `012`, `013`, `015`.

**Blocked** (after the reconciliation pass — see `BUNNY_READY_QUEUE.md` §2/§4): `UI-PUR-003`, `UI-PUR-007` and `UI-PUR-014` are `BLOCKED_BY_BACKEND`. The product questions they waited on (D-09, D-05, D-06) are answered; what remains is the unapproved backend change each needs (`BE-CR-002`, `001`, `003`). `UI-PUR-016` is ready for its server-enforced tier with its evidence criterion deferred until `BE-CR-004`; `UI-PUR-005` and `UI-PUR-012` are unblocked by D-01.

**Blocked pending backend authorization:** every `BE-CR-*` (6), plus the five frontend tasks that consume them (`UI-PUR-003`, `007`, `014`, `016`).

**First batch (re-tiered by the owner's Phase-4 priorities; the authoritative sequence is `BUNNY_READY_QUEUE.md` §4):**

```
Batch A — foundations and the purchaser list
1. UI-PUR-001  locale/direction/calendar formatting foundation
2. UI-PUR-002  the six-column purchaser list (+ shared row primitives)
3. UI-PUR-008  sheet actions behind a 3-dot menu
4. UI-PUR-004  list state preserved across the detail
```
…then Batch B (`005` → `006` → `016`), Batch C (`009` → `010` → `011` → `012` → `013`) and Batch D (`015`), with `003`/`007`/`014` held in the blocked lane. The audit's earlier standalone ordering (which led with the form fixes) is preserved in `EXECUTION_PLAN.md`; the owner's tiering (foundations → components → list → workspace → transactions → evidence → review → history → locale/theme → validation) governs now, and `UI-PUR-004` deliberately precedes `UI-PUR-005` because the overlay consumes the preserved list state.

---

## 5. Owner decisions outstanding (detail in `DECISIONS_AND_BLOCKERS.md`)

| # | Question | Blocks |
|---|---|---|
| D-01 | Is the Unsaved Changes dialog approved? Is the 390px mobile reference set approved? (`DESIGN_REFERENCES.md` says not; the handoff says accepted; no committed document says either) | `UI-PUR-012`, `UI-PUR-005` evidence |
| D-02 | English LTR now vs D-006's "Persian and English from day one" — and when is an English catalog authored? | sequencing (mechanism ship is safe) |
| D-03 | Does the list's date filter mean sheet-creation date or expense date? | possibly a spec clarification + contract change |
| D-04 | Does the purchaser list keep FR-062's stage-approved columns? | `UI-PUR-002` completeness |
| D-05 | May a sheet's account change after creation? (validate-and-expose vs immutable) | `UI-PUR-007` + `BE-CR-001` |
| D-06 | Stage cards: counts (needs `BE-CR-003`) or only the published figures? | `UI-PUR-014`, `UI-PUR-016` |
| D-07 | Two published transaction statuses can never occur — remove or document? | contract hygiene |
| D-08 | Does the mobile reference set match the owner's "eight mobile images"? | `UI-PUR-005` mobile evidence |
| D-09 | Is OPEN-first ordering the requirement, and how is it exposed? Also: what orders the OPEN group, which has no first-submission date? | `UI-PUR-003` + `BE-CR-002` |
| D-10 | The audit feed's `decision` field is the literal `'DECISION'` | API hygiene (frontend works around it) |
| D-11 | Should the workspace have a History tab at all, given history is per transaction by design? | `UI-PUR-006` |

**Status after the reconciliation pass:** D-01, D-02, D-05, D-06, D-09 and D-11 were answered by the product owner and applied; D-03, D-04, D-07, D-08 and D-10 are resolved to the extent repository evidence allows; D-12 is superseded by the delivery vocabulary. The exact effect per decision and per task is in `OWNER_DECISION_RECONCILIATION.md`.

**No decision was taken unilaterally.** In particular the pre-existing approval conflict in `references/DESIGN_REFERENCES.md` is reported, not resolved, and no image is labelled "approved" anywhere in this audit's output.

---

## 6. Checks actually run — and what was not run

**Run (read-only):**
- `git rev-parse --show-toplevel`, `git branch --show-current`, `git rev-parse HEAD`, `git log -1` — baseline confirmed.
- `git status --short` — before and after; pre-existing dirty paths identified and distinguished from this audit's outputs.
- Source inspection with exact line references across `specs/**`, `lib/**`, `src/**`, `appinfo/routes.php`, `tests/**`, `package.json`, and the whole `docs/frontend-redesign/**` intake.
- Whole-file and whole-directory searches (counts reported in the findings: e.g. `aria-invalid` 0 occurrences, `beforeunload`/`onBeforeRouteLeave`/`isDirty` 0, `prefers-reduced-motion` 0, `dir="rtl"` 20, `NcActions` 0).
- Independent recomputation of the finding counts by parsing the audit artefacts themselves (the summary tables were corrected against that parse — see §7).
- `wc -l`/`find` over the produced artefacts to verify they exist and are readable.

**Not run, and deliberately not claimed:**
- **No test suite was executed** — not `npm test`, not `npm run check`, not Playwright, not PHPUnit. No test result is asserted anywhere in this handoff.
- **No browser session and no screenshots.** Colour/token mapping and the exact visual comparison remain `VISUAL_REVIEW_NEEDED`.
- **No PHP or container command.** No `scripts/dev/*` wrapper was used, because nothing needed a runtime.
- **No live HTTP request.** So GAP-005 (pagination ties), GAP-022 (a decision on a correction-stage transaction) and GAP-029 (the 409 reaction) are recorded as `confirmed_in_source` / `inferred`, **not** as reproduced behaviour.
- **No dependency install, no build, no network fetch, no migration, no data mutation, no service restart.**
- **No code-intelligence graph tooling was used** (OCI/Serena/GitNexus were available to the environment but the audit's questions were answerable by direct source inspection plus targeted search; no claim of graph verification is made anywhere).

---

## 7. Self-review performed before this handoff

- Every P0/P1 finding was re-checked against the cited lines; the four highest-impact ones (GAP-011, GAP-023, GAP-025, GAP-030) were re-verified by a second direct inspection of the same files.
- Several initially assumed findings were demoted or dropped once the source contradicted them. Examples: the app does **not** use floats for money; decisions are **not** partially approvable; evidence size **is** enforced at 100 MB; there is **no** MIME allowlist to invent; there **is** no sheet-wide history endpoint and that is deliberate; screen-reader support is **not** absent (61 aria-labels, 39 roles, keyboard E2E).
- Duplicates were merged (two candidate per-stage-metric findings became GAP-020; two async-state observations became one optional improvement).
- **A counting error in this audit's own first draft was found and corrected:** the initial summary claimed 47 findings. Re-computing from the artefacts showed 72 numbered entries — 49 classified findings plus 23 verified-control records — and the summary tables in `AUDIT_REPORT.md` and `GAP_MATRIX.md` were rewritten to match. This is recorded here because the correction is part of the evidence trail, not a detail to hide.
- Every in-scope actionable finding either maps to a task or carries a written reason for exclusion (`PROPOSED_TASK_BACKLOG.md` §3).
- Backend-impacting items are isolated as `BE-CR-*` and marked blocked; no ticket smuggles a backend change into frontend work.

---

## 8. Git verification

- **No commit, no stage, no push.** Nothing was added to the index.
- **Application and specification sources untouched:** verified by `git status --short` showing no modification under `src/`, `lib/`, `tests/`, `appinfo/`, `specs/`, `l10n/`, or any configuration file.
- **Pre-existing work preserved:** the modified `AGENTS.md` and the untracked `.claude/`, `CLAUDE.md`, `docs/Review uploaded pasted content.zip` were already present before this audit and are unchanged by it. The pre-existing intake directories under `docs/frontend-redesign/` (`handoff/`, `prototype/`, `references/`, `_archives/`, `MANUS_INTAKE_MANIFEST.md`, `README_MANUS_INTAKE.md`) were read, not modified.
- **Created by this audit (all under `docs/frontend-redesign/review/deepseek/`):**

```
PROGRESS.md
SOURCE_MAP.md
AUDIT_REPORT.md
GAP_MATRIX.md
PROPOSED_TASK_BACKLOG.md
EXECUTION_PLAN.md
DECISIONS_AND_BLOCKERS.md
FINAL_HANDOFF.md
OWNER_DECISION_RECONCILIATION.md   (reconciliation pass)
BUNNY_READY_QUEUE.md               (reconciliation pass)
BLOCKED_ITEMS.md                   (reconciliation pass)
TEST_STRATEGY.md                   (reconciliation pass)
tasks/UI-PUR-001.md … tasks/UI-PUR-016.md   (16 files)
tasks/BE-CR-001.md  … tasks/BE-CR-006.md    (6 files)
```
`review/deepseek/README.md` was created by the *earlier, approved* intake task and was not modified here.

---

## 9. Next action (no further prompt required)

1. The product owner answers what remains open — `REC-01` (how an English catalog is sourced), the intra-OPEN ordering inside `BE-CR-002`, the currency and permission rules inside `BE-CR-001`, the `REC-03` semantics inside `BE-CR-003`, and whether the three spec-compliance changes (`BE-CR-004/005/006`) are in scope at all. D-01, D-05, D-06, D-09 and D-11 are already answered; see `OWNER_DECISION_RECONCILIATION.md`.
2. The product owner approves or rejects each task card. Approval is per card; approving `EXECUTION_PLAN.md` is not approving the cards.
3. Only then: hand the Batch A cards (`UI-PUR-001` → `002` → `008` → `004`, then the later batches) to an implementation agent, one card at a time, with the card's own tests as the completion evidence — sequence and gates in `BUNNY_READY_QUEUE.md`, verification levels in `TEST_STRATEGY.md`.
4. Backend work starts only after the corresponding `BE-CR` is explicitly authorized — with the open questions inside each `BE-CR` answered first, since several of them are product questions (a currency migration rule, a lock-version behaviour, a stricter submission rule).

**This is AUDIT COMPLETE + TASKS PROPOSED.** Implementation is **not** complete, not started, and not authorized by anything in this directory.

---

*Artefacts: `PROGRESS.md` (checkpoint ledger) · `SOURCE_MAP.md` (architecture, routes, evidence map, NOT_FOUND register) · `AUDIT_REPORT.md` (findings with evidence) · `GAP_MATRIX.md` (every finding in one table) · `PROPOSED_TASK_BACKLOG.md` (index + exclusions) · `EXECUTION_PLAN.md` (batches, collisions, tests) · `DECISIONS_AND_BLOCKERS.md` (D-01…D-12) · `OWNER_DECISION_RECONCILIATION.md` (owner clarifications, `REC-01…REC-06`, status deltas) · `BUNNY_READY_QUEUE.md` (authoritative queue) · `BLOCKED_ITEMS.md` (blocks and unblock conditions) · `TEST_STRATEGY.md` (evidence levels, verification areas, per-card tests) · `tasks/` (22 cards).*
