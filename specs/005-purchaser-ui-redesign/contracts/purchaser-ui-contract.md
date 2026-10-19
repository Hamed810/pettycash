# Contracts: Purchaser UI Redesign

**Feature**: `005-purchaser-ui-redesign` | **Date**: 2026-10-19

This feature changes **no** OCS operation, no OpenAPI schema, no workflow contract, no authorization rule, and no persisted structure. The 001 contracts (`specs/001-pettycash-v1-core/contracts/ocs-api.yaml`, `contracts/workflow.md`) remain the authoritative API/workflow documents and are read-only for this feature.

The contracts below are between the **redesigned purchaser surfaces** and the **existing** server payloads, plus the **management contract** this feature introduces for its own traceability. Every rule is either machine-checked by a named existing guard or explicitly marked as requiring owner evidence.

---

## 1. Presentation contract (UI ↔ existing payloads)

### C-01 — Direction is derived, never declared

1. Every purchaser view root declares `:dir="direction"` from `src/utils/direction.ts`; no purchaser view may contain a literal `dir="rtl"` or `dir="ltr"`.
2. `directionFor()` delegates to Nextcloud's `isRTL()`; a second RTL-language list MUST NOT be maintained.
3. Adding LTR support MUST NOT weaken any existing RTL assertion.

*Guard*: `tests/Frontend/Component/RtlAccessibility.spec.ts`, `tests/Frontend/localeFormatting.spec.ts`.

### C-02 — One formatting path per value kind

1. Dates render through `src/utils/date.ts`; missing renders `—`; unparsable renders the raw value; the same stored value renders identically in a list and a detail.
2. Money renders through `src/utils/money.ts` on minor-unit arithmetic with the payload's currency; no float math, no symbol invention, no conversion.
3. Numbers render through `src/utils/number.ts` with the active locale's digits and grouping.
4. Any new `toLocale*` call site outside these modules is a contract violation.

*Guard*: `tests/Frontend/localeFormatting.spec.ts` (13 tests, verified 2026-10-19).

### C-03 — The calendar preference is display-only and inert

1. The preference (`pettycash.calendarPreference`) MUST NOT be read by any store, API module, or type that builds a request payload.
2. Changing it MUST re-render every mounted formatter without a reload and without a network request.
3. No visible control to change it is authorized by this feature (`FR-005-004`); the mechanism may exist without a toggle.
4. A mutating negative test (identical payloads under both preferences) is required before the card can be called complete (`T018`, outstanding).

*Guard*: `localeFormatting.spec.ts` for (1)–(2); the live surface and the negative test are **not** covered yet.

### C-04 — The sheet list columns are closed

1. Exactly six columns, in order: Sheet, Status, Transactions, Recorded Amount, Latest Approved Amount, First Submitted (`FR-005-006`).
2. `FR-062`'s stage-approved figures remain present, each with its own label and its own cell; a strict six-column list requires an explicit FR-062 amendment (recorded, not adopted).
3. Recorded Amount = `totals.current`; Latest Approved Amount = `totals.effectiveApproved`; never-submitted → `—`; genuine zero → `0`.
4. No Creator, Owner, Currency, action, or invented period column may be added.
5. Status labels come from the catalogued vocabulary with design-token variants; raw enum identifiers MUST NOT be the intended user-facing label.

*Guard*: `tests/Frontend/Component/PurchaserFlow.spec.ts` (137 tests, verified 2026-10-19); `tests/Frontend/unrenderedColumnFallback.spec.ts`.

### C-05 — One action menu per row, gated by status

1. Exactly one overflow trigger per row; its accessible name contains the sheet title.
2. An `OPEN` row offers the approved actions; every other status offers only what the server permits.
3. A destructive action keeps the existing confirmation and MUST issue no request before confirmation.

*Guard*: `PurchaserFlow.spec.ts`, `tests/Frontend/Component/SheetDeletion.spec.ts` (9 tests, verified 2026-10-19).

### C-06 — OPEN-first ordering is a server capability

1. The client MUST NOT sort only the loaded page and MUST NOT claim the ordering requirement is met.
2. A sort control MUST NOT ship before `BE-CR-002` is approved **and** its intra-OPEN ordering and tie-breaker questions are answered.
3. Until then, `UI-PUR-003` stays `BLOCKED_BY_BACKEND`; no client-side approximation of any kind.

*Guard*: review evidence (`PROPOSED_TASK_BACKLOG.md`, `BLOCKED_ITEMS.md` §2.1); no automated guard can catch a lying page boundary.

### C-07 — The workspace is a dialog over the list, and the route stays real

1. `role="dialog"`, `aria-modal="true"`, accessible name = sheet title, focus enters / is contained / returns to the invoking row.
2. The `sheet-detail` route remains addressable (refresh and deep links keep working — `FR-005-013`).
3. Escape closes unless a dirty inner form's guard decides otherwise.
4. At ≤640px: full-screen layer, no horizontal scroll at 390px, exactly one `[role="dialog"]` element after drilling into a transaction.

*Guard to be created with the surface*; existing `RtlAccessibility.spec.ts` and `fr071NarrowViewport.spec.ts` remain baseline guards.

### C-08 — The History tab is an honest per-transaction aggregation

1. History is assembled from the existing per-transaction audit/revision endpoints; no sheet-wide audit endpoint may be requested or invented.
2. A decision value is read from the event's `after` payload; the literal string `DECISION` MUST NEVER be rendered (`D-10`).
3. The audit feed is paged honestly (bounded page + a further request); a truncated feed MUST NOT be presented as complete.
4. A visible disclaimer states that the view is a per-transaction aggregation, not a sheet-level audit record.

