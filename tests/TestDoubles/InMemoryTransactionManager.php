<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Port\TransactionManager;

final class InMemoryTransactionManager implements TransactionManager
{
    private array $callbacks = [];

    public function transactional(callable $callback): mixed
    {
        return $callback();
    }

    public function runInTransaction(callable $callback): mixed
    {
        return $callback();
    }
}