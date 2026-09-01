<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Currency> */
final class CurrencyMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pcash_currency', Currency::class);
    }

    /** @return list<Currency> */
    public function findAll(bool $includeInactive = true): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('is_default', 'DESC')->addOrderBy('code', 'ASC');
        if (!$includeInactive) {
            $qb->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
        return $this->findEntities($qb);
    }

    public function findByCode(string $code): Currency {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('code', $qb->createNamedParameter(strtoupper($code))));
        return $this->findEntity($qb);
    }

    public function clearDefaultExcept(?int $exceptId = null): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('is_default', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        if ($exceptId !== null) {
            $qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptId, IQueryBuilder::PARAM_INT)));
        }
        $qb->executeStatement();
    }
}
