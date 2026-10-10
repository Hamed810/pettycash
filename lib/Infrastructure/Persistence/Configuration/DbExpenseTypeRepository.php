<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Configuration;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbExpenseTypeRepository implements ExpenseTypeRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?ExpenseType
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_expense_types')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?ExpenseType
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_expense_types')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findActiveByTitle(string $title): ?ExpenseType
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_expense_types')
            ->where($qb->expr()->eq('title', $qb->createNamedParameter($title)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<ExpenseType> */
    public function findAllActive(): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_expense_types')
            ->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(ExpenseType $expenseType): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $expenseType->id ? $this->findById($expenseType->id) : $this->findActiveByTitle($expenseType->title);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        if ($existing) {
            $qb->update('pettycash_expense_types')
                ->set('description', $qb->createNamedParameter($expenseType->description))
                ->set('evidence_required', $qb->createNamedParameter($expenseType->evidenceRequired, IQueryBuilder::PARAM_BOOL))
                ->set('active', $qb->createNamedParameter($expenseType->active, IQueryBuilder::PARAM_BOOL))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE))
                ->set('updated_by', $qb->createNamedParameter($currentUser))
                ->set('lock_version', $qb->createNamedParameter($existing->lockVersion + 1))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->id)))
                ->andWhere($qb->expr()->eq('lock_version', $qb->createNamedParameter($existing->lockVersion)))
                ->executeStatement();
        } else {
            // `public_id` is NOT NULL with no default, and nothing upstream mints one: an expense type
            // is keyed by its title and the surrogate `id` is assigned by the database. This insert
            // did not supply the column at all, so every write failed on the constraint. No test
            // noticed, because all 13 repository doubles accept the aggregate as it stands. The
            // repository mints it here, which is the contract `mapRow()` already reads back.
            $publicId = $expenseType->publicId ?? (string) Uuid::random();

            $qb->insert('pettycash_expense_types')
                ->values([
                    'public_id' => $qb->createNamedParameter($publicId),
                    'title' => $qb->createNamedParameter($expenseType->title),
                    'description' => $qb->createNamedParameter($expenseType->description),
                    'evidence_required' => $qb->createNamedParameter($expenseType->evidenceRequired, IQueryBuilder::PARAM_BOOL),
                    'active' => $qb->createNamedParameter($expenseType->active, IQueryBuilder::PARAM_BOOL),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'created_by' => $qb->createNamedParameter($currentUser),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'updated_by' => $qb->createNamedParameter($currentUser),
                    'lock_version' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();

            $expenseType->publicId = $publicId;
            $expenseType->id = (int) $qb->getLastInsertId();
        }
    }

    private function mapRow(array $row): ExpenseType
    {
        $expenseType = ExpenseType::create(
            $row['title'],
            $row['description'] ?? null,
            (bool) $row['evidence_required']
        );
        $expenseType->active = (bool) $row['active'];
        $expenseType->createdAt = isset($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null;
        $expenseType->createdBy = $row['created_by'] ?? null;
        $expenseType->updatedAt = isset($row['updated_at']) ? new DateTimeImmutable($row['updated_at']) : null;
        $expenseType->updatedBy = $row['updated_by'] ?? null;
        $expenseType->lockVersion = (int) ($row['lock_version'] ?? 0);
        $expenseType->id = (int) $row['id'];
        $expenseType->publicId = $row['public_id'] ?? null;
        return $expenseType;
    }
}