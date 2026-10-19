# BUNNY_READY_QUEUE — the authorised implementation queue for the purchaser frontend

**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe`
**Prepared for:** Bunny (implementation agent). **Prepared by:** the reconciliation pass recorded in `OWNER_DECISION_RECONCILIATION.md`.
**Status of this document:** `PROPOSED`. It is a *queue*, not an approval. **No task in this file is approved to run.** A card may only be started after the owner has explicitly approved that card.

---

## 1. How to use this queue

1. **One card at a time.** The card is the contract: its scope, non-goals, acceptance criteria and tests are binding. Do not widen it, do not merge two cards, do not "fix nearby things".
2. **Never start an unapproved card, and never self-approve.** A card is startable only when the owner has approved *that card* in writing. Approving this queue is not approving a card.
3. **No backend, contract, spec, or prototype edits — ever, on any card.** Every card below is frontend-only unless the card says otherwise, and the two that touch backend behaviour (`UI-PUR-003`, `007`, `014`) are blocked. If a card appears to require a server change, **stop and report**; do not work around it.
4. **Do not install dependencies.** Use the existing `package.json` scripts. If a package seems missing, report it.
5. **Stay inside the card's file list.** If a correct fix requires touching a file the card does not name, stop and report.
6. **Tests during the card; gates at batch boundaries.** Targeted Vitest specs per card; `npm run check` and the PHP suite only at batch exit (see `TEST_STRATEGY.md` §5).
7. **A card's premise is a hypothesis until re-verified.** Every card cites file-and-line evidence at the baseline SHA. Re-read those lines before editing. If the source now contradicts the card, stop and report rather than improvising.
8. **Record evidence per card:** the commands you actually ran and a short result summary. Never claim a test that was not run, and never describe a source-level finding as "runtime verified".
9. **Keep `GAP-*` references** from the card in the branch/PR description; traceability is part of the deliverable.
10. **E2E needs the lab.** `npm run test:e2e` triggers `pretest:e2e` → `scripts/dev/opcache-settle`, i.e. the Nextcloud lab must be up (FR-071's 390px assertion sets its own viewport; the installed Pixel 7 project is 412px).

---

## 2. Status vocabulary and current totals

| Status | Meaning | May Bunny start it? |
|---|---|---|
| `READY_FOR_OWNER_APPROVAL` | No blocker found; correct with the current backend; awaiting the owner's per-card approval | Only after written approval |
| `BLOCKED_BY_BACKEND` | Requires a `BE-CR-*` that is not approved | **No** |
| `BLOCKED_BY_DESIGN` | A design question must be answered first | **No** — currently none |
| `BLOCKED_BY_SPEC` | A specification question must be answered first | **No** — currently none |
| `OPTIONAL_DEFERRED` | Improvement with no committed requirement | **No** — currently none |

**Audit-vocabulary mapping:** the cards' original `PROPOSED` means exactly `READY_FOR_OWNER_APPROVAL`; the cards' `BLOCKED_OWNER_DECISION` has been resolved into either `READY` (the decision was answered) or `BLOCKED_BY_BACKEND` (the decision was answered but the backend change it needs is still unauthorised). Each card carries an appended **Reconciliation update** block; where a card's header line and that block disagree, **the block wins**, and this file plus `BLOCKED_ITEMS.md` are authoritative.

**Totals (16 frontend cards):** 13 `READY_FOR_OWNER_APPROVAL` · 3 `BLOCKED_BY_BACKEND` · 0 `BLOCKED_BY_DESIGN` · 0 `BLOCKED_BY_SPEC` · 0 `OPTIONAL_DEFERRED`.
**Backend requests (6):** all `BLOCKED_PENDING_OWNER_APPROVAL`.

---

## 3. Owner priority tiers → tasks (with the two deliberate deviations)

| Owner tier | Tasks | Note |
|---|---|---|
| 1 · Shared frontend foundations | `UI-PUR-001` | Must land first: every other card formats dates/amounts and depends on direction handling |
| 2 · Reusable layout and design components | carried **inside** `UI-PUR-002` (status pill, amount/date cells, async-state idiom) and `UI-PUR-008` (the reusable `NcActions` row-menu pattern) | No separate task ID was invented; extraction is the first step of those two cards |
| 3 · Purchaser Sheets List | `UI-PUR-002` → `008` → `004` | Sequential: all three touch `SheetListView.vue` / its store |
| 4 · Sheet Detail workspace | `UI-PUR-004` → `005` → `006` (table slice) → `016` | `004` precedes `005` because the overlay consumes the preserved list state |
| 5 · Transaction creation/editing | `UI-PUR-009` → `011` → `012` | Form-centric lane |
| 6 · Evidence integration | `UI-PUR-010` | Same file as the form lane; keep it in the same batch |
| 7 · Review and correction UX | `UI-PUR-013` | `UI-PUR-016`'s evidence tier additionally waits on `BE-CR-004` |
| 8 · History and audit views | `UI-PUR-006`'s **second slice** (History tab) | Deviation 1: the card's *table* slice is needed by tier 4, so the card sits in tier 4 and its History slice satisfies tier 8 |
| 9 · Localization, calendar and theme | `UI-PUR-001` (mechanism, tier 1) + `UI-PUR-015` (a11y/theming parity) | Deviation 2: the *mechanism* cannot wait until tier 9 because tier 2–8 screens depend on it; the theme/RTL-safety parity pass stays at the end |
| 10 · Browser tests and regression validation | **gate, not a task** — see §5 | No task was invented for it; existing suites already cover the areas |

**Blocked lane (do not start):** `UI-PUR-003`, `UI-PUR-007`, `UI-PUR-014` and all six `BE-CR-*`.

---

## 4. The queue

### Batch A — foundations and the purchaser list (frontend only, no backend, no open decision)

#### A1 · `UI-PUR-001` — Locale-aware direction and calendar-aware date/number formatting foundation
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size M · Risk medium- · GAP-064, 065 (mechanism), 066, 067, 068
- **Outcome:** direction follows the active locale instead of a hardcoded `dir="rtl"`, and every date/amount site formats through one locale-aware helper instead of a hardcoded `fa-IR`; the display calendar becomes a preference that is independent of language.
- **Depends on:** nothing. **Everything else depends on it.**
- **Primary files:** `src/l10n.ts`, `src/main.ts`/app bootstrap, the ~20 views carrying `dir="rtl"`, shared formatting helpers, `l10n/*` **read-only**.
- **Important boundary:** ships the *mechanism only*. It must **not** author or stub an English catalog (`REC-01` is an open owner decision), and any new key it introduces must ship a `fa` entry or `npm test` fails on `tests/Frontend/l10nCatalog.spec.ts`.
- **English-LTR verification is `TO_VERIFY`** until an `en` catalog exists (see `TEST_STRATEGY.md` §3).

#### A2 · `UI-PUR-002` — Purchaser sheet list: the six-column layout, title above ID, and honest amounts
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size M · Risk low–medium · GAP-009, 010, 048, 047, 057
- **Outcome:** the list renders the approved six-column structure with the title above the ID and unambiguous amounts, while **every** FR-062/FR-063 figure keeps its own label and cell.
- **Resolved dependency (was D-04):** FR-062 is contractual and says the stage-approved figures appear "as separate columns … alongside the latest effective outcome column". Visual grouping is allowed; removal is a spec amendment that has not been made. Do not delete figures.
- **Added criterion (`REC-06`):** the date filter must name the date it filters — it is the sheet's **creation** date (`sheets.filter.from`/`.to` are generic today).
- **Also carries tier 2:** extract the reusable row primitives (status pill, amount cell, date cell, async-state idiom) as part of this card; `UI-PUR-008` and the workspace then reuse them.
- **Depends on:** `UI-PUR-001`. **Files:** `src/views/sheets/SheetListView.vue`, `src/stores/sheets.ts`, shared components.

#### A3 · `UI-PUR-008` — Sheet actions behind a 3-dot overflow menu
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size S · Risk low · GAP-013
- **Outcome:** per-row actions live behind an `NcActions` overflow menu (the codebase currently uses none), with delete routed through the existing confirmation dialog.
- **Depends on:** `UI-PUR-002` (row shell). **Files:** `SheetListView.vue` + the shared menu; reuses `DeleteSheetDialog.vue`.
- **Note:** this card establishes the reusable menu pattern that `UI-PUR-005`/`007` reuse.

#### A4 · `UI-PUR-004` — Resume where the purchaser left: list state across the sheet detail
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size S · Risk low · GAP-017 (state half)
- **Outcome:** returning from a sheet restores the list's page, filters and scroll; a post-edit refetch cannot silently reset the page or drop the user onto a wrong page; an emptied last page falls back honestly.
- **Depends on:** `UI-PUR-002`. **Must land before `UI-PUR-005`** — the overlay route renders beneath the list and consumes this preservation.
- **Files:** `src/views/sheets/SheetListView.vue`, `src/stores/sheets.ts`, router guards as needed.

**Batch A exit gate:** `npm test` + `npm run typecheck` + `npm run lint` + `npm run stylelint`, plus `npm run check` once at the end of the batch. Details and the per-card targeted specs: `TEST_STRATEGY.md` §4–§5.

---

### Batch B — the sheet detail workspace

#### B1 · `UI-PUR-005` — Sheet detail workspace: the large overlay over the list
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size L · Risk medium–high · GAP-017 (shell), 062, 070, 073
- **Outcome:** the detail opens as the designed large overlay over the list (deep-linkable route preserved), with the tab strip, a real focus trap, focus returned to the originating row on close, and full-screen behaviour on mobile.
- **Design evidence:** approved per D-01 (`references/dialogs/unsaved-changes-en-desktop-v1.png`, `references/mobile/*` — 9 images listed in `BLOCKED_ITEMS.md` §4). Mobile acceptance is against those references.
- **Depends on:** `UI-PUR-004` (list state) and the primitives from `UI-PUR-002`.
- **Accessibility is part of the card:** the prototype's missing focus trap must not be ported (GAP-073).
- **Files:** `src/views/sheets/SheetDetailView.vue`, router, new overlay/workspace components.

#### B2 · `UI-PUR-006` — Workspace transaction table, row actions, and an honest History tab
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size M · Risk low · GAP-019, 051, 052, 053, 057
- **Two internal slices, in this order:**
  - **B2.1 — table + row actions** (satisfies tier 4): transactions in the workspace with stage-appropriate actions, read from the payload's own permission projection; never inventing a client-side decision rule.
  - **B2.2 — History tab** (satisfies tier 8): assembled from the **existing** transaction-level audit and revision endpoints (D-11). No sheet-wide endpoint may be invented, and the tab must present itself honestly as per-transaction history. The published-but-impossible statuses (D-07) must never be rendered.
- **Depends on:** `UI-PUR-005`. **Files:** workspace components, `src/services/*` read wrappers.

#### B3 · `UI-PUR-016` — Tell the purchaser what is blocking submission, before they press Submit
- **Status:** `READY_FOR_OWNER_APPROVAL` (evidence tier deferred) · Size S–M · Risk low · GAP-031, consumes GAP-030
- **Outcome:** the Submit control stays reachable and the sheet explains precisely what is missing, instead of a silent or unexplained block; the server's 422 remains the backstop.
- **Resolved behaviour (was an open question):** "enabled with explicit blockers" is the chosen treatment — it is the accessible option and it does not misrepresent the server.
- **Two tiers:** blockers that mirror **server-enforced** preconditions (description, cost centre, expense type, at least one transaction) may be presented as blocking. The **evidence** blocker must be presented as **advisory** and must not claim the server will refuse — FR-023 is not enforced server-side and `BE-CR-004` is unapproved. When `BE-CR-004` is approved, the tier may be promoted in a follow-up card.
- **Depends on:** `UI-PUR-005`. **Files:** workspace/sheet-submit components.

**Batch B exit gate:** as Batch A, plus a keyboard/focus pass on the overlay (`tests/Frontend/keyboard-accessibility.spec.ts`, `tests/Frontend/Component/RtlAccessibility.spec.ts` are the pinning suites).

---

### Batch C — transaction creation, editing, evidence, correction

#### C1 · `UI-PUR-009` — Preselect the cost center from the last saved transaction in the sheet
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size S · Risk low · GAP-024
- **Outcome:** a new transaction in an existing sheet starts on the cost centre of the sheet's **most recently created and saved** transaction, falling back honestly to the existing default when none exists.
- **Resolved dependency (`REC-05`):** the ordering source is `TransactionSummary.activeRevision.createdAt` — every save creates a new revision (`TransactionService.php:330-332`), so this is a real last-save timestamp. **Do not** use array order, the displayed order, or the expense date.
- **Depends on:** `UI-PUR-005` (navigation into the form from the workspace) — can be built against the existing form route if the workspace is not yet merged.

#### C2 · `UI-PUR-010` — Evidence can only be attached to a transaction that exists
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size S · Risk low · GAP-025
- **Outcome:** the evidence affordance appears only once a transaction exists to attach it to; no upload is attempted against a non-existent id, and nothing is silently discarded. The 100 MB per-file ceiling must be re-presented accurately (verified-correct control — do not invent a MIME allowlist).
- **Files:** `src/views/transactions/TransactionFormView.vue` (evidence block).

#### C3 · `UI-PUR-011` — Delete a draft transaction (FR-025)
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size S · Risk low · GAP-023
- **Outcome:** a draft transaction can be deleted from the form/workspace with a confirmation naming the transaction; a stale-version deletion surfaces the existing 409 and reload rule instead of a generic error. The server path already exists and is guarded — no backend change.
- **Files:** `TransactionFormView.vue`, services wrapper, confirmation component.

#### C4 · `UI-PUR-012` — The unsaved-changes guard (save / discard / stay), and the 409 reload rule
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size M · Risk medium · GAP-054, 029, 056 (explicit non-goal)
- **Outcome:** navigating away from a dirty form raises the **approved** three-way dialog (D-01); Cancel, Escape, browser back, route change and unload are all guarded; a 409 on save explains the conflict and offers a reload rather than silently retrying.
- **Design evidence:** `references/dialogs/unsaved-changes-en-desktop-v1.png` — approved per D-01.
- **Explicit non-goal:** no global "Save sheet" (verified-correct control).
- **Files:** router guards, form views, shared dialog component.

#### C5 · `UI-PUR-013` — Returned transactions must go through the correction entry point
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size S · Risk low–medium · GAP-028
- **Resolved path (`REC-04`):** keep the existing PATCH-based correction flow as canonical (it is already pinned by passing tests and produces fewer revisions), and **remove (or repair) the dead `POST /correction` client wrapper** that omits `expectedVersion` and has no caller. Record the chosen path in the card's evidence. The alternative (adopting `POST /transactions/{id}/correction`) is recorded for the owner and must **not** be adopted unilaterally.
- **Files:** `src/services/transactionsApi.ts` (or equivalent), `TransactionDetailView.vue`, `TransactionFormView.vue`.

---

### Batch D — parity and polish

#### D1 · `UI-PUR-015` — Accessibility and theming parity for the redesigned purchaser surfaces
- **Status:** `READY_FOR_OWNER_APPROVAL` · Size M · Risk low · GAP-058, 059, 070
- **Outcome:** the redesigned surfaces match the existing accessibility and theming standards: light/dark parity, focus visibility, contrast on the new pill/menu/card components, reduced-motion honesty (the app has no `prefers-reduced-motion` handling — do not claim it does), and the 390px no-horizontal-scroll floor preserved.
- **Depends on:** all previous batches (it audits shipped surfaces).
- **Files:** the redesigned components' styles plus the shared theming tokens.

---

### Blocked lane — visible here so nothing is forgotten; **do not start**

| Card | Status | Blocked by | Unblock condition |
|---|---|---|---|
| `UI-PUR-003` — sorting: OPEN-first in either direction, correct across pages | `BLOCKED_BY_BACKEND` | `BE-CR-002` (unapproved). D-09 is **resolved**: OPEN-first is required and must hold across pagination, so a client-side sort of the loaded page is not an acceptable substitute | `BE-CR-002` approved **and** the intra-OPEN ordering question answered |
| `UI-PUR-007` — edit the sheet's title inline while OPEN, without ever changing its account by accident | `BLOCKED_BY_BACKEND` | `BE-CR-001` (unapproved). D-05 is **resolved** (account is editable while OPEN, subject to real permissions) but no contractually-correct frontend-only path exists: the controller requires `accountId`, the payload never carries the sheet's account, and guessing is the GAP-011 defect | `BE-CR-001` approved with the currency/permission rule answered |
| `UI-PUR-014` — stage summary inside the sheet workspace | `BLOCKED_BY_BACKEND` | `BE-CR-003` (unapproved). D-06 is **resolved**: counts **and** amounts per stage from actual decisions, Returned shown when applicable, final finance totals are not a substitute — which makes `BE-CR-003` required rather than optional | `BE-CR-003` approved with `REC-03`'s semantics stated |
| `BE-CR-001` … `BE-CR-006` | `BLOCKED_PENDING_OWNER_APPROVAL` | Governance requires explicit separate approval for any API/business-logic/authorization change | Owner approves the exact scope in writing, with each card's open questions answered |

---

## 5. Batch exit gates (tier 10 — validation, not a task)

At the end of each batch, on a clean tree, with the lab up where E2E is involved:

| Gate | Command | Scope |
|---|---|---|
| Frontend aggregate | `npm run check` | openapi + portability + error-rate + typecheck + lint + stylelint + Vitest + build + notices |
| PHP aggregate (batch/milestone boundaries only) | `scripts/dev/phpunit` | unit + integration + contract suites |
| Browser suites (only when the batch changes a browser-visible flow and E2E is available) | `npm run test:e2e` | Playwright, `chromium-desktop` + `firefox-desktop` + `edge-desktop` + `chromium-mobile` |
| 390px floor | `tests/Frontend/fr071NarrowViewport.spec.ts` (sets its own viewport) | FR-071 |
| Traceability | each card's tests, re-run | per-card DoD |

Do **not** re-run an unchanged full gate to obtain the same evidence twice, and do not run the PHP suite after a purely frontend edit.

---

## 6. Cross-cutting rules every card inherits

- **Direction:** no new `dir="rtl"` / `dir="ltr"` hardcoding anywhere; direction follows the locale through `UI-PUR-001`'s mechanism. Never hardcode a direction to make a screenshot match.
- **Locale:** no hardcoded `fa-IR`, no Jalali-only assumption; formatting goes through the shared helpers. The display calendar is a preference independent of language.
- **l10n gate:** every new key needs a `fa` entry, or `tests/Frontend/l10nCatalog.spec.ts` fails. No English catalog may be authored (`REC-01` is an owner decision).
- **Money:** integer minor units end to end; never introduce floats; never round client-side. The app already has no floats — keep it that way.
- **Domain rules stay server-side:** the client may *display* the server's `allowedActions`/status/stage projections, but must never re-derive a decision, approval or status rule. This is why `BE-CR-005` (stale sheet status) must be fixed in the backend, not compensated for in the UI.
- **Permissions:** hide, never disable-misleadingly; the server remains the authority; a client-side permission guess must never gate a write.
- **Theming:** `@nextcloud/vue` 9.x only. No Vuetify, no new UI framework.
- **Responsive:** 390px is the floor — no horizontal scroll, and the layout must stay usable with long Persian *and* long English labels.
- **Focus:** every new overlay/menu/dialog handles focus entry, trap and return.
- **Errors:** never swallow a 409/422; the existing conflict semantics are part of the design.

---

## 7. Do-not-invent list (verified as deliberate non-goals)

No sheet-wide audit endpoint · no invented MIME allowlist · no rolled-over or cross-sheet totals · no global "Save sheet" · no partial or bulk approval · no client-side sorting of the loaded page as a substitute for server ordering · no future-expense-date prohibition · no mobile-only route fork · no new dependency · no backend fix smuggled into a UI card.

---

## 8. Where the detail lives

- Cards (binding scope, acceptance, tests, rollback, approval gate): `tasks/UI-PUR-001.md` … `tasks/UI-PUR-016.md`, `tasks/BE-CR-001.md` … `tasks/BE-CR-006.md`
- Why each task exists (evidence): `AUDIT_REPORT.md`, `GAP_MATRIX.md`, `PROPOSED_TASK_BACKLOG.md`
- Decision reconciliation and `REC-*` items: `OWNER_DECISION_RECONCILIATION.md`
- Everything blocked and why: `BLOCKED_ITEMS.md`
- Verification levels, commands and per-card tests: `TEST_STRATEGY.md`
- File-collision map and original batching: `EXECUTION_PLAN.md` (superseded for **order** by this file where they differ)

*End of queue. Nothing here is approved to run.*