*Guard to be created with the surface.*

### C-09 — Row actions are a subset of the server's capability set

1. The transaction table renders from the server payload; no action or permission rule may be invented client-side.
2. No purchaser edit affordance is offered for a submitted transaction.
3. The two published-but-unreachable transaction statuses must never be rendered (`D-07`).

*Guard to be created with the surface.*

### C-10 — Inline sheet edit never guesses the account

1. `accounts[0]`-style substitution is prohibited (`GAP-011`: silent repointing).
2. Until `BE-CR-001` is approved, the account-changing half of the interaction is blocked; a title-only PATCH is contractually correct but implementation-refused (`REC-02`).

*Guard*: review evidence; the blocked card carries the criterion.

### C-11 — Transaction authoring correctness

1. Cost-center preselect uses the most recently saved transaction's `activeRevision.createdAt` only if the center is still active; display order, array order, and expense date MUST NOT be used; an existing transaction in edit mode is never overwritten (`REC-05`).
2. The create route has no file input and no attach control; the edit route keeps the uploader for DRAFT/awaiting-correction; an uploader with an empty transaction ID fails closed.
3. A DRAFT offers exactly one delete affordance with a confirmation; cancel issues no request; confirm sends one `DELETE` with the current `expectedVersion` (FR-025).
4. The two editable forms guard unsaved changes on cancel, Escape, in-app navigation, browser back, and tab close/reload; a pristine form does not prompt; Escape during the guard maps to Stay.
5. A 409 refetches, shows stored values, explains the conflict, and allows a retry with the fresh version; no field merge ever occurs.
6. Returned transactions use exactly one canonical correction path (the PATCH flow, `REC-04`); `expectedVersion` is non-zero; the dead correction wrapper that omits it is removed or repaired; the prior revision remains and resubmission restarts review at Cost Center.

*Guard*: existing `PurchaserFlow.spec.ts`, `TransactionCorrection.spec.ts`; new cases added per card.

### C-12 — Readiness states only server-enforced preconditions

1. Blockers: description, cost center, expense type, at least one transaction (`FR-005-027`).
2. Evidence is shown as `unknown` while `BE-CR-004` is unapproved — never as ready, never as blocking.
3. The Submit control remains reachable; the server's 422 remains the backstop.
4. A submitted sheet renders no readiness surface; a zero-transaction sheet shows the FR-024 blocker and no Submit affordance.

*Guard to be created with the surface.*

---

## 2. Stack contract

1. `@nextcloud/vue` 9.x and existing project utilities remain the approved stack.
2. No new UI framework, Vuetify component, icon set, focus-trap library, or other dependency (`FR-005-029`).
3. All colours come from existing Nextcloud theme variables; no hardcoded hex, local radius constant, or new `font-family` declaration.
4. `prefers-reduced-motion: reduce` disables animation; no information is conveyed only by motion.
5. Invalid controls expose `aria-invalid="true"` and a resolving `aria-describedby`.

*Guards*: `tests/Frontend/Component/NextcloudVueContract.spec.ts`, `tests/Frontend/typography.spec.ts`, `tests/Frontend/keyboard-accessibility.spec.ts`, `tests/Frontend/fr071NarrowViewport.spec.ts`.

---

## 3. Management contract (this feature's own ledger)

1. **Card identity**: every imported card keeps its original ID; no renumbering, no merging, no silent drop.
2. **Single canonical status**: `tasks.md` is the canonical execution ledger; the audit package is evidence, not a parallel status source. When they disagree, `tasks.md` plus the evidence link wins, and the disagreement is reported.
3. **Status vocabulary** is closed to the six values in `research.md` Decision 4.
4. **Gate explicitness**: any non-`DONE_VERIFIED` status names the exact condition that changes it.
5. **No backend by stealth**: a change to `lib/`, `appinfo/`, `specs/001-*`, the OpenAPI contract, or the workflow contract is out of this feature regardless of how it is justified.
6. **No approval by association**: approving this contract, the plan, or the task ledger approves no card, no batch, and no `BE-CR`.
7. **Git boundary**: no Git write is part of this feature; the Git plan is a separate, explicitly approved operation set (`FR`/`Out of Scope`, `GIT-PLAN-001-R1`).

---

## 4. Regression guards that must stay green

| Guard | Property |
|---|---|
| `tests/Frontend/l10nCatalog.spec.ts` | Persian catalog completeness; `fa.js`/`fa.json` key-for-key equality (362 keys) |
| `tests/Frontend/Component/RtlAccessibility.spec.ts` | RTL direction and structure |
| `tests/Frontend/fr071NarrowViewport.spec.ts` | 390px floor; repository-wide ban on `overflow-x: auto\|scroll` |
| `tests/Frontend/Component/NextcloudVueContract.spec.ts` | No Vuetify / no `v-*` |
| `tests/Frontend/routeNameIntegrity.spec.ts` | No invented route names |
| `tests/Frontend/keyboard-accessibility.spec.ts` | Keyboard reachability |
| `tests/Frontend/typography.spec.ts` | Typography contract |
| `tests/Frontend/Component/PurchaserFlow.spec.ts` | Purchaser journeys incl. list/actions |
| `tests/Frontend/Component/SheetDeletion.spec.ts` | Delete confirmation behaviour |
| `tests/Frontend/localeFormatting.spec.ts` | Date/number/money/direction formatting |
