# Data Model: Purchaser UI Redesign

**Feature**: `005-purchaser-ui-redesign` | **Date**: 2026-10-19

No new persisted entity and no change to any persisted domain entity. The entities below are **presentation and management models** that already exist in the working tree or are planned by this feature. None of them stores or transforms a financial value; the domain remains authoritative for every persisted figure.

> **Non-rule**: nothing in this file redefines a 001 domain rule. Where a field carries a financial meaning, the authoritative source is the 001 payload and the 001 specification. See `spec.md` §"Domain non-duplication rule".

---

## Entity: Locale context (`src/utils/locale.ts`, `src/utils/direction.ts`)

| Field / function | Type | Rule |
|---|---|---|
| `activeLanguage()` | `() => string` | Resolution order: `document.documentElement.dataset.locale` → `getLanguage()` → `'en'`; never throws |
| `activeLocale()` | `() => string` | The language normalised for `Intl` (`_` → `-`) and probed with `Intl.DateTimeFormat.supportedLocalesOf`; falls back to the language itself |
| `directionFor(language)` | `(string) => 'rtl' \| 'ltr'` | Pure; delegates to Nextcloud's `isRTL()` so no second RTL language list is maintained. Missing export → `'ltr'` (test-double state only) |
| `direction` | `ComputedRef<Direction>` | Read lazily; consumed by `AppRoot.vue` and every view root as `:dir="direction"` |

**Invariants**: no view may declare a literal `dir="rtl"`/`dir="ltr"`; the language cannot change without a page load, so a computed (not a constant) is the correct lifetime.

**State transitions**: none. The value is derived, never assigned.

---

## Entity: Calendar preference (`src/utils/calendar.ts`)

| Field / function | Type | Rule |
|---|---|---|
| `CalendarPreference` | `'persian' \| 'gregory'` | Display-only. Never participates in a stored date value |
| storage key | `'pettycash.calendarPreference'` | Device-scoped (localStorage). Not an account setting — that would require a new server field, an OCS contract change, and a migration, all out of scope (`FR-005-004`) |
| default | lazy | `persian` for `fa*` languages, `gregory` otherwise; independent of the current rendering language |
| `setCalendarPreference()` / `clearCalendarPreference()` | setters | Reactive: every mounted formatter re-renders. No reload, no network request |
| `stored` | `Ref<CalendarPreference \| null>` | `null` = "no explicit choice yet" → the language default applies |

**Invariant (card AC5)**: changing the preference MUST NOT change any payload a form submits. The card's mutating negative test (submit the same form under both preferences and diff the payloads) is **outstanding** and is tracked as `T018`.

**State transitions**: `null` → `'persian'`/`'gregory'` on an explicit choice; back to `null` on clear. No other transition exists.

---

## Entity: Formatting surface (`src/utils/date.ts`, `src/utils/number.ts`, `src/utils/money.ts`)

| Symbol | Rule |
|---|---|
| `ABSENT_MARKER = '—'` | Rendered for `null`, `undefined`, or `''` |
| `formatSheetDate(value, format)` | Missing → marker; unparsable → the raw value; never `Invalid Date` |
| `formatSheetDateTime(value, format)` | Same contract as above |
| `formatSheetDateRange(sheet)` | Composes the two helpers; both missing → the single marker |
| `formatNumber(value, options, locale)` | Locale digits and grouping; used for counts and page numbers, not for money |
| `formatMoney(money)` | Minor-unit division + `${formatted} ${currency}`; exact minor-unit arithmetic only; no float math, no symbol invention, no conversion |

**Invariants**: one helper path per value kind (a list and a detail MUST agree for the same stored value, `FR-005-002`); money never round-trips through a display helper into a request payload.

---

## Entity: Sheet list session (`src/utils/sheetListSession.ts`)

