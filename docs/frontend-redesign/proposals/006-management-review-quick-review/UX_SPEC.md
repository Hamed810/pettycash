# Pettycash — Cost Center Manager UX Proposal (Inline Quick Review)

**Status:** Design proposal for product-owner review. **Not approved, not an implementation authorization, not a modification of any approved specification.**

**Date:** 2026-11-01, revised 2026-11-02 | **Feature:** `006-management-review` | **Stage:** The product owner **approved the overall inline quick-review architecture** (2026-11-02) with the required refinements listed below. Those refinements are now **embedded** in the design-only mockup; this document records the decisions and the resulting surface. This remains a **design-only** proposal: the Phase 1 gate (`T022`) visual decision and the refinements in §13 are awaiting the owner's **final** visual approval. No spec, contract, backend, production frontend, test, or Git state is changed.

**Visual embodiment:** the design-only mockup under `./mockups/` (`index.html`, `mockup.js`, `mockup.css`) and its generated captures under `./mockups/screenshots/`. The approved Manus management references are listed in §15. No image-reading was used to prepare this document; every surface is described from the mockup source, the feature 006 artifacts, the 005 purchaser contract, and the code-intelligence/design handoff records cited inline.

---

## 0. Purpose, scope and standing

This document is the textual UX specification for the **Cost Center Manager quick-review experience**, the proposed successor to the Phase 1 sheet-first queue and the intended form of the Phase 2 **Sheet Review Workspace** (`FR-006-026`). It covers, in order:

1. Sheet identification and purchaser information (§2)
2. The expanded sheet quick-review interface (§3)
3. Compact transaction cards (§4)
4. Supporting-document thumbnails (§5)
5. Document preview overlay (§6)
6. Full transaction details (§7)
7. Approve / Reject / Return-for-correction interactions (§8)
8. Desktop layout (§9)
9. Mobile layout (§10)
10. Persian RTL and English LTR behaviour (§11)

followed by data-honesty classification (§12), proposed changes to the existing Spec Kit requirements (§13), approved references (§15), and open product-owner decisions (§16).

### 0.1 Scope guards (unchanged)

- **Design only.** No backend/infra file is inspected or changed; nothing under `lib/`, `appinfo/`, `tests/Backend/`, `specs/001-*`, or any approved contract is modified.
- **No production frontend code is modified.** The specified surfaces are proposals; the existing `CostCenterQueueView.vue`, `EvidencePreview.vue`, `DecisionForm.vue`, `ResponsiveTable.vue`, `StatusBadge.vue` and the `l10n` catalogs are the reuse targets, not edit targets.
- **No implementation tasks are generated in this document.** The Spec Kit workflow (`/speckit.*`) authorizes tasking only after the owner records the design decision.
- **No Git write.** This file is an untracked working-tree document; no stage, commit, push, or branch operation is performed.
- The constitution (specification-first, honesty, visual gate) and `specs/006-management-review/` remain authoritative. Where this proposal deviates from or extends an approved requirement, the deviation is **proposed** and listed in §13, never applied.

### 0.2 Relationship to the current state

- Phase 1 delivered a sheet-first queue that groups the proven-complete manager pending set and stops at the honest field boundaries (`specs/006-management-review/spec.md` `FR-006-011…023`; `docs/reports/006-management-review-phase-1.md`).
- Phase 1's row expansion lists **only the manager's pending transactions**, each linked to the existing `review-detail` route; no decision is recorded inline (plan D4).
- This proposal extends that expansion into a full **inline quick review**: an expanded sheet panel that shows the manager's relevant transactions with current decision state, compact decision actions on undecided transactions, document thumbnails and preview, a read-only full-details drawer, and a confirmation step — all without leaving the queue context and with exactly one overlay at a time.
- The proposal does **not** claim sheet-title, purchaser, sheet-total, submitted-date, reviewed-history, sheet-wide completion, or Senior eligibility unless a backend capability proves them (§12). Every such field the mockup renders as a design target is listed as backend-required.

### 0.3 Sources consulted

`.specify/memory/constitution.md`; `specs/006-management-review/{spec,plan,tasks}.md`; `docs/reports/006-management-review-phase-1.md`; `docs/frontend-redesign/references/managment/cost-center/README.md`; `docs/frontend-redesign/handoff/design-notes/{DESIGN_ISSUES_TO_FIX,DECISION_ADDENDUM_DRAFT}.md`; `docs/frontend-redesign/handoff/manus/01_PROJECT_CONTRACT_EN.md`; `docs/frontend-redesign/review/deepseek/` (audit evidence only); the proposal mockup (`index.html`, `mockup.js`, `mockup.css`); `src/components/review/DecisionForm.vue` (frontend reuse target) and `specs/001-pettycash-v1-core/spec.md` `FR-033` (explanation rule).**

### 0.4 Owner approval and embedded refinements (2026-11-02)

The owner accepted the architecture **conditional on the following refinements**, all now reflected in the mockup and the body of this document:

