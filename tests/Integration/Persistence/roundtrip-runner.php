<?php

declare(strict_types=1);

/**
 * Runs one persistence round trip against the real Nextcloud database and prints what it observed.
 *
 * ## Why this is a separate process
 *
 * The PHPUnit process deliberately does not bootstrap Nextcloud. `tests/bootstrap.php` wires a
 * lightweight container of `InMemory*` doubles, and loading `lib/base.php` into that process would
 * replace the global server and break every other suite. `tests/Contract/Fixture/seed-contract-fixtures.php`
 * established the pattern this file follows: bootstrap the server, do the work, print JSON, exit.
 *
 * ## Why it reports observations instead of verdicts
 *
 * Every repository below is asked to save an aggregate and then to find it again through its own
 * join keys, and the **resulting values** are printed. The assertions live in
 * `RepositoryRoundTripTest`, not here, so a failure is reported by PHPUnit against the figure that
 * was actually read out of MariaDB — and so a bug in this file cannot turn a broken repository into a
 * green test by agreeing with itself.
 *
 * ## Why it builds the repositories itself
 *
 * Rather than asking the app container, so the file does not depend on Nextcloud's app-container
 * plumbing and so a failure here is unambiguously the repository's. The wiring in
 * `lib/AppInfo/Application.php` is not what is under test here, and it is already exercised by every
 * contract test over real HTTP.
 *
 * ## Why it cleans up after itself
 *
 * This shares one MariaDB with the live-HTTP contract suite, so every row it inserts is recorded and
 * deleted in a `finally`, in reverse insertion order. It deletes **only** surrogate keys obtained from
 * its own inserts, never by natural key, so it cannot remove a row the contract fixtures own.
 *
 * Usage (from the app root, inside the container):
 *   php tests/Integration/Persistence/roundtrip-runner.php <scenario>
 *
 * Scenarios: configuration | account | expense | command
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This runner must be run from the command line.\n");
    exit(1);
}

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
if (!is_file($nextcloudRoot . '/lib/base.php')) {
    fwrite(STDERR, "Cannot locate Nextcloud at {$nextcloudRoot}; set NEXTCLOUD_ROOT.\n");
    exit(1);
}

require_once $nextcloudRoot . '/lib/base.php';

use OCA\PettyCash\Application\Command\CommandResult;
use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Audit\AuditRetentionPolicy;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Evidence\EvidenceLink;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\Enum\AuditEventType;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Infrastructure\Nextcloud\NextcloudCurrentUser;
use OCA\PettyCash\Infrastructure\Nextcloud\NextcloudUserDirectory;
use OCA\PettyCash\Infrastructure\Persistence\Account\DbAccountMovementRepository;
use OCA\PettyCash\Infrastructure\Persistence\Account\DbAccountRepository;
use OCA\PettyCash\Infrastructure\Persistence\Audit\DbAuditEventRepository;
use OCA\PettyCash\Infrastructure\Persistence\Command\DbCommandResultRepository;
use OCA\PettyCash\Infrastructure\Persistence\Configuration\DbCostCenterRepository;
use OCA\PettyCash\Infrastructure\Persistence\Configuration\DbCurrencyRepository;
use OCA\PettyCash\Infrastructure\Persistence\Configuration\DbExpenseTypeRepository;
use OCA\PettyCash\Infrastructure\Persistence\Configuration\DbRoleAssignmentRepository;
use OCA\PettyCash\Infrastructure\Persistence\Evidence\DbEvidenceLinkRepository;
use OCA\PettyCash\Infrastructure\Persistence\Evidence\DbEvidenceRepository;
use OCA\PettyCash\Infrastructure\Persistence\Expense\DbExpenseSheetRepository;
use OCA\PettyCash\Infrastructure\Persistence\Expense\DbExpenseTransactionRepository;
use OCA\PettyCash\Infrastructure\Persistence\Expense\DbTransactionRevisionRepository;
use OCA\PettyCash\Infrastructure\Persistence\Workflow\DbWorkflowDecisionRepository;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;

$scenario = $argv[1] ?? '';
$scenarios = ['configuration', 'account', 'expense', 'command', 'retention'];
if (!in_array($scenario, $scenarios, true)) {
    fwrite(STDERR, 'Unknown scenario ' . var_export($scenario, true) . '; expected one of ' . implode(', ', $scenarios) . ".\n");
    exit(1);
}

$actorUid = getenv('PETTYCASH_TEST_USER') ?: '';
if ($actorUid === '') {
    fwrite(STDERR, "PETTYCASH_TEST_USER is required. Run through scripts/dev/phpunit, which loads .env.testing.\n");
    exit(1);
}

$db = \OCP\Server::get(IDBConnection::class);
$userManager = \OCP\Server::get(IUserManager::class);
$userDirectory = new NextcloudUserDirectory($userManager);

// Every `Db*Repository::save()` stamps `created_by` / `updated_by` from `CurrentUser::uid()`, and
// `NextcloudCurrentUser` refuses to invent one. A CLI process has no session, so the acting identity
// is established the way an `occ` command does it: resolve the user and put it in the session. This
// is not a stand-in for authentication — the repositories are being asked what they do when a real
// user is signed in, and the audit columns they write are the ones under test.
$actingUser = $userManager->get($actorUid);
if ($actingUser === null) {
    fwrite(STDERR, "PETTYCASH_TEST_USER names a user that does not exist in this Nextcloud.\n");
    exit(1);
}
$session = \OCP\Server::get(IUserSession::class);
$session->setUser($actingUser);
$currentUser = new NextcloudCurrentUser($session);

$currencies = new DbCurrencyRepository($db, $userDirectory, $currentUser);
$costCenters = new DbCostCenterRepository($db, $userDirectory, $currentUser);
$expenseTypes = new DbExpenseTypeRepository($db, $userDirectory, $currentUser);
$roleAssignments = new DbRoleAssignmentRepository($db, $userDirectory, $currentUser);
$accounts = new DbAccountRepository($db, $userDirectory, $currentUser);
$movements = new DbAccountMovementRepository($db, $userDirectory, $currentUser);
$sheets = new DbExpenseSheetRepository($db, $userDirectory, $currentUser);
$revisions = new DbTransactionRevisionRepository($db, $userDirectory, $currentUser);
$transactions = new DbExpenseTransactionRepository($db, $userDirectory, $currentUser, $revisions);
$decisions = new DbWorkflowDecisionRepository($db, $userDirectory, $currentUser);
$auditEvents = new DbAuditEventRepository($db, $userDirectory, $currentUser);
$evidence = new DbEvidenceRepository($db, $userDirectory, $currentUser);
$evidenceLinks = new DbEvidenceLinkRepository($db, $userDirectory, $currentUser);
$commandResults = new DbCommandResultRepository($db, $userDirectory, $currentUser);

/** Surrogate keys inserted by this run, for deletion in reverse dependency order. */
$inserted = [];

