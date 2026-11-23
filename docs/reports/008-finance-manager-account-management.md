# Feature 008 — Finance Manager Account Management: Delivery Report

**Date**: 2026-11-19
**Feature Branch**: `008-finance-manager-account-management`
**Status**: All automated verification complete — awaiting T050 owner manual acceptance

---

## 1. Delivered Scope

The following surfaces are delivered and verified:

| Surface | Description | Read/Write |
|---------|-------------|------------|
| **Finance holder directory** | Searchable, paginated list of users who own ≥1 petty-cash account; shows identity, account count, active-account count, status | Read-only |
| **Holder account inspection** | Per-holder account list: identifier, currency, current balance (owed-aware), status, movement count, last movement timestamp | Read-only |
| **Authoritative account ledger** | Chronological six-column ledger: Date, Description, Approved, Recharge, Expense, Balance; authoritative posting timestamp and resulting balance per row; pagination preserves historical balances | Read-only |
| **Negative-balance owed semantics** | Negative balances presented as "Amount Owed to Petty Cash Holder" (EN) / "طلب تنخواه‌دار" (FA) — never as error, zero, or bare negative | Read-only |
| **Recharge workflow** | Amount entry → review (holder, destination account, currency, amount, current balance, description) → explicit confirmation → server-authoritative success; duplicate-submit prevention; failure/unknown never shown as success | Authorized write (reuses existing charge endpoint) |
| **Completed Cost Sheet history** | List of fully completed sheets only; shows reference, title, purchaser, total/approved/rejected counts, actual posted amount, completion date | Read-only |
| **Completed Cost Sheet detail** | Header + transaction table with reference, description, expense date, amount/currency, Cost Center/Senior/Finance decisions, evidence metadata, posting info; no mutation controls | Read-only |
| **Evidence viewing** | Read-only evidence preview via existing content endpoint; Finance-scoped, completed-history-scoped authorization; no upload/replace/delete controls | Read-only |
| **EN/FA localization** | All user-facing strings localized; English primary, Persian complete | — |
| **RTL/LTR** | Logical CSS properties; `:dir="direction"`; correct Persian RTL layout; Nextcloud shell preserved | — |
| **Accessibility** | Semantic tables, labelled inputs, focus-visible, keyboard operable dialogs (Escape, focus trap, focus restoration), `aria-live` announcements | — |
| **Responsive behavior** | Approved desktop priority and responsive breakpoints; stacked tables retain all column labels/values | — |

---

## 2. API Surface

