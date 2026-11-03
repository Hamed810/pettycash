# BE-CR-001 — Expose the sheet's account and make it immutable on update

- **Type:** backend contract + application change request · **Status:** **DELIVERED AND VERIFIED — `T438`–`T441` DONE_VERIFIED (2026-10-28)** — the product policy was decided on 2026-10-27 (see §9) and the owner authorized `T438`, `T439`, `T440`, and then `T441`; the repository account-identity integrity, the contract/detail projection, the immutable title-only PATCH, the read-only edit display, and the focused closure are implemented and verified (see §10). This request is **not** one of the four policy-undecided requests (`BE-CR-002`, `003`, `005`, `006`). The earlier same-currency replacement policy and its feasibility conflict remain **withdrawn**; no replacement path, second-account fixture, or partial slice exists.
- **Priority:** P1 · **Size:** S–M · **Risk:** low (additive contract field) / medium (the refusal rule is new behaviour)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-011 (server half) · **Frontend consumer:** `UI-PUR-007`

> **This is not an implementation ticket.** It is a change request that must be accepted by the product owner before any code is written, because it alters a published contract and adds a server-side refusal path.

## 1. Why it is needed

`UI-PUR-007` cannot fix GAP-011 on its own. The sheet-update request **requires** `accountId` (`lib/Controller/SheetController.php:147-158`), the sheet's own account is **not** exposed by the contract (`SheetSummary` has no account member — `contracts/ocs-api.yaml`), and the payload formatter deliberately drops it ("`formatSheet()` also emitted an `accountId` the contract does not declare. It is dropped here" — `lib/Infrastructure/Http/SheetPayloadFormatter.php:22-24`). The client therefore cannot send the correct value and substitutes the caller's first account, which the server accepts without validation:

```
$sheet->title = $title;
$sheet->accountId = $accountId;      // no existence / ownership / active / currency check
lib/Application/Expense/SheetService.php:184-186
```

Because the sheet stores `accountId` and `currency` as independent facts (`lib/Domain/Expense/ExpenseSheet.php:23-24`; persisted at `DbExpenseSheetRepository.php:177-178,196-197`) and never reconciles them, a rename can leave a sheet denominated in one currency pointing at an account holding another — while the account's own money rules require a matching currency (`lib/Domain/Account/PettyCashAccount.php:53,73`).

**Residual gap after the 2026-10-27 decision.** The owner made the source account immutable, so the defect is no longer "the account cannot be chosen" but "the account can still be silently repointed". The current update path accepts a supplied account, assigns it without checks, and persists it. A title-only update that omits the account is contractually correct on create but is refused by the current implementation because `accountId` is required. The sheet's authoritative account is also invisible to its own owner: the currency is shown and the account is not. The fix is therefore to expose the stored account on the detail payload, make the update title-only, and refuse every supplied account before persistence rather than validating a replacement that policy no longer permits.

## 2. Requested change

1. **Expose the account on the sheet detail payload.** ~~Add the sheet's account identifier to `SheetSummary` (and therefore `SheetDetail`)~~ **Superseded 2026-10-27 (see §9): the owner chose `SheetDetail` only; `SheetSummary` and the Sheets List response MUST NOT carry `accountId`.** Add the sheet's authoritative account identifier to `SheetDetail` as a UUID, with the account's display name if one is already available for UI affordance. Naming follows the existing convention for the create request (`accountId`, `uuid` — `contracts/ocs-api.yaml:1401-1403`). The value MUST be resolved from the stored account row, never from `accounts[0]`, list order, an empty string, or a guessed value.
2. **Make the account immutable on `PATCH /sheets/{sheetId}`.** The update request becomes title-only: a dedicated `SheetUpdate` schema carries `title` and `expectedVersion`, sets `additionalProperties: false`, and declares no `accountId`. Only **omission** of `accountId` is accepted. Any supplied value — including `null`, an empty or whitespace string, the current account, an inactive account, or an owned/active/same-currency account — is an attempted assignment and MUST be refused before persistence with `422 VALIDATION_FAILED` and `fieldErrors.accountId`, leaving the title, account, currency, and lock version unchanged. There is no account-changing path and no validation-of-a-replacement path.
3. **Keep FR-020 intact.** Creation-time behaviour must not change: a purchaser with no accounts must still be able to create a sheet with `accountId` omitted (`contracts/ocs-api.yaml:1400-1410`), and `SheetCreate` is untouched. This change request concerns **update** only; no provisioning occurs on update.
4. **Preserve the stored reference rather than repairing it.** An inactive stored account does not block a title-only update and is displayed accurately and read-only. An unknown, dangling, or currency-disagreeing stored reference is a data fault (`422 DATA_FAULT`), detected and reported, never silently replaced or re-denominated. Because the update never supplies an account, no corruption variant becomes a field-level account error.

