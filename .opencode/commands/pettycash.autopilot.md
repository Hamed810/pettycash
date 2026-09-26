---
description: Orchestrate PettyCash Spec Kit convergence and implementation with periodic reconciliation: converge first, then re-converge after every five implementation passes, up to fifty implementation passes total.
agent: build
---

# PettyCash Autopilot

Coordinate the existing PettyCash Spec Kit workflows without replacing or duplicating them.

Canonical sub-workflows:

- `.agents/skills/speckit.converge.md`
- `.agents/skills/speckit.implement.md`

Operational authority:

- `AGENTS.md`

Feature intent and governing constraints come from the active Spec Kit artifacts and constitution,
resolved by the canonical sub-workflows.

## Core Rules

- Read `AGENTS.md` before starting.
- Use the two canonical skill files above directly as procedural sub-workflows.
- Do not search for alternate Spec Kit skill locations or guess alternate workflow names.
- Do not hardcode a feature directory. Let the canonical Spec Kit prerequisite workflow resolve
  `FEATURE_DIR`.
- `AGENTS.md` is operational context, not feature intent.
- Convergence owns repository-wide reconciliation.
- Implementation stays focused on the active task plan.
- PHP/Nextcloud commands must follow the `scripts/dev/*` wrapper rules in `AGENTS.md`.
- Do not duplicate checks, task-selection logic, RED rules, or runtime instructions already owned
  by the canonical convergence and implementation skills.

If either canonical skill file is missing or unreadable, stop and report the exact path.

## Canonical Outcomes

Convergence must resolve to exactly one of:

- `converged`
- `existing_tasks_remaining`
- `tasks_appended`

`converged` means no implementation gap remains for the active feature scope.

If convergence lists remaining required work, do not reinterpret that result as `converged`.

## Implementation Pass

One implementation pass means one complete invocation of:

`.agents/skills/speckit.implement.md`

A single implementation pass may complete one or multiple tasks according to the implementation
skill's dependency, phase, and task-selection rules.

Do not count individual task completions as separate Autopilot passes.

## Cadence

Use this cadence:

```text
Initial Convergence
        ↓
Implementation × up to 10
        ↓
Convergence
        ↓
(repeat 10-pass implementation blocks + convergence)
        ↓
Implementation pass 50 maximum
        ↓
Final Convergence
        ↓
Stop
```

Maximum implementation passes per Autopilot run:

`50`

Normal reconciliation interval:

`5 implementation passes since the last convergence`

The total implementation counter never resets.

The "since last convergence" counter resets to zero after every normal or early convergence.

## Early Convergence

Do not wait for the five-pass interval when implementation discovers a material planning or
repository-state issue that requires repository-wide reconciliation.

Run convergence early when any of these occur:

- a new requirement or architecture decision is discovered that is not represented in the active
  spec/plan/tasks;
- task dependencies or ordering appear materially wrong;
- a task-state mismatch affects what should be implemented next;
- implementation discovers a cross-cutting repository drift outside the active task;
- implementation reports that no actionable task can be selected even though convergence said work
  remains;
- the active task reveals that an existing convergence finding is materially stale or incomplete.

Do not early-converge for ordinary targeted debugging or an expected active-task failure.

A real blocker requiring user action, credentials, destructive approval, or unresolved external
state stops Autopilot instead of forcing another convergence pass.

## Controller State

Track:

```text
implementation_total = 0
implementation_since_convergence = 0
last_convergence_outcome = null
```

## Execution Flow

### 1. Initial Convergence

Read and execute:

`.agents/skills/speckit.converge.md`

Preserve its prerequisite checks, repository assessment, duplicate-task protection, append-only
rules, hooks, and canonical outcome semantics.

Capture the actual outcome.

If:

```text
converged
```

stop successfully.

If:

```text
existing_tasks_remaining
```

or:

```text
tasks_appended
```

continue to implementation.

Any other or internally inconsistent outcome is a blocker.

### 2. Run an Implementation Pass

Before starting, if:

```text
implementation_total >= 50
```

go to Final Convergence.

Otherwise read and execute:

`.agents/skills/speckit.implement.md`

Preserve its task selection, dependencies, phase ordering, targeted investigation, runtime rules,
verification requirements, hooks, task updates, and completion semantics.

After a successful implementation pass:

```text
implementation_total += 1
implementation_since_convergence += 1
```

If implementation is blocked by a real blocker, stop and report it.

### 3. Decide Whether to Re-Converge

Run convergence when either condition is true:

```text
implementation_since_convergence >= 5
```

or an Early Convergence condition was triggered.

Otherwise immediately run the next implementation pass.

### 4. Periodic / Early Convergence

Execute the canonical convergence sub-workflow again.

Then reset:

```text
implementation_since_convergence = 0
```

If outcome is:

```text
converged
```

stop successfully.

If outcome is:

```text
existing_tasks_remaining
```

or:

```text
tasks_appended
```

continue implementation while:

```text
implementation_total < 50
```

If the 50-pass limit has already been reached, proceed to Final Convergence and stop afterward.

### 5. Final Convergence

After the 50th implementation pass, ensure one final convergence pass is executed unless the 50th
pass was immediately followed by a convergence pass already.

The final convergence determines the end state.

If:

```text
converged
```

report successful convergence.

If:

```text
existing_tasks_remaining
```

or:

```text
tasks_appended
```

stop at the safety limit and report the remaining task IDs/findings.

Do not start a 51st implementation pass automatically.

## Continuous Execution

Within one Autopilot invocation, continue automatically between implementation passes and scheduled
convergence passes.

Intermediate implementation or convergence summaries are progress reports, not instructions for the
user to manually invoke the next workflow.

Only stop for:

- `converged`;
- a real blocker requiring user action;
- an invalid/ambiguous canonical workflow outcome;
- the 50-implementation-pass safety limit after final convergence.

## Scope Discipline

Do not add orchestration logic that duplicates the canonical skill files.

In particular:

- do not perform a separate repository-wide audit before implementation;
- do not independently select tasks with ad-hoc grep/checkbox scans;
- do not recreate convergence findings outside `speckit.converge.md`;
- do not recreate implementation verification rules outside `speckit.implement.md`;
- do not duplicate existing convergence tasks;
- do not redo work already verified as implemented.

Autopilot coordinates; the canonical skills decide.

## Blockers

Stop when safe continuation requires user action or would violate project rules.

Typical blockers include:

- missing external credentials or secrets;
- destructive or shared-environment mutation requiring explicit approval;
- unresolved architecture/specification contradiction;
- missing canonical artifact or skill file;
- missing/non-executable required wrapper;
- infrastructure failure that cannot be safely recovered under `AGENTS.md`;
- repeated unexplained failure with no new evidence.

Report:

- what operation stopped;
- the exact blocker;
- the active task or convergence finding;
- the minimum user decision/action required.

## Final Report

At the end of the Autopilot run, report concisely:

- final status: `converged`, `blocked`, or `safety_limit`;
- implementation passes executed out of 50;
- convergence passes executed;
- task IDs completed during the run;
- convergence tasks appended, if any;
- significant verification performed;
- remaining task IDs/findings;
- blocker or non-blocking limitations, if any.

## Done When

- [ ] `AGENTS.md` execution boundaries were respected
- [ ] canonical convergence and implementation skills were used
- [ ] initial convergence ran before implementation
- [ ] convergence ran after each block of at most five implementation passes
- [ ] early convergence ran only for material reconciliation needs
- [ ] no more than fifty implementation passes ran automatically
- [ ] a final convergence pass determined the end state
- [ ] no duplicate orchestration logic replaced the canonical skill rules
- [ ] final status was reported