$track = static function (string $table, int $dbId) use (&$inserted): void {
    $inserted[] = [$table, $dbId];
};

/** The surrogate key of a row this run inserted, found through its public id. */
$surrogateIdOf = static function (string $table, string $publicId) use ($db): int {
    $qb = $db->getQueryBuilder();
    $qb->select('id')
        ->from($table)
        ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
        ->setMaxResults(1);
    $row = $qb->executeQuery()->fetchAssociative();
    if ($row === false) {
        throw new RuntimeException("No row in {$table} carries public id {$publicId}.");
    }

    return (int) $row['id'];
};

/**
 * `pettycash_currencies` has no `public_id` — `data-model.md` defines `Currency` with only `code`,
 * `name`, `minor_unit` and the audit columns, and currencies are referenced by code. So this table
 * needs its own lookup, and the difference is worth a named function rather than a duplicated block.
 */
$surrogateIdOfCurrency = static function (string $code) use ($db): int {
    $qb = $db->getQueryBuilder();
    $qb->select('id')
        ->from('pettycash_currencies')
        ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
        ->setMaxResults(1);
    $row = $qb->executeQuery()->fetchAssociative();
    if ($row === false) {
        throw new RuntimeException("No currency row carries code {$code}.");
    }

    return (int) $row['id'];
};

/**
 * Short, column-safe natural keys, so repeated or concurrent runs cannot collide.
 *
 * The currency code is three uppercase letters and nothing else — `Currency::create()` rejects
 * anything else, and a code is a natural key the table also enforces as unique, so a wider "marker"
 * would fail at the aggregate before it ever reached SQL. Three letters is 17,576 possibilities,
 * which is ample for a row that is deleted again in the same run; a collision would surface as a
 * duplicate-key error rather than as a quietly wrong assertion.
 */
$currencyCode = static function (): string {
    $letters = '';
    for ($i = 0; $i < 3; $i++) {
        $letters .= chr(65 + (ord(random_bytes(1)[0]) % 26));
    }

    return $letters;
};

$marker = static function (string $prefix): string {
    return $prefix . strtoupper(substr(str_replace('-', '', (string) Uuid::random()), 0, 6));
};

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$today = $now->format('Y-m-d');

/**
 * A command id unique to this run.
 *
 * `pettycash_account_movements.command_id` and `pettycash_command_results.command_id` are both
 * UNIQUE, and both repositories are deliberately idempotent per command id: a replayed command
 * returns the stored result instead of writing a second row. A fixed command id therefore makes the
 * *second* run of a scenario a silent no-op — the row is not inserted, and the runner then reports a
 * row that has been sitting in the database since the previous run. A per-run suffix is what makes a
 * repeated run measure the write rather than the index.
 */
$commandIdFor = static function (string $label): string {
    return 'rq-' . $label . '-' . strtoupper(substr(str_replace('-', '', (string) Uuid::random()), 0, 10));
};

$report = [];

try {
    $report = match ($scenario) {
        'configuration' => scenarioConfiguration(
            $currencies,
            $costCenters,
            $expenseTypes,
            $roleAssignments,
            $actorUid,
            $today,
            $now,
            $track,
            $surrogateIdOf,
            $surrogateIdOfCurrency,
            $currencyCode,
            $marker
        ),
        'account' => scenarioAccount(
            $currencies,
            $accounts,
            $movements,
            $actorUid,
            $now,
            $track,
            $surrogateIdOf,
            $surrogateIdOfCurrency,
            $currencyCode,
            $marker,
            $commandIdFor
        ),
        'expense' => scenarioExpense(
            $currencies,
            $costCenters,
            $expenseTypes,
            $accounts,
            $movements,
            $sheets,
            $transactions,
            $revisions,
            $decisions,
            $auditEvents,
            $evidence,
            $evidenceLinks,
            $actorUid,
            $now,
            $track,
            $surrogateIdOf,
            $surrogateIdOfCurrency,
            $currencyCode,
            $marker,
            $commandIdFor
        ),
        'command' => scenarioCommand($commandResults, $db, $track, $commandIdFor),
        'retention' => scenarioRetention($auditEvents, $db, $actorUid, $now, $track, $surrogateIdOf),
    };
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    echo json_encode(['error' => $e->getMessage()]);
    exit(1);
} finally {
    // Two sweeps, because one is not enough.
    //
    // By surrogate key is precise but incomplete: a row is only registered once its id has been
    // resolved, so a failure *between* the insert and the registration leaks it. That is not
    // hypothetical — the first runs of this file leaked 32 rows into the shared MariaDB precisely
    // that way, which is why the fingerprint sweep exists.
    //
    // By fingerprint catches whatever the first sweep missed, and every predicate below is a name
    // only this runner writes, so it cannot match a contract fixture row.
    foreach (array_reverse($inserted) as [$table, $dbId]) {
        $qb = $db->getQueryBuilder();
        $qb->delete($table)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($dbId)))
            ->executeStatement();
    }

    foreach ([
        ['pettycash_evidence', 'appdata_key', 'like', 'roundtrip/%'],
        ['pettycash_cost_centers', 'name', 'eq', 'Round Trip Cost Center'],
        ['pettycash_expense_types', 'title', 'like', 'Round Trip Expense %'],
        ['pettycash_currencies', 'name', 'eq', 'Round Trip Currency'],
        ['pettycash_currencies', 'name', 'eq', 'Round Trip Account Currency'],
        ['pettycash_transaction_audit', 'explanation', 'like', 'Retention probe %'],
        ['pettycash_configuration_audit', 'explanation', 'like', 'Retention probe %'],
        ['pettycash_currencies', 'name', 'eq', 'Round Trip Expense Currency'],
    ] as [$table, $column, $operator, $value]) {
        $qb = $db->getQueryBuilder();
        $condition = $operator === 'like'
            ? $qb->expr()->like($column, $qb->createNamedParameter($value))
            : $qb->expr()->eq($column, $qb->createNamedParameter($value));
        $qb->delete($table)->where($condition)->executeStatement();
    }

    // Command-keyed rows carry no recognisable name, so they are swept by the prefix every id this
    // runner mints carries.
    foreach ([
        ['pettycash_evidence_links', 'token_hash'],
        ['pettycash_account_movements', 'command_id'],
        ['pettycash_workflow_decisions', 'command_id'],
        ['pettycash_command_results', 'command_id'],
    ] as [$table, $column]) {
        $qb = $db->getQueryBuilder();
        $qb->delete($table)
            ->where($qb->expr()->like($column, $qb->createNamedParameter('rq-%')))
            ->executeStatement();
    }
}

echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ===== SCENARIOS =====

/**
 * `DbCurrencyRepository`, `DbCostCenterRepository`, `DbExpenseTypeRepository` and
 * `DbRoleAssignmentRepository` — the four configuration adapters, none of which needs a parent row.
 */
function scenarioConfiguration(
    DbCurrencyRepository $currencies,
    DbCostCenterRepository $costCenters,
    DbExpenseTypeRepository $expenseTypes,
    DbRoleAssignmentRepository $roleAssignments,
    string $actorUid,
    string $today,
    DateTimeImmutable $now,
    callable $track,
    callable $surrogateIdOf,
    callable $surrogateIdOfCurrency,
    callable $currencyCode,
    callable $marker
): array {
    // --- currency, with a three-digit minor unit no built-in table would guess ---
    $code = $currencyCode();
    $currencies->save(Currency::create($code, 'Round Trip Currency', 3));
    $track('pettycash_currencies', $surrogateIdOfCurrency($code));

    $byCode = $currencies->findByCode($code);
    $activeByCode = $currencies->findActiveByCode($code);
    $allActiveCodes = array_map(
        static fn (Currency $c): string => $c->code(),
        $currencies->findAllActive()
    );

    // --- cost center, with a manager and a senior manager so both scope reads have a subject ---
    $costCenter = CostCenter::create(
        $marker('CC'),
        'Round Trip Cost Center',
        'created by the persistence round trip',
        new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('UTC')),
        null
    );
    $costCenters->save($costCenter);
    $track('pettycash_cost_centers', $surrogateIdOf('pettycash_cost_centers', $costCenter->publicId));

    $ccByCode = $costCenters->findByCode($costCenter->code());
    $ccByPublicId = $costCenters->findByPublicId($costCenter->publicId);
    $ccActiveOn = $costCenters->findActiveByCodeOn($costCenter->code(), $now);
    $ccAllActiveCodes = array_map(
        static fn (CostCenter $c): string => $c->code(),
        $costCenters->findAllActiveOn($now)
    );

    // --- expense type, evidence-required so the boolean has to survive as true ---
    $title = 'Round Trip Expense ' . strtoupper(substr(str_replace('-', '', (string) Uuid::random()), 0, 6));
    $expenseType = ExpenseType::create($title, 'created by the persistence round trip', true);
    $expenseTypes->save($expenseType);
    $track('pettycash_expense_types', $surrogateIdOf('pettycash_expense_types', $expenseType->publicId));

    $etByTitle = $expenseTypes->findActiveByTitle($title);
    $etByPublicId = $expenseTypes->findByPublicId($expenseType->publicId);
    $etActiveTitles = array_map(
        static fn (ExpenseType $t): string => $t->title,
        $expenseTypes->findAllActive()
    );

    // --- role assignment, scoped to the cost center so the scoped read has a subject ---
    $roleAssignment = RoleAssignment::create(
        $actorUid,
        Role::SeniorManager,
        $ccByCode?->id,
        new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC')),
        null
    );
    $roleAssignments->save($roleAssignment);
    $track('pettycash_role_assignments', $surrogateIdOf('pettycash_role_assignments', $roleAssignment->publicId));

    $raForUser = array_map(
        static fn (RoleAssignment $r): string => (string) $r->publicId,
        $roleAssignments->findActiveByUserUid($actorUid, $now)
    );
    $raForUserAndRole = array_map(
        static fn (RoleAssignment $r): string => (string) $r->publicId,
        $roleAssignments->findActiveByUserAndRole($actorUid, Role::SeniorManager, $now)
    );
    $raForRoleAndCostCenter = array_map(
        static fn (RoleAssignment $r): string => (string) $r->publicId,
        $roleAssignments->findActiveByRoleAndCostCenter(Role::SeniorManager, (int) $ccByCode?->id, $now)
    );

    // A cost center has no manager column. Its managers are the role assignments scoped to it, and
    // this is the join `ManagementReviewService::getManagedCostCenterCodes()` performs to build a
    // reviewer's scope — so the round trip has to be able to walk it, or the review queue cannot be
    // scoped at all once T082 lands.
    $managedCodes = [];
    foreach ($roleAssignments->findActiveByUserAndRole($actorUid, Role::SeniorManager, $now) as $assignment) {
        if ($assignment->costCenterId === null) {
            continue;
        }
        $managedCode = $costCenters->findById($assignment->costCenterId)?->code();
        if ($managedCode !== null && $managedCode !== '') {
            $managedCodes[] = $managedCode;
        }
    }

    return [
        'currency' => [
            'code' => $code,
            'byCode' => $byCode === null ? null : [
                'code' => $byCode->code(),
                'name' => $byCode->name,
                'minorUnit' => $byCode->minorUnit,
                'active' => $byCode->active,
                'hasSurrogateKey' => $byCode->id !== null,
            ],
            'activeByCode' => $activeByCode === null ? null : [
                'code' => $activeByCode->code(),
                'minorUnit' => $activeByCode->minorUnit,
            ],
            'listedAmongAllActive' => in_array($code, $allActiveCodes, true),
        ],
        'costCenter' => [
            'code' => $costCenter->code(),
            'byCode' => $ccByCode === null ? null : [
                'code' => $ccByCode->code(),
                'name' => $ccByCode->name,
                'description' => $ccByCode->description,
                'active' => $ccByCode->active,
                'hasSurrogateKey' => $ccByCode->id !== null,
                'hasPublicId' => $ccByCode->publicId !== null,
            ],
            'byPublicId' => $ccByPublicId === null ? null : ['code' => $ccByPublicId->code()],
            'activeOnToday' => $ccActiveOn === null ? null : ['code' => $ccActiveOn->code()],
            'listedAmongAllActive' => in_array($costCenter->code(), $ccAllActiveCodes, true),
            // Resolved the way the application resolves it, through the assignment rather than a
            // column on the cost center.
            'foundAsManagedBySeniorManager' => in_array($costCenter->code(), $managedCodes, true),
        ],
        'expenseType' => [
            'title' => $title,
            'byTitle' => $etByTitle === null ? null : [
                'title' => $etByTitle->title,
                'description' => $etByTitle->description,
                'evidenceRequired' => $etByTitle->evidenceRequired,
                'active' => $etByTitle->active,
                'hasSurrogateKey' => $etByTitle->id !== null,
            ],
            'byPublicId' => $etByPublicId === null ? null : ['title' => $etByPublicId->title],
            'listedAmongAllActive' => in_array($title, $etActiveTitles, true),
        ],
        'roleAssignment' => [
            'publicId' => $roleAssignment->publicId,
            'role' => Role::SeniorManager->value,
            'foundForUser' => in_array($roleAssignment->publicId, $raForUser, true),
            'foundForUserAndRole' => in_array($roleAssignment->publicId, $raForUserAndRole, true),
            'foundForRoleAndCostCenter' => in_array($roleAssignment->publicId, $raForRoleAndCostCenter, true),
        ],
    ];
}

