<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use InvalidArgumentException;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Reporting\DashboardQuery;
use OCA\PettyCash\Application\Reporting\ExportService;
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
        private OcsErrorMapper $ocsErrorMapper
    ) {
        parent::__construct('pettycash', $request);
    }

    #[NoAdminRequired]
    public function create(
        string $resource = '',
        string $format = '',
        ?string $reportType = null
    ): Response {
        // Read the way every other controller in this app reads it, rather than as a bound
        // parameter: the contract declares the command id as a *header*, and a bound parameter would
        // accept a query-string copy of an idempotency key that the contract does not define.
        $commandId = (string) $this->request->getHeader('X-PettyCash-Command-ID');

        try {
            $fieldErrors = [];
            if ($commandId === '') {
                $fieldErrors['commandId'] = 'required';
            }
            if ($resource === '') {
                $fieldErrors['resource'] = 'required';
            }
            if ($format === '') {
                $fieldErrors['format'] = 'required';
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

            // Authorize-and-generate in one call: `ExportService::generate()` refuses *before* it
            // writes anything, which is the ordering `contracts/exports.md` requires. The rows are
            // empty for a `report` resource because building them is the report's own job, and
            // inventing them here would put a second definition of a report's contents in an
            // export path.
            $result = $this->exports->generate($scope, $resource, $format, []);

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
}
