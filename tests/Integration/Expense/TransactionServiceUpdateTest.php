<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Expense;

use InvalidArgumentException;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTypeRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionRevisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * T212: `updateTransaction()` takes UUIDs and snapshots code and name.
 *
 * ## The defect
 *
 * The caller-supplied expense type is a **public id**, and `updateTransaction()` says so: the full-edit
 * branch does `findByPublicId($expenseTypeTitle)` on it. The parameter was nevertheless *named*
 * `$expenseTypeTitle`, and the partial-edit branch then used the name:
 *
 * ```php
 * $expenseTypeTitle ?? $current->expenseTypeTitle()   // TransactionRevision::complete(), arg 6
 * ```
 *
 * `complete()`'s sixth parameter is `string $expenseTypeTitle`, assigned straight to
 * `$this->expenseTypeTitle`. So a `PATCH` that supplied a cost centre and an expense type but **omitted
 * the description** — which takes the partial branch, because that branch is gated on *all three* being
 * present — wrote a **UUID into the expense-type-title column**. Silently, on a draft revision, in a
 * column the finance queue later reads.
 *
 * The cost centre has the same shape of bug one level down: the partial branch takes the caller's
 * *new* UUID for the id while reusing the *current* revision's `costCenterCode` and `costCenterName`.
 * A revision that says "CC-200" by id and "CC-100" by name is worse than either being right.
 *
 * ## Why unit-level
 *
 * The argument mangling is a property of the method, and driving it directly is what makes the two
 * paths comparable: the same inputs, the same assertion, on both the full and partial branches. A
 * contract test would additionally prove the endpoint, but it cannot isolate *which* of the two
 * branches stored the bad value, and the endpoint is not where the defect is.
 */
final class TransactionServiceUpdateTest extends TestCase
{
    private const OWNER = 'purchaser-1';
    private const TRANSACTION_ID = '44444444-4444-4444-8444-444444444444';
    private const REVISION_ID = '55555555-5555-4555-8555-555555555555';
    private const COST_CENTER_ID = '66666666-6666-4666-8666-666666666666';
    private const OTHER_COST_CENTER_ID = '77777777-7777-4777-8777-777777777777';
    private const EXPENSE_TYPE_ID = '88888888-8888-4888-8888-888888888888';
    private const OTHER_EXPENSE_TYPE_ID = '99999999-9999-4999-8999-999999999999';

    private InMemoryExpenseSheetRepository $sheets;
    private InMemoryExpenseTransactionRepository $transactions;
    private InMemoryTransactionRevisionRepository $revisions;
    private InMemoryCostCenterRepository $costCenters;
    private InMemoryExpenseTypeRepository $expenseTypes;
    private TransactionService $service;
    private ExpenseTransaction $transaction;

