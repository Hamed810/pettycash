<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Evidence;

use OCA\PettyCash\Application\Refusal;
use RuntimeException;

/**
 * FR-031: a public-link administration command that cannot be carried out.
 *
 * ## Why these are refusals and not conflicts
 *
 * Every one of these is "the request is correct and the application cannot serve it as asked":
 *
 * - a **non-administrator** asking is not a stale-resource conflict, and answering `403` through the
 *   usual authorisation path would be equally right — this type exists so the *service* refuses rather
 *   than relying on each controller to remember, which is how `ConfigurationController`'s docblock
 *   describes its own arrangement ("each service enforces that itself through `AdministratorCheck`").
 * - an **expired-by-omission**, **past**, or **over-ceiling** `expiresAt` is not invalid input in the
 *   `VALIDATION_FAILED` sense either. `expiresAt` is a well-formed date; what is wrong is the
 *   configuration the administrator asked for, and `422` with this code says "change the request"
 *   where `VALIDATION_FAILED` says the same thing about a typo.
 *
 * `Refusal` is mapped to `422` with this exception's own code, so a client can tell "you may not do
 * that" from "that expiry is not one we will grant" without parsing prose.
 */
final class EvidenceLinkRefusal extends RuntimeException implements Refusal
{
    public static function becauseCreationIsAdministratorsOnly(): self
    {
        return new self('Creating a public evidence link is restricted to administrators.');
    }

    public static function becauseDeactivationIsAdministratorsOnly(): self
    {
        return new self('Deactivating a public evidence link is restricted to administrators.');
    }

    public static function becauseTheEvidenceDoesNotExist(): self
    {
        return new self('Not found');
    }

    /**
     * `404` rather than a named reason, for the same reason consumption is: the public path's refusals
     * must not confirm that evidence exists. This is only reachable by an authenticated administrator
     * who can already enumerate evidence, so the cost of the ambiguity is low — but consistency is
     * cheaper still than a second rule to remember.
     */
    public static function becauseTheEvidenceIsDeactivated(string $evidencePublicId): self
    {
        return new self(sprintf(
            'Evidence %s has been deactivated, so a public link to it could never be opened. Reactivate '
            . 'the evidence before creating a link.',
            $evidencePublicId,
        ));
    }

    public static function becauseTheLinkDoesNotExist(): self
    {
        return new self('Not found');
    }

    public static function becauseTheExpiryIsNotADate(string $field): self
    {
        return new self(sprintf('`%s` must be an ISO-8601 date-time.', $field));
    }

    public static function becauseTheExpiryIsInThePast(): self
    {
        return new self('`expiresAt` is in the past. A link that has already lapsed cannot be created.');
    }

    /**
     * @param string $requested what was asked for
     * @param string $ceiling   the furthest expiry that would be granted
     */
    public static function becauseTheExpiryExceedsTheCeiling(string $requested, string $ceiling): self
    {
        return new self(sprintf(
            '`expiresAt` of %s exceeds the maximum link lifetime of %s. Shorten it, or create a link that '
            . 'expires sooner and issue a new one later — the ceiling is refused rather than clamped, so '
            . 'the TTL you are given is always the one you asked for.',
            $requested,
            $ceiling,
        ));
    }

    public function errorCode(): string
    {
        return 'EVIDENCE_LINK_REFUSED';
    }
}