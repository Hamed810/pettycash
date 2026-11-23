<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Http;

use DateTimeInterface;
use OCA\PettyCash\Domain\Shared\Money;

/**
 * Feature 008 — the wire shapes of the Finance holder reads and the account ledger.
 *
 * This class is a **pure projection**: it receives values the Application layer has already
 * decided and reshapes them into the schemas declared by `contracts/ocs-api.yaml`
 * ({@see HolderSummary}, {@see HolderAccountSummary}, {@see FinanceLedgerRow}, {@see PageMeta}).
 * It performs no query, consults no user directory, makes no authorization decision, computes no
 * balance, and invents no default for a value it was not given. Every field below is copied from
 * its source or formatted from a value already present there.
 *
 * ## Why the holder display name is an argument
 *
 * `FinanceLedgerService::accountsForHolder()` deliberately does not consult `UserDirectory`: its
 * not-found rule must not depend on whether a uid names a real platform user, or the read becomes
 * an account-ownership oracle. The controller resolves the display name for the one holder it is
 * about to return and passes it in, so the formatter stays directory-free while the response still
 * carries the contract's `FinanceUserRef`.
 *
 * ## Why `balance` is built here rather than delegated
 *
 * `AccountPayloadFormatter::account()` maps a `PettyCashAccount`, but the Finance read returns a
 * projection array whose `balance` is already the authoritative stored `Money`. Rebuilding a
 * `PettyCashAccount` to call that formatter would invent aggregate state the read never loaded.
 * The money object is therefore mapped directly from the `Money` and the currency facts beside it.
 */
