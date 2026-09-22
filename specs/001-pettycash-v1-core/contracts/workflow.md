# Workflow Contract

## Authority Inputs

Every command derives authority from the authenticated user, current role assignments, transaction
ownership, cost-center scope, workflow stage, and record state. The client never supplies an actor,
authority role, balance, revision number, current stage, or calculated total.

V1 permits a transaction owner to decide their own transaction when the same user independently
holds the required manager, senior-manager, or finance role and scope. Administrator status alone
never grants finance authority. Every active finance officer shares the same finance queue.

## Command Contract

Non-repeatable commands require `X-PettyCash-Command-ID`, a client-generated UUID. Replaying the
same command with the same canonical payload returns the first result. Reusing the UUID with a
different payload returns conflict and creates no new business effect.

Mutable-resource commands carry `expectedVersion`. A stale version returns conflict and the caller
must reload; the client does not automatically retry a mutation.

## Decision Guards

| Stage | Allowed decisions | Required conditions |
|-------|-------------------|---------------------|
| Cost center | Approve, reject, return | Actor manages revision's cost center; reject/return explanation required |
| Senior | Approve, reject, return | Actor is senior manager for revision's cost center; reject/return explanation required |
| Finance after senior approval | Approve, reject, return | Actor has active finance role; shared queue; return explanation required |
| Finance after senior rejection | Reject, return | Finance approval forbidden; return explanation required |

All successful decisions insert an immutable decision and audit event. No endpoint edits or deletes
a decision.

## Correction Contract

1. A return decision places the transaction in purchaser correction.
2. Only the transaction owner may create and edit the correction draft.
3. The draft begins from the active revision but has a new revision identity.
4. Resubmission validates the full required dataset and evidence.
5. Activation atomically switches the active revision and routes it to cost-center review.
6. Prior revisions, decisions, evidence history, and audit events remain unchanged.

## Finance Settlement Contract

Finance approval locks the sheet, transaction, active revision, and account in a fixed order and
atomically creates:

- the finance approval decision;
- one unique consumption movement for the revision;
- the new account balance, which may be negative;
- terminal transaction state;
- recalculated sheet state; and
- the business audit event.

If any database step fails, none is committed. File or export I/O is never included in this
transaction. A unique consumption-source constraint and command idempotency prevent duplicates.

## Sheet Projection Contract

The sheet status is the earliest outstanding stage across active nonterminal revisions. A
transaction may advance before another, but the sheet remains at the earlier aggregate stage.
Completed transactions and their account movements never rewind when another transaction is
returned.

`SUBMITTED` is an atomic milestone and first formal audit event, not a long-lived sheet state.

## Error Contract

| HTTP status | Meaning |
|-------------|---------|
| 400 | Structurally invalid request |
| 401 | No authenticated host session |
| 403 | Authenticated but outside role, ownership, scope, stage, or state authority |
| 404 | Resource absent or intentionally hidden to avoid scope disclosure |
| 409 | Stale version, invalid current state, duplicate conflict, or concurrency conflict |
| 422 | Business validation failed, including missing submission fields or explanation |
| 500 | Unexpected failure; sensitive detail is logged through host technical logging, not returned |

Error bodies contain a stable code, localized-safe message key or message, optional field errors,
and command ID where applicable. They never include stack traces, storage paths, SQL, or secrets.
