# Research Notes: Finance Manager Expense Review (Feature 009)

**Feature**: `009-finance-manager-expense-review` | **Date**: 2026-11-19 | **Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

**Purpose**: Phase 0 research notes recording evidence routes, field sources, and gaps discovered during planning. These notes support the plan; they are not requirements and do not restate the spec.

---

## 1. Evidence Routes (verified in source)

| Route | Source | Status | Notes |
|---|---|---|---|
| `GET /api/v1/queues/{stage}` | `appinfo/routes.php:61` (`review#index`) | Existing | Covers `stage: finance`; returns `TransactionPage` (`Page<TransactionSummary>`). Flat, paginated transaction collection. No sheet grouping. |
| `GET /api/v1/transactions/{id}` | `appinfo/routes.php` (via `TransactionController::show`) | Existing | Returns `TransactionDetail` with `allowedActions`, `decisions[]`, `evidence[]`. Reused by `FinanceReviewView.vue`. |
| `POST /api/v1/transactions/{id}/decisions` | `appinfo/routes.php` (`review#decide`) | Existing | Single-transaction decision command; FR-084 replay safety, FR-085 first-write-wins, mandatory explanation for REJECT/RETURN. |
| `GET /evidence/{id}/content` | `EvidenceController` | Existing | Evidence content delivery; FR-060 inline-safe policy. Reused by `EvidencePreview.vue`. |
| `GET /api/v1/queues/finance/sheets` | — | Does not exist | No sheet-scoped finance read. Option B candidate for a future, separately-authorized batch. Not required at this stage (spec FR-009-026). |

## 2. Field Sources (verified in `src/types/api.ts`)

| Field | Source type | Available in flat `GET /queues/finance` (`TransactionSummary`) | Notes |
|---|---|---|---|
| Cost Sheet reference | `sheetId` | Yes (`TransactionSummary.sheetId`, `:185`) | Always available; basis for grouping. |
| Finance-eligible transaction count | derived | Yes | Count of de-duplicated `sheetId` group members. |
| Per-currency monetary summary | `activeRevision.amount` | Yes | Group by `currency`+`minorUnit`; no cross-currency sum. |
| Latest activity date | `activeRevision.createdAt` | Yes | Max per sheet; `null` → unavailable marker. |
| Sheet title | — | No | Not in `TransactionSummary`; honest unavailable until additive backend read. |
| Submitter/purchaser | — | No | Not in `TransactionSummary`; honest unavailable. |
| Submitted date | — | No | Not in `TransactionSummary`; honest unavailable. |
| Review status | `stage`/`status` | Partial | `TransactionSummary.stage` = `FINANCE`; `status` distinguishes `SENIOR_APPROVED`/`SENIOR_REJECTED`. Sheet-wide status not derivable client-side. |
| Prior Cost Center decision | `decisions[]` (stage `COST_CENTER`) | No (summary only) | Available in `TransactionDetail` per transaction; rendered in modal. |
| Prior Senior decision | `decisions[]` (stage `SENIOR`) | No (summary only) | Available in `TransactionDetail` per transaction; rendered in modal. |
| Finance decision | `decisions[]` (stage `FINANCE`) | `latestDecision?` | `latestDecision` is optional on summary; modal re-reads detail for full history. |
| Evidence | `evidenceCount` / `evidence[]` | Count only on summary | Full `evidence[]` in `TransactionDetail` per transaction; bound to transaction in modal. |
| Decision capability | `allowedActions` | No (summary only) | In `TransactionDetail` per transaction; gates Approve/Reject/Return per `decisionAllowed`. |

## 3. Gaps (honest, not requirements)

| Gap | Impact | Disposition in plan |
|---|---|---|
| Sheet title/purchaser/submitted date not in flat queue | Row fields shown as unavailable | Honest placeholders (spec FR-009-004/011); additive backend read deferred |
| No sheet-scoped finance endpoint | Grouping must be frontend all-pages over flat queue | Option A chosen; completeness guard proves the set |
| `TransactionSummary.latestDecision` optional | Queue row can't show latest decision reliably | Not shown on row; prior decisions shown per-transaction in modal from `TransactionDetail.decisions[]` |
| Transaction-level filter/sort not required by spec | Modal lists all finance-eligible transactions in deterministic order | Deferred (plan D6); not copied from Senior automatically |

