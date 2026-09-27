<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\DataIntegrity;

use LogicException;

/**
 * A stored record cannot be read back because the data behind it is incomplete.
 *
 * ## Why this is a type and not a bare LogicException
 *
 * `DbTransactionRevisionRepository` threw a plain `LogicException` when a revision's currency could not
 * be resolved. Every caller therefore had to guess what it meant, and `OcsErrorMapper` had to classify
 * it by exception class alone -- which is how a persistence fault came to be reported to callers as
 * `403 Forbidden` with its internal message attached (T029).
 *
 * A dedicated type lets a caller that *can* do something about it -- a report, which can explain itself
 * -- recognise it, while every other caller keeps the classification it had. It deliberately extends
 * `LogicException` so that behaviour is unchanged everywhere else: `OcsErrorMapper` no longer maps
 * `LogicException` to 403 (T029), so those callers still answer 500 and still log in full.
 *
 * ## The message is for a log, not for a client
 *
 * It names the surrogate ids, which is exactly what an operator needs and exactly what a caller must
 * not be given. A response built from this message is a *different* string; see `ReportController`,
 * which answers 422 with a message that says what is wrong without saying which row.
 */
final class UnreadableRecordData extends LogicException
{
}
