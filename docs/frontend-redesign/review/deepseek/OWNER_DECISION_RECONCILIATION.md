# OWNER_DECISION_RECONCILIATION — owner clarifications applied to the audit package

**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe` (unchanged; no commit was made by this pass).
**Scope of this pass:** reconcile the owner-provided clarifications (D-01, D-02, D-05, D-06, D-09, D-11) against the existing audit package, re-examine the remaining decisions against repository evidence, validate the 16 + 6 task cards, and prepare the delivery queue. **No new audit was run.**
**Status of this document:** `PROPOSED`. It records *planning* resolutions. It does not approve a design, does not authorize an implementation, and does not authorize any backend change.

---

## 1. Method and what was re-verified in this pass

Nothing below is inferred from the clarifications alone. Each clarification was checked against the contract, the implementation, or the test tooling, and only then applied. Facts verified for this pass:

| # | Fact | Evidence |
|---|---|---|
| V1 | The UI is **key-based**, not source-string based: `import { t } from '@nextcloud/l10n'` and calls of the form `t('pettycash', 'sheets.title')`, so an untranslated key renders as the key itself | `src/views/sheets/SheetListView.vue:16`, and the same pattern across the views |
| V2 | Only a **Persian** catalog exists: `l10n/fa.js` + `l10n/fa.json`, 356 keys; the completeness gate is fa-only ("Persian catalog completeness (T119): every key the UI can ask for exists") | `l10n/`, `tests/Frontend/l10nCatalog.spec.ts:1-3` |
| V3 | `src/l10n.ts` holds no English default table — only `FA_LOCALE`, `DYNAMIC_KEY_FAMILIES`, `expandFamily`, `stageKey`, `statusKey`, `decisionKey` | `src/l10n.ts:16-83` |
| V4 | `PATCH /sheets/{sheetId}` body = `allOf[SheetCreate, ExpectedVersion]`; `SheetCreate` requires **only** `title`; the schema text states `accountId` "cannot be required — FR-020 and a required `accountId` cannot both hold" | `specs/001-pettycash-v1-core/contracts/ocs-api.yaml:403-434`, `:1396-1410` |
| V5 | The controller nevertheless **refuses** an absent or blank `accountId` with a field-level 422 | `lib/Controller/SheetController.php:147-158` |
| V6 | Every transaction save creates a **new revision** with `number + 1`, so `activeRevision.createdAt` is a last-save timestamp | `lib/Application/Expense/TransactionService.php:330-332` |
| V7 | The sheet-summary query already selects `r.amount_minor`, `d.stage`, `d.decision` per active revision and reduces them into manager/senior/finance slots — per-stage decision data already exists | `lib/Infrastructure/Persistence/Expense/SheetSummaryQuery.php:173-181`, `:210-215` |
| V8 | The correction operation exists and is explicit: "Create a correction draft from the active returned revision", `201 → Revision`, `409` on `NotAwaitingCorrection`/stale version | `specs/001-pettycash-v1-core/contracts/ocs-api.yaml:594-617` |
| V9 | Repository governance **requires explicit separate approval** for backend/contract change: "API contract, financial-rule, authorization, or backend behavior changes … Out of scope without separate approval"; "Backend work or feature expansion must be raised and approved as a separate change request"; the BE-CR definition itself: "Owner explicitly approves the exact change before implementation" | `docs/frontend-redesign/CHARTER.md:84-86`, `DECISIONS.md` D-005, `handoff/governance/manus-handoff-snapshot/FAST_TRACK_ADDENDUM.md:42`, `…/FRONTEND_TASK_BACKLOG.md:26` |
| V10 | The mobile reference set contains **9 images**, not 7 or 8; the Unsaved Changes dialog reference exists | `docs/frontend-redesign/references/mobile/`, `references/dialogs/unsaved-changes-en-desktop-v1.png` |
| V11 | Browsers: `chromium-desktop`, `firefox-desktop`, `edge-desktop`, `chromium-mobile` (Pixel 7 = 412px), with an explicit note that a genuine 390px assertion must set its own viewport | `playwright.config.ts:25-34` |
| V12 | Frontend gate inventory and the E2E environment hook | `package.json` scripts; `pretest:e2e` = `scripts/dev/opcache-settle` |

**Search performed for previously approved backend changes (Phase 3 requirement):** the whole `docs/frontend-redesign/**` tree including governance and handoff snapshots was searched for backend/API/contract change language and for approval language. **Result: no document records approval of any backend change matching any `BE-CR-*`.** Approval is required by V9 and has not been granted. No approval was inferred from naming similarity or from the audit's own existence.

---

## 2. Owner clarifications applied

### D-01 — Unsaved Changes design approved; mobile purchaser screens are the current visual references

- **Resolution applied:** the Unsaved Changes dialog is treated as **approved design input**, and the images under `references/mobile/` are treated as the current mobile visual references. GAP-054's framing changes from "design signed off, implementation absent" to the same defect with an *approved* reference available at `references/dialogs/unsaved-changes-en-desktop-v1.png`.
- **Evidence:** the dialog reference file exists (V10); the previously conflicting `references/DESIGN_REFERENCES.md` registry is left as-is and is no longer treated as blocking.
- **Task effect:** `UI-PUR-012` is no longer gated on a design question → `READY_FOR_OWNER_APPROVAL`. `UI-PUR-005`'s mobile acceptance criteria have a concrete reference set (9 images, all listed in `BLOCKED_ITEMS.md` §4 for traceability).
- **Not decided by this:** the *implementation* is still absent and still a defect; approval of a design is not a statement that the code is correct.

### D-02 — English LTR is the current sequence; Persian RTL stays in product scope

- **Resolution applied:** the direction/formatting **mechanism** is required now and must not assume RTL; the bilingual product scope is preserved. `UI-PUR-001` proceeds on the mechanism only.
- **Verified consequence (new):** because the UI is key-based (V1) and no `en` catalog exists (V2, V3), an English-language user today sees raw keys such as `sheets.title`. **English LTR is therefore not reviewable in the running app until an English catalog exists.** This was not identified by the audit; it is recorded as `REC-01` (§4) and is an owner decision item, not something this pass may resolve.
- **Task effect:** `UI-PUR-001` → `READY_FOR_OWNER_APPROVAL` for the mechanism, with `REC-01` recorded as a dependent decision. No task is blocked by D-02 itself.
- **Explicitly not done:** no English catalog was authored, no partial catalog was proposed, and no requirement to use English in shipping builds was invented.

### D-05 — Sheet title and account are editable while the sheet is OPEN, subject to real permissions

- **Decision resolved:** the account is **not immutable**; the approved interaction is inline editing of the open sheet's metadata, not a separate Edit Sheet workflow.
- **Verified consequence (sharpened `BE-CR-001`):** the contract's own `accountId` description says the field cannot be required (V4), yet the controller refuses its absence (V5). So a **title-only PATCH** is *contractually correct but implementation-refused* — meaning even the least invasive workaround is a backend change, not a frontend one. The alternative remains to expose the sheet's `accountId` so the client can echo it back. Both options are backend changes; both stay blocked.
- **No contractually-correct frontend-only path exists:** the sheet's account is absent from every payload (`SheetPayloadFormatter.php:22-24`), and guessing `accounts[0]` is exactly GAP-011 (silent repointing). This analysis is recorded in `BLOCKED_ITEMS.md` §3.
- **Derived constraint (documented as derived, not owner-stated):** "subject to real permissions" is read as *ownership of the sheet and of the target account, with the account active*; the currency question (whether an account of a different currency may be adopted by a sheet that already stores a currency) is **not** answered by the clarification and must be answered before `BE-CR-001` is approved.
- **Task effect:** `UI-PUR-007` → `BLOCKED_BY_BACKEND` (previously `BLOCKED_OWNER_DECISION`). Its blocking reason changed, not its status class.

### D-06 — Workflow stage statistics must come from actual transaction decisions per stage

- **Decision resolved:** the stage statistics must be counts **and** amounts per stage (Approved, Rejected, Pending, Returned when applicable), computed from actual decisions; final finance totals are **not** a substitute. The audit's "counts or published figures?" question is therefore answered in favour of counts.
- **Verified consequence (de-risking `BE-CR-003`):** the required data already exists — `SheetSummaryQuery::rowsForSheet()` selects stage, decision and amount per active revision (V7). The delta is an aggregate plus a payload, not new persistence.
- **Semantic precision recorded as `REC-03` (§4):** the existing query covers **active** revisions only, so a Return that has since been superseded by a correction revision disappears from the set. The coherent reading (and the one consistent with FR-062's existing totals) is that stage statistics describe the sheet's current active revisions; this must be stated explicitly in the contract when `BE-CR-003` is approved.
- **Task effect:** `UI-PUR-014` → `BLOCKED_BY_BACKEND` (previously `BLOCKED_OWNER_DECISION`).

### D-09 — OPEN sheets always first; submitted sheets follow the requested First Submitted direction; must work across pagination

- **Decision resolved:** OPEN-first is a requirement and it must hold across pages, which makes it a server-ordering concern. The frontend cannot satisfy it by sorting the loaded page.
- **Verified consequence:** `GET /sheets` is paged server-side and the contract carries no ordering clause, so this needs `BE-CR-002` — unapproved → `UI-PUR-003` is `BLOCKED_BY_BACKEND`.
- **Residual sub-question (not resolved, and not resolvable from evidence):** the clarification does not say how the **OPEN group** is ordered among itself; an open sheet has no first-submission timestamp. This must be answered inside `BE-CR-002` before it is approved and before `UI-PUR-003` is implemented. It is recorded in `BLOCKED_ITEMS.md` §3.
- **Task effect:** `UI-PUR-003` → `BLOCKED_BY_BACKEND` (previously `BLOCKED_OWNER_DECISION`).

### D-11 — The History tab is part of the design; use existing transaction-level APIs

- **Decision resolved:** the History tab stays in the workspace design, and history continues to be assembled from the existing transaction-level audit/revision endpoints. No sheet-wide history endpoint is to be invented (this matches the audit's verified deliberate non-goal).
- **Verified consequence:** the tab is implementable with existing endpoints: the sheet detail supplies the transactions to iterate, and the per-transaction audit/revision pages are already paged. The UI must present it honestly as assembled per transaction, not as a sheet-wide feed.
- **Task effect:** `UI-PUR-006` → `READY_FOR_OWNER_APPROVAL`.

---

## 3. Remaining decisions re-examined against evidence

These were **not** covered by the owner clarifications. Each is resolved only where repository evidence is sufficient; otherwise it stays open and is marked so.

| # | Question | Disposition | Basis |
|---|---|---|---|
| D-03 | Does the list's date filter mean sheet-creation date or expense date? | **Partially resolved from evidence; the residual is a spec change nobody is authorized to make.** The implementation filters the sheet's creation date, and FR-064 requires each facet to resolve to exactly one source. The UI must therefore *name* the date it filters; today the labels are generic (`sheets.filter.from` / `sheets.filter.to`). Switching to expense-date semantics would be a new filter, needing a spec amendment. Task effect: small honesty criterion inside `UI-PUR-002` (`REC-06`), no task blocked. | `spec.md:1058` (FR-064), `src/views/sheets/SheetListView.vue:475,484` |
| D-04 | Does the purchaser list keep FR-062's stage-approved columns? | **Resolved conservatively from the specification.** FR-062 is contractual and says the figures MUST be shown "as separate columns (Cost Center Approved \| Senior Approved \| Finance Approved) alongside the latest effective outcome column". Removing them is a spec amendment, which this pass may not make. The redesign may re-group them visually, but each figure keeps its own label and its own cell. → `UI-PUR-002` unblocked; if the owner wants a strict six-column list, that requires an explicit FR-062 amendment. | `spec.md:1046-1048` (FR-062), `:1049-1057` (FR-063) |
| D-07 | Two published transaction statuses can never occur — remove or document? | **No action; non-blocking.** Removing published enum values is a contract change and is not authorized. The frontend must simply never render them; that requirement already lives in `UI-PUR-006`'s acceptance criteria. | contract enum vs. implementation |
| D-08 | Does the mobile reference set match "eight mobile images"? | **Resolved as moot.** The reference set is 9 images, listed in `BLOCKED_ITEMS.md` §4. D-01 makes all mobile screens the current references, so the count question no longer gates anything. | V10 |
| D-10 | The audit feed's `decision` field is the literal `'DECISION'` | **Resolved as a frontend-side handling detail; non-blocking.** The UI reads the entry's `after` payload rather than the literal type field. No backend change is proposed for it. | contract vs. `AuditEntry` usage |
| D-12 | Status vocabulary of the audit's own artefacts | **Superseded by the vocabulary mapping in §5.** All artefacts remain `PROPOSED`; nothing in this package is approved by this pass. | this document |

---

## 4. Reconciliation findings (`REC-*`) — new items found *while reconciling*, not new audit findings

These are **dependencies and diligence items**, not defects. They carry their own IDs so that the audit's `GAP-001…073` numbering stays untouched.

| ID | Finding | Why it matters | Disposition |
|---|---|---|---|
| `REC-01` | **No English catalog exists.** The UI is key-based (`t('pettycash', 'sheets.title')`), only `fa` ships, and `@nextcloud/l10n` renders an untranslated key as the key itself. English LTR therefore cannot be reviewed in a running build without an `en` catalog (~356 values to author). | D-02 prioritises English LTR. Every English-LTR visual acceptance criterion is unverifiable until this exists, and all new copy must keep shipping `fa` entries (the gate enforces it). | **Owner decision required** (sourcing: author `l10n/en.json`+`en.js`, or defer English review to the static references). Recorded in `BLOCKED_ITEMS.md` §4. Does **not** block `UI-PUR-001`'s mechanism. |
| `REC-02` | **`accountId` contract/implementation divergence.** `SheetCreate` requires only `title` and its text says `accountId` cannot be required; the controller refuses its absence. | Makes "title-only PATCH" contractually correct but implementation-refused, so the D-05 workaround is still a backend change. | Feeds `BE-CR-001` (blocked). Documented in `BLOCKED_ITEMS.md` §3. |
| `REC-03` | **Per-stage statistics semantics under corrections.** The per-stage data comes from *active* revisions, so a superseded Return vanishes; a corrected-and-resubmitted transaction re-enters review as Pending at Cost Center. | Without stating this in the contract, `BE-CR-003` could be implemented with a different meaning than the owner intends. | Must be stated in `BE-CR-003` before approval; not a blocker for any other task. |
| `REC-04` | **Canonical correction path resolved to the existing PATCH flow**, with the dead `POST /correction` client wrapper removed or repaired. The dedicated operation remains available but unused. | GAP-028's latent defect (a client wrapper that omits `expectedVersion` and has no caller) is removed with the smallest behavioural change; the PATCH flow is already pinned by passing tests, and the dedicated operation would add an extra initial revision to the history. | Applied to `UI-PUR-013` (frontend only). The alternative (adopting `POST /correction` as the entry point) is recorded for the owner in `BLOCKED_ITEMS.md` §4 but not adopted. |
| `REC-05` | **Ordering source for "most recently created and saved transaction" confirmed:** every save creates a new revision with `number + 1`, so `TransactionSummary.activeRevision.createdAt` is a real last-save timestamp. | Resolves `UI-PUR-009`'s previously open dependency; no backend change needed, and the task must not fall back to array order or expense date. | Applied to `UI-PUR-009` → `READY_FOR_OWNER_APPROVAL`. |
| `REC-06` | **Date-filter labels do not name the date they filter** (`sheets.filter.from` / `.to`). | Honesty of the interface under D-03's partial resolution; the filter is the sheet's creation date. | Small acceptance criterion inside `UI-PUR-002`. |

---

## 5. Task status changes (before → after)

**Vocabulary mapping (applies to all 22 cards):** the audit's `PROPOSED` becomes `READY_FOR_OWNER_APPROVAL`, which means *no blocker was found; the owner has not approved it*. It never means approved. Each card keeps its original text and carries an appended "Reconciliation update" block; the authoritative current status is `BUNNY_READY_QUEUE.md` §4 and `BLOCKED_ITEMS.md`.

### Frontend tasks (16)

| Task | Audit status | Current status | Reason |
|---|---|---|---|
| `UI-PUR-001` | PROPOSED | `READY_FOR_OWNER_APPROVAL` | D-02: the direction/formatting mechanism is required now; English catalog is `REC-01`, separate |
| `UI-PUR-002` | PROPOSED (D-04) | `READY_FOR_OWNER_APPROVAL` | D-04 resolved from FR-062: keep the stage figures as separate labelled cells; `REC-06` label fix added |
| `UI-PUR-003` | BLOCKED_OWNER_DECISION | `BLOCKED_BY_BACKEND` | D-09 resolved as OPEN-first across pages → `BE-CR-002` unapproved |
| `UI-PUR-004` | PROPOSED | `READY_FOR_OWNER_APPROVAL` | Unchanged; sequenced **before** `UI-PUR-005` because the overlay consumes it |
| `UI-PUR-005` | PROPOSED (D-01 evidence) | `READY_FOR_OWNER_APPROVAL` | D-01 approves the dialog and the mobile references; D-11 confirms the tabbed workspace |
| `UI-PUR-006` | PROPOSED (D-11) | `READY_FOR_OWNER_APPROVAL` | D-11 resolved: History tab in scope, existing per-transaction APIs |
| `UI-PUR-007` | BLOCKED_OWNER_DECISION (D-05) | `BLOCKED_BY_BACKEND` | D-05 resolved in favour of editable; no contractually-correct frontend-only path exists (`REC-02`) |
| `UI-PUR-008` | PROPOSED | `READY_FOR_OWNER_APPROVAL` | Unchanged |
| `UI-PUR-009` | PROPOSED | `READY_FOR_OWNER_APPROVAL` | `REC-05`: last-save ordering source confirmed, no backend change |
| `UI-PUR-010` | PROPOSED | `READY_FOR_OWNER_APPROVAL` | Unchanged |
| `UI-PUR-011` | PROPOSED | `READY_FOR_OWNER_APPROVAL` | Unchanged |
| `UI-PUR-012` | PROPOSED (D-01) | `READY_FOR_OWNER_APPROVAL` | D-01 resolved: dialog approved |
| `UI-PUR-013` | PROPOSED | `READY_FOR_OWNER_APPROVAL` | `REC-04`: canonical path resolved to PATCH; dead wrapper removed |
| `UI-PUR-014` | BLOCKED_OWNER_DECISION (D-06) | `BLOCKED_BY_BACKEND` | D-06 resolved as counts **and** amounts per stage → `BE-CR-003` unapproved |
| `UI-PUR-015` | PROPOSED | `READY_FOR_OWNER_APPROVAL` | Unchanged; runs after the surfaces exist |
| `UI-PUR-016` | BLOCKED_OWNER_DECISION | `READY_FOR_OWNER_APPROVAL` (evidence tier deferred) | Submit behaviour resolved conservatively (enabled + explicit blockers, server 422 as backstop); the evidence criterion waits on `BE-CR-004` and must be **advisory** until then |

**Totals: 13 `READY_FOR_OWNER_APPROVAL`, 3 `BLOCKED_BY_BACKEND`, 0 `BLOCKED_BY_DESIGN`, 0 `BLOCKED_BY_SPEC`, 0 `OPTIONAL_DEFERRED` (of 16).**

### Backend change requests (6) — all remain blocked

| BE-CR | Delta | Classification |
|---|---|---|
| `BE-CR-001` | Expose the sheet's `accountId` **or** make an absent `accountId` mean "unchanged" (both are backend changes); permission and currency rules to be stated | **Required by a frontend requirement** (D-05) |
| `BE-CR-002` | Server ordering: OPEN-first with a defined intra-OPEN order plus the First-Submitted direction across pages | **Required by a frontend requirement** (D-09) |
| `BE-CR-003` | Per-stage decision counts and amounts (data already selected by `rowsForSheet()`), with `REC-03`'s semantics stated | **Required by a frontend requirement** (D-06) |
| `BE-CR-004` | Evidence policy at submit/resubmit (FR-023 never enforced; resubmit inverted) | **Spec-compliance change, outside the redesign's scope** unless the owner brings it in |
| `BE-CR-005` | Live sheet-status derivation (currently only at finance settlement) | **Spec-compliance change**; not redesign-blocking; the frontend must **not** re-derive the domain rule |
| `BE-CR-006` | Guard against decisions while a transaction awaits purchaser correction | **Integrity guard**, lowest priority; depends on the owner's answer about whether that decision is ever legitimate |

Governance compels this table's conclusion (V9): the three `required-by-a-frontend-requirement` items are the only ones that even qualify for consideration under the fast-track rule ("permit proposed backend changes only where a concrete frontend requirement cannot be met otherwise"), and all six still need explicit separate approval.

---

## 6. What was **not** changed

- No `GAP-*` finding was renumbered, softened, or deleted; `AUDIT_REPORT.md` and `GAP_MATRIX.md` remain the audit's record.
- No decision was rewritten; D-01…D-12 keep their identities, and the ones the owner answered keep the owner's wording paraphrased in §2 with a pointer to the effect.
- No backend change was implemented, described as approved, or moved into a frontend card.
- No design was declared approved beyond what the owner's clarifications state; the `DESIGN_REFERENCES.md` conflict remains reported rather than resolved.
- No file outside `docs/frontend-redesign/review/deepseek/` was written; `src/`, `lib/`, `specs/`, `tests/`, `appinfo/`, `l10n/` and the Manus prototype are untouched.

---

## 7. What only the owner can do next

1. Answer `REC-01` (English catalog sourcing) — it gates every English-LTR visual acceptance.
2. Approve or reject each of the 13 ready task cards individually; approving a queue or an execution plan is not approving a card.
3. Decide whether the three spec-compliance changes (`BE-CR-004/005/006`) are in scope at all, separately from whether they are approved.
4. Answer the sub-questions that block `BE-CR-001/002/003` (currency rule and account permissions; intra-OPEN ordering; `REC-03` semantics).
5. Decide the two recorded alternatives if desired: adopting `POST /transactions/{id}/correction` as the entry point instead of the PATCH flow (`REC-04`), and any FR-062 amendment that would allow a strict six-column list (D-04).

*Nothing in this document authorises implementation. The next actor is Bunny, and Bunny may only start from cards the owner has explicitly approved.*
