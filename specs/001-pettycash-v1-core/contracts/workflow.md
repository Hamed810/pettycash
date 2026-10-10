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

## Command Replay Versus Superseded Attempt (FR-084, FR-085)

These are **different failures** and are resolved differently. Conflating them is a defect, because one
is detectable from the request alone and the other is not.

| | Replay (FR-084) | Superseded attempt (FR-085) |
|---|---|---|
| Cause | The **same** command arrives again, with the same `X-PettyCash-Command-ID` | A **different** command arrives against a transaction that has already moved on |
| Detectable from | The request alone, by looking up the command identifier | **The transaction's current state**, checked server-side inside the transaction |
| Outcome | Returns the **first outcome** verbatim; applies nothing | Applies nothing; returns `409` naming that a decision is already recorded |
| Audit | None additional — the first outcome was already audited | Exactly one `SUPERSEDED_ATTEMPT` event |

A superseded attempt occurs when a decision is already recorded against the same active revision and
stage, **or** when the active revision changed underneath the in-flight request. In both cases:

- the decision already recorded **stands** and is never overwritten — first write wins;
- **no** business effect is created: no decision, no revision, no workflow transition, no balance
  movement;
- the response is `409 Conflict` naming that a decision is already recorded and that the caller must
  reload to see the current outcome; and
- exactly one `SUPERSEDED_ATTEMPT` audit event is written, naming the transaction, its active revision,
  the stage, the actor, the time, the decision attempted, and the decision already in force. Its type
  is **neither an approval nor a rejection**.

The reason the loser is audited rather than silently dropped is that a discarded attempt is
indistinguishable from a request that never happened, leaving a reviewer who acted with no trace; the
reason it is *not* typed as an approval or rejection is that doing so would falsify the decision
history. `before` carries the decision in force and `after` the decision attempted, so one read
reconstructs the race.

**No transparent client retry.** FR-075 forbids automatic resumption, and a retry cannot help: the
state the caller reasoned about is gone.

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

**Target account resolution and the unsettleable case (FR-040).** The account is resolved by the
sheet's currency **at the moment of approval**. Where no **active** account in that currency exists for
the purchaser, finance approval is **refused with `422`**, naming the missing or inactive account; the
transaction stays at the finance stage and none of the six effects above is created.

Two rules make this unambiguous:

- **Default-account provisioning does not apply here.** FR-020's idempotent provisioning is scoped to
  the first sheet-creation attempt. Settlement MUST NOT create an account, because doing so would book
  approved expense against a balance that was never funded or approved.
- **The refusal is a configuration gap, not a system fault**, and the remedy is an administrator's
  deliberate, audited action under FR-015/FR-017 — restore, reactivate, or charge an account. The
  `422` message MUST name the account so the action is available without further diagnosis.

An approved-but-unsettleable expense is an acceptable and visible intermediate state; an expense booked
against a balance conjured up at approval time is not.

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
| 422 | Business validation failed, including missing submission fields, explanation, or an unsettleable settlement target account |
| 500 | Unexpected failure; sensitive detail is logged through host technical logging, not returned |

Error bodies contain a stable code, localized-safe message key or message, optional field errors,
and command ID where applicable. They never include stack traces, storage paths, SQL, or secrets.

## Unauthenticated Surface Contract (FR-031)

`GET /public/evidence/{token}` is the **only** route in V1 reachable without authentication, and this
is deliberate: the unguessable token **is** the authority. Anyone holding it may open the evidence
without a session and without any role or responsibility scope on the transaction. This is a bounded,
recorded exception to the role-scope rule FR-009 otherwise applies.

| Property | Rule | Why it is load-bearing |
|---|---|---|
| Abuse control | Nextcloud's brute-force limiter only. **No** application rate limiter, login challenge, or CAPTCHA | The source requirements place authentication policy in Nextcloud's hands; a second mechanism is the parallel authentication system Principle III forbids |
| Audit | Every access writes configuration audit with viewer **`anonymous`**, plus timestamp and IP | The field MUST NOT be null or absent — an empty field is indistinguishable from a missing record during the investigation it serves |
| Storage | Token stored only as a **hash**, disclosed once at creation | A leaked database must not be replayable into evidence access |
| Refusal | Unknown, deactivated, and expired tokens return **one identical `404`** | A distinguishable refusal confirms that a link existed and when it lapsed |
| Media type | FR-060's policy applies unchanged: inline only for inline-safe stored types, otherwise `application/octet-stream` + `attachment` | This is the one route where a stored media type reaches a viewer who never authenticated |

TTL and revocation are, because the token is the whole authority, the **only** remaining controls.
Creation remains administrator-only, disabled by default, and fully audited; the administrator's own
view right is not required to create a link, and a link for evidence the administrator cannot themselves
view is permitted and audited as such.

## V1 Accessibility Floor (FR-071, FR-076)

Each **primary journey** — those exercised by User Stories 1–7 — MUST be completable **using the
keyboard alone**, with a visible focus indicator on every interactive element reached, in Chrome 120+,
Edge 120+ and Firefox 115+ (ESR), at both viewport sizes, and under the default, dark and high-contrast
host themes. A focus indicator the active theme renders as absent **fails** the criterion: theme
coverage is part of it, not an enhancement.

**Deferred to V2 and not reportable as met in V1:** form control label association, error-message
association with the offending field, and announced (live-region) status for mutations.
