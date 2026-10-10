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
 * The live schema needs a bootstrapped Nextcloud server, and the PHPUnit process does not provide one —
 * the same reason `RepositoryRoundTripTest` and `ContractFixtures` spawn `roundtrip-runner.php`. Reached
 * the same way, so there is no second route to the database.
 *
 * ## What it reads, and what it does not
 *
 * Read-only. It opens the app's own connection and reports names. It creates, alters and drops nothing,
 * because this runs against the shared instance other applications use — and in CI, against the
 * ephemeral database service for the backend under test.
 *
 * The connection is taken from Nextcloud's own configuration rather than from an environment variable, so
 * it cannot report on a different database than the one the application actually uses — a report about the
 * wrong instance would satisfy every assertion here.
 *
 * ## T239: why it no longer speaks raw SQL
 *
 * This file used to open its own PDO connection and query `information_schema` plus `SHOW INDEX`, and it
 * **refused any backend other than `mysql`**. That refusal was not a portability virtue, it was the
 * portability gap: with it in place, three of the four backends T239 requires could not have reported a
 * schema at all, so SC-014's naming clause was verifiable only on the one engine this repository already
 * used for development.
 *
 * It now reads through `IDBConnection::createSchema()` — the portable abstraction that exists precisely
 * so schema inspection is not spelled per engine — and accepts all three declared backends. The one
 * question that abstraction cannot answer, check constraints, is reported as unobservable rather than
 * answered with a fabricated empty list; see the note where `checkConstraints` is emitted.
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
 * The database this report is about, read from Nextcloud's own configuration.
 *
 * `IConfig` rather than the connection object: `OC\DB\ConnectionAdapter` exposes no `getParams()`, and a
 * first draft that assumed it existed failed here rather than silently reporting an empty schema.
 *
 * `dbprefix` is deliberately **not** read, because it is `null` on an instance using the default and
 * `data-model.md:7-9` forbids hardcoding `oc_`. Instead the table walk below matches on `pettycash` with
 * no prefix at all, which is prefix-agnostic by construction.
 *
 * `dbhost`/`dbuser`/`dbpassword` were read here to build a PDO DSN and are no longer read at all: this
 * file no longer opens its own connection, which is what removed the raw SQL in the first place. Only
 * `dbname` and `dbtype` remain, because both are echoed into the report so a reader can tell which
 * instance answered. SQLite configures no host, so requiring one would have refused SQLite outright.
 */
$database = (string) $config->getSystemValue('dbname', '');
$dbType = (string) $config->getSystemValue('dbtype', 'mysql');

if ($database === '') {
    fwrite(STDERR, "Could not read the database name from Nextcloud's configuration.\n");
    exit(1);
}

// T239 / FR-077. The declared V1 backends are `mysql`, `pgsql` and `sqlite`, and this fixture used
// to refuse anything but `mysql` -- so the PostgreSQL and SQLite CI jobs could not have reported a
// schema at all, and SC-014's naming clause was unverifiable on two of the three declared backends.
//
// The refusal is gone because the report no longer needs raw SQL. `IDBConnection::createSchema()`
// is the portable abstraction Nextcloud builds on precisely so that schema inspection is not
// spelled per engine, and it answers every question this report asks:
// `getTables()`, `getColumns()`, `getIndexes()` (with `isUnique()`), and `getForeignKeys()`.
//
// Verified against the live instance before being relied on: `pettycash_sheets` reports 14 columns,
// 3 indexes (`pettycash_sheets_public_id_uniq` unique, `pettycash_sheets_owner_status_idx` not,
// `PRIMARY` unique) and 0 foreign keys -- which is what `SHOW INDEX` reported. `getDatabaseProvider()`
// confirms `mysql` on this instance, and it is what selects the platform below rather than
// inferring an engine from `$dbType`, so a `mysql`-typed MariaDB is not mistaken for MySQL.
// Nextcloud's *runtime* `dbtype` for SQLite is `sqlite3`, not the `sqlite` that `appinfo/info.xml`
// declares. Measured rather than assumed: a freshly installed SQLite instance writes
// `'dbtype' => 'sqlite3'` into `config/config.php`, and this file originally accepted only
// `['mysql','pgsql','sqlite']` — so it refused the very backend T239 adds a CI job for, and would
// have failed that job with a message naming a type the platform never emits. Both spellings are
// accepted because the config file and the app manifest genuinely disagree, and neither is wrong in
// its own context; the runtime spelling is what a report has to recognise.
if (!in_array($dbType, ['mysql', 'pgsql', 'sqlite', 'sqlite3'], true)) {
    fwrite(STDERR, "This fixture reports on the declared V1 backends only; dbtype is '{$dbType}'.\n");
    exit(1);
}

$provider = $connection->getDatabaseProvider();
$schema = $connection->createSchema();

/**
 * SC-014 lists "indexes, unique constraints, foreign keys, and check constraints" — and not primary
 * keys — so the primary key is excluded. It must be identified by WHAT IT IS, not by its name.
 *
 * This distinction was found by running the matrix rather than by reading it: on MySQL/MariaDB the
 * primary key reports the literal name `PRIMARY`, so a name comparison is sufficient there, and on
 * **PostgreSQL** it reports `<prefix>pettycash_accounts_pkey` — a real name the engine synthesises
 * per table. A name-based filter therefore flagged all 15 of them as unnamed offenders and failed
 * `testEveryUniqueConstraintCarriesAPettyCashName` and `testEveryIndexCarriesAPettyCashName` on a
 * schema that is in fact fully compliant.
 *
 * `Index::isPrimary()` is the portable predicate and answers the same question on every declared
 * backend, so it is what this filter uses. The name is deliberately never consulted: the exclusion is a
 * statement about the constraint's kind, and a name is a property of the engine that generated it.
 */
