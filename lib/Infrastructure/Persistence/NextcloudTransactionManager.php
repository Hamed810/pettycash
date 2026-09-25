<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence;

use OCA\PettyCash\Application\Port\TransactionManager;
use OCP\IDBConnection;
use Throwable;

final readonly class NextcloudTransactionManager implements TransactionManager
{
    public function __construct(private IDBConnection $connection) {}

    public function transactional(callable $operation): mixed
    {
        $this->connection->beginTransaction();
        try {
            $result = $operation();
            $this->connection->commit();
            return $result;
        } catch (Throwable $error) {
            $this->connection->rollBack();
            throw $error;
        }
    }

    /** @param array{sheet?:int,transaction?:int,revision?:int,account?:int} $ids */
    public function lockInSettlementOrder(array $ids): void
    {
        foreach (['sheet' => 'pettycash_sheets', 'transaction' => 'pettycash_transactions', 'revision' => 'pettycash_transaction_revisions', 'account' => 'pettycash_accounts'] as $key => $table) {
            if (!isset($ids[$key])) { continue; }
            $qb = $this->connection->getQueryBuilder();
            $qb->select('id')->from($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($ids[$key], \OCP\DB\Types::BIGINT)))->forUpdate()->executeQuery()->closeCursor();
        }
    }
}
