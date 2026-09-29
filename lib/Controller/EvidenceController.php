<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use Exception;
use InvalidArgumentException;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use RuntimeException;

class EvidenceController extends OCSController
{
    public function __construct(
        IRequest $request,
        private EvidenceService $evidenceService,
        private EvidenceRepository $evidenceRepository,
        private TransactionService $transactionService,
        private ExpenseTransactionRepository $transactionRepository,
        private CurrentUser $currentUser,
        private OcsErrorMapper $ocsErrorMapper
    ) {
        parent::__construct('pettycash', $request);
    }

    /**
     * @param string $transactionId
     * @param string $commandId
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function upload(string $transactionId, ?string $commandId = null): DataResponse
    {
        $commandId = $commandId ?? $this->request->getHeader('X-PettyCash-Command-ID');
        if ($commandId === null || trim($commandId) === '') {
            return $this->ocsErrorMapper->validation(
                'Required fields are missing',
                null,
                ['commandId' => ['commandId is required']]
            );
        }

        try {
            $file = $this->resolveUploadedFile('file');

            if ($file === null) {
                return $this->ocsErrorMapper->validation('No file uploaded', $commandId, ['file' => ['file is required']]);
            }

            if ($file['error'] !== UPLOAD_ERR_OK) {
                return $this->ocsErrorMapper->validation(
                    'File upload failed with error code: ' . $file['error'],
                    $commandId,
                    ['file' => ['file upload failed']]
                );
            }

            // Enforce the 100 MB ceiling from the declared size before the bytes
            // are read into memory, so an oversized upload is never buffered.
            if ($file['size'] > EvidenceService::MAX_FILE_SIZE) {
                return $this->ocsErrorMapper->payloadTooLarge(
                    'File size exceeds the 100 MB limit',
                    $commandId,
                    ['file' => ['file exceeds the 100 MB limit']]
                );
            }

            $content = $this->readUploadedContent($file);

            // V1 applies no evidence restrictions beyond the size ceiling
            // (FR-030 defers classification and redaction rules to V2).
            $originalFilename = $file['name'] !== '' ? $file['name'] : 'evidence';
            $mediaType = $file['mediaType'] !== '' ? $file['mediaType'] : 'application/octet-stream';

            // Verify transaction exists and user has access
            $ownerUid = $this->currentUser->uid();
            $transaction = $this->transactionRepository->findByPublicId($transactionId);

            if ($transaction === null || $transaction->ownerUid !== $ownerUid) {
                return $this->ocsErrorMapper->notFound('Transaction not found', $commandId);
            }

            // Check if transaction is in a state that allows evidence upload
            if ($transaction->status !== TransactionStatus::Draft && $transaction->status !== TransactionStatus::AwaitingPurchaserCorrection) {
                return $this->ocsErrorMapper->forbidden(
                    'Evidence can only be uploaded for draft or returned transactions',
                    $commandId
                );
            }

            $evidence = $this->evidenceService->uploadEvidence(
                $transactionId,
                $transaction->dbId ?? 0,
                $transaction->revision()->number(),
                $originalFilename,
                $content,
                $mediaType
            );

            return new DataResponse($this->formatEvidence($evidence), 201);

        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    /**
     * Read the uploaded file for `$field` without assuming how the request layer
     * exposes it.
     *
     * `IRequest::getUploadedFile()` is documented to return the `$_FILES` element,
     * which is the raw per-field array (`name`, `type`, `tmp_name`, `error`,
     * `size`); other deployments hand back an `UploadedFile` object, or a list of
     * them. Normalising all three shapes here keeps the action free of that
     * difference, which previously made every upload fail before the action body
     * could report a contract-shaped error.
     *
     * @return array{error:int,name:string,mediaType:string,size:int,tmpName:string,content?:string}|null
     */
    private function resolveUploadedFile(string $field): ?array
    {
        $uploaded = $this->request->getUploadedFile($field);

        if (is_object($uploaded) && method_exists($uploaded, 'getError')) {
            return $this->fromUploadedFileObject($uploaded);
        }

        if (!is_array($uploaded) || $uploaded === []) {
            return null;
        }

        if (array_key_exists('tmp_name', $uploaded)) {
            return [
                'error' => (int)($uploaded['error'] ?? UPLOAD_ERR_OK),
                'name' => (string)($uploaded['name'] ?? ''),
                'mediaType' => (string)($uploaded['type'] ?? ''),
                'size' => (int)($uploaded['size'] ?? 0),
                'tmpName' => (string)($uploaded['tmp_name'] ?? ''),
            ];
        }

        $first = reset($uploaded);
        if (is_object($first) && method_exists($first, 'getError')) {
            return $this->fromUploadedFileObject($first);
        }

        return null;
    }

    /**
     * @return array{error:int,name:string,mediaType:string,size:int,tmpName:string,content:string}
     */
    private function fromUploadedFileObject(object $file): array
    {
        $mediaType = method_exists($file, 'getClientMimeType') ? $file->getClientMimeType() : null;
        $size = method_exists($file, 'getSize') ? (int)$file->getSize() : 0;

        return [
            'error' => (int)$file->getError(),
            'name' => (string)$file->getClientName(),
            'mediaType' => $mediaType === null ? '' : (string)$mediaType,
            'size' => $size,
            'tmpName' => '',
            'content' => (string)$file->getContent(),
        ];
    }