## 3. Endpoint / contract / test impact

| Item | Impact |
|---|---|
| `GET /api/v1/sheets/{sheetId}` | `SheetDetail` gains a required public `accountId` (response-additive); `GET /api/v1/sheets` and `SheetSummary` are unchanged |
| `PATCH /api/v1/sheets/{sheetId}` | new title-only `SheetUpdate` request schema (`title` required, `expectedVersion` required, `additionalProperties: false`, **no** `accountId` property); any supplied `accountId` is a field-level `422 VALIDATION_FAILED`/`fieldErrors.accountId` refusal |
| `contracts/ocs-api.yaml` | `SheetDetail.accountId` added; `SheetUpdate` declared; `SheetCreate` and `SheetSummary` unchanged; `scripts/validate-openapi.sh` must pass and `src/generated/api.ts` must be regenerated only through `scripts/validate-openapi.sh --write` |
| `src/types/api.ts` | the hand-written `SheetDetail` type gains `accountId: string`; `SheetUpdate` carries `title` and `expectedVersion` only |
| Existing tests | `tests/Contract/Controller/PurchaserFlowControllerTest.php` (retarget `testSheetSummaryCarriesNoUndeclaredAccountId` to `GET /sheets` and add a detail assertion), `SheetSummaryConformanceTest.php` (allow `accountId` only on detail), `SheetProvisioningContractTest.php` (update the identity note), `CompletedSheetLockTest.php`, `StaleVersionConflictTest.php`, and `DataIntegritySheetCurrencyTest.php` |
| New tests | contract: any supplied `accountId` (including `null`, empty/whitespace, the current account, an inactive account, and an owned/active/same-currency account) → `422 VALIDATION_FAILED` with `fieldErrors.accountId` and no mutation; title-only → `200` with account, currency, and amounts unchanged; title-only on a sheet whose stored account is inactive → `200`, account preserved and displayed read-only; corrupted account reference and corrupted sheet-currency reference → `422 DATA_FAULT` on read and update, never `fieldErrors.accountId`; formatter detail-only unit test; repository round trip and unresolved-account refusal |
| Integration | `tests/Integration/Expense/SheetAccountUpdateTest.php`, `tests/Integration/Persistence/RepositoryRoundTripTest.php`/`roundtrip-runner.php`, and the existing settlement test as a no-change regression |

**Compatibility note (2026-10-27).** The response change is additive on `SheetDetail`; `SheetSummary`,
the `GET /sheets` list response, and `SheetCreate` are unchanged. Because create and update responses
also return `SheetDetail`, `src/generated/api.ts` and the hand-written `src/types/api.ts` are updated
in the same implementation slice, and existing in-repository consumers and strict response validators
are audited then. The contract records no external-consumer compatibility guarantee beyond its
versioned schema, so this record does not claim that no external consumers exist.

The request change is **not request-shape compatible**: a PATCH body that carries `accountId` is
schema-invalid under `SheetUpdate` and is refused by the server before persistence. It is also not
behaviourally neutral, because a body that resubmits the current account — including an inactive one —
was previously accepted and is now refused. The owner explicitly accepted pre-release API and
behavioural incompatibilities because the application is still under development and has not launched.
The release/versioning treatment is recorded under Constitution v1.4.0: because the PATCH change is
breaking for the published contract, Delivery item 6 requires explicit approval, a compatibility
strategy, release notes, and a **MAJOR version decision for the affected public contract** at the
separately authorized release step. This planning record performs no MAJOR, minor, patch,
`appinfo/info.xml` (`1.3.1`), or `package.json` (`1.0.0`) bump, and any release
note or version decision belongs to a separately authorized release step. A documented strict validator
that rejects the added detail property would be a V.6 breaking-change exception requiring separate
approval, not an assumed failure.

## 4. Why this is not "just make the field optional"

