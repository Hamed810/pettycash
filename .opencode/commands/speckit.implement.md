---
description: Execute the implementation plan by processing dependency-valid, feature-cohesive work from tasks.md and verifying it against the active Spec Kit artifacts.
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty).

## Pre-Execution Checks

**Check for extension hooks (before implementation)**:
- Check if `.specify/extensions.yml` exists in the project root.
- If it exists, read it and look for entries under the `hooks.before_implement` key
- If the YAML cannot be parsed or is invalid, do not skip silently: tell the user that `.specify/extensions.yml` could not be read (include the parser error) and that no hooks were checked, including any mandatory (`optional: false`) hooks registered there, then continue normally
- Filter out hooks where `enabled` is explicitly `false`. Treat hooks without an `enabled` field as enabled by default.
- For each remaining hook, do **not** attempt to interpret or evaluate hook `condition` expressions:
  - If the hook has no `condition` field, or it is null/empty, treat the hook as executable
  - If the hook defines a non-empty `condition`, skip the hook and leave condition evaluation to the HookExecutor implementation
- For each executable hook, output the following based on its `optional` flag:
  - **Optional hook** (`optional: true`):
    ```
    ## Extension Hooks

    **Optional Pre-Hook**: {extension}
    Command: `/{command}`
    Description: {description}

    Prompt: {prompt}
    To execute: `/{command}`
    ```
  - **Mandatory hook** (`optional: false`):
    ```
    ## Extension Hooks

    **Automatic Pre-Hook**: {extension}
    Executing: `/{command}`
    EXECUTE_COMMAND: {command}

    Wait for the result of the hook command before proceeding to the Outline.
    ```
    After emitting the block above you MUST actually invoke the hook and wait for it to finish before continuing. Run it the same way you would run the command yourself in this agent/session (the invocation may differ from the literal `{command}` id shown above, e.g. a skills-mode agent runs it as `/skill:speckit-...` or `$speckit-...`). Emitting the block alone does not run the hook.
- If no hooks are registered or `.specify/extensions.yml` does not exist, skip silently

## PettyCash Execution Context

Before entering the Outline:

- **REQUIRED**: Read `AGENTS.md`. It is authoritative for runtime boundaries, Docker ownership,
  safety constraints, approved wrappers, and project-local execution rules.
- Treat the active Spec Kit artifacts and approved contracts as feature intent. `AGENTS.md`
  is operational context, not product intent.
- Do not begin implementation with a repository-wide audit. `/speckit.converge` owns broad
  reconciliation; implementation should investigate only as far as needed to complete and verify
  the active work.
- Use the most direct trustworthy evidence available: source, tests, contracts, runtime behavior,
  LSP/symbol navigation, and codebase-memory when it materially helps. Do not force a particular
  discovery tool when a simpler targeted inspection is sufficient.
- Follow `AGENTS.md` for every command. Do not rediscover or bypass the established host/container
  boundary, approved `scripts/dev/*` wrappers, or Docker ownership rules.

## Verification Integrity

A task is complete only when its implementation and relevant verification agree.

- A failing test is a valid RED state only when the test collected/executed correctly and failed
  on the intended missing or incorrect behavior.
- Collection, configuration, component-resolution, environment, fixture/bootstrap, authentication,
  or unrelated runtime failures are not proof of the intended RED behavior.
- A passing assertion accompanied by unhandled exceptions/rejections or a broken harness is not a
  trustworthy PASS.
- Do not use truncated/filtered output as the sole evidence when it hides the runner's final status
  or the actual failure reason.
- Prefer targeted verification while developing; run broader feature/phase gates at coherent
  checkpoints instead of after every small edit.

## Outline

1. Run `.specify/scripts/bash/check-prerequisites.sh --json --require-tasks --include-tasks`
   once from repo root and parse `FEATURE_DIR` and `AVAILABLE_DOCS`.

   Canonical active artifacts are:

   - `FEATURE_DIR/spec.md`
   - `FEATURE_DIR/plan.md`
   - `FEATURE_DIR/tasks.md`
   - `FEATURE_DIR/data-model.md` if present
   - `FEATURE_DIR/contracts/` if present
   - `FEATURE_DIR/research.md` if present
   - `FEATURE_DIR/quickstart.md` if present
   - `.specify/memory/constitution.md` if present
   - `AGENTS.md` at repository root

   Do not search for alternate `spec.md`, `plan.md`, or `tasks.md` files and do not guess another
   feature directory.