## 4. Reuse Verification (components confirmed present)

| Component | Path | Role in Finance flow |
|---|---|---|
| `QuickReviewCard.vue` | `src/components/review/QuickReviewCard.vue` | Per-transaction card; props `transaction`, `evidence`, `allowedActions`, `busy`; emits `decide`/`openReview`/`openDetails`/`openPreview` |
| `TransactionDetailsDrawer.vue` | `src/components/review/TransactionDetailsDrawer.vue` | Full detail drawer; Description + History tabs; footer decide controls |
| `QuickDecisionModal.vue` | `src/components/review/QuickDecisionModal.vue` | Decision confirmation; per-kind copy; mandatory comment; `expectedVersion`; submitting state |
| `EvidencePreview.vue` | `src/components/evidence/EvidencePreview.vue` | Preview overlay; images/PDF/unknown; Previous/Next + `1/N`; download fallback |
| `DecisionForm.vue` | `src/components/review/DecisionForm.vue` | Decision form used by `FinanceReviewView.vue`; reusable semantics |
| `useSheetQueue.ts` | `src/composables/useSheetQueue.ts` | Completeness guard, dedupe, grouping, per-currency totals — extended, not re-implemented |
| `SeniorQueueView.vue` / `SeniorSheetModal.vue` | `src/views/reviews/`, `src/components/review/` | Modal-over-mounted-queue + focus-restore pattern |

## 5. Authorization Sources (verified)

| Source | Path | Role |
|---|---|---|
| `FinanceDecisionPolicy` | `lib/Domain/Workflow/FinanceDecisionPolicy.php` | `canApprove` only on `SENIOR_APPROVED`; `canReject`/`canReturn` on `SENIOR_APPROVED`/`SENIOR_REJECTED`/`AWAITING_FINANCE_REVIEW`. FR-039 enforced server-side. |
| `allowedActions` | `TransactionDetail.allowedActions` | `AllowedActions` (`canApprove`/`canReject`/`canReturn`); gated via `decisionAllowed(...) === true` (T026). |
| Finance role | `Role.FINANCE` (`types/api.ts:768`) | Existing role; scope unchanged by this feature (spec FR-009-023). |

## 6. Contract / Schema Notes

- `GET /api/v1/queues/{stage}` already covers finance (`appinfo/routes.php:61`). No contract change required at this stage (Option A).
- `POST /api/v1/transactions/{id}/decisions` already exists; unchanged.
- `types/api.ts` `TransactionSummary.sheetId` (`:185`) enables grouping without schema change.
- Generated types (`src/generated/api.ts`, `src/types/api.ts`) extended additively only for frontend presentation types; no contract-driven regeneration required.

## 7. Visual Baseline

| Baseline | Path | Use |
|---|---|---|
| `CostCenterQueueView.vue` | `src/views/reviews/CostCenterQueueView.vue` | Visual language baseline (006/007); Nextcloud shell, tokens, `ResponsiveTable`, focus treatment |
| `FinanceReviewView.vue` | `src/views/reviews/FinanceReviewView.vue` | Per-transaction Finance decision surface; `approveForbiddenReason` for senior-rejected; prior-decision history |
| `SeniorSheetModal.vue` | `src/components/review/SeniorSheetModal.vue` | Modal shell + focus-restore pattern (adapted, simplified for Finance: no export toolbar) |

---

## 8. Open Research Items (planning notes, not owner decisions)

None requiring owner decision. All field sources and gaps are resolved by Option A + honest-unavailable rendering per the spec. The only deferred item (additive Finance sheet read for title/purchaser/submitted date) is explicitly a planning decision, not a product requirement.
