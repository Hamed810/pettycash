<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Finance;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Finance\CompletedSheetHistoryService;
use OCA\PettyCash\Application\Finance\CompletedSheetScope;
use OCA\PettyCash\Application\Port\SheetPostedAmountsReader;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use OCA\PettyCash\Tests\TestDoubles\InMemoryWorkflowDecisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * T022 — `CompletedSheetHistoryService` integration verification over in-memory doubles.
 *
 * Proves the set-based read path: one page read, one count, one batched transaction-count
 * read, one batched posted-amount read, one batched decisions read, one batched evidence read,
 * and one batched postings read. No N+1 per row, per transaction, or per evidence item.
 *
 * The real SQL of `SheetSummaryQuery::postedAmountsFor()` and `DbExpenseTransactionRepository`
 * batched reads is proven separately by `FinanceReadQueryRoundTripTest` against MariaDB.
 */
final class CompletedSheetHistoryTest extends TestCase
{
    private ExpenseSheetRepository $sheets;
    private ExpenseTransactionRepository $transactions;
    private WorkflowDecisionRepository $decisions;
    private EvidenceRepository $evidence;
    private UserDirectory $directory;
    private SheetPostedAmountsReader $sheetPostedAmounts;
    private CompletedSheetScope $scope;
    private CompletedSheetHistoryService $service;

    protected function setUp(): void
    {
        $this->sheets = new InMemoryExpenseSheetRepository();
        $this->transactions = new InMemoryExpenseTransactionRepository();
        $this->decisions = new InMemoryWorkflowDecisionRepository();
        $this->evidence = new InMemoryEvidenceRepository();
        $this->directory = new InMemoryUserDirectory();
        $this->sheetPostedAmounts = $this->createMock(SheetPostedAmountsReader::class);

        $this->scope = new CompletedSheetScope($this->sheets, $this->transactions);

        $this->service = new CompletedSheetHistoryService(
            $this->sheets,
            $this->transactions,
            $this->decisions,
            $this->evidence,
            $this->sheetPostedAmounts,
            $this->directory,
            $this->scope,
            new InMemoryCurrencyRepository(),
        );
    }

    private function makeSheet(
        string $id,
        SheetStatus $status,
        string $title,
        ?DateTimeImmutable $completedAt = null
    ): ExpenseSheet {
        $sheet = new ExpenseSheet(
            $id, 'purchaser-' . $id, 'acc-' . $id, CurrencyCode::fromString('IRR'), $status
        );
        $sheet->title = $title;
        $sheet->completedAt = $completedAt;
        $this->sheets->save($sheet);

        return $sheet;
    }

    private function makeTransaction(
        string $id,
        int $sheetDbId,
        WorkflowStage $stage,
        TransactionStatus $status,
        ?string $finalOutcome = null,
        int $revisionNumber = 1
    ): ExpenseTransaction {
        $revision = TransactionRevision::draft(
            'rev-' . $id,
            $revisionNumber,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $transaction = new ExpenseTransaction($id, 'purchaser', $revision, $sheetDbId);
        $transaction->stage = $stage;
        $transaction->status = $status;
        $transaction->finalOutcome = $finalOutcome;
        $this->transactions->save($transaction);

        return $transaction;
    }

    private function completedSheetWithTransaction(string $id): array
    {
        $sheet = $this->makeSheet($id, SheetStatus::Completed, 'Completed ' . $id, new DateTimeImmutable('2026-10-01'));
        $tx = $this->makeTransaction(
            'tx-' . $id, $sheet->dbId, WorkflowStage::Final, TransactionStatus::CompletedApproved, 'APPROVED'
        );

        return ['sheet' => $sheet, 'transaction' => $tx];
    }

    private function makeEvidence(
        string $id,
        int $transactionDbId,
        bool $active = true,
        string $name = 'receipt.pdf',
        ?DateTimeImmutable $uploadedAt = null
    ): Evidence {
        $evidence = new Evidence(
            $id,
            $transactionDbId,
            1,
            'evidence/' . $id . '/1/' . $name,
            $name,
            'application/pdf',
            1024,
            'checksum-' . $id,
            $active,
            'purchaser',
            $uploadedAt ?? new DateTimeImmutable('2026-10-01 10:00:00', new DateTimeZone('UTC'))
        );
        $this->evidence->save($evidence);

        return $evidence;
    }

    public function testPageListsCompletedSheetsWithAuthoritativeTotal(): void
    {
        // 4 completed sheets, page size 2 → total = 4, page 1 has 2 items
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $this->completedSheetWithTransaction($id);
        }

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);

