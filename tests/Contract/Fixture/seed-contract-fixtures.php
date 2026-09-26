<?php

declare(strict_types=1);

/**
 * Seeds the reference and business fixtures the live-HTTP OCS contract tests
 * need, and prints a JSON manifest of the resulting public UUIDs on stdout.
 *
 * This runs as its own process because it bootstraps the Nextcloud server. The
 * PHPUnit process deliberately does not: `tests/bootstrap.php` wires a
 * lightweight container of in-memory doubles, and loading `lib/base.php` into
 * that process would replace the global server and break every other suite.
 *
 * Usage (from the app root, inside the container):
 *   php tests/Contract/Fixture/seed-contract-fixtures.php <nextcloud-user> [draft|submitted]
 *
 * The optional second argument selects the state the fixture business records are
 * left in, because a single transaction cannot be both an editable draft and a
 * transaction awaiting cost-center review:
 *
 *   draft      an OPEN sheet holding one DRAFT transaction. Use it for the sheet,
 *              transaction, and evidence lifecycle assertions.
 *   submitted  a sheet already in cost-center review holding one
 *              AWAITING_COST_CENTER_REVIEW transaction, with the acting user
 *              assigned as its cost-center manager. Use it for the management
 *              decision assertions.
 *   correction a transaction returned to its owner, in
 *              AWAITING_PURCHASER_CORRECTION with the evidence its revision
 *              already carries. Use it for the correction and resubmit
 *              assertions.
 *   usd       the same `draft` baseline plus a second sheet, transaction, and
 *              revision denominated in USD. Use it for the currency read-back
 *              assertions, which need a sheet whose currency is not the rial
 *              default.
 *   scope     the same `submitted` baseline plus a second cost center the acting
 *              user holds no assignment for, with a transaction inside it awaiting
 *              cost-center review. Use it for the out-of-scope authorization
 *              assertions, which cannot be expressed while the only cost center in
 *              the fixture is one the user manages.
 *
 * Every run is a reset, not just a first-time seed: the fixture rows are written
 * back to the requested baseline and the rows the suites create while exercising
 * them (decisions, evidence, and transaction audit events) are removed first.
 * That is what makes the contract tests independent of each other and of the
 * order PHPUnit happens to run them in, instead of asserting against state that
 * an earlier test left behind.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This seeder must be run from the command line.\n");
    exit(1);
}

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
if (!is_file($nextcloudRoot . '/lib/base.php')) {
    fwrite(STDERR, "Cannot locate Nextcloud at {$nextcloudRoot}; set NEXTCLOUD_ROOT.\n");
    exit(1);
}

require_once $nextcloudRoot . '/lib/base.php';

$userUid = $argv[1] ?? getenv('PETTYCASH_TEST_USER') ?: '';
if ($userUid === '') {
    fwrite(STDERR, "Usage: seed-contract-fixtures.php <nextcloud-user> [draft|submitted]\n");
    exit(1);
}

$state = $argv[2] ?? 'draft';
if (!in_array($state, ['draft', 'submitted', 'correction', 'usd', 'scope'], true)) {
    fwrite(STDERR, "Unknown fixture state '{$state}'; expected draft, submitted, correction, usd, or scope.\n");
    exit(1);
}

$userManager = \OCP\Server::get(\OCP\IUserManager::class);
if ($userManager->get($userUid) === null) {
    fwrite(STDERR, "Nextcloud user '{$userUid}' does not exist.\n");
    exit(1);
}

$db = \OCP\Server::get(\OCP\IDBConnection::class);

/** Fixed public UUIDs keep the manifest stable across runs. */
const FIXTURE_IDS = [
    'currency'        => '11111111-1111-4111-8111-111111111111',
    'costCenter'      => '22222222-2222-4222-8222-222222222222',
    'expenseType'     => '33333333-3333-4333-8333-333333333333',
    'account'         => '44444444-4444-4444-8444-444444444444',
    'sheet'           => '55555555-5555-4555-8555-555555555555',
    'transaction'     => '66666666-6666-4666-8666-666666666666',
    'revision'        => '77777777-7777-4777-8777-777777777777',
    'secondAccount'   => '88888888-8888-4888-8888-888888888888',
    // An account belonging to somebody who is not the acting user.
    //
    // The ledger states what a named person was given and what they spent, so "not yours" is the
    // answer that has to be enforced. Without a row owned by another user the only way to test that
    // is to assert on an id that does not exist, which is indistinguishable from a 404 for a missing
    // account and would pass against an endpoint that never checks ownership at all. The uid is a
    // plain string that matches no Nextcloud account on purpose: the endpoint compares it to the
    // session uid and never resolves it, so a real user would be needed by nothing.
    'foreignAccount'  => 'abababab-1111-4111-8111-111111111111',
    'secondCurrency'  => '99999999-9999-4999-8999-999999999999',
    'managerRole'     => 'bbbbbbbb-1111-4111-8111-111111111111',
    'seniorRole'      => 'bbbbbbbb-2222-4222-8222-222222222222',
    'evidence'        => 'cccccccc-1111-4111-8111-111111111111',
    // Two ledger rows on the primary account, one of each movement type.
    //
    // `GET /accounts/{id}/movements` is the only read that states what a person was given and what
    // they spent, so a fixture with an empty ledger could not tell a working projection from one
    // that silently returns nothing — which is exactly the failure an empty table invites. The
    // charge carries no `source_revision_id` and the consumption carries the fixture revision, so
    // the two branches of the schema are both represented and the sign of each is distinguishable.
    'chargeMovement'  => 'cccccccc-2222-4222-8222-222222222222',
    'consumptionMovement' => 'cccccccc-3333-4333-8333-333333333333',
    // A second business trio denominated in USD. FR-021 makes the sheet the
    // authoritative currency of every transaction on it, so a non-IRR sheet is
    // what proves the revision read path resolves the real currency instead of
    // assuming one.
    'usdSheet'        => 'dddddddd-1111-4111-8111-111111111111',
    'usdTransaction'  => 'dddddddd-2222-4222-8222-222222222222',
    'usdRevision'     => 'dddddddd-3333-4333-8333-333333333333',
    // A second cost center the acting user holds no assignment for, with a
    // transaction inside it awaiting cost-center review. FR-004/FR-005 require the
    // decision path to enforce scope server-side, so a user assigned only to CC-100
    // must be refused on a CC-200 transaction; a fixture whose only cost center is
    // the one the user manages cannot tell a real scope check from a vacuous one.
    'otherCostCenter' => 'eeeeeeee-1111-4111-8111-111111111111',
    'otherSheet'      => 'eeeeeeee-4444-4444-8444-444444444444',
    'otherTransaction' => 'eeeeeeee-2222-4222-8222-222222222222',
    'otherRevision'   => 'eeeeeeee-3333-4333-8333-333333333333',
];