1. **Preserve the approved flow** — Queue → Expand Sheet → Transaction Decision — and the listed surfaces. Unchanged; see §1–§11.
2. **Decisions from full details** — the details **drawer now carries Approve / Reject / Return-for-correction** with the same confirmation and explanation rules as inline quick review (Reject **and** Return require explanations; Approve does not). No duplicate submissions; a successful decision updates the card and keeps the sheet context. See §7.2/§8 ; embedded in `mockup.js` (`detailsOverlay` footer + `confirmOverlay` per-kind handling).
3. **Mobile review efficiency** — tighter summary-stat, banner, and toolbar spacing; expanded-sheet reflow; on expand the quick-review panel scrolls into view on mobile (smooth, skipped in capture); closing preview/details returns to the intact panel; touch-sized controls. See §10; embedded in `mockup.css` (container-query density) and `mockup.js` (scroll-to-panel).
4. **Honest statistics** — real connected data only: `Sheets awaiting decisions` and `Transactions pending decision`. Under the owner's final decision #2, unavailable statistics and unsupported history counts (`Decisions this week`, `Load per cost center`, and the `Done` tab) are **removed from the UI entirely** — no illustrative values and no disabled placeholder cards. See §9.2/§12; embedded in `mockup.js`.
5. **Preserve document preview** — compact thumbnails, preview overlay, cross-attachment navigation inside the preview, and the approved file-type-safety + accessibility behaviour. Unchanged; see §5–§6.
6. **Design deliverables only** — no production/backend/contract/spec/Git changes, no tasks, no builds or test runs. Only the mockup and this proposal are updated.

The section references above are the spec detail; the mockup is the visual embodiment. Any residual questions are listed in §16.

### 0.5 Final design decisions (2026-11-02, owner recorded)

1. **Decision actions live in the full-details drawer footer only.** `Approve / Reject / Return-for-correction` appear once, in the drawer footer behind the `Decide this transaction` label; they are **not** duplicated in the drawer body. One entry point per surface, no drift.
2. **Unavailable statistics and unsupported history counts are removed entirely.** The UI shows only real connected data (`Sheets awaiting decisions`, `Transactions pending decision`, and the `All sheets / Awaiting decision / In progress` tabs). No illustrative values and no disabled placeholder cards for capabilities that do not exist yet — `Decisions this week`, `Load per cost center`, and the `Done (47)` count are absent.
3. **Distinct purple Return-for-correction confirmation is kept**, consistent with the design system: purple `Return for correction` confirm button, purple `decision__warning--return` panel, and a required explanation (matching `FR-033`).

All previously accepted quick-review interactions (expand-in-place, thumbnails, preview overlay, full details, decision confirmation, mobile entry/scroll-to-panel, RTL/LTR) are preserved. The mockup in `./mockups/` reflects decisions 1–3; §16 now carries only the remaining agenda.

---

## 1. Interaction concept: keep the queue context, decide transaction-by-transaction

The management journey remains **sheet-first** (`FR-006-001`) and **transaction-level decision** (`FR-006-005`, 001 `FR-033`). The proposal's core idea is that the transition from queue to decision should not discard the queue:

- A sheet row expands **inline** (disclosure) into a quick-review panel scoped to the manager (§3).
- Each transaction renders as a **compact card** (§4) with the current decision state.
- Undecided cards carry **Approve / Reject / Return-for-correction** actions plus **Full details** and document **Preview**.
- Document thumbnails open a **preview overlay** over the queue without losing the panel (§6).
- **Full details** opens a read-only **drawer** for audit-level inspection (§7).
- A decision opens a **confirmation** dialog that posts one transaction decision and returns to the panel (§8).
- Nothing is a sheet-level decision; the panel footer states that sheet-wide decisions are impossible (§3.6).

Navigation hierarchy under this proposal: **Manager Queue → Sheet (expand) → Transaction Card → [Preview | Full details | Decision]** — i.e. the queue is never replaced by a separate destination page for the common review path, and the existing `review-detail` route remains reachable (§7.5) to satisfy `FR-006-017`. Because this refines `FR-006-002/003` (which described a separate sheet workspace), the change is proposed in §13.P1/P2 rather than assumed.

---

## 2. Sheet identification and purchaser information

The queue row (collapsed) and the expanded header both surface sheet identity. **Data-honesty governs every field** (`FR-006-011`, `FR-006-015`): the current API (`GET /queues/cost-center` summaries) provides `sheetId`, transaction-level amounts/currencies, cost-centre scope, and revision timestamps — **not** sheet titles, purchaser identities, sheet totals, or genuine submission dates. The proposal therefore distinguishes **connected fields** (rendered from the API) from **design-target fields** (rendered as the intended design, explicitly labelled unavailable until a backend capability exists).

### 2.1 Collapsed queue row — fields in Manus order

The list header and each row share the same seven-column grid on desktop (§9). In order:

1. **Sheet identity** — sheet icon mark, the **sheet title** (design target; while unpublished shows “Sheet title unavailable” exactly as Phase 1), and a meta line of the short sheet reference (monospace, LTR-isolated) plus an aggregate note such as `3 tx · awaiting` derived from the complete set.
2. **Purchaser** — initials avatar (soft), purchaser **display name** (design target) and **role** (design target) with “Purchaser unavailable” fallback.
3. **Txs** — the manager's **pending transaction count** in the sheet, derived from the de-duplicated complete set (connected).
4. **Total** — per-currency amount for the manager's scope. Connected totals are the manager's pending relevant amount by currency (`FR-006-014`, no cross-currency sum); the full **sheet total** is a design target (unavailable).
5. **Status** — a pill (“Awaiting review” / “In progress”). Derived from the manager's complete set; never a sheet-wide or Senior claim (`FR-006-018/019`).
6. **Submitted** — a submission date is a design target (unavailable; Phase 1 renders “Date unavailable”); shown as such with a footnote.
7. **Chevron** — disclosure control with `aria-expanded` and a descriptive label (“Expand quick review for <title>”).

### 2.2 Expanded header (top of §3 panel)

Repeats identity with more emphasis and adds the review scope:

- Eyebrow **“Quick review”**, then **sheet title** + monospace reference (bdi/LTR-isolated).
- Meta line: **Purchased by <name>** (design target), an **in-scope chip** naming the cost centre the manager is deciding as (`Cost Center North`, from session scope), and the **submitted** reference (design target, unavailable).
- A **Collapse** link returns to the collapsed list and preserves queue scroll/filter state.

### 2.3 Verdict for product owner

