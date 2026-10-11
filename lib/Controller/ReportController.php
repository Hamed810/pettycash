<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use InvalidArgumentException;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Reporting\DashboardQuery;
use OCA\PettyCash\Application\Reporting\ReportFilter;
use OCA\PettyCash\Application\Reporting\ReportFilterFactory;
use OCA\PettyCash\Application\Reporting\AuditSelectionQuery;
use OCA\PettyCash\Application\Reporting\EvidenceSelectionQuery;
use OCA\PettyCash\Application\Reporting\ReportQuery;
use OCA\PettyCash\Application\Reporting\ReportProjection;
use OCA\PettyCash\Application\Reporting\ChargedAccountScope;
use OCA\PettyCash\Application\Account\AccountPayloadFormatter;
use OCA\PettyCash\Application\Reporting\ReportScope;
use OCA\PettyCash\Application\Reporting\UnsupportedFilterField;
use OCA\PettyCash\Infrastructure\Nextcloud\TechnicalLogger;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

/**
 * Serves `GET /api/v1/reports/{reportType}` for the `ReportPage` schema in `contracts/ocs-api.yaml`.
 *
 * ## The enum is the contract's, not a convenient one
 *
 * `contracts/ocs-api.yaml` declares exactly `[sheets, transactions, cost-centers, accounts, process,
 * evidence, audit]`. `ReportQuery::SUPPORTED_TYPES` is asserted against that list as data, so a report
 * type is a contract fact rather than a naming preference, and an undeclared one is refused here with
 * a 422 that names the contract's set.
 *
 * FR-065 also names petty-cash *balances* and *movements* as report subjects, and FR-056 requires
 * configuration audit to be visible, and **none of the three is in the enum**. This controller does not
 * invent types to cover them. The gap is recorded in `tasks.md` (T112) because the choice belongs to the
 * contract: either extend the enum, or record that these are reachable through `accounts` and under what
 * filter. FR-065's movements *are* reachable here, through the `accounts` type's own rows, because a
 * movement is an attribute of an account; what is not reachable is any *type* named `balances`.
 *
 * **That sentence became true only in T368, and the way it was untrue is worth recording.** The
 * `accounts` row carried `id`, `currency`, `userUid`, `balanceMinor` and `active` and nothing else,
 * so "reachable through the `accounts` type's own rows" was true of the separate
 * `GET /accounts/{id}/movements` endpoint and false of this report. `accountsReport()` now nests each
 * account's movements on its row, bounded and flagged — see its own docblock for why the bound is
 * reported rather than implied.
 *
 * ## Scope is applied by the query, not by the source
 *
 * Every branch hands raw candidates plus an `$inScope` predicate to `ReportQuery::paginate()`, which
 * scopes first and filters second. The controller never pre-filters: doing so here would reintroduce
 * the ordering hazard T106 removed, and there would then be two places where scope is decided.
 *
 * ## Accounts are the caller's own, and that is deliberate
 *
 * There is no instance-wide account read in the domain, and FR-055's "accounts they charge" is not
 * derivable from the schema (T299). Rather than widen the report to every account, or invent a
 * repository method, this returns the acting user's own accounts -- the one set that is provably theirs
 * -- and leaves the charged-accounts question to T299. A report that guessed here would be the exact
 * FR-060 leak the read paths exist to prevent.
 */
class ReportController extends OCSController
{
    /** Bounded reads, as in `DashboardController`: these are window sizes, not instance totals. */
    private const BOUND = 100;

    /**
     * How many movements one `accounts` row carries (T368).
     *
     * FR-065 asks each account row to carry "that account's balance and its movements", which is
     * unbounded in principle -- an account's ledger grows for as long as the account is used. The
     * report therefore cannot promise the whole ledger, and the honest thing is to bound it and say
     * so: a row reports `movementsTruncated` so a caller can tell a complete ledger from a partial
     * one, and the response summary carries the bound itself.
     *
     * Deliberately not `ReportFilter::MAX_PAGE_SIZE`, which bounds *rows* rather than movements, and
     * applied per row rather than across the page so one busy account cannot consume the whole
     * response.
     */
    private const MOVEMENT_READ_BOUND = 200;

