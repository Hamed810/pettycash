<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Account;

use OCA\PettyCash\Application\Conflict;
use RuntimeException;

/**
 * A user already has an active account in that currency.
 *
 * A distinct type because the contract's answer is a 409 conflict and not a validation failure: the
 * request was well formed and the thing it asked for already exists. FR-014 allows additional
 * accounts, but one *per currency* — `pettycash_accounts_user_currency_uniq` is what enforces that,
 * and this is the application saying so rather than a unique-index error escaping to the caller.
 */
final class AccountAlreadyExists extends RuntimeException implements Conflict
{
    public function errorCode(): string
    {
        return 'ACCOUNT_ALREADY_EXISTS';
    }
}
