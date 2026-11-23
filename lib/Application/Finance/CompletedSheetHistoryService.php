<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Finance;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Application\DataIntegrity\UnreadableRecordData;
use OCA\PettyCash\Application\Port\SheetPostedAmountsReader;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;

/**
 * Feature 008 — the completed Cost Sheet history service (T017/T018).
 *
 * `page()` returns one page of sheets whose status is COMPLETED or
 * COMPLETED_WITH_REJECTIONS, ordered `completed_at DESC, id DESC`, with per-sheet
 * total/approved/rejected transaction counts and actual posted amounts derived from
 * consumption movements actually linked to the sheet — never a projection assuming
 * every approved transaction posted (FR-008-035).
 *
 * `detail()` returns one completed sheet with all of its transactions, each carrying
 * its latest stage decisions, full decision history, evidence metadata, and the
 * posting (occurredAt/amount/balanceAfter) linked to the transaction's revision, null
 * for a rejected transaction. The sheet is refused with null unless it is terminal and
 * every transaction is terminal, so a pending Finance-review state never appears as
 * completed history (FR-008-036).
 *
 * All reads are set-based: one page read, one count, one batched transaction-count
 * read, one batched posted-amount read, one batched decisions read, one batched
 * evidence read, and one batched postings read. Detail resolves the sheet's configured
 * currency once, for the authoritative scale shared by every transaction and posting.
 * No query per row, per transaction, or per evidence item.
 */
