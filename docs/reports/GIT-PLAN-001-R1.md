# GIT-PLAN-001-R1 — Purchaser UI Redesign Checkpoint

**Status:** Approved by the product owner on 2026-10-19. Execution results are recorded only after success.
**Branch:** `feat/pettycash-purchaser-ui-batch-a` (existing branch; no switch).
**Baseline:** HEAD `6006f28400e8962909afab0744460152353c12fe`, identical to `origin/main`; no upstream configured.
**Supersedes:** `GIT-PLAN-001` and the checkpoint scope in `research.md` Decision 8.

## 1. Purpose

This plan makes the approved working-tree state durable without mixing Feature 004 work, pre-existing user changes, or unverified build output. It uses exact file allowlists and ordered commits. It does not push, merge, tag, switch branches, or start Batch B.

## 2. Preconditions

These values were verified before the first write. If any value differs at execution time, stop and request renewed authorization.

| Check | Expected value |
|---|---|
| Branch | `feat/pettycash-purchaser-ui-batch-a` |
| HEAD | `6006f28400e8962909afab0744460152353c12fe` |
| Staged paths | `0` |
| Working-tree hash | `381d57aa68dfb0fdceff7772eb3c1b3e316731f3a596bfc1482b65ae13c4201c` |
| `FinanceReview.spec.ts` blob | `8657aeaf9242b34c357d1b1b5cc248133673134f` |
| `ManagementReview.spec.ts` blob | `f071f7196e47272448571f1012a9baebb78b5b66` |
| `FinanceReview.batch-a.patch` SHA-256 | `55d3e6fac5509c9ddc0cee9dfa6ee2ce98656fac175900bfc178e70bda8a2dbb` |
| `ManagementReview.batch-a.patch` SHA-256 | `3ed7fb2791b6568cbe546f5f9c59f00650e4f7fff8bb42083b51618cc3b04a05` |
| Upstream | none |

The working-tree hash is `git status --porcelain -uall | sha256sum` taken before the C0 edits. C0 changes it; that is expected and does not invalidate the baseline.

## 3. Execution order

| Phase | Action | Commit message |
|---|---|---|
| C0 | Documentation alignment; no commit | — |
| C1 | Spec Kit 005 artifacts (8 files) | `docs(spec): register Spec Kit feature 005 for the purchaser UI redesign` |
| C2 | Governance, report, and this plan (6 files) | `docs(governance): reconcile redesign status and record the R1 checkpoint plan` |
| C3 | DeepSeek evidence and Batch A report (36 files) | `docs(evidence): preserve the DeepSeek audit, task cards, and Batch A report` |
| C4 | Manus intake, handoff, prototype, inspected references (53 files) | `docs(design): preserve Manus intake, handoff notes, prototype code, and inspected references` |
| C5 | Batch A source, tests, and l10n (54 paths) | `feat(frontend): implement purchaser UI Batch A` |
| C6 | Generated `js/` and `css/` (638 raw, 381 net) | `chore(frontend): synchronize generated build assets` |
| C7 | Status record with actual SHAs (5 files) | `docs(status): record the executed R1 checkpoint` |

C7 is created only after C1–C6 and the final verification pass succeed. A failure stops the plan; no completion is recorded.

## 4. Exact allowlists

### C1 — Spec Kit 005 (8 files)

```bash
git add -- \
  specs/005-purchaser-ui-redesign/checklists/requirements.md \
  specs/005-purchaser-ui-redesign/contracts/purchaser-ui-contract.md \
  specs/005-purchaser-ui-redesign/data-model.md \
  specs/005-purchaser-ui-redesign/plan.md \
  specs/005-purchaser-ui-redesign/quickstart.md \
  specs/005-purchaser-ui-redesign/research.md \
  specs/005-purchaser-ui-redesign/spec.md \
  specs/005-purchaser-ui-redesign/tasks.md
```

### C2 — Governance and checkpoint plan (6 files)

