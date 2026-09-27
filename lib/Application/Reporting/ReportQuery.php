<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use InvalidArgumentException;
use OCA\PettyCash\Domain\Shared\Enum\Role;

/**
 * The role-scoped reports behind `/api/v1/reports/{reportType}`.
 *
 * ## Scope first, always
 *
 * `contracts/exports.md`: "Every report query is role-scoped **before** filtering, aggregation, or file
 * generation." Every method here takes a `ReportScope` first and applies it before any filter, and
 * `configurationAuditScope()` is named to make the order explicit. A report that filtered and then
 * scoped would return the right *count* for a subset of the wrong rows, which is the failure mode this
 * class exists to make impossible to express.
 *
 * ## The FR-055 configuration-audit rule, and what is not yet derivable
 *
 * `contracts/exports.md` and `plan.md` both fix the rule: **administrators see all; finance officers see
 * configuration audit for accounts they charge; cost-centre and senior managers see it for their
 * assigned cost centres.** The rule is implemented below and is fully testable.
 *
 * What is **not** implemented is resolving *which* accounts an officer has charged, and it is not
 * implemented deliberately:
 *
 * - `AccountMovement` records no actor, so the ledger cannot answer it.
 * - `AccountMovementRepository` has no by-actor read.
 * - `CommandResultRepository` exposes only `find($commandId)` and `save()`, so the
 *   `pettycash_command_results` row that *does* carry `actor_uid` and `operation` is reachable one row at
 *   a time and cannot be enumerated by actor.
 *
 * Inventing a `findByActorAndOperation()` port method here to serve one report would be a speculative
 * change to a port that `IdempotencyService` also depends on. Instead `configurationAuditScope()` takes
 * the charged account ids the caller already knows — the orchestration layer that performs charges can
 * supply them — and this report states plainly when it was given none. **A finance officer whose charged
 * accounts are not supplied sees no configuration audit**, which is the safe direction: an empty scope
 * must never widen into everything.
 */
