<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

final class ExpenseTransaction
{
    public WorkflowStage $stage = WorkflowStage::Draft;
    public TransactionStatus $status = TransactionStatus::Draft;
    public ?string $finalOutcome = null;

    public function __construct(
        public string $id,
        public string $ownerUid,
        private TransactionRevision $activeRevision,
        public ?int $sheetId = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public int $lockVersion = 0,
        public ?int $dbId = null,
        public ?string $publicId = null
    ) {}

    public function revision(): TransactionRevision
    {
        return $this->activeRevision;
    }

    public function activate(TransactionRevision $revision): void
    {
        $revision->activate();
        $this->activeRevision = $revision;
        $this->stage = WorkflowStage::CostCenter;
        $this->status = TransactionStatus::Pending;
    }
}