<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Audit;

use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Domain\Shared\ActorSnapshot;
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

        // `__construct` and `append` only. A second writer path is how a second divergent definition
        // of "what an audit event contains" starts, which is the same duplication class T289 was
        // written for on the account payload.
        self::assertSame(['__construct', 'append'], $methods);
    }

    public function testTheRemovedWriterPathsAreGone(): void
    {
        $reflection = new ReflectionClass(AuditWriterService::class);

        // Named explicitly as well as by list, so a future method called something else cannot pass
        // unnoticed and the failure message names what was removed and why.
        self::assertFalse(
            $reflection->hasMethod('appendTransaction'),
            'appendTransaction() was removed in T296: it had no callers and read $actor->role->value '
            . 'on a string, which would persist a null actor role contrary to FR-052. If a writer path '
            . 'is genuinely needed, add it to the AuditWriter port in the same change so the contract '
            . 'and the implementation cannot drift apart.'
        );
        self::assertFalse(
            $reflection->hasMethod('appendConfiguration'),
            'appendConfiguration() was removed in T296 for the same reason, and additionally because '
            . 'DbAuditEventRepository::save() targets pettycash_transaction_audit unconditionally, so it '
            . 'would have written a configuration event into the transaction stream contrary to FR-056. '
            . 'T294 owns the real configuration audit writer.'
        );
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
