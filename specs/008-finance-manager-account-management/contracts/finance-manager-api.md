# Contract — Finance Manager Account Management (Phase 1)

**Feature**: `specs/008-finance-manager-account-management/` | **Status**: feature-level additive contract. The canonical `specs/001-pettycash-v1-core/contracts/ocs-api.yaml` is **not modified** by this planning mission; merging these paths into it requires a separately authorized contract change before implementation.

## Authority and scope

This contract defines five **read-only** Finance-scoped endpoints, a read-only authorization extension on the existing evidence-content endpoint, and the unchanged reuse of the existing account-charge write. It does not redefine any domain, authorization, financial, lifecycle, evidence, or audit rule from `specs/001-pettycash-v1-core/spec.md`.

- All routes are OCS endpoints under `/api/v1`.
- All reads require an **effective `FINANCE` assignment** (active role assignment, resolved on the server). Platform-administrator status alone does not grant them.
- All reads take the actor uid from the authenticated session, never from a request parameter.
- The recharge endpoint is the existing `POST /accounts/{accountId}/charges`; its contract and authorization are unchanged.

## Common pagination and errors

Every list accepts:

| Parameter | Type | Default | Rule |
|---|---|---|---|
| `page` | integer | 1 | ≥ 1 |
| `pageSize` | integer | 25 | 1–100 |

`total` is the count of all matching records, not the page length. An empty result is `200` with `items: []` and `total: 0`, never a 404.

Errors use the existing `Error` envelope (`code`, `message`, optional `fieldErrors`, optional `commandId`):

| HTTP | `code` | When |
|---|---|---|
| 403 | `FORBIDDEN` | no authenticated user, or no effective `FINANCE` assignment |
| 404 | `NOT_FOUND` | unknown/non-holder user, unknown account, unknown sheet, or a sheet not in a terminal completed state |
| 422 | `VALIDATION_FAILED` | invalid `page`, `pageSize`, over-long `search` |
| 422 | `DATA_FAULT` | a stored record cannot be mapped (for example a consumption whose revision/sheet does not resolve) |
| 409 | `STATE_CONFLICT` | existing recharge version/account-state conflicts, unchanged |

A refused cross-user read returns 403 with **no holder data**. An unknown holder returns 404 with no distinction between “no such user” and “user owns no account”, so the endpoint is not an account-ownership oracle.

---

## 1. `GET /api/v1/finance/holders`

**Purpose**: paginated holder directory.

**Query**: `page`, `pageSize`, `search` (optional; trimmed; ≤64 characters; case-insensitive substring match on holder `displayName` or `userId`).

**Response 200**

```json
{
  "items": [
    {
      "userId": "alice",
      "displayName": "Alice Example",
      "accountCount": 2,
      "activeAccountCount": 1,
      "status": "MIXED"
    }
  ],
  "page": 1,
  "pageSize": 25,
  "total": 1
}
```

`status` is `ACTIVE` (all accounts active), `INACTIVE` (none active), or `MIXED`. A user with no petty-cash account never appears. The candidate set is always the distinct `pettycash_accounts.user_uid` population. Display names are resolved server-side only for those holder uids, with a per-request cache. A search term is applied to the complete holder population before pagination (server-side, never page-local); it never searches or enumerates the Nextcloud user directory.

---

## 2. `GET /api/v1/finance/holders/{holderUid}/accounts`

**Purpose**: all accounts for one holder, including inactive accounts.

**Query**: `page`, `pageSize`.

**Response 200**

```json
{
  "holder": { "userId": "alice", "displayName": "Alice Example" },
  "items": [
    {
      "id": "0b1f5c1e-6f6a-4a1e-9f0c-2f0d6a7b8c9d",
      "userId": "alice",
      "currency": "IRR",
      "minorUnit": 0,
      "balance": { "amountMinor": 150000, "currency": "IRR", "minorUnit": 0 },
      "active": true,
      "version": 3,
      "movementCount": 12,
      "lastMovementAt": "2026-11-15T10:00:00+00:00"
    }
  ],
  "page": 1,
  "pageSize": 25,
  "total": 2
}
```

`version` is the optimistic-lock value the recharge must send. `movementCount`/`lastMovementAt` are batched from the movement table; `lastMovementAt` is `null` for an unused account. No mutation control is part of this response.

---

## 3. `GET /api/v1/finance/accounts/{accountId}/ledger`

**Purpose**: the approved six-column chronological ledger for one account.

**Query**: `page`, `pageSize`.

**Response 200**

