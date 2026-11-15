# Contract Notes — Proposed Additive Senior Review API (NOT ratified)

**Feature**: `specs/007-senior-manager-cost-sheet-review/` | **Status**: design note for the planning phase, aligned with the owner-confirmed decisions D1–D9 (recorded 2026-11-08). Changing the live contract (`specs/001-pettycash-v1-core/contracts/ocs-api.yaml`) requires a separate authorization and the feature's own backend batch. Nothing here is in force.

## 1. Senior-scoped sheet read (mirror of B001–B004, stage-senior)

`GET /api/v1/queues/senior/sheets/{sheetId}`

- **Authorization** (`FR-007-056`): the acting user's active `SENIOR_MANAGER` assignments only (same source as `ManagementReviewService::getSeniorQueue` / `TransactionPayloadFormatter::canDecide`); a sheet unknown or outside those cost centers answers the same `404`, so the endpoint cannot confirm a sheet exists outside the caller's scope.
- **Response shape**: `SeniorManagerSheetReview` (`sheet: SeniorSheetSummary`, `progress`, `transactions[]` with `seniorState`/`seniorDecision`). An unknown/blank sheet reference stays an honest unavailable, never fabricated.
- **Gate (owner D1)**: `SeniorSheetSummary.allTransactionsCostCenterDecided` is a **required, backend-computed field** — `true` only when every sheet transaction has a **final** Cost Center decision (Approve, Reject, or a Return corrected then re-decided); a pending Return does not satisfy it. It is the only source of Senior-queue inclusion; it is never derived client-side and never from partial data. The narrow 001 `FR-037` **visibility** amendment it requires was **recorded in Feature 001 on 2026-11-08** (see `spec.md` Workflow Reconciliation). `reviewStatus` supplies the completed-sheet presentation status (owner D5).
- **Explicitly NOT provided**: any "Waiting for Cost Center Review" state, any sheet-wide CC verdict, or any client-derivable eligibility.

`GET /api/v1/queues/senior/sheets` (optional additive; alternatively the queue stays derived from `GET /queues/senior` + the completeness guard)

- A senior manager-scoped **sheet list** with pending-transaction counts/amounts per sheet, scoped to the manager's `SENIOR_MANAGER` cost centers, including only gate-satisfying sheets whose CC processing is complete (owner D1) and carrying each sheet's `reviewStatus` (owner D5).

## 2. Senior-scoped export (XLSX only, ordered ids)

Additive export capability for the owner-confirmed requirement (D3/D4): the export is **XLSX only** and contains **only** the filtered + sorted transactions in the **on-screen order**, with Cost Sheet identification/metadata and **one row per transaction carrying separate Cost Center and Senior decision fields**.

- **Approach (owner D4)**: additive `ExportRequest.resource: 'senior-queue'` served by `ExportController` with a Senior-scoped row source. The client submits the exact filtered + sorted transaction `ids` via the existing `ExportRequest.ids`; the row source MUST resolve each id in the **client-supplied order** (mirroring `transactionRows`' per-id loop, which already preserves order — it is not a `WHERE IN`) and scope-validate every id.
- **Row shape**: sheet id/title/currency/submitted total + transaction id/description/expense type/amount/date + **separate CC decision and Senior decision fields** (never merged).
- **Format**: `fileFormat: ['xlsx']` for the `senior-queue` resource only; existing CSV/PDF capabilities for other resources are unchanged (owner D3).
- **Measured-zero & refusal**: a legitimate empty selection returns a header-only XLSX (`rowCount: 0`, as today); an out-of-scope id or unsupported resource/format is refused via the existing `UnsupportedSelection` path, leaving no artifact. Server applies scope **before** generation (`contracts/exports.md`; `FR-007-056`), and a refusal names the missing capability rather than returning a partial artifact.

## 3. Contract deltas (when authorized)

- `ocs-api.yaml`: add `SeniorSheetSummary` (**with `allTransactionsCostCenterDecided` gate and `reviewStatus`**), `SeniorManagerSheetReview`, `SeniorSheetQueuePage` (if the sheet list endpoint is chosen), the `/queues/senior/sheets[/{sheetId}]` paths, and the `senior-queue` export resource (`fileFormat: ['xlsx']`, ordered `ids`, combined CC/Senior columns).
- `appinfo/routes.php`: additive `review#seniorSheet` (and optional `review#seniorSheetQueue`), `export#create` untouched for the existing resources.
- **Ordering contract**: the Senior row source MUST emit one row per submitted id in `ids` order; asserted in backend tests (owner D4).
- No change to `POST /transactions/{id}/decisions`, `GET /transactions/{id}`, `GET /queues/cost-center/*`, or the CC decision surface (`FR-007-051`). The 001 `FR-037` change is strictly the narrow Senior-queue **visibility** amendment (recorded in Feature 001 on 2026-11-08; stage transitions stay per-transaction).
