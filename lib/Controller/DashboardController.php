<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Reporting\DashboardQuery;
use OCA\PettyCash\Application\Reporting\ReportScope;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\SheetListFilter;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

/**
 * Serves `GET /api/v1/dashboard` for the `Dashboard` schema in `contracts/ocs-api.yaml`.
 *
 * ## Why this replaced `SessionController::dashboard()`
 *
 * The route already resolved, and `RouteResolutionTest` counted it as resolved — but the handler it
 * pointed at returned `['userId' => $uid]` for each held role and never called `DashboardQuery`. A
 * resolved route that returns role *stubs* is not a role-aware dashboard, so US7/AC1 was unbuilt while
 * the route-resolution guard reported it done. That guard can only see whether a method exists; it
 * cannot see whether the method does its job.
 *
 * The stub is gone rather than left as a second handler for the same path: two handlers behind one
 * contract path means one of them is unreachable and nobody can tell which. The approved contract is
 * untouched — same path, same five role-keyed objects.
 *
 * ## The union rule is key presence, not a merge
 *
 * US7/AC1's acceptance scenario is that a multi-role user sees the union of their roles' figures. A
 * user who is both purchaser and cost-centre manager gets both `purchaser` and `costCenter` objects,
 * so the union *is* the set of keys present. No new top-level field is invented for it, because the
 * contract's `Dashboard` declares exactly these five objects.
 *
 * ## Figures are bounded, and say so
 *
 * Every repository read here is paged — `findByOwnerUid(..., int $limit = 100)` and friends — and there
 * is no count query in the domain repositories. So a figure computed from a fetch is the size of the
 * window that was read, not an instance total. (The pre-existing
 * `ManagementReviewService::getCostCenterQueue()` returns `total = count()` of a paged read, so it has
 * the same property; that is its own task's concern, not this one's.)
 *
 * Rather than let a window size pose as a total, every card states the bound it was computed under
 * (`figuresBoundedBy`, `figuresTruncated`). The contract's role objects are
 * `additionalProperties: true`, so this is inside the declared surface — and a dashboard that says
 * "at least 100" is useful, while one that says "100" and means "however many I happened to fetch" is
 * worse than useless.
 */
class DashboardController extends OCSController
{
    /** The repository page size. Also the bound every figure is computed under. */
    private const BOUND = 100;

    public function __construct(
        \OCP\IRequest $request,
        private CurrentUser $currentUser,
        private DashboardQuery $dashboard,
        private ExpenseSheetRepository $sheets,
        private ExpenseTransactionRepository $transactions,
        private OcsErrorMapper $ocsErrorMapper,
        private \OCA\PettyCash\Domain\Account\Repository\AccountRepository $accounts
    ) {
        parent::__construct('pettycash', $request);
    }

