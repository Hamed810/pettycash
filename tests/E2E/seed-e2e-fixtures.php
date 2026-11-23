<?php

declare(strict_types=1);

/**
 * Fixture seeder for the **browser** (Playwright) suite, owned by `pettycash-e2e`.
 *
 * ## Why this exists and is not a parameter of the contract seeder
 *
 * `tests/Contract/Fixture/seed-contract-fixtures.php` pins every fixture identifier in a `const`
 * array (`FIXTURE_IDS`, line 90: `11111111-…` through `99999999-…`). It is therefore a **single-user**
 * fixture set by construction, and running it for a second user does not create a second set — it
 * **overwrites the first**, because both users end up owning the same `public_id`s. The observable
 * consequence, measured: seeding `pettycash-e2e` reported success and `GET /accounts` then answered
 * `total: 2`, but a later `scripts/dev/phpunit` run re-seeded `employee1` and the e2e account's accounts
 * were **gone**, leaving the sheet form rendering `sheets.noAccounts` with the account `NcSelect`
 * disabled (`:disabled="loadingAccounts || !hasAccounts"`, `SheetFormView.vue:176`).
 *
 * Two suites writing overlapping rows is the failure being fixed, and `T278` already made the contract
 * seeder a true reset for the contract suite's benefit. Parameterising its identifiers would put the
 * two suites one `git grep` away from colliding again, and the collision is silent: both "succeed".
 * So this is a **separate seeder with its own ID namespace**, and the two cannot overlap by accident.
 *
 * `employee1` is not an alternative. `global-setup.ts` already explains why: the OCS contract suite
 * authenticates as `employee1` and depends on that account's role assignments and seeded balances.
 *
 * ## What it seeds, and why each piece is needed
 *
 * - a **currency** and a **cost centre** and an **expense type**: global configuration rows. A
 *   transaction cannot be routed without a cost centre (`SubmitSheet.php:100` refuses a sheet whose any
 *   transaction has none), so without these no sheet containing an API-created transaction can be
 *   submitted at all.
 * - an **account** for the e2e user, so the sheet form's account `NcSelect` is enabled. It opens with a
 *   healthy balance because `T171`'s suite includes a case that submission must succeed regardless of
 *   balance.
 * - **role assignments** so a submitted sheet can actually be routed rather than stalling.
 *
 * Cost centres and expense types carry a human-facing unique `code`. These use an `E2E-` prefix so they
 * cannot collide with the contract seeder's `CC-100` / `ET-100` on the unique constraint — a collision
 * there would abort the run with a constraint error that reads like a schema bug.
 *
 * ## Idempotence and isolation
 *
 * Re-running is safe: every write is an upsert keyed on `public_id`. Isolation is the other half — see
 * `assertDoesNotDisturbOtherUsers()`, which is the check that would have caught the original collision.
 *
 * Usage:
 *   scripts/dev/in-container php tests/E2E/seed-e2e-fixtures.php [nextcloud-user]
 */

/**
 * Never exit 0 having done nothing.
 *
 * This exists because of how the first version of this script behaved. An uncaught
 * `DbalException` was being swallowed by Nextcloud's CLI error handler, which logged it to
 * `nextcloud.log` and **terminated with status 0**. Every run reported success, printed nothing, and
 * wrote no rows — and the only way to find out was to read the server's log by hand. A fixture seeder
 * that fails open is worse than one that is absent, because every downstream test reports a missing
 * record rather than a broken setup.
 *
 * So the whole body runs inside a handler that reports the class, message and origin on stderr and
 * exits non-zero. Any future statement added outside the `try` is a bug; keep new work inside it.
 */
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

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

$userUid = $argv[1] ?? getenv('PETTYCASH_E2E_USER') ?: '';
if ($userUid === '') {
    fwrite(STDERR, "Usage: seed-e2e-fixtures.php [nextcloud-user]\n");
    exit(1);
}

$userManager = \OCP\Server::get(\OCP\IUserManager::class);
if ($userManager->get($userUid) === null) {
    fwrite(STDERR, "No such Nextcloud account '{$userUid}'. Create it first; see .env.example.\n");
    exit(1);
}

/**
 * This seeder's own identifier namespace.
 *
 * Deliberately in a different shape from the contract seeder's `11111111-…` pattern so that a glance
 * at a row identifies which suite owns it. Every value is a valid v4-shaped UUID and none of them
 * appears in `FIXTURE_IDS`.
 */
