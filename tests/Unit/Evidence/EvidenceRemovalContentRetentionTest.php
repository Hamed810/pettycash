<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Evidence;

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
 * T072: post-submission removal deactivates; pre-submission removal deletes.
 *
 * ## Why this test exists and why it is a unit test
 *
 * FR-058 requires submitted evidence to be "marked inactive rather than physically removed and its
 * history remains visible". The two halves of that sentence land in different places: the **row** is
 * deactivated either way, so a row-level assertion cannot tell them apart — and over HTTP neither can
 * anything, because `GET /evidence/{id}/content` answers `403` once the evidence is inactive.
 *
 * That is exactly why the defect survived. `EvidenceController::destroy()` called
 * `deleteDraftEvidence()` for every transaction, whose comment said "For simplicity, we'll deactivate
 * in both cases", and that method deletes the AppData binary. A submitted transaction's evidence
 * therefore had its content destroyed while the row — and every assertion anyone wrote — looked
 * correct.
 *
 * The only place the difference is visible is the file store, so this drives the service directly and
 * inspects it. `InMemoryPrivateFileStore` is used rather than a mock so that "the bytes are still
 * there" is checked by the bytes.
 */
final class EvidenceRemovalContentRetentionTest extends TestCase
{
    private InMemoryEvidenceRepository $evidence;
    private InMemoryPrivateFileStore $files;
    private InMemoryAuditWriter $audit;
    private EvidenceService $service;

    private const KEY = 'evidence/tx-1/1/receipt.pdf';

    protected function setUp(): void
    {
        $this->evidence = new InMemoryEvidenceRepository();
        $this->files = new InMemoryPrivateFileStore();
        $this->audit = new InMemoryAuditWriter();

        $this->service = new EvidenceService(
            $this->files,
            $this->evidence,
            new InMemoryCurrentUser('officer1'),
            $this->audit,
            new InMemoryUserDirectory(),
            new IdempotencyService(new InMemoryCommandResultRepository())
        );

        $this->files->putEvidence('tx-1', 1, 'receipt.pdf', 'the original bytes');
    }

    /**
     * Seeded the way production holds it: `id` and `publicId` are the same value, because
     * `DbEvidenceRepository::save()` writes the aggregate's `id` into the `public_id` column, and the
     * lookups in both removals resolve by `publicId`.
     */
    private function seedEvidence(): Evidence
    {
        $evidence = new Evidence(
            id: 'ev-retention',
            transactionId: 7,
            originRevisionId: 42,
            appDataKey: self::KEY,
            originalName: 'receipt.pdf',
            mediaType: 'application/pdf',
            sizeBytes: 18,
            checksum: 'sum',
            active: true,
            uploadedBy: 'officer1',
            uploadedAt: new \DateTimeImmutable('2026-09-29T00:00:00+00:00'),
            publicId: 'ev-retention'
        );
        $this->evidence->save($evidence);

        return $evidence;
    }

    /**
     * The load-bearing assertion: a submitted transaction's evidence keeps its content.
     *
     * `deactivateEvidence()` is the post-submission path. It must mark the row inactive and leave the
     * binary alone, because the evidence is part of the business record FR-053 makes permanent and
     * `workflow.md`'s Correction Contract clause 6 lists "evidence history" among what must remain
     * unchanged. A version that routed here through `deleteDraftEvidence()` would pass every row-level
     * assertion in the suite and still have destroyed the file.
     */
    public function testDeactivatingSubmittedEvidenceKeepsItsContent(): void
    {
        $seeded = $this->seedEvidence();

        $this->service->deactivateEvidence('ev-retention', 'Removed after submission', 'cmd-1');

        self::assertFalse(
            $seeded->active,
            'the row must be marked inactive — FR-058 is a deactivation, not a no-op'
        );
        self::assertNotNull($seeded->deactivatedAt, 'the deactivation is recorded, with its time');
        self::assertTrue(
            $this->files->exists(self::KEY),
            'FR-058: submitted evidence is "marked inactive rather than physically removed". The bytes '
            . 'must survive, and this is the only place the difference from a real deletion is visible'
        );
        self::assertSame(
            'the original bytes',
            $this->files->readEvidence(self::KEY),
            'the retained content must be the original content, not a placeholder or a truncated copy'
        );
    }

