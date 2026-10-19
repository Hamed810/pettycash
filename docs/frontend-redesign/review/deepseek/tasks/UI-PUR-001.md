# UI-PUR-001 — Locale-aware direction and calendar-aware date/number formatting foundation

- **Priority:** P1 · **Status:** PROPOSED (not approved to run) · **Size:** M · **Risk:** medium (touches every purchaser surface indirectly)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-064, GAP-065 (mechanism only), GAP-066, GAP-067, GAP-068
- **Backend impact:** NONE

## 1. User-visible outcome

The application's text direction and its date/number rendering follow the user's language and calendar preference instead of being fixed to Persian RTL, so the accepted English-LTR design can actually be seen and reviewed in a running deployment — without changing a single stored value.

Purchaser screens affected: every purchaser surface — sheet list, sheet detail, sheet form, transaction list, transaction form, transaction detail, audit view (the 20 views that hardcode direction today).

## 2. Current evidence (verified paths)

| Fact | Evidence |
|---|---|
| Direction is hardcoded per view root | `src/views/sheets/SheetListView.vue:427,452`; `src/views/sheets/SheetDetailView.vue:203`; `src/views/sheets/SheetFormView.vue:170`; 17 further occurrences across `src/views/**` |
| Dates render through one helper, hardcoded locale | `src/utils/date.ts:36-38` (`toLocaleDateString('fa-IR')`) |
| Money/number rendering, hardcoded locale | `src/utils/money.ts:19-25` |
| Jalali exists only as a server-side reporting period | `lib/Domain/Shared/JalaliReportingPeriods.php` (FR-068), unrelated to display |
| Catalog is Persian only | `l10n/fa.js`, `l10n/fa.json`; `src/l10n.ts` (`FA_LOCALE`) |
| The prototype's "conversion" is a hardcoded pair table and writes fabricated dates | `docs/frontend-redesign/prototype/manus-purchaser-v1/app.js:36-43,290,293` |
| Existing RTL/390px assertions | `tests/Frontend/Component/RtlAccessibility.spec.ts:140-141`; `tests/Frontend/fr071NarrowViewport.spec.ts:61,98,126` |

## 3. Authoritative references

- `docs/frontend-redesign/DECISIONS.md` — **D-006** (Persian and English from day one; RTL/LTR per Nextcloud) is the governing accepted decision.
- `specs/001-pettycash-v1-core/spec.md:1156` — FR-071 (390px floor; must not regress).
- `specs/001-pettycash-v1-core/spec.md` — FR-068, the only Jalali requirement today (reporting periods, server-side), a reminder that the display preference must stay a display concern.
- Owner rule recorded in `DECISIONS_AND_BLOCKERS.md` D-02: the Gregorian/Jalali preference is **independent of UI language** and applies throughout Pettycash **without changing stored date semantics**.

## 4. Allowed scope / non-goals

**In scope**
- One shared formatting module (extend `src/utils/date.ts`, `src/utils/money.ts` or add a sibling) exposing locale + calendar aware helpers, with the calendar choice as an explicit option and a single default.
- Direction derived from the running language/locale rather than hardcoded, applied at the app shell so views inherit it (remove the 20 hardcoded attributes).
- A persisted display preference (calendar: Gregorian | Jalali) that is independent of the language, with a default that preserves today's Persian behaviour when the language is Persian.
- Replace the ~14 direct `toLocaleDateString`/`toLocaleString` call sites with the shared helpers.

**Non-goals (explicit)**
- **No translations.** Authoring an English catalog is a separate owner decision (D-02). This task ships the mechanism; if English strings are absent, the English-language case renders Persian strings in an LTR layout, which is honest and reviewable.
- **No date conversion that rewrites stored values.** The stored representation stays `YYYY-MM-DD` / ISO timestamps; Jalali/Gregorian is a rendering choice via `Intl` (e.g. `calendar: 'persian' | 'gregory'`), never a re-encoded value. Do **not** port the prototype's `{j,g}` pair objects or its hardcoded `2023-12-27` fallback (`app.js:290,293`) — that would write fabricated dates.
- No change to server responses, DTOs, report period resolution (FR-068) or any API contract.
- No new CSS framework, no Vuetify, no new dependency at all: `Intl` and existing `@nextcloud/vue`/`@nextcloud/l10n` are sufficient.

## 5. Implementation behaviour

