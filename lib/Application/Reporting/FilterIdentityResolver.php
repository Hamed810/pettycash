<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use InvalidArgumentException;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;

/**
 * Public identifiers to the surrogate keys `ReportQuery` actually filters on.
 *
 * ## Why this exists
 *
 * A report row carries `costCenterDbId` and `expenseTypeDbId`, because those are the join keys the
 * query reads. That is an internal fact. `data-model.md` states it as a rule — "Every externally
 * addressable aggregate uses an immutable UUID `public_id`; numeric `id` values remain internal join
 * keys" — and a wire filter named `costCenterDbIds` publishes an internal join key across the API
 * boundary. A caller cannot obtain one without a server round trip it has no other reason to make, and
 * an internal id that leaks becomes an identifier the API is then obliged to keep stable.
 *
 * So the vocabulary accepts `costCenterIds`/`expenseTypeIds` in **public** ids, and this class does the
 * resolution. The client is never asked for a surrogate key; that is the whole point.
 *
 * ## The `*DbIds` spellings stay
 *
 * They are removed only by a decision to change the wire contract, which no task has made: T306 and
 * T309 pinned them in `ReportAndExportControllerTest`, and `ExportController` accepts the same object
 * in `ExportRequest.filters`. Dropping them here would be a breaking change made as a side effect of
 * fixing a client. Both spellings resolve to the same `ReportFilter` fields and union, so a request
 * naming either — or both — means "these cost centres", which is what a caller expressing two id spaces
 * for one facet intends.
 *
 * ## An id that resolves to nothing is refused, not dropped
 *
 * Dropping it would widen the result set to everything, which is the failure this whole vocabulary
 * exists to prevent (see `ReportFilterFactory`): the caller asked for a subset, would receive the
 * superset, and nothing in the response would say so. A 422 that names the identifier is the only
 * answer that lets the caller fix the request in one attempt.
 *
 * A resolved entity whose `id` is null is treated the same way, and for the same reason: it has no
 * surrogate key to filter on, so it cannot become a match.
 */
final readonly class FilterIdentityResolver
{
    public function __construct(
        private CostCenterRepository $costCenters,
        private ExpenseTypeRepository $expenseTypes
    ) {}

    /**
     * @param list<string> $publicIds
     * @return list<int>
     * @throws InvalidArgumentException when an id names no cost centre
     */
    public function costCenterDbIds(array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }

        $dbIds = [];
        foreach ($publicIds as $publicId) {
            $costCenter = $this->costCenters->findByPublicId($publicId);
            if ($costCenter === null || $costCenter->id === null) {
                throw new InvalidArgumentException(sprintf(
                    "Filter 'costCenterIds' names \"%s\", which is not the public id of any cost centre. "
                    . 'Cost centres are addressed by their public UUID; a numeric database id is an '
                    . 'internal join key and is not accepted here.',
                    $publicId
                ));
            }
            $dbIds[] = $costCenter->id;
        }

        return array_values(array_unique($dbIds));
    }

    /**
     * @param list<string> $publicIds
     * @return list<int>
     * @throws InvalidArgumentException when an id names no expense type
     */
    public function expenseTypeDbIds(array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }

        $dbIds = [];
        foreach ($publicIds as $publicId) {
            $expenseType = $this->expenseTypes->findByPublicId($publicId);
            if ($expenseType === null || $expenseType->id === null) {
                throw new InvalidArgumentException(sprintf(
                    "Filter 'expenseTypeIds' names \"%s\", which is not the public id of any expense type. "
                    . 'Expense types are addressed by their public UUID; a numeric database id is an '
                    . 'internal join key and is not accepted here.',
                    $publicId
                ));
            }
            $dbIds[] = $expenseType->id;
        }

        return array_values(array_unique($dbIds));
    }
}