$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
$audit = ['created_at' => $now, 'created_by' => $userUid, 'updated_at' => $now, 'updated_by' => $userUid];

/**
 * Write a row to a known state, inserting it when it is absent and overwriting the
 * given columns when it is already there.
 *
 * `$keyColumn` defaults to `public_id`, but `pettycash_currencies` has no `public_id` —
 * `data-model.md` defines `Currency` without one — so currencies are keyed by
 * their unique `code` instead.
 */
function upsert(\OCP\IDBConnection $db, string $table, string $keyColumn, string $keyValue, array $values): void
{
    // One builder for the whole statement: a named parameter created by a
    // different builder instance is never bound and is emitted verbatim, which
    // produces SQL like `... near ':dcValue1 LIMIT 1'`.
    $select = $db->getQueryBuilder();
    $existing = $select->select('id')
        ->from($table)
        ->where($select->expr()->eq($keyColumn, $select->createNamedParameter($keyValue)))
        ->setMaxResults(1)
        ->executeQuery()
        ->fetchAssociative();

    if ($existing === false) {
        $insert = $db->getQueryBuilder()->insert($table);
        foreach ($values as $column => $value) {
            $type = is_bool($value) ? \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL : \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR;
            $insert->setValue($column, $insert->createNamedParameter($value, $type));
        }
        $insert->executeStatement();
        return;
    }

    $update = $db->getQueryBuilder()->update($table);
    foreach ($values as $column => $value) {
        $type = is_bool($value) ? \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL : \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR;
        $update->set($column, $update->createNamedParameter($value, $type));
    }
    $update->where($update->expr()->eq('id', $update->createNamedParameter((int) $existing['id'])))
        ->executeStatement();
}

/** Remove the rows the suites create while exercising a fixture. */
function deleteWhere(\OCP\IDBConnection $db, string $table, string $column, int $value): void
{
    $delete = $db->getQueryBuilder()->delete($table);
    $delete->where($delete->expr()->eq($column, $delete->createNamedParameter($value)))
        ->executeStatement();
}

