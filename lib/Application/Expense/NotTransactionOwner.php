<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use OCA\PettyCash\Application\Forbidden;
use RuntimeException;

/**
 * FR-032: only the transaction's owner may draft a correction for it.
 *
 * ## Why 403 and the reason it is not 404
 *
 * It was a bare `LogicException`, and it answered **500 UNEXPECTED_FAILURE** with its message withheld —
 * so a purchaser who was not the owner of a transaction was told the server had a fault. It is a
 * permission refusal, and `403` is the answer for that.
 *
 * `404` is deliberately *not* used, and the contrast with the evidence path is the point.
 * `EvidenceController::content()` answers `404` for an out-of-scope caller because there the record's
 * existence is itself the thing being protected and the caller may have no right to know it exists. A
 * correction is requested against a transaction the caller has already been shown, so nothing needs
 * concealing — and `403` is what tells a purchaser that the endpoint exists and the obstacle is
 * ownership, which is something they can act on. Answering `404` would send them looking for a
 * transaction that is sitting right there.
 */
final class NotTransactionOwner extends RuntimeException implements Forbidden
{
    public function __construct()
    {
        parent::__construct(
            'Only the purchaser who owns a transaction can draft a correction for it.'
        );
    }

    public function errorCode(): string
    {
        return 'NOT_TRANSACTION_OWNER';
    }
}