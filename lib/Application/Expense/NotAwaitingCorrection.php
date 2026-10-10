<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use OCA\PettyCash\Application\Conflict;
use RuntimeException;

/**
 * FR-032: a correction was requested for a transaction that is not awaiting one.
 *
 * ## Why this is a 409 and not a 422 or a 500
 *
 * It was a bare `LogicException`, and it answered **500 UNEXPECTED_FAILURE** with its message withheld.
 * That is the worst available answer: the request was not a fault, the caller was not forbidden, and the
 * sentence naming the actual obstacle was the one thing suppressed.
 *
 * `409` is the right one of the remaining three because **the caller's view is out of date, not wrong**.
 * The transaction is in some other state — approved, completed, or still in review — and the caller's
 * read of it predates that. `Conflict`'s own docblock states the recovery: reload and try again, and
 * FR-017's change reason makes it obvious to the user. `422` would send the caller to edit a request that
 * needed no correction; `403` would claim a permission problem where there is none.
 */
final class NotAwaitingCorrection extends RuntimeException implements Conflict
{
    public function __construct(
        private readonly string $transactionPublicId,
        private readonly string $currentStatus,
    ) {
        parent::__construct(sprintf(
            'This transaction is in state %s and is not awaiting a purchaser correction, so no correction '
            . 'draft was created. Reload the transaction to see its current state.',
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'TRANSACTION_NOT_AWAITING_CORRECTION';
    }

    public function transactionPublicId(): string
    {
        return $this->transactionPublicId;
    }

    public function currentStatus(): string
    {
        return $this->currentStatus;
    }
}