Relaxing `accountId` to optional on PATCH would let a title-only update avoid the bug, but "optional"
is ambiguous for an assignment field: the server cannot distinguish "unchanged" from "explicitly set to
the value it already has", and a later client can silently re-enable account selection without any
contract change. The owner rejected that ambiguity on 2026-10-27 by making the account immutable, so
the correct contract has no update-time account property at all: omission is the only form, and any
present key is a refused assignment. Merely making the field optional would also leave the sheet's
account **invisible** to its own owner — the currency is shown, the account is not — which the detail
projection fixes.

## 5. Open question for the owner (answered 2026-10-27 — see §9)

**May a sheet's account be changed at all after creation?**
- If **yes**: is a change to a differently-denominated account allowed? If it is, the sheet's stored currency must be reconciled in the same transaction, with a defined rule for the transactions already recorded in the old currency — that is a data-migration question, not a validation tweak, and this change request should be split.
- If **no**: the field becomes immutable after creation and the update path must reject any change with a named error (the frontend then shows the account read-only). This is the safest reading and the recommendation of this audit.

**Answered 2026-10-27 (Option A): no — the account is immutable after creation.** The owner selected
the second branch. The sheet currency is immutable as well, so no reconciliation, migration, or
re-denomination is needed. The source account is chosen at creation; an `OPEN` sheet's owner may change
the title only; any supplied `accountId` is refused before persistence. This supersedes the earlier
same-currency replacement answer recorded in the first draft of this section.

## 6. Acceptance criteria (for the future implementation)

1. A sheet's account id is present in its **detail** payload only; `SheetSummary` and every `GET /sheets` item MUST NOT carry it (owner decision 2026-10-27), and the value is the stored account's public UUID — never `accounts[0]`, list order, an empty string, or a guess.
2. A title-only PATCH that omits `accountId` succeeds unchanged, and the account id, currency, transaction amounts, and settlement routing are untouched.
3. Any PATCH that supplies `accountId` is refused with `422 VALIDATION_FAILED` and `fieldErrors.accountId`, whether the value is `null`, empty/whitespace, the current account, an inactive account, or an owned/active/same-currency account.
4. The update is transactional: a refused update leaves the sheet byte-identical — no title, account, currency, or lock-version write.
5. FR-020's zero-account provisioning still works on create, and no provisioning occurs on update.
6. `npm run check:openapi` passes; the generated client types are regenerated, not hand-edited.
7. No other endpoint's shape changes. `SheetSummary` and the Sheets List response remain unchanged and do not carry `accountId`.
8. The generated and hand-written types are updated in the same slice, and existing in-repository consumers and strict response validators are audited; the change does not assert that no external consumers exist.
9. A title-only PATCH on a sheet whose stored account is inactive succeeds and preserves that inactive account and its currency; the edit surface displays it accurately and read-only.
10. A corrupt stored account reference or a sheet/account currency disagreement is refused with `422 DATA_FAULT` on both read and update and is never converted to `fieldErrors.accountId`; the list remains all-or-nothing per FR-083 with no omission, no partial `200`, and no substitution.
11. No second-account fixture and no account-replacement test case exists; the one-account-per-currency constraint and the account model are unchanged.

## 7. Rollback

Independently revertable without data repair. Reverting removes the `SheetDetail` member, the
generated and hand-written types, the `SheetUpdate` schema, and the supplied-account refusal, and
restores the previous update handling. No stored `account_id` or `currency_id` value is modified by
this change, so rollback needs no down-migration, no backfill, and no settlement adjustment; rows
written by the current code remain readable. The frontend consumer (`UI-PUR-007`) must be reviewed
together with this change request if either is reverted, because it depends on the detail projection
and the title-only body.

## 8. Approval gate

**SATISFIED.** Requires: (a) acceptance of an additive contract change on a published API — **the owner accepted the `SheetDetail`-only direction on 2026-10-27**; (b) an answer to §5 — **answered 2026-10-27: the account is immutable**; (c) explicit authorization to add the refusal path and the title-only update — **granted for `T438`–`T441` on 2026-10-28**. All four slices are implemented and verified per §10, and `T041` is `DONE_VERIFIED`. `UI-PUR-007` remains a separate frontend task and was not implemented as a substitute for this change request. The 2026-10-27 planning pass registered the delivery as 001 `T438`–`T441`; registration was not implementation approval, and the 2026-10-28 authorizations covered all four slices in order.

## 9. Owner resolution — 2026-10-27

The product owner decided the complete product policy through the installed Spec Kit clarification
workflow and then selected **Option A** on the same date. This section records the decision only; it
authorizes **no** implementation, API/OpenAPI edit, test, build, or task change.

