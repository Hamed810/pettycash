<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Workflow\SheetStatus;

/** @var list<ExpenseTransaction> */
final class ExpenseSheet
{
    private array $transactions = [];

    public function __construct(
        public string $id,
        public string $ownerUid,
        public string $accountId,
        public CurrencyCode $currency,
        public SheetStatus $status = SheetStatus::Open,
        public ?int $submittedTotalMinor = null,
        public string $title = '',
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $firstSubmittedAt = null,
        public ?DateTimeImmutable $managementCompletedAt = null,
        public ?DateTimeImmutable $financeStartedAt = null,
        public ?DateTimeImmutable $completedAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public int $lockVersion = 0,
        public ?int $dbId = null,
        public ?string $publicId = null
    ) {}

    public static function open(string $id, string $owner, string $account, CurrencyCode $currency, string $title = ''): self
    {
        return new self($id, $owner, $account, $currency, SheetStatus::Open, null, $title);
    }

    public function addTransaction(ExpenseTransaction $transaction): void
    {
        if ($this->status !== SheetStatus::Open) {
            throw new LogicException('Submitted sheets are read-only');
        }
        if (!$transaction->revision()->amount()->currency()->equals($this->currency)) {
            throw new InvalidArgumentException('Transaction currency must inherit the sheet currency');
        }
        $this->transactions[] = $transaction;
    }

    public function submit(): int
    {
        if ($this->transactions === []) {
            throw new InvalidArgumentException('At least one transaction is required');
        }
        $total = 0;
        foreach ($this->transactions as $tx) {
            $tx->revision()->assertSubmittable();
            $tx->activate($tx->revision());
            $total += $tx->revision()->amount()->minorUnits();
        }
        $this->status = SheetStatus::CostCenter;
        $this->submittedTotalMinor = $total;
        $this->firstSubmittedAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $total;
    }

    /** @return list<ExpenseTransaction> */
    public function transactions(): array
    {
        return $this->transactions;
    }
}