<?php

declare(strict_types=1);

/**
 * Writes -- and removes -- one deliberately unreadable record, so the data-integrity refusal can be
 * observed over real HTTP without risking the shared database.
 *
 * ## Why this exists at all
 *
 * `DbTransactionRevisionRepository::resolveCurrencyCode()` walks revision -> transaction -> sheet ->
 * currency, and throws `UnreadableRecordData` when the walk yields nothing. `ReportController` catches
 * it and answers 422 with a message that says a stored record is incomplete, does not echo the
 * internal one, and logs the internal one in full so an operator can find the row. All of that was
 * written and none of it was verified: reaching the 422 requires a record with a dangling
 * `currency_id`, which is precisely the fault the persistence round trip exists to *remove*, so nobody
 * could produce one without writing the fault back into the MariaDB the live contract suite also uses.
 *
 * An earlier attempt to do exactly that was abandoned for the right reason. A probe whose failure mode
 * is "leaves a landmine that breaks three reports" is the same trade that produced the seven leaked
 * sheets in the first place. So the fixture is built the other way round: the removal is the part that
 * is guaranteed and the corruption is the part that is short-lived.
 *
 * ## What makes it safe
 *
 * Four properties, each of them separately checked rather than asserted here:
 *
 * 1. **The corruption is one column, on a row the product itself created.** The sheet, transaction and
 *    revision are written exactly as `seed-contract-fixtures.php` writes them, and only then is
 *    `pettycash_sheets.currency_id` pointed at an id that does not exist. So the thing under test is
 *    the *reference*, not a hand-built approximation of a business record -- a fixture that invented
 *    its own rows could be unreadable for a reason the product would never produce.
 * 2. **`insert` sweeps before it writes.** A previous run that was killed between the write and the
 *    removal leaves the fault in place, and the next `insert` removes it before writing its own. The
 *    blast radius is therefore one run, not one run per attempt, and it does not accumulate.
 * 3. **`remove` verifies rather than assumes.** It sweeps and then re-counts every fingerprint in
 *    `LeakedRows`, and exits non-zero if anything survived. A removal that reported success while the
 *    row was still there would be worse than no removal, because the operator would stop looking.
 * 4. **The fingerprint is a title, and it is in the shared definition.** `LeakedRows` knows how to find
 *    and remove `Corrupt Record Probe Sheet`, so `roundtrip-leftovers.php --delete` also repairs a
 *    crashed probe, and a human reading a 422 in a log can find the row by name.
 *
 * ## What it does not claim
 *
 * It does not prove the fault is unreachable in production. T308's remaining finding stands: a genuinely
 * dangling `sheets.currency_id` destroys three whole reports, and this fixture only makes that fault
 * *observable*. It also cannot reach the fault for an administrator's report, because
 * `ReportController::transactionsReport()` reads only `WorkflowStage::Final` rows for an administrator
 * and the probe is not at Final -- which is recorded rather than assumed, and measured in
 * `DataIntegrityReportTest`.
 *
 * Usage (inside the container):
 *   php tests/Contract/Fixture/corrupt-record-fixture.php <nextcloud-user> insert
 *   php tests/Contract/Fixture/corrupt-record-fixture.php <nextcloud-user> remove
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This fixture must be run from the command line.\n");
    exit(1);
}

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
if (!is_file($nextcloudRoot . '/lib/base.php')) {
    fwrite(STDERR, "Cannot locate Nextcloud at {$nextcloudRoot}; set NEXTCLOUD_ROOT.\n");
    exit(1);
}

require_once $nextcloudRoot . '/lib/base.php';
require_once __DIR__ . '/../../Support/LeakedRows.php';

use OCA\PettyCash\Tests\Support\LeakedRows;
use OCP\IDBConnection;
use OCP\IUserManager;

/** Fixed public ids, so a repeat run replaces its own rows rather than accumulating. */
const PROBE_IDS = [
    'sheet' => 'e5e5e501-1111-4111-8111-111111111111',
    'transaction' => 'e5e5e501-2222-4222-8222-222222222222',
    'revision' => 'e5e5e501-3333-4333-8333-333333333333',
];

/**
 * A `pettycash_currencies.id` that has never existed and never will.
 *
 * A real high id rather than a value the table happens not to use, so the row cannot become readable
 * again by accident: if the sequence ever reaches it, the fault under test silently stops existing and
 * the assertions would be passing against a healthy report. Well above the current auto-increment and
 * inside the unsigned BIGINT the column is declared as.
 */
