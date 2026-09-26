# T113 (frontend slice, second half) — US7 Role-Aware Dashboard Client

**Task ID**: T113 (dashboard portion; the reporting/export portion is recorded in `docs/reports/T113.md`)
**Status**: complete for the client slice
**Date**: 2026-09-26
**Spec Kit feature**: `001-pettycash-v1-core`
**Execution environment**: host-only frontend tooling. No PHP, Nextcloud, Docker, or container work;
no `scripts/dev/*` wrapper was invoked.

## Scope and independence

The main worktree is reserved for T287 and T288 (US3 Finance and PHP verification hygiene), so the
write-set here was checked against it first:

| Concern | This slice |
|---|---|
| US3 finance surfaces | none touched — no `FinanceQueueView`, no `src/components/review/`, no `src/services/reviewsApi.ts`, no `src/stores/reviews.ts`, no `accountsApi.ts` |
| T287's test moves | neither `tests/Frontend/Component/ManagementReview.spec.ts` nor `FinanceReview.spec.ts` was read for editing or edited |
| T288's targets | `scripts/dev/**` and `AGENTS.md` untouched |
| Shared coordination | `tasks.md`, `package.json`, `package-lock.json`, `vitest.config.ts`, `src/router/index.ts`, `src/main.ts`, `src/AppRoot.vue`, `src/services/http.ts`, `src/styles/**` untouched |
| Backend | no `lib/`, `appinfo/`, migration, or PHP file touched |
| Build output | `js/` and `css/` left exactly as committed; `npm run build` was run to verify and its output reverted, matching the previous slice's commit, which shipped source only |

The work is US7 dashboard, a different user story from US3, and five of the seven paths are new. The
two existing files touched are `src/views/DashboardView.vue` (an eight-line placeholder that read
"Petty Cash is ready for configuration", replaced by the real view) and `src/services/sessionApi.ts`
(two declarations removed, below).

### Files

| File | Change |
|---|---|
| `src/types/dashboard.ts` | **New.** `DashboardRole` and the contract's five buckets, `DASHBOARD_ROLE_FOR_SESSION_ROLE` (the mapping from `SessionContext.roles` onto them), `DashboardBucket`/`RoleScopedDashboard`, `DashboardMetric`/`DashboardMetricKind`, `DashboardCard`. |
| `src/utils/dashboard.ts` | **New.** `isMoney()`, `dashboardMetrics()`, `isRecord()` — the pure derivation from one bucket to the entries its card renders. |
| `src/services/dashboardApi.ts` | **New.** `fetchDashboard()` over `GET /dashboard`. |
| `src/stores/dashboard.ts` | **New.** Projection state, the role-aware card list, `loadDashboard()`, `clearError()`. |
| `src/components/dashboard/DashboardCard.vue` | **New.** One role's card: heading, metric label/value pairs, the "nothing to do" state, and the raw-value presentation. |
| `src/views/DashboardView.vue` | **Modified.** The placeholder replaced by the real view: loading, failure-with-manual-retry, empty, and the card grid. |
| `src/services/sessionApi.ts` | **Modified.** `RoleScopedDashboard` and `fetchDashboard()` removed; both are now owned by `dashboardApi.ts`/`types/dashboard.ts`. |
| `tests/Frontend/Component/Dashboard.spec.ts` | **New.** 33 tests over the service, the derivation, the store, the card, the view, and the FR-071/FR-072 declaration gates. |

### Why `sessionApi.ts` lost the dashboard

It declared `fetchDashboard()` against `GET /dashboard` with its own `RoleScopedDashboard` type, and
nothing referenced either. T113 names `src/services/dashboardApi.ts` as the owner, and leaving the
older copy in place would have created exactly the defect T235 raised about the unrouted duplicate
controllers: two implementations of one endpoint that a later fix could land in only one of. The
removal is a pure deletion of dead code; `fetchSessionContext()` is untouched and no caller changed.

## Requirements implemented

- **FR-061** — the role-aware dashboard. One card per role bucket the projection answers with, in
  the contract's order, and a bucket the session's roles do not cover is left off the screen. That
  filter is defence in depth and is documented as such: `plan.md` fixes authorization as
  server-authoritative, and while the session is still unknown the client does not second-guess the
  server's scope at all. Every key a bucket carries is rendered, so open sheets, returned
  transactions, queue counts, decision counts, balances, movements, and administrative summaries all
  appear whichever of them the projection chooses to send.
- **FR-075** — a failed read is reported with a retry the user triggers. The store re-issues nothing,
  and a failure clears the previous cards so a stale count is never presented as the current one.
  There is no mutation on this screen, so there is nothing to retry automatically even in principle.
- **FR-071** — right-to-left, the cards reflow to one column at 390px with no fixed or minimum width
  that would force sideways scrolling, no text below the 12pt floor, and every text colour declared
  with a fallback pair checked against the background it sits on.
