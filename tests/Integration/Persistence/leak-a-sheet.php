<?php

declare(strict_types=1);

/**
 * Writes, on request, exactly the row a persistence round trip that died between an insert and its
 * `$track()` registration leaves behind -- so the leak post-condition can be tested against a real leak
 * rather than described.
 *
 * ## Why this exists
 *
 * `RepositoryRoundTripTest` asserts that the round-trip runner fails its own scenario when its sweep left
 * something behind. Asserting that against source text cannot work: an `if (false)` around the
 * enforcement satisfies every "the guard is present" assertion while the suite enforces nothing. That
 * was tried, and the suite stayed green with the enforcement deleted.
 *
 * The fix is to make the leak rather than describe it. This writes one sheet carrying a harness
 * fingerprint -- a title no application path produces -- which the next scenario's sweep finds and
 * removes. So the row is real, the sweep that removes it is the real sweep, and the assertion is on a
 * failure that actually happened.
 *
 * ## Why it is safe to leave behind
 *
 * A sheet is the root of a subtree, and this one has no transactions, so it is a single row. It carries a
 * fingerprint the shared definition knows, so `roundtrip-leftovers.php --delete` and the next round-trip
 * run both remove it, and neither can mistake it for a contract fixture row. The worst outcome of a
 * crashed test is therefore one extra sheet that the next run cleans up -- not the seven dangling
 * `currency_id` rows that T308 had to unpick.
 *
 * Usage (inside the container):
 *   php tests/Integration/Persistence/leak-a-sheet.php <nextcloud-user>
 *   php tests/Integration/Persistence/leak-a-sheet.php --count
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This helper must be run from the command line.\n");
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

/** A fixed public id, so repeated calls update one row rather than accumulating. */
const LEAK_PUBLIC_ID = 'eaeaae01-1111-4111-8111-111111111111';

$db = \OCP\Server::get(IDBConnection::class);

try {
    if (in_array('--count', $argv, true)) {
        $qb = $db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from('pettycash_sheets')
            ->where($qb->expr()->eq('title', $qb->createNamedParameter(LeakedRows::ROUND_TRIP_SHEET_TITLE)));

        echo json_encode(['sheets' => (int) $qb->executeQuery()->fetchOne()]);

        exit(0);
    }

    $userUid = $argv[1] ?? '';
    if ($userUid === '' || \OCP\Server::get(IUserManager::class)->get($userUid) === null) {
        fwrite(STDERR, "Usage: leak-a-sheet.php <nextcloud-user>\n");
        exit(1);
    }

    $accountQb = $db->getQueryBuilder();
    $accountQb->select('id')
        ->addSelect('currency_id')
        ->from('pettycash_accounts')
        ->where($accountQb->expr()->eq('user_uid', $accountQb->createNamedParameter($userUid)))
        ->setMaxResults(1);
    $account = $accountQb->executeQuery()->fetchAssociative();
    if ($account === false) {
        throw new RuntimeException(
            "The acting user '{$userUid}' holds no account, so a sheet has no account to reference."
        );
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $values = [
        'public_id' => LEAK_PUBLIC_ID,
        'title' => LeakedRows::ROUND_TRIP_SHEET_TITLE,
        'owner_uid' => $userUid,
        'account_id' => (int) $account['id'],
        'currency_id' => (int) $account['currency_id'],
        'status' => 'OPEN',
        'submitted_total_minor' => 0,
        'first_submitted_at' => null,
        'management_completed_at' => null,
        'finance_started_at' => null,
        'completed_at' => null,
        'lock_version' => 0,
        'created_at' => $now,
    ];

    $exists = $db->getQueryBuilder();
    $existing = $exists->select('id')
        ->from('pettycash_sheets')
        ->where($exists->expr()->eq('public_id', $exists->createNamedParameter(LEAK_PUBLIC_ID)))
        ->setMaxResults(1)
        ->executeQuery()
        ->fetchAssociative();

    if ($existing === false) {
        $insert = $db->getQueryBuilder();
        $insert->insert('pettycash_sheets');
        foreach ($values as $column => $value) {
            $insert->setValue($column, $insert->createNamedParameter($value));
        }
        $insert->executeStatement();
    } else {
        $update = $db->getQueryBuilder();
        $update->update('pettycash_sheets');
        foreach ($values as $column => $value) {
            $update->set($column, $update->createNamedParameter($value));
        }
        $update->where($update->expr()->eq('id', $update->createNamedParameter((int) $existing['id'])))
            ->executeStatement();
    }

    echo json_encode(['publicId' => LEAK_PUBLIC_ID, 'title' => LeakedRows::ROUND_TRIP_SHEET_TITLE]);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    echo json_encode(['error' => $e->getMessage()]);
    exit(1);
}