const DANGLING_CURRENCY_ID = 4294000000;

$userUid = $argv[1] ?? '';
$action = $argv[2] ?? '';

if ($userUid === '' || !in_array($action, ['insert', 'remove'], true)) {
    fwrite(STDERR, "Usage: corrupt-record-fixture.php <nextcloud-user> <insert|remove>\n");
    exit(1);
}

if (\OCP\Server::get(IUserManager::class)->get($userUid) === null) {
    fwrite(STDERR, "Nextcloud user '{$userUid}' does not exist.\n");
    exit(1);
}

$db = \OCP\Server::get(IDBConnection::class);

$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');

/**
 * Write a row to a known state, inserting it when absent and overwriting the given columns when it is
 * already there. The same shape `seed-contract-fixtures.php` uses, for the same reason: one builder for
 * the whole statement, because a named parameter created by a different builder instance is never bound.
 */
function probeUpsert(IDBConnection $db, string $table, string $keyColumn, string $keyValue, array $values): void
{
    $select = $db->getQueryBuilder();
    $existing = $select->select('id')
        ->from($table)
        ->where($select->expr()->eq($keyColumn, $select->createNamedParameter($keyValue)))
        ->setMaxResults(1)
        ->executeQuery()
        ->fetchAssociative();

    if ($existing === false) {
        $insert = $db->getQueryBuilder();
        $insert->insert($table);
        foreach ($values as $column => $value) {
            $insert->setValue($column, $insert->createNamedParameter($value));
        }
        $insert->executeStatement();

        return;
    }

    $update = $db->getQueryBuilder();
    $update->update($table);
    foreach ($values as $column => $value) {
        $update->set($column, $update->createNamedParameter($value));
    }
    $update->where($update->expr()->eq('id', $update->createNamedParameter((int) $existing['id'])))
        ->executeStatement();
}

function probeScalar(IDBConnection $db, string $table, string $publicId, string $column): int
{
    $qb = $db->getQueryBuilder();
    $qb->select($column)
        ->from($table)
        ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
        ->setMaxResults(1);
    $row = $qb->executeQuery()->fetchAssociative();
    if ($row === false) {
        throw new RuntimeException("No row in {$table} carries public id {$publicId}.");
    }

    return (int) $row[$column];
}

/**
 * An active account of the acting user, and the currency it is denominated in.
 *
 * @return array{int, int} `[account id, currency id]`
 */
function probeAccountAndCurrency(IDBConnection $db, string $userUid): array
{
    $qb = $db->getQueryBuilder();
    $qb->select('id')
        ->addSelect('currency_id')
        ->from('pettycash_accounts')
        ->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
        ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
        ->setMaxResults(1);
    $row = $qb->executeQuery()->fetchAssociative();
    if ($row === false) {
        throw new RuntimeException(
            "The acting user '{$userUid}' holds no active account, so the probe sheet has nothing to "
            . 'reference. Seed the contract fixtures first (ContractFixtures::prepare()).'
        );
    }

    return [(int) $row['id'], (int) $row['currency_id']];
}