function scalar(\OCP\IDBConnection $db, string $table, string $keyValue, string $column, string $keyColumn = 'public_id'): int
{
    $qb = $db->getQueryBuilder();
    $row = $qb->select('id')
        ->from($table)
        ->where($qb->expr()->eq($keyColumn, $qb->createNamedParameter($keyValue)))
        ->setMaxResults(1)
        ->executeQuery()
        ->fetchAssociative();

    if ($row === false) {
        fwrite(STDERR, "Expected seeded row {$table}/{$keyValue} is missing.\n");
        exit(1);
    }

    return (int)$row['id'];
}

/** Read one column of a fixture row, so the manifest can report live lock values. */
function columnValue(\OCP\IDBConnection $db, string $table, string $keyValue, string $column, string $keyColumn = 'public_id'): int
{
    $qb = $db->getQueryBuilder();
    $row = $qb->select($column)
        ->from($table)
        ->where($qb->expr()->eq($keyColumn, $qb->createNamedParameter($keyValue)))
        ->setMaxResults(1)
        ->executeQuery()
        ->fetchAssociative();

    if ($row === false) {
        fwrite(STDERR, "Expected seeded row {$table}/{$keyValue} is missing.\n");
        exit(1);
    }

    return (int)$row[$column];
}

// --- Reference data -------------------------------------------------------

upsert($db, 'pettycash_currencies', 'code', 'IRR', array_merge([
    'code' => 'IRR',
    'name' => 'Iranian Rial',
    'minor_unit' => 0,
    'active' => true,
], $audit));

// A second currency lets the suite exercise multi-currency account selection.
upsert($db, 'pettycash_currencies', 'code', 'USD', array_merge([
    'code' => 'USD',
    'name' => 'US Dollar',
    'minor_unit' => 2,
    'active' => true,
], $audit));

upsert($db, 'pettycash_cost_centers', 'public_id', FIXTURE_IDS['costCenter'], array_merge([
    'public_id' => FIXTURE_IDS['costCenter'],
    'code' => 'CC-100',
    'name' => 'Contract Fixture Cost Center',
    'description' => 'Seeded for the OCS contract tests.',
    'valid_from' => $today,
    'valid_to' => null,
    'active' => true,
    'lock_version' => 0,
], $audit));

upsert($db, 'pettycash_expense_types', 'public_id', FIXTURE_IDS['expenseType'], array_merge([
    'public_id' => FIXTURE_IDS['expenseType'],
    'title' => 'Contract Fixture Expense Type',
    'description' => 'Seeded for the OCS contract tests.',
    'evidence_required' => false,
    'active' => true,
    'lock_version' => 0,
], $audit));

// --- Accounts -------------------------------------------------------------

$currencyDbId = scalar($db, 'pettycash_currencies', 'IRR', 'id', 'code');
$secondCurrencyDbId = scalar($db, 'pettycash_currencies', 'USD', 'id', 'code');
$costCenterDbId = scalar($db, 'pettycash_cost_centers', FIXTURE_IDS['costCenter'], 'id');
$expenseTypeDbId = scalar($db, 'pettycash_expense_types', FIXTURE_IDS['expenseType'], 'id');

upsert($db, 'pettycash_accounts', 'public_id', FIXTURE_IDS['account'], array_merge([
    'public_id' => FIXTURE_IDS['account'],
    'user_uid' => $userUid,
    'currency_id' => $currencyDbId,
    // A healthy opening balance so balance-independent submission is exercised
    // against a solvent account as well as an exhausted one.
    'balance_minor' => 100000000,
    'active' => true,
    'lock_version' => 0,
], $audit));

upsert($db, 'pettycash_accounts', 'public_id', FIXTURE_IDS['secondAccount'], array_merge([
    'public_id' => FIXTURE_IDS['secondAccount'],
    'user_uid' => $userUid,
    'currency_id' => $secondCurrencyDbId,
    'balance_minor' => 100000,
    'active' => true,
    'lock_version' => 0,
], $audit));

upsert($db, 'pettycash_accounts', 'public_id', FIXTURE_IDS['foreignAccount'], array_merge([
    'public_id' => FIXTURE_IDS['foreignAccount'],
    'user_uid' => 'pettycash-somebody-else',
    'currency_id' => $currencyDbId,
    'balance_minor' => 5000,
    'active' => true,
    'lock_version' => 0,
], $audit));

$accountDbId = scalar($db, 'pettycash_accounts', FIXTURE_IDS['account'], 'id');

