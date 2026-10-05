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
 * Scenarios: configuration | account | expense | command | retention | appdata | exportservice | sheetlist
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

// The app's own composer autoloader, required here rather than inherited.
//
// T345 corrected this comment, which previously claimed "Nextcloud core loads `<app>/vendor/autoload.php`
// for every enabled app (OC_App::registerAutoloading), which is why the app can use PhpSpreadsheet under
// Apache". That was false, and it was the premise the defect rested on. `registerAutoloading()` probes
// `<app>/composer/autoload.php` and never `<app>/vendor/autoload.php`, so PhpSpreadsheet was reachable in
// this runner and in every PHPUnit process — both of which load it by hand — and unreachable from the
// deployed app, where every XLSX export answered 500.
//
// So the asymmetry is the point, and it is why this line is not redundant: this runner bypasses the
// AppFramework bootstrap by loading `lib/base.php` directly and therefore does not get
// `composer/autoload.php` either, while a real request does. Any scenario touching a third-party class
// dies on "Class ... not found" without this line, on a class that works in the real runtime for a reason
// this runner does not share.
$appVendor = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($appVendor)) {
    require_once $appVendor;
}

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
use OCA\PettyCash\Domain\Expense\SheetListFilter;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\Enum\AuditEventType;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
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
use OCA\PettyCash\Application\Configuration\CostCenterService;
use OCA\PettyCash\Application\Configuration\CurrencyService;
use OCA\PettyCash\Application\Configuration\ExpenseTypeService;
use OCA\PettyCash\Application\Expense\SheetService;
use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Configuration\FinanceAssignmentService;
use OCA\PettyCash\Infrastructure\Nextcloud\NextcloudAdministratorCheck;
use OCA\PettyCash\Infrastructure\Nextcloud\SystemClock;
use OCA\PettyCash\Infrastructure\Persistence\Configuration\DbExpenseTypeRepository;
use OCA\PettyCash\Infrastructure\Persistence\Configuration\DbRoleAssignmentRepository;
use OCA\PettyCash\Infrastructure\Persistence\NextcloudTransactionManager;
use OCA\PettyCash\Tests\TestDoubles\FailingAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Infrastructure\Persistence\Evidence\DbEvidenceLinkRepository;
use OCA\PettyCash\Infrastructure\Persistence\Evidence\DbEvidenceRepository;
use OCA\PettyCash\Infrastructure\Persistence\Expense\DbExpenseSheetRepository;
use OCA\PettyCash\Infrastructure\Persistence\Expense\DbExpenseTransactionRepository;
use OCA\PettyCash\Infrastructure\Persistence\Expense\DbTransactionRevisionRepository;
use OCA\PettyCash\Infrastructure\Persistence\Workflow\DbWorkflowDecisionRepository;
use OCA\PettyCash\Tests\Support\LeakedRows;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;

require_once __DIR__ . '/../../Support/LeakedRows.php';

/**
 * The owner the `sheetlist` scenario's rows belong to.
 *
 * Deliberately not `$actorUid`. `employee1` is the user `ContractFixtures` seeds, so an owner-scoped
 * count taken over it is the fixture's sheets plus this scenario's, and the figure would then depend on
 * which scenario ran before it. A probe owner makes the owner scope exactly what this scenario wrote,
 * which is what lets the count be asserted as an exact number instead of as a comparison between two
 * answers that could be wrong together.
 *
 * `pettycash_sheets.owner_uid` is a plain 64-character column with no foreign key, so a uid that names
 * no Nextcloud user is a legal value and is what this probe needs.
 */
const PROBE_OWNER_UID = 't332-sheet-list-probe';

/**
 * The owner the filter probes' rows belong to.
 *
 * Separate from {@see PROBE_OWNER_UID} on purpose. That owner's 26 sheets are one currency and two
 * statuses written in the same second, which is what makes the paging figures exact; the filter probes
 * need a *second* currency and a sheet that was created on a *different day*, and adding those to the
 * same owner would change `written` out from under four assertions written against it.
 *
 * Declared here, beside {@see PROBE_OWNER_UID} and above the dispatch, rather than beside the
 * scenario that reads it. PHP executes a top-level `const` as an ordinary statement in file order, so a
 * constant declared after the `$scenario` dispatch below is still undefined when the scenario runs --
 * and it fails as `Undefined constant` from inside the scenario, which reads like a missing import
 * rather than an ordering mistake. Every constant this runner uses has to precede line 137.
 */
const T333_FILTER_PROBE_UID = 't333-sheet-filter-probe';

$scenario = $argv[1] ?? '';
$scenarios = ['configuration', 'account', 'expense', 'command', 'retention', 'appdata', 'exportservice', 'sheetlist'];
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

// T081/T082/T083 exercise the *services* over the real repositories, so the round trip proves the
// application layer against MariaDB and not only the persistence layer against an in-memory double.
//
// The acting identity for authorization is resolved separately from the session user: the services
// take an actor uid as a parameter and ask `AdministratorCheck` whether it may write, exactly as
// `AccountService::charge($uid, ...)` does, while the repositories stamp their audit columns from the
// session. `PETTYCASH_TEST_USER` is not an administrator in this instance, so a real administrator uid
// is discovered rather than assumed. Nothing here asserts on the audit columns, which is why the two
// identities may differ.
$groupManager = \OCP\Server::get(\OCP\IGroupManager::class);
$administratorUid = null;
$adminGroup = $groupManager->get('admin');
if ($adminGroup !== null) {
    foreach ($adminGroup->getUsers() as $member) {
        $administratorUid = $member->getUID();
        break;
    }
}
if ($administratorUid === null) {
    fwrite(STDERR, "The 'configuration' scenario needs a member of the admin group.\n");
    exit(1);
}
$administrators = new NextcloudAdministratorCheck($groupManager);
$clock = new SystemClock();

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
// FR-056: the configuration stream is a second binding of the same repository, and it is the
// only thing that proves the binding resolves to a different table with different subject
// columns. A unit test over two in-memory doubles cannot show either.
$configurationAuditEvents = new DbAuditEventRepository($db, $userDirectory, $currentUser, 'configuration');
$evidence = new DbEvidenceRepository($db, $userDirectory, $currentUser);
$evidenceLinks = new DbEvidenceLinkRepository($db, $userDirectory, $currentUser);
$commandResults = new DbCommandResultRepository($db, $userDirectory, $currentUser);

// T086 / FR-017. The real `AuditWriterService` over both real streams, and the *real*
// `NextcloudTransactionManager`. This is the only place in the test suite where the configuration audit
// can be shown to be atomic with its mutation: `InMemoryTransactionManager` runs its callback and rolls
// nothing back, so an in-memory run can show that an append was attempted and never that a refused
// audit leaves no committed configuration row.
$transactionAuditEvents = new DbAuditEventRepository($db, $userDirectory, $currentUser);
$auditWriter = new AuditWriterService($transactionAuditEvents, $currentUser, $userDirectory, $configurationAuditEvents);
$transactionManager = new NextcloudTransactionManager($db);
$configurationAudit = new ConfigurationAuditService($auditWriter, $transactionManager);

/**
 * One configuration-audit row, read as raw columns.
 *
 * Read raw rather than through `AuditEventRepository` because the load-bearing assertion is that
 * `before_json`/`after_json` hold the snapshots rather than the literal `"[]"` -- which is what a
 * projection could substitute while the object still reported something plausible.
 */
$configurationAuditRowOf = static function (?int $eventId) use ($db): ?array {
    if ($eventId === null) {
        return null;
    }
    $qb = $db->getQueryBuilder();
    $qb->select('entity_type', 'entity_id', 'event_type', 'actor_uid', 'actor_role', 'explanation', 'before_json', 'after_json')
        ->from('pettycash_configuration_audit')
        ->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId)))
        ->setMaxResults(1);
    $row = $qb->executeQuery()->fetchAssociative();
    return $row === false ? null : $row;
};

/** How many configuration-audit rows name one subject. */
$configurationAuditCountFor = static function (string $entityType, string $entityId) use ($db): int {
    $qb = $db->getQueryBuilder();
    $qb->select($qb->func()->count('id', 'total'))
        ->from('pettycash_configuration_audit')
        ->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
        ->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId)));
    return (int) $qb->executeQuery()->fetchOne();
};

/** The surrogate key of the configuration-audit row T086 wrote for one subject, for cleanup. */
$configurationAuditIdOf = static function (string $entityType, string $entityId) use ($db): ?int {
    $qb = $db->getQueryBuilder();
    $qb->select('id')
        ->from('pettycash_configuration_audit')
        ->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
        ->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId)))
        ->orderBy('id', 'DESC')
        ->setMaxResults(1);
    $id = $qb->executeQuery()->fetchOne();
    return $id === false ? null : (int) $id;
};

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
/**
 * The raw columns of one role-assignment row, read straight from the table.
 *
 * T084 needs to see `cost_center_id` as a *column* rather than as a property of the aggregate: the
 * unit suite can only assert what `RoleAssignment` reports about itself, and an insert or a
 * projection that quietly filled in a cost centre would leave that report saying null while the row
 * said otherwise. The scenario has no `$db`, so this is built here and passed in like `$surrogateIdOf`.
 */
$financeColumnsOf = static function (string $publicId) use ($db): ?array {
    $qb = $db->getQueryBuilder();
    $qb->select('role', 'cost_center_id', 'active', 'valid_from', 'valid_to')
        ->from('pettycash_role_assignments')
        ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
        ->setMaxResults(1);
    $row = $qb->executeQuery()->fetchAssociative();
    return $row === false ? null : $row;
};

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
 * How many rows `pettycash_sheets` holds for one owner, counted by SQL rather than by the repository.
 *
 * Built here and passed in like `$surrogateIdOf`, because the scenario has no `$db`. It exists so T332's
 * `total` is checked against the table and not against the code under test: an assertion of the form
 * "`listSheets()` agrees with `countByOwnerUid()`" would be satisfied by any implementation that got
 * both halves of the same defect wrong in the same way, which is precisely what the original
 * `count($sheets)` estimate was.
 */
