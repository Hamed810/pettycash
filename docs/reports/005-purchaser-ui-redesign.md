# 005-purchaser-ui-redesign — Spec Kit management migration

**Date:** 2026-10-19
**Spec:** `specs/005-purchaser-ui-redesign/` (imported audit cards → spec → research/plan/data-model/contracts/quickstart/checklist → canonical task ledger)
**Status:** management baseline complete; **no application code changed in this pass**; Batch B unstarted; all `BE-CR-001…006` blocked; the separate `GIT-PLAN-001-R1` checkpoint recorded C1–C6

## Problem

The Purchaser UI Redesign existed as three unreconciled artifacts and no single status authority:

- an audit package under `docs/frontend-redesign/review/deepseek/` (22 task cards, a gap matrix, owner-decision reconciliation, a blocked-items register, an execution plan);
- a Batch A execution report under `docs/frontend-redesign/review/bunny/` describing the implementation now recorded in C5;
- four governance documents (`CHARTER.md`, `DECISIONS.md`, `STATUS.md`, `ROADMAP.md`) whose `STATUS.md`/`ROADMAP.md` still described F1 as in progress with "no code changes".

There was no canonical requirement set, no canonical status ledger, no traceability from the audit's card IDs to repository artifacts, and no explicit Git boundary for the work.

## What was delivered

| Path | Change |
|---|---|
| `specs/005-purchaser-ui-redesign/spec.md` | New. 5 user stories, `FR-005-001…034`, 10 success criteria, edge cases, blocked journeys, 22-card traceability matrix, approval-state section |
| `specs/005-purchaser-ui-redesign/research.md` | New. 10 management decisions (identity, traceability, non-duplication, status vocabulary, blocked discipline, evidence tiers, governance policy, Git boundary, ledger layout, completion meaning) |
| `specs/005-purchaser-ui-redesign/plan.md` | New. Technical context, constitution check I–V, structure, phases and gates G-1…G-9, risks R1–R9, rollback |
| `specs/005-purchaser-ui-redesign/data-model.md` | New. Presentation/management entities only; no new persisted entity; domain-relationship table |
| `specs/005-purchaser-ui-redesign/contracts/purchaser-ui-contract.md` | New. `C-01…C-12` UI behaviour contract, stack contract, management contract, guard inventory |
| `specs/005-purchaser-ui-redesign/quickstart.md` | New. Automated + manual validation, prohibitions, completion-claim rules |
| `specs/005-purchaser-ui-redesign/checklists/requirements.md` | New. Requirements-quality review incl. traceability/blocked/evidence items |
| `specs/005-purchaser-ui-redesign/tasks.md` | New. **Canonical ledger** `T001…T052`; every card mapped with status + gate |
| `.specify/feature.json` | `specs/004-in-app-navigation` → `specs/005-purchaser-ui-redesign` (machine-local; **gitignored** by `.specify/.gitignore`, so not a commit candidate) |
| `docs/frontend-redesign/CHARTER.md` | Addendum 7 (FA+EN): Spec Kit ownership, audit-as-evidence, card-by-card approval, no-bypass, evidence tiers, Git licensing. No principle changed |
| `docs/frontend-redesign/DECISIONS.md` | Added `D-016…D-019` (owner instructions, 2026-10-19) and `P-005…P-010` (open items). Nothing recorded `Accepted` without an owner instruction |
| `docs/frontend-redesign/STATUS.md` | Reconciled: Batch A partial state, card counts, blockers, next actions; obsolete F1 status replaced (recoverable from Git history) |
| `docs/frontend-redesign/ROADMAP.md` | Phase statuses updated to evidence with `◐/▶/⬜` distinctions; fast-track note on F1; canonical-ledger pointer; explicit "no phase gate opened" statement |

**Deliberately NOT touched:** `src/**`, `tests/**`, `l10n/**`, `lib/**`, `appinfo/**`, `specs/001-pettycash-v1-core/**` (incl. `contracts/ocs-api.yaml` and `contracts/workflow.md`), `specs/004-in-app-navigation/tasks.md` (pre-existing modification), `docs/frontend-redesign/review/**` (audit evidence, read-only for this pass), `js/**`, `css/**` (generated build output), `references/**`, `prototype/**`, `handoff/**`, `_archives/**`, `AGENTS.md`, `CLAUDE.md`, `.claude/`, `.env*`, `docs/Review uploaded pasted content.zip`.

## Card disposition recorded

| Card(s) | Status | Basis |
|---|---|---|
| `UI-PUR-004`, `UI-PUR-008` | `DONE_VERIFIED` (technical) | Batch A report browser evidence + re-executed targeted tests |
| `UI-PUR-001`, `UI-PUR-002` | `PARTIAL` | Named residuals: AC2 surface, AC5 negative test, `REC-01` English labels, `REC-06` filter labels, owner visual acceptance |
| `UI-PUR-005/006/009/010/011/012/013/015/016` (server tier) | `READY_FOR_OWNER_APPROVAL` | No blocker found; not approved, not started |
| `UI-PUR-003`, `UI-PUR-007`, `UI-PUR-014` | `BLOCKED_BY_BACKEND` | Depend on `BE-CR-002/001/003`; no contractually-correct frontend-only path exists |
| `BE-CR-001…006` | `BLOCKED_PENDING_OWNER_APPROVAL` | No repository document records approval; open questions enumerated per request |

