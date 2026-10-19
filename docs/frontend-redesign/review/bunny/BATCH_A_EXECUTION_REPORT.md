# Purchaser UI — Batch A execution report

- **Batch:** Purchaser UI Batch A — UI-PUR-001, UI-PUR-002, UI-PUR-008, UI-PUR-004 (in that order)
- **Date:** 2026-10-19
- **Branch:** `feat/pettycash-purchaser-ui-batch-a`
- **HEAD:** `6006f28400e8962909afab0744460152353c12fe` (the approved baseline — no commits were made)
- **Working tree:** nothing staged, nothing committed, nothing pushed; pre-existing user changes preserved
  (` M AGENTS.md`, untracked `.claude/`, `CLAUDE.md`, `docs/Review uploaded pasted content.zip`, untracked
  `docs/frontend-redesign/**` trees)
- **Backend impact of the whole batch:** none. No PHP file, route, controller, domain object, migration,
  OpenAPI contract, auth or finance/workflow code was changed (`git status` shows no `*.php` entries).

## Status summary

| Task | Status | One-line reason |
| --- | --- | --- |
| UI-PUR-001 — locale-aware direction & calendar-aware formatting | **PARTIAL** | Mechanism shipped and browser-verified in both directions/calendars; a live calendar toggle (AC2) and a mutating negative test (AC5) are not part of the authorized scope; English *content* remains blocked by REC-01 (no English catalogue) |
| UI-PUR-002 — six-column sheet list | **PARTIAL** | Six Persian columns render exactly in the approved order, all inside the shell, figures grouped per D-04; the English render still falls back to raw message keys (REC-01) and that fallback does not fit the shell; OPEN-first global ordering remains blocked on an unapproved backend capability and was **not** faked |
| UI-PUR-008 — row action menu | **DONE_VERIFIED** | Browser-verified at 1440 and 390: one accessible trigger per row, status-gated items, keyboard open/Escape, confirm dialog names the sheet and cancels without a request |
| UI-PUR-004 — list context restoration | **DONE_VERIFIED** | Browser-verified: page + filter + scroll restored on browser Back, fresh entry resets, refetch on every return, clamp after delete |

**Code intelligence path actually used:**
`Code intelligence: OCI=not used in this batch (work was a known, local, already-mapped edit surface; discovery came from direct source inspection and targeted text search); Serena=not used (PHP untouched; frontend-only work); GitNexus detect-changes run post-hoc only (see "Change set" — its "critical" verdict is dominated by regenerated css/js build artifacts and is not a meaningful blast-radius signal here).`

---

## 1. UI-PUR-001 — Locale-aware direction and calendar-aware date/number formatting

### Implemented

- `src/utils/direction.ts` — single derivation point for text direction; `AppRoot.vue` declares
  `:dir="direction"` and the 18 view roots keep `:dir="direction"`. The 12 components that hardcoded
  `dir="rtl"` no longer do.
- `src/utils/locale.ts` — locale resolution that never throws:
  `document.documentElement.dataset.locale` → `getLanguage()` → `'en'`, normalised (`_`→`-`) and probed
  with `Intl.DateTimeFormat.supportedLocalesOf`. `getLocale`/`getCanonicalLocale` are deliberately not
  used (their behaviour with missing catalogues was the failure mode being removed).
- `src/utils/calendar.ts` — `CalendarPreference = 'persian' | 'gregory'`, stored under
  `pettycash.calendarPreference` (device-scoped, lazy default: Persian for `fa*` languages, Gregorian
  otherwise, independent of `en`/`fa`). `setCalendarPreference()` / `clearCalendarPreference()` exist;
  no visible toggle was authorised, so none is rendered.
- `src/utils/date.ts` — `formatSheetDate`, `formatSheetDateTime`, `ABSENT_MARKER = '—'`; null/empty render
  the marker, unparsable input renders the raw value (never `Invalid Date`).
- `src/utils/number.ts` / `src/utils/money.ts` — `formatNumber(value, options, locale)` and money kept at
  minor-unit division + `${formatted} ${currency}`; money/date/number all pass through the same locale
  and calendar preference.
- All 16 `toLocale*` call sites migrated; `TransactionDetailView` and `dashboard.ts` included.

