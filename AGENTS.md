# PettyCash V1 - Agent Instructions

## Purpose

This file defines operational instructions for AI coding agents working on PettyCash V1.

It does not replace Spec Kit artifacts.

Source of truth:

1. `constitution.md`
2. `spec.md`
3. `plan.md`
4. `tasks.md`
5. approved contracts
6. verified implementation

---

# Project Context

PettyCash V1 is a Nextcloud 34 application.

Repository:

`/home/ubuntu/projects/pettycash`

## Runtime Environment

PettyCash uses two execution environments.

### Host development environment

- Ubuntu VM
- Repository: `/home/ubuntu/projects/pettycash`

The host is used for:

- OpenCode
- Spec Kit
- codebase-memory MCP
- Git
- Node.js
- npm / npx
- Vite
- Vitest
- TypeScript / `vue-tsc`
- ESLint
- Stylelint
- Playwright

### Application runtime

PettyCash runs inside an existing Nextcloud Docker infrastructure.

Nextcloud container:

`docker-nextcloud-1`

Nextcloud:

- Version: 34
- Image: `nextcloud:34-apache`

Canonical repository mapping:

- Host: `/home/ubuntu/projects/pettycash`
- Container: `/var/www/html/custom_apps/pettycash`

PHP runtime:

- PHP and Nextcloud tooling are container-only.
- The container PHP version is authoritative.
- PHP is not a valid host execution environment for this project.
- Do not probe the host for PHP or Composer.

---

# Command Execution (MANDATORY)

Before executing any command, classify it by runtime.

## Host commands

Frontend and Node.js tooling must run directly on the Ubuntu host from:

`/home/ubuntu/projects/pettycash`

Use the existing project scripts from `package.json` whenever possible.

Available frontend commands include:

```bash
npm test
npm run test:unit
npm run typecheck
npm run lint
npm run stylelint
npm run build
npm run test:e2e
npm run check
```

`npm run check` is the preferred full frontend quality gate when complete frontend verification is appropriate.

It runs:

- typecheck
- lint
- stylelint
- tests
- build

Do not move Node.js tooling into the Nextcloud container unless explicitly instructed.

## PHP / Nextcloud commands

PHP and Nextcloud runtime tooling must never be executed directly on the host.

Never run directly on the host:

- `php`
- `composer`
- `phpunit`
- `./vendor/bin/*`
- `vendor/bin/*`
- `occ`

Do not probe or detect PHP-related tooling on the host.

Forbidden examples:

```bash
php -v
which php
command -v php
whereis php
composer --version
which composer
./vendor/bin/phpunit
vendor/bin/phpunit
php occ
```

Use the repository development wrappers instead:

| Need | Command |
|---|---|
| Run all PHP tests | `scripts/dev/phpunit` |
| Run one PHP test file | `scripts/dev/phpunit tests/Unit/FooTest.php` |
| List PHP tests | `scripts/dev/phpunit --list-tests` |
| Settle Apache's opcode cache | `scripts/dev/opcache-settle` |
| Settle Nextcloud's route cache | `scripts/dev/route-settle` |
| Clear Nextcloud's route cache | `scripts/dev/route-cache-clear` |
| OCC | `scripts/dev/occ <args>` |
| Composer | `scripts/dev/composer <args>` |
| Arbitrary container command | `scripts/dev/in-container <cmd>` |

The wrappers are responsible for:

- executing in `docker-nextcloud-1`
- selecting the correct container working directory
- mapping the repository to `/var/www/html/custom_apps/pettycash`
- settling Apache's opcode cache before a test run (see below)

Before any shell command involving PHP or Nextcloud tooling, verify that it starts with:

`scripts/dev/`

Do not manually construct `docker exec` commands for normal PHP/Nextcloud work when a wrapper exists.

## Opcode Cache Settling (MANDATORY)

`scripts/dev/phpunit` settles the container's PHP opcode cache before it runs, and it
fails the run if the wait cannot be computed. Do not bypass it, and do not reach for
`php -r 'opcache_reset();'` as a substitute.

The reason is that a PHP verification result in this repository can be a lie in either
direction:

- The container has `opcache.enable => 1` and `opcache.revalidate_freq => 60`.
- PHPUnit runs in the CLI SAPI, where `opcache.enable_cli => 0`. Unit and integration
  tests always recompile, so they cannot be affected.
- The OCS contract tests issue **real HTTP requests**, so `lib/` is loaded by Apache's
  `mod_php` through that cache. A run started within 60 seconds of an edit to `lib/` or
  `appinfo/` can execute the *previous* bytecode.

