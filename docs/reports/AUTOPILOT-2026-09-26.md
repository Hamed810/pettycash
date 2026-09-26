# Autopilot run — passes 1–3

**Feature**: PettyCash V1 Core Workflow (`specs/001-pettycash-v1-core`)
**Date**: 2026-09-26
**Status**: `blocked` — context exhausted, not converged
**Implementation passes executed**: 3 of 50
**Convergence passes executed**: 1 (initial)

## Cadence trace

| Step | Outcome | Work |
|---|---|---|
| Initial convergence | `tasks_appended` | Phase 36 appended: T282–T285 |
| Pass 1 | 3 tasks completed | T285, T284, T282 |
| Pass 2 | T283 slice 1 | Sheet Forms green; 3 mock-era assertions replaced; FR-071 typography gate added (RED) |
| Pass 3 | T283 slice 2 | Sheet Detail View green |

No early convergence was triggered. The FR-071 typography finding is **not** an early-convergence
condition: FR-071 is already an approved requirement and T116, T120, T123, and T135 already own it,
so it is ordinary remaining work rather than a planning or repository-state problem.

## What convergence found

The quality gate was red for a reason no open task covered: `npm test` reported **58 failed / 18
passed with 26 unhandled errors** while `T034`, `T042`, `T047`, and `T053` were all marked `[x]`.
Constitution V's Delivery rule 8 says a task is complete only when implementation, verification, and
task status agree.

The counts were also untrustworthy in both directions:

- **US2 measured nothing.** All 19 `ManagementReview.spec.ts` tests died inside `vue-router` during
  `mount()`, before a single assertion ran.
- **US1's 18 green tests passed for the wrong reason.** The axios mock resolved `undefined`, so every
  mount-time fetch raised an unhandled `TypeError`; the store stayed empty and the component rendered
  its "no data" branch, which happened to satisfy the structural assertions.

Tasks appended as Phase 36 (T282–T285). `tests/Frontend/__mocks__/components.ts` was recorded but
deliberately **not** appended — T158 and T215 already own it.

## The finding that mattered most

`PurchaserFlow.spec.ts` mocks `@nextcloud/vue` through the barrel, but **every view imports
`@nextcloud/vue/components/NcX` by deep path**; only 2 of 14 files use the barrel. The mock therefore
never reached the components it appeared to cover, and 14 of its 15 entries were dead weight. A probe
confirmed the real `NcSelect` renders a `v-select` with a `role="listbox"` and **zero** `<option>`
elements, and does not open under jsdom at all — so `findAll('option').length > 1` could never have
passed. The mocked list also included `NcAlert`, which **does not exist in `@nextcloud/vue` 9.x**.

Consequences: assertions were rewritten against the components that actually run, the validation
summary was built on a plain `role="alert"` element instead of an invented library component, and
the accounts store mock became a mutable binding because three requirements are conditional on the
caller's account count.

## Two of my own assertions were wrong, and were corrected against the source

- I invented a `FINANCE_APPROVED` status to demand a label for. The `TransactionStatus` union does not
  contain it. The test now drives the union.
- I asserted the *smallest* responsive breakpoint must be ≤390px. A `max-width: 600px` rule already
  covers 390px. The requirement is that the viewport falls inside a declared breakpoint, so the
  *largest* must reach 390.

## FR-071 typography: a spec-wide violation, found by replacing a meaningless test

The old assertion was `expect(wrapper.find('body').classes()).toContain('persian-font')` — which
proved nothing, and which `body` does not even own. Replacing it with a real check
(`tests/Frontend/typography.spec.ts`, resolving 12pt to 16px) found **42 declarations in `src/` below
the floor**: 12px, 13px, 14px, `0.75rem`, and `0.875rem` across `StatusBadge`, all five review views,
both evidence list components, and every review component. The app's entire typography is under
FR-071's floor.

This gate is deliberately **left red** and attributed to T120, which owns the styling, rather than
half-fixed inside T283.

