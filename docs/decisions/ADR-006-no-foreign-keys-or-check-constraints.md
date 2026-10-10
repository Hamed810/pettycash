# ADR-006: V1 declares no foreign keys and no check constraints

- **Status**: Accepted (2026-10-07, T392)
- **Decides**: The schema-constraint posture behind SC-014's naming clause
- **Traces to**: SC-014, FR-077, FR-078, FR-079, Constitution III, Constitution IV

## Context

SC-014 requires that "100% of V1 indexes, unique constraints, **foreign keys**, and check constraints carry
`pettycash_*` names". `data-model.md:9-11` goes further, mandating the convention for all four kinds and
naming `fk` and `chk` as valid kinds.

Measured against the live schema on the shared instance before this decision was taken:

| kind | declared | offenders (not `pettycash_*`) |
|---|---|---|
| tables | 15 | — |
| primary keys | 15 (all `PRIMARY`) | n/a — MariaDB cannot name a `PRIMARY KEY` |
| unique constraints | 23 | **0** |
| indexes (all kinds, `SHOW INDEX`) | 32 | **0** |
| **foreign keys** | **0** | 0 |
| **check constraints** | **0** | 0 |

`grep -c addForeignKeyConstraint lib/Migration/` returns 0 and the same for check constraints.

So the naming clause is **satisfied but vacuous** for two of its four kinds: there is nothing to name. A
gate that cannot fail is not a gate, and no test asserted any of this — `grep` for `information_schema`,
`SHOW CREATE TABLE` or `_fk` across `tests/` found nothing before this ADR.

## Decision

**V1 declares no foreign keys and no check constraints, and the application layer owns referential
integrity. SC-014's naming clause is retained and is now enforced against what actually exists.**

This is a decision about what V1 *declares*, not a claim that the constraint is satisfied today by
accident.

Three consequences, each stated rather than left for a reader to infer:

1. **The naming clause keeps its teeth for the two kinds that exist.** A test reads the real schema and
   asserts that every unique constraint and every index carries a `pettycash_*` name. That is the part of
   SC-014 that can fail.
2. **The empty kinds are recorded, not left ambiguous.** The same test asserts the foreign-key and
   check-constraint counts are **zero**, so this ADR is falsifiable. If a later task adds one, the test
   fails and the author must either name it `pettycash_<table>_<subject>_fk` or amend this ADR. Silence
   stops being an option, which is the whole point of writing it down.
3. **SC-014's wording is not amended here.** It says what a conforming schema must look like, and this
   schema conforms. Amending a requirement to match an implementation is the move this repository's
   Constitution I exists to prevent, and a vacuously-passing clause is not the same as a false one.

## Why not add foreign keys

Several absences are **deliberate and load-bearing**, and adding a constraint would break existing
verification:

- `roundtrip-runner.php:143` states that `pettycash_sheets.owner_uid` is a plain 64-character column
  **because a uid that names no Nextcloud user is a legal value** and its probe owner depends on that.
  An FK to `oc_users` would forbid it.
- `RepositoryRoundTripTest.php:1680` states that the leak post-condition relies on the schema declaring no
  foreign keys, "so a delete does not fail on a dependent".
- T391 established the same point from the other side: a deleted user's account rows must **survive**
  their owner, which a cascading FK would forbid outright and which Constitution IV requires.

A foreign key to `oc_users` would therefore contradict three existing guarantees, not merely add
integrity.

Foreign keys *within* pettycash (sheets → accounts, transactions → sheets) are a different question and are
**not** ruled out by this ADR. They were simply never declared, and no requirement asks for them. If a
future task wants them, it must argue the case against `data-model.md:380`'s existing promise that the
repositories prevent destructive cascades — because a real cascade *is* what an FK would add.

## Why not add check constraints

`data-model.md` does not identify a single invariant that needs database enforcement. The ones that do
exist — active `IRR` at `minor_unit = 0`, positive amounts, one active account per (user, currency) —
are already enforced in the domain and verified through real-MariaDB round trips, and
`pettycash_accounts_user_currency_uniq` already exists for the last of them as an index. Adding check
constraints would duplicate enforcement the application owns, on the database's dialect, which FR-077
treats as a portability defect rather than a portability win.

## Consequences

- `tests/Integration/Persistence/SchemaConstraintNamingTest.php` (new) reads the real schema and asserts
  both halves: the naming convention holds for what exists, and the two empty kinds are still empty.
- `data-model.md:380`'s claim is corrected. It said repositories "enforce referential integrity and
  prevent destructive cascades across historical entities"; nothing enforced either, and T391's listener is
  where the one real guarantee now lives.
- T391's ADR-005 remains the owner of what a *deleted user* leaves behind. This ADR owns the schema-level
  question only.

## What this ADR does not decide

Whether an orphaned row — one whose owning user no longer exists — should ever be reported, swept, or
made visible to an administrator. ADR-005 decides only that it is deactivated rather than deleted.
Reporting it is `T130`'s territory for evidence and nothing's territory for accounts, and that gap is
recorded on T391 rather than answered here.