| Field | Type | Rule |
|---|---|---|
| `SHEET_CONTEXT_ROUTES` | `Set<string>` | `{ 'sheet-detail', 'sheet-edit', 'sheet-create' }` — the only routes whose return marks a restore |
| `returning` | module boolean | Recorded by a `beforeEach` hook installed from `createAppRouter()`; read by the list in `onMounted` |
| captured context | page + filters + `scrollTop` | Captured on `goToDetail` / `goToEdit` / `goToCreate`; restored after refetch + `nextTick` |
| scroll container | resolved at runtime | Nearest scrollable ancestor of the list root → `#pettycash-app` → `#content` → `scrollingElement`. `#content` is `overflow: clip` in Nextcloud 34 and is **not** the scroller |

**Invariants**: a return always **refetches** (no row cache — the list is where a purchaser decides whether a sheet is still editable, so stale rows are not acceptable); a fresh entry resets page 1, filters, and scroll; a delete on the last page clamps to the last valid page and shows the `sheets.pageClamped` notice.

**State transitions**: `fresh → restored` on arrival from a `SHEET_CONTEXT_ROUTES` route; `restored → fresh` on any other arrival; the flag is consumed once.

---

## Entity: Workspace state (**plan** — `UI-PUR-005`/`006`, not implemented)

| Field | Type | Rule |
|---|---|---|
| `sheetId` | from the route | The existing `sheet-detail` route stays addressable (refresh, deep links) |
| `activeTab` | `'overview' \| 'approvals' \| 'history'` | Rendered as a keyboard-operable tablist; selected tab announced |
| `nestedTransaction` | transaction ID or `null` | At ≤640px replaces the workspace body; MUST NOT create a second `[role="dialog"]` element |
| `invokerRow` | element reference | Focus returns here on close, by any close path |

**Invariants**: `role="dialog"` + `aria-modal="true"` + accessible name = sheet title; focus enters, is contained, and returns; Escape closes unless a dirty inner form's guard decides otherwise; the list beneath is not unmounted.

---

## Entity: Readiness model (**plan** — `UI-PUR-016`)

| Field | Type | Rule |
|---|---|---|
| blockers | `Array<{ transactionId, field }>` | Only server-enforced preconditions: description, cost center, expense type, at least one transaction (`FR-005-027`) |
| evidence state | `'unknown'` | Forced while `BE-CR-004` is unapproved. Must never render as "ready" or "blocking" |
| submit affordance | reachable | Remains reachable; the server's 422 remains the backstop |

**Invariants**: no client-derived domain rule; a submitted sheet renders no readiness surface; a zero-transaction sheet shows the FR-024 blocker and no Submit affordance.

---

## Entity: Traceability record (management, this feature)

| Field | Rule |
|---|---|
| `cardId` | Original imported ID: `UI-PUR-001…016` or `BE-CR-001…006`. Never renumbered |
| `requirementIds` | One or more `FR-005-nnn` |
| `taskIds` | One or more `Tnnn` in `tasks.md` |
| `status` | One value from the `research.md` Decision 4 vocabulary |
| `gate` | The exact condition that changes the status (an owner answer, a `BE-CR` approval, a test, or a browser check) |
| `evidence` | A repository path or a command with its result — never an assertion without one |

**Invariant (`SC-005-001`)**: all 22 cards appear exactly once in `spec.md`'s matrix and in `tasks.md`, each with a requirement, a task, a status, and a gate. Zero unmapped cards.

---

## Relationship to the domain model

| Presentation entity | Domain authority | Relationship |
|---|---|---|
| Calendar preference, locale context | none | Pure display; no persisted counterpart |
| Formatting surface | `Money` value object (minor units + currency + `minorUnit`) | Read-only consumer; never converts or re-rounds |
| Sheet list session | `GET /sheets` paged response | Restores *context*, refetches *data* |
| Workspace state | `GET /sheets/{id}`, transaction endpoints | Read-only consumer; actions gated by the server's capability set |
| Readiness model | FR-022/023/024, submit 422 | Mirrors server-enforced preconditions; adds no rule |