const E2E_IDS = [
    'currency'    => 'e2e00000-0000-4000-8000-000000000001',
    'costCenter'  => 'e2e00000-0000-4000-8000-000000000002',
    'expenseType' => 'e2e00000-0000-4000-8000-000000000003',
    'account'     => 'e2e00000-0000-4000-8000-000000000004',
    'sheet'       => 'e2e00000-0000-4000-8000-000000000005',
    'managerRole' => 'e2e00000-0000-4000-8000-000000000006',
    'seniorRole'  => 'e2e00000-0000-4000-8000-000000000007',
];

/**
 * Insert-or-overwrite by key.
 *
 * Byte-for-byte the same shape as the contract seeder's `upsert()`, including the reason for one query
 * builder per statement: a named parameter created by a *different* builder instance is never bound and
 * is emitted verbatim, producing SQL like `... near ':dcValue1 LIMIT 1'`. That bug is subtle and already
 * paid for once, so the pattern is copied rather than reinvented.
 */
function e2eUpsert(\OCP\IDBConnection $db, string $table, string $keyColumn, string $keyValue, array $values): void
{
    $select = $db->getQueryBuilder();
    $existing = $select->select('id')
        ->from($table)
        ->where($select->expr()->eq($keyColumn, $select->createNamedParameter($keyValue)))
        ->setMaxResults(1)
        ->executeQuery()
        ->fetchAssociative();

    // No return type: Nextcloud's PARAM_STR/PARAM_BOOL are string constants, not ints.
    $type = static fn (mixed $v) => is_bool($v)
        ? \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL
        : \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR;

    if ($existing === false) {
        $insert = $db->getQueryBuilder()->insert($table);
        foreach ($values as $column => $value) {
            $insert->setValue($column, $insert->createNamedParameter($value, $type($value)));
        }
        $insert->executeStatement();
        return;
    }

    $update = $db->getQueryBuilder()->update($table);
    foreach ($values as $column => $value) {
        $update->set($column, $update->createNamedParameter($value, $type($value)));
    }
    $update->where($update->expr()->eq('id', $update->createNamedParameter((int) $existing['id'])))
        ->executeStatement();
}

function e2eDbId(\OCP\IDBConnection $db, string $table, string $keyValue, string $keyColumn = 'public_id'): int
{
    $qb = $db->getQueryBuilder();
    $id = $qb->select('id')
        ->from($table)
        ->where($qb->expr()->eq($keyColumn, $qb->createNamedParameter($keyValue)))
        ->setMaxResults(1)
        ->executeQuery()
        ->fetchOne();

    if ($id === false || $id === null) {
        fwrite(STDERR, "Expected row {$table}.{$keyColumn} = {$keyValue} to exist after upsert.\n");
        exit(1);
    }

    return (int) $id;
}

/**
 * A minimal, valid single-page PDF for the evidence-content scenario.
 *
 * The fixture used to write only the evidence metadata row, leaving `appdata_key` pointing at a
 * file that did not exist; the content endpoint therefore answered 500. This is the bytes that
 * the upload path would have stored, built here so the fixture has no binary asset dependency.
 */
function e2eEvidencePdf(): string
{
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 120] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    $stream = "BT\n/F1 14 Tf\n20 60 Td\n(E2E Receipt) Tj\nET\n";
    $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

    return $pdf;
}

/**
 * The isolation assertion, and the reason this seeder is trusted.
 *
 * Records which rows the *other* fixture owner had before this seeder ran, and verifies afterwards that
 * nothing outside `E2E_IDS` moved. The original bug was precisely a silent cross-write — the e2e user
 * and `employee1` ended up sharing `public_id`s — so a seeder that could cross-write without saying so
 * would be the same defect wearing a new filename.
 *
 * **A row snapshot needs the owner's column, not just the key.** The first version compared only
 * `public_id` and `updated_at`, which was doubly wrong: `pettycash_sheets` has no `updated_at` at all
 * (its columns are `id, public_id, title, owner_uid, account_id, currency_id, status,
 * submitted_total_minor, created_at, first_submitted_at, management_completed_at, finance_started_at,
 * completed_at, lock_version`), so the query raised a `DbalException` and Nextcloud's handler swallowed
 * it into `nextcloud.log` — **exit code 0, no output, no rows written**. A silent failure that looks
 * like success is the worst possible outcome for a fixture seeder, which is why the columns here were
 * read out of the schema rather than assumed a second time.
 *
 * And `public_id` alone would not have caught the bug even had it worked: the collision *re-owned*
 * existing rows, changing `owner_uid`/`user_uid` while leaving the key and the row count untouched. So
 * the snapshot carries the owner column per table — all three have one — plus `lock_version`, which all
 * three also have, so a silent in-place modification is visible.
 */