## Real defects fixed in production code

- `src/services/http.ts` — unguarded three-level OCS unwrap; a session expiry or proxy page produced
  a raw `TypeError` instead of the `ApiError` envelope T030 is accountable for.
- **Unhandled promise rejections were shipping.** Every store records a failure in its own `error`
  ref and then re-throws, and five views fired those loads from `onMounted`/`watch` uncaught, so any
  failed load became an unhandled rejection in the user's console. The store re-throw is load-bearing
  (`SheetFormView.handleSubmit` relies on it), so the fix went to the fire-and-forget call sites.
- `SheetFormView` — no validation summary, no account balance, submit enabled while invalid, and
  selects with no accessible name (the real `NcSelect` had been warning about this).
- `TransactionFormView` — no `aria-label` on the date and amount controls.
- `SheetDetailView` — FR-062's latest-effective-outcome column did not exist as a column; the
  submission error was painted but not announced.

## Verification

| Gate | Start of run | Now |
|---|---|---|
| `npm test` | 58 failed / 18 passed (76), **26 unhandled** | 44 failed / 59 passed (103), **0 unhandled** |
| `ManagementReview.spec.ts` | 19 failed, all router `TypeError`, 33s | 15 failed, all behaviour, 3.1s |
| `PurchaserFlow.spec.ts` | 39 failed / 18 passed | 28 failed / 39 passed |
| `npm run typecheck` | clean | clean |
| `npm run lint` | 0 errors / 105 warnings | 0 errors / 105 warnings |
| `npm run stylelint` | clean | clean |
| `npm run build` | OK | OK |
| `npm run check:portability` | OK | OK |
| `scripts/dev/phpunit` | 174 / 831 / 1 failure | 174 / 831 / 1 failure (T275, untouched) |

## Task state

| Task | State |
|---|---|
| T282 | complete |
| T284 | complete |
| T285 | complete |
| T283 | **open** — 2 of 7 view groups done; progress recorded in place |

T283's task entry carries the full evidence for both slices, including the corrections to my own
assertions, so the next pass does not re-derive them.

## Blocker

**Context exhausted.** 3 of 50 implementation passes ran. The remaining work is substantial and
genuinely unfinished, so this run must not be reported as converged:

- 28 `PurchaserFlow` failures (Transaction Forms, Transaction List/Detail, Evidence Upload,
  Submitted Read-Only, Multiple Open Sheets, Balance-Independent Submission)
- 15 `ManagementReview` failures, now trustworthy for the first time
- 1 `typography.spec.ts` failure — the 42 FR-071 declarations, owned with T120
- 1 PHP failure — T275, a contract-versus-FR-060 contradiction

Minimum action to continue: start a new Autopilot session. It should run convergence first only if
the repository state has changed; otherwise resume at T283. No user decision is needed for that path.

## Decision needed from the maintainer (does not block T283)

**T275** — `contracts/ocs-api.yaml:657-662` declares exactly one success content type for
`GET /evidence/{id}/content`, `application/octet-stream`, while FR-060 requires authorized users be
able to *preview* evidence, which that content type cannot do in a browser.
`EvidenceController::content()` implements the FR as `application/pdf`. One of the two must change:

1. amend the contract to describe a stored-media-type response, keeping FR-060 preview; or
2. clarify FR-060 to mean download-only and correct the implementation to `application/octet-stream`.

This is left unresolved on purpose: Constitution Governance requires a conflict between authoritative
artifacts to be reconciled *in the artifact*, not chosen silently in code.

## Other pre-existing items, not addressed

- `scripts/dev/occ` and Playwright E2E were not exercised; T171 and T054 own those.
- 105 ESLint warnings (0 errors) are pre-existing `vue/attributes-order` and
  `vue/html-closing-bracket-newline` style warnings.
- `@nextcloud/vue` is imported by deep path in 13 files and by barrel in 2. The mixed style is what
  made the mock behave unpredictably. Normalising it is a refactor no open task owns.
