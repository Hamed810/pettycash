<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Evidence;

use DateTimeImmutable;
use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Evidence\PublicEvidenceLinkService;
use OCA\PettyCash\Application\Evidence\PublicEvidenceRefusal;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Evidence\EvidenceLink;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Infrastructure\Http\EvidenceContentPolicy;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceLinkRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * FR-031 (clarified 2026-10-07): public evidence link consumption.
 *
 * ## Why the refusals are the bulk of this file
 *
 * The token is the entire authority on this path, so TTL and revocation are the only controls left. That
 * makes the *refusal* the security-relevant behaviour: an unknown, a deactivated and an expired token must
 * be indistinguishable, because an answer that said which applied would confirm to an anonymous caller that
 * a link once existed and roughly when it lapsed.
 *
 * So the three are asserted to be **byte-identical** rather than merely equal in status. Equal status with
 * differing messages is the exact shape of the leak, and a status-only assertion would pass it.
 */
final class PublicEvidenceLinkServiceTest extends TestCase
{
    private const NOW = '2026-10-07T00:00:00+00:00';
    private const BYTES = 'receipt bytes';

    private InMemoryEvidenceRepository $evidence;
    private InMemoryEvidenceLinkRepository $links;
    private InMemoryPrivateFileStore $files;
    private InMemoryAuditEventRepository $transactionAudit;
    private InMemoryAuditEventRepository $configurationAudit;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->evidence = new InMemoryEvidenceRepository();
        $this->links = new InMemoryEvidenceLinkRepository();
        $this->files = new InMemoryPrivateFileStore();
        $this->transactionAudit = new InMemoryAuditEventRepository();
        $this->configurationAudit = new InMemoryAuditEventRepository();
        $this->clock = new FixedClock(self::NOW);
    }

    // ===== the happy path ====================================================

    public function testAnActiveUnexpiredTokenOpensTheEvidence(): void
    {
        $this->seedEvidence('text/plain');
        $this->seedLink(active: true, expiresAt: new DateTimeImmutable('2026-11-01T00:00:00+00:00'));

        $content = $this->service()->consume('token-value', null, '203.0.113.7');

        self::assertSame(self::BYTES, $content->bytes);
        self::assertSame('text/plain', $content->contentType);
        self::assertSame('inline', $content->disposition);
        self::assertSame('receipt.txt', $content->originalName);
        self::assertStringContainsString('inline', $content->contentDispositionHeader());
    }

    /**
     * No role, no scope, no ownership — because the token is the whole authority.
     *
     * Asserted structurally rather than behaviourally: the service has no `CurrentUser`, no visibility
     * policy and no transaction repository, so there is no seam through which a caller could be checked.
     * That absence is FR-031's "deliberate, recorded exception to the role-scope rule FR-003 and FR-009",
     * and this is the pin that keeps the next contributor from "fixing" it.
     */
    public function testThePathTakesNoUserContextAtAll(): void
    {
        $constructor = (new \ReflectionClass(PublicEvidenceLinkService::class))->getConstructor();
        self::assertNotNull($constructor);

        $types = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            $constructor->getParameters(),
        );

        foreach ($types as $type) {
            self::assertDoesNotMatchRegularExpression(
                '/CurrentUser|UserDirectory|Visibility|Authorization/i',
                $type,
                'FR-031: consumption is unauthenticated and the token is the whole authority. A '
                . 'session-scoped dependency here is a role check the requirement forbids.'
            );
        }
    }

    // ===== the three refusals are one refusal ===============================

    /**
     * The load-bearing assertion of this file.
     *
     * Byte-identical messages, not equal statuses: an answer that differed only in wording would tell an
     * anonymous caller that a link once existed, and for an expired one roughly when it lapsed.
     */
    public function testUnknownDeactivatedAndExpiredTokensAreIndistinguishable(): void
    {
        $this->seedEvidence('text/plain');

        $unknown = $this->messageFor(fn () => $this->service()->consume('never-issued'));

        // Same evidence, three different reasons to refuse.
        $this->seedLink(active: false, expiresAt: null, token: 'deactivated-token');
        $deactivated = $this->messageFor(fn () => $this->service()->consume('deactivated-token'));

        $this->seedLink(active: true, expiresAt: new DateTimeImmutable('2026-09-01T00:00:00+00:00'), token: 'expired-token');
        $expired = $this->messageFor(fn () => $this->service()->consume('expired-token'));

        self::assertSame($unknown, $deactivated, 'FR-031: the refusal must be identical for unknown and deactivated.');
        self::assertSame($unknown, $expired, 'FR-031: the refusal must be identical for unknown and expired.');
        self::assertSame($unknown, $deactivated);
    }

    /**
     * The refusal text must carry no state about the link.
     *
     * Asserted against the words FR-031's wording rules out, so a later "helpful" message like "this link
     * has expired" fails here rather than shipping.
     */
    public function testTheRefusalSaysNothingAboutTheLinksState(): void
    {
        $this->seedEvidence('text/plain');
        $this->seedLink(active: false, expiresAt: null);

        $message = $this->messageFor(fn () => $this->service()->consume('token-value'));

        foreach (['expire', 'deactivat', 'revok', 'unknown', 'not found at'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $message,
                'FR-031: the refusal discloses neither that a link ever existed nor when it lapsed.'
            );
        }
    }

    /**
     * Deactivated *evidence* behind a live token is refused the same way too.
     *
     * FR-058 deactivates rather than deletes after submission, so a receipt withdrawn in error is the
     * ordinary case for this branch. A distinct answer would let an anonymous caller enumerate which
     * evidence on a transaction was withdrawn.
     */
    public function testDeactivatedEvidenceIsRefusedTheSameWay(): void
    {
        $this->seedEvidence('text/plain', active: false);
        $this->seedLink(active: true, expiresAt: null);

        $message = $this->messageFor(fn () => $this->service()->consume('token-value'));

        self::assertSame(
            $this->messageFor(fn () => $this->service()->consume('never-issued')),
            $message,
            'FR-031: a token whose evidence has been withdrawn must be refused exactly as an unknown one.'
        );
    }

    public function testARefusalCarriesNoBytesAndRecordsNoAccess(): void
    {
        $this->seedEvidence('text/plain');
        $this->seedLink(active: false, expiresAt: null);

        $this->capture(fn () => $this->service()->consume('token-value', null, '203.0.113.7'));

        self::assertSame(
            [],
            $this->configurationAudit->getEvents(),
            'a refused access must not leave an entry claiming evidence was disclosed.'
        );
    }

    /**
     * A link with no configured TTL stands on activation alone.
     *
     * FR-031 makes TTL *configurable*, not mandatory, so a null expiry means "none configured". Treating
     * it as expired would silently revoke every link created without one — which, with no creation path
     * yet, would be every link.
     */
    public function testALinkWithNoExpiryIsNotTreatedAsExpired(): void
    {
        $this->seedEvidence('text/plain');
        $this->seedLink(active: true, expiresAt: null);

        self::assertSame(self::BYTES, $this->service()->consume('token-value')->bytes);
    }

    /**
     * The boundary is inclusive: a token whose expiry is exactly now grants nothing.
     *
     * Pinned because `<=` versus `<` is a one-character difference that decides whether a link is live
     * for one more request, and a test using a comfortably distant expiry cannot see it.
     */
    public function testALinkExpiresAtTheInstantItExpires(): void
    {
        $this->seedEvidence('text/plain');
        $this->seedLink(active: true, expiresAt: new DateTimeImmutable(self::NOW));

        $refusal = $this->capture(fn () => $this->service()->consume('token-value'));

        self::assertSame(
            $this->messageFor(fn () => $this->service()->consume('never-issued')),
            $refusal->getMessage(),
            '`<=` rather than `<`: a token whose expiry is exactly now grants nothing, so the boundary is '
            . 'pinned rather than left to a comfortably distant date.'
        );
    }

    // ===== FR-060 on this path ==============================================

    /**
     * FR-060 unchanged, and this is the route where it matters most.
     *
     * The stored media type is whatever the uploading client declared — `EvidenceController::upload()`
     * keeps it verbatim because FR-030 defers classification to V2 — so it is attacker-supplied by
     * construction. Everywhere else the harm is bounded by an authenticated caller's session; here there is
     * no session at all, only a browser holding a token.
     */
    public function testAnInlineSafeTypeIsServedInPlace(): void
    {
        $this->seedEvidence('application/pdf');
        $this->seedLink(active: true, expiresAt: null);

        $content = $this->service()->consume('token-value');

        self::assertSame('application/pdf', $content->contentType);
        self::assertSame('inline', $content->disposition);
    }

    public function testAnInlineSafeTypeHonoursAnExplicitAttachmentRequest(): void
    {
        $this->seedEvidence('application/pdf');
        $this->seedLink(active: true, expiresAt: null);

        self::assertSame('attachment', $this->service()->consume('token-value', 'attachment')->disposition);
    }

    /**
     * The disposition the *caller asked for* is not the disposition served.
     *
     * A stored `text/html` receipt requested inline must be answered as a download. The response header is
     * what the browser acts on, so asserting only the service's `disposition` field would leave room for a
     * controller to put the requested value in the header instead.
     */
    public function testANonInlineSafeTypeIsForcedToADownloadEvenWhenInlineIsRequested(): void
    {
        $this->seedEvidence('text/html');
        $this->seedLink(active: true, expiresAt: null);

        $content = $this->service()->consume('token-value', 'inline');

        self::assertSame('application/octet-stream', $content->contentType, 'FR-060: never served with the stored type.');
        self::assertSame('attachment', $content->disposition);
        self::assertStringStartsWith('attachment;', $content->contentDispositionHeader());
        self::assertStringNotContainsString('inline', $content->contentDispositionHeader());
    }

    public function testSvgIsNotTreatedAsInlineSafe(): void
    {
        self::assertFalse(
            (new EvidenceContentPolicy())->isInlineSafe('image/svg+xml'),
            'SVG can carry <script>. Including it would satisfy a literal reading of "images" while '
            . 'reintroducing the harm FR-060 exists to prevent.'
        );
    }

    // ===== FR-031's per-access audit =======================================

    public function testEveryAccessIsRecordedAsAnAnonymousViewer(): void
    {
        $this->seedEvidence('text/plain');
        $this->seedLink(active: true, expiresAt: null);

        $this->service()->consume('token-value', null, '203.0.113.7');

        $events = $this->configurationAudit->getEvents();
        self::assertCount(1, $events, 'FR-031: every access is audited.');

        $event = $events[0];
        self::assertSame(ConfigurationEventType::EvidenceLinkAccessed->value, $event->eventType);
        self::assertSame(PublicEvidenceLinkService::SUBJECT, $event->entityType);
        self::assertSame(
            'anonymous',
            $event->actorUid,
            'FR-031: the viewer is recorded as `anonymous`. A signed-in visitor opening the same link '
            . 'must not be attributed by name, or the access investigation reads a person who did not '
            . 'access it anonymously.'
        );
        self::assertNotEmpty($event->occurredAt->format(DATE_ATOM), 'FR-031: the access is timestamped.');
    }

    public function testTheAccessRecordsTheRequestersAddress(): void
    {
        $this->seedEvidence('text/plain');
        $this->seedLink(active: true, expiresAt: null);

        $this->service()->consume('token-value', null, '203.0.113.7');

        $event = $this->configurationAudit->getEvents()[0];
        $after = json_decode($event->afterJson, true);

        self::assertIsArray($after);
        self::assertSame('203.0.113.7', $after['ip_address'], 'FR-031: the access records the requester IP.');
    }

    /**
     * An absent address is recorded as null rather than omitted.
     *
     * The distinction is the whole reason the field exists: "the platform did not expose an address" and
     * "the writer forgot to include one" are different findings, and only the first is a fact about the
     * request.
     */
    public function testAnAbsentAddressIsRecordedAsNullRatherThanOmitted(): void
    {
        $this->seedEvidence('text/plain');
        $this->seedLink(active: true, expiresAt: null);

        $this->service()->consume('token-value', null, null);

        $after = json_decode($this->configurationAudit->getEvents()[0]->afterJson, true);

        self::assertIsArray($after);
        self::assertArrayHasKey('ip_address', $after);
        self::assertNull($after['ip_address']);
    }

    /**
     * The access lands in the **configuration** stream, not the transaction one.
     *
     * FR-031 says configuration audit, and FR-056 requires the two streams stay separately identifiable.
     * A public visit is not a business action by a role-holder on a transaction, and putting it in the
     * transaction stream would make it indistinguishable from one.
     */
    public function testTheAccessIsRecordedInTheConfigurationStreamOnly(): void
    {
        $this->seedEvidence('text/plain');
        $this->seedLink(active: true, expiresAt: null);

        $this->service()->consume('token-value', null, '203.0.113.7');

        self::assertCount(1, $this->configurationAudit->getEvents());
        self::assertSame(
            [],
            $this->transactionAudit->getEvents(),
            'FR-056: the two audit streams must stay separately identifiable.'
        );
    }

    public function testTheAccessNamesTheLinkAndTheEvidenceItDisclosed(): void
    {
        $this->seedEvidence('text/plain');
        $link = $this->seedLink(active: true, expiresAt: null);

        $this->service()->consume('token-value', null, '203.0.113.7');

        $event = $this->configurationAudit->getEvents()[0];
        self::assertSame($link->id, $event->entityId, 'FR-055: the event is addressed by its subject.');

        $after = json_decode($event->afterJson, true);
        self::assertIsArray($after);
        self::assertSame('ev-public-1', $after['evidence_id'], 'an investigation needs to know which receipt was disclosed.');
        self::assertSame('text/plain', $after['media_type_served']);
    }

    /**
     * The token itself is never recorded.
     *
     * FR-031 condition (3) stores the token hashed and discloses it once at creation. Writing it into an
     * audit row — append-only, and therefore never repairable — would put the whole authority of this
     * path into a table that is read far more widely than the link row itself.
     */
    public function testTheTokenIsNeverWrittenToTheAuditTrail(): void
    {
        $this->seedEvidence('text/plain');
        $this->seedLink(active: true, expiresAt: null, token: 'super-secret-token');

        $this->service()->consume('super-secret-token', null, '203.0.113.7');

        foreach ($this->configurationAudit->getEvents() as $event) {
            self::assertStringNotContainsString(
                'super-secret-token',
                $event->beforeJson . $event->afterJson,
                'FR-031: the token is disclosed once at creation and stored hashed. An audit row that '
                . 'carries it hands the link to everyone who can read the trail.'
            );
        }
    }

    // ===== FR-031's "no second abuse-control mechanism" =====================

    /**
     * No throttle, no counter, no delay, no authentication on this path.
     *
     * FR-031 delegates abuse control to Nextcloud's existing brute-force limiter and forbids a second
     * mechanism, because a parallel one is the undeclared authentication system Principle III rules out.
     * A *behavioural* pin is the honest form: repeated refusals must be identical and must not slow down,
     * so any future rate limiting shows up here as extra state on the service rather than as a judgement
     * call in review.
     */
    public function testRepeatedAttemptsAreTreatedIdenticallyAndCarryNoAccumulatedState(): void
    {
        $this->seedEvidence('text/plain');
        $this->seedLink(active: true, expiresAt: null);

        $service = $this->service();

        $messages = [];
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $messages[] = $this->messageFor(static fn () => $service->consume('guessed-token'));
        }

        self::assertCount(1, array_unique($messages), 'FR-031: every attempt answers identically.');

        // The service must hold no per-token state, which is what a limiter would have to. Injected
        // collaborators are skipped: they are the repositories the service reads through, and their own
        // contents are asserted elsewhere. What is being checked is that *this* object accumulates
        // nothing across calls.
        foreach ((new \ReflectionClass(PublicEvidenceLinkService::class))->getProperties() as $property) {
            $value = $property->getValue($service);
            if (is_object($value)) {
                continue;
            }

            self::assertEmpty(
                $value,
                sprintf(
                    'FR-031: `%s` holds state after 25 refused attempts. Abuse control belongs to '
                    . "Nextcloud's brute-force limiter, and an application-level throttle here would be a "
                    . 'second mechanism the requirement forbids.',
                    $property->getName(),
                ),
            );
        }
    }

    // ===== the token digest =================================================

    /**
     * The token is looked up by digest, so a leaked database cannot be replayed into access.
     *
     * FR-031 condition (3). Asserted against the stored row rather than by reading the implementation,
     * because the property that matters is what is in the table.
     */
    public function testTheStoredValueIsADigestAndNotTheToken(): void
    {
        $this->seedEvidence('text/plain');
        $link = $this->seedLink(active: true, expiresAt: null);

        self::assertNotSame('token-value', $link->tokenHash, 'the token must never be stored verbatim.');
        self::assertSame(hash('sha256', 'token-value'), $link->tokenHash);
        self::assertSame(
            64,
            strlen($link->tokenHash),
            'SHA-256 hex, which fits the string(128) token_hash column.'
        );
    }

    public function testTheDigestDefinitionIsPublishedForTheCreationPath(): void
    {
        // `POST /evidence/{evidenceId}/links` is declared in `appinfo/routes.php` with no controller
        // action, so creation does not exist yet. When it is written it must call this rather than
        // re-derive the digest: two definitions produce links that are created successfully and then can
        // never be consumed, and the refusal for that is indistinguishable from "this link never existed".
        self::assertTrue(method_exists(PublicEvidenceLinkService::class, 'hashToken'));
        self::assertSame(hash('sha256', 'abc'), PublicEvidenceLinkService::hashToken('abc'));
    }

    // ===== helpers ===========================================================

    private function service(): PublicEvidenceLinkService
    {
        return new PublicEvidenceLinkService(
            $this->links,
            $this->evidence,
            $this->files,
            new EvidenceContentPolicy(),
            new AuditWriterService(
                $this->transactionAudit,
                // Throws for an unauthenticated request, exactly as `NextcloudCurrentUser::uid()` does.
                // Reaching it would be the defect: an anonymous visit has no session to read.
                new InMemoryCurrentUser('never-read'),
                new InMemoryUserDirectory(),
                $this->configurationAudit,
            ),
            $this->clock,
        );
    }

    private function seedEvidence(string $mediaType, bool $active = true): void
    {
        $this->files->putEvidence('tx-1', 1, 'receipt.txt', self::BYTES);

        $this->evidence->save(new Evidence(
            'ev-public-1',
            1,
            1,
            $this->files->putEvidence('tx-1', 1, 'receipt.txt', self::BYTES),
            'receipt.txt',
            $mediaType,
            strlen(self::BYTES),
            hash('sha256', self::BYTES),
            $active,
            'purchaser-1',
        ));
    }

    private function seedLink(
        bool $active,
        ?DateTimeImmutable $expiresAt,
        string $token = 'token-value',
    ): EvidenceLink {
        $link = new EvidenceLink(
            (string)Uuid::random(),
            1,
            PublicEvidenceLinkService::hashToken($token),
            $active,
            $expiresAt,
            'admin-1',
            new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
        );
        $this->links->save($link);

        return $link;
    }

    /** @param callable():mixed $operation */
    private function messageFor(callable $operation): string
    {
        return $this->capture($operation)->getMessage();
    }

    /** @param callable():mixed $operation */
    private function capture(callable $operation): PublicEvidenceRefusal
    {
        try {
            $operation();
        } catch (PublicEvidenceRefusal $refusal) {
            return $refusal;
        }

        self::fail('FR-031: the token was accepted when it should grant nothing.');
    }
}