<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Evidence;

use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Evidence\EvidenceLinkService;
use OCA\PettyCash\Application\Evidence\PublicEvidenceLinkService;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Infrastructure\Http\EvidenceContentPolicy;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceLinkRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * T407 item 4: the link audit is a lifecycle — CREATED → ACCESSED → DEACTIVATED — in the
 * configuration stream and in that order.
 *
 * ## Why this lives here rather than in the HTTP lifecycle test
 *
 * `PublicLinkLifecycleContractTest` drives create → open → revoke → refused over live HTTP, which
 * proves the cross-boundary behavior (shared digest, working URL, revocation stops the link). But no
 * HTTP reader can observe the audit sequence: the audit report enumerates the *reader's own* events,
 * and the access row's viewer is `anonymous` — a principal that never queries. Asserting the sequence
 * over HTTP would mean asserting on rows no endpoint serves.
 *
 * So the sequence is asserted where the writes land: both services share one
 * `InMemoryAuditEventRepository` behind a real `AuditWriterService`, exactly as the two existing
 * service test files wire it, and the recorded stream is read back in order. The HTTP test proves the
 * three writes happen; this proves they happen in the right stream, with the right viewers, in the
 * right order.
 *
 * ## What "in order" means here
 *
 * Insertion order in the shared double, which is the order the three calls ran: create, then consume,
 * then deactivate. A clock is fixed (`FixedClock`), so timestamps cannot distinguish the three — and
 * must not have to. Ordering by wall time would make the test pass for three writes in any order that
 * happened to run in sequence.
 */
final class PublicLinkAuditSequenceTest extends TestCase
{
    private const ADMIN_UID = 'test-admin';
    private const BYTES = 'lifecycle evidence bytes';
    private const NOW = '2026-10-09T12:00:00+00:00';

    private InMemoryEvidenceRepository $evidence;
    private InMemoryEvidenceLinkRepository $links;
    private InMemoryPrivateFileStore $files;
    private InMemoryAuditEventRepository $configurationAudit;

    protected function setUp(): void
    {
        $this->evidence = new InMemoryEvidenceRepository();
        $this->links = new InMemoryEvidenceLinkRepository();
        $this->files = new InMemoryPrivateFileStore();
        $this->configurationAudit = new InMemoryAuditEventRepository();

        $key = $this->files->putEvidence('tx-1', 1, 'receipt.txt', self::BYTES);
        $this->evidence->save(new Evidence(
            'ev-1',
            1,
            1,
            $key,
            'receipt.txt',
            'text/plain',
            strlen(self::BYTES),
            hash('sha256', self::BYTES),
            true,
            'purchaser-1',
        ));
    }

    public function testCreateAccessDeactivateRecordsThreeOrderedConfigurationEvents(): void
    {
        $created = $this->creationService()->create('ev-1', null, 'cmd-lifecycle-1');
        self::assertNotNull($created->token);

        $this->consumptionService()->consume((string) $created->token, null, '203.0.113.7');

        $this->creationService()->deactivate((string) $created->link['id'], 'cmd-lifecycle-2');

        // Insertion order in the shared double, which is the order the three calls ran. A fixed clock
        // means timestamps cannot distinguish them — and must not have to. Ordering by wall time would
        // pass for three writes in any order that happened to run in sequence.
        $types = array_map(
            static fn ($event): string => $event->eventType,
            $this->configurationAudit->getEvents()
        );
        self::assertSame(
            [
                ConfigurationEventType::EvidenceLinkCreated->value,
                ConfigurationEventType::EvidenceLinkAccessed->value,
                ConfigurationEventType::EvidenceLinkDeactivated->value,
            ],
            $types,
            'FR-031: the access record exists between creation and revocation, in the configuration stream'
        );
    }

    public function testTheAccessRowRecordsAnAnonymousViewerWithTheRequestIp(): void
    {
        $created = $this->creationService()->create('ev-1', null, 'cmd-lifecycle-3');
        $this->consumptionService()->consume((string) $created->token, null, '203.0.113.7');

        $access = $this->configurationAudit->findEntityIdsByEventType('EVIDENCE_LINK_ACCESSED', 0, 100);
        self::assertNotEmpty($access, 'consumption must record the access it just served');

        $rows = $this->configurationAudit->findByActorUid('anonymous', 0, 100);
        self::assertCount(1, $rows, 'the access row belongs to no authenticated principal');
        self::assertSame('anonymous', $rows[0]->actorUid);
    }

    public function testCreationAndRevocationNameTheAdministrator(): void
    {
        $created = $this->creationService()->create('ev-1', null, 'cmd-lifecycle-4');
        $this->creationService()->deactivate((string) $created->link['id'], 'cmd-lifecycle-5');

        $rows = $this->configurationAudit->findByActorUid(self::ADMIN_UID, 0, 100);
        $types = array_map(static fn ($event): string => $event->eventType, $rows);

        self::assertContains(ConfigurationEventType::EvidenceLinkCreated->value, $types);
        self::assertContains(ConfigurationEventType::EvidenceLinkDeactivated->value, $types);
        foreach ($rows as $row) {
            self::assertSame(self::ADMIN_UID, $row->actorUid);
        }
    }

    private function creationService(): EvidenceLinkService
    {
        return new EvidenceLinkService(
            $this->links,
            $this->evidence,
            new InMemoryAdministratorCheck(),
            new AuditWriterService(
                new InMemoryAuditEventRepository(),
                new InMemoryCurrentUser(self::ADMIN_UID),
                new InMemoryUserDirectory(),
                $this->configurationAudit,
            ),
            new IdempotencyService(new InMemoryCommandResultRepository()),
            new InMemoryCommandResultRepository(),
            new InMemoryCurrentUser(self::ADMIN_UID),
            new FixedClock(self::NOW),
        );
    }

    private function consumptionService(): PublicEvidenceLinkService
    {
        return new PublicEvidenceLinkService(
            $this->links,
            $this->evidence,
            $this->files,
            new EvidenceContentPolicy(),
            new AuditWriterService(
                new InMemoryAuditEventRepository(),
                // Throws for an unauthenticated request, exactly as `NextcloudCurrentUser::uid()` does.
                // Reaching it would be the defect: an anonymous visit has no session to read.
                new InMemoryCurrentUser('never-read'),
                new InMemoryUserDirectory(),
                $this->configurationAudit,
            ),
            new FixedClock(self::NOW),
        );
    }
}