1. **Immutable account:** the sheet's source account is selected at creation and MUST NOT change
   afterwards. This supersedes and withdraws the earlier same-currency replacement policy and its
   feasibility conflict. No account-model expansion, schema change, migration, constraint relaxation,
   or second-account fixture is authorized; `pettycash_accounts_user_currency_uniq` is unchanged.
2. **Currency:** the sheet currency is immutable after creation; existing transaction amounts MUST NOT
   be converted or reinterpreted, and no account may be replaced to obtain a different currency.
3. **Title-only and inactive existing account:** while the sheet is `OPEN`, its owner may update the
   title only. A title-only update omits `accountId` and preserves the stored account — active or
   inactive — and the stored currency. An inactive existing account MUST be displayed accurately and
   read-only and MUST NOT block the title-only update. There is no replacement path.
4. **Rejection:** only omission of `accountId` is accepted. Any supplied value — including `null`, the
   current account, an inactive account, or an owned, active, same-currency account — MUST be rejected
   before persistence with `422 VALIDATION_FAILED` and `fieldErrors.accountId`; no mutation is persisted.
5. **Corrupted data:** existing inconsistent account references or sheet/account currency mismatches
   MUST be detected and reported as `422 DATA_FAULT`, never silently repaired, remapped, auto-provisioned,
   or re-denominated. Because the update never supplies an account, neither corruption variant becomes
   a field-level account error.
6. **Payload:** the public `accountId` is declared on `SheetDetail` only; `SheetSummary` and the Sheets
   List response remain unchanged and MUST NOT carry it.
7. **Settlement:** settlement semantics remain unchanged; `sheet.accountId` is descriptive and MUST NOT
   become a settlement instruction.
8. **Normative source:** 001 `FR-019`/`FR-021` own the immutable-account policy; this record, 005
   `FR-005-019`/`FR-005-050`, `D-026`, and `BLOCKED_ITEMS.md` reference them and do not create a second
   policy authority.
9. **List availability and corrupted records:** the preferred behavior that a corrupt sheet must not
   make healthy sheets unavailable conflicts with FR-083's recorded all-or-nothing list answer. The
   Sheets List remains `422` with no silent omission and no partial `200`; skipping account resolution
   for the list would require a list-specific read model or a nullable domain field, which is a broader
   change and is not adopted here. An inactive-but-resolvable account is not corruption and remains
   readable, preserving read-only history where the stored reference is sound.

**Recorded in:** `specs/005-purchaser-ui-redesign/spec.md` (`## Clarifications` Session 2026-10-27;
FR-005-019; FR-005-050; SC-005-023), `specs/001-pettycash-v1-core/spec.md` (Session 2026-10-27;
FR-019; FR-021), `specs/001-pettycash-v1-core/data-model.md` (Expense Sheet), and
`docs/frontend-redesign/DECISIONS.md` (`D-026`). 001 `FR-019`/`FR-021` are the normative policy source;
the other records reference them. `T080` retains the `BLOCKED_BY_BACKEND` label pending separate
frontend implementation authorization, not because the policy is undecided and not because a replacement
decision is outstanding; the backend projection and update behaviour are now implemented. On 2026-10-28
the owner authorized `T438`–`T441`; all four are delivered and verified, `T440` is owner-approved, and
`T041` is `DONE_VERIFIED`.

## 10. Implementation record — 2026-10-28 (`T438`–`T441` delivered and verified)

The 2026-10-27 planning pass recorded the minimum delivery as 001 Phase 77 `T438`–`T441`, in the order
`T438` → `T439` → `T440` → `T441`. On 2026-10-28 the owner authorized all four in that order; each is
implemented and verified, and `T041` is `DONE_VERIFIED`. There is no replacement-path blocker, no
second-account fixture, and no positive different-account test case.

