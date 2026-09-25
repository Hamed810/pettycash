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
        public ?int $transactionDbId = null,
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
        string $costCenterCode,
        string $costCenterName,
        int $costCenterDbId,
        string $expenseTypeTitle,
        int $expenseTypeDbId,
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
        $this->costCenterCode = $costCenterCode;
        $this->costCenterName = $costCenterName;
        $this->costCenterDbId = $costCenterDbId;
        $this->expenseTypeTitle = $expenseTypeTitle;
        $this->expenseTypeDbId = $expenseTypeDbId;
        $this->evidence = $hasEvidence;
    }

    /**
     * Restore the business fields of a revision that is read back from storage.
     *
     * `complete()` is the draft-to-submitted domain transition and cannot be reused
     * here: it refuses to touch an already active revision, and a revision loaded
     * from the database is by definition already submitted. Persistence needs its
     * own explicit way to rebuild the state, because a revision whose description,
     * cost center, expense type, and evidence flag come back empty is not the record
     * that was stored — it silently fails submission validation, empties the
     * authorization scope used for management review, and is written back as an empty
     * description on the next save.
     *
     * `$costCenterId` is the cost center's public UUID, matching what
     * `complete()` receives; the numeric join key lives in `$costCenterDbId`.
     */
    public function hydrate(
        ?string $description,
        ?string $costCenterId,
        ?string $expenseTypeTitle,
        bool $hasEvidence,
        bool $active
    ): void {
        $this->description = $description;
        $this->costCenterId = $costCenterId;
        $this->expenseTypeTitle = $expenseTypeTitle;
        $this->evidence = $hasEvidence;
        $this->active = $active;
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
        if ($this->isCompleted()) {
            throw new LogicException('Cannot correct a completed transaction');
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

    /**
     * Check if this revision represents a completed transaction
     * This is a heuristic - the actual check should be done at the transaction level
     */
    public function isCompleted(): bool
    {
        // A revision is considered completed if it has been through finance stage
        // This is a simplification - the actual check should be done via the transaction
        return false; // Default to false, override in transaction check
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