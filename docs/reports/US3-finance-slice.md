# US3 Finance slice — T287, T058, T065, and three reopened tasks

**Tasks**: T287 complete · T058 complete · T065 partial · **T060, T061, T063 reopened**
**Feature**: PettyCash V1 Core Workflow (`specs/001-pettycash-v1-core`)
**Date**: 2026-09-26
**Preceded by**: `docs/reports/T288.md` (PHP verification trustworthiness), which this slice used
throughout — its own first red measurement would otherwise have been untrustworthy.

## The finding that shaped this slice

T065 asks for an account movement view. The endpoint it needs was **declared but did not exist**:
`appinfo/routes.php` lists four account routes and `AccountController` implemented one, so
`account#movements` resolved to a method that was not there. Confirmed live, not inferred:

```
GET /ocs/v2.php/apps/pettycash/api/v1/accounts/{id}/movements  ->  500
```

A declared route answering 500 is worse than an absent one. The contract says the endpoint exists, a
caller can construct the request, and the failure arrives as a *server error* rather than a 404 that
would have said plainly that nothing had been built. Nothing noticed, because the only account
assertion in the suite was the listing.

Building it then exposed two more defects in the same path, both in **checked** tasks:

**`DbAccountMovementRepository` could not read or write a single movement.** It read and wrote a
`currency_code` column that the migration has never created. `mapRow()` raised
`Undefined array key "currency_code"` and `CurrencyCode::fromString(): Argument #1 must be of type
string, null given`; `save()` failed on an unknown column. `data-model.md` defines the entity
*without* that column and draws `Currency 1 ── * Petty-Cash Account 1 ── * Account Movement` — the
currency belongs to the account. The repository was drift against the approved data model, and the
repair is a join, not a migration: inner joins to the account and its currency, plus a **left** join
to the revision (an inner join there would silently drop every charge, since `source_revision_id` is
null for exactly those rows).

**FR-040's consumption movement is never written.** `FinanceSettlementService::recordConsumption()`
updates the account balance and stops. `AccountMovementRepository` is injected into the class and
used **nowhere** in it. So a finance approval moves the balance and leaves no ledger row — and the
unique `source_revision_id` index, which is what T056's "exactly-one consumption" test depends on, is
never exercised by anything.

**`sourceRevisionId` leaked a surrogate key.** The contract declares it `format: uuid`; the
repository returned `'300'`. A raw auto-increment key is not resolvable by any client, and once
clients depend on it the leak cannot be withdrawn. Fixed by the same left join, so the public UUID is
returned.

So three checked tasks are reopened with the specific remaining work named, rather than left looking
finished. **T060, T061 and T063 stay open.** The settlement *write* path is not repaired here: it
needs RED tests first (`T056` owns "exactly-one consumption"), and writing them is the honest way to
establish that a movement is recorded exactly once.

## T287 — the finance-queue assertion leaves the US2 suite

Moved to `tests/Frontend/Component/FinanceReview.spec.ts`, the file T058 owns. `ManagementReview.spec.ts`
is **24/24** and the whole frontend gate went from *1 failed / 127 passed of 128* to green — the first
time it has been.

`should deny administrator without finance role from finance actions` **stays** in the US2 file on
purpose, and that is the judgement the task asked to be recorded either way. It mounts
`FinanceQueueView`, but what it asserts is an authorization property (`T045`/FR-045), which is
cross-cutting rather than US3 queue content. Moving it would be the same category error in reverse.
It is recorded as weak rather than fixed: it seeds an empty queue and then asserts no decision button
is present, which an empty queue satisfies even with authorization entirely absent.

Both moved assertions were corrected, not relocated, and one correction was **worthless at first**:

- The red one asked for `[data-testid="queue-item"]`, a test id no component has ever emitted. The
  views emit `queue-row-{id}`; inventing a `queue-item` attribute would have tested nothing.
- The other asserted the absence of `[data-testid="cost-center-filter"]`, which no queue view has ever
  had — it passes just as well against a view that renders nothing. FR-034/T062's substantive claim
  is that finance asks for the *whole* queue, which is observable in the request, so that is asserted.
