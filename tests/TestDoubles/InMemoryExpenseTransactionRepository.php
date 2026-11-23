<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;

final class InMemoryExpenseTransactionRepository implements \OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository
{
    /**
     * Read probes for the scalability regression: how many times the list path reached the old
     * bulk/N+1 entry points and how often the bounded projection ran. Reset via
     * {@see resetReadProbes()} immediately before the call under test.
     *
     * @var array{findByCostCenterIds: int, findBySheetId: int, findById: int, seniorSheetListRows: int}
     */
    public array $readProbes = [
        'findByCostCenterIds' => 0,
        'findBySheetId' => 0,
        'findById' => 0,
        'seniorSheetListRows' => 0,
    ];

    /**
     * Optional collaborators that mirror {@see seniorSheetListRows()}'s database aggregation. They
     * are attached by `tests/bootstrap.php` once all doubles exist — the decision double itself
     * depends on this one, so the two cannot be wired at construction. A double built without them
     * answers "no decisions / no sheets", which only matters if the list projection is called on a
     * standalone double (the suite registers them before use).
     */
    public function __construct(
        private ?WorkflowDecisionRepository $decisions = null,
        private ?InMemoryExpenseSheetRepository $sheets = null
    ) {}

    public function attachProjectionRepositories(
        WorkflowDecisionRepository $decisions,
        InMemoryExpenseSheetRepository $sheets
    ): void {
        $this->decisions = $decisions;
        $this->sheets = $sheets;
    }

    public function resetReadProbes(): void
    {
        $this->readProbes = [
            'findByCostCenterIds' => 0,
            'findBySheetId' => 0,
            'findById' => 0,
            'seniorSheetListRows' => 0,
        ];
    }

    /**
     * @var array<int, ExpenseTransaction>
     */
    private array $transactions = [];

    public function findById(int $id): ?ExpenseTransaction
    {
        $this->readProbes['findById']++;
        return $this->transactions[$id] ?? null;
    }

    public function findByPublicId(string $publicId): ?ExpenseTransaction
    {
        foreach ($this->transactions as $tx) {
            if ($tx->id === $publicId) {
                return $tx;
            }
        }
        return null;
    }

    /**
     * @return list<ExpenseTransaction>
     */
    public function findBySheetId(int $sheetId): array
    {
        $this->readProbes['findBySheetId']++;
        return $this->transactionsForSheet($sheetId);
    }

    /** @return list<ExpenseTransaction> */
    private function transactionsForSheet(int $sheetId): array
    {
        return array_values(array_filter($this->transactions, fn($t) => $t->sheetId === $sheetId));
    }

    public function countBySheetId(int $sheetId): int
    {
        return count($this->transactionsForSheet($sheetId));
    }