final readonly class ReportQuery
{
    /**
     * The report types, **exactly** as `contracts/ocs-api.yaml` declares them at
     * `/reports/{reportType}`.
     *
     * Copied from the contract rather than named independently, because a report type is part of the OCS
     * surface: a client sends the string in the URL. An earlier version of this file used
     * `cost_centers` (underscore) and added `balances`, `movements` and `configuration_audit`, none of
     * which the contract accepts — so a request for a contract-valid `cost-centers` would have been
     * refused, and three invented types would have been advertised. The contract is the source of truth
     * for this list; see T112's note for the gap that leaves open.
     */
    public const TYPE_SHEETS = 'sheets';
    public const TYPE_TRANSACTIONS = 'transactions';
    public const TYPE_COST_CENTERS = 'cost-centers';
    public const TYPE_ACCOUNTS = 'accounts';
    public const TYPE_PROCESS = 'process';
    public const TYPE_EVIDENCE = 'evidence';
    public const TYPE_AUDIT = 'audit';

    public const SUPPORTED_TYPES = [
        self::TYPE_SHEETS,
        self::TYPE_TRANSACTIONS,
        self::TYPE_COST_CENTERS,
        self::TYPE_ACCOUNTS,
        self::TYPE_PROCESS,
        self::TYPE_EVIDENCE,
        self::TYPE_AUDIT,
    ];

    public function supports(string $reportType): bool
    {
        return in_array($reportType, self::SUPPORTED_TYPES, true);
    }

    /**
     * @throws InvalidArgumentException for a report type the contract does not declare
     */
    public function assertSupported(string $reportType): void
    {
        if (!$this->supports($reportType)) {
            throw new InvalidArgumentException(
                "Unsupported report type '{$reportType}'; expected one of "
                . implode(', ', self::SUPPORTED_TYPES)
            );
        }
    }

    /**
     * Narrow a candidate row set to the actor's scope, then apply the filter.
     *
     * The order is the point, and it is why this is one method rather than two: a caller cannot scope
     * and filter in the wrong order, because there is only one order here.
     *
     * @param list<array<string, mixed>> $candidates
     * @param callable(array<string, mixed>, ReportScope): bool $inScope
     * @param list<string> $columns the report's column names, in order
     * @param array<string, mixed> $summary free-form totals and counts, per the contract's `ReportPage`
     * @return array{page: int, pageSize: int, total: int, columns: list<string>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function paginate(
        ReportScope $scope,
        ReportFilter $filter,
        array $candidates,
        callable $inScope,
        array $columns = [],
        array $summary = []
    ): array {
        $scoped = array_values(array_filter($candidates, static fn (array $row): bool => $inScope($row, $scope)));
        $filtered = $this->applyFilter($scoped, $filter);

        // Shaped as the contract's `ReportPage`: `PageMeta` (page, pageSize, total) plus columns, rows
        // and summary. Returning `items`/`offset` instead would have been a second pagination shape for a
        // contract that already declares one, and a client written against `rows` would have found nothing.
        return [
            'page' => $filter->page,
            'pageSize' => $filter->pageSize,
            'total' => count($filtered),
            'columns' => $columns,
            'rows' => array_slice($filtered, $filter->offset, $filter->pageSize),
            'summary' => $summary,
        ];
    }

    /**
     * The FR-055 / FR-056 configuration-audit visibility decision.
     *
     * @param list<int> $chargedAccountDbIds accounts the officer has charged, when the caller knows them
     * @return array{
     *     visible: bool,
     *     reason: string,
     *     costCenterDbIds: list<int>,
     *     accountDbIds: list<int>,
     *     subjectType: string
     * }
     */
    public function configurationAuditScope(
        ReportScope $scope,
        array $chargedAccountDbIds = []
    ): array {
        // Administrators see all configuration audit, and the rule is stated as "all" rather than as a
        // union of the narrower cases so that widening it later is a visible change.
        if ($scope->administrator) {
            return [
                'visible' => true,
                'reason' => 'FR-055: system administrators see all configuration audit events',
                'costCenterDbIds' => [],
                'accountDbIds' => [],
                'subjectType' => '',
            ];
        }

        // Accumulated rather than returned early, because FR-002/FR-008 make a multi-role actor hold the
        // **union** of their scopes. An earlier version returned on the first matching branch, so a user who
        // was both a manager and a finance officer silently lost the cost-centre half of their visibility.
        $costCenterDbIds = [];
        $accountDbIds = [];
        $reasons = [];
        $isFinance = $scope->hasRole(Role::Finance);
        $isManager = $scope->hasRole(Role::CostCenterManager) || $scope->hasRole(Role::SeniorManager);

        // Managers see configuration audit for their assigned cost centres.
        if ($isManager) {
            if ($scope->costCenterDbIds === []) {
                $reasons[] = 'FR-055: this manager has no assigned cost centre';
            } else {
                $costCenterDbIds = $scope->costCenterDbIds;
                $reasons[] = 'FR-055: cost-centre and senior managers see configuration audit for their '
                    . 'assigned cost centres';
            }
        }

        // FR-016: every finance officer shares one queue, so a charge is an instance-wide fact and any
        // officer may read configuration audit for accounts that have been charged at all. When the caller
        // supplies no charged accounts the scope is empty, not "everything" — see the class docblock.
        if ($isFinance) {
            if ($chargedAccountDbIds === []) {
                $reasons[] = 'FR-055: this officer has no charged accounts in scope, so an empty scope '
                    . 'must not widen to all';
            } else {
                $accountDbIds = array_values(array_unique($chargedAccountDbIds));
                $reasons[] = 'FR-055: finance officers see configuration audit for accounts they charge';
            }
        }

        $visible = $costCenterDbIds !== [] || $accountDbIds !== [];

        if ($visible) {
            $subjectType = $costCenterDbIds !== [] && $accountDbIds !== []
                ? 'cost_center_or_account'
                : ($costCenterDbIds !== [] ? 'cost_center' : 'account');
        } else {
            $subjectType = '';
        }

        if ($reasons === []) {
            $reasons[] = 'FR-055: configuration audit is visible to administrators, finance officers for '
                . 'accounts they charge, and managers for their assigned cost centres';
        }

        return [
            'visible' => $visible,
            'reason' => implode('; ', $reasons),
            'costCenterDbIds' => $costCenterDbIds,
            'accountDbIds' => $accountDbIds,
            'subjectType' => $subjectType,
        ];
    }

    /**
     * Whether a transaction-audit row is in scope.
     *
     * FR-055's first half: "audit visibility and export MUST follow the same role and scope restrictions
     * as the related transaction". So this defers to `ReportScope::coversOwned()` with the transaction's
     * finance-stage state, rather than re-deriving a rule that already exists.
     *
     * @param array<string, mixed> $row
     */
    public function transactionAuditInScope(ReportScope $scope, array $row): bool
    {
        $owner = (string) ($row['ownerUid'] ?? '');
        $reachedFinance = (bool) ($row['reachedFinanceStage'] ?? false);

        return $owner !== '' && $scope->coversOwned($owner, $reachedFinance);
    }

    /**
     * FR-056: the two audit streams must not return each other's events.
     *
     * A configuration event is identified by its subject type and a `CONFIGURATION` stage; a transaction
     * event by a transaction subject. The rule is expressed as a predicate rather than two separate
     * queries so that a report cannot ask the wrong one and quietly return the other stream.
     *
     * @param array<string, mixed> $row
     */
    public function isConfigurationEvent(array $row): bool
    {
        $stage = (string) ($row['stage'] ?? '');

        return $stage === 'CONFIGURATION' || ($row['entityType'] ?? '') === 'configuration';
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isTransactionEvent(array $row): bool
    {
        return !$this->isConfigurationEvent($row);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    /**
     * Which row key each `ReportFilter` field reads, in order of preference.
     *
     * This map is the single place that defines the report row vocabulary a filter is evaluated
     * against. It exists because the rule and its producers had drifted apart: the filter read
     * `currencyCode` and `actorUid` while sheets and transactions emitted `currency` and `ownerUid`,
     * so those filters compared an absent key and dropped every row while returning 200 (T307).
     *
     * Several entries list more than one key because the underlying concepts genuinely differ by
     * subject and both are correct in their own place: a sheet has an *owner*, an audit event has an
     * *actor*, and a filter on `actorUids` should mean whichever the report actually carries. The
     * list is "any of these, first present wins" -- not a rename forced onto one word.
     *
     * @var array<string, list<string>>
     */
    private const FILTER_FIELD_KEYS = [
        'statuses' => ['status'],
        'currencyCodes' => ['currency', 'currencyCode'],
        'actorUids' => ['ownerUid', 'actorUid'],
        'eventTypes' => ['eventType'],
        'costCenterDbIds' => ['costCenterDbId'],
        'createdBy' => ['createdBy'],
        'dateRange' => ['occurredAt', 'createdAt'],
    ];

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function applyFilter(array $rows, ReportFilter $filter): array
    {
        if (!$filter->hasFieldFilters()) {
            return $rows;
        }

        // Refuse a filter the rows cannot answer *before* comparing anything, so a vocabulary mismatch
        // surfaces as a 422 naming the field rather than as a confident empty page.
        $this->assertFilterIsEvaluable($rows, $filter);

        return array_values(array_filter(
            $rows,
            function (array $row) use ($filter): bool {
                foreach (self::FILTER_FIELD_KEYS as $field => $keys) {
                    if (!$this->filterRequests($filter, $field)) {
                        continue;
                    }

                    $key = $this->presentKey($row, $keys);
                    if ($key === null) {
                        // The row lacks the field this filter needs. `assertFilterIsEvaluable()`
                        // guarantees at least one row carries it, so this is a genuinely partial row
                        // set rather than a vocabulary mismatch; excluding it is the honest reading.
                        return false;
                    }

                    if (!$this->rowMatches($row[$key], $filter, $field)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * Whether the caller actually asked for this filter.
     */
    private function filterRequests(ReportFilter $filter, string $field): bool
    {
        return match ($field) {
            'statuses' => $filter->statuses !== [],
            'currencyCodes' => $filter->currencyCodes !== [],
            'actorUids' => $filter->actorUids !== [],
            'eventTypes' => $filter->eventTypes !== [],
            'costCenterDbIds' => $filter->costCenterDbIds !== [],
            'createdBy' => $filter->createdBy !== null,
            'dateRange' => $filter->hasDateRange(),
            default => false,
        };
    }

    /**
     * Compare one row value against the requested values for that filter.
     */
    private function rowMatches(mixed $value, ReportFilter $filter, string $field): bool
    {
        if ($field === 'dateRange') {
            $moment = $value instanceof \DateTimeInterface
                ? $value
                : new \DateTimeImmutable((string) $value);

            if ($filter->from !== null && $moment < $filter->from) {
                return false;
            }
            if ($filter->to !== null && $moment > $filter->to) {
                return false;
            }

            return true;
        }

        $actual = match ($field) {
            'statuses' => (string) $value,
            'currencyCodes' => (string) $value,
            'actorUids' => (string) $value,
            'eventTypes' => (string) $value,
            'costCenterDbIds' => (int) $value,
            'createdBy' => (string) $value,
            default => (string) $value,
        };

        $requested = match ($field) {
            'statuses' => $filter->statuses,
            'currencyCodes' => $filter->currencyCodes,
            'actorUids' => $filter->actorUids,
            'eventTypes' => $filter->eventTypes,
            'costCenterDbIds' => $filter->costCenterDbIds,
            'createdBy' => [$filter->createdBy],
            default => [],
        };

        return in_array($actual, array_map(strval(...), $requested), true)
            || ($field === 'costCenterDbIds' && in_array($actual, $requested, true));
    }

    /**
     * The first of `$keys` the row actually carries.
     *
     * @param list<string> $keys
     */
    private function presentKey(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Refuse any requested filter that no row in the set can answer.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function assertFilterIsEvaluable(array $rows, ReportFilter $filter): void
    {
        if ($rows === []) {
            // Nothing to compare against, so nothing is being asserted about the data. Refusing here
            // would make a filter unusable on an empty report, which is not a wrong answer.
            return;
        }

        $available = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $available[$key] = true;
            }
        }

        foreach (self::FILTER_FIELD_KEYS as $field => $keys) {
            if (!$this->filterRequests($filter, $field)) {
                continue;
            }

            foreach ($keys as $key) {
                if (isset($available[$key])) {
                    continue 2;
                }
            }

            throw new UnsupportedFilterField(sprintf(
                'This report cannot be filtered by `%s`: its rows carry %s. Filtering anyway would '
                . 'match nothing and report an empty result, so the request is refused instead.',
                $field,
                $available === [] ? 'no fields' : '`' . implode('`, `', array_keys($available)) . '`'
            ));
        }
    }
}
