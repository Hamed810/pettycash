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

    public function findById(int $id): ?AccountMovement
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_account_movements')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByCommandId(string $commandId): ?AccountMovement
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_account_movements')
            ->where($qb->expr()->eq('command_id', $qb->createNamedParameter($commandId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findBySourceRevisionId(int $sourceRevisionId): ?AccountMovement
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_account_movements')
            ->where($qb->expr()->eq('source_revision_id', $qb->createNamedParameter($sourceRevisionId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<AccountMovement> */
    public function findByAccountId(int $accountId, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_account_movements')
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId)));
        if ($from) {
            $qb->andWhere($qb->expr()->gte('occurred_at', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }
        if ($to) {
            $qb->andWhere($qb->expr()->lte('occurred_at', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }
        $qb->orderBy('occurred_at', 'DESC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<AccountMovement> */
    public function findByAccountIdPaged(int $accountId, int $offset, int $limit): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_account_movements')
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId)))
            ->orderBy('occurred_at', 'DESC')
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
                'currency_code' => $qb->createNamedParameter((string)$movement->amount->currency()),
                'description' => $qb->createNamedParameter($movement->description ?? ''),
                'source_revision_id' => $movement->sourceRevisionId ? $qb->createNamedParameter((int) $movement->sourceRevisionId) : $qb->createNamedParameter(null),
                'source_decision_id' => $qb->createNamedParameter(null),
                'command_id' => $qb->createNamedParameter($movement->commandId),
                'actor_uid' => $qb->createNamedParameter($currentUser),
                'actor_name' => $qb->createNamedParameter($this->userDirectory->displayName($currentUser)),
                'actor_role' => $qb->createNamedParameter('FINANCE'),
                'occurred_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
            ])
            ->executeStatement();
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
        return new AccountMovement(
            $row['public_id'],
            MovementType::from($row['movement_type']),
            \OCA\PettyCash\Domain\Shared\Money::of((int) $row['amount_minor'], \OCA\PettyCash\Domain\Shared\CurrencyCode::fromString($row['currency_code'])),
            \OCA\PettyCash\Domain\Shared\Money::of((int) $row['balance_after_minor'], \OCA\PettyCash\Domain\Shared\CurrencyCode::fromString($row['currency_code'])),
            $row['description'],
            $row['command_id'],
            $row['source_revision_id'] ? (string) $row['source_revision_id'] : null,
            $row['occurred_at'] ? new DateTimeImmutable($row['occurred_at']) : null
        );
    }
}