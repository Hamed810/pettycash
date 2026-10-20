# 005-purchaser-ui-redesign — Spec Kit management migration

**Date:** 2026-10-19 (management baseline); **Batch B update:** 2026-10-20
**Spec:** `specs/005-purchaser-ui-redesign/` (imported audit cards → spec → research/plan/data-model/contracts/quickstart/checklist → canonical task ledger)
**Status:** management baseline complete (2026-10-19); **Batch B frontend implemented and verified 2026-10-20** under the owner directive. Backend/API/contracts and **all Git writes** remain blocked; the English catalog and English visual acceptance remain blocked on `REC-01`; all `BE-CR-001…006` remain blocked; owner visual acceptance (`T051`) is pending. The separate `GIT-PLAN-001-R1` checkpoint recorded C1–C6.

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

## Card disposition recorded (2026-10-19 baseline)

**Historical.** The 2026-10-19 dispositions below are superseded by the 2026-10-20 Batch B section and by the canonical ledger; they are kept because the baseline is part of the Git plan record.

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

## Batch B frontend implementation and verification (2026-10-20)

**Authority.** The owner directive of 2026-10-20 authorized the approved Purchaser **frontend** work in the real Nextcloud app, through the existing cards, with verification in the running lab: Batch B **T029–T038 plus T022**. Backend changes (`lib/`, `appinfo/`, API/contracts) and every Git write remain blocked. The English catalog remains deferred (`REC-01`), and no visible calendar toggle is authorized (`FR-005-004` / T017).

**Delivered.**

- **Forms and correctness (T029–T033):** cost-center preselect from the most recent active revision (no overwrite), no create-route uploader (save-first hint; empty-ID uploader fails closed), DRAFT-only versioned delete, the save/discard/stay guard with `beforeunload`/route guard/409 refetch, and the canonical PATCH correction path with the live version.
- **Readiness and workspace (T034–T037):** server-tier submission readiness; the `/sheets/:id` overlay workspace with dialog semantics, focus entry/containment/return, Escape/scrim, four tabs with per-sheet memory, the 9-column transaction table, per-stage approvals, Overview, and History from `AuditEvent.after.decision` with honest paging.
- **List and filters (T022):** creation-date filter labels («ایجادشده از تاریخ / تا تاریخ») with the active-calendar hint, plus the 390px shell/filter/table fixes.
- **Parity (T038):** shipped surfaces use theme variables only; localized `StatusBadge`, no-close chips, reduced-motion-aware focus, and responsive desktop/mobile layouts in both directions.

**Verification.**

- `npm run check` passed on rerun: portability, typecheck, lint, stylelint, full suite **524/524**, build, notices. A first full run hit two 5s `PurchaserFlow` timeouts under load; the targeted `PurchaserFlow` run passed **150/150** and the rerun passed.
- Targeted: **9 files / 222 tests** (`SheetWorkspace`, `PurchaserFlow`, `SheetDeletion`, `RtlAccessibility`, `typography`, `l10nCatalog`, `fr071NarrowViewport`, `UnsavedChangesGuard`, `TransactionCorrection`).
- Live `fa` RTL at `http://localhost:8081`: overlay dialog, 4 tabs, 3 approval stages, 3 history events; zero console/page errors; 390×844 full-screen with no overflow; the list shell is 390px with one-column filters; the workspace table fits the dialog content with no horizontal scroll.
- Live `en` LTR: `dir=ltr` on desktop and mobile, no overflow, zero errors; the date reads `10/20/2026`. Raw keys are expected because no `l10n/en.*` exists; that is the measured `REC-01` consequence, not an English visual acceptance.
- Calendar independence: `fa` + Gregorian gives `۲۰۲۶/۱۰/۲۰`; `fa` + Jalali gives `۱۴۰۵/۷/۲۸`; filter hints match. Language did not select the calendar.

**Still blocked or open.**

- `T017` (visible calendar surface) and `T018` (mutating negative test) remain owner-gated; no control was added.
- `T023`/`REC-01`: English catalog sourcing and English visual acceptance.
- `T028` (`BE-CR-002`), `T039` (`BE-CR-001`), `T040` (`BE-CR-003`), `T044` (`BE-CR-004`), and `T045/T046` (`BE-CR-005/006`).
- `T051`: product-owner visual acceptance per card. The screenshots and live checks support it; they do not self-certify it.
- All Git writes.

**Git plan request (not executed).** Branch `feat/pettycash-purchaser-ui-batch-a`, HEAD `a70a9bf`; no staging or commit was performed. The proposed numbered plan is [`docs/reports/GIT-PLAN-002-R1.md`](GIT-PLAN-002-R1.md); it records the exact allowlists and the two blocking owner choices. The Batch B source/test/l10n/documentation change set is uncommitted; the build refreshed `js/` and `css/` (old hashed chunks deleted, new hashed chunks untracked, `css/pettycash-main.css` modified), and those generated assets need an explicit include/exclude decision. Pre-existing unrelated changes are preserved and excluded from any Batch B staging: `AGENTS.md`, `docs/reports/004-in-app-navigation.md`, `specs/004-in-app-navigation/tasks.md`, and the Feature 004 test modifications (`Administration`, `Dashboard`, `FinanceReview`, `ManagementReview`, `Navigation`). `G-BB-ASSETS` remains a commit gate: the ten desktop references are present in the working tree, but the directive authorizes implementation, not an explicit asset handoff; the Git approval must either include them or confirm the handoff. No push is authorized.

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

**Gate `G-BB-ASSETS`:** no Batch B commit may be created until these ten files are committed, or an owner-approved equivalent handoff exists. The 2026-10-20 directive authorizes implementation, not an explicit asset handoff; the commit decision still needs the files included or the owner's confirmation. Their sizes and SHA-256 values are recorded in `docs/reports/GIT-PLAN-001-R1.md`.

The three generated mobile duplicates and `references/contact-sheets/mobile_contact_sheet.png` are excluded.

## Next actions

1. Product owner: review the Batch B screenshots and record `T051` visual acceptance per completed card; the live checks support acceptance but cannot self-certify it.
2. Product owner: decide `REC-01`/`P-005` and `P-008` (calendar surface); authorize or decline `T017`/`T018`.
3. Product owner: approve the exact Git plan for the Batch B source/test/l10n/docs change set, the generated `js/`/`css/` include/exclude decision, and the `G-BB-ASSETS` treatment; no staging, commit, or push has been performed.
4. Backend owner: answer the `BE-CR-001/002/003` questions and scope `BE-CR-004/005/006`; only then can `T028`/`T039`/`T040`/`T044`/`T045`/`T046` proceed.
5. Push/merge remains unauthorized; `GIT-PLAN-003` for push/PR is not prepared; the checkpoint plan `GIT-PLAN-002-R1` is proposed.
