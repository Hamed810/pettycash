<?php

declare(strict_types=1);

/**
 * Counts the rows a test harness left behind in the shared MariaDB, and optionally removes them.
 *
 * ## Why this file no longer has its own predicate list
 *
 * It did, and the list disagreed with the runner's. That is the whole reason it exists: the two were
 * separately written descriptions of the same set of rows, the diagnostic had no `pettycash_sheets`
 * predicate at all, and it therefore printed `No leftovers` for as long as seven sheets with a dangling
 * `currency_id` sat in the database refusing `GET /reports/transactions`, `/reports/process` and
 * `/reports/evidence` outright. The operator running the diagnostic was told everything was fine by
 * the one tool whose job was to say otherwise.
 *
 * The definition now lives once, in `tests/Support/LeakedRows.php`, and this file and
 * `roundtrip-runner.php` both read it. A predicate that exists in one place cannot be missing from
 * the other by accident, which is the property the two separate lists could never have.
 *
 * Usage: php tests/Integration/Persistence/roundtrip-leftovers.php [--delete]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This helper must be run from the command line.\n");
    exit(1);
}

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
require_once $nextcloudRoot . '/lib/base.php';
require_once __DIR__ . '/../../Support/LeakedRows.php';

use OCA\PettyCash\Tests\Support\LeakedRows;
use OCP\IDBConnection;

// `lib/base.php` switches `display_errors` off, so an uncaught error in this script would exit 0
// having printed nothing -- which for a diagnostic is the exact failure it was written to prevent: it
// would report a clean database because it had crashed before looking. Every exit path is therefore
// explicit, and a throw is turned into a non-zero exit with the message on stderr.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$db = \OCP\Server::get(IDBConnection::class);
$delete = in_array('--delete', $argv, true);

try {
    $total = 0;
    foreach (LeakedRows::steps() as $step) {
        // The count comes from the shared definition, re-derived from the database. It deliberately
        // does not reuse the sweep's own arithmetic: a diagnostic that trusted the sweep's numbers
        // could not detect the sweep having done nothing, which is the failure it exists to catch.
        $count = LeakedRows::probeOne($db, $step);
        if ($count === 0) {
            continue;
        }

        echo $step['name'], ': ', $count, " row(s)\n";
        $total += $count;

        if ($delete) {
            echo '  removed ', LeakedRows::sweepOne($db, $step), "\n";
        }
    }

    if ($delete) {
        // The post-condition, evaluated after the last removal rather than after the first predicate
        // that happened to match. A `--delete` that reports success while rows remain is worse than
        // one that refuses: the operator stops looking.
        $remaining = LeakedRows::leftovers($db);
        if ($remaining !== []) {
            echo 'STILL PRESENT after deletion: ', json_encode($remaining, JSON_UNESCAPED_SLASHES), "\n";
            echo "Total: {$total}\n";
            exit(1);
        }
        echo "Verified clean after deletion.\n";
    }

    echo $total === 0 ? "No leftovers.\n" : "Total: {$total}\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    echo "The diagnostic could not complete; the database state is unknown, not clean.\n";
    exit(2);
}
