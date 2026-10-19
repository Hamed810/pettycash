# BE-CR-004 — One consistent evidence policy: required at submission, only when required

- **Type:** backend behaviour change request · **Status:** **BLOCKED_PENDING_OWNER_APPROVAL**
- **Priority:** P1 · **Size:** S · **Risk:** medium (it makes submission *stricter* in one place and *looser* in another)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-030, GAP-032 · **Frontend consumer:** `UI-PUR-016`

> **Change request, not an implementation ticket.** It changes whether a sheet can be submitted and whether a returned transaction can be resubmitted — both are user-visible financial-workflow gates.

## 1. Why it is needed

FR-023 states, verbatim: *"Submission MUST require every transaction to have an expense date, positive amount, description, exactly one cost center, one active expense type, **and all required evidence**"* (`specs/001-pettycash-v1-core/spec.md:796-797`; repeated at `data-model.md:122,267`).

Two places get this wrong, in opposite directions:

| Path | Actual behaviour | Evidence |
|---|---|---|
| Sheet submission | Evidence is **never** required — the gate is called with the default: `$this->assertSubmittable()` where the parameter defaults to `false`, and **no call site under `lib/` passes `true`** | `lib/Application/Workflow/SubmitSheet.php:83`; `lib/Domain/Expense/TransactionRevision.php:124-129` |
| Correction resubmission | Evidence is required **unconditionally**, even when the expense type does not require it | `lib/Application/Workflow/ResubmitCorrection.php:108-110` |

Consequences: a sheet with a missing mandatory receipt can be submitted and enter review (the reviewer's remedy is a return — a full round trip for a check the purchaser's own screen appeared to make); and a returned transaction whose expense type requires no evidence cannot be resubmitted without attaching an irrelevant file, which then becomes part of the audit record.

## 2. Requested change

1. **Enforce "required evidence" at sheet submission**, using the expense type's configuration exactly as the system already stores it (do not invent a new policy or a new configuration field). The `assertSubmittable()` call must receive the type-driven answer rather than the default.
2. **Make resubmission's evidence check conditional on the same configuration**, so the two paths agree.
3. **Keep the configuration as the single source of truth**: whatever defines "evidence required" for an expense type today (the same input the frontend uses at `src/views/transactions/TransactionFormView.vue:112-117,140-142,149`) is the only thing that decides.
4. **Refusal must name the offending transaction(s)** in the existing error envelope, consistent with how the other submission blockers are reported (the frontend already renders a submission-error region — `src/views/sheets/SheetDetailView.vue:442-451`).

## 3. Endpoint / contract / test impact

| Item | Impact |
|---|---|
| `POST /api/v1/sheets/{sheetId}/submit` | new refusal reason (evidence missing) — behaviour change, same status code family as the existing blockers |
| `POST /api/v1/transactions/{transactionId}/resubmit` | refusal becomes conditional; previously-refused cases now succeed |
| `contracts/ocs-api.yaml` | document the evidence precondition on submit and the conditional one on resubmit; `npm run check:openapi` must pass. **No new request/response fields.** |
| `specs/001-pettycash-v1-core/contracts/workflow.md` | must state the same rule (it is the behavioural contract for this path) |
| Domain | `TransactionRevision::assertSubmittable()`'s evidence parameter becomes load-bearing rather than dead — its docblock must say what the boolean means and who decides it |
| New tests (integration) | submit with a required-evidence type and no evidence → refused, naming the transaction; with evidence → accepted; with a non-required type and no evidence → accepted (the negative case that proves the check is conditional) |
| New tests (integration) | resubmit with a non-required type and no evidence → succeeds (currently fails); with a required type and no evidence → still refused |
| Existing tests | `tests/Integration/Workflow/SheetSubmissionTest.php:87-551` (15 cases) must be reviewed one by one: any case that submits a required-evidence type without evidence encodes the old behaviour and must change **deliberately**, with the reason recorded |
| Existing tests | `tests/Contract/Controller/EvidenceLifecycleControllerTest.php:148-467`; `tests/Integration/Workflow/TransactionCorrectionTest.php` |

## 4. Why this is not a "small bug fix"

Because it makes a previously-accepted workflow step fail. A purchaser with a required-evidence expense type who has already submitted sheets under the old rule now cannot submit the same incomplete sheet. That is the correct behaviour — but it is a **behaviour change with a user-visible consequence**, which is why it is a change request and not a silent patch. The owner must also decide whether the stricter rule needs a release note.

## 5. Open questions for the owner (must be answered before implementation)

1. Confirm the enforcement point: submission only, or also an earlier warning (the UI half is `UI-PUR-016`; the server is the enforcement).
2. Confirm that a *deactivated* expense type on a transaction is already refused at submission (it appears to be: `SubmitSheet.php` checks the active expense type) and that the new evidence check does not need to re-check type validity.
3. Confirm the message shape: one error naming the first offending transaction, or all of them? (Recommendation: all of them — the purchaser has to fix every one anyway.)
4. Is there any legitimate case of a transaction whose evidence was attached at an earlier revision and whose active revision has none? If yes, state which revision's evidence satisfies the requirement — otherwise the rule is "the transaction's current evidence set", which is how the model is built (`EvidenceController.php:132-146`).

## 6. Acceptance criteria (for the future implementation)

1. Submitting a sheet containing a transaction whose active expense type requires evidence and which has none is refused, with a message naming the transaction.
2. Adding the evidence (or changing to a type that does not require it) makes the same sheet submittable with no other change.
3. A sheet whose transactions all use non-required expense types submits successfully with no evidence attached (the conditional-is-real case).
4. A returned transaction using a non-required type resubmits without evidence.
5. A returned transaction using a required type still refuses without evidence.
6. The frontend's transaction-form requirement and the server's submission requirement agree on every case in a shared fixture matrix (the two must not disagree, which is the defect this change request closes).
7. The rule is stated identically in `contracts/workflow.md` and `contracts/ocs-api.yaml`.
8. No new configuration field or endpoint is introduced.

## 7. Rollback

Revert the two call sites; the stricter submission rule disappears with them. Because a stricter rule can block in-flight work, the rollback must be a one-commit revert with no data migration involved — which it is, since no stored state changes.

## 8. Approval gate

**BLOCKED_PENDING_OWNER_APPROVAL.** Requires: (a) authorization to make submission stricter; (b) confirmation of the four questions in §5; (c) agreement that any affected integration test may be updated — with each changed case listed in the completion report, since those tests currently encode the behaviour being corrected.
