# Feature Specification: Purchaser UI Redesign

**Feature Branch**: `005-purchaser-ui-redesign` (logical feature directory; the current worktree branch is `feat/pettycash-purchaser-ui-batch-a`, and no branch operation is authorized by this specification)

**Created**: 2026-10-19

**Status**: Draft — management baseline only. Batch A is recorded as technically verified; no Batch B work is authorized. See "Approval State" below.

**Input**: User description: "Bring the ongoing Purchaser UI Redesign under Spec Kit management, reconcile Batch A and all UI-PUR/BE-CR cards, update governance documents, and prepare an approval-gated Git operation plan without executing Git writes."

---

## Approval State and Authority

> **This specification is not an approval of implementation.**
>
> - The 16 `UI-PUR-*` cards and 6 `BE-CR-*` cards retain their original identities. Their current dispositions are recorded in the traceability matrix in this document and in `tasks.md`.
> - `BE-CR-001` through `BE-CR-006` are **BLOCKED_PENDING_OWNER_APPROVAL**. No backend, API-contract, workflow-contract, authorization, financial-rule, or specification change is authorized by this feature.
> - Batch A (`UI-PUR-001`, `UI-PUR-002`, `UI-PUR-008`, `UI-PUR-004`) is recorded as it exists in the working tree. `DONE_VERIFIED` means **technical verification only**; it is not product-owner visual acceptance.
> - Batch B is **not started** and is not authorized by this document. Its contents must be approved card by card.

### Source-of-truth order

When artifacts disagree, stop and reconcile; do not silently reinterpret one artifact through another.

1. `specs/001-pettycash-v1-core/spec.md` and its approved contracts (`contracts/ocs-api.yaml`, `contracts/workflow.md`, `data-model.md`) for domain, API, authorization, financial, lifecycle, evidence, currency, and revision behavior.
2. This specification for purchaser-facing presentation, interaction, and verification requirements.
3. `specs/005-purchaser-ui-redesign/plan.md` for the technical approach.
4. `specs/005-purchaser-ui-redesign/tasks.md` as the **canonical task ledger** for execution status.
5. The DeepSeek audit package under `docs/frontend-redesign/review/deepseek/` as **audit evidence**, not as a second requirement source.

`docs/decisions/ADR-002-spec-kit-source-of-truth.md` establishes Spec Kit artifacts as the durable source of truth. `docs/frontend-redesign/CHARTER.md` and `DECISIONS.md` govern the redesign's principles and decision record. This specification does not amend either.

### Domain non-duplication rule

This feature **MUST NOT** restate or redefine the business rules it depends on. Where a requirement needs a domain rule, it cites the authoritative 001 requirement instead. In particular, this feature does not redefine:

- account selection and currency rules (FR-020, FR-027);
- draft minimum information (FR-022);
- submission preconditions (FR-023, FR-024);
- pre-submission edit/delete and concurrent-edit behavior (FR-025);
- post-submission immutability (FR-026, FR-044);
- multi-cost-center sheets (FR-028);
- evidence multiplicity and the 100 MB ceiling (FR-029, FR-074);
- correction and resubmission (FR-044, FR-047);
- audit and revision history (FR-051, FR-052);
- sheet figures (FR-062) and sheet list/detail content (FR-063);
- filters (FR-064);
- Jalali reporting periods (FR-068);
- Persian RTL and the 390px floor (FR-071);
- replay safety (FR-084);
- sheet deletion (FR-086);
- the post-save destination (FR-088).

---

## Current State — Batch A Reconciliation

The following facts are evidence-based. They describe the working tree at the time of this management migration, not a released state.

