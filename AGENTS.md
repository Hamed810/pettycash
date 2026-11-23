# Pettycash — Repository Engineering Rules

Repository-wide engineering rules for all AI coding agents, including
read-only investigation. Follow the user’s authorized scope. These rules
govern evidence selection, execution boundaries, safety, and
verification; they do not replace the project constitution, approved
Spec Kit artifacts, API contracts, or recorded product decisions.

This document contains shared repository engineering rules. Management
responsibilities and delegation policy are maintained in the project
management documents.

## 1. Authority and task routing

### 1.1 Sources of truth

Use the following distinctions:

- **Intended behavior:** `.specify/memory/constitution.md`, the active
  feature `spec.md`, `plan.md`, `tasks.md`, and approved contracts,
  interpreted together with recorded owner decisions.
- **Implemented behavior:** the current source code and configuration.
- **Observed behavior:** results from correctly executed tests and
  runtime checks.

If these sources disagree, report the conflict and identify the exact
artifacts. Never silently redefine requirements from implementation,
infer approval from progress, or treat an old status snapshot as
current.

### 1.2 Classify work before selecting a workflow

- **New capability or functional change:** specify → plan → tasks →
  bounded implementation → converge. Use clarification, checklist, or
  analysis gates when relevant.
- **Existing bug or broken behavior:** bug assessment → bug fix → bug
  test. A bug is complete only when its terminal status is `verified`.
  Do not create a new feature specification unless intended behavior
  changes.
- **Already planned and authorized work:** implement only the existing
  authorized tasks. Use bounded batches for large task ledgers.
- **Requirement or scope change:** update the specification and
  dependent artifacts before implementation.
- **Investigation:** read-only; do not implement.
- **Quality review:** use only the relevant checklist, analysis, or
  convergence activity.
- **Human-only decision or external blocker:** identify it and stop at
  the decision boundary. Do not silently substitute scope or claim
  completion.

The workflow stages above are conceptual and client-neutral. Command
names, syntax, and command discovery are integration-specific and must
not be hardcoded in this shared document.

Unimplemented Spec Kit features are not automatically in scope for
frontend redesign or other bounded work.

### 1.3 Authorization boundaries and settled facts

- Classify each capability as **authorized now**, **pending separate
  authorization**, or **out of scope**. Do not infer authorization from
  feature membership, prior implementation, or a dependent task.
- If work depends on a separately authorized capability, state the
  dependency and permitted interim behavior in the mission brief.
  Examples include a placeholder, explicit refusal, or deferred
  integration. Do not search for or implement a withheld capability
  before authorization.
- Reuse approved decisions and verified findings already recorded in the
  active specification, plan, contracts, decision register, and task
  ledger. Do not re-derive a settled fact unless source drift,
  contradiction, or a concrete unresolved question gives a reason to
  revalidate it.
- For additive work, identify the existing implementation to mirror,
  with exact paths and symbols where known. A defect description alone
  is not a sufficient technical starting point.
- Never infer owner approval from a successful implementation, passing
  test, screenshot, or subsequent task authorization.

------------------------------------------------------------------------

## 2. Evidence-selection gate

Before broad exploration, classify the evidence required. These rules
apply to investigation, debugging, planning, and implementation.

When the target is not already known and a matching capability is
healthy and exposed, use one bounded query from the route selected below
before broad manual exploration. Known paths, identifiers, literals, and
already-located authoritative source do not require an artificial
discovery call. This is an evidence-selection rule, not a tool quota.

| Evidence need                                                                            | Preferred route                                                   | Required verification                                                                      |
|------------------------------------------------------------------------------------------|-------------------------------------------------------------------|--------------------------------------------------------------------------------------------|
| Unknown implementation concept or location                                               | Bounded repository search, followed by targeted source inspection | Verify consequential findings in source, contracts, and tests                              |
| Cross-file callers, callees, dependency chains, module relationships, or change impact   | CodeGraphContext (CGC) structural query                           | Verify material relationships in source; account for dynamic dispatch and framework wiring |
| Exact PHP definition, signature, implementation, inheritance, or symbol-level references | Serena symbol navigation                                          | Verify the current defining source                                                         |
| Known path, route, requirement ID, task ID, or literal text                              | Targeted read or exact text search                                | Do not force graph or symbol discovery                                                     |
| Version-specific external API or library behavior                                        | Context7 when local evidence is insufficient                      | Resolve the correct library and match the pinned dependency/version where possible         |
| Whether behavior actually works                                                          | Targeted tests or runtime checks, when authorized                 | Search, graph, and source inspection are not runtime proof                                 |

