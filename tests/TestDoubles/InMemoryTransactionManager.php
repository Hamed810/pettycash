<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Port\TransactionManager;

final class InMemoryTransactionManager implements TransactionManager
{
    /**
     * The keys passed to each `lockInSettlementOrder()` call, in call order.
     *
     * Recorded rather than discarded so a test can assert the *order* the adapter
     * locked in, which is the property that prevents a deadlock. Asserting only that
     * a lock was requested would pass whichever order the adapter happened to use.
     *
     * @var list<array<string, int>>
     */
    private array $lockCalls = [];

    /**
     * Called immediately before the operation runs, so a test can make the operation
     * itself throw and observe what the manager does with the failure.
     *
     * @var (callable(self): void)|null
     */
    private $onTransaction = null;

    public function transactional(callable $callback): mixed
    {
        if ($this->onTransaction !== null) {
            ($this->onTransaction)($this);
        }
        return $callback();
    }

    public function runInTransaction(callable $callback): mixed
    {
        return $this->transactional($callback);
    }

    public function lockInSettlementOrder(array $ids): void
    {
        $this->lockCalls[] = $ids;
    }

    /** @return list<array<string, int>> */
    public function lockCalls(): array
    {
        return $this->lockCalls;
    }

    public function resetLocks(): void
    {
        $this->lockCalls = [];
    }

    /** @param (callable(self): void)|null $hook */
    public function onTransaction(?callable $hook): void
    {
        $this->onTransaction = $hook;
    }
}
