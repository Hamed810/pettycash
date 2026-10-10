# AGENTS.md

## Purpose

Repository-specific operating rules for AI coding agents working in `pettycash`.

These rules do not replace Spec Kit, its commands, or its artifacts.

Source-of-truth order:

1. `constitution.md`
2. active `spec.md`
3. active `plan.md`
4. active `tasks.md`
5. approved contracts
6. verified implementation
7. runtime and test evidence

When approved requirements and existing implementation disagree, do not silently redefine the requirement from the code.

---

# Non-Negotiable Execution Rules

These rules are binding for every non-trivial coding task in this repository. They are requirements, not preferences. They are stated here, at the top, because violating them is the primary failure mode in this repository.

1. **Discover before you search.** When the implementation area, related behavior, precedent, or cross-file context is not already known and verified, perform actual task-relevant OCI semantic discovery *before* broad repository investigation.
2. **`index_status` is a readiness check only.** It does **not** count as OCI discovery.
3. **Inspect before you edit.** For PHP work, inspect the actual symbol definition/signature with Serena before editing it. Inspect references, callers, implementations, or inheritance when those relationships can affect the change.
4. **`initial_instructions` is initialization only.** It does **not** count as semantic inspection.
5. **No substitution.** Repeated `grep`, `rg`, `sed`, file reads, or failing tests must not be used as a substitute for OCI discovery or Serena inspection while those tools are available.
6. **Bridge failure is not an empty index.** If OpenCode OCI calls fail, return `{}`, or return structurally invalid results while `index_status` reports a ready, non-empty, compatible index, treat that as an integration/bridge failure. Before falling back to textual search, use the OCI CLI:
OCI CLI executable:
~/.config/opencode/node_modules/.bin/ocbi
   - `ocbi search`
   - `ocbi definition`
   - `ocbi graph callers`
   - `ocbi graph callees`
