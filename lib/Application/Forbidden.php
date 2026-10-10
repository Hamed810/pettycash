<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application;

/**
 * The caller may not act on this record, whatever they asked for.
 *
 * ## Why this exists, and why it is a marker rather than a `LogicException`
 *
 * FR-031/FRA-011 style authorisation refusals were reaching the client as **HTTP 500**. The mechanism is
 * the same one T398 fixed on the finance path, and it is worth stating once: `CreateCorrectionDraft`
 * threw a bare `LogicException('Only the transaction owner can create a correction')`,
 * `OcsErrorMapper` deliberately has **no** `LogicException` arm, and the default arm is `500
 * UNEXPECTED_FAILURE` with the message withheld.
 *
 * So a purchaser calling an endpoint they are not the owner of was told *the server had a fault*, and
 * told nothing about why. That is wrong twice: the request was not a fault, and the withheld message is
 * the only thing that would have said so.
 *
 * A bare `LogicException` cannot carry the distinction because `InvalidArgumentException` extends it —
 * which is why `OcsErrorMapper`'s `Conflict` docblock and T063's bug are both about this. Adding a
 * third marker is the same shape as `Conflict` (409) and `Refusal` (422): a type the mapper maps, so a
 * refusal cannot be reported as a fault by accident.
 *
 * **403 rather than 404, deliberately.** The 404 treatment belongs on evidence and public-link paths,
 * where the record's *existence* is itself the thing being protected and the caller may not have any
 * right to know it exists. A correction is requested by public id against a transaction the caller has
 * already been shown, so there is nothing to conceal — and answering `403` is what tells a purchaser
 * the endpoint exists and the obstacle is ownership, which is actionable in a way `404` is not.
 */
interface Forbidden
{
    /**
     * The contract's error code, stable enough for a client to branch on.
     */
    public function errorCode(): string;
}