<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use OCA\PettyCash\Application\NotFound;
use RuntimeException;

/**
 * A transaction the caller addressed is absent, another owner's, or already deleted.
 *
 * All three answer the same way on purpose. FR-003 requires another user's record to be reported as
 * absent rather than forbidden, and FR-025's delete replay requires a *different* command id after a
 * successful delete to answer `404` rather than a second `204`. Keeping one type for all three is what
 * makes those indistinguishable to a caller.
 *
 * The message is the exact phrase the endpoints already used ("Transaction not found"), so existing
 * caller-facing text does not change when the status moves from `422` to the contract's `404`.
 */
final class TransactionNotFound extends RuntimeException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Transaction not found');
    }
}