## Evidence tiers used in this pass

1. **Re-executed in this pass** — `npx vitest run tests/Frontend/localeFormatting.spec.ts tests/Frontend/Component/PurchaserFlow.spec.ts tests/Frontend/Component/SheetDeletion.spec.ts tests/Frontend/Component/RtlAccessibility.spec.ts` → **4 files / 168 tests passed** (`PurchaserFlow` 137, `localeFormatting` 13, `SheetDeletion` 9, `RtlAccessibility` 9), 2026-10-19.
2. **Report-recorded, not re-executed** — Batch A `npm run check` (23 files / 481 tests) and Playwright scripts (32/32, 8/8 + 8/8, 5/5) as recorded in `review/bunny/BATCH_A_EXECUTION_REPORT.md`.
3. **Source-verified** — `src/utils/{direction,locale,calendar,date,number,money,sheetListSession}.ts`, `SheetListView.vue`, `AppRoot.vue`, `router/index.ts`; test files under `tests/Frontend/`.
4. **Not obtained** — English catalog, English visual acceptance, live calendar surface (AC2), mutating negative test (AC5), owner visual acceptance, any Batch B evidence.

**R1 execution check (separate pass):** `PurchaserFlow`, `RtlAccessibility`, `SheetDeletion`, `routeNameIntegrity`, `localeFormatting`, `FinanceReview`, and `ManagementReview` passed as **7 files / 241 tests** on 2026-10-19.

No aggregate gate is claimed as executed by this pass.

## Checkpoint result (R1)

- Branch `feat/pettycash-purchaser-ui-batch-a`; baseline HEAD `6006f28400e8962909afab0744460152353c12fe`; C1–C6 recorded; C7 records this report. No upstream; no push.
- No `*.php` entry in `git status`; `lib/` and `appinfo/` clean.
- Generated `js/`/`css/` output is recorded in C6 (`8869fc6`) after `npm run build` produced output byte-identical to the pre-build backup.
- Pre-existing user changes remain unstaged and preserved: `AGENTS.md`, `CLAUDE.md`, `.claude/`, `docs/Review uploaded pasted content.zip`, Feature 004 tests/reports, the 10 excluded desktop references, generated mobile duplicates, the mobile contact sheet, `_archives/`, screenshots, `test-results/`, and `.env.testing`.
- `l10n/fa.js` and `l10n/fa.json` both carry 362 keys.

## Risks carried forward

| Risk | Note |
|---|---|
| Blocked cards being approximated client-side | Recorded as `FR-005-012/019/020` prohibitions; no workaround task exists in the ledger |
| Contract edited to match implementation | `specs/001-*` and both contracts are named out of scope |
| Report-recorded evidence upgraded to re-executed | Four-tier separation is stated in spec, plan, quickstart, and this report |
| Generated assets mixed with documentation | Resolved: C6 contains only `js/`/`css/`; C1–C5 contain no build output |
| Batch A work loss | Resolved: C5 (`fae7bce`) records the source, tests, and l10n; the working tree retains only excluded Feature 004/user changes |

## Asset availability

`GIT-PLAN-001-R1` includes the design registry, the approved desktop list (`01`), the unsaved-changes dialog, and the six 390px mobile references in C4. The following desktop references remain in the working tree and are required before Batch B:

- `02_sheet_detail_open_EN_reference.png`
- `03_sheet_detail_submitted_cc_EN_reference.png`
- `04_sheet_detail_completed_workflow_EN_reference.png`
- `05_sheet_approvals_tab_EN_reference.png`
- `06_sheet_overview_tab_EN_reference.png`
- `07_sheet_history_tab_EN_reference.png`
- `08_transaction_create_EN_reference.png`
- `09_transaction_correction_EN_reference.png`
- `10_transaction_read_only_EN_reference.png`
- `11_transaction_read_only_revisions_EN_reference.png`

**Gate `G-BB-ASSETS`:** no Batch B implementation commit may start until these ten files are committed, or an owner-approved equivalent handoff exists. Their sizes and SHA-256 values are recorded in `docs/reports/GIT-PLAN-001-R1.md`.

The three generated mobile duplicates and `references/contact-sheets/mobile_contact_sheet.png` are excluded.

## Next actions

1. `GIT-PLAN-001-R1` executed: C1–C6 recorded; C7 records this status. Push/PR is deferred to `GIT-PLAN-002`.
2. Product owner: decide `REC-01`, `P-008` (calendar surface), and the `READY_FOR_OWNER_APPROVAL` cards individually.
3. Product owner: answer the `BE-CR-001/002/003` questions and scope `BE-CR-004/005/006`.
4. Then, and only then, Batch B can be scheduled; no Batch B work is authorized today.
5. Push/merge is not authorized; prepare `GIT-PLAN-002` if the branch should be published.
