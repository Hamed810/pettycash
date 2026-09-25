---
description: Execute the implementation plan by processing and executing all tasks defined in tasks.md
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

## Project Execution Context (PettyCash)

Before entering the Outline:

- **REQUIRED**: Read `AGENTS.md` from the project root. Treat it as authoritative for
  operational, runtime, Docker ownership, safety, and tool-execution rules.
- Repository-wide understanding should normally be established by `/speckit.converge`.
  Do **not** begin implementation with a full repository scan or repeat convergence analysis.
- Identify the active or next genuinely incomplete task using `tasks.md`, dependency rules,
  and targeted repository evidence. An unchecked checkbox alone is not proof that the
  implementation is absent.
- If a relevant task appears already implemented, inspect only the files/tests needed to
  verify that task. Do not redo the work and do not audit the entire repository.
- Use `codebase-memory` only when the active task requires architecture, dependency, or
  existing-pattern discovery. Prefer LSP/Intelephense for PHP symbol navigation.
- Runtime routing is fixed by `AGENTS.md`:
  - Node/frontend tooling (`node`, `npm`, `npx`, Vite, Vitest, `vue-tsc`, ESLint,
    Stylelint, Playwright) runs on the Ubuntu host from the PettyCash repository.
  - PHP/Nextcloud tooling MUST use the repository wrappers:
    - `scripts/dev/phpunit`
    - `scripts/dev/occ`
    - `scripts/dev/composer`
    - `scripts/dev/in-container`
- Do not probe the host for PHP or Composer.
- Do not first try a PHP command on the host and then fall back to Docker.
- Do not manually construct `docker exec` commands for normal PHP/Nextcloud work when
  an approved `scripts/dev/*` wrapper exists.
- Do not guess container paths. The wrappers own container execution and working-directory
  selection according to `AGENTS.md`.
- Prefer existing `package.json` scripts for frontend verification. Use `npm run check`
  as the full frontend quality gate when complete frontend verification is appropriate.
- Do not infer, recreate, or relocate Docker infrastructure. PettyCash consumes the
  existing external Docker Compose project defined in `AGENTS.md`.

## Task Selection Rule

Do not use ad-hoc `grep`, regex scans, checkbox-only inspection, or shell pipelines as a
substitute for parsing the task plan already required by this skill.

`tasks.md` defines planned work and execution order; checkbox state alone is not repository truth.

To choose the next task:

1. Read and parse `tasks.md` as a structured task plan.
2. Respect phases, dependencies, sequencing rules, and `[P]` markers.
3. Identify the next candidate incomplete task.
4. Perform only targeted verification for that candidate when repository state may differ
   from the checkbox state.
5. If targeted evidence shows the task is already implemented, do not redo it. Report the
   task-state mismatch and continue to the next valid candidate.
6. Do not perform repository-wide completion discovery during implementation. Broad
   reconciliation belongs to `/speckit.converge`.

Never use a command such as `grep` over task checkboxes to determine which work is actually
implemented.

## Verification Output Integrity

Tests used to prove RED, PASS, or task completion must be run with enough output
to preserve the test runner's final status and actual failure reason.

Do not use `grep`, `head`, `tail`, or similar output filtering as the sole evidence
for test status.

Configuration, collection, component-resolution, environment, and runtime failures
are not valid RED states.

A RED test is valid only when the test executes correctly and fails on the intended
missing behavior.




## Outline

1. Run `.specify/scripts/bash/check-prerequisites.sh --json --require-tasks --include-tasks` from repo root and parse FEATURE_DIR and AVAILABLE_DOCS list. All paths must be absolute. For single quotes in args like "I'm Groot", use escape syntax: e.g 'I'\''m Groot' (or double-quote if possible: "I'm Groot").

2. **Check checklists status** (if FEATURE_DIR/checklists/ exists):
   - Treat checklist markers as a read-only gate: scan checkbox state, report status, and ask before proceeding when needed; do NOT modify checklist files or markers
   - `checklists/requirements.md` is the built-in spec-quality checklist maintained by `/speckit.specify` and `/speckit.clarify`; custom checklists generated by `/speckit.checklist` are reviewer-owned requirements-quality review artifacts
   - For custom checklists, `[x]` means the reviewer determined the requirements-quality criterion is satisfied; it does NOT mean implementation work is complete
   - Scan all checklist files in the checklists/ directory
   - For each checklist, count:
     - Total items: All lines matching `- [ ]` or `- [X]` or `- [x]`
     - Checked items: Lines matching `- [X]` or `- [x]`
     - Unchecked items: Lines matching `- [ ]`
   - Create a status table:

     ```text
     | Checklist | Total | Checked | Unchecked | Status |
     |-----------|-------|---------|-----------|--------|
     | ux.md     | 12    | 12      | 0         | ✓ PASS |
     | test.md   | 8     | 5       | 3         | ✗ FAIL |
     | security.md | 6   | 6       | 0         | ✓ PASS |
     ```

   - Calculate overall status:
     - **PASS**: All checklists have 0 unchecked items
     - **FAIL**: One or more checklists have unchecked items

   - **If any checklist has unchecked items**:
     - Display the table with unchecked item counts
     - **STOP** and ask: "Some checklists have unchecked items. Do you want to proceed with implementation anyway? (yes/no)"
     - Wait for user response before continuing
     - If user says "no" or "wait" or "stop", halt execution
     - If user says "yes" or "proceed" or "continue", proceed to step 3

   - **If all checklists are checked**:
     - Display the table showing all checklists passed
     - Automatically proceed to step 3

