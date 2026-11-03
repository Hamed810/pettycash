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

These rules are binding for every non-trivial coding task. They apply to every coding agent and model, regardless of provider, capability tier, or role. Select a tool because the task needs its evidence, not to satisfy a tool-usage quota.

1. **Discover before broad search.** For an unknown implementation area or behavior, use relevant, operational OCI semantic discovery before broad repository exploration. For a known symbol or a structural dependency question, use the appropriately scoped CGC, Serena, or exact-source lookup instead. An exact, fully known local edit does not require artificial discovery.
2. **Route by evidence type.** OCI provides semantic/conceptual discovery; CodeGraphContext (CGC) provides indexed structural relationships and impact candidates; Serena provides precise symbol navigation; source and contracts establish implementation facts; tests/runtime establish observed behavior. Do not describe one tool as a complete substitute for another.
3. **Readiness is not discovery.** OCI `index_status`, CGC `get_repository_stats` or `list_indexed_repositories`, and Serena `initial_instructions` are readiness/initialization checks. None counts as inspecting implementation.
4. **Inspect before editing PHP.** Inspect the actual symbol definition/signature with Serena when available. Inspect callers, references, implementations, and inheritance when material to the edit. If Serena is unavailable after a re-check, inspect the exact defining source. Never infer a PHP signature from call sites or failing tests.
5. **Do not replace available semantic evidence with blind scans.** Repeated `grep`, `rg`, `sed`, or unrelated test failures are not substitutes for applicable, healthy semantic or structural tools. Narrow, exact-source inspection is always appropriate for authoritative verification.
6. **Treat OCI failures accurately.** OCI is an OpenCode plugin, not an MCP server. An OCI plugin/bridge error, empty response, or structurally invalid result does not establish that the index is empty. Check index status and use the verified OCI CLI when available before making a bridge-failure diagnosis. If the CLI is absent or also fails, use CGC, Serena, and focused source inspection and report the limitation.
7. **Treat incomplete OCI embeddings accurately.** A completed indexing process with failed embedding batches is a *partially covered* semantic index, not a fully covered one. Do not assume an absent OCI search result proves absence in source. Check the current indexing report and `failed-batches.*.json` when coverage matters. Never silently initiate an expensive full re-embedding to fix a missed result.
8. **Treat CGC failures accurately.** A connected MCP server does not guarantee the graph is current or comprehensive. An empty, ambiguous, or failed CGC query is not proof of no dependency. Validate repository/context selection, graph freshness, and source. Do not rebuild the graph merely because the MCP bridge or a query failed.
9. **Protect indexing state.** Reuse existing OCI and CGC data. No force-reindex, index deletion, embedding-provider/model change, CGC backend switch, graph reset, or unapproved long-running watcher as an incidental step. Use supported incremental reconciliation only when needed, and avoid concurrent writers to the embedded CGC database.
10. **Use bounded investigation.** Query only what the task requires. Prefer direct callers/callees, scoped modules, and short paths before recursive traversal or repository-wide graph output. Avoid redundant fan-out across tools.
11. **Targeted verification by default.** Run affected tests and appropriate checks; reserve full suites, broad builds, and E2E gates for meaningful integration or milestone boundaries. See [Testing](#testing).
12. **Tests are not API discovery.** Inspect existing routes, symbols, constructors, and contracts before using tests to verify their behavior. Do not repeatedly run failing tests to guess signatures.
13. **Report actual evidence.** Completion reports for non-trivial coding work must name the code-intelligence path actually used and any unavailable tools; never claim unused tools. Example: `Code intelligence: OCI=<semantic discovery or unavailable>; CGC=<relationship analysis or not needed>; Serena=<symbol inspection or unavailable>; source=<verified files>`.

The sections below elaborate this contract; they do not override it.

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

Separate host development, Nextcloud runtime, and CGC tooling environments. Know which one is appropriate before running anything.

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

## CodeGraphContext Tooling Container

CGC is a separate development-tooling container, **not** the Nextcloud application/runtime container. Its Python dependencies belong in the CGC Docker image, not on the host or inside Nextcloud. Do not move PHP/Composer execution into the CGC container.

Current local deployment (verify before relying on it):

- MCP server name: `codegraphcontext` (registered through the MCP client).
- Docker image: `pettycash-cgc:current`.
- Source inside CGC: `/workspace`, mapped read-only from `/home/ubuntu/projects/pettycash`.
- Persistent CGC configuration and graph: `/home/cgc/.codegraphcontext`, bind-mounted from `/home/ubuntu/projects/pettycash/.cgc-data`.
- Embedded database backend: FalkorDB Lite, unless its verified configuration says otherwise.
- CGC ignore rules: `.cgcignore`, combined with applicable Git ignore rules.

Use the configured MCP integration for routine graph queries. Do not install Python packages on the host, replace the Docker image, modify global MCP settings, or launch a second database-writing process as part of normal code investigation. For any CLI fallback, confirm the container uses the **same** repository and persistent-data mounts as the MCP server, and coordinate access to the embedded database. A CLI that starts with a fresh, empty home directory is not checking the indexed project.

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

The binding rules are in [Non-Negotiable Execution Rules](#non-negotiable-execution-rules). This section provides specific routing and lifecycle guidance; it is model- and agent-independent.

## Evidence Hierarchy

Choose the narrowest source that can answer the question reliably:

| Question | Preferred evidence |
|---|---|
| Unknown behavior, concepts, implementation precedent, related specs | OCI semantic discovery, when usable |
| Known indexed symbol or keyword | CGC `find_code`, OCI lookup, or Serena as appropriate |
| Callers, callees, module dependencies, inheritance, change-impact candidates | CGC graph relationships |
| Exact PHP definition, signature, references, implementations | Serena + actual source |
| Nextcloud routes and dynamic registration | `appinfo/routes.php`, controllers, consumers, and actual source |
| Exact text, task/requirement IDs, or known location | Targeted source/text search |
| Actual control flow and approved behavior | Source, specs, and contracts |
| Observed behavior | Appropriate tests and runtime evidence |
| Actual changes | `git status` and `git diff` |

OCI and CGC are discovery evidence, not runtime proof. A graph edge, a search hit, an inferred community, and an exact source definition have different evidentiary strengths. Verify consequential claims against real source; tests/runtime verify behavior.

## Task-Driven Investigation

1. **Classify the question.** Determine whether conceptual discovery, precise symbol inspection, structural relationship analysis, or a known-source lookup is required. Do not invoke all tools routinely.
2. **Find the entry point.** When the area is unknown, prefer targeted OCI semantic discovery. If the target is already known, use CGC/Serena or the defining file directly rather than repeating conceptual search.
3. **Trace dependencies when justified.** Use CGC for scoped callers, callees, importers, module dependencies, class hierarchy, and likely blast radius. Begin with direct relationships; expand depth only when a change crosses boundaries or initial results are insufficient.
4. **Verify exact definitions.** Use Serena for PHP symbols and source inspection for cross-language, dynamic, generated, or framework-mediated behavior. Trace Nextcloud routes in source; do not infer routes from graph edges.
5. **Inspect contracts before edits.** Check approved specs and API contracts, affected interfaces, concrete callers, and tests. Preserve existing architecture and scope.
6. **Avoid unsupported claims.** No tool's missing edge, zero callers, dead-code label, high-complexity score, or ambiguous match alone justifies removal or refactoring. Reconcile conflicting results with source and runtime evidence.
7. **Check freshness when it matters.** For architectural or pre-edit graph claims, verify the correct repository/branch/context and whether the index reflects relevant recent edits. Do not silently index or rebuild merely to satisfy a readiness ritual.
8. **Constrain output.** Prefer path-scoped searches, unique symbol identifiers, short call chains, and concise tool output. Do not dump whole graphs or run broad tests to discover unrelated code.
9. **Handle outage by evidence type.** Use the supported local fallback for the failed tool if safe, then an alternate relevant tool and exact-source verification. State limitations rather than inventing missing capabilities.

## OCI — Semantic Discovery (OpenCode Plugin)

OCI remains installed and is **not** superseded or disabled by this policy. It is an OpenCode plugin, not part of the MCP server list. Use it for unknown implementation areas, conceptual/behavior discovery, related code and artifacts, analogous implementations, semantic search, and supported architecture or branch/PR context.

OCI capabilities, **when actually exposed by the installed plugin**, may include `codebase_context`, `codebase_peek`, `codebase_search`, `implementation_lookup`, `call_graph`, `architecture_context`, `code_communities`, and `pr_impact`. Discover the usable tool surface in the active session; do not assume every capability or branch comparison is supported.

`index_status` is a readiness/coverage diagnostic, not discovery. OCI rankings, embeddings, chunk boundaries, inferred relationships, and community labels are not authoritative. Missing semantic hits require source or other-tool verification, especially after indexing reports partial embedding failures.

### OCI Bridge Failure and CLI Fallback

- Distinguish plugin registration from a working invocation, and an invocation failure from an empty/partial index.
- If the OCI tool bridge fails, check `index_status` if callable. For a usable index, try the OCI CLI **only if its executable and command syntax are verified in the current installation**; known commands include `ocbi search`, `ocbi definition`, `ocbi graph callers`, and `ocbi graph callees`.
- The previously documented executable candidate is `~/.config/opencode/node_modules/.bin/ocbi`; check that it exists rather than assuming it does.
- If the CLI is missing, incompatible, or fails, use CGC for structural questions, Serena for exact symbols, and focused source inspection. Report which path succeeded.
- Never run a full or forced OCI reindex as a bridge repair. Do not alter embedding models/providers to workaround a failed lookup.

### OCI Index Lifecycle

- Check `index_status` before proposing an OCI refresh and reuse a recent status until indexing or repository/branch state changes.
- Indexing `complete` does **not** imply 100% embedding coverage when the run reports failed chunks. Inspect the relevant failure report when a decision depends on missing coverage.
- Keep existing index files, failure reports, branch metadata, and provider configuration intact unless the owner explicitly approves maintenance.
- Do not treat catalog entries, semantic vectors, or branch mappings as interchangeable evidence.
- Avoid expensive re-embedding for routine tasks; use a supported incremental operation only with a concrete freshness need and an understood cost.
- `.opencode/index/` is tooling state, not application source. Do not infer that locally deployed embedding infrastructure implies zero network traffic; verify the configured endpoint when privacy or networking is relevant.

### OCI Freshness During Active Work

During an active implementation slice, the OCI index may temporarily lag behind the current working tree.

For files that have been edited since the last OCI refresh, current source and Serena are the authoritative evidence for the latest implementation state.

Do not trigger OCI reindexing after every intermediate edit, save, test run, or transient work-in-progress change.

Refresh OCI incrementally only when the current implementation slice has reached a stable checkpoint, such as after a coherent edit set is complete, targeted verification has passed, or before a new investigation depends on up-to-date semantic discovery.

Do not use forced reindexing for normal freshness updates.

If OCI results conflict with current source during an active slice, treat the source as current and the OCI result as potentially stale until the next successful incremental refresh.

## CodeGraphContext (CGC) — Structural Graph Analysis (MCP)

CGC complements OCI and Serena. Use the **already indexed** graph for cross-file structure, function calls, module dependencies, inheritance, and candidate change impact; it is not a semantic-embedding replacement or proof of runtime call paths.

### Primary Graph Workflow

1. Select the indexed repository and, when relevant, confirm registration with `list_indexed_repositories` and summary with `get_repository_stats`. These are readiness checks, not analysis.
2. Locate a relevant symbol or file with `find_code` or an exact known path. Narrow by repository and path/context when supported.
3. Use `analyze_code_relationships` for relevant direct callers/callees, importers, inheritance, module dependencies, or a bounded call chain. Avoid unbounded traversal unless explicitly needed.
4. Resolve ambiguous names with their defining file, class/namespace, and source. If a graph relationship is missing, also examine dynamic dispatch, framework conventions, dependency injection, and cross-language integration.
5. Verify implementation-significant results with Serena and exact source. Cite the real file/symbol in the task report rather than presenting raw graph results as authoritative.

Tool names and parameters may differ across installed CGC versions. Prefer the tools actually exposed by the connected server. The MCP capabilities documented for CGC include `find_code`, `analyze_code_relationships`, `get_repository_stats`, `list_indexed_repositories`, and `execute_cypher_query`. Never fabricate unsupported tool names, query types, or relationships.

### Cypher and Destructive Tools

- Prefer high-level, narrowly scoped graph tools over free-form `execute_cypher_query`.
- Use **read-only** Cypher when standard tools cannot answer a justified structural question. Do not run write, delete, create, merge, reset, or schema-altering statements as ordinary discovery.
- Graph ingestion, repository deletion, package downloads, watcher startup, export/import, backend switches, and DB maintenance are state-changing operations requiring a separate justified maintenance task and appropriate authorization.
- Complexity and dead-code analysis are investigation leads only, not grounds for deletion or automatic refactoring.

### CGC Graph Freshness and Safety

- The current CGC repository is persisted under `.cgc-data/` and governed by `.cgcignore`. Reuse it; do not create an alternate DB inadvertently.
- **An already-indexed skip is not a freshness check.** The CLI may report the repository is already indexed even when files have changed. Do not assume that rerunning `cgc index .` reconciles every edit.
- For stale graphs, follow the verified incremental refresh procedure supported by the installed version (for example, the documented update operation or a correctly configured watcher with startup reconciliation). Inspect CLI help and coordinate any write with the running MCP server first.
- Watching is opt-in; do not assume it is running merely because MCP is connected. A watcher started without startup reconciliation may miss changes made while it was stopped.
- Avoid concurrent CGC indexing/watch/maintenance against the same embedded FalkorDB Lite store. Do not start background processes, force-index with `--force`, delete graph data, or switch databases without explicit authorization.
- Check ignore behavior before any new or expanded indexing; keep `.tools/`, `.cgc-data/`, `.opencode/`, `.serena/`, dependencies, generated assets, and sensitive environment files out of the graph.
- Some dynamic or ambiguous call relationships may be unresolved. Their absence does not imply the calls are absent in the program. Verify source when they matter.
- If MCP queries fail, confirm server/repository/context selection first. A standalone CLI fallback must target the same mounted data; avoid concurrent embedded DB access. Treat failure as integration or access trouble, not as a reason to rebuild.

## Serena — Precise Symbol Navigation

Serena is the authoritative symbol-navigation layer for PHP source work. Call `initial_instructions` once per relevant session before first Serena-oriented code investigation. This initializes the tool; it does not count as inspecting a symbol. Do not reinitialize unless the integration restarts or active project changes.

Use Serena for definitions, exact symbol boundaries, references, implementations, inheritance and interface relationships, diagnostics, and refactoring targets. Verify concrete PHP symbols before modifying them. A known file path does not make a text occurrence semantically equivalent to a symbol reference.

### Fallback Order

`Serena available → inspect actual symbols`

`Serena unavailable after re-check → inspect the exact defining source`

Never infer API signatures from call sites or use repeated failing tests to discover them.

## Context7 — External Documentation

Use Context7 for external framework/library/version-specific documentation when local contracts and source are insufficient and the MCP service is reachable. A DNS or connectivity error (for example, `EAI_AGAIN`) is an integration failure, not evidence that the external API lacks documentation.

When Context7 is unavailable, consult pinned local dependency versions, vendored types/docs, and official documentation if network access is permitted and available. State any uncertainty. Do not call Context7 merely to satisfy a checklist.

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

Choose the Spec Kit workflow by request type: new functionality uses the feature/specification flow; an existing-behavior bug uses the bug-assess/fix/test flow; already-approved `spec.md`, `plan.md`, and `tasks.md` go directly to bounded implementation; investigation alone does not trigger implementation. Do not recreate existing artifacts or silently change accepted requirements.

When running a Spec Kit workflow:

- Follow the active Spec Kit command and artifacts.
- Treat approved specifications and contracts as intended-behavior authority.
- Do not let existing implementation silently redefine approved requirements.
- Do not silently reinterpret a requested scope. If it contains human-only, externally blocked, or non-agent-executable work, surface that constraint before changing scope rather than substituting a self-selected critical path.

For Spec Kit artifacts:

- Use exact text search/read for known task IDs, requirement IDs, headings, checkbox state, or exact wording.
- Use OCI for semantic or cross-artifact discovery when that improves the task; use CGC for relevant structural relationships. Neither substitutes for exact known task-ID searches or the authoritative Spec Kit artifacts.

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

- OCI discovers conceptual/semantic context; CGC maps structural relationships and candidate impact; Serena verifies exact PHP symbols; source and contracts establish implementation; tests/runtime verify behavior; git verifies the change set.
- Discovery is not proof. Text occurrence is not a semantic reference.
- A passing test from the wrong runtime is not evidence.
- A result affected by stale runtime caches is not evidence.
- If a preferred tool was unavailable, say so instead of presenting a weaker method as equivalent.