```bash
git add -- \
  docs/frontend-redesign/CHARTER.md \
  docs/frontend-redesign/DECISIONS.md \
  docs/frontend-redesign/STATUS.md \
  docs/frontend-redesign/ROADMAP.md \
  docs/reports/005-purchaser-ui-redesign.md \
  docs/reports/GIT-PLAN-001-R1.md
```

### C3 — Audit evidence (36 files)

```bash
git add -- \
  docs/frontend-redesign/review/deepseek \
  docs/frontend-redesign/review/bunny/BATCH_A_EXECUTION_REPORT.md
```

This is 35 DeepSeek files plus 1 Batch A report. `docs/frontend-redesign/review/bunny/screenshots/**` is excluded.

### C4 — Design handoff and inspected references (53 files)

```bash
git add -- \
  docs/frontend-redesign/MANUS_INTAKE_MANIFEST.md \
  docs/frontend-redesign/README_MANUS_INTAKE.md \
  docs/frontend-redesign/handoff \
  docs/frontend-redesign/prototype/manus-purchaser-v1 \
  docs/frontend-redesign/references/DESIGN_REFERENCES.md \
  docs/frontend-redesign/references/desktop/01_sheets_list_desktop_EN_approved.png \
  docs/frontend-redesign/references/dialogs/unsaved-changes-en-desktop-v1.png \
  docs/frontend-redesign/references/mobile/sheet-detail-completed-with-rejections-purchaser-mobile-en-v1.png \
  docs/frontend-redesign/references/mobile/sheet-detail-cost-center-review-purchaser-mobile-en-v1.png \
  docs/frontend-redesign/references/mobile/sheet-detail-open-purchaser-mobile-en-v1.png \
  docs/frontend-redesign/references/mobile/sheets-list-purchaser-mobile-en-v1.png \
  docs/frontend-redesign/references/mobile/transaction-add-purchaser-mobile-en-v1.png \
  docs/frontend-redesign/references/mobile/transaction-correction-purchaser-mobile-en-v1.png

git restore --staged \
  'docs/frontend-redesign/prototype/manus-purchaser-v1/assets/generated-mobile/*'
```

The second command removes 4 generated-mobile assets from the index, leaving 18 prototype files. The four files stay in the working tree, untracked.

### C5 — Batch A source, tests, and l10n (54 paths)

```bash
git add -- \
  src/AppRoot.vue \
  src/components/audit/AuditTimeline.vue \
  src/components/audit/RevisionHistory.vue \
  src/components/dashboard/DashboardCard.vue \
  src/components/evidence/EvidenceLinkManagement.vue \
  src/components/evidence/EvidenceList.vue \
  src/components/evidence/EvidencePreview.vue \
  src/components/evidence/EvidenceUploader.vue \
  src/components/reports/ExportControls.vue \
  src/components/reports/PeriodDisclosure.vue \
  src/components/reports/ReportFilterBar.vue \
  src/components/reports/ReportTable.vue \
  src/components/review/EvidenceCard.vue \
  src/components/review/PriorDecisionHistory.vue \
  src/components/review/RevisionInfo.vue \
  src/components/shared/DeleteSheetDialog.vue \
  src/components/shared/ResponsiveTable.vue \
  src/components/shared/StatusBadge.vue \
  src/router/index.ts \
  src/utils/calendar.ts \
  src/utils/dashboard.ts \
  src/utils/date.ts \
  src/utils/direction.ts \
  src/utils/locale.ts \
  src/utils/money.ts \
  src/utils/number.ts \
  src/utils/sheetListSession.ts \
  src/views/accounts/AccountView.vue \
  src/views/admin/ConfigurationView.vue \
  src/views/admin/FundingView.vue \
  src/views/audit/AuditView.vue \
  src/views/DashboardView.vue \
  src/views/reports/ReportView.vue \
  src/views/reviews/CostCenterQueueView.vue \
  src/views/reviews/FinanceQueueView.vue \
  src/views/reviews/FinanceReviewView.vue \
  src/views/reviews/ReturnsQueueView.vue \
  src/views/reviews/SeniorQueueView.vue \
  src/views/reviews/TransactionReviewDetailView.vue \
  src/views/sheets/SheetDetailView.vue \
  src/views/sheets/SheetFormView.vue \
  src/views/sheets/SheetListView.vue \
  src/views/transactions/TransactionDetailView.vue \
  src/views/transactions/TransactionFormView.vue \
  src/views/transactions/TransactionListView.vue \
  l10n/fa.js \
  l10n/fa.json \
  tests/Frontend/Component/PurchaserFlow.spec.ts \
  tests/Frontend/Component/RtlAccessibility.spec.ts \
  tests/Frontend/Component/SheetDeletion.spec.ts \
  tests/Frontend/routeNameIntegrity.spec.ts \
  tests/Frontend/localeFormatting.spec.ts

git apply --cached /tmp/opencode/r1/FinanceReview.batch-a.patch
git apply --cached /tmp/opencode/r1/ManagementReview.batch-a.patch
```

