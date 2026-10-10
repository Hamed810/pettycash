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
 * A dedicated type lets the caller that *can* do something about it recognise it, and lets the
 * translation live in one place rather than in each controller that happens to be on the path. FR-083
 * requires exactly that: the same fault had two answers depending on the endpoint -- the report
 * endpoints returned 422 because `ReportController` translated it, while sheets and dashboard fell
 * through to the default mapper's 500 -- so `OcsErrorMapper` now classifies this type once, for every
 * caller. It deliberately extends `LogicException` and is checked *before* the `InvalidArgumentException`
 * arm, because it is not the caller's fault and must never be answered as a validation failure.
 *
 * ## The message is for a log, not for a client
 *
 * It names the surrogate ids, which is exactly what an operator needs and exactly what a caller must
 * not be given. `OcsErrorMapper` answers 422 with its own canonical text and logs this one in full --
 * see its `DATA_FAULT_MESSAGE`. A response built from *this* message is a disclosure, which is the
 * defect T029 was raised for.
 */
final class UnreadableRecordData extends LogicException
{
}
