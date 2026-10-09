<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Persistence;

use PHPUnit\Framework\TestCase;

/**
 * T413: **every column a `Db*Repository` writes exists in the real schema.**
 *
 * ## The defect this exists to prevent
 *
 * `DbEvidenceLinkRepository::save()` set `updated_at` and `updated_by`. `pettycash_evidence_links` has
 * never had either column, so **`POST /evidence-links/{linkId}/deactivate` answered
 * `500 UNEXPECTED_FAILURE` on every real HTTP call** — with
 * `SQLSTATE[42S22] Unknown column 'updated_at' in 'SET'`. The endpoint was delivered, routed, and marked
 * complete.
 *
 * **Every gate this repository has passed it:**
 *
 * | Gate | Why it passed |
 * |---|---|
 * | `EvidenceLinkServiceTest` — 19 tests, green | Runs against an **in-memory repository**. There is no schema to contradict it, so writing a column that does not exist is indistinguishable from writing one that does. |
 * | `RouteResolutionTest` (T406) — green | Proves the route **resolves** to a controller action. It never proves the action *runs*; those are different claims and this defect lived in the gap between them. |
 * | `scripts/dev/phpunit`, `npm run check` — green | The suite had never issued the request. |
 * | `SchemaConstraintNamingTest` — 5 tests, green | Asserts tables, indexes, unique constraints, foreign keys and check constraints. **Not one test about columns** — precisely the hole this falls through. |
 *
 * That last row is the point. The schema was already under test against the live instance, one level above
 * the column, and the shape of that file said a column check belonged next to it.
 *
 * ## Why the columns come from `information_schema` and not from the migrations
 *
 * Tried first, and **wrong**: comparing repository writes against the migration *source* reported eleven
 * tables writing absent columns, including `public_id` on four tables that certainly have it. The cause was
 * a fixed character window after each `createTable`, which missed columns added by later migrations. A
 * guard that reports phantom columns is worse than no guard — it teaches whoever hits it to ignore it. So
 * this reads the live schema through `schema-report-fixture.php`, which is prefix-agnostic by construction
 * and knows nothing about the instance's `oc_`.
 *
 * ## Why comments are stripped, and why that is load-bearing here
 *
 * This repository documents its own shape at length, and column names appear in docblocks *describing* a
 * table. A scanner that reads prose reports the documentation of a column as a write of it — and in a
 * codebase that documents its negative requirements at length, FR-045's own prose sits in the docblocks of
 * the very classes being scanned.
 *
 * ## Scope, stated rather than implied
 *
 * **Writes only.** A repository *selecting* a column the schema lacks is also a `500`, but mapping a method
 * to the table it reads is far less mechanical than a `->insert()`/`->update()` on a named table, and the
 * write side is where this defect actually lived. So this does not claim read coverage, and a future
 * defect of the read-shaped kind would not be caught by it.
 *
 * **And one writer is NOT covered, by name, because naming it is the honest option.**
 * `DbAuditEventRepository::save()` writes `->insert($this->table())` — the table name is a **method call**,
 * resolving through a `STREAMS` map to either `pettycash_transaction_audit` or
 * `pettycash_configuration_audit` depending on the stream, and its column list comes from a `rowValues()`
 * helper rather than an inline `values([...])`. A regex keyed on a quoted table literal therefore cannot see
 * it, and this test does not check it. That is a real gap in the guard, not a hypothetical: the audit
 * stream is the one place where a column mistake is hardest to notice, because a write that silently fails
 * there loses history rather than answering a caller.
 *
 * Closing it needs a different technique — resolving `$this->table()` through the stream map and reading
 * `rowValues()`'s returned keys — which is called out in the task rather than quietly left, because a guard
 * that reads as complete and is not is worse than one that admits its boundary.
 */
final class RepositoryWriteColumnTest extends TestCase
{
    private const PERSISTENCE_ROOT = __DIR__ . '/../../../lib/Infrastructure/Persistence';

    /** @var array<string, mixed>|null */
    private static ?array $report = null;

