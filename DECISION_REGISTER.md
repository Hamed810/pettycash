# Decision Register — Pettycash

## M-018: Full-page transaction CREATE Cancel navigation

- **Decision:** Cancelling creation of a new full-page transaction returns the purchaser to the detail view of the owning sheet, preserving the sheet identifier.
- **Requirement:** FR-093 (specs/001-pettycash-v1-core/spec.md)
- **Behavior:**
  - Clean CREATE Cancel → same owning `sheet-detail`
  - Dirty CREATE Cancel → Discard → same owning `sheet-detail`; no transaction created, no staged receipt/evidence uploaded
  - CREATE Save → continues under FR-088 (returns to owning `sheet-detail`)
  - Full-page EDIT cancellation → unchanged (returns to `transactions`)
  - Embedded OPEN-sheet workflows → unchanged
- **Scope:** No changes to authorization, financial rules, workflow state transitions, API contracts, or database schema.
- **Files:** src/views/transactions/TransactionFormView.vue, tests/Frontend/Component/PurchaserFlow.spec.ts, specs/001-pettycash-v1-core/spec.md, plus regenerated frontend assets (js/).