/**
 * `DbAccountRepository` and `DbAccountMovementRepository`, including the ledger read that joins the
 * account to its currency — the projection that used to read a `currency_code` column the table
 * never had, so it could not move a single row in either direction.
 */
function scenarioAccount(
    DbCurrencyRepository $currencies,
    DbAccountRepository $accounts,
    DbAccountMovementRepository $movements,
    string $actorUid,
    DateTimeImmutable $now,
    callable $track,
    callable $surrogateIdOf,
    callable $surrogateIdOfCurrency,
    callable $currencyCode,
    callable $marker,
    callable $commandIdFor
): array {
    $code = $currencyCode();
    $currencies->save(Currency::create($code, 'Round Trip Account Currency', 2));
    $track('pettycash_currencies', $surrogateIdOfCurrency($code));
    $currency = $currencies->findByCode($code);

    $account = PettyCashAccount::create((string) Uuid::random(), $actorUid, $currency);
    $accounts->save($account);
    $track('pettycash_accounts', (int) $account->dbId);

    // A charge, so the ledger has a row whose `source_revision_id` is genuinely null — the case an
    // inner join to the revision would have silently dropped.
    $commandId = $commandIdFor('charge');
    $movement = $account->charge(Money::of(100500, $account->currencyCode()), $commandId, 'round trip charge', $now);
    $movements->save($movement);
    $track('pettycash_account_movements', $surrogateIdOf('pettycash_account_movements', $movement->id));

    // Persisting the mutated balance is the update branch, and it is where an optimistic lock is
    // either honoured or quietly dropped. Reading the account back *after* it is what makes the
    // balance round trip mean anything: reading before the charge would only prove the insert wrote
    // the opening balance of zero.
    $accounts->save($account);

    $byPublicId = $accounts->findByPublicId($account->id);
    $bySurrogate = $accounts->findById((int) $account->dbId);
    $listedForUser = array_map(
        static fn (PettyCashAccount $a): string => $a->id,
        $accounts->findActiveByUserUid($actorUid)
    );
    $listedForUserAndCurrency = array_map(
        static fn (PettyCashAccount $a): string => $a->id,
        $accounts->findActiveByUserAndCurrency($actorUid, $account->currencyCode())
    );

    $movementByCommand = $movements->findByCommandId($commandId);
    $ledger = $movements->findByAccountId((int) $account->dbId);
    $ledgerPaged = $movements->findByAccountIdPaged((int) $account->dbId, 0, 1);

    return [
        'account' => [
            'publicId' => $account->id,
            'balanceAfterCharge' => $account->balanceMinor,
            'lockVersionAfterCharge' => $account->lockVersion,
            'byPublicId' => $byPublicId === null ? null : [
                'publicId' => $byPublicId->id,
                'userUid' => $byPublicId->userUid,
                'balanceMinor' => $byPublicId->balanceMinor,
                'currency' => (string) $byPublicId->currencyCode(),
                // The join must supply the configured scale rather than a guess: 2 here, and a
                // built-in table would have said 0 for an unknown code.
                'minorUnit' => $byPublicId->currency->minorUnit,
                'active' => $byPublicId->active,
                'lockVersion' => $byPublicId->lockVersion,
                'surrogateKey' => $byPublicId->dbId,
            ],
            'bySurrogateKey' => $bySurrogate === null ? null : ['publicId' => $bySurrogate->id],
            'listedForUser' => in_array($account->id, $listedForUser, true),
            'listedForUserAndCurrency' => in_array($account->id, $listedForUserAndCurrency, true),
        ],
        'movement' => [
            'publicId' => $movement->id,
            'byCommandId' => $movementByCommand === null ? null : [
                'publicId' => $movementByCommand->id,
                'type' => $movementByCommand->type->value,
                'amountMinor' => $movementByCommand->amount->minorUnits(),
                'balanceAfterMinor' => $movementByCommand->balanceAfter->minorUnits(),
                'description' => $movementByCommand->description,
                'accountPublicId' => $movementByCommand->accountPublicId,
                'sourceRevisionId' => $movementByCommand->sourceRevisionId,
                'hasOccurredAt' => $movementByCommand->occurredAt !== null,
            ],
            'ledgerCount' => count($ledger),
            'ledgerTypes' => array_map(static fn (AccountMovement $m): string => $m->type->value, $ledger),
            'ledgerSourceRevisionIds' => array_map(
                static fn (AccountMovement $m): ?string => $m->sourceRevisionId,
                $ledger
            ),
            'pagedCount' => count($ledgerPaged),
        ],
    ];
}

/**
 * `DbExpenseSheetRepository`, `DbExpenseTransactionRepository`, `DbTransactionRevisionRepository`,
 * `DbWorkflowDecisionRepository` and `DbAuditEventRepository` — the chain the task calls out, since a
 * revision, a decision and an audit row mean nothing if they point at the wrong parent.
 *
 * Also `DbEvidenceRepository` and `DbEvidenceLinkRepository`, plus the consumption movement, which is
 * the one movement that *does* name a source revision.
 */