// --- Management roles for the acting user ---------------------------------
//
// The decision assertions need a user who actually holds the review role for the
// fixture cost center; FR-002 permits reviewing one's own transaction only when
// the role, scope, and stage all line up independently.

upsert($db, 'pettycash_role_assignments', 'public_id', FIXTURE_IDS['managerRole'], array_merge([
    'public_id' => FIXTURE_IDS['managerRole'],
    'user_uid' => $userUid,
    'role' => 'COST_CENTER_MANAGER',
    'cost_center_id' => $costCenterDbId,
    'valid_from' => $today,
    'valid_to' => null,
    'active' => true,
], $audit));

upsert($db, 'pettycash_role_assignments', 'public_id', FIXTURE_IDS['seniorRole'], array_merge([
    'public_id' => FIXTURE_IDS['seniorRole'],
    'user_uid' => $userUid,
    'role' => 'SENIOR_MANAGER',
    'cost_center_id' => $costCenterDbId,
    'valid_from' => $today,
    'valid_to' => null,
    'active' => true,
], $audit));

// --- Business records -----------------------------------------------------

// The sheet status is the earliest outstanding stage, so it is a `SheetStatus` case
// value (COST_CENTER, CORRECTION, …) and never a transaction status such as
// AWAITING_COST_CENTER_REVIEW: `SheetStatus` cannot represent the latter, and a row
// carrying it fails to hydrate as soon as anything reads the sheet.
$sheetBaseline = match ($state) {
    'draft', 'usd' => ['status' => 'OPEN', 'submitted_total_minor' => null, 'first_submitted_at' => null],
    'correction' => ['status' => 'CORRECTION', 'submitted_total_minor' => 100000, 'first_submitted_at' => $now],
    default => ['status' => 'COST_CENTER', 'submitted_total_minor' => 100000, 'first_submitted_at' => $now],
};

upsert($db, 'pettycash_sheets', 'public_id', FIXTURE_IDS['sheet'], array_merge([
    'public_id' => FIXTURE_IDS['sheet'],
    'title' => 'Contract Fixture Sheet',
    'owner_uid' => $userUid,
    'account_id' => $accountDbId,
    'currency_id' => $currencyDbId,
    'management_completed_at' => null,
    'finance_started_at' => null,
    'completed_at' => null,
    'lock_version' => 0,
], $sheetBaseline, [
    'created_at' => $now,
]));

$sheetDbId = scalar($db, 'pettycash_sheets', FIXTURE_IDS['sheet'], 'id');

$transactionBaseline = match ($state) {
    'draft', 'usd' => ['current_stage' => 'COST_CENTER', 'current_status' => 'DRAFT'],
    'correction' => ['current_stage' => 'CORRECTION', 'current_status' => 'AWAITING_PURCHASER_CORRECTION'],
    default => ['current_stage' => 'COST_CENTER', 'current_status' => 'AWAITING_COST_CENTER_REVIEW'],
};

upsert($db, 'pettycash_transactions', 'public_id', FIXTURE_IDS['transaction'], array_merge([
    'public_id' => FIXTURE_IDS['transaction'],
    'sheet_id' => $sheetDbId,
    'owner_uid' => $userUid,
    'final_outcome' => null,
    'created_at' => $now,
    'updated_at' => $now,
    'lock_version' => 0,
], $transactionBaseline));

$transactionDbId = scalar($db, 'pettycash_transactions', FIXTURE_IDS['transaction'], 'id');

upsert($db, 'pettycash_transaction_revisions', 'public_id', FIXTURE_IDS['revision'], [
    'public_id' => FIXTURE_IDS['revision'],
    'transaction_id' => $transactionDbId,
    'revision_number' => 1,
    'expense_date' => $today,
    'amount_minor' => 100000,
    'description' => 'Contract fixture expense',
    'cost_center_id' => $costCenterDbId,
    'cost_center_code' => 'CC-100',
    'cost_center_name' => 'Contract Fixture Cost Center',
    'expense_type_id' => $expenseTypeDbId,
    'expense_type_title' => 'Contract Fixture Expense Type',
    // A submitted revision is routed with the manager snapshot taken at
    // activation; a draft has not been assigned to anyone yet.
    'manager_uid' => $state === 'draft' ? null : $userUid,
    'senior_manager_uid' => $state === 'draft' ? null : $userUid,
    'created_at' => $now,
    'created_by' => $userUid,
    'activated_at' => $now,
    'supersedes_revision_id' => null,
]);