1. **`T438` — repository account-identity integrity (DONE_VERIFIED, 2026-10-28).**
   `DbExpenseSheetRepository::mapRow()` resolves the stored `account_id` to the account's public UUID and
   currency *after* resolving the sheet currency, so a dangling sheet currency is never misclassified as
   an account mismatch. A missing account row or an account/currency disagreement throws
   `UnreadableRecordData` with the structural account-reference discriminator
   (`UnreadableRecordData::accountReference()` / `isAccountReference()`), never a message parse; a missing
   sheet-currency row throws the same type without it. The account lookup deliberately does not filter
   `active`, so inactive-but-resolvable history stays readable. `save()` resolves the account before
   either statement is built and refuses an unknown public id with the account-reference type before any
   write, instead of writing `account_id = 0`. `OcsErrorMapper` was not changed: its existing arm already
   answers `422 DATA_FAULT` for both variants, logs the internal detail, and withholds the message. The
   corrupt-record fixture gained `account` and `account-currency` probes under the existing
   sweep-before-write and verified-removal discipline, and the roundtrip runner records the normal public
   account round trip, both refusal variants, the inactive-account read, and the no-write refusal.
   Evidence, all via `scripts/dev/phpunit`: the six focused roundtrip cases **6/6, 20 assertions**; the full
   `RepositoryRoundTripTest` **80/80, 390 assertions**; `DataIntegritySheetAccountTest` plus the existing
   `DataIntegritySheetCurrencyTest` **11/11, 46 assertions**. The Sheets List remains all-or-nothing `422`
   under FR-083 with no omission and no partial `200`; skipping account resolution for the list would
   require a broader read model and is not adopted. No backfill, migration, schema change, default currency,
   or repair was added. **Operator-only remediation:** 134 pre-existing `pettycash-e2e` sheets in the shared
   lab pointed at an account deleted by an earlier fixture re-seed; the new mapper correctly refused them,
   and they were corrected out-of-band to the existing e2e account before verification. Application code
   still never repairs a stored reference; a corrupt production row stays refused until an operator acts.
   **Residual, not `T438`:** two 2026-10-11 E2E transactions (ids 2438/2441) have dangling
   `active_revision_id` values, so `DataIntegrityReportTest`'s `/reports/process` control still answers
   `422` from the pre-existing transaction-revision fault. Those rows are unrelated to sheet mapping and
   were not repaired or deleted.
2. **`T439` — contract and `SheetDetail.accountId` (DONE_VERIFIED, 2026-10-28).**
   `SheetDetail`'s detail-only `allOf` member now requires a UUID `accountId`; `SheetSummary` and
   `SheetCreate` are unchanged, and `GET /sheets` emits no account. `PATCH /sheets/{sheetId}` now uses a
   dedicated `SheetUpdate` schema with required `title` and `expectedVersion`, `additionalProperties:
   false`, no `accountId` property, and no FR-020 provisioning semantics; the operation and schema
   descriptions state that a supplied `accountId` is refused with
   `422 VALIDATION_FAILED`/`fieldErrors.accountId` before persistence, which `T440` enforces.
   `SheetPayloadFormatter::detail()` emits the sheet's stored public `accountId` and
   `summary()`/`summaries()` were not changed. The generated types were produced only through
   `scripts/validate-openapi.sh --write`, and both `src/generated/api.ts` and `src/types/api.ts` expose
   the field and `SheetUpdate`; the service/store and edit form now build a title-only body. The old
   detail-absence test was retargeted to the list, the detail conformance test allows `accountId` only on
   detail, and the provisioning identity note now asserts the provisioned account id equals the detail
   `accountId`. Focused evidence: `SheetPayloadFormatterAccountTest` **4/4, 11 assertions**;
   `SheetSummaryConformanceTest` **6/6, 165 assertions, 1 pre-existing skip**;
   `PurchaserFlowControllerTest` **64/64, 521 assertions**; `SheetProvisioningContractTest` **3/3, 28
   assertions**; targeted Vitest **174/174**; the OpenAPI gate, `npm run typecheck`, and scoped ESLint
   clean. The change is response-additive on detail but **not request-shape compatible**: legacy PATCH
   bodies carrying `accountId` are schema-invalid, and `T440` makes them server-refused. The owner
   accepted that pre-release incompatibility, and the closure/release note must record it. A documented
   strict validator that rejects the added property would be a V.6 exception.
   **Transitional risk:** the controller still required `accountId` until `T440`, so a title-only edit
   save answered `422` in this slice; the contract and frontend are already title-only, and no
   account-guessing path was added. This was resolved by `T440` later on 2026-10-28. `--write` also refreshed pre-existing uncommitted contract drift
   already present in the working tree (transaction-delete/evidence amendments) because the generator is
   whole-file; the generated file now matches the current contract byte-for-byte.
