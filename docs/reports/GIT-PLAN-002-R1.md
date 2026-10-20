# GIT-PLAN-002-R1 — Purchaser UI Batch B Frontend Checkpoint

**Status:** **Approved by the product owner on 2026-10-20 and executed the same day.** Results are recorded in §8; no push was performed.
**Branch:** `feat/pettycash-purchaser-ui-batch-a` (existing branch; no switch).
**Baseline:** HEAD `a70a9bf`; staged paths `0`.
**Follows:** `GIT-PLAN-001-R1` (Batch A checkpoint, executed 2026-10-19).

## 1. Purpose

Record the 2026-10-20 Batch B **frontend** checkpoint — `T029–T038` and `T022` — without mixing pre-existing Feature 004/user changes, generated output, unapproved backend work, or the deferred English catalog. The plan uses exact allowlists, does not push, merge, tag, switch branches, or claim product-owner visual acceptance (`T051`).

## 2. Preconditions

| Check | Expected value |
|---|---|
| Branch | `feat/pettycash-purchaser-ui-batch-a` |
| HEAD | `a70a9bf` |
| Staged paths | `0` |
| Batch B code gate | `npm run check` passed 2026-10-20 (full suite 524/524; targeted 9 files/222 tests; build) |
| Browser evidence | live `fa` RTL and `en` LTR desktop/mobile; calendar cross-check; zero console/page errors |
| Backend | no `lib/`, `appinfo/`, `specs/001-*`, or contract change |
| English catalog | no `l10n/en.*`; `T023`/`REC-01` remains open |
| Owner visual acceptance | `T051` remains open; this plan does not claim it |
| Git writes | none before approval; no push/merge/tag |

If the branch, HEAD, or staged count differs at execution time, stop and request renewed authorization.

## 3. Approved execution order

| Phase | Action | Commit message |
|---|---|---|
| C1 | Batch B source, tests, and l10n (18 paths) | `feat(frontend): implement purchaser UI Batch B` |
| C2 | Governance, canonical ledger, report, and this plan (6 paths) | `docs(governance): record Batch B frontend verification` |
| C3 | Generated `js/` and `css/` — approved (included) | `chore(frontend): synchronize generated build assets` |
| C4 | Ten desktop design references — approved (included) | `docs(design): add approved desktop design references` |
| C5 | Plan result and status sync with actual SHAs | `docs(status): record the executed Batch B checkpoint` |

C5 runs only after C1–C4 (as approved) and the final checks succeed. A failure stops the plan; no completion is recorded.

## 4. Exact allowlists

### C1 — Batch B source, tests, and l10n (18 paths)

```bash
git add -- \
  src/components/evidence/EvidenceUploader.vue \
  src/components/shared/ResponsiveTable.vue \
  src/components/shared/UnsavedChangesDialog.vue \
  src/composables/useSheetHistory.ts \
  src/composables/useUnsavedChangesGuard.ts \
  src/router/index.ts \
  src/styles/app.scss \
  src/views/sheets/SheetDetailView.vue \
  src/views/sheets/SheetFormView.vue \
  src/views/sheets/SheetListView.vue \
  src/views/sheets/workspaceTabs.ts \
  src/views/transactions/TransactionFormView.vue \
  l10n/fa.js \
  l10n/fa.json \
  tests/Frontend/Component/PurchaserFlow.spec.ts \
  tests/Frontend/Component/SheetDeletion.spec.ts \
  tests/Frontend/Component/SheetWorkspace.spec.ts \
  tests/Frontend/Composables/UnsavedChangesGuard.spec.ts
```

### C2 — Governance, ledger, report, and plan (6 paths)

```bash
git add -- \
  docs/frontend-redesign/DECISIONS.md \
  docs/frontend-redesign/ROADMAP.md \
  docs/frontend-redesign/STATUS.md \
  docs/reports/005-purchaser-ui-redesign.md \
  docs/reports/GIT-PLAN-002-R1.md \
  specs/005-purchaser-ui-redesign/tasks.md
```

### C3 — Generated build output (approved)

```bash
git add -- js css
```

This stages the refreshed hashed chunks, the old-chunk deletions, and `css/pettycash-main.css`. `*.map` files are ignored. Run the repeat-build comparison in §5 before staging.

### C4 — Ten desktop design references (approved)

