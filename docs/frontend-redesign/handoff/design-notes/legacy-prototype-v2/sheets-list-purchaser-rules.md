# English purchaser Sheets List rules

The current refinement covers only the English LTR purchaser view. It does not redesign manager or finance review queues, and it does not alter the shared sheet data model.

## Columns

The authenticated purchaser's list is creator-scoped to the purchaser, so the Creator column is omitted from this surface. Creator remains a shared data field and is still available to broader/reusable table renderers where ownership is relevant.

The list retains Currency, Status, Transactions, Active revision, Current/Submitted totals, Latest effective outcome, Stage-approved totals, Key dates, and row actions.

## Actions

The prototype mirrors the inspected status guards:

| Synthetic lifecycle state | Purchaser actions |
| --- | --- |
| Open | View, Edit, Delete |
| Returned for correction | View, Correct |
| Finance review | View only |
| Completed — approved | View only |

The interface does not invent manager or finance permissions. Server authorization remains authoritative; the UI hides controls that the current lifecycle should not offer.

## Synthetic coherence

Open has no submitted revision or stage approval. Returned for correction has a rejected amount and prior manager/senior approvals. Finance review has manager and senior approvals with finance still pending. Completed has all stage approvals and a completion date.