final readonly class CompletedSheetHistoryService
{
    public function __construct(
        private ExpenseSheetRepository $sheets,
        private ExpenseTransactionRepository $transactions,
        private WorkflowDecisionRepository $decisions,
        private EvidenceRepository $evidence,
        private SheetPostedAmountsReader $sheetPostedAmounts,
        private UserDirectory $userDirectory,
        private CompletedSheetScope $completedSheetScope,
        private CurrencyRepository $currencies,
    ) {}

    /**
     * One page of completed Cost Sheets.
     *
     * @return array{items: list<array<string, mixed>>, page: int, pageSize: int, total: int}
     */
    public function page(int $page, int $pageSize): array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be 1 or greater');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100');
        }

        $offset = ($page - 1) * $pageSize;

        // Fetch ALL sheets of both terminal statuses (no limit), then sort and slice.
        // `findByStatus()` with a limit would return only a per-status page, which cannot be
        // merged and re-sorted correctly across statuses.
        $completed = $this->sheets->findByStatus(SheetStatus::Completed);
        $rejected = $this->sheets->findByStatus(SheetStatus::CompletedWithRejections);

        $items = array_merge($completed, $rejected);
        usort($items, static function (ExpenseSheet $a, ExpenseSheet $b): int {
            $aCompleted = $a->completedAt ?? new DateTimeImmutable('1970-01-01');
            $bCompleted = $b->completedAt ?? new DateTimeImmutable('1970-01-01');
            $byCompleted = $bCompleted <=> $aCompleted;
            if ($byCompleted !== 0) {
                return $byCompleted;
            }
            return strcmp((string) $b->publicId, (string) $a->publicId);
        });
        $pageItems = array_slice($items, $offset, $pageSize);

        $sheetDbIds = array_map(static function (ExpenseSheet $sheet): int {
            return (int) ($sheet->dbId ?? 0);
        }, $pageItems);

        $counts = $this->transactions->transactionStatusProjectionFor($sheetDbIds);
        $posted = $this->sheetPostedAmounts->postedAmountsFor($sheetDbIds);

        $total = (int) ($this->sheets->countByStatus(SheetStatus::Completed))
            + (int) ($this->sheets->countByStatus(SheetStatus::CompletedWithRejections));

        return [
            'items' => array_map(
                static function (ExpenseSheet $sheet) use ($counts, $posted): array {
                    $dbId = (int) ($sheet->dbId ?? 0);
                    $sheetCounts = $counts[$dbId] ?? ['total' => 0, 'approved' => 0, 'rejected' => 0];
                    $sheetPosted = $posted[$dbId] ?? null;
                    $currencyCode = $sheet->currency;
                    // `postedAmountsFor()` returns one row per requested sheet, including a
                    // sheet with no postings, so the scale is the persisted currency's. A
                    // missing row would be a reader-contract violation; do not invent a scale
                    // from the currency code here.
                    $minorUnit = $sheetPosted['minorUnit'] ?? null;

                    return [
                        'id' => (string) $sheet->publicId,
                        'reference' => (string) $sheet->publicId,
                        'title' => $sheet->title,
                        'purchaser' => [
                            'userId' => $sheet->ownerUid,
                            'displayName' => $sheet->ownerUid,
                        ],
                        'totalTransactionCount' => $sheetCounts['total'],
                        'approvedTransactionCount' => $sheetCounts['approved'],
                        'rejectedTransactionCount' => $sheetCounts['rejected'],
                        'postedAmount' => $sheetPosted !== null
                            ? Money::of($sheetPosted['postedAmount'], $currencyCode)
                            : Money::of(0, $currencyCode),
                        'postingCount' => $sheetPosted !== null ? $sheetPosted['postingCount'] : 0,
                        'completedAt' => $sheet->completedAt,
                        'status' => $sheet->status->value,
                        '_currencyCode' => $currencyCode,
                        '_minorUnit' => $minorUnit,
                        '_accountId' => $sheet->accountId,
                        '_accountPublicId' => $sheet->accountId,
                    ];
                },
                $pageItems
            ),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * One completed Cost Sheet in full detail, or null when the sheet is not terminal or
     * any of its transactions is not terminal.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $sheetPublicId): ?array
    {
        $sheet = $this->sheets->findByPublicIdCompleted($sheetPublicId);
        if ($sheet === null) {
            return null;
        }

        $sheetDbId = (int) ($sheet->dbId ?? 0);
        $sheetTransactions = $this->transactions->findBySheetId($sheetDbId);

        // Reuse the shared completed-history scope predicate — no duplicated terminality logic.
        // The scope checks target transaction terminality at Final stage, sheet terminal status,
        // and all sibling transaction terminality in one predicate, so the detail read and the
        // evidence-content branch can never drift.
        if ($sheetTransactions !== []) {
            if (!$this->completedSheetScope->isExposedByCompletedHistory($sheetTransactions[0])) {
                return null;
            }
        } elseif (!in_array(
            $sheet->status,
            [SheetStatus::Completed, SheetStatus::CompletedWithRejections],
            true
        )) {
            // Zero-transaction sheet: scope cannot be applied without a transaction; guard status.
            return null;
        }

        $transactionDbIds = array_map(
            static function (ExpenseTransaction $transaction): int {
                return (int) ($transaction->dbId ?? 0);
            },
            $sheetTransactions
        );

        $allDecisions = $this->decisions->findByTransactionIds($transactionDbIds);
        $allEvidence = $this->evidence->findByTransactionIds($transactionDbIds);
        $postings = $this->sheetPostedAmounts->postingsForSheet($sheetDbId, $sheet->currency);

        // Resolve the configured scale once for the whole detail read. The sheet's currency is
        // the authority for every transaction and posting on it, so the value is not derived
        // from the code and no per-row lookup is performed.
        $currency = $this->currencies->findByCode((string) $sheet->currency);
        if ($currency === null) {
            throw new UnreadableRecordData(sprintf(
                'Completed Cost Sheet %s stores currency %s, which does not resolve to a configured currency.',
                (string) $sheet->publicId,
                (string) $sheet->currency
            ));
        }

        $decisionsByTx = $this->groupByTransactionId($allDecisions);
        $evidenceByTx = $this->groupByTransactionIdEvidence($allEvidence);

        $transactions = array_map(
            function (ExpenseTransaction $transaction) use (
                $decisionsByTx,
                $evidenceByTx,
                $postings,
                $sheet
            ): array {
                $publicId = (string) $transaction->publicId;
                $transactionDbId = (int) ($transaction->dbId ?? 0);
                $decisions = $decisionsByTx[$publicId] ?? [];
                // `pettycash_evidence.transaction_id` is the transaction's integer surrogate id,
                // which is what `findByTransactionIds()` reads and groups by. The public UUID is
                // the wire identity, not the join identity; looking evidence up by it silently
                // returned an empty list for every completed-sheet transaction.
                $evidence = $transactionDbId > 0 ? ($evidenceByTx[$transactionDbId] ?? []) : [];
                $posting = $postings[$publicId] ?? null;

                return [
                    'id' => (string) $transaction->publicId,
                    'reference' => (string) $transaction->publicId,
                    'revisionNumber' => (int) $transaction->revision()->number(),
                    'description' => $transaction->revision()->description() ?? '',
                    'expenseDate' => $transaction->revision()->expenseDate()->format('Y-m-d'),
                    'amount' => $transaction->revision()->amount(),
                    'finalOutcome' => $transaction->finalOutcome ?? null,
                    'status' => $transaction->status->value,
                    'decisions' => $this->latestDecisions($decisions),
                    'decisionHistory' => array_map(
                        fn (WorkflowDecision $d): array => $this->decisionDetail($d),
                        $decisions
                    ),
                    'evidence' => array_map(
                        fn (\OCA\PettyCash\Domain\Evidence\Evidence $e): array => $this->evidenceMetadata($e),
                        $evidence
                    ),
                    'posting' => $posting,
                ];
            },
            $sheetTransactions
        );

        $currencyCode = $sheet->currency;

        return [
            'id' => (string) $sheet->publicId,
            'reference' => (string) $sheet->publicId,
            'title' => $sheet->title,
            'purchaser' => [
                'userId' => $sheet->ownerUid,
                'displayName' => $sheet->ownerUid,
            ],
            'totalTransactionCount' => count($transactions),
            'approvedTransactionCount' => array_reduce(
                $transactions,
                static fn (int $carry, array $tx): int => $tx['finalOutcome'] === 'APPROVED' ? $carry + 1 : $carry,
                0
            ),
            'rejectedTransactionCount' => array_reduce(
                $transactions,
                static fn (int $carry, array $tx): int => $tx['finalOutcome'] === 'REJECTED' ? $carry + 1 : $carry,
                0
            ),
            'postedAmount' => array_reduce(
                $transactions,
                static fn (Money $carry, array $tx): Money => $tx['posting'] !== null
                    ? $carry->add($tx['posting']['amount'])
                    : $carry,
                Money::of(0, $currencyCode)
            ),
            'postingCount' => array_reduce(
                $transactions,
                static fn (int $carry, array $tx): int => $tx['posting'] !== null ? $carry + 1 : $carry,
                0
            ),
            'completedAt' => $sheet->completedAt,
            'status' => $sheet->status->value,
            'accountId' => $sheet->accountId,
            'currency' => (string) $currencyCode,
            'minorUnit' => $currency->minorUnit,
            'createdAt' => $sheet->createdAt,
            'firstSubmittedAt' => $sheet->firstSubmittedAt,
            'financeStartedAt' => $sheet->financeStartedAt,
            'transactions' => $transactions,
        ];
    }

    /**
     * Group workflow decisions by transaction surrogate id, newest decidedAt first.
     *
     * @return array<int, list<WorkflowDecision>>
     */
    private function groupByTransactionId(array $decisions): array
    {
        $grouped = [];
        foreach ($decisions as $decision) {
            // `findByTransactionIds` returns decisions across transactions; group by the
            // transaction surrogate id via the transaction's public id resolution is not
            // available here. Instead the caller passes a flat list; this double's grouping
            // key must come from the decision's transactionId public id. Since WorkflowDecision
            // carries transactionId as a public UUID, map through the transactions slice is
            // handled by the caller. Keep this as identity grouping by transactionId string.
            $key = $decision->transactionId;
            $grouped[$key][] = $decision;
        }

        foreach ($grouped as &$list) {
            usort($list, static function (WorkflowDecision $a, WorkflowDecision $b): int {
                $aAt = $a->decidedAt ?? new DateTimeImmutable('1970-01-01');
                $bAt = $b->decidedAt ?? new DateTimeImmutable('1970-01-01');
                return $bAt <=> $aAt;
            });
        }
        unset($list);

        return $grouped;
    }

    /**
     * Group evidence metadata by transaction surrogate id.
     *
     * The caller must use `ExpenseTransaction::$dbId` for the lookup; the public UUID is a
     * different identifier space and is never the key of this map.
     *
     * @return array<int, list<\OCA\PettyCash\Domain\Evidence\Evidence>>
     */
    private function groupByTransactionIdEvidence(array $evidence): array
    {
        $grouped = [];
        foreach ($evidence as $e) {
            $grouped[$e->transactionId][] = $e;
        }
        return $grouped;
    }

    /**
     * The latest decision per stage across the transaction's decision history.
     *
     * @return array{costCenter: array<string, mixed>|null, senior: array<string, mixed>|null, finance: array<string, mixed>|null}
     */
    private function latestDecisions(array $decisions): array
    {
        $latest = ['costCenter' => null, 'senior' => null, 'finance' => null];
        foreach ($decisions as $decision) {
            $stage = match ($decision->stage) {
                WorkflowStage::CostCenter => 'costCenter',
                WorkflowStage::Senior => 'senior',
                WorkflowStage::Finance => 'finance',
                default => null,
            };
            if ($stage !== null && ($latest[$stage] === null || $decision->decidedAt > $latest[$stage]->decidedAt)) {
                $latest[$stage] = $decision;
            }
        }

        return [
            'costCenter' => $latest['costCenter'] !== null ? $this->decisionDetail($latest['costCenter']) : null,
            'senior' => $latest['senior'] !== null ? $this->decisionDetail($latest['senior']) : null,
            'finance' => $latest['finance'] !== null ? $this->decisionDetail($latest['finance']) : null,
        ];
    }

    private function decisionDetail(WorkflowDecision $decision): array
    {
        return [
            'id' => (string) $decision->publicId,
            'stage' => $decision->stage->value,
            'decision' => $decision->decision->value,
            'explanation' => $decision->explanation ?? null,
            'actor' => [
                'userId' => $decision->actorUid ?? '',
                'displayName' => $decision->actorName ?? '',
                'role' => $decision->actorRole ?? '',
            ],
            'decidedAt' => $decision->decidedAt,
        ];
    }

    private function evidenceMetadata(\OCA\PettyCash\Domain\Evidence\Evidence $evidence): array
    {
        return [
            'id' => (string) $evidence->publicId,
            'name' => $evidence->originalName,
            'mediaType' => $evidence->mediaType,
            'sizeBytes' => (int) $evidence->sizeBytes,
            'active' => (bool) $evidence->active,
            'uploadedBy' => $evidence->uploadedBy ?? '',
            'uploadedAt' => $evidence->uploadedAt,
        ];
    }
}
