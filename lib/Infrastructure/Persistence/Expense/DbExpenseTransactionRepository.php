<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Expense;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
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
        private readonly CurrentUser $currentUser,
        private readonly DbTransactionRevisionRepository $revisionRepository
    ) {}

    public function findById(int $id): ?ExpenseTransaction
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?ExpenseTransaction
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transactions')
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
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId)))
            ->orderBy('created_at', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function countBySheetId(int $sheetId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'transaction_count'))
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId)));

        $row = $qb->executeQuery()->fetchAssociative();
        return $row === false ? 0 : (int) ($row['transaction_count'] ?? 0);
    }

    /** @return list<ExpenseTransaction> */
    public function findByOwnerUid(string $ownerUid, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transactions')
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
            ->from('pettycash_transactions', 't')
            ->join('t', 'pettycash_transaction_revisions', 'r', $qb->expr()->eq('t.active_revision_id', 'r.id'))
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
            ->from('pettycash_transactions')
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
        // Both senior outcomes, not just one.
        //
        // `contracts/workflow.md` gives finance two rows — "Finance after senior approval"
        // (approve, reject, return) and "Finance after senior rejection" (reject, return;
        // approval forbidden) — and FR-037 routes *approved or rejected* transactions to
        // finance. Selecting only `AWAITING_FINANCE_REVIEW` therefore hid senior-rejected
        // transactions from the queue, so FR-039's "finance may reject it or return it" had
        // nothing to act on: the only finance-reachable state was one no code path produces,
        // because a senior approval records `SENIOR_APPROVED`.
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('current_stage', $qb->createNamedParameter(WorkflowStage::Finance->value)))
            ->andWhere($qb->expr()->in(
                'current_status',
                $qb->createNamedParameter([
                    TransactionStatus::SeniorApproved->value,
                    TransactionStatus::SeniorRejected->value,
                ], IQueryBuilder::PARAM_STR_ARRAY)
            ))
            ->orderBy('created_at', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /**
     * Whether a row already exists for this aggregate, and its current lock version.
     *
     * This deliberately does not hydrate the aggregate: during creation the
     * transaction row is written first with a null `active_revision_id` and is only
     * linked to its revision by the follow-up save, so loading the full aggregate
     * would demand an active revision that does not exist yet. The update branch
     * needs nothing but the lock version.
     *
     * @return int|null current lock version, or null when no row exists
     */
    private function findLockVersion(ExpenseTransaction $transaction): ?int
    {
        $qb = $this->connection->getQueryBuilder();
        if ($transaction->dbId !== null) {
            $qb->select('lock_version')
                ->from('pettycash_transactions')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($transaction->dbId)))
                ->setMaxResults(1);
        } else {
            $qb->select('lock_version')
                ->from('pettycash_transactions')
                ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($transaction->id)))
                ->setMaxResults(1);
        }

        $row = $qb->executeQuery()->fetchAssociative();

        return $row === false ? null : (int) $row['lock_version'];
    }

    public function save(ExpenseTransaction $transaction): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existingLockVersion = $this->findLockVersion($transaction);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        $revisionDbId = $transaction->revision()->dbId ?? null;

        if ($existingLockVersion !== null) {
            $qb->update('pettycash_transactions')
                ->set('sheet_id', $transaction->sheetId ? $qb->createNamedParameter($transaction->sheetId) : $qb->createNamedParameter(null))
                ->set('owner_uid', $qb->createNamedParameter($transaction->ownerUid))
                ->set('active_revision_id', $revisionDbId ? $qb->createNamedParameter($revisionDbId) : $qb->createNamedParameter(null))
                ->set('current_stage', $qb->createNamedParameter($transaction->stage->value))
                ->set('current_status', $qb->createNamedParameter($transaction->status->value))
                ->set('final_outcome', $transaction->finalOutcome ? $qb->createNamedParameter($transaction->finalOutcome) : $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE))
                ->set('lock_version', $qb->createNamedParameter($existingLockVersion + 1))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($transaction->dbId)))
                ->andWhere($qb->expr()->eq('lock_version', $qb->createNamedParameter($existingLockVersion)))
                ->executeStatement();
            $transaction->lockVersion = $existingLockVersion + 1;
        } else {
            $qb->insert('pettycash_transactions')
                ->values([
                    'public_id' => $qb->createNamedParameter($transaction->id),
                    'sheet_id' => $transaction->sheetId ? $qb->createNamedParameter($transaction->sheetId) : $qb->createNamedParameter(null),
                    'owner_uid' => $qb->createNamedParameter($transaction->ownerUid),
                    'active_revision_id' => $revisionDbId ? $qb->createNamedParameter($revisionDbId) : $qb->createNamedParameter(null),
                    'current_stage' => $qb->createNamedParameter($transaction->stage->value),
                    'current_status' => $qb->createNamedParameter($transaction->status->value),
                    'final_outcome' => $transaction->finalOutcome ? $qb->createNamedParameter($transaction->finalOutcome) : $qb->createNamedParameter(null),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'lock_version' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();
            
            $transaction->dbId = $qb->getLastInsertId();
            $transaction->lockVersion = 0;
        }
    }

    /**
     * Delete a never-submitted transaction and the revisions that belong to it.
     *
     * The revision rows go first because `pettycash_transaction_revisions.transaction_id`
     * points at the transaction being removed, and leaving them behind would strand
     * revisions no query would ever reach.
     */
    public function delete(ExpenseTransaction $transaction): void
    {
        $transactionDbId = $transaction->dbId;
        if ($transactionDbId === null) {
            throw new RuntimeException(
                'Cannot delete a transaction that was never persisted'
            );
        }

        $qb = $this->connection->getQueryBuilder();

        $qb->delete('pettycash_transaction_revisions')
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionDbId)))
            ->executeStatement();

        $qb->delete('pettycash_evidence')
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionDbId)))
            ->executeStatement();

        $qb->delete('pettycash_transactions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionDbId)))
            ->executeStatement();
    }

    private function mapRow(array $row): ExpenseTransaction
    {
        $transactionDbId = (int) $row['id'];

        // The active revision carries the amount, currency and expense data, and
        // its domain invariant rejects revision numbers below one — so a
        // placeholder revision cannot be substituted here. The real one has to be
        // loaded or the transaction cannot be represented at all.
        $activeRevision = $this->revisionRepository->findActiveByTransactionId($transactionDbId);
        if ($activeRevision === null) {
            throw new RuntimeException(
                'Transaction ' . $row['public_id'] . ' has no active revision'
            );
        }

        $transaction = new ExpenseTransaction(
            $row['public_id'],
            $row['owner_uid'],
            $activeRevision
        );
        $transaction->sheetId = $row['sheet_id'] ? (int) $row['sheet_id'] : null;
        $transaction->stage = WorkflowStage::from($row['current_stage']);
        $transaction->status = TransactionStatus::from($row['current_status']);
        $transaction->finalOutcome = $row['final_outcome'] ?? null;
        $transaction->createdAt = $row['created_at'] ? new DateTimeImmutable($row['created_at']) : null;
        $transaction->updatedAt = $row['updated_at'] ? new DateTimeImmutable($row['updated_at']) : null;
        $transaction->lockVersion = (int) ($row['lock_version'] ?? 0);
        $transaction->dbId = $transactionDbId;
        $transaction->publicId = $row['public_id'];
        return $transaction;
    }
}