---
description: Autonomously orchestrate PettyCash Spec Kit implementation and periodic convergence with durable resume, unattended execution, and no initial convergence.
agent: build
---

# PettyCash Autopilot

Coordinate the existing PettyCash Spec Kit workflows without replacing or duplicating them.

Canonical sub-workflows:

- `.agents/skills/speckit.implement.md`
- `.agents/skills/speckit.converge.md`

Operational authority:

- `AGENTS.md`

Feature intent and governing constraints come from the active Spec Kit artifacts and constitution,
resolved by the canonical sub-workflows.

Autopilot is a durable, implementation-first controller around those workflows.

It MUST be possible to resume Autopilot by invoking this command again with no explanatory prompt.

---

## User Experience

Normal use:

```text
/pettycash.autopilot