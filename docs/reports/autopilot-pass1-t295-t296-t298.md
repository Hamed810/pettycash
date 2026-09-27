# Pass 1 — T295, T296, T298: the audit writer's dead path, and three false claims in the record

**Tasks**: T295 (complete), T296 (complete), T298 (complete)
**Feature**: PettyCash V1 Core Workflow (`specs/001-pettycash-v1-core`)
**Date**: 2026-09-27
**Pass**: Autopilot implementation pass 1 of 50 (resumed from checkpoint `ac1a12c`)

All three came out of the Phase 40 convergence and are the same category of work: a record that does
not match the code.

## T296 — the two dead audit-writer paths are gone

`AuditWriterService` declared four public methods. Only one — `append()`, the method the `AuditWriter`
port actually declares — had any caller. `appendTransaction()` and `appendConfiguration()` had **zero**
call sites, and both did `$actor->role->value` on an `ActorSnapshot` whose `$role` is typed `string`, so
on PHP 8.5 each would emit `Attempt to read property "value" on string` and persist a **null actor
role**, where FR-052 requires the role at the time of the event to be retained.

**Deleted**, the smaller of the two options the task offered, along with the now-unused import. The
alternative — re-typing `ActorSnapshot::$role` as the `Role` enum — was rejected, and doing both is what
the task forbids. `AuditWriterService` is now 56 lines declaring only `__construct` and `append()`.

**One thing that looked like a second dead class and was not.** `grep 'new ActorSnapshot('` returns
zero across `lib/` and `tests/`, which reads as conclusive. It is not: `WorkflowDecision::actor()`
constructs it by fully-qualified name, and `FinanceSettlementService` and `ManagementReviewService`
import it. `ActorSnapshot` is live and was kept. The lesson is the same one T138 hit — a `grep` that
returns nothing is evidence of a spelling, not of an absence.

That the two removed methods were the *only* place in `lib/` treating an `ActorSnapshot` role as an
enum is now asserted, and it is worth being precise about the exemption: `RoleAssignment::$role` **is**
the `Role` enum, so `$roleAssignment->role->value` at `DbRoleAssignmentRepository.php:106,127` and
`SessionController.php:110` is correct and the guard deliberately does not report it.

### The regression guard

Constitution V requires a fix to carry a regression test or a repeatable procedure. A *behavioural*
test could not do this: nothing calls the removed methods, so no invocation can fail. The only thing
that can detect their return is the shape of the class.

`tests/Unit/Audit/AuditWriterSurfaceTest.php`, 5 tests:
- the writer declares exactly `['__construct', 'append']`, asserted as data so adding a method is a
  visible act in a diff rather than something only a pattern notices;
- both removed names are asserted gone **by name**, so the failure message says what was removed and why;
- the class implements the port the container registers it against;
- `ActorSnapshot::$role` is declared `string` and not the enum — the declaration the removed methods
  contradicted;
- a tree-wide scan of `lib/` for `$actor->role->value` / `$snapshot->role->value`, with the
  `RoleAssignment` shape deliberately excluded.

Reintroducing `appendTransaction()` with the original bug fails **3**. Restored;
`git diff` confirms the change is 70 deletions and one import removal.

## T295 — T028 reopened

T028 was `[x]` on the claim "Implement transaction **and configuration** audit writers". Only the
transaction side existed. The configuration writer had zero call sites, and
`DbAuditEventRepository::save()` targets `pettycash_transaction_audit` unconditionally, so even if it
had been called it would have written a configuration event into the *transaction* stream, against
FR-056. T028 is now `[ ]` with the evidence inline.

**The evidence T295 cited moved during this pass**, which is worth recording rather than papering
over. T295's text says `grep -rn appendConfiguration lib/ tests/` "returns only the declaration". T296
ran first and deleted the method, so there is now no declaration at all, and the only three remaining
references in the repository are the guard assertions that keep it from returning. The claim is
stronger than when T295 was written, not weaker: there is now no configuration writer of any kind.

## T298 — three notes asserted completions that never happened

Found by a mechanical cross-check, not by reading. Three notes claimed another task was already `[x]`:

| Claim | Made by | Reality | Correct fix |
|---|---|---|---|
| T067 already complete | T195 (itself `[x]`) | created `[ ]` by `e074cff`, never checked | **mark the checkbox** — the file exists, 11 tests, green |
| T068 already complete | T196 (itself `[x]`) | same | **mark the checkbox** — 9 tests, green |
| T053 already complete | T032's table | T053 is `[ ]` and **correctly so** | **fix the note**, not the checkbox |

`git log -L` is what settled it: `e074cff` (2026-09-22) *created* T067 and T068 as `[ ]`, and
T195/T196 were added as `[x]` on 2026-09-26 asserting otherwise. The claims were false the day they
were written, and because the noting tasks are themselves checked, the error had already propagated
twice over.

T053 is the opposite case and is why this was one task with two directions rather than a blanket
tidy-up: T286 reopened it for good reason — its three browser-measured FR-071 clauses cannot be settled
under jsdom and belong to T116/T122/T123 — so marking it complete would be a *false completion*,
while leaving T067/T068 unchecked is a *false gap*. T196's "7 passing tests" was also corrected to 9.

T032's rows for T195/T196 were reworded too. Their claims are now true, but "already `[x]`" remains
misleading, since the tasks were not already complete when the row was written.

### The guard, and one problem with it

`tests/Unit/Workflow/TaskStateConsistencyTest.php`, 3 tests. The cross-check that found all three is
four lines of Python, which is exactly why it was never run as part of anything.

**It failed on first run**, catching a fourth occurrence I had missed: T032's T181 cell. My original
Python used a slightly different pattern than the test does, and the manual fix had only covered the
one line that script had flagged. That is the case for having written the test rather than repeating
the ad-hoc check.

**It then failed for a second, better reason.** Having fixed T181, the test flagged a line inside
T298's own text, which quoted the old cell verbatim to describe what had been corrected — the guard
reading my description of the defect as the defect. The notes were reworded to describe the claims
rather than reproduce them. A test that scans prose will always risk this, and the fix is to not embed
a literal that trips your own guard, not to weaken the guard.

`tasks.md` is discovered by globbing `specs/`, not hardcoded, and the test refuses to guess if a
second feature directory ever appears. Mutating a row to claim open T053 is complete fails it;
restored, green.

## Verification

`scripts/dev/phpunit` — **379 tests / 1831 assertions / 1 failure**, the failure being the pre-existing
T275 artifact conflict. Before this pass: 376. No regressions.

| Mutation | Result |
|---|---|
| Reintroduce `appendTransaction()` with the `$actor->role->value` bug | fails **3** |
| A note claims open T053 is complete | fails **1** |

## Limitations

- Psalm and `php-cs-fixer` remain unavailable (no `composer` on the container PATH; `Nextcloud\CodingStandard\Config` not installed), and Psalm's 758-error baseline is pre-existing. Not treated as a product failure.
- The IDE language server still reports `AuditWriterService.php:79` and `:112` and `RetentionPolicyTest.php`'s `ReflectionClass::IS_PUBLIC`. All three are stale — the first two lines no longer exist, and the third was verified replaced with `ReflectionMethod::IS_PUBLIC`. Authoritative evidence is `scripts/dev/phpunit` and `php -l`.

## Next

T294 (CRITICAL) is still gated on T253's naming decision. The unblocked concrete work is the US5
configuration slice (T081–T089) and the US7 export slice (T105–T111), both recorded absent by
convergence.
