# Quickstart: Purchaser UI Redesign

**Feature**: `005-purchaser-ui-redesign` | **Date**: 2026-10-19

This quickstart validates the **management baseline**, the **Batch A working-tree implementation** on demand, and the owner-approved **2026-10-23 receipt/creation slice** once implemented. It does not authorize additional Batch B work, any `BE-CR` other than `BE-CR-004`, or any Git write.

---

## 1. Automated validation — management baseline (host, ~1 minute)

The management artifacts are documents plus one pointer file. This planning pass changes no application source; the approved slice is future work tracked by `T053`–`T063` and 001 `T431`–`T435`.

```bash
# 1. Confirm the feature pointer resolves
cat .specify/feature.json          # must be {"feature_directory":"specs/005-purchaser-ui-redesign"}

# 2. Confirm every artifact exists
ls specs/005-purchaser-ui-redesign/
#   spec.md plan.md research.md data-model.md quickstart.md tasks.md
#   contracts/purchaser-ui-contract.md checklists/requirements.md

# 3. Confirm no application source changed in the migration
git status --short | grep -E '^( M|A |\?\?) (lib|appinfo)/' # expected: no output
git status --short | grep -c '\.php$'                        # expected: 0
```

Expected: the pointer resolves, all eight artifacts exist, no PHP is touched.

---

## 2. Automated validation — Batch A surface (host, targeted)

Run only when the Batch A surface itself needs re-verification (e.g. before a commit of it, or after touching `src/`). Do **not** run this to re-prove the management migration.

```bash
npx vitest run \
  tests/Frontend/localeFormatting.spec.ts \
  tests/Frontend/Component/PurchaserFlow.spec.ts \
  tests/Frontend/Component/SheetDeletion.spec.ts \
  tests/Frontend/Component/RtlAccessibility.spec.ts
```

Expected (verified 2026-10-19): **4 files / 168 tests passed** —
`PurchaserFlow` 137, `localeFormatting` 13, `SheetDeletion` 9, `RtlAccessibility` 9.

Supporting guards, cheap to run together:

```bash
npx vitest run \
  tests/Frontend/l10nCatalog.spec.ts \
  tests/Frontend/Component/NextcloudVueContract.spec.ts \
  tests/Frontend/fr071NarrowViewport.spec.ts \
  tests/Frontend/routeNameIntegrity.spec.ts \
  tests/Frontend/keyboard-accessibility.spec.ts
```

### Batch-boundary gate (do **not** run per edit)

```bash
npm run check
```

`npm run check` is portability + typecheck + lint + stylelint + vitest + build. Run it at a batch/milestone boundary, not after every local edit. The Batch A report records it green on its final tree (23 files / 481 tests); this management pass did **not** re-run it, and its result is quoted only as report evidence.

The management baseline does not touch PHP. The approved slice does, so PHP verification is **container-only** and is required for `T431`–`T435` and `T060`:

```bash
scripts/dev/phpunit <test>     # never host PHP
scripts/dev/opcache-settle     # before any live-HTTP probe after lib/appinfo edits
scripts/dev/route-settle       # only if appinfo/routes.php changes; T060 should not need it
```

Targeted future specs for the slice:

```bash
npx vitest run \
  tests/Frontend/Component/PurchaserFlow.spec.ts \
  tests/Frontend/Component/SheetWorkspace.spec.ts \
  tests/Frontend/Component/TransactionCorrection.spec.ts
scripts/dev/phpunit tests/Unit/Domain/Expense/SheetSubmissionPolicyTest.php
scripts/dev/phpunit tests/Integration/Workflow/SheetSubmissionTest.php
scripts/dev/phpunit tests/Contract/Controller/CorrectionControllerTest.php
scripts/dev/phpunit tests/Contract/Command/DevSeedSheetsCommandTest.php
```

---

## 3. Manual / browser validation (lab required)

Prerequisites: the pettycash lab is running (`/home/ubuntu/projects/nextcloud-lab/bin/lab pettycash up`, `http://localhost:8081`), and a purchaser account exists. Browser evidence is **technical evidence, not product-owner acceptance**.

1. **Locale + calendar matrix** — sign in as a purchaser; render the sheet list and a transaction form under `fa`, then `en`:
   - `fa` → RTL, Jalali by default, Persian digits;
   - `en` → LTR, Gregorian by default, Latin digits;
   - a calendar preference change re-renders dates with no reload and no request.
