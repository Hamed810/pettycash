<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Persistence;

use PHPUnit\Framework\TestCase;

/**
 * SC-014's constraint-naming clause, against the real schema. T392 / ADR-006.
 *
 * ## What was wrong before
 *
 * SC-014 requires that "100% of V1 indexes, unique constraints, **foreign keys**, and check constraints
 * carry `pettycash_*` names". Nothing tested any of it: `grep` for `information_schema`, `SHOW CREATE
 * TABLE` or `_fk` across `tests/` found nothing before this file. So the clause could not fail — not even
 * for the two kinds the schema *does* declare, let alone the two it declares none of.
 *
 * Measured on the shared instance when this was written: 15 tables, 23 unique constraints, 32 indexes, and
 * **zero** foreign keys and **zero** check constraints. The naming holds for everything that exists; the
 * other two kinds are empty, which is what ADR-006 records as a decision rather than an oversight.
 *
 * ## Why the empty kinds are asserted, not merely omitted
 *
 * Asserting only the naming convention would still pass with an unnamed foreign key present, because
 * "0 offenders out of 0 foreign keys" is true and "0 offenders out of 1" is also true. Each kind's
 * **count** is therefore asserted as well as its names, which makes ADR-006 falsifiable in both
 * directions: adding a constraint now fails this test until it is either named `pettycash_<table>_<subject>_fk`
 * or the ADR is amended.
 */
final class SchemaConstraintNamingTest extends TestCase
{
    /**
     * The 15 tables `data-model.md` defines.
     *
     * Compared against the **logical** names, with the instance prefix stripped by the fixture. The prefix
     * is `oc_` on this shared instance and `data-model.md:7-9` forbids hardcoding it, so nothing here
     * knows or asserts it.
     *
     * Asserting the exact set means a table that appears without a migration, or disappears with one, is
     * caught. A count alone would pass either change.
     *
     * @var list<string>
     */
    private const EXPECTED_TABLES = [
        'pettycash_account_movements',
        'pettycash_accounts',
        'pettycash_command_results',
        'pettycash_configuration_audit',
        'pettycash_cost_centers',
        'pettycash_currencies',
        'pettycash_evidence',
        'pettycash_evidence_links',
        'pettycash_expense_types',
        'pettycash_role_assignments',
        'pettycash_sheets',
        'pettycash_transaction_audit',
        'pettycash_transaction_revisions',
        'pettycash_transactions',
        'pettycash_workflow_decisions',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $report = null;

    /**
     * The live schema, read once per process.
     *
     * Through a fixture subprocess because `information_schema` and `SHOW INDEX` need a bootstrapped
     * Nextcloud, which the PHPUnit process does not provide — the same reason `RepositoryRoundTripTest`
     * and `ContractFixtures` shell out to `roundtrip-runner.php`.
     *
     * @return array<string, mixed>
     */
    private function report(): array
    {
        if (self::$report !== null) {
            return self::$report;
        }

        $fixture = dirname(__DIR__, 2) . '/Contract/Fixture/schema-report-fixture.php';
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>&1');
        $decoded = json_decode(trim((string) $output), true);

        self::assertIsArray(
            $decoded,
            "schema-report-fixture.php produced no report, so nothing below would be evidence:\n" . $output
        );
        self::assertArrayHasKey('tables', $decoded, 'the report must at least name the tables it saw');

        return self::$report = $decoded;
    }

    public function testEveryPettyCashTableIsPresentAndNoOthersAre(): void
    {
        $report = $this->report();

        // Already logical names: the fixture strips whatever instance prefix is configured, so this test
        // neither knows nor needs it — which is what `data-model.md:7-9` requires.
        $found = $report['tableNames'];
        sort($found);

        $expected = self::EXPECTED_TABLES;
        sort($expected);

        self::assertSame(
            $expected,
            $found,
            'SC-014 concerns a clean installation creating 100% of its tables; a table that appeared or '
                . 'vanished without a migration would change what the other assertions here are about'
        );
    }

    public function testEveryUniqueConstraintCarriesAPettyCashName(): void
    {
        $report = $this->report();

        self::assertNotEmpty(
            $report['uniqueConstraints'],
            'the fixture reported no unique constraints at all, which means it is not reading the schema '
                . 'this test thinks it is — an empty list must not be read as "no offenders"'
        );

        self::assertSame(
            [],
            $this->offenders($report['uniqueConstraints']),
            'SC-014: 100% of unique constraints must carry a `pettycash_*` name'
        );
    }

    public function testEveryIndexCarriesAPettyCashName(): void
    {
        $report = $this->report();

        self::assertNotEmpty(
            $report['indexes'],
            'the fixture reported no indexes at all, so it cannot be telling us anything about naming'
        );

        self::assertSame(
            [],
            $this->offenders($report['indexes']),
            'SC-014: 100% of indexes must carry a `pettycash_*` name'
        );
    }

    /**
     * ADR-006: V1 declares no foreign keys, and that is now falsifiable.
     *
     * **This is the assertion that makes the ADR worth having.** Without it, a future task could add an
     * unnamed foreign key and SC-014's clause would keep passing vacuously — the exact failure ADR-006
     * exists to end.
     *
     * Three of the existing guarantees depend on the absence, which is why it is recorded rather than
     * treated as an oversight: `roundtrip-runner.php`'s probe owner needs a uid that names no Nextcloud
     * user to be legal; `RepositoryRoundTripTest`'s leak post-condition needs deletes not to fail on
     * dependents; and ADR-005 needs a deleted user's rows to survive.
     */
    public function testNoForeignKeyIsDeclaredSoADR006StaysHonest(): void
    {
        $report = $this->report();

        self::assertSame(
            [],
            $report['foreignKeys'],
            'ADR-006 records that V1 declares no foreign key. If one has been added, name it '
                . '`pettycash_<table>_<subject>_fk` per data-model.md and amend ADR-006 — do not leave the '
                . 'absence undocumented, which is what SC-014\'s clause was silently permitting'
        );
    }

    /**
     * ADR-006: V1 declares no check constraints either.
     *
     * `data-model.md` identifies no invariant that needs database enforcement, and the ones that do exist
     * are enforced in the domain and verified through real-MariaDB round trips.
     */
    public function testNoCheckConstraintIsDeclaredSoADR006StaysHonest(): void
    {
        $report = $this->report();

        self::assertSame(
            [],
            $report['checkConstraints'],
            'ADR-006 records that V1 declares no check constraint. If one has been added, name it '
                . '`pettycash_<table>_<subject>_chk` per data-model.md and amend ADR-006'
        );
    }

    /**
     * The names that break the convention, with the table context a reader needs to fix them.
     *
     * Returning names rather than only a count: "3 offenders" sends a reader back to the database, while
     * `oc_pettycash_sheets.secret_idx` tells them the file to open.
     *
     * @return list<string>
     */
    private function offenders(array $names): array
    {
        return array_values(array_filter(
            $names,
            static fn (string $name): bool => !str_starts_with($name, 'pettycash_')
        ));
    }
}