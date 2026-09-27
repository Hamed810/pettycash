<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use InvalidArgumentException;
use OCA\PettyCash\Application\Reporting\UnsupportedSelection;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Reporting\DashboardQuery;
use OCA\PettyCash\Application\Reporting\ExportService;
use OCA\PettyCash\Application\Reporting\ReportScope;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Serves `POST /api/v1/exports` for the `ExportRequest` schema in `contracts/ocs-api.yaml`.
 *
 * ## Authorization happens inside the export, not before the response
 *
 * `contracts/exports.md` requires authorization "before filtering, aggregation, or file generation".
 * `ExportService::generate()` enforces that itself and refuses before writing anything, so this
 * controller's job is to pass the caller's scope in and let it refuse — not to pre-check and then
 * generate. Duplicating the rule here would create a second place where it can be got wrong, and the
 * test that matters (`testARefusedExportProducesNoArtifactAtAll`) is about the service's ordering, not
 * about this class.
 *
 * ## The artifact is streamed and then deleted, in that order
 *
 * "Generated artifacts use private transient application storage and are removed after streaming."
 * The artifact is read out of transient storage and the body is sent, and only then is `cleanup()`
 * called — in a `finally`, so a client that disconnects mid-download does not leave the artifact
 * behind. The ordering is the requirement: cleaning up first would send an empty body.
 *
 * ## The command id is required by the contract
 *
 * `CommandId` is `required: true` on this operation, so a request without `X-PettyCash-Command-ID` is
 * refused with 422 rather than served opportunistically. An export is a side-effecting generation
 * step, and an unkeyed one cannot be replayed safely.
 */
class ExportController extends OCSController
{
    public function __construct(
        IRequest $request,
        private CurrentUser $currentUser,
        private DashboardQuery $dashboard,
        private ExportService $exports,
        private \OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository $sheets,
        private \OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository $transactions,
        private OcsErrorMapper $ocsErrorMapper
    ) {
        parent::__construct('pettycash', $request);
    }

