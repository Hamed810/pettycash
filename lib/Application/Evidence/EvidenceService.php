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
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Shared\Uuid;

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
     * @param int $revisionNumber
     * @param string $originalFilename
     * @param string $content
     * @param string $mediaType
     * @return Evidence
     */
    public function uploadEvidence(
        string $transactionPublicId,
        int $transactionDbId,
        int $revisionNumber,
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
            1, // revision db ID - will be enhanced with proper revision tracking
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

        return $evidence;
    }

    /**
     * Delete draft evidence (before submission)
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

        // Only allow deletion if the transaction is still in draft state
        // This check will be enhanced with proper transaction state verification

        $this->fileStore->deleteDraftEvidence($evidence->appDataKey);
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

        $result = $this->idempotencyService->execute($commandId, ['evidence_id' => $evidencePublicId, 'reason' => $reason], function () use ($evidencePublicId, $actorUid, $reason, $commandId): array {
            $evidence = $this->evidenceRepository->findByPublicId($evidencePublicId);

            if ($evidence === null) {
                throw new InvalidArgumentException('Evidence not found');
            }

            if (!$evidence->active) {
                throw new InvalidArgumentException('Evidence already deactivated');
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

            // Write audit event
            $auditEvent = new AuditEvent(
                (string)Uuid::random(),
                null, // sheetId - will be resolved from transaction in a full implementation
                $evidence->transactionId,
                $evidence->originRevisionId,
                'evidence', // stage
                null, // decisionId
                'evidence', // entityType
                $evidence->id, // entityId
                'EVIDENCE_DEACTIVATED', // eventType
                $actorUid,
                $this->userDirectory->displayName($actorUid),
                $this->getUserRole($actorUid),
                $reason,
                json_encode($oldState, JSON_THROW_ON_ERROR),
                json_encode([
                    'active' => false,
                    'deactivated_by' => $actorUid,
                    'deactivated_at' => $evidence->deactivatedAt?->format(\DateTimeInterface::ATOM),
                    'deactivation_reason' => $reason,
                ], JSON_THROW_ON_ERROR),
                $commandId,
                new DateTimeImmutable('now', new DateTimeZone('UTC'))
            );
            $this->auditWriter->append((array)$auditEvent);

            return ['evidence' => $evidence];
        });

        return $result['evidence'];
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