<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

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

    public function removeTransaction(string $transactionId): void
    {
        $this->transactions = array_filter($this->transactions, fn($tx) => $tx->id() !== $transactionId);
    }

    /**
     * Recalculate sheet status based on the earliest outstanding transaction stage
     */
    public function recalculateStatus(): void
    {
        if ($this->transactions === []) {
            return;
        }

        $stageOrder = [
            WorkflowStage::Draft->value => 0,
            WorkflowStage::CostCenter->value => 1,
            WorkflowStage::Correction->value => 2,
            WorkflowStage::Senior->value => 3,
            WorkflowStage::Finance->value => 4,
            WorkflowStage::Final->value => 5,
        ];

        $earliestStage = WorkflowStage::Final;
        $earliestOrder = PHP_INT_MAX;

        foreach ($this->transactions as $tx) {
            $order = $stageOrder[$tx->stage->value] ?? PHP_INT_MAX;
            if ($order < $earliestOrder) {
                $earliestOrder = $order;
                $earliestStage = $tx->stage;
            }
        }

        // Map workflow stage to sheet status
        $this->status = match ($earliestStage) {
            WorkflowStage::Draft => SheetStatus::Open,
            WorkflowStage::CostCenter => SheetStatus::CostCenter,
            WorkflowStage::Senior => SheetStatus::Senior,
            WorkflowStage::Finance => SheetStatus::Finance,
            WorkflowStage::Correction => SheetStatus::Correction,
            WorkflowStage::Final => SheetStatus::Completed,
            default => SheetStatus::Open,
        };

        // Check if all transactions are completed
        $allCompleted = true;
        $hasRejections = false;
        foreach ($this->transactions as $tx) {
            if (!in_array($tx->status, [TransactionStatus::CompletedApproved, TransactionStatus::CompletedRejected], true)) {
                $allCompleted = false;
            }
            if ($tx->status === TransactionStatus::CompletedRejected) {
                $hasRejections = true;
            }
        }

        if ($allCompleted) {
            $this->status = $hasRejections ? SheetStatus::CompletedWithRejections : SheetStatus::Completed;
            $this->completedAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }
}