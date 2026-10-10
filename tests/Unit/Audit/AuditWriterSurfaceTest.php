<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Audit;

use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Domain\Shared\ActorSnapshot;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * T296: the audit writer has exactly one path, and it is the port's.
 *
 * ## What this guards
 *
 * `AuditWriterService` used to carry two extra public methods, `appendTransaction()` and
 * `appendConfiguration()`, that nothing called. Both did `$actor->role->value` on an `ActorSnapshot`
 * whose `$role` is typed `string` — so on PHP 8.5 each would emit
 * `Attempt to read property "value" on string` and persist a **null actor role**, where FR-052
 * requires the actor's role at the time of the event to be retained.
 *
 * They were deleted rather than repaired, which is the smaller change and the one the task named.
 * Deleting dead code is a fix, and Constitution V requires a fix to carry a regression test or a
 * repeatable regression procedure, so this file is that procedure. A behavioural test could not do
 * this job: nothing calls the removed methods, so no invocation can fail — the only thing that can
 * detect their return is the shape of the class.
 *
 * The second test is the one that would have caught the original defect. It asserts that
 * `ActorSnapshot::$role` is declared as `string` and not as the `Role` enum, because the bug was a
 * caller disagreeing with that declaration. If someone re-types the property to `Role`, the six live
 * construction sites that pass a string will break, and this test will have said so first.
 */
