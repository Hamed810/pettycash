# ADR-001: AppData Adapter Belongs to Infrastructure Layer

## Status

Accepted

## Date

2026-09-23

## Context

The PettyCash V1 application stores evidence files in Nextcloud's private `IAppData` storage (per Decision 8 in research.md). This requires an adapter to interact with the Nextcloud `IAppData` service for file upload, download, deletion, and checksum verification.

The architectural question is where this adapter belongs in the layered architecture:
- **Domain layer**: Pure business logic, no external dependencies
- **Application layer**: Use cases and orchestration, depends on Domain and Ports
- **Infrastructure layer**: Concrete implementations of Ports, external service integrations
- **Presentation layer**: Controllers, OCS API, Vue frontend

The AppData adapter is a concrete implementation that directly calls Nextcloud's `IAppData` service API. It has no business logic—it only provides technical file storage operations.

## Decision

Place the AppData adapter (`AppDataEvidenceStore`) in the **Infrastructure layer** under `lib/Infrastructure/Nextcloud/`.

This aligns with:
- The architecture defined in `plan.md` line 147: "Nextcloud/ # User, admin, logging, clock, AppData adapters"
- Task T025: "Implement ... private AppData adapters in `lib/Infrastructure/Nextcloud/`"
- Task T036: "Implement private evidence storage ... in `lib/Infrastructure/Nextcloud/AppDataEvidenceStore.php`"
- The Dependency Rule: Domain/Application must not depend on Infrastructure; Infrastructure implements Ports defined by Application

## Alternatives Considered

1. **Domain layer**: Rejected. The adapter has a hard dependency on Nextcloud's `IAppData` interface, violating Domain purity (no external dependencies).

2. **Application layer**: Rejected. Application layer contains use cases and orchestration, not concrete external service implementations. Placing it here would couple use cases to Nextcloud specifics.

3. **Shared/Common library**: Rejected. No other bounded context exists in this modular monolith; adding a shared library adds unnecessary indirection.

## Consequences

### Positive

- Clean separation: Domain/Application define *what* storage operations are needed (via Ports), Infrastructure provides *how* (via Nextcloud AppData)
- Testability: Application layer tests can use in-memory Port fakes; Infrastructure tests verify real Nextcloud integration
- Replaceability: If storage backend changes (e.g., S3, local filesystem), only Infrastructure adapter changes
- Consistent with existing Infrastructure adapters: `NextcloudCurrentUser`, `NextcloudUserDirectory`, `SystemClock`, `TechnicalLogger`, `NextcloudTransactionManager`

### Negative

- Additional indirection: Requires a Port interface in Application layer (`PrivateFileStorePort` or similar)
- Slightly more files: Port + Adapter + wiring configuration

## Implementation Impact

- **Port**: `lib/Application/Port/PrivateFileStorePort.php` (or similar) — defines upload, download, delete, exists, checksum methods
- **Adapter**: `lib/Infrastructure/Nextcloud/AppDataEvidenceStore.php` — implements Port using `OCP\Files\IAppData`
- **Wiring**: `lib/AppInfo/Application.php` — binds Port to Adapter in container
- **Tests**: 
  - Unit tests for Port contract (Application layer)
  - Integration tests for Adapter against real Nextcloud AppData (Infrastructure layer)

## Related References

- Spec Kit feature: `specs/001-pettycash-v1-core/`
- Research Decision 8: Store Evidence in Private Nextcloud AppData (`specs/001-pettycash-v1-core/research.md:125`)
- Task T025: Implement private AppData adapters (`tasks.md:71`)
- Task T036: Implement AppDataEvidenceStore (`tasks.md:103`)
- Architecture structure: `plan.md:147`