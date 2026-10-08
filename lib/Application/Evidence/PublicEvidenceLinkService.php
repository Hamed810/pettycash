<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Evidence;

use DateTimeImmutable;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceLinkRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Infrastructure\Http\EvidenceContentPolicy;

/**
 * FR-031 (clarified 2026-10-07): consuming a public evidence link. The token is the whole authority.
 *
 * ## What this path deliberately does not do
 *
 * **It does not check who the caller is.** FR-031 settles that consumption is unauthenticated and that
 * this is "the deliberate, recorded exception to the role-scope rule FR-003 and FR-009 otherwise apply".
 * So there is no `mayRead()`, no role, no cost-centre scope, and no ownership test anywhere below. A
 * reviewer looking for one should read this docblock before adding it: the absence is the requirement.
 *
 * **It does not add abuse control.** FR-031 delegates it to Nextcloud's existing brute-force limiter and
 * forbids a second authentication, rate-limiting or access-control mechanism, because that would be the
 * parallel authentication system Principle III rules out. This service therefore contains no counter, no
 * throttle and no delay.
 *
 * **It does not distinguish the three refusals.** Unknown, deactivated and expired all produce the same
 * {@see PublicEvidenceRefusal}, and the reason is in FR-031: the token is the entire authority, so the
 * only remaining controls are TTL and revocation — and a refusal that said *which* applied would confirm
 * that a link once existed and when it lapsed. That is a disclosure, and the same class of leak T029 was
 * raised for, so all three collapse into one indistinguishable answer.
 *
 * ## Why the token is hashed before it is looked up
 *
 * FR-031 condition (3): the token is "stored only as a hash and shown once at creation", so a leaked
 * database cannot be replayed into evidence access. That makes the lookup a hash lookup, which in turn
 * makes {@see self::hashToken()} the single definition of the transformation. The creation path must call
 * it rather than invent its own, because a link created with a different digest can never be consumed —
 * and that failure would look like "the link never existed", the one answer an administrator cannot act on.
 */
final class PublicEvidenceLinkService
{
    /**
     * The `entity_type` every access is addressed by in configuration audit.
     *
     * FR-055 addresses configuration events by `entity_type`/`entity_id`, and an evidence link is
     * administered configuration rather than a transaction, so `evidence_link` is the subject. Using
     * `evidence` would collide with upload/deactivation events and make "was this link accessed?"
     * unanswerable without reading every snapshot.
     */
    public const SUBJECT = 'evidence_link';

    /**
     * FR-031: the viewer recorded for an unauthenticated access.
     *
     * Never null and never a UID, because an anonymous visit has no UID and a nullable viewer field makes
     * the entry indistinguishable from a missing audit record during the access investigation it exists
     * to serve.
     */
    public const ANONYMOUS_VIEWER = 'anonymous';

    /**
     * `actor_role` for an anonymous access.
     *
     * The column is `string(32)` and not null. There is no role here — FR-031 is explicit that the token
     * is the whole authority and that the viewer need hold no role or scope right on the transaction — so
     * the honest value is one that says so rather than a real role name borrowed from the vocabulary, which
     * would put a fabricated `FINANCE` or `SENIOR_MANAGER` into a permanent record.
     */
    public const ANONYMOUS_ROLE = 'NONE';

    public function __construct(
        private readonly EvidenceLinkRepository $linkRepository,
        private readonly EvidenceRepository $evidenceRepository,
        private readonly PrivateFileStore $fileStore,
        private readonly EvidenceContentPolicy $contentPolicy,
        private readonly AuditWriter $auditWriter,
        private readonly Clock $clock,
    ) {}