```bash
git add -- \
  docs/frontend-redesign/references/desktop/02_sheet_detail_open_EN_reference.png \
  docs/frontend-redesign/references/desktop/03_sheet_detail_submitted_cc_EN_reference.png \
  docs/frontend-redesign/references/desktop/04_sheet_detail_completed_workflow_EN_reference.png \
  docs/frontend-redesign/references/desktop/05_sheet_approvals_tab_EN_reference.png \
  docs/frontend-redesign/references/desktop/06_sheet_overview_tab_EN_reference.png \
  docs/frontend-redesign/references/desktop/07_sheet_history_tab_EN_reference.png \
  docs/frontend-redesign/references/desktop/08_transaction_create_EN_reference.png \
  docs/frontend-redesign/references/desktop/09_transaction_correction_EN_reference.png \
  docs/frontend-redesign/references/desktop/10_transaction_read_only_EN_reference.png \
  docs/frontend-redesign/references/desktop/11_transaction_read_only_revisions_EN_reference.png
```

C4 was executed under the owner's approval; the ten files are committed.

### C5 — Executed-status record (2 paths)

```bash
git add -- \
  docs/frontend-redesign/STATUS.md \
  docs/reports/GIT-PLAN-002-R1.md
```

C5 updates the plan's status/results and the `STATUS.md` repository-sync line with the actual SHAs.

## 5. Verification gate before C3

The current `js/` and `css/` output was produced by the passing `npm run check`. Before staging it:

1. Back up `js/` and `css/` to `/tmp/opencode/batch-b-build/`.
2. Run `npm run build` on the host.
3. Diff the backup against the new `js/` and `css/`.
4. Stage C3 only if both diffs are empty. If either differs, stop, do not stage `js/`/`css/`, and report the difference.

**Result (2026-10-20):** the repeat build produced empty `js/` and `css/` diffs; C3 was staged and committed.

C1/C2 do not depend on this gate; C3 does.

## 6. Owner choices (resolved)

The product owner approved the plan on 2026-10-20 and selected both included options.

1. **Generated assets.** Include `js/`/`css/` in C3 (recommended: the repository then matches the verified build) or exclude them. Excluding leaves the refreshed output only in the working tree; the old tracked chunks remain deleted there.
2. **`G-BB-ASSETS`.** Include the ten desktop references in C4 (recommended: they are the approved design references already used for Batch B) or confirm an owner-approved equivalent handoff and skip C4. The 2026-10-20 directive authorizes implementation; it is not by itself an explicit asset handoff.
3. **Approval.** `APPROVE GIT-PLAN-002-R1` was received with both included options on 2026-10-20.

## 7. Exclusions

- Pre-existing/unrelated working-tree changes: `AGENTS.md`, `docs/reports/004-in-app-navigation.md`, `specs/004-in-app-navigation/tasks.md`, and `tests/Frontend/Component/{Administration,Dashboard,FinanceReview,ManagementReview,Navigation}.spec.ts`.
- Untracked tooling/evidence not in this checkpoint: `.claude/skills/**`, `CLAUDE.md`, `docs/Review uploaded pasted content.zip`, `docs/frontend-redesign/_archives/**`, generated mobile duplicates, `docs/frontend-redesign/references/contact-sheets/**`, Batch A screenshots, `test-results/**`, `.env*`.
- Backend and contracts: no `lib/`, `appinfo/`, `specs/001-*`, OpenAPI, or workflow-contract change.
- English catalog: no `l10n/en.*`; `REC-01` remains open.
- The ten desktop references are included in C4 under the 2026-10-20 approval; the `01` reference was already committed.
- No push, merge, tag, branch switch, or history rewrite.

## 8. Result

**Executed on 2026-10-20.** The product owner approved the plan and selected both included options: C3 generated assets and C4 ten desktop references.

| Phase | Commit | Result |
|---|---|---|
| C1 | `8ccadea` | 18 source/test/l10n paths committed |
| C2 | `c16a0c3` | 6 governance/ledger/report/plan paths committed |
| C3 | `6ff72cc` | 418 generated `js/`/`css/` paths committed after the repeat-build gate |
| C4 | `4a28aea` | 10 desktop design references committed |
| C5 | this status commit | 2 plan/status paths; the SHA is reported in the execution response and is not self-recorded |

**Repeat-build gate:** after backing up `js/` and `css/`, `npm run build` completed successfully and `diff -r` reported both trees identical; only then was C3 staged.

**Repository state:** branch `feat/pettycash-purchaser-ui-batch-a`; C1–C4 sit on baseline `a70a9bf`; C5 carries this record. No push, merge, tag, branch switch, or history rewrite was performed. The pre-existing unrelated changes listed in §7 remain uncommitted. `T051`, `REC-01`/`T023`, `T017`/`T018`, the backend cards, and the six `BE-CR`s remain outside this checkpoint.