2. **Check checklist status** if `FEATURE_DIR/checklists/` exists:
   - Checklist markers are read-only for this command.
   - Count checked and unchecked items in each checklist.
   - If any checklist has unchecked items, show a compact status table and ask whether to proceed.
   - If all are checked, continue automatically.
   - A checked requirements-quality item is not proof of completed implementation.

3. Load implementation context using progressive disclosure:
   - **REQUIRED**: `tasks.md`, `plan.md`, and `AGENTS.md`
   - **REQUIRED WHEN RELEVANT**: the specific spec requirements, contracts, data-model sections,
     research decisions, constitution clauses, and quickstart scenarios touched by the active work
   - Do not repeatedly reload large artifacts when the required context is already established and
     repository state has not changed.

4. **Project setup verification**:
   - Preserve Spec Kit's setup intent, but do not repeat generic setup discovery on every pass.
   - Verify/create ignore files only when:
     - setup/toolchain work is still active,
     - a relevant file is missing,
     - or the active task changes the corresponding tool/build context.
   - For PettyCash, merely running inside external Docker infrastructure does not make this
     repository a Docker build context; follow `AGENTS.md`.
   - Do not create configuration for technologies that are not actually present.

5. Parse `tasks.md` as a structured plan:
   - task IDs and checkbox state;
   - feature/phase grouping;
   - explicit dependencies and ordering;
   - `[P]` markers;
   - referenced files and verification requirements;
   - convergence tasks that block or belong to a feature slice.

## Work Selection

Choose work by dependency and feature coherence, not by "first unchecked checkbox" alone.

Use this order:

1. honor explicit dependencies and blocking prerequisites;
2. continue the currently active user story / feature slice when one is already in progress;
3. absorb open convergence tasks that directly block or correct that same slice;
4. otherwise select the next dependency-valid feature work from `tasks.md`;
5. prefer a cohesive batch over jumping between unrelated phases.

Checkbox state is planning state, not conclusive repository truth:

- If an unchecked task is already satisfied, verify it narrowly and mark it complete rather than
  redoing the implementation.
- If a checked task in the active scope has concrete evidence that its required behavior or
  verification is incomplete, reopen that checkbox and repair the task instead of creating
  duplicate work.
- Do not perform a repository-wide search for stale task state during implementation; broader
  reconciliation belongs to `/speckit.converge`.

A convergence phase is not automatically higher priority than unfinished feature work. A convergence
task should move ahead only when it is a dependency, a correctness blocker, or directly belongs to
the active feature slice.

## Implementation Batch

An invocation does **not** need to exhaust every open task in a large backlog.

By default, execute one coherent dependency-safe batch and stop at a natural verification boundary.
A batch may contain one task or several tightly related tasks. Continue farther only when the work
remains coherent and context is still reliable.

Within the batch:

- follow TDD where the task plan calls for tests first;
- keep tasks that write the same files sequential;
- implement against the current spec/plan/contracts rather than stale assumptions in old task prose;
- when task prose conflicts with newer approved intent, do not silently choose one: use the current
  source-of-truth ordering from `AGENTS.md` and report the stale task wording for reconciliation.

## Parallel Task Execution

Tasks marked `[P]` may execute concurrently when all of the following are true:

- there is no dependency relationship;
- write scopes do not overlap;
- they do not mutate the same runtime/database/test state;
- they do not both modify coordination surfaces such as dependency manifests, routes, DI/bootstrap,
  migrations, or `tasks.md`.

Use at most 3 concurrent workers. If independence is uncertain, run sequentially.

After a parallel batch:

1. reconcile the changes;
2. run combined relevant verification;
3. update `tasks.md` serially;
4. resolve conflicts before starting more work.

## Baseline Failures and New Failures