    /**
     * @return list<ExpenseTransaction>
     */
    public function findByOwnerUid(string $ownerUid, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->transactions, function ($t) use ($ownerUid, $stage, $status) {
            if ($t->ownerUid !== $ownerUid) {
                return false;
            }
            if ($stage !== null && $t->stage !== $stage) {
                return false;
            }
            if ($status !== null && $t->status !== $status) {
                return false;
            }
            return true;
        });
        return array_slice(array_values($result), 0, $limit);
    }

    /**
     * @return list<ExpenseTransaction>
     */
    public function findByCostCenterId(int $costCenterId, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $result = $this->transactionsInCostCenters([$costCenterId], $stage, $status);
        return array_slice($result, $offset, $limit);
    }

    /**
     * @param list<int> $costCenterIds
     * @return list<ExpenseTransaction>
     */
    public function findByCostCenterIds(array $costCenterIds, ?WorkflowStage $stage = null, ?TransactionStatus $status = null): array
    {
        $this->readProbes['findByCostCenterIds']++;
        return $this->transactionsInCostCenters($costCenterIds, $stage, $status);
    }

    /**
     * The same predicate and ordering as `DbExpenseTransactionRepository`.
     *
     * The cost centre is matched on the revision's numeric join key, not its public code: the
     * production query filters `r.cost_center_id`, and comparing a code here silently returned no
     * rows at all.
     *
     * @param list<int> $costCenterIds
     * @return list<ExpenseTransaction>
     */
    private function transactionsInCostCenters(array $costCenterIds, ?WorkflowStage $stage, ?TransactionStatus $status): array
    {
        $ids = [];
        foreach ($costCenterIds as $costCenterId) {
            $ids[(int) $costCenterId] = true;
        }

        $result = array_filter($this->transactions, function (ExpenseTransaction $t) use ($ids, $stage, $status) {
            $costCenterId = $t->revision()->costCenterDbId;
            if ($costCenterId === null || !isset($ids[$costCenterId])) {
                return false;
            }
            if ($stage !== null && $t->stage !== $stage) {
                return false;
            }
            if ($status !== null && $t->status !== $status) {
                return false;
            }
            return true;
        });

        $result = array_values($result);
        usort($result, static function (ExpenseTransaction $a, ExpenseTransaction $b): int {
            $aCreatedAt = $a->createdAt;
            $bCreatedAt = $b->createdAt;
            if ($aCreatedAt === null && $bCreatedAt !== null) {
                return 1;
            }
            if ($aCreatedAt !== null && $bCreatedAt === null) {
                return -1;
            }
            if ($aCreatedAt !== null && $bCreatedAt !== null) {
                $comparison = $bCreatedAt <=> $aCreatedAt;
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return ($b->dbId ?? 0) <=> ($a->dbId ?? 0);
        });

        return $result;
    }

    /**
     * Mirror of `DbExpenseTransactionRepository::seniorSheetListRows()`: one compact row per
     * candidate sheet, computed from the same predicates, so the in-memory suite asks the same
     * question the database adapter answers.
     *
     * The scope and the current-revision decision rules are reproduced here, not delegated to the
     * service's own helpers, because a double that called back into the service would turn the
     * service's read path into a cycle. The counters the service uses during the list are what the
     * bounded-load regression asserts against.
     *
     * @param string $actorUid
     * @param list<int> $costCenterIds
     * @return array<int, array{
     *   awaitingSenior: int,
     *   decidedBySenior: int,
     *   returnedByThisSenior: bool,
     *   ccGateUnresolved: int,
     *   activityCreated: ?DateTimeImmutable,
     *   activityUpdated: ?DateTimeImmutable,
     *   activityDecided: ?DateTimeImmutable,
     *   sheetCreated: ?DateTimeImmutable,
     *   sheetFirstSubmitted: ?DateTimeImmutable,
     *   sheetCompleted: ?DateTimeImmutable,
     * }>
     */
    public function seniorSheetListRows(string $actorUid, array $costCenterIds): array
    {
        $this->readProbes['seniorSheetListRows']++;

        $ids = [];
        foreach ($costCenterIds as $costCenterId) {
            $ids[(int) $costCenterId] = true;
        }

        // Same scope predicate as the production query: the active revision's numeric cost-centre
        // join key, never the code (see transactionsInCostCenters()).
        $bySheet = [];
        foreach ($this->transactions as $transaction) {
            if ($transaction->sheetId === null) {
                continue;
            }
            $costCenterId = $transaction->revision()->costCenterDbId;
            if ($costCenterId === null || !isset($ids[$costCenterId])) {
                continue;
            }
            $bySheet[$transaction->sheetId][] = $transaction;
        }

        $rows = [];
        foreach ($bySheet as $sheetDbId => $sheetTransactions) {
            $awaitingSenior = 0;
            $decidedBySenior = 0;
            $returnedByThisSenior = false;
            $activityCreated = null;
            $activityUpdated = null;
            $activityDecided = null;

            foreach ($sheetTransactions as $transaction) {
                $decision = $this->currentRevisionDecision($transaction, WorkflowStage::Senior);

                if ($decision === null) {
                    // Awaiting only while actually at the Senior stage.
                    if ($transaction->stage !== WorkflowStage::Senior) {
                        continue;
                    }
                    $awaitingSenior++;
                    $activityCreated = $this->maxInstant($activityCreated, $transaction->createdAt);
                    $activityUpdated = $this->maxInstant($activityUpdated, $transaction->updatedAt);
                } else {
                    if ($decision->actorUid !== $actorUid) {
                        // Another senior's decision is never disclosed and never counted.
                        continue;
                    }
                    $decidedBySenior++;
                    if ($decision->decision === Decision::Return) {
                        $returnedByThisSenior = true;
                    }
                    $activityCreated = $this->maxInstant($activityCreated, $transaction->createdAt);
                    $activityUpdated = $this->maxInstant($activityUpdated, $transaction->updatedAt);
                    $activityDecided = $this->maxInstant($activityDecided, $decision->decidedAt);
                }
            }

            // Whole-sheet gate, computed over every transaction of the sheet, scoped or not.
            $ccGateUnresolved = 0;
            foreach ($this->transactionsForSheet($sheetDbId) as $sheetTransaction) {
                if (!$this->hasFinalCostCenterDecision($sheetTransaction)) {
                    $ccGateUnresolved++;
                }
            }

            [$sheetCreated, $sheetFirstSubmitted, $sheetCompleted] = $this->sheets?->sheetInstantsForProjection($sheetDbId)
                ?? [null, null, null];

            $rows[$sheetDbId] = [
                'awaitingSenior' => $awaitingSenior,
                'decidedBySenior' => $decidedBySenior,
                'returnedByThisSenior' => $returnedByThisSenior,
                'ccGateUnresolved' => $ccGateUnresolved,
                'activityCreated' => $activityCreated,
                'activityUpdated' => $activityUpdated,
                'activityDecided' => $activityDecided,
                'sheetCreated' => $sheetCreated,
                'sheetFirstSubmitted' => $sheetFirstSubmitted,
                'sheetCompleted' => $sheetCompleted,
            ];
        }

        return $rows;
    }

    /**
     * The decision recorded against this transaction's **current** revision for one stage.
     *
     * Mirrors `ManagementReviewService`'s revision-scoped rule: only the decision whose revision is
     * the transaction's active one counts, and with the (revision_id, stage) unique index there is
     * at most one per stage, so the last match is the one in force.
     */
    private function currentRevisionDecision(ExpenseTransaction $transaction, WorkflowStage $stage): ?WorkflowDecision
    {
        if ($transaction->dbId === null || $this->decisions === null) {
            return null;
        }

        $currentRevisionId = $transaction->revision()->id();
        $current = null;
        foreach ($this->decisions->findByTransactionId($transaction->dbId) as $decision) {
            if ($decision->stage !== $stage) {
                continue;
            }
            if ($decision->revisionId !== $currentRevisionId) {
                continue;
            }
            $current = $decision;
        }

        return $current;
    }

    /**
     * Whether the transaction's current revision carries a final Cost Center decision, mirroring
     * `ManagementReviewService::hasFinalCostCenterDecision()` for the whole-sheet gate.
     */
    private function hasFinalCostCenterDecision(ExpenseTransaction $transaction): bool
    {
        $decision = $this->currentRevisionDecision($transaction, WorkflowStage::CostCenter);

        if ($decision === null) {
            return false;
        }
        if ($decision->decision === Decision::Return) {
            return false;
        }
        if ($transaction->stage === WorkflowStage::Correction
            && $transaction->status === TransactionStatus::AwaitingPurchaserCorrection
        ) {
            return false;
        }

        return true;
    }

    private function maxInstant(?DateTimeImmutable $current, ?DateTimeImmutable $candidate): ?DateTimeImmutable
    {
        if ($candidate !== null && ($current === null || $candidate > $current)) {
            return $candidate;
        }
        return $current;
    }

    /**
     * @return list<ExpenseTransaction>
     */
    public function findByStageAndStatus(WorkflowStage $stage, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->transactions, function ($t) use ($stage, $status) {
            if ($t->stage !== $stage) {
                return false;
            }
            if ($status !== null && $t->status !== $status) {
                return false;
            }
            return true;
        });
        return array_slice(array_values($result), 0, $limit);
    }

    /**
     * @return list<ExpenseTransaction>
     */
    public function findForFinanceQueue(int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->transactions, fn($t) => $t->stage === \OCA\PettyCash\Domain\Workflow\WorkflowStage::Finance);
        return array_slice(array_values($result), $offset, $limit);
    }

    /**
     * Every transaction the double holds, at every stage and in every status (T379).
     *
     * Wider than `findForFinanceQueue()` on purpose, so a controller still publishing that read's
     * length under the name `totalTransactions` cannot satisfy the T379 regression test.
     */
    public function countAll(): int
    {
        return count($this->transactions);
    }

    /**
     * Every transaction of a set of sheets, in one batched read (T016/T018).
     *
     * Mirrors `DbExpenseTransactionRepository::findBySheetIds()`, including the sheet-id-then-created_at-
     * then-id ordering, so the in-memory suite asks the same question the database adapter answers.
     */
    public function findBySheetIds(array $sheetDbIds): array
    {
        $ids = [];
        foreach ($sheetDbIds as $sheetDbId) {
            if (is_int($sheetDbId)) {
                $ids[$sheetDbId] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $result = array_values(array_filter(
            $this->transactions,
            fn (ExpenseTransaction $t): bool => $t->sheetId !== null && isset($ids[$t->sheetId])
        ));

        usort($result, static function (ExpenseTransaction $a, ExpenseTransaction $b): int {
            $bySheet = ($a->sheetId ?? 0) <=> ($b->sheetId ?? 0);
            if ($bySheet !== 0) {
                return $bySheet;
            }
            $aCreatedAt = $a->createdAt;
            $bCreatedAt = $b->createdAt;
            if ($aCreatedAt === null && $bCreatedAt !== null) {
                return 1;
            }
            if ($aCreatedAt !== null && $bCreatedAt === null) {
                return -1;
            }
            if ($aCreatedAt !== null && $bCreatedAt !== null) {
                $comparison = $aCreatedAt <=> $bCreatedAt;
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return ($a->dbId ?? 0) <=> ($b->dbId ?? 0);
        });

        return $result;
    }

    /**
     * The transaction-status projection for a set of sheets, in one grouped read (T016/T017).
     *
     * Mirrors `DbExpenseTransactionRepository::transactionStatusProjectionFor()`: total, approved and
     * rejected come from one pass over `final_outcome`, so `approved + rejected = total` is an
     * invariant the double holds by construction rather than by three separate filters that could
     * drift. A sheet with no rows is absent from the map.
     *
     * @return array<int, array{total: int, approved: int, rejected: int}>
     */
    public function transactionStatusProjectionFor(array $sheetDbIds): array
    {
        $ids = [];
        foreach ($sheetDbIds as $sheetDbId) {
            if (is_int($sheetDbId)) {
                $ids[$sheetDbId] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $projection = [];
        foreach ($this->transactions as $transaction) {
            if ($transaction->sheetId === null || !isset($ids[$transaction->sheetId])) {
                continue;
            }
            $sheetDbId = $transaction->sheetId;
            $entry = $projection[$sheetDbId] ?? ['total' => 0, 'approved' => 0, 'rejected' => 0];
            $entry['total']++;
            if ($transaction->finalOutcome === 'APPROVED') {
                $entry['approved']++;
            } elseif ($transaction->finalOutcome === 'REJECTED') {
                $entry['rejected']++;
            }
            $projection[$sheetDbId] = $entry;
        }

        return $projection;
    }

    public function save(ExpenseTransaction $transaction): void
    {
        if ($transaction->dbId === null) {
            $transaction->dbId = count($this->transactions) + 1;
        }
        // `publicId` is assigned from the aggregate's own `id`, mirroring
        // `DbExpenseTransactionRepository::save()` (line 237) and `DbEvidenceRepository::save()`. The
        // real insert writes the `id` into the `public_id` column, so the two are the same value in
        // production -- but the property was left null here, which meant `findByPublicId()` could never
        // find anything a test had just saved, and a test could pass while production could not resolve
        // the id it had been given. Any double that does not mirror this hides that difference.
        if ($transaction->publicId === null) {
            $transaction->publicId = $transaction->id;
        }
        $this->transactions[$transaction->dbId] = $transaction;
    }

    public function delete(ExpenseTransaction $transaction): void
    {
        if ($transaction->dbId !== null) {
            unset($this->transactions[$transaction->dbId]);
        }
    }

    public function clear(): void
    {
        $this->transactions = [];
    }
}