3. **`T440` — immutable title-only PATCH and read-only edit display (DONE_VERIFIED, 2026-10-28).** Only omission of `accountId` is
   accepted; any present key, including `null`, is refused before mutation with field-level
   `422 VALIDATION_FAILED` via a narrow `SheetAccountInvalid`. `SheetService::updateSheet()` accepts no
   account parameter, and no adopted-account validation exists. A title-only update succeeds on a sheet
   whose stored account is inactive and preserves that account and currency. Both corruption variants
   remain `422 DATA_FAULT`; the mapper logs and withholds the internal message and never converts either
   to `fieldErrors.accountId`. `ExpenseSheet::$currency` becomes `readonly`. The full-page edit form
   displays the stored account and currency authoritatively and read-only from `SheetDetail.accountId`,
   keeps an inactive current account visible, offers no account selector, and never sends `accountId`;
   `accounts[0]` is not an edit source. Owner scoping, `OPEN`-only refusal, and stale-version `409`
   ordering are preserved; settlement is untouched. `T440` required official Google Chrome desktop and
   390px Persian-RTL screenshots under `artifacts/visual-review/t440/<iteration>/`, and stopped for
   explicit owner visual approval; the owner recorded limited visual approval on 2026-10-28, verbatim:
   “I approve T440 with limited visual acceptance.” `T440` is `DONE_VERIFIED`; the deferred visual
   issues belong to `T080`.
4. **`T441` — focused verification and closure (DONE_VERIFIED, 2026-10-28).**
   Contract/integration/repository/formatter/domain and conformance/provisioning tests, dashboard
   account-fault coverage, the OpenAPI gate, `npm run typecheck`, targeted Vitest/lint, regression on the
   existing sheet and settlement tests, and the closure record. Evidence: 14 focused PHPUnit suites,
   **236 tests, 1,616 assertions, 1 pre-existing skip**; `scripts/validate-openapi.sh` exits 0
   (OpenAPI 3.0.3, 33 paths, 43 operations, 46 schemas; generated types match); `npm run typecheck`
   passes; targeted Vitest 8 files/**223 tests**; scoped ESLint/Stylelint clean. `T441` added one
   dashboard account-fault test and documentation; no application source changed. The closure records
   the accepted pre-release compatibility impact, that Delivery item 6 requires a MAJOR public-contract
   decision plus release notes and a compatibility strategy at the separately authorized release step,
   and the release/versioning decision without performing an automatic bump, and records the
   operator-only remediation procedure for pre-existing corrupt rows. `T041` closed as `DONE_VERIFIED`.
   `T039`/`T080` retain the `BLOCKED_BY_BACKEND` label pending their own separate frontend implementation
   authorization, with `T080`'s visual gate after implementation. `T079` remains `DONE_VERIFIED`;
   `T437`/`ADR-008` remain deferred.

**No schema change or migration is required:** `account_id` and `currency_id` already exist. `T438`
changed no schema; `T439` changed no schema; `T440`–`T441` add no cross-currency migration, no settlement change, no authorization
change, no account-model change, and no `SheetSummary` change. The full task text, files, dependencies,
and acceptance criteria are in `specs/001-pettycash-v1-core/tasks.md` Phase 77; the architecture,
rollback, and the `T438`/`T439` delivery notes are in `specs/001-pettycash-v1-core/plan.md`.

---

## Historical note — 2026-10-19 reconciliation (superseded 2026-10-27)

- **Status at that time (2026-10-19):** `BLOCKED_PENDING_OWNER_APPROVAL`. No approval existed then; a search of `docs/frontend-redesign/**` found no document approving any matching backend change, and governance requires explicit separate approval.
- **Superseded product answer:** the 2026-10-19 reading was that the sheet's account **is** editable while the sheet is OPEN. The owner's 2026-10-27 Option A decision reverses that reading: the account is immutable after creation and only the title may change. This paragraph is retained as history and MUST NOT be read as current policy.
- **Superseded sharpening by `REC-02`:** `SheetCreate` requires only `title` and its text states `accountId` "cannot be required" (`ocs-api.yaml:1396-1410`), while `SheetController.php:147-158` refuses an absent `accountId` with a 422. The minimal workaround — a title-only PATCH — was *contractually correct but implementation-refused*; `T440` is the task that makes it implementation-correct.
- **Superseded open answers:** the currency rule when a sheet "adopts a differently-denominated account", and the permission reading that required ownership of the target account plus its active state. Both are moot because no account can be adopted; the currency is immutable and the stored account is preserved.
- Frontend consumer `UI-PUR-007` was blocked on this request and on the backend delivery of the immutable-account behaviour; it was not blocked on any further policy answer. As of 2026-10-28 the request and delivery are complete, so only its own separate frontend implementation authorization remains.
