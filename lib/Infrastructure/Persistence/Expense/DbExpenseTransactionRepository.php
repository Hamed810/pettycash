<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Expense;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbExpenseTransactionRepository implements ExpenseTransactionRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?ExpenseTransaction
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_transactions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?ExpenseTransaction
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_transactions')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<ExpenseTransaction> */
    public function findBySheetId(int $sheetId): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_transactions')
            ->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId)))
            ->orderBy('created_at', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<ExpenseTransaction> */
    public function findByOwnerUid(string $ownerUid, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_transactions')
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
        if ($stage) {
            $qb->andWhere($qb->expr()->eq('current_stage', $qb->createNamedParameter($stage->value)));
        }
        if ($status) {
            $qb->andWhere($qb->expr()->eq('current_status', $qb->createNamedParameter($status->value)));
        }
        $qb->orderBy('created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<ExpenseTransaction> */
    public function findByCostCenterId(int $costCenterId, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('t.*')
            ->from('pc_transactions', 't')
            ->join('t', 'pc_transaction_revisions', 'r', $qb->expr()->eq('t.active_revision_id', 'r.id'))
            ->where($qb->expr()->eq('r.cost_center_id', $qb->createNamedParameter($costCenterId)));
        if ($stage) {
            $qb->andWhere($qb->expr()->eq('t.current_stage', $qb->createNamedParameter($stage->value)));
        }
        if ($status) {
            $qb->andWhere($qb->expr()->eq('t.current_status', $qb->createNamedParameter($status->value)));
        }
        $qb->orderBy('t.created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<ExpenseTransaction> */
    public function findByStageAndStatus(WorkflowStage $stage, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_transactions')
            ->where($qb->expr()->eq('current_stage', $qb->createNamedParameter($stage->value)));
        if ($status) {
            $qb->andWhere($qb->expr()->eq('current_status', $qb->createNamedParameter($status->value)));
        }
        $qb->orderBy('created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<ExpenseTransaction> */
    public function findForFinanceQueue(int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_transactions')
            ->where($qb->expr()->eq('current_stage', $qb->createNamedParameter(WorkflowStage::Finance->value)))
            ->andWhere($qb->expr()->eq('current_status', $qb->createNamedParameter(TransactionStatus::Pending->value)))
            ->orderBy('created_at', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(ExpenseTransaction $transaction): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $transaction->dbId ? $this->findById($transaction->dbId) : $this->findByPublicId($transaction->id);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        $revisionDbId = $transaction->revision()->dbId ?? null;

        if ($existing) {
            $qb->update('pc_transactions')
                ->set('sheet_id', $transaction->sheetId ? $qb->createNamedParameter($transaction->sheetId) : $qb->createNamedParameter(null))
                ->set('owner_uid', $qb->createNamedParameter($transaction->ownerUid))
                ->set('active_revision_id', $revisionDbId ? $qb->createNamedParameter($revisionDbId) : $qb->createNamedParameter(null))
                ->set('current_stage', $qb->createNamedParameter($transaction->stage->value))
                ->set('current_status', $qb->createNamedParameter($transaction->status->value))
                ->set('final_outcome', $transaction->finalOutcome ? $qb->createNamedParameter($transaction->finalOutcome) : $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now))
                ->set('lock_version', $qb->createNamedParameter($existing->lockVersion + 1))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->dbId)))
                ->andWhere($qb->expr()->eq('lock_version', $qb->createNamedParameter($existing->lockVersion)))
                ->executeStatement();
            $transaction->lockVersion = $existing->lockVersion + 1;
        } else {
            $insertId = $qb->insert('pc_transactions')
                ->values([
                    'public_id' => $qb->createNamedParameter($transaction->id),
                    'sheet_id' => $transaction->sheetId ? $qb->createNamedParameter($transaction->sheetId) : $qb->createNamedParameter(null),
                    'owner_uid' => $qb->createNamedParameter($transaction->ownerUid),
                    'active_revision_id' => $revisionDbId ? $qb->createNamedParameter($revisionDbId) : $qb->createNamedParameter(null),
                    'current_stage' => $qb->createNamedParameter($transaction->stage->value),
                    'current_status' => $qb->createNamedParameter($transaction->status->value),
                    'final_outcome' => $transaction->finalOutcome ? $qb->createNamedParameter($transaction->finalOutcome) : $qb->createNamedParameter(null),
                    'created_at' => $qb->createNamedParameter($now),
                    'updated_at' => $qb->createNamedParameter($now),
                    'lock_version' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();
            
            $transaction->dbId = (int) $insertId;
            $transaction->lockVersion = 0;
        }
    }

    private function mapRow(array $row): ExpenseTransaction
    {
        $transaction = new ExpenseTransaction(
            $row['public_id'],
            $row['owner_uid'],
            new \OCA\PettyCash\Domain\Expense\TransactionRevision(
                '', 0, new DateTimeImmutable(), \OCA\PettyCash\Domain\Shared\Money::of(0, \OCA\PettyCash\Domain\Shared\CurrencyCode::fromString('IRR'))
            )
        );
        $transaction->sheetId = $row['sheet_id'] ? (int) $row['sheet_id'] : null;
        $transaction->stage = WorkflowStage::from($row['current_stage']);
        $transaction->status = TransactionStatus::from($row['current_status']);
        $transaction->finalOutcome = $row['final_outcome'] ?? null;
        $transaction->createdAt = $row['created_at'] ? new DateTimeImmutable($row['created_at']) : null;
        $transaction->updatedAt = $row['updated_at'] ? new DateTimeImmutable($row['updated_at']) : null;
        $transaction->lockVersion = (int) ($row['lock_version'] ?? 0);
        $transaction->dbId = (int) $row['id'];
        $transaction->publicId = $row['public_id'];
        return $transaction;
    }
}