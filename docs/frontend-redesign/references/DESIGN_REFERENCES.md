# Design references — فایل‌های تصویری

All files in `references/` are **copy snapshots from this conversation**. They are **visual/UI direction only**; fake data, approvals, legal permissions and even some visible controls may be contradictory to Spec Kit. Do not implement business behavior from pixels.

| File | Screen | Status | Important caution |
|---|---|---|---|
| `01_sheets_list_desktop_EN_approved.png` | Purchaser Sheets List EN desktop | **Owner FINAL visual** | Preserve title above sheet ID, six columns, hover/no chevron; date examples may be inaccurate |
| `02_sheet_detail_open_EN_reference.png` | Sheet Detail: OPEN | **Accepted visual direction** | OPEN means never submitted; all transactions Draft; actual count/totals must reconcile |
| `03_sheet_detail_submitted_cc_EN_reference.png` | Submitted/in Cost Center review | **Accepted visual direction** | Can't add/edit/delete; show stage-specific decisions |
| `04_sheet_detail_completed_workflow_EN_reference.png` | Completed + stage summaries | **Styling reference / data NOT approved** | Returned transaction shown under completed and stage values inconsistent; must fix |
| `05_sheet_approvals_tab_EN_reference.png` | Approvals tab | **Visually accepted, semantics need correction** | Compute approvals for each actual stage, no bulk/partial decisions |
| `06_sheet_overview_tab_EN_reference.png` | Overview + by Cost Center | **Visually accepted, semantics need correction** | Duplicate metadata, cost-center aggregates must reconcile with rows |
| `07_sheet_history_tab_EN_reference.png` | History + transaction audit timeline | **Visually accepted, semantics need correction** | Per-transaction revisions/audit only; no invented sheet-level audit API |
| `08_transaction_create_EN_reference.png` | Add Transaction | **Visually accepted, source semantics incorrect** | Submitted banner invalid for add; Vendor/Notes & arbitrary file policy unverified; evidence after create |
| `09_transaction_correction_EN_reference.png` | Returned Transaction correction | **Visually accepted, source semantics need correction** | Resubmit is separate from PATCH Save; no new fields |
| `10_transaction_read_only_EN_reference.png` | View Transaction (read-only) | **Visually accepted** | Must show actual active revision and permitted evidence actions |
| `11_transaction_read_only_revisions_EN_reference.png` | View Transaction with revision history | **Visually accepted** | Rev/audit data must come from existing per-transaction endpoints |

**Not yet approved:** Unsaved Changes V3, all 390px purchaser mobile views, final interactive Manus prototype, implementation.

## R1 checkpoint scope (2026-10-19)

`GIT-PLAN-001-R1` includes this registry, the approved desktop list (`01`), the unsaved-changes dialog, and the six 390px mobile references in C4. The remaining ten desktop references (`02`–`11`) stay in the working tree for Batch B. Their sizes and SHA-256 values are recorded in `docs/reports/GIT-PLAN-001-R1.md`.

**Batch B gate `G-BB-ASSETS`:** no Batch B implementation commit may start until `02`–`11` are committed or an owner-approved equivalent handoff exists.

**Image style:** clean Pettycash/Budget-inspired design; pale backgrounds and green CTA; Nextcloud-compatible, responsive, light/dark, modest hover elevation, clean typography.