- **The first version of that request assertion read `config.url` and could not fail.** Axios keeps
  query parameters in `config.params` and serialises them at send time, so a `costCenterId` added to
  the request was invisible to it and the suite passed 4/4 with the mutation applied. Re-read from
  `config.params` and asserting the key set is exactly `['page','pageSize']`, the same mutation fails
  it. Found by mutation-checking, not by reading the test.

The new suite also drives the component **through the transport** rather than seeding the store. The
US2 file seeds `reviewsStore.costCenterQueue` and stubs `fetchCostCenterQueue`, which works only
because the stub is what stops the mount-time load from overwriting the seed — it makes the component
a passenger on state the test arranged. The first draft of the new file made the same mistake and
rendered zero rows, which is how it surfaced.

## T065 — the settled-versus-management labelling

`contracts/exports.md` states the rule the component exists to enforce: *"Reports label the effective
stage and do not describe pre-finance approval as settled."* FR-036 makes the senior decision final
as a **management** decision; FR-040 makes the consumption movement the **settlement**. Those are two
different things, and nothing in the UI distinguished them.

`src/components/review/SettlementLabel.vue` derives `settled` from `finalOutcome` **alone**, so a
senior approval is `false` by construction rather than by omission. Four states, each with its own
wording, icon and `data-settled` attribute, all in text so the state survives a colour-blind reader, a
monochrome print and a high-contrast theme (WCAG 1.4.1).

`FinanceReviewView.vue` shows that label, the management decision finance acts on top of (**both**
decisions — the cost-centre one is advisory under FR-034 and filtering it out would hide exactly what
that requirement keeps visible), and an explanation when approve is unavailable on a senior-rejected
transaction (FR-039). The client never restates the policy: the capability comes from the server and
the buttons are built from it, so the two cannot disagree.

`AccountView.vue` shows the balance and the ledger. Two judgement calls worth naming:

- **A negative balance is shown as an ordinary value**, with a note, not as an error. FR-027 permits
  negative balances *and* forbids the balance from blocking submission, so flagging one would
  contradict a requirement the rest of the app obeys.
- **The movement sign is rendered explicitly.** `formatMoney` prints a magnitude, so a charge of 100
  and a consumption of 100 are the same string without it — and the direction of the money is the
  entire content of a ledger.

**A routing detail that would have failed silently.** `/reviews/finance/:transactionId` overlaps
`/reviews/:stage/:transactionId`, and `vue-router` ranks a static segment above a parameter, so the
finance row resolves to the new view. But the *queue* was pushing `{ name: 'review-detail' }`, which
resolves to a working page with no labelling — an officer would land somewhere plausible and wrong
with no way to tell. Nothing caught this: a router test proves the route resolves, not that the link
names it. A test now asserts the pushed route name, and reverting the view fails it.

## T058 — the tests, and what they do not prove

25 tests, all four subjects. Every one was **mutation-checked**, because a relocated assertion that
stops testing anything is the failure mode T287 exists to correct:

| Mutation | Caught |
|---|---|
| Treat a senior approval as settled | yes |
| Treat a finance rejection as settled | yes |
| Drop the approve-refusal explanation | yes |
| Drop the movement sign | yes |
| Drop the negative-balance note | yes |
| Drop the settlement label from the finance page | yes |
| Filter out the advisory cost-centre decision | yes |
| Add an automatic retry (FR-075 violation) | yes — 2 tests |
| Point the finance row at the generic detail view | yes |
| Point it nowhere | yes |
| Remove a view's mobile breakpoint | yes |
| Add a `min-width: 480px` | yes |
| A literal `12px` font-size | yes |

Two mutations initially **survived** and both were informative:

- **The auto-retry check looked insensitive.** `flushPromises()` drains macrotasks as well as
  microtasks, so a retry on a short timer has already fired by the time `mountView` returns, and the
  "nothing else was requested" assertion alone could not see it. The count taken immediately after
  mount is what carries the requirement. One earlier reading of this mutation was mine, from output
  truncated by `head -5`, which reported a pass that had not happened.
