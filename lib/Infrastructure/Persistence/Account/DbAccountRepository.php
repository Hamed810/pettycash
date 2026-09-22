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

    public function findById(int $id): ?PettyCashAccount
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('a.*, c.code as currency_code, c.name as currency_name, c.minor_unit as currency_minor_unit, c.id as currency_id, c.public_id as currency_public_id')
            ->from('pc_accounts', 'a')
            ->join('a', 'pc_currencies', 'c', $qb->expr()->eq('a.currency_id', 'c.id'))
            ->where($qb->expr()->eq('a.id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?PettyCashAccount
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('a.*, c.code as currency_code, c.name as currency_name, c.minor_unit as currency_minor_unit, c.id as currency_id, c.public_id as currency_public_id')
            ->from('pc_accounts', 'a')
            ->join('a', 'pc_currencies', 'c', $qb->expr()->eq('a.currency_id', 'c.id'))
            ->where($qb->expr()->eq('a.public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<PettyCashAccount> */
    public function findActiveByUserUid(string $userUid): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('a.*, c.code as currency_code, c.name as currency_name, c.minor_unit as currency_minor_unit, c.id as currency_id, c.public_id as currency_public_id')
            ->from('pc_accounts', 'a')
            ->join('a', 'pc_currencies', 'c', $qb->expr()->eq('a.currency_id', 'c.id'))
            ->where($qb->expr()->eq('a.user_uid', $qb->createNamedParameter($userUid)))
            ->andWhere($qb->expr()->eq('a.active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<PettyCashAccount> */
    public function findActiveByUserAndCurrency(string $userUid, CurrencyCode $currency): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('a.*, c.code as currency_code, c.name as currency_name, c.minor_unit as currency_minor_unit, c.id as currency_id, c.public_id as currency_public_id')
            ->from('pc_accounts', 'a')
            ->join('a', 'pc_currencies', 'c', $qb->expr()->eq('a.currency_id', 'c.id'))
            ->where($qb->expr()->eq('a.user_uid', $qb->createNamedParameter($userUid)))
            ->andWhere($qb->expr()->eq('c.code', $qb->createNamedParameter((string) $currency)))
            ->andWhere($qb->expr()->eq('a.active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(PettyCashAccount $account): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $account->id ? $this->findById((int) $account->id) : $this->findByPublicId($account->id);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        if ($existing) {
            $qb->update('pc_accounts')
                ->set('balance_minor', $qb->createNamedParameter($account->balanceMinor))
                ->set('active', $qb->createNamedParameter($account->active, IQueryBuilder::PARAM_BOOL))
                ->set('updated_at', $qb->createNamedParameter($now))
                ->set('updated_by', $qb->createNamedParameter($currentUser))
                ->set('lock_version', $qb->createNamedParameter($existing->lockVersion + 1))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->id)))
                ->andWhere($qb->expr()->eq('lock_version', $qb->createNamedParameter($existing->lockVersion)))
                ->executeStatement();
            $account->lockVersion = $existing->lockVersion + 1;
        } else {
            $currency = $this->getCurrencyByCode($account->currencyCode());
            if (!$currency) {
                throw new \InvalidArgumentException('Currency not found: ' . $account->currencyCode());
            }
            $insertId = $qb->insert('pc_accounts')
                ->values([
                    'public_id' => $qb->createNamedParameter($account->id),
                    'user_uid' => $qb->createNamedParameter($account->userUid),
                    'currency_id' => $qb->createNamedParameter($currency->id),
                    'balance_minor' => $qb->createNamedParameter($account->balanceMinor),
                    'active' => $qb->createNamedParameter($account->active, IQueryBuilder::PARAM_BOOL),
                    'created_at' => $qb->createNamedParameter($now),
                    'created_by' => $qb->createNamedParameter($currentUser),
                    'updated_at' => $qb->createNamedParameter($now),
                    'updated_by' => $qb->createNamedParameter($currentUser),
                    'lock_version' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();
            
            $account->dbId = (int) $insertId;
            $account->lockVersion = 0;
        }
    }

    private function getCurrencyByCode(CurrencyCode $currencyCode): ?Currency
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_currencies')
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
            $row['currency_public_id'] ?? null
        );
        $account = PettyCashAccount::create(
            $row['public_id'],
            $row['user_uid'],
            $currency
        );
        $account->balanceMinor = (int) $row['balance_minor'];
        $account->active = (bool) $row['active'];
        $account->lockVersion = (int) ($row['lock_version'] ?? 0);
        $account->id = (int) $row['id'];
        return $account;
    }
}