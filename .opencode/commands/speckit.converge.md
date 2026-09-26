---
description: Reconcile the current codebase and task state with the active feature artifacts, repair trustworthy task-state drift, and append only genuinely novel remaining work.
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty).

## Pre-Execution Checks

**Check for extension hooks (before convergence)**:

- Check if `.specify/extensions.yml` exists in the project root.
- If it exists, read it and look for entries under the `hooks.before_converge` key
- If the YAML cannot be parsed or is invalid, do not skip silently: tell the user that `.specify/extensions.yml` could not be read (include the parser error) and that no hooks were checked, including any mandatory (`optional: false`) hooks registered there, then continue normally
- Filter out hooks where `enabled` is explicitly `false`. Treat hooks without an `enabled` field as enabled by default.
- For each remaining hook, do **not** attempt to interpret or evaluate hook `condition` expressions:
  - If the hook has no `condition` field, or it is null/empty, treat the hook as executable
  - If the hook defines a non-empty `condition`, skip the hook and leave condition evaluation to the HookExecutor implementation
- For each executable hook, output the following based on its `optional` flag:
  - **Optional hook** (`optional: true`):

    ```text
    ## Extension Hooks

    **Optional Pre-Hook**: {extension}
    Command: `/{command}`
    Description: {description}

    Prompt: {prompt}
    To execute: `/{command}`
    ```

  - **Mandatory hook** (`optional: false`):

    ```text
    ## Extension Hooks

    **Automatic Pre-Hook**: {extension}
    Executing: `/{command}`
    EXECUTE_COMMAND: {command}

    Wait for the result of the hook command before proceeding to the Goal.
    ```
    After emitting the block above you MUST actually invoke the hook and wait for it to finish before continuing. Run it the same way you would run the command yourself in this agent/session (the invocation may differ from the literal `{command}` id shown above, e.g. a skills-mode agent runs it as `/skill:speckit-...` or `$speckit-...`). Emitting the block alone does not run the hook.

- If no hooks are registered or `.specify/extensions.yml` does not exist, skip silently

## PettyCash Convergence Context

Before assessing implementation state:

- **REQUIRED**: Read `AGENTS.md` for runtime, safety, Docker ownership, and tool-execution rules.
  It is operational context, not product intent.
- Use the active feature's `spec.md`, `plan.md`, `tasks.md`, approved contracts, and constitution
  as intent/governance sources according to the source-of-truth ordering in `AGENTS.md`.
- Use the most direct trustworthy evidence available. Prefer source/tests/contracts/runtime evidence
  over inference. Use LSP, targeted search, codebase-memory, or other tools when they materially help;
  do not force a specific discovery sequence.
- Follow `AGENTS.md` for every verification command.
- Convergence assesses present repository reality. Do not rely on git history to prove whether a
  requirement is currently satisfied.

## Goal

Reconcile intended behavior, implementation reality, and task state.

Convergence should:

1. identify meaningful gaps between the active Spec Kit artifacts and current implementation;
2. distinguish product defects from test/harness, infrastructure, and artifact-state defects;
3. reuse or repair existing tasks before creating new ones;
4. group multiple symptoms that share one verified root cause into one remediation unit;
5. append new tasks only for genuinely novel remaining work not already represented.

The purpose is to reduce drift and backlog ambiguity, not to generate a new task for every failing
assertion or every observation.

This command requires a complete `tasks.md` produced by `/speckit.tasks`.

## Operating Constraints

Convergence MUST NOT:

- modify `spec.md` or `plan.md`;
- modify application code;
- silently resolve an actual spec/contract contradiction;
- delete task lines, reorder task history, or reuse/renumber task IDs.

Convergence MAY make narrow, evidence-backed repairs to `tasks.md`:

- change a task checkbox from `[ ]` to `[X]` when the task's required behavior and its meaningful
  verification are both already satisfied;
- change `[X]` back to `[ ]` when concrete evidence proves the task's required behavior or
  verification is not actually complete;
- repair an unambiguous duplicate Convergence phase-number/header collision without moving task lines
  or changing task IDs;
- append a new Convergence phase only when genuinely novel uncovered work remains.

If evidence is insufficient, leave existing task state unchanged and report the uncertainty.

**Constitution Authority**: `.specify/memory/constitution.md` is non-negotiable. A concrete violation
of a MUST principle is a convergence finding. Do not invent additional obligations from general best
practice when they are not present in the active artifacts.

## Convergence Mode

Use **normal convergence** by default.

Use **full convergence** only when the user explicitly requests `full`, `audit`, `release`, or an
equivalent repository-wide/milestone review in `$ARGUMENTS`.

### Normal Convergence

Bound assessment to:

- the active or next dependency-valid user story / feature slice;
- open tasks and convergence tasks that block or belong to that slice;
- files/components/contracts touched by that slice;
- failing gates that directly affect that slice;
- cross-cutting plan/constitution constraints exercised by that slice.

