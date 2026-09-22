<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Expense;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbTransactionRevisionRepository implements TransactionRevisionRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?TransactionRevision
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_transaction_revisions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?TransactionRevision
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_transaction_revisions')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<TransactionRevision> */
    public function findByTransactionId(int $transactionId): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_transaction_revisions')
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId)))
            ->orderBy('revision_number', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function findActiveByTransactionId(int $transactionId): ?TransactionRevision
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('r.*')
            ->from('pc_transaction_revisions', 'r')
            ->join('r', 'pc_transactions', 't', $qb->expr()->eq('t.active_revision_id', 'r.id'))
            ->where($qb->expr()->eq('t.id', $qb->createNamedParameter($transactionId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function save(TransactionRevision $revision): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $revision->dbId ? $this->findById($revision->dbId) : $this->findByPublicId($revision->id());
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        if ($existing) {
            $qb->update('pc_transaction_revisions')
                ->set('description', $qb->createNamedParameter($revision->description()))
                ->set('cost_center_id', $revision->costCenterDbId ? $qb->createNamedParameter($revision->costCenterDbId) : $qb->createNamedParameter(null))
                ->set('cost_center_code', $revision->costCenterCode ? $qb->createNamedParameter($revision->costCenterCode) : $qb->createNamedParameter(null))
                ->set('cost_center_name', $revision->costCenterName ? $qb->createNamedParameter($revision->costCenterName) : $qb->createNamedParameter(null))
                ->set('expense_type_id', $revision->expenseTypeDbId ? $qb->createNamedParameter($revision->expenseTypeDbId) : $qb->createNamedParameter(null))
                ->set('expense_type_title', $revision->expenseTypeTitle() ? $qb->createNamedParameter($revision->expenseTypeTitle()) : $qb->createNamedParameter(null))
                ->set('manager_uid', $revision->managerUid ? $qb->createNamedParameter($revision->managerUid) : $qb->createNamedParameter(null))
                ->set('senior_manager_uid', $revision->seniorManagerUid ? $qb->createNamedParameter($revision->seniorManagerUid) : $qb->createNamedParameter(null))
                ->set('activated_at', $revision->activatedAt ? $qb->createNamedParameter($revision->activatedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null))
                ->set('supersedes_revision_id', $revision->supersedes() ? $qb->createNamedParameter($this->getRevisionDbId($revision->supersedes())) : $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now))
                ->set('updated_by', $qb->createNamedParameter($currentUser))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->dbId)))
                ->executeStatement();
        } else {
            $insertId = $qb->insert('pc_transaction_revisions')
                ->values([
                    'public_id' => $qb->createNamedParameter($revision->id()),
                    'transaction_id' => $qb->createNamedParameter($this->getTransactionDbId($revision->id())), // This needs to be resolved
                    'revision_number' => $qb->createNamedParameter($revision->number()),
                    'expense_date' => $qb->createNamedParameter($revision->expenseDate->format('Y-m-d')),
                    'amount_minor' => $qb->createNamedParameter($revision->amount()->minorUnits()),
                    'description' => $qb->createNamedParameter($revision->description() ?? ''),
                    'cost_center_id' => $revision->costCenterDbId ? $qb->createNamedParameter($revision->costCenterDbId) : $qb->createNamedParameter(null),
                    'cost_center_code' => $revision->costCenterCode ? $qb->createNamedParameter($revision->costCenterCode) : $qb->createNamedParameter(null),
                    'cost_center_name' => $revision->costCenterName ? $qb->createNamedParameter($revision->costCenterName) : $qb->createNamedParameter(null),
                    'expense_type_id' => $revision->expenseTypeDbId ? $qb->createNamedParameter($revision->expenseTypeDbId) : $qb->createNamedParameter(null),
                    'expense_type_title' => $revision->expenseTypeTitle() ? $qb->createNamedParameter($revision->expenseTypeTitle()) : $qb->createNamedParameter(null),
                    'manager_uid' => $revision->managerUid ? $qb->createNamedParameter($revision->managerUid) : $qb->createNamedParameter(null),
                    'senior_manager_uid' => $revision->seniorManagerUid ? $qb->createNamedParameter($revision->seniorManagerUid) : $qb->createNamedParameter(null),
                    'created_at' => $qb->createNamedParameter($now),
                    'created_by' => $qb->createNamedParameter($currentUser),
                    'activated_at' => $revision->activatedAt ? $qb->createNamedParameter($revision->activatedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null),
                    'supersedes_revision_id' => $revision->supersedes() ? $qb->createNamedParameter($this->getRevisionDbId($revision->supersedes())) : $qb->createNamedParameter(null),
                ])
                ->executeStatement();
            
            $revision->dbId = (int) $insertId;
        }
    }

    private function getTransactionDbId(string $revisionId): int
    {
        // This is a simplification - in reality, we'd need to know the transaction ID
        // For now, return 0 as placeholder
        return 0;
    }

    private function getRevisionDbId(string $publicId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pc_transaction_revisions')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (int) $row['id'] : 0;
    }

    private function mapRow(array $row): TransactionRevision
    {
        $revision = new TransactionRevision(
            $row['public_id'],
            (int) $row['revision_number'],
            new DateTimeImmutable($row['expense_date']),
            \OCA\PettyCash\Domain\Shared\Money::of(
                (int) $row['amount_minor'],
                \OCA\PettyCash\Domain\Shared\CurrencyCode::fromString('IRR') // Placeholder - would need to get from sheet
            ),
            $row['supersedes_revision_id'] ? $this->getRevisionPublicId((int) $row['supersedes_revision_id']) : null
        );
        $revision->costCenterDbId = $row['cost_center_id'] ? (int) $row['cost_center_id'] : null;
        $revision->costCenterCode = $row['cost_center_code'] ?? null;
        $revision->costCenterName = $row['cost_center_name'] ?? null;
        $revision->expenseTypeDbId = $row['expense_type_id'] ? (int) $row['expense_type_id'] : null;
        $revision->managerUid = $row['manager_uid'] ?? null;
        $revision->seniorManagerUid = $row['senior_manager_uid'] ?? null;
        $revision->activatedAt = $row['activated_at'] ? new DateTimeImmutable($row['activated_at']) : null;
        $revision->createdAt = $row['created_at'] ? new DateTimeImmutable($row['created_at']) : null;
        $revision->createdBy = $row['created_by'] ?? null;
        $revision->dbId = (int) $row['id'];
        $revision->publicId = $row['public_id'];
        return $revision;
    }

    private function getRevisionPublicId(int $id): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('public_id')
            ->from('pc_transaction_revisions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $row['public_id'] : '';
    }
}