<?php

declare(strict_types=1);

/**
 * Reports the real schema's constraint names, so SC-014 can fail.
 *
 * ## Why this exists (T392 / ADR-006)
 *
 * SC-014 requires that "100% of V1 indexes, unique constraints, **foreign keys**, and check constraints
 * carry `pettycash_*` names". Before this file, `grep` for `information_schema`, `SHOW CREATE TABLE` or
 * `_fk` across `tests/` found nothing: **no test asserted any part of SC-014's naming clause.** For the two
 * kinds that exist it could have failed; for foreign keys and check constraints there is nothing to name,
 * so the clause passed vacuously and could never fail.
 *
 * That is the gap this closes, and it is closed by reporting the **counts as well as the names**:
 * asserting only the naming convention would still pass if a future task added an unnamed foreign key,
 * because "0 offenders out of 0 foreign keys" is technically true. `SchemaConstraintNamingTest` asserts
 * the counts too, which makes ADR-006 falsifiable in both directions.
 *
 * ## Why a subprocess
 *
 * `information_schema` and `SHOW CREATE TABLE` need a live connection, and the PHPUnit process does not
 * bootstrap the Nextcloud server — the same reason `RepositoryRoundTripTest` and `ContractFixtures` spawn
 * `roundtrip-runner.php`. Reached the same way, so there is no second route to the database.
 *
 * ## What it reads, and what it does not
 *
 * Read-only. It opens a PDO connection to the app's configured database and reports names. It creates,
 * alters and drops nothing, because this runs against the shared instance other applications use.
 *
 * The connection is taken from Nextcloud's own configuration rather than from an environment variable, so
 * it cannot report on a different database than the one the application actually uses — a report about the
 * wrong instance would satisfy every assertion here.
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

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, sprintf("schema-report-fixture failed: %s: %s\n", $e::class, $e->getMessage()));
    exit(1);
});

$connection = \OCP\Server::get(\OCP\IDBConnection::class);
$config = \OCP\Server::get(\OCP\IConfig::class);

/**
 * Connection details, read from Nextcloud's own configuration.
 *
 * `IConfig` rather than the connection object: `OC\DB\ConnectionAdapter` exposes no `getParams()`, and a
 * first draft that assumed it existed failed here rather than silently reporting an empty schema.
 *
 * `dbprefix` is deliberately **not** read, because it is `null` on an instance using the default and
 * `data-model.md:7-9` forbids hardcoding `oc_`. Instead the table query below matches on
 * `pettycash%` with no prefix at all, which is prefix-agnostic by construction.
 */
$database = (string) $config->getSystemValue('dbname', '');
$host = (string) $config->getSystemValue('dbhost', '');
$user = (string) $config->getSystemValue('dbuser', '');
$password = (string) $config->getSystemValue('dbpassword', '');
$dbType = (string) $config->getSystemValue('dbtype', 'mysql');

if ($database === '' || $host === '') {
    fwrite(STDERR, "Could not read the database name or host from Nextcloud's configuration.\n");
    exit(1);
}

if ($dbType !== 'mysql') {
    // Not a refusal to report: the names being checked are backend-neutral, and saying so is more useful
    // than a report this fixture cannot produce. `information_schema` and `SHOW INDEX` are both MySQL/MariaDB
    // spellings, and FR-077 treats a dependency on backend-specific syntax as a defect to rework rather
    // than a portability win.
    fwrite(STDERR, "This fixture reports on MySQL/MariaDB only; dbtype is '{$dbType}'.\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s', $host, $database),
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// The application's own tables, found **without** the instance prefix and without unwrapping the
// connection to ask for it.
//
// Two earlier attempts were both wrong and are worth recording. Matching `LIKE 'pettycash%'` reported
// `tables: 0`, because the real names carry the instance prefix. Reading it from
// `$connection->getInner()->getPrefix()` fixed the count and was then rejected by `check:portability`,
// correctly: `getInner()` unwraps the portable connection, and `data-model.md:7-9` forbids hardcoding
// `oc_`, which `IConfig`'s `dbprefix` would have forced since it is `null` on a default instance.
//
// So the prefix is not needed at all. The application owns the *suffix* of every table name and nothing
// else in this schema shares it, so matching `%pettycash%` and reporting each name from its first
// `pettycash` onwards is prefix-agnostic by construction -- it reports `pettycash_sheets` for
// `oc_pettycash_sheets`, `db_pettycash_sheets`, or no prefix whatsoever.
$candidates = $pdo->query(
    "SELECT table_name FROM information_schema.tables WHERE table_schema = "
    . $pdo->quote($database)
    . " AND table_name LIKE '%pettycash%' ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

$tableRows = [];
foreach ($candidates as $name) {
    $logical = strpos((string) $name, 'pettycash');

    if ($logical !== false) {
        $tableRows[] = substr((string) $name, $logical);
    }
}

$unique = [];
$foreignKeys = [];
$checkConstraints = [];
$indexes = [];

foreach ($candidates as $table) {
    // Foreign keys and check constraints come from the constraint catalog rather than from parsing DDL.
    // `information_schema.table_constraints` reports them per table and per name, and parsing
    // `SHOW CREATE TABLE` output would mean depending on MariaDB's exact formatting — which FR-077 names
    // as a portability defect.
    $constraints = $pdo->prepare(
        'SELECT constraint_name, constraint_type FROM information_schema.table_constraints'
        . ' WHERE table_schema = ? AND table_name = ?'
    );
    $constraints->execute([$database, $table]);

    foreach ($constraints->fetchAll(PDO::FETCH_ASSOC) as $constraint) {
        $name = (string) $constraint['constraint_name'];
        $type = strtoupper((string) $constraint['constraint_type']);

        // `PRIMARY` is excluded deliberately and it is not an oversight: MariaDB cannot name a primary
        // key, and SC-014's list says "indexes, unique constraints, foreign keys, and check constraints",
        // which does not include primary keys. Counting them would make the assertion unimplementable.
        if ($name === 'PRIMARY') {
            continue;
        }

        match ($type) {
            'UNIQUE' => $unique[] = $name,
            'FOREIGN KEY' => $foreignKeys[] = $name,
            'CHECK' => $checkConstraints[] = $name,
            default => null,
        };
    }

    // Plain indexes are absent from `table_constraints` (it carries only the four kinds above), so they
    // need `SHOW INDEX` — which is a per-table statement, hence the loop.
    foreach ($pdo->query(sprintf('SHOW INDEX FROM `%s`', str_replace('`', '', $table))) as $row) {
        $name = (string) $row['Key_name'];

        if ($name === 'PRIMARY') {
            continue;
        }

        $indexes[] = ['name' => $name, 'unique' => (int) $row['Non_unique'] === 0];
    }
}

/** Every constraint kind, deduplicated and sorted, so the report is stable between runs. */
$normalise = static function (array $names): array {
    $names = array_values(array_unique($names));
    sort($names);

    return $names;
};

echo json_encode([
    'database' => $database,
    'tables' => count($tableRows),
    'tableNames' => array_values($tableRows),
    'uniqueConstraints' => $normalise($unique),
    'indexes' => $normalise(array_column($indexes, 'name')),
    'foreignKeys' => $normalise($foreignKeys),
    'checkConstraints' => $normalise($checkConstraints),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";