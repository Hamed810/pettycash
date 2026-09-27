<?php

declare(strict_types=1);

namespace OCA\PettyCash\AppInfo;

use OCA\PettyCash\Application\Command\CommandResultRepository;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Expense\SheetService;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\PrivateFileStore;
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
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Application\Workflow\FinanceQueue;
use OCA\PettyCash\Application\Workflow\FinanceSettlementService;
use OCA\PettyCash\Application\Workflow\ManagementReviewService;
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
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Infrastructure\Nextcloud\AppDataEvidenceStore;
use OCA\PettyCash\Infrastructure\Nextcloud\NextcloudAdministratorCheck;
use OCA\PettyCash\Infrastructure\Nextcloud\NextcloudCurrentUser;
use OCA\PettyCash\Infrastructure\Export\CsvExporter;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter;
use OCA\PettyCash\Infrastructure\Export\XlsxExporter;
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

        $context->registerService(AuditEventRepository::class, function ($c) {
            return new DbAuditEventRepository(
                $c->query(IDBConnection::class),
                $c->query(UserDirectory::class),
                $c->query(CurrentUser::class)
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
                $c->query(CurrencyRepository::class)
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

        // Audit writer
        $context->registerService(AuditWriter::class, function ($c) {
            return new \OCA\PettyCash\Application\Audit\AuditWriterService(
                $c->query(AuditEventRepository::class),
                $c->query(CurrentUser::class),
                $c->query(UserDirectory::class)
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

        // Application services
        $context->registerService(SheetService::class, function ($c) {
            return new SheetService(
                $c->query(ExpenseSheetRepository::class),
                $c->query(\OCA\PettyCash\Domain\Account\Repository\AccountRepository::class),
                $c->query(CurrentUser::class),
                $c->query(TransactionManager::class)
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
                $c->query(TransactionManager::class)
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

        $context->registerService(SubmitSheet::class, function ($c) {
            return new SubmitSheet(
                $c->query(TransactionManager::class),
                $c->query(ExpenseSheetRepository::class),
                $c->query(AuditWriter::class),
                $c->query(IdempotencyService::class),
                $c->query(CurrentUser::class),
                $c->query(CostCenterRepository::class),
                $c->query(ExpenseTransactionRepository::class)
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
                $c->query(\OCA\PettyCash\Domain\Shared\Clock::class)
            );
        });

        $context->registerService(ReportQuery::class, function ($c) {
            return new ReportQuery();
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
                $c->query(ExpenseSheetRepository::class),
                $c->query(ExpenseTransactionRepository::class),
                $c->query(CostCenterRepository::class),
                $c->query(AccountRepository::class),
                $c->query(EvidenceRepository::class),
                $c->query(AuditEventRepository::class),
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
                $c->query(ExpenseSheetRepository::class),
                $c->query(ExpenseTransactionRepository::class),
                $c->query(OcsErrorMapper::class)
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
                $c->query(AuthorizationService::class)
            );
        });
    }

    public function boot(IBootContext $context): void
    {
        // Intentionally lightweight: migrations and commands perform explicit work.
    }
}