function snapshotForeignRows(\OCP\IDBConnection $db, array $ourIds): array
{
    // Every table listed here has been checked to carry all three of `public_id`, `lock_version` and
    // the named owner column. Adding a table to this list without checking would reintroduce exactly
    // the swallowed-exception failure above.
    $tables = [
        'pettycash_sheets' => 'owner_uid',
        'pettycash_transactions' => 'owner_uid',
        'pettycash_accounts' => 'user_uid',
    ];

    $snapshot = [];
    foreach ($tables as $table => $ownerColumn) {
        $qb = $db->getQueryBuilder();
        $rows = $qb->select('public_id', 'lock_version', $ownerColumn)
            ->from($table)
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $id = (string) ($row['public_id'] ?? '');
            if (!in_array($id, $ourIds, true)) {
                $snapshot["{$table}:{$id}"] = implode('|', [
                    (string) ($row['lock_version'] ?? ''),
                    (string) ($row[$ownerColumn] ?? ''),
                ]);
            }
        }
    }

    return $snapshot;
}

function assertDoesNotDisturbOtherUsers(\OCP\IDBConnection $db, array $before): void
{
    $after = snapshotForeignRows($db, array_values(E2E_IDS));
    $changed = [];
    foreach ($after as $key => $updatedAt) {
        if (array_key_exists($key, $before) && $before[$key] !== $updatedAt) {
            $changed[] = $key;
        }
    }
    foreach (array_keys($before) as $key) {
        if (!array_key_exists($key, $after)) {
            $changed[] = "{$key} (deleted)";
        }
    }

    if ($changed !== []) {
        fwrite(STDERR, "This seeder must not touch rows outside its own ID namespace, but it changed:\n  "
            . implode("\n  ", $changed) . "\n");
        exit(1);
    }
}

