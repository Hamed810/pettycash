<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Evidence;

use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * T318: `DELETE /evidence/{id}` is a repeatable command, so it must be *effect*-idempotent.
 *
 * ## Why this test exists
 *
 * The maintainer decision for T318 is that a DELETE is repeatable and therefore exempt from
 * `X-PettyCash-Command-ID` — `contracts/workflow.md` requires that header only for
 * *non-repeatable* commands, and `contracts/ocs-api.yaml` had `$ref`ed it onto two DELETEs anyway.
 * Exempting the operation removes the idempotency wrapper that would have absorbed a replay, so
 * the *effect* of repeating the command becomes the operation's own responsibility.
 *
 * On this path that responsibility was not met. `EvidenceService::deleteDraftEvidence()` is a soft
 * delete: it calls `Evidence::deactivate()` and saves. `deactivate()` unconditionally rewrites
 * `deactivatedAt` with `new DateTimeImmutable('now')`, and `deleteDraftEvidence()` has no guard on
 * `!$evidence->active` — the guard at `EvidenceService.php:164` belongs to `deactivateEvidence()`,
 * a different method that `EvidenceController::destroy()` does not call. So the second DELETE of an
 * already-inactive evidence row succeeded while recording a *new* deactivation timestamp.
 *
 * That is the failure the header exemption creates and it is worse than the header requirement was:
 * `deactivatedAt` is a recorded business fact, and after this change a value that depends on how
 * many times the client retried would sit in the audit-visible history. Repeating a repeatable
 * command must leave no trace.
 *
 * ## Why these assertions are the ones that catch it
 *
 * The timestamps are compared as `DateTimeImmutable` objects rather than as formatted strings.
 * `EvidenceController` formats with `DateTimeInterface::ATOM`, which truncates to whole seconds, so
 * two deletes inside one second would compare equal in a string assertion and the test would pass
 * against the defect it exists to catch. Object comparison keeps the microseconds and makes two
 * consecutive calls distinguishable.
 */
final class EvidenceDeleteRepeatabilityTest extends TestCase
{
    private InMemoryEvidenceRepository $evidence;
    private InMemoryPrivateFileStore $files;
    private EvidenceService $service;

    protected function setUp(): void
    {
        $this->evidence = new InMemoryEvidenceRepository();
        $this->files = new InMemoryPrivateFileStore();

        $this->service = new EvidenceService(
            $this->files,
            $this->evidence,
            new InMemoryCurrentUser('officer1'),
            new InMemoryAuditWriter(),
            new InMemoryUserDirectory(),
            new IdempotencyService(new InMemoryCommandResultRepository())
        );
    }

    /**
     * Seeded the way production holds it: `id` and `publicId` are the same value, because
     * `DbEvidenceRepository::save()` writes the aggregate's `id` into the `public_id` column. The
     * lookup in `deleteDraftEvidence()` resolves by `publicId`, so a fixture that split the two
     * would test a state the database cannot hold.
     */
    private function seedEvidence(): Evidence
    {
        $evidence = new Evidence(
            id: 'ev-repeat',
            transactionId: 1,
            originRevisionId: 42,
            appDataKey: 'evidence/repeat/receipt.pdf',
            originalName: 'receipt.pdf',
            mediaType: 'application/pdf',
            sizeBytes: 1024,
            checksum: 'abc123',
            active: true,
            uploadedBy: 'officer1',
            uploadedAt: new \DateTimeImmutable('2026-09-29T00:00:00+00:00'),
            publicId: 'ev-repeat'
        );
        $this->files->putEvidence('repeat', 1, 'receipt.pdf', 'bytes');
        $this->evidence->save($evidence);

        return $evidence;
    }

    public function testTheFirstDeleteDeactivatesTheEvidence(): void
    {
        // The repository hands back the very object it was given, so the state is read off the
        // aggregate itself rather than through a second lookup that could resolve to a different
        // instance than the one the service mutated.
        $seeded = $this->seedEvidence();
        self::assertTrue($seeded->active, 'the fixture must start active for this assertion to mean anything');
        self::assertNull($seeded->deactivatedAt, 'the fixture must start with no deactivation time');

        $this->service->deleteDraftEvidence('ev-repeat');

        self::assertFalse($seeded->active, 'the first delete must deactivate the evidence');
        self::assertNotNull($seeded->deactivatedAt, 'the first delete must record a deactivation time');
        self::assertSame('officer1', $seeded->deactivatedBy);
        self::assertSame('Draft deleted by purchaser', $seeded->deactivationReason);
    }

    /**
     * The load-bearing assertion: repeating a repeatable command records nothing new.
     *
     * Both deletes are expected to succeed. `deleteDraftEvidence()` is reachable twice, and a
     * repeatable command is by definition one a client may repeat — refusing the second call with an
     * error would make the operation non-repeatable while claiming it is exempt from the header
     * that exists to protect non-repeatable ones.
     */
    public function testRepeatingTheDeleteDoesNotRewriteTheDeactivationTimestamp(): void
    {
        $this->seedEvidence();

        $this->service->deleteDraftEvidence('ev-repeat');
        $first = $this->evidence->findByPublicId('ev-repeat');
        self::assertNotNull($first);
        $firstDeactivatedAt = $first->deactivatedAt;
        self::assertNotNull($firstDeactivatedAt, 'the first delete must record a deactivation time');

        $this->service->deleteDraftEvidence('ev-repeat');
        $second = $this->evidence->findByPublicId('ev-repeat');

        self::assertNotNull($second, 'the repeat must not remove the row: it is a soft delete');
        self::assertEquals(
            $firstDeactivatedAt,
            $second->deactivatedAt,
            'a repeated delete must not record a second deactivation time. `Evidence::deactivate()` '
            . 'rewrites this unconditionally, so without a guard the recorded instant depends on how '
            . 'many times the client retried -- a business fact varying with retry count'
        );
    }

