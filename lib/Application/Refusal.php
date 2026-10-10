<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application;

/**
 * A well-formed request that cannot be carried out because the application's own state is not ready.
 *
 * ## Where this sits relative to `Conflict` and to validation
 *
 * There are three answers an endpoint can give, and PHP's exception hierarchy makes all three easy to
 * conflate:
 *
 * - **The caller's input is wrong** — `InvalidArgumentException`, answered `422 VALIDATION_FAILED`.
 *   Sending back a correction fixes it.
 * - **The resource moved under the caller** — [`Conflict`](Conflict.php), answered `409`. Reloading
 *   and retrying fixes it.
 * - **The request is correct and the server is not in a state to serve it** — this interface, answered
 *   `422` with its own code. Neither sending back a correction nor retrying fixes it; somebody has to
 *   change the configuration first.
 *
 * The third case is the one that had no home. It used to be a bare `LogicException`, and
 * `OcsErrorMapper` deliberately has no `LogicException` arm — the right decision for a fault nobody
 * can act on, and the wrong outcome for a business rule that somebody *can* act on. FR-040 is that
 * case: a finance approval is correct and well formed, and the purchaser's petty-cash account for the
 * sheet's currency has been deactivated since the sheet was created. Answering `500` told finance the
 * server was broken; answering `VALIDATION_FAILED` told them to change a request that was already
 * right. Both send them looking for the fault in the wrong place.
 *
 * ## Why 422 rather than 409
 *
 * `Conflict` answers 409 because the caller's recovery is "reload and try again". There is nothing to
 * reload here — the request is not out of date, the configuration is missing — so retrying unchanged
 * would fail identically forever. 422 is the status that says *this cannot be processed as written*,
 * and the message carries what has to change.
 *
 * ## Why the code is the exception's own
 *
 * Exactly as `Conflict` documents: `OcsErrorMapper` needs a code, not a table of classes, and a code
 * supplied at the throw site keeps `ACCOUNT_NOT_SETTLEABLE` distinguishable to a client without the
 * mapper growing an arm per exception.
 */
interface Refusal
{
    /**
     * The contract's error code, stable enough for a client to branch on.
     */
    public function errorCode(): string;
}