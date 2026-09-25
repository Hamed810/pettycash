# ADR-003: Domain Layer Must Not Depend on Nextcloud Services

## Status

Accepted

## Date

2026-09-23

## Context

PettyCash V1 is a Nextcloud 34 application with a layered architecture:

- **Domain**: Business rules, entities, value objects, invariants
- **Application**: Use cases, orchestration, workflows
- **Infrastructure**: Persistence, Nextcloud services, external integrations
- **Presentation**: OCS API, Vue frontend

The Domain layer contains pure business logic that must be testable in isolation and portable. Nextcloud services (e.g., `OCP\IUser`, `OCP\IAppData`, `OCP\IL10N`, `OCP\IConfig`) are framework-specific infrastructure concerns.

The architectural problem: Domain code must not directly call or depend on Nextcloud service interfaces, as this would:
- Couple business logic to Nextcloud implementation details
- Prevent isolated unit testing without a full Nextcloud runtime
- Violate the Dependency Rule (inner layers must not depend on outer layers)
- Make the domain non-portable to other contexts

## Decision

The **Domain layer must have zero dependencies on Nextcloud services** (OCP interfaces, Nextcloud base classes, or any Nextcloud runtime APIs).

All Nextcloud interactions belong in the Infrastructure layer, exposed to the Application layer via Port interfaces (Dependency Inversion). The Application layer orchestrates use cases using Domain entities and Infrastructure implementations.

## Alternatives Considered

1. **Allow Domain to use Nextcloud value objects (e.g., `OCP\IUser`)**: Rejected. Even value objects create compile-time coupling to Nextcloud. Domain should define its own `UserId`, `UserName`, etc.

2. **Allow Domain to use Nextcloud base classes (e.g., `OCP\AppFramework\Entity`)**: Rejected. Inheriting from framework classes ties domain models to persistence and framework lifecycle.

3. **Inject Nextcloud services into Domain via constructor**: Rejected. This still creates a dependency; the Domain should not know Nextcloud exists.

4. **Domain events published to Nextcloud event bus directly**: Rejected. Domain events should be plain objects; Infrastructure handles dispatch.

## Consequences

### Positive

- Domain is pure PHP, testable with standard PHPUnit (no Nextcloud test bootstrap needed)
- Business logic is decoupled from framework upgrades/changes
- Clear boundary: Infrastructure adapts Nextcloud → Domain; Domain never reaches out
- Enables future portability (e.g., CLI tool, different framework, standalone library)

### Negative

- Requires mapping/translation at Application/Infrastructure boundary (e.g., `OCP\IUser` → `Domain\User`)
- More boilerplate: Port interfaces, DTOs, mappers
- Developers must resist shortcut of injecting `IUserManager` directly into Domain services

## Implementation Impact

- **Domain** (`lib/Domain/`): No `use OCP\...` imports; no `extends` from Nextcloud classes
- **Application** (`lib/Application/`): Defines Port interfaces (`UserDirectoryPort`, `ClockPort`, `LoggerPort`, etc.)
- **Infrastructure** (`lib/Infrastructure/Nextcloud/`): Implements Ports using Nextcloud services (`NextcloudUserDirectory`, `SystemClock`, `TechnicalLogger`)
- **Wiring** (`lib/AppInfo/Application.php`): Binds Ports to Infrastructure implementations

## Related References

- Architecture Rules: `AGENTS.md:231-265`
- Architecture structure: `plan.md` (layer definitions)
- Existing ADR-001: AppData Adapter in Infrastructure Layer
- Spec Kit feature: `specs/001-pettycash-v1-core/`