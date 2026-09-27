<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Account;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\PettyCashAccount;

/**
 * The account shapes `contracts/ocs-api.yaml` declares, in one place.
 *
 * ## Why this class is here and not in `Infrastructure\Http`
 *
 * It was added to `lib/Infrastructure/Http/` by T057 to report `AccountMutationResult` on a finance
 * decision, and `AccountService` could not delegate to it: taking an `Infrastructure.Http`
 * dependency from the Application layer inverts the direction the project rules set. So the service
 * kept building the same two arrays itself, and the class ended up documenting the duplication
 * instead of removing it. By T289 there were **three** copies of the account shape and three of the
 * movement shape — two of them inside `AccountService` itself, because `listForUser()` and
 * `listMovements()` built their arrays inline while `createFor()` and `charge()` called private
 * twins.
 *
 * That is precisely the failure `TransactionPayloadFormatter` documents having been born of: the
 * transaction payload had a flat `revision` object, no `allowedActions`, `decisions` or `evidence`,
 * and every affected template dereferenced `undefined` on a live response. A second copy of a
 * payload shape is not a style question; it is a defect waiting for the two copies to differ.
 *
 * The Application layer is the one layer both the service and the controllers can depend on —
 * Presentation depends on Application, which is the direction the rules set — so this is the home
 * where there is exactly one definition. It sits beside `AccountService` rather than in a new
 * `Dto/` directory because `plan.md`'s declared structure reconciliation is T238's open work, and
 * creating a fourth declared-but-missing directory here would quietly take a piece of it.
 *
 * The class is a pure mapping from aggregate to array: it reads the domain and invents no business
 * rule, so both callers get the same `Account` and the same `Movement` for the same aggregate.
 */
final readonly class AccountPayloadFormatter
{
    /**
     * The contract's `Account`.
     *
     * The fraction digits come from the account's own configured currency. `Money` carries the
     * amount and the currency code but not the scale, and a scale guessed from a built-in table is
     * what prints a rial amount a hundred times too small.
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
     * The account is a second argument rather than derived from the movement because the movement
     * carries its account's *public id* and the amount's scale lives on the account's currency: a
     * movement read on its own cannot say how many fraction digits its own figure is in.
     *
     * `occurredAt` is not nullable in the contract. A movement the aggregate has just built has no
     * timestamp until the repository writes one, so the aggregate's own value is preferred and
     * `now` is the fallback.
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
    public function mutation(PettyCashAccount $account, AccountMovement $movement): array
    {
        return [
            'account' => $this->account($account),
            'movement' => $this->movement($movement, $account),
        ];
    }
}
