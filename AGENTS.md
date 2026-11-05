# Pettycash — Agent Operating Rules

Repository-wide rules for **all AI coding agents**, including read-only investigation. Follow the user's scope. These rules govern evidence selection, execution boundaries, safety, and verification; they do not replace approved Spec Kit artifacts.

## 1. Authority and task routing

**Intended behavior authority:** `constitution.md` → active `spec.md` → `plan.md` → `tasks.md` → approved contracts. **Implemented behavior:** current source. **Observed behavior:** correctly executed tests/runtime. If they disagree, report the conflict; never silently redefine requirements from code.

Classify work before invoking a workflow:

- **New capability / functional change:** `/speckit-specify` → `/speckit-plan` → `/speckit-tasks` → bounded `/speckit-implement` → `/speckit-converge`; add `/speckit-clarify`, `/speckit-checklist`, `/speckit-analyze` when needed.
- **Bug / broken existing behavior:** `/speckit-bug-assess` → `/speckit-bug-fix` → `/speckit-bug-test`; complete only when `verified`. Do not create a feature spec unless intended behavior changes.
- **Approved `spec.md` + `plan.md` + `tasks.md`:** implement existing tasks only; use bounded batches for large ledgers.
- **Requirement/scope change:** update specification and dependent artifacts before code.
- **Investigation:** read-only; do not invoke implementation.
- **Human-only/external blocker:** surface it; do not silently substitute scope or claim completion.

Unimplemented Spec Kit features are not automatically in scope for frontend redesign or other bounded work.

---

## 2. Mandatory evidence-selection gate

Before broad exploration, classify the evidence required. These triggers apply to investigation, debugging, planning, and edits. When the target is not already known and a matching capability is healthy and exposed, use one bounded query from the route selected by this table before broad manual exploration. Known paths, identifiers, literals, and already-located authoritative source do not require an artificial discovery call. This is not a tool quota.

| Need | Route | Verification |
| --- | --- | --- |
| Unknown behavior/concept/location | **OCI** semantic discovery | Verify consequential hits in source/contracts |
| Cross-file callers/callees/dependency chains/module relationships/change impact | **CGC** structural graph | Verify material edges in source; account for dynamic/framework wiring |
| Exact PHP definition/signature/implementation/inheritance or symbol-level references | **Serena** symbol navigation | Verify current defining source |
| Known path/route/ID/literal text | Targeted read or exact text search | Do not force semantic/graph discovery |
| Version-specific external API/library behavior | **Context7** if local evidence is insufficient | Match pinned dependency/version where possible |
| Whether behavior actually works | Targeted tests/runtime when authorized | Search/graph/source is not runtime proof |

Repository evidence routing determines which capability is appropriate for a task. Generic tool/server usage descriptions describe capability, not repository routing. Do not select a tool merely because its server description calls it “primary”, “preferred”, or “critical”, or recommends eager use.

Rules:

1. **Unknown area → OCI first.** One focused semantic query before broad scans; skip when path/symbol is already known.
2. **Structural question → CGC first.** Cross-file callers/callees, dependency chains, module relationships, or blast radius require a bounded graph query when CGC is available.
3. **Exact PHP symbol → Serena first.** Material definition/signature/implementation/inheritance or symbol-level reference questions require Serena when available, including read-only work.
4. **Known exact text → direct lookup.** Requirement IDs, task IDs, routes, filenames, literals: use exact search/read.
5. **Source/contracts remain authoritative for implementation facts.** Discovery tools identify candidates; they do not prove runtime behavior.
6. **Bound queries.** Start with direct relationships, narrow paths/context, and small result sets; expand only when needed.

If a question spans multiple evidence types, start with the most specific evidence required. Use another route only for a distinct unresolved sub-question or when the selected route is insufficient or unavailable.

Examples:

