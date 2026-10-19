# BE-CR-001 — Expose the sheet's account and validate it on update

- **Type:** backend contract + application change request · **Status:** **BLOCKED_PENDING_OWNER_APPROVAL**
- **Priority:** P1 · **Size:** S–M · **Risk:** low (additive contract field) / medium (the validation rule is new behaviour)
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

## 2. Requested change

1. **Expose the account on the sheet payload.** Add the sheet's account identifier to `SheetSummary` (and therefore `SheetDetail`), as a UUID, with the account's display name if one is available for UI affordance. Naming must follow the existing convention for the create request (`accountId`, `uuid` — `contracts/ocs-api.yaml:1401-1403`).
2. **Validate the assignment on `PATCH /sheets/{sheetId}`.** Refuse, with a named `422` consistent with the existing error envelope (`tests/Contract/Controller/ErrorEnvelopeContractTest.php`), when the supplied account:
   - does not exist, or
   - is not owned by the caller, or
   - is inactive, or
   - is denominated in a currency other than the sheet's current currency (or, if the owner prefers, redefine the rule deliberately and reconcile the currency — see §5).
3. **Keep FR-020 intact.** Creation-time behaviour must not change: a purchaser with no accounts must still be able to create a sheet with `accountId` omitted (`contracts/ocs-api.yaml:1400-1410`). This change request concerns **update** only.

## 3. Endpoint / contract / test impact

| Item | Impact |
|---|---|
| `GET /api/v1/sheets`, `GET /api/v1/sheets/{sheetId}` | response schema gains an account member (additive) |
| `PATCH /api/v1/sheets/{sheetId}` | new refusal path; new `422` reason |
| `contracts/ocs-api.yaml` | `SheetSummary` property added; the PATCH description gains the validation rule; `npm run check:openapi` must pass and `src/generated/api.ts` must be regenerated (`openapi:write`) |
| `src/types/api.ts` | the sheet summary type gains the member |
| Existing tests | `tests/Contract/Controller/PurchaserFlowControllerTest.php:393` (update returns 200) and any schema-shape assertions must be reviewed; shape tests that pin the exact member set will need a deliberate update |
| New tests | contract: update with a foreign account → 422; inactive → 422; wrong currency → 422; own account → 200 with the same currency; list/detail payload contains the account; create-with-no-account still provisions (FR-020 regression) |
| Integration | `tests/Integration/Expense/TransactionServiceUpdateTest.php` neighbours; the data-integrity expectations in `tests/Contract/Controller/DataIntegritySheetCurrencyTest.php` must remain satisfied |

## 4. Why this is not "just make the field optional"

Relaxing `accountId` to optional on PATCH would let a title-only update avoid the bug, but it would leave the sheet's account **invisible** to its own owner — the currency is shown, the account is not — which contradicts the accepted direction that the account is an editable OPEN-sheet field and makes any future account selection impossible. Exposing plus validating is the smaller long-term change; making the field optional is a legitimate fallback (see `UI-PUR-007` §8) but should be a conscious choice, recorded as such.

## 5. Open question for the owner (must be answered before implementation)

**May a sheet's account be changed at all after creation?**
- If **yes**: is a change to a differently-denominated account allowed? If it is, the sheet's stored currency must be reconciled in the same transaction, with a defined rule for the transactions already recorded in the old currency — that is a data-migration question, not a validation tweak, and this change request should be split.
- If **no**: the field becomes immutable after creation and the update path must reject any change with a named error (the frontend then shows the account read-only). This is the safest reading and the recommendation of this audit.

## 6. Acceptance criteria (for the future implementation)

1. A sheet's account id is present in its list and detail payloads.
2. A PATCH that does not change the account succeeds unchanged.
3. A PATCH that attempts an invalid account is refused with a named 422, and the sheet keeps its account (negative case: no partial write — the title must not be updated either, or the whole request must be accepted; a half-applied update is the one unacceptable outcome).
4. The update is transactional: a refused update leaves the sheet byte-identical.
5. FR-020's zero-account provisioning still works on create.
6. `npm run check:openapi` passes; the generated client types are regenerated, not hand-edited.
7. No other endpoint's shape changes.

## 7. Rollback

Additive and independently revertable: removing the schema member and the validation restores today's behaviour (and today's defect). The frontend consumer (`UI-PUR-007`) must therefore be reviewed together with this change request if either is reverted.

## 8. Approval gate

**BLOCKED_PENDING_OWNER_APPROVAL.** Requires: (a) acceptance of an additive contract change on a published API; (b) an answer to §5; (c) explicit authorization to add a server-side refusal path. No work starts before all three. `UI-PUR-007` must not be implemented as a substitute for this change request.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Status unchanged:** `BLOCKED_PENDING_OWNER_APPROVAL`. **No approval exists**; a search of `docs/frontend-redesign/**` found no document approving any matching backend change, and governance requires explicit separate approval.
- **Product question answered (`D-05`):** the sheet's account **is** editable while the sheet is OPEN, subject to real permissions — so the request is not asking whether the account may change, only how to expose and validate the change.
- **Sharpened by `REC-02` (contract/implementation divergence):** `SheetCreate` requires only `title` and its text states `accountId` "cannot be required" (`ocs-api.yaml:1396-1410`), while `SheetController.php:147-158` refuses an absent `accountId` with a 422. The minimal workaround — a title-only PATCH — is therefore *contractually correct but implementation-refused*, which is why it too needs a backend change. Present both options in the approval request.
- **Answer before approval (see `BLOCKED_ITEMS.md` §3.3):** the currency rule when a sheet that already stores a currency adopts a differently-denominated account; and the permission reading — "subject to real permissions" is currently *derived* as ownership of the sheet **and** the target account, with the account active.
- Frontend consumer `UI-PUR-007` is blocked on this request and on those two answers.
