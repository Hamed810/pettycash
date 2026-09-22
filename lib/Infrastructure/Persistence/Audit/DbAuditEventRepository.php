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
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?AuditEvent
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_transaction_audit')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?AuditEvent
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_transaction_audit')
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
            ->from('pc_transaction_audit')
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
            ->from('pc_transaction_audit')
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
            ->from('pc_transaction_audit')
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
            ->from('pc_transaction_audit')
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
            $qb->insert('pc_transaction_audit')
                ->values([
                    'public_id' => $qb->createNamedParameter($event->id),
                    'sheet_id' => $event->sheetId ? $qb->createNamedParameter($event->sheetId) : $qb->createNamedParameter(null),
                    'transaction_id' => $event->transactionId ? $qb->createNamedParameter($event->transactionId) : $qb->createNamedParameter(null),
                    'revision_id' => $event->revisionId ? $qb->createNamedParameter($event->revisionId) : $qb->createNamedParameter(null),
                    'stage' => $qb->createNamedParameter($event->stage),
                    'decision_id' => $event->decisionId ? $qb->createNamedParameter($event->decisionId) : $qb->createNamedParameter(null),
                    'event_type' => $qb->createNamedParameter($event->eventType),
                    'actor_uid' => $qb->createNamedParameter($event->actorUid),
                    'actor_name' => $qb->createNamedParameter($event->actorName),
                    'actor_role' => $qb->createNamedParameter($event->actorRole),
                    'explanation' => $qb->createNamedParameter($event->explanation ?? ''),
                    'before_json' => $qb->createNamedParameter($event->beforeJson),
                    'after_json' => $qb->createNamedParameter($event->afterJson),
                    'command_id' => $event->commandId ? $qb->createNamedParameter($event->commandId) : $qb->createNamedParameter(null),
                    'occurred_at' => $qb->createNamedParameter($event->occurredAt->format('Y-m-d H:i:s')),
                ])
                ->executeStatement();
        }
    }

    private function mapRow(array $row): AuditEvent
    {
        return new AuditEvent(
            $row['public_id'],
            $row['sheet_id'] ? (int) $row['sheet_id'] : null,
            $row['transaction_id'] ? (int) $row['transaction_id'] : null,
            $row['revision_id'] ? (int) $row['revision_id'] : null,
            $row['stage'],
            $row['decision_id'] ? (int) $row['decision_id'] : null,
            '', // entityType - for transaction audit it's empty
            null, // entityId - for transaction audit it's null
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