    /**
     * A guard on `deactivatedAt` alone would be half a fix.
     *
     * The other two fields `deactivate()` writes are recorded facts of the same kind: who removed the
     * evidence and why. `deactivatedBy` happens to be stable here only because the owner check above
     * it already restricts the call to the uploader — a later change that widens that check would
     * silently make the *actor* vary with retry count too, so the pair is asserted together.
     */
    public function testRepeatingTheDeleteDoesNotRewriteTheRecordedActorOrReason(): void
    {
        $this->seedEvidence();

        $this->service->deleteDraftEvidence('ev-repeat');
        $first = $this->evidence->findByPublicId('ev-repeat');
        self::assertNotNull($first);

        $this->service->deleteDraftEvidence('ev-repeat');
        $second = $this->evidence->findByPublicId('ev-repeat');
        self::assertNotNull($second);

        self::assertSame($first->deactivatedBy, $second->deactivatedBy, 'the recorded actor must not change on a repeat');
        self::assertSame(
            $first->deactivationReason,
            $second->deactivationReason,
            'the recorded reason must not change on a repeat'
        );
    }

    /**
     * The repeat must be a no-op on the binary too.
     *
     * `deleteDraftEvidence()` calls `$fileStore->deleteDraftEvidence()` before touching the row.
     * `AppDataEvidenceStore::deleteDraft()` swallows `NotFoundException` and is already idempotent,
     * so this passes both before and after the fix. It is here because the fix adds an early return
     * *above* that call, and a guard that skipped the file cleanup would leave a draft binary behind
     * for evidence whose row was deactivated by the first call — a leak that no status code reports.
     */
    public function testTheRepeatLeavesNoDraftBinaryBehind(): void
    {
        $this->seedEvidence();

        $this->service->deleteDraftEvidence('ev-repeat');
        $this->service->deleteDraftEvidence('ev-repeat');

        self::assertFalse(
            $this->files->exists('evidence/repeat/receipt.pdf'),
            'the draft binary must be gone after the delete, including when a guard short-circuits the repeat'
        );
    }

    /**
     * The repeat is a success, not an error.
     *
     * This is what makes the operation repeatable in the sense `contracts/workflow.md` uses, and it
     * pins the negative deliberately: an implementation that "fixes" the timestamp rewrite by
     * refusing the second call would return 404/409/422 here and reintroduce the very requirement the
     * maintainer removed.
     */
    public function testTheRepeatIsAcceptedRatherThanRefused(): void
    {
        $this->seedEvidence();

        $this->service->deleteDraftEvidence('ev-repeat');

        $refused = false;
        try {
            $this->service->deleteDraftEvidence('ev-repeat');
        } catch (\Throwable) {
            $refused = true;
        }

        self::assertFalse(
            $refused,
            'a repeatable command must accept a repeat. Refusing it would make DELETE non-repeatable '
            . 'while the contract exempts it from the header that guards non-repeatable commands'
        );
    }

    /**
     * Authorization is not weakened by the guard.
     *
     * The fix adds a branch on `!$evidence->active` before the write. This asserts the branch is the
     * *only* thing the guard changed: evidence belonging to someone else must still be refused, and
     * it must still be refused for the same reason a non-existent id is — the contract's
     * hidden-not-found rule, so a caller cannot probe for other users' evidence ids.
     */
    public function testEvidenceBelongingToAnotherUserIsStillRefused(): void
    {
        $this->seedEvidence();

        $refused = false;
        try {
            $this->service->deleteDraftEvidence('ev-not-mine');
        } catch (\InvalidArgumentException) {
            $refused = true;
        }

        self::assertTrue($refused, 'an unknown evidence id must be refused');

        $foreign = new Evidence(
            id: 'ev-foreign',
            transactionId: 1,
            originRevisionId: 42,
            appDataKey: 'evidence/foreign/receipt.pdf',
            originalName: 'receipt.pdf',
            mediaType: 'application/pdf',
            sizeBytes: 1024,
            checksum: 'def456',
            active: true,
            uploadedBy: 'somebody-else',
            uploadedAt: new \DateTimeImmutable('2026-09-29T00:00:00+00:00'),
            publicId: 'ev-foreign'
        );
        $this->evidence->save($foreign);

        $refused = false;
        try {
            $this->service->deleteDraftEvidence('ev-foreign');
        } catch (\InvalidArgumentException) {
            $refused = true;
        }

        self::assertTrue(
            $refused,
            "another user's evidence must still be refused; the inactive-guard must not widen access"
        );
        self::assertTrue(
            $this->evidence->findByPublicId('ev-foreign')->active,
            "another user's evidence must remain active after a refused delete"
        );
    }
}