This has already produced both a false PASS and a false FAIL here (T288), so neither a
green run nor a red one is evidence until the cache has settled. Measured for T288: with
a canary that threw unconditionally from `SheetPayloadFormatter::totals()`, a run inside
the window reported 13/13 passing; with correct code restored and run inside the window,
the same two tests failed. Both runs were wrong, and both looked healthy.

Rules:

- Use `scripts/dev/phpunit`. It settles first, and blocks for at most
  `opcache.revalidate_freq + 1` seconds after the most recent change under `lib/` or
  `appinfo/`. When nothing changed recently it returns immediately.
- `scripts/dev/in-container php vendor/bin/phpunit` is **not** a substitute. It skips the
  settle and skips `.env.testing`, so the contract tests cannot authenticate.
- If you run PHPUnit directly for any reason, settle first:
  `scripts/dev/opcache-settle && scripts/dev/in-container php vendor/bin/phpunit ...`
- `scripts/dev/opcache-settle --status` reports the remaining wait without blocking, and
  exits 3 while a wait is outstanding.
- If `scripts/dev/opcache-settle` exits non-zero, the run is blocked on purpose. Fix the
  reported cause. Do not re-run without it.
- Editing `tests/`, `docs/`, the frontend, or the scripts does not require settling; only
  `lib/` and `appinfo/` are loaded by the SAPI.

Do not "fix" a slow or surprising contract-test result by waiting a minute and re-running
to see whether it goes away, and do not conclude a fix did not work from a run taken
inside the window. Settle first, then measure.

## Route Cache Settling (MANDATORY)

Settling the opcode cache is **not sufficient** for an OCS contract result.

Nextcloud's `CachingRouter` does not re-read `appinfo/routes.php` per request. It
caches the whole serialized route collection — every enabled app, not only this one —
for 3600 seconds. So after a change to `appinfo/routes.php`, a request can be
dispatched by a route table that predates the edit, while the file on disk is
correct and `opcache-settle` reports `settled`.

This produced a real false result here (T300): renaming a route passed
`opcache-settle` and then answered `500` with
`ReflectionException: SessionController::dashboard() does not exist`, from a
`nextcloud.log` entry timestamped *after* the edit. A healthy application reported a
broken one.

Rules:

- `scripts/dev/route-settle` verifies it. `scripts/dev/phpunit` runs it
  automatically whenever the selected tests include the contract suite, and blocks
  the run with a non-zero exit when a stale collection could still be served.
- It **blocks** rather than waits. The opcache wait is 60s and provably
  sufficient; this TTL is 3600s, so blocking an hour is not acceptable. The fix is
  one command.
- `scripts/dev/route-cache-clear` performs the clear: it signals `SIGUSR2` to the
  Apache master (PID 1 in this image), waits for the workers to return, and records
  the moment. The record matters — without it `route-settle` could not distinguish
  "not yet cleared" from "already cleared" and would block for the rest of the hour
  after a successful clear.
- The container name is `docker-nextcloud-1`, per the pinned compose project.

`docker exec ... php -r 'apcu_clear_cache();'` does **not** work: it clears the CLI's
cache, not the `mod_php` workers'. `occ` cannot either — under CLI
`ICacheFactory::createLocal()` is array-backed, not APCu.

A contract result obtained while `route-settle` reports outstanding is not evidence,
in the same way and for the same reason a result inside the opcache window is not.

Do not guess container paths.

Do not use:

`/var/www/html/apps/pettycash`

The canonical container path is:

`/var/www/html/custom_apps/pettycash`

Container paths do not exist on the host. Never `cd` into a container path from the host shell.

If a required wrapper is missing or not executable:

- stop that operation
- report the missing wrapper
- do not fall back to host PHP
- do not invent a replacement runtime
- do not silently switch to a manually guessed Docker command

If a command reports `php: not found`:

- treat it as an execution-boundary violation
- do not investigate host PHP
- rerun the intended operation using the appropriate `scripts/dev/*` wrapper

## Mandatory Execution Rule

Route commands according to these fixed boundaries:

- Frontend / Node.js tooling -> Ubuntu host
- PHP / Nextcloud tooling -> `scripts/dev/*` wrappers

Do not infer execution location from generic framework conventions.

Do not first try a host command and then fall back to Docker.

Do not rediscover this execution boundary during normal implementation.

If a command fails because of a path or executable error, do not switch execution environments automatically. First verify the command against the canonical paths and wrappers defined here.

---

# Docker Infrastructure Ownership

The Docker infrastructure is NOT managed by the PettyCash repository, and it is not owned by
any single application repository. It is a **shared local development lab** used by both
PTS and PettyCash.

Lab location:

`/home/ubuntu/projects/nextcloud-lab/`

Compose file:

`/home/ubuntu/projects/nextcloud-lab/compose.yaml`

The PettyCash repository:

- contains application code only
- consumes the existing Nextcloud runtime
- does not own Docker lifecycle
- mounts at `/var/www/html/custom_apps/pettycash`; PTS mounts at `.../custom_apps/pts`

Never assume:

`/home/ubuntu/projects/pettycash/docker-compose.yml`

is the runtime source.

## Compose project name is pinned, and it is load-bearing

The lab's `compose.yaml` sets `name: docker`. That is not a typo and not a cosmetic choice.
It is the project name the environment has always had — it was derived from the compose
file previously living in a directory called `docker/` — and it is the prefix for every
container, network and volume:

- containers `docker-nextcloud-1`, `docker-db-1`, `docker-nextcloud-volume-init-1`
- network `docker_default`
- volumes `docker_pts-nextcloud` (Nextcloud install, config, all user data) and
  `docker_pts-db` (MariaDB data)

If the project name changes, Compose looks for differently-named volumes, does not find
them, and Docker creates **empty** ones — a blank Nextcloud with an empty database while
the real data sits unused. Renaming the project and its volumes is a deliberate migration
requiring its own backup and verification, never an incidental edit.

Container names therefore remain `docker-*` even though the lab directory is
`nextcloud-lab`. That is expected.

---

# Docker Safety

This project shares Docker infrastructure with other applications.

Never:

- delete Docker volumes
- run `docker compose down -v`
- remove Nextcloud volumes
- recreate unrelated containers
- modify shared Docker configuration without explicit instruction
- create a replacement Docker environment for PettyCash

Important volumes:

- `docker_pts-nextcloud`
- `docker_pts-db`

Do not install host PHP packages only to emulate the container runtime.

---

# Docker Runtime Recovery

If `docker-nextcloud-1` is not running:

1. Check existing containers:

```bash
docker ps -a
```

2. Do not create a new Docker environment.

3. Do not initialize a new Compose project.

4. Do not run Docker Compose from the PettyCash repository.

5. Use the existing shared Docker lab at:

`/home/ubuntu/projects/nextcloud-lab/`

6. Use the existing Compose file:

`/home/ubuntu/projects/nextcloud-lab/compose.yaml`

7. Do not recreate containers or volumes unless explicitly instructed.

8. Do not run `docker compose down -v` or any command that removes a named volume. Plain
`docker compose down` stops the lab and keeps all data; `-v` deletes the Nextcloud
installation and the database.

9. After recovery, continue using `scripts/dev/*` wrappers for PHP/Nextcloud commands.

---

# Legacy Repository

Legacy repository:

`/home/ubuntu/projects/pettycash_legacy_backup`

Rules:

- Reference only.
- Use for historical investigation when needed.
- Never use legacy code as the V1 architecture source.
- Do not copy legacy implementation decisions without Spec Kit approval.

---

# Spec Kit Workflow

Development is controlled by Spec Kit.

Before implementing, read:

- active `spec.md`
- `plan.md`
- `tasks.md`

Rules:

- Work from the first genuinely incomplete task.
- Do not assume task numbers always represent execution order.
- Respect dependencies and parallel markers.
- Do not redo completed tasks.
- Do not modify completed task history without evidence.
- Convergence is responsible for broad repository understanding.
- Implementation should remain focused on the active task.

Task completion requires:

- implementation or test work completed
- acceptance criteria verified
- relevant tests executed

---

# Convergence and Repository Understanding

Convergence is the primary phase for repository-wide understanding.

During convergence:

- use codebase-memory when available
- inspect architecture relationships
- inspect component relationships
- inspect dependency relationships
- identify existing implementation
- identify task-state mismatches
- identify missing or partial work

Prefer:

- codebase-memory
- LSP / symbol navigation
- targeted file inspection

Avoid unnecessary repeated full-repository scans.

Normal implementation should not repeat repository-wide analysis already performed during convergence.

---

# Implementation Workflow

Implementation should focus on the active task.

Before modifying code, confirm:

- `AGENTS.md` rules are loaded
- relevant specification requirements are known
- `plan.md` constraints are understood
- the active task in `tasks.md` is identified
- the correct execution environment is known

During implementation:

- do not scan the entire repository without a specific reason
- do not rediscover known project architecture
- use targeted investigation only
- use codebase-memory when relevant relationships or existing patterns are unclear
- prefer LSP over grep for PHP symbol resolution

For command execution:

- frontend / Node.js commands run directly on the host
- PHP / Nextcloud commands use `scripts/dev/*`
- never probe host PHP first
- never guess a container path
- never manually switch execution environments as a fallback

---

# Task State Awareness

`tasks.md` represents planned work, but checkbox state may not always match repository reality.

Rules:

- Do not assume an unchecked task is missing.
- Do not perform broad repository audits during normal implementation.
- Verify only relevant areas when:
  - a dependency appears missing
  - tests contradict task state
  - implementation may already exist

