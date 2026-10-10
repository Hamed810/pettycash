<?php
declare(strict_types=1);
namespace OCA\PettyCash\Application\Port;
interface TransactionManager
{
    /** @template T @param callable():T $operation @return T */
    public function transactional(callable $operation): mixed;

    /**
     * Take write locks on the settlement's rows, always in the same order.
     *
     * FR-042 makes a multi-record settlement atomic, and two finance officers approving
     * at once is the ordinary case rather than the exotic one. Without a single agreed
     * order the database is free to deadlock the pair, and a deadlock surfacing as a
     * failed settlement is a real cost to the business, not an edge case.
     *
     * The order is fixed by the adapter — sheet, then transaction, then revision, then
     * account — and callers pass whatever subset they hold. It belongs on the port
     * rather than only on the MariaDB adapter because the settlement use case is
     * required to take these locks; leaving the method off the port made it
     * unreachable from the application layer, and an unreachable lock is not a lock.
     *
     * @param array{sheet?:int,transaction?:int,revision?:int,account?:int} $ids
     *         Surrogate keys. Ids that are absent are skipped, so a caller that holds
     *         only an account does not need to invent the rest.
     */
    public function lockInSettlementOrder(array $ids): void;
}
