<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use InvalidArgumentException;
use OCA\PettyCash\Application\Reporting\UnsupportedSelection;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Reporting\DashboardQuery;
use OCA\PettyCash\Application\Reporting\ExportService;
use OCA\PettyCash\Application\Reporting\ReportFilter;
use OCA\PettyCash\Application\Reporting\ReportFilterFactory;
use OCA\PettyCash\Application\Reporting\AuditSelectionQuery;
use OCA\PettyCash\Application\Reporting\EvidenceSelectionQuery;
use OCA\PettyCash\Application\Reporting\ReportQuery;
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
    /**
     * The declared columns of each resource this endpoint can enumerate.
     *
     * They exist for one reason: a selection that a filter narrowed to **nothing** must still produce
     * an artifact that says what it would have contained. `contracts/exports.md` requires "an explicit
     * column header row", and a header derived from the first row cannot be emitted when there is no
     * first row — so the true-negative result was a zero-byte file, indistinguishable from a build that
     * failed. They are also the vocabulary a filter is evaluated against, which is why they are stated
     * once, here, rather than inferred per request.
     */
    private const SHEET_COLUMNS = ['id', 'title', 'ownerUid', 'status', 'currency', 'submittedTotalMinor', 'createdAt'];

    private const TRANSACTION_COLUMNS = [
        'id', 'ownerUid', 'stage', 'status', 'sheetId', 'createdAt',
        'costCenterDbId', 'costCenterCode', 'expenseTypeDbId', 'expenseTypeTitle', 'amountMinor', 'currency',
    ];

    /**
     * How many rows an evidence or audit export may read.
     *
     * A named selection is resolved id by id and is not bounded by this at all — the cap applies
     * only to the "export everything in scope" case, which enumerates. Stated rather than left
     * implicit because a cap that silently truncated a caller's named selection would be a
     * selection that was accepted and discarded, which is the invariant this method exists to
     * make impossible.
     */
    private const SELECTION_BOUND = 1000;

    public function __construct(
        IRequest $request,
        private CurrentUser $currentUser,
        private DashboardQuery $dashboard,
        private ExportService $exports,
        private ReportQuery $reports,
        private ReportFilterFactory $filters,
        private EvidenceSelectionQuery $evidenceSelection,
        private AuditSelectionQuery $auditSelection,
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
            // `Error.fieldErrors` is a map of field name to a **list** of messages
            // (`contracts/ocs-api.yaml`), and every other controller in the app builds it that way.
            // These three were bare strings, so this endpoint emitted
            // `fieldErrors.fileFormat: "required"` where the contract and `src/types/api.ts` both
            // promise `["required"]` — a value the client's typed loop cannot iterate. Found by
            // `ErrorEnvelopeContractTest`, which asserts the declared shape rather than only the
            // status.
            $fieldErrors = [];
            if ($commandId === '') {
                $fieldErrors['commandId'] = ['commandId is required'];
            }
            if ($resource === '') {
                $fieldErrors['resource'] = ['resource is required'];
            }
            if ($fileFormat === '') {
                // Reported under the contract's new name, not the old one: a client that was told
                // `format` is required would look for a field that no longer exists.
                $fieldErrors['fileFormat'] = ['fileFormat is required'];
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

            // Whether the request is servable at all is decided before the selection is examined.
            // Asking "which sheets?" of a `pdf` request would answer with a selection complaint and
            // hide the real reason -- that the format cannot be produced -- behind an unrelated one.
            $permitted = $this->exports->authorization($scope, $resource, $fileFormat);
            if (!$permitted['allowed']) {
                throw new UnsupportedSelection($permitted['reason']);
            }

            // Parsed after the authorization decision, because an unrecognised filter is a complaint
            // about the selection and must not mask the answer to "can this be exported at all".
            $filter = $this->filterFrom($params['filters'] ?? null);

            // Resolve the selection *before* generating. Authorize-and-generate in one call still
            // holds -- `ExportService::generate()` refuses before it writes anything, which is the
            // ordering `contracts/exports.md` requires -- but the rows are now the selected ones.
            [$rows, $columns] = $this->rowsForSelection($resource, $ids, $filter, $scope, $fileFormat, $uid);

            // T367: the ZIP entries the grouped evidence export needs.
            //
            // This argument was a literal `[]` and that was the whole defect: `ExportService`
            // routes `zip` to `generateZip($entries)`, `EvidenceArchiveExporter` treats an empty
            // entry list as "nothing to add" and still writes a manifest, so the endpoint could
            // only ever have answered with **a valid, empty ZIP carrying an empty manifest** — the
            // "manifest that looks like evidence but is not" failure T110's result object exists to
            // prevent. `grep -rn "new EvidenceArchiveEntry" lib/` returned nothing, so nothing in
            // the application could produce one.
            //
            // Built only for the one combination that reads them: `evidence` + `zip`. Building
            // entries for a csv export would do four lookups per selected id and discard them.
            $entries = [];
            if ($resource === ExportService::RESOURCE_EVIDENCE && $fileFormat === ExportService::FORMAT_ZIP) {
                if ($ids === []) {
                    throw new UnsupportedSelection(
                        "A 'zip' export of resource 'evidence' needs a selection: pass the evidence "
                        . 'ids to include. Building an archive of every file you can see is not '
                        . 'implemented, and answering with an empty archive would misreport it as an '
                        . 'empty result.'
                    );
                }
                $entries = $this->evidenceSelection->archiveEntries($scope, $uid, $ids);
            }

            $result = $this->exports->generate($scope, $resource, $fileFormat, $rows, $entries, $columns);


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
                // FR-068: an export that resolved a predefined period states the range and the zone, for
                // the same reason a report does — and an export is where the disagreement actually gets
                // noticed, because two finance officers reconcile their figures against each other. Built
                // by `ReportFilter` so this endpoint and `GET /reports` cannot state it differently.
                ...$filter->periodDisclosure(),
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
     * Reduce rows to the ids the caller named, or refuse.
     *
     * ## Why this refuses rather than filters
     *
     * Filtering alone is the defect this method exists to prevent, in its row form. A caller naming
     * three evidence files, one of which does not exist or is someone else's, would receive a
     * well-formed artifact carrying **two** — with `rowCount: 2` and nothing anywhere saying the third
     * was dropped. That is the same shape T302 recorded for `ids` on sheets: a selection that is
     * accepted and discarded, which is worse than a refusal because the numbers look measured.
     *
     * So every named id must be accounted for. A missing id and a foreign id are indistinguishable
     * here on purpose: distinguishing them would confirm the existence of records the caller may not
     * see, which is the disclosure FR-003 exists to prevent.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private function narrowToNamedIds(array $rows, array $ids, string $resource): array
    {
        $wanted = array_values(array_unique($ids));
        $byId = [];

        foreach ($rows as $row) {
            $byId[(string) ($row['id'] ?? '')] = $row;
        }

        $missing = array_values(array_filter(
            $wanted,
            static fn (string $id): bool => !isset($byId[$id])
        ));

        if ($missing !== []) {
            throw new UnsupportedSelection(
                sprintf(
                    "No %s in scope matches %s. A selection is honoured in full or refused; an export "
                    . 'that silently dropped %s would report a row count that looks measured.',
                    $resource,
                    count($missing) === 1 ? "id '{$missing[0]}'" : 'ids ' . implode(', ', $missing),
                    count($missing) === 1 ? 'it' : 'them'
                )
            );
        }

        // Reindexed: the artifact writer iterates by position, so a gap would become a blank line.
        return array_values(array_map(
            static fn (string $id): array => $byId[$id],
            $wanted
        ));
    }

    /**
     * Apply a selection query's scope predicate, passing the scope the way its contract declares it.
     *
     * The predicates are `callable(array<string, mixed>, ReportScope): bool` — the same shape
     * `ReportQuery::paginate()` calls them with — so they cannot be handed straight to
     * `array_filter()`, which passes one argument. Doing that produced an
     * `ArgumentCountError` from inside the closure and therefore a bare 500 on every evidence export.
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>, ReportScope): bool $inScope
     * @return list<array<string, mixed>>
     */
    private function scopedRows(array $rows, callable $inScope, ReportScope $scope): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => (bool) $inScope($row, $scope)
        ));
    }

    /**
     * The rows the caller selected, narrowed by the filter, or a refusal.
     *
     * ## The invariant this exists to establish
     *
     * A selection is either honoured or refused. It is never accepted and discarded, which is what
     * the empty row set did: `ExportRequest.ids` and `ExportRequest.filters` were declared,
     * documented, and read by nobody, so a caller selecting two sheets and a date range received a
     * correctly-shaped artifact containing neither. That is worse than a 422, because `rowCount` and
     * `byteSize` make the empty answer look measured rather than missing.
     *
     * `ids` was the half T302 closed. `filters` was still open: it was read for exactly one thing —
     * the boolean "did the caller ask for something" — so a request carrying both an id list and a
     * filter received the ids, unfiltered, with nothing in the response to say the filter had been
     * discarded. Measured on the live endpoint before this task: the same request answered
     * `rowCount: 1` for a filter that matched the sheet, for one that could not, for an unknown filter
     * name, and for a date range that excluded it.
     *
     * So both halves are resolved here: the ids to real, scope-checked rows, and then the filter to a
     * narrowing of those rows, applied by `ReportQuery` so there is one definition of what a filter
     * means rather than one per endpoint. For every resource that does not resolve a selection at all,
     * the request is refused with a reason naming what is not yet supported.
     *
     * ## A filter narrows, it does not create
     *
     * A `filters`-only request is refused rather than answered, and the two refusals say different
     * things. With nothing at all there is no selection to speak of; with a filter and no ids there is
     * a filter with nothing to narrow, and enumerating everything in scope is a different feature that
     * is not implemented. Answering either with an empty artifact would report "you have no sheets" for
     * what is really "I was not asked which sheets".
     *
     * ## A missing id and a foreign id are the same answer
     *
     * An id that does not exist and an id belonging to someone else both produce the same refusal.
     * That is deliberate and matches `AccountController`'s treatment of a foreign ledger: if the two
     * were distinguishable, this endpoint would confirm the existence of records the caller may not
     * see, which is the disclosure FR-003 exists to prevent. The filter cannot change that, because it
     * runs after the lookup and the scope check rather than instead of them.
     *
     * @param list<string> $ids
     * @return array{list<array<string, mixed>>, list<string>} the rows and the resource's declared columns
     */
    private function rowsForSelection(
        string $resource,
        array $ids,
        ReportFilter $filter,
        ReportScope $scope,
        string $fileFormat,
        string $uid
    ): array {
        $selectsSomething = $ids !== [] || $filter->hasFieldFilters();

        if ($resource === ExportService::RESOURCE_SHEET || $resource === ExportService::RESOURCE_SHEETS) {
            return [
                $this->reports->narrow($filter, $this->sheetRows($ids, $scope, $fileFormat, $selectsSomething)),
                self::SHEET_COLUMNS,
            ];
        }

        if ($resource === ExportService::RESOURCE_TRANSACTIONS) {
            return [
                $this->reports->narrow(
                    $filter,
                    $this->transactionRows($ids, $scope, $fileFormat, $selectsSomething)
                ),
                self::TRANSACTION_COLUMNS,
            ];
        }

        // T367. These two answered 422 in **both** the selected and the unselected case, because
        // nothing resolved rows for them — so no request shape produced an artifact for a resource
        // the contract declares and the client offers. Both producers already existed for
        // GET /reports/{type}; they are called rather than copied, so a scope rule cannot mean one
        // thing in a report and another in an export.
        if ($resource === ExportService::RESOURCE_EVIDENCE) {
            [$rows, $inScope, $columns] = $this->evidenceSelection->candidates($uid, self::SELECTION_BOUND);

            if ($ids !== []) {
                $rows = $this->narrowToNamedIds($rows, $ids, 'evidence');
            }

            return [
                $this->reports->narrow($filter, $this->scopedRows($rows, $inScope, $scope)),
                $columns,
            ];
        }

        if ($resource === ExportService::RESOURCE_AUDIT) {
            [$rows, $inScope, $columns] = $this->auditSelection->candidates($uid, self::SELECTION_BOUND);

            if ($ids !== []) {
                $rows = $this->narrowToNamedIds($rows, $ids, 'audit');
            }

            return [
                $this->reports->narrow($filter, $this->scopedRows($rows, $inScope, $scope)),
                $columns,
            ];
        }

        if ($selectsSomething) {
            // The refusal that makes the defect impossible to reintroduce silently. `report`, `audit`
            // and `evidence` do not yet resolve a selection, and until they do the honest answer is
            // "not supported" rather than an artifact that quietly omits what was asked for.
            throw new UnsupportedSelection(
                "Exporting a selection of '{$resource}' is not supported yet; ids/filters are honoured "
                . "for resource 'sheet', 'sheets', 'transactions', 'evidence' and 'audit'. Requesting "
                . "the export of 'report' without a selection is unaffected."
            );
        }

        // **No selection, and no row source either — so this is refused rather than answered with an
        // empty artifact.** The code this replaces returned `[[], []]` under the comment "an empty
        // artifact, which is at least an honest answer", and it was not one: `ExportResult` then carried
        // `rowCount: 0` and `byteSize` for a real file, so the emptiness read as **measured**. A user
        // who exported the cost-centre report got a well-formed CSV with a header row and no rows, and
        // nothing anywhere said the report had no exporter (T342).
        //
        // The distinction this draws is the one that matters: an export of `sheets`/`transactions` that
        // matches nothing is a *measured* zero and is answered `200` with `rowCount: 0` — asserted by
        // `testAnEmptyResultStillNamesItsColumns`. An export of a resource with no row projection is not
        // a measurement at all, and the only honest answer is the same refusal a selection gets.
        throw new UnsupportedSelection(
            "Exporting resource '{$resource}' is not supported yet; rows are resolved for 'sheet', "
            . "'sheets', 'transactions', 'evidence' and 'audit', and 'report' is the one resource "
            . 'still without a row source. No artifact is produced for a resource with no row '
            . 'source, because an empty file with a row count of zero reads as a measured result.'
        );

        // The refusal above is the only exit for a resource with no row source: every path that reaches
        // here has already answered, or has refused on its own terms.
        throw new \LogicException('Unreachable: every resource without a row projection is refused above.');
    }

    /**
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private function sheetRows(array $ids, ReportScope $scope, string $format, bool $selectsSomething): array
    {
        if (!$selectsSomething) {
            throw new UnsupportedSelection(
                "A '{$format}' export of resource 'sheets' needs a selection: pass ids. Exporting every "
                . 'sheet is not implemented, and answering with an empty artifact would misreport it as '
                . 'an empty result.'
            );
        }

        if ($ids === []) {
            throw new UnsupportedSelection(
                "A '{$format}' export of resource 'sheets' cannot be narrowed by 'filters' alone: a "
                . 'filter selects from a set of records, and passing ids is how the set is chosen. '
                . 'Exporting every sheet in your scope is not implemented, so this request is refused '
                . 'rather than answered with an artifact that omits the selection entirely.'
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
     * The transaction export's rows carry the same columns the transactions report carries, and for
     * the same reason FR-028 gives.
     *
     * A transaction's cost centre, expense type, amount and currency are recorded on its **own active
     * revision**, not on its sheet, because one sheet may hold transactions for several cost centres.
     * Carrying them here is what makes FR-068's "cost center transactions" a selection this endpoint
     * can honour rather than one it has to refuse: without the columns, `costCenterDbIds` is
     * unanswerable and the facet is lost, and the export and the report would have two different ideas
     * of what a transaction row is — which is the drift T307 had to unpick on the report side.
     *
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private function transactionRows(array $ids, ReportScope $scope, string $format, bool $selectsSomething): array
    {
        if (!$selectsSomething) {
            throw new UnsupportedSelection(
                "A '{$format}' export of resource 'transactions' needs a selection: pass ids. Exporting "
                . 'every transaction is not implemented, and answering with an empty artifact would '
                . 'misreport it as an empty result.'
            );
        }

        if ($ids === []) {
            throw new UnsupportedSelection(
                "A '{$format}' export of resource 'transactions' cannot be narrowed by 'filters' alone: a "
                . 'filter selects from a set of records, and passing ids is how the set is chosen. '
                . 'Exporting every transaction in your scope is not implemented, so this request is '
                . 'refused rather than answered with an artifact that omits the selection entirely.'
            );
        }

        $rows = [];
        foreach ($ids as $id) {
            $transaction = $this->transactions->findByPublicId($id);
            if ($transaction === null || !$scope->coversOwned($transaction->ownerUid)) {
                throw new UnsupportedSelection("No transaction is available to you with id '{$id}'.");
            }

            $revision = $transaction->revision();

            $rows[] = [
                'id' => $transaction->publicId,
                'ownerUid' => $transaction->ownerUid,
                'stage' => $transaction->stage->value,
                'status' => $transaction->status->value,
                'sheetId' => $transaction->sheetId,
                'createdAt' => $transaction->createdAt?->format(DATE_ATOM),
                'costCenterDbId' => $revision->costCenterDbId,
                'costCenterCode' => $revision->costCenterCode,
                'expenseTypeDbId' => $revision->expenseTypeDbId,
                'expenseTypeTitle' => $revision->expenseTypeTitle(),
                'amountMinor' => $revision->amount()->minorUnits(),
                'currency' => (string) $revision->amount()->currency(),
            ];
        }

        return $rows;
    }

    /**
     * `ExportRequest.filters`, read as the contract's object.
     *
     * The contract declares `filters` as `type: object`, so a JSON body delivers an array and that is
     * the shape expected here. Two things it does *not* permit:
     *
     * - **A value that is not an object at all.** A client that stringifies its filter — a form-encoded
     *   request, or a wrapper that serialises everything — used to get its filter silently dropped,
     *   because `is_array()` alone treated anything else as "no filters". That is the accepted-and-
     *   discarded defect by a different route, and it is the one a client is least likely to notice,
     *   since the response is a perfectly good unfiltered export.
     * - **An empty object being refused.** A caller that builds a filter and finds every value empty
     *   has asked for no filter. Refusing that would fail an unrelated client change for no reason.
     *
     * What the filter *means* is `ReportFilterFactory`'s, shared with the report endpoint.
     */
    private function filterFrom(mixed $raw): ReportFilter
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return new ReportFilter();
        }

        if (!is_array($raw)) {
            throw new UnsupportedSelection(sprintf(
                'ExportRequest.filters must be an object of filter names, not a %s. Accepted filters '
                . 'are: %s. A filter that cannot be read is refused rather than ignored, because '
                . 'ignoring it exports the whole selection while the caller believes it was narrowed.',
                get_debug_type($raw),
                implode(', ', ReportFilterFactory::ACCEPTED_FILTERS)
            ));
        }

        return $this->filters->fromArray($raw);
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