### Acceptance criteria (card) — outcome

| # | AC | Result |
| --- | --- | --- |
| 1 | `en` renders LTR, `fa` renders RTL | ✅ browser-verified at 1440 and 390 (`lang=en` → `dir=ltr`, `lang=fa` → `dir=rtl`) |
| 2 | Calendar change re-renders without reload | ⚠️ PARTIAL — preference is applied at load; no live toggle surface was authorised. Documented limitation; a toggle candidate for the owner |
| 3 | Date rendering consistent across list/detail | ✅ one helper path; `localeFormatting.spec.ts` (13 tests) + browser |
| 4 | Digits/grouping per locale | ✅ `۲۰۰٬۰۰۰ IRR` vs `200,000 IRR` in browser |
| 5 | No stored value changes | ✅ by construction: the preference is read only by Intl render options (`grep` over `src/stores`, `src/api`, `src/types` → no consumers). A mutating submit-under-both-preferences E2E was NOT run |
| 6 | FR-071 at 390 en | ✅ browser: `lang=en`, 390×844, document overflow 0 |

### Tests / evidence

- `tests/Frontend/localeFormatting.spec.ts` — **13 tests** (new).
- Deliberate spec-scan updates: `RtlAccessibility.spec.ts`, `Dashboard.spec.ts`, `ManagementReview.spec.ts`,
  `FinanceReview.spec.ts` (partial l10n mocks with `getLanguage: () => 'fa'`).
- Browser: EN 8/8 + FA 8/8 locale checks, including cross-combinations — EN + Persian calendar renders
  `7/20/1405 AP` with Latin digits; FA + Gregorian renders `۲۰۲۶/۱۰/۱۲` with Persian digits.

### Limitations / remaining dependencies

- **REC-01 (owner decision):** no English catalogue exists, so the English interface renders raw message
  keys (`sheets.sheet`, …). The layout consequence of that state is documented under UI-PUR-002. No English
  catalogue was authored (explicitly out of scope).
- Calendar preference is device-scoped (localStorage), not an account setting.
- UI-PUR-005 (overlay/Escape focus) and UI-PUR-012 (unsaved-changes guard) are separate tasks and were not
  expanded into.

---

## 2. UI-PUR-002 — Six-column sheet list

### Implemented

- `SheetListView.vue`: `LIST_COLUMNS` = **Sheet, Status, Transactions, Recorded Amount, Latest Approved
  Amount, First Submitted** (exact order). Title above the sheet id; no Creator column; no redundant
  row-action column (the overflow menu lives inside the Sheet cell); no invented monthly periods.
- FR-062 figures grouped inside the two amount cells (D-04): Recorded = `totals.current` (+ submitted
  snapshot line when present), Latest Approved = `effectiveApproved` / `effectiveRejected` /
  `effectivePending` / `costCenterApproved` / `seniorApproved` / `financeApproved`, each with its own
  label and `data-test` hook.
- A never-submitted sheet renders the absent marker (`—`), never `0` (AC5); a genuine `0` renders `۰`.
- `AsyncState.vue` adopted for loading/error/empty; **distinct** empty messages for "nothing yet" vs
  "nothing matches the filter"; clamped-page notice (`sheets.pageClamped`); bottom error alert only when
  rows exist.
- `StatusBadge.vue`: sheet-status vocabulary (`OPEN`, `COST_CENTER`, `SENIOR`, `FINANCE`, `CORRECTION`,
  `COMPLETED`, `COMPLETED_WITH_REJECTIONS`) now resolves to catalogue labels and design-token variants
  instead of printing raw enums (found in the real browser).
- **Rejected and removed:** horizontal-scroll containers. `src/views/**` and `ResponsiveTable` are held to
  `fr071NarrowViewport.spec.ts`'s repository-wide rule that an overflow is *fixed*, not scrolled. The
  six columns now reflow inside the shell instead (see "Cross-cutting fixes").

### Acceptance criteria (card) — outcome

