# PROPOSED_TASK_BACKLOG — index of prepared tasks

**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe` · recorded 2026-10-19
**Every task below is PROPOSED.** None is approved, none may be started on the strength of this document alone. Backend changes are isolated in `BE-CR-*` cards and are blocked pending owner approval.

**ID hygiene:** the repository's earlier draft backlog (`docs/frontend-redesign/handoff/governance/manus-handoff-snapshot/FRONTEND_TASK_BACKLOG.md`) used `UI-00`…`UI-xx` identifiers. Those are **not reused**; this set uses `UI-PUR-nnn` with fresh numbering, and the legacy IDs remain untouched where they are.

---

## 1. Frontend tasks (purchaser scope only)

| ID | Title | Pri | Size | Status | Depends on | Backend | Findings |
|---|---|---|---|---|---|---|---|
| `UI-PUR-001` | Locale-aware direction and calendar-aware date/number formatting foundation | P1 | M | PROPOSED | — | NONE | GAP-064, 065*, 066, 067, 068 |
| `UI-PUR-002` | Sheet list: six-column layout, title above ID, honest amounts | P1 | M | PROPOSED (D-04 for stage columns) | `UI-PUR-001` (soft) | NONE | GAP-009, 010, 048, 069 |
| `UI-PUR-003` | Sheet list sorting: OPEN-first in either direction, correct across pages | P1 | M | **BLOCKED_OWNER_DECISION** (D-09) | `BE-CR-002` | **BE-CR-002** | GAP-001, 002, 003 |
| `UI-PUR-004` | List state preserved across the sheet detail (page, filters, scroll) | P1 | S | PROPOSED | — | NONE | GAP-017 (state) |
| `UI-PUR-005` | Sheet detail workspace: the large overlay over the list | P1 | L | PROPOSED | `UI-PUR-004`, `UI-PUR-012` (partial) | NONE | GAP-017 (shell), 062, 070, 073 |
| `UI-PUR-006` | Workspace transaction table, row actions, honest History tab | P1 | M | PROPOSED (D-11 for the tab) | `UI-PUR-005` | NONE | GAP-019, 051, 052, 053 |
| `UI-PUR-007` | Inline title edit while OPEN, without changing the account | P1 | M | **BLOCKED_BY_BACKEND** (D-05 answered 2026-10-27: immutable account) | `UI-PUR-005`, `UI-PUR-008` | **BE-CR-001** | GAP-011 (FE), 012 |
| `UI-PUR-008` | Sheet actions behind a 3-dot overflow menu | P2 | S | PROPOSED | — | NONE | GAP-013 |
| `UI-PUR-009` | Preselect the cost center from the last saved transaction in the sheet | P1 | S | PROPOSED | — | NONE | GAP-024 |
| `UI-PUR-010` | Evidence only after the transaction exists | P1 | S | PROPOSED | — | NONE | GAP-025 |
| `UI-PUR-011` | Delete a draft transaction (FR-025) | P1 | S | PROPOSED | — | NONE | GAP-023 |
| `UI-PUR-012` | Unsaved-changes guard (save/discard/stay) + the 409 reload rule | P1 | M | PROPOSED (D-01) | — | NONE | GAP-054, 029 |
| `UI-PUR-013` | Returned transactions go through the correction entry point | P2 | S | PROPOSED | — | NONE | GAP-028 |
| `UI-PUR-014` | Stage summary in the workspace, fed only by server figures | P2 | M | **BLOCKED_OWNER_DECISION** (D-06) | `UI-PUR-005` | conditional **BE-CR-003** | GAP-018, 020 |
| `UI-PUR-015` | Accessibility and theming parity for the redesigned surfaces | P2 | M | PROPOSED | `UI-PUR-002`, 005, 006 | NONE | GAP-058, 059, 070 |
| `UI-PUR-016` | Tell the purchaser what blocks submission, before they press Submit | P2 | S–M | **BLOCKED_OWNER_DECISION** (Submit behaviour) | `UI-PUR-005`, 006; `BE-CR-004` for the evidence half | conditional **BE-CR-004** | GAP-031, 030 |

\* `UI-PUR-001` builds the localization *mechanism* only; authoring an English catalog is D-02 and is explicitly out of scope.

## 2. Backend change requests (all blocked pending owner approval)

| ID | Title | Pri | Size | Endpoint / contract impact | Consumer task | Findings |
|---|---|---|---|---|---|---|
| `BE-CR-001` | Expose the sheet's account on `SheetDetail` only and make the account immutable on update | P1 | S–M | `GET /sheets/{id}`, `PATCH /sheets/{id}`, `SheetDetail`, OpenAPI | `UI-PUR-007` | GAP-011 |
| `BE-CR-002` | Sortable, deterministic, OPEN-first ordering for the sheet list | P1 | M | `GET /sheets`, OpenAPI (+ a stable tie-breaker) | `UI-PUR-003` | GAP-001, 005 |
| `BE-CR-003` | Per-stage decision counts for a sheet (**conditional**) | P2 | M | `GET /sheets/{id}`, `SheetTotals`/`SheetSummary`, OpenAPI | `UI-PUR-014` Shape 2 | GAP-020 |
| `BE-CR-004` | One consistent evidence policy at submit and resubmit | P1 | S | `POST /sheets/{id}/submit`, `POST /transactions/{id}/resubmit`, `contracts/workflow.md`, OpenAPI | `UI-PUR-016` | GAP-030, 032 |
| `BE-CR-005` | Keep the sheet's status derived, not stale | P1 | S–M | decision/submit/resubmit paths; `contracts/workflow.md` (no payload change) | list + workspace status | GAP-021, 040 |
| `BE-CR-006` | Refuse a decision on a transaction awaiting correction | P2 | S | `POST /transactions/{id}/decisions`, OpenAPI | indirectly `UI-PUR-006` | GAP-022 |

## 3. Explicitly not tasked (with reasons)

| Item | Reason |
|---|---|
| GAP-004 page-size control, GAP-007 list search | optional improvements with no independent user outcome; the list must exist in its accepted shape first |
| GAP-006 (filters use creation date) | depends on D-03; a spec clarification may make it a non-issue |
| GAP-008 N+1 totals | performance only; SC-008 is deferred by the spec itself |
| GAP-014 first-submitted immutability hardening | holds today by construction; changing the domain model is not justified |
| GAP-015 prototype "reversible" copy | the prototype is not production code |
| GAP-019 virtualization | no measured problem at the observed scale (32–40 rows) |
| GAP-036 per-revision evidence wording | the model is spec-consistent; the prototype's label is the error |
| GAP-041 dead status values | recorded in D-07; changing a published enum needs a change-control decision and no purchaser surface depends on it |
| GAP-044 explanation maxlength (server side) | reviewer-surface concern, outside the purchaser redesign |
| GAP-046/047/050/051 | constraints, encoded as acceptance criteria ("do not invent", "do not port") rather than tickets |
| GAP-056 draft recovery | a new capability; an explicit non-goal of `UI-PUR-012` |
| GAP-057 unused `AsyncState.vue` | too small for an independent outcome; folded into `UI-PUR-002`/`005`/`006` acceptance |
| GAP-071/072 | implementation-style constraints; no user-visible outcome |
| Manager / finance / admin / reporting surfaces | outside the purchaser-only scope of this assignment, even where the same class of gap exists |

## 4. Related repository work that this backlog does not touch

| Artefact | Standing |
|---|---|
| `docs/frontend-redesign/{CHARTER,DECISIONS,ROADMAP,STATUS,README}.md` | read, unmodified |
| `docs/frontend-redesign/handoff/governance/manus-handoff-snapshot/FRONTEND_TASK_BACKLOG.md`, `FAST_TRACK_ADDENDUM.md` | historical; untouched; IDs not reused |
| `docs/frontend-redesign/references/**`, `prototype/**`, `_archives/**` | read-only references; untouched |
| `specs/**` | inspected; **not modified** — every change this backlog needs is expressed as a `BE-CR` for the owner to authorize |
| `src/**`, `lib/**`, `tests/**`, `appinfo/**` | inspected; **not modified** |

---

*Companion documents: `GAP_MATRIX.md` (every finding), `EXECUTION_PLAN.md` (sequence and batches), `DECISIONS_AND_BLOCKERS.md` (D-01…D-12), `tasks/*.md` (the cards), `FINAL_HANDOFF.md` (executive summary).*
