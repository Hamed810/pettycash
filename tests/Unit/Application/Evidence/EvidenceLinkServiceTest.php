<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Evidence;

use DateTimeImmutable;
use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Application\Command\CommandConflict;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Evidence\CreatedEvidenceLink;
use OCA\PettyCash\Application\Evidence\EvidenceLinkRefusal;
use OCA\PettyCash\Application\Evidence\EvidenceLinkService;
use OCA\PettyCash\Application\Evidence\PublicEvidenceLinkService;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Application\Command\CommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceLinkRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * T403: creating and revoking a public evidence link — FR-031's producer half.
 *
 * ## What this file is really about
 *
 * Before T403, `appinfo/routes.php` declared `POST /evidence/{evidenceId}/links` and
 * `POST /evidence-links/{linkId}/deactivate`, the contract declared both, and **no code anywhere
 * constructed an `EvidenceLink`**. `grep "linkRepository->save"` returned nothing and
 * `PublicEvidenceLinkService::hashToken()` had no production caller, so `pettycash_evidence_links` had a
 * migration, a working adapter, and no writer. A consumer existed with no producer.
 *
 * So the tests below are mostly about properties that are easy to get wrong in a way that is invisible
 * until much later: the token's disclosure, its digest, the TTL ceiling, and what a replay may and may
 * not return.
 */
