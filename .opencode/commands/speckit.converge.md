---
description: Assess the current codebase against the feature's spec, plan, and tasks, then append any remaining unbuilt work as new tasks to tasks.md so implement can complete it.
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

## Project Convergence Context (PettyCash)

Convergence is the primary repository-understanding phase for PettyCash.

Before assessing implementation state:

- **REQUIRED**: Read `AGENTS.md` from the project root for operational/runtime,
  Docker ownership, safety, and tool-execution rules. `AGENTS.md` is operational context,
  not a source of feature intent.
- Use `codebase-memory` when available to establish architecture relationships,
  component dependencies, and existing implementation patterns before broad text search.
- Prefer LSP/symbol navigation and targeted file inspection to verify findings.
- Bound assessment to the scope defined by `spec.md`, `plan.md`, `tasks.md`, and the
  constitution. Do not perform an unbounded repository audit.
- Do not use git history, branch comparison, or diff-based reasoning; convergence assesses
  the present repository state.
- Any verification command executed during convergence must follow the host/container
  execution boundaries in `AGENTS.md`.

## Goal

Close the gap between what a feature's specification, plan, and tasks call for and what the
codebase currently implements. Read `spec.md`, `plan.md`, and `tasks.md` as the **sole
source of intent** (with the constitution as governing constraints), assess the current
state of the code, determine which requirements, acceptance criteria, plan decisions, and
existing tasks are unmet, incomplete, or only partially satisfied, and **append each piece
of remaining work that is not already covered by an existing incomplete task as a new,
traceable task** at the bottom of `tasks.md` so that `/speckit.implement` can complete it.

This command requires a complete `tasks.md` produced by `/speckit.tasks`. It MAY run
before an implementation pass to reconcile repository reality with the planned work, and
it MAY be run again after implementation as a verification/convergence pass.

This is **not** a diff tool and does **not** track changes. It assesses the present state
of the code relative to the feature's artifacts — no git, no branch comparison, no history.

## Operating Constraints

**APPEND-ONLY, NEVER REWRITE**: The command's **only** write is appending a new
`## Phase N: Convergence` section to `tasks.md`. It MUST NOT:

- modify `spec.md` or `plan.md` in any way;
- rewrite, renumber, reorder, or delete any existing task (including tasks from a prior
  Convergence phase);
- modify, create, or delete any application code — completing the appended tasks is the
  job of `/speckit.implement`.

When the codebase already satisfies everything, the command MUST leave `tasks.md`
**byte-for-byte unchanged** (no empty Convergence header) and report a clean result.

**Constitution Authority**: The project constitution (`.specify/memory/constitution.md`) is
**non-negotiable**. Code that violates a MUST principle is the highest-severity finding and
produces a corresponding remediation task. If the constitution is an unfilled template,
skip constitution checks gracefully rather than failing.

## Execution Steps

### 1. Initialize Convergence Context

Run `.specify/scripts/bash/check-prerequisites.sh --json --require-spec --require-tasks --include-tasks` once from repo root and parse JSON for FEATURE_DIR and AVAILABLE_DOCS. Derive absolute paths:

- SPEC = FEATURE_DIR/spec.md
- PLAN = FEATURE_DIR/plan.md
- TASKS = FEATURE_DIR/tasks.md
- CONSTITUTION = `.specify/memory/constitution.md` (if present)
- AGENTS = `AGENTS.md` at repository root (operational/runtime context)
If `spec.md`, `plan.md`, or `tasks.md` is missing, STOP with a clear, actionable message naming the
prerequisite command to run (`/speckit.specify` for a missing spec, `/speckit.plan` for a missing plan,
`/speckit.tasks` for missing tasks). Do not produce partial output.
For single quotes in args like "I'm Groot", use escape syntax: e.g 'I'\''m Groot' (or double-quote if possible: "I'm Groot").

### 2. Load Artifacts (Progressive Disclosure)

Load only the minimal necessary context from each artifact:

**From spec.md:**

