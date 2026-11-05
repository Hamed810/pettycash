<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Application\Workflow\ManagementReviewService;
use OCA\PettyCash\Application\Workflow\SupersededDecisionAudit;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\ManagerTransactionState;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryWorkflowDecisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * B001–B003 and B005's service-level acceptance evidence.
 *
 * The manager-scoped read is a separate projection from `getCostCenterQueue()`: it is authorized by
 * the caller's active manager assignments, contains only current-revision transactions in this
 * manager's scope, and reports manager-specific progress. These tests pin the authorization and
 * workflow distinctions that FR-006-047/048 require, without touching the queue's separate
 * duplicate/pagination defect.
 */
final class ManagerSheetReviewTest extends TestCase
{
    private $container;

    protected function setUp(): void
    {
        $this->container = \TestContainerAccessor::getInstance();

        $this->container->query(ExpenseTransactionRepository::class)->clear();
        $this->container->query(ExpenseSheetRepository::class)->clear();
        $this->container->query(RoleAssignmentRepository::class)->clear();
        $this->container->query(AuditEventRepository::class)->clear();
        $this->container->query(\OCA\PettyCash\Application\Command\CommandResultRepository::class)->clear();

        $decisions = $this->container->query(WorkflowDecisionRepository::class);
        if ($decisions instanceof InMemoryWorkflowDecisionRepository) {
            $decisions->clear();
        }
    }

    protected function tearDown(): void
    {
        $this->container->setInstance(
            CurrentUser::class,
            new InMemoryCurrentUser('test-user')
        );
    }

    public function testB001ReturnsSheetMetadataForAManagedSheet(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('manager-1', 101);

        $sheet = $this->openSheet('sheet-managed', 'purchaser-1', 'Manager Sheet');
        $transaction = $this->addTransaction($sheet, 'tx-managed', 101, 'CC-100', 50000);
        $this->submit($sheet);

        $this->actAs('manager-1');
        $review = $this->review()->getCostCenterSheetForManager($sheet->publicId);

        self::assertNotNull($review, 'A manager assigned to the transaction cost centre must see the sheet');
        self::assertSame('sheet-managed', $review['sheet']->publicId);
        self::assertSame('Manager Sheet', $review['sheet']->title);
        self::assertSame('purchaser-1', $review['sheet']->ownerUid);
        self::assertSame(50000, $review['sheet']->submittedTotalMinor);
        self::assertNotNull($review['sheet']->firstSubmittedAt, 'firstSubmittedAt is the genuine submission date');
        self::assertSame(
            $transaction->id,
            $review['transactions'][0]['transaction']->id
        );
        self::assertSame(ManagerTransactionState::AwaitingManagerDecision, $review['transactions'][0]['state']);
        self::assertNull($review['transactions'][0]['decision']);
        self::assertSame(
            [
                'awaitingManager' => 1,
                'decidedByManager' => 0,
                'returnedForCorrection' => 0,
                'total' => 1,
                'managerComplete' => false,
            ],
            $review['progress']
        );
    }

    public function testB001UnknownAndOutOfScopeSheetsAreIndistinguishable(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->seedCostCenter(102, 'CC-200');
        $this->assignManager('manager-1', 101);

        $outOfScope = $this->openSheet('sheet-out-of-scope', 'purchaser-1', 'Outside My Scope');
        $this->addTransaction($outOfScope, 'tx-out-of-scope', 102, 'CC-200', 75000);
        $this->submit($outOfScope);

        $this->actAs('manager-1');

        self::assertNull(
            $this->review()->getCostCenterSheetForManager($outOfScope->publicId),
            'A sheet whose only transaction is outside the manager cost centre must not be returned'
        );
        self::assertNull(
            $this->review()->getCostCenterSheetForManager('00000000-0000-4000-8000-000000000000'),
            'An unknown sheet must answer the same null as an out-of-scope sheet'
        );
    }

    public function testB002KeepsDecidedByThisManagerDistinct(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('manager-1', 101);

        $sheet = $this->openSheet('sheet-decided', 'purchaser-1', 'Decided Sheet');
        $transaction = $this->addTransaction($sheet, 'tx-decided', 101, 'CC-100', 60000);
        $this->submit($sheet);

        $this->actAs('manager-1');
        $this->review()->recordDecision(
            $transaction->id,
            WorkflowStage::CostCenter,
            Decision::Approve,
            'Approved',
            'cmd-decided-' . Uuid::random(),
            0
        );

        $review = $this->review()->getCostCenterSheetForManager($sheet->publicId);

        self::assertNotNull($review);
        self::assertSame(ManagerTransactionState::DecidedByManager, $review['transactions'][0]['state']);
        self::assertNotNull($review['transactions'][0]['decision']);
        self::assertSame(Decision::Approve, $review['transactions'][0]['decision']->decision);
        self::assertSame('manager-1', $review['transactions'][0]['decision']->actorUid);
        self::assertSame(1, $review['progress']['decidedByManager']);
        self::assertSame(0, $review['progress']['awaitingManager']);
        self::assertTrue($review['progress']['managerComplete']);
    }