- “Where is receipt enforcement implemented?” → OCI discovery → verify use case/domain source and relevant tests.
- “What changes if `ResubmitCorrection` changes?” → CGC callers/module deps → verify routes/source/contracts/tests.
- “What is `ExpenseTransaction::recordDecision`'s exact signature?” → Serena symbol lookup → defining source; never infer from tests.
- “Find `FR-085`.” → exact text search only.

### Failure handling

Distinguish **not exposed**, **invocation failed**, **empty/ambiguous**, **possibly stale/partial**, and **not needed**.

If a required tool call fails because of invalid arguments or schema usage, inspect the exposed schema and retry once with corrected arguments. For other failures, re-check availability once. If the tool still cannot provide the required evidence, use the documented fallback and report the limitation accurately.

**Never rebuild, delete, reset, or reconfigure an index as an incidental response to a failed query.**

---

## 3. Tool rules

Tool selection is defined only by the evidence-routing table in §2. The sections below define how to use a selected tool safely and efficiently; they do not redefine when that tool should be selected.

### OCI — semantic discovery

OCI is an **OpenCode plugin**, not an MCP server.

- When selected by §2, use OCI for one focused semantic discovery pass over unfamiliar implementation areas, concepts, analogous code, or supported architecture context.
- Check readiness only when uncertain; readiness checks are not code investigation.
- Prefer one compact `codebase_context`-style pass for unfamiliar code; once scoped, use exposed definition/call-graph operations or exact source instead of repeated semantic searches.
- If the bridge fails, verify the installed OCI CLI and its actual syntax before using it; do not assume executable names/paths.
- Missing semantic hits do not prove source absence, especially with partial embedding coverage.
- Do not force reindex or change embedding model/provider to repair lookup failures.
- During active edits, current source and Serena outrank a stale semantic index; refresh only at a justified stable checkpoint using supported incremental reconciliation.

### CodeGraphContext — structural graph

Current project deployment:

- MCP server: `codegraphcontext`
- image: `pettycash-cgc:current`
- repository in CGC: `/workspace`
- persistent config/graph in container: `/home/cgc/.codegraphcontext`
- host persistence: `/home/ubuntu/projects/pettycash/.cgc-data`
- backend: embedded FalkorDB Lite unless verified otherwise

When selected by §2, use the existing graph and prefer bounded `find_code` or `analyze_code_relationships` queries for the required structural evidence, such as direct callers/callees, importers, `class_hierarchy`, `call_chain`, or `module_deps`. Use repository stats/listing only as readiness checks. Resolve ambiguous symbols by file/class/namespace. Missing edges do not prove absence; verify dynamic dispatch, dependency injection, framework wiring, and cross-language paths in source.

Prefer high-level tools over raw Cypher; if Cypher is necessary, keep it **read-only**. Never perform ordinary-investigation writes: no repository deletion, package ingestion, bundle loading, schema mutation, watcher startup, graph reset, or second writer/container against the same embedded store. “Already indexed” is not a freshness guarantee; use only the verified incremental update path when freshness materially matters.

### Serena — exact symbols

When §2 selects Serena, use the actual symbol operations required for the exact PHP definition, signature, implementation, inheritance/type relationship, or symbol-level references.

Direct source reading verifies the result; it does not replace the symbol query.
Use the **actual tool surface exposed by the active Serena configuration**; effective names may vary by backend/configuration. Initialize as required by the integration (for example `initial_instructions` once when applicable); initialization does not count as symbol inspection.

Use symbol-level operations for definitions, references, implementations, inheritance/type relationships, and diagnostics. Before modifying PHP, inspect the actual defining symbol/signature plus material callers/references/implementations. Never discover PHP APIs by repeatedly failing tests.

### Context7 — external docs

When §2 selects Context7, resolve the library ID first, then query docs, unless the exact Context7 ID is already known. Keep each query to one concept and prefer the pinned version. If unavailable, use pinned local dependency information and official docs when permitted; state uncertainty. Do not call it merely to satisfy a checklist.

---

## 4. Index/tooling-state safety

