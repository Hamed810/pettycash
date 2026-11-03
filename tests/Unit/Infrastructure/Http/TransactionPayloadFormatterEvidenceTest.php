<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Infrastructure\Http;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Infrastructure\Http\TransactionPayloadFormatter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTypeRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryWorkflowDecisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * FR-092: `TransactionSummary.evidenceRequired` is derived from the active expense type's current
 * setting, and `evidenceCount` counts only active evidence.
 *
 * The two values are deliberately asserted together. A readiness surface needs both to decide
 * whether a transaction is blocking submission, and a payload that exposed the requirement but
 * counted inactive evidence would tell the purchaser to attach a receipt they already had.
 */
final class TransactionPayloadFormatterEvidenceTest extends TestCase
{
    private const TYPE_UUID = '33333333-3333-4333-8333-333333333333';

    public function testAReceiptRequiredExpenseTypeIsExposedAlongsideActiveEvidenceCount(): void
    {
        $expenseTypes = new InMemoryExpenseTypeRepository();
        $this->requiredType($expenseTypes, true);

        $evidence = new InMemoryEvidenceRepository();
        $evidence->save($this->evidence('ev-1', 7, true));
        $evidence->save($this->evidence('ev-2', 7, false));

        $summary = $this->formatter($expenseTypes, $evidence)->summary($this->transaction(7));

        self::assertTrue($summary['evidenceRequired'], 'The active expense type requires a receipt');
        self::assertSame(1, $summary['evidenceCount'], 'Only active evidence satisfies readiness');
    }

    public function testAnExpenseTypeWithoutTheRequirementIsExposedAsFalse(): void
    {
        $expenseTypes = new InMemoryExpenseTypeRepository();
        $this->requiredType($expenseTypes, false);

        $summary = $this->formatter($expenseTypes, new InMemoryEvidenceRepository())->summary($this->transaction(7));

        self::assertFalse($summary['evidenceRequired']);
    }

    public function testATransactionWithNoExpenseTypeYetIsNotRequiredToHaveAReceipt(): void
    {
        $summary = $this->formatter(new InMemoryExpenseTypeRepository(), new InMemoryEvidenceRepository())
            ->summary($this->transaction(7, null));

        self::assertFalse($summary['evidenceRequired'], 'A draft with no expense type must not show a receipt blocker');
        self::assertSame(0, $summary['evidenceCount']);
    }

    public function testTheFlagTracksTheCurrentExpenseTypeSettingNotARevisionSnapshot(): void
    {
        $expenseTypes = new InMemoryExpenseTypeRepository();
        $type = $this->requiredType($expenseTypes, true);
        $formatter = $this->formatter($expenseTypes, new InMemoryEvidenceRepository());
        $transaction = $this->transaction(7);

        self::assertTrue($formatter->summary($transaction)['evidenceRequired']);

        $type->evidenceRequired = false;
        $expenseTypes->save($type);

        self::assertFalse(
            $formatter->summary($transaction)['evidenceRequired'],
            'FR-092 requires the active expense type\'s current setting, so changing the type must change the payload'
        );
    }

    private function requiredType(InMemoryExpenseTypeRepository $expenseTypes, bool $required): ExpenseType
    {
        $type = ExpenseType::create('Travel', null, $required);
        $type->publicId = self::TYPE_UUID;
        $type->id = 9;
        $expenseTypes->save($type);

        return $type;
    }

    private function transaction(int $dbId, ?string $expenseTypeId = self::TYPE_UUID): ExpenseTransaction
    {
        $revision = TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete(
            'Description',
            'cc-1',
            'CC-1',
            'Cost Center',
            7,
            'Travel',
            9,
            false,
            $expenseTypeId
        );

        return new ExpenseTransaction(
            'tx-1',
            'purchaser-1',
            $revision,
            null,
            dbId: $dbId,
            publicId: 'tx-public-1'
        );
    }

    private function evidence(string $id, int $transactionId, bool $active): Evidence
    {
        return new Evidence(
            $id,
            $transactionId,
            1,
            'evidence/' . $id,
            $id . '.pdf',
            'application/pdf',
            100,
            'checksum',
            $active,
            'purchaser-1',
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            publicId: $id
        );
    }

    private function formatter(
        InMemoryExpenseTypeRepository $expenseTypes,
        InMemoryEvidenceRepository $evidence
    ): TransactionPayloadFormatter {
        return new TransactionPayloadFormatter(
            new InMemoryWorkflowDecisionRepository(),
            $evidence,
            new InMemoryCostCenterRepository(),
            new InMemoryCurrencyRepository(),
            new InMemoryRoleAssignmentRepository(),
            new InMemoryExpenseSheetRepository(),
            new InMemoryCurrentUser('purchaser-1'),
            $expenseTypes
        );
    }
}
