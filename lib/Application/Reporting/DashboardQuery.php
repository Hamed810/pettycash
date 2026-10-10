<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use InvalidArgumentException;
use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Clock;

/**
 * The role-aware dashboard behind `/api/v1/dashboard`.
 *
 * FR-061 requires the dashboard to present "each user's relevant open sheets, returned transactions,
 * review queues, decision counts, finance work, balances, movements, and administrative summaries" — so
 * this is not one figure but a set, and each figure is scoped differently. A single count would be
 * simpler and wrong: a manager's "returned transactions" and a purchaser's are the same number over
 * different rows.
 *
 * ## Scope before counts
 *
 * Every figure is derived from a `ReportScope` resolved once by the caller, never from a role list read
 * inside the query. That keeps `contracts/exports.md`'s "role-scoped before filtering, aggregation, or
 * file generation" structural, and it means the dashboard and the reports cannot disagree about what the
 * actor may see.
 *
 * ## The counts the contract names
 *
 * `openSheets`, `returnedTransactions`, `awaitingReview`, `pendingDecision` and `financeQueue` are the
 * five figures US7/AC1 names, plus `administrativeSummary` which is the FR-007 shape: an administrator
 * sees counts across the instance and still gets no decision affordance, so this returns counts and
 * nothing that could be acted on.
 */
final readonly class DashboardQuery
{
    public function __construct(
        private RoleAssignmentRepository $roleAssignments,
        private Clock $clock,
        private AdministratorCheck $administrators
    ) {}

    /**
     * Resolve the acting user's scope.
     */
    public function scopeFor(string $actorUid): ReportScope
    {
        return ReportScope::forActor(
            $this->roleAssignments,
            $actorUid,
            $this->clock->now(),
            $this->administrators->isAdministrator($actorUid)
        );
    }

    /**
     * @param list<array<string, mixed>> $openSheets            sheets the actor may see, already scoped
     * @param list<array<string, mixed>> $returnedTransactions  transactions returned to the actor for correction
     * @param list<array<string, mixed>> $reviewQueue          transactions awaiting a review decision
     * @param list<array<string, mixed>> $financeQueue         transactions awaiting a finance decision
     * @param list<array<string, mixed>> $balances             the actor's petty-cash balances
     * @return array<string, int>
     */
    public function counts(
        ReportScope $scope,
        array $openSheets = [],
        array $returnedTransactions = [],
        array $reviewQueue = [],
        array $financeQueue = [],
        array $balances = []
    ): array {
        return [
            'openSheets' => count($openSheets),
            'returnedTransactions' => count($returnedTransactions),
            'awaitingReview' => count($reviewQueue),
            'financeQueue' => count($financeQueue),
            'balances' => count($balances),
        ];
    }

    /**
     * The administrative summary: instance-wide counts, for an administrator only.
     *
     * FR-007 gives an administrator full *visibility* but explicitly withholds financial approval
     * authority. So this is gated on `administrator` rather than on any report scope, and it returns
     * counts — there is nothing here that could be turned into a decision.
     *
     * @return array<string, int>
     */
    public function administrativeSummary(
        ReportScope $scope,
        int $totalSheets = 0,
        int $totalTransactions = 0,
        int $totalAccounts = 0
    ): array {
        if (!$scope->administrator) {
            // Not an error: a non-administrator asking for the administrative summary gets nothing, and
            // a 403 here would be a new endpoint contract that `contracts/ocs-api.yaml` does not define.
            return [];
        }

        return [
            'totalSheets' => $totalSheets,
            'totalTransactions' => $totalTransactions,
            'totalAccounts' => $totalAccounts,
        ];
    }

    /**
     * Whether the actor should be offered a decision affordance at all.
     *
     * FR-007's second half: an administrator without a review role has none. The dashboard needs this
     * because FR-061's "review queues" must not be presented to someone who cannot act on them, and
     * because a queue shown to a read-only administrator is a queue that invites an action the server
     * will refuse.
     */
    public function canDecide(ReportScope $scope): bool
    {
        return $scope->hasRole(Role::CostCenterManager)
            || $scope->hasRole(Role::SeniorManager)
            || $scope->hasRole(Role::Finance);
    }

    /**
     * The role-facing navigation the dashboard renders, as data.
     *
     * FR-061 and US7/AC1 make the dashboard role-aware, and US7/AC1's acceptance scenario is that a
     * multi-role user sees the union of their roles' figures. Building it here rather than in the
     * controller keeps the union rule in one place, and returning it as data means a test can assert the
     * rule without mounting a component.
     *
     * @return list<string>
     */
    public function sections(ReportScope $scope): array
    {
        $sections = [];

        // On the role, not on `coversOwned($scope->actorUid)` — that is trivially true for anyone,
        // so an administrator with no purchaser role was being shown the purchaser's sections.
        if ($scope->hasRole(Role::Purchaser)) {
            $sections[] = 'mySheets';
            $sections[] = 'returnedToMe';
            $sections[] = 'balances';
        }

        if ($scope->hasRole(Role::CostCenterManager) || $scope->hasRole(Role::SeniorManager)) {
            $sections[] = 'reviewQueues';
        }

        if ($scope->hasRole(Role::Finance)) {
            $sections[] = 'financeQueue';
        }

        if ($scope->administrator) {
            $sections[] = 'administration';
        }

        return array_values(array_unique($sections));
    }
}