1. Resolve the active locale once (Nextcloud provides the user's language; `@nextcloud/l10n` already supplies `getLanguage`/`translate`). Derive `dir` from it (`fa`, `ar`, `he` → `rtl`; otherwise `ltr`) and apply it on the shell element so views inherit; keep a per-view override only where a deliberately RTL island is required (do not add one).
2. Format dates through `formatDate(value, { calendar, locale })`; a missing value remains the existing dash (`src/utils/date.ts:31`) — never an epoch date.
3. Format money through `formatMoney(amountMinor, currency, { locale })`; keep minor-unit semantics exactly as they are (integers, currency code from the payload).
4. Persist the calendar preference per user (localStorage is acceptable only if the owner accepts a device-scoped preference; the honest default is the user's account setting if one exists, else the instance default). **If no account-level setting exists and adding one is required, stop and raise it — a preference that silently differs per browser is a product decision, not an implementation detail.** Model it in exactly one place so a future account setting can replace the storage layer without touching call sites.
5. Error/empty states: none introduced. The helper must be pure and total (no throw on an unexpected locale or currency code — fall back to the raw value rather than rendering `NaN`).

## 6. Acceptance criteria

1. With an English (or any non-RTL) language active, every purchaser view renders LTR with no hardcoded `dir="rtl"` remaining in `src/views/**`; with `fa` active, the layout is RTL as today (existing RTL tests still pass unchanged).
2. A Jalali/Gregorian preference change re-renders every date on the purchaser surfaces without a reload and without any network request.
3. The same stored value renders identically in a list and a detail view (no divergence between two helpers — this is the failure `src/utils/date.ts` was written to prevent).
4. Persian digits/grouping and Latin digits/grouping follow the locale; amounts remain exact minor-unit integers.
5. Switching the calendar does **not** change any value submitted to the server: a transaction saved while the Gregorian view is active carries the same `expenseDate` as one saved from the Jalali view (negative test: submit the same form under both preferences and diff the request payloads).
6. FR-071 survives: at 390px with the English locale, no horizontal scrollbar appears on the sheet list, sheet detail or transaction form.

## 7. Tests

| Level | Command | What it must assert |
|---|---|---|
| Unit | `npx vitest run tests/Frontend/Component/<new>.spec.ts` (create) | direction mapping; both calendars; both digit sets; missing date → dash; unknown locale → raw value, never `NaN`/epoch |
| Unit | `npx vitest run tests/Frontend/Component/RtlAccessibility.spec.ts` | existing RTL expectations unchanged |
| Component | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | no purchaser view emits a literal `dir="rtl"` when the locale is `en` |
| E2E | `npm run test:e2e` (Playwright; `pretest:e2e` settles the opcode cache — TO_VERIFY that the lab is up) | toggle the calendar on the sheet list and on a transaction form; assert dates change and no request is made |
| Gate | `npm run typecheck && npm run lint && npm run stylelint` | clean |

## 8. Dependencies / rollback

- **Depends on:** nothing.
- **Blocks (informational):** nothing hard; `UI-PUR-005` and `UI-PUR-015` assume direction is inherited rather than hardcoded.
- **Rollback:** revert the commit; the preference default preserves current Persian behaviour, so a partial rollout is benign.

## 9. Definition of Done

- [ ] The 20 hardcoded direction attributes are gone; direction is derived in one place.
- [ ] One date helper and one money helper are the only rendering paths on the purchaser surfaces.
- [ ] Calendar preference persists and is documented, with the storage-layer decision recorded.
- [ ] Tests above executed; output pasted into the completion evidence.
- [ ] `git status` shows only the intended paths; no application behaviour change beyond rendering.

## 10. Approval gate

**PROPOSED.** Not approved. A human owner must accept this card (and D-02's sequencing question) before any implementation. Nothing in this card authorizes a contract, domain or storage change.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Current status:** `READY_FOR_OWNER_APPROVAL` (the header's `PROPOSED` means exactly this; nothing here is approved). Authoritative queue: `BUNNY_READY_QUEUE.md`; blockers: `BLOCKED_ITEMS.md`.
- **D-02 applied:** English LTR is the current design/implementation sequence, and the bilingual product scope is preserved. This card therefore ships the **mechanism only** — direction follows the active locale, no direction may be hardcoded in either direction.
- **New dependency `REC-01`:** the UI is key-based (`t('pettycash', 'sheets.title')`), only `fa` ships (356 keys), and `src/l10n.ts` has no English default table — so rendered English is `TO_VERIFY` until the owner decides how an English catalog is sourced. **Do not author, stub, or guess an English catalog in this card.**
- Any new key still needs a `fa` entry or `tests/Frontend/l10nCatalog.spec.ts` fails (correctly).
- All other scope, acceptance criteria and tests in this card are unchanged.