    /**
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
        self::assertArrayHasKey(
            'columns',
            $decoded,
            'the fixture must report each table\'s columns; without them this test cannot assert anything.'
        );

        return self::$report = $decoded;
    }

    // ===== the assertions ==========================================================

    public function testEveryColumnAWriteTouchesExistsInTheLiveSchema(): void
    {
        $report = $this->report();
        $columns = $report['columns'];

        self::assertIsArray($columns, 'the report must carry a table => columns map');
        self::assertNotEmpty($columns, 'the report saw no columns, so the assertions below are vacuous');

        $offenders = [];

        foreach ($this->writtenColumns() as $file => $writes) {
            foreach ($writes as $table => $written) {
                if (!isset($columns[$table])) {
                    // A write to a table the schema has no record of. Reported rather than skipped: a
                    // repository naming a table that does not exist is the same defect one level up, and
                    // silently skipping would let it through here.
                    $offenders[] = sprintf('%s -> table `%s` (no such table in the live schema)', $file, $table);
                    continue;
                }

                /** @var list<string> $live */
                $live = $columns[$table];
                $missing = array_values(array_diff($written, $live, ['id']));

                if ($missing !== []) {
                    $offenders[] = sprintf(
                        '%s -> `%s`: %s (the table has %d columns)',
                        $file,
                        $table,
                        implode(', ', $missing),
                        count($live)
                    );
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "A write to a column the schema does not have is a runtime 500, not a compile error, and the\n"
            . "unit tests cannot see it: they run against in-memory repositories with no schema to\n"
            . "contradict them. That is how `updated_at` shipped and made every link revocation fail:\n  "
            . implode("\n  ", $offenders)
        );
    }

    public function testTheScanActuallyFindsWrites(): void
    {
        // **The guard against a vacuous guard.** If the extraction ever stops matching this repository's
        // query-builder style, the test above reports zero offenders and passes — which is precisely the
        // failure mode this file exists to end. So the number of statements and columns recovered is
        // itself asserted, and set above what the sweep measured so a silent regression cannot pass.
        $writes = $this->writtenColumns();

        // Counted as **distinct file => table pairs with at least one column**, which is what
        // `writtenColumns()` actually returns: several repositories write the same table from more than one
        // method, and the assertion above compares a *deduplicated* column set, so counting raw
        // statements here would measure a different thing than the one being guarded. Measured on this
        // instance: **13 pairs and 137 columns**, and the thresholds below sit just
        // under both.
        $pairs = 0;
        $columns = 0;
        foreach ($writes as $perFile) {
            foreach ($perFile as $written) {
                $pairs++;
                $columns += count($written);
            }
        }

        self::assertGreaterThanOrEqual(
            12,
            $pairs,
            'the scan found almost no repository/table pairs. Either the repositories stopped using '
            . '->insert()/->update() or this file\'s extraction stopped matching them, and either way '
            . 'the assertion above is now vacuous.'
        );
        self::assertGreaterThanOrEqual(
            130,
            $columns,
            'the scan recovered too few columns to be meaningful; the extraction has probably stopped '
            . 'matching the `values([...])` form.'
        );
    }

    public function testTheScanDoesNotClaimToCoverTheDynamicallyNamedWriter(): void
    {
        // Stated as an assertion rather than only in the class docblock, because a limitation that lives
        // only in prose is one a future reader can miss. If someone teaches this scan to resolve
        // `$this->table()`, this fails and asks them to remove the claim rather than leaving both.
        $writes = $this->writtenColumns();

        self::assertArrayNotHasKey(
            'Audit/DbAuditEventRepository.php',
            $writes,
            'DbAuditEventRepository now appears in the scan. If the extraction was taught to resolve '
            . '`$this->table()` and `rowValues()`, remove this assertion and update the class docblock — '
            . 'the "one writer is not covered" note is now false.'
        );
    }

    public function testTheLiveSchemaItselfIsReportedPerTable(): void
    {
        $columns = $this->report()['columns'];

        // The named case, pinned. `pettycash_evidence_links` has 10 columns and has never had
        // `updated_at` — so if a future migration does add it, this test must notice rather than keep
        // asserting a shape from memory.
        self::assertArrayHasKey('pettycash_evidence_links', $columns);
        self::assertNotContains(
            'updated_at',
            $columns['pettycash_evidence_links'],
            'T413: this column is what made every revocation 500. If it now exists, the defect was fixed '
            . 'by migration rather than by removing the write — which is a different change and deserves '
            . 'to be noticed.'
        );
        self::assertContains(
            'deactivated_at',
            $columns['pettycash_evidence_links'],
            'the real column is `deactivated_at`; the removed `updated_at` duplicated what it records.'
        );
    }

    // ===== extraction ==============================================================

    /**
     * Columns written by each repository file, as `file => table => [columns]`.
     *
     * Parsed from source rather than by instrumenting the query builder, because the property under test
     * is about what the code *says* it writes, and a builder that records at runtime would only see the
     * statements a test happened to exercise — which is the same blind spot this file was written to
     * close.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function writtenColumns(): array
    {
        $result = [];

        foreach ($this->repositoryFiles() as $file) {
            $source = (string) file_get_contents($file);
            // Comments first. A docblock naming a column is documentation, not a write of it.
            $code = (string) preg_replace(['#/\*[\s\S]*?\*/#', '#//[^\n]*#'], '', $source);

            foreach ($this->writeStatements($code) as [$table, $body]) {
                $written = $this->columnsIn($body);

                if ($written === []) {
                    continue;
                }

                $result[$this->relative($file)][$table] = array_values(array_unique(array_merge(
                    $result[$this->relative($file)][$table] ?? [],
                    $written
                )));
            }
        }

        return $result;
    }

    /**
     * Each `->insert('table', ...)` / `->update('table', ...)` and the statement body it governs.
     *
     * The body ends at the query builder's terminal call, so a window that ran to the end of the method
     * would attribute one statement's columns to another.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function writeStatements(string $code): array
    {
        $statements = [];

        foreach (['insert', 'update'] as $verb) {
            if (preg_match_all(
                '/->' . $verb . '\(\s*[\'"](\w+)[\'"](.*?)->execute(?:Statement|Query)\s*\(/s',
                $code,
                $matches,
                PREG_SET_ORDER
            ) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $statements[] = [$match[1], $match[2]];
            }
        }

        return $statements;
    }

    /**
     * @return list<string>
     */
    private function columnsIn(string $body): array
    {
        $columns = [];

        // `->set('column', ...)` for an update.
        if (preg_match_all('/->set\(\s*[\'"](\w+)[\'"]/', $body, $sets) > 0) {
            $columns = array_merge($columns, $sets[1]);
        }

        // `'column' => $qb->createNamedParameter(...)` for a values() array. The query builder variable is
        // matched loosely (`$qb`, `$query`, …) rather than named, because a hardcoded variable name would
        // make this stop finding writes the day a method renames its local.
        //
        // Both results are read from `$matches[1]` — the capture group — rather than iterated directly.
        // An earlier version wrote `foreach (preg_match_all(...) ?: [] as $match)`, which iterates the
        // *count* preg_match_all returns: a positive integer is not iterable, so the loop body never ran
        // and the scan silently found **zero** writes. The guard test caught it on its first run, which
        // is the only reason it exists.
        if (preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*\$\w+->createNamedParameter/', $body, $values) > 0) {
            $columns = array_merge($columns, $values[1]);
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return list<string>
     */
    private function repositoryFiles(): array
    {
        $root = realpath(self::PERSISTENCE_ROOT);
        self::assertIsString($root, 'the persistence root must exist for this test to mean anything');

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        self::assertNotEmpty($files, 'no repositories found; the scan would be vacuous');

        return $files;
    }

    private function relative(string $file): string
    {
        $root = realpath(self::PERSISTENCE_ROOT) ?: self::PERSISTENCE_ROOT;

        return ltrim(str_replace($root, '', realpath($file) ?: $file), '/');
    }
}