function scenarioExpense(
    DbCurrencyRepository $currencies,
    DbCostCenterRepository $costCenters,
    DbExpenseTypeRepository $expenseTypes,
    DbAccountRepository $accounts,
    DbAccountMovementRepository $movements,
    DbExpenseSheetRepository $sheets,
    DbExpenseTransactionRepository $transactions,
    DbTransactionRevisionRepository $revisions,
    DbWorkflowDecisionRepository $decisions,
    DbAuditEventRepository $auditEvents,
    DbEvidenceRepository $evidence,
    DbEvidenceLinkRepository $evidenceLinks,
    string $actorUid,
    DateTimeImmutable $now,
    callable $track,
    callable $surrogateIdOf,
    callable $surrogateIdOfCurrency,
    callable $currencyCode,
    callable $marker,
    callable $commandIdFor
): array {
    // --- the configuration the expense chain points at. A zero minor unit is deliberate: the
    //     revision's currency can only be learned by walking revision -> transaction -> sheet ->
    //     currency, and a wrong scale is a wrong amount on every total derived from it. ---
    $code = $currencyCode();
    $currencies->save(Currency::create($code, 'Round Trip Expense Currency', 0));
    $track('pettycash_currencies', $surrogateIdOfCurrency($code));
    $currency = $currencies->findByCode($code);

    $costCenter = CostCenter::create(
        $marker('CC'),
        'Round Trip Expense Cost Center',
        null,
        new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC')),
        null
    );
    $costCenters->save($costCenter);
    $track('pettycash_cost_centers', $surrogateIdOf('pettycash_cost_centers', $costCenter->publicId));

    $title = 'Round Trip Expense ' . strtoupper(substr(str_replace('-', '', (string) Uuid::random()), 0, 6));
    $expenseType = ExpenseType::create($title, null, true);
    $expenseTypes->save($expenseType);
    $track('pettycash_expense_types', $surrogateIdOf('pettycash_expense_types', $expenseType->publicId));

    $account = PettyCashAccount::create((string) Uuid::random(), $actorUid, $currency);
    $accounts->save($account);
    $track('pettycash_accounts', (int) $account->dbId);

    // --- sheet ---
    $sheet = new ExpenseSheet(
        (string) Uuid::random(),
        $actorUid,
        $account->id,
        $account->currencyCode(),
        title: 'Round Trip Sheet'
    );
    $sheets->save($sheet);
    $track('pettycash_sheets', (int) $sheet->dbId);

    $sheetByPublicId = $sheets->findByPublicId($sheet->id);
    $sheetBySurrogate = $sheets->findById((int) $sheet->dbId);
    $sheetsForOwner = array_map(
        static fn (ExpenseSheet $s): string => $s->id,
        $sheets->findByOwnerUid($actorUid)
    );
    $sheetsOpen = array_map(
        static fn (ExpenseSheet $s): string => $s->id,
        $sheets->findByStatus(\OCA\PettyCash\Domain\Workflow\SheetStatus::Open)
    );

    // --- transaction and revision, in that order, because the revision's `transaction_id` is a NOT
    //     NULL join key onto a row that does not exist until the transaction is inserted ---
    $revision = new TransactionRevision(
        (string) Uuid::random(),
        1,
        $now,
        Money::of(250000, $account->currencyCode()),
        costCenterDbId: $costCenter->id,
        costCenterCode: $costCenter->code(),
        costCenterName: $costCenter->name,
        expenseTypeDbId: $expenseType->id,
        managerUid: $actorUid,
        seniorManagerUid: $actorUid
    );
    $revision->complete(
        'round trip expense',
        (string) $costCenter->publicId,
        $costCenter->code(),
        $costCenter->name,
        (int) $costCenter->id,
        $expenseType->title,
        (int) $expenseType->id,
        false
    );

    $transaction = new ExpenseTransaction((string) Uuid::random(), $actorUid, $revision);
    $transaction->sheetId = (int) $sheet->dbId;
    $transactions->save($transaction);
    $track('pettycash_transactions', (int) $transaction->dbId);

    $revision->transactionDbId = (int) $transaction->dbId;
    $revisions->save($revision);
    $track('pettycash_transaction_revisions', (int) $revision->dbId);

    // Only now can the transaction name its active revision, and only now can the join in
    // `findActiveByTransactionId` resolve.
    $transactions->save($transaction);

    $transactionByPublicId = $transactions->findByPublicId($transaction->id);
    $transactionBySurrogate = $transactions->findById((int) $transaction->dbId);
    $transactionsForSheet = array_map(
        static fn (ExpenseTransaction $t): string => $t->id,
        $transactions->findBySheetId((int) $sheet->dbId)
    );
    $countForSheet = $transactions->countBySheetId((int) $sheet->dbId);
    $activeRevision = $revisions->findActiveByTransactionId((int) $transaction->dbId);
    $revisionsForTransaction = $revisions->findByTransactionId((int) $transaction->dbId);
    $revisionByPublicId = $revisions->findByPublicId($revision->id());

    // --- a second revision, so `supersedes` and revision ordering are exercised ---
    $revision2 = new TransactionRevision(
        (string) Uuid::random(),
        2,
        $now,
        Money::of(300000, $account->currencyCode()),
        $revision->id(),
        costCenterDbId: $costCenter->id,
        costCenterCode: $costCenter->code(),
        costCenterName: $costCenter->name,
        expenseTypeDbId: $expenseType->id,
        managerUid: $actorUid,
        seniorManagerUid: $actorUid
    );
    $revision->complete(
        'round trip expense',
        (string) $costCenter->publicId,
        $costCenter->code(),
        $costCenter->name,
        (int) $costCenter->id,
        $expenseType->title,
        (int) $expenseType->id,
        false
    );
    $revision2->transactionDbId = (int) $transaction->dbId;
    $revisions->save($revision2);
    $track('pettycash_transaction_revisions', (int) $revision2->dbId);

    $revision2Read = $revisions->findByPublicId($revision2->id());
    $revisionsOrdered = $revisions->findByTransactionId((int) $transaction->dbId);

    // --- decision, pointing at the first revision ---
    $decisionCommandId = $commandIdFor('decision');
    $decision = WorkflowDecision::create(
        (string) Uuid::random(),
        $transaction->id,
        $revision->id(),
        WorkflowStage::CostCenter,
        Decision::Approve,
        'approved in the round trip',
        $decisionCommandId
    );
    $decision->actorUid = $actorUid;
    $decision->actorName = 'Round Trip Actor';
    $decision->actorRole = Role::CostCenterManager->value;
    $decisions->save($decision);
    $track('pettycash_workflow_decisions', $surrogateIdOf('pettycash_workflow_decisions', $decision->publicId));

    $decisionByCommand = $decisions->findByCommandId($decisionCommandId);
    $decisionByPublicId = $decisions->findByPublicId($decision->publicId);
    $decisionBySurrogate = $decisions->findById((int) $decision->dbId);
    $decisionsForTransaction = array_map(
        static fn (WorkflowDecision $d): string => (string) $d->publicId,
        $decisions->findByTransactionId((int) $transaction->dbId)
    );
    $decisionsForRevisionAndStage = array_map(
        static fn (WorkflowDecision $d): string => (string) $d->publicId,
        $decisions->findByRevisionAndStage((int) $revision->dbId, WorkflowStage::CostCenter)
    );

    // --- audit event, pointing at the sheet, the transaction, the revision and the decision ---
    $auditEvent = new AuditEvent(
        (string) Uuid::random(),
        (int) $sheet->dbId,
        (int) $transaction->dbId,
        (int) $revision->dbId,
        WorkflowStage::CostCenter->value,
        (int) $decision->dbId,
        'transaction',
        $transaction->id,
        AuditEventType::Decided->value,
        $actorUid,
        'Round Trip Actor',
        Role::CostCenterManager->value,
        'approved in the round trip',
        '{"stage":"DRAFT"}',
        '{"stage":"COST_CENTER"}',
        $decisionCommandId,
        $now
    );
    $auditEvents->save($auditEvent);
    $track('pettycash_transaction_audit', $surrogateIdOf('pettycash_transaction_audit', $auditEvent->publicId));

    $auditByPublicId = $auditEvents->findByPublicId($auditEvent->publicId);
    $auditBySurrogate = $auditEvents->findById((int) $auditEvent->dbId);
    $auditForTransaction = array_map(
        static fn (AuditEvent $e): string => (string) $e->publicId,
        $auditEvents->findByTransactionId((int) $transaction->dbId)
    );
    $auditForSheet = array_map(
        static fn (AuditEvent $e): string => (string) $e->publicId,
        $auditEvents->findBySheetId((int) $sheet->dbId)
    );
    $auditForActor = array_map(
        static fn (AuditEvent $e): string => (string) $e->publicId,
        $auditEvents->findByActorUid($actorUid)
    );
    $auditInRange = array_map(
        static fn (AuditEvent $e): string => (string) $e->publicId,
        $auditEvents->findByDateRange(
            new DateTimeImmutable('-1 day', new DateTimeZone('UTC')),
            new DateTimeImmutable('+1 day', new DateTimeZone('UTC'))
        )
    );
    $auditOutOfRange = array_map(
        static fn (AuditEvent $e): string => (string) $e->publicId,
        $auditEvents->findByDateRange(
            new DateTimeImmutable('+2 days', new DateTimeZone('UTC')),
            new DateTimeImmutable('+3 days', new DateTimeZone('UTC'))
        )
    );

    // --- evidence on the transaction, and a public link on the evidence ---
    $evidenceRow = new Evidence(
        (string) Uuid::random(),
        (int) $transaction->dbId,
        (int) $revision->dbId,
        'roundtrip/' . $transaction->id . '/receipt.pdf',
        'receipt.pdf',
        'application/pdf',
        2048,
        str_repeat('a', 64),
        true,
        $actorUid,
        $now
    );
    $evidence->save($evidenceRow);
    $track('pettycash_evidence', $surrogateIdOf('pettycash_evidence', $evidenceRow->publicId));

    $evidenceByPublicId = $evidence->findByPublicId($evidenceRow->publicId);
    $evidenceBySurrogate = $evidence->findById((int) $evidenceRow->dbId);
    $evidenceForTransaction = $evidence->findByTransactionId((int) $transaction->dbId);
    $evidenceActiveOnly = $evidence->findByTransactionId((int) $transaction->dbId, true);
    $evidenceForRevision = $evidence->findByRevisionId((int) $revision->dbId);

    $tokenHash = hash('sha256', 'rq-evidence-link-' . Uuid::random());
    $link = new EvidenceLink(
        (string) Uuid::random(),
        (int) $evidenceRow->dbId,
        $tokenHash,
        false,
        $now->modify('+7 days'),
        $actorUid,
        $now
    );
    $evidenceLinks->save($link);
    $track('pettycash_evidence_links', $surrogateIdOf('pettycash_evidence_links', $link->publicId));

    $linkByToken = $evidenceLinks->findByTokenHash($tokenHash);
    $linkByPublicId = $evidenceLinks->findByPublicId($link->publicId);
    $linksForEvidence = $evidenceLinks->findByEvidenceId((int) $evidenceRow->dbId);

    // --- the consumption movement, the one row that names a source revision ---
    $consumption = $account->consume(
        Money::of(250000, $account->currencyCode()),
        $commandIdFor('consumption'),
        $revision->id(),
        $decision->id
    );
    $movements->save($consumption);
    $track('pettycash_account_movements', $surrogateIdOf('pettycash_account_movements', $consumption->id));

    $consumptionByRevision = $movements->findBySourceRevisionId($revision->id());
    $ledger = $movements->findByAccountId((int) $account->dbId);

    return [
        'sheet' => [
            'publicId' => $sheet->id,
            'byPublicId' => $sheetByPublicId === null ? null : [
                'publicId' => $sheetByPublicId->id,
                'title' => $sheetByPublicId->title,
                'ownerUid' => $sheetByPublicId->ownerUid,
                'currency' => (string) $sheetByPublicId->currency,
                'status' => $sheetByPublicId->status->value,
                'lockVersion' => $sheetByPublicId->lockVersion,
                'surrogateKey' => $sheetByPublicId->dbId,
            ],
            'bySurrogateKey' => $sheetBySurrogate === null ? null : ['publicId' => $sheetBySurrogate->id],
            'listedForOwner' => in_array($sheet->id, $sheetsForOwner, true),
            'listedUnderStatus' => in_array($sheet->id, $sheetsOpen, true),
        ],
        'transaction' => [
            'publicId' => $transaction->id,
            'byPublicId' => $transactionByPublicId === null ? null : [
                'publicId' => $transactionByPublicId->id,
                'ownerUid' => $transactionByPublicId->ownerUid,
                'sheetDbId' => $transactionByPublicId->sheetId,
                'stage' => $transactionByPublicId->stage->value,
                'status' => $transactionByPublicId->status->value,
                'surrogateKey' => $transactionByPublicId->dbId,
                'revision' => [
                    'publicId' => $transactionByPublicId->revision()->id(),
                    'number' => $transactionByPublicId->revision()->number(),
                    'amountMinor' => $transactionByPublicId->revision()->amount()->minorUnits(),
                    'currency' => (string) $transactionByPublicId->revision()->amount()->currency(),
                    'costCenterId' => $transactionByPublicId->revision()->costCenterId(),
                    'costCenterCode' => $transactionByPublicId->revision()->costCenterCode,
                    'expenseTypeDbId' => $transactionByPublicId->revision()->expenseTypeDbId,
                'expenseTypeTitle' => $transactionByPublicId->revision()->expenseTypeTitle(),
                    // Derived from `pettycash_evidence` on every read rather than stored, so it is
                    // the join that has to work, not a column.
                    'hasEvidence' => $transactionByPublicId->revision()->hasEvidence(),
                ],
            ],
            'bySurrogateKey' => $transactionBySurrogate === null ? null : ['publicId' => $transactionBySurrogate->id],
            'listedForSheet' => in_array($transaction->id, $transactionsForSheet, true),
            'countForSheet' => $countForSheet,
        ],
        'revision' => [
            'firstPublicId' => $revision->id(),
            'byPublicId' => $revisionByPublicId === null ? null : [
                'publicId' => $revisionByPublicId->id(),
                'number' => $revisionByPublicId->number(),
                'amountMinor' => $revisionByPublicId->amount()->minorUnits(),
                'currency' => (string) $revisionByPublicId->amount()->currency(),
                'costCenterId' => $revisionByPublicId->costCenterId(),
                'costCenterCode' => $revisionByPublicId->costCenterCode,
                'costCenterName' => $revisionByPublicId->costCenterName,
                'expenseTypeDbId' => $revisionByPublicId->expenseTypeDbId,
                'expenseTypeTitle' => $revisionByPublicId->expenseTypeTitle(),
                'managerUid' => $revisionByPublicId->managerUid,
                'seniorManagerUid' => $revisionByPublicId->seniorManagerUid,
                'transactionDbId' => $revisionByPublicId->transactionDbId,
            ],
            'activeForTransaction' => $activeRevision === null ? null : [
                'publicId' => $activeRevision->id(),
                'number' => $activeRevision->number(),
            ],
            'countForTransaction' => count($revisionsForTransaction),
            'second' => [
                'publicId' => $revision2->id(),
                'number' => $revision2Read?->number(),
                // `supersedes_revision_id` is an int join key in the table and a public UUID on the
                // aggregate, so a read that returns the key instead of the UUID breaks every caller
                // that resolves a revision by public id.
                'supersedes' => $revision2Read?->supersedes(),
                'supersedesIsPublicId' => $revision2Read?->supersedes() === $revision->id(),
            ],
            'numbersInOrder' => array_map(
                static fn (TransactionRevision $r): int => $r->number(),
                $revisionsOrdered
            ),
        ],
        'decision' => [
            'publicId' => $decision->publicId,
            'byCommandId' => $decisionByCommand === null ? null : [
                'publicId' => $decisionByCommand->publicId,
                'stage' => $decisionByCommand->stage->value,
                'decision' => $decisionByCommand->decision->value,
                'transactionPublicId' => $decisionByCommand->transactionId,
                'revisionPublicId' => $decisionByCommand->revisionId,
                'explanation' => $decisionByCommand->explanation,
                'actorUid' => $decisionByCommand->actorUid,
                'actorName' => $decisionByCommand->actorName,
                'actorRole' => $decisionByCommand->actorRole,
                'surrogateKey' => $decisionByCommand->dbId,
            ],
            'byPublicId' => $decisionByPublicId === null ? null : ['publicId' => $decisionByPublicId->publicId],
            'bySurrogateKey' => $decisionBySurrogate === null ? null : ['publicId' => $decisionBySurrogate->publicId],
            'countForTransaction' => count($decisionsForTransaction),
            'foundForRevisionAndStage' => in_array($decision->publicId, $decisionsForRevisionAndStage, true),
        ],
        'audit' => [
            'publicId' => $auditEvent->publicId,
            'byPublicId' => $auditByPublicId === null ? null : [
                'publicId' => $auditByPublicId->publicId,
                'eventType' => $auditByPublicId->eventType,
                'stage' => $auditByPublicId->stage,
                'entityType' => $auditByPublicId->entityType,
                'entityId' => $auditByPublicId->entityId,
                'sheetDbId' => $auditByPublicId->sheetId,
                'transactionDbId' => $auditByPublicId->transactionId,
                'revisionDbId' => $auditByPublicId->revisionId,
                'decisionDbId' => $auditByPublicId->decisionId,
                'actorUid' => $auditByPublicId->actorUid,
                'actorName' => $auditByPublicId->actorName,
                'actorRole' => $auditByPublicId->actorRole,
                'explanation' => $auditByPublicId->explanation,
                'beforeJson' => $auditByPublicId->beforeJson,
                'afterJson' => $auditByPublicId->afterJson,
                'commandId' => $auditByPublicId->commandId,
            ],
            'bySurrogateKey' => $auditBySurrogate === null ? null : ['publicId' => $auditBySurrogate->publicId],
            'countForTransaction' => count($auditForTransaction),
            'countForSheet' => count($auditForSheet),
            'foundForActor' => in_array($auditEvent->publicId, $auditForActor, true),
            'foundInDateRange' => in_array($auditEvent->publicId, $auditInRange, true),
            'foundOutsideDateRange' => in_array($auditEvent->publicId, $auditOutOfRange, true),
        ],
        'evidence' => [
            'publicId' => $evidenceRow->publicId,
            'byPublicId' => $evidenceByPublicId === null ? null : [
                'publicId' => $evidenceByPublicId->publicId,
                'transactionDbId' => $evidenceByPublicId->transactionId,
                'originRevisionDbId' => $evidenceByPublicId->originRevisionId,
                'appDataKey' => $evidenceByPublicId->appDataKey,
                'originalName' => $evidenceByPublicId->originalName,
                'mediaType' => $evidenceByPublicId->mediaType,
                'sizeBytes' => $evidenceByPublicId->sizeBytes,
                'checksum' => $evidenceByPublicId->checksum,
                'active' => $evidenceByPublicId->active,
                'uploadedBy' => $evidenceByPublicId->uploadedBy,
            ],
            'bySurrogateKey' => $evidenceBySurrogate === null ? null : ['publicId' => $evidenceBySurrogate->publicId],
            'countForTransaction' => count($evidenceForTransaction),
            'countForTransactionActiveOnly' => count($evidenceActiveOnly),
            'countForRevision' => count($evidenceForRevision),
        ],
        'evidenceLink' => [
            'publicId' => $link->publicId,
            'byTokenHash' => $linkByToken === null ? null : [
                'publicId' => $linkByToken->publicId,
                'evidenceDbId' => $linkByToken->evidenceId,
                'active' => $linkByToken->active,
                'hasExpiry' => $linkByToken->expiresAt !== null,
                'createdBy' => $linkByToken->createdBy,
            ],
            'byPublicId' => $linkByPublicId === null ? null : ['publicId' => $linkByPublicId->publicId],
            'countForEvidence' => count($linksForEvidence),
        ],
        'consumption' => [
            'publicId' => $consumption->id,
            'bySourceRevisionId' => $consumptionByRevision === null ? null : [
                'publicId' => $consumptionByRevision->id,
                'type' => $consumptionByRevision->type->value,
                'amountMinor' => $consumptionByRevision->amount->minorUnits(),
                'sourceRevisionId' => $consumptionByRevision->sourceRevisionId,
            ],
            'ledgerTypes' => array_map(static fn (AccountMovement $m): string => $m->type->value, $ledger),
            'ledgerSourceRevisionIds' => array_map(
                static fn (AccountMovement $m): ?string => $m->sourceRevisionId,
                $ledger
            ),
        ],
    ];
}