| # | AC | Result |
| --- | --- | --- |
| 1 | Six columns, exact order, desktop | ✅ browser (FA): headers `برگه، وضعیت، تعداد تراکنش، جمع جاری، مؤثر تأییدشده، نخستین ثبت`; all six inside the shell |
| 2 | Title above id | ✅ browser (title above UUID, both in the Sheet cell) |
| 3 | Recorded = `totals.current`, Latest Approved = `totals.effectiveApproved` | ✅ unit (divergent fixture) + browser |
| 4 | Never-submitted `—` vs genuine `0` | ✅ unit + browser (OPEN rows show `—`) |
| 5 | `0` transactions renders `۰` | ✅ unit + browser |
| 6 | 390px without horizontal scroll | ✅ browser FA + EN: document overflow 0, cards shown, `<table>` hidden, six `sheet-list-card-field-*` per card |
| 7 | English headers/labels from catalogue | ⛔ BLOCKED by REC-01 (no English catalogue). Raw keys render in EN; **no English catalogue was authored** |
| 8 | Deliberate test updates | ✅ listed in "Deliberate spec updates" |
| 9 | Keyboard reachability | ✅ status cell is a link/button; menu keyboard-verified (008) |

### OPEN-first global pagination (explicitly NOT faked)

Global "OPEN first" ordering across pages is a server capability that does not exist. Per instruction it
was **left un-implemented**, not approximated client-side.

### Known limitation (measured, not hidden)

With the missing English catalogue, the English raw-key fallback strings are longer than any real label
and are unbreakable inside components that intentionally do not wrap (title button, status chip). Measured
at 1440: table min-content **1427px** inside a **1200px** shell, 2 columns outside the visible shell area,
rows 330px. In the translated (Persian) render the table is **1104px**, rows **162px**, all six columns
inside the shell, no document overflow at 1440 or 1024. This EN state is a REC-01 artifact, not a
regression (the pre-batch 11-column table was strictly wider). `overflow-wrap: anywhere` was applied to
headers, amount figures and captions to shrink whatever can legitimately wrap.

---

## 3. UI-PUR-008 — Row action menu (3-dot, context-sensitive)

### Implemented

`sheetActionsMenu(item)` inside the Sheet/title cell in both the main table and the open-sheets region.
Items are status-gated: an **OPEN** sheet offers View / Edit / Delete; every other status offers View only
(the server refuses the rest; the UI must not offer it). The trigger's `aria-label` names the sheet
(new key `sheets.actionsNamed`), `forceMenu` is set, and the existing hooks
(`view-sheet`, `edit-sheet`, `delete-sheet`) are preserved.

### Acceptance criteria (card) — outcome

| # | AC | Result |
| --- | --- | --- |
| 1 | Exactly one overflow trigger per row; no standalone Delete in the list | ✅ browser: 25 triggers / 25 rows |
| 2 | Accessible name contains the sheet title (negative: no duplicates) | ✅ browser + unit (menu test asserts `t` call arguments) |
| 3 | Delete shows the confirmation; cancel issues no request | ✅ browser: dialog names the sheet, cancel path present, **no DELETE request issued** |
| 4 | Confirm deletes, reloads, 409 surfaces as error | ✅ pre-existing unit path retained (`SheetDeletion.spec.ts`) |
| 5 | A submitted sheet offers no Delete item | ✅ browser: 1 item (View) for a submitted row vs 3 for OPEN |
| 6 | Keyboard journey + Escape returns focus | ✅ browser: Enter opens, items focusable, Escape closes and returns focus |
| 7 | No new dependency; legible in themes | ✅ `NcActions`/`NcActionButton` from the existing `@nextcloud/vue`; dark-theme screenshot attached |

### Fixed during verification (real-browser findings)

- `DeleteSheetDialog.vue` rendered the raw `delete-sheet-<uuid>` string as its **visible** heading
  (`NcDialog`'s `name` prop is painted). Now the translated question is the heading; the dialog body still
  names the sheet.
- `StatusBadge.vue` rendered NcChip's default close “✕” on every status pill (the template had never set
  `no-close`). A status is not dismissible; `no-close` added. Verified in browser: `closeIcon: none`.

Backend impact: none (no new operations). Admin is not treated as a financial approval authority anywhere.

---

## 4. UI-PUR-004 — List context restoration

### Implemented