    #[NoAdminRequired]
    public function index(): DataResponse
    {
        try {
            $uid = $this->currentUser->uid();
            if ($uid === '') {
                return $this->ocsErrorMapper->forbidden('No authenticated user in session');
            }

            $scope = $this->dashboard->scopeFor($uid);
            ['counts' => $counts, 'truncated' => $truncated] = $this->figures($scope);

            $cards = [];

            if ($scope->hasRole(Role::Purchaser)) {
                $cards['purchaser'] = $this->card(
                    $uid,
                    [
                        'openSheets' => $counts['openSheets'],
                        'returnedToMe' => $counts['returnedToMe'],
                    ],
                    ['openSheets' => $truncated['openSheets'], 'returnedToMe' => $truncated['returnedToMe']]
                );
            }

            if ($scope->hasRole(Role::CostCenterManager)) {
                $cards['costCenter'] = $this->card(
                    $uid,
                    // Only for cost centres this actor manages. A manager with no assignment gets 0
                    // rather than an instance-wide number, because the scope is the manager's
                    // assignments and nothing else.
                    ['awaitingReview' => $counts['awaitingCostCenterReview']],
                    ['awaitingReview' => $truncated['awaitingCostCenterReview']]
                );
            }

            if ($scope->hasRole(Role::SeniorManager)) {
                $cards['senior'] = $this->card(
                    $uid,
                    ['awaitingReview' => $counts['awaitingSeniorReview']],
                    ['awaitingReview' => $truncated['awaitingSeniorReview']]
                );
            }

            if ($scope->hasRole(Role::Finance)) {
                $cards['finance'] = $this->card(
                    $uid,
                    ['awaitingReview' => $counts['awaitingFinanceReview']],
                    ['awaitingReview' => $truncated['awaitingFinanceReview']]
                );
            }

            if ($scope->administrator) {
                // T379. All three figures are exact COUNTs, so this card is built with
                // `$exact` rather than a window: `figuresBoundedBy` is a claim about how a figure was
                // derived, and stating 100 beside a COUNT(*) would be untrue in exactly the way
                // `totalAccounts: 0` was. `figuresExact` says what is actually true.
                $cards['admin'] = $this->card(
                    $uid,
                    $this->dashboard->administrativeSummary(
                        $scope,
                        $counts['totalSheets'],
                        $counts['totalTransactions'],
                        $counts['totalAccounts']
                    ),
                    [],
                    exact: true
                );
            }

            return new DataResponse($cards);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * @param array<string, int> $figures
     * @param array<string, bool> $truncated
     * @return array<string, mixed>
     */
    private function card(string $uid, array $figures, array $truncated, bool $exact = false): array
    {
        return [
            'userId' => $uid,
            'figures' => $figures,
            // Stated rather than implied: these are window sizes, not instance totals -- except when
            // the figures are COUNTs, which have no window, where the envelope says so rather than
            // borrowing a bound it never had (T379).
            'figuresBoundedBy' => $exact ? null : self::BOUND,
            'figuresExact' => $exact,
            // Computed, not assumed. A read that returned exactly BOUND rows may have more behind it,
            // so `true` here means "at least this many" rather than "this many". Hardcoding `false`
            // would have been the more comfortable value and the untrue one.
            'figuresTruncated' => in_array(true, $truncated, true),
        ];
    }

    /**
     * Scoped figures, each computed from a bounded read and filtered by the actor's scope.
     *
     * A figure is truncated when the read that produced it came back full: the repository's window was
     * the limit, so the real number is at least this one.
     *
     * @return array{counts: array<string, int>, truncated: array<string, bool>}
     */
    private function figures(ReportScope $scope): array
    {
        $uid = $scope->actorUid;

        // Open sheets the actor may see. A purchaser's own are read by owner; an administrator's card
        // reads by status, because `coversOwned()` is true for everything they see and an owner read
        // would report only the sheets they personally opened.
        $openSheets = $scope->administrator
            ? $this->sheets->findByStatus(SheetStatus::Open, 0, self::BOUND)
            : $this->sheets->findByOwnerUid($uid, new SheetListFilter(SheetStatus::Open), 0, self::BOUND);
        $openSheetsInScope = array_values(array_filter(
            $openSheets,
            static fn ($sheet): bool => $scope->coversOwned($sheet->ownerUid)
        ));

        // Transactions handed back to this purchaser for correction.
        $returned = array_values(array_filter(
            $this->transactions->findByOwnerUid($uid, null, TransactionStatus::AwaitingPurchaserCorrection, 0, self::BOUND),
            static fn ($transaction): bool => $scope->coversOwned($transaction->ownerUid)
        ));

        // Review queues. Each is filtered by scope, so a cost-centre manager sees only their own
        // cost centres' transactions and a finance officer sees the finance queue they reached.
        $costCenterQueue = $this->stageScoped(
            $this->transactions->findByStageAndStatus(WorkflowStage::CostCenter, null, 0, self::BOUND),
            $scope
        );
        $seniorQueue = $this->stageScoped(
            $this->transactions->findByStageAndStatus(WorkflowStage::Senior, null, 0, self::BOUND),
            $scope
        );
        $financeQueue = $this->stageScoped(
            $this->transactions->findForFinanceQueue(0, self::BOUND),
            $scope
        );

        $counts = [
            'openSheets' => count($openSheetsInScope),
            'returnedToMe' => count($returned),
            'awaitingCostCenterReview' => count($costCenterQueue),
            'awaitingSeniorReview' => count($seniorQueue),
            'awaitingFinanceReview' => count($financeQueue),
            // T379: instance-wide COUNTs rather than the length of a filtered window.
            // `totalSheets` was `findByStatus(Open, ...)` and `totalTransactions` the finance queue,
            // so the administrator's card named two subsets as totals; `totalAccounts` had no source
            // at all and was passed as a literal 0.
            'totalSheets' => $this->sheets->countAll(),
            'totalTransactions' => $this->transactions->countAll(),
            'totalAccounts' => $this->accounts->countAll(),
        ];

        // Truncation is a property of the *read*, not of the scope filter, so it is judged before
        // filtering: a window that came back full was full whether or not the rows then survived scope.
        $truncated = [
            'openSheets' => count($openSheets) === self::BOUND,
            'returnedToMe' => count($returned) === self::BOUND,
            'awaitingCostCenterReview' => count($costCenterQueue) === self::BOUND,
            'awaitingSeniorReview' => count($seniorQueue) === self::BOUND,
            'awaitingFinanceReview' => count($financeQueue) === self::BOUND,
            // `totalSheets`, `totalTransactions` and `totalAccounts` are deliberately absent: a COUNT
            // cannot be truncated, because there is no window for one to fall out of. Restoring them
            // as `=== self::BOUND` would reintroduce the false claim T379 removed.
        ];

        return ['counts' => $counts, 'truncated' => $truncated];
    }

    /**
     * Keep only the transactions the actor's scope covers.
     *
     * A stage read is stage-wide, so the scope check is what makes the queue private. A senior manager
     * holding no senior role, or a cost-centre manager with no matching assignment, gets nothing here
     * rather than the whole stage.
     *
     * @param list<\OCA\PettyCash\Domain\Expense\ExpenseTransaction> $transactions
     * @return list<\OCA\PettyCash\Domain\Expense\ExpenseTransaction>
     */
    private function stageScoped(array $transactions, ReportScope $scope): array
    {
        return array_values(array_filter(
            $transactions,
            static fn ($transaction): bool => $scope->coversOwned($transaction->ownerUid)
        ));
    }
}