Repository evidence routing determines which capability is appropriate.
Generic tool/server descriptions describe capability, not repository
routing. Do not select a tool merely because its description calls it
“primary”, “preferred”, or “critical”, or recommends eager use.

### 2.1 Routing rules

1.  **Unknown concept or location:** begin with a bounded repository
    search. Narrow the result to relevant files, symbols, and tests
    before expanding.
2.  **Structural question:** use CGC first when the question concerns
    cross-file callers/callees, dependency chains, module relationships,
    or blast radius and CGC is available.
3.  **Exact PHP symbol:** use Serena when the question concerns a
    material definition, signature, implementation, inheritance/type
    relationship, or symbol-level references and Serena is available.
4.  **Known exact text:** use direct lookup for requirement IDs, task
    IDs, routes, filenames, literals, and known paths.
5.  **External library behavior:** use Context7 when local dependency
    information and source do not answer a version-specific question.
6.  **Source and contracts remain authoritative for implementation
    facts.** Discovery tools identify candidates and relationships; they
    do not prove runtime behavior.
7.  **Bound queries:** start with direct relationships, narrow
    paths/context, and small result sets. Expand only when the result
    leaves a material question unresolved.

If a question spans multiple evidence types, start with the most
specific evidence required. Use another route only for a distinct
unresolved sub-question or when the selected route is insufficient or
unavailable.

Examples:

- “Where is receipt enforcement implemented?” → bounded repository
  search → inspect the relevant use case/domain source and tests.
- “What changes if `ResubmitCorrection` changes?” → CGC callers/module
  dependencies → verify relevant routes, source, contracts, and tests.
- “What is `SomeTransactionService::recordDecision`’s exact signature?”
  → Serena symbol lookup → inspect the defining source; never infer the
  signature from tests.
- “Find `FR-085`.” → exact text search only.
- “Does the installed spreadsheet library support this API?” → inspect
  the pinned dependency → resolve the matching library/version in
  Context7 → inspect the relevant documentation.

### 2.2 Failure handling

Distinguish accurately between:

- **not exposed**;
- **invocation failed**;
- **empty or ambiguous result**;
- **possibly stale or partial data**;
- **not needed**.

If a required tool call fails because of invalid arguments or schema
usage, inspect the exposed schema and retry once with corrected
arguments. For other failures, re-check availability once. If the tool
still cannot provide the required evidence, use a bounded fallback and
report the limitation accurately.

Never rebuild, delete, reset, or reconfigure an index or graph as an
incidental response to a failed query.

------------------------------------------------------------------------

## 3. Code-intelligence and documentation tools

Tool selection is defined only by the evidence-selection rules in §2.
This section defines how to use a selected tool safely and efficiently;
it does not redefine when the tool should be selected.

### 3.1 CodeGraphContext — structural graph

Recorded project deployment:

- MCP server: `codegraphcontext`
- Image: `pettycash-cgc:current`
- Repository path inside CGC: `/workspace`
- Persistent configuration/graph path in the container:
  `/home/cgc/.codegraphcontext`
- Host persistence path: `/home/ubuntu/projects/pettycash/.cgc-data`
- Backend: embedded FalkorDB Lite unless the active configuration proves
  otherwise.

Treat these values as recorded deployment context, not permission to
change the deployment. Verify the live configuration before any
authorized maintenance.

When §2 selects CGC, use the existing graph and prefer bounded
`find_code` or `analyze_code_relationships` queries for the required
structural evidence. Relevant relationship types may include direct
callers/callees, importers, `class_hierarchy`, `call_chain`, or
`module_deps`, depending on the tools actually exposed by the active
server.

Use repository statistics and graph listing only as readiness checks,
not as substitutes for the requested structural query. Resolve ambiguous
symbols by file, class, and namespace.

Missing graph edges do not prove absence. Verify dynamic dispatch,
dependency injection, framework wiring, generated registrations, and
cross-language paths in source.

Prefer high-level tools over raw Cypher. If Cypher is necessary, keep it
read-only.

Never perform ordinary-investigation writes, including:

- repository deletion;
- package ingestion;
- bundle loading;
- schema mutation;
- watcher startup;
- graph reset;
- creation of another writer/container against the same embedded store.

“Already indexed” is not a freshness guarantee. Use only the verified
incremental update path when freshness materially affects the decision
and the maintenance operation is authorized.