The two patches add one language-mock hunk each. They do not add the Feature 004 hunks.

### C6 — Generated build output

```bash
git add -- js css
```

This stages 638 raw paths; rename detection reports 381 net. `*.map` files are ignored. C6 runs only after the V4 check passes.

### C7 — Status record (5 files)

```bash
git add -- \
  docs/frontend-redesign/STATUS.md \
  docs/frontend-redesign/ROADMAP.md \
  specs/005-purchaser-ui-redesign/spec.md \
  docs/reports/005-purchaser-ui-redesign.md \
  docs/reports/GIT-PLAN-001-R1.md
```

## 5. Build verification (V4)

Before C6:

1. Back up the current output:

   ```bash
   tar -C /home/ubuntu/projects/pettycash -cf /tmp/opencode/r1/js-css-before.tar js css
   ```

2. Run the repository build on the host:

   ```bash
   npm run build
   ```

3. Compare content. Map files are ignored by Git but are included in the comparison:

   ```bash
   rm -rf /tmp/opencode/r1/js-css-before
   mkdir -p /tmp/opencode/r1/js-css-before
   tar -C /tmp/opencode/r1/js-css-before -xf /tmp/opencode/r1/js-css-before.tar
   diff -r /tmp/opencode/r1/js-css-before/js js
   diff -r /tmp/opencode/r1/js-css-before/css css
   ```

4. Proceed only if both diffs are empty. If either differs, stop, do not stage `js/` or `css/`, and report the difference.

The source/build relationship was already checked before this plan: the latest source mtime is 16:01, the bundle entry mtime is 16:08:24, and all entry imports resolve.

## 6. Batch B asset gate (`G-BB-ASSETS`)

The following 10 desktop references are not part of R1. They remain in the working tree, untouched and untracked. No Batch B implementation commit may start until they are committed, or an owner-approved equivalent handoff exists.