- Reuse existing OCI and CGC state.
- Graph/index maintenance is never an ordinary investigation step. Do not start watchers, index/reindex repositories, rebuild/reset/delete graph or index state, change backends or embedding providers/models, install indexing dependencies, or introduce another writer unless the user explicitly requests that maintenance operation.
- Connected MCP does not prove graph freshness/completeness. OCI `complete` may still have failed embedding batches; inspect coverage only when a missing result affects a decision.
- Keep `.opencode/`, `.cgc-data/`, `.serena/`, `.tools/`, dependencies/generated assets, and sensitive env files out of graph/index scope where configured.
- Coordinate any CGC maintenance with the running MCP server; avoid concurrent writers.

---

## 5. Execution boundaries

Project identity:

- Nextcloud app/version: `pettycash`, `34`
- repository: `/home/ubuntu/projects/pettycash`
- runtime mount: `/var/www/html/custom_apps/pettycash`
- lab root: `/home/ubuntu/projects/nextcloud-lab`
- lab command: `/home/ubuntu/projects/nextcloud-lab/bin/lab pettycash <command>`
- runtime containers: `pettycash-lab-nextcloud-1`, `pettycash-lab-db-1`
- lab URL: `http://localhost:8081`

Pettycash and PTS are separate labs. `pts-lab-*` resources and port `8082` belong to the sibling project.

### Host development

Run OpenCode, Spec Kit, Git, Node/npm, Vite, Vitest, TypeScript/`vue-tsc`, ESLint, Stylelint, and Playwright on Ubuntu host. Prefer existing scripts:

```bash
npm test
npm run typecheck
npm run lint
npm run stylelint
npm run build
npm run test:e2e
npm run check
```

`npm run check` covers frontend gates only; it does not run PHP tests.

### PHP / Nextcloud runtime

Never run host PHP/Composer/PHPUnit/OCC, including version probes:

```bash
php ...
composer ...
phpunit ...
./vendor/bin/*
vendor/bin/*
occ ...
```

Use wrappers only:

```bash
scripts/dev/phpunit <test>
scripts/dev/phpunit --list-tests
scripts/dev/occ <args>
scripts/dev/composer <args>
scripts/dev/in-container <cmd>
scripts/dev/opcache-settle
scripts/dev/route-settle
scripts/dev/route-cache-clear
```

If a required wrapper is missing/non-executable, stop that operation and report it. Do not fall back to host PHP or ad-hoc `docker exec` when a wrapper exists.

### Composer boundary

Use only `scripts/dev/composer <args>`. Do not install Composer into the shared runtime, change Composer-managed ownership to `www-data`/root, or bypass required platform extensions merely to force dependency resolution.

---

## 6. Runtime caches

A stale-cache result is not evidence.

- After `lib/` or `appinfo/` PHP edits, `scripts/dev/phpunit` handles its own opcode settling; before direct live-HTTP checks run `scripts/dev/opcache-settle`.
- If opcode settling reports a wait/failure, affected HTTP/contract evidence is invalid until resolved.
- After `appinfo/routes.php` changes, use:

```bash
scripts/dev/route-settle
scripts/dev/route-cache-clear
```

Opcode settling does not replace route-cache handling.

---

## 7. Docker safety

The Docker host is shared; this repository does not own global Docker lifecycle.

Pettycash runtime resources include:

- `pettycash-lab-nextcloud-1`
- `pettycash-lab-db-1`
- `pettycash-lab-volume-init`
- `pettycash-lab_default`
- `pettycash-lab_nc-data`
- `pettycash-lab_nc-db`

Never delete volumes, run `docker compose down -v`, replace the lab because it is stopped, recreate unrelated containers, change the Compose project name, modify lab Docker configuration without approval, or stop/start/reconfigure PTS resources. Do not inspect/manipulate PTS volumes except through an explicitly approved read-only isolation check.

For recovery, inspect first and use:

