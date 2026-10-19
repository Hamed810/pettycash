# UI-PUR-016 — Tell the purchaser what is blocking submission, before they press Submit

- **Priority:** P2 · **Status:** READY_FOR_OWNER_APPROVAL for the server-enforced tier; the evidence criterion waits on `BE-CR-004` and stays advisory until it is approved · **Size:** S–M · **Risk:** low
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-031, GAP-030 (consumes) · **Backend impact:** `BE-CR-004` only for the advisory evidence tier (separately blocked); the rest of the card is frontend-only

## 1. User-visible outcome

An OPEN sheet that cannot be submitted shows why — which transaction is incomplete and what it is missing — instead of an enabled Submit button and a server rejection after the click.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| Submit availability ignores completeness | `canSubmit = status==='OPEN' && transactions.length>0` — `src/stores/sheets.ts:14` |
| The button appears whenever `canSubmit` is true | `src/views/sheets/SheetDetailView.vue:258-261` |
| Server-side, the refusal arrives as a 422 rendered into a dedicated region | `SheetDetailView.vue:442-451`, with the behaviour documented as intentional at `:439-441` |
| The server's own gate checks description, cost center, expense type, at least one transaction and resolvable cost centers | `lib/Application/Workflow/SubmitSheet.php:94-116`; `lib/Domain/Expense/TransactionRevision.php:124-129` |
| …but **not** evidence, even when the expense type requires it | `SubmitSheet.php:83` calls with the default `$evidenceRequired=false`; see `BE-CR-004` |
| Transaction-level validation already exists in the form | `TransactionFormView.vue:112-117,140-142,149` |
| The purchaser already sees per-transaction status in the sheet table | `SheetDetailView.vue:114-121` (stage/status columns) |

## 3. Authoritative references

- FR-023 `spec.md:796-797` — what submission requires (date, positive amount, description, exactly one cost center, one active expense type, all required evidence).
- FR-024 `spec.md:831` — at least one transaction.
- FR-088 `spec.md` — the sheet's status, its transactions and its Submit control are presented together; this card improves exactly that surface.
- FR-074 `spec.md:1187` — the evidence ceiling, relevant to the hint's wording.

## 4. Allowed scope / non-goals

**In scope**
- A readiness summary on the OPEN sheet: for each transaction, whether it is submit-ready, and if not, which requirement is unmet (missing description, cost center, expense type, or required evidence).
- The Submit control becomes an explicit state rather than a guess: enabled when everything is ready; when not, either disabled with a pointer to the blocking rows, or enabled with a confirmation listing the blockers — the owner picks (see §10). Recommended: keep the button **enabled** and show the blockers, because a disabled button with no visible reason is the accessibility problem this card exists to remove.
- The blockers must be derived from the same requirement list the server enforces, and must not claim to know anything the server will not check (in particular: evidence blocking must not be asserted client-side until `BE-CR-004` makes the server enforce the same rule).
- The list of blocking transactions is keyboard reachable and links to each transaction.

**Non-goals**
- No new endpoint, no new validation rule, no change to the server's preconditions.
- No disabling of the Submit control as the *only* signal.
- No client-side evidence policy invented ahead of `BE-CR-004` (the current mismatch is `BE-CR-004`'s to fix).
- No change to the post-click 422 handling, which must stay as a safety net.

## 5. Implementation behaviour

- Derivation lives in one place (a computed readiness model) so the sheet header, the transaction rows and any hint text agree.
- If the requirement source is the transaction's active revision payload, the completion report must name the fields used; any requirement the payload cannot express (today: required evidence) is shown as "unknown" rather than as "ready".
- Wording: the blockers are statements of fact ("Transaction of 1403/07/12 has no cost center"), not accusations, and each carries the catalogued short label of the missing field.
- Accessibility: the blockers region is announced when it changes after a transaction save, without stealing focus.
- Mobile: the blockers appear in the same full-screen workspace (`UI-PUR-005`), not in a tooltip.

## 6. Acceptance criteria

1. An OPEN sheet whose transaction lacks a cost center shows that transaction as a blocker naming the missing field, and pressing Submit leads to a refusal (or is prevented) with the same explanation — the two must agree.
2. An OPEN sheet whose transactions are all complete and evidence-satisfied shows no blockers and submits successfully.
3. A sheet with zero transactions shows the FR-024 blocker and no Submit affordance (existing behaviour preserved).
4. A submitted sheet shows no readiness summary at all (negative case: the surface is OPEN-only).
5. Evidence: until `BE-CR-004` lands, no transaction is reported as blocked *because of* evidence, and none is reported as evidence-ready — the field's state is shown as unknown. After `BE-CR-004` lands, the client uses the server's answer.
6. The blockers region is announced politely on change and never traps focus.
7. At 390px the blockers are readable and the Submit control remains reachable.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | criteria 1–4, 6 |
| Component | `npx vitest run tests/Frontend/Component/RtlAccessibility.spec.ts` | 390px + direction |
| Contract (PHP, container) | `scripts/dev/phpunit tests/Integration/Workflow/SheetSubmissionTest.php:87-551` | the server's gate is unchanged by this card |
| E2E | `npm run test:e2e` (TO_VERIFY: lab) | incomplete sheet → blockers → fix → submit |
| Gate | `npm run check` | clean |

## 8. Dependencies / rollback

- **Depends on:** `UI-PUR-005` (where the control lives) and `UI-PUR-006` (row rendering). **Blocked by:** `BE-CR-004` for criterion 5's evidence half.
- **Rollback:** remove the readiness model and the region; the existing 422 path remains.

## 9. Definition of Done

- [ ] Readiness model in one place, with its requirement sources named in the completion report.
- [ ] The blockers and the server's refusal agree on every tested case.
- [ ] Explicit statement of what remains unknown until `BE-CR-004`.

## 10. Approval gate

**BLOCKED_OWNER_DECISION.** Two questions for the owner: (1) should Submit be disabled or enabled-with-blockers when the sheet is not ready? (2) Is the evidence half of this card in scope now (`BE-CR-004`), or deferred so the card ships with the "unknown" treatment for evidence?

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Current status:** `READY_FOR_OWNER_APPROVAL` for the server-enforced tier; the evidence criterion is deferred and must stay **advisory** until `BE-CR-004` is approved.
- **Submit behaviour resolved conservatively:** the Submit control stays reachable and the sheet explains precisely what is missing; the server's 422 remains the backstop. This is the accessible option and it misrepresents nothing.
- **Tiering is binding:** only **server-enforced** preconditions (description, cost centre, expense type, at least one transaction) may be presented as blocking. The evidence item must **not** claim the server will refuse — FR-023 is not enforced server-side (`lib/Application/Workflow/SubmitSheet.php:83`).
- **`BE-CR-004` classification:** a spec-compliance change, outside the redesign's scope unless the owner brings it in (`BLOCKED_ITEMS.md` §3.2). When it is approved, promoting the evidence tier is a follow-up card, not part of this one.
