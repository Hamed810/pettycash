<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Audit;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbAuditEventRepository implements AuditEventRepository
{
    /**
     * Audit streams. The two tables are disjoint in their subject columns: the transaction stream
     * carries sheet/transaction/revision/stage/decision, the configuration stream carries
     * entity_type/entity_id. Both share public_id, actor, payload, command_id and occurred_at.
     *
     * The stream name is a closed set so that the table it resolves to can never be an
     * arbitrary caller-supplied string reaching `from()`. An unrecognised name is refused at
     * construction rather than silently falling back to the transaction stream, which would
     * write configuration events into the transaction table.
     */
    private const STREAMS = [
        'transaction' => ['table' => 'pettycash_transaction_audit', 'subject' => 'transaction'],
        'configuration' => ['table' => 'pettycash_configuration_audit', 'subject' => 'configuration'],
    ];

    private readonly string $stream;

    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser,
        string $stream = 'transaction',
    ) {
        if (!isset(self::STREAMS[$stream])) {
            throw new \InvalidArgumentException(
                'Unknown audit stream: ' . $stream
            );
        }
        $this->stream = $stream;
    }

    private function table(): string
    {
        return self::STREAMS[$this->stream]['table'];
    }

    private function subject(): string
    {
        return self::STREAMS[$this->stream]['subject'];
    }

    public function findById(int $id): ?AuditEvent
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from($this->table())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?AuditEvent
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from($this->table())
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /**
     * The event type this repository orders by.
     *
     * ## Why the tiebreak is not optional
     *
     * `occurred_at` is stored at second granularity — every writer formats `Y-m-d H:i:s` — and a submit
     * followed by two approvals lands inside one second routinely. Ordering by `occurred_at` alone
     * therefore leaves same-second events in whatever order the database returns, which varies between
     * runs and, worse, between page boundaries. Measured: with a single-key ordering the
     * `occurred_at` sequence of a three-event timeline was *ascending* while the events themselves were
     * in reverse order, because all three shared one second.
     *
     * `id` is a monotonic autoincrement, so it is the insertion order — which for an append-only stream
     * is the order things happened. Adding it makes the sequence total, so the timeline is stable and
     * T319's paged walk cannot repeat or skip an event at a page seam.
     */
    private const TIMELINE_ORDER = ['occurred_at' => 'ASC', 'id' => 'ASC'];

    /** Apply the timeline ordering. */
    private function inTimelineOrder(IQueryBuilder $qb): void
    {
        foreach (self::TIMELINE_ORDER as $column => $direction) {
            $qb->addOrderBy($column, $direction);
        }
    }

    /**
     * T321: the sheet a transaction belongs to, as an internal id.
     *
     * Resolved with a second, indexed lookup rather than a correlated subquery in the timeline's
     * `WHERE`. The subquery would have been fewer lines but it cannot be built through
     * `IQueryBuilder`'s expression API without hand-assembling the table prefix, and a hand-assembled
     * prefix is the kind of thing that works until the deployment's `dbprefix` differs. This is the
     * same `select(...)->from('pettycash_transactions')` shape `DbExpenseTransactionRepository` uses,
     * so the prefix is handled by the driver.
     *
     * `null` when the transaction does not exist, which leaves the timeline reading as the plain
     * transaction predicate instead of matching nothing.
     */
    private function sheetDbIdFor(int $transactionId): ?int
    {
        $qb = $this->connection->getQueryBuilder();
        $sheetId = $qb->select('sheet_id')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionId)))
            ->executeQuery()
            ->fetchOne();

        if ($sheetId === false || $sheetId === null) {
            return null;
        }

        // `BIGINT` arrives as a string on some drivers and an int on others, the same reason
        // `mapRow()` coerces rather than assumes.
        return (int) $sheetId;
    }

    /**
     * T321: what belongs on a transaction's audit timeline.
     *
     * **Resolution (B)**, and the reason it is not (A). A sheet submission is one fact about one sheet
     * that covers every transaction in it, so it is recorded once, with `sheet_id` set and
     * `transaction_id => null` (`SubmitSheet.php:119-120`) and `entity_type => 'sheet'`. That row is
     * correct, and `data-model.md:314` declares `transaction_id` nullable for exactly this reason.
     *
     * But a read filtered on `transaction_id` alone could never return it, so a transaction's audit
     * opened with `ROUTED` — the routing that happened *after* submission — and contained no submission
     * at all. FR-051 requires that "formal transaction audit MUST begin with sheet submission", and
     * US6/AC1 that submission is "the first formal event" a reader sees.
     *
     * (A) — writing one `SUBMITTED` event per transaction — was rejected because it would record the
     * same submission N times and contradict the `entity_type`/`entity_id` snapshot already on the row.
     * Folding the sheet's events in at read time keeps the fact single and the row honest, and the
     * `transaction_id IS NULL` clause is what makes it *singleton by construction*: a sheet-scoped
     * event matches no matter how many transactions the sheet later gains, and matches exactly once.
     *
     * **This helper is used by the count as well as the read, and that is the point.** T319 requires
     * `countByTransactionId()` to filter identically to `findByTransactionId()`, because a `total` that
     * does not describe its own page is worse than no total. Widening the read without widening the
     * count through this same helper would have shipped exactly that bug; sharing the predicate makes
     * the two unable to drift.
     */
    private function applyTransactionTimelineScope(IQueryBuilder $qb, int $transactionId): void
    {
        $ownEvents = $qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId));
        $sheetId = $this->sheetDbIdFor($transactionId);

        if ($sheetId === null) {
            $qb->andWhere($ownEvents);
            return;
        }

        $qb->andWhere($qb->expr()->orX(
            $ownEvents,
            $qb->expr()->andX(
                $qb->expr()->isNull('transaction_id'),
                $qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId, IQueryBuilder::PARAM_INT))
            )
        ));
    }

    /**
     * A transaction's official history — US6/AC1's "transaction timeline".
     *
     * Ordered by `inTimelineOrder()`, and scoped by `applyTransactionTimelineScope()`, so the sheet's
     * submission is the first event rather than an absent one. The submission precedes the
     * transaction's own `ROUTED`, and both share the `occurred_at` second that submission and routing
     * happen in, so the `id` tiebreak in `inTimelineOrder()` is what places the sheet-scoped row first:
     * `SubmitSheet` appends `SUBMITTED` before the per-transaction `ROUTED` events, so insertion order
     * and time order agree.
     *
     * @return list<AuditEvent>
     */
    public function findByTransactionId(int $transactionId, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from($this->table());
        $this->applyTransactionTimelineScope($qb, $transactionId);
        $qb->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->inTimelineOrder($qb);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /**
     * T319: the untruncated size of the set behind a page.
     *
     * Counted by the database rather than by reading a page and counting it. `findByTransactionId()`
     * is bounded by `setMaxResults`, so its return value can only ever describe the window it was
     * given; deriving the total from it produces a response that carries every key `PageMeta`
     * requires and still tells the client the history ends at the end of page one.
     *
     * The predicate is identical to the find's, deliberately, and it is the *same code* — both call
     * `applyTransactionTimelineScope()`. A count filtered slightly differently from the read it
     * describes is worse than no count at all: it looks authoritative and is not. T321 widened both
     * sides together; had only the read been widened, this would have reported a `total` that
     * contradicted its own page.
     *
     * `fetchOne()` returns the aggregate as the driver gives it — `COUNT(*)` is `BIGINT`, which
     * arrives as a string on some drivers and an int on others (the same reason `mapRow()` coerces
     * its integer columns rather than assuming). Cast rather than trust.
     */
    public function countByTransactionId(int $transactionId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'pettycash_audit_count'))
            ->from($this->table());
        $this->applyTransactionTimelineScope($qb, $transactionId);

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * The sheet's history — US6/AC1's "history table".
     *
     * Chronological, with the same tiebreak as `findByTransactionId()` and for the same reason: this
     * is a timeline, and the sheet view renders it beside the transaction revisions, which already come
     * back `revision_number ASC`. Ascending here and descending there put the two halves of one screen
     * in opposite directions.
     *
     * @return list<AuditEvent>
     */
    public function findBySheetId(int $sheetId, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from($this->table())
            ->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId)))
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->inTimelineOrder($qb);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /**
     * One actor's events, for the audit report — **not** the transaction timeline.
     *
     * Left newest-first deliberately, and the decision is recorded here rather than left implicit.
     * `ReportController:518` is the only caller and hands the rows to the report machinery, which does
     * not re-sort them; a bounded report read newest-first is the conventional order and **no artifact
     * states an order for reports** — US6/AC1 and SC-006 are about the timeline and the history table,
     * and FR-065/FR-066 speak of report *contents*, not sequence.
     *
     * Changing it would have been a silent behaviour change to a completed surface (T106, T112) on the
     * strength of a requirement that does not reach it. It is flagged rather than swept along.
     *
     * @return list<AuditEvent>
     */
    public function findByActorUid(string $actorUid, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from($this->table())
            ->where($qb->expr()->eq('actor_uid', $qb->createNamedParameter($actorUid)))
            ->orderBy('occurred_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /**
     * Events within a period — a **history** read, so chronological.
     *
     * Decided rather than deferred, as T320 required. It has no production caller, so nothing is
     * pinned either way, but it returns the same event stream the timeline does over a bounded window —
     * leaving it reverse-chronological would hand the next caller the identical defect, and a caller
     * cannot tell from the signature that one method is a timeline and the other is not.
     *
     * @return list<AuditEvent>
     */
    public function findByDateRange(DateTimeImmutable $from, DateTimeImmutable $to, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from($this->table())
            ->where($qb->expr()->gte('occurred_at', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
            ->andWhere($qb->expr()->lte('occurred_at', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))))
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->inTimelineOrder($qb);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(AuditEvent $event): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $event->dbId ? $this->findById($event->dbId) : $this->findByPublicId($event->id);
        
        if ($existing) {
            // Audit events are immutable - should not be updated
            return;
        } else {
            $qb->insert($this->table())
                ->values($this->rowValues($qb, $event))
                ->executeStatement();

            // Neither key came back. The insert writes `public_id` from `$event->id`, but the
            // surrogate key and the `publicId` mirror were both left null, so an audit event that had
            // just been appended could not be found again by either `findById()` or
            // `findByPublicId()` — and `AuditWriterService::append()` is the only way the application
            // records business history. Found by T236's real-database round trip.
            $event->dbId = (int) $qb->getLastInsertId();
            $event->publicId = $event->id;
        }
    }

    /** @return list<string> */
    public function findEntityIdsByEventType(string $eventType, int $offset = 0, int $limit = 1000): array
    {
        if ($this->subject() !== 'configuration') {
            // The transaction table has no entity_id column. Returning an empty list would read as
            // "nothing has ever been recorded", which is the opposite of what a mis-bound stream
            // means, so this refuses instead.
            throw new \LogicException(
                'entity ids exist only on the configuration audit stream; this repository is bound to '
                . $this->table() . '.'
            );
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->selectDistinct('entity_id')
            ->from($this->table())
            ->where($qb->expr()->eq('event_type', $qb->createNamedParameter($eventType)))
            ->andWhere($qb->expr()->isNotNull('entity_id'))
            ->orderBy('entity_id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return array_map(
            static fn ($v): string => (string) $v,
            $qb->executeQuery()->fetchFirstColumn()
        );
    }

    /**
     * Build the insert values for this stream.
     *
     * The subject columns are disjoint between the two tables, so each stream writes exactly
     * its own. Writing the transaction stream's columns into the configuration table is a
     * schema error, and writing the configuration stream's columns into the transaction table
     * would drop the sheet/transaction linkage the evidence trail depends on.
     */
    private function rowValues(IQueryBuilder $qb, AuditEvent $event): array
    {
        $shared = [
            'public_id' => $qb->createNamedParameter($event->id),
            'event_type' => $qb->createNamedParameter($event->eventType),
            'actor_uid' => $qb->createNamedParameter($event->actorUid),
            'actor_name' => $qb->createNamedParameter($event->actorName),
            'actor_role' => $qb->createNamedParameter($event->actorRole),
            'explanation' => $qb->createNamedParameter($event->explanation ?? ''),
            'before_json' => $qb->createNamedParameter($event->beforeJson),
            'after_json' => $qb->createNamedParameter($event->afterJson),
            'command_id' => $event->commandId ? $qb->createNamedParameter($event->commandId) : $qb->createNamedParameter(null),
            'occurred_at' => $qb->createNamedParameter($event->occurredAt->format('Y-m-d H:i:s')),
        ];

        if ($this->subject() === 'configuration') {
            return $shared + [
                'entity_type' => $qb->createNamedParameter($event->entityType),
                'entity_id' => $event->entityId ? $qb->createNamedParameter($event->entityId) : $qb->createNamedParameter(null),
            ];
        }

        return $shared + [
            'sheet_id' => $event->sheetId ? $qb->createNamedParameter($event->sheetId) : $qb->createNamedParameter(null),
            'transaction_id' => $event->transactionId ? $qb->createNamedParameter($event->transactionId) : $qb->createNamedParameter(null),
            'revision_id' => $event->revisionId ? $qb->createNamedParameter($event->revisionId) : $qb->createNamedParameter(null),
            'stage' => $qb->createNamedParameter($event->stage),
            'decision_id' => $event->decisionId ? $qb->createNamedParameter($event->decisionId) : $qb->createNamedParameter(null),
        ];
    }

    private function mapRow(array $row): AuditEvent
    {
        // The subject columns are absent, not null, on the stream that does not define them, so
        // every access is defaulted. Reading them unguarded both warns and invents values.
        //
        // BIGINT columns come back as int on this platform's driver and as string on others, so
        // the conversion accepts both rather than assuming one.
        $int = static fn (int|string|null $v): ?int => ($v === null || $v === '' ? null : (int) $v);

        return new AuditEvent(
            $row['public_id'],
            $int($row['sheet_id'] ?? null),
            $int($row['transaction_id'] ?? null),
            $int($row['revision_id'] ?? null),
            $row['stage'] ?? '',
            $int($row['decision_id'] ?? null),
            $row['entity_type'] ?? '',
            $row['entity_id'] ?? null,
            $row['event_type'],
            $row['actor_uid'],
            $row['actor_name'],
            $row['actor_role'],
            $row['explanation'] ?? null,
            $row['before_json'],
            $row['after_json'],
            $row['command_id'] ?? null,
            new DateTimeImmutable($row['occurred_at']),
            (int) $row['id'],
            $row['public_id']
        );
    }
}