$rawOwnerSheetCount = static function (string $ownerUid) use ($db): int {
    $qb = $db->getQueryBuilder();
    $qb->select($qb->func()->count('*', 'raw_sheet_count'))
        ->from('pettycash_sheets')
        ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));

    return (int) $qb->executeQuery()->fetchOne();
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
            (new CurrencyService($currencies, $administrators, $configurationAudit)),
            (new CostCenterService($costCenters, $roleAssignments, $userDirectory, $administrators, $clock, $configurationAudit)),
            (new ExpenseTypeService($expenseTypes, $administrators, $configurationAudit)),
            (new FinanceAssignmentService($roleAssignments, $userDirectory, $administrators, $clock, $configurationAudit)),
            // The same repositories and the same *real* transaction manager, behind an audit writer that
            // refuses. Nothing is faked here except the one failure under test.
            (new CurrencyService($currencies, $administrators, new ConfigurationAuditService(new FailingAuditWriter($auditWriter), $transactionManager))),
            $currencies,
            // The same repository and the same *real* transaction manager, behind an audit writer that
            // refuses. Nothing is faked here except the one failure under test.
            (new CostCenterService($costCenters, $roleAssignments, $userDirectory, $administrators, $clock, new ConfigurationAuditService(new FailingAuditWriter($auditWriter), $transactionManager))),
            $administratorUid,
            $actorUid,
            $today,
            $now,
            $track,
            $surrogateIdOf,
            $surrogateIdOfCurrency,
            $currencyCode,
            $marker,
            $financeColumnsOf,
            $configurationAuditIdOf,
            $configurationAuditRowOf,
            $configurationAuditCountFor
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
            $configurationAuditEvents,
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
        'appdata' => scenarioAppDataExportStore(),
        'exportservice' => scenarioExportService(),
        // T332: `GET /sheets` reported the length of the page it had just read as `total`. The
        // service is the real one over the real repository, and the identity is an `InMemoryCurrentUser`
        // naming a probe owner, so the owner's sheet count is exactly what this scenario wrote --
        // `employee1`, the user the contract fixtures seed, would make every figure depend on which
        // fixtures happened to exist when the suite ran.
        'sheetlist' => scenarioSheetList(
            $currencies,
            $accounts,
            $sheets,
            (new SheetService($sheets, $accounts, new InMemoryCurrentUser(PROBE_OWNER_UID), $transactionManager)),
            // T333's date facet compares a `created_at` written by `save()` (always `now()`) against a
            // back-dated one, so the scenario needs the same instant the dispatch stamped. Passing it
            // explicitly, as every other scenario does, is what keeps the two dates on one clock: read
            // from `new DateTimeImmutable` a second time, a boundary that crosses midnight mid-scenario
            // would make the fixtures disagree with the assertions.
            $now,
            $track,
            $surrogateIdOfCurrency,
            $currencyCode,
            $rawOwnerSheetCount,
            // T333 needs a sheet created on a different day, and `DbExpenseSheetRepository::save()` stamps
            // `created_at` with `now()` on every insert. Back-dating one row by SQL is the only way to
            // get a date facet whose two bounds disagree; a fixture whose rows all share one timestamp
            // cannot tell `from` from `to`, and both could be wrong in the same direction.
            static function (int $sheetDbId, string $createdAt) use ($db): void {
                $qb = $db->getQueryBuilder();
                $qb->update('pettycash_sheets')
                    ->set('created_at', $qb->createNamedParameter($createdAt))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($sheetDbId)))
                    ->executeStatement();
            },
            // A second `SheetService` over the same repositories, reading as a different owner.
            static fn (string $ownerUid): SheetService => new SheetService(
                $sheets,
                $accounts,
                new InMemoryCurrentUser($ownerUid),
                $transactionManager
            )
        ),
    };
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    echo json_encode(['error' => $e->getMessage()]);
    exit(1);
} finally {
    // AppData artifacts are swept by the same discipline as database rows, and for the same reason: the
    // scenario runs against the shared instance, and a generated export is a real file on disk that
    // would otherwise outlive the run. `deleteAll()` is a blanket call, so a partially completed build
    // cannot leak by leaving a file the sweep does not know about.
    try {
        $appData = \OCP\Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('pettycash');
        (new \OCA\PettyCash\Infrastructure\Nextcloud\AppDataExportStore($appData))->deleteAll();
    } catch (Throwable $e) {
        fwrite(STDERR, 'AppData sweep failed: ' . $e->getMessage() . "\n");
    }

    // Two sweeps, because one is not enough.
    //
    // By surrogate key is precise but incomplete: a row is only registered once its id has been
    // resolved, so a failure *between* the insert and the registration leaks it. That is not
    // hypothetical — the first runs of this file leaked 32 rows into the shared MariaDB precisely
    // that way, which is why the fingerprint sweep exists.
    foreach (array_reverse($inserted) as [$table, $dbId]) {
        $qb = $db->getQueryBuilder();
        $qb->delete($table)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($dbId)))
            ->executeStatement();
    }

    // By fingerprint catches whatever the first sweep missed.
    //
    // The definition of a fingerprint lives in `tests/Support/LeakedRows.php` and is shared with
    // `roundtrip-leftovers.php`, because the two were separately written lists of the same thing and
    // they disagreed: the diagnostic had no `pettycash_sheets` predicate at all and reported
    // `No leftovers` while seven broken sheets sat in the database refusing three whole reports.
    LeakedRows::sweep($db);

    // The post-condition, and the reason the sweep above is trustworthy rather than merely present.
    //
    // A sweep that runs and is never checked is a sweep that can quietly stop working, and the failure
    // it prevents is not visible where it happens: the next run's report answers 422 because a record
    // cannot be read, and the search starts in the report. Reporting the surviving fingerprints here
    // puts the number next to the run that produced it.
    //
    // The payload is *recorded* here and the failure is *raised* after the echo, below, rather than here.
    // A throw from inside `finally` skips the trailing `echo`, so a leak found at this point would leave
    // the caller with no output at all and a message about a subprocess that produced none -- which is
    // strictly less information than the count it was about to report. Printing first and failing second
    // means the suite receives the exact rows and names them.
    $leftovers = LeakedRows::leftovers($db);
    if ($leftovers !== []) {
        $report['leftovers'] = $leftovers;
    }
}

echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// The post-condition, enforced. Reached by running this file directly as often as by the suite, and the
// two are the same check: a harness that only fails when something is watching it is not a guard.
LeakedRows::assertNothingSurvived(
    isset($report['leftovers']) && is_array($report['leftovers']) ? $report['leftovers'] : [],
    "The '{$scenario}' round trip"
);

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
    CurrencyService $currencyService,
    CostCenterService $costCenterService,
    ExpenseTypeService $expenseTypeService,
    FinanceAssignmentService $financeAssignmentService,
    CurrencyService $unwritableCurrencyService,
    DbCurrencyRepository $unwritableCurrencies,
    CostCenterService $unwritableCostCenterService,
    string $administratorUid,
    string $actorUid,
    string $today,
    DateTimeImmutable $now,
    callable $track,
    callable $surrogateIdOf,
    callable $surrogateIdOfCurrency,
    callable $currencyCode,
    callable $marker,
    callable $financeColumnsOf,
    callable $configurationAuditIdOf,
    callable $configurationAuditRowOf,
    callable $configurationAuditCountFor
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

    // --- T081/T082/T083: the application services over the real repositories ---
    //
    // Everything below is a *service* call, so what is being proved is that the application layer
    // works against MariaDB rather than only against an in-memory double. Three properties each:
    // the write persists and reads back, the authorization gate holds against a real
    // `IGroupManager`, and the invariant the schema does not hold is enforced here rather than by a
    // unique index that does not exist.
    $serviceCode = $currencyCode();
    $serviceCurrency = $currencyService->create(
        $administratorUid,
        $serviceCode,
        'Service Currency',
        3
    );
    $track('pettycash_currencies', $surrogateIdOfCurrency($serviceCode));

    $serviceDuplicateRefused = false;
    try {
        $currencyService->create($administratorUid, $serviceCode, 'Duplicate', 3);
    } catch (\OCA\PettyCash\Application\Configuration\ConfigurationConflict) {
        $serviceDuplicateRefused = true;
    }

    $serviceNonAdminRefused = false;
    try {
        $currencyService->create($actorUid, $currencyCode(), 'Not An Admin', 2);
    } catch (\LogicException) {
        $serviceNonAdminRefused = true;
    }

    $serviceStaleVersionRefused = false;
    try {
        $currencyService->update($administratorUid, $serviceCode, 'Stale', 3, true, 99);
    } catch (\OCA\PettyCash\Application\Configuration\ConfigurationConflict) {
        $serviceStaleVersionRefused = true;
    }

    $serviceCostCenterCode = $marker('SVC');
    $serviceCostCenter = $costCenterService->create(
        $administratorUid,
        $serviceCostCenterCode,
        'Service Cost Center',
        'created through CostCenterService',
        $now,
        null,
        $actorUid,
        'admin'
    )['costCenter'];
    $track('pettycash_cost_centers', $surrogateIdOf('pettycash_cost_centers', $serviceCostCenter->publicId));

    // T082's whole point: the managers are role assignments scoped by the centre's surrogate key,
    // and the service's own read walks that join back. This is the same join
    // `ManagementReviewService::getManagedCostCenterCodes()` performs.
    $serviceManagers = $costCenterService->currentManagersOn($serviceCostCenter, $now);
    foreach ($serviceManagers as $roleAssignmentsForRole) {
        foreach ($roleAssignmentsForRole as $assignment) {
            $track(
                'pettycash_role_assignments',
                $surrogateIdOf('pettycash_role_assignments', $assignment->publicId)
            );
        }
    }

    $serviceTitle = 'Service Expense ' . strtoupper(substr(str_replace('-', '', (string) Uuid::random()), 0, 6));
    $serviceExpenseType = $expenseTypeService->create(
        $administratorUid,
        $serviceTitle,
        'created through ExpenseTypeService',
        true
    );
    $track('pettycash_expense_types', $surrogateIdOf('pettycash_expense_types', $serviceExpenseType->publicId));

    $serviceDuplicateTitleRefused = false;
    try {
        $expenseTypeService->create($administratorUid, $serviceTitle, 'duplicate', false);
    } catch (\OCA\PettyCash\Application\Configuration\ConfigurationConflict) {
        $serviceDuplicateTitleRefused = true;
    }

    // Read back *before* deactivating. `findActiveByTitle()` stops matching once the type is inactive,
    // so a value gathered afterwards would be null for the right reason and would prove nothing.
    $serviceEvidenceRequiredBeforeDeactivation = $expenseTypes->findActiveByTitle($serviceTitle)?->evidenceRequired;

    $serviceDeactivated = $expenseTypeService->deactivate($administratorUid, $serviceTitle, 0);
    $serviceTitleAfterDeactivation = $expenseTypes->findById((int) $serviceDeactivated->id)?->title;
    $serviceEvidenceRequiredAfterDeactivation = $expenseTypes->findById((int) $serviceDeactivated->id)?->evidenceRequired;

    // --- T084: the shared-queue finance roster over MariaDB ---
    //
    // Three properties that only a real table can decide. First, `cost_center_id` must be NULL *in the
    // column*, not merely on the aggregate: `data-model.md` scopes a finance assignment to the shared
    // queue and nowhere else, and an aggregate that reported null while the row held an id would pass
    // every aggregate-level assertion in the unit suite. Second, a lapsed assignment must not be reused
    // on re-appointment, because the contract lists active *and historical* finance assignments and the
    // history only exists if the ended row survives. Third, `POST` must converge on one row, because
    // `pettycash_role_assignments` carries no unique index that would do it.
    $financeUser = $actorUid;

    // A window that ended yesterday, so "is this user current?" has a negative answer to read.
    $lapsedFrom = $now->modify('-400 days')->format('Y-m-d');
    $lapsedTo = $now->modify('-1 day')->format('Y-m-d');
    $lapsed = $financeAssignmentService->create(
        $administratorUid,
        $financeUser,
        $lapsedFrom,
        'round trip: an appointment that has since lapsed',
        $lapsedTo
    );
    $track('pettycash_role_assignments', $surrogateIdOf('pettycash_role_assignments', $lapsed->publicId));
    /** The public ids of the finance assignments *this run* created, for scoped counting below. */
    $financeRunPublicIds = [(string) $lapsed->publicId];

    // Read the column itself, not the aggregate: this is the assertion that would fail if a projection
    // or an insert quietly filled in a cost centre.
    $financeColumns = $financeColumnsOf($lapsed->publicId);

    // The repository lookup the whole upsert decision rests on, asked for directly.
    $noCurrentAfterLapse = $roleAssignments->findCurrentByUserAndRole($financeUser, Role::Finance, $now) === null;

    // Re-appointment over a lapsed row must ADD a row, not rewrite the historical window.
    $financeLive = $financeAssignmentService->create(
        $administratorUid,
        $financeUser,
        $today,
        'round trip: appointed with an open window'
    );
    $track('pettycash_role_assignments', $surrogateIdOf('pettycash_role_assignments', $financeLive->publicId));

    $financeRunPublicIds[] = (string) $financeLive->publicId;

    // Scoped to the rows this run created, **not** to every FINANCE row belonging to the user.
    //
    // `$financeUser` is `PETTYCASH_TEST_USER`, which is also the contract suite's actor, and
    // `seed-contract-fixtures.php` seeds it a FINANCE assignment of its own. Counting by `userUid`
    // therefore included that fixture row whenever the contract suite had been seeded and excluded it
    // when it had not, so the expected totals tracked the order the suites ran in rather than anything
    // this scenario did. It surfaced only once T086's additions changed how often the suite was run.
    // Filtering on the public ids created here makes the count a statement about this scenario alone.
    $financeRowsFor = static fn(): array => array_values(array_filter(
        $financeAssignmentService->listForCaller($administratorUid),
        static fn(RoleAssignment $a): bool => in_array((string) $a->publicId, $financeRunPublicIds, true)
    ));
    $rosterPublicIds = static fn(array $rows): array => array_map(
        static fn(RoleAssignment $a): string => (string) $a->publicId,
        $rows
    );

    $rowsAfterReAppointment = $financeRowsFor();
    $lapsedWindowRetained = in_array(
        $lapsedFrom . '/' . $lapsedTo,
        array_map(
            static fn(RoleAssignment $a): string => $a->validFrom->format('Y-m-d') . '/' . ($a->validTo?->format('Y-m-d') ?? 'open'),
            $rowsAfterReAppointment
        ),
        true
    );

    // A repeated POST for a user who already holds a current assignment must update that row.
    $financeRepeated = $financeAssignmentService->create(
        $administratorUid,
        $financeUser,
        $today,
        'round trip: a repeated post for the same officer'
    );
    $rowsAfterRepeat = $financeRowsFor();

    // Revocation arrives as a POST with `active: false`, because the contract declares no PATCH or
    // DELETE for this resource.
    $financeRevoked = $financeAssignmentService->create(
        $administratorUid,
        $financeUser,
        $today,
        'round trip: revoked from the shared queue',
        null,
        false
    );
    $rowsAfterRevocation = $financeRowsFor();

    // Re-validating the very same window must reuse the retained row rather than adding a third, or a
    // revoked officer's history would claim they held the role three times over one window.
    $financeRevalidated = $financeAssignmentService->create(
        $administratorUid,
        $financeUser,
        $today,
        'round trip: revalidated after revocation'
    );
    $rowsAfterRevalidation = $financeRowsFor();

    $financeNonAdminRefused = false;
    try {
        $financeAssignmentService->create($financeUser, $financeUser, $today, 'round trip: self-appointment');
    } catch (\LogicException) {
        $financeNonAdminRefused = true;
    }

    $financeUnknownUserRefused = false;
    try {
        $financeAssignmentService->create(
            $administratorUid,
            'definitely-not-a-real-user-' . strtolower(substr(str_replace('-', '', (string) Uuid::random()), 0, 8)),
            $today,
            'round trip: unknown host user'
        );
    } catch (\InvalidArgumentException) {
        $financeUnknownUserRefused = true;
    }

    // --- T082 reopened scope: `CostCenterService::update()` against real MariaDB ---
    //
    // Two properties only a real table can show, and both are the reason this method needed reopening
    // rather than a stub:
    //
    // 1. **A rename actually persists.** `DbCostCenterRepository::save()`'s update branch wrote `name`,
    //    `description`, the validity window and `active` but never `code`, so a rename reported success,
    //    advanced `lock_version`, and left the row keyed by the old code. Every double accepts the
    //    aggregate with any code, so no unit test could have seen it.
    // 2. **`lock_version` advances and is published back.** The contract's `CostCenter` response is
    //    required to carry `version`; if the adapter left the aggregate holding the pre-update value,
    //    a client echoing it straight back into `expectedVersion` would be refused for a write it had
    //    just made.
    $updateCostCenter = $costCenterService->create(
        $administratorUid,
        $marker('UPD'),
        'Before The Rename',
        'created for the reopened T082 scope',
        $now,
        null,
        $actorUid,
        'admin'
    );
    $updateSubject = $updateCostCenter['costCenter'];
    $track('pettycash_cost_centers', $surrogateIdOf('pettycash_cost_centers', $costCenter->publicId));
    foreach ($costCenterService->currentManagersOn($updateSubject, $now) as $forRole) {
        foreach ($forRole as $assignment) {
            $track('pettycash_role_assignments', $surrogateIdOf('pettycash_role_assignments', $assignment->publicId));
        }
    }

    $originalCode = $updateSubject->code();
    $updateCentreCode = $originalCode . 'X';
    $versionBefore = $costCenters->findByPublicId((string) $updateSubject->publicId)?->lockVersion;

    $renamed = $costCenterService->update(
        $administratorUid,
        (string) $updateSubject->publicId,
        (int) $versionBefore,
        $updateCentreCode,
        'After The Rename',
        'updated through CostCenterService::update()',
        $now,
        null,
        false,
        null,
        null,
        'round trip: renaming the centre'
    );

    $versionAfter = $costCenters->findByPublicId((string) $updateSubject->publicId)?->lockVersion;
    $updateCentreEventId = $configurationAuditIdOf('cost_center', (string) $updateSubject->publicId);
    if ($updateCentreEventId !== null) {
        $track('pettycash_configuration_audit', $updateCentreEventId);
    }
    $updateEventRow = $configurationAuditRowOf($updateCentreEventId);
    $updateEventsForCentre = $configurationAuditCountFor('cost_center', (string) $updateSubject->publicId);

    // A stale write must be refused and must leave the row as the first writer left it.
    $staleRefused = false;
    try {
        $costCenterService->update(
            $administratorUid,
            (string) $updateSubject->publicId,
            (int) $versionBefore,
            $originalCode,
            'Stale Write',
            null,
            $now,
            null,
            true,
            null,
            null,
            'round trip: a stale write'
        );
    } catch (\OCA\PettyCash\Application\Configuration\ConfigurationConflict) {
        $staleRefused = true;
    }

    // A refused audit must leave the row uncommitted -- the real rollback for this write path.
    $updateRollbackCode = $marker('URB');
    $updateAuditRefusal = null;
    try {
        $unwritableCostCenterService->update(
            $administratorUid,
            (string) $updateSubject->publicId,
            (int) $costCenters->findByPublicId((string) $updateSubject->publicId)?->lockVersion,
            $updateRollbackCode,
            'Never Committed',
            null,
            $now,
            null,
            true,
            null,
            null,
            'round trip: an update whose audit cannot be written'
        );
    } catch (\RuntimeException $e) {
        $updateAuditRefusal = $e->getMessage();
    }

    // --- T086: the configuration audit, atomically, over real MariaDB ---
    //
    // Everything above proves persistence and the services' own rules. This proves the one property only
    // a real database transaction can show: a configuration change and its audit record are a single
    // unit. Three cases, each a failure the in-memory doubles cannot produce:
    //
    //   1. a successful write leaves exactly one committed row in `pettycash_configuration_audit`;
    //   2. an audit that cannot be persisted leaves **no** committed configuration row;
    //   3. a refused mutation leaves no *further* audit row.
    //
    // The actor recorded is the *session* user (`PETTYCASH_TEST_USER`), not `$administratorUid`, because
    // `AuditWriterService` resolves the actor from `CurrentUser` and deliberately ignores any
    // `actor_uid` in the payload -- an actor supplied by a caller would be a value the caller chose.
    $auditedCode = $currencyCode();
    $currencyService->create($administratorUid, $auditedCode, 'Audited Currency', 2);
    $track('pettycash_currencies', $surrogateIdOfCurrency($auditedCode));

    $auditedEventId = $configurationAuditIdOf('currency', $auditedCode);
    if ($auditedEventId !== null) {
        $track('pettycash_configuration_audit', $auditedEventId);
    }

    $auditedRow = $configurationAuditRowOf($auditedEventId);
    $auditCountFor = $configurationAuditCountFor;
    $auditCountForAudited = $auditCountFor('currency', $auditedCode);

    // Case 2: the audit stream refuses. The currency must not survive, because an unrecorded
    // configuration change is exactly what FR-017 forbids.
    $rolledBackCode = $currencyCode();
    $auditRefusalObserved = null;
    try {
        $unwritableCurrencyService->create($administratorUid, $rolledBackCode, 'Never Committed', 2);
    } catch (\RuntimeException $e) {
        $auditRefusalObserved = $e->getMessage();
    }
    $currencyRowAfterAuditRefusal = $currencies->findByCode($rolledBackCode);
    $auditRowsAfterAuditRefusal = $auditCountFor('currency', $rolledBackCode);

    // Case 3: the mutation itself is refused, so no *further* audit row may exist for that subject. A
    // duplicate code is the natural way to make the domain refuse, and it refuses before writing.
    $refusedCode = $currencyCode();
    $currencyService->create($administratorUid, $refusedCode, 'First', 2);
    $track('pettycash_currencies', $surrogateIdOfCurrency($refusedCode));
    $firstEventId = $configurationAuditIdOf('currency', $refusedCode);
    if ($firstEventId !== null) {
        $track('pettycash_configuration_audit', $firstEventId);
    }
    $auditCountBeforeRefusal = $auditCountFor('currency', $refusedCode);

    $duplicateRefusalObserved = null;
    try {
        $currencyService->create($administratorUid, $refusedCode, 'Duplicate', 2);
    } catch (\Throwable $e) {
        $duplicateRefusalObserved = $e::class;
    }
    $auditCountAfterRefusal = $auditCountFor('currency', $refusedCode);

    // The discriminating case for the snapshots. A *creation* encodes an empty `before` as the JSON
    // literal `"[]"`, which is indistinguishable from the failure `AuditWriterService` documents --
    // a payload whose `before`/`after` keys were never read also stores `"[]"` in both columns. So the
    // creation proves nothing about the snapshots, and an update is the only observation that can:
    // its `before` must hold the pre-update values and differ from its `after`.
    $updatedCode = $currencyCode();
    $currencyService->create($administratorUid, $updatedCode, 'Before Name', 3);
    $track('pettycash_currencies', $surrogateIdOfCurrency($updatedCode));
    $creationEventId = $configurationAuditIdOf('currency', $updatedCode);
    if ($creationEventId !== null) {
        $track('pettycash_configuration_audit', $creationEventId);
    }

    $currencyService->update($administratorUid, $updatedCode, 'After Name', 0, false, 0);
    $updateEventId = $configurationAuditIdOf('currency', $updatedCode);
    if ($updateEventId !== null) {
        $track('pettycash_configuration_audit', $updateEventId);
    }
    $updateRow = $configurationAuditRowOf($updateEventId);
    $updateBefore = json_decode((string) ($updateRow['before_json'] ?? ''), true);
    $updateAfter = json_decode((string) ($updateRow['after_json'] ?? ''), true);

    return [
        'services' => [
            'currencyCode' => $serviceCode,
            'currencyPersisted' => $currencies->findActiveByCode($serviceCode)?->minorUnit,
            'currencyMinorUnit' => $serviceCurrency->minorUnit,
            'duplicateRefused' => $serviceDuplicateRefused,
            'nonAdministratorRefused' => $serviceNonAdminRefused,
            'staleVersionRefused' => $serviceStaleVersionRefused,
            'costCenterCode' => $serviceCostCenter->code(),
            'managerUsers' => array_map(
                static fn (RoleAssignment $a): string => $a->userUid,
                $serviceManagers[Role::CostCenterManager->value] ?? []
            ),
            'seniorManagerUsers' => array_map(
                static fn (RoleAssignment $a): string => $a->userUid,
                $serviceManagers[Role::SeniorManager->value] ?? []
            ),
            'expenseTypeTitle' => $serviceTitle,
            'expenseTypePersisted' => $serviceEvidenceRequiredBeforeDeactivation,
            'duplicateTitleRefused' => $serviceDuplicateTitleRefused,
            'deactivated' => $serviceDeactivated->active,
            'titleAfterDeactivation' => $serviceTitleAfterDeactivation,
            'evidenceRequiredAfterDeactivation' => $serviceEvidenceRequiredAfterDeactivation,
            'stillListedAsActive' => in_array($serviceTitle, $etActiveTitles, true),
            // --- T084 ---
            'financeUser' => $financeUser,
            // The column, not the aggregate. `cost_center_id` must be NULL in MariaDB itself.
            'financeRoleInColumn' => $financeColumns['role'] ?? null,
            // `array_key_exists`, not `??`: `??` returns its fallback for a key that is *present and
            // null*, which is exactly the value this assertion is about. `??` would report a correctly
            // NULL `cost_center_id` as absent and fail the assertion that matters most here.
            'financeCostCenterColumnIsNull' => $financeColumns !== null
                && array_key_exists('cost_center_id', $financeColumns)
                && $financeColumns['cost_center_id'] === null,
            'financeValidFromInColumn' => $financeColumns['valid_from'] instanceof DateTimeInterface
                ? $financeColumns['valid_from']->format('Y-m-d')
                : (is_string($financeColumns['valid_from'] ?? null) ? $financeColumns['valid_from'] : null),
            'financeValidToInColumn' => ($financeColumns['valid_to'] ?? null) instanceof DateTimeInterface
                ? $financeColumns['valid_to']->format('Y-m-d')
                : (is_string($financeColumns['valid_to'] ?? null) ? $financeColumns['valid_to'] : null),
            'financeAggregateRole' => $lapsed->role->value,
            'financeAggregateCostCenterIsNull' => $lapsed->costCenterId === null,
            // The lookup the upsert decision rests on: an ended window is not "current".
            'noCurrentAssignmentAfterLapse' => $noCurrentAfterLapse,
            // Re-appointment must add a row so the contract's "historical" half has something to return.
            'lapsedRowSurvivedReAppointment' => $lapsedWindowRetained,
            'rowsForFinanceUserAfterReAppointment' => count($rowsAfterReAppointment),
            // A repeated POST must converge on one row.
            'repeatedPostUpdatedInPlace' => $financeRepeated->id === $financeLive->id,
            'rowsForFinanceUserAfterRepeat' => count($rowsAfterRepeat),
            // Revocation is a POST with active:false, since the contract has no PATCH or DELETE.
            'revocationClearedTheActiveFlag' => $financeRevoked->active === false,
            'revocationReusedTheSameRow' => $financeRevoked->id === $financeLive->id,
            'revokedRowStillInRoster' => in_array((string) $financeRevoked->publicId, $rosterPublicIds($rowsAfterRevocation), true),
            'rowsForFinanceUserAfterRevocation' => count($rowsAfterRevocation),
            // Re-validating the same window must reuse the retained row, not add another.
            'revalidationReusedTheSameRow' => $financeRevalidated->id === $financeLive->id,
            'rowsForFinanceUserAfterRevalidation' => count($rowsAfterRevalidation),
            'financeNonAdministratorRefused' => $financeNonAdminRefused,
            'financeUnknownHostUserRefused' => $financeUnknownUserRefused,
            // FR-016: the roster this service returns must be the finance queue, and must not include
            // the manager assignments T082 created for the same table in the same scenario.
            'rosterExcludesManagerAssignments' => array_values(array_filter(
                $financeAssignmentService->listForCaller($administratorUid),
                static fn(RoleAssignment $a): bool => $a->role !== Role::Finance
            )) === [],
            // --- T082 reopened scope (T086 wiring below) ---
            'updateOriginalCode' => $originalCode,
            'updateNewCode' => $updateCentreCode,
            'updateCodePersistedInColumn' => $costCenters->findByCode($updateCentreCode)?->code(),
            'updateOldCodeNoLongerResolves' => $costCenters->findByCode($originalCode) === null,
            'updateNamePersistedInColumn' => $costCenters->findByPublicId((string) $updateSubject->publicId)?->name,
            'updateDeactivatedInColumn' => $costCenters->findByPublicId((string) $updateSubject->publicId)?->active,
            'updatePublicIdUnchanged' => $costCenters->findByCode($updateCentreCode)?->publicId === (string) $updateSubject->publicId,
            'updateVersionBefore' => $versionBefore,
            'updateVersionInColumnAfter' => $versionAfter,
            'updateVersionReturnedToCaller' => $renamed->lockVersion,
            'updateEventsForCentre' => $updateEventsForCentre,
            'updateEventEntityId' => $updateEventRow['entity_id'] ?? null,
            'updateEventExplanation' => $updateEventRow['explanation'] ?? null,
            'updateStaleRefused' => $staleRefused,
            'updateNameAfterStaleAttempt' => $costCenters->findByPublicId((string) $updateSubject->publicId)?->name,
            'updateAuditRefusal' => $updateAuditRefusal,
            'updateRowSurvivedAuditRefusal' => $costCenters->findByCode($updateRollbackCode) !== null,
            'auditedCurrencyCode' => $auditedCode,
            'auditedEventExists' => $auditedEventId !== null,
            'auditedEventsForSubject' => $auditCountForAudited,
            'auditedRowEntityType' => $auditedRow['entity_type'] ?? null,
            'auditedRowEntityId' => $auditedRow['entity_id'] ?? null,
            'auditedRowEventType' => $auditedRow['event_type'] ?? null,
            // The session user, not `$administratorUid`. See the note above the block.
            'auditedRowActorUid' => $auditedRow['actor_uid'] ?? null,
            'sessionUid' => $actorUid,
            'auditedRowActorRole' => $auditedRow['actor_role'] ?? null,
            // `array_key_exists`, not `??`: `??` yields its fallback for a key that is *present and
            // null*, which is exactly the value being asserted.
            'auditedRowExplanation' => $auditedRow['explanation'] ?? null,
            'auditedRowExplanationIsNull' => $auditedRow !== null
                && array_key_exists('explanation', $auditedRow)
                && $auditedRow['explanation'] === null,
            'auditedRowBeforeJson' => $auditedRow['before_json'] ?? null,
            'auditedRowAfterJson' => $auditedRow['after_json'] ?? null,
            'auditRefusalObserved' => $auditRefusalObserved,
            'currencyRowSurvivedAuditRefusal' => $currencyRowAfterAuditRefusal !== null,
            'auditRowsAfterAuditRefusal' => $auditRowsAfterAuditRefusal,
            'duplicateRefusalObserved' => $duplicateRefusalObserved,
            'auditCountBeforeRefusedMutation' => $auditCountBeforeRefusal,
            'auditCountAfterRefusedMutation' => $auditCountAfterRefusal,
            'updatedCurrencyCode' => $updatedCode,
            'updateRowEventType' => $updateRow['event_type'] ?? null,
            'updateBeforeJson' => $updateBefore,
            'updateAfterJson' => $updateAfter,
        ],
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
    DbAuditEventRepository $configurationAuditEvents,
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
        title: LeakedRows::ROUND_TRIP_SHEET_TITLE
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
        // Was `Decided`, which covered an approval. The event types were split so an approval, a
        // rejection and a return are distinguishable, and the explanation below records this as the
        // approval it always was.
        AuditEventType::Approved->value,
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

    // --- configuration audit event, addressed by entity_type/entity_id (FR-055) ---
    $configurationEvent = new AuditEvent(
        (string) Uuid::random(),
        null,
        null,
        null,
        '',
        null,
        'account',
        'round-trip-account-1',
        'ACCOUNT_CHARGED',
        $actorUid,
        'Round Trip Actor',
        Role::Finance->value,
        'charged in the round trip',
        '{}',
        '{"movement_id":"mov-1"}',
        $decisionCommandId,
        $now
    );
    $configurationAuditEvents->save($configurationEvent);
    $track('pettycash_configuration_audit', $surrogateIdOf('pettycash_configuration_audit', $configurationEvent->publicId));

    $configurationByPublicId = $configurationAuditEvents->findByPublicId($configurationEvent->publicId);
    $configurationBySurrogate = $configurationAuditEvents->findById((int) $configurationEvent->dbId);
    $configurationByActor = array_map(
        static fn (AuditEvent $e): string => (string) $e->publicId,
        $configurationAuditEvents->findByActorUid($actorUid)
    );
    $configurationByRange = array_map(
        static fn (AuditEvent $e): string => (string) $e->publicId,
        $configurationAuditEvents->findByDateRange($now, $now)
    );
    // Read the configuration table directly. Going through the binding under test cannot prove
    // the binding reached that table; only the table itself can.
    $configurationRows = \OCP\Server::get(\OCP\IDBConnection::class)->executeQuery(
        'SELECT public_id FROM oc_pettycash_configuration_audit WHERE public_id = ?',
        [$configurationEvent->publicId]
    )->fetchAll();
    // The separation that matters: the transaction binding must not be able to read it.
    $visibleToTransactionStream = array_map(
        static fn (AuditEvent $e): string => (string) $e->publicId,
        $auditEvents->findByActorUid($actorUid)
    );
    $transmissionOfConfiguration = in_array(
        (string) $configurationEvent->publicId,
        $visibleToTransactionStream,
        true
    );

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
        'configurationAudit' => [
            'publicId' => $configurationEvent->publicId,
            'byPublicId' => $configurationByPublicId === null ? null : [
                'publicId' => $configurationByPublicId->publicId,
                'eventType' => $configurationByPublicId->eventType,
                'entityType' => $configurationByPublicId->entityType,
                'entityId' => $configurationByPublicId->entityId,
                'sheetDbId' => $configurationByPublicId->sheetId,
                'transactionDbId' => $configurationByPublicId->transactionId,
                'revisionDbId' => $configurationByPublicId->revisionId,
                'decisionDbId' => $configurationByPublicId->decisionId,
                'stage' => $configurationByPublicId->stage,
                'actorUid' => $configurationByPublicId->actorUid,
                'actorName' => $configurationByPublicId->actorName,
                'actorRole' => $configurationByPublicId->actorRole,
                'explanation' => $configurationByPublicId->explanation,
                'beforeJson' => $configurationByPublicId->beforeJson,
                'afterJson' => $configurationByPublicId->afterJson,
                'commandId' => $configurationByPublicId->commandId,
            ],
            'bySurrogateKey' => $configurationBySurrogate === null ? null : ['publicId' => $configurationBySurrogate->publicId],
            'foundForActor' => in_array($configurationEvent->publicId, $configurationByActor, true),
            'foundInDateRange' => in_array($configurationEvent->publicId, $configurationByRange, true),
            'rowCountInConfigurationTable' => count($configurationRows),
            'visibleToTransactionStream' => $transmissionOfConfiguration,
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

/**
 * `AppDataExportStore` against a real `IAppData`-backed filesystem.
 *
 * ## Why this scenario exists
 *
 * T110 was marked complete on a claim that did not hold: `contracts/exports.md` requires "generated
 * artifacts use private transient application storage" and the implementation wrote to the host's
 * *shared* `sys_get_temp_dir()`. Convergence caught it, the store was reworked onto `IAppData`, and
 * then the honest position was that **every** test for it still ran against an in-memory double — the
 * same gap T236 was created for, where thirteen doubles hid nine broken repositories.
 *
 * The parts most likely to be wrong are exactly the parts a double cannot see: whether
 * `getFolder`/`newFolder`/`newFile` compose the way the adapter assumes, whether
 * `stream_copy_to_stream()` into `ISimpleFile::write()` actually works, and whether a file written
 * through that stream is readable through `ISimpleFile::read()` afterwards.
 *
 * ## Why distinctness is proven by ordering rather than by path
 *
 * "The persisted artifact is not the scratch file" cannot be checked by comparing paths, because
 * `ISimpleFolder` deliberately does not expose an absolute path. It is proven by the stronger fact
 * instead: the scratch file is **deleted** by the exporter's `finally`, and the artifact is still there
 * afterwards. If the artifact were the scratch file, deleting the scratch would have deleted it.
 *
 * Usage: php tests/Integration/Persistence/roundtrip-runner.php appdata
 */
function scenarioAppDataExportStore(): array
{
    $appData = \OCP\Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('pettycash');
    $store = new \OCA\PettyCash\Infrastructure\Nextcloud\AppDataExportStore($appData);

    $report = [];

    // --- 1. scratchPath returns a real, writable, non-app-data location ---
    $scratch = $store->scratchPath('probe.zip');
    $isFile = is_file($scratch);
    $isWritable = $isFile && is_writable($scratch);
    $scratchDir = dirname($scratch);

    // --- 2. persist() through a real IAppData, by stream ---
    $payload = random_bytes(4096) . 'pettycash-appdata-probe';
    file_put_contents($scratch, $payload);
    $artifact = 'probe-' . bin2hex(random_bytes(6)) . '.zip';
    $store->persist($artifact, $scratch);

    $exists = $store->exists($artifact);
    $size = $store->size($artifact);

    // --- 3. read back through the real ISimpleFile::read() stream ---
    $handle = $store->read($artifact);
    $readBack = '';
    if (is_resource($handle)) {
        $readBack = (string) stream_get_contents($handle);
        fclose($handle);
    }
    $identical = $readBack === $payload;
    $shaOfStored = sha1($readBack);
    $shaOfOriginal = sha1($payload);

    // --- 4. the artifact is a file object with a name and a size the folder agrees with ---
    $folder = $appData->getFolder('exports');
    $listing = array_map(
        static fn ($child): string => $child->getName(),
        array_values(array_filter(
            $folder->getDirectoryListing(),
            static fn ($child): bool => $child instanceof \OCP\Files\SimpleFS\ISimpleFile
        ))
    );

    // --- 5. replace semantics: persisting the same name again overwrites ---
    $store->persist($artifact, $scratch);
    $sizeAfterReplace = $store->size($artifact);
    $replacement = 'a much shorter replacement payload';
    file_put_contents($scratch, $replacement);
    $store->persist($artifact, $scratch);
    $sizeAfterSecondReplace = $store->size($artifact);
    $replacementMatched = (string) stream_get_contents($store->read($artifact)) === $replacement;

    // --- 6. delete() removes exactly one artifact ---
    $other = 'probe-other-' . bin2hex(random_bytes(6)) . '.zip';
    file_put_contents($scratch, 'other');
    $store->persist($other, $scratch);
    $beforeDelete = $store->size($other);
    $store->delete($other);
    $otherGone = !$store->exists($other);
    $probeStillThere = $store->exists($artifact);
    $deleteOfAbsentIsQuiet = true;
    try {
        $store->delete('probe-never-existed-' . bin2hex(random_bytes(4)) . '.zip');
    } catch (Throwable) {
        $deleteOfAbsentIsQuiet = false;
    }

    // --- 7. the whole exporter path, end to end on a real filesystem ---
    // The probe's own scratch is removed *before* the exporter runs, so the scratch diff below can
    // only ever contain files the exporter itself created. The first version of this scenario globbed
    // after the build without unlinking its own probe, and reported the probe's file as a leaked
    // scratch — a probe artefact that would have been read as a product defect.
    if (is_file($scratch)) {
        @unlink($scratch);
    }
    $scratchBefore = glob(sys_get_temp_dir() . '/pettycash-export-*') ?: [];

    $exporter = new \OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter($store);
    $entry = new \OCA\PettyCash\Infrastructure\Export\EvidenceArchiveEntry(
        'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
        'receipt.pdf',
        'application/pdf',
        9,
        str_repeat('b', 64),
        true,
        'probe-actor',
        'کاربر آزمایشی',
        new DateTimeImmutable('2026-03-15T10:30:00+00:00'),
        'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb',
        1,
        'cccccccc-3333-4333-8333-cccccccccccc',
        'dddddddd-4444-4444-8444-dddddddddddd',
        'Probe Sheet',
        'probe/scratch-evidence.pdf'
    );

    $result = $exporter->build([$entry], static fn (): string => 'pdf-bytes');
    $exporterArtifact = $result->artifactName;
    $exporterStored = $store->exists($exporterArtifact);
    $exporterBytes = '';
    $exporterHandle = $store->read($exporterArtifact);
    if (is_resource($exporterHandle)) {
        $exporterBytes = (string) stream_get_contents($exporterHandle);
        fclose($exporterHandle);
    }
    $exporterIsZip = substr($exporterBytes, 0, 2) === 'PK';
    $exporterZipSize = strlen($exporterBytes);

    // Read the manifest back out of the *stored* archive rather than scanning the raw bytes. A ZIP
    // member is deflated by default, so the first version of this probe looked for plaintext in the
    // archive file and concluded the Persian text had been lost, when it was merely compressed. The
    // first version of that check therefore proved nothing either way.
    $manifestText = '';
    $manifestMembers = [];
    $evidenceMemberBytes = '';
    $extracted = tempnam(sys_get_temp_dir(), 'pettycash-probe-zip-');
    if ($extracted !== false && $exporterIsZip) {
        file_put_contents($extracted, $exporterBytes);
        $zip = new ZipArchive();
        if ($zip->open($extracted) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $manifestMembers[] = $name;
                if ($name === 'manifest.csv') {
                    $manifestText = (string) $zip->getFromIndex($i);
                } elseif (str_ends_with($name, 'receipt.pdf')) {
                    $evidenceMemberBytes = (string) $zip->getFromIndex($i);
                }
            }
            $zip->close();
        }
        @unlink($extracted);
    }
    $manifestPresent = $manifestText !== '';
    $persianPresent = str_contains($manifestText, 'کاربر آزمایشی');
    $manifestHasSheetTitle = str_contains($manifestText, 'Probe Sheet');
    $evidenceContentMatches = $evidenceMemberBytes === 'pdf-bytes';

    // Scratch files the exporter created must all be gone. The exporter names them itself, so this is
    // asserted by pattern rather than by asking the store what it handed out.
    $scratchAfter = array_values(array_filter(
        glob(sys_get_temp_dir() . '/pettycash-export-*') ?: [],
        static fn (string $path): bool => is_file($path)
    ));
    // Only files that appeared *during* the build count as leaked.
    $leftoverScratch = array_values(array_diff($scratchAfter, $scratchBefore));

    // --- 7b. scratch cleanup when the build FAILS, on the real filesystem ---
    //
    // The unit test covers this against the double; this proves it against the real path. A build that
    // throws must not leave a partially assembled archive in the shared system temp directory, and a
    // half-written evidence archive lying around is exactly the kind of thing the contract's
    // "removed after streaming" exists to prevent.
    $scratchBaseline = glob(sys_get_temp_dir() . '/pettycash-export-*') ?: [];
    $failingExporter = new \OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter($store);
    $failurePropagated = false;
    $failureMessage = '';
    $artifactsBeforeFailure = count($store->exists('probe-bulk-a.zip') ? ['x'] : []);
    try {
        $failingExporter->build([$entry], static function (): string {
            throw new RuntimeException('deliberate reader failure for the cleanup probe');
        });
    } catch (Throwable $e) {
        $failurePropagated = true;
        $failureMessage = $e->getMessage();
    }
    $scratchAfterFailure = array_values(array_filter(
        glob(sys_get_temp_dir() . '/pettycash-export-*') ?: [],
        static fn (string $p): bool => is_file($p)
    ));
    $leakedOnFailure = array_values(array_diff($scratchAfterFailure, $scratchBaseline));

    // --- 8. deleteAll() empties the folder ---
    //
    // Checked inside the scenario rather than after it, because the runner's own `finally` calls
    // `deleteAll()` — so a test that simply asserted the folder was empty afterwards would be asserting
    // its own cleanup, and would pass even if `deleteAll()` did nothing at all.
    $bulkA = $store->scratchPath('bulk-a.zip');
    $bulkB = $store->scratchPath('bulk-b.zip');
    file_put_contents($bulkA, 'bulk artifact one');
    file_put_contents($bulkB, 'bulk artifact two');
    $store->persist('probe-bulk-a.zip', $bulkA);
    $store->persist('probe-bulk-b.zip', $bulkB);
    $beforeBulk = $store->size('probe-bulk-a.zip') !== null && $store->size('probe-bulk-b.zip') !== null;
    $store->deleteAll();
    $afterBulk = $store->exists('probe-bulk-a.zip') || $store->exists('probe-bulk-b.zip')
        || $store->exists($exporterArtifact);
    foreach ([$bulkA, $bulkB] as $bulk) {
        if (is_file($bulk)) {
            @unlink($bulk);
        }
    }

    $report = [
        'store' => get_class($store),
        'failure' => [
            'propagated' => $failurePropagated,
            'message' => $failureMessage,
            'leakedScratch' => $leakedOnFailure,
            'noScratchLeak' => $leakedOnFailure === [],
        ],
        'deleteAll' => [
            'hadArtifacts' => $beforeBulk,
            'nothingLeft' => !$afterBulk,
        ],
        'scratch' => [
            'isFile' => $isFile,
            'isWritable' => $isWritable,
            'inSystemTemp' => $scratchDir === rtrim(sys_get_temp_dir(), '/'),
        ],
        'persist' => [
            'exists' => $exists,
            'size' => $size,
            'sizeMatchesSource' => $size === strlen($payload),
            'readBackIdentical' => $identical,
            'shaOfStored' => $shaOfStored,
            'shaOfOriginal' => $shaOfOriginal,
            'shaMatches' => $shaOfStored === $shaOfOriginal,
        ],
        'folder' => [
            'listing' => $listing,
            'listingIncludesArtifact' => in_array($artifact, $listing, true),
            'listingIsAllFiles' => count($listing) > 0,
        ],
        'replace' => [
            'sizeAfterFirstReplace' => $sizeAfterReplace,
            'sizeAfterSecondReplace' => $sizeAfterSecondReplace,
            'replacementMatched' => $replacementMatched,
        ],
        'delete' => [
            'sizeBeforeDelete' => $beforeDelete,
            'otherGone' => $otherGone,
            'unrelatedStillThere' => $probeStillThere,
            'deleteOfAbsentIsQuiet' => $deleteOfAbsentIsQuiet,
        ],
        'exporter' => [
            'artifactName' => $exporterArtifact,
            'stored' => $exporterStored,
            'artifactBytes' => $result->artifactBytes,
            'actualBytes' => $exporterZipSize,
            'sizeAgrees' => $result->artifactBytes === $exporterZipSize,
            'isZip' => $exporterIsZip,
            'manifestPresent' => $manifestPresent,
            'manifestMembers' => $manifestMembers,
            'manifestText' => $manifestText,
            'persianPresent' => $persianPresent,
            'manifestHasSheetTitle' => $manifestHasSheetTitle,
            'evidenceContentMatches' => $evidenceContentMatches,
            'manifestRows' => $result->manifestRowCount(),
            'memberPaths' => $result->memberPaths,
            'leftoverScratchFiles' => $leftoverScratch,
            // The distinctness proof: the scratch is gone, the artifact survived.
            'scratchRemoved' => $leftoverScratch === [],
            'artifactOutlivedScratch' => $exporterStored && $leftoverScratch === [],
        ],
    ];

    // Remove the probe scratch last. The runner's `finally` sweeps the AppData side; this is the disk
    // side, and leaving a stray temp file behind is the same litter problem.
    if (is_file($scratch)) {
        @unlink($scratch);
    }

    return $report;
}


/**
 * T111: `ExportService` against a real `IAppData` and a real `AppDataEvidenceStore`.
 *
 * The unit suite for the service runs entirely on `InMemoryTransientExportStore` and
 * `InMemoryPrivateFileStore`, and there are exactly two properties it structurally cannot prove:
 *
 * 1. **The scratch file is gone.** The service writes CSV bytes to `$store->scratchPath()` and removes
 *    them in a `finally`. An in-memory store can report "the scratch still exists"; only a real
 *    `tempnam()` file on a real filesystem can prove the removal actually happened. This is not
 *    hypothetical: the first version of `generateCsv()` never unlinked its scratch, the unit test
 *    caught it, and had the unit test not existed the copy would have sat in the system temp
 *    directory with nothing tied to its lifetime.
 * 2. **A missing evidence file really does throw.** `ExportService::readEvidence()` omits an evidence
 *    member by catching `RuntimeException`. That catch is only correct because
 *    `AppDataEvidenceStore::read()` throws `RuntimeException` on `NotFoundException` — an in-memory
 *    double returning `''` would have made the catch unreachable and the "empty but valid archive"
 *    guard silently untested.
 *
 * Usage: php tests/Integration/Persistence/roundtrip-runner.php exportservice
 */
function scenarioExportService(): array
{
    $appData = \OCP\Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('pettycash');
    $store = new \OCA\PettyCash\Infrastructure\Nextcloud\AppDataExportStore($appData);
    $evidenceStore = new \OCA\PettyCash\Infrastructure\Nextcloud\AppDataEvidenceStore($appData);

    $service = new \OCA\PettyCash\Application\Reporting\ExportService(
        new \OCA\PettyCash\Infrastructure\Export\CsvExporter(),
        new \OCA\PettyCash\Infrastructure\Export\XlsxExporter($store),
        new \OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter($store),
        $store,
        $evidenceStore
    );

    $admin = new \OCA\PettyCash\Application\Reporting\ReportScope(
        'probe-admin',
        [\OCA\PettyCash\Domain\Shared\Enum\Role::Administrator],
        [],
        true
    );
    $scoped = new \OCA\PettyCash\Application\Reporting\ReportScope(
        'probe-manager',
        [\OCA\PettyCash\Domain\Shared\Enum\Role::CostCenterManager],
        [11]
    );
    $refused = new \OCA\PettyCash\Application\Reporting\ReportScope(
        'probe-purchaser',
        [\OCA\PettyCash\Domain\Shared\Enum\Role::Purchaser],
        []
    );

    $rows = [
        ['id' => '1', 'title' => 'برگهٔ خردید', 'amount' => '125000'],
        ['id' => '2', 'title' => 'هزینهٔ ایاب', 'amount' => '42000'],
    ];

    $report = [];

    // --- 1. a refused export writes nothing to real app storage ---
    $before = listingOf($appData);
    $refusedThrew = false;
    $refusedReason = '';
    try {
        $service->generate($refused, 'sheet', 'csv', $rows);
    } catch (InvalidArgumentException $e) {
        $refusedThrew = true;
        $refusedReason = $e->getMessage();
    }
    $report['refusedThrew'] = $refusedThrew;
    $report['refusedReason'] = $refusedReason;
    $report['refusedAddedNothing'] = listingOf($appData) === $before;

    // --- 2. PDF is refused while T109 is blocked, and writes nothing ---
    $pdfDecision = $service->authorization($admin, 'report', 'pdf');
    $report['pdfAllowed'] = $pdfDecision['allowed'];
    $report['pdfReasonMentionsT109'] = str_contains($pdfDecision['reason'], 'T109');
    $report['pdfReasonMentionsFont'] = str_contains($pdfDecision['reason'], 'font');

    // --- 3. CSV through real app storage, and the scratch really is removed ---
    $scratchBefore = glob(sys_get_temp_dir() . '/pettycash-export-*') ?: [];
    $csv = $service->generate($admin, 'sheets', 'csv', $rows);
    $scratchAfter = glob(sys_get_temp_dir() . '/pettycash-export-*') ?: [];
    $leaked = array_values(array_diff($scratchAfter, $scratchBefore));

    $report['csvStored'] = $store->exists($csv['artifactName']);
    $report['csvFormat'] = $csv['format'];
    $report['csvRowCount'] = $csv['rowCount'];
    $report['csvByteSize'] = $csv['byteSize'];
    $report['csvSizeMatchesStore'] = $store->size($csv['artifactName']) === $csv['byteSize'];
    $report['csvLeakedScratch'] = $leaked;
    $report['csvPersisted'] = '';
    $handle = $store->read($csv['artifactName']);
    if (is_resource($handle)) {
        $report['csvPersisted'] = (string) stream_get_contents($handle);
        fclose($handle);
    }
    $report['csvHasPersian'] = str_contains($report['csvPersisted'], 'برگهٔ خردید');
    $report['csvRows'] = substr_count(trim($report['csvPersisted']), "\n") + 1;

    // --- 4. XLSX is a real workbook in real app storage ---
    $xlsx = $service->generate($admin, 'report', 'xlsx', $rows);
    $xlsxBytes = '';
    $xlsxHandle = $store->read($xlsx['artifactName']);
    if (is_resource($xlsxHandle)) {
        $xlsxBytes = (string) stream_get_contents($xlsxHandle);
        fclose($xlsxHandle);
    }
    $report['xlsxStored'] = $store->exists($xlsx['artifactName']);
    $report['xlsxIsZipContainer'] = substr($xlsxBytes, 0, 2) === 'PK';
    $report['xlsxRowCount'] = $xlsx['rowCount'];

    // --- 5. ZIP reads real evidence out of the real evidence store ---
    // Written through putEvidence(), not put(). putEvidence() creates the transaction/revision folders
    // and returns the stored key (`evidence/<tx>/<rev>/<file>`), which is the form findFile() expects —
    // it strips the leading `evidence/` because the walk starts inside that folder. The raw put() takes a
    // key relative to the evidence folder instead and does not strip the prefix, so passing a prefixed
    // key to it writes to a path read() can never reach. Nothing in lib/ calls put(); this probe did,
    // and it is why the first attempt failed to create the file.
    $realKey = $evidenceStore->putEvidence(
        bin2hex(random_bytes(6)),
        1,
        'receipt.pdf',
        'real-evidence-bytes'
    );

    $entry = new \OCA\PettyCash\Infrastructure\Export\EvidenceArchiveEntry(
        'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
        'receipt.pdf',
        'application/pdf',
        9,
        str_repeat('b', 64),
        true,
        'probe-actor',
        'کاربر آزمایشی',
        new DateTimeImmutable('2026-03-15T10:30:00+00:00'),
        'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb',
        1,
        'cccccccc-3333-4333-8333-cccccccccccc',
        'dddddddd-4444-4444-8444-dddddddddddd',
        'Probe Sheet',
        $realKey
    );

    $zip = $service->generate($admin, 'evidence', 'zip', [], [$entry]);
    $report['zipStored'] = $store->exists($zip['artifactName']);
    $report['zipRowCount'] = $zip['rowCount'];
    $report['zipIncludedRealEvidence'] = $zip['rowCount'] === 1;
    $report['zipEvidenceMember'] = zipMemberContaining($store, $zip['artifactName'], 'receipt.pdf');
    $report['zipManifestHasPersian'] = zipMemberContaining($store, $zip['artifactName'], 'manifest.csv') !== ''
        && str_contains(zipMemberContaining($store, $zip['artifactName'], 'manifest.csv'), 'کاربر آزمایشی');

    // --- 6. a genuinely absent evidence key is omitted, not archived as an empty member ---
    $absentEntry = new \OCA\PettyCash\Infrastructure\Export\EvidenceArchiveEntry(
        'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
        'ghost.pdf',
        'application/pdf',
        9,
        str_repeat('c', 64),
        true,
        'probe-actor',
        'کاربر آزمایشی',
        new DateTimeImmutable('2026-03-15T10:30:00+00:00'),
        'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb',
        1,
        'cccccccc-3333-4333-8333-cccccccccccc',
        'dddddddd-4444-4444-8444-dddddddddddd',
        'Probe Sheet',
        'evidence/' . bin2hex(random_bytes(6)) . '/1/never-written.pdf'
    );
    $emptyZip = $service->generate($admin, 'evidence', 'zip', [], [$absentEntry]);
    $report['absentRowCount'] = $emptyZip['rowCount'];
    $emptyBytes = '';
    $emptyHandle = $store->read($emptyZip['artifactName']);
    if (is_resource($emptyHandle)) {
        $emptyBytes = (string) stream_get_contents($emptyHandle);
        fclose($emptyHandle);
    }
    // A valid archive with no evidence member: not the "looks like evidence, is not" artifact.
    $report['absentZipIsValidButEmpty'] = substr($emptyBytes, 0, 2) === 'PK' && $emptyZip['rowCount'] === 0;

    // --- 7. a manager with scope is allowed where a purchaser is refused ---
    $report['scopedAllowed'] = $service->authorization($scoped, 'sheet', 'csv')['allowed'];
    $report['refusedRoleAllowed'] = $service->authorization($refused, 'sheet', 'csv')['allowed'];

    // --- 8. cleanup empties real app storage ---
    $beforeCleanup = listingOf($appData);
    $service->cleanup();
    $afterCleanup = listingOf($appData);
    $report['cleanupEmptiedStore'] = $afterCleanup === [];
    $report['cleanupRemovedEverything'] = count($beforeCleanup) > 0 && $afterCleanup === [];
    $report['cleanupSafeWhenEmpty'] = (static function () use ($service): bool {
        $service->cleanup();
        return true;
    })();

    return $report;
}

/**
 * @return list<string> the export artifact names currently in real app storage
 *
 * No parameter type: the object `IAppDataFactory::get()` returns is the internal
 * `OC\Files\AppData\AppData`, which is not an instance of the `OCP\Files\AppData\IAppData`
 * interface, so hinting the interface makes the helper uncallable from the only place it is used.
 */
/**
 * T332: `SheetService::listSheets()` over the real repository, asked for more than one page.
 * T333: the same service asked for the four facets FR-064 names.
 *
 * ## Why this scenario exists separately from `expense`
 *
 * `expense` writes one sheet. One sheet is a single page, so every `total` derived from that page is
 * arithmetically indistinguishable from the owner's real count -- which is why the defect survived
 * there and why a fixture-driven contract test cannot catch it either. The count is only observable
 * when the owner holds more sheets than a page can carry, so this scenario writes 26 and reads two
 * pages.
 *
 * ## What is measured, and against what
 *
 * The pages and their `total` come from the real `SheetService` over the real
 * `DbExpenseSheetRepository`. The count they are compared against is a plain `COUNT(*)` over the table
 * (`$rawOwnerSheetCount`), not the repository's own count: an assertion of the form "the service agrees
 * with the repository" would be satisfied by two halves of the same defect agreeing with each other,
 * and that is exactly the shape the original `count($sheets)` estimate had.
 *
 * ## Why a probe owner
 *
 * See `PROBE_OWNER_UID`. The count is an exact number rather than a comparison, because nothing else
 * in this shared instance owns those rows.
 *
 * ## Why the facets are measured here rather than only in a contract test
 *
 * A facet that reaches SQL as a named parameter is not the same question as a facet compared in PHP.
 * The currency facet is a **join** to `pettycash_currencies` and the date bounds are
 * `PARAM_DATETIME` comparisons against a `DATETIME` column, and both can be wrong in ways an
 * in-memory double cannot reproduce: a `createNamedParameter()` that silently emits an unbound token, a
 * `to` bound read as midnight and excluding every row created during the day it names, a joined `*`
 * that becomes ambiguous. Only this scenario runs the real statement.
 *
 * ## What it still does not do
 *
 * It does not bind the query parameters through HTTP, so the transport half -- that `status`, `currency`,
 * `from` and `to` arrive under those names at all, and that an unrecognised value is refused -- is
 * measured by the contract suite. This block answers the narrower question of what the repository does
 * with a filter once it has one.
 */
function scenarioSheetList(
    DbCurrencyRepository $currencies,
    DbAccountRepository $accounts,
    DbExpenseSheetRepository $sheets,
    SheetService $sheetService,
    DateTimeImmutable $now,
    callable $track,
    callable $surrogateIdOfCurrency,
    callable $currencyCode,
    callable $rawOwnerSheetCount,
    callable $backdateCreatedAt,
    callable $sheetServiceFor
): array {
    $code = $currencyCode();
    $currencies->save(Currency::create($code, 'Round Trip Sheet List Currency', 0));
    $track('pettycash_currencies', $surrogateIdOfCurrency($code));
    $currency = $currencies->findByCode($code);

    $account = PettyCashAccount::create((string) Uuid::random(), PROBE_OWNER_UID, $currency);
    $accounts->save($account);
    $track('pettycash_accounts', (int) $account->dbId);

    // 26 sheets for one owner against a page size of 25: one full page, one row beyond it, and a
    // second page that exists but holds a single row. The two statuses are what let the count's
    // predicate be checked as well as its result -- a count that ignored `status` would still answer
    // the right number for an unfiltered request.
    $pageSize = 25;
    $written = 26;
    $open = 20;
    for ($i = 0; $i < $written; $i++) {
        $sheet = new ExpenseSheet(
            (string) Uuid::random(),
            PROBE_OWNER_UID,
            $account->id,
            $account->currencyCode(),
            $i < $open ? SheetStatus::Open : SheetStatus::CostCenter,
            title: LeakedRows::ROUND_TRIP_SHEET_TITLE
        );
        $sheets->save($sheet);
        $track('pettycash_sheets', (int) $sheet->dbId);
    }

    /** One page as the service answered it, flattened for the JSON report. */
    $pageOf = static function (array $page): array {
        return [
            'itemCount' => count($page['items']),
            'total' => $page['total'],
            'ids' => array_map(static fn (ExpenseSheet $s): string => $s->id, $page['items']),
        ];
    };

    $pageOne = $pageOf($sheetService->listSheets(1, $pageSize));
    $pageTwo = $pageOf($sheetService->listSheets(2, $pageSize));
    // Past the end. Its rows are empty, but its `total` is not: this is the case that used to report
    // zero, because a total taken from an empty page is zero, and it is the shape a client reads when
    // it asks for the page after the last one.
    $pageThree = $pageOf($sheetService->listSheets(3, $pageSize));

    $everyOwned = $sheets->findByOwnerUid(PROBE_OWNER_UID, null, 0, $written * 4);
    $otherOwner = $sheets->countByOwnerUid('t332-sheet-list-other-owner');

    // ===== T333: the FR-064 facets, over the same real statement =====

    // Two currencies, because a currency facet measured against a single currency cannot distinguish
    // "filtered" from "matched everything": every sheet would carry the same code.
    $firstCode = $code;
    $secondCode = $currencyCode();
    while ($secondCode === $firstCode) {
        $secondCode = $currencyCode();
    }
    $currencies->save(Currency::create($secondCode, 'Round Trip Second Sheet Currency', 0));
    $track('pettycash_currencies', $surrogateIdOfCurrency($secondCode));
    $secondCurrency = $currencies->findByCode($secondCode);

    // One account per currency. The sheet's currency is a property of the sheet, but its `account_id`
    // is a separate column, and a sheet pointing at an account denominated in something else is exactly
    // the inconsistency `ExpenseSheet` refuses at the aggregate boundary -- so each sheet's account
    // carries its own currency.
    $firstAccount = PettyCashAccount::create((string) Uuid::random(), T333_FILTER_PROBE_UID, $currency);
    $accounts->save($firstAccount);
    $track('pettycash_accounts', (int) $firstAccount->dbId);

    $secondAccount = PettyCashAccount::create((string) Uuid::random(), T333_FILTER_PROBE_UID, $secondCurrency);
    $accounts->save($secondAccount);
    $track('pettycash_accounts', (int) $secondAccount->dbId);

    // Four sheets that between them make every facet two-sided: a status with two members on each side,
    // a currency with three and one, and a creation day with three today and one yesterday.
    $openToday = new ExpenseSheet(
        (string) Uuid::random(),
        T333_FILTER_PROBE_UID,
        $firstAccount->id,
        $firstAccount->currencyCode(),
        SheetStatus::Open,
        title: LeakedRows::ROUND_TRIP_SHEET_TITLE
    );
    $sheets->save($openToday);
    $track('pettycash_sheets', (int) $openToday->dbId);

    $costCenterToday = new ExpenseSheet(
        (string) Uuid::random(),
        T333_FILTER_PROBE_UID,
        $firstAccount->id,
        $firstAccount->currencyCode(),
        SheetStatus::CostCenter,
        title: LeakedRows::ROUND_TRIP_SHEET_TITLE
    );
    $sheets->save($costCenterToday);
    $track('pettycash_sheets', (int) $costCenterToday->dbId);

    $costCenterYesterday = new ExpenseSheet(
        (string) Uuid::random(),
        T333_FILTER_PROBE_UID,
        $firstAccount->id,
        $firstAccount->currencyCode(),
        SheetStatus::CostCenter,
        title: LeakedRows::ROUND_TRIP_SHEET_TITLE
    );
    $sheets->save($costCenterYesterday);
    $track('pettycash_sheets', (int) $costCenterYesterday->dbId);
    // Back-dated by SQL because `save()` always stamps `now`: a date facet measured only against rows
    // created in the same second cannot tell `from` from `to` apart, and both bounds could be wrong in
    // the same direction.
    $backdateCreatedAt((int) $costCenterYesterday->dbId, $now->modify('-1 day')->format('Y-m-d H:i:s'));

    $openInOtherCurrency = new ExpenseSheet(
        (string) Uuid::random(),
        T333_FILTER_PROBE_UID,
        $secondAccount->id,
        $secondAccount->currencyCode(),
        SheetStatus::Open,
        title: LeakedRows::ROUND_TRIP_SHEET_TITLE
    );
    $sheets->save($openInOtherCurrency);
    $track('pettycash_sheets', (int) $openInOtherCurrency->dbId);

    $today = $now->format('Y-m-d');
    $yesterday = $now->modify('-1 day')->format('Y-m-d');
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');

    $filteredService = $sheetServiceFor(T333_FILTER_PROBE_UID);
    $facet = static function (?SheetListFilter $filter) use ($filteredService): array {
        $page = $filteredService->listSheets(1, 25, $filter);

        return [
            'total' => $page['total'],
            'ids' => array_map(static fn (ExpenseSheet $s): string => $s->id, $page['items']),
        ];
    };

    return [
        'sheetList' => [
            'written' => $written,
            'pageSize' => $pageSize,
            // The table, by SQL.
            'rawOwnerRowCount' => $rawOwnerSheetCount(PROBE_OWNER_UID),
            // The same question through the repository's count.
            'ownerScopedCount' => $sheets->countByOwnerUid(PROBE_OWNER_UID),
            'openCount' => $sheets->countByOwnerUid(PROBE_OWNER_UID, new SheetListFilter(SheetStatus::Open)),
            'submittedCount' => $sheets->countByOwnerUid(PROBE_OWNER_UID, new SheetListFilter(SheetStatus::CostCenter)),
            // An owner with no sheets at all. Zero, not an exception: every new purchaser arrives here.
            'otherOwnerCount' => $otherOwner,
            'pageOne' => $pageOne,
            'pageTwo' => $pageTwo,
            'pageThree' => $pageThree,
            'everyOwnedId' => array_map(static fn (ExpenseSheet $s): string => $s->id, $everyOwned),
        ],
        'sheetFacets' => [
            // The known shape of the probe set, reported rather than assumed, so the facet assertions
            // below are checked against what was written and not against this file's own arithmetic.
            'owner' => T333_FILTER_PROBE_UID,
            'firstCurrency' => (string) $firstAccount->currencyCode(),
            'secondCurrency' => (string) $secondAccount->currencyCode(),
            'written' => 4,
            'today' => $today,
            'yesterday' => $yesterday,
            'openToday' => $openToday->id,
            'costCenterToday' => $costCenterToday->id,
            'costCenterYesterday' => $costCenterYesterday->id,
            'openInOtherCurrency' => $openInOtherCurrency->id,
            'all' => $facet(null),
            'statusOpen' => $facet(new SheetListFilter(SheetStatus::Open)),
            'statusCostCenter' => $facet(new SheetListFilter(SheetStatus::CostCenter)),
            // Nothing matches, which is the case a filter-appears-to-work test cannot distinguish from a
            // filter that was ignored.
            'statusCompleted' => $facet(new SheetListFilter(SheetStatus::Completed)),
            'currencyFirst' => $facet(new SheetListFilter(null, $firstAccount->currencyCode())),
            'currencySecond' => $facet(new SheetListFilter(null, $secondAccount->currencyCode())),
            // A whole-day bound, exactly as the controller builds one from `?from=2026-10-04`.
            'fromToday' => $facet(new SheetListFilter(from: new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('UTC')))),
            'toToday' => $facet(new SheetListFilter(to: new DateTimeImmutable($today . ' 23:59:59', new DateTimeZone('UTC')))),
            'justYesterday' => $facet(new SheetListFilter(
                from: new DateTimeImmutable($yesterday . ' 00:00:00', new DateTimeZone('UTC')),
                to: new DateTimeImmutable($yesterday . ' 23:59:59', new DateTimeZone('UTC'))
            )),
            // `to` at midnight: the reading this scenario exists to catch, because it would exclude
            // every row created during the very day it names.
            'toTodayAtMidnight' => $facet(new SheetListFilter(to: new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('UTC')))),
            'fromTomorrow' => $facet(new SheetListFilter(
                from: new DateTimeImmutable($tomorrow . ' 00:00:00', new DateTimeZone('UTC'))
            )),
            // Two facets at once. A filter object that resolved only its first argument would answer
            // this identically to `statusOpen`.
            'openInSecondCurrency' => $facet(new SheetListFilter(SheetStatus::Open, $secondAccount->currencyCode())),
            'openTodayOnOrBeforeYesterday' => $facet(new SheetListFilter(
                SheetStatus::Open,
                null,
                null,
                new DateTimeImmutable($yesterday . ' 23:59:59', new DateTimeZone('UTC'))
            )),
        ],
    ];
}