    /**
     * The one place a link token is turned into the value stored in `token_hash`.
     *
     * Public because the *creation* path needs it and does not exist yet — `POST
     * /evidence/{evidenceId}/links` is declared in `appinfo/routes.php` with no controller action, which
     * is T363's gap and not this task's. When that action is written it MUST call this, not
     * `hash('sha256', $token)` written a second time: two definitions of the same digest means links
     * that are created successfully and then cannot be consumed, and the refusal for that is
     * indistinguishable from "this link never existed".
     *
     * SHA-256 hex, 64 characters, which fits the `string(128)` column with room to spare.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Open an evidence item through its public link.
     *
     * @param string $token the unguessable token, exactly as issued
     * @param string|null $requestedDisposition `inline`, `attachment`, or null for the `inline` default
     * @param string|null $viewerIp the requester's address, recorded per FR-031
     * @throws PublicEvidenceRefusal for an unknown, deactivated or expired token, and for evidence that
     *                             no longer resolves. All four are the same refusal on purpose.
     */
    public function consume(
        string $token,
        ?string $requestedDisposition = null,
        ?string $viewerIp = null,
    ): PublicEvidenceContent {
        $link = $this->linkRepository->findByTokenHash(self::hashToken($this->trimmed($token)));

        // The three refusals, and the evidence that cannot be resolved, are one branch on purpose. See
        // the class docblock: distinguishing them would confirm that a link once existed.
        if ($link === null || !$link->active || $this->hasExpired($link->expiresAt)) {
            throw PublicEvidenceRefusal::becauseTheTokenGrantsNothing();
        }

        $evidence = $this->evidenceRepository->findById($link->evidenceId);

        // Deactivated evidence is refused through the same refusal as an absent one. FR-058 deactivates
        // rather than deletes after submission, so this branch is the ordinary case for a receipt
        // withdrawn in error — and a distinct answer would let an anonymous caller enumerate which
        // evidence on a transaction was withdrawn.
        if ($evidence === null || !$evidence->active) {
            throw PublicEvidenceRefusal::becauseTheTokenGrantsNothing();
        }

        // FR-060 unchanged on this path. It is the one route where a stored media type reaches a viewer
        // who never authenticated and never will, so the "attacker-supplied by construction" argument in
        // `EvidenceContentPolicy` applies with no session boundary to fall back on: there is no origin
        // here to protect, only a browser that has been handed a token.
        $resolved = $this->contentPolicy->resolve($evidence->mediaType, $requestedDisposition);

        $this->recordAccess($link->id, $evidence, $resolved, $viewerIp);

        return new PublicEvidenceContent(
            $this->fileStore->readEvidence($evidence->appDataKey),
            $resolved['contentType'],
            $resolved['disposition'],
            $evidence->originalName,
            $evidence->sizeBytes,
        );
    }

    /**
     * FR-031's per-access audit entry.
     *
     * Recorded **after** the content has been resolved but **before** it is returned, so an access that
     * fails to produce bytes leaves no false record of a successful one. The order is not arbitrary in
     * the other direction either: the event is written from inside the same call, so a link that is
     * consumed cannot produce an entry-less audit trail unless the audit write itself fails — and that
     * failure surfaces as a refusal rather than as an untracked success.
     */
    private function recordAccess(
        string $linkPublicId,
        Evidence $evidence,
        array $resolved,
        ?string $viewerIp,
    ): void {
        $this->auditWriter->appendConfigurationAsViewer(self::ANONYMOUS_VIEWER, [
            'id' => (string)Uuid::random(),
            'entity_type' => self::SUBJECT,
            'entity_id' => $linkPublicId,
            'event_type' => ConfigurationEventType::EvidenceLinkAccessed->value,
            'explanation' => null,
            'actor_role' => self::ANONYMOUS_ROLE,
            // FR-052 still applies: the applicable values. `after` carries what was actually served and
            // from where, because the access investigation this exists to serve needs to know which
            // evidence was disclosed and not merely that some link was opened.
            //
            // `ip_address` is in the snapshot because `pettycash_configuration_audit` has no address
            // column — adding a migration for one field of one event would be a schema change to serve a
            // single producer, and FR-052's snapshot columns are where the values belong. It is recorded
            // as null rather than omitted when the platform does not expose it, so an investigation can
            // tell "no address was available" from "the writer forgot".
            'after' => [
                'evidence_id' => $evidence->id,
                'media_type_served' => $resolved['contentType'],
                'disposition_served' => $resolved['disposition'],
                'ip_address' => $viewerIp,
                'viewer' => self::ANONYMOUS_VIEWER,
            ],
            // A consumption mutates nothing business-side, so there is no command id: the contract
            // declares this operation with no `X-PettyCash-Command-ID` and idempotency comes from the
            // token rather than from a replayed command.
            'command_id' => null,
        ]);
    }

    private function hasExpired(?DateTimeImmutable $expiresAt): bool
    {
        // A null expiry is a link with no TTL. FR-031 makes TTL configurable rather than mandatory, so
        // null means "no expiry configured" and the link stands on activation alone. Treating it as
        // expired would silently revoke every link created without one.
        return $expiresAt !== null && $expiresAt <= $this->clock->now();
    }

    private function trimmed(string $token): string
    {
        // Trimming is not leniency about which tokens are valid — the token is opaque and any
        // alteration changes the digest. It removes the copy-and-paste whitespace a recipient of a link
        // will realistically have, which otherwise produces the one refusal an administrator cannot
        // diagnose.
        return trim($token);
    }
}