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
 *   finance   the primary transaction moved to finance review with a senior
 *              approval behind it, plus two more there: one in the cost center the
 *              acting user does not manage, and one the senior manager rejected. The
 *              acting user additionally holds the FINANCE role, which no other state
 *              grants. Use it for the finance queue and finance decision assertions.
 *              Without it the finance endpoints can only be observed against an empty
 *              queue, which every implementation satisfies.
 *   deactivated-rial
 *              the `draft` baseline with both of the acting user's accounts retired:
 *              zero **active** accounts while a rial record exists and is inactive.
 *              Use it for FR-020's exists-but-inactive carve-out (T428), in which
 *              provisioning MUST NOT fire and sheet creation MUST be refused with
 *              `422` naming the account and the remedy.
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
if (!in_array($state, ['draft', 'submitted', 'correction', 'usd', 'scope', 'finance', 'deactivated-rial'], true)) {
    fwrite(STDERR, "Unknown fixture state '{$state}'; expected draft, submitted, correction, usd, scope, finance, or deactivated-rial.\n");
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
    'secondCurrency'  => '99999999-9999-4999-8999-999999999999',    'managerRole'     => 'bbbbbbbb-1111-4111-8111-111111111111',
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
    // The settled expense the ledger's consumption row belongs to.
    //
    // It cannot be the working transaction's own revision.
    // `pettycash_account_movements_consumption_uniq` is unique on `source_revision_id`, which is
    // what makes FR-040's "exactly one consumption" an enforced fact — and which also means a
    // revision can be settled exactly once, ever. A fixture that claimed the *current* revision
    // had already been settled left no revision the finance suite could settle at all: the
    // approval failed on a duplicate key, and the only honest reading of that fixture is a
    // ledger row standing in for a settlement that has not happened. So the history is its own
    // transaction on its own sheet, at FINAL, out of every queue.
    'ledgerSheet'     => 'cccccccc-4444-4444-8444-444444444444',
    'ledgerTransaction' => 'cccccccc-5555-4555-8555-555555555555',
    'ledgerRevision'  => 'cccccccc-6666-4666-8666-666666666666',
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
    // --- The finance review fixture ---------------------------------------
    //
    // Three transactions sit in finance review, and each one exists for a different
    // claim. Without them the finance endpoints can only be exercised against an
    // empty queue, and an empty queue satisfies every implementation: one scoped
    // per cost center, one that returns nothing at all, and one that filters on the
    // wrong status all look identical.
    //
    // The management decisions are seeded as rows, not implied by the status. A
    // transaction can only be at finance review because a cost-centre manager and a
    // senior manager decided on it, so the decision history a finance officer reads
    // in order to act (FR-036) has to exist for the read to be a read of anything.
    'financeRole'     => 'bbbbbbbb-3333-4333-8333-333333333333',
    'costCenterDecision' => 'bbbbbbbb-4444-4444-8444-444444444444',
    'seniorDecision'  => 'bbbbbbbb-5555-4555-8555-555555555555',
    'primaryCommands' => [
        'cost-center' => 'bbbbbbbb-6666-4666-8666-666666666666',
        'senior'      => 'bbbbbbbb-7777-4777-8777-777777777777',
    ],
    // The same, inside the cost center the acting user holds no assignment for.
    //
    // FR-034 makes the finance queue shared, and T062 is the task that says so. A
    // transaction the actor does not manage is the only thing that can tell a shared
    // queue from one scoped to the actor's own cost centers — the acting user
    // manages CC-100, so a queue filtered to CC-100 would return the ordinary
    // fixture and look correct.
    'sharedSheet'     => 'ffffffff-1111-4111-8111-111111111111',
    'sharedTransaction' => 'ffffffff-2222-4222-8222-222222222222',
    'sharedRevision'  => 'ffffffff-3333-4333-8333-333333333333',
    'sharedCostCenterDecision' => 'ffffffff-4444-4444-8444-444444444444',
    'sharedSeniorDecision' => 'ffffffff-5555-4555-8555-555555555555',
    'sharedCommands'  => [
        'cost-center' => 'ffffffff-6666-4666-8666-666666666666',
        'senior'      => 'ffffffff-7777-4777-8777-777777777777',
    ],
    // The same stage, but the senior manager refused it.
    //
    // FR-037 routes senior-approved *and* senior-rejected transactions to finance, and
    // FR-039 draws the matrix from the senior outcome: finance may reject or return
    // this one but must not approve it. A policy that only handles the approval case
    // satisfies a fixture where the senior always agreed.
    'rejectedSheet'   => 'ffffffff-8888-4888-8888-888888888888',
    'rejectedTransaction' => 'ffffffff-9999-4999-8999-999999999999',
    'rejectedRevision' => 'ffffffff-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'rejectedCostCenterDecision' => 'ffffffff-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'rejectedSeniorDecision' => 'ffffffff-cccc-4ccc-8ccc-cccccccccccc',
    'rejectedCommands' => [
        'cost-center' => 'ffffffff-dddd-4ddd-8ddd-dddddddddddd',
        'senior'      => 'ffffffff-eeee-4eee-8eee-eeeeeeeeeeee',
    ],
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

/** The surrogate key of a fixture row, or null when it does not exist yet. */
function dbIdOrNull(\OCP\IDBConnection $db, string $table, string $keyValue, string $keyColumn = 'public_id'): ?int
{
    $qb = $db->getQueryBuilder();
    $row = $qb->select('id')
        ->from($table)
        ->where($qb->expr()->eq($keyColumn, $qb->createNamedParameter($keyValue)))
        ->setMaxResults(1)
        ->executeQuery()
        ->fetchAssociative();

    return $row === false ? null : (int)$row['id'];
}

/**
 * Seed one transaction sitting in finance review, with the two management decisions
 * that put it there.
 *
 * The `finance` state needs three of these and the differences between them are the
 * whole point of the state — same stage, different senior outcome, and a cost center
 * the actor does not manage — so the block is written once and parameterised rather
 * than copied, where fixing one copy later would leave the other two subtly different
 * and the fixture would quietly stop proving what it was written to prove.
 *
 * @param array<string,mixed> $s
 * @return int the transaction's surrogate key
 */
function seedFinanceReviewTransaction(\OCP\IDBConnection $db, array $s): int
{
    upsert($db, 'pettycash_sheets', 'public_id', $s['sheetId'], array_merge([
        'public_id' => $s['sheetId'],
        'title' => $s['title'],
        'owner_uid' => $s['userUid'],
        'account_id' => $s['accountDbId'],
        'currency_id' => $s['currencyDbId'],
        // The sheet's own status is the earliest stage any of its transactions is
        // still at, so a sheet whose transactions are all in finance review is
        // `FINANCE` — see `ExpenseSheet::recalculateStatusFrom()`, which is what
        // will recompute it from this very value once finance settles.
        'status' => 'FINANCE',
        'submitted_total_minor' => $s['amountMinor'],
        'first_submitted_at' => $s['now'],
        'management_completed_at' => null,
        'finance_started_at' => $s['now'],
        'completed_at' => null,
        'lock_version' => 0,
        'created_at' => $s['now'],
    ]));

    $sheetDbId = scalar($db, 'pettycash_sheets', $s['sheetId'], 'id');

    upsert($db, 'pettycash_transactions', 'public_id', $s['transactionId'], [
        'public_id' => $s['transactionId'],
        'sheet_id' => $sheetDbId,
        'owner_uid' => $s['userUid'],
        'current_stage' => 'FINANCE',
        'current_status' => $s['status'],
        'final_outcome' => null,
        'created_at' => $s['now'],
        'updated_at' => $s['now'],
        'lock_version' => 0,
    ]);

    $transactionDbId = scalar($db, 'pettycash_transactions', $s['transactionId'], 'id');

    upsert($db, 'pettycash_transaction_revisions', 'public_id', $s['revisionId'], [
        'public_id' => $s['revisionId'],
        'transaction_id' => $transactionDbId,
        'revision_number' => 1,
        'expense_date' => $s['today'],
        'amount_minor' => $s['amountMinor'],
        'description' => $s['description'],
        'cost_center_id' => $s['costCenterDbId'],
        'cost_center_code' => $s['costCenterCode'],
        'cost_center_name' => $s['costCenterName'],
        'expense_type_id' => $s['expenseTypeDbId'],
        'expense_type_title' => 'Contract Fixture Expense Type',
        'manager_uid' => $s['userUid'],
        'senior_manager_uid' => $s['userUid'],
        'created_at' => $s['now'],
        'created_by' => $s['userUid'],
        'activated_at' => $s['now'],
        'supersedes_revision_id' => null,
    ]);

    $revisionDbId = scalar($db, 'pettycash_transaction_revisions', $s['revisionId'], 'id');

    $link = $db->getQueryBuilder()->update('pettycash_transactions');
    $link->set('active_revision_id', $link->createNamedParameter($revisionDbId))
        ->where($link->expr()->eq('id', $link->createNamedParameter($transactionDbId)))
        ->executeStatement();

    // Reset before writing the two decisions, or a run that follows one the suites
    // spent a finance decision on would collide on
    // `pettycash_workflow_decisions_revision_stage_uniq` — and, worse, would leave
    // the suite's own finance decision in the history a later assertion reads.
    deleteWhere($db, 'pettycash_workflow_decisions', 'transaction_id', $transactionDbId);
    deleteWhere($db, 'pettycash_evidence', 'transaction_id', $transactionDbId);
    deleteWhere($db, 'pettycash_transaction_audit', 'transaction_id', $transactionDbId);
    deleteWhere($db, 'pettycash_transaction_audit', 'sheet_id', $sheetDbId);

    $stages = [
        'cost-center' => ['publicId' => $s['costCenterDecision'], 'stage' => 'COST_CENTER', 'role' => 'COST_CENTER_MANAGER'],
        'senior' => ['publicId' => $s['seniorDecision'], 'stage' => 'SENIOR', 'role' => 'SENIOR_MANAGER'],
    ];

    foreach ($s['decisions'] as $which) {
        $spec = $stages[$which];
        // The cost-centre decision is always an approval: this is the stage that has to
        // have passed for the transaction to reach finance at all. Only the senior
        // decision varies, and it is the one FR-039's matrix is drawn from.
        $outcome = $spec['stage'] === 'SENIOR' ? $s['seniorOutcome'] : 'APPROVE';

        upsert($db, 'pettycash_workflow_decisions', 'public_id', $spec['publicId'], [
            'public_id' => $spec['publicId'],
            'transaction_id' => $transactionDbId,
            'revision_id' => $revisionDbId,
            'stage' => $spec['stage'],
            'decision' => $outcome,
            'explanation' => $outcome === 'APPROVE' ? null : 'Contract fixture: outside policy.',
            'actor_uid' => $s['userUid'],
            'actor_name' => $s['userUid'],
            'actor_role' => $spec['role'],
            'command_id' => $s['commands'][$which],
            'decided_at' => $s['now'],
        ]);
    }

    return $transactionDbId;
}

/**
 * Remove the finance review fixture when another state is requested.
 *
 * The rows are only *created* by the `finance` state, and every other state seeds a
 * world in which no transaction awaits finance review. Leaving them behind would
 * make the finance queue of the next state depend on whether the finance suite had
 * run first — which is exactly the order-dependence this seeder exists to prevent.
 */
function removeFinanceReviewFixture(\OCP\IDBConnection $db, array $ids): void
{
    foreach (['transaction', 'revision', 'sheet'] as $kind) {
        $table = [
            'transaction' => 'pettycash_transactions',
            'revision' => 'pettycash_transaction_revisions',
            'sheet' => 'pettycash_sheets',
        ][$kind];

        $transactionDbId = dbIdOrNull($db, 'pettycash_transactions', $ids['transaction']);
        if ($transactionDbId !== null) {
            deleteWhere($db, 'pettycash_workflow_decisions', 'transaction_id', $transactionDbId);
            deleteWhere($db, 'pettycash_evidence', 'transaction_id', $transactionDbId);
            deleteWhere($db, 'pettycash_transaction_audit', 'transaction_id', $transactionDbId);
        }

        $sheetDbId = dbIdOrNull($db, 'pettycash_sheets', $ids['sheet']);
        if ($sheetDbId !== null) {
            deleteWhere($db, 'pettycash_transaction_audit', 'sheet_id', $sheetDbId);
        }

        $delete = $db->getQueryBuilder()->delete($table);
        $delete->where($delete->expr()->eq('public_id', $delete->createNamedParameter($ids[$kind])))
            ->executeStatement();
    }
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

// A third, so "open an account" has a currency the acting user does not already hold one in.
//
// The fixture gives the user an IRR account and a USD account, and FR-014 allows exactly one active
// account per currency. With only those two configured, every create would collide with the seed and
// the endpoint could be observed only in its refusal — which is how a 409 came to be the first thing
// these tests saw. `minor_unit => 2` keeps it distinguishable from the rial's 0.
upsert($db, 'pettycash_currencies', 'code', 'EUR', array_merge([
    'code' => 'EUR',
    'name' => 'Euro',
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
    // **Zero, and that used to be 100,000.** This account exists to be *unused* --
    // `AccountControllerTest::testAccountWithNoMovementsIsAnEmptySuccess` and
    // `::testTheCallersOwnSecondAccountIsStillReadable` both assert its ledger is empty, and
    // "an account that has never been used is a normal state the purchaser view has to render"
    // is worth a fixture. It also carried a non-zero balance, and those two claims cannot both
    // hold: FR-015 requires the amount, description and resulting balance to be recorded as a
    // movement, so a balance with no ledger is a state the application cannot produce.
    //
    // T368 found it. Asserting the new accounts-report invariant -- that a row's balance is
    // reachable from the movements it carries -- is what surfaced it, and the finding matters
    // beyond this fixture: a non-zero balance beside an empty ledger is exactly what a charge
    // that moved money without writing a movement would look like, so the fixture was quietly
    // excusing that class of defect on the account every charge test uses.
    'balance_minor' => 0,
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

// --- Exists-but-inactive rial (T428 / FR-020) -----------------------------------
//
// The `deactivated-rial` state leaves the acting user with zero **active** accounts
// while a rial record exists and is inactive — the exact precondition of FR-020's
// carve-out, in which provisioning MUST NOT fire and sheet creation MUST be refused
// with `422` naming the account and the remedy. Both of the acting user's accounts
// are retired, because one remaining active account would resolve instead of
// refusing and the suite would assert against the wrong rule. Every other state
// upserts these rows with `'active' => true` above, so preparing any of them
// restores the baseline and this state cannot leak into another suite.
//
// Written directly rather than through `PATCH /accounts/{id}`: deactivation is
// administrator-only over HTTP (FR-013) and no principal the suites may use holds
// the petty-cash Administrator assignment, so the endpoint is unreachable from
// here by design rather than by omission. The configuration-audit row FR-017
// requires for a real deactivation is therefore absent too — this row is fixture
// state, not an administrative act, and the suites asserting the audit trail seed
// through the service layer instead.
if ($state === 'deactivated-rial') {
    foreach ([FIXTURE_IDS['account'], FIXTURE_IDS['secondAccount']] as $retiredPublicId) {
        $retire = $db->getQueryBuilder()->update('pettycash_accounts');
        // `false` MUST be bound with an explicit boolean type: without one the
        // value arrives as `''`, which strict MariaDB rejects for this column —
        // the same reason `upsert()` above maps booleans to `PARAM_BOOL`.
        $retire->set(
            'active',
            $retire->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)
        )
            ->where($retire->expr()->eq('public_id', $retire->createNamedParameter($retiredPublicId)))
            ->executeStatement();
    }
}

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

// The FINANCE role, granted in every state to the acting user.
//
// It is unconditional, and that is the point worth knowing: a fixture that always passes a role
// gate makes the gate's *refusals* unobservable, which is why the FR-015 charge actor gate is
// covered at `tests/Integration/Account/AccountChargeAuthorizationTest.php` rather than over HTTP.
// T291 is the open task for the endpoint-level half of that coverage.
//
// It carries no cost center, which is the schema's own way of saying the role is not
// scoped: `pettycash_role_assignments.cost_center_id` is nullable and the finance
// queue is the one queue a cost-centre-scoped read cannot narrow.
//
// It does not have to be withdrawn in the other states: `canReview()` resolves the
// required role from the transaction's own stage, so holding FINANCE changes nothing
// about a cost-centre or senior stage decision.
upsert($db, 'pettycash_role_assignments', 'public_id', FIXTURE_IDS['financeRole'], array_merge([
    'public_id' => FIXTURE_IDS['financeRole'],
    'user_uid' => $userUid,
    'role' => 'FINANCE',
    'cost_center_id' => null,
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
    'draft', 'usd', 'deactivated-rial' => ['status' => 'OPEN', 'submitted_total_minor' => null, 'first_submitted_at' => null],
    'correction' => ['status' => 'CORRECTION', 'submitted_total_minor' => 100000, 'first_submitted_at' => $now],
    'finance' => ['status' => 'FINANCE', 'submitted_total_minor' => 100000, 'first_submitted_at' => $now],
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
    'draft', 'usd', 'deactivated-rial' => ['current_stage' => 'COST_CENTER', 'current_status' => 'DRAFT'],
    'correction' => ['current_stage' => 'CORRECTION', 'current_status' => 'AWAITING_PURCHASER_CORRECTION'],
    // `SENIOR_APPROVED`, not `AWAITING_FINANCE_REVIEW`: a senior *approval* is what
    // routes a transaction to finance, and `findForFinanceQueue()` selects exactly
    // the two senior outcomes. Seeding the status the enum offers but no code path
    // produces would make the finance queue fixture match a state the application
    // cannot reach.
    'finance' => ['current_stage' => 'FINANCE', 'current_status' => 'SENIOR_APPROVED'],
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

// --- The settled expense the ledger's consumption belongs to ----------------
//
// Its own sheet, transaction, and revision, at FINAL. It is out of every queue and out of the
// working sheet's totals, and it exists so that the ledger's consumption row is a real history
// rather than a claim that the working revision has already been settled — see the note on
// FIXTURE_IDS['ledgerRevision'].
upsert($db, 'pettycash_sheets', 'public_id', FIXTURE_IDS['ledgerSheet'], [
    'public_id' => FIXTURE_IDS['ledgerSheet'],
    'title' => 'Contract Fixture Settled Sheet',
    'owner_uid' => $userUid,
    'account_id' => $accountDbId,
    'currency_id' => $currencyDbId,
    'status' => 'COMPLETED',
    'submitted_total_minor' => 100000,
    'first_submitted_at' => $now,
    'management_completed_at' => $now,
    'finance_started_at' => $now,
    'completed_at' => $now,
    'lock_version' => 0,
    'created_at' => $now,
]);

$ledgerSheetDbId = scalar($db, 'pettycash_sheets', FIXTURE_IDS['ledgerSheet'], 'id');

upsert($db, 'pettycash_transactions', 'public_id', FIXTURE_IDS['ledgerTransaction'], [
    'public_id' => FIXTURE_IDS['ledgerTransaction'],
    'sheet_id' => $ledgerSheetDbId,
    'owner_uid' => $userUid,
    'current_stage' => 'FINAL',
    'current_status' => 'COMPLETED_APPROVED',
    'final_outcome' => 'APPROVED',
    'created_at' => $now,
    'updated_at' => $now,
    'lock_version' => 0,
]);

$ledgerTransactionDbId = scalar($db, 'pettycash_transactions', FIXTURE_IDS['ledgerTransaction'], 'id');

upsert($db, 'pettycash_transaction_revisions', 'public_id', FIXTURE_IDS['ledgerRevision'], [
    'public_id' => FIXTURE_IDS['ledgerRevision'],
    'transaction_id' => $ledgerTransactionDbId,
    'revision_number' => 1,
    'expense_date' => $today,
    'amount_minor' => 100000,
    'description' => 'Contract fixture settled expense',
    'cost_center_id' => $costCenterDbId,
    'cost_center_code' => 'CC-100',
    'cost_center_name' => 'Contract Fixture Cost Center',
    'expense_type_id' => $expenseTypeDbId,
    'expense_type_title' => 'Contract Fixture Expense Type',
    'manager_uid' => $userUid,
    'senior_manager_uid' => $userUid,
    'created_at' => $now,
    'created_by' => $userUid,
    'activated_at' => $now,
    'supersedes_revision_id' => null,
]);

$ledgerRevisionDbId = scalar($db, 'pettycash_transaction_revisions', FIXTURE_IDS['ledgerRevision'], 'id');

$ledgerLink = $db->getQueryBuilder()->update('pettycash_transactions');
$ledgerLink->set('active_revision_id', $ledgerLink->createNamedParameter($ledgerRevisionDbId))
    ->where($ledgerLink->expr()->eq('id', $ledgerLink->createNamedParameter($ledgerTransactionDbId)))
    ->executeStatement();

deleteWhere($db, 'pettycash_workflow_decisions', 'transaction_id', $ledgerTransactionDbId);
deleteWhere($db, 'pettycash_evidence', 'transaction_id', $ledgerTransactionDbId);
deleteWhere($db, 'pettycash_transaction_audit', 'transaction_id', $ledgerTransactionDbId);
deleteWhere($db, 'pettycash_transaction_audit', 'sheet_id', $ledgerSheetDbId);

// --- The account ledger ---------------------------------------------------
//
// The opening balance is written directly on the account row, which is a balance with no history
// behind it — and a ledger that begins mid-story cannot show that a charge and a consumption are
// distinguishable, that `balance_after` moves in the right direction, or that the last row agrees
// with the balance the account actually carries. Two rows make all three checkable.
//
// The figures are chosen so the ledger reconciles with the account row *and* leaves that row's
// balance at 100,000,000, which the submission and negative-balance assertions already depend on:
// a charge of 100,100,000 followed by a 100,000 consumption ends at exactly 100,000,000. Changing
// the account balance to make the numbers easier would have quietly moved the baseline for every
// other contract test that reads it.
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
    'description' => 'Settlement of an earlier expense',
    'source_revision_id' => $ledgerRevisionDbId,
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
$otherCostCenterDbId = null;

if (in_array($state, ['scope', 'finance'], true)) {
    upsert($db, 'pettycash_cost_centers', 'public_id', FIXTURE_IDS['otherCostCenter'], array_merge([
        'public_id' => FIXTURE_IDS['otherCostCenter'],
        'code' => 'CC-200',
        'name' => 'Contract Fixture Unmanaged Cost Center',
        'description' => 'Seeded so out-of-scope decisions can be refused and the shared finance queue can be told from a scoped one.',
        'valid_from' => $today,
        'valid_to' => null,
        'active' => true,
        'lock_version' => 0,
    ], $audit));

    $otherCostCenterDbId = scalar($db, 'pettycash_cost_centers', FIXTURE_IDS['otherCostCenter'], 'id');
    $otherCostCenterId = FIXTURE_IDS['otherCostCenter'];
}

// The transaction that proves a cost-centre decision is refused outside the actor's
// own cost centers. The cost center itself is shared with the `finance` state, which
// needs it for a different reason, but this transaction belongs to the scope
// assertions alone: a CC-200 transaction sitting in cost-centre review would
// otherwise show up in the cost-centre queue of every finance-state assertion.
if ($state === 'scope') {
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

// Accounts a suite opened, as opposed to the three the fixture seeds.
//
// `POST /accounts` creates a real account, and `pettycash_accounts_user_currency_uniq` makes it
// permanent: once a run has opened one in a currency, every later run of the same state collides with
// it, so the endpoint could only ever be observed in its refusal and the suite's own result depended
// on whether it had run before.
$openedAccounts = $db->getQueryBuilder()->delete('pettycash_accounts');
$openedAccounts->where($openedAccounts->expr()->eq('user_uid', $openedAccounts->createNamedParameter($userUid)))
    ->andWhere($openedAccounts->expr()->notIn('public_id', $openedAccounts->createNamedParameter(
        [FIXTURE_IDS['account'], FIXTURE_IDS['secondAccount'], FIXTURE_IDS['foreignAccount']],
        \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
    )))
    ->executeStatement();

// Ledger rows a settlement or a charge wrote, as opposed to the two the fixture seeds.
//
// Every account the acting user holds, not only the primary one: a charge against the USD account
// leaves its movement behind just as a settlement against the IRR account does, and a surviving
// movement describes a balance its account no longer holds — the newest movement's `balanceAfter` and
// the account's own `balance` are two separate reads of the same fact, and
// `AccountControllerTest` asserts they agree.
$userAccountIds = [];
$userAccountSelect = $db->getQueryBuilder();
$userAccountRows = $userAccountSelect->select('id')
    ->from('pettycash_accounts')
    ->where($userAccountSelect->expr()->eq('user_uid', $userAccountSelect->createNamedParameter($userUid)))
    ->executeQuery()
    ->fetchAllAssociative();
foreach ($userAccountRows as $userAccountRow) {
    $userAccountIds[] = (int) $userAccountRow['id'];
}

if ($userAccountIds !== []) {
    $settlementMovements = $db->getQueryBuilder()->delete('pettycash_account_movements');
    $settlementMovements->where($settlementMovements->expr()->in('account_id', $settlementMovements->createNamedParameter(
        $userAccountIds,
        \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
    )))
        ->andWhere($settlementMovements->expr()->notIn('public_id', $settlementMovements->createNamedParameter(
            [FIXTURE_IDS['chargeMovement'], FIXTURE_IDS['consumptionMovement']],
            \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
        )))
        ->executeStatement();
}

// --- Transactions awaiting finance review ----------------------------------
//
// Placed after the reset above rather than beside the other business records,
// because the primary transaction's management decisions are deleted by that reset
// and a block that seeded them earlier would have them deleted after it.
//
// Every figure here is a different amount so that a settlement writing the wrong
// revision's figure is visible in the balance rather than merely plausible.
$financeSharedId = null;
$financeRejectedId = null;
$financeSharedVersion = null;
$financeRejectedVersion = null;
// T370: the two finance sheets, so the completed-state lock suite can settle a transaction and
// then address the sheet it completed. Null in every other state, like the ids above, so a suite
// that forgets to `prepare('finance')` fails loudly rather than against a sheet that holds nothing.
$financeSharedSheetId = null;
$financeRejectedSheetId = null;

if ($state === 'finance') {
    seedFinanceReviewTransaction($db, [
        'userUid' => $userUid,
        'now' => $now,
        'today' => $today,
        'accountDbId' => $accountDbId,
        'currencyDbId' => $currencyDbId,
        'expenseTypeDbId' => $expenseTypeDbId,
        'decisions' => ['cost-center', 'senior'],
        'seniorOutcome' => 'APPROVE',
    ] + [
        // The primary trio, already written above with a `finance` baseline; this
        // adds the two management decisions and nothing else.
        'sheetId' => FIXTURE_IDS['sheet'],
        'transactionId' => FIXTURE_IDS['transaction'],
        'revisionId' => FIXTURE_IDS['revision'],
        'title' => 'Contract Fixture Sheet',
        'amountMinor' => 100000,
        'description' => 'Contract fixture expense',
        'status' => 'SENIOR_APPROVED',
        'costCenterDbId' => $costCenterDbId,
        'costCenterCode' => 'CC-100',
        'costCenterName' => 'Contract Fixture Cost Center',
        'costCenterDecision' => FIXTURE_IDS['costCenterDecision'],
        'seniorDecision' => FIXTURE_IDS['seniorDecision'],
        'commands' => FIXTURE_IDS['primaryCommands'],
    ]);

    seedFinanceReviewTransaction($db, [
        'userUid' => $userUid,
        'now' => $now,
        'today' => $today,
        'accountDbId' => $accountDbId,
        'currencyDbId' => $currencyDbId,
        'expenseTypeDbId' => $expenseTypeDbId,
        'decisions' => ['cost-center', 'senior'],
        'seniorOutcome' => 'APPROVE',
        'sheetId' => FIXTURE_IDS['sharedSheet'],
        'transactionId' => FIXTURE_IDS['sharedTransaction'],
        'revisionId' => FIXTURE_IDS['sharedRevision'],
        'title' => 'Contract Fixture Unmanaged Cost Center Sheet',
        'amountMinor' => 60000,
        'description' => 'Contract fixture expense awaiting finance in an unmanaged cost center',
        'status' => 'SENIOR_APPROVED',
        'costCenterDbId' => $otherCostCenterDbId,
        'costCenterCode' => 'CC-200',
        'costCenterName' => 'Contract Fixture Unmanaged Cost Center',
        'costCenterDecision' => FIXTURE_IDS['sharedCostCenterDecision'],
        'seniorDecision' => FIXTURE_IDS['sharedSeniorDecision'],
        'commands' => FIXTURE_IDS['sharedCommands'],
    ]);

    seedFinanceReviewTransaction($db, [
        'userUid' => $userUid,
        'now' => $now,
        'today' => $today,
        'accountDbId' => $accountDbId,
        'currencyDbId' => $currencyDbId,
        'expenseTypeDbId' => $expenseTypeDbId,
        'decisions' => ['cost-center', 'senior'],
        'seniorOutcome' => 'REJECT',
        'sheetId' => FIXTURE_IDS['rejectedSheet'],
        'transactionId' => FIXTURE_IDS['rejectedTransaction'],
        'revisionId' => FIXTURE_IDS['rejectedRevision'],
        'title' => 'Contract Fixture Senior-Rejected Sheet',
        'amountMinor' => 70000,
        'description' => 'Contract fixture expense the senior manager refused',
        'status' => 'SENIOR_REJECTED',
        'costCenterDbId' => $costCenterDbId,
        'costCenterCode' => 'CC-100',
        'costCenterName' => 'Contract Fixture Cost Center',
        'costCenterDecision' => FIXTURE_IDS['rejectedCostCenterDecision'],
        'seniorDecision' => FIXTURE_IDS['rejectedSeniorDecision'],
        'commands' => FIXTURE_IDS['rejectedCommands'],
    ]);

    $financeSharedId = FIXTURE_IDS['sharedTransaction'];
    $financeRejectedId = FIXTURE_IDS['rejectedTransaction'];
    $financeSharedVersion = columnValue($db, 'pettycash_transactions', FIXTURE_IDS['sharedTransaction'], 'lock_version');
    $financeRejectedVersion = columnValue($db, 'pettycash_transactions', FIXTURE_IDS['rejectedTransaction'], 'lock_version');
    // The two finance sheets the T370 lock suite settles into `COMPLETED` and
    // `COMPLETED_WITH_REJECTIONS`. Each finance fixture owns its own sheet precisely so that
    // settling one transaction cannot disturb another, which is what makes each of the two
    // completed states reachable from a single decision.
    $financeSharedSheetId = FIXTURE_IDS['sharedSheet'];
    $financeRejectedSheetId = FIXTURE_IDS['rejectedSheet'];
} else {
    // The primary trio is reset by the baseline above, so only the two extra
    // transactions have to go. Their decisions go with them, which is why a later
    // `finance` run starts from a clean history instead of colliding on
    // `pettycash_workflow_decisions_revision_stage_uniq`.
    removeFinanceReviewFixture($db, [
        'sheet' => FIXTURE_IDS['sharedSheet'],
        'transaction' => FIXTURE_IDS['sharedTransaction'],
        'revision' => FIXTURE_IDS['sharedRevision'],
    ]);
    removeFinanceReviewFixture($db, [
        'sheet' => FIXTURE_IDS['rejectedSheet'],
        'transaction' => FIXTURE_IDS['rejectedTransaction'],
        'revision' => FIXTURE_IDS['rejectedRevision'],
    ]);
}

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
    'ledgerRevisionId'   => FIXTURE_IDS['ledgerRevision'],
    // Seeded only in the `correction` state, like the row itself. A suite that needs it in another
    // state gets `null` and fails loudly rather than deleting an evidence id that matches nothing.
    'evidenceId'         => $state === 'correction' ? FIXTURE_IDS['evidence'] : null,
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
    // Present only in the `finance` state. The primary trio's ids above are already
    // reported for every state, because the finance state reuses them rather than
    // seeding a fourth transaction: the ordinary case is the same sheet and revision
    // every other state uses, moved to finance review.
    'financeSharedTransactionId' => $financeSharedId,
    'financeSharedTransactionVersion' => $financeSharedVersion,
    'financeRejectedTransactionId' => $financeRejectedId,
    'financeRejectedTransactionVersion' => $financeRejectedVersion,
    'financeSharedSheetId'    => $financeSharedSheetId,
    'financeRejectedSheetId'  => $financeRejectedSheetId,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