| Fact | Evidence |
|---|---|
| Branch / HEAD | `feat/pettycash-purchaser-ui-batch-a` at `6006f28400e8962909afab0744460152353c12fe`, identical to `origin/main`; no upstream configured. |
| Batch A scope | `UI-PUR-001`, `UI-PUR-002`, `UI-PUR-008`, `UI-PUR-004`, in that order. |
| Batch A commits | `fae7bce` (C5 source/tests/l10n) and `8869fc6` (C6 generated build). The management checkpoint is C1–C4; C7 records this status. No push. |
| Batch A backend impact | None. No `lib/` or `appinfo/` change; `git status` contains no `*.php` entry. |
| `UI-PUR-001` | **PARTIAL**. Direction/locale/calendar/date/number/money mechanism is implemented and was browser-verified by the Batch A report. A user-facing calendar-change surface (card AC2) and a mutating negative test (card AC5) remain outstanding. English content is blocked by `REC-01`. |
| `UI-PUR-002` | **PARTIAL**. Six Persian columns, title above ID, amount semantics, status labels, and `AsyncState` are implemented and browser-verified. English labels are blocked by `REC-01`; the date-filter labels do not yet name the creation date (`REC-06`); product-owner visual acceptance remains outstanding. |
| `UI-PUR-008` | **DONE_VERIFIED** (technical). One status-gated overflow trigger per row, accessible name, confirmation dialog, cancel-without-request, and keyboard path were browser-verified. |
| `UI-PUR-004` | **DONE_VERIFIED** (technical). Page, filters, and scroll restoration; fresh-entry reset; refetch on return; last-page clamp after delete were browser-verified. |
| Current targeted verification | `npx vitest run tests/Frontend/localeFormatting.spec.ts tests/Frontend/Component/PurchaserFlow.spec.ts tests/Frontend/Component/SheetDeletion.spec.ts tests/Frontend/Component/RtlAccessibility.spec.ts` → **4 files / 168 tests passed** on 2026-10-19 (`PurchaserFlow` 137, `localeFormatting` 13, `SheetDeletion` 9, `RtlAccessibility` 9). |
| Batch A report gate | The Batch A report records `npm run check` on its final tree as **23 files / 481 tests passed**. This management migration did **not** re-run that aggregate gate; the report is the evidence for it. |
| Browser evidence | The Batch A report records two Playwright scripts: `32/32` Persian UI checks and `8/8` EN + `8/8` FA locale checks, plus `5/5` delete-dialog checks. These are **report-recorded**, not re-executed in this pass. |
| Working-tree noise | 335 untracked paths, 317 tracked deletions, 56 tracked modifications. The deletions/replacements are dominated by regenerated `js/` and `css/` build output. Pre-existing user changes include `AGENTS.md`, `CLAUDE.md`, `.claude/`, `docs/Review uploaded pasted content.zip`, and the untracked `docs/frontend-redesign/**` audit/reference/prototype trees. None of these is part of this feature. |
| Catalog | `l10n/fa.js` and `l10n/fa.json` carry the same 362 keys, enforced by `tests/Frontend/l10nCatalog.spec.ts`. |
| Backend change requests | `BE-CR-001` … `BE-CR-006` are all unapproved and blocked. No document in the repository records approval. |

> **Important:** the Batch A report's browser verification was performed against the live lab and is recorded there. This specification treats it as report evidence. It does not convert that evidence into product-owner visual acceptance, and it does not claim that the current working tree was re-verified in a browser during this management pass.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Truthful locale, calendar, and direction rendering (Priority: P1)

A purchaser sees dates, numbers, money, and text direction that match the active language and the chosen calendar, without any stored value changing. A Persian session remains RTL and Jalali by default; a non-Persian session is LTR and Gregorian by default; the calendar is a display preference, independent of language.

**Why this priority**: Every purchaser surface depends on this foundation. A wrong locale, calendar, or direction makes every later visual acceptance ambiguous, and the previous hardcoded `dir="rtl"` made the English-LTR design impossible to review in a running build.

**Independent Test**: Render the sheet list and a transaction form under `fa`/Persian, `en`/Gregorian, `en`/Persian, and `fa`/Gregorian. Direction, digits, and date system change as specified; a missing date renders `—`; an unparsable date renders its raw value; no network request is issued by a calendar change.

**Acceptance Scenarios**:

1. **Given** an English (non-RTL) active language, **When** any purchaser view renders, **Then** the view is LTR and contains no literal `dir="rtl"`.
2. **Given** a Persian active language, **When** any purchaser view renders, **Then** the view remains RTL and the existing RTL assertions still pass.
3. **Given** the same stored date in the list and in a detail view, **When** both render, **Then** they use the same helper and produce the same result.
4. **Given** a missing or empty date, **When** it renders, **Then** the absent marker `—` is shown, never an epoch date.
5. **Given** a calendar preference change, **When** it is applied while views are mounted, **Then** every date re-renders without a reload and without a network request.
6. **Given** a form saved under the Gregorian preference and the same form saved under the Persian preference, **When** the request payloads are compared, **Then** the stored date value is identical. *(This is the card's AC5 negative test and is currently outstanding.)*

---

### User Story 2 — A trustworthy sheet list that resumes where the purchaser left (Priority: P1)

A purchaser opens `/sheets`, sees a compact six-column list with honest amounts, can act on a row through one overflow menu, and returns to the same page, filters, and scroll position after opening a sheet. OPEN-first global ordering is **not** faked client-side; it waits for a server capability.

**Why this priority**: The sheet list is the purchaser's primary working surface and the entry point to every other purchaser journey.

**Independent Test**: With a fixture containing never-submitted sheets, submitted sheets, a zero-transaction sheet, and a sheet whose recorded and effective-approved amounts differ, the list renders the six columns in order, the title above the ID, `—` for an absent submitted figure, `0` for a genuine zero, and one accessible action menu per row. Page/filter/scroll restoration is verified by returning from a detail view.

**Acceptance Scenarios**:

1. **Given** a populated list, **When** it renders at desktop width, **Then** the columns are exactly Sheet, Status, Transactions, Recorded Amount, Latest Approved Amount, First Submitted, and FR-062's stage-approved figures remain present with their own labels and cells.
2. **Given** a row, **When** it renders, **Then** the sheet title is the primary line and the public ID is beneath it.
3. **Given** a fixture where `totals.current` and `totals.effectiveApproved` differ, **When** the row renders, **Then** Recorded Amount equals `totals.current` and Latest Approved Amount equals `totals.effectiveApproved`.
4. **Given** a never-submitted sheet, **When** its submitted figure renders, **Then** it shows `—`; **Given** a submitted sheet whose recorded total is genuinely zero, **Then** it shows `0`.
5. **Given** a sheet with zero transactions, **When** the row renders, **Then** it shows `0` transactions, not a blank.
6. **Given** a 390px viewport, **When** the list renders, **Then** there is no horizontal scroll and no clipped identifier.
7. **Given** the English locale, **When** the list renders, **Then** all headers and status labels come from the catalog. *(Blocked by `REC-01` until an English catalog is approved; raw keys are not accepted as visual completion.)*
8. **Given** the date filter, **When** its labels render, **Then** they name the sheet **creation** date, not an unqualified "from/to".
9. **Given** a row with status `OPEN`, **When** its overflow menu opens, **Then** it offers the approved actions; a non-OPEN row offers only the actions the server permits.
10. **Given** the purchaser opens a sheet from page N with filters and scroll offset, **When** they return, **Then** page N, the filters, and approximately the same scroll offset are restored and the list is refetched.
11. **Given** a fresh entry to `/sheets` from another route, **When** the list mounts, **Then** it starts at page 1 with default filters.
12. **Given** OPEN sheets distributed across pages, **When** the purchaser pages through the list, **Then** no submitted sheet appears above an unrendered OPEN sheet. *(Blocked by `BE-CR-002`; the client MUST NOT sort only the loaded page.)*

---

### User Story 3 — A sheet workspace that preserves context and tells the truth (Priority: P1)

A purchaser opens a sheet into a large workspace over the list, with Overview, Approvals, and History; the list beneath keeps its state; on mobile the workspace is a full-screen layer with no nested dialog stack; transaction history is assembled honestly from per-transaction endpoints.

**Why this priority**: This is the minimum viable detail surface. A route-only detail page loses the list context, and a derived "sheet history" presented as a first-class feed would misrepresent the domain.

**Independent Test**: Open a row, switch tabs by keyboard, drill into a transaction, return, close, and verify focus returns to the originating row and the list is unchanged. At 390px, assert exactly one `[role="dialog"]` element and no horizontal scroll.

**Acceptance Scenarios**:

1. **Given** a row, **When** it is activated by mouse or keyboard, **Then** a workspace opens over the list and the list is not unmounted.
2. **Given** the workspace, **When** it opens, **Then** it has `role="dialog"`, `aria-modal="true"`, an accessible name equal to the sheet title, and focus inside.
3. **Given** the workspace is open, **When** Tab/Shift+Tab are pressed repeatedly, **Then** focus cannot reach the page behind.
4. **Given** the workspace is closed by any path, **When** it closes, **Then** focus returns to the row that opened it.
5. **Given** Escape with no dirty inner form, **When** it is pressed, **Then** the workspace closes; with a dirty inner form, the unsaved-changes guard decides.
6. **Given** the tab strip, **When** it is operated by keyboard, **Then** tabs are reachable, switchable, and the selected tab is announced.
7. **Given** a ≤640px viewport, **When** the workspace opens, **Then** it is a full-screen layer with no horizontal scroll at 390px, and drilling into a transaction does not create a second dialog element.
8. **Given** a sheet deleted elsewhere while open, **When** the workspace refreshes, **Then** it shows a clear not-found state, not a blank page.
9. **Given** a saved draft transaction, **When** the save completes, **Then** the purchaser still lands on the sheet detail with the new transaction and the Submit control visible (FR-088).
10. **Given** the transaction table, **When** a row renders, **Then** its actions are a subset of the server's capability set and no purchaser edit is offered for a submitted transaction.
11. **Given** the History tab, **When** an approved transaction is shown, **Then** the decision value comes from the event's `after` payload and the literal string `DECISION` is never rendered.
12. **Given** a transaction with a paged audit feed, **When** History loads, **Then** it requests a bounded page and offers a further request; it does not present a truncated feed as complete.
13. **Given** the History tab, **When** it renders, **Then** it displays a visible disclaimer that it is a per-transaction aggregation, not a sheet-level audit record.
14. **Given** an OPEN sheet, **When** the purchaser edits its title inline, **Then** the sheet's account is never guessed or substituted. *(Blocked by `BE-CR-001`.)*
15. **Given** the workspace summary, **When** it shows stage statistics, **Then** every count and amount comes from a server aggregation. *(Blocked by `BE-CR-003`; client-side derivation is prohibited.)*

---

### User Story 4 — Safe transaction authoring before submission (Priority: P1)

A purchaser adding a transaction gets a useful cost-center default, can attach evidence only after the transaction exists, can delete a draft deliberately, is protected from losing unsaved work, and starts a returned transaction's correction through one canonical path.

**Why this priority**: These are correctness defects in existing flows. They do not depend on the final visual design and they survive any later restyle.

**Independent Test**: Create transaction A with cost center X, then B with Y; a new transaction preselects Y. On the create screen there is no upload control; after saving, the edit screen accepts an upload with a non-empty transaction ID. Cancel a dirty form and choose Stay/Discard/Save; assert the correct request count and destination. Start a correction from a returned transaction and assert the revision sequence is unchanged.

**Acceptance Scenarios**:

1. **Given** a sheet with saved transactions, **When** a new transaction opens, **Then** the cost center is preselected from the most recently saved transaction's `activeRevision.createdAt`, only if that center is still active; otherwise the field is blank.
2. **Given** the transaction table is re-sorted or reversed, **When** a new transaction opens, **Then** the preselect is unchanged; display order and expense date never influence it.
3. **Given** an existing transaction in edit mode, **When** it opens, **Then** it shows its own cost center and is never overwritten by the preselect rule.
4. **Given** the create route, **When** it renders, **Then** there is no file input and no attach control, and a catalogued hint explains that evidence is added after saving.
5. **Given** the edit route for a DRAFT, **When** a file is selected, **Then** exactly one `POST /api/v1/transactions/{id}/evidence` is issued with a non-empty ID, and no request path contains `//`.
6. **Given** an uploader with an empty transaction ID, **When** it renders, **Then** it is disabled and issues no request.
7. **Given** a DRAFT transaction, **When** the purchaser chooses delete, **Then** a confirmation names the transaction, cancel issues no request, and confirm issues one `DELETE` with the current `expectedVersion`.
8. **Given** a non-DRAFT transaction, **When** it renders, **Then** no delete control is offered.
9. **Given** a dirty sheet or transaction form, **When** the purchaser cancels, presses Escape, navigates in-app, uses browser back, or closes/reloads the tab, **Then** the approved three-way guard is offered (save / discard / stay), with the documented restrictions.
10. **Given** an untouched form, **When** it closes, **Then** no guard appears.
11. **Given** a stale version, **When** a save returns 409, **Then** the form refetches the stored values, explains the conflict, and a second save with the fresh version succeeds; no field merge occurs.
12. **Given** a returned transaction, **When** the purchaser starts a correction, **Then** exactly one canonical path is used, `expectedVersion` is sent, the prior revision is retained, and the resubmission still restarts review at Cost Center.

---

### User Story 5 — Submission readiness and accessible, theme-native surfaces (Priority: P2)

A purchaser can see what blocks submission before pressing Submit, and the redesigned surfaces remain keyboard-usable, theme-native, reduced-motion-aware, and readable at 390px.

**Why this priority**: It improves the primary flow without adding a business capability. The evidence tier of the readiness surface depends on an unapproved backend change and must not overclaim.

**Independent Test**: Open an OPEN sheet whose transaction lacks a cost center; the readiness region names the missing field, Submit remains reachable, and the server's 422 agrees. Open a completed sheet; no readiness region. Emulate `prefers-reduced-motion: reduce`; the workspace opens without animation. Submit an invalid form; `aria-invalid` and a resolving `aria-describedby` are present.

**Acceptance Scenarios**:

1. **Given** an OPEN sheet with an incomplete transaction, **When** readiness renders, **Then** the blocking transaction and the missing server-enforced field are named.
2. **Given** an OPEN sheet with complete transactions, **When** readiness renders, **Then** no blocker is shown and submission succeeds.
3. **Given** a sheet with zero transactions, **When** readiness renders, **Then** the FR-024 blocker is shown and no Submit affordance is offered.
4. **Given** a submitted sheet, **When** it renders, **Then** no readiness surface is shown.
5. **Given** `BE-CR-004` is not approved, **When** evidence is considered, **Then** it is shown as unknown, never as ready or blocking.
6. **Given** `prefers-reduced-motion: reduce`, **When** the workspace opens/closes or tabs switch, **Then** no animation runs and no information is conveyed only by motion.
7. **Given** an invalid form submission, **When** errors render, **Then** each invalid control has `aria-invalid="true"` and `aria-describedby` resolves to the visible message.
8. **Given** the redesigned surfaces, **When** they render in light and dark themes, **Then** every colour comes from existing Nextcloud theme variables and the focus ring is visible.
9. **Given** the changed styles, **When** they are inspected, **Then** no hardcoded hex colour, local radius constant, or new font-family declaration was introduced.
10. **Given** `@nextcloud/vue` 9.x, **When** the surfaces render, **Then** no Vuetify component or new UI framework is present.

---

### Blocked User Journeys (not deliverable until the stated gate opens)

These journeys are part of the redesign's intent but **MUST NOT** be implemented, approximated client-side, or unblocked by editing a contract or specification.

| Journey | Card | Blocking gate | Required owner answer |
|---|---|---|---|
| OPEN-first, cross-page sorting | `UI-PUR-003` | `BE-CR-002` approval | Intra-OPEN ordering and equal-timestamp tie-breaker |
| Inline title/account edit without silent account repointing | `UI-PUR-007` | `BE-CR-001` approval | Currency rule for a differently denominated account; permission reading |
| Per-stage decision counts and amounts | `UI-PUR-014` | `BE-CR-003` approval | `REC-03` semantics and payload shape |
| Evidence as a submission blocker | `UI-PUR-016` evidence tier | `BE-CR-004` approval | Whether FR-023's evidence rule is in the current release scope |
| Live-derived sheet status | list + workspace status display | `BE-CR-005` approval | Lock-version behavior on recomputation |
| Refusing a decision during correction | `UI-PUR-006` history integrity | `BE-CR-006` approval | Whether such a decision is ever legitimate |

---

### Edge Cases

- **Stale version (409)**: the client reloads and lets the purchaser decide; it never field-merges (FR-025).
- **Deleted sheet while the workspace is open**: a clear not-found state, not a blank page.
- **Purchaser with no accounts**: sheet creation still works with `accountId` omitted (FR-020).
- **Deactivated cost center**: the preselect falls back to blank; no error is shown; the draft still saves (FR-022).
- **Sheet with zero transactions**: readiness shows the FR-024 blocker and no Submit control.
- **Missing English catalog**: English labels render raw keys; this is a `REC-01` product gap, not a completed English visual state.
- **Mixed calendar/language**: `fa` + Gregorian and `en` + Persian are legitimate combinations.
- **Deep link to a sheet**: the overlay model must keep the existing route addressable.
- **Nested dialog on mobile**: drilling into a transaction replaces the workspace body; the DOM must contain one dialog, not two.
- **Pagination ties**: identical timestamps must not duplicate or skip rows; this is a backend ordering requirement (`BE-CR-002`).
- **Returned transaction**: correction retains the prior revision and resubmission restarts at Cost Center.
- **Evidence upload before save**: no upload affordance on the create route; no empty-ID request.
- **Dirty working tree**: generated `js/`/`css/` churn and pre-existing user changes must not be staged indiscriminately.
- **Stale generated assets**: the repository tracks build output; a build regenerates it. This is mechanical output, not hand-edited source.

---

## Requirements *(mandatory)*

### Functional Requirements

#### Direction, locale, and formatting

- **FR-005-001**: Text direction MUST be derived from the active language in one place and applied at the app shell; purchaser views MUST NOT hardcode `dir="rtl"` or `dir="ltr"`.
- **FR-005-002**: Dates MUST render through one shared helper; a missing/empty value MUST render `—`; an unparsable value MUST render the raw value; a list and a detail MUST agree for the same stored value.
- **FR-005-003**: Numbers and money MUST follow the active locale's digits and grouping; money MUST remain exact minor-unit arithmetic with the payload's currency and `minorUnit`; no float arithmetic, symbol invention, or currency conversion is permitted.
- **FR-005-004**: The calendar (Persian/Gregorian) MUST be a display preference independent of language, applied reactively without reload or network request, and MUST NOT change any stored value. A user-facing control to change it is **not authorized** by this feature; the mechanism may exist without a visible toggle.
- **FR-005-005**: Persian RTL, the 390px no-horizontal-scroll floor, visible focus, and keyboard operability MUST remain at least as strong as FR-071 requires. Adding English-LTR support MUST NOT weaken the Persian behavior.

#### Sheet list

- **FR-005-006**: The main list MUST render exactly the six accepted columns in order — Sheet, Status, Transactions, Recorded Amount, Latest Approved Amount, First Submitted — while FR-062's latest-effective and three stage-approved figures remain present with their own labels and cells.
- **FR-005-007**: The Sheet cell MUST show the title as the primary line and the public ID beneath it; no Creator, Owner, Currency, action, or invented period column may be added.
- **FR-005-008**: Recorded Amount MUST equal `totals.current`; Latest Approved Amount MUST equal `totals.effectiveApproved`; a never-submitted sheet MUST show `—`, a genuine zero MUST show `0`, and a zero-transaction sheet MUST show `0`.
- **FR-005-009**: Status labels MUST use the existing catalogued vocabulary and design-token variants; raw enum identifiers MUST NOT be the intended user-facing label.
- **FR-005-010**: The list MUST preserve page, filters, and scroll across a sheet-detail round trip, MUST refetch on return, MUST reset on a fresh entry, and MUST clamp to a valid page after a delete.
- **FR-005-011**: Each row MUST expose exactly one status-gated overflow action menu with an accessible name containing the sheet title; destructive actions MUST keep the existing confirmation and MUST NOT issue a request until confirmed.
- **FR-005-012**: OPEN-first ordering across pages is a server capability. The client MUST NOT sort only the loaded page and MUST NOT claim the requirement is met until `BE-CR-002` is approved and implemented. A sort control MUST NOT be shipped ahead of that capability.

#### Sheet workspace

- **FR-005-013**: Activating a row MUST open a workspace over the list, preserving the list's state; the existing sheet route MUST remain addressable so refresh and deep links keep working.
- **FR-005-014**: The workspace MUST implement dialog semantics (`role="dialog"`, `aria-modal="true"`, accessible name), focus entry, focus containment, focus return to the invoking row, and Escape behavior consistent with the unsaved-changes guard.
- **FR-005-015**: The workspace MUST expose Overview, Approvals, and History as a keyboard-operable tablist (or equivalent documented navigation with the same semantics).
- **FR-005-016**: At ≤640px the workspace MUST become a full-screen layer; drilling into a transaction MUST replace the workspace body and MUST NOT create a nested dialog stack.
- **FR-005-017**: The transaction table MUST render from the server payload; row actions MUST be a subset of the server's capability set; no action or permission rule may be invented client-side.
- **FR-005-018**: The History tab MUST aggregate the existing per-transaction audit/revision endpoints, MUST render decision values from the event's `after` payload, MUST never render the literal `DECISION`, MUST page the audit feed honestly, and MUST display a per-transaction-aggregation disclaimer. No sheet-wide audit endpoint may be requested or invented.
- **FR-005-019**: Inline editing of an OPEN sheet's title/account MUST never guess the account (`accounts[0]` or list order) and MUST send the sheet's actual account or wait for `BE-CR-001`. Until `BE-CR-001` is approved, the account-changing half is blocked.
- **FR-005-020**: Stage counts/amounts MUST come from a server aggregation and MUST NOT be derived client-side. Until `BE-CR-003` is approved, the count-bearing shape is blocked; only server-published figures may be shown.

#### Transaction authoring

- **FR-005-021**: A new transaction MUST preselect the cost center from the most recently saved transaction in the same sheet, ordered by `activeRevision.createdAt`, only if the center is still active; otherwise the field MUST be blank. Display order, array order, and expense date MUST NOT be used.
- **FR-005-022**: The create-transaction route MUST NOT show a file input or attach control; it MUST show a catalogued hint that evidence is added after saving. The edit route MUST keep the uploader for DRAFT/awaiting-correction transactions. An uploader with an empty transaction ID MUST fail closed.
- **FR-005-023**: A DRAFT transaction MUST offer exactly one delete affordance with a confirmation naming the transaction; cancel MUST issue no request; confirm MUST send one `DELETE` with `expectedVersion`; non-DRAFT transactions MUST offer no delete control.
- **FR-005-024**: The two editable purchaser forms MUST guard unsaved changes on cancel, Escape, in-app navigation, browser back, and tab close/reload, using the approved save/discard/stay dialog. A pristine form MUST NOT prompt; Escape during the guard MUST map to Stay; no field merge may occur on 409.
- **FR-005-025**: The 409 recovery MUST refetch the record, show stored values, explain the conflict, and allow a retry with the fresh version (FR-025).
- **FR-005-026**: Returned transactions MUST use exactly one canonical correction path (the existing PATCH flow, per `REC-04`); the client MUST send a non-zero `expectedVersion`; the dead correction wrapper that omits it MUST be removed or repaired; the prior revision MUST remain.
- **FR-005-027**: Submission readiness MUST present only server-enforced preconditions (description, cost center, expense type, at least one transaction). Until `BE-CR-004` is approved, evidence MUST be shown as unknown, never as ready or blocking. The Submit control MUST remain reachable and the server's 422 MUST remain the backstop.

#### Accessibility and theming

- **FR-005-028**: The redesigned surfaces MUST support `prefers-reduced-motion`, associate validation errors with their inputs via `aria-invalid` and a resolving `aria-describedby`, use only existing Nextcloud theme variables, and keep a visible focus ring in light and dark themes.
- **FR-005-029**: No new UI framework, Vuetify component, icon set, focus-trap library, or other dependency may be introduced; `@nextcloud/vue` 9.x and existing project utilities remain the approved stack.

#### Governance, scope, and traceability

- **FR-005-030**: The feature MUST preserve the original `UI-PUR-001` … `UI-PUR-016` and `BE-CR-001` … `BE-CR-006` identifiers and map every one to a requirement, a canonical task, a current status, and a gate.
- **FR-005-031**: `BE-CR-001` … `BE-CR-006` MUST remain `BLOCKED_PENDING_OWNER_APPROVAL`. No backend, API-contract, workflow-contract, authorization, financial-rule, or 001-specification edit may be made to unblock them. Approval must be explicit and separate.
- **FR-005-032**: The feature MUST NOT add a new business capability, replace the production frontend with the Manus prototype, or start Batch B without explicit owner approval.
- **FR-005-033**: Every task MUST be small, independently testable, reversible, and traceable to an acceptance scenario. A task that cannot satisfy this MUST be split or returned to the plan.
- **FR-005-034**: Completion claims MUST distinguish source inspection, component/unit verification, aggregate gate results, browser evidence, and product-owner acceptance. A passing test from the wrong runtime is not evidence.

### Key Entities

These are **presentation and management entities**, not new persisted domain entities.

- **Locale context**: active language, resolved locale, derived direction.
- **Calendar preference**: Persian or Gregorian; display-only; persisted per device in `pettycash.calendarPreference`; no stored date value changes.
- **Sheet list session**: page, filters, scroll offset, and return marker for a detail/edit/create round trip.
- **Workspace state**: sheet ID, active tab, nested transaction view, and the invoking row used for focus return.
- **Readiness model**: per-transaction blockers derived only from server-enforced preconditions, with an explicit `unknown` state for evidence until `BE-CR-004`.
- **Traceability record**: card ID, requirement IDs, canonical task IDs, current status, evidence, and gate.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-005-001**: 100% of the 22 imported cards (`UI-PUR-001` … `UI-PUR-016`, `BE-CR-001` … `BE-CR-006`) appear in this specification's traceability matrix and in `tasks.md`, each with a requirement, task, status, and gate; zero unmapped cards.
- **SC-005-002**: The four Batch A affected specs pass on the current working tree: 4 files / 168 tests (verified 2026-10-19); no `lib/` or `appinfo/` diff; no `*.php` entry in `git status`.
- **SC-005-003**: Zero backend or contract changes are made by this feature; all six `BE-CR` items remain blocked.
- **SC-005-004**: Persian RTL and the 390px floor remain green on every redesigned surface (`RtlAccessibility`, `fr071NarrowViewport`), and no purchaser view contains a hardcoded direction attribute.
- **SC-005-005**: Every new user-facing string has a Persian catalog entry; `tests/Frontend/l10nCatalog.spec.ts` remains green; the catalog remains key-for-key consistent between `fa.js` and `fa.json`.
- **SC-005-006**: No Vuetify or new UI dependency is introduced; `NextcloudVueContract.spec.ts` remains green.
- **SC-005-007**: Every remaining card is marked `READY_FOR_OWNER_APPROVAL`, `BLOCKED_BY_BACKEND`, or `NOT_STARTED`; no Batch B task is checked off or silently started.
- **SC-005-008**: Each completed visual card has recorded browser evidence and an explicit product-owner acceptance status; "technically verified" is never reported as "visually accepted".
- **SC-005-009**: Every completion report lists the actual commands and result counts, the changed paths, and a `git status` enumeration; no aggregate gate is claimed without execution.
- **SC-005-010**: A reviewer can trace any acceptance scenario to a task and any task back to a card and a 001 requirement without consulting chat history.

---

## Traceability Matrix

Status vocabulary: `DONE_VERIFIED` (technical verification only), `PARTIAL`, `READY_FOR_OWNER_APPROVAL`, `BLOCKED_BY_BACKEND`, `BLOCKED_PENDING_OWNER_APPROVAL`, `NOT_STARTED`.

| Card | Short title | 005 requirements | Canonical tasks | Current status | Gate / evidence |
|---|---|---|---|---|---|
| `UI-PUR-001` | Locale/calendar/direction foundation | FR-005-001..005 | T016–T020 | **PARTIAL** (Batch A) | AC2 live surface + AC5 negative test + `REC-01`; Batch A report |
| `UI-PUR-002` | Six-column sheet list | FR-005-006..010 | T021–T023 | **PARTIAL** (Batch A) | `REC-01` English labels, `REC-06`, owner visual acceptance |
| `UI-PUR-003` | OPEN-first sorting across pages | FR-005-012 | T028, T042 | **BLOCKED_BY_BACKEND** | `BE-CR-002` + intra-OPEN/tie-breaker answers |
| `UI-PUR-004` | List state restoration | FR-005-010 | T025 | **DONE_VERIFIED** | Batch A report; targeted tests |
| `UI-PUR-005` | Sheet workspace overlay | FR-005-013..016 | T035 | **READY_FOR_OWNER_APPROVAL** | Owner approval; Batch B not started |
| `UI-PUR-006` | Workspace transaction table + History | FR-005-017..018 | T036–T037 | **READY_FOR_OWNER_APPROVAL** | Owner approval; no sheet-wide audit API |
| `UI-PUR-007` | Inline title/account edit | FR-005-019 | T039, T041 | **BLOCKED_BY_BACKEND** | `BE-CR-001` + currency/permission answers |
| `UI-PUR-008` | Row overflow menu | FR-005-011 | T024 | **DONE_VERIFIED** | Batch A report; targeted tests |
| `UI-PUR-009` | Cost-center preselect | FR-005-021 | T029 | **READY_FOR_OWNER_APPROVAL** | `REC-05`; owner approval |
| `UI-PUR-010` | Evidence only after save | FR-005-022 | T030 | **READY_FOR_OWNER_APPROVAL** | Owner approval |
| `UI-PUR-011` | Delete draft transaction | FR-005-023 | T031 | **READY_FOR_OWNER_APPROVAL** | FR-025; owner approval |
| `UI-PUR-012` | Unsaved-changes guard + 409 reload | FR-005-024..025 | T032 | **READY_FOR_OWNER_APPROVAL** | D-01 dialog; owner approval |
| `UI-PUR-013` | Canonical correction path | FR-005-026 | T033 | **READY_FOR_OWNER_APPROVAL** | `REC-04`; owner approval |
| `UI-PUR-014` | Stage counts/amounts | FR-005-020 | T040, T043 | **BLOCKED_BY_BACKEND** | `BE-CR-003` + `REC-03` |
| `UI-PUR-015` | Accessibility/theming parity | FR-005-028..029 | T038 | **READY_FOR_OWNER_APPROVAL** | Runs after surfaces exist |
| `UI-PUR-016` | Submission readiness | FR-005-027 | T034, T044 | **READY_FOR_OWNER_APPROVAL** (server tier) | Evidence tier blocked by `BE-CR-004` |
| `BE-CR-001` | Expose/validate sheet account | FR-005-019, FR-005-031 | T041 | **BLOCKED_PENDING_OWNER_APPROVAL** | Currency + permission answers |
| `BE-CR-002` | Deterministic OPEN-first ordering | FR-005-012, FR-005-031 | T042 | **BLOCKED_PENDING_OWNER_APPROVAL** | Intra-OPEN + tie-breaker answers |
| `BE-CR-003` | Per-stage counts and amounts | FR-005-020, FR-005-031 | T043 | **BLOCKED_PENDING_OWNER_APPROVAL** | `REC-03` semantics |
| `BE-CR-004` | Consistent evidence policy | FR-005-027, FR-005-031 | T044 | **BLOCKED_PENDING_OWNER_APPROVAL** | Scope + four owner answers |
| `BE-CR-005` | Live-derived sheet status | FR-005-031 | T045 | **BLOCKED_PENDING_OWNER_APPROVAL** | Lock-version answer |
| `BE-CR-006` | Refuse decision during correction | FR-005-031 | T046 | **BLOCKED_PENDING_OWNER_APPROVAL** | Whether such a decision is legitimate |

---

## Assumptions

- Batch A's implementation is recorded in C5 (`fae7bce`) and is the baseline for any later Batch B work. The working tree still contains the excluded Feature 004 and pre-existing user changes.
- The approved UI stack remains Vue 3 + TypeScript + `@nextcloud/vue` 9.x + `@nextcloud/l10n`; no parallel framework is introduced.
- The Nextcloud lab remains available for browser evidence at batch boundaries, but this management migration did not use it.
- `specs/001-pettycash-v1-core/` remains authoritative for domain behavior. Any needed change to it is a separate, owner-approved change request.
- The DeepSeek audit package remains the evidence base. Its task cards are not duplicated into Spec Kit; their IDs are imported and mapped.
- The product owner approves each implementation card individually. Approving this specification, the plan, or the task ledger is **not** approving Batch B or any `BE-CR`.
- The existing branch is preserved. The management baseline itself performs no Git write; `GIT-PLAN-001-R1` is a separate approved checkpoint.

---

## Out of Scope

- Any backend, API, workflow-contract, authorization, financial-rule, migration, or 001-specification change.
- Any implementation of `BE-CR-001` … `BE-CR-006`.
- Batch B implementation, including `UI-PUR-005`, `UI-PUR-006`, `UI-PUR-009` … `UI-PUR-016`.
- Authoring an English catalog (`REC-01`) or any partial/stub English catalog.
- Manager, finance, administrator, reporting, or audit-feed redesign beyond the purchaser scope.
- New business features: draft autosave/recovery, page-size control, list search, virtualization, bulk actions, general column sorting, or a sheet-wide audit endpoint.
- Replacing the production frontend with the Manus prototype or copying its token system/code/assets.
- Git writes: `add`, `commit`, `push`, `merge`, `rebase`, `reset`, `clean`, branch/tag/config changes. A separate approval is required.

---

## Review and Acceptance

- **Specification quality**: see `checklists/requirements.md`.
- **Technical approach**: see `plan.md`.
- **Canonical execution status**: see `tasks.md`.
- **Manual verification**: see `quickstart.md`.
- **UI behavior invariants**: see `contracts/purchaser-ui-contract.md`.
- **Evidence package**: `docs/frontend-redesign/review/deepseek/` (audit), `docs/frontend-redesign/review/bunny/BATCH_A_EXECUTION_REPORT.md` (Batch A).
- **Governance updates**: `docs/frontend-redesign/CHARTER.md`, `DECISIONS.md`, `STATUS.md`, `ROADMAP.md`; migration report: `docs/reports/005-purchaser-ui-redesign.md`.

**Approval line (not granted by this document):**

```text
APPROVE SPEC 005 — Purchaser UI Redesign — planning baseline only
APPROVE BATCH B SCOPE — individually listed cards: <none yet>
```
