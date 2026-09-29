<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Application\Command\CommandResultRepository;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Application\Expense\SheetService;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Application\Workflow\SubmitSheet;
use OCA\PettyCash\Application\Workflow\ManagementReviewService;
use OCA\PettyCash\Application\Workflow\FinanceSettlementService;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Infrastructure\Nextcloud\SystemClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTypeRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionRevisionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountMovementRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryWorkflowDecisionRepository;

// Simple DI container for integration tests
class TestContainer
{
    private array $services = [];
    private array $instances = [];

    public function register(string $id, callable $factory): void
    {
        $this->services[$id] = $factory;
    }

    public function query(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (!isset($this->services[$id])) {
            throw new \RuntimeException("Service $id not found in test container");
        }
        $factory = $this->services[$id];
        $instance = $factory($this);
        $this->instances[$id] = $instance;
        return $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    public function setInstance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }
}

// Global test container instance using static accessor for PHPUnit 11 compatibility
class TestContainerAccessor
{
    private static ?TestContainer $instance = null;

    public static function setInstance(TestContainer $container): void
    {
        self::$instance = $container;
    }

    public static function getInstance(): TestContainer
    {
        if (self::$instance === null) {
            throw new \RuntimeException('TestContainer not initialized. Bootstrap may not have run.');
        }
        return self::$instance;
    }
}

TestContainerAccessor::setInstance(new TestContainer());

$container = TestContainerAccessor::getInstance();

// Register core infrastructure test doubles
$container->register(TransactionManager::class, fn() => new InMemoryTransactionManager());
$container->register(CurrentUser::class, fn() => new InMemoryCurrentUser('test-user'));
$container->register(UserDirectory::class, fn() => new InMemoryUserDirectory());
$container->register(AuditWriter::class, fn($c) => new AuditWriterService(
    $c->query(AuditEventRepository::class),
    $c->query(CurrentUser::class),
    $c->query(UserDirectory::class),
    $c->query(AuditEventRepository::class)
));
$container->register(AdministratorCheck::class, fn() => new InMemoryAdministratorCheck());
$container->register(PrivateFileStore::class, fn() => new InMemoryPrivateFileStore());
$container->register(CommandResultRepository::class, fn() => new InMemoryCommandResultRepository());
$container->register(Clock::class, fn() => new SystemClock());

// Register AuthorizationService
$container->register(AuthorizationService::class, fn() => new AuthorizationService());

// Register repositories
$container->register(CostCenterRepository::class, fn() => new InMemoryCostCenterRepository());
$container->register(CurrencyRepository::class, fn() => new InMemoryCurrencyRepository());
$container->register(ExpenseTypeRepository::class, fn() => new InMemoryExpenseTypeRepository());
$container->register(RoleAssignmentRepository::class, fn() => new InMemoryRoleAssignmentRepository());
$container->register(ExpenseSheetRepository::class, fn() => new InMemoryExpenseSheetRepository());
$container->register(ExpenseTransactionRepository::class, fn() => new InMemoryExpenseTransactionRepository());
$container->register(TransactionRevisionRepository::class, fn() => new InMemoryTransactionRevisionRepository());
$container->register(EvidenceRepository::class, fn() => new InMemoryEvidenceRepository());
$container->register(AuditEventRepository::class, fn() => new InMemoryAuditEventRepository());
$container->register(AccountRepository::class, fn() => new InMemoryAccountRepository());
$container->register(AccountMovementRepository::class, fn() => new InMemoryAccountMovementRepository());
$container->register(WorkflowDecisionRepository::class, fn() => new InMemoryWorkflowDecisionRepository());

// Register IdempotencyService
$container->register(IdempotencyService::class, fn($c) => new IdempotencyService($c->query(CommandResultRepository::class)));

// Register AuditWriterService
$container->register(AuditWriterService::class, fn($c) => new AuditWriterService(
    $c->query(AuditEventRepository::class),
    $c->query(CurrentUser::class),
    $c->query(UserDirectory::class),
    $c->query(AuditEventRepository::class)
));

// Register Application Services
$container->register(SheetService::class, fn($c) => new SheetService(
    $c->query(ExpenseSheetRepository::class),
    $c->query(AccountRepository::class),
    $c->query(CurrentUser::class),
    $c->query(TransactionManager::class)
));

$container->register(TransactionService::class, fn($c) => new TransactionService(
    $c->query(ExpenseSheetRepository::class),
    $c->query(ExpenseTransactionRepository::class),
    $c->query(TransactionRevisionRepository::class),
    $c->query(CostCenterRepository::class),
    $c->query(ExpenseTypeRepository::class),
    $c->query(EvidenceRepository::class),
    $c->query(PrivateFileStore::class),
    $c->query(CurrentUser::class),
    $c->query(TransactionManager::class),
    $c->query(AuditWriter::class)
));

$container->register(EvidenceService::class, fn($c) => new EvidenceService(
    $c->query(PrivateFileStore::class),
    $c->query(EvidenceRepository::class),
    $c->query(CurrentUser::class),
    $c->query(AuditWriter::class),
    $c->query(UserDirectory::class),
    $c->query(IdempotencyService::class)
));

$container->register(SubmitSheet::class, fn($c) => new SubmitSheet(
    $c->query(TransactionManager::class),
    $c->query(ExpenseSheetRepository::class),
    $c->query(AuditWriter::class),
    $c->query(IdempotencyService::class),
    $c->query(CurrentUser::class),
    $c->query(CostCenterRepository::class),
    $c->query(ExpenseTransactionRepository::class),
    $c->query(RoleAssignmentRepository::class)
));

$container->register(ManagementReviewService::class, fn($c) => new ManagementReviewService(
    $c->query(TransactionManager::class),
    $c->query(ExpenseSheetRepository::class),
    $c->query(ExpenseTransactionRepository::class),
    $c->query(TransactionRevisionRepository::class),
    $c->query(CostCenterRepository::class),
    $c->query(RoleAssignmentRepository::class),
    $c->query(WorkflowDecisionRepository::class),
    $c->query(AuditWriter::class),
    $c->query(IdempotencyService::class),
    $c->query(CurrentUser::class),
    $c->query(UserDirectory::class),
    $c->query(AuthorizationService::class)
));

$container->register(FinanceSettlementService::class, fn($c) => new FinanceSettlementService(
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
));

// Helper to get container in tests
function getTestContainer(): TestContainer
{
    return TestContainerAccessor::getInstance();
}