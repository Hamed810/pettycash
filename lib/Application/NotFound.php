<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application;

use Throwable;

/**
 * The resource the caller addressed does not exist, or must be reported as if it does not.
 *
 * ## Why this marker exists
 *
 * `OcsErrorMapper` maps `InvalidArgumentException` to `422 VALIDATION_FAILED`. That is the right answer
 * for a field the caller can correct, and the wrong answer for a record that is simply not there:
 * FR-025's replay-safe transaction delete must distinguish "your request was malformed" from "this row
 * is already gone", and the contract declares `404 NOT_FOUND` for the latter.
 *
 * A marker type keeps that distinction a property of the exception rather than of one controller's
 * `catch` chain. It also keeps the disclosure rule honest: an absent record and another owner's record
 * answer identically, so a caller cannot probe for the existence of a row it may not see (FR-003).
 *
 * The HTTP code is fixed (`NOT_FOUND`), unlike `Conflict`/`Refusal`/`Forbidden`, whose error codes vary
 * by the business rule that raised them. There is only one 404 code in the contract, so this interface
 * carries no `errorCode()`.
 */
interface NotFound extends Throwable
{
}