    /**
     * @param array{error:int,name:string,mediaType:string,size:int,tmpName:string,content?:string} $file
     */
    private function readUploadedContent(array $file): string
    {
        if (array_key_exists('content', $file)) {
            return (string)$file['content'];
        }

        $tmpName = $file['tmpName'];
        if ($tmpName === '' || !is_file($tmpName)) {
            throw new RuntimeException('Uploaded file is missing');
        }

        $content = file_get_contents($tmpName);
        if ($content === false) {
            throw new RuntimeException('Failed to read uploaded file');
        }

        return $content;
    }

/**
 * @param string $evidenceId
 * @return DataResponse
 */
#[NoAdminRequired]
public function destroy(string $evidenceId): DataResponse
{
        // No command id is read, required, or reported here.
        //
        // This used to refuse the request with a 422 when `X-PettyCash-Command-ID` was absent, which
        // contradicted `contracts/workflow.md`: that contract requires the header only for
        // *non-repeatable* commands, and a DELETE is repeatable. It also refused with 422 where the
        // missing-parameter convention this repository settled on elsewhere (T317, and
        // `ReviewController`) is 400, so the header check was wrong on two counts at once.
        //
        // The header is not even forwarded to the service. It was read here only to be validated and
        // then attached to error payloads — `deleteDraftEvidence()` never received it, so the header
        // bought no replay protection at any point. `deleteDraftEvidence()` is now effect-idempotent
        // on its own terms: a repeat returns the same `200` and the same metadata, including the
        // `deactivatedAt` recorded by the first call.
        try {
            $ownerUid = $this->currentUser->uid();
            $evidence = $this->evidenceRepository->findByPublicId($evidenceId);

            if ($evidence === null || $evidence->uploadedBy !== $ownerUid) {
                return $this->ocsErrorMapper->notFound('Evidence not found');
            }

            // Check if transaction is in draft state (allows full deletion) or submitted (only deactivation)
            // For simplicity, we'll deactivate in both cases - the service handles the actual file deletion for drafts
            $this->evidenceService->deleteDraftEvidence($evidenceId);

            // Re-read rather than formatting the instance loaded above. The repository returns a
            // freshly mapped object per call, so the pre-delete snapshot still reads `active: true`
            // and `deactivatedAt: null` — while the contract this answers declares `200` as
            // "Deactivated evidence metadata". It also made a repeat answer with pre-delete state
            // on *both* calls, which is the one shape under which the idempotency of the write could
            // never be observed from the response.
            $deactivated = $this->evidenceRepository->findByPublicId($evidenceId);

            return new DataResponse($this->formatEvidence($deactivated ?? $evidence), 200);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * @param string $evidenceId
     * @param string $disposition
     * @return DataResponse|StreamResponse
     */
    #[NoAdminRequired]
    public function content(string $evidenceId, ?string $disposition = null): DataResponse|StreamResponse
    {
        $disposition = $disposition ?? 'inline';

        try {
            $ownerUid = $this->currentUser->uid();
            $evidence = $this->evidenceRepository->findByPublicId($evidenceId);

            if ($evidence === null || $evidence->uploadedBy !== $ownerUid) {
                return $this->ocsErrorMapper->notFound('Evidence not found');
            }

            // Check if evidence is active
            if (!$evidence->active) {
                return $this->ocsErrorMapper->forbidden('Evidence has been deactivated');
            }

            // Read the file content from AppData
            $content = $this->evidenceService->readEvidenceContent($evidence->appDataKey);

            // Create a temporary file for streaming
            $tempFile = tempnam(sys_get_temp_dir(), 'evidence_');
            if ($tempFile === false) {
                throw new RuntimeException('Failed to create temporary file');
            }
            
            $bytesWritten = file_put_contents($tempFile, $content);
            if ($bytesWritten === false) {
                throw new RuntimeException('Failed to write temporary file');
            }

            // Return streaming response using the temporary file
            $stream = new StreamResponse(
                $tempFile,
                Http::STATUS_OK,
                [
                    'Content-Type' => $evidence->mediaType,
                    'Content-Length' => (string)$evidence->sizeBytes,
                    'Content-Disposition' => $disposition === 'attachment' 
                        ? "attachment; filename=\"{$evidence->originalName}\"" 
                        : "inline; filename=\"{$evidence->originalName}\"",
                ]
            );

            // Register cleanup callback
            register_shutdown_function(function () use ($tempFile) {
                if (file_exists($tempFile)) {
                    @unlink($tempFile);
                }
            });

            return $stream;

        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'AppData file not found') {
                return $this->ocsErrorMapper->notFound('Evidence file not found');
            }
            return $this->ocsErrorMapper->map($e);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * Format evidence for API response
     *
     * @param \OCA\PettyCash\Domain\Evidence\Evidence $evidence
     * @return array
     */
    private function formatEvidence(\OCA\PettyCash\Domain\Evidence\Evidence $evidence): array
    {
        return [
            'id' => $evidence->id,
            'transactionId' => $evidence->transactionId,
            'revisionId' => $evidence->originRevisionId,
            'name' => $evidence->originalName,
            'mediaType' => $evidence->mediaType,
            'sizeBytes' => $evidence->sizeBytes,
            'active' => $evidence->active,
            'uploadedBy' => $evidence->uploadedBy,
            'uploadedAt' => $evidence->uploadedAt?->format(\DateTimeInterface::ATOM),
            'deactivatedAt' => $evidence->deactivatedAt?->format(\DateTimeInterface::ATOM),
            'deactivationReason' => $evidence->deactivationReason,
        ];
    }
}