2. **Settings route and calendar control** — open the personal Settings destination (desktop gear entry in the lower navigation section; mobile integrated into the existing navigation model):
   - Verify the Settings route loads for an authenticated purchaser.
   - Select Gregorian, then Persian; each change applies reactively with no reload and no network request.
   - Verify the current selection reflects the existing `pettycash.calendarPreference` value.
   - Verify no Pettycash language selector, profile management, or unrelated setting is present.
3. **Four language/calendar combinations** — repeat the date re-render check under English UI + Gregorian, English UI + Persian, Persian UI + Gregorian, and Persian UI + Persian calendar. Calendar choice is independent of Nextcloud UI language.
4. **Representative review dates** — confirm management-review date surfaces consume the shared calendar-aware formatter under each combination.
5. **Calendar-aware expense-date input (`T081–T084`, approved dependency exception)** — open the transaction create form under Gregorian preference and verify the expense-date input shows a Gregorian calendar; switch to Persian preference and verify the same input shows a genuine Jalali calendar with correct Jalali year/month/day navigation (not translated Gregorian month labels). Repeat under `en` UI + Persian calendar and `fa` UI + Gregorian calendar to confirm language ⊥ calendar independence. Select a date under each calendar and confirm the outgoing mutation payload always carries canonical Gregorian `YYYY-MM-DD`. Confirm no date is silently changed when opening, closing, initializing, or switching calendars. Verify disabled/read-only states, validation, and keyboard accessibility. Light/dark themes and no horizontal scroll at 390px are checked in official Google Chrome (`channel: 'chrome'`) at desktop and 390px mobile.

   - **Not executable before `T082` implementation; this step is recorded as future work, not a current gate.**

   **Transaction CREATE modal (`T085`–`T088`, 2026-11-24 owner authorization)** — from any sheet detail, click "Add transaction" and verify a modal opens over the mounted sheet workspace (the workspace is NOT unmounted or replaced):
    - The modal matches the existing transaction EDIT modal's visual conventions; heading is the localized "Add transaction" (`transactions.createTitle`).
    - Existing form fields and validation (expense date + positive amount minimum) are preserved; calendar-aware expense-date picker works under Gregorian and Jalali preferences.
    - Cancel and Save draft actions are present; no EDIT-only controls (Delete draft, history, transaction ID) appear before a transaction exists.
    - Pristine Cancel closes without confirmation; dirty Cancel → Stay preserves entered data with zero writes; dirty Cancel → Discard performs no writes and creates no transaction.
    - Save draft creates exactly one transaction; on successful save the modal closes, the owning sheet detail returns, sheet and transaction data are refreshed, the new transaction is displayed, and counts/amounts refresh (FR-088 preserved).
    - A forced staged-receipt upload failure leaves the transaction saved and offers retry without duplicating the transaction.
    - Direct navigation to `/sheets/:sheetId/transactions/create`, browser Back/Forward, and refresh all preserve the owning sheet identity and reopen/open the modal correctly.
    - Modal accessibility: exactly one `[role="dialog"]`, inert background, focus containment, focus restoration after dismissal, Escape handling for nested calendar controls, LTR/RTL compatibility, no horizontal scroll at 390px.
    - Record screenshots under `artifacts/visual-review/<task-id>/<iteration>/`; owner-controlled desktop visual acceptance is explicit; mobile visual review remains deferred.

    - **Verified 2026-11-25**: all twelve journeys pass in official Google Chrome (`channel: 'chrome'`) at en-desktop, fa-desktop (RTL), and en-mobile (390px). 49 checks per configuration, 0 failures. Screenshots under `artifacts/visual-review/t088/iteration-1/`. Network evidence recorded in `artifacts/visual-review/t088/iteration-1/*/evidence.json`.
    - **Owner visual acceptance (desktop)**: ACCEPTED 2026-11-25. Product owner explicit statement: "تایید هست". CREATE modal presentation and interaction accepted. Phase 19 implementation and desktop visual acceptance: COMPLETE.
    - **Deferred**: additional owner mobile visual review remains deferred. `UXC-V2-GATE` remains OPEN. Sheet total refresh was not explicitly documented in the browser report; no verification evidence is claimed for it.

