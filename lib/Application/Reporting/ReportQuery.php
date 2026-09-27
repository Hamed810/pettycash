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
    /** The report types this query understands, as declared by `contracts/ocs-api.yaml`. */
    public const TYPE_SHEETS = 'sheets';
    public const TYPE_TRANSACTIONS = 'transactions';
    public const TYPE_COST_CENTERS = 'cost_centers';
    public const TYPE_BALANCES = 'balances';
    public const TYPE_MOVEMENTS = 'movements';
    public const TYPE_PROCESS = 'process';
    public const TYPE_EVIDENCE = 'evidence';
    public const TYPE_AUDIT = 'audit';
    public const TYPE_CONFIGURATION_AUDIT = 'configuration_audit';

    public const SUPPORTED_TYPES = [
        self::TYPE_SHEETS,
        self::TYPE_TRANSACTIONS,
        self::TYPE_COST_CENTERS,
        self::TYPE_BALANCES,
        self::TYPE_MOVEMENTS,
        self::TYPE_PROCESS,
        self::TYPE_EVIDENCE,
        self::TYPE_AUDIT,
        self::TYPE_CONFIGURATION_AUDIT,
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
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int, offset: int}
     */
    public function paginate(
        ReportScope $scope,
        ReportFilter $filter,
        array $candidates,
        callable $inScope
    ): array {
        $scoped = array_values(array_filter($candidates, static fn (array $row): bool => $inScope($row, $scope)));
        $filtered = $this->applyFilter($scoped, $filter);

        return [
            'items' => array_slice($filtered, $filter->offset, $filter->pageSize),
            'total' => count($filtered),
            'page' => $filter->page,
            'pageSize' => $filter->pageSize,
            'offset' => $filter->offset,
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
    private function applyFilter(array $rows, ReportFilter $filter): array
    {
        if (!$filter->hasFieldFilters()) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            function (array $row) use ($filter): bool {
                if ($filter->statuses !== [] && !in_array((string) ($row['status'] ?? ''), $filter->statuses, true)) {
                    return false;
                }
                if ($filter->currencyCodes !== [] && !in_array((string) ($row['currencyCode'] ?? ''), $filter->currencyCodes, true)) {
                    return false;
                }
                if ($filter->costCenterDbIds !== [] && !in_array((int) ($row['costCenterDbId'] ?? 0), $filter->costCenterDbIds, true)) {
                    return false;
                }
                if ($filter->actorUids !== [] && !in_array((string) ($row['actorUid'] ?? ''), $filter->actorUids, true)) {
                    return false;
                }
                if ($filter->eventTypes !== [] && !in_array((string) ($row['eventType'] ?? ''), $filter->eventTypes, true)) {
                    return false;
                }
                if ($filter->createdBy !== null && (string) ($row['createdBy'] ?? '') !== $filter->createdBy) {
                    return false;
                }
                if ($filter->from !== null && !isset($row['occurredAt']) && !isset($row['createdAt'])) {
                    return false;
                }

                $timestamp = $row['occurredAt'] ?? $row['createdAt'] ?? null;
                if ($timestamp !== null) {
                    $moment = $timestamp instanceof \DateTimeInterface ? $timestamp : new \DateTimeImmutable((string) $timestamp);
                    if ($filter->from !== null && $moment < $filter->from) {
                        return false;
                    }
                    if ($filter->to !== null && $moment > $filter->to) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }
}
