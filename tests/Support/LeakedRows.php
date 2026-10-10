<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Support;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use RuntimeException;

/**
 * The one definition of "a row a test harness owns", shared by everything that writes rows into the
 * shared MariaDB and everything that cleans them up.
 *
 * ## Why this file exists
 *
 * `roundtrip-runner.php` swept by surrogate key and by fingerprint; `roundtrip-leftovers.php` counted
 * leftovers with a *separately written* list of the same fingerprints. Two lists of the same thing is
 * two things that can disagree, and they did: the diagnostic had no `pettycash_sheets` predicate at
 * all, so for the whole time the runner was leaking seven sheets into a database that also held a
 * currency-sweep bug, it reported `No leftovers` while `GET /reports/transactions` answered 422. T310
 * patched both lists by hand and the patch is invisible -- nothing tests that the two agree, and the
 * next predicate added to one of them will not be added to the other.
 *
 * So the definition lives here, once, and both callers consume it. A predicate that exists in exactly
 * one place cannot be missing from the other by accident.
 *
 * ## Why it is a class and not a list
 *
 * A flat list of `[table, column, operator, value]` cannot express the two steps that matter most.
 *
 * The first is **ordering**: sheets must be swept before the currencies they reference, or the sweep
 * re-creates the dangling `sheets.currency_id` that made three whole reports return no data. A list
 * that anyone can reorder is a list that will be reordered.
 *
 * The second is that **deleting a parent leaves a subtree**. Deleting a sheet without its transactions
 * leaves `pettycash_transactions.sheet_id` pointing at nothing; those rows are invisible to every
 * sheet-scoped read and to every simple predicate, and they break the currency join in
 * `DbTransactionRevisionRepository::resolveCurrencyCode()` -- which is to say a leak of the parent
 * turns three reports off. A predicate over one table cannot describe that, so a step may declare the
 * tables it deletes and the dispatcher does the cascade.
 *
 * ## `sweeps` is load-bearing, not documentation
 *
 * Every step declares the tables it removes. `sweptTables()` is the union, and
 * `RepositoryRoundTripTest` asserts that **every table the runner registers with `$track()` appears in
 * that union**. That is what stops the class of defect rather than one instance of it: a table the
 * runner writes but no step reaches is a permanent leak, and before this file existed there were six
 * (`pettycash_accounts`, `pettycash_role_assignments`, `pettycash_transactions`,
 * `pettycash_transaction_revisions`, and both audit tables for the expense scenario) that only the
 * by-id sweep could remove -- which is exactly the sweep that fails when the run dies between an
 * insert and its registration.
 */
final class LeakedRows
{
    /**
     * The title of the sheet the corrupt-record fixture writes, and the title of the sheet the round
     * trip writes. Both are named in this file rather than in either caller, because the sweep has to
     * recognise them and the code that creates them has to agree, and two separately-typed spellings of
     * one fingerprint is the same divergence this file was written to remove.
     */
    public const ROUND_TRIP_SHEET_TITLE = 'Round Trip Sheet';

    public const CORRUPT_PROBE_SHEET_TITLE = 'Corrupt Record Probe Sheet';

    /**
     * Command ids this project mints for test harnesses all carry this prefix, and it is longer than the
     * 36-character `command_id` columns allow for anything resembling a real id, so the prefix is a
     * safe key. Every command-keyed table is swept by it.
     */
    private const COMMAND_PREFIX = 'rq-%';

    /** `name`/`title` values only these harnesses write. */
    private const NAME_PREFIX = 'Round Trip%';

    private const EXPENSE_TYPE_PREFIX = 'Round Trip Expense %';

    private const RETENTION_EXPLANATION = 'Retention probe %';

    private const EVIDENCE_KEY_PREFIX = 'roundtrip/%';

    /**
     * Tables that hang off a transaction, deepest first. Order is not cosmetic: a table must never be
     * left holding a key to a row that was just removed.
     *
     * @return list<string>
     */
    private const TRANSACTION_CHILDREN = [
        'pettycash_evidence',
        'pettycash_transaction_revisions',
        'pettycash_workflow_decisions',
        'pettycash_transaction_audit',
    ];