### 3.2 Serena — exact symbols

When §2 selects Serena, use the actual symbol operations required for
the exact PHP definition, signature, implementation, inheritance/type
relationship, or symbol-level references.

Direct source reading verifies the result; it does not replace a
required symbol query.

Use the actual tool surface exposed by the active Serena configuration.
Effective names and schemas may vary by backend or configuration.
Initialize as required by the integration (for example,
`initial_instructions` once when applicable); initialization does not
count as symbol inspection.

Use symbol-level operations for definitions, references,
implementations, inheritance/type relationships, and diagnostics. Before
modifying PHP, inspect the actual defining symbol/signature plus
material callers, references, or implementations.

Never discover PHP APIs by repeatedly failing tests. Do not assume that
a symbol example from an old report still exists; verify it against the
current checkout.

### 3.3 Context7 — external documentation

When §2 selects Context7, resolve the library ID first, then query
documentation, unless the exact Context7 ID is already known.

Keep each query focused on one concept and prefer the dependency version
pinned by the project. Do not assume that the latest online
documentation matches the installed version.

If Context7 is unavailable, use pinned local dependency information and
official documentation when permitted; state any remaining uncertainty.
Do not call Context7 merely to satisfy a checklist.

------------------------------------------------------------------------

## 4. Index and tooling-state safety

- Reuse the existing CGC graph and Serena project state.
- Graph/index maintenance is never an ordinary investigation step.
- Do not start watchers, index/reindex repositories,
  rebuild/reset/delete graph or index state, change backends, install
  indexing dependencies, or introduce another writer unless the user
  explicitly authorizes that maintenance operation.
- A connected MCP server does not prove graph freshness or completeness.
- Keep project integration/configuration directories such as
  `.opencode/` and `.kilo/`, graph and symbol state such as `.cgc-data/`
  and `.serena/`, tool state such as `.tools/`, dependencies, generated
  assets, and sensitive environment files out of graph/index scope where
  the active configuration supports exclusions.
- Coordinate any authorized CGC maintenance with the running MCP server.
  Avoid concurrent writers.
- Do not delete tool state merely because a tool is temporarily
  unavailable or returns an empty result.

------------------------------------------------------------------------

## 5. Execution boundaries

### 5.1 Project identity and runtime

- Nextcloud app/version: `pettycash`, Nextcloud `34`
- Repository: `/home/ubuntu/projects/pettycash`
- Runtime mount: `/var/www/html/custom_apps/pettycash`
- Lab root: `/home/ubuntu/projects/nextcloud-lab`
- Lab command:
  `/home/ubuntu/projects/nextcloud-lab/bin/lab pettycash <command>`
- Runtime containers: `pettycash-lab-nextcloud-1`, `pettycash-lab-db-1`
- Lab URL: `http://localhost:8081`

Pettycash and PTS are separate labs. `pts-lab-*` resources and port
`8082` belong to the sibling project.

### 5.2 Host development

Run the active coding-agent client, Spec Kit, Git, Node/npm, Vite,
Vitest, TypeScript/`vue-tsc`, ESLint, Stylelint, and Playwright on the
Ubuntu host. Prefer existing scripts:

``` bash
npm test
npm run typecheck
npm run lint
npm run stylelint
npm run build
npm run test:e2e
npm run check
```

`npm run check` covers frontend gates only; it does not run PHP tests.

Use the installed project tools and their documented client-specific
configuration. Do not assume a command is available on `PATH`; verify
the executable path when necessary.

### 5.3 PHP / Nextcloud runtime

Never run host PHP/Composer/PHPUnit/OCC, including version probes:

``` bash
php ...
composer ...
phpunit ...
./vendor/bin/*
vendor/bin/*
occ ...
```

Use wrappers only:

``` bash
scripts/dev/phpunit <test>
scripts/dev/phpunit --list-tests
scripts/dev/occ <args>
scripts/dev/composer <args>
scripts/dev/in-container <cmd>
scripts/dev/opcache-settle
scripts/dev/route-settle
scripts/dev/route-cache-clear
```

If a required wrapper is missing or non-executable, stop that operation
and report it. Do not fall back to host PHP or ad-hoc `docker exec` when
a wrapper exists.

### 5.4 Composer boundary

Use only `scripts/dev/composer <args>`. Do not install Composer into the
shared runtime, change Composer-managed ownership to `www-data` or root,
or bypass required platform extensions merely to force dependency
resolution.

