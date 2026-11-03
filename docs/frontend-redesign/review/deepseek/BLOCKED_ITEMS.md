# BLOCKED_ITEMS — everything that cannot proceed, and exactly why

**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe` · **Status of this document:** `PROPOSED`
**Rule this document enforces:** a blocked item stays blocked until the *stated* unblock condition is met. None of these items may be worked around by editing the backend, the contract, the specification, or the prototype.

---

## 1. Summary

| Category | Count | IDs |
|---|---|---|
| Frontend tasks blocked by an unapproved backend change | **2** | `UI-PUR-003`, `UI-PUR-014` |
| Frontend tasks whose backend dependency is delivered and which await separate implementation authorization | **1** | `UI-PUR-007` |
| Backend change requests awaiting explicit owner approval | **5** | `BE-CR-002` … `BE-CR-006` |
| Backend change requests with decided policy, delivered and verified | **1** | `BE-CR-001` (`T438`–`T441`, `DONE_VERIFIED` 2026-10-28; `T041` closed) |
| Owner decisions still open that gate *planning*, not any current card | **2** | `REC-01` (English catalog), intra-OPEN ordering (inside `BE-CR-002`) |
| Owner options recorded but deliberately not adopted | **2** | `REC-04` alternative (adopt `POST /correction`), FR-062 amendment for a strict six-column list |
| Findings requiring runtime verification before they may be called reproduced | **3** | `GAP-005`, `GAP-022`, `GAP-029` |
| Verified-correct controls that must **not** be "fixed" | many | see `AUDIT_REPORT.md` §verified controls |

Nothing else in the package is blocked. At the original audit baseline, **13 of the 16 frontend cards were `READY_FOR_OWNER_APPROVAL`** (not approved); later task closures are tracked in `specs/005-purchaser-ui-redesign/tasks.md`.

---

## 2. Blocked frontend tasks

### 2.1 `UI-PUR-003` — Sheet list sorting: OPEN-first in either direction, correct across pages

- **Blocked by:** `BE-CR-002` (unapproved).
- **Decision status:** D-09 is **resolved**. OPEN sheets must always appear first; submitted sheets follow the requested First Submitted direction; the behaviour must hold across pagination.
- **Why the frontend cannot do it alone:** `GET /sheets` is paged server-side (`ocs-api.yaml:364-381`) and the contract contains **no ordering clause**; ordering only the loaded page is the already-recorded defect (`GAP-005`) and would make page boundaries lie. A client-side sort cannot satisfy "across pagination".
- **Contractually-correct frontend-only workaround:** **none.** The only correct behaviours are (a) wait for server ordering, or (b) leave the list unordered — which is the defect.
- **Unblock condition:** `BE-CR-002` approved **and** its intra-OPEN ordering question (below) answered, then `UI-PUR-003` implemented against the frozen contract.

### 2.2 `UI-PUR-007` — Edit the sheet's title inline while OPEN, with the account immutable

- **Blocked by:** `BE-CR-001` **delivery is complete** (immutable-account policy decided 2026-10-27; 001 `T438`–`T441` all delivered and verified 2026-10-28; `T041` closed). The remaining blocker is separate implementation authorization for this frontend task. There is no replacement-path blocker: the prior same-currency replacement policy is withdrawn, and no slice is partially blocked.
- **Decision status:** D-05 is **resolved and corrected** — while the sheet is `OPEN`, its owner may edit the **title only**. The source account and currency are immutable after creation. The earlier "title *and* account are editable" reading is superseded by the owner's 2026-10-27 Option A decision.
- **Why the frontend cannot do it alone — and this is the sharpest finding of the reconciliation pass:**
  1. The controller **requires** `accountId` on update and refuses its absence with a field-level 422 (`lib/Controller/SheetController.php:147-158`).
  2. The contract, however, requires only `title` and states that `accountId` "cannot be required" (`ocs-api.yaml:1396-1410`). So the **only** least-invasive workaround — a title-only PATCH — is *contractually correct but implementation-refused*; making it work is itself a backend change.
  3. The client cannot compute the sheet's current account: it is absent from every payload (`SheetPayloadFormatter.php:22-24` drops it deliberately), so even a legitimate read-only display is impossible today.
  4. Substituting `accounts[0]` is exactly `GAP-011`: a silent repointing of the sheet, with no currency reconciliation. The controller currently accepts that value without any existence, ownership, active-state, or currency check.
- **Contractually-correct frontend-only workaround:** **none exists.** Not sending the field fails validation; sending a guess is data corruption; sending the current account is still a refused assignment under the immutable-account policy.
- **Unblock condition:** the `BE-CR-001` backend delivery (001 `T438`–`T441`) is complete, and this frontend task still requires separate explicit implementation authorization. The delivered behavior covers repository account-identity integrity, `SheetDetail.accountId`, title-only `SheetUpdate` with any supplied `accountId` refused before persistence, read-only edit display, and focused verification (14 suites/236 tests/1,616 assertions, 1 pre-existing skip; OpenAPI/TypeScript gates passing; settlement unchanged). The policy's normative source is 001 `FR-019`/`FR-021`: the source account and currency are immutable after creation; an `OPEN` sheet's owner may update the title only; the account is displayed accurately and read-only, including when inactive; a title-only PATCH omits `accountId`; any supplied `accountId` — including `null`, the current account, an inactive account, or an owned/active/same-currency account — is refused with `422 VALIDATION_FAILED`/`fieldErrors.accountId`; corrupted references are reported as `422 DATA_FAULT`, never repaired. The public `accountId` is declared on `SheetDetail` only. The Sheets List remains governed by FR-083's all-or-nothing corrupt-record answer and does not gain `accountId`. `pettycash_accounts_user_currency_uniq` and the account model are unchanged; no second-account fixture exists.

### 2.3 `UI-PUR-014` — Stage summary inside the sheet workspace

- **Blocked by:** `BE-CR-003` (unapproved, and now **required** rather than optional).
- **Decision status:** D-06 is **resolved** — stage statistics must come from actual transaction decisions at each stage, showing counts **and** amounts for Approved, Rejected, Pending and (when applicable) Returned; final finance totals are **not** an acceptable substitute.
- **Why the frontend cannot do it alone:** the detail payload publishes only the four stage-approved *money* figures; the per-stage decision **counts** are not in any response. Deriving them client-side would mean iterating transactions and re-implementing a domain aggregation in the presentation layer — forbidden by the project's layer rules.
- **Contractually-correct frontend-only workaround:** **partial and insufficient.** The existing published figures can be displayed honestly (that is today's behaviour and part of `UI-PUR-002`), but counts cannot be produced without either lying or re-deriving a domain rule. Neither is acceptable.
- **Unblock condition:** `BE-CR-003` approved with `REC-03`'s semantics stated (below).

---

## 3. Backend change requests — one delivered, four policy-undecided

**`BE-CR-001` is not one of the four policy-undecided requests.** Its immutable-account policy was
decided on 2026-10-27; the delivery 001 `T438`–`T441` is implemented and verified (2026-10-28), and
`T041` is closed. There is no replacement path and no partially blocked slice. The downstream frontend
cards still require their own separate implementation authorization. `BE-CR-002`, `003`, `005`, and `006` are
the four requests that remain policy-undecided. 001 `FR-019`/`FR-021` are the normative source for the
`BE-CR-001` policy; this document references them rather than restating them as an independent policy.

### 3.1 The approval rule being applied

Repository governance requires explicit separate approval for any API, financial-rule, authorization or backend-behaviour change (`docs/frontend-redesign/CHARTER.md:84-86`, `DECISIONS.md` D-005, `handoff/governance/manus-handoff-snapshot/FAST_TRACK_ADDENDUM.md:42`), and the backlog's own `BE-CR` definition says the owner "explicitly approves the exact change before implementation" (`…/FRONTEND_TASK_BACKLOG.md:26`). A search of the whole `docs/frontend-redesign/**` tree found **no document that approves any matching backend change**. Approval was not inferred from similar naming, from the audit's existence, or from the owner's clarifications (which explicitly authorise *task planning only*).

### 3.2 The six requests

| ID | Required API / behaviour delta | Endpoints & code touched | Frontend consumer | Classification |
|---|---|---|---|---|
| `BE-CR-001` | Expose the sheet's `accountId` on **`SheetDetail` only**; make the account immutable after creation (title-only `SheetUpdate`; any supplied `accountId` refused before persistence with `fieldErrors.accountId`; sheet currency immutable; inactive stored account preserved and displayed read-only; corrupted references reported, never repaired) | `PATCH /sheets/{sheetId}`, `GET /sheets/{sheetId}`; `SheetController`, `SheetService`, `SheetPayloadFormatter`, `DbExpenseSheetRepository` | `UI-PUR-007` | **Required by a frontend requirement** (D-05); **immutable-account policy decided 2026-10-27 (`D-026`), with `T438`–`T441` delivered and verified 2026-10-28 and `T041` closed** — the backend behavior is delivered; the frontend task is blocked on its own separate implementation authorization, **not** policy-undecided and **not** on a replacement decision |
| `BE-CR-002` | Deterministic server ordering: OPEN-first always, then the requested direction on First Submitted, valid across pages, with a documented tie-breaker | `GET /sheets` (`ocs-api.yaml:364-381`), list query + repository | `UI-PUR-003` | **Required by a frontend requirement** (D-09) |
| `BE-CR-003` | Per-stage decision **counts and amounts** (Approved / Rejected / Pending / Returned) added to the sheet-session payload; data already selected by `SheetSummaryQuery::rowsForSheet()` (`:173-181`, `:210-215`) — this is an aggregate + payload change, not new persistence | sheet summary projection; contract schema for the new object | `UI-PUR-014` | **Required by a frontend requirement** (D-06) |
| `BE-CR-004` | One consistent evidence policy: require "all required evidence" at submission (FR-023 currently never enforced — `SubmitSheet.php:83`, `TransactionRevision.php:124-129`) and make resubmission consistent (`ResubmitCorrection.php:108-110` demands it unconditionally) | submission + resubmission application services | `UI-PUR-016` (advisory tier) | **Spec-compliance change — outside the redesign's scope** unless the owner brings it in |
| `BE-CR-005` | Keep the sheet's status a live-derived projection instead of recomputing it only at finance settlement (`FinanceSettlementService.php:408,524-536`; rule in `ExpenseSheet.php:133-179`; required by `contracts/workflow.md:110-113`) | every decision / return / resubmission path | the list and workspace status display (`UI-PUR-002`, `UI-PUR-005`) | **Spec-compliance change** — not redesign-blocking; the frontend must **not** re-derive the rule |
| `BE-CR-006` | Refuse a review decision recorded against a transaction that is awaiting purchaser correction | decision-recording path | History/Approvals surfaces (`UI-PUR-006`) | **Integrity guard** — lowest priority; only legitimate if the owner confirms such a decision is never legitimate |

### 3.3 Open questions that must be answered **before** an approval would even be meaningful

> **2026-10-27:** both `BE-CR-001` questions below are answered in `D-026`; the delivery is 001 `T438`–`T441` and all four slices are delivered and verified (2026-10-28), with `T041` closed. The remaining `BE-CR-002`/`BE-CR-003` questions are still open. The policy answers and task registration authorized documentation only; the later separate authorizations covered the implementation. 001 `FR-019`/`FR-021` are the normative policy source; this document references them. **Option A (immutable account)** supersedes the earlier same-currency replacement answer: the source account and currency are immutable after creation; a title-only PATCH omits `accountId` and preserves the stored account even when inactive; any supplied `accountId` — including `null`, the current account, or an owned/active/same-currency account — is refused before persistence with `422 VALIDATION_FAILED`/`fieldErrors.accountId`. The preferred partial-list availability for a corrupt sheet is **not** adopted because it conflicts with FR-083's all-or-nothing list answer; the list remains `422` with no omission and no partial `200`, and an inactive-but-resolvable account remains readable. `pettycash_accounts_user_currency_uniq` and the account model are unchanged; there is no replacement path, no second-account fixture, and `T041` requires only implementation authorization.
>
> **Readiness reconciliation (2026-10-27, documentation only):** the `T438`–`T441` text now distinguishes an account-reference fault from a sheet-currency fault, defines `save()` refusal, retargets the contradictory detail-absence test, allows `accountId` only in the detail conformance check, updates the provisioning identity note, makes `T440` depend on `T439`, adds the read-only account/currency display with no `accounts[0]` and no `accountId` submission, and adds conformance/provisioning/dashboard/`npm run typecheck`/targeted Vitest coverage plus operator-only remediation and the `T440` official-Chrome desktop + 390px Persian-RTL owner visual gate. **No second active IRR fixture is added**, because no replacement case exists. All four slices were ready under explicit implementation authorization, and `T041` could close after authorized delivery and verification. No in-app repair is authorized.

| For | Question | Evidence status |
|---|---|---|
| `BE-CR-001` | May a sheet's source account be changed after creation, including to a differently denominated account? | **Answered 2026-10-27 (`D-026`, Option A): no — the account is immutable.** The currency is also immutable; no conversion, migration, or re-denomination occurs. |
| `BE-CR-001` | "Subject to real permissions" — confirm the reading for a title-only update | **Answered 2026-10-27 (`D-026`): the sheet's owner may update the title only while the sheet is `OPEN`.** No account is supplied or validated, and there is no administrator or finance override. |
| `BE-CR-002` | How is the **OPEN group** ordered among itself? Open sheets have no first-submission timestamp. | **Not answered by D-09**; must be chosen |
| `BE-CR-002` | Tie-breaker when several sheets share a First Submitted instant | Not answerable from source |
| `BE-CR-003` | Confirm `REC-03`: stage statistics cover the sheet's **active** revisions, so a Return superseded by a correction revision no longer counts as returned, and the resubmitted transaction re-enters as Pending at Cost Center | Derived from `rowsForSheet()`'s row set; must be confirmed |
| `BE-CR-003` | Confirm the payload shape (per-stage object with count + amount for approved/rejected/pending, plus returned when applicable) and that it must never be presented as a finance total | Design detail; needs the owner's wording |
| `BE-CR-004` | Is FR-023's evidence rule in the current release scope at all, given "existing Spec Kit requirements that have not yet been implemented are not automatically in scope"? | Governance question |
| `BE-CR-006` | Is a decision on a correction-stage transaction ever legitimate? | Not answerable from source |

---

## 4. Blocked decisions and recorded alternatives (not tasks)

### 4.1 `REC-01` — no English catalog exists (owner decision required)

The UI is key-based (`t('pettycash', 'sheets.title')`, `src/views/sheets/SheetListView.vue:16`), only `fa` ships (356 keys), `src/l10n.ts` has no English default table, and `@nextcloud/l10n` renders an untranslated key as the key itself. **Consequence: English LTR cannot be reviewed in a running build until an `en` catalog exists (~356 values).** Since D-02 prioritises English LTR, this gates every English visual acceptance criterion in `TEST_STRATEGY.md` — but it does **not** gate `UI-PUR-001`'s mechanism, which is required either way.

- **Options:** (a) author `l10n/en.json` + `l10n/en.js` as a prerequisite content task; (b) keep shipping `fa` and verify English LTR against the static references only.
- **Must not happen meanwhile:** a partial/stub English catalog, or new UI copy shipped without its `fa` entry (the gate in `tests/Frontend/l10nCatalog.spec.ts` will fail, correctly).

### 4.2 Residual specification questions (non-blocking, recorded)

- **D-03 residual:** the list's date filter keeps **creation-date** semantics and must *say so* (`REC-06`, inside `UI-PUR-002`). Switching to expense-date semantics is a new filter and needs a spec amendment.
- **D-04 residual:** FR-062 (`spec.md:1046-1048`) requires the stage-approved figures "as separate columns" — they stay visible with their own labels and cells. A strict six-column list would need an explicit FR-062 amendment.
- **D-07:** the two published-but-unreachable transaction statuses stay in the contract (removal is a contract change) and must never be rendered (`UI-PUR-006` criterion). Non-blocking.
- **D-10:** the audit feed's literal `'DECISION'` field stays; the UI reads the payload it needs. Non-blocking.

### 4.3 Alternatives recorded but deliberately **not** adopted

- **`REC-04` alternative:** adopting `POST /transactions/{transactionId}/correction` (`ocs-api.yaml:594-617`) as the canonical correction entry point instead of the PATCH flow. Not adopted because it changes revision history (an extra initial revision) and the PATCH flow is already pinned by passing behaviour. The dedicated operation stays available.
- **FR-062 amendment** for a strict six-column list (see D-04 residual).

### 4.4 Mobile reference set (D-01 / D-08 — resolved, listed for traceability)

`docs/frontend-redesign/references/mobile/` contains **9** images: `sheets-list-purchaser-mobile-en-v1.png`, `sheet-detail-open-purchaser-mobile-en-v1.png`, `sheet-detail-cost-center-review-purchaser-mobile-en-v1.png`, `sheet-detail-completed-with-rejections-purchaser-mobile-en-v1.png`, `transaction-add-purchaser-mobile-en-v1.png`, `transaction-correction-purchaser-mobile-en-v1.png`, plus `generated_mobile_view_transaction.png`, `generated_mobile_edit_draft.png`, `generated_mobile_revisions_history.png`. The dialog reference is `references/dialogs/unsaved-changes-en-desktop-v1.png`. The earlier "eight images" discrepancy is moot: D-01 makes the mobile screens the current references.

---

## 5. Items requiring runtime evidence before stronger claims

**Findings the audit recorded as source-confirmed/inferred, never reproduced — do not describe them as runtime-verified:**

| Finding | What is missing |
|---|---|
| `GAP-005` — pagination tie behaviour | Requires a live `GET /sheets` against a dataset with identical sort keys |
| `GAP-022` — a decision recorded against a correction-stage transaction | Requires a live workflow run through that state |
| `GAP-029` — the client's reaction to a 409 | Requires a live stale-version conflict |

**Acceptance areas that will need runtime/browser verification once implemented** (tracked in `TEST_STRATEGY.md` §3): English LTR rendering (`REC-01`), Persian RTL rendering of the *new* components, mobile acceptance against the 9 references, focus-trap and focus-return behaviour, dark-mode contrast on the new components, the 390px floor on the new layouts, and the evidence-upload affordance against a real transaction.

---

## 6. Prohibitions

While any item above is blocked: **do not** edit `lib/**`, `appinfo/**`, `specs/**` (including the OpenAPI contract and workflow contract), `l10n/**`, `tests/**`, the Manus prototype, or any governance document; **do not** add an endpoint, parameter, field or ordering clause; **do not** re-derive a domain rule client-side to compensate; **do not** install a dependency to close a gap; **do not** commit, stage or push. Report the block instead.

*The three blocked frontend cards are otherwise fully specified and will start the moment their backend request is approved and its open questions are answered.*
