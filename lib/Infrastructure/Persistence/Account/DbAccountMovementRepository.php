<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Account;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbAccountMovementRepository implements AccountMovementRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    /**
     * The movement projection used by every read path, including the owning account's currency.
     *
     * A movement has **no `currency_code` column**. `data-model.md` defines the entity without one
     * and draws `Currency 1 ── * Petty-Cash Account 1 ── * Account Movement`: the currency belongs
     * to the account, and the ledger reaches it through that relationship. The previous
     * implementation read and wrote a `currency_code` column on the movement table, which the
     * migration never creates — so every read raised `Undefined array key "currency_code"` and every
     * write failed on an unknown column. The repository could not move a single row in either
     * direction, and the only reason it was not noticed is that nothing had yet tried.
     *
     * Denormalising the code onto the row would also be the wrong fix: it is a second source of
     * truth for a value the account already owns, free to disagree with it, and the ledger is the
     * one record a balance has to be reconstructible from.
     *
     * `selectAlias()` rather than an inline `c.code as currency_code`, because `IQueryBuilder`
     * splits a select string containing ` as ` and keeps only the first segment.
     */
    private function applyProjection(IQueryBuilder $qb): IQueryBuilder
    {
        return $qb->select('m.*')
            ->selectAlias('c.code', 'currency_code')
            ->selectAlias('a.public_id', 'account_public_id')
            ->selectAlias('r.public_id', 'source_revision_public_id')
            ->selectAlias('d.public_id', 'source_decision_public_id');
    }

    /**
     * Joins the movement table to its account and that account's currency.
     *
     * Inner joins for those two: a movement whose account or currency row is missing is a corrupted
     * ledger, and returning it with a guessed currency would put a wrong figure in front of a finance
     * officer. It has to fail instead.
     *
     * The revision join is a **left** join, and that is not a style choice. `source_revision_id` is
     * nullable and every charge leaves it null, so an inner join would silently drop every charge
     * from the ledger — the rows most likely to be read, being the ones an administrator funded the
     * account with.
     */
    private function applyJoins(IQueryBuilder $qb): IQueryBuilder
    {
        return $qb->from('pettycash_account_movements', 'm')
            ->join('m', 'pettycash_accounts', 'a', $qb->expr()->eq('m.account_id', 'a.id'))
            ->join('a', 'pettycash_currencies', 'c', $qb->expr()->eq('a.currency_id', 'c.id'))
            ->leftJoin('m', 'pettycash_transaction_revisions', 'r', $qb->expr()->eq('m.source_revision_id', 'r.id'))
            ->leftJoin('m', 'pettycash_workflow_decisions', 'd', $qb->expr()->eq('m.source_decision_id', 'd.id'));
    }

    public function findById(int $id): ?AccountMovement
    {
        $qb = $this->connection->getQueryBuilder();
        $this->applyProjection($qb);
        $this->applyJoins($qb);
        $qb->where($qb->expr()->eq('m.id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByCommandId(string $commandId): ?AccountMovement
    {
        $qb = $this->connection->getQueryBuilder();
        $this->applyProjection($qb);
        $this->applyJoins($qb);
        $qb->where($qb->expr()->eq('m.command_id', $qb->createNamedParameter($commandId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findBySourceRevisionId(int|string $sourceRevisionId): ?AccountMovement
    {
        $qb = $this->connection->getQueryBuilder();
        $this->applyProjection($qb);
        $this->applyJoins($qb);
        $qb->where($qb->expr()->eq(
            'm.source_revision_id',
            $qb->createNamedParameter($this->resolveRevisionDbId($sourceRevisionId), \OCP\DB\Types::BIGINT)
        ))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<AccountMovement> */
    public function findByAccountId(int $accountId, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        $qb = $this->connection->getQueryBuilder();
        $this->applyProjection($qb);
        $this->applyJoins($qb);
        $qb->where($qb->expr()->eq('m.account_id', $qb->createNamedParameter($accountId)));
        if ($from) {
            $qb->andWhere($qb->expr()->gte('m.occurred_at', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }
        if ($to) {
            $qb->andWhere($qb->expr()->lte('m.occurred_at', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }
        $qb->orderBy('m.occurred_at', 'DESC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<AccountMovement> */
    public function findByAccountIdPaged(int $accountId, int $offset, int $limit): array
    {
        $qb = $this->connection->getQueryBuilder();
        $this->applyProjection($qb);
        $this->applyJoins($qb);
        $qb->where($qb->expr()->eq('m.account_id', $qb->createNamedParameter($accountId)))
            ->orderBy('m.occurred_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(AccountMovement $movement): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $this->findByCommandId($movement->commandId);
        if ($existing) {
            return; // Idempotent - already saved
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        // A movement is a row in one account's ledger, so the owning account must
        // be resolved from the aggregate. The surrogate key is looked up from the
        // account's public UUID; guessing an id would attach the movement to an
        // arbitrary account and corrupt that account's balance history.
        $accountId = $this->resolveAccountDbId($movement);

        $qb->insert('pettycash_account_movements')
            ->values([
                'public_id' => $qb->createNamedParameter($movement->id),
                'account_id' => $qb->createNamedParameter($accountId),
                'movement_type' => $qb->createNamedParameter($movement->type->value),
                'amount_minor' => $qb->createNamedParameter($movement->amount->minorUnits()),
                'balance_after_minor' => $qb->createNamedParameter($movement->balanceAfter->minorUnits()),
                // No `currency_code`: the currency is the account's, reached through `account_id`.
                'description' => $qb->createNamedParameter($movement->description ?? ''),
                // Both source columns are **required for consumption** by `data-model.md`, and
                // the aggregate carries both as public UUIDs while the table stores surrogate
                // keys. The previous code cast the UUID straight to `(int)`, which is `0` for
                // every UUID — so a consumption would have been written against revision `0`,
                // colliding with every other consumption in the table on the
                // `pettycash_account_movements_consumption_uniq` index, and the decision
                // reference was hard-coded to null.
                'source_revision_id' => $movement->sourceRevisionId === null
                    ? $qb->createNamedParameter(null)
                    : $qb->createNamedParameter($this->resolveRevisionDbId($movement->sourceRevisionId), IQueryBuilder::PARAM_INT),
                'source_decision_id' => $movement->sourceDecisionId === null
                    ? $qb->createNamedParameter(null)
                    : $qb->createNamedParameter($this->resolveDecisionDbId($movement->sourceDecisionId), IQueryBuilder::PARAM_INT),
                'command_id' => $qb->createNamedParameter($movement->commandId),
                'actor_uid' => $qb->createNamedParameter($currentUser),
                'actor_name' => $qb->createNamedParameter($this->userDirectory->displayName($currentUser)),
                'actor_role' => $qb->createNamedParameter('FINANCE'),
                'occurred_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
            ])
            ->executeStatement();

            // Deliberately no `$movement->dbId = …` here, unlike the other repositories.
            //
            // `AccountMovement` is a `final readonly` aggregate, so its database identity cannot be
            // assigned after construction — and that is correct rather than an omission: a movement
            // is a fact, not a mutable record, and the caller gets a *new* instance carrying the
            // surrogate key back from `mapRow()` on the next read. Finding a just-written movement
            // by `findByCommandId()` is the same path a replayed command takes, so it is the one
            // that has to work, and it does.
    }

    /**
     * Resolve a revision reference to its surrogate key.
     *
     * The aggregate and the public contract both use the revision's public UUID, while the
     * column is a foreign key, so the translation has to happen here. A UUID is not numeric,
     * so casting it yields `0` — which is a valid key pointing at some *other* revision, and
     * on the unique consumption index it collides with every other consumption. That is a
     * silent corruption rather than a failure, so an unresolvable reference aborts instead.
     */
    private function resolveRevisionDbId(int|string $sourceRevisionId): int
    {
        return $this->resolvePublicId('pettycash_transaction_revisions', $sourceRevisionId, 'revision');
    }

    /** As above, for the finance decision that authorised the consumption. */
    private function resolveDecisionDbId(int|string $sourceDecisionId): int
    {
        return $this->resolvePublicId('pettycash_workflow_decisions', $sourceDecisionId, 'decision');
    }

    private function resolvePublicId(string $table, int|string $publicId, string $label): int
    {
        if (is_int($publicId)) {
            return $publicId;
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from($table)
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if ($row === false || !isset($row['id'])) {
            throw new InvalidArgumentException(
                sprintf('Account movement %s not found: %s', $label, $publicId)
            );
        }

        return (int) $row['id'];
    }

    /**
     * Resolve the `pettycash_accounts` surrogate key for a movement's owning account.
     *
     * Fails loudly rather than defaulting to a placeholder id: a movement written
     * against the wrong account is an unrecoverable ledger corruption, so the
     * transaction must abort instead.
     */
    private function resolveAccountDbId(AccountMovement $movement): int
    {
        $accountPublicId = $movement->accountPublicId;
        if ($accountPublicId === null || trim($accountPublicId) === '') {
            throw new InvalidArgumentException(
                'Account movement is missing its owning account reference'
            );
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pettycash_accounts')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($accountPublicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if ($row === false || !isset($row['id'])) {
            throw new InvalidArgumentException(
                'Account not found for movement: ' . $accountPublicId
            );
        }

        return (int) $row['id'];
    }

    private function mapRow(array $row): AccountMovement
    {
        $currency = CurrencyCode::fromString((string) $row['currency_code']);

        return new AccountMovement(
            $row['public_id'],
            MovementType::from($row['movement_type']),
            Money::of((int) $row['amount_minor'], $currency),
            Money::of((int) $row['balance_after_minor'], $currency),
            $row['description'],
            $row['command_id'],
            // The revision's and decision's **public UUIDs**, not their surrogate keys, for
            // the same reason the account's is: `contracts/ocs-api.yaml` declares
            // `sourceRevisionId` as `format: uuid`, and FR-040's traceability is only useful
            // to a caller who can act on the id. `null` for a charge, which has neither.
            isset($row['source_revision_public_id']) ? $row['source_revision_public_id'] : null,
            $row['occurred_at'] ? new DateTimeImmutable($row['occurred_at']) : null,
            // The surrogate key and the owning account's public UUID, so a movement read back from
            // the database can be passed to `save()` again — the idempotency check and the account
            // lookup both need them, and an aggregate that lost them on the way out could not be
            // written a second time.
            (int) $row['id'],
            null,
            $row['account_public_id'] ?? null,
            isset($row['source_decision_public_id']) ? $row['source_decision_public_id'] : null
        );
    }
}