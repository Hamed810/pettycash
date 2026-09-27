<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Http;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\PettyCashAccount;

/**
 * The account shapes `contracts/ocs-api.yaml` declares, for the endpoints that answer with one.
 *
 * `AccountService` builds the same two arrays for `GET /accounts` and
 * `GET /accounts/{id}/movements`, and this class exists because `POST /transactions/{id}/decisions`
 * has to report `AccountMutationResult` from the settlement it just performed rather than by
 * reading the ledger back — at which point two copies of the same shape begin to drift, which is
 * how the transaction payload ended up with a flat `revision` object that every view dereferenced
 * as `undefined`.
 *
 * `AccountService` is left alone deliberately: it sits in the Application layer, and depending on
 * an `Infrastructure\Http` formatter from there would invert the dependency direction the
 * project's layer rules set. The two must stay in step until that projection is moved out of the
 * service, which is a larger change than the contract gap that prompted this class.
 */
final readonly class AccountPayloadFormatter
{
    /**
     * The contract's `Account`.
     *
     * @return array<string, mixed>
     */
    public function account(PettyCashAccount $account): array
    {
        return [
            'id' => $account->id,
            'userId' => $account->userUid,
            'balance' => [
                'amountMinor' => $account->balance()->minorUnits(),
                'currency' => (string) $account->currencyCode(),
                'minorUnit' => $account->currency->minorUnit,
            ],
            'active' => $account->active,
            'version' => $account->lockVersion,
        ];
    }

    /**
     * The contract's `Movement`.
     *
     * `occurredAt` is not nullable in the contract. A movement the aggregate has just built has
     * no timestamp until the repository writes one, so the aggregate's own value is preferred and
     * `now` is the fallback — the same trade `AccountService::listMovements()` makes.
     *
     * @return array<string, mixed>
     */
    public function movement(AccountMovement $movement, PettyCashAccount $account): array
    {
        $currency = (string) $account->currencyCode();
        $minorUnit = $account->currency->minorUnit;

        return [
            'id' => $movement->id,
            'type' => $movement->type->value,
            'amount' => [
                'amountMinor' => $movement->amount->minorUnits(),
                'currency' => $currency,
                'minorUnit' => $minorUnit,
            ],
            'balanceAfter' => [
                'amountMinor' => $movement->balanceAfter->minorUnits(),
                'currency' => $currency,
                'minorUnit' => $minorUnit,
            ],
            'description' => $movement->description,
            'sourceRevisionId' => $movement->sourceRevisionId,
            'occurredAt' => ($movement->occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * The contract's `AccountMutationResult`.
     *
     * @return array<string, mixed>
     */
    public function mutation(AccountMovement $movement, PettyCashAccount $account): array
    {
        return [
            'account' => $this->account($account),
            'movement' => $this->movement($movement, $account),
        ];
    }
}