------------------------------------------------------------------------

## 6. Runtime caches

A stale-cache result is not evidence.

- After `lib/` or `appinfo/` PHP edits, `scripts/dev/phpunit` handles
  its own opcode settling. Before direct live-HTTP checks, run
  `scripts/dev/opcache-settle`.
- If opcode settling reports a wait or failure, affected HTTP/contract
  evidence is invalid until resolved.
- After `appinfo/routes.php` changes, use:

``` bash
scripts/dev/route-settle
scripts/dev/route-cache-clear
```

Opcode settling does not replace route-cache handling.

Do not claim runtime behavior from a response served by stale PHP
opcodes or stale route metadata.

------------------------------------------------------------------------

## 7. Docker safety

The Docker host is shared; this repository does not own the global
Docker lifecycle.

Pettycash runtime resources include:

- `pettycash-lab-nextcloud-1`
- `pettycash-lab-db-1`
- `pettycash-lab-volume-init`
- `pettycash-lab_default`
- `pettycash-lab_nc-data`
- `pettycash-lab_nc-db`

Never:

- delete volumes;
- run `docker compose down -v`;
- replace the lab because it is stopped;
- recreate unrelated containers;
- change the Compose project name;
- modify lab Docker configuration without approval;
- stop, start, or reconfigure PTS resources;
- inspect or manipulate PTS volumes except through an explicitly
  approved read-only isolation check.

For recovery, inspect first and use:

``` bash
/home/ubuntu/projects/nextcloud-lab/bin/lab pettycash up
```

Do not run Docker Compose from the Pettycash repository.

CGC is a code-intelligence tool, not the application runtime. Do not
move PHP/Composer work into CGC or install CGC Python dependencies on
the host or Nextcloud runtime.

------------------------------------------------------------------------

## 8. Architecture and frontend constraints

### 8.1 Backend architecture

Preserve the established domain-driven design boundaries:

- **Domain:** entities, value objects, and invariants; no
  Nextcloud-service dependencies.
- **Application:** use cases and workflow orchestration.
- **Infrastructure:** persistence, Nextcloud services, and external
  integrations.
- **Presentation:** OCS controllers and Vue UI.

Do not move business rules into controllers or views.

Preserve the Nextcloud application boundary, application identity
`pettycash`, and PHP namespace `OCA\PettyCash`.

Nextcloud facilities remain authoritative for authentication, user
identity, file access, platform security controls, and technical
logging. Do not introduce a parallel authentication system, unmanaged
document storage, or an independent technical-log subsystem.

Business rules, authorization, ownership, workflow transitions, required
evidence, and financial effects must be enforced at trusted backend
boundaries regardless of client behavior. Client-side checks are
usability aids and must not be the only enforcement.

Sensitive multi-record operations must be atomic. Preserve transaction
revisions, historical decisions, and append-only business audit records.
Do not rewrite or delete historical financial evidence.

Do not expand business logic, API, persistence, schema, or authorization
scope without explicit approval and the required specification/plan.

### 8.2 Frontend constraints

- The approved UI library is `@nextcloud/vue` **9.x**.
- **Vuetify is not approved.** Do not install/configure it or introduce
  `v-*` components to make tests pass.
- Treat existing Vuetify-style drift as an issue to resolve through the
  approved workflow, not as precedent.
- Preserve approved financial rules, roles, permissions, APIs, and
  persistence unless the authorized scope explicitly changes them.
- For approved UI work, verify relevant Persian/English localization,
  RTL/LTR, light/dark themes, responsiveness, accessibility, and
  loading/error/empty states.
- Include responsive, localization, RTL/LTR, keyboard, focus, and
  accessibility checks in each user story or implementation batch’s
  definition of done. Do not defer all UI quality checks to a final
  polish phase.
- Before promising visual acceptance, confirm that the active session
  can inspect the required screenshots or browser state. If visual
  comparison is unavailable, record the exact evidence gap and route it
  to the owner-controlled visual gate defined in the feature contract
  and approved frontend-redesign acceptance process.
- Never claim or simulate pixel-level verification that was not
  performed.

### 8.3 Frontend redesign references

For frontend redesign work, respect the approved scope, design
decisions, and acceptance gates in `docs/frontend-redesign/` when those
documents exist in the current Git state. Confirm that the referenced
proposal is approved and applicable before treating it as an
implementation requirement.

------------------------------------------------------------------------

## 9. Verification

During implementation, prefer affected-area checks:

- targeted `scripts/dev/phpunit <test>`;
- targeted Vitest;
- scoped lint;
- typecheck when type relationships change;
- focused browser checks when required by acceptance criteria.

Do not run broad suites for reassurance or use failing tests as API
discovery.

At feature, phase, or milestone boundaries, broader gates may include:

``` bash
npm run check
scripts/dev/phpunit
```

Run E2E/compatibility checks only when required by scope, acceptance
criteria, or an integration boundary. Do not duplicate unchanged checks
already covered by `npm run check`; prefer explicit affected tests over
broad regex filters.

A passing test from the wrong runtime is not evidence. An unexecuted
test must never be reported as passed.

### 9.1 Browser verification

All Playwright browser tests used as required evidence must run in
official Google Chrome using the `chrome` channel:

``` ts
{ browserName: 'chromium', channel: 'chrome' }
```

Bundled Chromium, Firefox, WebKit, and Edge must not be used for
required evidence. No silent browser fallback is permitted. Desktop and
mobile emulation must both use Google Chrome.

If Google Chrome is unavailable, stop browser verification and report
the blocker; do not substitute another engine.

### 9.2 Verification sequencing

For multi-story or multi-phase work, run focused checks at the smallest
useful boundary (component, story, or backend batch), then run the
agreed milestone gates.

Avoid both extremes:

- do not postpone all verification until the end;
- do not rerun unchanged broad suites after every small task.

Record which checks were actually executed and which remain deferred.

### 9.3 Visual evidence

- Store generated review screenshots under `artifacts/visual-review/`,
  organized by task and review iteration, for example
  `artifacts/visual-review/<task-id>/<iteration>/`.
- The canonical evidence location must be inside the repository. Do not
  use external temporary directories such as `/tmp` as canonical
  evidence storage.
- Preserve evidence until the owner review is recorded.
- Completion reports must cite exact repository-relative screenshot
  paths.
- The visual-evidence tree must be excluded from Git. Excluding
  generated evidence must not exclude the task ledger, specification, or
  report that cites it.
- Do not claim visual approval from an automated pass, an unreviewed
  screenshot, or silence.

------------------------------------------------------------------------

## 10. Git and shared-working-tree safety

The repository working tree may contain work from previous tasks or
sessions. Treat all pre-existing modifications and untracked files as
user-owned unless explicitly identified otherwise.

- Before significant changes, inspect `git status --short` and the
  relevant `git diff`.
- Only one coding-agent session may actively perform write operations in
  the shared working tree at a time. Do not assume another session’s
  changes are disposable or that its work is complete.
- Preserve all pre-existing changes, including generated outputs and
  untracked deliverables.
- Never discard unrelated changes, reset/clean unrelated state, stage
  unrelated files, use broad `git add .`/`git add -A` in a dirty tree,
  or push automatically.
- Do not stage, commit, push, reset, or clean unless the user explicitly
  authorizes the exact operation.
- Do not silently overwrite a pre-existing modified or untracked file.
  Inspect ownership and purpose first; if uncertain, stop and report.
- After a bounded task, report the actual final `git status --short` and
  distinguish changes made by the task from pre-existing dirty state.

------------------------------------------------------------------------

## 11. Reports and architecture decision records

- Use `docs/reports/` for significant durable work reports when the
  workflow requires them.
- Put durable architectural decisions under `docs/decisions/` as
  architecture decision records (ADRs).
- Do not create ADRs for routine bug fixes, ordinary refactors,
  temporary implementation details, or routine agent-workflow changes.
- Do not create process-heavy reports for trivial work.
- Record owner-approved material decisions in `DECISION_REGISTER.md`
  when required by project governance. An ADR does not replace the
  decision register or an approved specification.

------------------------------------------------------------------------

## 12. Completion-report contract

For non-trivial work, report only evidence actually obtained:

- scope completed or investigated;
- concrete files, symbols, contracts, and task IDs;
- tests and checks actually executed, with results;
- unresolved gaps and blockers;
- the actual code-intelligence path used.

Use a concise evidence line such as:

``` text
Code intelligence: CGC=<used | unavailable | not needed>; Serena=<used | unavailable | not needed>; Context7=<used | unavailable | not needed>; source=<verified paths>; tests=<executed checks | not run>.
```

Distinguish **not needed** from **unavailable/failed**. If an applicable
healthy tool was skipped, give a task-relevant reason. Never claim
unexecuted tools, tests, runtime probes, or completed tasks.

