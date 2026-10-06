<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use Exception;
use InvalidArgumentException;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Application\Evidence\EvidenceVisibility;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Infrastructure\Http\EvidenceContentPolicy;
use OCA\PettyCash\Infrastructure\Http\TransactionPayloadFormatter;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
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
        private ExpenseSheetRepository $sheetRepository,
        private TransactionPayloadFormatter $payloadFormatter,
        private CurrentUser $currentUser,
        private OcsErrorMapper $ocsErrorMapper,
        private EvidenceVisibility $evidenceVisibility,
        private EvidenceContentPolicy $contentPolicy
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
                // The revision's database id, which is what `origin_revision_id` joins against. The
                // *number* above only names the AppData key: the two are different identifier spaces,
                // and passing the number made every upload attach itself to whichever revision happened
                // to have that id (T072).
                $transaction->revision()->dbId,
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

            // Two different removals, chosen by the transaction's state.
            //
            // The comment that used to sit here said "For simplicity, we'll deactivate in both cases —
            // the service handles the actual file deletion for drafts", and that "for simplicity" was
            // the defect: `deleteDraftEvidence()` deletes the AppData binary, so a **submitted**
            // transaction's evidence had its content physically removed. FR-058 requires the opposite —
            // "evidence is marked inactive rather than physically removed and its history remains
            // visible" — and `workflow.md`'s Correction Contract clause 6 requires "evidence history" to
            // remain unchanged. The evidence *row* survived either way, which is why nothing noticed:
            // the difference is the file behind it, and the row looks identical in both cases.
            //
            // So: a draft is genuinely deleted (FR-025, evidence removable before submission), and
            // anything already submitted is deactivated with its content retained. Both branches are
            // repeatable, per T318.
            // The boundary is the **sheet**, not the transaction.
            //
            // The transaction's own status is the wrong signal, and measuring it is what corrected this:
            // after `POST /sheets/{id}/submit` the sheet leaves `OPEN` while the transaction still reads
            // `DRAFT` — submission routes the transaction to a stage without rewriting that field. So a
            // transaction-status check sends every submitted transaction down the draft path and deletes
            // the binary, which is the bug this routing exists to prevent.
            //
            // The sheet is also the right unit on the merits: FR-025 and FR-058 both tie evidence
            // removability to submission, and submission is a property of the sheet. This mirrors
            // `TransactionService::deleteTransaction()` and `deleteDraftEvidence()`, which both ask the
            // sheet the same question.
            //
            // An unresolved parent is not treated as "draft": the safe reading of a failed lookup is the
            // restrictive one, because deleting a binary on the strength of a lookup that failed is the
            // irreversible half of this decision.
            $transaction = $this->transactionRepository->findById($evidence->transactionId);
            $sheet = $transaction === null ? null : $this->sheetRepository->findById($transaction->sheetId ?? 0);

            if ($sheet !== null && $sheet->status === SheetStatus::Open) {
                $this->evidenceService->deleteDraftEvidence($evidenceId);
            } else {
                // The command id is optional here: this branch is effect-idempotent, so a retry with
                // no header still returns the same state rather than re-recording a deactivation. The id
                // is used only to correlate the audit event, and a real one is a better correlation than
                // a generated one, not a better behaviour.
                //
                // Trimmed before the fallback, because `IRequest::getHeader()` answers an **empty
                // string** for an absent header rather than null. Without the emptiness check the
                // fallback never fired and every headerless call reached `IdempotencyService` under the
                // same empty key — so the second such call anywhere in the installation was refused with
                // `409 IDEMPOTENCY_CONFLICT`, attributing a replay conflict to an unrelated request.
                $commandId = trim((string) $this->request->getHeader('X-PettyCash-Command-ID'));
                $this->evidenceService->deactivateEvidence(
                    $evidenceId,
                    'Removed after submission',
                    $commandId === '' ? (string)\OCA\PettyCash\Domain\Shared\Uuid::random() : $commandId
                );
            }

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
            $actorUid = $this->currentUser->uid();
            $evidence = $this->evidenceRepository->findByPublicId($evidenceId);

            if ($evidence === null) {
                return $this->ocsErrorMapper->notFound('Evidence not found');
            }

            // FR-060 / Constitution IV: document access follows **transaction visibility**, not
            // uploader identity.
            //
            // This compared `$evidence->uploadedBy` to the caller, which no reviewer can satisfy — the
            // receipt was uploaded by the purchaser and the cost-centre manager, senior manager and
            // finance officer who must rely on it are three different accounts. Every reviewer got
            // `404 Evidence not found` for every receipt on every transaction they were asked to
            // approve (T362).
            //
            // `404` is kept for an out-of-scope caller so the endpoint cannot be used to confirm the
            // existence of a record the caller may not see (FR-003) — the same treatment
            // `AccountController` gives a foreign ledger. What changed is *which* question is asked,
            // not the shape of the answer.
            $transaction = $this->transactionRepository->findById($evidence->transactionId);
            if ($transaction === null) {
                // An evidence row whose transaction does not resolve is a data fault, not a
                // not-found: the caller may well be entitled to read it, and answering 404 would tell
                // a legitimate reader the file had vanished — the Phase 33 "header, no data" shape.
                return $this->ocsErrorMapper->validation(
                    'This evidence cannot be read because its transaction could not be resolved. '
                    . 'This is a stored-data fault, not a permissions or filter result.'
                );
            }

            if (!$this->evidenceVisibility->mayRead($actorUid, $transaction)) {
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

            // FR-060 decides the headers from the **stored** media type, not from what the caller
            // asked for: an inline-safe type (images, PDF, plain text) is served with its own type so
            // it renders, and anything else is forced to `application/octet-stream` + `attachment`.
            //
            // This was the second half of FR-060 and it was missing. The stored type is whatever the
            // uploading client declared -- `upload()` keeps `$file['mediaType']` verbatim because
            // FR-030 defers classification to V2 -- so serving it back verbatim under a caller-chosen
            // `inline` disposition let an uploader store `text/html` and have it execute as script in
            // a reviewer's session in the Nextcloud origin. FR-060 names that harm in its own words.
            $contentType = $this->contentPolicy->resolve($evidence->mediaType, $disposition);

            // Return streaming response using the temporary file
            $stream = new StreamResponse(
                $tempFile,
                Http::STATUS_OK,
                [
                    'Content-Type' => $contentType['contentType'],
                    'Content-Length' => (string)$evidence->sizeBytes,
                    'Content-Disposition' => $contentType['disposition'] === 'attachment'
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
     * Delegates to the one emitter of the `Evidence` schema rather than keeping a second mapping here.
     * This copy was the drift `AccountPayloadFormatter`'s docblock warns about, and it was wrong twice:
     * it emitted a `deactivationReason` the contract does not declare (and `src/types/api.ts` does not
     * carry), and it published the integer join keys in two fields the contract declares as uuids. A
     * client cannot tell which endpoint it called and get a shape that holds for both (T339).
     *
     * @param \OCA\PettyCash\Domain\Evidence\Evidence $evidence
     * @return array
     */
    private function formatEvidence(\OCA\PettyCash\Domain\Evidence\Evidence $evidence): array
    {
        return $this->payloadFormatter->evidence($evidence);
    }
}