3. Load and analyze the implementation context:
   - **REQUIRED**: Read `AGENTS.md` for operational/runtime constraints. `AGENTS.md`
     governs execution behavior but does not replace Spec Kit artifacts as feature intent.
   - **REQUIRED**: Read tasks.md for the complete task list and execution plan
   - **REQUIRED**: Read plan.md for tech stack, architecture, and file structure
   - **IF EXISTS**: Read data-model.md for entities and relationships
   - **IF EXISTS**: Read contracts/ for API specifications and test requirements
   - **IF EXISTS**: Read research.md for technical decisions and constraints
   - **IF EXISTS**: Read .specify/memory/constitution.md for governance constraints
   - **IF EXISTS**: Read quickstart.md for integration scenarios

4. **Project Setup Verification**:
   - **REQUIRED**: Create/verify ignore files based on actual project setup:

   **Detection & Creation Logic**:
   - Check if the following command succeeds to determine if the repository is a git repo (create/verify .gitignore if so):

     ```sh
     git rev-parse --git-dir 2>/dev/null
     ```

   - Check if Dockerfile* exists or the repository actually owns a Docker build context → create/verify .dockerignore.
     For PettyCash, Docker being mentioned in `plan.md` or `AGENTS.md` is not sufficient:
     the Docker infrastructure is external to this repository, so do not create or modify
     `.dockerignore` solely because the application runs in Docker.
   - Check if .eslintrc* exists → create/verify .eslintignore
   - Check if eslint.config.* exists → ensure the config's `ignores` entries cover required patterns
   - Check if .prettierrc* exists → create/verify .prettierignore
   - Check if .npmrc or package.json exists → create/verify .npmignore (if publishing)
   - Check if terraform files (*.tf) exist → create/verify .terraformignore
   - Check if .helmignore needed (helm charts present) → create/verify .helmignore

   **If ignore file already exists**: Verify it contains essential patterns, append missing critical patterns only
   **If ignore file missing**: Create with full pattern set for detected technology

   **Common Patterns by Technology** (from plan.md tech stack):
   - **Node.js/JavaScript/TypeScript**: `node_modules/`, `dist/`, `build/`, `*.log`, `.env*`
   - **Python**: `__pycache__/`, `*.pyc`, `.venv/`, `venv/`, `dist/`, `*.egg-info/`
   - **Java**: `target/`, `*.class`, `*.jar`, `.gradle/`, `build/`
   - **C#/.NET**: `bin/`, `obj/`, `*.user`, `*.suo`, `packages/`
   - **Go**: `*.exe`, `*.test`, `vendor/`, `*.out`
   - **Ruby**: `.bundle/`, `log/`, `tmp/`, `*.gem`, `vendor/bundle/`
   - **PHP**: `vendor/`, `*.log`, `*.cache`, `*.env`
   - **Rust**: `target/`, `debug/`, `release/`, `*.rs.bk`, `*.rlib`, `*.prof*`, `.idea/`, `*.log`, `.env*`
   - **Kotlin**: `build/`, `out/`, `.gradle/`, `.idea/`, `*.class`, `*.jar`, `*.iml`, `*.log`, `.env*`
   - **C++**: `build/`, `bin/`, `obj/`, `out/`, `*.o`, `*.so`, `*.a`, `*.exe`, `*.dll`, `.idea/`, `*.log`, `.env*`
   - **C**: `build/`, `bin/`, `obj/`, `out/`, `*.o`, `*.a`, `*.so`, `*.exe`, `*.dll`, `autom4te.cache/`, `config.status`, `config.log`, `.idea/`, `*.log`, `.env*`
   - **Swift**: `.build/`, `DerivedData/`, `*.swiftpm/`, `Packages/`
   - **R**: `.Rproj.user/`, `.Rhistory`, `.RData`, `.Ruserdata`, `*.Rproj`, `packrat/`, `renv/`
   - **Universal**: `.DS_Store`, `Thumbs.db`, `*.tmp`, `*.swp`, `.vscode/`, `.idea/`

   **Tool-Specific Patterns**:
   - **Docker**: `node_modules/`, `.git/`, `Dockerfile*`, `.dockerignore`, `*.log*`, `.env*`, `coverage/`
   - **ESLint**: `node_modules/`, `dist/`, `build/`, `coverage/`, `*.min.js`
   - **Prettier**: `node_modules/`, `dist/`, `build/`, `coverage/`, `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`
   - **Terraform**: `.terraform/`, `*.tfstate*`, `*.tfvars`, `.terraform.lock.hcl`
   - **Kubernetes/k8s**: `*.secret.yaml`, `secrets/`, `.kube/`, `kubeconfig*`, `*.key`, `*.crt`