- **T266/T267 lesson, restated** — a bare number is read as a count; an amount has to arrive as a
  `Money` so its own currency scale is used. A client that guessed a divisor would print a rial
  amount a hundred times too small.
- **T119 boundary** — every string goes through `t('pettycash', …)` under one `pettycash.` root, and a
  role or metric the Persian catalog does not name yet keeps the contract's own identifier rather
  than rendering an untranslated key.

## Verification

| Command | Result |
|---|---|
| `npx vitest run tests/Frontend/Component/Dashboard.spec.ts` | **OK — 33 tests passed**, 0 skipped, 0 incomplete |
| `npx vitest run` (full suite) | 1 failed / 190 passed of 191. The one failure is `ManagementReview.spec.ts > FinanceQueueView > should render shared finance queue for all finance officers`, which was failing identically before this change (1 failed / 157 passed of 158) and is **T287's** reclassification, not a regression here |
| `npx vitest run tests/Frontend/typography.spec.ts` | **OK — 4 tests passed** (the app-wide FR-071 floor, re-run because user-facing styles and text were added) |
| `npm run typecheck` | clean |
| `npm run lint` | 0 errors / 105 warnings — identical to the pre-change baseline |
| `npm run stylelint` | clean (one `#ffffff` → `#fff` fix was needed to satisfy `color-hex-length`) |
| `npm run check:portability` | OK |
| `npm run build` | built successfully; the generated `js/`+`css/` bundles were then reverted to the committed state |

### RED proven by mutation, not assumed

Each mutation was applied to a backup copy, its md5 compared before and after so a silently
ineffective edit could not be reported as a result, the targeted test run, and the original
restored. All nine produced the expected failure:

| Mutation | Failing assertion |
|---|---|
| Role filter removed from the store's card list | `leaves out a bucket the session does not hold` |
| `isMoney` short-circuited to `false` (the fixed-divisor regression) | `formats an amount at the currency scale the payload carries` |
| Structured value replaced by an empty text | `shows a structured value rather than dropping it` |
| `data-testid="dashboard-retry"` renamed | `shows the failure with a retry the user must trigger (FR-075)` |
| View breakpoint moved to 300px | `declares a mobile breakpoint that reaches the 390px viewport` |
| A 12px font declaration introduced | `declares no text size below the 12pt (16px) floor` |
| The metric `<dt>` label deleted | `keeps a status readable as text and not by colour alone` |
| Label colour `#6b6b6b` → `#cccccc` | `meets AA for every text colour the new components declare` |
| Error colour `#c62828` → `#e6a0a0` | `meets AA for every text colour the new components declare` |

**One of my own gates was wrong first and was rewritten.** The contrast test originally asserted two
hex pairs written into the test itself. Changing a component's label to an AA-failing grey left it
green — the same "the assertion describes something the source never promised" defect T286
catalogued. It now reads every declared `color:`/`background-color:` fallback pair out of the new
`.vue` sources and measures those, which is why the last two mutations above are caught. A
non-vacuity check (`#cccccc` on white must be rejected) guards the helper.

## Known limitations

- **No server behind it.** `DashboardController` does not exist (T112), so `GET /dashboard` answers
  500 in the running instance. The client is tested against the contract's shape, not a live answer.
- **The contract does not describe the inside of a bucket.** `contracts/ocs-api.yaml` types each of
  `purchaser`/`costCenter`/`senior`/`finance`/`admin` as `additionalProperties: true` with no named
  properties, while FR-061 enumerates the content. The client therefore reads the bucket as the open
  object the contract declares and renders every key, instead of hard-coding a field list that could
  contradict T106. The consequence is honest: a key the catalog does not name shows as its own
  identifier, and a structured value shows in full as JSON rather than being dressed up as a headline
  number. When T106's real shape lands, `dashboardMetrics()` is the single place to add a kind.
- **No Persian catalog entries.** Keys render as their own identifiers until T119 exists.
- **Not routed by this slice.** `src/router/index.ts` already registers the dashboard route against
  this view, so nothing had to change; that file stays off limits to this lane regardless.
- **Browser-measured clauses of FR-071 are not claimed.** No horizontal-scroll measurement, no
  rendered glyph size, and no rendered contrast — jsdom has neither a layout engine nor font metrics.
  T122's browser matrix and T123's manual matrix own those, as they do for every other view.
- **T104's consolidated `DashboardReports.spec.ts` is untouched.** This suite is
  `tests/Frontend/Component/Dashboard.spec.ts`, so T104 remains free to consolidate it with the
  reporting half; it was not consumed here.

## Next incomplete task in this area

T113 is now delivered on the client in both halves. What remains for US7 is server-side and
out of scope for a host-only frontend slice: T106 (`DashboardQuery`/`ReportQuery`), T107–T110
(exporters), T111 (`ExportService`), T112 (controllers and routes), and T104's consolidated
contract/Vue tests.