    /**
     * A draft's evidence is genuinely removed.
     *
     * The other half, and the guard on the test above: routing by transaction state must not have made
     * *both* paths non-destructive. FR-025 lets a purchaser remove evidence before submission, and a
     * guard written to fix the retention bug by keeping everything would satisfy that test alone.
     */
    public function testDeletingDraftEvidenceRemovesItsContent(): void
    {
        $seeded = $this->seedEvidence();

        $this->service->deleteDraftEvidence('ev-retention');

        self::assertFalse($seeded->active, 'the row is deactivated even on the draft path');
        self::assertFalse(
            $this->files->exists(self::KEY),
            'evidence removable before submission (FR-025) is removed outright, not merely deactivated. '
            . 'The AppData binary is the storage the record points at, so keeping it would be a leak'
        );
    }

    /**
     * The deactivation is recorded once, not once per call.
     *
     * T318 settled this DELETE as repeatable, so a second `deactivateEvidence()` under a *different*
     * command id is a legitimate retry. It must be a no-op: `Evidence::deactivate()` writes
     * `deactivatedAt` unconditionally, so re-running it would answer 200 while recording a new
     * deactivation instant — a recorded business fact that varies with how often the client pressed the
     * button.
     *
     * The same command id is a different case and is not exercised here: `IdempotencyService` answers
     * it from the stored result before this method is reached, so it never reaches the deactivate call
     * at all.
     */
    public function testRepeatingTheDeactivationChangesNothing(): void
    {
        $seeded = $this->seedEvidence();

        $this->service->deactivateEvidence('ev-retention', 'Removed after submission', 'cmd-1');
        $firstDeactivatedAt = $seeded->deactivatedAt;
        $eventsAfterFirst = count($this->audit->getEvents());

        $this->service->deactivateEvidence('ev-retention', 'Removed after submission', 'cmd-2');

        self::assertEquals(
            $firstDeactivatedAt,
            $seeded->deactivatedAt,
            'a repeat must not re-record when the evidence was deactivated'
        );
        self::assertSame(
            $eventsAfterFirst,
            count($this->audit->getEvents()),
            'a repeat must not write a second audit event. FR-053 makes audit append-only, so a duplicate '
            . 'deactivation is a permanent false statement about the record'
        );
        self::assertTrue(
            $this->files->exists(self::KEY),
            'and a repeat must still not touch the content'
        );
    }

    /**
     * The deactivation keeps the content *and* records who removed it and why.
     *
     * Asserted together because they are the two halves of FR-058 and the first draft of this test
     * checked only the row: a change that retained the file but dropped the actor would satisfy a
     * weaker version of the same test.
     */
    public function testTheDeactivationRecordsWhoAndWhyWithoutDiscardingTheEvidence(): void
    {
        $this->seedEvidence();

        $this->service->deactivateEvidence('ev-retention', 'Duplicate receipt', 'cmd-1');

        $events = $this->audit->getEvents();
        self::assertCount(1, $events, 'a deactivation writes exactly one audit event');
        self::assertSame('EVIDENCE_DEACTIVATED', $events[0]['event_type'] ?? null);

        $stored = $this->evidence->findByPublicId('ev-retention');
        self::assertNotNull($stored);
        self::assertSame('officer1', $stored->deactivatedBy, 'FR-052: the actor is retained');
        self::assertSame('Duplicate receipt', $stored->deactivationReason, 'the reason is retained');
        self::assertTrue($this->files->exists(self::KEY), 'and the content is still there');
    }
}