$revisionDbId = scalar($db, 'pettycash_transaction_revisions', FIXTURE_IDS['revision'], 'id');

// Point the transaction at its active revision now that the revision exists.
$link = $db->getQueryBuilder()->update('pettycash_transactions');
$link->set('active_revision_id', $link->createNamedParameter($revisionDbId))
    ->where($link->expr()->eq('id', $link->createNamedParameter($transactionDbId)))
    ->executeStatement();

// --- The account ledger ---------------------------------------------------
//
// The opening balance is written directly on the account row, which is a balance with no history
// behind it — and a ledger that begins mid-story cannot show that a charge and a consumption are
// distinguishable, that `balance_after` moves in the right direction, or that the last row agrees
// with the balance the account actually carries. Two rows make all three checkable.
//
// The figures are chosen so the ledger reconciles with the account row *and* leaves that row's
// balance at 100,000,000, which the submission and negative-balance assertions already depend on:
// a charge of 100,100,000 followed by the fixture revision's 100,000 consumption ends at exactly
// 100,000,000. Changing the account balance to make the numbers easier would have quietly moved
// the baseline for every other contract test that reads it.
upsert($db, 'pettycash_account_movements', 'public_id', FIXTURE_IDS['chargeMovement'], [
    'public_id' => FIXTURE_IDS['chargeMovement'],
    'account_id' => $accountDbId,
    'movement_type' => 'CHARGE',
    'amount_minor' => 100100000,
    'balance_after_minor' => 100100000,
    'description' => 'Opening petty-cash float',
    'source_revision_id' => null,
    'source_decision_id' => null,
    'command_id' => 'cccccccc-4222-4222-8222-222222222222',
    // `actor_uid`/`actor_name`/`actor_role` come from the migration's `actorColumns()`. They are
    // NOT NULL with no default, and there is no `created_at`/`updated_at` on this table at all: the
    // migration calls `actorColumns()` but not `auditColumns()`, so a row carrying the usual audit
    // columns fails. The failure is silent — the seeder exits 0 having printed nothing, which the
    // harness reports as "Contract fixture seeding produced no output" rather than as a schema error.
    'actor_uid' => $userUid,
    'actor_name' => $userUid,
    'actor_role' => 'FINANCE',
    'occurred_at' => $now,
]);

upsert($db, 'pettycash_account_movements', 'public_id', FIXTURE_IDS['consumptionMovement'], [
    'public_id' => FIXTURE_IDS['consumptionMovement'],
    'account_id' => $accountDbId,
    'movement_type' => 'CONSUMPTION',
    'amount_minor' => 100000,
    // 100,100,000 less this consumption is the 100,000,000 the account row carries, so a test can
    // assert that the last movement's `balanceAfter` and the account's `balance` agree. A fixture
    // where they disagreed would make that assertion fail for the wrong reason.
    'balance_after_minor' => 100000000,
    'description' => 'Settlement of the fixture revision',
    'source_revision_id' => $revisionDbId,
    'source_decision_id' => null,
    'command_id' => 'cccccccc-4333-4333-8333-333333333333',
    'actor_uid' => $userUid,
    'actor_name' => $userUid,
    'actor_role' => 'FINANCE',
    'occurred_at' => $now,
]);

// --- A non-rial sheet -----------------------------------------------------
//
// `amount_minor` is only meaningful next to a currency, and FR-021 makes the
// sheet the authority for that currency. A revision read back through
// `DbTransactionRevisionRepository::mapRow()` must therefore report the sheet's
// own currency; seeding a USD sheet is what makes an IRR assumption observable.

$usdSheetId = null;
$usdTransactionId = null;
$usdRevisionId = null;

