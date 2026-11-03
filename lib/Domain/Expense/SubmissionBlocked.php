<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense;

use InvalidArgumentException;

/**
 * A complete sheet or correction that is refused because an expense type requires a receipt and the
 * transaction has none active.
 *
 * This is a validation failure, not a conflict: the caller can attach the missing receipt and retry
 * without reloading. It carries every offending transaction rather than only the first, because a
 * purchaser who fixes one transaction at a time would otherwise be refused as many times as the sheet
 * has offenders.
 *
 * The field key is the same one the client's readiness surface uses:
 * `transactions.<transactionId>.evidence`.
 */
final class SubmissionBlocked extends InvalidArgumentException
{
    /**
     * The caller-facing explanation for one offending transaction. Shared so sheet submission and
     * correction resubmission cannot drift into two different sentences for the same rule.
     */
    public const EVIDENCE_FIELD_MESSAGE = 'A receipt is required for this transaction';

    /**
     * @param array<string, list<string>> $fieldErrors
     */
    public function __construct(
        private readonly array $fieldErrors,
        string $message = 'Required receipts are missing'
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, list<string>>
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
