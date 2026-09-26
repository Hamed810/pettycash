<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Account;

use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;

/**
 * Read access to a user's own petty-cash accounts and their ledgers.
 *
 * A purchaser may only ever see and select their own accounts, so the acting user is supplied by the
 * trusted controller from the authenticated session and is never taken from a request parameter. A
 * `userId` filter is accepted only when it names the caller, because the endpoint may not be used to
 * read another user's balances.
 */
final class AccountService
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountMovementRepository $movements
    ) {}

    /**
     * The caller's active accounts, in the shape `contracts/ocs-api.yaml` requires: an `Account`
     * carrying a `Money` balance whose `minorUnit` is the configured fraction digits for that
     * currency, never a value guessed from a built-in table.
     *
     * An empty page is a valid answer: having no account is a normal state the purchaser UI must be
     * able to render, not an error. Only the active projection is exposed, which is what selecting
     * an account for a new sheet requires; listing inactive accounts is part of the finance and
     * administration surface owned by T085 and T088.
     *
     * @return array{items: list<array{id: string, userId: string, balance: array{amountMinor: int, currency: string, minorUnit: int}, active: bool, version: int}>, page: int, pageSize: int, total: int}
     */
    public function listForUser(string $userUid, int $page = 1, int $pageSize = 25): array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be 1 or greater');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100');
        }

        $items = [];
        foreach ($this->accounts->findActiveByUserUid($userUid) as $account) {
            $items[] = [
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

        $total = count($items);

        return [
            'items' => array_slice($items, ($page - 1) * $pageSize, $pageSize),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * One account's ledger, in the shape `contracts/ocs-api.yaml` requires: a `Movement` per row,
     * carrying the `Money` shape the purchaser and finance views format at the currency's own
     * configured scale.
     *
     * Ownership is enforced here rather than in the controller, because the account's public id
     * arrives from the request and the ledger is the most sensitive read in the application: a
     * ledger states what a named person was given and what they spent. An account belonging to
     * somebody else is refused with the same error as one that does not exist, so the endpoint
     * cannot be used to discover which account ids are real.
     *
     * `total` is the account's full movement count rather than the size of the requested page,
     * which is what lets the view offer pagination and say how much history exists.
     *
     * @return array{items: list<array{id: string, type: string, amount: array{amountMinor: int, currency: string, minorUnit: int}, balanceAfter: array{amountMinor: int, currency: string, minorUnit: int}, description: string, sourceRevisionId: ?string, occurredAt: string}>, page: int, pageSize: int, total: int}
     */
    public function listMovements(
        string $userUid,
        string $accountPublicId,
        int $page = 1,
        int $pageSize = 25
    ): array {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be 1 or greater');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100');
        }

        $account = $this->accounts->findByPublicId($accountPublicId);
        if ($account === null || $account->userUid !== $userUid) {
            // One message for both cases on purpose: distinguishing them would turn this endpoint
            // into an oracle for which account ids exist.
            throw new LogicException('Account not found for the authenticated user');
        }
        if ($account->dbId === null) {
            throw new LogicException('Account has not been persisted');
        }

        // The fraction digits come from the account's own currency configuration. `Money` carries the
        // amount and the currency code but not the scale, and a scale guessed from a built-in table
        // is what prints a rial amount a hundred times too small.
        $minorUnit = $account->currency->minorUnit;
        $currency = (string) $account->currencyCode;

        $all = $this->movements->findByAccountId($account->dbId);
        $total = count($all);
        // One query for the whole ledger and slice it, the same trade `listForUser` makes: the
        // alternative is a count query plus a paged one, and the contract needs `total` for the
        // page metadata regardless, so the single read is both cheaper and simpler to reason about.
        $ledgerPage = array_slice($all, ($page - 1) * $pageSize, $pageSize);

        $items = [];
        foreach ($ledgerPage as $movement) {
            $items[] = [
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
                // An unpersisted movement carries no timestamp; `now` is wrong for it, but a movement
                // read back from the database always has one, and the field is not nullable in the
                // contract, so the aggregate's own value is preferred and only then defaulted.
                'occurredAt' => ($movement->occurredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->format(\DateTimeInterface::ATOM),
            ];
        }

        return [
            'items' => $items,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ];
    }
}
