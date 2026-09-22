<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Configuration;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbCurrencyRepository implements CurrencyRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findByCode(string $code): ?Currency
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_currencies')
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findActiveByCode(string $code): ?Currency
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_currencies')
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<Currency> */
    public function findAllActive(): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_currencies')
            ->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(Currency $currency): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $this->findByCode($currency->code());
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->getCurrentUser();

        if ($existing) {
            $qb->update('pc_currencies')
                ->set('name', $qb->createNamedParameter($currency->name))
                ->set('minor_unit', $qb->createNamedParameter($currency->minorUnit))
                ->set('active', $qb->createNamedParameter($currency->active, IQueryBuilder::PARAM_BOOL))
                ->set('updated_at', $qb->createNamedParameter($now))
                ->set('updated_by', $qb->createNamedParameter($currentUser))
                ->set('lock_version', $qb->createNamedParameter($existing->lockVersion + 1))
                ->where($qb->expr()->eq('code', $qb->createNamedParameter($currency->code())))
                ->andWhere($qb->expr()->eq('lock_version', $qb->createNamedParameter($existing->lockVersion)))
                ->executeStatement();
        } else {
            $qb->insert('pc_currencies')
                ->values([
                    'code' => $qb->createNamedParameter($currency->code()),
                    'name' => $qb->createNamedParameter($currency->name),
                    'minor_unit' => $qb->createNamedParameter($currency->minorUnit),
                    'active' => $qb->createNamedParameter($currency->active, IQueryBuilder::PARAM_BOOL),
                    'created_at' => $qb->createNamedParameter($now),
                    'created_by' => $qb->createNamedParameter($currentUser),
                    'updated_at' => $qb->createNamedParameter($now),
                    'updated_by' => $qb->createNamedParameter($currentUser),
                    'lock_version' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();
        }
    }

    private function mapRow(array $row): Currency
    {
        $currency = Currency::create(
            $row['code'],
            $row['name'],
            (int) $row['minor_unit']
        );
        $currency->active = (bool) $row['active'];
        $currency->createdAt = isset($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null;
        $currency->createdBy = $row['created_by'] ?? null;
        $currency->updatedAt = isset($row['updated_at']) ? new DateTimeImmutable($row['updated_at']) : null;
        $currency->updatedBy = $row['updated_by'] ?? null;
        $currency->lockVersion = (int) ($row['lock_version'] ?? 0);
        return $currency;
    }

    private function getCurrentUser(): string
    {
        try {
            return $this->currentUser->uid();
        } catch (\Throwable) {
            return 'system';
        }
    }
}