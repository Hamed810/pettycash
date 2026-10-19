# DECISIONS_AND_BLOCKERS — what needs a product decision before implementation

**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe` · recorded 2026-10-19
**Rule for this document:** it records contradictions and open questions and proposes dispositions. It **does not** accept, merge or overwrite any decision. `docs/frontend-redesign/DECISIONS.md` (D-001…D-015, with P-001…P-004 open) and `docs/frontend-redesign/{CHARTER,ROADMAP,STATUS}.md` were read and are unmodified by this audit.

---

## 1. Governance contradictions found (chronological, unresolved)

### D-01 — Is the Unsaved Changes dialog approved? Is the mobile reference set approved?
**The contradiction:** `docs/frontend-redesign/references/DESIGN_REFERENCES.md` records the Unsaved Changes V3 design and **all** 390px purchaser mobile views as "not yet approved" (its own status column), while the Manus handoff documents (`handoff/manus/00_START_HERE_FA.md`, `handoff/manus/01_PROJECT_CONTRACT_EN.md`) describe the Unsaved Changes interaction as accepted and the desktop set as the approved direction. `docs/frontend-redesign/STATUS.md` (dated 2026-10-11) predates the Manus work and records nothing about it. **No committed repository document records a product-owner approval for any specific image.**

**Why it matters:** three proposed tasks (`UI-PUR-012` unsaved guard, `UI-PUR-005` mobile workspace, `UI-PUR-002` list layout) are specified against images whose approval status is disputed. Implementing a "not yet approved" design as if it were approved would silently promote a draft.

**Audit's treatment:** no image is labelled "approved" anywhere in this audit's output. `UI-PUR-012`'s approval gate explicitly asks the owner to confirm D-01 before implementation.

**Proposed disposition:** the owner either (a) records the approval in the repository (append-only entry in `DECISIONS.md` per its own procedure) naming the specific files, or (b) marks the disputed images as references-not-approvals and accepts that the corresponding tasks carry design risk. **The audit does not recommend (a) or (b) — that is the owner's call.**

---

### D-02 — English LTR now vs "Persian and English from day one"
**The contradiction:** accepted decision **D-006** requires Persian **and** English from day one with RTL/LTR following Nextcloud. The Manus sequencing statement is "English LTR only for now, Persian deferred". The repository's actual state is the opposite extreme: `l10n/` ships **Persian only** (`fa.js`, `fa.json`), and `dir="rtl"` is hardcoded in 20 views (GAP-064).

**Why it matters:** two different things are conflated — the *design* language (English LTR, defensible as a design-phase choice) and the *product's* localization mechanism (which D-006 decides). If the redesign ships English-LTR screens with no English catalog and no direction handling, the result is a Persian-RTL product wearing an English layout, which satisfies neither reading.

**Proposed disposition:** treat them separately. `UI-PUR-001` (mechanism: direction follows the locale, formatting is locale-aware) is safe under either reading and is proposed now. The *translation* question — when an English catalog is authored and whether Persian remains the default — is the owner's, and is explicitly **not** in `UI-PUR-001`'s scope.

---

### D-03 — What does the sheets-list date filter actually filter?
**The gap:** FR-064 requires filtering by "date" without naming which date (`spec.md:1058-1076`); the implementation filters `created_at` (`DbExpenseSheetRepository.php:121-133`) while the list's date columns are dominated by expense dates and the submission dates (`SheetListView.vue:308-326`). A purchaser filtering "from 1403/07/01" can reasonably expect expense dates.

**Proposed disposition:** either the spec is amended to say "sheet creation date" explicitly, or the filter moves to the expense-date range (a contract + query change). **A one-line clarification in `spec.md` is the cheaper resolution and should be preferred if the current behaviour is the intended one.** No task is proposed until this is answered; the frontend must not silently relabel the field.

---

### D-04 — Does the purchaser list keep FR-062's stage-approved columns?
**The contradiction:** FR-062 mandates the latest-effective figure **and** three separate stage-approved figures on sheet summaries, with a rationale recorded in the spec about not merging them (`spec.md:1046-1048`). The accepted design direction reduces the list to six columns (Sheet, Status, Transactions, Recorded, Latest Approved, First Submitted).

**Why it matters:** dropping the stage columns from the list is a *specification* question, not a layout preference — the spec explicitly argues that a senior approval later rejected by finance must stay visible. Removing it from the purchaser's list may be correct (it is arguably reviewer information on the purchaser's screen), but that is a product judgement.

**Proposed disposition (for the owner to accept or reject):** keep all six accepted columns as the primary layout and retain the three stage-approved figures in a secondary line inside the amounts cell or a disclosure row, so `UI-PUR-002` can ship without deleting anything the spec mandates. If the owner prefers a true six-column list, the spec's FR-062 needs an explicit amendment naming the surfaces where the stage figures remain (detail and queues are the obvious candidates).

---

### D-05 — May a sheet's account change after creation?
**The defect:** GAP-011 (a retitle can silently repoint a sheet to another account, unvalidated, with the currency left behind).

**Proposed disposition:** the safest reading is that the account is editable while OPEN but must be **validated** on update (owner, existence, active, currency compatibility) and **visible** in the sheet payload so the client can send the right value — `BE-CR-001`. If the owner prefers immutability, the update path must still stop accepting a substituted account, which requires the same payload exposure. **The one option that must not be chosen is "leave it as it is".**

---

### D-06 — Stage cards: counts or only the figures the system already publishes?
**The gap:** per-stage **approval amounts** exist; per-stage counts and per-stage rejected/pending/returned do not (`GAP-020`), and the client cannot reconstruct them from `TransactionSummary`'s single `latestDecision`.

**Proposed disposition:** `UI-PUR-014` Shape 1 (existing figures, each labelled with its stage, with "awaiting" derived from each transaction's outstanding stage and clearly labelled as such) requires **no backend change** and honours the owner's "do not invent rolled-over totals" rule. Shape 2 (real per-stage counts) requires `BE-CR-003`. The owner's rule says stage statistics "must show stage-specific approved/rejected/pending/returned decisions" — if that is read strictly, Shape 2 is required and `BE-CR-003` must be approved; if it is read as "must not show *rolled-up* totals", Shape 1 satisfies it. **This reading is the decision.**

---

### D-07 — Two published transaction statuses can never occur
**The gap:** `AWAITING_SENIOR_REVIEW` and `AWAITING_FINANCE_REVIEW` are in the domain enum and in the published contract's `TransactionStatus` enum, but no code path produces them (`GAP-041`).

**Proposed disposition:** either remove them from the contract enum (a change-control act on a published surface) or document them as reserved. The redesign must not render them, and `UI-PUR-006`'s status mapping must not invent labels for states that cannot occur. Low urgency; recorded so it is decided rather than discovered later.

---

### D-08 — Does the mobile reference set match the owner's "eight mobile images"?
**The gap:** the intake contact sheet shows eight tiles (seven mobile screens plus one desktop dialog); seven mobile files exist under `references/mobile/`. Whether the intended set was eight mobile screens, or eight tiles of mixed type, is not determinable from the repository.

**Proposed disposition:** the owner confirms the intended set. Until then, `UI-PUR-005`'s mobile acceptance criteria are checked against the seven available references and the contact sheet, and no image is treated as missing evidence of a screen.

---

### D-09 — Is OPEN-first ordering the product requirement, and how is it exposed?
**The gap:** the requirement exists only in an unmerged addendum (`U-05`) and the Manus contract; `specs/` has no ordering requirement and the contract has no ordering clause (`GAP-001`).

**Two things to decide:** (1) confirm the product requirement; (2) choose the mechanism — a `sort` parameter with OPEN-first as the list's documented default, or an explicit `status=OPEN` request used by the "open sheets" region only, leaving the default list ordering untouched. The second is a smaller behaviour change with one extra request per view; the first is closer to the stated requirement.

**Also unresolved and needing an answer:** the rule says OPEN sheets order by *first submission*, a timestamp they do not have by definition. The proposed reading is: OPEN group first (ordered by creation time), then the rest ordered by first submission, in the requested direction — confirm this is what was meant (`BE-CR-002` §5).

---

### D-10 — The audit feed's top-level `decision` field is the literal string `'DECISION'`
**The gap:** `lib/Controller/AuditController.php:115` — the real value lives inside `after`.

**Proposed disposition:** the frontend reads from the event payload (`UI-PUR-006` acceptance criterion 4 pins it), and the field's meaning is documented. Changing the published field is a separate hygiene question; **no backend change is requested**.

---

### D-11 — Should the workspace have a History tab at all?
**The issue:** history is per transaction only, by design (`spec.md:240`), so a sheet-level History tab is a client-side aggregation with ordering/authorisation questions of its own — the very questions the spec declined to answer.

**Proposed disposition:** two admissible outcomes: (a) an explicitly-labelled aggregation with a visible disclaimer and deterministic client-side ordering (`UI-PUR-006` as written); (b) no History tab, with per-transaction history reachable from each row. The owner chooses; the audit slightly favours (a) **only because** the disclaimer is displayed, and flags that (b) is the more conservative reading of `spec.md:240`.

---

### D-12 — Status of this audit's own outputs
**Not a contradiction, recorded for completeness:** every artefact in `review/deepseek/` is `PROPOSED`. Nothing here approves a design, accepts a decision, or authorizes implementation. The pre-existing approval conflict in `references/DESIGN_REFERENCES.md` is reported, not resolved.

---

## 2. Blockers by task

| Task | Blocked by | Nature |
|---|---|---|
| `UI-PUR-003` | D-09 + `BE-CR-002` | product decision + contract change |
| `UI-PUR-007` | D-05 + `BE-CR-001` | product decision + contract/validation change |
| `UI-PUR-012` | D-01 | approval clarity (dialog status) |
| `UI-PUR-014` | D-06 (+ `BE-CR-003` if Shape 2) | product decision (+ conditional backend) |
| `UI-PUR-016` | D-06/`BE-CR-004` for its evidence half | conditional backend |
| `UI-PUR-002` | D-04 for the stage-columns question | product decision (list can ship otherwise) |
| `UI-PUR-006` | D-11 | product decision (History tab) |
| `UI-PUR-005` | D-01 for the mobile acceptance evidence | approval clarity |

## 3. Assumptions made by this audit (conservative, non-invasive)

| # | Assumption | Basis | If wrong |
|---|---|---|---|
| A1 | The six-column list is the intended list layout; FR-062's figures are retained until the owner says otherwise | design direction + FR-062 | `UI-PUR-002` scope changes only |
| A2 | The Unsaved Changes interaction is wanted even though its approval is disputed | it is the most-referenced accepted interaction, and the guard is a safety feature | `UI-PUR-012` waits |
| A3 | Expense-date semantics are not being changed by the redesign | FR-023's no-window clarification | D-03 must be answered first |
| A4 | No backend change is made without a separate approval, even when a frontend task is "blocked" by it | the assignment's change control | a blocked task simply stays blocked |
| A5 | The `review/deepseek/` directory is the only place this audit writes | the assignment | n/a — verified in `FINAL_HANDOFF.md` |
| A6 | Prototype values (floats, hardcoded USD, fabricated Jalali dates, folded stage buckets) are examples, never authorities | the assignment + `KNOWN_GAPS.md` | a port would introduce fabricated financial data — the reason each is explicitly forbidden in the task cards |

---

*Companion documents: `AUDIT_REPORT.md`, `GAP_MATRIX.md`, `PROPOSED_TASK_BACKLOG.md`, `EXECUTION_PLAN.md`, `FINAL_HANDOFF.md`, `tasks/`.*
