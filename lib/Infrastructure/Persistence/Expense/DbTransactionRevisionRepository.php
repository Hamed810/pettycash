<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Expense;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
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
            ->from('pettycash_transaction_revisions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?TransactionRevision
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transaction_revisions')
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
            ->from('pettycash_transaction_revisions')
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId)))
            ->orderBy('revision_number', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function findActiveByTransactionId(int $transactionId): ?TransactionRevision
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('r.*')
            ->from('pettycash_transaction_revisions', 'r')
            ->join('r', 'pettycash_transactions', 't', $qb->expr()->eq('t.active_revision_id', 'r.id'))
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
            $qb->update('pettycash_transaction_revisions')
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
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->dbId)))
                ->executeStatement();
        } else {
            $qb->insert('pettycash_transaction_revisions')
                ->values([
                    'public_id' => $qb->createNamedParameter($revision->id()),
                    'transaction_id' => $qb->createNamedParameter($this->requireTransactionDbId($revision)),
                    'revision_number' => $qb->createNamedParameter($revision->number()),
                    'expense_date' => $qb->createNamedParameter($revision->expenseDate()->format('Y-m-d')),
                    'amount_minor' => $qb->createNamedParameter($revision->amount()->minorUnits()),
                    'description' => $qb->createNamedParameter($revision->description() ?? ''),
                    'cost_center_id' => $revision->costCenterDbId ? $qb->createNamedParameter($revision->costCenterDbId) : $qb->createNamedParameter(null),
                    'cost_center_code' => $revision->costCenterCode ? $qb->createNamedParameter($revision->costCenterCode) : $qb->createNamedParameter(null),
                    'cost_center_name' => $revision->costCenterName ? $qb->createNamedParameter($revision->costCenterName) : $qb->createNamedParameter(null),
                    'expense_type_id' => $revision->expenseTypeDbId ? $qb->createNamedParameter($revision->expenseTypeDbId) : $qb->createNamedParameter(null),
                    'expense_type_title' => $revision->expenseTypeTitle() ? $qb->createNamedParameter($revision->expenseTypeTitle()) : $qb->createNamedParameter(null),
                    'manager_uid' => $revision->managerUid ? $qb->createNamedParameter($revision->managerUid) : $qb->createNamedParameter(null),
                    'senior_manager_uid' => $revision->seniorManagerUid ? $qb->createNamedParameter($revision->seniorManagerUid) : $qb->createNamedParameter(null),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'created_by' => $qb->createNamedParameter($currentUser),
                    'activated_at' => $revision->activatedAt ? $qb->createNamedParameter($revision->activatedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null),
                    'supersedes_revision_id' => $revision->supersedes() ? $qb->createNamedParameter($this->getRevisionDbId($revision->supersedes())) : $qb->createNamedParameter(null),
                ])
                ->executeStatement();
            
            $revision->dbId = $qb->getLastInsertId();
        }
    }

    /**
     * `pettycash_transaction_revisions.transaction_id` is a NOT NULL join key onto
     * `pettycash_transactions.id`, so a revision can never be persisted without its
     * owning transaction. Silently writing a placeholder would orphan the row.
     */
    private function requireTransactionDbId(TransactionRevision $revision): int
    {
        if ($revision->transactionDbId === null) {
            throw new LogicException(
                'Cannot persist a transaction revision without its owning transaction id'
            );
        }

        return $revision->transactionDbId;
    }

    private function getRevisionDbId(string $publicId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pettycash_transaction_revisions')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Resolve the cost center's public UUID from its numeric join key.
     *
     * `complete()` stores the public UUID in `TransactionRevision::$costCenterId`
     * while `save()` writes only the numeric `cost_center_id`, so the read path has
     * to translate the key back or the authorization scope built from that field is
     * empty.
     */
    private function getCostCenterPublicId(int $dbId): ?string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('public_id')
            ->from('pettycash_cost_centers')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($dbId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row === false ? null : (string) $row['public_id'];
    }

    /**
     * Whether the revision has at least one active evidence item.
     *
     * `data-model.md` keeps evidence requirement a validation over "active evidence
     * related to the revision/transaction" rather than a column on the revision, so
     * the flag is derived from `pettycash_evidence` on every read.
     */
    private function hasActiveEvidence(int $transactionDbId, int $revisionDbId): bool
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pettycash_evidence')
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionDbId)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('origin_revision_id', $qb->createNamedParameter($revisionDbId)),
                $qb->expr()->isNull('origin_revision_id')
            ))
            ->setMaxResults(1);

        return $qb->executeQuery()->fetchAssociative() !== false;
    }

    private function mapRow(array $row): TransactionRevision
    {
        $revisionDbId = (int) $row['id'];
        $transactionDbId = $row['transaction_id'] !== null ? (int) $row['transaction_id'] : 0;
        $costCenterDbId = $row['cost_center_id'] ? (int) $row['cost_center_id'] : null;
        $activatedAt = $row['activated_at'] ? new DateTimeImmutable($row['activated_at']) : null;

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
        $revision->costCenterDbId = $costCenterDbId;
        $revision->costCenterCode = $row['cost_center_code'] ?? null;
        $revision->costCenterName = $row['cost_center_name'] ?? null;
        $revision->expenseTypeDbId = $row['expense_type_id'] ? (int) $row['expense_type_id'] : null;
        $revision->managerUid = $row['manager_uid'] ?? null;
        $revision->seniorManagerUid = $row['senior_manager_uid'] ?? null;
        $revision->activatedAt = $activatedAt;
        $revision->createdAt = $row['created_at'] ? new DateTimeImmutable($row['created_at']) : null;
        $revision->createdBy = $row['created_by'] ?? null;
        $revision->transactionDbId = $row['transaction_id'] !== null ? $transactionDbId : null;
        $revision->dbId = $revisionDbId;
        $revision->publicId = $row['public_id'];

        // A submitted revision is one that has been activated; the domain keeps no
        // separate flag in `pettycash_transaction_revisions`.
        $revision->hydrate(
            $row['description'] !== null && $row['description'] !== '' ? (string) $row['description'] : null,
            $costCenterDbId !== null ? $this->getCostCenterPublicId($costCenterDbId) : null,
            $row['expense_type_title'] !== null && $row['expense_type_title'] !== '' ? (string) $row['expense_type_title'] : null,
            $transactionDbId > 0 && $this->hasActiveEvidence($transactionDbId, $revisionDbId),
            $activatedAt !== null
        );

        return $revision;
    }

    private function getRevisionPublicId(int $id): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('public_id')
            ->from('pettycash_transaction_revisions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $row['public_id'] : '';
    }
}