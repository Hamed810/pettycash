<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Evidence;

use RuntimeException;

/**
 * FR-031: this token grants nothing. One type for all four reasons it might.
 *
 * ## Why the reasons are not carried
 *
 * The token is the entire authority on this path, so TTL and revocation are the only controls left — and
 * a refusal that named which control applied would tell an anonymous caller that a link once existed and,
 * for an expired one, roughly when it lapsed. FR-031 requires "the same answer" for unknown, deactivated
 * and expired, and the only way to honour that is for the answer to not carry the reason.
 *
 * So the message is a single fixed sentence with no state in it: not "this link has expired", not "this
 * link was deactivated", not "no such link". A caller learns only that this token does not currently grant
 * access, which is the whole of what they are entitled to know.
 *
 * The same type also covers evidence that no longer resolves, for the same reason: "the link is valid but
 * its evidence was withdrawn" is an enumeration oracle over a transaction's receipts.
 */
final class PublicEvidenceRefusal extends RuntimeException
{
    public const ERROR_CODE = 'NOT_FOUND';

    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * The single refusal. Every branch that could produce it calls this, so there is exactly one string
     * in the codebase for a token that grants nothing, and no branch can drift into a more specific one.
     */
    public static function becauseTheTokenGrantsNothing(): self
    {
        return new self('Not found');
    }
}