    protected function setUp(): void
    {
        $this->sheets = new InMemoryExpenseSheetRepository();
        $this->transactions = new InMemoryExpenseTransactionRepository();
        $this->revisions = new InMemoryTransactionRevisionRepository();
        $this->costCenters = new InMemoryCostCenterRepository();
        $this->expenseTypes = new InMemoryExpenseTypeRepository();

        $this->seedCostCenters();
        $this->seedExpenseTypes();

        $sheet = ExpenseSheet::open('sheet-1', self::OWNER, 'account-1', CurrencyCode::fromString('IRR'));
        $sheet->dbId = 1;
        $sheet->status = SheetStatus::Open;
        $this->sheets->save($sheet);

        // A revision that is already complete, so the partial branch has real prior values to
        // preserve. Without them the fallback would be writing nulls and the test would pass for the
        // wrong reason.
        $current = TransactionRevision::draft(self::REVISION_ID, 1, new \DateTimeImmutable('2026-01-01'), Money::positive(1000, CurrencyCode::fromString('IRR')));
        $current->complete(
            'original description',
            self::COST_CENTER_ID,
            'CC-100',
            'Original Cost Center',
            1,
            'Original Expense Type',
            1,
            false
        );
        $this->transaction = new ExpenseTransaction(self::TRANSACTION_ID, self::OWNER, $current);
        $this->transaction->dbId = 1;
        $this->transaction->sheetId = 1;
        $this->transaction->lockVersion = 0;
        $this->transactions->save($this->transaction);
        // `transaction_id` is NOT NULL, and the double enforces that rather than accepting a
        // placeholder -- the same guard `DbTransactionRevisionRepository` has.
        $current->transactionDbId = 1;
        // `draft()` takes the public id as its first argument and stores it in the private `$id`;
        // `publicId` is a separate column the real repository populates, and the double's
        // `findByPublicId` reads it. Without this the immutability assertion below would silently
        // look up nothing and pass.
        $current->publicId = self::REVISION_ID;
        $this->revisions->save($current);

        $this->service = new TransactionService(
            $this->sheets,
            $this->transactions,
            $this->revisions,
            $this->costCenters,
            $this->expenseTypes,
            new InMemoryEvidenceRepository(),
            new InMemoryPrivateFileStore(),
            new InMemoryCurrentUser(self::OWNER),
            new InMemoryTransactionManager(),
            // The audit writer is a real collaborator now that T213 records `REVISION_CREATED` and
            // `TRANSACTION_CHANGED` here, so a test that constructs the service directly has to supply
            // one. The in-memory writer is used rather than the real service so these tests stay focused
            // on the revision semantics they were written for.
            new InMemoryAuditWriter(),
            new IdempotencyService(new InMemoryCommandResultRepository())
        );
    }

    private function seedCostCenters(): void
    {
        $original = CostCenter::create('CC-100', 'Original Cost Center', null, new \DateTimeImmutable('2026-01-01'), null);
        $original->id = 1;
        $original->publicId = self::COST_CENTER_ID;
        $this->costCenters->save($original);

        $other = CostCenter::create('CC-200', 'Other Cost Center', null, new \DateTimeImmutable('2026-01-01'), null);
        $other->id = 2;
        $other->publicId = self::OTHER_COST_CENTER_ID;
        $this->costCenters->save($other);
    }

    private function seedExpenseTypes(): void
    {
        $original = ExpenseType::create('Original Expense Type', null, false);
        $original->id = 1;
        $original->publicId = self::EXPENSE_TYPE_ID;
        $this->expenseTypes->save($original);

        $other = ExpenseType::create('Other Expense Type', null, false);
        $other->id = 2;
        $other->publicId = self::OTHER_EXPENSE_TYPE_ID;
        $this->expenseTypes->save($other);
    }

    // ===== the defect =====

    /**
     * The headline: a UUID must never land in the expense-type-title column.
     *
     * Written as an assertion about the *title* rather than about "not equal to the id", because the
     * title is the fact the column means. A test asserting `assertNotSame($uuid, $title)` would pass
     * against any other wrong value; asserting the title is the resolved one pins the contract.
     */
    public function testAPartialEditResolvesTheSuppliedExpenseTypeIdToItsTitle(): void
    {
        $this->service->updateTransaction(
            self::TRANSACTION_ID,
            '2026-02-01',
            2000,
            null,                        // description omitted -> the partial branch
            self::COST_CENTER_ID,
            self::EXPENSE_TYPE_ID,       // supplied, and is a UUID
            0,
            'cmd-t317-1'
        );

        $stored = $this->activeRevision();
        self::assertSame(
            'Original Expense Type',
            $stored->expenseTypeTitle(),
            'a UUID must never be stored as the expense type title. The caller supplies a public id; the '
            . 'revision stores the title it resolves to.'
        );
        self::assertNotSame(self::EXPENSE_TYPE_ID, $stored->expenseTypeTitle());
    }

