<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbEvidenceRepository implements EvidenceRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?Evidence
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_evidence')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?Evidence
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_evidence')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<Evidence> */
    public function findByTransactionId(int $transactionId, bool $activeOnly = false): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_evidence')
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId)));
        if ($activeOnly) {
            $qb->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
        $qb->orderBy('uploaded_at', 'DESC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<Evidence> */
    public function findByRevisionId(int $revisionId): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_evidence')
            ->where($qb->expr()->eq('origin_revision_id', $qb->createNamedParameter($revisionId)))
            ->orderBy('uploaded_at', 'DESC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(Evidence $evidence): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $evidence->dbId ? $this->findById($evidence->dbId) : $this->findByPublicId($evidence->id);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        if ($existing) {
            $qb->update('pc_evidence')
                ->set('active', $qb->createNamedParameter($evidence->active, IQueryBuilder::PARAM_BOOL))
                ->set('deactivated_by', $evidence->deactivatedBy ? $qb->createNamedParameter($evidence->deactivatedBy) : $qb->createNamedParameter(null))
                ->set('deactivated_at', $evidence->deactivatedAt ? $qb->createNamedParameter($evidence->deactivatedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null))
                ->set('deactivation_reason', $evidence->deactivationReason ? $qb->createNamedParameter($evidence->deactivationReason) : $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now))
                ->set('updated_by', $qb->createNamedParameter($currentUser))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->dbId)))
                ->executeStatement();
        } else {
            $insertId = $qb->insert('pc_evidence')
                ->values([
                    'public_id' => $qb->createNamedParameter($evidence->id),
                    'transaction_id' => $qb->createNamedParameter($evidence->transactionId),
                    'origin_revision_id' => $qb->createNamedParameter($evidence->originRevisionId),
                    'appdata_key' => $qb->createNamedParameter($evidence->appDataKey),
                    'original_name' => $qb->createNamedParameter($evidence->originalName),
                    'media_type' => $qb->createNamedParameter($evidence->mediaType),
                    'size_bytes' => $qb->createNamedParameter($evidence->sizeBytes),
                    'checksum' => $qb->createNamedParameter($evidence->checksum),
                    'active' => $qb->createNamedParameter($evidence->active, IQueryBuilder::PARAM_BOOL),
                    'uploaded_by' => $qb->createNamedParameter($evidence->uploadedBy ?? $currentUser),
                    'uploaded_at' => $evidence->uploadedAt ? $qb->createNamedParameter($evidence->uploadedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter($now),
                    'deactivated_by' => $qb->createNamedParameter(null),
                    'deactivated_at' => $qb->createNamedParameter(null),
                    'deactivation_reason' => $qb->createNamedParameter(null),
                ])
                ->executeStatement();
            
            $evidence->dbId = (int) $insertId;
        }
    }

    private function mapRow(array $row): Evidence
    {
        return new Evidence(
            $row['public_id'],
            (int) $row['transaction_id'],
            (int) $row['origin_revision_id'],
            $row['appdata_key'],
            $row['original_name'],
            $row['media_type'],
            (int) $row['size_bytes'],
            $row['checksum'],
            (bool) $row['active'],
            $row['uploaded_by'] ?? null,
            $row['uploaded_at'] ? new DateTimeImmutable($row['uploaded_at']) : null,
            $row['deactivated_by'] ?? null,
            $row['deactivated_at'] ? new DateTimeImmutable($row['deactivated_at']) : null,
            $row['deactivation_reason'] ?? null,
            (int) $row['id'],
            $row['public_id']
        );
    }
}