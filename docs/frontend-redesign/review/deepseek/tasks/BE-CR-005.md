# BE-CR-005 — Keep the sheet's status derived, not stale

- **Type:** backend behaviour change request · **Status:** **BLOCKED_PENDING_OWNER_APPROVAL**
- **Priority:** P1 · **Size:** S–M · **Risk:** medium (touches every decision path and the submission path)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-021, GAP-040 (hardening) · **Frontend consumer:** the sheet list and workspace status display (`UI-PUR-002`, `UI-PUR-005`)

> **Change request, not an implementation ticket.** It adds writes on paths that currently do not write, so it must be reviewed for idempotency, locking and audit consequences.

## 1. Why it is needed

`contracts/workflow.md:110-113` requires the sheet's status to be a **derived projection** of its transactions ("the earliest still-outstanding stage"). The derivation exists and is correct:

- ranking and minimum over the sheet's transactions — `lib/Domain/Expense/ExpenseSheet.php:133-151`;
- stage → sheet status mapping — `:154-162`;
- all-terminal override producing `COMPLETED` / `COMPLETED_WITH_REJECTIONS` — `:164-179`.

But it is invoked from exactly one place:

```
lib/Application/Workflow/FinanceSettlementService.php:408  →  :524-536
```

`ExpenseSheet::recalculateStatus()` has **no other caller** (verified by search over `lib/`). Neither `ManagementReviewService` (cost-center and senior decisions) nor `ResubmitCorrection` recomputes it, and neither does the submission path.

**Consequence, stated concretely:** a purchaser's sheet can be approved by the cost-center manager and still show its previous status in the sheet list, which is the purchaser's primary surface. A returned transaction likewise does not move the sheet into its correction status until finance touches something on that sheet — which for an early-stage return may be never.

This is a display defect over correct data, and it also weakens the terminal invariant (GAP-040): the completion gate is sound, but the *status field* it produces can lag the facts it summarises, so a sheet can transiently read as mid-review while every transaction is terminal.

## 2. Requested change

1. Recompute the sheet's derived status **inside the same transaction as every write that can change it**:
   - every decision recorded at any stage (`ManagementReviewService::recordDecision` → `recordDecision` path),
   - a correction resubmission (`ResubmitCorrection`, which activates a revision and returns the transaction to the cost-center stage),
   - submission (`SubmitSheet`, which moves every transaction out of draft),
   - draft transaction creation/deletion (which can move a sheet between "has transactions" and "has none" — relevant to `UI-PUR-016`'s readiness surface rather than to the review stages).
2. Make it impossible to forget: prefer a single place where a transaction's state changes are flushed to its sheet over four independent call sites. If that is not feasible without a broader refactor, add an explicit assertion/guard so a new decision path fails loudly rather than silently skipping the recomputation.
3. **Do not change the derivation rule itself.** The min-rank rule, the stage mapping and the terminal override stay exactly as they are — this change request is about *when* it runs, not *what* it computes.
4. Keep the finance-settlement call as a no-op-when-already-current path (it must remain correct under repeated invocation).

## 3. Endpoint / contract / test impact

| Item | Impact |
|---|---|
| `POST /api/v1/transactions/{transactionId}/decisions` | the response's transaction/sheet status becomes current; no shape change |
| `POST /api/v1/transactions/{transactionId}/resubmit` | same |
| `POST /api/v1/sheets/{sheetId}/submit` | same |
| `GET /api/v1/sheets`, `GET /api/v1/sheets/{sheetId}` | same payload, current values |
| Contract | **no field changes**; `contracts/workflow.md:110-113` already states the required behaviour, so this request brings the implementation into line with the existing contract. The `ocs-api.yaml` descriptions may need a sentence confirming that the returned status is current. |
| New tests (integration) | after a cost-center decision, `GET /sheets/{id}` reports the derived status **without** any finance operation having occurred; after a return at any stage, the sheet reports its correction status; after resubmission, the sheet reports the cost-center stage; after submission, it leaves OPEN |
| New tests (integration) | repeated/duplicate decision submissions (existing replay semantics — `tests/Integration/Workflow/ManagementDecisionTest.php:604-637`) still produce one recomputation and one audit trail |
| New tests (unit) | the derivation function is unchanged (existing `tests/Unit/Domain/Workflow/ManagementWorkflowTest.php:170` pins the earliest-outstanding rule) |
| Existing tests | any test that asserts a sheet status only after a finance operation must be checked for accidental dependency on the bug (i.e. it may pass today *because* the recomputation is delayed) |

## 4. Risks and why they are acceptable

| Risk | Mitigation |
|---|---|
| An extra write on every decision could change the audit trail | the recomputation writes only the sheet's `status`/`lockVersion`/`updatedAt` fields; the audit events for decisions are unchanged. The completion report must state exactly which fields the recomputation touches. |
| Lock-version churn could cause spurious 409s for a concurrently open sheet editor | the recomputation is inside the decision's own transaction; decide explicitly whether the sheet's `lockVersion` increments on recomputation, and document the answer. If it does, a purchaser holding a sheet edit open while a manager decides will get a 409 — which FR-025 says is the correct outcome, but it must be a *deliberate* decision, not a surprise. |
| Recomputation on every transaction write could be repeated work | it is one aggregate read plus one row write per affected sheet; measure before and after and report the numbers. |

## 5. Open questions for the owner (must be answered before implementation)

1. Confirm that "live-derived status" is the intended product behaviour (the contract already says so; this request makes the code obey it).
2. Should the sheet's lock version increment when only the derived status changes? (Affects whether an open editor sees a 409 after an unrelated decision.)
3. Is the transient inconsistency of GAP-040 acceptable until this lands, or should the frontend anyway re-fetch after a decision (a workaround, not a fix)?

## 6. Acceptance criteria (for the future implementation)

1. Immediately after a cost-center decision, the sheet's status as returned by both the list and the detail endpoint reflects the earliest outstanding stage — asserted with no finance operation in the test.
2. Immediately after a return at each of the three stages, the sheet reports its correction status.
3. Immediately after a correction resubmission, the sheet reports the cost-center stage.
4. Immediately after submission, the sheet is no longer OPEN.
5. When every transaction is terminal, the sheet reports `COMPLETED` or `COMPLETED_WITH_REJECTIONS` as appropriate, with no transaction left awaiting anything (this is the GAP-040 hardening assertion).
6. Repeated identical requests (replay) do not produce a different status or a duplicate audit event.
7. The derivation logic itself is unchanged: the existing unit test asserting the earliest-outstanding rule passes unmodified.
8. The completion report lists the exact fields written by the recomputation and the measured query cost per decision.

## 7. Rollback

Reverting the added call sites restores today's behaviour (and today's stale display). Because no schema or payload changes, the revert is trivial; the test changes must be reverted with it.

## 8. Approval gate

**BLOCKED_PENDING_OWNER_APPROVAL.** Requires: (a) confirmation of the lock-version decision (§5 item 2); (b) authorization to add writes on the decision/submission paths; (c) agreement that any integration test which passes today only because of the delayed recomputation may be corrected — with each such test listed in the completion report.
