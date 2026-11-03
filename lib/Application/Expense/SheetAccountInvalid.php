<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use InvalidArgumentException;

/**
 * A sheet update that tried to change the account the sheet was created against.
 *
 * The account and currency of a sheet are immutable after creation (FR-019 / FR-021). A supplied
 * `accountId` is not an alternative value and is not the stored value restated: it is a field the
 * update contract does not accept at all. The refusal is therefore a field-level validation failure,
 * not a conflict and not an "unchanged" no-op, so the caller learns which field was rejected.
 *
 * `fieldErrors()` keeps the key `accountId` for the same reason `SubmissionBlocked` keeps its
 * transaction keys: the client already renders field errors by key, and collapsing this one into a
 * string-only message would erase the only part of the answer a form can attach to a control.
 */
final class SheetAccountInvalid extends InvalidArgumentException
{
    public const FIELD_MESSAGE = 'The account of a sheet cannot be changed';

    /**
     * @param array<string, list<string>> $fieldErrors
     */
    public function __construct(
        private readonly array $fieldErrors = ['accountId' => [self::FIELD_MESSAGE]],
        string $message = self::FIELD_MESSAGE
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