final class AuditWriterSurfaceTest extends TestCase
{
    public function testTheWriterDeclaresOnlyThePortMethod(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(AuditWriterService::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        // `__construct` plus exactly the port's paths. T296 deleted `appendConfiguration()` and
        // this assertion is why that was safe; T294 restored it *through the port*, so the rule this
        // test guards is unchanged: a writer path exists only because the port declares it, and a
        // second definition of "what an audit event contains" cannot start as a public method that
        // the container never sees. T399 added `appendConfigurationAsViewer()` the same way, for
        // FR-031's unauthenticated access event, which the session-resolving path cannot write.
        self::assertSame(
            ['__construct', 'append', 'appendConfiguration', 'appendConfigurationAsViewer'],
            $methods,
        );

        $portMethods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(AuditWriter::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        sort($portMethods);
        // `__construct` is a construction detail of the class, not a port member.
        $writerPaths = array_values(array_diff($methods, ['__construct']));
        self::assertSame(
            $portMethods,
            $writerPaths,
            'every public writer method must be declared on the port, so the container and the '
            . 'contract cannot drift apart'
        );
    }

    public function testTheRemovedWriterPathsAreGone(): void
    {
        $reflection = new ReflectionClass(AuditWriterService::class);

        // Named explicitly as well as by list, so a future method called something else cannot pass
        // unnoticed and the failure message names what was removed and why.
        self::assertFalse(
            $reflection->hasMethod('appendTransaction'),
            'appendTransaction() was removed in T296: it had no callers and read $actor->role->value '
            . 'on a string, which would persist a null actor role contrary to FR-052. T294 did not '
            . 'restore it — `append()` is the transaction path, and a second one would reintroduce '
            . 'exactly the divergence T289 was written for on the account payload.'
        );
    }

    /**
     * FR-056: the two streams are separate tables, and a configuration event written into the
     * transaction stream is not a formatting problem — it lands in a table whose subject columns
     * are sheet/transaction, where the configuration event has nothing to fill them with.
     *
     * This is the guarantee T296's assertion stood in for. It was removed because
     * `DbAuditEventRepository::save()` targeted `pettycash_transaction_audit` unconditionally, so
     * there was no correct way to add the method. T294 made the stream a constructor binding, which
     * is what makes the separation observable here: two repositories, and each path must reach
     * only its own.
     */
    public function testEachWriterPathReachesOnlyItsOwnStream(): void
    {
        $transaction = new InMemoryAuditEventRepository();
        $configuration = new InMemoryAuditEventRepository();

        $writer = new AuditWriterService(
            $transaction,
            new InMemoryCurrentUser('officer-1'),
            new InMemoryUserDirectory(),
            $configuration
        );

        $writer->append([
            'id' => '11111111-1111-4111-8111-111111111111',
            'sheet_id' => 7,
            'event_type' => 'DECISION',
        ]);
        $writer->appendConfiguration([
            'id' => '22222222-2222-4222-8222-222222222222',
            'entity_type' => 'account',
            'entity_id' => 'acc-1',
            'event_type' => 'ACCOUNT_CHARGED',
        ]);

        $inTransaction = $transaction->getEvents();
        $inConfiguration = $configuration->getEvents();

        self::assertCount(1, $inTransaction, 'the transaction event must reach the transaction stream');
        self::assertCount(1, $inConfiguration, 'the configuration event must reach the configuration stream');

        self::assertSame(
            'DECISION',
            $inTransaction[0]->eventType,
            'the transaction stream must not receive the configuration event'
        );
        self::assertSame(
            'ACCOUNT_CHARGED',
            $inConfiguration[0]->eventType,
            'the configuration stream must not receive the transaction event'
        );
        self::assertSame('account', $inConfiguration[0]->entityType, 'FR-055 addresses configuration events by entity_type');
        self::assertSame('acc-1', $inConfiguration[0]->entityId);
    }

    /**
     * A configuration event with no subject is refused rather than written.
     *
     * `entity_type` is the only thing that separates a configuration event from a transaction one
     * in this stream. Without it the row is indistinguishable from a transaction event whose
     * subject silently failed to bind, which is the state FR-055's charged-account derivation
     * cannot detect.
     */
    public function testAConfigurationEventWithoutASubjectIsRefused(): void
    {
        $configuration = new InMemoryAuditEventRepository();
        $writer = new AuditWriterService(
            new InMemoryAuditEventRepository(),
            new InMemoryCurrentUser('officer-1'),
            new InMemoryUserDirectory(),
            $configuration
        );

        $this->expectException(\InvalidArgumentException::class);
        try {
            $writer->appendConfiguration([
                'id' => '33333333-3333-4333-8333-333333333333',
                'event_type' => 'ACCOUNT_CHARGED',
            ]);
        } finally {
            self::assertCount(0, $configuration->getEvents(), 'a subjectless configuration event must not be written');
        }
    }

    public function testTheWriterImplementsThePortItIsRegisteredAgainst(): void
    {
        self::assertContains(
            AuditWriter::class,
            class_implements(AuditWriterService::class) ?: [],
            'the container registers AuditWriterService against the AuditWriter port, so the class '
            . 'must implement it or the registration resolves to something else'
        );
    }

    public function testActorSnapshotRoleIsAStringAndNotTheRoleEnum(): void
    {
        $property = (new ReflectionClass(ActorSnapshot::class))->getProperty('role');
        $type = $property->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('string', $type->getName());

        // This is the declaration the removed methods contradicted. Asserting it here means a caller
        // treating the role as an enum has a named place to be caught, rather than producing a null
        // actor role in an audit row that nothing downstream notices.
        self::assertNotSame(
            'OCA\\PettyCash\\Domain\\Shared\\Enum\\Role',
            $type->getName(),
            'ActorSnapshot::$role is a string. WorkflowDecision::actor() and the two workflow services '
            . 'build it from a string, and re-typing it to the Role enum would break them.'
        );
    }

    public function testNoProductionCodeReadsTheEnumValueOfAnActorSnapshotRole(): void
    {
        // The structural form of the same guarantee, over the tree rather than over one class. A
        // future `->role->value` on an ActorSnapshot is the exact defect T296 removed, and reading it
        // from a test is the only place that can see it before an audit row is written with a null
        // actor role.
        $offenders = [];
        $roots = [__DIR__ . '/../../../lib'];

        foreach ($roots as $root) {
            foreach ($this->phpFilesIn($root) as $file) {
                $source = (string) file_get_contents($file);
                // Only the ActorSnapshot shape: RoleAssignment::$role IS the Role enum, so
                // `$roleAssignment->role->value` is correct and must not be reported.
                if (preg_match('/\\$(actor|snapshot)->role->value/', $source) === 1) {
                    $offenders[] = str_replace(dirname($root, 2) . '/', '', $file);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These files read ->role->value off an ActorSnapshot, whose \$role is a string:\n  - "
            . implode("\n  - ", $offenders)
            . "\nOn PHP 8.5 this emits a warning and evaluates to null, so the audit row would record a "
            . 'null actor role where FR-052 requires the role at the time of the event.'
        );
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