For long-running work, keep handoffs compact and state-based:

- completed items;
- current exact state;
- changed paths;
- tests actually run;
- unresolved blockers;
- next authorized step;
- stop condition.

A new session must start from that handoff and the authoritative project
artifacts. It must not replay the entire investigation or assume
unavailable conversation history.

**Final principle:** discovery is not proof; text occurrence is not a
semantic reference; stale-cache output is not evidence.

------------------------------------------------------------------------

## 13. Mission preparation and pre-edit gate

For each implementation batch that touches more than one file or crosses
a module/contract boundary, the mission brief or execution handoff must
provide the applicable items below:

- **Objective and exact task IDs:** intended outcome and bounded batch.
- **Starting state:** completed work, known root cause, current
  blockers, and relevant pre-existing dirty/untracked state when known.
- **Settled decisions and authorization:** approved product decisions,
  dependencies, and capabilities that are not yet authorized.
- **Technical map:** exact relevant paths, symbols, contracts, reusable
  components, and existing tests. Include line anchors only when already
  verified; do not invent them.
- **Write boundary:** complete list of files permitted to change,
  including generated files. If a complete list cannot be known safely
  in advance, define permitted directories and require the active
  session to report any proposed expansion before writing.
- **Acceptance and verification:** observable acceptance criteria and
  targeted commands/tests expected to prove them.
- **Stop condition:** exact point at which the session must stop and
  report.

Before the first edit, provide a short **plan of record** containing:

1.  files to change;
2.  implementation sequence and dependencies;
3.  tests/checks to run;
4.  known risks or unresolved feasibility questions.

For non-trivial changes, perform a bounded feasibility check before
implementation. Check the relevant data source, authorization boundary,
query boundedness/pagination, existing reusable behavior, and
compatibility with established architecture.

Do not implement a known-unbounded or knowingly unsafe workaround merely
to complete the batch. If a mandatory condition cannot be met within
scope, stop and report the blocker with evidence.

The plan of record is a checkpoint, not a request for repeated approval.
Continue within the user’s existing authorization unless a genuine scope
change, product decision, safety issue, or newly discovered blocker
requires owner input.

The write boundary limits writes; it does not prohibit necessary
read-only inspection of directly relevant dependencies. Preserve all
pre-existing changes. Never broaden the write boundary silently.

------------------------------------------------------------------------

## 14. Mission clarity, bounded context, and output quality

These rules apply to all coding agents and are especially useful in
tool-using, long-context sessions.

- Put the **mission and scope first**. Follow with the minimum verified
  context, technical map, acceptance criteria, and output format. Apply
  the authorization rules in §1 and the write-boundary/stop-condition
  rules in §13.
- Prefer direct, numbered instructions and explicit file/symbol names
  over long narrative explanations. Define technical terms once. When a
  restriction is already stated elsewhere, cite that section rather than
  restating it.
- Separate **facts**, **owner decisions**, **assumptions**, and **open
  questions**. Never turn an assumption into a requirement without
  approval.
- Use the smallest read or query that answers the current question. Do
  not print full-tree status, diffs, logs, or generated files when a
  scoped summary or targeted excerpt is sufficient.
- Keep tool output and reports bounded. Summarize large results and
  retain exact paths, identifiers, counts, and relevant evidence. Split
  a long report at predefined section boundaries rather than allowing
  one oversized response to be truncated.
- Do not expose private chain-of-thought or lengthy internal
  deliberation. Report the conclusion, concise rationale, evidence,
  uncertainty, and next action.
- Long context capacity is not permission for unbounded exploration or
  oversized output. Prefer a compact handoff over replaying a long
  conversation.
- If a task is blocked, report the blocker and the smallest decision or
  authorization needed. Do not fill the gap with unrelated investigation
  or speculative implementation.

Recommended handoff format:

``` text
Mission / task IDs:

Authorization and write boundary:

Completed:

Current state:

Files changed:

Checks actually run and results:

Open risks / blockers:

Next authorized step:

Stop condition:
```

------------------------------------------------------------------------

## 15. Official references

Use official documentation for the exact tool or framework version in
use. Resolve version differences rather than assuming that current
online documentation matches installed dependencies.

- CodeGraphContext MCP:
  https://codegraphcontext.github.io/reference/mcp/
- Serena tools: https://oraios.github.io/serena/01-about/035_tools.html
- Context7 MCP: https://context7.com/docs/resources/all-clients
- Spec Kit: https://github.github.com/spec-kit/quickstart
