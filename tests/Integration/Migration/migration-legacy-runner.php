<?php

declare(strict_types=1);

/**
 * T254: exercise the REAL migration path and prove the legacy tables survive it.
 *
 * Runs the app's pending forward-only migrations through Nextcloud's own
 * `OC\DB\MigrationService` (the same machinery `occ upgrade` and app enable use),
 * snapshotting every legacy `oc_pc_*` table before and after: row counts and full
 * column shapes must be byte-identical afterwards, and the new `command_results`
 * columns (T251) must exist. Emits one JSON document on stdout; the companion
 * `LegacyTablePreservationTest` asserts it.
 *
 * Run through `scripts/dev/phpunit` (in-container Nextcloud bootstrap required).
 */

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
if (!is_file($nextcloudRoot . '/lib/base.php')) {
    fwrite(STDERR, "Nextcloud not found at {$nextcloudRoot}.\n");
    exit(1);
}
require_once $nextcloudRoot . '/lib/base.php';

use OC\DB\Connection as DbalConnection;
use OC\DB\MigrationService;

function fail(string $message): never
{
    echo json_encode(['error' => $message], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

try {
    $dbal = \OC::$server->get(DbalConnection::class);
    // The table prefix is derived, not assumed: whatever the environment
    // configures, the migrations bookkeeping table ends in `migrations`.
    $prefix = 'oc_';
    foreach ($dbal->createSchemaManager()->listTableNames() as $candidate) {
        if (str_ends_with($candidate, 'migrations')) {
            $prefix = substr($candidate, 0, -strlen('migrations'));
            break;
        }
    }

    $schemaManager = $dbal->createSchemaManager();
    // The invariant is ownership, not a fixed legacy list: a forward app
    // migration may only touch its own `pettycash_*` tables. Everything else —
    // Nextcloud core tables and any legacy `oc_pc_*` tables where they exist —
    // must be byte-identical afterwards. (`oc_migrations` itself is excluded:
    // recording the applied version is the migrator's own bookkeeping.)
    $ownedTables = array_values(array_filter(
        $schemaManager->listTableNames(),
        static fn (string $t): bool => str_starts_with($t, $prefix . 'pettycash_')
    ));
    sort($ownedTables);
    $protectedTables = array_values(array_filter(
        $schemaManager->listTableNames(),
        static fn (string $t): bool => $t !== $prefix . 'migrations'
            && !str_starts_with($t, $prefix . 'pettycash_')
    ));
    sort($protectedTables);
    $legacyTables = array_values(array_filter(
        $protectedTables,
        static fn (string $t): bool => str_starts_with($t, $prefix . 'pc_')
    ));

    $snapshot = static function () use ($dbal, $schemaManager, $protectedTables): array {
        $out = [];
        foreach ($protectedTables as $table) {
            $count = (int) $dbal->fetchOne('SELECT COUNT(*) FROM ' . $table);
            $columns = [];
            foreach ($schemaManager->listTableColumns($table) as $name => $column) {
                $columns[$name] = [
                    'type' => $column->getType()->getName(),
                    'length' => $column->getLength(),
                    'notnull' => $column->getNotnull(),
                    'default' => $column->getDefault(),
                ];
            }
            ksort($columns);
            $out[$table] = ['rows' => $count, 'columns' => $columns];
        }
        return $out;
    };

    $before = $snapshot();

    $migrator = new MigrationService('pettycash', $dbal);
    $availableBefore = $migrator->getAvailableVersions();
    $migratedBefore = $migrator->getMigratedVersions();
    $migrator->migrate();
    $migratedAfter = (new MigrationService('pettycash', $dbal))->getMigratedVersions();
    $applied = array_values(array_diff($migratedAfter, $migratedBefore));

    $after = $snapshot();

    $commandResults = $prefix . 'pettycash_command_results';
    $resultColumns = array_keys($schemaManager->listTableColumns($commandResults));

    echo json_encode([
        'legacyTables' => $legacyTables,
        'protectedTableCount' => count($protectedTables),
        'protectedUnchanged' => $before === $after,
        'legacyUnchanged' => array_intersect_key($before, array_flip($legacyTables))
            === array_intersect_key($after, array_flip($legacyTables)),
        'appliedMigrations' => $applied,
        'availableMigrations' => $availableBefore,
        'commandResultsColumns' => $resultColumns,
    ], JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $e) {
    fail(get_class($e) . ': ' . $e->getMessage());
}
