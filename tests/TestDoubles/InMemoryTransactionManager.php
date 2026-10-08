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

    /** How many `transactional()` calls are currently open. */
    private int $depth = 0;

    public function transactional(callable $callback): mixed
    {
        $this->depth++;
        try {
            if ($this->onTransaction !== null) {
                ($this->onTransaction)($this);
            }

            return $callback();
        } finally {
            $this->depth--;
        }
    }

    /**
     * Whether a transaction is open right now.
     *
     * Added so a test can assert *where* a write happened relative to the transaction, not merely that
     * one was opened. Recording `onTransaction` only proves a transaction began; an audit appended
     * after the callback returned -- the ordering that satisfies "an event exists" while committing the
     * mutation unrecorded -- is indistinguishable without this.
     *
     * Note the `finally`: a thrown operation still decrements, so a rolled-back transaction does not
     * leave the probe permanently open and make the next assertion pass for the wrong reason.
     */
    public function isTransactionOpen(): bool
    {
        return $this->depth > 0;
    }

    /**
     * How many transactions are open right now, as opposed to merely whether any is.
     *
     * Added for FR-085's audit-ordering property. `isTransactionOpen()` cannot answer that question:
     * the superseded-attempt recorder legitimately opens a transaction of its own to write the event,
     * so an observer inside `append()` always sees an open transaction and cannot tell whether it is
     * the recorder's or one the business operation is still holding. Depth separates the two — a
     * writer called at depth 1 is in the recorder's transaction alone, and one called at depth 2 is
     * nested inside a business transaction that will roll it back with itself.
     */
    public function transactionDepth(): int
    {
        return $this->depth;
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
