<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Account;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbAccountRepository implements AccountRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    /**
     * Apply the account + joined-currency projection used by every read path.
     *
     * The currency columns are added with `selectAlias()` rather than an inline
     * `c.code as currency_code` string: `IQueryBuilder` splits any select string
     * containing ` as ` and keeps only the first segment, so a projection with
     * several inline aliases is silently truncated into one malformed alias and
     * MariaDB rejects the query.
     *
     * `pettycash_currencies` has no `public_id` — `data-model.md` defines `Currency` with
     * only `code`, `name`, `minor_unit` and the audit columns, and currencies are
     * referenced by code, not by an external UUID.
     */
    private function applyProjection(IQueryBuilder $qb): IQueryBuilder
    {
        $qb->select('a.*')
            ->selectAlias('c.code', 'currency_code')
            ->selectAlias('c.name', 'currency_name')
            ->selectAlias('c.minor_unit', 'currency_minor_unit')
            ->selectAlias('c.id', 'currency_id');

        return $qb;
    }

    public function findById(int $id): ?PettyCashAccount
    {
        $qb = $this->connection->getQueryBuilder();
        $this->applyProjection($qb)
            ->from('pettycash_accounts', 'a')
            ->join('a', 'pettycash_currencies', 'c', $qb->expr()->eq('a.currency_id', 'c.id'))
            ->where($qb->expr()->eq('a.id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?PettyCashAccount
    {
        $qb = $this->connection->getQueryBuilder();
        $this->applyProjection($qb)
            ->from('pettycash_accounts', 'a')
            ->join('a', 'pettycash_currencies', 'c', $qb->expr()->eq('a.currency_id', 'c.id'))
            ->where($qb->expr()->eq('a.public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<PettyCashAccount> */
    public function findActiveByUserUid(string $userUid): array
    {
        $qb = $this->connection->getQueryBuilder();
        $this->applyProjection($qb)
            ->from('pettycash_accounts', 'a')
            ->join('a', 'pettycash_currencies', 'c', $qb->expr()->eq('a.currency_id', 'c.id'))
            ->where($qb->expr()->eq('a.user_uid', $qb->createNamedParameter($userUid)))
            ->andWhere($qb->expr()->eq('a.active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /**
     * T391 (ADR-005): every account the uid holds, deactivated ones included.
     *
     * Deliberately **not** reusing `findActiveByUserUid()` with its filter dropped here — that read is
     * also called by FR-020's resolve branch, where "active" is the rule rather than an accident, and
     * loosening the one place that enforces it would be a much worse outcome than a second, narrower
     * method existing. The two reads differ by exactly one predicate and that predicate is the point.
     *
     * @return list<PettyCashAccount>
     */
    public function findAllByUserUid(string $userUid): array
    {
        $qb = $this->connection->getQueryBuilder();
        $this->applyProjection($qb)
            ->from('pettycash_accounts', 'a')
            ->join('a', 'pettycash_currencies', 'c', $qb->expr()->eq('a.currency_id', 'c.id'))
            ->where($qb->expr()->eq('a.user_uid', $qb->createNamedParameter($userUid)));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<PettyCashAccount> */
    public function findActiveByUserAndCurrency(string $userUid, CurrencyCode $currency): array
    {
        $qb = $this->connection->getQueryBuilder();
        $this->applyProjection($qb)
            ->from('pettycash_accounts', 'a')
            ->join('a', 'pettycash_currencies', 'c', $qb->expr()->eq('a.currency_id', 'c.id'))
            ->where($qb->expr()->eq('a.user_uid', $qb->createNamedParameter($userUid)))
            ->andWhere($qb->expr()->eq('c.code', $qb->createNamedParameter((string) $currency)))
            ->andWhere($qb->expr()->eq('a.active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /**
     * Every account on the instance, active and inactive alike (T379).
     *
     * The instance-wide read `AccountRepository` did not have, which is why the administrator card
     * carried a literal `0`. Unlike the other two reads this repository offers, it is deliberately
     * **not** filtered on `active`: the caller is asking how many accounts exist, and a count that
     * quietly dropped deactivated ones would answer a different question under the same name.
     */
    public function countAll(): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'pettycash_account_count'))
            ->from('pettycash_accounts', 'a');

        return (int) $qb->executeQuery()->fetchOne();
    }

    public function save(PettyCashAccount $account): void
    {
        $qb = $this->connection->getQueryBuilder();
        // `$id` is the public UUID and `dbId` the surrogate key, so the lookup has
        // to follow whichever one the aggregate actually carries.
        $existing = $account->dbId !== null
            ? $this->findById($account->dbId)
            : $this->findByPublicId($account->id);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        if ($existing) {
            $qb->update('pettycash_accounts')
                ->set('balance_minor', $qb->createNamedParameter($account->balanceMinor))
                ->set('active', $qb->createNamedParameter($account->active, IQueryBuilder::PARAM_BOOL))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE))
                ->set('updated_by', $qb->createNamedParameter($currentUser))
                ->set('lock_version', $qb->createNamedParameter($existing->lockVersion + 1))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->dbId)))
                ->andWhere($qb->expr()->eq('lock_version', $qb->createNamedParameter($existing->lockVersion)))
                ->executeStatement();
            $account->dbId = $existing->dbId;
            $account->lockVersion = $existing->lockVersion + 1;
        } else {
            $currency = $this->getCurrencyByCode($account->currencyCode());
            if (!$currency) {
                throw new \InvalidArgumentException('Currency not found: ' . $account->currencyCode());
            }
            $qb->insert('pettycash_accounts')
                ->values([
                    'public_id' => $qb->createNamedParameter($account->id),
                    'user_uid' => $qb->createNamedParameter($account->userUid),
                    'currency_id' => $qb->createNamedParameter($currency->id),
                    'balance_minor' => $qb->createNamedParameter($account->balanceMinor),
                    'active' => $qb->createNamedParameter($account->active, IQueryBuilder::PARAM_BOOL),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'created_by' => $qb->createNamedParameter($currentUser),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'updated_by' => $qb->createNamedParameter($currentUser),
                    'lock_version' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();
            
            $account->dbId = $qb->getLastInsertId();
            $account->lockVersion = 0;
        }
    }

    private function getCurrencyByCode(CurrencyCode $currencyCode): ?Currency
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_currencies')
            ->where($qb->expr()->eq('code', $qb->createNamedParameter((string) $currencyCode)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? new Currency(
            $row['code'],
            $row['name'],
            (int) $row['minor_unit'],
            (bool) $row['active'],
            isset($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null,
            $row['created_by'] ?? null,
            isset($row['updated_at']) ? new DateTimeImmutable($row['updated_at']) : null,
            $row['updated_by'] ?? null,
            (int) ($row['lock_version'] ?? 0),
            (int) $row['id'],
            $row['public_id'] ?? null
        ) : null;
    }

    private function mapRow(array $row): PettyCashAccount
    {
        $currencyCode = CurrencyCode::fromString($row['currency_code']);
        $currency = new Currency(
            $row['currency_code'],
            $row['currency_name'],
            (int) $row['currency_minor_unit'],
            true,
            null, null, null, null, 0,
            (int) $row['currency_id'],
            null
        );
        $account = PettyCashAccount::create(
            $row['public_id'],
            $row['user_uid'],
            $currency
        );
        $account->balanceMinor = (int) $row['balance_minor'];
        $account->active = (bool) $row['active'];
        $account->lockVersion = (int) ($row['lock_version'] ?? 0);
        // `$id` is the public UUID; the surrogate primary key belongs on `dbId`.
        // Assigning the integer here both violates the property type and replaces
        // the public identity every caller resolves accounts by.
        $account->dbId = (int) $row['id'];
        return $account;
    }
}