if ($state === 'usd') {
    $secondAccountDbId = scalar($db, 'pettycash_accounts', FIXTURE_IDS['secondAccount'], 'id');

    upsert($db, 'pettycash_sheets', 'public_id', FIXTURE_IDS['usdSheet'], [
        'public_id' => FIXTURE_IDS['usdSheet'],
        'title' => 'Contract Fixture USD Sheet',
        'owner_uid' => $userUid,
        'account_id' => $secondAccountDbId,
        'currency_id' => $secondCurrencyDbId,
        'status' => 'OPEN',
        'submitted_total_minor' => null,
        'first_submitted_at' => null,
        'management_completed_at' => null,
        'finance_started_at' => null,
        'completed_at' => null,
        'lock_version' => 0,
        'created_at' => $now,
    ]);

    $usdSheetDbId = scalar($db, 'pettycash_sheets', FIXTURE_IDS['usdSheet'], 'id');

    upsert($db, 'pettycash_transactions', 'public_id', FIXTURE_IDS['usdTransaction'], [
        'public_id' => FIXTURE_IDS['usdTransaction'],
        'sheet_id' => $usdSheetDbId,
        'owner_uid' => $userUid,
        'current_stage' => 'COST_CENTER',
        'current_status' => 'DRAFT',
        'final_outcome' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'lock_version' => 0,
    ]);

    $usdTransactionDbId = scalar($db, 'pettycash_transactions', FIXTURE_IDS['usdTransaction'], 'id');

    // 10.00 USD. Read back as IRR this is a ten-thousand-rial expense, which is
    // the corruption the currency read path has to avoid.
    upsert($db, 'pettycash_transaction_revisions', 'public_id', FIXTURE_IDS['usdRevision'], [
        'public_id' => FIXTURE_IDS['usdRevision'],
        'transaction_id' => $usdTransactionDbId,
        'revision_number' => 1,
        'expense_date' => $today,
        'amount_minor' => 1000,
        'description' => 'Contract fixture USD expense',
        'cost_center_id' => $costCenterDbId,
        'cost_center_code' => 'CC-100',
        'cost_center_name' => 'Contract Fixture Cost Center',
        'expense_type_id' => $expenseTypeDbId,
        'expense_type_title' => 'Contract Fixture Expense Type',
        'manager_uid' => null,
        'senior_manager_uid' => null,
        'created_at' => $now,
        'created_by' => $userUid,
        'activated_at' => $now,
        'supersedes_revision_id' => null,
    ]);

    $usdRevisionDbId = scalar($db, 'pettycash_transaction_revisions', FIXTURE_IDS['usdRevision'], 'id');

    $usdLink = $db->getQueryBuilder()->update('pettycash_transactions');
    $usdLink->set('active_revision_id', $usdLink->createNamedParameter($usdRevisionDbId))
        ->where($usdLink->expr()->eq('id', $usdLink->createNamedParameter($usdTransactionDbId)))
        ->executeStatement();

    deleteWhere($db, 'pettycash_workflow_decisions', 'transaction_id', $usdTransactionDbId);
    deleteWhere($db, 'pettycash_evidence', 'transaction_id', $usdTransactionDbId);
    deleteWhere($db, 'pettycash_transaction_audit', 'transaction_id', $usdTransactionDbId);
    deleteWhere($db, 'pettycash_transaction_audit', 'sheet_id', $usdSheetDbId);

    $usdSheetId = FIXTURE_IDS['usdSheet'];
    $usdTransactionId = FIXTURE_IDS['usdTransaction'];
    $usdRevisionId = FIXTURE_IDS['usdRevision'];
}

// --- A cost center the acting user does not manage -------------------------
//
// Scope enforcement can only be observed against a cost center the actor is *not*
// assigned to. Building the authorization context from the record under review makes
// the scope comparison compare the transaction's own cost center against a list
// derived from that same value, which is always true; only a second, unmanaged cost
// center can tell a real check from a vacuous one.

$otherCostCenterId = null;
$otherTransactionId = null;
$otherTransactionVersion = null;

