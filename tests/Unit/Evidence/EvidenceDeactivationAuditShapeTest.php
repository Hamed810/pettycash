<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Evidence;

use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * T213: the audit event a real caller produces is one the real writer can read.
 *
 * `AuditEventShapeTest` pins the writer's rule in isolation. This pins the *call site*, and then hands
 * the payload it captured to the real `AuditWriterService` to close the loop -- the defect lived in
 * exactly that gap between the two, where a caller builds a domain object and the writer expects a
 * named-key array.
 *
 * The loop is closed deliberately rather than asserted on the payload alone: a payload that merely
 * *looks* right, fed to a writer that cannot read it, would still lose the data, and that is the whole
 * failure mode being fixed.
 */
final class EvidenceDeactivationAuditShapeTest extends TestCase
{
    private InMemoryEvidenceRepository $evidence;
    private InMemoryAuditWriter $written;
    private InMemoryAuditEventRepository $stored;
    private EvidenceService $service;

    protected function setUp(): void
    {
        $this->evidence = new InMemoryEvidenceRepository();
        $this->written = new InMemoryAuditWriter();
        $this->stored = new InMemoryAuditEventRepository();

        $this->service = new EvidenceService(
            new InMemoryPrivateFileStore(),
            $this->evidence,
            new InMemoryCurrentUser('officer1'),
            $this->written,
            new InMemoryUserDirectory(),
            new IdempotencyService(new InMemoryCommandResultRepository())
        );
    }

    private function seedEvidence(): Evidence
    {
        // `id` and `publicId` hold the same value in production: `DbEvidenceRepository::save()` writes
        // the aggregate's `id` into the `public_id` column and then assigns `publicId = id`. They are
        // seeded to the same string here so the fixture matches what the repository produces, and the
        // lookup by public id resolves the way it does live. A fixture that split them would be testing a
        // state the database cannot hold.
        $evidence = new Evidence(
            id: 'ev-1',
            transactionId: 1,
            originRevisionId: 42,
            appDataKey: 'appdata_pcash-evidence-1',
            originalName: 'invoice.pdf',
            mediaType: 'application/pdf',
            sizeBytes: 12_345,
            checksum: 'abc123',
            active: true,
            uploadedBy: 'officer1',
            uploadedAt: new \DateTimeImmutable('2026-09-29T00:00:00+00:00'),
            publicId: 'ev-1'
        );
        $this->evidence->save($evidence);

        return $evidence;
    }

    public function testDeactivationEmitsAReadableEventTypeRatherThanADefaultedOne(): void
    {
        $this->seedEvidence();

        $this->service->deactivateEvidence('ev-1', 'submitted in error', 'cmd-1');

        $payloads = $this->written->getEvents();
        self::assertCount(1, $payloads, 'a deactivation must emit exactly one audit event');
        self::assertSame('EVIDENCE_DEACTIVATED', $payloads[0]['event_type'] ?? null);
        self::assertArrayNotHasKey(
            'eventType',
            $payloads[0],
            'a camelCase key is the defect itself: the writer reads snake_case, so a camelCase payload is '
            . 'stored with every field defaulted instead of being rejected'
        );
    }

    public function testTheEventIdentifiesTheEvidenceAndTheTransactionItBearsOn(): void
    {
        $this->seedEvidence();

        $this->service->deactivateEvidence('ev-1', 'submitted in error', 'cmd-1');

        $payload = $this->written->getEvents()[0];
        self::assertSame('evidence', $payload['entity_type']);
        self::assertSame('ev-1', $payload['entity_id']);
        self::assertSame(1, $payload['transaction_id']);
        self::assertSame(42, $payload['revision_id']);
        self::assertSame('cmd-1', $payload['command_id']);
        self::assertSame('submitted in error', $payload['explanation']);
    }

    /**
     * FR-052: the event retains the applicable before-and-after values.
     *
     * This is the assertion the old code could not have passed. The cast produced no `before`/`after`
     * key at all, so the writer's `?? []` default stored the literal `"[]"` in both columns.
     */
    public function testTheEventRetainsItsBeforeAndAfterValues(): void
    {
        $this->seedEvidence();

        $this->service->deactivateEvidence('ev-1', 'submitted in error', 'cmd-1');

        $payload = $this->written->getEvents()[0];
        self::assertSame(
            [
                'active' => true,
                'deactivated_by' => null,
                'deactivated_at' => null,
                'deactivation_reason' => null,
            ],
            $payload['before'] ?? null,
            'the prior state must be captured in full: the three nulls are part of the shape being captured, '
            . 'not fields to drop'
        );
        self::assertFalse($payload['after']['active'] ?? null, 'the new state must show the evidence inactive');
        self::assertSame('officer1', $payload['after']['deactivated_by'] ?? null);
        self::assertSame('submitted in error', $payload['after']['deactivation_reason'] ?? null);
        self::assertNotSame(
            '[]',
            json_encode($payload['before'] ?? null),
            'an empty before-snapshot is exactly what the camelCase cast produced'
        );
    }

    /**
     * The payload the real caller produces, read by the real writer, survives intact.
     *
     * Nothing about the two halves proves the other. The caller can send a well-spelled array that the
     * writer silently defaults, and the writer can read correctly from a payload nobody sends. This
     * passes the captured payload straight through and inspects the stored row.
     */
    public function testTheRealWriterStoresTheRealCallersPayloadWithoutLosingAnything(): void
    {
        $this->seedEvidence();
        $this->service->deactivateEvidence('ev-1', 'submitted in error', 'cmd-1');

        $writer = new AuditWriterService(
            $this->stored,
            new InMemoryCurrentUser('officer1'),
            new InMemoryUserDirectory(),
            new InMemoryAuditEventRepository()
        );
        $writer->append($this->written->getEvents()[0]);

        $events = $this->stored->getEvents();
        self::assertCount(1, $events, 'the writer must accept the payload the caller actually sends');
        $event = $events[0];

        self::assertSame('EVIDENCE_DEACTIVATED', $event->eventType, 'not UNKNOWN');
        self::assertSame('evidence', $event->entityType, 'not an empty string');
        self::assertSame('ev-1', $event->entityId);
        self::assertSame(1, $event->transactionId);
        self::assertSame(42, $event->revisionId);
        self::assertSame('EVIDENCE', $event->stage, 'not the DRAFT default');
        self::assertSame('officer1', $event->actorUid, 'the writer resolves the actor from the session');
        self::assertStringContainsString('"active":true', $event->beforeJson);
        self::assertStringContainsString('"active":false', $event->afterJson);
        self::assertStringNotContainsString('"[]"', $event->beforeJson);
        self::assertStringNotContainsString('"[]"', $event->afterJson);
    }

    public function testDeactivationIsStillRefusedForEvidenceThatIsNotActive(): void
    {
        $this->seedEvidence();
        $this->service->deactivateEvidence('ev-1', 'submitted in error', 'cmd-1');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->deactivateEvidence('ev-1', 'again', 'cmd-2');
    }
}
