<?php

declare(strict_types=1);

namespace OCA\PettyCash\AppInfo;

use OCA\PettyCash\Application\Command\CommandResultRepository;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Account\AccountAdministrationService;
use OCA\PettyCash\Application\Configuration\ConfigurationPayloadFormatter;
use OCA\PettyCash\Application\Configuration\CostCenterService;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCA\PettyCash\Application\Configuration\CurrencyService;
use OCA\PettyCash\Application\Configuration\ExpenseTypeService;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Configuration\FinanceAssignmentService;
use OCA\PettyCash\Application\Expense\DeleteSheet;
use OCA\PettyCash\Application\Expense\SheetService;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Application\Evidence\EvidenceVisibility;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Application\Port\RequestTimeZone;
use OCA\PettyCash\Application\Port\RequestRoute;
use OCA\PettyCash\Application\Port\RetentionPolicyStore;
use OCA\PettyCash\Application\Port\TransientExportStore;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\Audit\RetentionPolicyService;
use OCA\PettyCash\Controller\ExportController;
use OCA\PettyCash\Controller\ReportController;
use OCA\PettyCash\Application\Reporting\DashboardQuery;
use OCA\PettyCash\Application\Reporting\ExportService;
use OCA\PettyCash\Application\Reporting\ReportQuery;
use OCA\PettyCash\Application\Reporting\AuditSelectionQuery;
use OCA\PettyCash\Application\Reporting\CompleteSheetExport;
use OCA\PettyCash\Application\Reporting\EvidenceSelectionQuery;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Application\Workflow\FinanceQueue;
use OCA\PettyCash\Application\Workflow\FinanceSettlementService;
use OCA\PettyCash\Application\Workflow\ManagementReviewService;
use OCA\PettyCash\Application\Workflow\SupersededDecisionAudit;
use OCA\PettyCash\Application\Evidence\PublicEvidenceLinkService;
use OCA\PettyCash\Application\Evidence\EvidenceLinkService;
use OCA\PettyCash\Application\Workflow\SubmitSheet;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceLinkRepository;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Infrastructure\Nextcloud\AppDataEvidenceStore;
use OCA\PettyCash\Infrastructure\Nextcloud\DeletedUserAccountListener;
use OCA\PettyCash\Infrastructure\Nextcloud\NextcloudAdministratorCheck;
use OCA\PettyCash\Infrastructure\Nextcloud\NextcloudCurrentUser;
use OCA\PettyCash\Infrastructure\Export\CsvExporter;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter;
use OCA\PettyCash\Infrastructure\Export\XlsxExporter;
use OCA\PettyCash\Infrastructure\Http\EvidenceContentPolicy;
use OCA\PettyCash\Infrastructure\Nextcloud\NextcloudRequestTimeZone;
use OCA\PettyCash\Infrastructure\Nextcloud\NextcloudRequestRoute;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCA\PettyCash\Infrastructure\Nextcloud\AppDataExportStore;
use OCA\PettyCash\Infrastructure\Nextcloud\NextcloudRetentionPolicyStore;
use OCA\PettyCash\Infrastructure\Nextcloud\NextcloudUserDirectory;
use OCA\PettyCash\Infrastructure\Nextcloud\SystemClock;
use OCA\PettyCash\Infrastructure\Nextcloud\TechnicalLogger;
use OCA\PettyCash\Infrastructure\Persistence\Account\DbAccountMovementRepository;
use OCA\PettyCash\Infrastructure\Persistence\Account\DbAccountRepository;
use OCA\PettyCash\Infrastructure\Persistence\Audit\DbAuditEventRepository;
use OCA\PettyCash\Infrastructure\Persistence\Command\DbCommandResultRepository;
use OCA\PettyCash\Infrastructure\Persistence\Configuration\DbCostCenterRepository;
use OCA\PettyCash\Infrastructure\Persistence\Configuration\DbCurrencyRepository;
use OCA\PettyCash\Infrastructure\Persistence\Configuration\DbExpenseTypeRepository;
use OCA\PettyCash\Infrastructure\Persistence\Configuration\DbRoleAssignmentRepository;
use OCA\PettyCash\Infrastructure\Persistence\Expense\DbExpenseSheetRepository;
use OCA\PettyCash\Infrastructure\Persistence\Expense\DbExpenseTransactionRepository;
use OCA\PettyCash\Infrastructure\Persistence\Expense\DbTransactionRevisionRepository;
use OCA\PettyCash\Infrastructure\Http\SheetPayloadFormatter;
use OCA\PettyCash\Infrastructure\Http\TransactionPayloadFormatter;
use OCA\PettyCash\Infrastructure\Persistence\Expense\SheetSummaryQuery;
use OCA\PettyCash\Infrastructure\Persistence\Evidence\DbEvidenceRepository;
use OCA\PettyCash\Infrastructure\Persistence\Evidence\DbEvidenceLinkRepository;
use OCA\PettyCash\Infrastructure\Persistence\NextcloudTransactionManager;
use OCA\PettyCash\Infrastructure\Persistence\Workflow\DbWorkflowDecisionRepository;
use OCP\AppFramework\App;
use OCP\IAppConfig;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\IAppData;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

