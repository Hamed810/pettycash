<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Finance;

use InvalidArgumentException;
use OCA\PettyCash\Application\DataIntegrity\UnreadableRecordData;
use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;

/**
 * Feature 008 — the Finance holder-account and ledger reads (T008/T012/T013).
 *
 * Given a holder uid, lists the petty-cash accounts actually owned by that uid — active and inactive
 * alike — with the movement-activity facts batched from the movement table. Given an account's public
 * id, returns one authoritative ledger page. The service is internal at this stage; no HTTP surface
 * exists in this batch, and the public Finance gate (T009) composes `FinanceAccess` separately.
 *
 * ## Not-found behavior at the application boundary
 *
 * Both reads return `null` when the subject does not exist: an unknown uid and a uid that owns no
 * petty-cash account are the same answer, and an unknown account public id is the same answer as an
 * absent ledger. The caller (the future controller) maps that single result to 404, so neither method
 * is an ownership oracle. Crucially, the holder read never consults `UserDirectory`: whether the uid
 * names a real platform user is not this read's question, and asking would turn the endpoint into an
 * oracle.
 *
 * ## Ledger authority
 *
 * Every row is derived from one persisted `AccountMovement`; no row is synthesised and no two
 * movements are consolidated. `occurredAt` and `balanceAfter` are the persisted values — never the
 * sheet creation date, the transaction expense date, a page sum, or a reconstructed running balance.
 * Negative balances pass through unchanged; presentation is a frontend concern.
 *
 * ## Query boundedness
 *
 * A ledger page issues a constant number of queries regardless of page size: the account read, the
 * batched account-activity read, the independent movement count, the paged movement read, one batched
 * provenance resolution, and one batched approval-count read. The last two are skipped only when the
 * page contains no consumption movement at all. Nothing here issues a query per row.
 *
 * ## No mutation capability
 *
 * This class exposes reads only. No account, movement, balance, or version is writable here.
 */
