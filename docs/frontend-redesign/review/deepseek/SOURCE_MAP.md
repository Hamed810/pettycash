# SOURCE_MAP — Pettycash purchaser frontend: authority, architecture and evidence map

**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe` (merge PR #9 from `docs/frontend-redesign-status`)
**Recorded:** 2026-10-19 · **Git root:** `/home/ubuntu/projects/pettycash`
**Worktree at audit start (pre-existing, NOT produced by this audit):** `M AGENTS.md`; untracked `.claude/`, `CLAUDE.md`, `docs/Review uploaded pasted content.zip`, `docs/frontend-redesign/{MANUS_INTAKE_MANIFEST.md,README_MANUS_INTAKE.md,_archives/,handoff/,prototype/,references/,review/}`.

---

## 1. Scope of this audit

Purchaser-facing UI/UX of Pettycash in Nextcloud, audited against:

1. **Spec Kit** — `specs/001-pettycash-v1-core/spec.md` (88 requirement ids `FR-001…FR-086`), `data-model.md`, `plan.md`, `contracts/ocs-api.yaml` (1796 lines), `contracts/workflow.md`, `contracts/exports.md`.
2. **Actual implementation at HEAD** — `lib/` (PHP, hexagonal) and `src/` (Vue 3 + Pinia).
3. **Product-owner-accepted visual direction** — `docs/frontend-redesign/references/` (desktop references + Unsaved Changes dialog) and the Manus prototype `docs/frontend-redesign/prototype/manus-purchaser-v1/`.
4. **Committed governance** — `docs/frontend-redesign/{CHARTER,DECISIONS,ROADMAP,STATUS}.md` (D-001…D-015 accepted) plus the Manus handoff decisions (`handoff/design-notes/DECISION_ADDENDUM_DRAFT.md`, `U-01…U-18`, **not yet merged into the repository decision log**).

Out of scope: manager/finance/admin queue redesign, business-feature additions, backend refactors, Persian mockup generation.

---

## 2. Runtime architecture (verified)

### Backend — `lib/` (Nextcloud app, PHP 8, no `illuminate/*`)

| Layer | Path | Contents |
|---|---|---|
| App info | `lib/AppInfo/Application.php` | DI container, route registration |
| Presentation | `lib/Controller/` | 13 controllers: Account, Audit, Configuration, Dashboard, Evidence, Export, Page, PublicEvidence, Report, Review, Session, **Sheet**, **Transaction** |
| Application | `lib/Application/{Account,Audit,Command,Configuration,DataIntegrity,Evidence,Expense,Port,Reporting,Security,Support,Workflow}` | use-cases/services |
| Domain | `lib/Domain/{Account,Audit,Configuration,Evidence,Expense,Shared,Workflow}` | entities, enums (`Domain/Shared/Enum`), invariants; no Nextcloud dependency (ADR-003) |
| Infrastructure | `lib/Infrastructure/{Export,Http,Nextcloud,Persistence}` | DB repositories (`Persistence/*`), payload formatters (`Http/`), exporters |
| Migrations | `lib/Migration/` | schema |

### Frontend — `src/` (Vue 3.5.25, Pinia 3.0.4, vue-router 4.6.3, `@nextcloud/vue` 9.12.0)

- `src/AppRoot.vue` — shell (`<RouterView />`, no `KeepAlive`, no `scrollBehavior`).
- `src/router/index.ts` — 24 routes (listing in §4).
- `src/stores/` — 10 Pinia stores (`sheets`, `transactions`, `reviews`, `audit`, `accounts`, `configuration`, `dashboard`, `reports`, `auth`).
- `src/services/` — 11 API clients over `@nextcloud/axios`.
- `src/views/` — 20 views incl. `sheets/{SheetListView,SheetDetailView,SheetFormView}.vue`, `transactions/{TransactionListView,TransactionFormView,TransactionDetailView}.vue`.
- `src/components/` — 27 SFCs (audit, dashboard, evidence, navigation, reports, review, shared).
- `src/generated/api.ts` — generated from `contracts/ocs-api.yaml` via `openapi-typescript`; `scripts/validate-openapi.sh` gates drift (`npm run check:openapi`).
- `src/styles/` — `app.scss`, `_responsive.scss` (640px cards / 480px tight, 390px floor per FR-071), `_rtl.scss` (logical properties + bidi isolation).
- `src/utils/` — `date.ts`, `money.ts`, `dashboard.ts`, `selectOption.ts`.

### Tooling already present (not executed in this audit)

`npm run check` → openapi → portability → error-rate → typecheck → lint → stylelint → `vitest run` → `vite build` → notices.
`npm run test:e2e` → Playwright (`pretest:e2e` settles the opcode cache).
PHP tests: `scripts/dev/phpunit` (container-only per `AGENTS.md`).
Existing suites: `tests/Frontend/Component/*.spec.ts` (14 files), `tests/Frontend/*.spec.ts` (l10n, RTL, FR-071, unrendered-column), `tests/E2E/*.spec.ts` (12 flows), `tests/Contract/Controller/*Test.php` (~35 contract tests), `tests/Integration/*`, `tests/Unit/*`.

---

## 3. API surface (OCS, from `appinfo/routes.php`)

| Area | Routes |
|---|---|
| Session / dashboard | `GET /api/v1/me`, `GET /api/v1/dashboard` |
| Sheets | `POST /api/v1/sheets`, `GET /api/v1/sheets`, `GET/PATCH/DELETE /api/v1/sheets/{sheetId}`, `POST /api/v1/sheets/{sheetId}/submit` |
| Transactions | `POST /api/v1/sheets/{sheetId}/transactions`, `GET/PATCH/DELETE /api/v1/transactions/{transactionId}`, `POST …/correction`, `POST …/resubmit` |
| History | `GET /api/v1/transactions/{transactionId}/revisions`, `GET /api/v1/transactions/{transactionId}/audit` |
| Evidence | `POST /api/v1/transactions/{transactionId}/evidence`, `DELETE /api/v1/evidence/{evidenceId}`, `GET /api/v1/evidence/{evidenceId}/content`, `POST /api/v1/evidence/{evidenceId}/links`, `POST /api/v1/evidence-links/{linkId}/deactivate`, `GET /api/v1/public/evidence/{token}` |
| Review | `GET /api/v1/queues/{stage}`, `GET /api/v1/returns`, `POST /api/v1/transactions/{transactionId}/decisions` |
| Configuration | currencies, cost-centers, expense-types (+ `/{id}` PATCH), `GET/POST /api/v1/configuration/finance-assignments` |
| Accounts | `GET/POST /api/v1/accounts`, `PATCH /api/v1/accounts/{accountId}`, `POST /api/v1/accounts/{accountId}/charges`, `GET /api/v1/accounts/{accountId}/movements` |
| Reports / export | `GET /api/v1/reports/{reportType}`, `POST /api/v1/exports` |

**Contract gap observed:** `GET /sheets` accepts only `status, ownerId, currency, from, to, page, pageSize` (`contracts/ocs-api.yaml:364-381`). There is **no `sort` parameter and no ordering clause anywhere in the contract** — `grep -niE "sort|order by|ordering"` over the OpenAPI document returns nothing.

---

## 4. Route map (frontend, `src/router/index.ts`)

`/` dashboard · `/sheets` sheet-list · `/sheets/create` · `/sheets/:id` detail · `/sheets/:id/edit` · `/sheets/:sheetId/transactions` · `/sheets/:sheetId/transactions/create` · `/transactions/:id` · `/transactions/:id/edit` · `/audit/:transactionId` · `/reviews/{cost-center,senior,finance,returns}` · `/reviews/:stage/:transactionId` · `/reviews/finance/:transactionId` · `/accounts` · `/reports` · `/admin/configuration` · `/admin/funding` · `/:pathMatch(.*)*`.

Purchaser-relevant subset: `/sheets`, `/sheets/create`, `/sheets/:id`, `/sheets/:id/edit`, `/sheets/:sheetId/transactions*`, `/transactions/:id*`, `/audit/:transactionId`.

---

## 5. Evidence-based requirement → design → implementation map

| Area | Spec / authority | Implementation (verified paths) | Design / prototype reference |
|---|---|---|---|
| Sheets list columns | FR-063 `spec.md:1049-1057` (identity, owner, currency, status, count, key dates, current/submitted totals, latest active revision); FR-062 `spec.md:1046-1048` (headline + 3 stage-approved columns) | `src/views/sheets/SheetListView.vue:140-158` (11 columns), cells `:266-326` | `references/desktop/01_sheets_list_desktop_EN_approved.png`; prototype `app.js:200` (6 columns) |
| Sheets list totals | FR-062; `data-model.md:203,214-218` | `lib/Infrastructure/Http/SheetPayloadFormatter.php:246-260`; `lib/Infrastructure/Persistence/Expense/SheetSummaryQuery.php:45-52,173-227`; `lib/Domain/Expense/SheetTotals.php:40-44` | prototype `app.js:139-144` (float sums) |
| Sheets list filters | FR-064 `spec.md:1058-1076` | server `lib/Application/Expense/SheetListFilterFactory.php:59-71,148`; SQL `DbExpenseSheetRepository.php:104-134`; UI `SheetListView.vue:458-501` | prototype `app.js:193-198` |
| Sheets list pagination | `Page`/`PageSize` params + `SheetPage` `ocs-api.yaml:983-995,1123-1131,1162-1166` | `lib/Application/Expense/SheetService.php:141-149`; `SheetController.php:112-117`; `src/stores/sheets.ts:11,24,31` | prototype `app.js:184-186,202` (client-side 5/page) |
| Sheets list ordering | **NOT_FOUND in spec and contract** | `DbExpenseSheetRepository.php:58` — `orderBy('created_at','DESC')` only | Manus contract `handoff/manus/01_PROJECT_CONTRACT_EN.md` (OPEN-first, both directions, server-wide); addendum `U-05` |
| Open-sheet deletion | FR-086 `spec.md:885` | route `appinfo/routes.php` (`sheet#destroy`); UI `src/components/shared/DeleteSheetDialog.vue`; tests `tests/Frontend/Component/SheetDeletion.spec.ts` | desktop reference 01 (3-dot overflow); prototype `app.js:308,323` |
| Sheet metadata edit while OPEN | Sheet `update` route; `SheetFormView.vue` | | prototype `app.js:232-233` (inline edit, OPEN only) |
| Sheet submit | FR-023/024 `spec.md:796-831`; `routes.php` `sheet#submit` | `src/stores/sheets.ts:14` `canSubmit` | prototype `app.js:307` |
| Transaction fields | FR-021/022/028/029 `spec.md:792-852` | `TransactionController`, `TransactionFormView.vue` | prototype `app.js:55-57,244` |
| Cost-center-per-transaction default | Addendum `U-11` (new frontend behaviour, owner-approved in conversation) | — see AUDIT_REPORT area D | prototype `app.js:282` (`lastCreatedTransactionId`) |
| Evidence 100 MB ceiling | FR-074 `spec.md:1187` | — see AUDIT_REPORT area E | prototype disclaims any size/MIME policy (`KNOWN_GAPS.md:7`) |
| Decisions / stages | FR-032…FR-049 `spec.md:915-1017`; `contracts/workflow.md` | — see AUDIT_REPORT areas F/G | prototype `app.js:45-48,145-150` |
| Per-transaction history (no sheet-wide audit) | FR-051/052 `spec.md:992-998`; explicit non-goal note `spec.md:240` | `audit#revisions`, `audit#timeline` routes; `src/components/audit/*` | prototype History tab is a client-side merge, disclaimed `app.js:226` |
| Unsaved-changes guard | Owner-accepted (`references/dialogs/unsaved-changes-en-desktop-v1.png`); `handoff/manus/01_PROJECT_CONTRACT_EN.md` | — see AUDIT_REPORT area I | prototype `app.js:275-279,301-305,354-355` |
| RTL / 390px floor | FR-071 `spec.md:1156`; D-006 | `src/styles/_rtl.scss`, `_responsive.scss`; `dir="rtl"` hardcoded in 20 views | prototype `styles.css:224-266` (`dir="ltr"`, EN only) |

---

## 6. Governance map (and where it contradicts itself)

| Source | Role | Current weight |
|---|---|---|
| `docs/frontend-redesign/CHARTER.md` | v1.0 charter, roles, acceptance policy | authoritative |
| `docs/frontend-redesign/DECISIONS.md` | D-001…D-015 **Accepted**; P-001…P-004 open | authoritative; change requires the append-only procedure in `DECISIONS.md:38-42` |
| `docs/frontend-redesign/ROADMAP.md` | F0–F6 phases; F1.1 done, **F1.2 next**; implementation not authorized | authoritative |
| `docs/frontend-redesign/STATUS.md` | state as of 2026-10-11 | **stale** relative to the Manus work (see below) |
| `handoff/governance/manus-handoff-snapshot/*` | older governance copies shipped in the Manus package | historical only; `04_DESIGN_INVENTORY_AND_MISSING_ASSETS.md` states it "does NOT overwrite repository governance" |
| `handoff/design-notes/DECISION_ADDENDUM_DRAFT.md` | `U-01…U-18` conversation decisions, explicitly "**not yet written into Git**" | unmerged proposal |
| `references/DESIGN_REFERENCES.md` | per-image approval registry the Manus package used | **internally stale** — see §7 |

Explicit contradictions to surface (not to resolve unilaterally):

1. **D-006 (FA+EN from day one, RTL/LTR per Nextcloud)** vs the Manus sequencing "English LTR only for now" (`handoff/manus/01_PROJECT_CONTRACT_EN.md`), ratified only in the unmerged addendum `U-02`. Repository reality today: **Persian only** (`l10n/fa.*`), `dir="rtl"` hardcoded.
2. **`STATUS.md` (2026-10-11)**: "design not started, no prototype" vs the actual Manus deliverables (11 desktop references, mobile references, v1.0.0 prototype) — the repository status file was never updated after the Manus phase.
3. **`references/DESIGN_REFERENCES.md`** lists Unsaved Changes V3 and all 390px mobile views as "**Not yet approved**" while `handoff/manus/00_START_HERE_FA.md` and `01_PROJECT_CONTRACT_EN.md` call Unsaved Changes **approved** and the desktop set accepted. Unresolved; treated as an owner decision (§`DECISIONS_AND_BLOCKERS.md`).
4. **FR-062** mandates three separate stage-approved columns on sheet summaries; the Manus redesign lowers the list to six columns (Recorded + Latest Approved + First Submitted). Whether the stage columns remain is an owner decision — the six-column direction is **not** a ratified repository decision.

---

## 7. Prototype map (Manus v1.0.0)

`docs/frontend-redesign/prototype/manus-purchaser-v1/` — vanilla HTML/CSS/JS + zero-dependency Node static server (`server.js`), localStorage persistence key `pettycash-purchaser-prototype-v1` (`app.js:1`). Full inventory in `AUDIT_REPORT.md` §L and the workstream record. Headline facts used by this audit:

- Screens: list (`app.js:182-204`), sheet workspace modal with Overview/Approvals/History (`:209-234`), transaction add/edit-draft/correction (`:236-244`), read-only transaction + history (`:246-254`), confirm dialog (`:256-259`), toasts (`:132-134`).
- Domain model is **compatible** with the domain rules: Cost Center per transaction (`:56`), Expense Type (no "Category"), no Vendor/Notes, categorical decisions only, no partial approval, no bulk approval, Add-Transaction blocked after submit, no global Save, no sheet-wide audit endpoint.
- Compared with the real app it is a **simplification**, not an authority: floats instead of integer minor units (`:34,60-64`), fabricated Jalali↔Gregorian pairs (`:37-43,290,293`), `returned` folded into `pending` in stage summaries (`:148-150`), no server pagination, no permission model, no focus trap, no focus return.
- Known prototype defects (UI copy/state): confirm dialog for delete still offers "Discard changes" (`:258` vs deletion branch `:323`); delete copy claims "reversible" although deletion persists (`:308` vs `:323`); Escape does nothing while the confirm dialog is open (`:351`); transaction rows are focusable without a role (`:214`).
- `state.confirm` is used at `:257` but never initialised in the state literal (`:115-122`).

---

## 8. NOT_FOUND register (searched, absent)

| Searched for | Searched in | Result |
|---|---|---|
| Server-wide OPEN-first ordering, asc/desc, across pagination | `specs/**` (`sort`, `order`, `open first`, `first submitted`), `contracts/ocs-api.yaml`, `src/`, `lib/` | **NOT_FOUND** — exists only in the Manus handoff contract/addendum |
| `sort` query parameter on `GET /sheets` | `contracts/ocs-api.yaml:364-381` | **NOT_FOUND** |
| Search/free-text filter on the sheets list | `SheetListView.vue`, `sheetsApi.ts`, `SheetController.php`, FR-064 | **NOT_FOUND** |
| Sheet-detail **modal** over the list | `src/views/sheets/*`, `src/router/index.ts`, `AppRoot.vue` (`KeepAlive`/`scrollBehavior`) | **NOT_FOUND** — navigation replaces the list; page resets to 1 |
| Global Gregorian/Jalali display preference for the app | `src/`, `lib/` (`jalali`, `calendar`, `preference`) | **NOT_FOUND** — Jalali exists only as a server-side reporting-period resolver (FR-068) |
| English locale catalog | `l10n/` | **NOT_FOUND** — `fa.js`, `fa.json` only |
| Sheet-wide audit/history endpoint | `appinfo/routes.php`, `contracts/ocs-api.yaml` | **NOT_FOUND** — per-transaction only (matches the deliberate non-goal at `spec.md:240`) |
| Dynamic user-language `dir` | `src/views/**` | **NOT_FOUND** — `dir="rtl"` hardcoded in 20 views |

---

*Companion documents: `AUDIT_REPORT.md`, `GAP_MATRIX.md`, `PROPOSED_TASK_BACKLOG.md`, `EXECUTION_PLAN.md`, `DECISIONS_AND_BLOCKERS.md`, `FINAL_HANDOFF.md`.*
