<?php

declare(strict_types=1);

/**
 * One-off inspection helper: counts rows left behind by an interrupted round-trip run.
 *
 * Every predicate here is specific to `roundtrip-runner.php`'s own fingerprints — the `roundtrip/`
 * AppData prefix, the `CC`-prefixed cost-center codes, and the all-`b` evidence-link token that an
 * earlier revision of the runner hard-coded. Nothing here can match a contract fixture row.
 *
 * Usage: php tests/Integration/Persistence/roundtrip-leftovers.php [--delete]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This helper must be run from the command line.\n");
    exit(1);
}

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
require_once $nextcloudRoot . '/lib/base.php';

$db = \OCP\Server::get(\OCP\IDBConnection::class);
$delete = in_array('--delete', $argv, true);

$predicates = [
    // A list of pairs, not a keyed map: three of these share one table, and a keyed map would keep
    // only the last of them.
    ['pettycash_evidence_links', static function ($qb) {
        return $qb->expr()->eq('token_hash', $qb->createNamedParameter(str_repeat('b', 64)));
    }],
    ['pettycash_evidence', static function ($qb) {
        return $qb->expr()->like('appdata_key', $qb->createNamedParameter('roundtrip/%'));
    }],
    ['pettycash_cost_centers', static function ($qb) {
        return $qb->expr()->eq('name', $qb->createNamedParameter('Round Trip Cost Center'));
    }],
    ['pettycash_expense_types', static function ($qb) {
        return $qb->expr()->like('title', $qb->createNamedParameter('Round Trip Expense %'));
    }],
    ['pettycash_currencies', static function ($qb) {
        return $qb->expr()->eq('name', $qb->createNamedParameter('Round Trip Currency'));
    }],
    ['pettycash_currencies', static function ($qb) {
        return $qb->expr()->eq('name', $qb->createNamedParameter('Round Trip Account Currency'));
    }],
    ['pettycash_currencies', static function ($qb) {
        return $qb->expr()->eq('name', $qb->createNamedParameter('Round Trip Expense Currency'));
    }],
];

$total = 0;
foreach ($predicates as [$table, $predicate]) {
    $countQb = $db->getQueryBuilder();
    $countQb->select($countQb->func()->count('*'))->from($table)->where($predicate($countQb));
    $count = (int) $countQb->executeQuery()->fetchOne();

    if ($count === 0) {
        continue;
    }

    echo $table, ': ', $count, " row(s)\n";
    $total += $count;

    if ($delete) {
        $deleteQb = $db->getQueryBuilder();
        $deleteQb->delete($table)->where($predicate($deleteQb));
        echo '  deleted ', $deleteQb->executeStatement(), "\n";
    }
}

echo $total === 0 ? "No leftovers.\n" : "Total: {$total}\n";
