<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Expense;

use OCA\PettyCash\Application\DataIntegrity\UnreadableRecordData;

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
            
            $revision->dbId = (int) $qb->getLastInsertId();
            // The insert writes `public_id` from the aggregate's own `id`, but never assigned it
            // back to the `publicId` mirror, so an aggregate that had just been saved reported
            // `publicId === null` and every `findByPublicId()` on it could not resolve the row it
            // had written. `mapRow()` sets the mirror on the way back in; this sets it on the way
            // out. Found by T236's real-database round trip.
            $revision->publicId = $revision->id();
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
     * The expense type's public UUID for a stored `expense_type_id`.
     *
     * The mirror of `getCostCenterPublicId()`, and null for the same reason it returns null: a join key
     * with no matching row must not be reported as an identifier. The caller treats null as "this
     * revision names no resolvable expense type" rather than substituting the title, because the title
     * is exactly what `EntitySnapshot.id` must not carry.
     */
    private function getExpenseTypePublicId(int $dbId): ?string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('public_id')
            ->from('pettycash_expense_types')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($dbId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row === false ? null : (string) $row['public_id'];
    }

    /**
     * Whether the revision has at least one active evidence item.
     *
     * `data-model.md:248` states the rule: "Required evidence is validated against active evidence
     * related to the revision/**transaction**." The predicate below was scoped to the revision only —
     * evidence whose `origin_revision_id` equalled *this* revision's, or was null — so it answered
     * a strictly narrower question than the one the model documents, and the docblock above it
     * described the wider rule the code did not implement.
     *
     * The narrower scope is what made correction unusable. A returned transaction's evidence belongs
     * to the revision it was uploaded against, and `workflow.md`'s Correction Contract clause 6 keeps
     * that evidence history unchanged. So a correction draft — a *new* revision, per clause 3 —
     * inherited no evidence at all, and `ResubmitCorrection`'s `hasEvidence()` check refused every
     * resubmission with `422 "Transaction missing required evidence"` on a transaction that plainly
     * had evidence. Measured against the contract fixture: a complete, correctly-edited correction
     * could not be resubmitted, which is US4/AC2's whole request.
     *
     * Scoping to the transaction is what `data-model.md` already says, and it is the right unit here:
     * evidence is a property of the expense, not of one attempt at describing it. Deactivation still
     * counts correctly — `active` is required, so evidence removed after submission stops satisfying
     * it, which is what FR-058's "marked inactive rather than physically removed" relies on.
     */
    private function hasActiveEvidence(int $transactionDbId, int $revisionDbId): bool
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pettycash_evidence')
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionDbId)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->setMaxResults(1);

        return $qb->executeQuery()->fetchAssociative() !== false;
    }

    /**
     * Resolve the currency code that `amount_minor` is denominated in.
     *
     * `pettycash_transaction_revisions` stores an integer minor-unit amount with
     * no currency of its own, and FR-021 makes the sheet the authority for its
     * transactions' currency, so the read path has to walk revision -> transaction
     * -> sheet -> currency to learn it. Assuming a currency here is not a display
     * detail: because the fraction digits are themselves per-currency
     * configuration, the same integer denotes a different real amount in every
     * currency, and a wrong assumption corrupts every total, settlement, and
     * export derived from the revision.
     *
     * @throws LogicException when the owning sheet's currency cannot be resolved,
     *                        rather than substituting a default that would silently
     *                        report the wrong money
     */
    private function resolveCurrencyCode(int $revisionDbId, int $transactionDbId): string
    {
        if ($transactionDbId <= 0) {
            throw new LogicException(
                'Cannot read a transaction revision amount without its owning transaction id'
            );
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('c.code')
            ->from('pettycash_transaction_revisions', 'r')
            ->join('r', 'pettycash_transactions', 't', $qb->expr()->eq('r.transaction_id', 't.id'))
            ->join('t', 'pettycash_sheets', 's', $qb->expr()->eq('t.sheet_id', 's.id'))
            ->join('s', 'pettycash_currencies', 'c', $qb->expr()->eq('s.currency_id', 'c.id'))
            ->where($qb->expr()->eq('r.id', $qb->createNamedParameter($revisionDbId)))
            ->andWhere($qb->expr()->eq('r.transaction_id', $qb->createNamedParameter($transactionDbId)))
            ->setMaxResults(1);

        $row = $qb->executeQuery()->fetchAssociative();
        if ($row === false || !isset($row['code']) || $row['code'] === '') {
            // Typed rather than a bare LogicException, so a caller that can explain the failure to a
            // client -- a report -- can recognise it. The ids stay in the message because this one is
            // for the log, not for a response body.
            throw new UnreadableRecordData(
                'Cannot resolve the currency of transaction revision ' . $revisionDbId
                . ' from its sheet; the amount has no valid currency'
            );
        }

        return (string) $row['code'];
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
                \OCA\PettyCash\Domain\Shared\CurrencyCode::fromString(
                    $this->resolveCurrencyCode($revisionDbId, $transactionDbId)
                )
            ),
            $row['supersedes_revision_id'] ? $this->getRevisionPublicId((int) $row['supersedes_revision_id']) : null
        );
        $revision->costCenterDbId = $costCenterDbId;
        $revision->costCenterCode = $row['cost_center_code'] ?? null;
        $revision->costCenterName = $row['cost_center_name'] ?? null;
        $revision->expenseTypeDbId = $row['expense_type_id'] ? (int) $row['expense_type_id'] : null;
        // T330. Resolved here, beside `getCostCenterPublicId()` above, because that is where the cost
        // centre's public id is already resolved from its join key -- the two are the same fact about
        // two entities, and resolving only one of them is what left `Revision.expenseType.id` carrying a
        // title while `costCenter.id` carried a uuid.
        $revision->expenseTypeId = $revision->expenseTypeDbId !== null
            ? $this->getExpenseTypePublicId($revision->expenseTypeDbId)
            : null;
        $revision->managerUid = $row['manager_uid'] ?? null;
        $revision->seniorManagerUid = $row['senior_manager_uid'] ?? null;
        $revision->activatedAt = $activatedAt;
        $revision->createdAt = $row['created_at'] ? new DateTimeImmutable($row['created_at']) : null;
        $revision->createdBy = $row['created_by'] ?? null;
        $revision->transactionDbId = $row['transaction_id'] !== null ? $transactionDbId : null;
        $revision->dbId = $revisionDbId;
        $revision->publicId = $row['public_id'];

        $revision->hydrate(
            $row['description'] !== null && $row['description'] !== '' ? (string) $row['description'] : null,
            $costCenterDbId !== null ? $this->getCostCenterPublicId($costCenterDbId) : null,
            $row['expense_type_title'] !== null && $row['expense_type_title'] !== '' ? (string) $row['expense_type_title'] : null,
            $transactionDbId > 0 && $this->hasActiveEvidence($transactionDbId, $revisionDbId),
            $this->isCurrentRevision($transactionDbId, $revisionDbId)
        );

        return $revision;
    }

    /**
     * Whether this revision is the transaction's current one.
     *
     * **This is the projection `Revision.active` is read from, and it used to be `activated_at IS NOT
     * NULL`.** That answers a different question. `activated_at` records that a revision was *once*
     * submitted; the contract's `Revision.active` means *is current*, which is what
     * `TransactionDetailView.vue:82` relies on when it does `revisions.find(r => r.active)` and what
     * FR-050 rests on — "the active revision MUST determine the current transaction information".
     *
     * The two diverge the moment a correction happens. A correction adds a revision; the superseded
     * one keeps its `activated_at` forever, so deriving from it reported **two** active revisions
     * after every resubmission, with a client taking whichever the list returned first. Measured
     * against the contract fixture: revision 1 and revision 3 both read `active: true`.
     *
     * `pettycash_transactions.active_revision_id` is the only durable record of which revision is
     * current, and exactly one row points at exactly one revision — so reading it makes "exactly one
     * active" a property of the data rather than something the query has to infer.
     */
    private function isCurrentRevision(int $transactionDbId, int $revisionDbId): bool
    {
        if ($transactionDbId <= 0) {
            return false;
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('active_revision_id')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionDbId)))
            ->setMaxResults(1);

        $current = $qb->executeQuery()->fetchOne();

        return $current !== false && $current !== null && (int) $current === $revisionDbId;
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