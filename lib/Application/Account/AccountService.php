<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Account;

use InvalidArgumentException;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;

/**
 * Read access to a user's own petty-cash accounts.
 *
 * A purchaser may only ever see and select their own accounts, so the acting user is supplied by the
 * trusted controller from the authenticated session and is never taken from a request parameter. A
 * `userId` filter is accepted only when it names the caller, because the endpoint may not be used to
 * read another user's balances.
 */
final class AccountService
{
    public function __construct(
        private AccountRepository $accounts
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
}