final readonly class FinancePayloadFormatter
{
    /**
     * The contract's `FinanceHolderPage`: `PageMeta` plus a list of `HolderSummary`.
     *
     * @param array{items: list<array<string, mixed>>, page: int, pageSize: int, total: int} $page
     * @return array<string, mixed>
     */
    public function holderPage(array $page): array
    {
        return [
            'items' => array_map(
                fn (array $holder): array => $this->holderSummary($holder),
                $page['items']
            ),
            'page' => $page['page'],
            'pageSize' => $page['pageSize'],
            'total' => $page['total'],
        ];
    }

    /**
     * The contract's `HolderSummary`.
     *
     * @param array<string, mixed> $holder
     * @return array<string, mixed>
     */
    public function holderSummary(array $holder): array
    {
        return [
            'userId' => $holder['userId'],
            'displayName' => $holder['displayName'],
            'accountCount' => $holder['accountCount'],
            'activeAccountCount' => $holder['activeAccountCount'],
            'status' => $holder['status'],
        ];
    }

    /**
     * The contract's `FinanceHolderAccountPage`: `PageMeta`, a `FinanceUserRef` header, and the
     * holder's `HolderAccountSummary` rows.
     *
     * @param array{items: list<array<string, mixed>>, page: int, pageSize: int, total: int} $page
     * @return array<string, mixed>
     */
    public function holderAccountPage(string $holderUid, string $displayName, array $page): array
    {
        return [
            'holder' => [
                'userId' => $holderUid,
                'displayName' => $displayName,
            ],
            'items' => array_map(
                fn (array $account): array => $this->holderAccountSummary($account),
                $page['items']
            ),
            'page' => $page['page'],
            'pageSize' => $page['pageSize'],
            'total' => $page['total'],
        ];
    }

    /**
     * The contract's `HolderAccountSummary`.
     *
     * `lastMovementAt` is the batched persisted `MAX(occurred_at)`, formatted as the contract's
     * `date-time`; an account with no movements keeps `null` rather than being given a fabricated
     * timestamp. `balance`, `active`, and `version` are copied verbatim from the read projection:
     * a negative balance is transmitted as it is stored, and no capability field is added.
     *
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function holderAccountSummary(array $account): array
    {
        /** @var Money $balance */
        $balance = $account['balance'];
        $lastMovementAt = $account['lastMovementAt'];

        return [
            'id' => $account['id'],
            'userId' => $account['userId'],
            'currency' => $account['currency'],
            'minorUnit' => $account['minorUnit'],
            'balance' => [
                'amountMinor' => $balance->minorUnits(),
                'currency' => $account['currency'],
                'minorUnit' => $account['minorUnit'],
            ],
            'active' => $account['active'],
            'version' => $account['version'],
            'movementCount' => $account['movementCount'],
            'lastMovementAt' => $lastMovementAt instanceof DateTimeInterface
                ? $lastMovementAt->format(DateTimeInterface::ATOM)
                : null,
        ];
    }

    /**
     * The contract's `FinanceLedgerPage`: `PageMeta`, an account header, and `FinanceLedgerRow` items.
     *
     * The account header and every row are copied from the application projection — no derivation,
     * no balance recomputation, no capability fields. A negative `balance` or `balanceAfter`
     * is transmitted verbatim. `occurredAt` and `balanceAfter` are already `DateTimeInterface` and
     * `Money` from the persisted movement; this method only formats them for the wire.
     *
     * @param array{
     *     account: array<string, mixed>,
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     pageSize: int,
     *     total: int
     * } $page
     * @return array<string, mixed>
     */
    public function ledgerPage(array $page): array
    {
        // The account projection already resolved the configured scale from the persisted
        // currency. Every movement on the account is denominated in that same currency, so
        // the value is threaded to the row money objects rather than re-derived from a code.
        $minorUnit = (int) $page['account']['minorUnit'];

        return [
            'account' => $this->ledgerAccountHeader($page['account']),
            'items' => array_map(
                fn (array $row): array => $this->ledgerRow($row, $minorUnit),
                $page['items']
            ),
            'page' => $page['page'],
            'pageSize' => $page['pageSize'],
            'total' => $page['total'],
        ];
    }

    /**
     * The ledger account header mirrors `HolderAccountSummary` plus the authoritative current balance.
     *
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function ledgerAccountHeader(array $account): array
    {
        /** @var Money $balance */
        $balance = $account['balance'];
        $lastMovementAt = $account['lastMovementAt'];

        return [
            'id' => $account['id'],
            'userId' => $account['userId'],
            'currency' => $account['currency'],
            'minorUnit' => $account['minorUnit'],
            'balance' => [
                'amountMinor' => $balance->minorUnits(),
                'currency' => $account['currency'],
                'minorUnit' => $account['minorUnit'],
            ],
            'active' => $account['active'],
            'version' => $account['version'],
            'movementCount' => $account['movementCount'],
            'lastMovementAt' => $lastMovementAt instanceof DateTimeInterface
                ? $lastMovementAt->format(DateTimeInterface::ATOM)
                : null,
        ];
    }

    /**
     * One `FinanceLedgerRow` — exactly one monetary side, persisted date and balance, sheet context
     * and approved/total for consumptions, `approved: null` for recharges.
     *
     * @param array<string, mixed> $row
     * @param int $minorUnit the account's authoritative configured scale
     * @return array<string, mixed>
     */
    private function ledgerRow(array $row, int $minorUnit): array
    {
        $isCharge = $row['type'] === 'CHARGE';

        $recharge = $isCharge ? $this->money($row['recharge'], $minorUnit) : null;
        $expense = $isCharge ? null : $this->money($row['expense'], $minorUnit);
        $balanceAfter = $this->money($row['balanceAfter'], $minorUnit);
        $occurredAt = $row['occurredAt'];
        $sheet = $row['sheet'];
        $approved = $row['approved'];

        return [
            'id' => $row['id'],
            'type' => $row['type'],
            'occurredAt' => $occurredAt instanceof DateTimeInterface
                ? $occurredAt->format(DateTimeInterface::ATOM)
                : (is_string($occurredAt) && trim($occurredAt) !== '' ? $occurredAt : null),
            'description' => $row['description'],
            'sheet' => $sheet ? [
                'id' => $sheet['id'],
                'reference' => $sheet['reference'],
                'title' => $sheet['title'],
            ] : null,
            'approved' => $approved !== null ? [
                'approvedCount' => $approved['approvedCount'],
                'totalCount' => $approved['totalCount'],
            ] : null,
            'recharge' => $recharge,
            'expense' => $expense,
            'balanceAfter' => $balanceAfter,
            'sourceRevisionId' => $row['sourceRevisionId'],
        ];
    }

    /**
     * Format a `Money` value (or a `Money`-compatible array) for the wire.
     *
     * @param Money|array<string, mixed>|null $money
     * @param int $minorUnit the account's authoritative configured scale
     * @return array<string, mixed>|null
     */
    private function money(Money|array|null $money, int $minorUnit): ?array
    {
        if ($money === null) {
            return null;
        }
        if ($money instanceof Money) {
            return [
                'amountMinor' => $money->minorUnits(),
                'currency' => (string) $money->currency(),
                'minorUnit' => $minorUnit,
            ];
        }
        // Already an array from the projection (e.g., recharge/expense in ledgerRow input)
        return $money;
    }

    /**
     * The contract's `CompletedSheetPage`: `PageMeta` plus a list of `CompletedSheetSummary`.
     *
     * @param array{items: list<array<string, mixed>>, page: int, pageSize: int, total: int} $page
     * @return array<string, mixed>
     */
    public function completedSheetPage(array $page): array
    {
        return [
            'items' => array_map(
                fn (array $sheet): array => $this->completedSheetSummary($sheet),
                $page['items']
            ),
            'page' => $page['page'],
            'pageSize' => $page['pageSize'],
            'total' => $page['total'],
        ];
    }

    /**
     * One `CompletedSheetSummary`.
     *
     * @param array<string, mixed> $sheet
     * @return array<string, mixed>
     */
    private function completedSheetSummary(array $sheet): array
    {
        $balance = $sheet['postedAmount'];
        // The page projection carries `_minorUnit`; the detail projection carries the same
        // authoritative value as `minorUnit`. Neither is derived from the currency code here.
        $minorUnit = $sheet['_minorUnit'] ?? $sheet['minorUnit'] ?? null;

        return [
            'id' => $sheet['id'],
            'reference' => $sheet['reference'],
            'title' => $sheet['title'],
            'purchaser' => $sheet['purchaser'],
            'totalTransactionCount' => $sheet['totalTransactionCount'],
            'approvedTransactionCount' => $sheet['approvedTransactionCount'],
            'rejectedTransactionCount' => $sheet['rejectedTransactionCount'],
            'postedAmount' => [
                'amountMinor' => $balance->minorUnits(),
                'currency' => (string) $balance->currency(),
                'minorUnit' => $minorUnit,
            ],
            'postingCount' => $sheet['postingCount'],
            'completedAt' => $sheet['completedAt'] instanceof DateTimeInterface
                ? $sheet['completedAt']->format(DateTimeInterface::ATOM)
                : null,
            'status' => $sheet['status'],
        ];
    }

    /**
     * One `CompletedSheetDetail` — extends the summary with header fields and transactions.
     *
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    public function completedSheetDetail(array $detail): array
    {
        $summary = $this->completedSheetSummary($detail);

        return array_merge($summary, [
            'accountId' => $detail['accountId'],
            'currency' => $detail['currency'],
            'minorUnit' => $detail['minorUnit'],
            'createdAt' => $detail['createdAt'] instanceof DateTimeInterface
                ? $detail['createdAt']->format(DateTimeInterface::ATOM)
                : null,
            'firstSubmittedAt' => $detail['firstSubmittedAt'] instanceof DateTimeInterface
                ? $detail['firstSubmittedAt']->format(DateTimeInterface::ATOM)
                : null,
            'financeStartedAt' => $detail['financeStartedAt'] instanceof DateTimeInterface
                ? $detail['financeStartedAt']->format(DateTimeInterface::ATOM)
                : null,
            'transactions' => array_map(
                fn (array $tx): array => $this->completedTransaction($tx, (int) $detail['minorUnit']),
                $detail['transactions']
            ),
        ]);
    }

    /**
     * One `CompletedSheetTransaction`.
     *
     * @param array<string, mixed> $tx
     * @param int $minorUnit the completed sheet's authoritative configured scale
     * @return array<string, mixed>
     */
    private function completedTransaction(array $tx, int $minorUnit): array
    {
        $posting = $tx['posting'];

        return [
            'id' => $tx['id'],
            'reference' => $tx['reference'],
            'revisionNumber' => $tx['revisionNumber'],
            'description' => $tx['description'],
            'expenseDate' => $tx['expenseDate'],
            'amount' => [
                'amountMinor' => $tx['amount']->minorUnits(),
                'currency' => (string) $tx['amount']->currency(),
                'minorUnit' => $minorUnit,
            ],
            'finalOutcome' => $tx['finalOutcome'],
            'status' => $tx['status'],
            'decisions' => $this->decisionStages($tx['decisions']),
            'decisionHistory' => array_map(
                fn (array $d): array => $this->decision($d),
                $tx['decisionHistory']
            ),
            'evidence' => array_map(
                fn (array $e): array => $this->evidenceMetadata($e),
                $tx['evidence']
            ),
            'posting' => $posting !== null ? [
                'occurredAt' => $posting['occurredAt'] instanceof DateTimeInterface
                    ? $posting['occurredAt']->format(DateTimeInterface::ATOM)
                    : null,
                'amount' => [
                    'amountMinor' => $posting['amount']->minorUnits(),
                    'currency' => (string) $posting['amount']->currency(),
                    'minorUnit' => $minorUnit,
                ],
                'balanceAfter' => [
                    'amountMinor' => $posting['balanceAfter']->minorUnits(),
                    'currency' => (string) $posting['balanceAfter']->currency(),
                    'minorUnit' => $minorUnit,
                ],
            ] : null,
        ];
    }

    /**
     * One `FinanceDecisionStages`: latest decision per stage.
     *
     * @param array{costCenter: array<string, mixed>|null, senior: array<string, mixed>|null, finance: array<string, mixed>|null} $stages
     * @return array{costCenter: array<string, mixed>|null, senior: array<string, mixed>|null, finance: array<string, mixed>|null}
     */
    private function decisionStages(array $stages): array
    {
        return [
            'costCenter' => $stages['costCenter'] !== null ? $this->decision($stages['costCenter']) : null,
            'senior' => $stages['senior'] !== null ? $this->decision($stages['senior']) : null,
            'finance' => $stages['finance'] !== null ? $this->decision($stages['finance']) : null,
        ];
    }

    /**
     * One `Decision` schema entry.
     *
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function decision(array $d): array
    {
        return [
            'id' => $d['id'],
            'stage' => $d['stage'],
            'decision' => $d['decision'],
            'explanation' => $d['explanation'],
            'actor' => $d['actor'],
            'decidedAt' => $d['decidedAt'] instanceof DateTimeInterface
                ? $d['decidedAt']->format(DateTimeInterface::ATOM)
                : null,
        ];
    }

    /**
     * One `FinanceEvidenceMetadata`: metadata only, no content/URL/token.
     *
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function evidenceMetadata(array $e): array
    {
        return [
            'id' => $e['id'],
            'name' => $e['name'],
            'mediaType' => $e['mediaType'],
            'sizeBytes' => $e['sizeBytes'],
            'active' => $e['active'],
            'uploadedBy' => $e['uploadedBy'],
            'uploadedAt' => $e['uploadedAt'] instanceof DateTimeInterface
                ? $e['uploadedAt']->format(DateTimeInterface::ATOM)
                : null,
        ];
    }
}
