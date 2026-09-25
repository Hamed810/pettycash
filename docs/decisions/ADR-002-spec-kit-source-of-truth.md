# ADR-002: Spec Kit as Source of Truth

## Status

Accepted

## Date

2026-09-23


## Context

PettyCash V1 uses Spec Kit to manage requirements, architecture planning,
and implementation tasks.

During development, multiple sources of information may exist:
- chat history
- repository code
- task definitions
- design documents

Without a defined authority, implementation intent can drift from the
planned design.


## Decision

Spec Kit artifacts are the source of truth for planned work:

- spec.md defines requirements
- plan.md defines architectural direction
- tasks.md defines implementation tasks and acceptance criteria

Implementation status is verified through:

- repository state
- tests
- runtime validation

Chat history and temporary agent context are not considered project
sources of truth.


## Alternatives Considered

### Chat history as source of truth

Rejected:
- temporary
- not version controlled
- difficult for future agents to consume


### Code only as source of truth

Rejected:
- code shows implementation, not always original intent or requirements


## Consequences

Positive:

- clearer traceability from requirement to implementation
- easier continuation by different agents or developers

Negative:

- Spec Kit artifacts require maintenance
- implementation changes may require updating documentation


## Related References

- PettyCash V1 Spec Kit feature
- AGENTS.md