5. **Mutation-payload equality (`T018`)** — submit the same transaction form under Gregorian and Persian preferences and diff the request payloads; they must be identical. Calendar selection changes display only and must not alter persisted dates or mutation payloads.
2. **Sheet list** — six columns in the accepted order, title above the ID, `—` for a never-submitted sheet, `0` for a genuine zero, `0` transactions for an empty sheet, one accessible action menu per row; no horizontal scroll at 390px.
   - **Outstanding**: English labels (blocked by `REC-01` — raw keys will render); the date-filter labels do not yet name the creation date (`REC-06`); owner visual acceptance.
3. **Row actions** — an `OPEN` row offers View/Edit/Delete; a submitted row offers View only; Delete opens a confirmation that names the sheet; **cancel issues no request**.
4. **List context** — open a sheet from page N with a filter, then browser-Back: page N, filter, and scroll are restored and the list refetches; a fresh visit resets to page 1; a delete on the last page clamps.
5. **Receipt/creation slice** (after `T053`–`T060`; not executable before then): seed the four debug-gated sheets with `scripts/dev/seed-test-sheets` (25/30/35/40 transactions), then verify:
   - create with only expense date + positive amount; save succeeds without a receipt;
   - create with description, cost center, expense type, and receipts selected before the first save; the transaction is created, then each receipt is uploaded with a non-empty ID; a forced upload failure leaves the transaction saved and names the failed file;
   - readiness names a transaction whose expense type requires a receipt and has none; a receipt-exempt transaction is not blocked;
   - submit is refused with a `422` naming every missing receipt; after attaching an active receipt, submission succeeds;
   - correction resubmission is refused for a receipt-required type without a receipt and succeeds for a receipt-exempt type;
   - repeat in `fa`/RTL and `en`/LTR at desktop and 390px mobile; record screenshots and console output.
6. **Backend-blocked journeys outside the approved slice** (must fail today, by design): no OPEN-first ordering across pages; no inline title edit (the sheet's source account and currency are immutable and displayed read-only); no per-stage counts. Do not "fix" any of these client-side. `BE-CR-004` is no longer in this list; it is approved but not implemented.

---

## 4. Explicit prohibitions for any follow-up pass

- No Git write (`add`, `commit`, `push`, `merge`, `rebase`, `reset`, `clean`, branch/tag/config) without an approved `GIT-PLAN-*`.
- No Batch B card started without per-card owner approval, except the approved `T053`–`T063` slice.
- No edit to `lib/`, `appinfo/`, `specs/001-*`, the OpenAPI contract, or the workflow contract outside the 2026-10-23 `BE-CR-004` allowlist (receipt enforcement, `evidenceRequired`, per-transaction `422` field errors, and the debug-gated fixture command).
- No English catalog authored (`REC-01` is an owner decision, not an agent task).
- No new dependency, no Vuetify, no UI framework change, except the narrowly scoped FR-005-029 exception permitting `@jalali-js/vue@0.4.3` (exact pinned version) and its necessary reviewed transitive dependencies solely for the transaction expense-date calendar picker wrapper (`src/components/common/CalendarDatePicker.vue`), used exclusively in CREATE and EDIT flows.
- No host PHP, no manual `docker exec`, no container-side frontend tooling.
- No staging outside an approved `GIT-PLAN-*` allowlist. `GIT-PLAN-001-R1` isolates generated `js/`/`css/` output in its build-sync commit (C6), includes only inspected design references and the bounded Manus prototype subset, and excludes `_archives/`, `review/bunny/screenshots/`, `test-results/`, `.env.testing`, and pre-existing user changes (`AGENTS.md`, `CLAUDE.md`, `.claude/`, `.env*`, `docs/Review uploaded pasted content.zip`, Feature 004 files).

---

## 5. How to read completion status

| Claim | Requires |
|---|---|
| `DONE_VERIFIED` | Re-executed tests **or** recorded browser evidence naming the surface and viewport |
| aggregate green | An executed `npm run check` with its own file/test counts |
| product-owner acceptance | An explicit owner statement — never inferred from a passing test |
| blocked | The named `BE-CR` approval **and** its open answers, except `BE-CR-004`, which is approved but not implemented |
| receipt slice verified | Targeted Vitest + container PHPUnit + lab browser evidence in desktop/mobile and RTL/LTR; frontend-only tests are not server-enforcement proof |

A passing test from the wrong runtime, a report-recorded result quoted as re-executed, or a "technically verified" card reported as "visually accepted" are all completion-claim violations (`FR-005-034`, `SC-005-008`, `SC-005-009`).