if ($state === 'scope') {
    upsert($db, 'pettycash_cost_centers', 'public_id', FIXTURE_IDS['otherCostCenter'], array_merge([
        'public_id' => FIXTURE_IDS['otherCostCenter'],
        'code' => 'CC-200',
        'name' => 'Contract Fixture Unmanaged Cost Center',
        'description' => 'Seeded so out-of-scope decisions can be refused.',
        'valid_from' => $today,
        'valid_to' => null,
        'active' => true,
        'lock_version' => 0,
    ], $audit));

    $otherCostCenterDbId = scalar($db, 'pettycash_cost_centers', FIXTURE_IDS['otherCostCenter'], 'id');

    upsert($db, 'pettycash_sheets', 'public_id', FIXTURE_IDS['otherSheet'], [
        'public_id' => FIXTURE_IDS['otherSheet'],
        'title' => 'Contract Fixture Out-of-Scope Sheet',
        'owner_uid' => $userUid,
        'account_id' => $accountDbId,
        'currency_id' => $currencyDbId,
        'status' => 'COST_CENTER',
        'submitted_total_minor' => 50000,
        'first_submitted_at' => $now,
        'management_completed_at' => null,
        'finance_started_at' => null,
        'completed_at' => null,
        'lock_version' => 0,
        'created_at' => $now,
    ]);

    $otherSheetDbId = scalar($db, 'pettycash_sheets', FIXTURE_IDS['otherSheet'], 'id');

    upsert($db, 'pettycash_transactions', 'public_id', FIXTURE_IDS['otherTransaction'], [
        'public_id' => FIXTURE_IDS['otherTransaction'],
        'sheet_id' => $otherSheetDbId,
        'owner_uid' => $userUid,
        'current_stage' => 'COST_CENTER',
        'current_status' => 'AWAITING_COST_CENTER_REVIEW',
        'final_outcome' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'lock_version' => 0,
    ]);

    $otherTransactionDbId = scalar($db, 'pettycash_transactions', FIXTURE_IDS['otherTransaction'], 'id');

    upsert($db, 'pettycash_transaction_revisions', 'public_id', FIXTURE_IDS['otherRevision'], [
        'public_id' => FIXTURE_IDS['otherRevision'],
        'transaction_id' => $otherTransactionDbId,
        'revision_number' => 1,
        'expense_date' => $today,
        'amount_minor' => 50000,
        'description' => 'Contract fixture out-of-scope expense',
        'cost_center_id' => $otherCostCenterDbId,
        'cost_center_code' => 'CC-200',
        'cost_center_name' => 'Contract Fixture Unmanaged Cost Center',
        'expense_type_id' => $expenseTypeDbId,
        'expense_type_title' => 'Contract Fixture Expense Type',
        'manager_uid' => $userUid,
        'senior_manager_uid' => $userUid,
        'created_at' => $now,
        'created_by' => $userUid,
        'activated_at' => $now,
        'supersedes_revision_id' => null,
    ]);

    $otherRevisionDbId = scalar($db, 'pettycash_transaction_revisions', FIXTURE_IDS['otherRevision'], 'id');

    $otherLink = $db->getQueryBuilder()->update('pettycash_transactions');
    $otherLink->set('active_revision_id', $otherLink->createNamedParameter($otherRevisionDbId))
        ->where($otherLink->expr()->eq('id', $otherLink->createNamedParameter($otherTransactionDbId)))
        ->executeStatement();

    deleteWhere($db, 'pettycash_workflow_decisions', 'transaction_id', $otherTransactionDbId);
    deleteWhere($db, 'pettycash_evidence', 'transaction_id', $otherTransactionDbId);
    deleteWhere($db, 'pettycash_transaction_audit', 'transaction_id', $otherTransactionDbId);
    deleteWhere($db, 'pettycash_transaction_audit', 'sheet_id', $otherSheetDbId);

    $otherCostCenterId = FIXTURE_IDS['otherCostCenter'];
    $otherTransactionId = FIXTURE_IDS['otherTransaction'];
    $otherTransactionVersion = columnValue($db, 'pettycash_transactions', FIXTURE_IDS['otherTransaction'], 'lock_version');
}

// --- Clear what the suites leave behind -----------------------------------

deleteWhere($db, 'pettycash_workflow_decisions', 'transaction_id', $transactionDbId);
deleteWhere($db, 'pettycash_evidence', 'transaction_id', $transactionDbId);
deleteWhere($db, 'pettycash_transaction_audit', 'transaction_id', $transactionDbId);
deleteWhere($db, 'pettycash_transaction_audit', 'sheet_id', $sheetDbId);

// Remove rows the suites created on the fixture sheet itself.
//
// `POST /sheets/{id}/transactions` writes a real draft with only an expense date and
// an amount, and those rows survive every seed. They accumulate on the fixture sheet
// until submission walks them and fails on a transaction that has no cost center —
// an order-dependent failure caused entirely by fixture state, not by the code under
// test. Their revisions go first so none are stranded.
// One builder for the whole statement: a named parameter created by a different
// builder instance is never bound and is emitted verbatim, which produces invalid
// SQL. The same builder also cannot bind one name twice, so the two placeholders
// here are distinct.
$straySelect = $db->getQueryBuilder();
$strayRows = $straySelect->select('id')
    ->from('pettycash_transactions')
    ->where($straySelect->expr()->eq('sheet_id', $straySelect->createNamedParameter($sheetDbId)))
    ->andWhere($straySelect->expr()->neq('id', $straySelect->createNamedParameter($transactionDbId)))
    ->executeQuery()
    ->fetchAllAssociative();