| File | Screen | Size (bytes) | SHA-256 |
|---|---|---|---|
| `02_sheet_detail_open_EN_reference.png` | Sheet Detail: OPEN | 1,395,871 | `7596831907f79a7ce506ff86eaf904955c6b2a4de3ddefa34182d9b75b3b903e` |
| `03_sheet_detail_submitted_cc_EN_reference.png` | Submitted / Cost Center review | 1,451,295 | `e3c7f276a6ded848b6f938fca07455cf402574f002858be5ba7fcbdac0bd486e` |
| `04_sheet_detail_completed_workflow_EN_reference.png` | Completed and stage summaries | 1,467,431 | `0f5846e52663583416b6dde6d00a31472e74cb1c9ab845cd38bff6acd5d3e0e4` |
| `05_sheet_approvals_tab_EN_reference.png` | Approvals tab | 1,516,690 | `3bfa4b80180f08841d2db9a28a1fb848918f7eab9c8bb3eb05b5f1a0d865e764` |
| `06_sheet_overview_tab_EN_reference.png` | Overview tab | 1,429,031 | `bdd65302742f73155df344dd77c92937ab25e61a41c1482534390e297dd8ba79` |
| `07_sheet_history_tab_EN_reference.png` | History tab | 1,513,502 | `6cf399ce1033cd5d548e62941e34e387dcba34fd5ee26bebbd421b8f26e71cf1` |
| `08_transaction_create_EN_reference.png` | Add Transaction | 1,341,091 | `37db9e9ef22f7eb4cd4f11d9c11b29243158e144e4ce7d6c906ec0f322f549d3` |
| `09_transaction_correction_EN_reference.png` | Returned Transaction correction | 1,368,679 | `d9dec46ea57eed02e58260457e507945a3bd6fa858d8b20fe11e89427ab9cecb` |
| `10_transaction_read_only_EN_reference.png` | View Transaction (read-only) | 1,393,013 | `a435feb8402623d8fd0f2190310603ffcfa13c692a725a2f50931178d184e97d` |
| `11_transaction_read_only_revisions_EN_reference.png` | View Transaction with revisions | 1,412,874 | `2f38d97688464560720a1302bfe34e389de866bb7a6275a855f79137fbb2d9e7` |

Included in C4: `01_sheets_list_desktop_EN_approved.png`, the unsaved-changes dialog, and the six 390px mobile references. The three generated mobile duplicates and `references/contact-sheets/mobile_contact_sheet.png` are excluded.

## 7. Excluded paths

- `AGENTS.md`, `CLAUDE.md`, `.claude/**`
- `docs/Review uploaded pasted content.zip`
- `docs/reports/004-in-app-navigation.md`
- `specs/004-in-app-navigation/**`
- `tests/Frontend/Component/Administration.spec.ts`
- `tests/Frontend/Component/Dashboard.spec.ts`
- `tests/Frontend/Component/Navigation.spec.ts`
- Feature 004 hunks in `FinanceReview.spec.ts` and `ManagementReview.spec.ts`
- `docs/frontend-redesign/review/bunny/screenshots/**`
- `_archives/**`
- `test-results/**`
- `.env.testing`
- The 10 desktop references listed in section 6
- The 3 generated mobile duplicates
- `references/contact-sheets/mobile_contact_sheet.png`
- `prototype/manus-purchaser-v1/assets/generated-mobile/**`
- `.specify/feature.json` (gitignored)
- `*.map` (ignored)

## 8. Safety rules

- Never use `git add .`, `git add -A`, `git reset --hard`, `git clean -fd`, force push, branch switch, merge, or tag.
- Stage only the exact allowlists above.
- Before every commit, run `git diff --cached --name-status`, a forbidden-prefix check, and `git diff --cached --check`.
- Stop on any precondition drift, unexpected staged path, failed targeted test, or build mismatch.
- Do not commit a red test run.
- Do not create C7 until C1–C6 and the final verification pass succeed.
- Do not push. Do not start Batch B.

## 9. Verification checklist

| Check | Expected |
|---|---|
| C1 staged paths | 8 |
| C2 staged paths | 6 |
| C3 staged paths | 36 |
| C4 staged paths | 53 (after the generated-mobile unstage) |
| C5 staged paths | 54; exactly 1 hunk per patched test; zero `FR-004` additions |
| C6 staged paths | 638 raw; 381 net after rename detection; no source, test, or doc path |
| C7 staged paths | 5; docs only |
| Forbidden prefixes | none in any commit |
| Whitespace | `git diff --cached --check` clean |
| Final state | 7 commits ahead of `origin/main`; no upstream; no push |

## 10. Execution result

Pending. C7 records the actual SHAs, commit subjects, validation results, and final `git status` only after C1–C6 succeed.
