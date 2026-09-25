---
description: Load PettyCash project development context before coding tasks
agent: build
---

# PettyCash Development Context

Use this skill as a lightweight project-context bootstrap. It does not replace
`AGENTS.md` or Spec Kit artifacts.

## Workflow

PettyCash follows:

1. Convergence first
   - reconcile repository reality with Spec Kit intent
   - use codebase-memory for repository-wide understanding when available
   - avoid duplicate tasks when existing incomplete tasks already cover a gap

2. Implementation second
   - execute confirmed work
   - focus on the active task
   - avoid repeating repository-wide discovery

## Required Context

Before implementation, read:

- `AGENTS.md`
- active `spec.md`
- `plan.md`
- `tasks.md`

## Execution Boundary

Use `AGENTS.md` as the authority for runtime/tool execution.

- Frontend / Node.js tooling runs on the Ubuntu host.
- PHP / Nextcloud tooling runs inside `docker-nextcloud-1`.
- Docker infrastructure is external to the PettyCash repository.

Prefer targeted investigation during implementation.
Do not rediscover already-known architecture without a task-specific reason.
