<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Audit;

use OCA\PettyCash\Application\Audit\RetentionPolicyService;
use OCA\PettyCash\Domain\Audit\AuditRetentionPolicy;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * T138 / FR-057: an audit record younger than seven years is never archived or deleted.
 *
 * ## The claim being tested, stated precisely
 *
 * "Never archived or deleted" is enforced at the **application** boundary, not by the database.
 * MariaDB will execute a `DELETE` from `pettycash_transaction_audit` if something issues one. What
 * FR-053 and Constitution IV require is that no service, port, or repository in PettyCash exposes a
 * path that does it, and that the schema has nowhere to record that it was done. So this file has two
 * halves and both are needed:
 *
 * 1. **Real-database half** — two rows of deliberately different ages are written through the
 *    production repository, read back out of MariaDB, and evaluated against the real
 *    `AuditRetentionPolicy`. The young one must still be there and must still be protected; the ten-year
 *    one must come back archivable. Without the old row, a policy that answered "protected" for
 *    everything would pass.
 * 2. **Structural half** — reflection over the three types that could offer a removal path, asserting
 *    that none of them declares a method whose name is a removal or a rewrite. This is the part that
 *    actually holds the guarantee, and it fails the moment somebody adds `delete()` to the port.
 *
 * The structural half is not a substitute for the first run finding a real defect — the opposite. It
 * is the assertion that keeps the guarantee from quietly regressing while the two are both in place.
 *
 * ## How the database half reaches MariaDB
 *
 * Through `tests/Integration/Persistence/roundtrip-runner.php` in a subprocess, which bootstraps the
 * Nextcloud server. The PHPUnit process cannot, because `tests/bootstrap.php` wires a container of
 * in-memory doubles and loading `lib/base.php` would replace the global server. This is T236's
 * established pattern; the runner reports observations and this file asserts them, so a bug in the
 * runner cannot turn a broken policy green by agreeing with itself.
 */
final class RetentionPolicyTest extends TestCase
{
    /**
     * Method-name patterns that would constitute a removal or rewrite path for business audit.
     *
     * Matched against public method names only, case-insensitively, on the three types that could
     * plausibly offer one. `permitsDeletion()` is deliberately *not* matched: it is a question, not an
     * operation, and the value object needs to be able to ask and answer false.
     */
    private const REMOVAL_METHOD_PATTERN =
        '/^(delete|remove|purge|truncate|archive|erase|expunge|destroy|drop|overwrite|rewrite|mutate|update)/i';