### Additive Finance Read Endpoints (5)

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/v1/finance/holders` | Holder directory (paginated, searchable) |
| `GET` | `/api/v1/finance/holders/{holderUid}/accounts` | Holder's accounts |
| `GET` | `/api/v1/finance/accounts/{accountId}/ledger` | Unified account ledger (paginated) |
| `GET` | `/api/v1/finance/completed-sheets` | Completed Cost Sheet list (paginated) |
| `GET` | `/api/v1/finance/completed-sheets/{sheetId}` | Completed Cost Sheet detail |

### Evidence Content Authorization (Recorded Condition)

- **Existing endpoint**: `GET /evidence/{evidenceId}/content` — no shape change
- **Recorded authorization condition**: read-only, content-only, Finance-scoped, completed-history-scoped

### Recharge Mutation

- **Reuses existing endpoint**: `POST /accounts/{accountId}/charges`
- **Body**: `AccountCharge` (`amountMinor`, `description`, `occurredAt`, `expectedVersion`)
- **Header**: `X-PettyCash-Command-ID` (idempotency key)
- **No Finance-specific recharge mutation endpoint was added**

### Contract Alignment

- Canonical contract: `specs/001-pettycash-v1-core/contracts/ocs-api.yaml`
- Additive only — no existing operation, schema, purchaser path, or reused charge endpoint changed
- Generated types refreshed: `src/generated/api.ts` (via `scripts/validate-openapi.sh --write`)
- Drift gate: `npm run check:openapi` — green

---

## 3. Verification Summary

All verification layers executed with recorded results:

| Layer | Command | Result | Notes |
|-------|---------|--------|-------|
| **OpenAPI consistency** | `npm run check:openapi` | ✅ Pass | Drift gate green; additive-only diff verified |
| **Backend unit tests** | `scripts/dev/phpunit tests/Unit/Application/Finance` | ✅ Pass | FinanceAccess, CompletedSheetScope, holder status, ledger row formatting |
| **Backend integration tests** | `scripts/dev/phpunit tests/Integration/Finance/FinanceReadQueryTest.php` | ✅ Pass | Directory scope, population-wide search, account listing, ledger integrity/pagination/query budget (≤6 queries) |
| **Backend integration tests** | `scripts/dev/phpunit tests/Integration/Finance/CompletedSheetHistoryTest.php` | ✅ Pass | Completed list/detail terminality, posted amounts, multi-posting preservation |
| **Backend contract tests** | `scripts/dev/phpunit tests/Contract/Controller/FinanceManagementControllerTest.php` | ✅ Pass | All five Finance reads + error semantics (403/404/422/200) |
| **Backend contract tests** | `scripts/dev/phpunit tests/Contract/Controller/EvidenceContentAccessTest.php` | ✅ Pass | 5-case authorization matrix (Finance read, non-Finance denial, no mutation, scope denial, existing behavior unchanged) |
| **Backend contract tests** | `scripts/dev/phpunit tests/Unit/Application/Evidence/EvidenceContentAccessTest.php` | ✅ Pass | Predicate unit scope |
| **Backend contract tests** | `scripts/dev/phpunit tests/Unit/Application/Audit/AuditQueryServiceVisibilityTest.php` | ✅ Pass | Audit/revision evidence visibility unchanged |
| **Regression tests** | `scripts/dev/phpunit` (existing suites) | ✅ Pass | Purchaser ownership, recharge/charge, Finance settlement, Cost Center/Senior Manager, evidence authorization — all unchanged |
| **Frontend component/unit tests** | `npm test -- --run tests/Frontend/Component/Finance` | ✅ Pass | All surfaces; ledger six columns/order, Approved `of`/N/A, single-sided amounts, server balances preserved, owed label both languages, recharge states, completed read-only, evidence read-only |
| **Frontend typecheck** | `npm run typecheck` | ✅ Pass | No type errors |
| **Frontend lint** | `npm run lint` | ✅ Pass | ESLint clean |
| **Frontend stylelint** | `npm run stylelint` | ✅ Pass | Stylelint clean |
| **Localization parity** | `tests/Frontend/l10nCatalog.spec.ts` + EN/FA key-parity gate | ✅ Pass | Every Feature 008 key in en.json/en.js/fa.json/fa.js; owed label pair, not-applicable marker verified |
| **Accessibility checks** | `tests/Frontend/Component/Finance/` assertions | ✅ Pass | Keyboard, focus trap/restoration, accessible names/roles/labels, `aria-live`, focus-visible |
| **Chrome E2E** | `npx playwright test tests/E2E/finance-manager.spec.ts --project=chrome-desktop` | ✅ Pass | **Run 1: 27/27 passed** |
| **Chrome E2E (repeat)** | `npx playwright test tests/E2E/finance-manager.spec.ts --project=chrome-desktop` | ✅ Pass | **Run 2: 27/27 passed** |
| **English visual evidence** | `npx playwright test tests/E2E/capture-visual-evidence.spec.ts --project=chrome-desktop` (T047 subset) | ✅ Captured | 10 screenshots under `artifacts/visual-review/T047/final/` |
| **Persian/RTL visual evidence** | `npx playwright test tests/E2E/capture-visual-evidence.spec.ts --project=chrome-desktop` (T048 subset) | ✅ Captured | 7 screenshots under `artifacts/visual-review/T048/final/` |

### Key E2E Evidence

```
Run 1: 27/27 passed
Run 2: 27/27 passed
```

### Visual Evidence Paths

**T047 — English (10 screenshots)**:
- `artifacts/visual-review/T047/final/01-holder-directory.png`
- `artifacts/visual-review/T047/final/02-holder-directory-search.png`
- `artifacts/visual-review/T047/final/03-holder-accounts.png`
- `artifacts/visual-review/T047/final/04-account-ledger.png`
- `artifacts/visual-review/T047/final/05-recharge-amount-entry.png`
- `artifacts/visual-review/T047/final/06-recharge-review.png`
- `artifacts/visual-review/T047/final/07-recharge-success.png`
- `artifacts/visual-review/T047/final/08-completed-sheets-list.png`
- `artifacts/visual-review/T047/final/09-completed-sheet-detail.png`
- `artifacts/visual-review/T047/final/10-evidence-preview.png`

**T048 — Persian/RTL (7 screenshots)**:
- `artifacts/visual-review/T048/final/01-holder-directory-fa.png`
- `artifacts/visual-review/T048/final/02-holder-accounts-fa.png`
- `artifacts/visual-review/T048/final/03-ledger-fa.png`
- `artifacts/visual-review/T048/final/04-recharge-dialog-fa.png`
- `artifacts/visual-review/T048/final/05-completed-sheets-list-fa.png`
- `artifacts/visual-review/T048/final/06-completed-sheet-detail-fa.png`
- `artifacts/visual-review/T048/final/07-evidence-preview-fa.png`

### Negative Balance

**Not obtainable from current accepted runtime data** — no negative-balance account or ledger row exists in the E2E fixture set. Both active account (3,134 IRR) and inactive account (0.00 USD) have non-negative balances; all 21 ledger rows show positive balances.

---

## 4. Recovered Defects

The following significant defects were discovered and resolved during convergence:

| Defect | Description | Resolution |
|--------|-------------|------------|
| **Invalid CONSUMPTION fixture amount** | Seeded consumption movement used incorrect minor-unit amount | Corrected to match authoritative revision amount |
| **Missing CONSUMPTION provenance** | Ledger consumption rows lacked valid `source_revision_id` linkage | Seeded proper revision chain: transaction → revision → movement |
| **Invalid E2E revision/status fixture values** | Fixtures used non-terminal status values (`APPROVED` instead of `COMPLETED_APPROVED`) | Corrected to terminal lifecycle states per domain rules |
| **Completed-detail close route** | Closing completed detail navigated to holder route instead of completed list | Fixed navigation to return to `/finance/completed-sheets` |
| **Holder Directory `v-model` defect** | Search input binding was one-way; search term not sent to server | Fixed `v-model` binding and server-side search integration |
| **Completed-history evidence transaction-ID mismatch** | Evidence metadata referenced wrong transaction ID | Aligned evidence `transaction_id` with completed sheet's terminal transaction |
| **Missing seeded evidence appdata content** | Evidence metadata row existed but `appdata_key` pointed to non-existent file | Seeded actual PDF bytes via `PrivateFileStore::putEvidence`; content endpoint now returns 200 |

---

## 5. Unrelated Baseline Issues

**Correction/Resubmission RED behavior**: The existing correction and resubmission workflows retain their pre-Feature 008 behavior (including any RED-state presentation). This is **unrelated to Feature 008** and is not represented as a Feature 008 regression. Feature 008 delivers read-only completed history and recharge only; it does not modify decision semantics, correction flows, or resubmission logic.

---

## 6. Remaining Gate

```
T050 — Owner manual visual acceptance — pending
```

Owner approval is a separate manual gate. No automated check, test pass, or screenshot completes T050. Work stops here pending recorded owner approval with gate identifier, date, and authorized scope.

---

## 7. Task Ledger — Final State

```
T001  [x]  T013  [x]  T025  [x]  T037  [x]  T049  [x]
T002  [x]  T014  [x]  T026  [x]  T038  [x]  T050  [ ]
T003  [x]  T015  [x]  T027  [x]  T039  [x]
T004  [x]  T016  [x]  T028  [x]  T040  [x]
T005  [x]  T017  [x]  T029  [x]  T041  [x]
T006  [x]  T018  [x]  T030  [x]  T042  [x]
T007  [x]  T019  [x]  T031  [x]  T043  [x]
T008  [x]  T020  [x]  T032  [x]  T044  [x]
T009  [x]  T021  [x]  T033  [x]  T045  [x]
T010  [x]  T022  [x]  T034  [x]  T046  [x]
T011  [x]  T023  [x]  T035  [x]  T047  [x]
T012  [x]  T024  [x]  T036  [x]  T048  [x]
```

**Automated tasks complete**: T001–T049 ✅
**Owner gate**: T050 ⏳

---

## 8. Code Intelligence Evidence

```
Code intelligence: OCI=not needed; CGC=not needed; Serena=not needed; source=verified paths; tests=executed checks
```

- All verification used direct source reading, targeted test execution, and live Chrome E2E runs
- No semantic/graph/symbol discovery was required for this final evidence capture and reporting phase

---

## 9. Files Changed (This Session)

| File | Purpose |
|------|---------|
| `tests/E2E/capture-visual-evidence.spec.ts` | Visual evidence capture script (temporary test file) |
| `artifacts/visual-review/T047/final/*.png` | 10 English screenshots |
| `artifacts/visual-review/T048/final/*.png` | 7 Persian/RTL screenshots |
| `docs/reports/008-finance-manager-account-management.md` | This delivery report |

**No production code modified**. No backend PHP, OpenAPI, generated API, schema/migrations, Vue production behavior, store/API logic, routes, or localization strings were changed.

---

## 10. Git State

```
$ git status --short
 M tests/E2E/capture-visual-evidence.spec.ts
?? artifacts/visual-review/T047/final/
?? artifacts/visual-review/T048/final/
?? docs/reports/008-finance-manager-account-management.md
```

**Confirmed**:
- ✅ No T050 completion
- ✅ No production behavior changes
- ✅ No stage/commit/push/reset/clean/deploy
- ✅ Only test artifact and evidence files added
- ✅ Pre-existing working-tree changes preserved