    /** @return list<array<string, mixed>> */
    public static function steps(): array
    {
        return [
            // --- rows keyed by something this harness mints, so one column is enough ---
            self::named('evidence', 'pettycash_evidence', 'appdata_key', 'like', self::EVIDENCE_KEY_PREFIX),
            self::named('evidence links', 'pettycash_evidence_links', 'token_hash', 'like', self::COMMAND_PREFIX),
            self::named('account movements', 'pettycash_account_movements', 'command_id', 'like', self::COMMAND_PREFIX),
            self::named('workflow decisions', 'pettycash_workflow_decisions', 'command_id', 'like', self::COMMAND_PREFIX),
            self::named('command results', 'pettycash_command_results', 'command_id', 'like', self::COMMAND_PREFIX),
            // The audit tables. Both streams are swept twice, once by the command id the round trip
            // mints and once by the retention probe's explanation, because the two scenarios write
            // their rows with different keys and one predicate per table would leave the other's rows
            // reachable only by the by-id sweep.
            self::named('transaction audit (command)', 'pettycash_transaction_audit', 'command_id', 'like', self::COMMAND_PREFIX),
            self::named('transaction audit (retention)', 'pettycash_transaction_audit', 'explanation', 'like', self::RETENTION_EXPLANATION),
            self::named('configuration audit (command)', 'pettycash_configuration_audit', 'command_id', 'like', self::COMMAND_PREFIX),
            self::named('configuration audit (retention)', 'pettycash_configuration_audit', 'explanation', 'like', self::RETENTION_EXPLANATION),

            // --- subtrees, which are what a one-column predicate cannot express ---
            // Sheets BEFORE the currencies they reference. Deleting the currency first would re-create
            // the dangling reference this sweep exists to remove, so the order is asserted by a test
            // rather than left to a future reader's judgement.
            self::sheetSubtree('round trip sheets', self::ROUND_TRIP_SHEET_TITLE),
            self::sheetSubtree('corrupt probe sheets', self::CORRUPT_PROBE_SHEET_TITLE),
            // The net for a run that died after its sheet was already swept: by then the offending
            // sheet id is unknowable, so the orphans have to be found from their own left join. Keyed
            // off the orphan rather than off the sheet, because a sheet-keyed predicate matches none
            // of them -- which is why the earlier sheet-keyed version of this step found 0 of 28.
            self::orphans(),
            // Children of a fingerprint parent. A round-trip account and a round-trip role assignment
            // carry no name of their own -- `pettycash_accounts` is (user, currency) and a role
            // assignment is (user, role, cost centre) -- so the only stable handle is the parent this
            // harness also wrote. Both must run before their parent is swept, or the handle is gone.
            self::childrenOf(
                'round trip accounts',
                'pettycash_currencies',
                'name',
                'like',
                self::NAME_PREFIX,
                'pettycash_accounts',
                'currency_id'
            ),
            self::childrenOf(
                'round trip role assignments',
                'pettycash_cost_centers',
                'name',
                'like',
                self::NAME_PREFIX,
                'pettycash_role_assignments',
                'cost_center_id'
            ),

            // --- the parents, last ---
            self::named('currencies', 'pettycash_currencies', 'name', 'like', self::NAME_PREFIX),
            self::named('cost centers', 'pettycash_cost_centers', 'name', 'like', self::NAME_PREFIX),
            self::named('expense types', 'pettycash_expense_types', 'title', 'like', self::EXPENSE_TYPE_PREFIX),
        ];
    }

    /** @return list<string> every table at least one step removes rows from. */
    public static function sweptTables(): array
    {
        $tables = [];
        foreach (self::steps() as $step) {
            foreach ($step['sweeps'] as $table) {
                $tables[$table] = true;
            }
        }
        $swept = array_keys($tables);
        sort($swept);

        return $swept;
    }

    /**
     * Run every step, deepest dependency first.
     *
     * @return array<string, int> rows removed, keyed by step name
     */
    public static function sweep(IDBConnection $db): array
    {
        $removed = [];
        foreach (self::steps() as $step) {
            $removed[$step['name']] = self::sweepOne($db, $step);
        }

        return $removed;
    }

