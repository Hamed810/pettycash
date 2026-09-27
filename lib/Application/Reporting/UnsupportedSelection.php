<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use InvalidArgumentException;

/**
 * A requested export selection that this endpoint cannot honour.
 *
 * A distinct type rather than a bare `InvalidArgumentException` so the controller can answer
 * "this selection is not supported" with a 422 and a reason, separately from a malformed request,
 * while both still land on the validation path the contract declares for `POST /exports`.
 *
 * The whole point is that it is *thrown*. A selection the endpoint cannot satisfy must never produce
 * an artifact: an export that ignores the ids and filters it was given is a wrong answer delivered
 * successfully, with `rowCount` and `byteSize` attesting to it (T302).
 */
final class UnsupportedSelection extends InvalidArgumentException
{
}
