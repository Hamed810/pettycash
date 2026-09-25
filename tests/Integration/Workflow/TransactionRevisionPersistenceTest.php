<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Workflow;

use LogicException;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;

use PHPUnit\Framework\TestCase;

/**
 * `pettycash_transaction_revisions.transaction_id` is a NOT NULL join key onto
 * `pettycash_transactions.id`, and `pettycash_transactions.active_revision_id` points back at
 * the revision. These tests pin the write ordering that makes both links
 * resolvable, so a revision can never be persisted with a placeholder
 * transaction id (which would orphan it from its transaction).
 */
final class TransactionRevisionPersistenceTest extends TestCase
{
    private $container;

    protected function setUp(): void
    {
        $this->container = \TestContainerAccessor::getInstance();

        foreach ([
            ExpenseSheetRepository::class,
            ExpenseTransactionRepository::class,
            TransactionRevisionRepository::class,
        ] as $repoClass) {
            $repo = $this->container->query($repoClass);
            $repo->clear();
            $this->container->setInstance($repoClass, $repo);
        }
    }

    public function testCreatingATransactionLinksItsRevisionToTheTransaction(): void
    {
        $service = $this->container->query(TransactionService::class);
        $sheetId = $this->openSheet();

        $transaction = $service->createTransaction($sheetId, '2026-09-20', 1000);

        $revision = $transaction->revision();

        self::assertNotNull(
            $transaction->dbId,
            'The transaction must be persisted so it has an internal join key'
        );
        self::assertSame(
            $transaction->dbId,
            $revision->transactionDbId,
            'The revision must carry the internal id of its owning transaction'
        );
    }

    public function testPersistedRevisionIsRetrievableByItsTransaction(): void
    {
        $service = $this->container->query(TransactionService::class);
        $sheetId = $this->openSheet();

        $transaction = $service->createTransaction($sheetId, '2026-09-20', 1000);
        $revisions = $this->container->query(TransactionRevisionRepository::class);

        $found = $revisions->findByTransactionId((int)$transaction->dbId);

        self::assertCount(1, $found, 'The revision must be retrievable through its transaction');
        self::assertSame($transaction->revision()->id(), $found[0]->id());
    }

    public function testARevisionWithoutItsOwningTransactionIsRejected(): void
    {
        $revisions = $this->container->query(TransactionRevisionRepository::class);

        $orphan = TransactionRevision::draft(
            'orphan-revision',
            1,
            new \DateTimeImmutable('2026-09-20', new \DateTimeZone('UTC')),
            Money::positive(1000, CurrencyCode::fromString('IRR'))
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('without its owning transaction id');

        $revisions->save($orphan);
    }

    private function openSheet(): string
    {
        $sheet = \OCA\PettyCash\Domain\Expense\ExpenseSheet::open(
            'sheet-rev-link',
            'test-user',
            'acc-1',
            CurrencyCode::fromString('IRR'),
            'Revision link sheet'
        );
        $sheet->publicId = 'sheet-rev-link';
        $this->container->query(ExpenseSheetRepository::class)->save($sheet);

        return 'sheet-rev-link';
    }
}