    /**
     * Run one step.
     *
     * Public because `roundtrip-leftovers.php --delete` removes a step at a time and re-checks between
     * them, and because a diagnostic that reimplemented the dispatch would be a second definition of
     * what a step does -- which is the failure this class was written to end.
     *
     * @param array<string, mixed> $step
     * @return int rows removed
     */
    public static function sweepOne(IDBConnection $db, array $step): int
    {
        return match ($step['kind']) {
            'named' => self::deleteNamed($db, $step),
            'sheet_subtree' => self::deleteSheetSubtree($db, (string) $step['value']),
            'orphans' => self::deleteTransactionSubtree($db, self::probeOrphanedTransactionIds($db)),
            'children_of' => self::deleteChildrenOf($db, $step),
        };
    }

    /**
     * How many rows one step would still remove.
     *
     * For `sheet_subtree` and `children_of` this counts the **whole subtree**, not the roots, so it
     * agrees with what `sweepOne()` reports having removed. Counting only the roots would report `1` for
     * a probe that owns a sheet, a transaction and a revision, and the two numbers would then be
     * describing different things while sitting next to each other in the same report.
     *
     * For `children_of` the count is the children rather than the parents, because the parent is
     * already reported by the parent's own `named` step; counting it again here would keep reporting the
     * parents long after the children -- the rows that actually leak -- were gone.
     *
     * @param array<string, mixed> $step
     */
    public static function probeOne(IDBConnection $db, array $step): int
    {
        return match ($step['kind']) {
            'named' => self::countNamed($db, $step),
            'sheet_subtree' => self::probeSheetSubtreeRows($db, (string) $step['value']),
            'orphans' => count(self::probeOrphanedTransactionIds($db)),
            'children_of' => self::countChildrenOf($db, $step),
        };
    }

    /**
     * Every row a titled sheet owns, counted across the whole subtree.
     */
    private static function probeSheetSubtreeRows(IDBConnection $db, string $title): int
    {
        $sheetIds = self::idsMatching(
            $db,
            'pettycash_sheets',
            static fn (IQueryBuilder $qb): string => $qb->expr()->eq('title', $qb->createNamedParameter($title))
        );
        if ($sheetIds === []) {
            return 0;
        }

        $transactionIds = self::idsMatching(
            $db,
            'pettycash_transactions',
            static fn (IQueryBuilder $qb): string => $qb->expr()->in(
                'sheet_id',
                $qb->createNamedParameter($sheetIds, IQueryBuilder::PARAM_INT_ARRAY)
            )
        );

        $rows = count($sheetIds) + count($transactionIds);
        foreach (self::TRANSACTION_CHILDREN as $table) {
            if ($transactionIds === []) {
                break;
            }
            $rows += self::countWhereIn($db, $table, 'transaction_id', $transactionIds);
        }

        return $rows;
    }