7. **Do not reindex around a bridge failure.** Never rebuild or force-reindex a healthy OCI index because the OpenCode OCI bridge failed.
8. **Serena unavailability has a defined fallback.** Re-check availability first. If it remains unavailable, inspect the exact defining source before editing. Never infer signatures, constructor visibility, parameter order, interfaces, or semantic constraints from nearby call sites, and never discover them by repeatedly running failing tests.
9. **Targeted tests by default.** Full PHPUnit, full Vitest, aggregate frontend gates, and full builds belong at meaningful integration, feature, and milestone boundaries — not after every local edit. See [Testing](#testing).
10. **Tests are not an API discovery mechanism.** A failing test must not be the first way you learn an existing API, route, constructor, symbol, or method signature when OCI, Serena, or exact source inspection can establish it first.
11. **Report the real path.** The completion report for non-trivial coding work must include one concise line naming the code-intelligence path actually used, e.g. `Code intelligence: OCI=<actual discovery>; Serena=<actual symbol inspections>`. If a fallback was required, state the fallback instead of claiming tool use.

The sections below provide repository detail. They reference this contract; they do not restate it.

---

# Project Context

- Nextcloud 34 application.
- Repository: `/home/ubuntu/projects/pettycash`
- Canonical container mount: `/var/www/html/custom_apps/pettycash`
- App id: `pettycash`
- Nextcloud container: `pettycash-lab-nextcloud-1`
- Database container: `pettycash-lab-db-1`
- Docker lab: `/home/ubuntu/projects/nextcloud-lab`
- Lab interface: `/home/ubuntu/projects/nextcloud-lab/bin/lab pettycash <command>`
- Compose project name: `pettycash-lab`
- Lab URL: `http://localhost:8081`

This repository has its OWN Nextcloud lab. PTS has a separate one (`pts-lab`, port 8082),
with its own database, volumes, network, users and test data. Nothing is shared. A runtime
that carries both apps is the retired shared lab; see
`/home/ubuntu/projects/nextcloud-lab/legacy/README.md`.

---

# Execution Boundaries — MANDATORY

Two execution environments. Know which one you are in before running anything.

## Host Development Environment

Run directly on the Ubuntu host from the repository:

- OpenCode
- Spec Kit
- Git
- Node.js
- npm / npx
- Vite
- Vitest
- TypeScript / `vue-tsc`
- ESLint
- Stylelint
- Playwright

Prefer existing `package.json` scripts:

```bash
npm test              # vitest run
npm run typecheck     # vue-tsc --noEmit
npm run lint          # eslint src tests/Frontend
npm run stylelint
npm run build
npm run test:e2e
npm run check         # portability + typecheck + lint + stylelint + test + build
```

`npm run check` covers the frontend gates only. It does not run PHP tests.

Do not move frontend tooling into the Nextcloud container unless explicitly required.

## PHP / Nextcloud Runtime

PHP and Nextcloud tooling are container-only.

Never run on the host:

```bash
php ...
composer ...
phpunit ...
./vendor/bin/*
vendor/bin/*
occ ...
```

Do not probe host PHP or Composer (`php -v`, `which php`, `command -v php`, `composer --version`, `which composer`).

Use repository wrappers:

| Need | Command |
|---|---|
| Run all PHP tests | `scripts/dev/phpunit` |
| Run one PHP test | `scripts/dev/phpunit <test>` |
| List PHP tests | `scripts/dev/phpunit --list-tests` |
| OCC | `scripts/dev/occ <args>` |
| Composer | `scripts/dev/composer <args>` |
| Arbitrary runtime command | `scripts/dev/in-container <cmd>` |
| Opcode settle | `scripts/dev/opcache-settle` |
| Route settle | `scripts/dev/route-settle` |
| Route cache clear | `scripts/dev/route-cache-clear` |

Do not manually construct `docker exec` commands when a wrapper exists. Do not guess container paths.

If a required wrapper is missing or not executable: stop that operation, report the missing wrapper, and do not fall back to host PHP, invent a replacement runtime, or silently construct an ad-hoc Docker command.

A `php: not found` result is an execution-boundary violation, not an environment fact. Use the wrapper.

## Composer Boundary

Composer is external development tooling and does not run inside the shared Nextcloud runtime container. Use only `scripts/dev/composer ...`.

Do not:

- run Composer directly on the host
- install Composer into the shared runtime container
- change Composer-managed file ownership back to `www-data` or root
- bypass required platform extensions merely to force dependency resolution

---

# Runtime Caches — MANDATORY

Stale caches produce false results. A result affected by a stale cache is not evidence.

## Opcode Cache

PHP HTTP behavior may be stale after edits under `lib/` or `appinfo/`.

- `scripts/dev/phpunit` settles the opcode cache before PHP verification. Do not substitute direct PHPUnit execution.
- For direct live-HTTP probing after edits under `lib/` or `appinfo/`, settle first with `scripts/dev/opcache-settle`.
- Settle before measuring; do not wait for a stale HTTP result to reveal the problem.
- If `scripts/dev/opcache-settle` reports an outstanding wait or failure, the affected HTTP or contract-test result is invalid until resolved.

Editing frontend code, tests, documentation, or development scripts does not require opcode settling unless runtime PHP source also changed.

## Route Cache

Changes to `appinfo/routes.php` require route-cache handling:

```bash
scripts/dev/route-settle
scripts/dev/route-cache-clear
```

Opcode settling does not refresh Nextcloud's route collection. Do not substitute ad-hoc APCu clearing or guessed Apache signals.

---

# Docker Safety — MANDATORY

The Docker environment is shared by pettycash and other applications. This repository does not own Docker lifecycle.

Never:

- delete Docker volumes or run `docker compose down -v`
- remove Nextcloud or database volumes
- create a replacement Docker environment
- recreate unrelated containers
- change the Compose project name
- modify lab Docker configuration without explicit instruction

Lab resources owned by this project: `pettycash-lab-nextcloud-1`, `pettycash-lab-db-1`,
`pettycash-lab-volume-init` container, `pettycash-lab_default`, `pettycash-lab_nc-data`,
`pettycash-lab_nc-db`.

The PTS lab (`pts-lab-*`) belongs to the sibling repository. Never stop, start, reconfigure
or inspect its volumes as part of work here — except with `bin/lab isolation`, which only
reads. A change that makes this app appear in the PTS lab, or vice versa, is a defect, not a
configuration choice.

For runtime recovery, use the lab at `/home/ubuntu/projects/nextcloud-lab`. Do not run
Docker Compose from this repository:

```bash
/home/ubuntu/projects/nextcloud-lab/bin/lab pettycash up
```

Inspect first with `docker ps -a`. Do not create a new environment because the existing one
is stopped.

---

# Code Investigation and Tool Routing

The binding rules are in [Non-Negotiable Execution Rules](#non-negotiable-execution-rules). This section is the reference detail.

## Evidence Hierarchy

Pick the tool that matches the question:

| Question | Authority |
|---|---|
| Concept, unknown area, related behavior, precedent, cross-artifact context | OCI semantic discovery |
| Known candidate symbol, authoritative definition site | OCI definition/implementation lookup |
| Relationships, blast radius, cross-layer flow | OCI graph, when it adds information |
| Exact PHP semantics | Serena |
| Control flow and behavior | source |
| Textual fact at a known location | exact text search |
| Behavioral proof | tests / runtime |
| Actual change set | `git status` / `git diff` |

OCI is discovery evidence. Serena is PHP semantic evidence. Source is implementation evidence. Tests and runtime are behavioral evidence. None substitutes for another; do not present a weaker method as equivalent.

## OCI — Discovery

OCI is the default first discovery layer for non-trivial repository discovery: unknown implementation areas, domain/behavior discovery, related concepts, analogous implementations, precedents, cross-artifact discovery, behavior spread across files or layers, and locating related specs, reports, tests, and source.

Primary tools: `codebase_context`, `codebase_peek`, `codebase_search`, `implementation_lookup`.

OCI output is not authoritative proof. Do not treat chunk boundaries, rankings, inferred communities, or indexed call edges as exact source semantics.

OCI is not required for an exact, already-known, genuinely local edit where no repository discovery is needed.

### Fallback Order

`OpenCode OCI tools → OCI CLI → narrow textual/source fallback`

Going directly from an OpenCode OCI failure to broad `grep` is prohibited. Use `ocbi search`, `ocbi definition`, `ocbi graph callers`, `ocbi graph callees` first. See rule 6 in the execution contract for how to recognize a bridge failure versus an empty index.

### OCI Graph

Use graph, call-graph, and architecture tools when they materially improve discovery: cross-layer execution flows, unknown callers or downstream areas, blast radius, architectural relationships, related subsystems, and how several discovered symbols connect.

For PHP relationships that drive an implementation decision: use graph to discover likely relationships, verify concrete symbols with Serena, then inspect source when control flow matters.

Do not run graph analysis mechanically when the exact relationship is already known and verified.

### OCI Index Lifecycle

Inspect `index_status` before creating or refreshing an index, and reuse that status within a session unless indexing or repository state materially changes.

- Reuse an existing usable semantic index whenever possible.
- Distinguish branch/catalog state from the semantic embedding index.
- A populated catalog with an empty or stale semantic index may be incrementally refreshed.
- Use estimate-only mode before a potentially expensive refresh when available.
- Do not force-reindex unchanged content or change the embedding provider/model during normal implementation.
- Report meaningful index mutations when they occur.
- Treat `.opencode/index/` as tooling state, not repository source state.
- Do not claim indexing had no network activity merely because Ollama is local infrastructure. Report the configured endpoint accurately when network/privacy behavior matters.

## Serena — Semantic PHP Navigation

Serena is the authoritative semantic navigation layer for PHP source work. Call `initial_instructions` once per OpenCode session before the first code-oriented investigation; do not begin PHP semantic investigation or PHP editing before it. Do not re-initialize for later tasks in the same session unless Serena restarted or the active project changed.

Use Serena for symbol definitions, exact symbol boundaries, references, callers and usages, implementations, inheritance and interface relationships, symbol-level dependency verification, diagnostics, and refactoring targets.

Before editing a PHP symbol, verify that symbol with Serena. A known file path does not make text search equivalent to semantic symbol verification.

### Fallback Order

`Serena available → inspect actual symbols`
`Serena unavailable after re-check → inspect the exact defining source`

Never infer an API or signature from call sites, and never discover a signature by repeatedly running failing tests.

## Context7

Use Context7 when the task depends on external framework, library, or API behavior and repository evidence is insufficient: Nextcloud API behavior, `@nextcloud/vue`, third-party package APIs, framework version-specific behavior.

Do not call Context7 merely to satisfy a checklist.

---

# Testing

Use the correct execution environment for every test (see [Execution Boundaries](#execution-boundaries--mandatory)). Scale verification to change scope and meaningful milestone boundaries — not to a fixed "every N tasks" rule.

## During Implementation

- Targeted PHP test file/class/filter via `scripts/dev/phpunit <test>`
- Targeted Vitest spec(s)
- File-scoped lint where appropriate
- No full-suite reruns merely for reassurance

## Before Completing One Task

- Affected-area verification
- Typecheck when type relationships changed
- Relevant lint/style checks
- Expand scope only when the change is cross-cutting

## Feature / Phase / Milestone Boundary

```bash
npm run check
scripts/dev/phpunit
```

Run E2E or compatibility suites only when the task or environment requires it.

## Rules

- Do not duplicate checks already included in `npm run check` on an unchanged tree.
- Do not rerun an unchanged full-project check just to obtain the same evidence again.
- A broad regex filter is not automatically "targeted". Prefer explicit affected tests where practical.
- A passing test from the wrong runtime is not evidence.

---

# Spec Kit

Spec Kit owns task sequencing, the implementation workflow, artifact workflow, and requested scope. This file owns repository safety, environment/runtime rules, investigation/tool-routing rules, and verification policy.

When running a Spec Kit workflow:

- Follow the active Spec Kit command and artifacts.
- Treat approved specifications and contracts as intended-behavior authority.
- Do not let existing implementation silently redefine approved requirements.
- Do not silently reinterpret a requested scope. If it contains human-only, externally blocked, or non-agent-executable work, surface that constraint before changing scope rather than substituting a self-selected critical path.

For Spec Kit artifacts:

- Use exact text search/read for known task IDs, requirement IDs, headings, checkbox state, or exact wording.
- Use OCI when semantic or cross-artifact discovery would be faster or more informative. Do not scan broad task-number ranges merely to discover related work when semantic discovery is the real question.

---

# Architecture

Preserve the existing layer boundaries. Do not move business logic into controllers or UI components.

- **Domain** — business rules, entities, value objects, invariants. Must not depend on Nextcloud services.
- **Application** — use cases, orchestration, workflows.
- **Infrastructure** — persistence, Nextcloud services, external integrations.
- **Presentation** — OCS API, Vue frontend.

## Frontend Framework

Approved UI library: `@nextcloud/vue` 9.x. Vuetify is not part of the approved stack.

Do not install Vuetify, introduce `v-*` Vuetify components, or configure Vuetify merely to make tests pass.

Treat Vuetify-style components found in existing source as implementation drift and resolve them through the approved project workflow.

---

# Git Safety

Before significant changes, inspect `git status` and `git diff`. These are authoritative for what changed.

Never:

- discard unrelated changes
- reset repository state
- clean unrelated working-tree content
- stage unrelated files
- use broad `git add .` or `git add -A` in a dirty tree
- push automatically

Preserve unrelated working-tree changes.

---

# Reports and Architecture Decisions

Significant completed work follows the repository's existing reporting conventions under `docs/reports/`. Do not create process-heavy reports for trivial changes unless the active workflow requires them.

Durable architectural decisions belong under `docs/decisions/`. Create ADRs for those, not for routine bug fixes, ordinary refactoring, temporary implementation details, or agent workflow rules.

---

# Final Principles

Keep repository work bounded, evidence-based, and safe.

- OCI discovers; Serena verifies PHP semantics; source explains implementation; tests/runtime verify behavior; git verifies the change set.
- Discovery is not proof. Text occurrence is not a semantic reference.
- A passing test from the wrong runtime is not evidence.
- A result affected by stale runtime caches is not evidence.
- If a preferred tool was unavailable, say so instead of presenting a weaker method as equivalent.