Two connected values (pending count, relevant amount by currency) plus one honest derived state (awaiting/in-progress) can be delivered today. Sheet title, purchaser, sheet total, and submitted date **must** be design-target unavailable states unless a backend capability (manager-scoped sheet summary/`GET /sheets/{id}` for managers) is separately authorized (§13.P4).

---

## 3. Expanded sheet quick-review interface

The expandable panel (`sheet__body`) is the manager's **sheet-scoped review surface**. It shows **the manager's relevant transactions in that sheet** — including those the manager has already decided and those returned awaiting purchaser correction — so the manager can see what remains against the sheet's progress, while **decision actions appear only on awaiting (undecided) cards** (§8). No transaction outside the manager's scope is listed, counted, or inferred (`FR-006-004`).

### 3.1 Panel composition, top to bottom

1. **Expanded header** (§2.2).
2. **Transaction card list** (§4), one card per relevant transaction, ordered by the current sort.
3. **Footer note** — `Sheet-wide decisions are not possible.` plus: *“This design keeps the decision gate transaction-by-transaction. A sheet leaves the queue only when every relevant transaction has a decision. Whole-sheet actions are intentionally unavailable.”* This is the honest restatement of `FR-006-005` and the stage rule context.

### 3.2 Returned and decided cards inside the panel

A decided card (approved / rejected / returned) is shown in its current state with no decision actions:

- **Approved by you** — green check treatment, with the decision meta (“25 Oct · you — with note”).
- **Returned** — purple treatment showing the return explanation that went to the purchaser (“Returned · missing sponsor approval (RF-12). Resubmit with sponsor memo…”). A returned transaction is presented as **unresolved until the correction workflow completes** (`FR-006-021`), never as advanced.
- A rejected transaction is never labelled as senior-ready or “advanced” (`FR-006-020`).

The panel footer explains that the sheet stays in the queue until every relevant transaction has a decision (matching the Manus state A/B/C intent, framed per-manager).

### 3.3 Amount and currency honesty

Counts and per-currency sums are computed **after** de-duplication, over the **proven-complete** set only (`FR-006-012/013`). Multiple currencies are shown separately; no cross-currency total exists (`FR-006-014`).

### 3.4 Completeness / unavailable states

- If the response cannot be proven complete, the sheet grouping is preceded by the honest **“Sheet grouping unavailable”** state and a working link to the preserved transaction list (`FR-006-013`, §12).
- Sheet-wide Cost Center completion and Senior Review eligibility are **never** rendered as a badge here; the panel's footer and the focus banner (§10.3) explain that only per-manager assignments are discounted and that sheet-wide/senior status cannot be shown until a backend capability exists (`FR-006-018/022/023`).

### 3.5 Queue chrome shared with Phase 1

