<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Source-level guard for FR-078, FR-079, and SC-014.
 *
 * These assertions are deliberately structural rather than a substring search for one token: a
 * check that only looks for a single literal can never fail for an unrelated input, so it proves
 * nothing. Each test below enumerates the identifiers the migrations actually declare and
 * constrains all of them.
 */
final class V1SchemaMigrationTest extends TestCase
{
    /** Every V1 table the data model declares, in `pettycash_*` form. */
    private const EXPECTED_TABLES = [
        'pettycash_currencies',
        'pettycash_cost_centers',
        'pettycash_expense_types',
        'pettycash_role_assignments',
        'pettycash_accounts',
        'pettycash_account_movements',
        'pettycash_sheets',
        'pettycash_transactions',
        'pettycash_transaction_revisions',
        'pettycash_workflow_decisions',
        'pettycash_evidence',
        'pettycash_evidence_links',
        'pettycash_transaction_audit',
        'pettycash_configuration_audit',
        'pettycash_command_results',
    ];

    /** PostgreSQL truncates at 63 bytes, MySQL at 64 characters; stay under the smaller. */
    private const MAX_IDENTIFIER_LENGTH = 63;

    /** @return array<string, string> file path => source */
    private static function migrationSources(): array
    {
        $root = dirname(__DIR__, 3) . '/lib/Migration';
        $files = glob($root . '/Version*.php') ?: [];
        self::assertNotEmpty($files, 'no versioned migration files were found in lib/Migration');

        $sources = [];
        foreach ($files as $file) {
            $sources[$file] = (string) file_get_contents($file);
        }
        return $sources;
    }

    public function testMigrationsAreForwardOnlyAndNeverDropOrRename(): void
    {
        foreach (self::migrationSources() as $file => $source) {
            self::assertStringNotContainsString('dropTable', $source, $file . ' must not drop a table');
            self::assertStringNotContainsString('renameTable', $source, $file . ' must not rename a table');
            self::assertStringNotContainsString('renameIndex', $source, $file . ' must not rename an index');
        }
    }

    public function testMigrationsNeverReferencePrototypeOrLegacyTables(): void
    {
        foreach (self::migrationSources() as $file => $source) {
            // `oc_pcash_*` and `oc_pc_*` are the discarded prototype's tables in the shared
            // environment. FR-080 makes them unmanaged: V1 must not read, write, or drop them.
            self::assertStringNotContainsString('oc_pcash', $source, $file . ' references prototype tables');
            self::assertStringNotContainsString('oc_pc_', $source, $file . ' references prototype tables');
        }
    }

    public function testEveryDeclaredTableUsesTheAppScopedPrefix(): void
    {
        $declared = [];

        foreach (self::migrationSources() as $file => $source) {
            preg_match_all("/(?:createTable|hasTable|getTable)\('([a-z0-9_]+)'/", $source, $matches);
            foreach ($matches[1] as $table) {
                $declared[$table][] = $file;
            }
        }

        self::assertNotEmpty($declared, 'no table identifiers were found in the migrations');

        foreach ($declared as $table => $files) {
            self::assertStringStartsWith(
                'pettycash_',
                $table,
                sprintf('table "%s" declared in %s is not app-scoped (FR-078)', $table, implode(', ', array_unique($files))),
            );
        }
    }

    public function testBaselineCreatesExactlyTheDeclaredV1Tables(): void
    {
        $created = [];

        foreach (self::migrationSources() as $source) {
            preg_match_all("/createTable\('([a-z0-9_]+)'/", $source, $matches);
            foreach ($matches[1] as $table) {
                $created[$table] = true;
            }
        }

        $created = array_keys($created);
        sort($created);
        $expected = self::EXPECTED_TABLES;
        sort($expected);

        self::assertSame(
            $expected,
            $created,
            'the baseline migrations must create exactly the tables declared in data-model.md, and no others',
        );
    }

    public function testNoNonAppScopedTableOrIndexNameSurvives(): void
    {
        foreach (self::migrationSources() as $file => $source) {
            // A bare `pc_` identifier is the provisional development namespace that FR-078 removes
            // from the V1 baseline entirely.
            self::assertDoesNotMatchRegularExpression(
                "/'pc_[a-z0-9_]*'/",
                $source,
                $file . ' still declares a provisional pc_ identifier (FR-078)',
            );
        }
    }

    public function testEveryIndexAndConstraintIsExplicitlyNamed(): void
    {
        $calls = 0;

        foreach (self::migrationSources() as $file => $source) {
            // `addIndex($columns)` with no name lets the schema layer generate an identifier that
            // is not app-scoped, so a name argument is mandatory. A migration that declares no
            // index at all (for example a column widening) is not required to.
            preg_match_all('/add(?:Unique)?Index\((.*?)\);/', $source, $matches);
            $calls += count($matches[1]);

            foreach ($matches[1] as $arguments) {
                self::assertMatchesRegularExpression(
                    '/\[[^\]]*\],\s*\S/',
                    $arguments,
                    sprintf('%s declares an index without a name (FR-079): addIndex(%s)', $file, $arguments),
                );
            }
        }

        self::assertGreaterThan(0, $calls, 'no index declarations were found across the migrations');
    }

    public function testEveryDeclaredIndexNameIsAppScopedAndCorrectlyShaped(): void
    {
        $names = [];

        foreach (self::migrationSources() as $file => $source) {
            preg_match_all('/add(?:Unique)?Index\((.*?)\);/', $source, $matches);
            foreach ($matches[1] as $arguments) {
                preg_match_all("/'(pettycash_[a-z0-9_]+)'/", $arguments, $literals);
                foreach ($literals[1] as $name) {
                    $names[$name][] = $file;
                }
            }
        }

        self::assertNotEmpty($names, 'no index names were found in the migrations');

        foreach ($names as $name => $files) {
            self::assertMatchesRegularExpression(
                '/^pettycash_[a-z0-9_]+_(idx|uniq|fk|chk)$/',
                $name,
                sprintf('index "%s" in %s does not follow pettycash_<table>_<subject>_<kind> (FR-079)', $name, implode(', ', array_unique($files))),
            );
        }
    }

    public function testEveryIdentifierFitsThePortableBackendLimits(): void
    {
        $identifiers = [];

        foreach (self::migrationSources() as $source) {
            preg_match_all("/'(pettycash_[a-z0-9_]+)'/", $source, $matches);
            foreach ($matches[1] as $identifier) {
                $identifiers[$identifier] = true;
            }
        }

        self::assertNotEmpty($identifiers, 'no app-scoped identifiers were found in the migrations');

        foreach (array_keys($identifiers) as $identifier) {
            self::assertLessThanOrEqual(
                self::MAX_IDENTIFIER_LENGTH,
                strlen($identifier),
                sprintf('identifier "%s" is %d characters, which exceeds the portable limit of %d', $identifier, strlen($identifier), self::MAX_IDENTIFIER_LENGTH),
            );
        }
    }
}
