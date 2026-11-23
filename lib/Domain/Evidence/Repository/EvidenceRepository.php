<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Evidence\Repository;

use OCA\PettyCash\Domain\Evidence\Evidence;

/** @extends \IteratorAggregate<int, Evidence> */
interface EvidenceRepository
{
    public function findById(int $id): ?Evidence;
    public function findByPublicId(string $publicId): ?Evidence;
    /** @return list<Evidence> */
    public function findByTransactionId(int $transactionId, bool $activeOnly = false): array;
    /** @return list<Evidence> */
    public function findByRevisionId(int $revisionId): array;

    /**
     * Every evidence row attached to a set of transactions, in one batched read.
     *
     * Added for T016/T018. The completed Cost Sheet detail shows evidence metadata per
     * transaction, and a per-transaction `findByTransactionId()` read would make the detail
     * endpoint N+1 in the number of transactions on the sheet. An empty input returns an
     * empty list without issuing a query.
     *
     * @param list<int> $transactionDbIds surrogate `pettycash_transactions.id` values
     * @return list<Evidence>
     */
    public function findByTransactionIds(array $transactionDbIds): array;

    public function save(Evidence $evidence): void;
    /**
     * Remove an evidence row outright.
     *
     * Reserved for the FR-058 pre-submission case, where the purchaser's draft
     * transaction is deleted together with the evidence attached to it. After
     * submission evidence is only ever deactivated, never deleted.
     */
    public function delete(Evidence $evidence): void;
}