    /**
     * The filter rule itself lives in `ReportFilterFactory`, not here.
     *
     * It used to be a private constant and a private method on this controller, and it moved because
     * `POST /exports` needs the identical rule for `ExportRequest.filters`. A filter name that meant
     * one thing here and another there is the T307 shape again — two definitions, one of them
     * unreachable by the endpoint that was supposed to share it — so the vocabulary, the coercion and
     * the refusal are one class that both controllers call. This controller keeps only what is
     * genuinely its own: the *encoding* of `filters` on this endpoint, which is a query parameter and
     * therefore arrives as a JSON string.
     */
    public function __construct(
        \OCP\IRequest $request,
        private CurrentUser $currentUser,
        private DashboardQuery $dashboard,
        private ReportQuery $reports,
        private ReportFilterFactory $filters,
        private ExpenseSheetRepository $sheets,
        private ExpenseTransactionRepository $transactions,
        private CostCenterRepository $costCenters,
        private AccountRepository $accounts,
        private EvidenceRepository $evidence,
        private AuditEventRepository $auditEvents,
        private EvidenceSelectionQuery $evidenceSelection,
        private AuditSelectionQuery $auditSelection,
        private ChargedAccountScope $chargedAccounts,
        private AccountMovementRepository $accountMovements,
        private AccountPayloadFormatter $accountPayload,
        private TechnicalLogger $logger,
        private OcsErrorMapper $ocsErrorMapper,
        private Clock $clock
    ) {
        parent::__construct('pettycash', $request);
    }

    #[NoAdminRequired]
    public function show(
        string $reportType,
        string $filters = '',
        int $page = 1,
        int $pageSize = ReportFilter::DEFAULT_PAGE_SIZE
    ): DataResponse {
        try {
            $uid = $this->currentUser->uid();
            if ($uid === '') {
                return $this->ocsErrorMapper->forbidden('No authenticated user in session');
            }

            if (!$this->reports->supports($reportType)) {
                return $this->ocsErrorMapper->validation(
                    "Unsupported report type '{$reportType}'; contracts/ocs-api.yaml declares "
                    . implode(', ', ReportQuery::SUPPORTED_TYPES)
                );
            }

            $scope = $this->dashboard->scopeFor($uid);
            $filter = $this->filterFrom($page, $pageSize, $filters);

            [$candidates, $inScope, $columns, $summary] = $this->candidatesFor($reportType, $scope, $uid);

            // FR-068: a response that resolved a predefined period states the exact range it resolved
            // and the zone it resolved it in, so two users in different zones can tell a different
            // period from a different timezone. The disclosure is built in `ReportFilter` rather than
            // here so `POST /exports` states it identically — two copies is how the two endpoints drift.
            $summary = array_merge($summary, $filter->periodDisclosure());

            return new DataResponse($this->reports->paginate(
                $scope,
                $filter,
                $candidates,
                $inScope,
                $columns,
                $summary
            ));
        } catch (UnsupportedFilterField $e) {
            // A filter this report cannot answer is a client error, and the contract's response for
            // `POST /exports` shows 422/ValidationError is the shape this API uses for "your request
            // cannot be served as asked". Answering 200 with an empty page instead is the T307 defect.
            return $this->ocsErrorMapper->validation($e->getMessage());
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * The `filters` query parameter, decoded as JSON and handed to `ReportFilterFactory`.
     *
     * The contract types it as a string, "encoded as query parameters by the client", which leaves the
     * encoding to the client. JSON is the one encoding a bare string can carry unambiguously, and a
     * malformed value is a client error rather than something to silently ignore -- an ignored filter
     * would return *more* rows than the caller asked for while looking like a filtered result.
     *
     * Only the decoding is here. What the filter *means* -- which names exist, what shape each value
     * has, what to do with a name that is not in the vocabulary -- belongs to the factory, which the
     * export endpoint asks as well. Measured before T306: this method read eight keys and discarded
     * every other key in the object without comment, so `{"stages":["FINANCE"]}`, `{"banana":true}` and
     * the typo `{"costcentreIds":[1]}` each returned the whole report and nothing in the response said
     * so.
     */
    private function filterFrom(int $page, int $pageSize, string $filters): ReportFilter
    {
        if ($filters === '') {
            return new ReportFilter($page, $pageSize);
        }

        $decoded = json_decode($filters, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException(
                'The `filters` parameter must be a JSON object; got ' . $this->describe($filters)
            );
        }

        return $this->filters->fromArray($decoded, $page, $pageSize);
    }

    /**
     * @return array{list<array<string, mixed>>, callable(array<string, mixed>, ReportScope): bool, list<string>, array<string, mixed>}
     */
    private function candidatesFor(string $reportType, ReportScope $scope, string $uid): array
    {
        return match ($reportType) {
            ReportQuery::TYPE_SHEETS => $this->sheetsReport($scope, $uid),
            ReportQuery::TYPE_TRANSACTIONS => $this->transactionsReport($scope),
            ReportQuery::TYPE_COST_CENTERS => $this->costCentersReport($scope),
            ReportQuery::TYPE_ACCOUNTS => $this->accountsReport($scope, $uid),
            ReportQuery::TYPE_PROCESS => $this->processReport(),
            ReportQuery::TYPE_EVIDENCE => $this->evidenceReport($scope, $uid),
            ReportQuery::TYPE_AUDIT => $this->auditReport($scope, $uid),
            // Unreachable: `supports()` above already refused anything else.
            default => throw new InvalidArgumentException("Unsupported report type '{$reportType}'"),
        };
    }

    /**
     * @return array{list<array<string, mixed>>, callable, list<string>, array<string, mixed>}
     */
    private function sheetsReport(ReportScope $scope, string $uid): array
    {
        $rows = array_map(
            static fn ($sheet): array => ReportProjection::sheetRow($sheet),
            $scope->administrator
                ? $this->sheets->findByStatus(\OCA\PettyCash\Domain\Workflow\SheetStatus::Open, 0, self::BOUND)
                : $this->sheets->findByOwnerUid($uid, null, 0, self::BOUND)
        );

        return [
            $rows,
            // Ownership is the scope test. `coversOwned()` is what grants finance visibility of a sheet
            // that reached their stage, so it is the single definition and is not restated here.
            static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) ($row['ownerUid'] ?? '')),
            ReportProjection::SHEET_BASE_COLUMNS,
            ['rowsRead' => count($rows), 'rowsBoundedBy' => self::BOUND],
        ];
    }