try {
	$db = \OCP\Server::get(\OCP\IDBConnection::class);
	$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	$today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
	$audit = ['created_at' => $now, 'created_by' => $userUid, 'updated_at' => $now, 'updated_by' => $userUid];

	$before = snapshotForeignRows($db, array_values(E2E_IDS));

	e2eUpsert($db, 'pettycash_currencies', 'code', 'IRR', array_merge([
	    'code' => 'IRR',
	    'name' => 'Iranian Rial',
	    'minor_unit' => 0,
	    'active' => true,
	], $audit));
	$currencyDbId = e2eDbId($db, 'pettycash_currencies', 'IRR', 'code');

	e2eUpsert($db, 'pettycash_cost_centers', 'public_id', E2E_IDS['costCenter'], array_merge([
	    'public_id' => E2E_IDS['costCenter'],
	    // `E2E-` prefixed: `code` is unique, and the contract seeder already owns `CC-100`.
	    'code' => 'E2E-CC-1',
	    'name' => 'E2E Cost Center',
	    'description' => 'Seeded for the Playwright suite only.',
	    'valid_from' => $today,
	    'valid_to' => null,
	    'active' => true,
	], $audit));
	$costCenterDbId = e2eDbId($db, 'pettycash_cost_centers', E2E_IDS['costCenter']);

	e2eUpsert($db, 'pettycash_expense_types', 'public_id', E2E_IDS['expenseType'], array_merge([
	    'public_id' => E2E_IDS['expenseType'],
	    // No `code` and no validity dates: `pettycash_expense_types` is
	    // `id, public_id, title, description, evidence_required, active, …`. Asserting a `code` here would
	    // have failed on an unknown column, and `code` is what made the `E2E-` prefix look necessary.
	    'title' => 'E2E Expense Type',
	    'description' => 'Seeded for the Playwright suite only.',
	    'evidence_required' => true,
	    'active' => true,
	], $audit));
	$expenseTypeDbId = e2eDbId($db, 'pettycash_expense_types', E2E_IDS['expenseType']);

	e2eUpsert($db, 'pettycash_accounts', 'public_id', E2E_IDS['account'], array_merge([
	    'public_id' => E2E_IDS['account'],
	    'user_uid' => $userUid,
	    'currency_id' => $currencyDbId,
	    // A healthy opening balance: `T171`'s suite asserts that submission succeeds regardless of
	    // balance, and that assertion is only meaningful against a solvent account.
	    'balance_minor' => 1000000000,
	    'active' => true,
	    'lock_version' => 0,
	], $audit));


	e2eUpsert($db, 'pettycash_role_assignments', 'public_id', E2E_IDS['managerRole'], array_merge([
	    'public_id' => E2E_IDS['managerRole'],
	    'user_uid' => $userUid,
	    'role' => 'COST_CENTER_MANAGER',
	    'cost_center_id' => $costCenterDbId,
	    'valid_from' => $today,
	    'valid_to' => null,
	    'active' => true,
	], $audit));

	e2eUpsert($db, 'pettycash_role_assignments', 'public_id', E2E_IDS['seniorRole'], array_merge([
	    'public_id' => E2E_IDS['seniorRole'],
	    'user_uid' => $userUid,
	    'role' => 'SENIOR_MANAGER',
	    // T053 evidence: the domain requires a cost centre for manager roles
	    // (RoleAssignment::create), senior included — a null centre makes /me
	    // answer 422, as the contract seeder already does it with a centre.
	    'cost_center_id' => $costCenterDbId,
	    'valid_from' => $today,
	    'valid_to' => null,
	    'active' => true,
	], $audit));

	// A draft sheet, so a test can open a detail page without creating one first. Deliberately left with
	// **no transactions**: `T054` and `T171` add their own, and seeding any here would make the sheet's
	// contents depend on seeding order.
	// A draft sheet, so a test can open a detail page without creating one first. Deliberately left with
	// **no transactions**: `T054` and `T171` add their own, and seeding any here would make the sheet's
	// contents depend on seeding order.
	//
	// Column names read from the schema, not guessed: the status column is `status` (there is no `state`),
	// the version column is `lock_version` (there is no `version`), and there is no `updated_at` — the audit
	// block below is the *contract* seeder's shape and does not belong here, since this table has no
	// `created_by`/`updated_by` to write.
	e2eUpsert($db, 'pettycash_sheets', 'public_id', E2E_IDS['sheet'], [
	    'public_id' => E2E_IDS['sheet'],
	    'title' => 'E2E Draft Sheet',
	    'owner_uid' => $userUid,
	    'account_id' => e2eDbId($db, 'pettycash_accounts', E2E_IDS['account']),
	    'currency_id' => $currencyDbId,
	    'status' => 'OPEN',
	    'submitted_total_minor' => 0,
	    'created_at' => $now,
	    'lock_version' => 0,
	]);

	// --- Finance Manager E2E fixtures (T046) ---
	//
	// A USD currency so a second (inactive) account can exist for the e2e user:
	// accounts are unique per (user_uid, currency_id).
	e2eUpsert($db, 'pettycash_currencies', 'code', 'USD', array_merge([
		'code' => 'USD',
		'name' => 'United States Dollar',
		'minor_unit' => 2,
		'active' => true,
	], $audit));
	$usdCurrencyDbId = e2eDbId($db, 'pettycash_currencies', 'USD', 'code');

	// A second inactive account so scenario 3 can show active + inactive side by side.
	$inactiveAccountPublicId = 'e2e00000-0000-4000-8000-000000000009';
	e2eUpsert($db, 'pettycash_accounts', 'public_id', $inactiveAccountPublicId, array_merge([
		'public_id' => $inactiveAccountPublicId,
		'user_uid' => $userUid,
		'currency_id' => $usdCurrencyDbId,
		'balance_minor' => 0,
		'active' => false,
		'lock_version' => 0,
	], $audit));

	// Ledger movements on the active account so scenario 4 can render a non-empty ledger.
	// Seeded below, right after the revision they reference, so the consumption's
	// `source_revision_id` can be written inline on insert (provenance).
	$activeAccountId = e2eDbId($db, 'pettycash_accounts', E2E_IDS['account']);
	$movementTime = (new DateTimeImmutable('2026-11-01 10:00:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

	// A completed Cost Sheet owned by the e2e user with one approved transaction
	// (scenarios 6-8: completed list, read-only detail, evidence viewing).
	$completedSheetPublicId = 'e2e00000-0000-4000-8000-000000000013';
	e2eUpsert($db, 'pettycash_sheets', 'public_id', $completedSheetPublicId, [
		'public_id' => $completedSheetPublicId,
		'title' => 'E2E Completed Sheet',
		'owner_uid' => $userUid,
		'account_id' => $activeAccountId,
		'currency_id' => $currencyDbId,
		'status' => 'COMPLETED',
		'submitted_total_minor' => 3000,
		'created_at' => $movementTime,
		'first_submitted_at' => $movementTime,
		'management_completed_at' => $movementTime,
		'finance_started_at' => $movementTime,
		'completed_at' => $movementTime,
		'lock_version' => 1,
	]);
	$completedSheetDbId = e2eDbId($db, 'pettycash_sheets', $completedSheetPublicId);

	// One approved transaction on the completed sheet. Its `active_revision_id` is
	// left null until the revision row exists, then back-linked below so the id
	// always matches the revision the database actually assigned.
	$transactionPublicId = 'e2e00000-0000-4000-8000-000000000014';
	e2eUpsert($db, 'pettycash_transactions', 'public_id', $transactionPublicId, [
		'public_id' => $transactionPublicId,
		'sheet_id' => $completedSheetDbId,
		'owner_uid' => $userUid,
		'active_revision_id' => null,
		'current_stage' => 'FINAL',
		'current_status' => 'COMPLETED_APPROVED',
		'final_outcome' => 'APPROVED',
		'created_at' => $movementTime,
		'updated_at' => $movementTime,
		'lock_version' => 1,
	]);
	$transactionDbId = e2eDbId($db, 'pettycash_transactions', $transactionPublicId);

	// The revision that authorised the consumption movement ...0011: a legitimate,
	// existing revision on the intended transaction provenance, so the movement
	// hydrates instead of failing on `Consumption source is required`.
	e2eUpsert($db, 'pettycash_transaction_revisions', 'public_id', 'e2e00000-0000-4000-8000-000000000012', [
		'public_id' => 'e2e00000-0000-4000-8000-000000000012',
		'transaction_id' => $transactionDbId,
		'revision_number' => 1,
		'expense_date' => $today,
		'amount_minor' => 2000,
		'description' => 'E2E ledger probe consumption',
		'cost_center_id' => $costCenterDbId,
		'cost_center_code' => 'E2E-CC-1',
		'cost_center_name' => 'E2E Cost Center',
		'expense_type_id' => $expenseTypeDbId,
		'expense_type_title' => 'E2E Expense Type',
		'manager_uid' => $userUid,
		'senior_manager_uid' => $userUid,
		'created_at' => $movementTime,
		'created_by' => $userUid,
		'activated_at' => $movementTime,
		'supersedes_revision_id' => null,
	]);
	$revisionDbId = e2eDbId($db, 'pettycash_transaction_revisions', 'e2e00000-0000-4000-8000-000000000012');

	// Point the transaction's active revision at the revision we just created. The
	// surrogate id the database assigned stays correct across repeated fixture runs.
	$qb = $db->getQueryBuilder();
	$linkTransaction = $qb->update('pettycash_transactions');
	$linkTransaction->set('active_revision_id', $qb->createNamedParameter($revisionDbId))
		->where($qb->expr()->eq('public_id', $qb->createNamedParameter($transactionPublicId)))
		->executeStatement();
	$revisionDbId = e2eDbId($db, 'pettycash_transaction_revisions', 'e2e00000-0000-4000-8000-000000000012');

	// Ledger movements on the active account so scenario 4 can render a non-empty ledger.
	// `source_revision_id` is written inline on insert so the consumption carries valid provenance.
	e2eUpsert($db, 'pettycash_account_movements', 'public_id', 'e2e00000-0000-4000-8000-000000000010', [
		'public_id' => 'e2e00000-0000-4000-8000-000000000010',
		'account_id' => $activeAccountId,
		'movement_type' => 'CHARGE',
		'amount_minor' => 5000,
		'balance_after_minor' => 5000,
		'description' => 'E2E ledger probe recharge',
		'source_revision_id' => null,
		'source_decision_id' => null,
		'command_id' => 'cmd-e2e-001',
		'actor_uid' => $userUid,
		'actor_name' => 'PettyCash E2E',
		'actor_role' => 'FINANCE',
		'occurred_at' => $movementTime,
	]);

	e2eUpsert($db, 'pettycash_account_movements', 'public_id', 'e2e00000-0000-4000-8000-000000000011', [
		'public_id' => 'e2e00000-0000-4000-8000-000000000011',
		'account_id' => $activeAccountId,
		'movement_type' => 'CONSUMPTION',
		'amount_minor' => 2000,
		'balance_after_minor' => 3000,
		'description' => 'E2E ledger probe consumption',
		'source_revision_id' => $revisionDbId,
		'source_decision_id' => null,
		'command_id' => 'cmd-e2e-002',
		'actor_uid' => $userUid,
		'actor_name' => 'PettyCash E2E',
		'actor_role' => 'FINANCE',
		'occurred_at' => $movementTime,
	]);

	// Sync the account balance to the last movement so the UI balance matches.
	$qb = $db->getQueryBuilder();
	$update = $qb->update('pettycash_accounts');
	$update->set('balance_minor', $update->createNamedParameter(3000));
	$update->where($qb->expr()->eq('public_id', $update->createNamedParameter(E2E_IDS['account'])))
		->executeStatement();

	// Evidence metadata and the bytes it names (scenario 8: evidence viewing).
	//
	// A metadata row is not evidence: `EvidenceController::content()` reads the bytes from
	// `appdata_key`. The old fixture wrote only the row, so the deterministic evidence id pointed
	// at a file that never existed and the content endpoint answered 500. Write the file through
	// the same PrivateFileStore adapter as the upload path, then record the key, size and checksum
	// of the bytes that were actually stored.
	\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('pettycash');
	$fileStore = \OCP\Server::get(\OCA\PettyCash\Application\Port\PrivateFileStore::class);
	$evidenceContent = e2eEvidencePdf();
	$evidenceAppDataKey = $fileStore->putEvidence(
		$transactionPublicId,
		1,
		'E2E Receipt.pdf',
		$evidenceContent
	);

	$evidencePublicId = 'e2e00000-0000-4000-8000-000000000015';
	e2eUpsert($db, 'pettycash_evidence', 'public_id', $evidencePublicId, [
		'public_id' => $evidencePublicId,
		'transaction_id' => $transactionDbId,
		'origin_revision_id' => $revisionDbId,
		'appdata_key' => $evidenceAppDataKey,
		'original_name' => 'E2E Receipt.pdf',
		'media_type' => 'application/pdf',
		'size_bytes' => strlen($evidenceContent),
		'checksum' => $fileStore->checksumOfContent($evidenceContent),
		'active' => true,
		'uploaded_by' => $userUid,
		'uploaded_at' => $movementTime,
	]);

	// --- Finance Expense Review E2E fixtures (Feature 009) ---
	//
	// Finance-eligible sheet: two senior-approved transactions awaiting Finance decision.
	// Used by Feature 009 E2E scenarios: Cost Sheet queue, modal, per-transaction decisions,
	// evidence viewing, focus restoration, and queue/modal reconciliation.
	$financeSheetPublicId = 'e2e00000-0000-4000-8000-000000000016';
	e2eUpsert($db, 'pettycash_sheets', 'public_id', $financeSheetPublicId, [
		'public_id' => $financeSheetPublicId,
		'title' => 'E2E Finance Review Sheet',
		'owner_uid' => $userUid,
		'account_id' => $activeAccountId,
		'currency_id' => $currencyDbId,
		'status' => 'FINANCE_REVIEW',
		'submitted_total_minor' => 7500,
		'created_at' => $movementTime,
		'first_submitted_at' => $movementTime,
		'management_completed_at' => $movementTime,
		'finance_started_at' => $movementTime,
		'lock_version' => 1,
	]);
	$financeSheetDbId = e2eDbId($db, 'pettycash_sheets', $financeSheetPublicId);

	// Finance-eligible transaction A: senior-approved, awaiting Finance decision.
	$financeTxAPublicId = 'e2e00000-0000-4000-8000-000000000017';
	e2eUpsert($db, 'pettycash_transactions', 'public_id', $financeTxAPublicId, [
		'public_id' => $financeTxAPublicId,
		'sheet_id' => $financeSheetDbId,
		'owner_uid' => $userUid,
		'active_revision_id' => null,
		'current_stage' => 'FINANCE',
		'current_status' => 'AWAITING_FINANCE_REVIEW',
		'final_outcome' => null,
		'created_at' => $movementTime,
		'updated_at' => $movementTime,
		'lock_version' => 1,
	]);
	$financeTxADbId = e2eDbId($db, 'pettycash_transactions', $financeTxAPublicId);

	// Finance-eligible transaction B: senior-approved, awaiting Finance decision (second sibling).
	$financeTxBPublicId = 'e2e00000-0000-4000-8000-000000000018';
	e2eUpsert($db, 'pettycash_transactions', 'public_id', $financeTxBPublicId, [
		'public_id' => $financeTxBPublicId,
		'sheet_id' => $financeSheetDbId,
		'owner_uid' => $userUid,
		'active_revision_id' => null,
		'current_stage' => 'FINANCE',
		'current_status' => 'AWAITING_FINANCE_REVIEW',
		'final_outcome' => null,
		'created_at' => $movementTime,
		'updated_at' => $movementTime,
		'lock_version' => 1,
	]);
	$financeTxBDbId = e2eDbId($db, 'pettycash_transactions', $financeTxBPublicId);

	// Senior-approved revisions for both finance-eligible transactions.
	e2eUpsert($db, 'pettycash_transaction_revisions', 'public_id', 'e2e00000-0000-4000-8000-000000000021', [
		'public_id' => 'e2e00000-0000-4000-8000-000000000021',
		'transaction_id' => $financeTxADbId,
		'revision_number' => 1,
		'expense_date' => $today,
		'amount_minor' => 3000,
		'description' => 'E2E Finance review transaction A',
		'cost_center_id' => $costCenterDbId,
		'cost_center_code' => 'E2E-CC-1',
		'cost_center_name' => 'E2E Cost Center',
		'expense_type_id' => $expenseTypeDbId,
		'expense_type_title' => 'E2E Expense Type',
		'manager_uid' => $userUid,
		'senior_manager_uid' => $userUid,
		'created_at' => $movementTime,
		'created_by' => $userUid,
		'activated_at' => $movementTime,
		'supersedes_revision_id' => null,
	]);
	$revisionADbId = e2eDbId($db, 'pettycash_transaction_revisions', 'e2e00000-0000-4000-8000-000000000021');

	e2eUpsert($db, 'pettycash_transaction_revisions', 'public_id', 'e2e00000-0000-4000-8000-000000000022', [
		'public_id' => 'e2e00000-0000-4000-8000-000000000022',
		'transaction_id' => $financeTxBDbId,
		'revision_number' => 1,
		'expense_date' => $today,
		'amount_minor' => 4500,
		'description' => 'E2E Finance review transaction B',
		'cost_center_id' => $costCenterDbId,
		'cost_center_code' => 'E2E-CC-1',
		'cost_center_name' => 'E2E Cost Center',
		'expense_type_id' => $expenseTypeDbId,
		'expense_type_title' => 'E2E Expense Type',
		'manager_uid' => $userUid,
		'senior_manager_uid' => $userUid,
		'created_at' => $movementTime,
		'created_by' => $userUid,
		'activated_at' => $movementTime,
		'supersedes_revision_id' => null,
	]);
	$revisionBDbId = e2eDbId($db, 'pettycash_transaction_revisions', 'e2e00000-0000-4000-8000-000000000022');

	// Back-link transactions to their revisions.
	$qb = $db->getQueryBuilder();
	$qb->update('pettycash_transactions')
		->set('active_revision_id', $qb->createNamedParameter($revisionADbId))
		->where($qb->expr()->eq('public_id', $qb->createNamedParameter($financeTxAPublicId)))
		->executeStatement();
	$qb->update('pettycash_transactions')
		->set('active_revision_id', $qb->createNamedParameter($revisionBDbId))
		->where($qb->expr()->eq('public_id', $qb->createNamedParameter($financeTxBPublicId)))
		->executeStatement();

	// Evidence on finance-eligible transaction A.
	$evidenceAppDataKeyA = $fileStore->putEvidence(
		$financeTxAPublicId,
		1,
		'E2E Finance Receipt.pdf',
		$evidenceContent
	);
	$evidenceAPublicId = 'e2e00000-0000-4000-8000-000000000023';
	e2eUpsert($db, 'pettycash_evidence', 'public_id', $evidenceAPublicId, [
		'public_id' => $evidenceAPublicId,
		'transaction_id' => $financeTxADbId,
		'origin_revision_id' => $revisionADbId,
		'appdata_key' => $evidenceAppDataKeyA,
		'original_name' => 'E2E Finance Receipt.pdf',
		'media_type' => 'application/pdf',
		'size_bytes' => strlen($evidenceContent),
		'checksum' => $fileStore->checksumOfContent($evidenceContent),
		'active' => true,
		'uploaded_by' => $userUid,
		'uploaded_at' => $movementTime,
	]);

	// Senior-rejected sheet: one senior-rejected transaction (Finance cannot approve).
	$rejectedSheetPublicId = 'e2e00000-0000-4000-8000-000000000024';
	e2eUpsert($db, 'pettycash_sheets', 'public_id', $rejectedSheetPublicId, [
		'public_id' => $rejectedSheetPublicId,
		'title' => 'E2E Senior-Rejected Sheet',
		'owner_uid' => $userUid,
		'account_id' => $activeAccountId,
		'currency_id' => $currencyDbId,
		'status' => 'FINANCE_REVIEW',
		'submitted_total_minor' => 1500,
		'created_at' => $movementTime,
		'first_submitted_at' => $movementTime,
		'management_completed_at' => $movementTime,
		'finance_started_at' => $movementTime,
		'lock_version' => 1,
	]);
	$rejectedSheetDbId = e2eDbId($db, 'pettycash_sheets', $rejectedSheetPublicId);

	// Senior-rejected transaction: Finance may reject or return, but not approve.
	$rejectedTxPublicId = 'e2e00000-0000-4000-8000-000000000025';
	e2eUpsert($db, 'pettycash_transactions', 'public_id', $rejectedTxPublicId, [
		'public_id' => $rejectedTxPublicId,
		'sheet_id' => $rejectedSheetDbId,
		'owner_uid' => $userUid,
		'active_revision_id' => null,
		'current_stage' => 'FINANCE',
		'current_status' => 'AWAITING_FINANCE_REVIEW',
		'final_outcome' => null,
		'created_at' => $movementTime,
		'updated_at' => $movementTime,
		'lock_version' => 1,
	]);
	$rejectedTxDbId = e2eDbId($db, 'pettycash_transactions', $rejectedTxPublicId);

	// Senior-rejected revision: senior decision was REJECT, so Finance cannot approve.
	e2eUpsert($db, 'pettycash_transaction_revisions', 'public_id', 'e2e00000-0000-4000-8000-000000000026', [
		'public_id' => 'e2e00000-0000-4000-8000-000000000026',
		'transaction_id' => $rejectedTxDbId,
		'revision_number' => 1,
		'expense_date' => $today,
		'amount_minor' => 1500,
		'description' => 'E2E Senior-rejected transaction',
		'cost_center_id' => $costCenterDbId,
		'cost_center_code' => 'E2E-CC-1',
		'cost_center_name' => 'E2E Cost Center',
		'expense_type_id' => $expenseTypeDbId,
		'expense_type_title' => 'E2E Expense Type',
		'manager_uid' => $userUid,
		'senior_manager_uid' => $userUid,
		'created_at' => $movementTime,
		'created_by' => $userUid,
		'activated_at' => $movementTime,
		'supersedes_revision_id' => null,
	]);
	$rejectedRevisionDbId = e2eDbId($db, 'pettycash_transaction_revisions', 'e2e00000-0000-4000-8000-000000000026');

	$qb->update('pettycash_transactions')
		->set('active_revision_id', $qb->createNamedParameter($rejectedRevisionDbId))
		->where($qb->expr()->eq('public_id', $qb->createNamedParameter($rejectedTxPublicId)))
		->executeStatement();

	assertDoesNotDisturbOtherUsers($db, $before);


} catch (\Throwable $e) {
	fwrite(STDERR, 'E2E fixture seeding FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n"
		. '  at ' . $e->getFile() . ':' . $e->getLine() . "\n");
	exit(1);
}

echo json_encode([
    'user' => $userUid,
    'currencyDbId' => $currencyDbId,
    'costCenterId' => E2E_IDS['costCenter'],
    'expenseTypeId' => E2E_IDS['expenseType'],
    'accountId' => E2E_IDS['account'],
    'sheetId' => E2E_IDS['sheet'],
    'otherUsersUntouched' => true,
], JSON_PRETTY_PRINT) . "\n";