    /**
     * Columns that would record a record having been archived or deleted. Their absence from the real
     * schema is the second half of "the application cannot quietly drop it" — if the guarantee were
     * ever softened into "we record it as archived", one of these would have to appear.
     */
    private const ARCHIVE_MARKER_COLUMNS = [
        'deleted_at',
        'archived_at',
        'purged_at',
        'removed_at',
        'expires_at',
        'retention_until',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $report = null;

    // ===== Real database: the young record is intact and protected =====

    public function testAYoungAuditRecordSurvivesInTheRealDatabase(): void
    {
        $young = $this->retention()['young'];

        self::assertTrue($young['survived'], 'a freshly written audit row must be readable back');
        self::assertSame(1, $young['rowsInTable'], 'and must be present exactly once');
    }

    public function testTheOccurrenceTimeReadBackIsTheOneWritten(): void
    {
        $young = $this->retention()['young'];

        self::assertNotNull($young['readOccurredAt']);
        self::assertSame(
            substr((string) $young['writtenOccurredAt'], 0, 10),
            substr((string) $young['readOccurredAt'], 0, 10)
        );
    }

    public function testAYoungAuditRecordIsProtectedFromArchival(): void
    {
        $young = $this->retention()['young'];

        self::assertTrue($young['protected'], 'a record inside the seven-year window must be protected');
        self::assertFalse($young['archivable']);
    }

    public function testConsultingThePolicyRemovesNothing(): void
    {
        $retention = $this->retention();

        // The row counts in the report are taken *after* the policy was evaluated. An evaluation that
        // archived its input as a side effect would show up here as a count of zero.
        self::assertSame(1, $retention['young']['rowsInTable']);
        self::assertSame(1, $retention['old']['rowsInTable']);
    }

    // ===== Real database: the boundary is a real comparison =====

    public function testARecordOlderThanSevenYearsIsArchivable(): void
    {
        $old = $this->retention()['old'];

        self::assertTrue($old['survived'], 'an old record must still be readable: archival is not deletion');
        self::assertTrue($old['archivable'], 'a ten-year-old record is past the window');
        self::assertFalse($old['protected']);
    }

    public function testTheYoungAndOldRecordsGetOppositeVerdicts(): void
    {
        $retention = $this->retention();

        // The control that makes the young-record assertions mean something. Without it, a policy
        // returning `protected` unconditionally would satisfy every test in the first section.
        self::assertNotSame(
            $retention['young']['protected'],
            $retention['old']['protected'],
            'the floor must discriminate by age, not answer one way for everything'
        );
    }

    public function testTheRealDatabaseRecordsAnAvailabilityDateForTheYoungRow(): void
    {
        $young = $this->retention()['young'];

        self::assertNotNull($young['availableUntil']);
        self::assertGreaterThan(
            (string) $young['readOccurredAt'],
            (string) $young['availableUntil'],
            'the record must remain available for years after it occurred'
        );
    }

    public function testThePolicyInForceHonorsTheFloorAndPermitsNoDeletion(): void
    {
        $policy = $this->retention()['policy'];

        self::assertSame(AuditRetentionPolicy::MINIMUM_RETENTION_YEARS, $policy['minimumRetentionYears']);
        self::assertSame(7, $policy['minimumRetentionYears'], 'FR-057 fixes the floor at seven years');
        self::assertTrue($policy['honorsMinimum']);
        self::assertFalse($policy['permitsDeletion']);
    }

    // ===== The real schema has nowhere to record an archival =====

    public function testTheAuditTablesHaveNoArchiveOrDeletionColumn(): void
    {
        $schema = $this->retention()['schema'];

        self::assertArrayHasKey('pettycash_transaction_audit', $schema);
        self::assertArrayHasKey('pettycash_configuration_audit', $schema);

        foreach ($schema as $table => $columns) {
            foreach (self::ARCHIVE_MARKER_COLUMNS as $marker) {
                self::assertNotContains(
                    $marker,
                    $columns,
                    "{$table} has a '{$marker}' column, so a record could be marked as archived or deleted"
                );
            }
        }
    }

    public function testBothAuditTablesRetainTheFullActorAndTimeContext(): void
    {
        $schema = $this->retention()['schema'];

        // FR-052 requires each event to keep actor identity, role, stage and time. A retention policy
        // that could trim a row down to a date would make the seven-year guarantee hollow, so the
        // columns the requirement depends on must be present in the real schema.
        foreach (['pettycash_transaction_audit', 'pettycash_configuration_audit'] as $table) {
            foreach (['actor_uid', 'actor_name', 'actor_role', 'occurred_at', 'event_type', 'explanation'] as $column) {
                self::assertContains($column, $schema[$table], "{$table} must retain '{$column}'");
            }
        }
    }

    // ===== The structural guarantee: no removal path exists =====

    /**
     * @dataProvider typesThatCouldRemoveBusinessAudit
     */
    public function testNoPublicMethodCanRemoveOrRewriteAnAuditRecord(string $class): void
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertDoesNotMatchRegularExpression(
                self::REMOVAL_METHOD_PATTERN,
                $method->getName(),
                sprintf(
                    '%s::%s() would be a removal or rewrite path for business audit. FR-053 and '
                    . 'Constitution IV make these records non-editable and non-deletable for every role, '
                    . 'including administrators, so no such method may exist at all.',
                    $class,
                    $method->getName()
                )
            );
        }
    }

    /** @return list<array{string}> */
    public static function typesThatCouldRemoveBusinessAudit(): array
    {
        return [
            [AuditEventRepository::class],
            [AuditRetentionPolicy::class],
            [RetentionPolicyService::class],
        ];
    }

    public function testTheAuditPortOffersOnlyReadsAndAnAppend(): void
    {
        $methods = array_map(
            static fn ($m): string => $m->getName(),
            (new ReflectionClass(AuditEventRepository::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        // Asserted as data rather than as a pattern so that adding a method to the port is a visible,
        // deliberate act in a diff instead of something only a regex would notice.
        self::assertSame([
            'findByActorUid',
            'findByDateRange',
            'findById',
            'findByPublicId',
            'findBySheetId',
            'findByTransactionId',
            'save',
        ], $methods);
    }

    public function testThePolicyObjectIsAdvisoryOnly(): void
    {
        $methods = array_map(
            static fn ($m): string => $m->getName(),
            (new ReflectionClass(AuditRetentionPolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        // It answers questions about a record's age. It performs nothing, which is what makes
        // "the policy may never rewrite business history" a property of the type rather than a
        // promise about its callers.
        self::assertSame([
            'availableUntil',
            'default',
            'honorsMinimum',
            'isArchivable',
            'isProtected',
            'of',
            'permitsDeletion',
            'retentionYears',
        ], $methods);
    }

    // ===== Runner plumbing =====

    private function retention(): array
    {
        if (self::$report !== null) {
            return self::$report;
        }

        $runner = __DIR__ . '/../Persistence/roundtrip-runner.php';
        if (!is_file($runner)) {
            throw new RuntimeException("The round-trip runner is missing at {$runner}.");
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner)
            . ' retention 2>/dev/null';
        $output = shell_exec($command);

        if ($output === null || trim($output) === '') {
            throw new RuntimeException(
                'The round-trip runner produced no output for the retention scenario. It exits with a '
                . 'message on stderr when it cannot reach the database.'
            );
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("The round-trip runner did not return JSON:\n" . $output);
        }
        if (isset($decoded['error'])) {
            throw new RuntimeException(
                'The retention round trip failed against the real database: ' . $decoded['error']
            );
        }

        return self::$report = $decoded;
    }
}
