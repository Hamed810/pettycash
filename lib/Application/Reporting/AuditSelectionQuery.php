<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;

/**
 * The transaction-audit trail the `audit` report and an `audit` export both read.
 *
 * ## Why this is a class and not two private methods
 *
 * `ReportController::auditReport()` already produced these rows. T367 added a second consumer —
 * `POST /exports` with `resource: audit`, which answered **422 in both the selected and the
 * unselected case**, because `ExportController::rowsForSelection()` knew how to resolve rows for
 * `sheet`, `sheets` and `transactions` only.
 *
 * The obvious fix is to copy the producer into the export controller. That is the T307 shape this
 * codebase has been bitten by repeatedly: one definition of a rule in two places, one of them added
 * later and never cross-checked, and a filter or a scope change that reaches only one of them. The
 * same reasoning moved `ReportFilterFactory` out of `ReportController` — its own docblock records
 * that "a filter name that meant one thing here and another there is the T307 shape again".
 *
 * So there is one producer, both endpoints call it, and neither can drift from the other.
 *
 * ## The scope rule, and why it is the actor's own trail
 *
 * An event is visible when the actor is the caller. Stated rather than derived, because the wider
 * alternatives are real and none is chosen here: widening to every event an administrator can see
 * would need a rule for who may read another person's history, and **no requirement states one**.
 * An empty scope must therefore never widen to "everything", which is the same direction
 * `ReportQuery::configurationAuditScope()` refuses in its own words.
 */
final class AuditSelectionQuery
{
    public function __construct(private AuditEventRepository $auditEvents) {}

    /**
     * The row set, its scope predicate and its declared columns.
     *
     * The three travel together because they are one contract: a caller that changed the columns
     * without the rows, or scoped rows it did not enumerate, would produce an artifact or a page
     * whose declared shape no longer describes its contents.
     *
     * @return array{list<array<string, mixed>>, callable(array<string, mixed>, ReportScope): bool, list<string>, array<string, mixed>}
     */
    public function candidates(string $uid, int $bound): array
    {
        $rows = array_map(
            static fn ($event): array => [
                'id' => $event->id,
                'actorUid' => $event->actorUid,
                'actorRole' => $event->actorRole,
                'eventType' => $event->eventType,
                'entityType' => $event->entityType,
                'entityId' => $event->entityId,
                // FR-064 names `stage` as an audit facet and fixes its source: "an audit event's
                // **stage** is the stage recorded on the event". Carried so that `{"stages":[...]}`
                // is evaluable rather than refused as a filter no row can answer.
                'stage' => $event->stage,
                // `DATE_ATOM` rather than a default format, because `ReportQuery`'s date-range rule
                // parses these strings and a locale-dependent one would not round-trip.
                'occurredAt' => $event->occurredAt->format(DATE_ATOM),
            ],
            $this->auditEvents->findByActorUid($uid, 0, $bound)
        );

        return [
            $rows,
            static fn (array $row): bool => ($row['actorUid'] ?? null) === $uid,
            self::columns(),
            ['scope' => 'own_audit_trail', 'rowsRead' => count($rows), 'rowsBoundedBy' => $bound],
        ];
    }

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return ['id', 'actorUid', 'actorRole', 'eventType', 'entityType', 'entityId', 'stage', 'occurredAt'];
    }
}