try {
    if ($action === 'insert') {
        // Property 2: repair before writing. A probe killed by a crashed test run is removed here, so
        // the fault is present for one run rather than for one run per attempt.
        $sweptFirst = LeakedRows::sweep($db);

        // The account and currency the probe's sheet points at, resolved from the acting user rather
        // than hardcoded.
        //
        // `pettycash_sheets.account_id` is NOT NULL, so the sheet has to name a real account, and the
        // account is what supplies a real `currency_id` to break later. Looking both up by the acting
        // user keeps this file free of the seeder's fixed UUIDs: a copy of an id here would be a second
        // place that has to change when the fixture is renumbered, and would fail as a bare
        // "No row carries public id" rather than as the missing setup it actually is.
        [$accountDbId, $currencyDbId] = probeAccountAndCurrency($db, $userUid);

        probeUpsert($db, 'pettycash_sheets', 'public_id', PROBE_IDS['sheet'], [
            'public_id' => PROBE_IDS['sheet'],
            'title' => LeakedRows::CORRUPT_PROBE_SHEET_TITLE,
            'owner_uid' => $userUid,
            'account_id' => $accountDbId,
            'currency_id' => $currencyDbId,
            'status' => 'OPEN',
            'submitted_total_minor' => 0,
            'first_submitted_at' => null,
            'management_completed_at' => null,
            'finance_started_at' => null,
            'completed_at' => null,
            'lock_version' => 0,
            'created_at' => $now,
        ]);
        $sheetDbId = probeScalar($db, 'pettycash_sheets', PROBE_IDS['sheet'], 'id');

        // Not at Final stage, and this is load-bearing rather than incidental.
        //
        // `ReportController::transactionsReport()` reads `findByStageAndStatus(Final, ...)` for an
        // administrator and `findByOwnerUid(...)` for everyone else. A probe at Final would therefore be
        // read by *both*, so a crashed probe would refuse the report for a user who has nothing to do
        // with it. A non-administrator is the only reader that can be reached, which confines the
        // fault's blast radius to the one fixture that creates it. The contract tests authenticate as
        // `PETTYCASH_TEST_USER`, which holds no administrator role, so the probe is reachable at all.
        probeUpsert($db, 'pettycash_transactions', 'public_id', PROBE_IDS['transaction'], [
            'public_id' => PROBE_IDS['transaction'],
            'sheet_id' => $sheetDbId,
            'owner_uid' => $userUid,
            'active_revision_id' => null,
            'current_stage' => 'DRAFT',
            'current_status' => 'DRAFT',
            'final_outcome' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'lock_version' => 0,
        ]);
        $transactionDbId = probeScalar($db, 'pettycash_transactions', PROBE_IDS['transaction'], 'id');

        // A draft revision, so its cost centre and expense type are null -- which is what a draft
        // revision genuinely looks like, and which also keeps the probe from depending on the seeder's
        // cost-centre and expense-type fixtures. FR-021 makes the *sheet* the authority for the
        // revision's currency, and the sheet's currency is the thing being broken.
        probeUpsert($db, 'pettycash_transaction_revisions', 'public_id', PROBE_IDS['revision'], [
            'public_id' => PROBE_IDS['revision'],
            'transaction_id' => $transactionDbId,
            'revision_number' => 1,
            'expense_date' => $today,
            'amount_minor' => 12345,
            'description' => 'Corrupt record probe expense',
            'cost_center_id' => null,
            'cost_center_code' => null,
            'cost_center_name' => null,
            'expense_type_id' => null,
            'expense_type_title' => null,
            'manager_uid' => $userUid,
            'senior_manager_uid' => $userUid,
            'created_at' => $now,
            'created_by' => $userUid,
            'activated_at' => null,
            'supersedes_revision_id' => null,
        ]);
        $revisionDbId = probeScalar($db, 'pettycash_transaction_revisions', PROBE_IDS['revision'], 'id');

        $link = $db->getQueryBuilder()->update('pettycash_transactions');
        $link->set('active_revision_id', $link->createNamedParameter($revisionDbId))
            ->where($link->expr()->eq('id', $link->createNamedParameter($transactionDbId)))
            ->executeStatement();

        // The corruption, in one statement, after every row above is real.
        //
        // `UNSIGNED BIGINT` reaches 18446744073709551615, so 4294000000 is a legal value for the column:
        // MariaDB accepts the write, which is the point. The row is now one that no application path
        // can produce, because `ExpenseSheet` takes a `Currency` aggregate and the aggregate's `id` comes
        // from a row that exists.
        $break = $db->getQueryBuilder()->update('pettycash_sheets');
        $break->set('currency_id', $break->createNamedParameter(DANGLING_CURRENCY_ID))
            ->where($break->expr()->eq('id', $break->createNamedParameter($sheetDbId)))
            ->executeStatement();

        echo json_encode([
            'sheetId' => PROBE_IDS['sheet'],
            'transactionId' => PROBE_IDS['transaction'],
            'revisionId' => PROBE_IDS['revision'],
            // The surrogate ids, so the test can assert they are *absent* from the response body. The
            // exception message names the revision's surrogate key, and T029's whole point was that
            // this must not reach a caller -- which cannot be asserted without knowing the number.
            'revisionDbId' => $revisionDbId,
            'danglingCurrencyId' => DANGLING_CURRENCY_ID,
            'repairedBeforeWriting' => array_sum($sweptFirst),
        ], JSON_UNESCAPED_SLASHES);

        exit(0);
    }

    // remove: sweep, then prove it.
    $removed = LeakedRows::sweep($db);
    $remaining = LeakedRows::leftovers($db);

    echo json_encode([
        'removed' => $removed,
        'leftovers' => $remaining,
    ], JSON_UNESCAPED_SLASHES);

    if ($remaining !== []) {
        fwrite(STDERR, "The corrupt-record probe could not be removed; the fault is still in the database.\n");
        exit(1);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    echo json_encode(['error' => $e->getMessage()]);
    exit(2);
}
