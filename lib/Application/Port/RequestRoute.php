<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Port;

/**
 * The route a request arrived on, as a single human-readable string. FR-087.
 *
 * ## Why a port and not `IRequest` injected directly
 *
 * `OcsErrorMapper` originally took `OCP\IRequest`. That is untestable: `tests/bootstrap.php` does not
 * bootstrap Nextcloud, so **no** `OCP\*` class is loadable in a PHPUnit run — verified, not assumed. Any
 * unit test touching the mapper therefore cannot construct it, and FR-087's five required details would
 * have had no executable assertion at all.
 *
 * This is the same reasoning `RequestTimeZone` already records on this port list, for the same reason: a
 * requirement that resolves data *out of the framework request* is a requirement about a collaborator, and
 * the collaborator is what makes it testable.
 *
 * ## What the implementation must guarantee, whoever provides it
 *
 * - **Never throws.** This is called on the failure path, while a fault is already being recorded; a
 *   collaborator that threw here would replace a diagnosable `500` with a second one.
 * - **Returns a non-empty string whenever a request is in scope.** FR-087 names "the route" as a required
 *   detail, so an implementation that returns `''` outside a request context must say so in its own
 *   contract — which is why the docblock here does.
 * - **Includes the method.** `GET` and `DELETE` on the same path fail for different reasons, and an
 *   operator reading a log needs to know which was attempted.
 */
interface RequestRoute
{
    /**
     * Method and path, e.g. `GET /apps/pettycash/api/v1/sheets/abc`.
     *
     * Returns `''` when there is no request in scope (a background or CLI entry point), never `null` —
     * a caller merging this into log context must be able to distinguish "no route" from "no value".
     */
    public function describe(): string;
}