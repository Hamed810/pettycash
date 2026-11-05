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
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryWorkflowDecisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Regression evidence for the duplicated management queues.
 *
 * The original fixture gave the manager two active assignments to the same cost centre. The queue
 * looped the role list, re-read every assignment for every manager role entry, fetched each cost
 * centre once per list entry, and finally applied the page offset twice (per cost centre and after
 * the merge). The result was 144 rows for 72 transactions, a total that doubled the real amount,
 * and pages that repeated some rows while skipping others.
 *
 * These tests reproduce the overlapping-assignment and multi-cost-centre shapes directly against
 * the application service, then assert the properties the queue must hold: unique IDs, a unique
 * total, complete pages, stable ordering, no leakage outside the manager scope, and agreement with
 * the manager-scoped sheet-summary read introduced in Batch 1.
 */
final class ManagementQueuePaginationTest extends TestCase
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

    public function testCostCenterQueueReturnsEachTransactionOnceWithOverlappingAssignments(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->seedCostCenter(102, 'CC-200');
        $this->seedCostCenter(103, 'CC-300');

        // The original defect: two active assignments for the same cost centre plus a second
        // managed cost centre. The role list and the cost-centre ID list both used to duplicate.
        $this->assignManager('manager-1', 101, '2026-01-01');
        $this->assignManager('manager-1', 101, '2026-02-01');
        $this->assignManager('manager-1', 102, '2026-01-01');

        $sheet = $this->openSheet('sheet-overlap', 'purchaser-1', 'Overlapping Assignments');
        $first = $this->addTransaction($sheet, 'tx-101-a', 101, 'CC-100', 1000, '2026-09-01T10:00:00+00:00');
        $second = $this->addTransaction($sheet, 'tx-101-b', 101, 'CC-100', 2000, '2026-09-02T10:00:00+00:00');
        $third = $this->addTransaction($sheet, 'tx-102-a', 102, 'CC-200', 3000, '2026-09-03T10:00:00+00:00');
        $outside = $this->addTransaction($sheet, 'tx-103-a', 103, 'CC-300', 4000, '2026-09-04T10:00:00+00:00');
        $this->submit($sheet);

        $this->actAs('manager-1');
        $queue = $this->review()->getCostCenterQueue(1, 25);
        $ids = $this->ids($queue['items']);

        self::assertSame(3, $queue['total'], 'The total must count the unique authorized set, not assignments');
        self::assertCount(3, $queue['items']);
        self::assertSame([$third->id, $second->id, $first->id], $ids);
        self::assertCount(3, array_unique($ids), 'Every transaction must appear exactly once');
        self::assertNotContains($outside->id, $ids, 'A cost centre outside the manager scope must not leak');
    }

    public function testCostCenterQueuePaginatesTheUniqueSetWithoutGapsOrOverlap(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->seedCostCenter(102, 'CC-200');
        $this->assignManager('manager-1', 101, '2026-01-01');
        $this->assignManager('manager-1', 101, '2026-02-01');
        $this->assignManager('manager-1', 102, '2026-01-01');

        $firstSheet = $this->openSheet('sheet-pages-a', 'purchaser-1', 'Paged Cost Centre One');
        $secondSheet = $this->openSheet('sheet-pages-b', 'purchaser-1', 'Paged Cost Centre Two');

        $expectedOrder = [];
        $dateTimes = [
            '2026-09-10T12:00:00+00:00',
            '2026-09-10T12:01:00+00:00',
            '2026-09-10T12:02:00+00:00',
            // Two rows share an instant: the surrogate-key tiebreaker must keep the order stable.
            '2026-09-10T12:03:00+00:00',
            '2026-09-10T12:03:00+00:00',
            '2026-09-10T12:05:00+00:00',
            '2026-09-10T12:06:00+00:00',
        ];

        foreach ($dateTimes as $index => $dateTime) {
            $sheet = $index < 4 ? $firstSheet : $secondSheet;
            $costCenterId = $index < 4 ? 101 : 102;
            $costCenterCode = $index < 4 ? 'CC-100' : 'CC-200';
            $expectedOrder[] = $this->addTransaction(
                $sheet,
                'tx-page-' . $index,
                $costCenterId,
                $costCenterCode,
                1000 + $index,
                $dateTime
            )->id;
        }
        $this->submit($firstSheet);
        $this->submit($secondSheet);

        $this->actAs('manager-1');

        $pages = [];
        $totals = [];
        for ($page = 1; $page <= 3; $page++) {
            $result = $this->review()->getCostCenterQueue($page, 3);
            $pages[$page] = $this->ids($result['items']);
            $totals[$page] = $result['total'];
        }

        self::assertSame([7, 7, 7], array_values($totals), 'Every page must advertise the unique total');
        self::assertCount(3, $pages[1]);
        self::assertCount(3, $pages[2]);
        self::assertCount(1, $pages[3]);

        $collected = array_merge($pages[1], $pages[2], $pages[3]);
        self::assertCount(7, array_unique($collected), 'Pages must not overlap');
        self::assertEqualsCanonicalizing($expectedOrder, $collected, 'Pages must not omit a transaction');

        // Newest first, with the later persisted row first when two share the same second.
        self::assertSame(
            [
                'tx-page-6',
                'tx-page-5',
                'tx-page-4',
                'tx-page-3',
                'tx-page-2',
                'tx-page-1',
                'tx-page-0',
            ],
            $collected
        );

        $again = $this->ids($this->review()->getCostCenterQueue(1, 3)['items']);
        self::assertSame($pages[1], $again, 'Re-reading a page must return the same order');
    }

    public function testSeniorQueueUsesTheSameUniqueScopedPagination(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->seedCostCenter(102, 'CC-200');
        $this->seedCostCenter(103, 'CC-300');
        $this->assignManager('senior-1', 101, '2026-01-01', Role::SeniorManager);
        $this->assignManager('senior-1', 101, '2026-02-01', Role::SeniorManager);
        $this->assignManager('senior-1', 102, '2026-01-01', Role::SeniorManager);

        $managedSheet = $this->openSheet('sheet-senior', 'purchaser-1', 'Senior Scope');
        $outsideSheet = $this->openSheet('sheet-senior-outside', 'purchaser-1', 'Outside Senior Scope');

        $expected = [];
        for ($index = 0; $index < 5; $index++) {
            $expected[] = $this->addTransaction(
                $managedSheet,
                'tx-senior-' . $index,
                $index < 3 ? 101 : 102,
                $index < 3 ? 'CC-100' : 'CC-200',
                5000 + $index,
                '2026-09-11T12:0' . $index . ':00+00:00'
            )->id;
        }
        $outside = $this->addTransaction(
            $outsideSheet,
            'tx-senior-outside',
            103,
            'CC-300',
            9000,
            '2026-09-11T12:09:00+00:00'
        );

        $this->submit($managedSheet);
        $this->submit($outsideSheet);
        foreach ($managedSheet->transactions() as $transaction) {
            $transaction->stage = WorkflowStage::Senior;
            $transaction->status = TransactionStatus::AwaitingSeniorReview;
        }
        $outside->stage = WorkflowStage::Senior;
        $outside->status = TransactionStatus::AwaitingSeniorReview;
        $this->container->query(ExpenseTransactionRepository::class)->save($outside);

        $this->actAs('senior-1');

        $pages = [];
        $totals = [];
        for ($page = 1; $page <= 3; $page++) {
            $result = $this->review()->getSeniorQueue($page, 2);
            $pages[$page] = $this->ids($result['items']);
            $totals[$page] = $result['total'];
        }

        self::assertSame([5, 5, 5], array_values($totals));
        self::assertCount(2, $pages[1]);
        self::assertCount(2, $pages[2]);
        self::assertCount(1, $pages[3]);

        $collected = array_merge($pages[1], $pages[2], $pages[3]);
        self::assertCount(5, array_unique($collected));
        self::assertEqualsCanonicalizing($expected, $collected);
        self::assertNotContains($outside->id, $collected, 'A senior manager must not see an unassigned cost centre');
    }

    public function testManagerScopedSheetSummaryAgreesWithTheDeduplicatedQueue(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->seedCostCenter(102, 'CC-200');
        $this->seedCostCenter(103, 'CC-300');
        $this->assignManager('manager-1', 101, '2026-01-01');
        $this->assignManager('manager-1', 101, '2026-02-01');
        $this->assignManager('manager-1', 102, '2026-01-01');

        $sheet = $this->openSheet('sheet-summary-queue', 'purchaser-1', 'Summary And Queue');
        $first = $this->addTransaction($sheet, 'tx-summary-101', 101, 'CC-100', 1000, '2026-09-12T10:00:00+00:00');
        $second = $this->addTransaction($sheet, 'tx-summary-102', 102, 'CC-200', 2000, '2026-09-12T11:00:00+00:00');
        $outside = $this->addTransaction($sheet, 'tx-summary-103', 103, 'CC-300', 3000, '2026-09-12T12:00:00+00:00');
        $this->submit($sheet);

        $this->actAs('manager-1');

        $review = $this->review()->getCostCenterSheetForManager($sheet->publicId);
        self::assertNotNull($review);
        self::assertSame(2, $review['progress']['total']);
        self::assertEqualsCanonicalizing(
            [$second->id, $first->id],
            $this->ids(array_column($review['transactions'], 'transaction'))
        );
        self::assertNotContains($outside->id, $this->ids(array_column($review['transactions'], 'transaction')));

        $queue = $this->review()->getCostCenterQueue(1, 25);
        self::assertSame(2, $queue['total']);
        self::assertSame([$second->id, $first->id], $this->ids($queue['items']));
        self::assertEqualsCanonicalizing(
            $this->ids(array_column($review['transactions'], 'transaction')),
            $this->ids($queue['items']),
            'The queue and the manager-scoped sheet summary must expose the same authorized set'
        );
    }

    /** @param list<ExpenseTransaction> $transactions @return list<string> */
    private function ids(array $transactions): array
    {
        return array_map(static fn(ExpenseTransaction $transaction): string => $transaction->id, $transactions);
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
        string $createdAt
    ): ExpenseTransaction {
        $revision = TransactionRevision::draft(
            $publicId . '-rev-1',
            1,
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

        $transaction = new ExpenseTransaction(
            $publicId,
            $sheet->ownerUid,
            $revision,
            $sheet->dbId,
            new DateTimeImmutable($createdAt)
        );
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

    private function assignManager(
        string $uid,
        int $costCenterId,
        string $validFrom,
        Role $role = Role::CostCenterManager
    ): void {
        $this->container->query(RoleAssignmentRepository::class)->save(
            RoleAssignment::create(
                $uid,
                $role,
                $costCenterId,
                new DateTimeImmutable($validFrom, new DateTimeZone('UTC')),
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
}