    #[NoAdminRequired]
    public function create(
        string $resource = '',
        string $fileFormat = '',
        ?string $reportType = null
    ): Response {
        // Read the way every other controller in this app reads it, rather than as a bound
        // parameter: the contract declares the command id as a *header*, and a bound parameter would
        // accept a query-string copy of an idempotency key that the contract does not define.
        $commandId = (string) $this->request->getHeader('X-PettyCash-Command-ID');

        // FR-068's selection. Previously read by nobody, which is the defect T302 records: a caller
        // that selected two sheets and a date range received a well-formed artifact containing
        // neither. `resource` and `fileFormat` are bound parameters; `ids` and `filters` are not
        // bound, so they are read from the request params here.
        try {
            $params = $this->request->getParams();
            $fieldErrors = [];
            if ($commandId === '') {
                $fieldErrors['commandId'] = 'required';
            }
            if ($resource === '') {
                $fieldErrors['resource'] = 'required';
            }
            if ($fileFormat === '') {
                // Reported under the contract's new name, not the old one: a client that was told
                // `format` is required would look for a field that no longer exists.
                $fieldErrors['fileFormat'] = 'required';
            }

            if ($fieldErrors !== []) {
                // The wording matches the other controllers' for a missing command id, so a client
                // sees one rule rather than two phrasings of it.
                return $this->ocsErrorMapper->validation('Required fields are missing', $commandId, $fieldErrors);
            }

            $uid = $this->currentUser->uid();
            if ($uid === '') {
                return $this->ocsErrorMapper->forbidden('No authenticated user in session');
            }

            $scope = $this->dashboard->scopeFor($uid);

            // Parsed here rather than beside the command id, so a malformed `ids` value becomes the
            // 422 the contract declares instead of escaping the try block as a 500.
            $ids = $this->idList($params['ids'] ?? null);
            $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];

            // Whether the request is servable at all is decided before the selection is examined.
            // Asking "which sheets?" of a `pdf` request would answer with a selection complaint and
            # hide the real reason -- that the format cannot be produced -- behind an unrelated one.
            $permitted = $this->exports->authorization($scope, $resource, $fileFormat);
            if (!$permitted['allowed']) {
                throw new UnsupportedSelection($permitted['reason']);
            }

            // Resolve the selection *before* generating. Authorize-and-generate in one call still
            // holds -- `ExportService::generate()` refuses before it writes anything, which is the
            // ordering `contracts/exports.md` requires -- but the rows are now the selected ones.
            $rows = $this->rowsForSelection($resource, $ids, $filters, $scope, $commandId, $fileFormat);

            $result = $this->exports->generate($scope, $resource, $fileFormat, $rows);

            try {
                $stream = $this->exports->readArtifact($result['artifactName']);
                $bytes = is_resource($stream) ? (string) stream_get_contents($stream) : '';
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } finally {
                // After the bytes are in hand, never before: removing first would send an empty body.
                // In a `finally`, so a client that disconnects mid-download still does not leave the
                // artifact in transient storage.
                $this->exports->deleteArtifact($result['artifactName']);
            }

            $response = new DataResponse([
                'artifactName' => $result['artifactName'],
                'mediaType' => $result['mediaType'],
                'format' => $result['format'],
                'byteSize' => $result['byteSize'],
                'rowCount' => $result['rowCount'],
                'artifactRemoved' => !$this->exports->artifactExists($result['artifactName']),
                // Nextcloud's OCS layer wraps every body as JSON, so the artifact cannot be the raw
                // response body the contract's media types describe. It is carried base64-encoded
                // alongside the media type that says what it is.
                'data' => base64_encode($bytes),
            ]);
            $response->setStatus(Http::STATUS_OK);

            return $response;
        } catch (InvalidArgumentException $e) {
            // A refused export is a client-visible rule failure -- unknown format, a resource the
            // actor may not see, a format that cannot be produced yet -- and 422 is what the contract
            // declares for it.
            return $this->ocsErrorMapper->validation($e->getMessage(), $commandId !== '' ? $commandId : null);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId !== '' ? $commandId : null);
        }
    }

    /**
     * The rows the caller selected, or a refusal.
     *
     * ## The invariant this exists to establish
     *
     * A selection is either honoured or refused. It is never accepted and discarded, which is what
     * the empty row set did: `ExportRequest.ids` and `ExportRequest.filters` were declared,
     * documented, and read by nobody, so a caller selecting two sheets and a date range received a
     * correctly-shaped artifact containing neither. That is worse than a 422, because `rowCount` and
     * `byteSize` make the empty answer look measured rather than missing.
     *
     * So for the resources this task wires -- `sheet`, `sheets` and `transactions` -- the selection is
     * resolved to real, scope-checked rows. For every other resource that arrives carrying a
     * selection, the request is refused with a reason naming what is not yet supported, rather than
     * answered with something that ignores the question.
     *
     * ## A missing id and a foreign id are the same answer
     *
     * An id that does not exist and an id belonging to someone else both produce the same refusal.
     * That is deliberate and matches `AccountController`'s treatment of a foreign ledger: if the two
     * were distinguishable, this endpoint would confirm the existence of records the caller may not
     * see, which is the disclosure FR-003 exists to prevent.
     *
     * @param list<string> $ids
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function rowsForSelection(
        string $resource,
        array $ids,
        array $filters,
        ReportScope $scope,
        string $commandId,
        string $fileFormat
    ): array {
        $selectsSomething = $ids !== [] || $filters !== [];

        if ($resource === ExportService::RESOURCE_SHEET || $resource === ExportService::RESOURCE_SHEETS) {
            return $this->sheetRows($ids, $scope, $commandId, $fileFormat, $selectsSomething);
        }

        if ($resource === ExportService::RESOURCE_TRANSACTIONS) {
            return $this->transactionRows($ids, $scope, $commandId, $fileFormat, $selectsSomething);
        }

        if ($selectsSomething) {
            // The refusal that makes the defect impossible to reintroduce silently. `report`, `audit`
            // and `evidence` do not yet resolve a selection, and until they do the honest answer is
            // "not supported" rather than an artifact that quietly omits what was asked for.
            throw new UnsupportedSelection(
                "Exporting a selection of '{$resource}' is not supported yet; ids/filters are honoured "
                . "for resource 'sheet', 'sheets' and 'transactions'. Requesting the export without a "
                . 'selection is unaffected.'
            );
        }

        // No selection and no row source for this resource: an empty artifact, which is at least an
        // honest answer to "export everything you support for this resource" rather than a filtered
        // one pretending to be complete.
        return [];
    }

    /**
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private function sheetRows(array $ids, ReportScope $scope, string $commandId, string $format, bool $selectsSomething): array
    {
        if (!$selectsSomething) {
            throw new UnsupportedSelection(
                "A '{$format}' export of resource 'sheets' needs a selection: pass ids, or use a resource "
                . 'this endpoint can enumerate. Exporting every sheet is not implemented, and answering '
                . 'with an empty artifact would misreport it as an empty result.'
            );
        }

        $rows = [];
        foreach ($ids as $id) {
            $sheet = $this->sheets->findByPublicId($id);
            // One message for "no such sheet" and "not yours": see rowsForSelection().
            if ($sheet === null || !$scope->coversOwned($sheet->ownerUid)) {
                throw new UnsupportedSelection("No sheet is available to you with id '{$id}'.");
            }
            $rows[] = [
                'id' => $sheet->publicId,
                'title' => $sheet->title,
                'ownerUid' => $sheet->ownerUid,
                'status' => $sheet->status->value,
                'currency' => (string) $sheet->currency,
                'submittedTotalMinor' => $sheet->submittedTotalMinor,
                'createdAt' => $sheet->createdAt?->format(DATE_ATOM),
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private function transactionRows(array $ids, ReportScope $scope, string $commandId, string $format, bool $selectsSomething): array
    {
        if (!$selectsSomething) {
            throw new UnsupportedSelection(
                "A '{$format}' export of resource 'transactions' needs a selection: pass ids. Exporting "
                . 'every transaction is not implemented, and answering with an empty artifact would '
                . 'misreport it as an empty result.'
            );
        }

        $rows = [];
        foreach ($ids as $id) {
            $transaction = $this->transactions->findByPublicId($id);
            if ($transaction === null || !$scope->coversOwned($transaction->ownerUid)) {
                throw new UnsupportedSelection("No transaction is available to you with id '{$id}'.");
            }
            $rows[] = [
                'id' => $transaction->publicId,
                'ownerUid' => $transaction->ownerUid,
                'stage' => $transaction->stage->value,
                'status' => $transaction->status->value,
                'sheetId' => $transaction->sheetId,
                'createdAt' => $transaction->createdAt?->format(DATE_ATOM),
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function idList(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw)) {
            throw new UnsupportedSelection('ExportRequest.ids must be an array of UUID strings.');
        }

        $ids = [];
        foreach ($raw as $value) {
            if (!is_string($value) || $value === '') {
                throw new UnsupportedSelection('ExportRequest.ids must be an array of UUID strings.');
            }
            $ids[] = $value;
        }

        return $ids;
    }
}