    /**
     * The same for the cost centre's *name*, which the partial branch took from the current revision
     * while taking the id from the caller.
     */
    public function testAPartialEditSnapshotsTheCodeAndNameOfTheSuppliedCostCentre(): void
    {
        $this->service->updateTransaction(
            self::TRANSACTION_ID,
            '2026-02-01',
            2000,
            null,
            self::OTHER_COST_CENTER_ID,  // a different cost centre than the current revision's
            self::EXPENSE_TYPE_ID,
            0,
            'cmd-t317-2'
        );

        $stored = $this->activeRevision();
        self::assertSame(self::OTHER_COST_CENTER_ID, $stored->costCenterId());
        self::assertSame('CC-200', $stored->costCenterCode, 'the code must be the supplied centre\'s, not the previous one');
        self::assertSame('Other Cost Center', $stored->costCenterName, 'the name must be the supplied centre\'s, not the previous one');
        self::assertSame(2, $stored->costCenterDbId);
    }

    // ===== the parts that were already right, asserted so they stay right =====

    public function testAFullEditSnapshotsTheCodeAndName(): void
    {
        $this->service->updateTransaction(
            self::TRANSACTION_ID,
            '2026-02-01',
            2000,
            'new description',
            self::OTHER_COST_CENTER_ID,
            self::OTHER_EXPENSE_TYPE_ID,
            0,
            'cmd-t317-3'
        );

        $stored = $this->activeRevision();
        self::assertSame('new description', $stored->description());
        self::assertSame('CC-200', $stored->costCenterCode);
        self::assertSame('Other Cost Center', $stored->costCenterName);
        self::assertSame('Other Expense Type', $stored->expenseTypeTitle());
        self::assertSame(2, $stored->expenseTypeDbId);
    }

    /**
     * A partial edit preserves what it does not mention. Without this the fix could "resolve
     * everything" and silently overwrite the caller's own data on a field they never touched.
     */
    public function testAPartialEditPreservesEveryFieldTheCallerOmitted(): void
    {
        $this->service->updateTransaction(
            self::TRANSACTION_ID,
            '2026-02-01',
            2000,
            null,
            null,
            null,
            0,
            'cmd-t317-4'
        );

        $stored = $this->activeRevision();
        self::assertSame('original description', $stored->description());
        self::assertSame(self::COST_CENTER_ID, $stored->costCenterId());
        self::assertSame('CC-100', $stored->costCenterCode);
        self::assertSame('Original Cost Center', $stored->costCenterName);
        self::assertSame('Original Expense Type', $stored->expenseTypeTitle());
        self::assertSame(1, $stored->expenseTypeDbId);
    }

    public function testAnUnknownExpenseTypeIdIsRefusedRatherThanStored(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expense type not found');

        $this->service->updateTransaction(
            self::TRANSACTION_ID,
            '2026-02-01',
            2000,
            'd',
            self::COST_CENTER_ID,
            'no-such-expense-type',
            0,
            'cmd-t317-5'
        );
    }

    public function testAnUnknownCostCentreIdIsRefusedRatherThanStored(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cost center not found');

        $this->service->updateTransaction(
            self::TRANSACTION_ID,
            '2026-02-01',
            2000,
            'd',
            'no-such-cost-centre',
            self::EXPENSE_TYPE_ID,
            0,
            'cmd-t317-6'
        );
    }

    /**
     * The edit produces a *new* revision and leaves the old one readable, per FR-022's immutability.
     * Asserted because the fix rewrites how the revision is completed, and a rewrite that mutated in
     * place would satisfy every assertion above.
     */
    public function testTheEditCreatesANewRevisionAndLeavesTheOldOneIntact(): void
    {
        $before = count($this->revisions->findByTransactionId(1));

        $this->service->updateTransaction(
            self::TRANSACTION_ID,
            '2026-02-01',
            2000,
            'new description',
            self::COST_CENTER_ID,
            self::EXPENSE_TYPE_ID,
            0,
            'cmd-t317-7'
        );

        self::assertCount($before + 1, $this->revisions->findByTransactionId(1));
        self::assertSame(
            'original description',
            $this->revisions->findByPublicId(self::REVISION_ID)?->description(),
            'the prior revision must not be rewritten by a correction'
        );
        self::assertSame(2, $this->activeRevision()->number());
    }

    private function activeRevision(): TransactionRevision
    {
        $revision = $this->transaction->revision();
        self::assertNotNull($revision, 'the update must leave an active revision');

        return $revision;
    }
}