/**
 * `DbCommandResultRepository` — the stored answer a replayed command is rebuilt from.
 *
 * Its table is `pettycash_command_results`, keyed by the caller's command id rather than a public
 * UUID, so it is the one repository with no `public_id` to look the row up by after inserting it.
 */
function scenarioCommand(
    DbCommandResultRepository $commandResults,
    IDBConnection $db,
    callable $track,
    callable $commandIdFor
): array {
    $commandId = $commandIdFor('command');
    $hash = hash('sha256', json_encode(['actor' => 'round-trip'], JSON_THROW_ON_ERROR));
    $response = [
        'account' => ['id' => 'acct-1', 'balance' => ['amountMinor' => 4200, 'currency' => 'IRR', 'minorUnit' => 0]],
        'movement' => ['id' => 'mvmt-1', 'type' => 'CHARGE'],
    ];

    $commandResults->save(new CommandResult($commandId, $hash, $response));

    $qb = $db->getQueryBuilder();
    $qb->select('id')
        ->from('pettycash_command_results')
        ->where($qb->expr()->eq('command_id', $qb->createNamedParameter($commandId)))
        ->setMaxResults(1);
    $track('pettycash_command_results', (int) $qb->executeQuery()->fetchAssociative()['id']);

    $found = $commandResults->find($commandId);
    $unknown = $commandResults->find($commandId . '-never-issued');

    return [
        'commandId' => $commandId,
        'found' => $found === null ? null : [
            'commandId' => $found->commandId,
            'hashMatches' => hash_equals($hash, $found->payloadHash),
            // The stored response is the frozen answer a replay is rebuilt from, so a round trip
            // that reshapes it would make a retry report something the first attempt never said.
            'response' => $found->response,
            'responseIsIdentical' => $found->response === $response,
        ],
        'unknownCommandIdIsNull' => $unknown === null,
    ];
}