$isPrimaryName = static fn (string $name): bool => strtoupper($name) === 'PRIMARY';

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
// else in this schema shares it, so matching on `pettycash` and reporting each name from its first
// `pettycash` onwards is prefix-agnostic by construction -- it reports `pettycash_sheets` for
// `oc_pettycash_sheets`, `db_pettycash_sheets`, or no prefix whatsoever.
$tables = [];
foreach ($schema->getTables() as $table) {
    $real = $table->getName();
    $logical = strpos($real, 'pettycash');

    if ($logical !== false) {
        $tables[substr($real, $logical)] = $table;
    }
}
ksort($tables);

/**
 * T413: every column each table actually has.
 *
 * **Why this had to be reported here rather than parsed from migrations.** An earlier attempt compared
 * repository writes against the migration *source* and produced **eleven false positives** — it reported
 * `public_id` missing for four tables that have it — because it read a fixed character window after each
 * `createTable` and missed columns added by later migrations. A guard that reports phantom columns is worse
 * than no guard, because it teaches whoever hits it to ignore it. So the columns come from the live
 * schema, exactly as every other name in this report does.
 *
 * Keyed by the **logical** table name, prefix stripped, for the same reason `$tables` is: the instance
 * prefix is not known to this file and `data-model.md:7-9` forbids hardcoding it.
 */
$columns = [];
foreach ($tables as $logical => $table) {
    $names = array_keys($table->getColumns());
    sort($names);

    $columns[$logical] = $names;
}

$unique = [];
$foreignKeys = [];
$checkConstraints = [];
$indexes = [];

/** Every constraint kind, deduplicated and sorted, so the report is stable between runs. */
$normalise = static function (array $names): array {
    $names = array_values(array_unique($names));
    sort($names);

    return $names;
};

foreach ($tables as $logical => $table) {
    foreach ($table->getIndexes() as $index) {
        $name = (string) $index->getName();

        // Excluded by kind, not by name — see `$isPrimaryName` for why the literal comparison is not
        // sufficient. `isPrimary()` covers the engine-generated `<table>_pkey` form PostgreSQL reports
        // as well as the literal `PRIMARY` MySQL/MariaDB report.
        if ($index->isPrimary() || $isPrimaryName($name)) {
            continue;
        }

        $indexes[] = $name;

        // A unique index *is* a unique constraint on every declared backend, and the portable API
        // reports one object carrying `isUnique()` rather than two catalogs listing them separately —
        // which is exactly the MySQL/MariaDB conflation `information_schema.table_constraints` and
        // `SHOW INDEX` between, and one reason this no longer needs raw SQL.
        if ($index->isUnique()) {
            $unique[] = $name;
        }
    }

    foreach ($table->getForeignKeys() as $foreignKey) {
        $foreignKeys[] = (string) $foreignKey->getName();
    }
}

/**
 * ADR-006's check-constraint clause, reported per backend **rather than assumed**.
 *
 * Doctrine's `Table` — the portable abstraction this fixture now reads through — has no
 * check-constraint API at all. Measured, not assumed: reflecting over
 * `Doctrine\DBAL\Schema\Table` on this instance yields **zero** methods and **zero** properties
 * whose names contain "check", so `getCheckConstraints()` does not exist and cannot be called.
 *
 * That leaves three honest options and this file takes the third:
 *
 *  1. report `[]` unconditionally — which is what this file did while it was MySQL-only, and which
 *     makes ADR-006's clause **vacuous on SQLite and PostgreSQL**: "0 offenders out of 0 check
 *     constraints" is true whether the schema declares none or a hundred. `SchemaConstraintNamingTest`
 *     documents that exact vacuity as the failure mode ADR-006 exists to end, and reintroducing it on
 *     two backends would trade a MySQL-only truth for a cross-backend silence.
 *  2. read each backend's catalog directly — which is the vendor-specific SQL this file just stopped
 *     using, re-added under a portability requirement.
 *  3. **say which backends can answer the question.** A report that names the backends it could not
 *     observe is a fact a test can assert on; a report that claims an empty list is a claim.
 *
 * So `checkConstraintsBackends` names the engines whose catalogs this file can read for check
 * constraints — which is none, through the portable API — and `checkConstraints` is reported only
 * where the answer is real. `SchemaConstraintNamingTest` asserts against that distinction rather than
 * against a fabricated empty list.
 */
$checkConstraintObservable = static fn (string $provider): bool => in_array(
    $provider,
    // Doctrine's portable Table exposes none of these; listed explicitly so adding a driver that
    // does is a one-line change with a stated consequence, not a silent broadening of the clause.
    [],
    true
);

echo json_encode([
    'database' => $database,
    'dbType' => $dbType,
    // `getDatabaseProvider()` rather than `$dbType`: the Nextcloud type `mysql` covers both MySQL and
    // MariaDB, and this reports which concrete engine answered.
    'databaseProvider' => $provider,
    'tables' => count($tables),
    'tableNames' => array_keys($tables),
    'uniqueConstraints' => $normalise($unique),
    'indexes' => $normalise($indexes),
    'foreignKeys' => $normalise($foreignKeys),
    'checkConstraints' => $normalise($checkConstraints),
    // Whether `checkConstraints` above is an observation or an absence, per backend.
    'checkConstraintsObservable' => $checkConstraintObservable($provider),
    'checkConstraintsBackends' => [],
    // T413. Absent before this task, and its absence is why a delivered endpoint could ship a write to
    // `updated_at` — a column `pettycash_evidence_links` never had — and answer 500 on every real call.
    'columns' => $columns,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";