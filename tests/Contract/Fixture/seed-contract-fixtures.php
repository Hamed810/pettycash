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
if (!in_array($state, ['draft', 'submitted', 'correction'], true)) {
    fwrite(STDERR, "Unknown fixture state '{$state}'; expected draft, submitted, or correction.\n");
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
    'secondCurrency'  => '99999999-9999-4999-8999-999999999999',
    'managerRole'     => 'bbbbbbbb-1111-4111-8111-111111111111',
    'seniorRole'      => 'bbbbbbbb-2222-4222-8222-222222222222',
    'evidence'        => 'cccccccc-1111-4111-8111-111111111111',
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

$sheetBaseline = $state === 'draft'
    ? ['status' => 'OPEN', 'submitted_total_minor' => null, 'first_submitted_at' => null]
    : ['status' => 'AWAITING_COST_CENTER_REVIEW', 'submitted_total_minor' => 100000, 'first_submitted_at' => $now];

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
    'draft' => ['current_stage' => 'COST_CENTER', 'current_status' => 'DRAFT'],
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

// --- Clear what the suites leave behind -----------------------------------

deleteWhere($db, 'pettycash_workflow_decisions', 'transaction_id', $transactionDbId);
deleteWhere($db, 'pettycash_evidence', 'transaction_id', $transactionDbId);
deleteWhere($db, 'pettycash_transaction_audit', 'transaction_id', $transactionDbId);
deleteWhere($db, 'pettycash_transaction_audit', 'sheet_id', $sheetDbId);

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
    'sheetId'            => FIXTURE_IDS['sheet'],
    'transactionId'      => FIXTURE_IDS['transaction'],
    'revisionId'         => FIXTURE_IDS['revision'],
    'revisionNumber'     => 1,
    // The optimistic-lock values the suites must echo back as `expectedVersion`.
    'sheetVersion'       => columnValue($db, 'pettycash_sheets', FIXTURE_IDS['sheet'], 'lock_version'),
    'transactionVersion' => columnValue($db, 'pettycash_transactions', FIXTURE_IDS['transaction'], 'lock_version'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
