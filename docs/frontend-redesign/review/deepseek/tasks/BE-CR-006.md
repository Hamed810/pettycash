# BE-CR-006 — Refuse a review decision against a transaction awaiting correction

- **Type:** backend behaviour change request · **Status:** **BLOCKED_PENDING_OWNER_APPROVAL**
- **Priority:** P2 · **Size:** S · **Risk:** low
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-022 · **Frontend consumers:** the workspace History/Approvals surfaces (`UI-PUR-006`)

> **Change request, not an implementation ticket.** It adds a refusal where the system currently accepts and records a decision that has no effect. It is filed because it was found during a read-only audit and **not** because it is in the purchaser redesign's way — it is the lowest-priority item in this set.

## 1. Why it is needed

A decision POST that reaches a transaction whose status is `AWAITING_PURCHASER_CORRECTION` (stage `CORRECTION`) passes every guard and is stored:

| Guard | Result |
|---|---|
| Owner authorisation — `lib/Application/Security/AuthorizationService.php:68-70` | passes |
| Stage guard (`$transaction->stage !== $stage`) — `lib/Application/Workflow/ManagementReviewService.php:221-223` | passes |
| Persistence — `DbWorkflowDecisionRepository` insert | the decision row is written |
| Audit — the decision event is recorded | written |
| **Effect** — `ExpenseTransaction::applyDecision()`'s `Correction` branch | **does nothing** — `lib/Domain/Expense/ExpenseTransaction.php:184-186` |

So the transaction's state does not move, while a decision and an audit event now exist. Any surface that reports decisions (including the workspace History tab being planned in `UI-PUR-006`, and the revision history a reviewer reads during reconciliation) will show a decision that never applied to anything.

**Confidence note:** the code path is confirmed by source inspection; the behaviour was **not** reproduced with a live request during this audit (no requests were sent). The finding is therefore recorded as `confirmed_in_source`, behaviourally `unverified`, and the change request must be validated against a running instance before implementation.

## 2. Requested change

1. Refuse a decision for a transaction whose status is `AWAITING_PURCHASER_CORRECTION` (or whose stage is `CORRECTION`), with a named error in the existing envelope, **before** any decision row or audit event is written.
2. Apply the rule in one place that every decision entry point passes through, so a future stage cannot reintroduce the hole.
3. Do not change any other refusal: the existing stage guard, the finance decision matrix (`lib/Domain/Workflow/FinanceDecisionPolicy.php:37-68`) and the immutable-decision rule (`DbWorkflowDecisionRepository.php:94-97,118-149`) stay exactly as they are.

## 3. Endpoint / contract / test impact

| Item | Impact |
|---|---|
| `POST /api/v1/transactions/{transactionId}/decisions` | new refusal reason; **no shape change** |
| `contracts/ocs-api.yaml` | the decisions operation's description gains the precondition; `npm run check:openapi` must pass |
| `contracts/workflow.md` | state the rule in the decision contract (the correction state is already described there, `:67-75`) |
| New tests (contract) | a decision against an awaiting-correction transaction is refused, and **no** decision row and **no** audit event are created (assert both, not just the status code) |
| New tests (contract) | after a correction resubmission, a decision at the cost-center stage succeeds again (the rule must not block the legitimate case) |
| Existing tests | `tests/Integration/Workflow/ManagementDecisionTest.php`, `tests/Integration/Workflow/SupersededDecisionAttemptTest.php:93-295`, `tests/Contract/Controller/ManagementReviewControllerTest.php:62-263` — none is expected to encode the accepted-then-ignored behaviour; if one does, that is a finding in itself and must be reported, not silently edited |

## 4. Open questions for the owner (must be answered before implementation)

1. Is there any legitimate reason to record a decision while a transaction awaits correction (for example, a manager annotating their earlier decision)? If yes, this change request is withdrawn and the History surface must instead label such decisions as not-applied. **This is the decisive question** — the answer determines whether the fix is a refusal or a display rule.
2. If refused, which error reason string should be used, to stay consistent with the existing vocabulary (`tests/Contract/Controller/ErrorCodeVocabularyTest.php`)?

## 5. Acceptance criteria (for the future implementation)

1. A decision against an awaiting-correction transaction is refused with a named error.
2. The refusal creates no decision row and no audit event (assert the counts).
3. A decision against the same transaction **after** resubmission succeeds and restarts correctly at the cost-center stage.
4. The existing stage guard and finance policy behaviour are unchanged (their tests pass unmodified).
5. The rule is stated in both contracts.
6. If the owner answers question 1 with "yes, it is legitimate", then this change request is withdrawn and replaced by a labelling requirement in `UI-PUR-006` — recorded in this file rather than deleted, so the reasoning survives.

## 6. Rollback

Removing the guard restores today's behaviour. Nothing is stored differently, so the rollback is a one-line revert (plus the test deletions).

## 7. Approval gate

**BLOCKED_PENDING_OWNER_APPROVAL.** Lowest priority of the six change requests: it fixes a reconciliation trap that no purchaser journey depends on, and its correct disposition depends entirely on the owner's answer to §4 question 1. Until then, `UI-PUR-006` must render history values **from the event payload** (never from the literal, see GAP-052) and must not assume every decision applies to the revision it appears on.