final class EvidenceLinkServiceTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';

    private InMemoryEvidenceRepository $evidence;
    private InMemoryEvidenceLinkRepository $links;
    private InMemoryAuditEventRepository $transactionAudit;
    private InMemoryAuditEventRepository $configurationAudit;
    private \OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository $commandResults;
    private FixedClock $clock;

    /** `InMemoryAdministratorCheck` answers true only for this uid, so the authorization tests need no double. */
    private const ADMIN_UID = 'test-admin';
    private const NON_ADMIN_UID = 'manager-1';

    protected function setUp(): void
    {
        $this->evidence = new InMemoryEvidenceRepository();
        $this->links = new InMemoryEvidenceLinkRepository();
        $this->transactionAudit = new InMemoryAuditEventRepository();
        $this->configurationAudit = new InMemoryAuditEventRepository();
        $this->commandResults = new \OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository();
        $this->clock = new FixedClock(self::NOW);
    }

    // ===== the happy path ====================================================

    public function testAnAdministratorCreatesALinkAndReceivesTheToken(): void
    {
        $this->seedEvidence();

        $created = $this->service()->create('ev-1', null, 'cmd-create-1');

        self::assertNotNull($created->token, 'a first attempt must disclose the token');
        self::assertSame(64, strlen($created->token), '32 CSPRNG bytes hex-encoded');
        self::assertTrue($created->link['active']);
        self::assertSame('ev-1', $created->link['evidenceId']);
    }

    public function testTheStoredValueIsADigestAndNotTheToken(): void
    {
        $this->seedEvidence();

        $created = $this->service()->create('ev-1', null, 'cmd-create-2');

        $stored = $this->links->all();
        self::assertCount(1, $stored);
        self::assertNotSame($created->token, $stored[0]->tokenHash);
        self::assertSame(PublicEvidenceLinkService::hashToken((string) $created->token), $stored[0]->tokenHash);
    }

    /**
     * The digest must come from the consumer's definition, not a second copy of it.
     *
     * Two definitions of "how a token becomes a stored hash" produce links that are created
     * successfully and can then never be consumed — and the refusal for that is the *identical*
     * `404 "Not found"` a caller gets for a link that never existed, which is the one answer an
     * administrator cannot act on.
     */
    public function testTheDigestIsTheConsumersOwnDefinition(): void
    {
        $this->seedEvidence();
        $created = $this->service()->create('ev-1', null, 'cmd-create-3');

        $stored = $this->links->all()[0];
        self::assertSame(
            PublicEvidenceLinkService::hashToken((string) $created->token),
            $stored->tokenHash,
            'creation and consumption must agree on the digest, or a created link can never be opened.'
        );
    }

    /**
     * The token is the entire authority on the public path, so its generator is a security property.
     *
     * Asserted structurally: `EvidenceLinkService::generateToken()` must not be `uniqid()`, `md5()`,
     * `rand()` or a UUID, all of which are guessable or machine-correlated.
     */
    public function testTheTokenComesFromTheCryptographicGenerator(): void
    {
        $tokens = [];
        for ($i = 0; $i < 64; $i++) {
            $tokens[] = EvidenceLinkService::generateToken();
        }

        self::assertCount(64, array_unique($tokens), 'a token generator that repeats is not a token generator');
        foreach ($tokens as $token) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        }

        $source = file_get_contents(__DIR__ . '/../../../../lib/Application/Evidence/EvidenceLinkService.php');
        foreach (['uniqid(', 'mt_rand(', 'rand(', 'md5(', 'Uuid::random()'] as $weak) {
            self::assertStringNotContainsString(
                $weak,
                substr($source, (int) strpos($source, 'function generateToken')),
                "`{$weak}` is not a source of unguessable authority."
            );
        }
    }

    // ===== FR-031's TTL rules ================================================

    public function testAnAbsentExpiryGetsTheThirtyDayDefault(): void
    {
        $this->seedEvidence();

        $created = $this->service()->create('ev-1', null, 'cmd-ttl-1');

        $expected = (new DateTimeImmutable(self::NOW))->modify('+' . EvidenceLinkService::DEFAULT_TTL_DAYS . ' days');
        self::assertSame(
            $expected->format(DATE_ATOM),
            $created->link['expiresAt'],
            'FR-031: default 30 days.'
        );
        self::assertSame(30, EvidenceLinkService::DEFAULT_TTL_DAYS);
    }

    public function testAnExpiryInsideTheCeilingIsHonoured(): void
    {
        $this->seedEvidence();
        $inside = (new DateTimeImmutable(self::NOW))->modify('+90 days')->format(DATE_ATOM);

        self::assertSame(
            $inside,
            $this->service()->create('ev-1', $inside, 'cmd-ttl-2')->link['expiresAt'],
        );
    }

    /**
     * Refused, not clamped.
     *
     * Silently shortening a link to the ceiling would make the stored TTL disagree with the one the
     * administrator was given, and nothing would tell them. A refusal is the only answer that leaves
     * the stored value equal to the requested one.
     */
    public function testAnExpiryBeyondOneYearIsRefusedRatherThanClamped(): void
    {
        $this->seedEvidence();
        $tooLong = (new DateTimeImmutable(self::NOW))->modify('+400 days')->format(DATE_ATOM);

        $refusal = $this->capture(fn () => $this->service()->create('ev-1', $tooLong, 'cmd-ttl-3'));

        self::assertStringContainsString('exceeds the maximum link lifetime', $refusal->getMessage());
        self::assertSame(365, EvidenceLinkService::MAX_TTL_DAYS, 'FR-031: maximum 1 year.');
        self::assertSame([], $this->links->all(), 'a refused expiry must not store a link');
    }

    public function testAPastExpiryIsRefused(): void
    {
        $this->seedEvidence();
        $past = (new DateTimeImmutable(self::NOW))->modify('-1 day')->format(DATE_ATOM);

        $this->capture(fn () => $this->service()->create('ev-1', $past, 'cmd-ttl-4'));
        self::assertSame([], $this->links->all());
    }

    public function testAnUnparseableExpiryIsRefused(): void
    {
        $this->seedEvidence();

        $this->capture(fn () => $this->service()->create('ev-1', 'next tuesday-ish', 'cmd-ttl-5'));
        self::assertSame([], $this->links->all());
    }

    // ===== FR-084: replay ====================================================

    /**
     * The tension this task had to resolve, asserted on both halves.
     *
     * FR-084 says a retried creation must not duplicate its effect; FR-031 says the token is disclosed
     * once. A retried request therefore returns **the same link** and **no token**, which is why the
     * contract's `EvidenceLink.url` is nullable and documented "returned only at creation".
     */
    public function testAReplayReturnsTheSameLinkAndDoesNotDiscloseTheTokenAgain(): void
    {
        $this->seedEvidence();
        $service = $this->service();

        $first = $service->create('ev-1', null, 'cmd-replay-1');
        $replay = $service->create('ev-1', null, 'cmd-replay-1');

        self::assertSame(
            $first->link['id'],
            $replay->link['id'],
            'FR-084: a replay must not create a second link.'
        );
        self::assertCount(1, $this->links->all(), 'exactly one link exists after the replay');
        self::assertNull($replay->token, 'FR-031: the token is disclosed once, not re-disclosed to a retrier.');
        self::assertNull($replay->url('https://nc.example'), 'and so no url, which is what `url: null` is for.');
    }

    /**
     * T414: a retry with the same command id returns the original result **even when time has moved on**.
     *
     * The test above passes with a **fixed** clock, and that is exactly why the defect it now guards
     * survived: a frozen clock makes two attempts byte-identical no matter how the default expiry is
     * derived, so the payload comparison had nothing to disagree about. The real behaviour differs — and
     * had to be measured rather than assumed.
     *
     * **What was wrong.** `create()` hashes the resolved expiry into the idempotency payload, and the
     * default expiry was derived as `clock->now() + 30 days`. With a clock carrying sub-second precision,
     * a retry arriving in a later microsecond derived a *different* expiry, held different content under
     * one command id, and `IdempotencyService` — correctly, per `data-model.md:377` — refused it with a
     * `CommandConflict`, which the caller sees as `409`. So a client retrying because it never received the
     * first response got a refusal for a reason it could neither observe nor correct.
     *
     * **`IdempotencyService` was not at fault**, and the distinction is the point: it compared what it
     * was given, and it was given genuinely different content. The obligation belongs to the derivation.
     *
     * The clock is advanced by **1.5 seconds** rather than by a whole second on purpose. Second-granular
     * truncation is what makes the retry stable, and advancing by a full second would also pass against an
     * implementation that truncated to the minute. A sub-second-and-a-half step crosses the boundary the
     * fix draws.
     */
    public function testARetryReturnsTheOriginalResultEvenWhenTimeHasAdvanced(): void
    {
        // **Sub-second start, deliberately.** This file's `NOW` is second-granular
        // (`00:00:00`), and on such a clock `resolveExpiry()`'s truncation to whole seconds is a
        // no-op — so a test starting from `NOW` would pass against the *unfixed* code and prove
        // nothing. A real clock carries microseconds, which is what made the defect reachable over
        // HTTP at all, so the test has to carry them too.
        $this->clock->setNow('2026-10-08T00:00:00.750000+00:00');

        $this->seedEvidence();
        $service = $this->service();

        $first = $service->create('ev-1', null, 'cmd-retry-after-delay');

        // The retry a real client sends: same command id, same intent, arriving later. 1.5s lands in
        // the *next* second, so this crosses the boundary the fix draws — an implementation
        // truncating to the minute would still fail here.
        $this->clock->advance('+1.5 seconds');

        // **And a long gap, which is what the fix actually earns.** Truncation to whole seconds only
        // survives a sub-second gap, so a test that advanced by 1.5s would also pass against an
        // implementation that merely narrowed the failure window. Two days cannot be absorbed by any
        // bucket short of a week, and a caller whose network held a request for a day must still get the
        // original result rather than a conflict they cannot explain.
        $this->clock->advance('+2 days');

        $retry = $service->create('ev-1', null, 'cmd-retry-after-delay');

        self::assertSame(
            $first->link['id'],
            $retry->link['id'],
            'FR-042 as clarified 2026-10-08: a retry returns the original result. A caller that never '
            . 'received the first response must not be refused for a reason it cannot observe.'
        );
        self::assertSame(
            $first->link['expiresAt'],
            $retry->link['expiresAt'],
            'the derived default expiry must be identical across attempts, or the content comparison '
            . 'cannot recognise the retry as the same command.'
        );
        self::assertCount(1, $this->links->all(), 'a retry must not create a second link.');
        self::assertNull($retry->token, 'FR-031: and it still must not re-disclose the token.');
    }

    /**
     * The complement, so the fix cannot be "ignore the expiry".
     *
     * A gate that accepted anything would also pass the test above. This pins that the comparison still
     * refuses genuinely different content under one command id — which is the other half of
     * `data-model.md:377`, and the half that makes the first one meaningful.
     */
    public function testTheSameCommandIdWithDifferentContentIsStillRefused(): void
    {
        $this->seedEvidence();
        $service = $this->service();

        $service->create('ev-1', null, 'cmd-conflict-1');

        // **The second evidence must exist**, or the refusal comes from the lookup and this test passes
        // for the wrong reason. A first attempt at this shape proved exactly that: seeding only `ev-1`
        // and then asking for `ev-2` produced `EvidenceLinkRefusal("Not found")` — a correct refusal, and
        // no evidence at all about the content comparison.
        $this->seedEvidence(true, 'ev-2');
        $this->seedEvidence(true, 'ev-3');

        $this->expectException(CommandConflict::class);

        // Same command id, a different target: the documented conflict.
        $service->create('ev-3', null, 'cmd-conflict-1');
    }

    /**
     * The reason a replay cannot return the token: **it must not be stored anywhere.**
     *
     * The obvious implementation returns the token from the idempotent operation, and
     * `IdempotencyService` writes that response into `command_results` — persisting the whole authority
     * of the public path in a table nothing purges. This asserts the stored result does not carry it.
     */
    public function testTheStoredCommandResultNeverContainsTheToken(): void
    {
        $this->seedEvidence();

        $created = $this->service()->create('ev-1', null, 'cmd-replay-2');

        $stored = $this->commandResults->find('cmd-replay-2');
        self::assertNotNull($stored, 'FR-084 requires a stored result for a replay to be safe');
        self::assertStringNotContainsString(
            (string) $created->token,
            json_encode($stored->response, JSON_THROW_ON_ERROR),
            'the token must never be persisted; `command_results` outlives the response that may carry it.'
        );
    }

    // ===== authorization ====================================================

    public function testANonAdministratorCannotCreateALink(): void
    {
        $this->seedEvidence();

        $refusal = $this->capture(
            fn () => $this->service(self::NON_ADMIN_UID)->create('ev-1', null, 'cmd-auth-1'),
        );

        self::assertStringContainsString('administrators', $refusal->getMessage());
        self::assertSame([], $this->links->all());
    }

    public function testANonAdministratorCannotRevokeALink(): void
    {
        $this->seedEvidence();
        $this->service()->create('ev-1', null, 'cmd-auth-2');

        $this->capture(fn () => $this->service(self::NON_ADMIN_UID)->deactivate($this->links->all()[0]->id, 'cmd-auth-3'));
        self::assertTrue($this->links->all()[0]->active, 'the link must remain active');
    }

    /**
     * The clarification's rule that makes the capability useful: an administrator's **own view right is
     * not required**, and a link on evidence they cannot themselves open is permitted and audited.
     *
     * Asserted structurally rather than behaviourally, because the requirement is an *absence*: there
     * must be no visibility dependency on the service at all. A check added later would look helpful
     * and would break the case the clarification names.
     */
    public function testCreationTakesNoEvidenceVisibilityDependency(): void
    {
        $types = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            (new \ReflectionClass(EvidenceLinkService::class))->getConstructor()?->getParameters() ?? [],
        );

        foreach ($types as $type) {
            self::assertDoesNotMatchRegularExpression(
                '/EvidenceVisibility|EvidenceSelection/i',
                $type,
                'FR-031 (2026-10-07): an administrator\'s own view right is not required to create a '
                . 'link, and a link on evidence they cannot open is permitted and audited as such.'
            );
        }
    }

    // ===== revocation ========================================================

    public function testRevocationStandsAndStopsTheLinkWorking(): void
    {
        $this->seedEvidence();
        $service = $this->service();
        $created = $service->create('ev-1', null, 'cmd-rev-1');

        $result = $service->deactivate($created->link['id'], 'cmd-rev-2');

        self::assertFalse($result['link']['active']);
        self::assertFalse($this->links->all()[0]->active);
    }

    /**
     * A retry after a lost response must not fail: the client's intent — that the link be revoked — is
     * already satisfied, and answering an error would push it toward a retry loop.
     */
    public function testRevokingAnAlreadyRevokedLinkSucceeds(): void
    {
        $this->seedEvidence();
        $service = $this->service();
        $created = $service->create('ev-1', null, 'cmd-rev-3');

        $service->deactivate($created->link['id'], 'cmd-rev-4');
        $second = $service->deactivate($created->link['id'], 'cmd-rev-5');

        self::assertFalse($second['link']['active']);
        self::assertTrue($second['already_inactive'], 'the repeat is reported, not silently absorbed');
    }

    public function testRevokingAnUnknownLinkIsRefused(): void
    {
        $refusal = $this->capture(fn () => $this->service()->deactivate('no-such-link', 'cmd-rev-6'));

        // The same message consumption uses, so an administrator cannot tell "no such link" from
        // "some link, not yours" by the wording.
        self::assertSame('Not found', $refusal->getMessage());
        self::assertSame([], $this->links->all());
    }

    // ===== audit ============================================================

    public function testCreationAndRevocationAreAuditedInTheConfigurationStream(): void
    {
        $this->seedEvidence();
        $service = $this->service();

        $created = $service->create('ev-1', null, 'cmd-audit-1');
        $service->deactivate($created->link['id'], 'cmd-audit-2');

        $types = array_map(static fn ($e): string => $e->eventType, $this->configurationAudit->getEvents());

        self::assertSame(['EVIDENCE_LINK_CREATED', 'EVIDENCE_LINK_DEACTIVATED'], $types);
        self::assertSame(
            [],
            $this->transactionAudit->getEvents(),
            'FR-056: the two audit streams stay separately identifiable.'
        );
    }

    /**
     * FR-055: only funding and charging events count toward an officer's charged-account scope, so a
     * link event must not widen it. Asserted through the enum rather than the reader, because the
     * reader matches two named cases and the structural fact is that these are two *different* ones.
     */
    public function testLinkEventsCannotCountAsAccountCharges(): void
    {
        $this->seedEvidence();
        $created = $this->service()->create('ev-1', null, 'cmd-audit-3');

        $scope = new \OCA\PettyCash\Application\Reporting\ChargedAccountScope(
            $this->configurationAudit,
            new \OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository(),
        );

        self::assertSame(
            [],
            $scope->chargedAccountDbIds(),
            'FR-055: an event type outside funding and charging MUST NOT count toward the set, so a '
            . 'public-link event cannot widen an officer\'s configuration-audit scope.'
        );
        self::assertNotNull($created->token);
    }

    // ===== helpers ===========================================================

    private function service(string $uid = self::ADMIN_UID): EvidenceLinkService
    {
        return new EvidenceLinkService(
            $this->links,
            $this->evidence,
            new \OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck(),
            new AuditWriterService(
                $this->transactionAudit,
                new InMemoryCurrentUser($uid),
                new InMemoryUserDirectory(),
                $this->configurationAudit,
            ),
            new IdempotencyService($this->commandResults),
            $this->commandResults,
            new InMemoryCurrentUser($uid),
            $this->clock,
        );
    }

    /**
     * Seed one evidence row.
     *
     * `$publicId` is a parameter rather than a hardcoded `ev-1` because T414's conflict test needs a
     * **second** target to reuse one command id against. Writing `ev-1` unconditionally meant that test
     * re-saved the same row and then asked for a target that did not exist, so the refusal came from the
     * lookup — correct behaviour, and no evidence about the content comparison the test was for.
     */
    private function seedEvidence(bool $active = true, string $publicId = 'ev-1'): void
    {
        $this->evidence->save(new Evidence(
            $publicId,
            1,
            1,
            'appdata/' . $publicId,
            'receipt.txt',
            'text/plain',
            12,
            hash('sha256', 'bytes'),
            $active,
            'purchaser-1',
        ));
        // Force the surrogate key the link stores, so the audit assertion can read it back.
        $this->evidence->save($this->evidence->findByPublicId($publicId));
    }

    /** @param callable():mixed $operation */
    private function capture(callable $operation): EvidenceLinkRefusal
    {
        try {
            $operation();
        } catch (EvidenceLinkRefusal $refusal) {
            return $refusal;
        }

        self::fail('the operation was accepted when it should have been refused.');
    }
}