Do not re-audit completed unrelated user stories merely because they exist in `tasks.md`.

### Full Convergence

Assess the complete feature scope:

- all buildable FR/SC obligations;
- all user-story acceptance scenarios;
- plan decisions and data-model constraints;
- approved contracts;
- applicable constitution MUST/SHOULD rules;
- every existing task regardless of checkbox state.

Use full convergence for milestone/release reconciliation, not as the routine Autopilot cadence.

## Execution Steps

### 1. Initialize Convergence Context

Run `.specify/scripts/bash/check-prerequisites.sh --json --require-spec --require-tasks --include-tasks`
once from repo root and parse `FEATURE_DIR` and `AVAILABLE_DOCS`.

Canonical paths:

- SPEC = `FEATURE_DIR/spec.md`
- PLAN = `FEATURE_DIR/plan.md`
- TASKS = `FEATURE_DIR/tasks.md`
- DATA_MODEL = `FEATURE_DIR/data-model.md` if present
- CONTRACTS = `FEATURE_DIR/contracts/` if present
- RESEARCH = `FEATURE_DIR/research.md` if present
- QUICKSTART = `FEATURE_DIR/quickstart.md` if present
- CONSTITUTION = `.specify/memory/constitution.md` if present
- AGENTS = `AGENTS.md`

Do not search for alternate Spec Kit artifacts or guess a different feature directory.

If required artifacts are missing, stop with the corresponding prerequisite command:
`/speckit.specify`, `/speckit.plan`, or `/speckit.tasks`.

### 2. Load Artifacts with Progressive Disclosure

Load only what the selected convergence mode requires.

From spec:
- relevant FR/SC items;
- relevant user stories and acceptance scenarios;
- relevant edge cases.

From plan/research/data-model/contracts:
- architecture/stack decisions;
- data and persistence constraints;
- API/behavior contracts;
- touch-points and technical decisions.

From tasks:
- task IDs, phase/story grouping, dependency information, checkbox state, task descriptions,
  verification expectations, and convergence task ownership.

From constitution:
- applicable normative requirements.

From AGENTS:
- operational/runtime/safety rules only.

### 3. Build the Intent and Coverage Map

For each obligation in scope, map:

```text
intent source
→ implementing code/surface
→ relevant tests/runtime evidence
→ existing task(s), if any
→ current verified state
```

Do not treat checkbox state alone as implementation evidence.

In normal mode, do not expand this map beyond the selected feature slice unless a concrete dependency
or cross-cutting violation requires it.

### 4. Diagnose Before Classifying a Gap

When a failing check or suspicious behavior is found, determine which category actually failed:

- **product behavior** — implementation does not satisfy the intended behavior;
- **test/fixture/harness** — the test cannot validly exercise the intended behavior;
- **infrastructure/environment** — runtime/setup/credentials/tooling prevents trustworthy execution;
- **artifact/task state** — implementation and task/checklist metadata disagree;
- **spec/contract contradiction** — two authoritative intent sources conflict.

Rules:

- A harness/configuration failure is not proof the product behavior is missing.
- A passing assertion with unhandled runtime errors is not proof the behavior passes.
- A spec/contract contradiction requiring a maintainer decision must not be "fixed" by choosing a
  side silently. Reuse an existing decision task or append one decision task if none exists.
- Infrastructure blockers are reported as blockers unless the active artifacts already define
  implementation work to remove them.

### 5. Classify Findings

Use these gap types for actionable implementation/task findings:

- **`missing`**: required behavior/work is absent.
- **`partial`**: present but incomplete.
- **`contradicts`**: implementation conflicts with approved intent or constitution.
- **`unrequested`**: implementation is outside approved intent.

For `unrequested` work, do not automatically append a task. Append only when it creates an active
correctness/governance/maintenance risk that requires action; otherwise record it as informational.

Each finding records:

- stable finding ID;
- source reference;
- gap type and severity;
- verified root cause;
- concise evidence;
- existing covering task(s);
- remaining work, if any.

Severity:

- **CRITICAL**: concrete constitution MUST violation or baseline P1 functionality is blocked/corrupted.
- **HIGH**: core FR/acceptance/contract behavior is missing or materially wrong.
- **MEDIUM**: secondary behavior, test/harness integrity, or meaningful maintainability drift.
- **LOW**: polish or low-risk informational drift.

### 6. Reconcile Existing Tasks Before Appending

For each finding, check all semantically relevant existing tasks, regardless of checkbox state.

Apply in this order:

1. **Already covered by open task**  
   Reuse it. Do not append a duplicate.

2. **Checked task is actually incomplete**  
   If concrete evidence proves its required behavior/verification is incomplete, reopen the task
   (`[X]` → `[ ]`) and reuse it.

3. **Unchecked task is already complete**  
   If required behavior and meaningful verification are both satisfied, mark it `[X]`.

4. **Several failures share one root cause**  
   Cover them with one existing or new remediation task, not one task per test/failure.

