<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Evidence;

/**
 * The result of creating a public link: the contract's `EvidenceLink` shape, plus the raw token.
 *
 * ## Why `token` is a separate member and is null on a replay
 *
 * FR-084 requires a retried creation not to duplicate its effect, and FR-031 requires the token to be
 * disclosed **once**. Those two cannot both be satisfied by a stored command result that carries the
 * token, so the token lives outside the idempotency payload entirely: the service returns it on the
 * first attempt and `null` on a replay.
 *
 * `null` means "a link exists but its token is not re-disclosed", **not** "no link". A client that
 * lost the first response is told the link id and its metadata and must rotate the link to get a new
 * token — which is the honest outcome, because re-disclosing a token to whoever asks would make the
 * once-only rule meaningless.
 */
final readonly class CreatedEvidenceLink
{
    /**
     * @param array<string, mixed> $link the contract's `EvidenceLink` members, without `url`
     * @param string|null $token the raw token, or null when this was a replay
     */
    public function __construct(
        public array $link,
        public ?string $token,
    ) {}

    /**
     * The public URL, or `null` on a replay.
     *
     * Built from the token rather than stored, so a URL that would hand over the authority cannot
     * outlive the single response that is allowed to carry it.
     */
    public function url(?string $baseUrl): ?string
    {
        if ($this->token === null || $baseUrl === null) {
            return null;
        }

        return rtrim($baseUrl, '/')
            . '/ocs/v2.php/apps/pettycash/api/v1/public/evidence/'
            . $this->token;
    }
}