    public function testB002KeepsReturnedForCorrectionDistinct(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('manager-1', 101);

        $sheet = $this->openSheet('sheet-returned', 'purchaser-1', 'Returned Sheet');
        $transaction = $this->addTransaction($sheet, 'tx-returned', 101, 'CC-100', 65000);
        $this->submit($sheet);

        $this->actAs('manager-1');
        $this->review()->recordDecision(
            $transaction->id,
            WorkflowStage::CostCenter,
            Decision::Return,
            'Missing receipt',
            'cmd-returned-' . Uuid::random(),
            0
        );

        $review = $this->review()->getCostCenterSheetForManager($sheet->publicId);

        self::assertNotNull($review);
        self::assertSame(ManagerTransactionState::ReturnedForCorrection, $review['transactions'][0]['state']);
        self::assertSame(Decision::Return, $review['transactions'][0]['decision']->decision);
        self::assertSame(1, $review['progress']['returnedForCorrection']);
        self::assertSame(0, $review['progress']['decidedByManager']);
        self::assertTrue($review['progress']['managerComplete']);
    }

    public function testB002ExcludesAnotherManagersDecisionAndTransactions(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('manager-1', 101);
        $this->assignManager('manager-2', 101);

        $sheet = $this->openSheet('sheet-two-managers', 'purchaser-1', 'Two Managers');
        $first = $this->addTransaction($sheet, 'tx-first-manager', 101, 'CC-100', 50000);
        $second = $this->addTransaction($sheet, 'tx-second-manager', 101, 'CC-100', 51000);
        $this->submit($sheet);

        $this->actAs('manager-1');
        $this->review()->recordDecision(
            $first->id,
            WorkflowStage::CostCenter,
            Decision::Approve,
            'Approved by the first manager',
            'cmd-first-' . Uuid::random(),
            0
        );

        $this->actAs('manager-2');
        $review = $this->review()->getCostCenterSheetForManager($sheet->publicId);

        self::assertNotNull($review);
        self::assertCount(1, $review['transactions'], 'Another manager decision must not be disclosed');
        self::assertSame($second->id, $review['transactions'][0]['transaction']->id);
        self::assertSame(ManagerTransactionState::AwaitingManagerDecision, $review['transactions'][0]['state']);
        self::assertSame(1, $review['progress']['awaitingManager']);
        self::assertSame(0, $review['progress']['decidedByManager']);
        self::assertFalse($review['progress']['managerComplete']);
    }

    public function testB003ManagerCompleteDoesNotClaimWholeSheetCompletion(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->seedCostCenter(102, 'CC-200');
        $this->assignManager('manager-1', 101);

        $sheet = $this->openSheet('sheet-partial-scope', 'purchaser-1', 'Partially Managed');
        $managed = $this->addTransaction($sheet, 'tx-managed-side', 101, 'CC-100', 50000);
        $outside = $this->addTransaction($sheet, 'tx-outside-side', 102, 'CC-200', 70000);
        $this->submit($sheet);

        $this->actAs('manager-1');
        $before = $this->review()->getCostCenterSheetForManager($sheet->publicId);
        self::assertNotNull($before);
        self::assertFalse($before['progress']['managerComplete']);

        $this->review()->recordDecision(
            $managed->id,
            WorkflowStage::CostCenter,
            Decision::Approve,
            'Approved',
            'cmd-partial-' . Uuid::random(),
            0
        );

        $after = $this->review()->getCostCenterSheetForManager($sheet->publicId);

        self::assertNotNull($after);
        self::assertCount(1, $after['transactions']);
        self::assertSame($managed->id, $after['transactions'][0]['transaction']->id);
        self::assertNull(
            $this->findTransaction($after['transactions'], $outside->id),
            'A transaction in a cost centre this manager does not manage must not be exposed'
        );
        self::assertSame(1, $after['progress']['total']);
        self::assertSame(1, $after['progress']['decidedByManager']);
        self::assertSame(0, $after['progress']['awaitingManager']);
        self::assertTrue(
            $after['progress']['managerComplete'],
            'No transaction awaits this manager, even though the sheet still has one outside their scope'
        );
    }