foreach ($strayRows as $strayRow) {
    $strayDbId = (int) $strayRow['id'];
    deleteWhere($db, 'pettycash_workflow_decisions', 'transaction_id', $strayDbId);
    deleteWhere($db, 'pettycash_evidence', 'transaction_id', $strayDbId);
    deleteWhere($db, 'pettycash_transaction_audit', 'transaction_id', $strayDbId);

    $strayRevisions = $db->getQueryBuilder()->delete('pettycash_transaction_revisions');
    $strayRevisions->where($strayRevisions->expr()->eq('transaction_id', $strayRevisions->createNamedParameter($strayDbId)))
        ->executeStatement();

    $strayDelete = $db->getQueryBuilder()->delete('pettycash_transactions');
    $strayDelete->where($strayDelete->expr()->eq('id', $strayDelete->createNamedParameter($strayDbId)))
        ->executeStatement();
}

// Correction revisions are created by the suites, and the baseline is always
// revision 1. Without this, a correction run leaves revision 2 behind and the next
// run violates `pettycash_transaction_revisions_number_uniq` — the fixture would
// depend on the order the suites happen to execute in, which is exactly what this
// seeder is supposed to prevent.
$extraRevisions = $db->getQueryBuilder()->delete('pettycash_transaction_revisions');
$extraRevisions->where($extraRevisions->expr()->eq('transaction_id', $extraRevisions->createNamedParameter($transactionDbId)))
    ->andWhere($extraRevisions->expr()->gt('revision_number', $extraRevisions->createNamedParameter(1)))
    ->executeStatement();

// A returned transaction can only be resubmitted once its revision carries
// evidence, so the correction state needs one. This is metadata only: the
// assertions that read evidence bytes upload their own file instead.
if ($state === 'correction') {
    upsert($db, 'pettycash_evidence', 'public_id', FIXTURE_IDS['evidence'], [
        'public_id' => FIXTURE_IDS['evidence'],
        'transaction_id' => $transactionDbId,
        'origin_revision_id' => $revisionDbId,
        'appdata_key' => 'contract-fixture/evidence.txt',
        'original_name' => 'contract-fixture.txt',
        'media_type' => 'text/plain',
        'size_bytes' => 21,
        'checksum' => hash('sha256', 'contract fixture bytes'),
        'active' => true,
        'uploaded_by' => $userUid,
        'uploaded_at' => $now,
        'deactivated_by' => null,
        'deactivated_at' => null,
        'deactivation_reason' => null,
    ]);
}

echo json_encode([
    'userUid'            => $userUid,
    'state'              => $state,
    'currencyId'         => FIXTURE_IDS['currency'],
    'secondCurrencyId'   => FIXTURE_IDS['secondCurrency'],
    'costCenterId'       => FIXTURE_IDS['costCenter'],
    'expenseTypeId'      => FIXTURE_IDS['expenseType'],
    'accountId'          => FIXTURE_IDS['account'],
    'secondAccountId'    => FIXTURE_IDS['secondAccount'],
    'foreignAccountId'   => FIXTURE_IDS['foreignAccount'],
    'chargeMovementId'   => FIXTURE_IDS['chargeMovement'],
    'consumptionMovementId' => FIXTURE_IDS['consumptionMovement'],
    'sheetId'            => FIXTURE_IDS['sheet'],
    'transactionId'      => FIXTURE_IDS['transaction'],
    'revisionId'         => FIXTURE_IDS['revision'],
    'revisionNumber'     => 1,
    // The optimistic-lock values the suites must echo back as `expectedVersion`.
    'sheetVersion'       => columnValue($db, 'pettycash_sheets', FIXTURE_IDS['sheet'], 'lock_version'),
    'transactionVersion' => columnValue($db, 'pettycash_transactions', FIXTURE_IDS['transaction'], 'lock_version'),
    // Present only in the `usd` state; `null` elsewhere so a suite that forgets to
    // prepare that state fails loudly instead of reading a stale manifest key.
    'usdSheetId'         => $usdSheetId,
    'usdTransactionId'   => $usdTransactionId,
    'usdRevisionId'      => $usdRevisionId,
    'usdTransactionVersion' => $usdTransactionId === null
        ? null
        : columnValue($db, 'pettycash_transactions', FIXTURE_IDS['usdTransaction'], 'lock_version'),
    // Present only in the `scope` state.
    'otherCostCenterId'   => $otherCostCenterId,
    'otherTransactionId'  => $otherTransactionId,
    'otherTransactionVersion' => $otherTransactionVersion,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
