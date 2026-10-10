# ADR-004: Contract-First API Development with RED Tests Before Implementation

## Status
Accepted

## Date
2026-09-23

## Context
PettyCash V1 exposes an OCS API for the Vue frontend and external integrations. The API contract must be stable, versioned, and verifiable before implementation begins. Without a contract-first approach, the frontend and backend drift, integration tests fail late, and breaking changes are discovered in production.

Constraints:
- Nextcloud OCS API conventions must be followed
- Vue frontend consumes the API concurrently with backend development
- Docker-based runtime requires container-verified tests
- Spec Kit workflow mandates RED tests before implementation (speckit-implement)

## Decision
Adopt contract-first API development with RED tests written before any implementation:

1. **Contract Definition**: OpenAPI/Swagger spec defines all endpoints, request/response schemas, error codes
2. **RED Tests First**: Integration tests against the contract are written and run (failing) before any handler code exists
3. **Implementation**: Backend implements handlers to satisfy the failing tests
4. **Contract Validation**: CI validates implementation matches contract on every change

## Alternatives Considered

**Implementation-first with generated docs**: Rejected—contracts become afterthought; frontend integration breaks; no verification until runtime.

**Shared TypeScript types only**: Rejected—no runtime validation; schema evolution uncontrolled; Nextcloud OCS requires specific response format.

**Contract testing only (no RED)**: Rejected—tests pass vacuously; no guarantee implementation matches contract; defeats Spec Kit RED requirement.

## Consequences

Positive:
- Frontend/backend parallel development with shared contract
- Breaking changes caught at test time, not runtime
- Spec Kit RED workflow enforced: tests fail before implementation exists
- OCS API compliance verified automatically
- Contract serves as living documentation

Negative:
- Upfront investment in contract design
- Contract changes require coordinated updates (tests + implementation)
- More initial test infrastructure needed

## Implementation Impact
- OpenAPI spec stored in `ocs/openapi.yaml` (or equivalent)
- Integration tests in `tests/Integration/Api/` target contract endpoints
- CI pipeline validates contract compliance on PR
- speckit-implement reads contract + RED tests as task input

## Related References
- Spec Kit feature: PettyCash V1 OCS API
- Related ADR: ADR-002 (Spec Kit as Source of Truth)
- Task IDs: API contract tasks in tasks.md