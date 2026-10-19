# PROGRESS — purchaser UI/UX audit and task preparation

**Status:** COMPLETE (audit finished, tasks proposed, nothing implemented)
**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe` · 2026-10-19
**Wrote only under:** `docs/frontend-redesign/review/deepseek/` — no application, spec, test or config file was modified; nothing was staged or committed.

---

## Phase ledger

| Phase | Scope | State |
|---|---|---|
| 1 — Baseline & source mapping | branch/HEAD, dirty-tree inventory, architecture, routes (44 OCS / 24 frontend), spec inventory (88 FRs), prototype intake, `SOURCE_MAP.md` | ✅ complete |
| 2 — Audit areas A–L | sheets list · sheet lifecycle · detail workspace · transactions · evidence · decisions · money · history · unsaved changes · layout/a11y/theming · i18n/RTL/calendar · prototype feasibility | ✅ complete — 49 findings + 23 verified-control records |
| 3 — Self-review & verification | re-check of every P1 against cited lines; demotion of contradicted assumptions; duplicate merge; **count correction (47 → 72 numbered entries: 49 findings + 23 verified records)** | ✅ complete |
| 4 — Task authoring | 16 `UI-PUR-*` cards, 6 `BE-CR-*` change requests, backlog index, execution plan, decisions/blockers register | ✅ complete |
| 5 — Handoff | `FINAL_HANDOFF.md`; git verification; this ledger | ✅ complete |

---

## Workstreams executed

| WS | Subject | Outcome |
|---|---|---|
| WS1 | Sheets list: columns, ordering, pagination, filters | 11 columns vs the accepted 6; single `ORDER BY created_at DESC`; no `sort` parameter in the contract; OPEN region page-local; filters status/currency/from-to; N+1 totals; no tie-breaker |
| WS2 | Sheet lifecycle + evidence | account substitution on edit; OPEN-only PATCH with version 409; delete route with guards but plain buttons; submit preconditions; `firstSubmittedAt` single writer; evidence 100 MB / no MIME policy; evidence-required not enforced at submit; multiple attachments; **no unsaved-changes guard anywhere** |
| WS3 | Workflow, stage metrics, money, revisions/audit | status enum has two dead values; sheet status recomputed only in finance settlement; decisions categorical and immutable per (revision, stage); return → new revision → restart at Cost Center; minor units integer end to end; per-stage metrics = approval amounts only; history is per transaction only (deliberate) |
| WS4 | Direct inspection: i18n/RTL/calendar/responsive/tooling | `dir="rtl"` ×20 hardcoded; `l10n/` fa-only; `fa-IR` hardcoded in date/money helpers + ~12 sites; Jalali only as a server reporting period; responsive 640/480/390 floors test-pinned; a11y baseline real but `aria-invalid`/`prefers-reduced-motion` absent |
| WS5 | Prototype reverse-engineering | fixture model, renderers, interactions, breakpoints 900/680, tokens, a11y mechanics, float money, faked evidence, and the prototype's own defects (no focus trap, fabricated Jalali dates on save, "reversible" delete copy, no Escape while confirming) |

---

## Artefacts produced

| File | Content |
|---|---|
| `PROGRESS.md` | this ledger |
| `SOURCE_MAP.md` | architecture, API/route maps, requirement→design→implementation evidence map, governance contradictions, prototype map, NOT_FOUND register |
| `AUDIT_REPORT.md` | 49 findings with evidence + 23 verified controls + 4 unresolvable-from-source questions |
| `GAP_MATRIX.md` | every finding in one table with class, priority, confidence, owner, disposition + finding→task mapping + exclusion reasons |
| `PROPOSED_TASK_BACKLOG.md` | 16 UI tasks + 6 BE change requests, with dependencies and explicit exclusions |
| `EXECUTION_PLAN.md` | batches, file-collision map, per-batch test strategy, ready-for-first-batch short list |
| `DECISIONS_AND_BLOCKERS.md` | D-01…D-12 (governance contradictions, open product questions, conservative assumptions) |
| `FINAL_HANDOFF.md` | executive summary, coverage, checks actually run vs not run, git verification, next actions |
| `tasks/UI-PUR-001…016.md` | 16 implementation-ready task cards, every one `PROPOSED` + approval gate |
| `tasks/BE-CR-001…006.md` | 6 backend change requests, every one `BLOCKED_PENDING_OWNER_APPROVAL` |

---

## Confirmed baseline facts (unchanged from Phase 1; retained for later readers)

- 13 controllers, 44 OCS routes, 24 frontend routes, 27 SFCs, 10 stores.
- `l10n/` ships Persian only; `dir="rtl"` hardcoded in 20 views; date/number rendering hardcoded to `fa-IR`.
- Money is integer minor units end to end; no float money in the application.
- No sheet-wide audit endpoint exists — a deliberate, documented non-goal (`spec.md:240`).
- No global "Save sheet" control, no bulk/partial approval, no rolled-over totals — all correct and to be preserved.

## Counts (recomputed from the artefacts, see `FINAL_HANDOFF.md` §7)

- Classified findings: **49** — P1 **15** · P2 **18** · P3 **16**.
- Verified-control records: **23**.
- Numbered entries total: **72**.
- Tasks prepared: **16 frontend** + **6 backend (blocked)**.
- Blocked by a product decision: `UI-PUR-003`, `UI-PUR-007`, `UI-PUR-014`, `UI-PUR-016` (plus D-01's approval clarity for `UI-PUR-005`/`012` evidence).

## Verification performed

- `git status --short` before and after: no modification under `src/`, `lib/`, `tests/`, `appinfo/`, `specs/`, `l10n/`.
- No test suite, build, browser session, container command, network fetch, migration or data mutation was performed — none is claimed as evidence.
- Every P1 finding re-checked against its cited lines; several assumptions were dropped when the source contradicted them; the initial finding-count error was corrected against a parse of the artefacts.

## Next action for the owner

1. Answer D-01, D-04, D-05, D-06, D-09 (the ones that gate work) in `DECISIONS_AND_BLOCKERS.md`.
2. Approve task cards individually; approving the execution plan is not approving cards.
3. Hand Batch 0 to an implementation agent only after approval.

---

## Reconciliation pass — owner clarifications applied (2026-10-19)

The owner's clarifications (D-01, D-02, D-05, D-06, D-09, D-11) were reconciled against the package. **No finding, evidence line or `GAP-*` reference was changed.**

- **New documents:** `OWNER_DECISION_RECONCILIATION.md`, `BUNNY_READY_QUEUE.md`, `BLOCKED_ITEMS.md`, `TEST_STRATEGY.md`. `FINAL_HANDOFF.md` updated to reference them. The affected cards carry a *Reconciliation update* block (14 cards).
- **Status deltas:** 13 of the 16 frontend cards are `READY_FOR_OWNER_APPROVAL`; **3 are `BLOCKED_BY_BACKEND`** (`UI-PUR-003`, `007`, `014` — their product questions are answered, the backend change each needs is not approved). All 6 `BE-CR-*` remain `BLOCKED_PENDING_OWNER_APPROVAL`; no backend change is approved anywhere. (Supersedes the "Blocked by a product decision" and "Next action" lists above.)
- **New reconciliation findings `REC-01…REC-06`** — dependencies and diligence items, not audit defects. `REC-01` (no English catalog exists; key-based i18n ships `fa` only) gates rendered-English verification while leaving `UI-PUR-001`'s mechanism buildable.
- **Authoritative queue and statuses:** `BUNNY_READY_QUEUE.md` (supersedes the earlier batch order in `EXECUTION_PLAN.md` where they differ).
- **Verification of this pass:** `git status --porcelain` over `src lib tests appinfo specs l10n package.json playwright.config.ts vitest.config.ts` returned 0 lines; nothing staged (`git diff --cached --name-only` = 0); HEAD still `6006f28400e8962909afab0744460152353c12fe`. No test suite, build, browser session, HTTP request or container command was run in this pass either — none is claimed.
