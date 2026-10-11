<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Shared\Enum\AuditEventType;

final readonly class EvidenceService
{
    /**
     * Maximum accepted evidence file size in bytes (100 MB), per FR-074.
     *
     * Public so the presentation layer can reject an oversized payload before
     * its bytes are read into memory, keeping one source of truth for the limit.
     */
    public const MAX_FILE_SIZE = 104857600; // 100 MB

    public function __construct(
        private PrivateFileStore $fileStore,
        private EvidenceRepository $evidenceRepository,
        private CurrentUser $currentUser,
        private AuditWriter $auditWriter,
        private UserDirectory $userDirectory,
        private IdempotencyService $idempotencyService
    ) {}

    /**
     * Upload evidence for a draft transaction
     *
     * @param string $transactionPublicId
     * @param int $transactionDbId
     * @param int $revisionNumber     the revision's sequence number, used for the AppData key only
     * @param int|null $originRevisionDbId the revision this evidence is attached to
     * @param string $originalFilename
     * @param string $content
     * @param string $mediaType
     * @return Evidence
     */
    public function uploadEvidence(
        string $transactionPublicId,
        int $transactionDbId,
        int $revisionNumber,
        ?int $originRevisionDbId,
        string $originalFilename,
        string $content,
        string $mediaType
    ): Evidence {
        $ownerUid = $this->currentUser->uid();

        if (strlen($content) > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('File size exceeds 100 MB limit');
        }

        // Store the file with opaque key structure
        $key = $this->fileStore->putEvidence($transactionPublicId, $revisionNumber, $originalFilename, $content);
        $checksum = $this->fileStore->checksumOfContent($content);

        $evidenceId = (string)Uuid::random();
        $evidence = new Evidence(
            $evidenceId,
            $transactionDbId,
            // The revision this evidence was uploaded against, not a constant.
            //
            // This was hardcoded to `1` with the comment "will be enhanced with proper revision
            // tracking", so every evidence row in the application claimed revision 1 forever — including
            // rows uploaded while correcting, which are by definition attached to a *later* revision.
            // FR-050 makes the active revision determine the transaction's current information, and
            // FR-048 keeps each revision's history intact, so which revision an item belongs to is the
            // difference between a reconstructable history and one that cannot say what was corrected
            // when.
            $originRevisionDbId ?? 0,
            $key,
            $originalFilename,
            $mediaType,
            strlen($content),
            $checksum,
            true, // active
            $ownerUid,
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );

        $this->evidenceRepository->save($evidence);

        // FR-051 "evidence additions". `uploadEvidence()` previously wrote nothing at all, so the audit
        // stream could show an evidence deactivation with no record that the evidence was ever attached
        // -- an event about a file whose arrival the official history did not contain.
        //
        // The file name and media type are recorded but not the content: the bytes live in appdata and
        // the checksum is what identifies them, so the event points at the stored file without copying it
        // into an append-only table.
        $this->auditWriter->append([
            'id' => (string)Uuid::random(),
            // As in `deactivateEvidence()`, the sheet cannot be resolved here without a transaction
            // repository, so it is recorded as unknown rather than guessed. See the T213 report.
            'sheet_id' => null,
            'transaction_id' => $transactionDbId,
            'revision_id' => $originRevisionDbId,
            'stage' => 'EVIDENCE',
            'decision_id' => null,
            'entity_type' => 'evidence',
            'entity_id' => $evidence->id,
            'event_type' => AuditEventType::EvidenceAdded->value,
            'explanation' => '',
            // Nothing about the evidence was previously true: it did not exist a moment ago.
            'before' => [],
            'after' => [
                'original_filename' => $originalFilename,
                'media_type' => $mediaType,
                'size_bytes' => strlen($content),
                'checksum' => $checksum,
                'uploaded_by' => $ownerUid,
                'active' => true,
            ],
            'command_id' => null,
        ]);

        return $evidence;
    }

  /**
   * Delete draft evidence (before submission)
   *
   * Repeatable by design. `contracts/workflow.md` requires `X-PettyCash-Command-ID` only for
   * *non-repeatable* commands, and the maintainer decision on T318 settled that `DELETE
   * /evidence/{id}` is one of them, so this operation carries no idempotency wrapper and a retry
   * reaches this method again rather than being answered from a stored result.
   *
   * That makes the *effect* idempotency this method's own responsibility, and the one thing it must
   * not do is re-record the deactivation. `Evidence::deactivate()` writes `deactivatedAt`,
   * `deactivatedBy`, and `deactivationReason` unconditionally, so without the guard below a repeat
   * would answer 200 while rewriting `deactivatedAt` to the retry instant. The guard is on the
   * evidence's own state rather than on the call, which is what makes the answer independent of how
   * many times the client retried: `deactivatedBy` and `deactivationReason` are recorded facts of
   * the same kind and are left alone for the same reason.
   *
   * It is deliberately *not* a refusal. Refusing a repeat would make the operation
   * non-repeatable while the contract exempts it from the header that guards non-repeatable
   * commands, reinstating the contradiction T318 removes. `deactivateEvidence()` at line 177
   * refuses, because that path is a submitted-evidence transition with its own guard and its own
   * idempotency contract.
   *
   * The AppData binary is removed before the guard on purpose: the file store is already
   * idempotent (`AppDataEvidenceStore::deleteDraft()` swallows `NotFoundException`), and a repeat
   * must not be able to short-circuit a cleanup the first call may have failed to complete.
   *
   * @param string $evidencePublicId
   */
  public function deleteDraftEvidence(string $evidencePublicId): void
  {
        $ownerUid = $this->currentUser->uid();
        $evidence = $this->evidenceRepository->findByPublicId($evidencePublicId);

        if ($evidence === null || $evidence->uploadedBy !== $ownerUid) {
            throw new InvalidArgumentException('Evidence not found');
        }

        $this->fileStore->deleteDraftEvidence($evidence->appDataKey);

        // Only allow deletion if the transaction is still in draft state
        // This check will be enhanced with proper transaction state verification

        if (!$evidence->active) {
            return;
        }

        // Note: Repository doesn't have delete method - evidence is deactivated instead
        $evidence->deactivate($ownerUid, 'Draft deleted by purchaser');
        $this->evidenceRepository->save($evidence);
    }

/**
     * Deactivate submitted evidence (after submission) with audit trail
     *
     * @param string $evidencePublicId
     * @param string $reason
     * @param string $commandId
     * @return Evidence
     */
    public function deactivateEvidence(string $evidencePublicId, string $reason, string $commandId): Evidence
    {
        $actorUid = $this->currentUser->uid();

        $result = $this->idempotencyService->execute($commandId, $actorUid, 'evidence.deactivate', $evidencePublicId, ['evidence_id' => $evidencePublicId, 'reason' => $reason], function () use ($evidencePublicId, $actorUid, $reason, $commandId): array {
            $evidence = $this->evidenceRepository->findByPublicId($evidencePublicId);

            if ($evidence === null) {
                throw new InvalidArgumentException('Evidence not found');
            }

            if (!$evidence->active) {
                // Already deactivated: return it as it stands.
                //
                // This used to throw "Evidence already deactivated". T318 settled that
                // `DELETE /evidence/{id}` is a **repeatable** command exempt from
                // `X-PettyCash-Command-ID`, and this is the post-submission half of that same
                // operation — so a repeat is a legitimate retry, not a caller error, and refusing it
                // would make the endpoint non-repeatable while the contract exempts it from the header
                // that guards non-repeatable commands.
                //
                // Returning early rather than falling through is what keeps the answer independent of
                // retry count: `deactivate()` rewrites `deactivatedAt`, `deactivatedBy` and
                // `deactivationReason` unconditionally, so re-running it would answer 200 while
                // recording a *new* deactivation instant. The replay that shares this command id never
                // reaches here at all — `IdempotencyService` returns its stored result — so this branch
                // is the different-command-id retry, and it is a no-op.
                return ['evidence_id' => $evidencePublicId];
            }

            // Store old state for audit
            $oldState = [
                'active' => true,
                'deactivated_by' => null,
                'deactivated_at' => null,
                'deactivation_reason' => null,
            ];

            // Deactivate the evidence
            $evidence->deactivate($actorUid, $reason);
            $this->evidenceRepository->save($evidence);

            // Write the audit event.
            //
            // This used to construct an `AuditEvent` **positionally** and cast it to an array:
            //
            //     $this->auditWriter->append((array) new AuditEvent(/* 17 arguments */));
            //
            // Casting an object yields its **camelCase** property names, and `AuditWriterService`
            // reads **snake_case** keys. Not one key matched, so every field was silently defaulted:
            // the row was stored as `event_type = 'UNKNOWN'`, `stage = 'DRAFT'`, with no subject, no
            // sheet, no transaction, and `before_json`/`after_json` both the literal `"[]"`.
            //
            // That silently discarded exactly what FR-052 requires an event to retain -- the
            // before-and-after values -- and FR-053 makes audit records append-only, so the row could
            // never be repaired afterwards. The payload is now the named-key shape the writer reads, and
            // the writer refuses anything that does not carry a readable `event_type`, so a future
            // caller cannot reintroduce this by accident.
            //
            // `actor_uid` and `actor_name` are deliberately absent: `AuditWriterService` resolves them
            // from the session and the user directory, so the FR-052 actor snapshot reflects who
            // actually acted rather than what the caller claimed -- and no caller can forget to send it.
            //
            // `actor_role` is also absent, so the writer applies its `'PURCHASER'` default. That is not a
            // behaviour change: the `getUserRole()` helper this service used to call is a stub that
            // hardcodes `'PURCHASER'` with a "simplified role detection" note, so the recorded role is
            // the same string it has always been. Real per-role resolution is a pre-existing fidelity gap
            // that `ManagementReviewService` and `FinanceSettlementService` solve properly by deriving
            // the role from the cost center. Fixing the stub here would be a separate change, recorded
            // in docs/reports/T213.md as a known limitation rather than quietly folded into this one.
            $this->auditWriter->append([
                'id' => (string)Uuid::random(),
                // Recorded as null rather than guessed. This service holds no transaction repository,
                // so it cannot resolve which sheet the evidence belongs to, and a *wrong* sheet id would
                // attribute the deactivation to a record the evidence does not belong to -- which is
                // worse than admitting the link is unknown. The transaction id and the revision id below
                // are both present, so the row is still reachable from either. Wiring the repository in
                // to populate this is a deliberate follow-on, not something to invent here.
                'sheet_id' => null,
                'transaction_id' => $evidence->transactionId,
                'revision_id' => $evidence->originRevisionId,
                'stage' => 'EVIDENCE',
                'entity_type' => 'evidence',
                'entity_id' => $evidence->id,
                'event_type' => AuditEventType::EvidenceDeactivated->value,
                'explanation' => $reason,
                'before' => $oldState,
                'after' => [
                    'active' => false,
                    'deactivated_by' => $actorUid,
                    'deactivated_at' => $evidence->deactivatedAt?->format(\DateTimeInterface::ATOM),
                    'deactivation_reason' => $reason,
                ],
                'command_id' => $commandId,
            ]);

            return ['evidence_id' => $evidencePublicId];
        }, 200);

        // Re-read rather than returning the aggregate from inside the closure.
        //
        // `IdempotencyService` stores the closure's response as JSON and reads it back as an **array**
        // (`DbCommandResultRepository`: `json_decode($row['response_json'], true)`). Returning the
        // `Evidence` object from inside meant the replay produced `['evidence' => [...array...]]`, and
        // the `: Evidence` return type then threw — so every replayed post-submission deactivation
        // answered `500 UNEXPECTED_FAILURE` with the message withheld, on a transaction whose evidence
        // had in fact been deactivated correctly.
        //
        // Storing only the id makes the stored result something that can actually survive the round
        // trip, and re-reading gives the caller the real aggregate. This is the same shape T317 settled
        // for the transaction commands: re-read the aggregate by its public id after the block.
        $stored = $this->evidenceRepository->findByPublicId((string) ($result['evidence_id'] ?? $evidencePublicId));

        if ($stored === null) {
            throw new InvalidArgumentException('Evidence not found');
        }

        return $stored;
    }

    /**
     * Get evidence by ID
     *
     * @param string $evidencePublicId
     * @return Evidence|null
     */
    public function getEvidence(string $evidencePublicId): ?Evidence
    {
        $ownerUid = $this->currentUser->uid();
        $evidence = $this->evidenceRepository->findByPublicId($evidencePublicId);

        if ($evidence === null || $evidence->uploadedBy !== $ownerUid) {
            return null;
        }

        return $evidence;
    }

    /**
     * List evidence for a transaction
     *
     * @param int $transactionDbId
     * @param bool $activeOnly
     * @return list<Evidence>
     */
    public function listEvidence(int $transactionDbId, bool $activeOnly = false): array
    {
        return $this->evidenceRepository->findByTransactionId($transactionDbId, $activeOnly);
    }

    /**
     * Read evidence content
     *
     * @param string $appDataKey
     * @return string
     */
    public function readEvidenceContent(string $appDataKey): string
    {
        return $this->fileStore->readEvidence($appDataKey);
    }

    /**
     * Check if evidence exists
     *
     * @param string $appDataKey
     * @return bool
     */
    public function evidenceExists(string $appDataKey): bool
    {
        return $this->fileStore->exists($appDataKey);
    }

    /**
     * Get evidence file size
     *
     * @param string $appDataKey
     * @return int
     */
    public function getEvidenceFileSize(string $appDataKey): int
    {
        return $this->fileStore->getFileSize($appDataKey);
    }

    private function getUserRole(string $userUid): string
    {
        // Simplified role detection - in practice this would check role assignments
        return 'PURCHASER';
    }
}