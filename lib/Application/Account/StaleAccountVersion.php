<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Account;

use OCA\PettyCash\Application\Conflict;
use RuntimeException;

/**
 * The account moved on between the caller reading it and charging it.
 *
 * Distinct from every other failure here because the contract's answer is a 409 and the caller's next
 * action is different: reload and retry with the new version. Funding is the one write two finance
 * officers make at the same moment, and a lost update here is money that vanishes from the ledger —
 * so the version is checked rather than trusted.
 */
final class StaleAccountVersion extends RuntimeException implements Conflict
{
    public function errorCode(): string
    {
        return 'ACCOUNT_VERSION_CONFLICT';
    }
}