- Functional Requirements (FR-###)
- Success Criteria (SC-###) — include only items requiring buildable work; exclude
  post-launch outcome metrics and business KPIs
- User Stories and their Acceptance Scenarios
- Edge Cases (if present)

**From plan.md:**

- Architecture/stack choices and technical decisions
- Data Model references
- Phases and named touch-points (files/components the plan says will be created or edited)
- Technical constraints

**From tasks.md:**

- Task IDs (to compute the next ID and next phase number)
- Descriptions, phase grouping, and referenced file paths

**From constitution (if not an unfilled template):**

- Principle names and MUST/SHOULD normative statements

**From AGENTS.md:**

- Load only operational/runtime, Docker ownership, safety, investigation, and execution
  boundaries needed to assess the repository safely.
- Do **not** treat `AGENTS.md` as feature intent and do not derive new product requirements from it.

### 3. Build the Intent Inventory

Create an internal model (do not echo raw artifacts):

- **Requirements inventory**: one stable key per FR-### / SC-### / user-story acceptance
  scenario (e.g. `US1/AC2`), plus the plan decisions and constitution principles that
  impose buildable obligations.
- **Code-scope map**: from the file paths named in `plan.md` and `tasks.md`, use
  `codebase-memory` when available to identify relevant architecture/dependency relationships,
  then use targeted keyword/LSP/file inspection for the concepts each requirement describes.
  Derive the set of source files and components in scope for assessment. Bound the assessment
  to these — do **not** infer scope beyond what the artifacts define and do not fall back to
  an unbounded full-repository scan.

### 4. Assess the Codebase and Classify Findings

For each item in the intent inventory, inspect the current code in scope and produce a
`Finding` only where there is a gap.

#### Frontend Architecture Verification

When assessing frontend implementation:

- Verify that UI components follow the frontend stack approved by `plan.md`,
  `research.md`, `tasks.md`, and `package.json`.
- Do not treat an unapproved UI framework as an implicit or missing dependency.
- If source code uses components from a UI framework that is not part of the
  approved stack, classify it as implementation drift.
- Do not recommend installing an unapproved framework merely to make existing
  code or tests pass.
- For PettyCash, `@nextcloud/vue` 9.x is the approved component library unless
  the active Spec Kit artifacts explicitly state otherwise.
- Existing Vuetify-style `v-*` components must therefore be treated as a
  convergence finding unless the active feature artifacts explicitly approve Vuetify.
- When this drift conflicts with an explicit stack decision in the active artifacts,
  classify the finding as `contradicts` rather than as a missing dependency.

Classify every finding by **gap type**:

- **`missing`**: the required work is absent from the code entirely.
- **`partial`**: the work exists but does not yet fully satisfy the requirement /
  acceptance criterion / plan decision.
- **`contradicts`**: the code does something that conflicts with stated intent or a
  constitution MUST principle.
- **`unrequested`**: the code contains work not called for by the spec, plan, or tasks
  (surfaced for awareness — converge does **not** delete code, it only appends a task to
  review/justify or remove it).

Each `Finding` records: a stable id, the `source-ref` it traces to, the `gap-type`, a
severity, a short human-readable description with the evidence (the file/area observed),
and whether an existing incomplete task already covers the remaining work.

Before treating a gap as appendable work:

- Compare the gap with existing incomplete tasks in `tasks.md`.
- If an existing incomplete task already fully and traceably covers the gap, do **not**
  append a duplicate convergence task. Mark the finding internally as `covered_by_existing_task`
  and record the relevant task ID(s) for the summary.
- An unchecked task is not evidence that implementation is missing. If targeted inspection
  shows the task is already satisfied by the current code/tests, record a task-state mismatch
  for awareness, but do not rewrite the old checkbox and do not append duplicate work.

**Edge cases:**

- **Little or no code yet**: treat the entire specified scope as `missing` remaining work
  rather than failing.
- **Nothing remains**: produce zero findings and follow the converged branch in Step 7.

### 5. Assign Severity

- **CRITICAL**: violates a constitution MUST principle, or a `missing`/`contradicts` gap
  that blocks baseline functionality of a P1 user story.
- **HIGH**: a `missing` or `partial` gap on a core functional requirement or acceptance
  criterion.
- **MEDIUM**: a `partial` gap on a secondary requirement, or an `unrequested` addition with
  unclear justification.
- **LOW**: minor partial gaps, polish, or low-risk `unrequested` additions.

### 6. Present the In-Session Findings Summary

Before appending anything, output a compact, severity-graded summary (no file writes yet):

## Convergence Findings

| ID | Gap Type | Severity | Source | Evidence | Existing Task | Remaining Work |
|----|----------|----------|--------|----------|---------------|----------------|
| F1 | missing  | HIGH     | FR-008 | Example: no append-only guard detected in path/to/module.py when writing tasks.md | T041 or — | Add append-only enforcement |

**Summary metrics:**

- Requirements / acceptance criteria checked
- Plan decisions checked
- Constitution principles checked (or "skipped — template")
- Findings by gap type (missing / partial / contradicts / unrequested)
- Findings by severity
- Findings already covered by existing incomplete tasks
- Task-state mismatches observed (informational only)

### 7. Append Convergence Tasks (or report current convergence state)

**If there are one or more actionable findings not already covered by existing incomplete
tasks** (`tasks_appended` outcome):

Append to the **end** of `tasks.md`, per the append contract:

1. Scan all existing task IDs; let `M` be the maximum. Determine the next phase number `N`
   (highest existing phase + 1).
2. Write a single new section header `## Phase N: Convergence`.
3. Emit one checklist item per actionable finding, ordered CRITICAL/HIGH first, assigning
   zero-padded IDs `T{M+1:03d}, T{M+2:03d}, …`:

   ```markdown
   - [ ] T042 <imperative description> per <source-ref> (<gap-type>)
   ```

   `<source-ref>` traces the task to its origin: e.g. `FR-003`, `SC-002`,
   `US1/AC2`, `plan: storage decision`, `Constitution II`.

   `<gap-type>` is one of `missing`, `partial`, `contradicts`, `unrequested`.

   Constitution-violation tasks MUST be emitted first and described as
   `CRITICAL`.
4. Never reuse or renumber existing IDs. If a prior Convergence phase exists, add a new,
   separately-numbered one below it — do not touch the old one.
5. Never append a convergence task when the same remaining work is already fully covered
   by an existing incomplete task in `tasks.md`.

**If gaps remain but every gap is already covered by existing incomplete tasks**
(`existing_tasks_remaining` outcome):

- Do **not** modify `tasks.md`.
- Report: **"No new convergence tasks appended — remaining gaps are already represented by existing tasks."**
- List the relevant existing task IDs and recommend `/speckit.implement`.
- Do not report the repository as converged.

**If there are no implementation gaps** (`converged` outcome):

- Do **not** modify `tasks.md` at all — no empty phase header.
- Report: **"✅ Converged — the implementation satisfies the spec, plan, and tasks."**
- Include the summary counts of what was checked.

### 8. Provide Next Actions (Handoff)

- On `tasks_appended`: state how many tasks were appended under which phase, and recommend
  running `/speckit.implement` to complete them; note that a follow-up converge
  run will find fewer or no remaining items.
- On `existing_tasks_remaining`: state that no duplicate tasks were appended, identify the
  existing incomplete task IDs that already cover the gaps, and recommend running
  `/speckit.implement`.
- On `converged`: recommend proceeding to review / opening a PR. No further implement pass
  is needed for this feature's specified scope.

### 9. Check for extension hooks

After producing the result, check if `.specify/extensions.yml` exists in the project root.

- If it exists, read it and look for entries under the `hooks.after_converge` key
- If the YAML cannot be parsed or is invalid, do not skip silently: tell the user that `.specify/extensions.yml` could not be read (include the parser error) and that no hooks were checked, including any mandatory (`optional: false`) hooks registered there, then continue normally
- Filter out hooks where `enabled` is explicitly `false`. Treat hooks without an `enabled` field as enabled by default.
- For each remaining hook, do **not** attempt to interpret or evaluate hook `condition` expressions:
  - If the hook has no `condition` field, or it is null/empty, treat the hook as executable
  - If the hook defines a non-empty `condition`, skip the hook and leave condition evaluation to the HookExecutor implementation
- Report the convergence outcome (`converged`, `existing_tasks_remaining`, or
  `tasks_appended`) in-session before listing any hooks, so users can decide whether to run
  optional follow-up commands.
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