```json
{
  "account": {
    "id": "0b1f5c1e-6f6a-4a1e-9f0c-2f0d6a7b8c9d",
    "userId": "alice",
    "currency": "IRR",
    "minorUnit": 0,
    "balance": { "amountMinor": 150000, "currency": "IRR", "minorUnit": 0 },
    "active": true,
    "version": 3,
    "movementCount": 12,
    "lastMovementAt": "2026-11-15T10:00:00+00:00"
  },
  "items": [
    {
      "id": "9a7c1d20-1a2b-4c3d-8e4f-5a6b7c8d9e0f",
      "type": "CONSUMPTION",
      "occurredAt": "2026-11-15T10:00:00+00:00",
      "description": "October travel",
      "sheet": {
        "id": "5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a",
        "reference": "5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a",
        "title": "October travel"
      },
      "approved": { "approvedCount": 5, "totalCount": 10 },
      "recharge": null,
      "expense": { "amountMinor": 25000, "currency": "IRR", "minorUnit": 0 },
      "balanceAfter": { "amountMinor": 150000, "currency": "IRR", "minorUnit": 0 },
      "sourceRevisionId": "6e5d4c3b-2a1f-4e8d-9c7b-8a6f5e4d3c2b"
    },
    {
      "id": "1b2c3d4e-5f60-4a7b-8c9d-0e1f2a3b4c5d",
      "type": "CHARGE",
      "occurredAt": "2026-11-10T09:30:00+00:00",
      "description": "Monthly funding",
      "sheet": null,
      "approved": null,
      "recharge": { "amountMinor": 500000, "currency": "IRR", "minorUnit": 0 },
      "expense": null,
      "balanceAfter": { "amountMinor": 500000, "currency": "IRR", "minorUnit": 0 },
      "sourceRevisionId": null
    }
  ],
  "page": 1,
  "pageSize": 25,
  "total": 2
}
```

**Invariants**

- The user-visible columns are exactly: **Date**, **Description**, **Approved**, **Recharge**, **Expense**, **Balance**, in that order. `type` is semantics/styling, not a column.
- `occurredAt` is the persisted movement timestamp. It is never the sheet creation date, the transaction expense date, or a mockup date.
- `balanceAfter` is the persisted resulting balance for that movement. The client never sums the page.
- Exactly one of `recharge`/`expense` is non-null. A `CHARGE` has `sheet: null`, `approved: null`, `expense: null`; a `CONSUMPTION` has `sheet` and `approved` non-null and `recharge: null`.
- A consumption whose sheet cannot be resolved is a `DATA_FAULT`, not an `approved: null` row.
- `approved.approvedCount` is the sheet's approved transaction count and `approved.totalCount` its total transaction count. The approved cell renders `approvedCount of totalCount`; a charge renders the localized “not applicable”.
- There is no rejected-count column in this response.
- No row carries an action, capability, or mutation link.

---

## 4. `GET /api/v1/finance/completed-sheets`

**Purpose**: paginated completed Cost Sheet history.

**Query**: `page`, `pageSize`.

**Response 200**

```json
{
  "items": [
    {
      "id": "5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a",
      "reference": "5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a",
      "title": "October travel",
      "purchaser": { "userId": "alice", "displayName": "Alice Example" },
      "totalTransactionCount": 10,
      "approvedTransactionCount": 5,
      "rejectedTransactionCount": 5,
      "postedAmount": { "amountMinor": 125000, "currency": "IRR", "minorUnit": 0 },
      "postingCount": 5,
      "completedAt": "2026-11-16T08:00:00+00:00",
      "status": "COMPLETED_WITH_REJECTIONS"
    }
  ],
  "page": 1,
  "pageSize": 25,
  "total": 1
}
```

**Invariants**

- Only `COMPLETED` and `COMPLETED_WITH_REJECTIONS` sheets appear. A sheet with any pending Finance transaction is absent.
- `approved + rejected = total` for a completed sheet.
- `postedAmount` is the sum of the consumption movements actually linked to the sheet, not a projection from approved transaction amounts.
- `reference` is the sheet's authoritative public UUID; the schema has no separate business-reference column.

---

## 5. `GET /api/v1/finance/completed-sheets/{sheetId}`

**Purpose**: read-only completed detail.

**Response 200**