/**
 * `pettycash_transaction_audit` under the FR-057 retention floor, observed against the real database.
 *
 * ## Why this scenario exists separately from `expense`
 *
 * The `expense` scenario proves an audit row round-trips. It cannot answer T138's question, which is
 * about what happens to a row over *time* rather than across a save. So this scenario writes two rows
 * with deliberately different ages and evaluates the real `AuditRetentionPolicy` against the values
 * read back out of MariaDB.
 *
 * ## Why there are two rows and not one
 *
 * A test that wrote only a young record and asserted "still protected" would pass against a policy
 * that answered `protected` for every input, including records centuries old. The ten-year-old row is
 * the control: if the comparison is real, exactly one of the two comes back protected.
 *
 * ## What it deliberately does not claim
 *
 * MariaDB will happily `DELETE` from this table if something issues the statement. FR-053 and
 * Constitution IV are guarantees about the *application* boundary — no service, port or controller
 * exposes a mutation — and the schema has no archive or deletion marker column. Nothing here pretends
 * the database itself is append-only; the structural half of that claim lives in
 * `tests/Integration/Audit/RetentionPolicyTest.php`, where reflection can assert it.
 */
function scenarioRetention(
    DbAuditEventRepository $auditEvents,
    \OCP\IDBConnection $db,
    string $actorUid,
    DateTimeImmutable $now,
    callable $track,
    callable $surrogateIdOf
): array {
    $policy = AuditRetentionPolicy::default();

    $makeEvent = static function (string $label, DateTimeImmutable $occurredAt) use ($actorUid): AuditEvent {
        return new AuditEvent(
            (string) Uuid::random(),
            null,
            null,
            null,
            WorkflowStage::CostCenter->value,
            null,
            'transaction',
            'retention-probe-' . $label,
            AuditEventType::Submitted->value,
            $actorUid,
            'Retention Probe',
            Role::Purchaser->value,
            'Retention probe ' . $label,
            '{}',
            '{}',
            (string) Uuid::random(),
            $occurredAt
        );
    };

    $countRows = static function (string $publicId) use ($db): int {
        $qb = $db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'hits'))
            ->from('pettycash_transaction_audit')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)));
        return (int) $qb->executeQuery()->fetchOne();
    };

    $describe = static function (string $label, AuditEvent $written, ?AuditEvent $read, int $rows) use ($policy, $now): array {
        return [
            'label' => $label,
            'writtenOccurredAt' => $written->occurredAt->format(DATE_ATOM),
            'readOccurredAt' => $read?->occurredAt->format(DATE_ATOM),
            'survived' => $read !== null,
            'rowsInTable' => $rows,
            // Evaluated against the occurrence time MariaDB holds, not the one just written, so a
            // column that silently lost its value would show up as a wrong verdict rather than pass.
            'protected' => $read === null ? null : $policy->isProtected($read->occurredAt, $now),
            'archivable' => $read === null ? null : $policy->isArchivable($read->occurredAt, $now),
            'availableUntil' => $read === null ? null : $policy->availableUntil($read->occurredAt)->format(DATE_ATOM),
        ];
    };

    // --- the young record: written now, unambiguously inside the seven-year window ---
    $young = $makeEvent('young', $now);
    $auditEvents->save($young);
    $track('pettycash_transaction_audit', $surrogateIdOf('pettycash_transaction_audit', $young->publicId));

    // --- the control: ten years old, unambiguously outside it ---
    $old = $makeEvent('old', $now->modify('-10 years'));
    $auditEvents->save($old);
    $track('pettycash_transaction_audit', $surrogateIdOf('pettycash_transaction_audit', $old->publicId));

    $youngRead = $auditEvents->findByPublicId($young->publicId);
    $oldRead = $auditEvents->findByPublicId($old->publicId);

    // Counted *after* the policy has been evaluated above, so the report shows that consulting it
    // removed nothing. An evaluation that quietly archived its input would show a count of 0 here.
    $report = [
        'policy' => [
            'minimumRetentionYears' => AuditRetentionPolicy::MINIMUM_RETENTION_YEARS,
            'effectiveYears' => $policy->retentionYears(),
            'honorsMinimum' => $policy->honorsMinimum(),
            'permitsDeletion' => $policy->permitsDeletion(),
        ],
        'young' => $describe('young', $young, $youngRead, $countRows($young->publicId)),
        'old' => $describe('old', $old, $oldRead, $countRows($old->publicId)),
    ];

    // The real columns, so the absence of an archive or deletion marker is observed rather than
    // assumed. Read through the Nextcloud schema API rather than `SHOW COLUMNS`, which is
    // vendor-specific and would itself be a portability defect against FR-077.
    //
    // `createSchema()` hands back tables by their *prefixed* name, so `hasTable('pettycash_...')` is
    // false on a real instance. The tables are therefore matched by suffix rather than looked up by
    // the logical name: FR-078 requires that no test hardcode an instance prefix such as `oc_`, and
    // matching by suffix is what lets this run unchanged on an instance configured with a different
    // one. `getPrefix()` is not consulted either, because it does not report the prefix the query
    // builder actually applies.
    $report['schema'] = [];
    foreach ($db->createSchema()->getTables() as $table) {
        foreach (['pettycash_transaction_audit', 'pettycash_configuration_audit'] as $logical) {
            if (!str_ends_with($table->getName(), $logical)) {
                continue;
            }
            $columns = array_keys($table->getColumns());
            sort($columns);
            // Reported under the logical name, so the assertions never depend on the prefix either.
            $report['schema'][$logical] = $columns;
        }
    }

    return $report;
}