- `src/utils/sheetListSession.ts` installed from `createAppRouter()`; navigation from
  `sheet-detail` / `sheet-edit` / `sheet-create` marks a return; fresh entry resets page 1, clears filters
  and scroll.
- Scroll capture happens on `goToDetail` / `goToEdit` / `goToCreate`; restore happens after the list
  reloads + `nextTick`.
- The return always **refetches** (no row caching), so an edited title is visible on return.
- Delete on the last page clamps to the last valid page with the `sheets.pageClamped` notice; delete
  rejection is caught.
- **Scroll-container correction found in the real browser:** the app scroller is `#pettycash-app`
  (`overflow-y: auto`); `#content` is `overflow: clip` and never scrolls, so writing `#content.scrollTop`
  is a silent no-op that jsdom cannot catch. The resolver now walks up from the view's `listRoot` to the
  nearest scrollable ancestor with `#pettycash-app` → `#content` → `scrollingElement` as fallbacks.

### Acceptance criteria (card) — outcome

| # | AC | Result |
| --- | --- | --- |
| 1 | Filters + page N + scroll (± one row height) restored | ✅ browser: page ۲, filter `IRR`, `scrollTop 800 → 800` after browser Back |
| 2 | Same behaviour from the open-sheets region | ✅ same capture path; unit-covered |
| 3 | Fresh visit resets | ✅ browser: page ۱, filters cleared, scroll 0 |
| 4 | Refetch after edit on return | ✅ always-refetch design + unit |
| 5 | Delete on the last page falls back to a valid page | ✅ unit (clamp logic) |
| 6 | Existing pager/spec expectations updated deliberately | ✅ `PurchaserFlow.spec.ts`, `routeNameIntegrity.spec.ts` |

Scope respected: the unsaved-changes dependency (UI-PUR-012) was **not** expanded into; modal
focus/Escape inside detail/edit views is UI-PUR-005 and untouched.

---

## 5. Real-browser verification (what actually ran)

- **Tooling:** Playwright 1.57, real Chromium (headless) against the live lab
  `http://localhost:8081` (`pettycash-lab-nextcloud-1`), signed in as the dedicated `pettycash-e2e`
  account.
- **Scripts** (kept under gitignored `test-results/`):
  `verify-batch-a-fa.mjs` — **32/32 checks passed** (six Persian columns exact order, RTL, title above
  id, translated statuses, Persian digits, Jalali `۱۴۰۵/۷/۲۰`, no owner/actions columns, no document
  overflow at 1440; menu trigger + 3 items for OPEN / 1 for submitted, Enter/Escape/focus; filter+page+scroll
  restored after Back; fresh entry resets; 390px cards with six fields, no overflow; dark scheme painted).
  `verify-batch-a-locale.mjs` — **8/8 (EN) + 8/8 (FA)**: EN LTR + Gregorian + Latin digits; EN + Persian
  calendar `7/20/1405 AP` (Latin digits); FA RTL + Jalali + Persian digits; FA + Gregorian `۲۰۲۶/۱۰/۱۲`
  (Persian digits).
  `verify-batch-a-delete.mjs` — **5/5**: confirm dialog names the sheet, cancel path present, Escape
  closes, **no DELETE request issued**.
- **Screenshots** (`docs/frontend-redesign/review/bunny/screenshots/`):
  `desktop-fa-light.png`, `desktop-fa-light-menu-open.png`, `desktop-fa-dark.png`,
  `desktop-fa-gregorian-calendar.png`, `desktop-en-light.png`, `desktop-en-persian-calendar.png`,
  `mobile-390-fa-light.png`, `mobile-390-en-light.png`.
- **Lab runtime changes made for verification (disclosed):** the `pettycash-e2e` password was rotated via
  OCC and the new value written to the gitignored `.env.testing`; `firstrunwizard show 34.0.2` set for
  that account; bruteforce reset for the gateway IP; the account's language was returned to `fa` and the
  localStorage calendar override removed.

## 6. Cross-cutting fixes found by the real browser (not visible to jsdom)

1. **Scroll container** — `#pettycash-app`, not `#content` (004).
2. **Raw enum statuses** in the list (`COST_CENTER`) — mapped to catalogue labels + variants (002).
3. **Pager position** — the `fa` translation had dropped `{page}/{total}`; fixed in both catalogues and
   the pager now passes `formatNumber(...)` so pages print `صفحه ۱ از ۳۰` (002).
