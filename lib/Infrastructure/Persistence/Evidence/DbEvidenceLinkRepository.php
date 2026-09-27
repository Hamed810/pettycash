<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Evidence\EvidenceLink;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceLinkRepository;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbEvidenceLinkRepository implements EvidenceLinkRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?EvidenceLink
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_evidence_links')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?EvidenceLink
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_evidence_links')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByTokenHash(string $tokenHash): ?EvidenceLink
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_evidence_links')
            ->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($tokenHash)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<EvidenceLink> */
    public function findByEvidenceId(int $evidenceId): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_evidence_links')
            ->where($qb->expr()->eq('evidence_id', $qb->createNamedParameter($evidenceId)))
            ->orderBy('created_at', 'DESC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(EvidenceLink $link): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $link->dbId ? $this->findById($link->dbId) : $this->findByPublicId($link->id);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        if ($existing) {
            $qb->update('pettycash_evidence_links')
                ->set('active', $qb->createNamedParameter($link->active, IQueryBuilder::PARAM_BOOL))
                ->set('deactivated_by', $link->deactivatedBy ? $qb->createNamedParameter($link->deactivatedBy) : $qb->createNamedParameter(null))
                ->set('deactivated_at', $link->deactivatedAt ? $qb->createNamedParameter($link->deactivatedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE))
                ->set('updated_by', $qb->createNamedParameter($currentUser))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->dbId)))
                ->executeStatement();
        } else {
            $qb->insert('pettycash_evidence_links')
                ->values([
                    'public_id' => $qb->createNamedParameter($link->id),
                    'evidence_id' => $qb->createNamedParameter($link->evidenceId),
                    'token_hash' => $qb->createNamedParameter($link->tokenHash),
                    'active' => $qb->createNamedParameter($link->active, IQueryBuilder::PARAM_BOOL),
                    'expires_at' => $link->expiresAt ? $qb->createNamedParameter($link->expiresAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null),
                    'created_by' => $qb->createNamedParameter($link->createdBy ?? $currentUser),
                    'created_at' => $link->createdAt ? $qb->createNamedParameter($link->createdAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'deactivated_by' => $qb->createNamedParameter(null),
                    'deactivated_at' => $qb->createNamedParameter(null),
                ])
                ->executeStatement();
            
            $link->dbId = (int) $qb->getLastInsertId();
            // The insert writes `public_id` from the aggregate's own `id`, but never assigned it
            // back to the `publicId` mirror, so an aggregate that had just been saved reported
            // `publicId === null` and every `findByPublicId()` on it could not resolve the row it
            // had written. `mapRow()` sets the mirror on the way back in; this sets it on the way
            // out. Found by T236's real-database round trip.
            $link->publicId = $link->id;
        }
    }

    private function mapRow(array $row): EvidenceLink
    {
        return new EvidenceLink(
            $row['public_id'],
            (int) $row['evidence_id'],
            $row['token_hash'],
            (bool) $row['active'],
            $row['expires_at'] ? new DateTimeImmutable($row['expires_at']) : null,
            $row['created_by'] ?? null,
            $row['created_at'] ? new DateTimeImmutable($row['created_at']) : null,
            $row['deactivated_by'] ?? null,
            $row['deactivated_at'] ? new DateTimeImmutable($row['deactivated_at']) : null,
            (int) $row['id'],
            $row['public_id']
        );
    }
}