final class FinanceLedgerService
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly AccountMovementRepository $movements,
        private readonly FinanceRead $query
    ) {}

    /**
     * All accounts owned by a holder uid, active and inactive, with batched activity.
     *
     * @return array{items: list<array<string, mixed>>, page: int, pageSize: int, total: int}|null
     *         `null` when the uid owns no petty-cash account — indistinguishable from an unknown uid
     */
    public function accountsForHolder(string $holderUid, int $page = 1, int $pageSize = 25): ?array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be 1 or greater');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100');
        }

        $owned = $this->accounts->findAllByUserUid($holderUid);
        if ($owned === []) {
            return null;
        }

        // Deterministic page order per the approved pagination rule: currency, then public id.
        usort($owned, static function (PettyCashAccount $a, PettyCashAccount $b): int {
            $byCurrency = strcmp((string) $a->currencyCode(), (string) $b->currencyCode());

            return $byCurrency !== 0 ? $byCurrency : strcmp($a->id, $b->id);
        });

        $total = count($owned);
        $pageItems = array_slice($owned, ($page - 1) * $pageSize, $pageSize);

        // One grouped activity read for the whole page — never one query per account. Accounts with
        // no movements are simply absent from the returned map, which becomes `null`/`0` below.
        $activity = $this->query->accountActivityFor(array_map(
            static fn (PettyCashAccount $a): int => (int) $a->dbId,
            $pageItems
        ));

        $items = [];
        foreach ($pageItems as $account) {
            $dbId = (int) $account->dbId;
            $facts = $activity[$dbId] ?? ['movementCount' => 0, 'lastMovementAt' => null];
            $items[] = [
                'id' => $account->id,
                'userId' => $account->userUid,
                'currency' => (string) $account->currencyCode(),
                'minorUnit' => $account->currency->minorUnit,
                'balance' => $account->balance(),
                'active' => $account->active,
                'version' => $account->lockVersion,
                'movementCount' => $facts['movementCount'],
                'lastMovementAt' => $facts['lastMovementAt'],
            ];
        }

        return [
            'items' => $items,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * One authoritative ledger page for a single account.
     *
     * `total` is the independent movement count for the whole account; the rows are one slice of it.
     * The account header reuses the same authoritative current balance the holder-account read emits,
     * plus the batched activity facts (`movementCount` is `total`, because both describe the same
     * complete population; `lastMovementAt` is the batched `MAX(occurred_at)` and never the first row
     * of the loaded page).
     *
     * @return array{
     *     account: array<string, mixed>,
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     pageSize: int,
     *     total: int
     * }|null `null` when the account public id does not resolve
     */
    public function ledger(string $accountId, int $page = 1, int $pageSize = 25): ?array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be 1 or greater');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100');
        }

        $account = $this->accounts->findByPublicId($accountId);
        if ($account === null || $account->dbId === null) {
            return null;
        }

        $accountDbId = (int) $account->dbId;
        $total = $this->movements->countByAccountId($accountDbId);
        $pageRows = $this->movements->findByAccountIdPaged(
            $accountDbId,
            ($page - 1) * $pageSize,
            $pageSize
        );

        // The header's last-movement timestamp is the authoritative maximum over the account, not the
        // top row of this page: on page 2 the two are different facts, and reporting the page's first
        // row as "last movement" would make the header change as the client paginates.
        $activity = $this->query->accountActivityFor([$accountDbId]);
        $facts = $activity[$accountDbId] ?? ['movementCount' => 0, 'lastMovementAt' => null];

        $revisionPublicIds = [];
        foreach ($pageRows as $movement) {
            if ($movement->sourceRevisionId !== null && trim($movement->sourceRevisionId) !== '') {
                $revisionPublicIds[] = $movement->sourceRevisionId;
            }
        }
        $context = $this->query->ledgerContextFor(array_values(array_unique($revisionPublicIds)));

        $sheetPublicIds = [];
        foreach ($context as $resolved) {
            $sheetPublicIds[] = $resolved['sheetId'];
        }
        $approvedCounts = $this->query->ledgerApprovedCountsFor(array_values(array_unique($sheetPublicIds)));

        $items = [];
        foreach ($pageRows as $movement) {
            $items[] = $this->ledgerRow($movement, $context, $approvedCounts);
        }

        return [
            'account' => [
                'id' => $account->id,
                'userId' => $account->userUid,
                'currency' => (string) $account->currencyCode(),
                'minorUnit' => $account->currency->minorUnit,
                'balance' => $account->balance(),
                'active' => $account->active,
                'version' => $account->lockVersion,
                'movementCount' => $total,
                'lastMovementAt' => $facts['lastMovementAt'],
            ],
            'items' => $items,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * Project one persisted movement into the six-column ledger row.
     *
     * Exactly one monetary side is non-null: `recharge` for a charge, `expense` for a consumption. A
     * consumption that cannot resolve its whole provenance chain is a **data fault** — never a row
     * with `approved: null`, which would present a posting as if it carried no Cost Sheet.
     *
     * @param array<string, array{sheetId: string, sheetTitle: string}> $context
     * @param array<string, array{approvedCount: int, totalCount: int}> $approvedCounts
     * @return array<string, mixed>
     */
    private function ledgerRow(
        AccountMovement $movement,
        array $context,
        array $approvedCounts
    ): array {
        $isCharge = $movement->type === MovementType::Charge;
        $sheet = null;
        $approved = null;
        $description = $movement->description;

        if (!$isCharge) {
            $revisionPublicId = $movement->sourceRevisionId;
            if ($revisionPublicId === null || trim($revisionPublicId) === '') {
                throw new UnreadableRecordData(sprintf(
                    'Consumption movement %s carries no source revision, so its Cost Sheet cannot be resolved.',
                    $movement->id
                ));
            }

            $resolved = $context[$revisionPublicId] ?? null;
            if ($resolved === null) {
                throw new UnreadableRecordData(sprintf(
                    'Consumption movement %s references revision %s, which does not resolve to a Cost Sheet.',
                    $movement->id,
                    $revisionPublicId
                ));
            }

            $sheetPublicId = $resolved['sheetId'];
            $counts = $approvedCounts[$sheetPublicId] ?? null;
            if ($counts === null) {
                throw new UnreadableRecordData(sprintf(
                    'Consumption movement %s resolves to Cost Sheet %s, whose transaction counts cannot be read.',
                    $movement->id,
                    $sheetPublicId
                ));
            }

            $sheet = [
                // The schema has no separate business-reference column; the public UUID is both the
                // sheet identifier and the authoritative reference.
                'id' => $sheetPublicId,
                'reference' => $sheetPublicId,
                'title' => $resolved['sheetTitle'],
            ];
            $approved = [
                'approvedCount' => $counts['approvedCount'],
                'totalCount' => $counts['totalCount'],
            ];
            // The movement's stored literal is "Expense consumption"; the approved presentation for a
            // consumption is the Cost Sheet title resolved from the provenance chain.
            $description = $resolved['sheetTitle'];
        }

        return [
            'id' => $movement->id,
            'type' => $movement->type->value,
            // The persisted posting timestamp — never the sheet's creation date or the revision's
            // expense date, and never a value derived from the page.
            'occurredAt' => $movement->occurredAt,
            'description' => $description,
            'sheet' => $sheet,
            'approved' => $approved,
            'recharge' => $isCharge ? $movement->amount : null,
            'expense' => $isCharge ? null : $movement->amount,
            // The persisted resulting balance, transmitted verbatim. A negative value is an amount
            // owed; clamping or recomputing it here would destroy the historical balance.
            'balanceAfter' => $movement->balanceAfter,
            'sourceRevisionId' => $movement->sourceRevisionId,
        ];
    }
}
