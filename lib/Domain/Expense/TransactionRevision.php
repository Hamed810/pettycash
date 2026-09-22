<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Domain\Shared\Money;

final class TransactionRevision
{
    private ?string $description = null;
    private ?string $costCenterId = null;
    private ?string $expenseTypeTitle = null;
    private bool $evidence = false;
    private bool $active = false;

    public function __construct(
        private string $id,
        private int $number,
        private DateTimeImmutable $expenseDate,
        private Money $amount,
        private ?string $supersedesId = null,
        public ?int $costCenterDbId = null,
        public ?string $costCenterCode = null,
        public ?string $costCenterName = null,
        public ?int $expenseTypeDbId = null,
        public ?int $managerDbId = null,
        public ?string $managerUid = null,
        public ?int $seniorManagerDbId = null,
        public ?string $seniorManagerUid = null,
        public ?DateTimeImmutable $activatedAt = null,
        public ?int $dbId = null,
        public ?string $publicId = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?string $createdBy = null
    ) {
        if ($number < 1) {
            throw new InvalidArgumentException('Revision number starts at one');
        }
    }

    public static function draft(
        string $id,
        int $number,
        DateTimeImmutable $date,
        Money $amount,
        ?string $supersedes = null
    ): self {
        return new self($id, $number, $date, $amount, $supersedes);
    }

    public function complete(
        string $description,
        string $costCenterId,
        string $expenseTypeTitle,
        bool $hasEvidence
    ): void {
        if ($this->active) {
            throw new LogicException('Submitted revisions are immutable');
        }
        if (trim($description) === '' || trim($costCenterId) === '' || trim($expenseTypeTitle) === '') {
            throw new InvalidArgumentException('Submission fields are required');
        }
        $this->description = $description;
        $this->costCenterId = $costCenterId;
        $this->expenseTypeTitle = $expenseTypeTitle;
        $this->evidence = $hasEvidence;
    }

    public function assertSubmittable(bool $evidenceRequired = false): void
    {
        if ($this->description === null || $this->costCenterId === null || $this->expenseTypeTitle === null || ($evidenceRequired && !$this->evidence)) {
            throw new InvalidArgumentException('Revision is incomplete');
        }
    }

    public function activate(): void
    {
        $this->assertSubmittable();
        $this->active = true;
        $this->activatedAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function correction(string $id): self
    {
        if (!$this->active) {
            throw new LogicException('Only submitted revisions can be corrected');
        }
        $copy = new self(
            $id,
            $this->number + 1,
            $this->expenseDate,
            $this->amount,
            $this->id
        );
        $copy->description = $this->description;
        $copy->costCenterId = $this->costCenterId;
        $copy->expenseTypeTitle = $this->expenseTypeTitle;
        $copy->costCenterDbId = $this->costCenterDbId;
        $copy->costCenterCode = $this->costCenterCode;
        $copy->costCenterName = $this->costCenterName;
        $copy->expenseTypeDbId = $this->expenseTypeDbId;
        return $copy;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function supersedes(): ?string
    {
        return $this->supersedesId;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function costCenterId(): ?string
    {
        return $this->costCenterId;
    }

    public function expenseTypeTitle(): ?string
    {
        return $this->expenseTypeTitle;
    }

    public function hasEvidence(): bool
    {
        return $this->evidence;
    }

    public function expenseDate(): DateTimeImmutable
    {
        return $this->expenseDate;
    }
}