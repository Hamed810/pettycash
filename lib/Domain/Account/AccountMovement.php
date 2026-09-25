<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Account;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;

final readonly class AccountMovement
{
    public function __construct(
        public string $id,
        public MovementType $type,
        public Money $amount,
        public Money $balanceAfter,
        public string $description,
        public string $commandId,
        public ?string $sourceRevisionId = null,
        public ?DateTimeImmutable $occurredAt = null,
        public ?int $dbId = null,
        public ?string $publicId = null,
        /**
         * Public UUID of the account this movement belongs to.
         *
         * A movement is meaningless without its account — it is the account's
         * ledger — so the reference is carried on the aggregate and resolved to
         * the surrogate `pettycash_accounts.id` only by the persistence adapter.
         */
        public ?string $accountPublicId = null,
    ) {
        if ($amount->minorUnits() <= 0) {
            throw new InvalidArgumentException('Movement amount must be positive');
        }
        if ($type === MovementType::Consumption && $sourceRevisionId === null) {
            throw new InvalidArgumentException('Consumption source is required');
        }
    }

    public static function createCharge(
        string $id,
        Money $amount,
        string $description,
        PettyCashAccount $account,
        ?string $commandId = null
    ): self {
        $commandId = $commandId ?? (string)Uuid::random();
        $balanceAfter = $account->balance()->add($amount);
        
        return new self(
            $id,
            MovementType::Charge,
            $amount,
            $balanceAfter,
            $description,
            $commandId,
            null,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            null,
            null,
            $account->id
        );
    }

    public static function createConsumption(
        string $id,
        Money $amount,
        string $description,
        PettyCashAccount $account,
        string $sourceRevisionId,
        ?string $commandId = null
    ): self {
        $commandId = $commandId ?? (string)Uuid::random();
        $balanceAfter = $account->balance()->subtract($amount);
        
        return new self(
            $id,
            MovementType::Consumption,
            $amount,
            $balanceAfter,
            $description,
            $commandId,
            $sourceRevisionId,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            null,
            null,
            $account->id
        );
    }

    public function signedAmount(): int
    {
        return $this->type === MovementType::Charge 
            ? $this->amount->minorUnits() 
            : -$this->amount->minorUnits();
    }

    public function balanceAfter(): Money
    {
        return $this->balanceAfter;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function type(): MovementType
    {
        return $this->type;
    }

    public function commandId(): string
    {
        return $this->commandId;
    }

    public function sourceRevisionId(): ?string
    {
        return $this->sourceRevisionId;
    }
}