```json
{
  "id": "5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a",
  "reference": "5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a",
  "title": "October travel",
  "purchaser": { "userId": "alice", "displayName": "Alice Example" },
  "accountId": "0b1f5c1e-6f6a-4a1e-9f0c-2f0d6a7b8c9d",
  "currency": "IRR",
  "minorUnit": 0,
  "totalTransactionCount": 10,
  "approvedTransactionCount": 5,
  "rejectedTransactionCount": 5,
  "postedAmount": { "amountMinor": 125000, "currency": "IRR", "minorUnit": 0 },
  "postingCount": 5,
  "createdAt": "2026-11-01T08:00:00+00:00",
  "firstSubmittedAt": "2026-11-02T08:00:00+00:00",
  "financeStartedAt": "2026-11-10T08:00:00+00:00",
  "completedAt": "2026-11-16T08:00:00+00:00",
  "status": "COMPLETED_WITH_REJECTIONS",
  "transactions": [
    {
      "id": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
      "reference": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
      "revisionNumber": 2,
      "description": "Taxi to site",
      "expenseDate": "2026-10-28",
      "amount": { "amountMinor": 25000, "currency": "IRR", "minorUnit": 0 },
      "finalOutcome": "APPROVED",
      "status": "COMPLETED_APPROVED",
      "decisions": {
        "costCenter": {
          "id": "b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e",
          "stage": "COST_CENTER",
          "decision": "APPROVE",
          "explanation": null,
          "actor": { "userId": "manager1", "displayName": "Manager One", "role": "COST_CENTER_MANAGER" },
          "decidedAt": "2026-11-03T09:00:00+00:00"
        },
        "senior": {
          "id": "c3d4e5f6-a7b8-4c9d-8e0f-2a3b4c5d6e7f",
          "stage": "SENIOR",
          "decision": "APPROVE",
          "explanation": null,
          "actor": { "userId": "senior1", "displayName": "Senior One", "role": "SENIOR_MANAGER" },
          "decidedAt": "2026-11-05T09:00:00+00:00"
        },
        "finance": {
          "id": "d4e5f6a7-b8c9-4d0e-9f1a-3b4c5d6e7f80",
          "stage": "FINANCE",
          "decision": "APPROVE",
          "explanation": "Receipt verified",
          "actor": { "userId": "finance1", "displayName": "Finance One", "role": "FINANCE" },
          "decidedAt": "2026-11-10T09:00:00+00:00"
        }
      },
      "decisionHistory": [
        {
          "id": "b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e",
          "stage": "COST_CENTER",
          "decision": "APPROVE",
          "explanation": null,
          "actor": { "userId": "manager1", "displayName": "Manager One", "role": "COST_CENTER_MANAGER" },
          "decidedAt": "2026-11-03T09:00:00+00:00"
        },
        {
          "id": "c3d4e5f6-a7b8-4c9d-8e0f-2a3b4c5d6e7f",
          "stage": "SENIOR",
          "decision": "APPROVE",
          "explanation": null,
          "actor": { "userId": "senior1", "displayName": "Senior One", "role": "SENIOR_MANAGER" },
          "decidedAt": "2026-11-05T09:00:00+00:00"
        },
        {
          "id": "d4e5f6a7-b8c9-4d0e-9f1a-3b4c5d6e7f80",
          "stage": "FINANCE",
          "decision": "APPROVE",
          "explanation": "Receipt verified",
          "actor": { "userId": "finance1", "displayName": "Finance One", "role": "FINANCE" },
          "decidedAt": "2026-11-10T09:00:00+00:00"
        }
      ],
      "evidence": [
        {
          "id": "e5f6a7b8-c9d0-4e1f-8a2b-4c5d6e7f8091",
          "name": "receipt.pdf",
          "mediaType": "application/pdf",
          "sizeBytes": 48213,
          "active": true,
          "uploadedBy": "alice",
          "uploadedAt": "2026-10-28T12:00:00+00:00"
        }
      ],
      "posting": {
        "occurredAt": "2026-11-10T09:00:00+00:00",
        "amount": { "amountMinor": 25000, "currency": "IRR", "minorUnit": 0 },
        "balanceAfter": { "amountMinor": 150000, "currency": "IRR", "minorUnit": 0 }
      }
    }
  ]
}
```

**Invariants**

- The detail is returned only when the sheet is terminal **and** every transaction is `COMPLETED_APPROVED` or `COMPLETED_REJECTED`. Otherwise `404`.
- Every transaction has a terminal Finance decision. No `AWAITING_FINANCE_REVIEW` transaction appears.
- `expenseDate` is labelled as the expense date; `posting.occurredAt` is the accounting posting timestamp. They may differ and must not be conflated.
- `posting` is `null` for a rejected transaction; otherwise it is the one consumption movement linked to that transaction's revision.
- `postedAmount` is the sum of `posting.amount` across the response, with `postingCount` matching the number of non-null postings.
- Evidence is metadata only. Content remains `GET /evidence/{evidenceId}/content` under the read-only completed-history rule below; the detail response embeds no content, URL, or token.
- The response contains no `allowedActions`, decision form, or mutation link.

### Evidence content authorization (existing route)

`GET /api/v1/evidence/{evidenceId}/content` keeps its request and response contract. Its authorization is composed in this order:

1. The existing `EvidenceVisibility::mayRead()` rule is evaluated unchanged. If it admits the actor, the request proceeds exactly as today.
2. Otherwise, an actor with an effective `FINANCE` assignment is admitted only when the evidence's transaction is terminal (`COMPLETED_APPROVED` or `COMPLETED_REJECTED` at `WorkflowStage::Final`), its sheet is `COMPLETED` or `COMPLETED_WITH_REJECTIONS`, and every transaction in that sheet is terminal. This is the same `CompletedSheetScope` used by the completed-history detail read.

Rules:

- This extension is **content-only and read-only**. It does not permit upload, replacement, deletion, deactivation, link creation, or any evidence/sheet/transaction mutation; those routes keep their existing guards.
- It does not change `AuditQueryService`, audit/revision visibility, public-link behavior, or any existing purchaser, Cost Center Manager, or Senior Manager evidence result.
- It adds no parallel storage or download mechanism and no new evidence route.
- Out-of-scope evidence keeps the existing non-disclosure answer (404); deactivated evidence keeps its existing 403.
- A Finance actor cannot use this rule to read evidence outside a sheet the completed-history operation would expose.

---

## 6. Reused recharge write

`POST /api/v1/accounts/{accountId}/charges`

**Unchanged request** (`AccountCharge`):

```json
{
  "amountMinor": 500000,
  "description": "Monthly funding",
  "occurredAt": "2026-11-16T10:00:00+00:00",
  "expectedVersion": 3
}
```

**Unchanged headers**: `X-PettyCash-Command-ID` is required and must be a valid UUID.

**Unchanged response 201** (`AccountMutationResult`):

```json
{
  "account": {
    "id": "0b1f5c1e-6f6a-4a1e-9f0c-2f0d6a7b8c9d",
    "userId": "alice",
    "balance": { "amountMinor": 650000, "currency": "IRR", "minorUnit": 0 },
    "active": true,
    "version": 4
  },
  "movement": {
    "id": "f6a7b8c9-d0e1-4f2a-9b3c-5d6e7f809123",
    "type": "CHARGE",
    "amount": { "amountMinor": 500000, "currency": "IRR", "minorUnit": 0 },
    "balanceAfter": { "amountMinor": 650000, "currency": "IRR", "minorUnit": 0 },
    "description": "Monthly funding",
    "sourceRevisionId": null,
    "occurredAt": "2026-11-16T10:00:00+00:00"
  }
}
```

**Rules**

- The existing actor gate (`FINANCE` or `ADMINISTRATOR`), cross-user allowance, inactive-account refusal, version guard, idempotent replay, movement/balance persistence, and audit event are **unchanged**.
- A repeated submission with the same command id returns the original result; no second financial effect occurs.
- The UI must not show success or a resulting balance until this 201 is received. A failure/unknown result must not be presented as success.
- The destination currency is the account's currency; the request has no currency field and no conversion is offered.

---

## UI contract (normative presentation rules)

1. The ledger table has exactly six user-visible columns in this order: **Date**, **Description**, **Approved**, **Recharge**, **Expense**, **Balance**.
2. The Approved cell for a sheet-linked consumption renders the localized `${approvedCount} of ${totalCount}`; a charge renders the localized “not applicable”.
3. A row shows a monetary figure in exactly one of the Recharge/Expense columns.
4. Every Balance cell renders the server's `balanceAfter`; the page never computes a running balance.
5. A negative balance renders as a magnitude plus the approved owed label — English `Amount Owed to Petty Cash Holder`, Persian `طلب تنخواه‌دار` — not as an error, zero, or bare negative.
6. The ledger, holder, account, and completed-history surfaces expose no Approve/Reject/Return, decision-edit, movement-edit, or historical mutation control.
7. Completed history shows reference, title, purchaser, total/approved/rejected counts, and actual posted amount; detail additionally shows every transaction, its stage decisions, evidence metadata, and posting information where the record holds them.
8. Every surface has explicit loading, error, and empty states; no blank or fabricated surface.
9. All user-facing strings are localized in English and Persian; direction follows the active language; the existing Nextcloud shell/navigation is preserved.
10. No financial value is fabricated, inferred, or locally substituted.
11. Completed-history evidence uses the existing preview/content path under the read-only rule above. The UI offers no upload, replacement, deletion, deactivation, or link-creation control in the module, and it never fabricates unavailable content.

## Canonical-contract follow-up

Before implementation, a separately authorized contract change must add these five paths and their schemas to `specs/001-pettycash-v1-core/contracts/ocs-api.yaml`. Until that change lands:

- the existing canonical contract remains the authority for the endpoints it already declares;
- this feature contract is the planning authority for the additive reads;
- implementation must not claim the canonical contract already covers the new routes.

The completed-history evidence-content condition is a server-side authorization rule on an existing operation; it adds no path or schema. If the canonical contract's authorization description is updated, it belongs in the same separately authorized contract batch. Until then, this feature contract records the condition.