    /**
     * A transaction whose `sheet_id` is set but whose sheet is gone.
     *
     * Public because a diagnostic has to be able to ask the same question the sweep answers. A
     * diagnostic that asked it differently is how `No leftovers` came to be printed over 112 broken
     * rows.
     *
     * @return list<int>
     */
    public static function probeOrphanedTransactionIds(IDBConnection $db): array
    {
        $qb = $db->getQueryBuilder();
        $qb->select('t.id')
            ->from('pettycash_transactions', 't')
            ->leftJoin('t', 'pettycash_sheets', 's', 't.sheet_id = s.id')
            ->where($qb->expr()->isNotNull('t.sheet_id'))
            ->andWhere($qb->expr()->isNull('s.id'));

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $qb->executeQuery()->fetchAll()
        );
    }

    /**
     * The parent rows a `children_of` step reaches through.
     *
     * @param array<string, mixed> $step
     * @return list<int>
     */
    public static function probeParentIds(IDBConnection $db, array $step): array
    {
        return self::idsMatching(
            $db,
            (string) $step['parent_table'],
            self::comparator(
                (string) $step['parent_column'],
                (string) $step['parent_operator'],
                (string) $step['parent_value']
            )
        );
    }

    /**
     * Fail loudly when a sweep left rows behind.
     *
     * A sweep that runs and is not checked is a sweep that can quietly stop working, and the failure it
     * prevents is not visible where it happens: it surfaces in some later run's report, which answers 422
     * because a record cannot be read, and the search starts in the report. That is the whole of T308's
     * investigation -- three endpoints returning no data, and the cause in a test harness a dozen files
     * away.
     *
     * ## Why this is a function and not a string in the test
     *
     * Asserting that a caller's source *contains* the words `leftovers` proves the words are there. It
     * does not prove the branch is reachable, and the first version of that assertion was satisfied by an
     * `if (false)` guard with the whole body still in place -- found by mutating it and watching the
     * suite stay green, which is the only way this class of gap is ever found. A function that throws is
     * testable by calling it, so this is one.
     *
     * @param array<string, int> $leftovers as returned by {@see self::leftovers()}
     * @throws RuntimeException when anything survived
     */
    public static function assertNothingSurvived(array $leftovers, string $context): void
    {
        if ($leftovers === []) {
            return;
        }

        $summary = [];
        foreach ($leftovers as $what => $count) {
            $summary[] = "{$what}: {$count}";
        }

        throw new RuntimeException(
            "{$context} left rows in the shared database that its own sweep could not remove -- "
            . implode('; ', $summary)
            . '. A row the sweep cannot reach is a permanent leak, and a dangling `sheets.currency_id` '
            . 'refuses three whole reports. Add a step to tests/Support/LeakedRows.php, or run '
            . 'scripts/dev/in-container php tests/Integration/Persistence/roundtrip-leftovers.php --delete.'
        );
    }

    /**
     * Count what a sweep would still find, by step.
     *
     * @return array<string, int> non-zero counts only, keyed by step name
     */
    public static function leftovers(IDBConnection $db): array
    {
        $left = [];
        foreach (self::steps() as $step) {
            $count = self::probeOne($db, $step);
            if ($count > 0) {
                $left[$step['name']] = $count;
            }
        }

        return $left;
    }

    /** @return array{string, string, string, 'eq'|'like', string, list<string>} */
    private static function named(
        string $name,
        string $table,
        string $column,
        string $operator,
        string $value
    ): array {
        return [
            'name' => $name,
            'kind' => 'named',
            'table' => $table,
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'sweeps' => [$table],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sheetSubtree(string $name, string $title): array
    {
        return [
            'name' => $name,
            'kind' => 'sheet_subtree',
            'value' => $title,
            // `pettycash_sheets` is last in this list because it is deleted last; `sweeps` is a set, so
            // the ordering that matters is the order of the deletes below, not the order here.
            'sweeps' => array_merge(self::TRANSACTION_CHILDREN, ['pettycash_transactions', 'pettycash_sheets']),
        ];
    }

    /** @return array<string, mixed> */
    private static function orphans(): array
    {
        return [
            'name' => 'orphaned transactions',
            'kind' => 'orphans',
            'sweeps' => array_merge(self::TRANSACTION_CHILDREN, ['pettycash_transactions']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function childrenOf(
        string $name,
        string $parentTable,
        string $parentColumn,
        string $parentOperator,
        string $parentValue,
        string $childTable,
        string $childColumn
    ): array {
        return [
            'name' => $name,
            'kind' => 'children_of',
            'parent_table' => $parentTable,
            'parent_column' => $parentColumn,
            'parent_operator' => $parentOperator,
            'parent_value' => $parentValue,
            'child_table' => $childTable,
            'child_column' => $childColumn,
            'sweeps' => [$childTable],
        ];
    }

    /**
     * Delete a sheet and everything hanging off it.
     *
     * @return int rows removed
     */
    private static function deleteSheetSubtree(IDBConnection $db, string $title): int
    {
        $sheetIds = self::idsMatching($db, 'pettycash_sheets', static fn (IQueryBuilder $qb): string
            => $qb->expr()->eq('title', $qb->createNamedParameter($title)));
        if ($sheetIds === []) {
            return 0;
        }

        $transactionIds = self::idsMatching($db, 'pettycash_transactions', static fn (IQueryBuilder $qb): string
            => $qb->expr()->in('sheet_id', $qb->createNamedParameter($sheetIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $removed = self::deleteTransactionSubtree($db, $transactionIds);

        return $removed + self::deleteFrom($db, 'pettycash_sheets', static fn (IQueryBuilder $qb): string
            => $qb->expr()->in('id', $qb->createNamedParameter($sheetIds, IQueryBuilder::PARAM_INT_ARRAY)));
    }

    /**
     * @param list<int> $transactionIds
     * @return int rows removed
     */
    private static function deleteTransactionSubtree(IDBConnection $db, array $transactionIds): int
    {
        if ($transactionIds === []) {
            return 0;
        }

        $removed = 0;
        foreach (self::TRANSACTION_CHILDREN as $table) {
            $removed += self::deleteFrom($db, $table, static fn (IQueryBuilder $qb): string
                => $qb->expr()->in('transaction_id', $qb->createNamedParameter($transactionIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        return $removed + self::deleteFrom($db, 'pettycash_transactions', static fn (IQueryBuilder $qb): string
            => $qb->expr()->in('id', $qb->createNamedParameter($transactionIds, IQueryBuilder::PARAM_INT_ARRAY)));
    }


    /**
     * @param array<string, mixed> $step
     * @return callable(IQueryBuilder): string
     */
    private static function comparator(string $column, string $operator, string $value): callable
    {
        return static function (IQueryBuilder $qb) use ($column, $operator, $value): string {
            $parameter = $qb->createNamedParameter($value);

            return $operator === 'like'
                ? $qb->expr()->like($column, $parameter)
                : $qb->expr()->eq($column, $parameter);
        };
    }

    /**
     * The `id`s of every row matching a condition.
     *
     * The condition is a closure over the *statement's own* query builder rather than a prebuilt
     * string, because a named parameter created by a different builder instance is never bound and is
     * emitted verbatim -- producing SQL like `... near ':dcValue1 LIMIT 1'`.
     *
     * @param callable(IQueryBuilder): string $condition
     * @return list<int>
     */
    private static function idsMatching(IDBConnection $db, string $table, callable $condition): array
    {
        $qb = $db->getQueryBuilder();
        $qb->select('id')->from($table)->where($condition($qb));

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $qb->executeQuery()->fetchAll()
        );
    }

    /**
     * @param callable(IQueryBuilder): string $condition
     */
    private static function countMatching(IDBConnection $db, string $table, callable $condition): int
    {
        $qb = $db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))->from($table)->where($condition($qb));

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * @param callable(IQueryBuilder): string $condition
     */
    private static function deleteFrom(IDBConnection $db, string $table, callable $condition): int
    {
        $qb = $db->getQueryBuilder();

        return $qb->delete($table)->where($condition($qb))->executeStatement();
    }

    /**
     * @param array<string, mixed> $step
     * @return int rows removed
     */
    private static function deleteNamed(IDBConnection $db, array $step): int
    {
        return self::deleteFrom(
            $db,
            (string) $step['table'],
            self::comparator((string) $step['column'], (string) $step['operator'], (string) $step['value'])
        );
    }

    /**
     * @param array<string, mixed> $step
     * @return int rows removed
     */
    private static function countNamed(IDBConnection $db, array $step): int
    {
        return self::countMatching(
            $db,
            (string) $step['table'],
            self::comparator((string) $step['column'], (string) $step['operator'], (string) $step['value'])
        );
    }

    /**
     * @param array<string, mixed> $step
     * @return int rows removed
     */
    private static function deleteChildrenOf(IDBConnection $db, array $step): int
    {
        return self::countChildrenOf($db, $step) === 0
            ? 0
            : self::deleteWhereIn($db, (string) $step['child_table'], (string) $step['child_column'], self::probeParentIds($db, $step));
    }

    /**
     * @param array<string, mixed> $step
     */
    private static function countChildrenOf(IDBConnection $db, array $step): int
    {
        $parentIds = self::probeParentIds($db, $step);
        if ($parentIds === []) {
            return 0;
        }

        return self::countWhereIn($db, (string) $step['child_table'], (string) $step['child_column'], $parentIds);
    }

    /**
     * @param list<int> $ids
     */
    private static function deleteWhereIn(IDBConnection $db, string $table, string $column, array $ids): int
    {
        return self::deleteFrom(
            $db,
            $table,
            static fn (IQueryBuilder $qb): string => $qb->expr()->in(
                $column,
                $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)
            )
        );
    }

    /**
     * @param list<int> $ids
     */
    private static function countWhereIn(IDBConnection $db, string $table, string $column, array $ids): int
    {
        return self::countMatching(
            $db,
            $table,
            static fn (IQueryBuilder $qb): string => $qb->expr()->in(
                $column,
                $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)
            )
        );
    }
}
