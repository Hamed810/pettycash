# ADR-007: Pre-save receipt attachment and submission-time receipt enforcement

- **Status**: Accepted; **implemented and technically verified 2026-10-24** (owner-approved behavior). Owner visual acceptance (`T051`), the full PHP suite (`T049`), and the final traceability audit (`T052`) remain open.
- **Date**: 2026-10-23
- **Decides**: How a receipt can be selected before the first save, and when the receipt rule is enforced
- **Traces to**: 001 FR-022, FR-023, FR-025, FR-047, FR-089–FR-092, SC-017; 005 FR-005-022,
  FR-005-027, FR-005-031, FR-005-035–FR-005-038; `docs/frontend-redesign/DECISIONS.md` D-022

## Context

The owner requires all of the following at once:

1. a transaction can be created with only an expense date and a positive amount;
2. every existing optional field — description, cost center, expense type — can be supplied in the same
   first save;
3. one or more receipts can be selected **before** the first save;
4. a save never requires a receipt;
5. sheet submission requires a valid receipt for every transaction subject to the existing receipt
   requirement; and
6. the purchaser can see which transactions are missing one.

The current implementation does not satisfy this. The create form has the optional fields but no receipt
control, and its “Create/Update” action applies submission-only required-field and receipt validation.
`ExpenseSheet::submit()` calls `TransactionRevision::assertSubmittable()` without the expense type's
`evidence_required` flag, so sheet submission currently enforces no receipts at all.
`ResubmitCorrection::execute()` does the opposite: it requires evidence unconditionally, including for
expense types configured as not requiring it.

The existing evidence endpoint requires a stored transaction ID and ownership. That constraint is the
reason a pre-save receipt cannot simply be posted to the current endpoint before the transaction exists.

## Decision

**Receipts selected before the first save are staged client-side, then attached to the transaction
immediately after creation. Saving never evaluates the receipt requirement. Submission and correction
resubmission evaluate the active expense type's current `evidence_required` rule, per transaction.**

The decision has five parts.

1. **Client-side staging.** The create form keeps selected files in memory with a local identity and a
   state (`staged`, `uploading`, `attached`, `failed`). No upload request is issued before the transaction
   exists, and no request is ever issued with an empty transaction ID.
2. **Create first, then attach.** The first save creates the transaction. Each staged file is then
   uploaded through the existing `POST /transactions/{id}/evidence` endpoint. There is no new
   pre-transaction upload endpoint and no orphan-evidence lifecycle.
3. **Partial failure is explicit.** If the transaction is created but an upload fails, the transaction
   remains saved, the failed file is named, and the purchaser can retry from the edit surface. The save is
   not rolled back merely because a receipt failed; a receipt was never required to save.
4. **Saving is receipt-free.** `TransactionService::createTransaction()` and the update path never
   evaluate `evidence_required`. The FR-022 minimum and the full optional draft payload are both valid on
   save.
5. **Submission is the gate.** Sheet submission and correction resubmission require at least one active
   evidence item attached to the transaction when the active expense type's **current**
   `evidence_required` is true. Evidence from a prior revision remains valid because evidence belongs to
   the transaction, not to one revision. A deactivated item does not satisfy the rule. A receipt-exempt
   type is not refused. A refusal is `422` with a `transactions.<transactionId>.evidence` field error for
   every offending transaction.

The server remains authoritative for readiness. `TransactionSummary` exposes a server-derived
`evidenceRequired` boolean alongside the existing active `evidenceCount`; the client does not infer the
rule from expense type names, local configuration, or a second copy of the rule.

## Alternatives considered

| Alternative | Why rejected |
|---|---|
| New pre-transaction upload endpoint with staged tokens | Adds an orphan-evidence lifecycle, a new authorization surface, token expiry/cleanup, and a new contract operation the owner did not approve. It also needs a second storage state before the transaction exists. |
| Auto-create an empty draft when the form opens | Satisfies "before first save" mechanically but leaves abandoned draft transactions whenever the purchaser cancels. It also changes the meaning of opening a form into a write. |
| Require the receipt on save | Directly contradicts the owner requirement and makes the FR-022 minimum invalid. |
| Snapshot `evidence_required` at transaction creation | Drafts would not reflect the current configuration at the moment they are submitted. The rule belongs to the submission gate, not to the revision snapshot. |
| Enforce receipts only in the frontend | The requirement explicitly says submission validation cannot be considered complete from frontend checks. A stale or malicious client would bypass it. |
| Require evidence on every correction resubmission | This is the current inverted behavior; it rejects receipt-exempt expense types and is not the owner's rule. |

## Consequences

- **No new persistence.** The staging queue is presentation state only. Evidence remains in the existing
  table and AppData storage.
- **No new endpoint.** The existing evidence upload path and its ownership/state checks are reused.
- **Partial success is a first-class state.** The UI must distinguish “transaction saved, receipt failed”
  from “transaction not saved” and must offer retry without re-entering the transaction.
- **Contract changes are required**: `TransactionSummary.evidenceRequired`; a `422` on correction
  resubmission; and a documented `transactions.<transactionId>.evidence` field-error key. These are
  authorized only for this slice.
- **Server enforcement must aggregate.** The submit path should name every offending transaction, not
  only the first, so the readiness list and the `422` agree.
- **The rule is current-configuration based.** An administrator changing `evidence_required` while a
  draft exists changes what that draft needs at submission. This is intentional: the configuration in
  force at the gate is the rule the reviewer is applying.
- **Tests must cover both directions**: required versus exempt, active versus deactivated evidence, save
  without a receipt, upload failure after creation, and resubmission of a receipt-exempt correction.

## Implementation impact

- 001 `T431`–`T435`: RED tests, conditional submit enforcement, conditional resubmission, the
  `evidenceRequired` payload field, and create/save verification. Recorded complete in
  `specs/001-pettycash-v1-core/tasks.md`.
- 005 `T044` and `T053`–`T063`: single-form save, staging queue, API type regeneration, readiness, `422` mapping,
  correction UI, debug-gated fixtures, browser evidence, and traceability. All are `DONE_VERIFIED` (technical)
  in `specs/005-purchaser-ui-redesign/tasks.md`; the evidence report is
  `docs/reports/005-receipt-creation-slice.md`.
- Verification: four browser runs (`fa`/`en` × desktop/390px), 46/46 checks and 11 screenshots each with
  zero page errors; targeted Vitest 4 files / 190 tests; container PHPUnit 5 files / 51 tests / 765
  assertions; `npm run check` 26 files / 536 tests, lint 0 errors / 261 warnings, build and notices green.
- `docs/frontend-redesign/DECISIONS.md` D-022 records the owner directive and its verified implementation;
  P-009 shows `BE-CR-004` approved and implemented/verified, and P-006 the bounded backend exception.
- No production seed data is introduced. The 25/30/35/40-transaction sheets are debug-gated verification
  data only.