```bash
/home/ubuntu/projects/nextcloud-lab/bin/lab pettycash up
```

Do not run Docker Compose from the Pettycash repository. CGC is tooling, not runtime: do not move PHP/Composer work into CGC or install CGC Python dependencies on host/Nextcloud.

---

## 8. Architecture and frontend

Preserve DDD boundaries:

- **Domain:** entities/value objects/invariants; no Nextcloud-service dependencies.
- **Application:** use cases and workflow orchestration.
- **Infrastructure:** persistence, Nextcloud services, external integrations.
- **Presentation:** OCS controllers and Vue UI.

- For frontend redesign work, respect the approved scope, design decisions, and acceptance gates in `docs/frontend-redesign/` when those documents exist in the current Git state.

- Do not expand business logic, API, or persistence scope without explicit approval.

Do not move business rules into controllers or views.

Frontend:

- approved UI library: `@nextcloud/vue` **9.x**;
- **Vuetify is not approved**: do not install/configure it or introduce `v-*` components to make tests pass;
- treat existing Vuetify-style drift as an issue to resolve through the approved workflow, not as precedent;
- preserve approved financial rules, roles, permissions, APIs, and persistence unless scope explicitly changes;
- for approved UI work, verify relevant FA/EN, RTL/LTR, light/dark, responsiveness, accessibility, and loading/error/empty states.

---

## 9. Verification

During implementation prefer affected-area checks: targeted `scripts/dev/phpunit <test>`, targeted Vitest, scoped lint, and typecheck when type relationships change. Do not run broad suites for reassurance or use failing tests as API discovery.

At feature/phase/milestone boundaries, broader gates may include:

```bash
npm run check
scripts/dev/phpunit
```

Run E2E/compatibility only when required by scope, acceptance criteria, or an integration boundary. Do not duplicate unchanged checks already covered by `npm run check`; prefer explicit affected tests over broad regex filters. A passing test from the wrong runtime is not evidence, and an unexecuted test must never be reported as passed.

---

## 10. Git safety

Before significant changes inspect `git status` and relevant `git diff`. Never discard unrelated changes, reset/clean unrelated state, stage unrelated files, use broad `git add .`/`git add -A` in a dirty tree, or push automatically.

---

## 11. Reports and ADRs

Use `docs/reports/` for significant durable work reports when the workflow requires them. Put durable architectural decisions under `docs/decisions/` as ADRs. Do not create ADRs for routine bug fixes, ordinary refactors, temporary implementation details, or agent workflow rules; do not create process-heavy reports for trivial work.

---

## 12. Completion-report contract

For non-trivial work report only evidence actually obtained: scope completed/investigated, concrete files/symbols/contracts, tests/checks actually executed and results, unresolved gaps, and the actual code-intelligence path.

Use a concise evidence line such as:

```text
Code intelligence: OCI=<used | unavailable | not needed>; CGC=<used | unavailable | not needed>; Serena=<used | unavailable | not needed>; source=<verified paths>; tests=<executed checks | not run>.
```

Distinguish **not needed** from **unavailable/failed**. If an applicable healthy tool was skipped, give a task-relevant reason; “source was easier” is not sufficient for a structural/symbol question covered by the mandatory gate. Never claim unexecuted tools, tests, runtime probes, or completed tasks.

**Final principle:** discovery is not proof; text occurrence is not a semantic reference; stale-cache output is not evidence.

---

## Official references

- OpenCode instructions: `https://opencode.ai/v2/docs/instructions`
- OpenCode rules: `https://docs.opencode.ai/docs/rules/`
- OCI: `https://github.com/Helweg/open-codebase-index`
- CodeGraphContext MCP: `https://codegraphcontext.github.io/reference/mcp/`
- Serena tools: `https://oraios.github.io/serena/01-about/035_tools.html`
- Context7 MCP: `https://context7.com/docs/resources/all-clients`
- Spec Kit: `https://github.github.com/spec-kit/quickstart`