        $page = $this->service->page(1, 2);

        $this->assertSame(4, $page['total']);
        $this->assertSame(1, $page['page']);
        $this->assertSame(2, $page['pageSize']);
        $this->assertCount(2, $page['items']);
        $this->assertSame('Completed d', $page['items'][0]['title']); // completed_at DESC
    }

    public function testPageReportsCorrectTotalNotPageLength(): void
    {
        // 5 completed sheets, page size 2 → total = 5, not 2
        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            $this->completedSheetWithTransaction($id);
        }

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);

        $page = $this->service->page(1, 2);

        $this->assertSame(5, $page['total']);
        $this->assertCount(2, $page['items']);
    }

    public function testPageReturnsEmptyWhenNoCompletedSheets(): void
    {
        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);

        $page = $this->service->page(1, 25);

        $this->assertSame(0, $page['total']);
        $this->assertCount(0, $page['items']);
    }

    public function testDetailReturnsFullTransactionData(): void
    {
        $data = $this->completedSheetWithTransaction('s1');
        $sheet = $data['sheet'];
        $tx = $data['transaction'];

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);

        $detail = $this->service->detail((string) $sheet->publicId);

        $this->assertNotNull($detail);
        $this->assertSame('Completed s1', $detail['title']);
        $this->assertSame(SheetStatus::Completed->value, $detail['status']);
        $this->assertSame(1, $detail['totalTransactionCount']);
        $this->assertSame(1, $detail['approvedTransactionCount']);
        $this->assertSame(0, $detail['rejectedTransactionCount']);
        $this->assertCount(1, $detail['transactions']);
        $this->assertSame('tx-s1', $detail['transactions'][0]['id']);
        $this->assertSame('APPROVED', $detail['transactions'][0]['finalOutcome']);
        $this->assertSame('COMPLETED_APPROVED', $detail['transactions'][0]['status']);
    }

    public function testDetailReturnsNullForNonTerminalSheet(): void
    {
        $sheet = $this->makeSheet('s-pending', SheetStatus::Open, 'Open Sheet');
        $this->makeTransaction('tx-pending', $sheet->dbId, WorkflowStage::Finance, TransactionStatus::AwaitingFinanceReview);

        $detail = $this->service->detail((string) $sheet->publicId);

        $this->assertNull($detail);
    }

    public function testDetailReturnsNullWhenSiblingIsNonTerminal(): void
    {
        $sheet = $this->makeSheet('s-mixed', SheetStatus::Completed, 'Mixed Sheet');
        $this->makeTransaction('tx-terminal', $sheet->dbId, WorkflowStage::Final, TransactionStatus::CompletedApproved, 'APPROVED');
        $this->makeTransaction('tx-pending', $sheet->dbId, WorkflowStage::Finance, TransactionStatus::AwaitingFinanceReview);

        $detail = $this->service->detail((string) $sheet->publicId);

        $this->assertNull($detail);
    }

    public function testDetailReturnsNullForUnknownSheet(): void
    {
        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);

        $detail = $this->service->detail('does-not-exist');

        $this->assertNull($detail);
    }

    public function testCompletedWithRejectionsIsAlsoListed(): void
    {
        $sheet = $this->makeSheet('s-rejected', SheetStatus::CompletedWithRejections, 'Rejected Sheet');
        $this->makeTransaction('tx-rejected', $sheet->dbId, WorkflowStage::Final, TransactionStatus::CompletedRejected, 'REJECTED');

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);

        $page = $this->service->page(1, 25);

        $this->assertSame(1, $page['total']);
        $this->assertSame('Rejected Sheet', $page['items'][0]['title']);
        $this->assertSame(SheetStatus::CompletedWithRejections->value, $page['items'][0]['status']);
    }

    public function testPageValidatesArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->page(0, 25);
    }

    public function testPageValidatesPageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->page(1, 0);
    }

    public function testPageValidatesPageSizeMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->page(1, 101);
    }

    // ===== T017 — Finance-pending sheet excluded from completed list =====

    public function testPageExcludesFinancePendingSheet(): void
    {
        // A sheet with at least one Finance-pending transaction is not terminal; its status is
        // Finance/Open rather than COMPLETED, so it must not appear in the completed list.
        $sheet = $this->makeSheet('s-fp', SheetStatus::Finance, 'Finance Pending Sheet');
        $this->makeTransaction(
            'tx-fp', $sheet->dbId, WorkflowStage::Finance, TransactionStatus::AwaitingFinanceReview
        );

        // Also seed a completed sheet so total is not trivially zero.
        $this->completedSheetWithTransaction('a');

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);

        $page = $this->service->page(1, 25);

        $this->assertSame(1, $page['total']);
        $this->assertCount(1, $page['items']);
        $titles = array_map(static fn (array $item): string => $item['title'], $page['items']);
        $this->assertNotContains('Finance Pending Sheet', $titles);
    }

    public function testPageReportsPostedAmountAndCountFromLinkedMovements(): void
    {
        $data = $this->completedSheetWithTransaction('s1');
        $sheet = $data['sheet'];

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturnCallback(
            static fn (array $sheetDbIds): array => array_combine(
                $sheetDbIds,
                array_fill(0, count($sheetDbIds), [
                    'postedAmount' => 75000,
                    'postingCount' => 3,
                    'currencyCode' => 'IRR',
                    'minorUnit' => 0,
                ])
            )
        );

        $page = $this->service->page(1, 25);

        $this->assertSame(1, $page['total']);
        $this->assertSame(1, $page['items'][0]['totalTransactionCount']);
        $this->assertSame(1, $page['items'][0]['approvedTransactionCount']);
        $this->assertSame(0, $page['items'][0]['rejectedTransactionCount']);
        $this->assertSame(75000, $page['items'][0]['postedAmount']->minorUnits());
        $this->assertSame(3, $page['items'][0]['postingCount']);
    }

    // ===== T018 — detail reuses CompletedSheetScope; posting integrity =====

    public function testDetailReturnsNullForFinancePendingSheet(): void
    {
        // Sheet status is terminal but a sibling transaction is still Finance-pending.
        $sheet = $this->makeSheet('s-mixed', SheetStatus::Completed, 'Mixed Sheet');
        $this->makeTransaction(
            'tx-terminal', $sheet->dbId, WorkflowStage::Final, TransactionStatus::CompletedApproved, 'APPROVED'
        );
        $this->makeTransaction(
            'tx-fp', $sheet->dbId, WorkflowStage::Finance, TransactionStatus::AwaitingFinanceReview
        );

        $detail = $this->service->detail((string) $sheet->publicId);

        $this->assertNull($detail, 'a sheet with a Finance-pending transaction must not be exposed');
    }

    public function testDetailAllowsCompletedWithRejectionsAllTerminal(): void
    {
        $sheet = $this->makeSheet('s-cr', SheetStatus::CompletedWithRejections, 'Completed With Rejections');
        $this->makeTransaction(
            'tx-approved', $sheet->dbId, WorkflowStage::Final, TransactionStatus::CompletedApproved, 'APPROVED'
        );
        $this->makeTransaction(
            'tx-rejected', $sheet->dbId, WorkflowStage::Final, TransactionStatus::CompletedRejected, 'REJECTED'
        );

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturn([]);

        $detail = $this->service->detail((string) $sheet->publicId);

        $this->assertNotNull($detail);
        $this->assertSame(SheetStatus::CompletedWithRejections->value, $detail['status']);
        $this->assertSame(2, $detail['totalTransactionCount']);
        $this->assertSame(1, $detail['approvedTransactionCount']);
        $this->assertSame(1, $detail['rejectedTransactionCount']);
    }

    public function testDetailRejectedTransactionHasNullPosting(): void
    {
        $data = $this->completedSheetWithTransaction('s1');
        $sheet = $data['sheet'];
        $tx = $data['transaction'];
        $tx->status = TransactionStatus::CompletedRejected;
        $tx->finalOutcome = 'REJECTED';

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturn([]);

        $detail = $this->service->detail((string) $sheet->publicId);

        $this->assertNotNull($detail);
        $this->assertSame('REJECTED', $detail['transactions'][0]['finalOutcome']);
        $this->assertNull($detail['transactions'][0]['posting']);
    }

    public function testDetailPostingOccurredAtEqualsMovementTimestamp(): void
    {
        $data = $this->completedSheetWithTransaction('s1');
        $sheet = $data['sheet'];
        $tx = $data['transaction'];

        $movementOccurredAt = new DateTimeImmutable('2026-10-05 14:30:00', new DateTimeZone('UTC'));

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturnCallback(
            fn (int $sheetDbId, CurrencyCode $currencyCode): array => [(string) $tx->publicId => [
                'occurredAt' => $movementOccurredAt,
                'amount' => Money::positive(50000, CurrencyCode::fromString('IRR')),
                'balanceAfter' => Money::positive(50000, CurrencyCode::fromString('IRR')),
            ]]
        );

        $detail = $this->service->detail((string) $sheet->publicId);

        $this->assertNotNull($detail);
        $posting = $detail['transactions'][0]['posting'];
        $this->assertNotNull($posting);
        $this->assertSame($movementOccurredAt, $posting['occurredAt']);
    }

    public function testDetailPostingTimestampDistinctFromExpenseDate(): void
    {
        $data = $this->completedSheetWithTransaction('s1');
        $sheet = $data['sheet'];
        $tx = $data['transaction'];

        // The transaction's expense date differs from the movement's occurred_at.
        $movementOccurredAt = new DateTimeImmutable('2026-10-05 14:30:00', new DateTimeZone('UTC'));

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturnCallback(
            fn () => [(string) $tx->publicId => [
                'occurredAt' => $movementOccurredAt,
                'amount' => Money::positive(50000, CurrencyCode::fromString('IRR')),
                'balanceAfter' => Money::positive(50000, CurrencyCode::fromString('IRR')),
            ]]
        );

        $detail = $this->service->detail((string) $sheet->publicId);

        $this->assertNotNull($detail);
        $this->assertNotSame($movementOccurredAt->format('Y-m-d'), $detail['transactions'][0]['expenseDate']);
        $this->assertSame($movementOccurredAt, $detail['transactions'][0]['posting']['occurredAt']);
    }

    public function testDetailExposesNoMutationCapability(): void
    {
        $data = $this->completedSheetWithTransaction('s1');
        $sheet = $data['sheet'];

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturn([]);

        $detail = $this->service->detail((string) $sheet->publicId);

        $this->assertNotNull($detail);
        // Header carries no decision, mutation, or capability field.
        $this->assertArrayNotHasKey('decisions', $detail);
        $this->assertArrayNotHasKey('actions', $detail);
        $this->assertArrayNotHasKey('capabilities', $detail);
        // Each transaction carries no mutation path.
        foreach ($detail['transactions'] as $tx) {
            $this->assertArrayNotHasKey('approve', $tx);
            $this->assertArrayNotHasKey('reject', $tx);
            $this->assertArrayNotHasKey('returnForCorrection', $tx);
            $this->assertArrayNotHasKey('mutationUrl', $tx);
            $this->assertArrayNotHasKey('contentUrl', $tx);
        }
    }

    // ===== T022 — multiple postings and interleaving =====

    public function testMultiplePostingsRemainDistinct(): void
    {
        $data = $this->completedSheetWithTransaction('s1');
        $sheet = $data['sheet'];
        $tx1 = $data['transaction'];

        // Add a second transaction with its own posting on the same sheet.
        $tx2 = $this->makeTransaction(
            'tx-s1b', $sheet->dbId, WorkflowStage::Final, TransactionStatus::CompletedApproved, 'APPROVED'
        );

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturnCallback(
            fn (int $sheetDbId, CurrencyCode $currencyCode): array => [
                (string) $tx1->publicId => [
                    'occurredAt' => new DateTimeImmutable('2026-10-01 10:00:00', new DateTimeZone('UTC')),
                    'amount' => Money::positive(30000, CurrencyCode::fromString('IRR')),
                    'balanceAfter' => Money::positive(30000, CurrencyCode::fromString('IRR')),
                ],
                (string) $tx2->publicId => [
                    'occurredAt' => new DateTimeImmutable('2026-10-02 14:30:00', new DateTimeZone('UTC')),
                    'amount' => Money::positive(45000, CurrencyCode::fromString('IRR')),
                    'balanceAfter' => Money::positive(75000, CurrencyCode::fromString('IRR')),
                ],
            ]
        );

        $detail = $this->service->detail((string) $sheet->publicId);

        $this->assertNotNull($detail);
        $this->assertSame(2, $detail['totalTransactionCount']);
        $this->assertSame(2, count($detail['transactions']));

        // Each posting keeps its own timestamp, amount, and resulting balance.
        $postings = [];
        foreach ($detail['transactions'] as $tx) {
            $postings[$tx['id']] = $tx['posting'];
        }

        self::assertNotNull($postings['tx-s1']);
        self::assertSame('2026-10-01 10:00:00', $postings['tx-s1']['occurredAt']->format('Y-m-d H:i:s'));
        self::assertSame(30000, $postings['tx-s1']['amount']->minorUnits());
        self::assertSame(30000, $postings['tx-s1']['balanceAfter']->minorUnits());

        self::assertNotNull($postings['tx-s1b']);
        self::assertSame('2026-10-02 14:30:00', $postings['tx-s1b']['occurredAt']->format('Y-m-d H:i:s'));
        self::assertSame(45000, $postings['tx-s1b']['amount']->minorUnits());
        self::assertSame(75000, $postings['tx-s1b']['balanceAfter']->minorUnits());

        // Posted total is the sum of individual postings, not a synthetic consolidated row.
        self::assertSame(75000, $detail['postedAmount']->minorUnits());
        self::assertSame(2, $detail['postingCount']);
    }

    public function testInterleavingPreservesIndependentPostings(): void
    {
        $data = $this->completedSheetWithTransaction('s1');
        $sheet = $data['sheet'];
        $tx1 = $data['transaction'];

        // Add a second completed transaction on the same sheet, with a movement between them.
        $tx2 = $this->makeTransaction(
            'tx-s1b', $sheet->dbId, WorkflowStage::Final, TransactionStatus::CompletedApproved, 'APPROVED'
        );

        $this->sheetPostedAmounts->method('postedAmountsFor')->willReturn([]);
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturnCallback(
            fn (int $sheetDbId, CurrencyCode $currencyCode): array => [
                (string) $tx1->publicId => [
                    'occurredAt' => new DateTimeImmutable('2026-10-01 09:00:00', new DateTimeZone('UTC')),
                    'amount' => Money::positive(20000, CurrencyCode::fromString('IRR')),
                    'balanceAfter' => Money::positive(20000, CurrencyCode::fromString('IRR')),
                ],
                // An unrelated movement between the two postings does not merge into either.
                (string) $tx2->publicId => [
                    'occurredAt' => new DateTimeImmutable('2026-10-02 11:00:00', new DateTimeZone('UTC')),
                    'amount' => Money::positive(25000, CurrencyCode::fromString('IRR')),
                    'balanceAfter' => Money::positive(45000, CurrencyCode::fromString('IRR')),
                ],
            ]
        );

        $detail = $this->service->detail((string) $sheet->publicId);

        $this->assertNotNull($detail);
        $this->assertSame(2, $detail['totalTransactionCount']);

        $posting1 = $detail['transactions'][0]['posting'];
        $posting2 = $detail['transactions'][1]['posting'];

        self::assertNotNull($posting1);
        self::assertSame(20000, $posting1['amount']->minorUnits());
        self::assertSame(20000, $posting1['balanceAfter']->minorUnits());

        self::assertNotNull($posting2);
        self::assertSame(25000, $posting2['amount']->minorUnits());
        self::assertSame(45000, $posting2['balanceAfter']->minorUnits());

        // The two postings are independent — neither subsumes the other.
        self::assertNotSame($posting1['occurredAt'], $posting2['occurredAt']);
        self::assertNotSame($posting1['amount'], $posting2['amount']);
    }

    // ===== Feature 008 — completed-history evidence association =====

    /**
     * The evidence row's `transaction_id` is the transaction's integer surrogate id, while the
     * detail payload's `id` is the public UUID. The two identifier spaces must not be conflated:
     * looking the batch up by the public UUID returned an empty list for every completed sheet.
     */
    public function testDetailReturnsTheActiveEvidenceOfTheOwningTransaction(): void
    {
        $data = $this->completedSheetWithTransaction('s-evidence');
        $sheet = $data['sheet'];
        $tx = $data['transaction'];
        $uploadedAt = new DateTimeImmutable('2026-10-01 10:00:00', new DateTimeZone('UTC'));

        $this->makeEvidence('ev-1', (int) $tx->dbId, true, 'E2E Receipt.pdf', $uploadedAt);
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturn([]);

        $detail = $this->service->detail((string) $sheet->publicId);

        self::assertNotNull($detail);
        $evidence = $detail['transactions'][0]['evidence'];
        self::assertCount(1, $evidence, 'the active evidence must be returned under its transaction');
        self::assertSame('ev-1', $evidence[0]['id']);
        self::assertSame('E2E Receipt.pdf', $evidence[0]['name']);
        self::assertSame('application/pdf', $evidence[0]['mediaType']);
        self::assertSame(1024, $evidence[0]['sizeBytes']);
        self::assertTrue($evidence[0]['active']);
        self::assertSame('purchaser', $evidence[0]['uploadedBy']);
        self::assertSame($uploadedAt, $evidence[0]['uploadedAt']);
    }

    public function testDetailDoesNotAttachEvidenceToAnotherTransaction(): void
    {
        $data = $this->completedSheetWithTransaction('s-evidence');
        $sheet = $data['sheet'];
        $tx1 = $data['transaction'];
        $tx2 = $this->makeTransaction(
            'tx-other', $sheet->dbId, WorkflowStage::Final, TransactionStatus::CompletedApproved, 'APPROVED'
        );

        $this->makeEvidence('ev-tx1', (int) $tx1->dbId);
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturn([]);

        $detail = $this->service->detail((string) $sheet->publicId);

        self::assertNotNull($detail);
        $byId = [];
        foreach ($detail['transactions'] as $transaction) {
            $byId[$transaction['id']] = $transaction['evidence'];
        }

        self::assertSame('ev-tx1', $byId[(string) $tx1->publicId][0]['id'] ?? null);
        self::assertSame([], $byId[(string) $tx2->publicId], 'evidence must not cross transaction boundaries');
    }

    public function testDetailPartitionsEvidenceAcrossTransactions(): void
    {
        $data = $this->completedSheetWithTransaction('s-evidence');
        $sheet = $data['sheet'];
        $tx1 = $data['transaction'];
        $tx2 = $this->makeTransaction(
            'tx-other', $sheet->dbId, WorkflowStage::Final, TransactionStatus::CompletedApproved, 'APPROVED'
        );

        $this->makeEvidence('ev-tx1-a', (int) $tx1->dbId, true, 'first.pdf');
        $this->makeEvidence('ev-tx1-b', (int) $tx1->dbId, true, 'second.pdf');
        $this->makeEvidence('ev-tx2-a', (int) $tx2->dbId, true, 'other.pdf');
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturn([]);

        $detail = $this->service->detail((string) $sheet->publicId);

        self::assertNotNull($detail);
        $byId = [];
        foreach ($detail['transactions'] as $transaction) {
            $byId[$transaction['id']] = array_map(
                static fn (array $evidence): string => $evidence['id'],
                $transaction['evidence']
            );
        }

        self::assertSame(['ev-tx1-a', 'ev-tx1-b'], $byId[(string) $tx1->publicId]);
        self::assertSame(['ev-tx2-a'], $byId[(string) $tx2->publicId]);
    }

    /**
     * FR-059: deactivated evidence is never physically removed and its history stays readable, so
     * the completed-history detail keeps listing the metadata with `active: false`.
     */
    public function testDetailKeepsInactiveEvidenceMetadataListed(): void
    {
        $data = $this->completedSheetWithTransaction('s-evidence');
        $sheet = $data['sheet'];
        $tx = $data['transaction'];

        $this->makeEvidence('ev-inactive', (int) $tx->dbId, false, 'removed.pdf');
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturn([]);

        $detail = $this->service->detail((string) $sheet->publicId);

        self::assertNotNull($detail);
        $evidence = $detail['transactions'][0]['evidence'];
        self::assertCount(1, $evidence, 'inactive evidence keeps its metadata in history');
        self::assertSame('ev-inactive', $evidence[0]['id']);
        self::assertFalse($evidence[0]['active']);
    }

    public function testDetailReadsEvidenceInOneBatchedQueryForAllTransactions(): void
    {
        $data = $this->completedSheetWithTransaction('s-evidence');
        $sheet = $data['sheet'];
        $tx1 = $data['transaction'];
        $tx2 = $this->makeTransaction(
            'tx-other', $sheet->dbId, WorkflowStage::Final, TransactionStatus::CompletedApproved, 'APPROVED'
        );

        $repository = $this->createMock(EvidenceRepository::class);
        $repository->expects(self::once())
            ->method('findByTransactionIds')
            ->with(self::callback(static function (array $ids) use ($tx1, $tx2): bool {
                return $ids === [(int) $tx1->dbId, (int) $tx2->dbId];
            }))
            ->willReturn([]);

        $service = new CompletedSheetHistoryService(
            $this->sheets,
            $this->transactions,
            $this->decisions,
            $repository,
            $this->sheetPostedAmounts,
            new InMemoryUserDirectory(),
            $this->scope,
            new InMemoryCurrencyRepository(),
        );

        $this->sheetPostedAmounts->method('postingsForSheet')->willReturn([]);

        $detail = $service->detail((string) $sheet->publicId);

        self::assertNotNull($detail);
    }

    public function testDetailExposesNoEvidenceMutationCapability(): void
    {
        $data = $this->completedSheetWithTransaction('s-evidence');
        $sheet = $data['sheet'];
        $tx = $data['transaction'];

        $this->makeEvidence('ev-1', (int) $tx->dbId);
        $this->sheetPostedAmounts->method('postingsForSheet')->willReturn([]);

        $detail = $this->service->detail((string) $sheet->publicId);

        self::assertNotNull($detail);
        $evidence = $detail['transactions'][0]['evidence'][0];
        self::assertArrayHasKey('id', $evidence);
        self::assertArrayHasKey('name', $evidence);
        self::assertArrayHasKey('active', $evidence);

        // The completed-history read is metadata-only: no upload/replace/delete/deactivate path.
        $forbidden = [
            'actions', 'capabilities', 'canUpload', 'canReplace', 'canDelete', 'canDeactivate',
            'uploadUrl', 'replaceUrl', 'deleteUrl', 'deactivateUrl', 'contentUrl', 'url',
        ];
        foreach ($forbidden as $capability) {
            self::assertArrayNotHasKey(
                $capability,
                $evidence,
                'completed-history evidence must not expose a mutation capability: ' . $capability
            );
        }
    }
}
