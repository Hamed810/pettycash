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
        /**
         * Public UUID of the finance decision that justified this consumption.
         *
         * `data-model.md` marks `source_decision_id` "Required for consumption" alongside
         * `source_revision_id`, and the two answer different questions: the revision says
         * *what* was spent, the decision says *on whose authority*. A ledger row carrying
         * only the first cannot answer a dispute about whether the spend was approved, which
         * is the question FR-040's single movement exists to settle.
         */
        public ?string $sourceDecisionId = null,
    ) {
        if ($amount->minorUnits() <= 0) {
            throw new InvalidArgumentException('Movement amount must be positive');
        }
        if ($type === MovementType::Consumption && $sourceRevisionId === null) {
            throw new InvalidArgumentException('Consumption source is required');
        }
        // A consumption being **created** must name the decision that authorised it: `data-model.md`
        // marks `source_decision_id` "Required for consumption", and without it a ledger row says
        // money was spent without recording on whose authority.
        //
        // A row already carrying a `dbId` is exempt, and that exemption is deliberate rather than
        // a loophole. It has been written; it is history, and the read path must be able to return
        // it. Enforcing the rule on hydration instead means a single pre-existing row without the
        // reference makes the whole account's movements endpoint fail — which is how a malformed
        // row would hide an entire money history behind a validation error, and it would report a
        // data problem as a request problem. New movements cannot be created without the decision:
        // `createConsumption()` takes it as a required argument and `PettyCashAccount::consume()`
        // has no overload that omits it.
        if (
            $type === MovementType::Consumption
            && $dbId === null
            && ($sourceDecisionId === null || trim($sourceDecisionId) === '')
        ) {
            throw new InvalidArgumentException('Consumption source decision is required');
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
        string $sourceDecisionId,
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
            $account->id,
            $sourceDecisionId
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

    public function sourceDecisionId(): ?string
    {
        return $this->sourceDecisionId;
    }
}