    public function testB003HistoricalDecisionOnASupersededRevisionDoesNotCount(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('manager-1', 101);

        $sheet = $this->openSheet('sheet-revised', 'purchaser-1', 'Revised Sheet');
        $transaction = $this->addTransaction($sheet, 'tx-revised', 101, 'CC-100', 50000, 1);
        $this->submit($sheet);

        $submittedAt = $sheet->firstSubmittedAt;

        $historical = WorkflowDecision::create(
            (string) Uuid::random(),
            $transaction->id,
            $transaction->revision()->id(),
            WorkflowStage::CostCenter,
            Decision::Approve,
            null,
            'cmd-historical-' . Uuid::random()
        );
        $historical->actorUid = 'manager-1';
        $historical->decidedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->container->query(WorkflowDecisionRepository::class)->save($historical);

        $revision = TransactionRevision::draft(
            'tx-revised-rev-2',
            2,
            new DateTimeImmutable('2026-09-21', new DateTimeZone('UTC')),
            Money::positive(90000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('Corrected description', 'CC-100', 'CC-100', 'CC-100', 101, 'Office Supplies', 1, true);
        $transaction->activate($revision);
        $this->container->query(ExpenseTransactionRepository::class)->save($transaction);

        $this->actAs('manager-1');
        $review = $this->review()->getCostCenterSheetForManager($sheet->publicId);

        self::assertNotNull($review);
        self::assertSame(ManagerTransactionState::AwaitingManagerDecision, $review['transactions'][0]['state']);
        self::assertSame(1, $review['progress']['awaitingManager']);
        self::assertSame(0, $review['progress']['decidedByManager']);
        self::assertSame(
            50000,
            $review['sheet']->submittedTotalMinor,
            'The submitted total is the first-submission snapshot, not the corrected revision amount'
        );
        self::assertSame($submittedAt, $review['sheet']->firstSubmittedAt);
    }

    private function openSheet(string $publicId, string $ownerUid, string $title): ExpenseSheet
    {
        $sheet = ExpenseSheet::open(
            $publicId,
            $ownerUid,
            'account-1',
            CurrencyCode::fromString('IRR'),
            $title
        );
        $sheet->publicId = $publicId;
        $this->container->query(ExpenseSheetRepository::class)->save($sheet);

        return $sheet;
    }

    private function addTransaction(
        ExpenseSheet $sheet,
        string $publicId,
        int $costCenterDbId,
        string $costCenterCode,
        int $amountMinor,
        int $revisionNumber = 1
    ): ExpenseTransaction {
        $revision = TransactionRevision::draft(
            $publicId . '-rev-' . $revisionNumber,
            $revisionNumber,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive($amountMinor, CurrencyCode::fromString('IRR'))
        );
        $revision->complete(
            'Description for ' . $publicId,
            $costCenterCode,
            $costCenterCode,
            $costCenterCode . ' name',
            $costCenterDbId,
            'Office Supplies',
            1,
            true
        );

        $transaction = new ExpenseTransaction($publicId, $sheet->ownerUid, $revision, $sheet->dbId);
        $this->container->query(ExpenseTransactionRepository::class)->save($transaction);

        $sheet->addTransaction($transaction);

        return $transaction;
    }

    private function submit(ExpenseSheet $sheet): void
    {
        $sheet->submit();
        $this->container->query(ExpenseSheetRepository::class)->save($sheet);
    }

    private function seedCostCenter(int $id, string $code): void
    {
        $costCenter = CostCenter::create(
            $code,
            $code . ' name',
            'Test cost centre',
            new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
            null
        );
        $costCenter->id = $id;
        $this->container->query(CostCenterRepository::class)->save($costCenter);
    }

    private function assignManager(string $uid, int $costCenterId): void
    {
        $this->container->query(RoleAssignmentRepository::class)->save(
            RoleAssignment::create(
                $uid,
                Role::CostCenterManager,
                $costCenterId,
                new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
                null
            )
        );
    }

    private function actAs(string $uid): void
    {
        $this->container->setInstance(CurrentUser::class, new InMemoryCurrentUser($uid));
    }

    private function review(): ManagementReviewService
    {
        return new ManagementReviewService(
            $this->container->query(TransactionManager::class),
            $this->container->query(ExpenseSheetRepository::class),
            $this->container->query(ExpenseTransactionRepository::class),
            $this->container->query(TransactionRevisionRepository::class),
            $this->container->query(CostCenterRepository::class),
            $this->container->query(RoleAssignmentRepository::class),
            $this->container->query(WorkflowDecisionRepository::class),
            $this->container->query(AuditWriter::class),
            $this->container->query(IdempotencyService::class),
            $this->container->query(CurrentUser::class),
            $this->container->query(UserDirectory::class),
            $this->container->query(AuthorizationService::class),
            $this->container->query(SupersededDecisionAudit::class)
        );
    }

    /** @param list<array{transaction: ExpenseTransaction}> $transactions */
    private function findTransaction(array $transactions, string $publicId): ?ExpenseTransaction
    {
        foreach ($transactions as $item) {
            if ($item['transaction']->id === $publicId) {
                return $item['transaction'];
            }
        }

        return null;
    }
}
