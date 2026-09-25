---
description: Create a lightweight Architecture Decision Record (ADR)
agent: build
---

Create a lightweight Architecture Decision Record for:

$ARGUMENTS

Purpose:

Record an important architectural decision and its reasoning.

Rules:

- Do not modify source code.
- Do not modify:
  - spec.md
  - plan.md
  - tasks.md
- Do not create reports.
- Keep the ADR concise (preferably under one page).
- Do not create ADRs for:
  - bug fixes
  - simple refactoring
  - task completion
  - temporary implementation details
  - agent instructions
  - development environment setup
  - Docker runtime location
  - execution workflow rules

  Exception: one of the items above may justify an ADR only when it represents a durable
  system architecture decision rather than an operational or agent-workflow rule.

ADR content must focus only on:
- the architectural decision
- why it was needed
- important trade-offs

Do not include:
- project governance rules
- agent workflows
- CI/CD policies
- process documentation
- full task definitions
- file inventories
- implementation plans


Before writing:

Read only when relevant:

- AGENTS.md
- existing ADRs in docs/decisions/
- related Spec Kit artifacts


Create the ADR under:

docs/decisions/


Filename:

ADR-NNN-short-title.md


Use this structure:

# ADR-NNN: Title

## Status

Accepted

## Date

YYYY-MM-DD


## Context

Explain:
- the architectural problem or decision point
- relevant constraints


## Decision

Explain:
- what was chosen
- why this approach was selected


## Alternatives Considered

List only meaningful alternatives.

For each:
- describe briefly
- explain why it was not selected


## Consequences

Positive:
- benefits

Negative:
- trade-offs


## Implementation Impact

Only include if relevant.

Keep this section brief.
Prefer architectural rules over implementation checklists.


## Related References

Include only relevant:
- Spec Kit feature
- task IDs
- related ADRs


After creating:

Report only:
- created file path
- short decision summary