function listingOf(object $appData): array
{
    try {
        $folder = $appData->getFolder('exports');
    } catch (\OCP\Files\NotFoundException) {
        return [];
    }

    $names = array_map(
        static fn ($child): string => $child->getName(),
        array_values(array_filter(
            $folder->getDirectoryListing(),
            static fn ($child): bool => $child instanceof \OCP\Files\SimpleFS\ISimpleFile
        ))
    );
    sort($names);

    return $names;
}

/**
 * Read one member out of a stored archive, by suffix.
 *
 * Extracting rather than substring-scanning the raw bytes: ZIP members are deflated, so scanning the
 * archive file for plaintext would report a healthy archive as empty.
 */
function zipMemberContaining(\OCA\PettyCash\Infrastructure\Nextcloud\AppDataExportStore $store, string $artifact, string $suffix): string
{
    $bytes = '';
    $handle = $store->read($artifact);
    if (is_resource($handle)) {
        $bytes = (string) stream_get_contents($handle);
        fclose($handle);
    }

    $path = tempnam(sys_get_temp_dir(), 'pettycash-probe-member-');
    if ($path === false || substr($bytes, 0, 2) !== 'PK') {
        return '';
    }

    file_put_contents($path, $bytes);
    $found = '';
    $zip = new ZipArchive();
    if ($zip->open($path) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, $suffix)) {
                $found = (string) $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
    }
    @unlink($path);

    return $found;
}