- **Dropping the `font-size` declaration from a component passes**, and correctly so: the component
  then inherits the floor from `.pettycash-shell`, which satisfies FR-071. No fix was manufactured.

The store's `movements.value = []` on a ledger failure is **not** separately observable through the
view — the template's branch order already prevents a stale list beside a fresh error, and that is
what the test verifies. The store line is defence in depth and is not claimed as verified.

## FR-071's three clauses are declarations, not measurements

Same position as `T053`. jsdom has no layout engine and no font metrics, so an overflow is not
observable and a contrast ratio is never computed. What is asserted is what can be: a declared mobile
breakpoint reaching 390px in both spellings, no `min-width` forcing a wider viewport, and no declared
font-size below 16px resolved through the app's own tokens. The measurements belong to `T116`'s file
and `T122`/`T123`'s browser and manual matrices, and `T065` is left **partial** for exactly that
reason.

## Verification

| Check | Result |
|---|---|
| `scripts/dev/phpunit` | **202 tests / 1006 assertions / 1 failure** — the failure is T275, unchanged |
| `npx vitest run` | **181 passed / 181**, 6 files (was 1 failed / 127 passed of 128) |
| `npm run typecheck` | clean |
| `npm run stylelint` | clean (2 errors introduced and fixed during the slice) |
| `npm run lint` | 0 errors / 146 warnings — 105 pre-existing plus 41 from the new files, all in the two rule classes every committed view already trips (`vue/html-closing-bracket-newline`, `vue/script-indent` on `switch` inside `<script setup>`; `StatusBadge.vue` warns identically) |
| `npm run build` | clean |
| `npm run check:portability` | OK |
| `AccountControllerTest` | 12/12, 69 assertions |
| `FinanceReview.spec.ts` | 25/25 |

Two verification notes, because both nearly produced a wrong conclusion:

- `npm run typecheck` **failed** after the tests were green — three `string | undefined` errors the test
  runner does not catch. Fixed, and the gate then passed. A green `vitest` is not a typecheck.
- `js/` and `css/` are **tracked build output**, so rebuilding changed every content hash and the
  working tree shows mass deletions plus new untracked bundles. That is the repository's existing
  arrangement, not a regression.

## Known limitations

- **The settlement write path is still broken and is not repaired here.** `create` and `charge` still
  answer 500; no finance approval records a consumption movement. This is the most important thing
  this slice found and it is deliberately left as RED-able work under reopened T060/T061/T063 rather
  than patched without tests.
- `npm run test:e2e` remains red on a pre-existing harness defect, not on this slice:
  `playwright.config.ts` defines no `storageState` and no `globalSetup`, so the suite drives the app
  unauthenticated and every test times out waiting for the app UI. Verified pre-existing by re-running
  with these changes stashed. **No open task covers it** — a task-state gap worth a convergence task.
- The four queue views each define a **local `formatMoney`** duplicating `src/utils/money.ts`. The new
  views use the shared one. Not consolidated here; the drift is pre-existing and cosmetic-free to leave.
- Contract fixtures for the ledger were added with a `foreignAccount` owned by a uid that matches no
  Nextcloud account. That is sufficient because the endpoint compares the string to the session uid and
  never resolves it, and it avoids creating real users in shared state for an ownership test.

## Next dependency-valid work

1. **T061 / T060 / T063, in that order** — write the RED tests for a recorded consumption movement
   (T056's "exactly-one consumption" is already scoped for this), repair `recordConsumption()` to call
   `$movementRepository->save()`, then implement `account#create` and `account#charge` so the declared
   routes stop answering 500.
2. **T057** — contract tests for the shared finance queue and finance decision responses, in
   `tests/Contract/Controller/FinanceReviewControllerTest.php`, which does not yet exist.
3. **T053** and **T065** stay open on their browser-measured FR-071 clauses until T116/T122/T123 run.
4. A convergence task for the unauthenticated e2e harness.