5. **Superseded/corrective task chain exists**  
   Evaluate the resulting current behavior and reuse the newest task that actually owns the work.
   Do not create another task merely because older task prose is stale.

6. **No task covers the verified root cause**  
   Only now is the finding eligible for a new task.

Do not append "investigate" tasks when the root cause is already known.

### 7. Present a Compact Findings Summary

Before writing new tasks, report a compact table:

| ID | Type | Severity | Source | Root Cause / Evidence | Existing Task | Action |
|----|------|----------|--------|-----------------------|---------------|--------|

Then summarize:

- convergence mode (`normal` or `full`);
- obligations checked;
- findings by type/severity;
- existing tasks reused;
- tasks reopened/closed during reconciliation;
- genuinely uncovered root causes;
- blockers/decisions requiring user action.

Avoid repeating long historical findings already represented in tasks/reports.

### 8. Append Only Genuinely Novel Tasks

If novel actionable root causes remain:

1. Scan existing task IDs; let `M` be the maximum.
2. Determine the next unused Convergence phase number. Do not create a duplicate phase heading.
3. Append one new `## Phase N: Convergence` section.
4. Append one task per novel root cause, ordered by dependency and severity:

   ```markdown
   - [ ] T{M+1:03d} <imperative remediation> per <source-ref> (<gap-type>)
   ```

5. Preserve existing task IDs and history.

Never append a task merely because:

- a test failed for a harness/configuration reason;
- an existing task already owns the same root cause;
- an old checked task can be safely reopened;
- several symptoms are manifestations of one root cause;
- the item is informational `unrequested` work with no active risk.

### 9. Determine Outcome

Use the existing Autopilot-compatible outcomes:

**`tasks_appended`**
- one or more genuinely novel tasks were appended.

**`existing_tasks_remaining`**
- implementation gaps remain but are now fully represented by existing/reopened tasks;
- task-state reconciliation may have occurred;
- no new tasks were appended.

**`converged`**
- no implementation gaps remain in the assessed scope;
- no unresolved blocker/decision prevents that conclusion.

On normal convergence, say explicitly that `converged` applies to the assessed feature slice, not
necessarily the entire V1 feature. Full-feature convergence requires full mode.

### 10. Provide Next Action

- `tasks_appended`: report appended IDs and the feature/root cause they belong to; recommend
  `/speckit.implement`.
- `existing_tasks_remaining`: list the small set of next dependency-valid task IDs; recommend
  `/speckit.implement`.
- `converged`: state the scope that converged. If normal mode, continue to the next feature slice;
  if full mode, proceed to review/release as appropriate.

## Extension Hooks

After producing the result, check if `.specify/extensions.yml` exists in the project root.

- If it exists, read it and look for entries under the `hooks.after_converge` key
- If the YAML cannot be parsed or is invalid, do not skip silently: tell the user that `.specify/extensions.yml` could not be read (include the parser error) and that no hooks were checked, including any mandatory (`optional: false`) hooks registered there, then continue normally
- Filter out hooks where `enabled` is explicitly `false`. Treat hooks without an `enabled` field as enabled by default.
- For each remaining hook, do **not** attempt to interpret or evaluate hook `condition` expressions:
  - If the hook has no `condition` field, or it is null/empty, treat the hook as executable
  - If the hook defines a non-empty `condition`, skip the hook and leave condition evaluation to the HookExecutor implementation
- Report the convergence outcome (`converged`, `existing_tasks_remaining`, or `tasks_appended`)
  in-session before listing optional hooks.
- For each executable hook, output the following based on its `optional` flag:
  - **Optional hook** (`optional: true`):

    ```text
    ## Extension Hooks

    **Optional Hook**: {extension}
    Command: `/{command}`
    Description: {description}

    Prompt: {prompt}
    To execute: `/{command}`
    ```

  - **Mandatory hook** (`optional: false`):

    ```text
    ## Extension Hooks

    **Automatic Hook**: {extension}
    Executing: `/{command}`
    EXECUTE_COMMAND: {command}
    ```
    After emitting the block above you MUST actually invoke the hook and wait for it to finish before continuing. Run it the same way you would run the command yourself in this agent/session (the invocation may differ from the literal `{command}` id shown above, e.g. a skills-mode agent runs it as `/skill:speckit-...` or `$speckit-...`). Emitting the block alone does not run the hook.

- If no hooks are registered or `.specify/extensions.yml` does not exist, skip silently

## Done When

- [ ] Assessed scope is explicit (`normal` feature slice or `full`)
- [ ] Findings are evidence-backed and root-cause grouped
- [ ] Test/harness/infrastructure failures were not misreported as product gaps
- [ ] Existing tasks were reused/reconciled before any append
- [ ] No duplicate task IDs or Convergence phase headers were introduced
- [ ] Only genuinely novel uncovered root causes produced new tasks
- [ ] Application code, spec.md, and plan.md were not modified
- [ ] Outcome and next dependency-valid work were reported