    /**
     * @return array{list<array<string, mixed>>, callable, list<string>, array<string, mixed>}
     */
    private function transactionsReport(ReportScope $scope): array
    {
        $candidates = $scope->administrator
            ? $this->transactions->findByStageAndStatus(\OCA\PettyCash\Domain\Workflow\WorkflowStage::Final, null, 0, self::BOUND)
            : $this->transactions->findByOwnerUid($scope->actorUid, null, null, 0, self::BOUND);

        $rows = array_map(
            // FR-064 fixes the source of each transaction facet: "a transaction's **cost center** is
            // the one recorded on that transaction's own active revision -- not the sheet's, because a
            // sheet may hold transactions for several cost centres (FR-028)", and its "expense type"
            // and "amount" also come from the active revision. The builder lives in
            // ReportProjection so the export cannot hold a second idea of the row.
            static fn ($transaction): array => ReportProjection::transactionRow($transaction),
            $candidates
        );

        return [
            $rows,
            static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) ($row['ownerUid'] ?? '')),
            ReportProjection::TRANSACTION_COLUMNS,
            ['rowsRead' => count($rows), 'rowsBoundedBy' => self::BOUND],
        ];
    }

    /**
     * @return array{list<array<string, mixed>>, callable, list<string>, array<string, mixed>}
     */
    private function costCentersReport(ReportScope $scope): array
    {
        $rows = array_map(
            static fn ($costCenter): array => [
                'id' => $costCenter->id,
                'code' => $costCenter->code(),
                'name' => $costCenter->name,
            ],
            $this->costCenters->findAllActiveOn($this->clock->now())
        );

        return [
            $rows,
            // A cost-centre row is in scope when the actor's scope covers it. An actor with no
            // assignments -- a plain purchaser -- covers none, and so sees none. Configuration is not
            // secret, but a report that listed every centre to everyone would not be role-scoped, and
            // the contract's whole point for reports is that it is.
            static fn (array $row, ReportScope $s): bool => $s->administrator
                || $s->costCenterDbIds !== []
                || $s->hasRole(\OCA\PettyCash\Domain\Shared\Enum\Role::SeniorManager),
            ['id', 'code', 'name'],
            ['rowsRead' => count($rows)],
        ];
    }

    /**
     * Accounts, scoped by FR-055 and FR-016.
     *
     * The rule the clarification settled: an actor sees their own accounts; a finance officer
     * additionally sees the accounts that have been charged; an administrator sees all.
     *
     * "Charged at all" rather than "charged by this officer", because FR-016 puts every finance
     * officer on one shared queue — a charge is a fact about the instance, and an officer who cannot
     * see an account another officer charged cannot work that account's queue. `ChargedAccountScope`
     * answers that instance-wide question; nothing here narrows it to the caller.
     *
     * A non-finance actor is left with their own accounts, which is what this returned before T299
     * and is still correct for them. The change is that a finance officer no longer sees strictly
     * fewer accounts than the rule allows.
     *
     * ## Why each row carries its movements
     *
     * FR-065: petty-cash balances and movements are "reachable as data of the `accounts` report --
     * one account per row, carrying that account's balance **and its movements**." The 2026-09-28
     * clarification settled the shape question against a separate report type, so the movements have
     * nowhere else to live: a nested array on the row is FR-065's wording read literally, and it is
     * the shape a client needs to render one account with its history without a second request per
     * row.
     *
     * The alternative this rejected -- a `movementCount` beside the balance, with the ledger reached
     * through `GET /accounts/{id}/movements` -- is cheaper and is **not** what the requirement says.
     * It also had a concrete failure behind it: this class's own docblock claimed the movements were
     * "reachable through the `accounts` type's own rows" while the row carried no movements at all,
     * so the claim was true of the endpoint and false of the report.
     *
     * `ReportPage.rows` is `additionalProperties: true` in `contracts/ocs-api.yaml`, so no contract
     * amendment is needed and none is claimed.
     *
     * ## What the movement read costs, stated rather than hidden
     *
     * `AccountMovementRepository` has no bulk read, so this is one paged read per candidate account --
     * an N+1, deliberately rather than accidentally. `SheetPayloadFormatter` is the precedent for
     * what to do when that stops being acceptable: it needed the same shape (a range per sheet) and
     * `SheetSummaryQuery::expenseDateRanges()` exists specifically to stop a page of sheets becoming
     * a page of queries. If this needs the same treatment, the hoist belongs in a query class beside
     * the other report queries, not here.
     *
     * The read is bounded per account and the bound is **stated in the response**: `movementsTruncated`
     * is true when an account had more history than the bound, so a caller can never mistake a
     * bounded page for a complete ledger. That matters more here than elsewhere, because the
     * alternative failure -- a partial ledger shown next to a balance reflecting all of it -- reads as
     * an accounting error.
     *
     * @return array{list<array<string, mixed>>, callable(array<string, mixed>, ReportScope): bool, list<string>, array<string, mixed>}
     */
    private function accountsReport(ReportScope $scope, string $uid): array
    {
        $own = $this->accounts->findActiveByUserUid($uid);

        $charged = $scope->hasRole(\OCA\PettyCash\Domain\Shared\Enum\Role::Finance)
            ? $this->chargedAccounts->chargedAccountDbIds()
            : [];

        // An administrator already sees the instance, so the charged lookup would only add a query
        // whose answer is a subset of what they are about to be given.
        $includeCharged = !$scope->administrator && $charged !== [];

        $candidates = $includeCharged
            ? array_merge($own, $this->accountsByDbIds($charged, $own))
            : $own;

        // Read once per candidate before the rows are built, so each row's movements and its balance
        // describe the same instant.
        $movementsByAccount = [];
        foreach ($candidates as $account) {
            $movementsByAccount[$account->id] = $this->movementsFor($account);
        }

        $rows = array_map(
            static fn ($account): array => [
                'id' => $account->id,
                'currency' => (string) $account->currencyCode(),
                'userUid' => $account->userUid,
                'balanceMinor' => $account->balanceMinor,
                'active' => $account->active,
                // FR-065: the account's movements as data of this row. The `Movement` shape is the
                // contract's own, produced by the same formatter `GET /accounts/{id}/movements` uses,
                // so a movement cannot read differently depending on which endpoint served it -- the
                // `AccountPayloadFormatter` docblock records having been bitten by exactly that.
                'movements' => $movementsByAccount[$account->id]['movements'],
                'movementsTruncated' => $movementsByAccount[$account->id]['truncated'],
            ],
            $candidates
        );

        $ownRowIds = array_map(static fn ($a): string => $a->id, $own);
        $chargedRowIds = $includeCharged
            ? array_values(array_diff(array_map(static fn ($a): string => $a->id, $candidates), $ownRowIds))
            : [];

        return [
            $rows,
            // Scope is applied as row membership, decided above from the roles, rather than by a
            // per-row predicate that would have to re-derive the same rule for every row. The
            // predicate is kept because `paginate()` takes one, and it asserts the decision rather
            // than re-making it.
            static function (array $row) use ($ownRowIds, $chargedRowIds): bool {
                $id = (string) ($row['id'] ?? '');
                return in_array($id, $ownRowIds, true) || in_array($id, $chargedRowIds, true);
            },
            ['id', 'currency', 'userUid', 'balanceMinor', 'active', 'movements', 'movementsTruncated'],
            // Stated in the response rather than only in a task note, because a caller reading
            // `accounts` deserves to know which rule produced the set. The `t299: pending` marker
            // that stood here said the scope was unresolved; it is resolved and the marker is gone,
            // and a caller can no longer mistake a resolved rule for a pending one.
            [
                'scope' => $scope->administrator
                    ? 'all_accounts'
                    : ($includeCharged ? 'own_and_charged_accounts' : 'own_accounts'),
                'chargedAccountsIncluded' => $includeCharged,
                'chargedAccountCount' => count($chargedRowIds),
                // The bound the per-account movement read applied, so the per-row truncation flag has
                // a stated meaning rather than being an unexplained boolean.
                'movementReadBound' => self::MOVEMENT_READ_BOUND,
            ],
        ];
    }

    /**
     * One account's movements, and whether the bound cut the ledger short.
     *
     * Scoped by the account's own surrogate key, which is the property that keeps a movement
     * belonging to another account off this row: a per-account query cannot return one. That is why
     * nothing filters after the read -- filtering here would be asserting the absence of a leak the
     * query was never able to produce.
     *
     * `MOVEMENT_READ_BOUND + 1` rows are requested rather than `MOVEMENT_READ_BOUND`, because the only
     * way to distinguish "exactly at the bound" from "truncated at the bound" is to see one row more
     * than the bound allows. Asking for exactly the bound and calling the result complete would make a
     * ledger of precisely the bound length indistinguishable from one that continues.
     *
     * An account with no surrogate key has no movements to read. A persisted account always has one,
     * so this serves only an aggregate built in memory, and an empty ledger is honest for it.
     *
     * @return array{movements: list<array<string, mixed>>, truncated: bool}
     */
    private function movementsFor(\OCA\PettyCash\Domain\Account\PettyCashAccount $account): array
    {
        if ($account->dbId === null) {
            return ['movements' => [], 'truncated' => false];
        }

        $found = $this->accountMovements->findByAccountIdPaged(
            $account->dbId,
            0,
            self::MOVEMENT_READ_BOUND + 1
        );

        $truncated = count($found) > self::MOVEMENT_READ_BOUND;

        return [
            'movements' => array_map(
                fn ($movement): array => $this->accountPayload->movement($movement, $account),
                $truncated ? array_slice($found, 0, self::MOVEMENT_READ_BOUND) : $found
            ),
            'truncated' => $truncated,
        ];
    }

    /**
     * The accounts named by surrogate keys, minus the ones already in hand.
     *
     * Deduplicated here because an account can be both the caller's own and charged, and a report
     * that listed it twice would double its count and its balance.
     *
     * @param list<int> $dbIds
     * @param list<\OCA\PettyCash\Domain\Account\PettyCashAccount> $already
     * @return list<\OCA\PettyCash\Domain\Account\PettyCashAccount>
     */
    private function accountsByDbIds(array $dbIds, array $already): array
    {
        $seen = array_map(static fn ($a): int => (int) $a->dbId, $already);
        $extra = [];

        foreach ($dbIds as $dbId) {
            if (in_array($dbId, $seen, true)) {
                continue;
            }
            $account = $this->accounts->findById($dbId);
            if ($account === null) {
                // A charge naming an account that no longer resolves is a real audit row, but it
                // cannot become a report row: the row would have no owner, currency or balance to
                // report. Skipping it is honest; inventing a placeholder would not be.
                $this->logger->log('warning', 'A charged account could not be resolved for the accounts report', [
                    'app' => 'pettycash',
                ]);
                continue;
            }
            $extra[] = $account;
            $seen[] = $dbId;
        }

        return $extra;
    }

    /**
     * The process report: one row per workflow stage, counted from the transactions in it.
     *
     * @return array{list<array<string, mixed>>, callable, list<string>, array<string, mixed>}
     */
    private function processReport(): array
    {
        $stages = [];

        foreach (\OCA\PettyCash\Domain\Workflow\WorkflowStage::cases() as $stage) {
            $transactions = $this->transactions->findByStageAndStatus($stage, null, 0, self::BOUND);
            $stages[] = [
                'stage' => $stage->value,
                'transactionCount' => count($transactions),
                'distinctOwners' => count(array_unique(array_map(
                    static fn ($transaction): string => $transaction->ownerUid,
                    $transactions
                ))),
            ];
        }

        return [
            $stages,
            // Stage rows carry no owner, so the scope test is whether the actor may see *any* process
            // at all. Returning stage counts to a role with no queue would be the dashboard's
            // FR-007 mistake in report form.
            static fn (array $row, ReportScope $s): bool => $s->administrator
                || $s->costCenterDbIds !== []
                || $s->hasRole(\OCA\PettyCash\Domain\Shared\Enum\Role::Finance),
            ['stage', 'transactionCount', 'distinctOwners'],
            ['rowsRead' => count($stages), 'rowsBoundedBy' => self::BOUND],
        ];
    }

    /**
     * Delegates to `EvidenceSelectionQuery`.
     *
     * T367 moved the producer out of this controller because `POST /exports` with
     * `resource: evidence` became a second consumer — an endpoint that answered 422 in both the
     * selected and the unselected case, so the client's evidence export produced no file at all.
     *
     * @return array{list<array<string, mixed>>, callable, list<string>, array<string, mixed>}
     */
    private function evidenceReport(ReportScope $scope, string $uid): array
    {
        return $this->evidenceSelection->candidates($uid, self::BOUND);
    }

    /**
     * Delegates to `AuditSelectionQuery`.
     *
     * T367 moved the producer out of this controller because `POST /exports` with
     * `resource: audit` became a second consumer. Two copies of one scope rule would be the T307
     * shape: a rule that means one thing in a report and another in an export, with nothing
     * cross-checking them.
     *
     * @return array{list<array<string, mixed>>, callable, list<string>, array<string, mixed>}
     */
    private function auditReport(ReportScope $scope, string $uid): array
    {
        return $this->auditSelection->candidates($uid, self::BOUND);
    }

    /**
     * A rejected `filters` value, quoted and truncated.
     *
     * The one piece of filter handling left here, because it describes *this endpoint's* encoding: a
     * query parameter arrives as a bare string, and echoing a whole JSON document into a 422 body is
     * noise rather than diagnosis.
     */
    private function describe(string $value): string
    {
        return strlen($value) > 60
            ? var_export(substr($value, 0, 60) . '...', true)
            : var_export($value, true);
    }
}
