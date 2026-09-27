<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Account;

use InvalidArgumentException;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;
use DateTimeImmutable;

final class PettyCashAccount
{
    public function __construct(
        public string $id,
        public string $userUid,
        public CurrencyCode $currencyCode,
        public Currency $currency,
        public int $balanceMinor = 0,
        public bool $active = true,
        public int $lockVersion = 0,
        public ?DateTimeImmutable $createdAt = null,
        public ?string $createdBy = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?string $updatedBy = null,
        public ?int $dbId = null
    ) {}

    public static function create(string $id, string $userUid, Currency $currency): self
    {
        if (trim($userUid) === '') {
            throw new InvalidArgumentException('User UID is required');
        }
        return new self($id, $userUid, CurrencyCode::fromString($currency->code()), $currency, 0, true);
    }

    public function currencyCode(): CurrencyCode
    {
        return $this->currencyCode;
    }

    public function charge(
        Money $amount,
        string $commandId,
        string $description = 'Account charge',
        ?DateTimeImmutable $occurredAt = null
    ): AccountMovement {
        if (!$this->active) {
            throw new InvalidArgumentException('Cannot charge inactive account');
        }
        if (!$amount->currency()->equals($this->currencyCode)) {
            throw new InvalidArgumentException('Currency mismatch');
        }
        $movement = AccountMovement::createCharge(
            $this->newMovementId(),
            $amount,
            $description,
            $this,
            $commandId,
            $occurredAt
        );
        $this->balanceMinor = $movement->balanceAfter()->minorUnits();
        return $movement;
    }

    public function consume(Money $amount, string $commandId, string $sourceRevisionId, string $sourceDecisionId): AccountMovement
    {
        if (!$this->active) {
            throw new InvalidArgumentException('Cannot consume from inactive account');
        }
        if (!$amount->currency()->equals($this->currencyCode)) {
            throw new InvalidArgumentException('Currency mismatch');
        }
        $movement = AccountMovement::createConsumption(
            $this->newMovementId(),
            $amount,
            'Expense consumption',
            $this,
            $sourceRevisionId,
            $sourceDecisionId,
            $commandId
        );
        $this->balanceMinor = $movement->balanceAfter()->minorUnits();
        return $movement;
    }

    /**
     * A movement's public identifier.
     *
     * This used to be `$this->id . '-' . $commandId`, which is 73 characters for two UUIDs, and
     * `pettycash_account_movements.public_id` is 36. Every charge and every consumption therefore
     * failed to insert with "Data too long for column 'public_id'" — so `POST /accounts/{id}/decisions`
     * with APPROVE answered **500**, having already applied the new balance to the aggregate in
     * memory. A UUID is minted instead, like every other aggregate here.
     *
     * The command id is not lost by this: it is stored in its own `command_id` column, which is
     * what makes a replayed command find the movement it already wrote. Folding it into the public
     * id added nothing but the overflow.
     */
    private function newMovementId(): string
    {
        return (string)\OCA\PettyCash\Domain\Shared\Uuid::random();
    }

    public function balance(): Money
    {
        return Money::of($this->balanceMinor, $this->currencyCode);
    }
}