If existing implementation is found:

- do not rewrite it
- report the mismatch
- continue safely when possible

---

# Repository Investigation

## codebase-memory

Primary use:

- convergence
- architecture understanding
- dependency discovery
- existing pattern discovery

During implementation, use it only when needed for the active task.

Avoid:

- repeated full repository exploration
- unnecessary rediscovery

## PHP LSP / Intelephense

Use for:

- class definitions
- references
- namespaces
- method signatures
- diagnostics

Prefer LSP over grep when resolving PHP symbols.

LSP runs on the host for code intelligence only. It does not make host PHP a valid application runtime.

---

# Test Execution Rules

Use the correct environment for each test type.

## Frontend tests and verification

Run on the Ubuntu host from:

`/home/ubuntu/projects/pettycash`

Available project scripts:

```bash
npm test
npm run test:unit
npm run typecheck
npm run lint
npm run stylelint
npm run build
npm run test:e2e
npm run check
```

Use `npm run check` as the preferred full frontend verification command when appropriate.

Playwright runs on the host against the configured application runtime.

The browser reaches the app over HTTP, so an e2e run executes `lib/` through Apache's
opcode cache and is subject to the same staleness as the contract tests. `npm run test:e2e`
therefore settles the cache first via `pretest:e2e`; see **Opcode Cache Settling** above.

Do not run frontend Node.js tooling inside the Nextcloud container unless explicitly instructed.

## PHP tests and verification

Use only the repository wrappers.

Examples:

```bash
scripts/dev/phpunit
scripts/dev/phpunit tests/Unit/FooTest.php
scripts/dev/phpunit --list-tests
```

A PHP test run is only evidence if Apache's opcode cache has settled first.
`scripts/dev/phpunit` does that for you; see **Opcode Cache Settling** above before
interpreting any contract-test result.

Do not run:

```bash
php ...
./vendor/bin/phpunit
vendor/bin/phpunit
composer ...
occ ...
```

directly on the Ubuntu host.

Do not manually substitute an ad-hoc `docker exec` command when the wrapper exists.

## RED-State Rules

For RED-state tasks, a valid RED state requires:

- tests execute
- assertions execute
- failures represent missing behavior
- skipped = 0
- incomplete = 0

Invalid RED states:

- skipped tests
- incomplete tests
- placeholder assertions
- tests asserting current broken behavior
- expecting 404 only because an endpoint does not exist
- collection/configuration/runtime failures unrelated to the intended missing behavior
- failures caused by using the wrong execution environment or wrong container path

Tests must assert:

- specification behavior
- approved contract behavior
- task acceptance criteria

Do not implement future tasks only to make current RED tests pass.

---

# Frontend Testing Rules

Temporary frontend mocks may exist during RED test phases.

Rules:

- Mock components must not contain business behavior.
- Temporary mocks must not hide the final implementation.
- When real components are introduced, test wiring must be reviewed.
- Remove or redirect temporary aliases when real components become the intended test target.

---

# Architecture Rules

Preserve the existing architecture.

## Domain

Contains:

- business rules
- entities
- value objects
- invariants

Must not depend on Nextcloud services.

## Application

Contains:

- use cases
- orchestration
- workflows

## Infrastructure

Contains:

- persistence
- Nextcloud services
- external integrations

## Presentation

Contains:

- OCS API
- Vue frontend

Do not move business logic into controllers or UI components.

---

# Git Safety

Before significant changes:

```bash
git status
git diff
```

Never:

- discard unrelated changes
- reset repository state
- push automatically

---

# Task Completion Reports

For significant tasks create:

`docs/reports/TXXX.md`

Include:

- Task ID
- Status
- Files changed
- Tests executed
- Test results
- Verification details
- Known limitations
- Next incomplete task

---

# Architecture Decisions

Architecture decisions should be recorded as ADRs.

Location:

`docs/decisions/`

Create ADRs only for decisions that affect future architecture.

Examples:

- choosing a new architectural boundary
- introducing a major service pattern
- changing persistence strategy
- changing integration approach

Do not create ADRs for:

- bug fixes
- simple refactoring
- normal task completion
- temporary implementation details
- agent workflow rules
- routine development environment instructions

## Frontend UI Framework

The approved UI component library is:

`@nextcloud/vue` 9.x

Vuetify is NOT part of the approved frontend stack.

Do not:

- install Vuetify
- introduce `v-*` Vuetify components
- configure Vuetify only to make tests pass
- treat unresolved `v-*` components as a test-environment problem

If existing source code contains Vuetify-style components, treat this as
implementation drift against the approved architecture and resolve it through
Spec Kit convergence/implementation.