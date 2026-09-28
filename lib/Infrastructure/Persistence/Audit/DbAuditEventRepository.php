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

    /** @return list<AuditEvent> */
    public function findByTransactionId(int $transactionId, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from($this->table())
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId)))
            ->orderBy('occurred_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<AuditEvent> */
    public function findBySheetId(int $sheetId, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from($this->table())
            ->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId)))
            ->orderBy('occurred_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<AuditEvent> */
    public function findByActorUid(string $actorUid, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from($this->table())
            ->where($qb->expr()->eq('actor_uid', $qb->createNamedParameter($actorUid)))
            ->orderBy('occurred_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<AuditEvent> */
    public function findByDateRange(DateTimeImmutable $from, DateTimeImmutable $to, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from($this->table())
            ->where($qb->expr()->gte('occurred_at', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
            ->andWhere($qb->expr()->lte('occurred_at', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))))
            ->orderBy('occurred_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
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