When broader verification is red:

- distinguish failures caused by the active batch from known failures already covered by open tasks;
- never call a known failure a PASS;
- do not call a known covered failure a new regression without evidence;
- record the covering task ID for known baseline failures and continue only when dependency/order
  rules allow;
- investigate new or unexplained regressions before marking the active work complete.

A test-harness defect is its own problem; it is not evidence that the product behavior under test is
missing.

## Progress Tracking

For each completed task:

- verify the task's acceptance/behavioral requirement with the narrowest meaningful test or check;
- mark `[X]` only after the verification is trustworthy;
- create/update `docs/reports/TXXX.md` only when required by `AGENTS.md` or when the task is
  significant enough to need durable evidence;
- keep conversational progress compact: completed IDs, verification, new blocker if any, next work.

Do not halt merely because an unrelated known baseline test is red. Halt when:

- the active task cannot be completed safely;
- a required decision is missing;
- a destructive/shared-environment action requires approval;
- an unexplained regression blocks trustworthy completion;
- or a mandatory dependency is unavailable.

## Completion Validation

At the end of the batch:

- verify completed work against the relevant spec/plan/contracts and constitution constraints;
- run the relevant combined verification for the batch;
- confirm no new unexplained regression was introduced;
- ensure task checkboxes reflect the verified state of tasks touched in this invocation;
- report the next dependency-valid work rather than claiming the entire feature is complete unless
  all required work is actually complete.

Note: If `tasks.md` is missing or fundamentally incomplete, stop and recommend `/speckit.tasks`.
Do not regenerate the task plan implicitly.

## Mandatory Post-Execution Hooks

**You MUST complete this section before reporting completion to the user.**

Check if `.specify/extensions.yml` exists in the project root.
- If it does not exist, or no hooks are registered under `hooks.after_implement`, skip to the Completion Report.
- If it exists, read it and look for entries under the `hooks.after_implement` key.
- If the YAML cannot be parsed or is invalid, do not skip silently: tell the user that `.specify/extensions.yml` could not be read (include the parser error) and that no hooks were checked, including any mandatory (`optional: false`) hooks registered there, then continue to the Completion Report.
- Filter out hooks where `enabled` is explicitly `false`. Treat hooks without an `enabled` field as enabled by default.
- For each remaining hook, do **not** attempt to interpret or evaluate hook `condition` expressions:
  - If the hook has no `condition` field, or it is null/empty, treat the hook as executable
  - If the hook defines a non-empty `condition`, skip the hook and leave condition evaluation to the HookExecutor implementation
- For each executable hook, output the following based on its `optional` flag:
  - **Mandatory hook** (`optional: false`) — **You MUST emit `EXECUTE_COMMAND:` for each mandatory hook**:
    ```
    ## Extension Hooks

    **Automatic Hook**: {extension}
    Executing: `/{command}`
    EXECUTE_COMMAND: {command}
    ```
    After emitting the block above you MUST actually invoke the hook and wait for it to finish before continuing. Run it the same way you would run the command yourself in this agent/session (the invocation may differ from the literal `{command}` id shown above, e.g. a skills-mode agent runs it as `/skill:speckit-...` or `$speckit-...`). Emitting the block alone does not run the hook.
  - **Optional hook** (`optional: true`):
    ```
    ## Extension Hooks

    **Optional Hook**: {extension}
    Command: `/{command}`
    Description: {description}

    Prompt: {prompt}
    To execute: `/{command}`
    ```

## Completion Report

Report concisely:

- task IDs completed/reopened/reconciled in this invocation;
- verification performed and its trustworthy result;
- known baseline failures still present, if relevant;
- blocker/decision if any;
- next dependency-valid task or feature slice.

## Done When

- [ ] Active batch is implemented and verified against relevant Spec Kit artifacts
- [ ] Touched task states match verified repository reality
- [ ] No new unexplained regression remains
- [ ] Runtime/tool execution followed `AGENTS.md`
- [ ] Significant reports were written when required
- [ ] Extension hooks were dispatched or skipped according to the rules above
- [ ] Completion report identifies the next work or confirms full completion
