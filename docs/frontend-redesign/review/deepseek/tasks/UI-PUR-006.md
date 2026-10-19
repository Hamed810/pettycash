# UI-PUR-006 — Workspace transaction table, row actions, and an honest History tab

- **Priority:** P1 · **Status:** PROPOSED · **Size:** M · **Risk:** low
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-019, GAP-051, GAP-052, GAP-053, GAP-057 · **Backend impact:** NONE

## 1. User-visible outcome

Inside the sheet workspace the purchaser sees every transaction in the sheet — including a 30+ row sheet — with the correct columns, a per-row action set that respects status and permission, and a History view that shows real decision values instead of the word "DECISION".

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| Transaction table lives in the route detail view | `src/views/sheets/SheetDetailView.vue:114-121` (column keys incl. `expenseDate, amount, costCenter, expenseType, stage, status, evidenceCount, actions`), `:403-425` |
| Columns declare `sortable: true` (client-side) | `:114-121` |
| Per-transaction history endpoints are the only history | `appinfo/routes.php:31-32` — `GET /transactions/{id}/revisions` (bare array, `lib/Controller/AuditController.php:48-64`) and `GET /transactions/{id}/audit` (paged `AuditPage`, `:76-101`) |
| No sheet-wide history endpoint, deliberately | `routes.php` (no sheet-scoped audit route); `spec.md:240` |
| The audit event's top-level `decision` is the literal `'DECISION'` | `lib/Controller/AuditController.php:115`; the real value is inside `after` (`ManagementReviewService.php:286-291`; finance adds `financial_effect` `FinanceSettlementService.php:353-359`) |
| Prototype merges per-transaction audits client-side and disclaims a sheet-level API | `prototype/manus-purchaser-v1/app.js:225-226` |
| Prototype rows are focusable without a role | `app.js:214` |

## 3. Authoritative references

- FR-063 `spec.md:1049-1057` — sheet detail content (incl. the latest active revision number, `activeRevisionNumber`).
- FR-051/FR-052 `spec.md:992-998` — history is per transaction and every event is recorded.
- `spec.md:240` — the deliberate rejection of a sheet-level history aggregate (ordering across transactions, per-transaction authorisation, pagination).
- `contracts/workflow.md` — decision semantics the History tab renders.
- Accepted direction: Overview / Approvals / History workspace structure (`handoff/manus/01_PROJECT_CONTRACT_EN.md`).

## 4. Allowed scope / non-goals

**In scope**
- The transactions table inside the workspace: the existing column set, one row per transaction, usable at 30–40 rows without horizontal scroll at 390px (cards), and without re-sorting a page that the server ordered.
- Row activation opens the read-only transaction view (`/transactions/:id`); the row's action set is limited to what the status and `allowedActions` permit — no action invented client-side (the transaction payload carries a capability set: `TransactionPayloadFormatter.php:283-313`).
- The History tab: an explicit, clearly-labelled aggregation of per-transaction audit events fetched from `GET /transactions/{id}/audit`, with the audit feed **paged** and the revisions array treated as unbounded-but-ordered (`AuditController.php:48-64,76-101`).
- History rows must render the decision value from the event's `after` payload, never the literal `decision` field; the revision number comes from the same place.
- A visible disclaimer that this is a per-transaction aggregation, not a sheet-level audit record (mirroring `app.js:226`), so a reader is not misled about ordering guarantees.
- Adopt the async-state idiom chosen by `UI-PUR-002` for the tab's loading/empty/error states.

**Non-goals**
- No sheet-wide audit endpoint, no new API, no client-side "global timeline" claim beyond what the aggregating fields support.
- No decision or approval UI here: this is the **purchaser's** read surface. Approvals tab shows statuses/history, not action buttons for managers.
- No virtualization (GAP-019 recorded: no measured problem; acceptance is a scroll/perf check).
- No bulk actions of any kind.
- No change to the transaction read view's own layout beyond what the workspace shell requires.

## 5. Implementation behaviour

- Table → cards at the project's existing breakpoint; amounts via the shared money helper; dates via the shared date helper (`UI-PUR-001`).
- Sorting a visible column re-orders only the loaded row set and must be labelled as such if the sheet ever pages (today `SheetDetail` returns all transactions in one payload — verify before claiming, and if it ever pages, say "sorted within this page").
- Row keyboard semantics: `role="button"` (or a real `<button>` in the first cell) for the activation target — the prototype's role-less focusable rows (`app.js:214`) must not be reproduced.
- History pagination: "load more" or paging, with an explicit end state; never render a truncated list as if it were complete.
- History ordering: state the ordering basis (the audit feed is chronological per transaction; across transactions the merge order must be deterministic — sort by `occurredAt` then by transaction id — and labelled).
- Empty states: a sheet with no transactions, a transaction with no decisions, and a transaction whose audit is empty each render distinct, honest copy.

## 6. Acceptance criteria

1. A 40-row sheet renders in the workspace; at 390px each row is a card with labelled fields and no horizontal scroll.
2. Every row's actions are a subset of the server's capability set for that transaction; a submitted transaction offers no purchaser edit control (negative case).
3. Activating a row (mouse and keyboard) opens the transaction view; returning restores the workspace and the tab.
4. The History tab shows, for an approved transaction, the decision value `APPROVE` (or the catalogued equivalent) and **never** the string `DECISION` (negative case, asserted literally).
5. The audit feed requests a bounded page size and issues a second request when more is available; a 200-event transaction does not render 200 rows by default if the feed is paged — verified by asserting the request count.
6. The aggregation disclaimer is present and visible.
7. New strings are catalogued; no literal text added.
8. Existing `tests/Frontend/Component/AuditEvidence.spec.ts` expectations remain satisfied or are deliberately updated with a stated reason.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/AuditEvidence.spec.ts` | audit values from `after`, literal-`DECISION` negative case, paging requests |
| Component | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | 40-row fixture, card mode at 390px, capability-limited actions |
| E2E | `npm run test:e2e` — extend `tests/E2E/audit-evidence.spec.ts` (TO_VERIFY: lab) | History tab journey end to end |
| Gate | `npm run check` | clean |

## 8. Dependencies / rollback

- **Depends on:** `UI-PUR-005` (the shell the table lives in).
- **Rollback:** the table and History tab are additions inside one view; revert restores today's single view.

## 9. Definition of Done

- [ ] Table, row actions, History tab with paging and disclaimer.
- [ ] The `DECISION` literal negative test exists and passes.
- [ ] Screenshots at desktop and 390px for owner review.

## 10. Approval gate

**PROPOSED.** Not approved. The History tab's client-side aggregation is the one judgement call here; the owner may prefer to omit the tab rather than present a derived view — that choice is recorded in `DECISIONS_AND_BLOCKERS.md` D-11.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Current status:** `READY_FOR_OWNER_APPROVAL` (the header's `PROPOSED` means exactly this; nothing here is approved).
- **D-11 resolved:** the History tab is part of the design and must be assembled from the **existing** transaction-level audit and revision endpoints. No sheet-wide history endpoint may be invented, and the tab must present itself honestly as per-transaction history.
- **Internal order:** slice 1 = the workspace transaction table and row actions (needed by the workspace tier); slice 2 = the History tab. Both belong to this card and each keeps its own acceptance line and tests.
- **D-07:** the two published-but-unreachable transaction statuses must never be rendered (already an acceptance criterion here).
- Row actions come from the payload's own permission projection; no client-side decision rule may be derived.