4. **Delete dialog heading** showed `delete-sheet-<uuid>` — now the translated question (008).
5. **Status pills** carried NcChip's stray close “✕” — `no-close` (008).
6. **Table overflow** — six columns with D-04 figure groups exceeded the `max-width: 1200px` shell and
   the columns past it were painted outside and unreachable. Two approaches were tried and **rejected**:
   a `12rem` cap on the amount cells (rows became 330px) and a scroll container (forbidden by
   `fr071NarrowViewport.spec.ts`, which scans all of `src/` for `overflow-x: auto|scroll`). The accepted
   fix makes `NcNoteCard`'s unnamed slot `div` shrinkable (`:deep(.notecard > div) { min-width: 0 }`) so
   the table reflows into the card, plus `overflow-wrap: anywhere` for fallback strings. It also exposed a
   real scoping defect: `listCell` VNodes are created during **`ResponsiveTable`'s** render, so they carry
   *its* scope id — the view's scoped `.amounts-cell` / `.sheet-identity` rules silently never applied and
   the figures rendered as one inline run. They are now `:deep()`-scoped and verified in the browser.

## 7. Test evidence

- `npm run check` (final tree): portability OK, error-rate self-test OK, typecheck OK, **lint 0 errors**
  (97 warnings, all in untouched pre-existing files), stylelint OK, **23 test files / 481 tests passed**,
  build OK.
- Targeted runs: `PurchaserFlow.spec.ts` 137, `SheetDeletion.spec.ts` 9, `localeFormatting.spec.ts` 13,
  `l10nCatalog.spec.ts` 5, `RtlAccessibility.spec.ts` 9, `NextcloudVueContract.spec.ts` 6,
  `fr071NarrowViewport.spec.ts` (3 checks) — all passing.
- `l10n/fa.js` and `l10n/fa.json` carry the same 362 keys (enforced by `l10nCatalog.spec.ts`).

## 8. Change set

- **Source (tracked):** 50 files changed, +1275 / −484 — `src/AppRoot.vue`, `src/router/index.ts`, 18 view
  roots, 17 components, `src/utils/{date,money,dashboard}.ts`, `SheetListView.vue` (1077 lines),
  `l10n/fa.js`, `l10n/fa.json`, and 7 test files.
- **Source (new, untracked):** `src/utils/direction.ts`, `locale.ts`, `calendar.ts`, `number.ts`,
  `sheetListSession.ts`, `tests/Frontend/localeFormatting.spec.ts`.
- **Build output:** `js/` and `css/` are **tracked in this repository**, so the mandatory `npm run build`
  regenerated them (317 old hashed chunks deleted, new hashed chunks untracked). This is mechanical build
  output, not hand-edited code; it was left consistent with the source rather than reverted.
- **GitNexus** `detect-changes --scope all` (run post-hoc): 367 files / 2827 symbols, risk “critical” —
  that verdict is dominated by the regenerated css/js bundles, and is not a meaningful blast-radius signal
  for this change. Reported as-is; not used to claim a clean check.

## 9. Remaining dependencies / blocked items (not worked around)

1. **OPEN-first global pagination ordering** — needs a backend ordering capability; explicitly not faked.
2. **REC-01 English content** — no English catalogue; extensions must not author one. It also drives the
   EN raw-key layout residual measured above.
3. **UI-PUR-003 / 007 / 014** — still `BLOCKED_BY_BACKEND` and outside Batch A.
4. **UI-PUR-005** (overlay Escape/focus) and **UI-PUR-012** (unsaved-changes guard) — not authorised in
   this batch; the 004 work deliberately stops at the session/scroll contract.
5. **REC-06** (date-filter labels naming the date they filter) was not in the authorised 002 scope list;
   left open rather than silently expanded into.
6. The repo's own Playwright suite (`tests/E2E/global-setup.ts`) cannot run because
   `PETTYCASH_E2E_ADMIN_*` in `.env.testing` are stale (401); browser verification therefore used the
   purchaser-only ad-hoc scripts above. The admin credentials were **not** modified.