5. Parse tasks.md structure and extract:
   - **Task phases**: Setup, Tests, Core, Integration, Polish
   - **Task dependencies**: Sequential vs parallel execution rules
   - **Task details**: ID, description, file paths, parallel markers [P]
   - **Execution flow**: Order and dependency requirements
   - **Task selection**: Choose the next candidate from the parsed task structure, not from
     ad-hoc checkbox grep/regex output
   - **Task-state verification**: When needed, verify only the candidate task against relevant
     files/tests; do not broaden this into a repository-wide audit

6. Execute implementation following the task plan:
   - **Environment routing**: Before each command, route it using `AGENTS.md`.
     Frontend/Node commands run on the Ubuntu host.
     PHP/Nextcloud commands MUST use the approved `scripts/dev/*` wrappers.
     Do not retry a failed PHP/Nextcloud command on the host, do not probe for host PHP,
     and do not replace a wrapper with an ad-hoc `docker exec` command merely because a
     binary or path is unavailable.
   - **Phase-by-phase execution**: Complete each phase before moving to the next
   - **Respect dependencies**: Run sequential tasks in order, parallel tasks [P] can run together
   - **Follow TDD approach**: Execute test tasks before their corresponding implementation tasks
   - **File-based coordination**: Tasks affecting the same files must run sequentially
   - **Validation checkpoints**: Verify each phase completion before proceeding

7. Implementation execution rules:
   - **Setup first**: Initialize project structure, dependencies, configuration
   - **Tests before code**: If you need to write tests for contracts, entities, and integration scenarios
   - **Core development**: Implement models, services, CLI commands, endpoints
   - **Integration work**: Database connections, middleware, logging, external services
   - **Polish and validation**: Unit tests, performance optimization, documentation

8. Progress tracking and error handling:
   - Report progress after each completed task
   - For significant completed tasks, create/update `docs/reports/TXXX.md` as required
     by `AGENTS.md`; do not create reports for trivial work.
   - If a PHP/Nextcloud command fails because a wrapper is missing, not executable, or uses
     an invalid path, stop that operation and report the wrapper problem. Do not fall back
     to host PHP or an invented Docker command.
   - If `php: not found` appears, treat it as an execution-boundary violation and rerun the
     intended operation through the appropriate `scripts/dev/*` wrapper.
   - Halt execution if any non-parallel task fails
   - For parallel tasks [P], continue with successful tasks, report failed ones
   - Provide clear error messages with context for debugging
   - Suggest next steps if implementation cannot proceed
   - **IMPORTANT** For completed tasks, make sure to mark the task off as [X] in the tasks file.

9. Completion validation:
   - Verify all required tasks are completed
   - Check that implemented features match the original specification
   - Validate that tests pass and coverage meets requirements
   - Confirm the implementation follows the technical plan

Note: This command assumes a complete task breakdown exists in tasks.md. If tasks are incomplete or missing, suggest running `/speckit.tasks` first to regenerate the task list.

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
## Known Baseline Failures

When broader verification reveals a failing test:

- Determine whether the failure is newly introduced by the active implementation or is an already-known repository gap covered by an existing incomplete task.
- A known failure already covered by an incomplete task must not be reported as a passing verification result.
- Do not classify such a known failure as a new regression solely because it appears during broader verification.
- Record the failing test, error, and covering task ID, then continue when the task dependency/order permits.
- A new or unexplained regression must be investigated before the active task can be considered complete.


## Parallel Task Execution

Tasks marked `[P]` may be executed concurrently when all of the following are true:

- they have no dependency relationship;
- their expected write scopes do not overlap;
- they do not mutate the same shared runtime/database/test state;
- neither task changes shared coordination files such as `tasks.md`,
  dependency manifests, routes, DI/bootstrap, or migrations.

Prefer a maximum of 3 concurrent implementation workers.

After a parallel batch completes:

1. reconcile all changes;
2. run the relevant combined verification;
3. update task state serially;
4. resolve any conflict before starting the next batch.

If independence is uncertain, execute the tasks sequentially.



## Completion Report

Report final status with summary of completed work.

## Done When

- [ ] All tasks in tasks.md completed and marked `[X]`
- [ ] Implementation validated against specification, plan, and test coverage
- [ ] Runtime/tool commands executed through the correct host commands or approved `scripts/dev/*` wrappers per `AGENTS.md`
- [ ] Significant task reports created when required by `AGENTS.md`
- [ ] Extension hooks dispatched or skipped according to the rules in Mandatory Post-Execution Hooks above
- [ ] Completion reported to user with summary of completed work