final class Application extends App implements IBootstrap
{
    public const APP_ID = 'pettycash';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
        // Nextcloud infrastructure adapters
        $context->registerService(PrivateFileStore::class, function ($c) {
            return new AppDataEvidenceStore($c->query(IAppData::class));
        });

        $context->registerService(CurrentUser::class, function ($c) {
            return new NextcloudCurrentUser($c->query(IUserSession::class));
        });

        $context->registerService(UserDirectory::class, function ($c) {
            return new NextcloudUserDirectory($c->query(IUserManager::class));
        });

        $context->registerService(
            \OCA\PettyCash\Application\Port\AdministratorCheck::class,
            function ($c) {
                return new NextcloudAdministratorCheck($c->query(IGroupManager::class));
            }
        );

        $context->registerService(
            \OCA\PettyCash\Domain\Shared\Clock::class,
            fn() => new SystemClock()
        );

        $context->registerService(
            \OCA\PettyCash\Infrastructure\Nextcloud\TechnicalLogger::class,
            function ($c) {
                return new \OCA\PettyCash\Infrastructure\Nextcloud\TechnicalLogger($c->query(LoggerInterface::class));
            }
        );

        // Persistence repositories
        $context->registerService(CurrencyRepository::class, function ($c) {
            return new DbCurrencyRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        $context->registerService(CostCenterRepository::class, function ($c) {
            return new DbCostCenterRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        $context->registerService(ExpenseSheetRepository::class, function ($c) {
            return new DbExpenseSheetRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        $context->registerService(ExpenseTransactionRepository::class, function ($c) {
            return new DbExpenseTransactionRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class),
                $c->query(TransactionRevisionRepository::class)
            );
        });

        $context->registerService(TransactionRevisionRepository::class, function ($c) {
            return new DbTransactionRevisionRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        $context->registerService(EvidenceRepository::class, function ($c) {
            return new DbEvidenceRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        // FR-031: public evidence links had a domain entity, a port and a working adapter, and **no
        // container registration at all** — so `findByTokenHash()` was called from nowhere and the whole
        // consumption path was unreachable. That is the same shape as the unrouted
        // `evidence#links` / `evidence#deactivateLink` actions: the schema and the repository existed and
        // nothing was wired to them.
        //
        // `CurrentUser` is injected because the adapter's constructor requires it, and it is safe here
        // only because every use of it is inside `save()`: `NextcloudCurrentUser::uid()` throws for an
        // unauthenticated request, and construction does not call it. Consumption only reads, so the
        // anonymous path never reaches that method — and `PublicEvidenceLinkService` is not given a
        // repository method that could.
        $context->registerService(EvidenceLinkRepository::class, function ($c) {
            return new DbEvidenceLinkRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        $context->registerService(AuditEventRepository::class, function ($c) {
            return new DbAuditEventRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        // T367: the two shared selection queries.
        //
        // Registered explicitly because both controllers below are built by hand — they are
        // `new ReportController(...)` / `new ExportController(...)` inside `registerService`, not
        // autowired — so the dependency lists there are positional and have to name these classes.
        //
        // That is worth stating because it is what the failure looked like. Adding two constructor
        // parameters without adding them to these lists shifted every later argument along: position
        // 7 received `ExpenseSheetRepository`, so the container handed `ExportController` a
        // `DbExpenseSheetRepository` where it declared `EvidenceSelectionQuery`. Every export then
        // answered an **empty 200** whose only trace was a TypeError in `nextcloud.log`, because the
        // OCS error mapper had no exception to map. Nothing in the response said anything was wrong.
        $context->registerService(EvidenceSelectionQuery::class, function ($c) {
            return new EvidenceSelectionQuery(
                $c->query(EvidenceRepository::class),
                $c->query(ExpenseTransactionRepository::class),
                $c->query(TransactionRevisionRepository::class),
                $c->query(ExpenseSheetRepository::class),
                $c->query(UserDirectory::class)
            );
        });

        $context->registerService(AuditSelectionQuery::class, function ($c) {
            return new AuditSelectionQuery(
                $c->query(AuditEventRepository::class)
            );
        });

        // T366 / FR-067. Registered explicitly, in the same block as the other two selection
        // queries, because `ExportController` is built by hand with a positional argument list —
        // adding a constructor parameter without adding it there shifts every later argument along,
        // which is the exact failure the comment above `EvidenceSelectionQuery` records.
        $context->registerService(CompleteSheetExport::class, function ($c) {
            return new CompleteSheetExport(
                $c->query(ExpenseSheetRepository::class),
                $c->query(ExpenseTransactionRepository::class),
                $c->query(TransactionRevisionRepository::class),
                $c->query(WorkflowDecisionRepository::class),
                $c->query(AuditEventRepository::class),
                $c->query(EvidenceRepository::class),
                $c->query(SheetSummaryQuery::class),
                $c->query(UserDirectory::class)
            );
        });

        // The configuration audit stream (FR-055). Same class, different bound table and subject
        // columns, so the single AuditEventRepository port is unchanged and the reads stay
        // stream-scoped. Registered under a distinct key rather than as a second
        // AuditEventRepository binding, which would make the transaction stream ambiguous.
        //
        // T319 added `countByTransactionId()` to that port, which makes it the ninth method rather
        // than the seven this comment used to claim — the count was added precisely because a stale
        // count is what this gap turned on, so the number here had to be corrected with it. It counts
        // within whichever table this binding resolves to, so a configuration-stream caller gets a
        // configuration count; nothing calls it that way, and the `AuditEventRepository` binding
        // above is the transaction stream that `GET /transactions/{id}/audit` uses.
        $context->registerService('PettyCashConfigurationAuditRepository', function ($c) {
            return new DbAuditEventRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class),
                'configuration'
            );
        });

        $context->registerService(AccountRepository::class, function ($c) {
            return new DbAccountRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        $context->registerService(AccountMovementRepository::class, function ($c) {
            return new DbAccountMovementRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        $context->registerService(ExpenseTypeRepository::class, function ($c) {
            return new DbExpenseTypeRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        $context->registerService(RoleAssignmentRepository::class, function ($c) {
            return new DbRoleAssignmentRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        $context->registerService(WorkflowDecisionRepository::class, function ($c) {
            return new DbWorkflowDecisionRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        // The derived sheet-summary projection behind FR-062. Registered rather than auto-wired
        // because it takes the connection, which the container cannot resolve on its own.
        $context->registerService(SheetSummaryQuery::class, function ($c) {
            return new SheetSummaryQuery($c->query(IDBConnection::class));
        });

        // The single source of truth for the sheet OCS payload, for the same reason the transaction
        // formatter exists: the contract requires `totals`, and a private copy of this mapping in
        // SheetController had already drifted away from it.
        $context->registerService(SheetPayloadFormatter::class, function ($c) {
            return new SheetPayloadFormatter(
                $c->query(SheetSummaryQuery::class),
                $c->query(ExpenseTransactionRepository::class),
                $c->query(CurrencyRepository::class),
                // `SheetSummary.ownerDisplayName` is resolved rather than emitted as a literal, through
                // the same port `SessionController` resolves the caller's own name through (T340).
                $c->query(UserDirectory::class),
                // `SheetDetail.transactions` is `TransactionSummary[]`, formatted by the transaction
                // formatter rather than by a second private copy of that mapping here.
                $c->query(TransactionPayloadFormatter::class)
            );
        });

        // The single source of truth for the transaction OCS payload. Both
        // TransactionController and ReviewController delegate to it; see the class for why
        // the formatting was not left duplicated in the controllers.
        $context->registerService(TransactionPayloadFormatter::class, function ($c) {
            return new TransactionPayloadFormatter(
                $c->query(WorkflowDecisionRepository::class),
                $c->query(EvidenceRepository::class),
                $c->query(CostCenterRepository::class),
                $c->query(CurrencyRepository::class),
                $c->query(RoleAssignmentRepository::class),
                $c->query(ExpenseSheetRepository::class),
                $c->query(CurrentUser::class)
            );
        });

        $context->registerService(CommandResultRepository::class, function ($c) {
            return new DbCommandResultRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
            );
        });

        // Transaction manager
        $context->registerService(TransactionManager::class, function ($c) {
            return new NextcloudTransactionManager($c->query(IDBConnection::class));
        });

        // FR-016/FR-055: the derivation of "accounts charged at all" reads the configuration audit
        // stream, so it takes the configuration binding rather than the transaction one. Bound to the
        // wrong stream it would throw instead of quietly answering nothing.
        $context->registerService(\OCA\PettyCash\Application\Reporting\ChargedAccountScope::class, function ($c) {
            return new \OCA\PettyCash\Application\Reporting\ChargedAccountScope(
                $c->query('PettyCashConfigurationAuditRepository'),
                $c->query(AccountRepository::class)
            );
        });

        // Audit writer
        $context->registerService(AuditWriter::class, function ($c) {
            return new \OCA\PettyCash\Application\Audit\AuditWriterService(
                $c->query(AuditEventRepository::class),
                $c->query(CurrentUser::class),
                $c->query(UserDirectory::class),
                $c->query('PettyCashConfigurationAuditRepository')
            );
        });

        // Authorization service
        $context->registerService(AuthorizationService::class, function ($c) {
            return new AuthorizationService();
        });

        // Idempotency service
        $context->registerService(IdempotencyService::class, function ($c) {
            return new IdempotencyService($c->query(CommandResultRepository::class));
        });

        // Configuration administration services (T081, T082, T083).
        //
        // Registered explicitly rather than autowired, matching the other application services here.
        // Every dependency is already bound above, so autowiring would also resolve them -- the
        // registration exists to make the dependency set visible at a glance, because these three are
        // the first services whose *authorization* dependency (`AdministratorCheck`) is what keeps a
        // non-administrator from writing configuration, and that is easy to miss when it is implicit.
        //
        // `ConfigurationController` (T087) consumes these and is deliberately absent: its eleven route
        // targets stay in T272's `KNOWN_UNRESOLVED` until the actions actually exist.
        // T086 / FR-017. Registered before the four administration services that depend on it, because
        // they receive it as a constructor argument. Both of its own dependencies are bound above: the
        // shared `AuditWriter` (which routes `appendConfiguration()` to the configuration stream) and
        // the shared `TransactionManager`, which is what makes a mutation and its audit record one
        // unit -- an audit that cannot be persisted rolls the mutation back.
        $context->registerService(ConfigurationAuditService::class, function ($c) {
            return new ConfigurationAuditService(
                $c->query(AuditWriter::class),
                $c->query(TransactionManager::class)
            );
        });
        $context->registerService(CurrencyService::class, function ($c) {
            return new CurrencyService(
                $c->query(CurrencyRepository::class),
                $c->query(AdministratorCheck::class),
                $c->query(ConfigurationAuditService::class),
                // T422 / FR-023: lets `update()` know whether this currency already holds amounts, which is
                // what decides if its decimal scale may change. Injected rather than queried lazily so the
                // answer cannot depend on when the check happens relative to the write.
                $c->query(\OCA\PettyCash\Domain\Account\Repository\AccountRepository::class)
            );
        });
        $context->registerService(CostCenterService::class, function ($c) {
            return new CostCenterService(
                $c->query(CostCenterRepository::class),
                $c->query(RoleAssignmentRepository::class),
                $c->query(UserDirectory::class),
                $c->query(AdministratorCheck::class),
                $c->query(Clock::class),
                $c->query(ConfigurationAuditService::class)
            );
        });
        $context->registerService(ExpenseTypeService::class, function ($c) {
            return new ExpenseTypeService(
                $c->query(ExpenseTypeRepository::class),
                $c->query(AdministratorCheck::class),
                $c->query(ConfigurationAuditService::class)
            );
        });
        // T084. `UserDirectory` is the second authorization-adjacent dependency in this group and it is
        // the one that is easy to overlook: the table's `user_uid` column is a bare string with no
        // foreign key, so nothing below this service would stop an assignment naming a user who does
        // not exist. `Clock` is a dependency rather than a convenience because whether a POST updates
        // an existing row or inserts a new one depends on whether that user's assignment is live
        // *today*, and "today" has to be the clock's.
        $context->registerService(FinanceAssignmentService::class, function ($c) {
            return new FinanceAssignmentService(
                $c->query(RoleAssignmentRepository::class),
                $c->query(UserDirectory::class),
                $c->query(AdministratorCheck::class),
                $c->query(Clock::class),
                $c->query(ConfigurationAuditService::class)
            );
        });
        // T087. The formatter is registered rather than left to autowiring so that the shape
        // `contracts/ocs-api.yaml` declares is resolved from one named definition in review, next to the
        // four services that produce the aggregates it reads.
        $context->registerService(ConfigurationPayloadFormatter::class, function () {
            return new ConfigurationPayloadFormatter();
        });
        // T085. Depends on `CurrencyService` rather than `CurrencyRepository`: the default rial's *scale*
        // is configuration (`minor_unit = 0`) and `CurrencyService::defaultRial()` is the one place that
        // decides so. Querying the repository here would let provisioning read an `IRR` whose scale
        // nothing has checked.
        $context->registerService(AccountAdministrationService::class, function ($c) {
            return new AccountAdministrationService(
                $c->query(\OCA\PettyCash\Domain\Account\Repository\AccountRepository::class),
                $c->query(CurrencyService::class),
                // FR-017: provisioning records the account it creates, so an account is no longer the
                // one configurable resource whose arrival leaves no trace. The audit writer is queried
                // rather than the configuration-audit service, because this method keeps its own
                // duplicate-key handling and wrapping the write in `ConfigurationAuditService::record()`
                // would move that logic the idempotency note on the class depends on.
                $c->query(\OCA\PettyCash\Application\Port\AuditWriter::class)
            );
        });

        // T391 / ADR-005: close a deleted user's petty-cash accounts.
        //
        // Registered rather than resolved lazily inside `boot()`, because a listener that is not
        // registered is a listener that never runs and the account stays active — and an active
        // orphaned account is exactly the defect this exists to remove.
        $context->registerEventListener(
            UserDeletedEvent::class,
            DeletedUserAccountListener::class,
            // Default priority. The listener only reads accounts this application owns and writes
            // nothing outside them, so it needs no ordering against other applications' handlers, and
            // claiming an early slot would be a way of asserting a precedence nothing depends on.
            0
        );

        // Application services
        $context->registerService(SheetService::class, function ($c) {
            return new SheetService(
                $c->query(ExpenseSheetRepository::class),
                $c->query(\OCA\PettyCash\Domain\Account\Repository\AccountRepository::class),
                $c->query(CurrentUser::class),
                $c->query(TransactionManager::class),
                // FR-020's account rule (T358). Injected rather than reimplemented in the sheet path, so
                // provisioning keeps the idempotency and concurrent-insert handling T085 gave it.
                $c->query(AccountAdministrationService::class)
            );
        });

        $context->registerService(DeleteSheet::class, function ($c) {
            // T417 / FR-086. `IdempotencyService` is injected rather than left to the controller so the
            // replay gate wraps the guards themselves: on a replay the closure never runs, so a retried
            // delete cannot re-validate a version check against a sheet that no longer exists.
            return new DeleteSheet(
                $c->query(CurrentUser::class),
                $c->query(ExpenseSheetRepository::class),
                $c->query(\OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository::class),
                $c->query(\OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository::class),
                $c->query(PrivateFileStore::class),
                $c->query(TransactionManager::class),
                $c->query(\OCA\PettyCash\Application\Command\IdempotencyService::class)
            );
        });

        $context->registerService(TransactionService::class, function ($c) {
            return new TransactionService(
                $c->query(ExpenseSheetRepository::class),
                $c->query(ExpenseTransactionRepository::class),
                $c->query(TransactionRevisionRepository::class),
                $c->query(CostCenterRepository::class),
                $c->query(\OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository::class),
                $c->query(EvidenceRepository::class),
                $c->query(PrivateFileStore::class),
                $c->query(CurrentUser::class),
                $c->query(TransactionManager::class),
                $c->query(AuditWriter::class),
                $c->query(IdempotencyService::class)
            );
        });

        $context->registerService(EvidenceService::class, function ($c) {
            return new EvidenceService(
                $c->query(PrivateFileStore::class),
                $c->query(EvidenceRepository::class),
                $c->query(CurrentUser::class),
                $c->query(AuditWriter::class),
                $c->query(UserDirectory::class),
                $c->query(IdempotencyService::class)
            );
        });

        // FR-060 / Constitution IV: who may open a transaction's evidence. Registered as its own
        // service rather than built inside the controller, because the rule has to be assertable as a
        // matrix and a private method on the controller is not testable without an `IDBConnection`
        // (T362). It deliberately does not consult `AuthorizationService::canView()`; see its docblock.
        $context->registerService(EvidenceVisibility::class, function ($c) {
            return new EvidenceVisibility(
                $c->query(AuthorizationService::class),
                $c->query(RoleAssignmentRepository::class),
                $c->query(CostCenterRepository::class)
            );
        });

        // FR-068's Jalali period resolver. Takes only the `Clock`, so the calendar it resolves against
        // is the application clock rather than PHP's -- FR-068 requires resolution at query time, and a
        // test that cannot move time cannot assert that a period is the *in-progress* one.
        $context->registerService(\OCA\PettyCash\Domain\Shared\JalaliReportingPeriods::class, function ($c) {
            return new \OCA\PettyCash\Domain\Shared\JalaliReportingPeriods($c->query(\OCA\PettyCash\Domain\Shared\Clock::class));
        });

        // FR-068's request time zone. The adapter reads Nextcloud's own per-user `core.timezone`
        // preference and falls back to the `default_timezone` system value, so no migration or admin
        // surface is needed for this app; see the port for why the chain is core's and not ours.
        $context->registerService(RequestTimeZone::class, function ($c) {
            return new NextcloudRequestTimeZone($c->query(\OCP\IConfig::class));
        });

        // FR-087: the route a fault is recorded against. Registered explicitly for the same reason
        // `RequestTimeZone` above is — the container *could* resolve `RequestRoute` to nothing, because
        // the interface has no single obvious implementation, and a fault recorder that silently resolved
        // to a null collaborator would drop the route from every log line without any error.
        $context->registerService(RequestRoute::class, function ($c) {
            return new NextcloudRequestRoute($c->query(\OCP\IRequest::class));
        });

        // FR-060's inline-safe media-type policy (T275). Registered explicitly for the same reason
        // `EvidenceVisibility` above is: the container resolving it by type-hint would work, but every
        // other `Infrastructure\Http` collaborator here is named, and an unregistered dependency that
        // silently fails to resolve shows up as a 500 on an evidence preview rather than as a wiring
        // error. It has no collaborators of its own.
        $context->registerService(EvidenceContentPolicy::class, function () {
            return new EvidenceContentPolicy();
        });

        $context->registerService(SubmitSheet::class, function ($c) {
            return new SubmitSheet(
                $c->query(TransactionManager::class),
                $c->query(ExpenseSheetRepository::class),
                $c->query(AuditWriter::class),
                $c->query(IdempotencyService::class),
                $c->query(CurrentUser::class),
                $c->query(CostCenterRepository::class),
                $c->query(ExpenseTransactionRepository::class),
                $c->query(RoleAssignmentRepository::class)
            );
        });

        $context->registerService(FinanceQueue::class, function ($c) {
            return new FinanceQueue(
                $c->query(ExpenseTransactionRepository::class),
                $c->query(RoleAssignmentRepository::class),
                $c->query(CurrentUser::class)
            );
        });

        // Audit retention policy (FR-057). Registered explicitly because the store is a Nextcloud
        // IAppConfig adapter, which the container cannot construct on its own.
        $context->registerService(RetentionPolicyStore::class, function ($c) {
            return new NextcloudRetentionPolicyStore($c->query(IAppConfig::class));
        });

        $context->registerService(RetentionPolicyService::class, function ($c) {
            return new RetentionPolicyService(
                $c->query(RetentionPolicyStore::class),
                $c->query(AuditWriter::class),
                $c->query(\OCA\PettyCash\Domain\Shared\Clock::class)
            );
        });

        // Private transient storage for generated export artifacts. contracts/exports.md requires
        // "private transient application storage", and plan.md names IAppData, so the grouped-evidence
        // exporter persists there rather than in the host's shared system temp directory.
        $context->registerService(TransientExportStore::class, function ($c) {
            return new AppDataExportStore($c->query(IAppData::class));
        });

        // Export orchestration (T111). Registered here so the export controller (T112) can authorize
        // before it generates anything: the service owns the transient-artifact lifecycle, so a caller
        // that resolves it gets both the authorization decision and the cleanup in one place.
        // T112. `DashboardQuery` was built in T106 but never registered, so nothing could resolve it and
        // `/api/v1/dashboard` had to be served by a stub instead of the role-aware projection.
        $context->registerService(DashboardQuery::class, function ($c) {
            return new DashboardQuery(
                $c->query(RoleAssignmentRepository::class),
                $c->query(\OCA\PettyCash\Domain\Shared\Clock::class),
                $c->query(\OCA\PettyCash\Application\Port\AdministratorCheck::class)
            );
        });

        $context->registerService(ReportQuery::class, function ($c) {
            return new ReportQuery();
        });

        // T306. The filter vocabulary and its coercion, shared by `GET /reports` and `POST /exports`.
        // It was a private constant and a private method on `ReportController`, which left the export
        // endpoint free to answer the same filter name differently -- and it did, by ignoring filters
        // entirely. One definition, two callers.
        //
        // T329 added the `FilterIdentityResolver` argument: the vocabulary now accepts cost centres and
        // expense types by **public UUID**, which is what `data-model.md` requires on the wire, and
        // resolving one to the surrogate key the rows are filtered on is a server responsibility. Both
        // repositories it needs are registered above.
        $context->registerService(\OCA\PettyCash\Application\Reporting\ReportFilterFactory::class, function ($c) {
            return new \OCA\PettyCash\Application\Reporting\ReportFilterFactory(
                new \OCA\PettyCash\Application\Reporting\FilterIdentityResolver(
                    $c->get(CostCenterRepository::class),
                    $c->get(ExpenseTypeRepository::class)
                ),
                // FR-068. The period resolver and the request time zone are resolved here rather than in
                // a query, so the Jalali conversion never enters the query path (plan.md). Appended
                // rather than inserted, and this list is positional and hand-written -- T367 recorded
                // that getting it wrong shifts every later argument and answers an empty 200.
                $c->query(\OCA\PettyCash\Domain\Shared\JalaliReportingPeriods::class),
                $c->query(RequestTimeZone::class),
                $c->query(CurrentUser::class)
            );
        });

        $context->registerService(ExportService::class, function ($c) {
            return new ExportService(
                new CsvExporter(),
                new XlsxExporter($c->query(TransientExportStore::class)),
                new EvidenceArchiveExporter($c->query(TransientExportStore::class)),
                $c->query(TransientExportStore::class),
                $c->query(PrivateFileStore::class)
            );
        });

        // T112. Both controllers take their scope from `DashboardQuery::scopeFor()`, the same way
        // `DashboardController` does, so a report and a dashboard can never disagree about who the
        // caller is.
        $context->registerService(ReportController::class, function ($c) {
            return new ReportController(
                $c->query(\OCP\IRequest::class),
                $c->query(CurrentUser::class),
                $c->query(DashboardQuery::class),
                $c->query(ReportQuery::class),
                $c->query(\OCA\PettyCash\Application\Reporting\ReportFilterFactory::class),
                $c->query(ExpenseSheetRepository::class),
                $c->query(ExpenseTransactionRepository::class),
                $c->query(CostCenterRepository::class),
                $c->query(AccountRepository::class),
                $c->query(EvidenceRepository::class),
                $c->query(AuditEventRepository::class),
                $c->query(EvidenceSelectionQuery::class),
                $c->query(AuditSelectionQuery::class),
                $c->query(\OCA\PettyCash\Application\Reporting\ChargedAccountScope::class),
                // T368: FR-065's movements on each `accounts` row. Both were appended here rather than
                // anywhere else because this list is positional and hand-written — inserting two
                // arguments without inserting them here would shift every later one along and hand
                // `ReportController` a `TechnicalLogger` where it declares
                // `AccountPayloadFormatter`. That failure is silent: the endpoint answers an empty or
                // wrong-shaped 200 rather than refusing. T367 hit this exact list and the diagnosis it
                // recorded was "how the code is loaded", which no suite asserts.
                $c->query(\OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository::class),
                $c->query(\OCA\PettyCash\Application\Account\AccountPayloadFormatter::class),
                $c->query(\OCA\PettyCash\Infrastructure\Nextcloud\TechnicalLogger::class),
                $c->query(OcsErrorMapper::class),
                $c->query(\OCA\PettyCash\Domain\Shared\Clock::class)
            );
        });

        $context->registerService(ExportController::class, function ($c) {
            return new ExportController(
                $c->query(\OCP\IRequest::class),
                $c->query(CurrentUser::class),
                $c->query(DashboardQuery::class),
                $c->query(ExportService::class),
                $c->query(ReportQuery::class),
                $c->query(\OCA\PettyCash\Application\Reporting\ReportFilterFactory::class),
                $c->query(EvidenceSelectionQuery::class),
                $c->query(AuditSelectionQuery::class),
                $c->query(CompleteSheetExport::class),
                $c->query(ExpenseSheetRepository::class),
                $c->query(ExpenseTransactionRepository::class),
                $c->query(OcsErrorMapper::class),
                // T365, appended last so no existing positional argument shifts: `ExportController`
                // is built by hand, and the file already documents what a mid-list insertion did once.
                $c->query(SheetSummaryQuery::class)
            );
        });

        // FR-085. Registered explicitly rather than left to reflection-based autowiring, because
        // `ManagementReviewService` resolves it implicitly while `FinanceSettlementService` is built
        // with a fixed argument list below — and a service that one path receives by autowiring and
        // the other does not is a service whose two instances can differ for reasons nothing states.
        $context->registerService(SupersededDecisionAudit::class, function ($c) {
            return new SupersededDecisionAudit(
                $c->query(\OCA\PettyCash\Application\Port\AuditWriter::class),
                $c->query(ExpenseTransactionRepository::class),
                $c->query(TransactionManager::class),
                $c->query(LoggerInterface::class)
            );
        });

        // FR-031: public evidence link consumption. Registered explicitly because it is constructed from
        // ports that are all registered under concrete names, and a route action resolved by reflection
        // would build a *different* instance of the audit writer than the one the authenticated paths use
        // — which is exactly the kind of divergence that makes an audit entry appear in one stream and not
        // the other.
        $context->registerService(PublicEvidenceLinkService::class, function ($c) {
            return new PublicEvidenceLinkService(
                $c->query(EvidenceLinkRepository::class),
                $c->query(EvidenceRepository::class),
                $c->query(PrivateFileStore::class),
                $c->query(EvidenceContentPolicy::class),
                $c->query(\OCA\PettyCash\Application\Port\AuditWriter::class),
                $c->query(\OCA\PettyCash\Domain\Shared\Clock::class)
            );
        });

        // FR-031: the producer half — creating and revoking a link. Registered explicitly for the same
        // reason, and because `EvidenceController` is autowired: a service resolved by reflection for one
        // path and explicitly for another is a service whose two instances can differ for reasons
        // nothing states. Both halves share `EvidenceLinkRepository` and the audit writer, so a link can
        // be created by one and opened by the other only if both see the same rows.
        $context->registerService(EvidenceLinkService::class, function ($c) {
            return new EvidenceLinkService(
                $c->query(EvidenceLinkRepository::class),
                $c->query(EvidenceRepository::class),
                $c->query(\OCA\PettyCash\Application\Port\AdministratorCheck::class),
                $c->query(\OCA\PettyCash\Application\Port\AuditWriter::class),
                $c->query(IdempotencyService::class),
                $c->query(\OCA\PettyCash\Application\Command\CommandResultRepository::class),
                $c->query(CurrentUser::class),
                $c->query(\OCA\PettyCash\Domain\Shared\Clock::class)
            );
        });

        $context->registerService(FinanceSettlementService::class, function ($c) {
            return new FinanceSettlementService(
                $c->query(TransactionManager::class),
                $c->query(ExpenseSheetRepository::class),
                $c->query(ExpenseTransactionRepository::class),
                $c->query(TransactionRevisionRepository::class),
                $c->query(AccountRepository::class),
                $c->query(AccountMovementRepository::class),
                $c->query(CostCenterRepository::class),
                $c->query(RoleAssignmentRepository::class),
                $c->query(WorkflowDecisionRepository::class),
                $c->query(AuditWriter::class),
                $c->query(IdempotencyService::class),
                $c->query(CurrentUser::class),
                $c->query(UserDirectory::class),
                $c->query(AuthorizationService::class),
                $c->query(SupersededDecisionAudit::class)
            );
        });
    }

    public function boot(IBootContext $context): void
    {
        // Intentionally lightweight: migrations and commands perform explicit work.
    }
}