Search (by sheet reference / cost centre), filter tabs, and sort operate **locally over the loaded set** with no per-keystroke network call. The tabs in the proposal are **All sheets / Awaiting decision / In progress**; their counts reflect the proven-complete set. No `Done` tab exists — decision-history counts are unavailable and are therefore **not shown at all** (final decision #2, §13.P6).

### 3.6 No nested dialogs, one layer at a time

Preview, full details, and confirmation each render as a single overlay above the panel; the panel stays mounted behind them. Closing a layer returns focus to the originating control and leaves the panel in place (`FR-006-009` C-07 single-layer rule). On mobile, overlay layers become full-screen one at a time (§10).

---

## 4. Compact transaction cards

Each relevant transaction inside the expanded panel renders as a **compact card** (mockup `tx-card`), so a full sheet fits on screen without losing the queue context.

### 4.1 Card anatomy, top to bottom

1. **Icon + title block** — a small type icon (receipt / card / sheet glyph) beside the transaction title, a one- or two-line description, and a **meta line**: transaction id (monospace, LTR-isolated) · merchant · paid-on · payment method · category.
2. **Side column** — the amount with its currency immediately above a **status pill** (Awaiting review / In progress / Approved / Rejected / Returned).
3. **Attachments row** — a labelled “Attachments” line with a count, followed by up to two thumbnails and a **+N more** tile when the transaction carries more (§5).
4. **Action bar (undecided only)** — `Reject` (danger) · `Return` (secondary) · spacer · `Full details` (link) · `Approve` (primary). The actions wrap into a 2-column grid on narrow screens with Approve spanning full width.
5. **Decided note (decided cards only)** — replaces the action bar; see §4.2.

### 4.2 Decided states inside the panel

- **Approved** — green check line, e.g. `Approved by you · 25 Oct 2026 · you — with note`. The note is shown only when one exists; approvals never require one.
- **Returned** — purple treatment with a route icon and the exact return explanation that was sent to the purchaser, e.g. `Returned · missing sponsor approval (RF-12). Resubmit with sponsor memo, then it returns to your queue.` A returned card is **unresolved** until the correction workflow completes (`FR-006-021`) — it is never presented as advanced or as done.
- **Rejected** — shown as rejected with no senior-ready or “advanced” claim (`FR-006-020`).

### 4.3 Decided cards in the transaction total and scope

Decided cards remain counted in the sheet's de-duplicated scope (§3.4), so the manager can see progress, but they carry no decision actions. Only **awaiting** cards are actionable, preserving the transaction-by-transaction gate (`FR-006-005`, 001 `FR-033`).

### 4.4 Field honesty on the card

The transaction id, amount, currency, status and the cost-centre scope are **connected** values. Merchant, paid-on, payment method and category are **backend-required design targets** — they come from the richer transaction record, not the queue summary, and must be prepared as unavailable/placeholder until proven (§12). No value is invented to fill a card.

---

## 5. Supporting-document thumbnails

### 5.1 Thumbnail set

Each transaction renders its **first two** attachments as real thumbnails (rendered document art for images/PDFs; a type glyph icon for non-previwable kinds such as PPTX/DOCX/XLSX) plus a **`+N more`** tile when more than two exist. The mockup demonstrates this with a three-attachment receipt card.

### 5.2 Interaction

- Each thumbnail is a button whose accessible name is `<file name> — Opens document preview`; it opens the preview overlay for that document (§6), passing the sheet id, transaction id and document id so the overlay can restore exactly that document.
- The `+N more` tile opens the preview overlay at the **first** attachment of that transaction, where the user navigates forward.
- Thumbnails are only ever affordances into the preview overlay or, for non-inline-safe types, into the download/unsupported path — never inline renderers of untrusted content.

### 5.3 Honesty

Thumbnails render only documents that are part of the transaction's returned evidence set. The set and its media types come from the backend; a thumbnail never fabricates a page or a preview for a type the preview policy forbids (§6.4). Metadata (extension, size) shown beside a doc is presented as provided, never guessed.

---

## 6. Document preview overlay

The preview overlay (`doc`) is a single centered modal above the panel; the queue and panel stay mounted behind it. It reuses the approved evidence-preview rules from 001 `FR-060` and 005 `FR-005-042` rather than inventing a new policy.

### 6.1 Header

- Eyebrow **“Document preview”**, the **file name** as the accessible dialog name (per 005 `FR-005-042`), and a meta line of transaction id · transaction title · sheet reference (LTR-isolated).
- A **Close** button; the scrim also closes.

### 6.2 Body — inline-safe types

For inline-safe media (`application/pdf`, PNG, JPEG, GIF, WebP, BMP, `text/plain`, as closed in 005 `FR-005-042`) the modal renders the document content inline. `text/plain` renders as text, never as markup — no `v-html`/`innerHTML`.

### 6.3 Non-inline-safe fallback

For non-inline-safe formats the modal shows a **file-fallback** panel — type icon, file name, download meta, and the honest message *“Inline preview is not available for this file type. This format opens outside the browser (e.g. PPTX, DOCX, XLSX). Download the file to inspect it…”*. The **Download** action remains available (attachment disposition); no broken preview is shown and no untrusted markup is rendered (`image/svg+xml`, HTML download-only).

### 6.4 Navigation and actions

- **Previous / Next** step through the transaction's documents with a `1 / N` counter; the buttons disable at the ends.
- **Download** requests the file; **Close** returns focus to the originating thumbnail/control with the panel intact.
- Object URLs, if used for preview, are revoked on close/unmount (005 `FR-005-042`); preview must never trigger a download or an upload on its own.
- On mobile the preview becomes a full-screen layer (§10).

---

## 7. Full transaction details (read-only drawer)

`Full details` opens a **right-side drawer** (mockup `overlay--drawer` / `drawer`) over the panel so the manager can inspect a transaction to audit level **and decide it** without leaving the queue context. The drawer's **fields are read-only**; the decision actions (§7.6) are the drawer's only writes, and they use the same confirmation and validation as the inline quick review (§8).

### 7.1 Header

- Eyebrow **“Full details”**, the transaction title, and a meta line of transaction id · sheet title · sheet reference (LTR-isolated), with a **Close** button.
- A persistent two-tab nav: **Description** and **History** (the mockup defaults to Description; History is a separate panel).

### 7.2 Body — Description panel

- **Hero amount** — the amount with currency, the caption `Amount · <paid on>`, and the status pill plus cost-centre scope on the side.
- **Detail grid** — labelled fields: Sheet (reference), Purchased by (name · role), Merchant, Paid on, Payment method, Category, Cost center, Status. Fields render the returned values; any unproven field shows its unavailable state rather than a fabrication (§12).
- **Description** — the transaction's description text.
- **Attachments** — a list of documents, each with thumbnail/type art, name, and ext · size meta, and tappable to open the preview overlay for that document (one overlay at a time).

### 7.3 Body — History panel

A vertical timeline of the transaction's revision/status events (submission by purchaser, routing to cost centre, decision by the manager with its note, correction return, and so on), each with a title and timestamp. The history reflects backend-provided events only; no event is invented.

### 7.4 Drawer behaviour

- In LTR the drawer slides from the **right**; in RTL from the **left** (mirrored shadow/position, mockup `[dir=rtl]` rules).
- Scrim closes it; Close button returns focus to the originating card control. The panel and queue remain mounted.
- On mobile the drawer becomes a full-screen sheet (§10).

### 7.5 Decision actions in the drawer

For an **undecided** (awaiting) transaction the drawer footer shows a **`Decide this transaction`** label and three buttons — `Reject` (danger) · `Return` (secondary) · `Approve` (primary) — plus `Close`. For an already-decided transaction the actions are omitted. Choosing a decision replaces the drawer with the same confirmation modal as the inline quick review (§8), so both entry points share:

- the identical confirmation copy, warning, and note field;
- the same validation — **Reject and Return require an explanation; Approve never does** (`FR-033`);
- the same submit/completion behaviour — on success the card re-renders in its decided state and the **sheet panel stays mounted** (the drawer closes back to the panel); no duplicate post is possible (first-write-wins, `FR-085`; submitting disables all controls).

This single decision workflow means the card and the drawer can never drift into inconsistent states: both read the same transaction record and both post the same single-transaction decision.

### 7.6 Operational fallback stays reachable

The drawer is inspect-and-return within the queue. The existing `review-detail?stage=cost-center` route (001 workflow, `FR-006-017`) remains reachable and unchanged — the proposed surface augments it, it does not remove it.

---

## 8. Approve / Reject / Return-for-correction interactions

Every undecided card offers exactly three decisions — **Approve**, **Reject**, **Return for correction** — each decided **one transaction at a time** (`FR-006-005`, 001 `FR-033`). There is no sheet-level decision and no bulk decision, matching the panel footer (§3.1). The same three actions appear in the full-details drawer (§7.5); **both the card and the drawer are entry points into the same single-transaction confirmation workflow** — same copy, same validation, same result.

### 8.1 Confirmation modal

Choosing a decision opens a single confirmation modal (`modal--decision`) above the panel with:

- **Header** — eyebrow `Quick review · <sheet ref>`, title **“Approve transaction?”** (Reject: **“Reject — explanation required”**), and the transaction id · title.
- **Decision summary** — the transaction title, a meta line of sheet reference · paid-on, and the amount.
- **Warning** — `Approving posts this decision immediately. Make sure the amounts and proof on the receipt match what you can see in the preview.` (reject variant: `Rejecting is recorded immediately. Explain what is wrong so the purchaser can correct it — no partial or sheet-wide rejection is possible.`)
- **Note field** — `Optional note to the purchaser` for Approve (approvals never require a note); **required** for Reject and for Return (see §8.3).
- **Footer** — `Posts immediately · applies to this transaction only`, plus **Cancel** and the confirm button (**Confirm approve** / **Reject transaction** / Return variant).

### 8.2 Explanation rule (authoritative)

Per 001 `FR-033`, **REJECT and RETURN require an explanation**; Approve never requires one. This matches the existing `DecisionForm.vue` (`requiresExplanation` = REJECT || RETURN, 500-char maximum) — the proposal reuses that semantic rather than changing it. Under the owner's refinement #2 the mockup **now** uses distinct Return copy and styling (`Return for correction`, purple confirm + warning) and enforces an explanation for both Reject and Return via the same modal guard (§8.4). See §13.P3 for the recorded correction.

### 8.3 Return-for-correction specifically

Return is a decision like the others: it posts one explanation per returned transaction to the purchaser and the history, and the transaction is then presented as unresolved until the correction workflow completes (`FR-006-021`). It is never a sheet-level or partial-sheet action.

### 8.4 Reject-without-note guard

If the manager confirms a Reject while the explanation is empty, the modal re-renders in the **error state** (`error`): an inline `Explanation required · Add an explanation before rejecting.` message with the textarea flagged `aria-invalid`; the decision is **not** posted. The same guard applies to Return (via §8.2).

### 8.5 Submit and completion

- On confirm the modal enters the **submitting** state: the confirm control shows a spinner and “Recording decision…” and all its controls disable; the scrim no longer closes (first-write-wins, 001 `FR-085`).
- On success the modal closes, the card re-renders in its decided state (§4.2), a **toast** confirms (`TX-10842 · Approved · Decisions stay visible in the flow…`), and the sheet remains in the queue until every relevant transaction is decided.
- A failed decision keeps the data and exposes the error; it does not unmount the loaded panel (005 `FR-005-044` loading isolation applies to the mutations).

---

## 9. Desktop layout

### 9.1 Shell

Standard Nextcloud app chrome: header (brand, breadcrumb, apps/avatar), a left **sidebar** with primary navigation (`Approvals` active, badge 3), and a content column. A **page head** carries the cost-centre eyebrow, the page title **“Management queue”**, a subtitle, and a `Cost center manager` role badge.

### 9.2 Scope banner and summary stats

- **Scope banner** — route icon, *“You are deciding as Cost Center North”*, *“Each transaction below is decided on its own. This workspace never approves, returns or rejects a whole sheet at once.”* and a `Review policy` link.
- **Summary grid** (4 across → 2 across below 1120 px) — only the **real connected** stats: `Sheets awaiting decisions` and `Transactions pending decision`. Unavailable statistics (`Decisions this week`, `Load per cost center`) and unsupported history counts (`Done`) are **absent** from the UI entirely — no disabled cards, no illustrative values (final decision #2, §13.P6).

### 9.3 Queue card, toolbar and list

- **Queue card** — title **“Relevant transactions”**, the Manus-focused description, and a count (e.g. `3 sheets in queue · N transactions to decide`).
- A short **availability note** states that sheet title/purchaser/totals are design-target fields not yet delivered by the API.
- **Toolbar** — a search field (search sheet, purchaser or ID), filter tabs (`All sheets` / `Awaiting decision` / `In progress`), and a sort control (Newest submitted first / Amount high→low / low→high). No `Done` tab is shown (final decision #2).
- **List head and rows** share the same seven-column grid: Sheet · Purchaser · Txs · Total (numeric) · Status · Submitted · Chevron. At ≤1120 px the grid narrows; the mono sheet reference and amounts keep LTR isolation (§11).

### 9.4 Expanded panel and overlays on desktop

- Expanded sheet panel: header row (§2.2) with a **Collapse** link, the transaction card list, and the footer note. The cards' action bars sit right-aligned in a row.
- Overlays are centered modals (**preview** up to 920 px, **decision** up to 560 px) and the **drawer** (up to 780 px) anchored to the reading edge (§7.4). Exactly one overlay at a time; the panel stays mounted behind it (§3.6).

---

## 10. Mobile layout

Below ~980 px the app reflows to a phone-like column; the mockup uses container queries driven by the narrow `430 px` review frame.

### 10.1 Reflow

- Sidebar and column headers hidden; the sheet list columns collapse to a **two-region card**: identity + chevron on top, then the `Txs` and `Total` stats as a 2-column grid with status and submitted below. Each sheet reads as a tappable disclosure row (§2.1), so the manager still scans sheets without a horizontal table.
- Transaction cards stack: icon/title block, then amount + pill; the **action bar** becomes a 2-column grid with `Approve` spanning full width and the primary buttons at ≥44 px touch height. Attachments and the decided note flow underneath.
- **Summary stats** collapse to 2-across at ≤1120 px and keep a compact gap ≤460 px; the header trims to brand + avatar.

### 10.2 Overlays become full-screen, one at a time

On the narrow frame the scrimmed overlays fill the viewport: the modal takes full width with a 94 vh body, and the **drawer** becomes a full-screen sheet (full width / height with scrolled body). Preview navigation and the decision footer lay out as stacked full-width controls. This preserves the single-layer rule (§3.6): preview, details and confirmation are never stacked; closing one returns to the panel.

### 10.3 390 px floor and focus

The decision modal, preview, and drawer respect the approved 390 px floor and the C-07 single-dialog rule (005 `FR-005-040/042`); textarea, note, and confirm controls remain usable at the minimum width, and RTL/layout stays stable during loading (005 `FR-005-044`).

### 10.4 Mobile review efficiency (owner refinement #3)

To reduce scrolling before the reviewer reaches the transaction cards:

- **Density** — summary stat cards tighten (10 px padding, 15 px hint), the scope banner compacts (9 px padding, smaller gap), and the queue toolbar gaps shrink (8 px, reduced top margin); the expanded sheet body and card list sit directly under a compact panel header (embedded in `mockup.css` container queries).
- **Expand-to-panel** — expanding a sheet on mobile scrolls its quick-review panel into view (`scrollIntoView`, smooth) so the reviewer lands on the cards, not the top of the page; the behavior is skipped during capture so screenshots stay deterministic (embedded in `mockup.js`).
- **Context preservation** — closing a document preview or the full-details drawer returns to the still-mounted expanded panel in its prior state; opening an overlay never unmounts the queue or panel (§3.6).
- **Touch comfort** — decision actions at ≥44 px targets on their own grid with `Approve` full-width, tab hit-areas spread evenly, and the drawer/preview footers stacked as full-width touch controls (§10.2).

---

## 11. Persian RTL and English LTR behaviour

- The whole frame is rendered with the document direction of the active locale (`dir=rtl` for فارسی, `dir=ltr` for English) with mirrored layout: drawer anchors to the **left** in RTL with mirrored shadows (§7.4), previous/next arrows flip, and text alignment follows `text-align: start`.
- **Latin isolates**: transaction/sheet references, currency amounts, merchant/category Latin names, and the mockup's “Cost Center North” literal are wrapped where needed (`bdi`/`unicode-bidi: isolate` — mockup `[dir=rtl]` rules) so a Persian sentence never reorders the English tokens. Currency position stays consistent with the amount.
- Locale shaping: Persian numerals on counts/amounts, pp-token dates (`۲۸ اکتبر ۲۰۲۶`), and full translated copy for every label, pill, toast, and warning (mockup `COPY.fa`). The direction and shaping rules follow the prototype-field warnings in `DESIGN_ISSUES_TO_FIX.md` (calendar/direction pitfalls) rather than inheriting them.**
- Reduced-motion (`prefers-reduced-motion`) and forced-colors styles are provided; thumbnails/buttons keep visible focus in high-contrast mode.

---

## 12. Data honesty — connected vs backend-required design targets

Every field the mockup renders is classified here. **Connected** values are derived from the current queue/transaction responses and must be shown as real; **backend-required** values are design targets that the mockup renders as intended design but the current API does not provide — they must ship as unavailable/placeholder until a backend capability exists (`FR-006-011`, `FR-006-015`, and the 005 transaction contract). Nothing below is claimable today without §13 authorization.

| Surface | Field | Classification | Source / requirement |
|---|---|---|---|
| Queue row / panel | Sheet id + revision stamp | **Connected** | queue summary (`GET /queues/cost-center`) |
| Queue row / panel | Sheet **title** | **Backend-required** | `FR-006-015`; Phase 1 renders “Sheet title unavailable” |
| Queue row | Purchaser name + role | **Backend-required** | `FR-006-015`; “Purchaser unavailable” fallback |
| Queue row | `Txs` pending count | **Connected** | de-duplicated complete set (`FR-006-012/013`) |
| Queue row | Pending amount by currency | **Connected** | per-currency only, no cross-currency sum (`FR-006-014`) |
| Queue row | Full sheet total | **Backend-required** | `FR-006-015` |
| Queue row | Status pill (awaiting / in progress) | **Connected (derived)** | from manager's complete set; never sheet-wide/Senior (`FR-006-018/019`) |
| Queue row | Submitted date | **Backend-required** | “Date unavailable” |
| Tab counts | All / Awaiting / In progress | **Connected (derived, if set complete)** | `FR-006-013` |
| Tx card | Transaction id, amount, currency, status, cost centre | **Connected** | queue/transaction responses |
| Tx card / details | Merchant, paid-on, payment method, category | **Backend-required** | richer transaction record; placeholder until proven |
| Details drawer | Sheet reference, purchaser, status, description | **Connected where returned** | `GET /transactions/{id}` reads (`FR-005-040`) |
| Card + drawer | Approve / Reject / Return-for-correction actions | **Connected (real actions)** | single-transaction decision on awaiting items (`FR-033`; `FR-006-005`); available in both entry points, §7.5/§8 |
| Details / preview | Evidence set, media types, history events | **Connected** | returned evidence + revisions; none invented |
| Panel | Sheet-wide completion, Senior eligibility, whole-sheet actions | **Unavailable by policy** | `FR-006-005/018/021/022/023`; reduced to disabled/unavailable states |

Two connected values (pending count, relevant amount by currency) plus derived manager state (awaiting / in progress) are all that is shown in the summary/tabs — the only live values. Every other informative field in the mockup is a labelled design target or an unavailable state. **Unavailable statistics and unsupported history counts are not rendered at all** (final decision #2): there is no `Decisions this week` stat, no `Load per cost center` card, and no `Done` tab, so no inventable number can be mistaken for a capability.

---

## 13. Proposed changes to existing Spec Kit requirements

None of these are applied to any approved requirement. They are the explicit deltas between feature 006 / 001 / 005 and this proposal. On 2026-11-02 the owner **approved the architecture with these refinements conditionally**: P1/P2 (workspace form) are accepted in principle, and P3–P7 are the refinement deltas now **embedded in the design-only mockup** (§0.4). Recording the owner's decision on each remains the gate before any implementation tasking — this document is still a design deliverable, not a change to the specs.

### P1 — `FR-006-003`: adopt the inline quick review as the Phase 2 workspace form

- **Current:** FR-006-002/003 describe a separate sheet workspace destination reached from the Phase 1 queue.
- **Proposed:** The Sheet Review Workspace (`FR-006-026`) is delivered as the **inline quick review** specified here — the Phase 1 queue is retained and the sheet expands in place rather than being replaced by a separate page for the common review path. The preserved `review-detail` route stays reachable for deep links (`FR-006-017`).
- **Why:** iteration shows the decision gate is transaction-by-transaction (`FR-006-005`, 001 `FR-033`) and sheet-level; keeping the queue context removes redundant navigation and keeps the queue chrome honest. This is a routing/IA refinement, not a scope reduction.

### P2 — `FR-006-002`: hierarchy is queue → sheet → transaction card, never a sheet-only page

- **Current:** FR-006-002 names the sheet as its own review surface.
- **Proposed:** The management hierarchy is **Manager Queue → Sheet (expand) → Transaction Card → [Preview | Full details | Decision]** (see §1). The queue is the persistent shell; the sheet quick-review panel is a disclosure layer, not a destination.
- **Why:** consistent with P1; ensures the operational queue never disappears behind the proposed surface.

### P3 — `FR-006-006` + 001 `FR-033` + mockup Return-copy correction

- **Current:** `DecisionForm.vue` already enforces `requiresExplanation` = REJECT || RETURN and 001 `FR-033` requires explanation for REJECT and RETURN; the **mockup** currently enforces the note for Reject only and reuses Approve's styling/copy (`Confirm approve`) and its “no note required” behaviour for the Return confirm.
- **Proposed:** (a) The shipped Return confirmation uses **distinct copy** (e.g. “Return transaction” / “Return for correction”), hazard-appropriate styling, and a **required** explanation field; Approve alone keeps no-note-optional. (b) A spec/acceptance note makes explicit that both Reject **and** Return require an explanation, one transaction per decision, no bulk — matching `DecisionForm.vue` and `FR-033`.
- **Why:** the mockup's Return confirm is a visual defect against the approved semantic; the proposal must not carry it into implementation.

### P4 — `FR-006-011`/`FR-006-015`: authorize a manager-scoped backend capability for the design-target fields

- **Current:** the queue summary provides sheet id, transaction-level amounts/currencies, cost-centre scope and revision stamps; sheet title, purchaser, sheet total, submitted date, and the merchant/paid-on/payment-method/category transaction fields are not available to the manager surface.
- **Proposed:** a separately authorized backend capability — a manager-scoped sheet summary endpoint (`GET /sheets/{id}` for managers) — delivers sheet title, purchaser display name/role, sheet total (per currency), submitted date, and the richer transaction record fields, so the §12 backend-required entries become connected. Until it exists, the surface renders the unavailable/placeholder states defined in §12.
- **Why:** this is the only way the mockup's intended design can be realized without fabricating data (`FR-006-011/015`).

### P5 — New requirement: preview overlay reuses the approved evidence-preview policy

- **Current:** feature 006 has no explicit evidence-preview requirement for the manager quick review; 001 `FR-060` and 005 `FR-005-042` govern the purchaser evidence preview (inline-safe closed set: `application/pdf`, PNG, JPEG, GIF, WebP, BMP, `text/plain`; SVG/HTML/non-inline-safe download-only; text never rendered as markup; accessible dialog name = file name; Escape close; object-URL revocation).
- **Proposed:** add to feature 006 that document preview in the quick review **must reuse 001 `FR-060` + 005 `FR-005-042` verbatim** — same inline-safe policy, same download fallback and fallback copy, same accessibility and focus rules, same single-dialog constraint (§6). No new or looser policy is introduced.

### P6 — `FR-006-016`: unavailable statistics and history counts are removed from the UI entirely

- **Current:** the original mockup rendered `Decisions this week` as a live stat and a `Done (47)` tab count without a manager decision-history capability.
- **Proposed (owner final decision #2):** both are **removed from the UI entirely** — no illustrative count, no disabled placeholder card, no `Done` tab. The summary grid shows only the two connected stats and the tabs only the three derived sets. Nothing implies a capability that does not exist.
- **Why:** direct application of `FR-006-015/016`; removal is simpler and more honest than disabled placeholders — the capability can return when a decision-history source is approved.

### P7 — `FR-006-012` decided-items scope + mockup citation slip

- **Current:** (a) the mockup's availability note cites `FR-006-002/FR-006-003` as the source of the design-target fields; the governing rule for field availability is `FR-006-011`/`FR-006-015`; (b) the session scope questions whether decided items belong inside the sheet's counts/scope.
- **Proposed:** (a) any shipped copy and future mockup iterations cite **`FR-006-011`/`FR-006-015`** for the unavailable-field rule, not FR-006-002/003; (b) specify that the sheet panel's scope and per-currency totals are computed over the manager-scoped set **including the manager's decided items** (so progress is visible) while decision actions appear only on awaiting cards — an application of `FR-006-012/013`'s proven-complete-set rule, not a redefinition of it.
- **Why:** corrects an internal citation defect and binds the decided-items-in-panel scope to the existing completeness rule rather than inventing a new aggregation.

---

## 14. Verification and evidence

This document was produced from text sources only — no image-reading tool was used and no PNG was opened.

- **Layout, copy, and state detail** (§2–§11) were read from the design-only mockup source: `mockups/index.html` (harness/state list), `mockups/mockup.js` (full EN/FA copy table, data model, all renderers — collapsed row, sheet body, tx card, doc thumbs, preview overlay, unsupported fallback, details drawer, confirm/error/submitting/completed states, toast), and `mockups/mockup.css` (overlay, drawer, RTL fixes, container-query breakpoints at 1120/980/700/460 px, reduced-motion and forced-colors).
- **Requirement facts** were read from the authoritative contracts: `specs/006-management-review/spec.md` (FR-006-001…026, quoted verbatim where cited), `specs/001-pettycash-v1-core/spec.md` (FR-033 explanation rule, FR-060 preview authority, FR-085 first-write-wins), and `specs/005-purchaser-ui-redesign/spec.md` (FR-005-040 transaction detail, FR-005-042 evidence preview, FR-005-044 loading isolation).
- **Frontend reuse semantics** were taken from `src/components/review/DecisionForm.vue` (`requiresExplanation` = REJECT || RETURN).
- **Known prototype-field warnings** were drawn from `docs/frontend-redesign/handoff/design-notes/DESIGN_ISSUES_TO_FIX.md` and `DECISION_ADDENDUM_DRAFT.md` (calendar/direction pitfalls; no Vendor/Notes/custom Category fields).
- **Limitation:** visual-pixel claims (exact spacing, shadows, breaks) are described from CSS rather than captured screenshots, and should be confirmed against the mockup's generated captures if the owner reviews them. Behavioural claims are exact from `mockup.js`.

Code intelligence for this design pass: **OCI = not applicable (design-only, no code discovery needed); CGC = not applicable; Serena = `DecisionForm.vue` semantics reused from the known path; source = mockup files under `./mockups/` plus the spec files cited.** No backend, production frontend, or test file was modified.

---

## 15. References

- Feature 006 artifacts: `specs/006-management-review/{spec,plan,tasks}.md`; Phase 1 report `docs/reports/006-management-review-phase-1.md`.
- 001 core: `specs/001-pettycash-v1-core/spec.md` — FR-033 (explanation rule), FR-060 (evidence preview), FR-085 (first-write-wins).
- 005 purchaser redesign: `specs/005-purchaser-ui-redesign/spec.md` — FR-005-040, FR-005-042, FR-005-044.
- Design mockup (this proposal's visual embodiment): `docs/frontend-redesign/proposals/006-management-review-quick-review/mockups/{index.html,mockup.js,mockup.css}` and its generated captures under `mockups/screenshots/`.
- Approved Manus management references (visual, **not opened in this pass**), under `docs/frontend-redesign/references/managment/cost-center/`:
  - `01-queue-desktop-en.png`, `01-queue-mobile-en.png`
  - `02-workspace-state-a-pending-desktop-en.png`, `02-workspace-state-a-pending-mobile-en.png`
  - `02-workspace-state-b-manager-done-stage-pending-desktop-en.png`
  - `02-workspace-state-c-stage-complete-desktop-en.png`
  - `alternates/02-workspace-initial-desktop-en.png`, `alternates/02-workspace-initial-mobile-en.png`
  - index: `README.md` (filenames/ordering above).
- Handoff/design records: `docs/frontend-redesign/handoff/design-notes/DESIGN_ISSUES_TO_FIX.md`, `DECISION_ADDENDUM_DRAFT.md`.
- Reuse target: `src/components/review/DecisionForm.vue` (frontend, unchanged).
- Constitution: `.specify/memory/constitution.md` (specification-first, honesty, visual gate).

---

## 16. Open product-owner decisions

**Approved on 2026-11-02:** the inline quick-review architecture (D1 — workspace form) and the explanation rule (D2 — Reject **and** Return require an explanation). **Final design decisions recorded (§0.5):** (1) decision actions live in the full-details drawer footer only — not duplicated in the body; (2) unavailable statistics and unsupported history counts are removed from the UI entirely; (3) the distinct purple Return-for-correction confirmation is kept. All are embedded in the mockup.

**Still open — the immediate agenda is the owner's final *visual* approval of the revised designs and screenshots** (Desktop/English, Desktop/Persian, Mobile/English, Mobile/Persian; each covering expanded sheet + cards, full details with decision controls in the footer, document preview, return confirmation, decision completion, and mobile entry into quick review):

1. **D3 — Backend capability (P4):** authorise a manager-scoped sheet-summary/transaction-record capability (title, purchaser, sheet total, submitted date, merchant/paid/method/category). Per the owner's instructions this is **not yet requested** — it stays open until after visual approval.
2. **D4 — Preview policy (P5):** confirm the quick review reuses 001 `FR-060` + 005 `FR-005-042` verbatim.
3. **D6 — Citation fix (P7):** confirm shipped copy cites `FR-006-011`/`FR-006-015` for unavailable fields, and the decided-in-panel scope rule.
4. **D0 — Ongoing policy:** sheet-wide decisions, Senior Review eligibility, and whole-sheet completion remain unavailable by approved policy (FR-006-005/018/022/023) and are not part of this proposal; with decision #2 there is no longer any surface that could imply them.

**Remaining design questions for the visual review:** none from the previous note — the three questions there are superseded by the recorded final decisions (§0.5). Any new observations from the screenshots will be folded into a follow-up.

Until the owner records final visual approval and the D3/D4/D6/D0 decisions, this remains a design-only proposal; no spec, contract, backend, production frontend, test, or Git state is changed. The implementation waits at the gate.

