# PettyCash architecture (V1)

## Layer boundaries

- **Domain** (`lib/Domain/`): business rules, entities, value objects,
  invariants. Must not depend on Nextcloud services (ADR-003). Pure PHP;
  unit-tested without the container.
- **Application** (`lib/Application/`): use cases, orchestration, workflows,
  ports. Depends on domain + ports only. The projection shared by reports and
  exports lives here (`Application/Reporting/ReportProjection`, T315).
- **Infrastructure** (`lib/Infrastructure/`): persistence (Nextcloud
  `IDBConnection` behind repository ports), Nextcloud adapters
  (user directory, clock, AppData), export writers. The only layer that may
  reference `OCP\IDBConnection`, and only inside `Persistence/` + `Nextcloud/`
  (+ DI wiring in `AppInfo/Application.php`) — enforced by
  `scripts/security-check.sh` (T125).
- **Presentation**: OCS API (`lib/Controller/`, `appinfo/routes.php`) + Vue
  frontend (`src/`). Thin: controllers scope, validate, and shape; business
  rules stay in application/domain.

## Measurable RTL criteria (FR-071)

- No horizontal scroll at a 390px viewport (browser-measured, T122).
- User-facing text ≥12pt (16px) as painted, locked by
  `tests/Frontend/typography.spec.ts` and measured in `browser-matrix.spec.ts`.
- WCAG AA contrast for status text/icons; status meaning never colour-alone
  (icon + text, `StatusBadge.vue`).
- Keyboard-only primary journeys with a visible focus indicator under default,
  dark, and high-contrast themes (`keyboard-accessibility.spec.ts`).
- Logical-property RTL styles + bidi isolation (`src/styles/_rtl.scss`,
  `src/styles/_responsive.scss`, T120).

## Performance targets (FR-073, as amended)

- p95 <3s for routine views (informational probe: T128; 20 warmed runs).
- Error rate: 5xx over all requests issued; deliberately provoked 4xx/409/422
  excluded; environment-induced responses excluded with the count published;
  not measured when environment-induced responses exceed 1% (`quickstart.md`
  §8.2, `scripts/measure-error-rate.mjs`).
- Zero data loss: atomicity suite
  (`tests/Integration/Workflow/AtomicityFailureInjectionTest.php`), never
  inferred from timing. SC-008 deferred post-V1.

## Authorization decision matrix (FR-002/FR-007/FR-008, FR-055/FR-056)

- Purchaser: own sheets/transactions only.
- Cost-center manager / senior manager: assigned centres (+ scope union for
  multi-role, FR-002/FR-008); self-review permitted when the user holds the
  required review role + scope + stage permission.
- Finance: shared queue, no cost-center scoping (FR-006/FR-016); sees own +
  charged accounts in the `accounts` report (T299).
- Administrator without finance role: denied finance decisions (FR-007,
  explicit).
- Configuration audit: admins all; finance charged accounts; managers assigned
  centres; purchasers nothing (FR-055/FR-056, `ConfigAuditScopeTest`).
- Transaction vs configuration audit streams never cross (FR-056).

## Evidence (FR-074, FR-030)

- 100 MB per-file ceiling, enforced in `EvidenceService`, the controller, and
  the AppData store; reconciliation diagnostic (`EvidenceReconciliationService`,
  T130) is read-only and reports orphans/missing/oversized without deleting.
- Advanced evidence restrictions deferred to V2 (FR-030).

## Browser targets (FR-072)

Chrome 120+, Edge 120+, Firefox 115+ ESR, desktop + mobile-sized viewports
(`playwright.config.ts`, `browser-matrix.spec.ts`, T122).
