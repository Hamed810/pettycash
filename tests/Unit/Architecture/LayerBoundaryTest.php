<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The layer boundary `plan.md` declares, asserted rather than described.
 *
 * ## Why this reads source instead of calling code
 *
 * A layering violation is a fact about *imports*, not about behaviour, so no amount of exercising the
 * code can detect it. The worst version of that is the one this file exists for: an interface in
 * `lib/Domain/` whose methods are all satisfied by an implementation that legitimately lives in
 * `lib/Infrastructure/`, and whose only effect is to let the innermost layer name a type from the
 * layer above it. Every test that exercises the repository passes. The boundary is broken and nothing
 * is red.
 *
 * That is not hypothetical — it happened, and T268 is the task that removes it. So the assertion is
 * structural, and it is structural over the whole directory rather than over one file, because the
 * value is in the *set*: "this is the only exception" is a claim that stops being true the moment a
 * second one appears, and nothing else in the suite would notice.
 */
final class LayerBoundaryTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/../../..';

    /**
     * The layers `plan.md` orders, innermost first.
     *
     * The order is the whole content of the rule. `Domain` may not reach outward at all; `Application`
     * may reach `Domain`; `Infrastructure` may reach both. Only the first of those is asserted here,
     * because it is the one with no mechanical excuse — a repository *has* to be implemented
     * somewhere, and the mistake is putting its port in the layer that the implementation must not be
     * able to see.
     */
    private const LAYER_ORDER = ['Domain', 'Application', 'Infrastructure', 'Controller'];

    public function testTheDomainLayerHasNoOutwardDependency(): void
    {
        // The table this test trusts, checked before it is trusted. Every comparison below is
        // `ranksAbove()`, which is `array_search` over `LAYER_ORDER` -- so a table that puts `Domain`
        // last would make the rule "nothing is above Domain", and the scan would pass over a real
        // violation while reporting nothing. The rule and the assertion are only as good as the order
        // they share, and the order is a piece of data in a test file like any other.
        $this->assertSame(
            'Domain',
            self::LAYER_ORDER[0],
            'LayerBoundaryTest::LAYER_ORDER must list Domain first. It is compared by position, so a '
            . 'reordered table inverts the rule this test exists to enforce and makes every assertion '
            . 'below vacuous.'
        );

        $violations = [];

        foreach ($this->phpFilesIn('lib/Domain') as $file) {
            $imports = $this->importsIn($file);
            foreach (array_keys($imports) as $imported) {
                $layer = $this->layerOf($imported);

                if ($layer !== null && $this->ranksAbove($layer, 'Domain')) {
                    $violations[] = sprintf(
                        '%s imports %s. `plan.md` declares Domain the innermost layer with no outward '
                        . 'dependency, and the rest of `lib/Domain/` contains no such import -- this is '
                        . 'the exception, not the pattern.',
                        $this->relative($file),
                        $imported
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "The Domain layer must not import from a layer above it:\n  " . implode("\n  ", $violations)
        );
    }

    /**
     * The boundary is not merely satisfied today; it is *watched*.
     *
     * A directory-level test passes silently forever if the scan finds nothing to look at -- an empty
     * glob, a wrong path, a renamed root. So the scan is asserted against a file that is known to be
     * there and known to import inward, which proves the scan is looking at the real tree.
     */
    public function testTheScanActuallySeesTheDomainLayer(): void
    {
        $files = $this->phpFilesIn('lib/Domain');

        $this->assertNotSame(
            [],
            $files,
            'The scan found no PHP files under lib/Domain, so the boundary test above would pass '
            . 'vacuously. A test that cannot fail is not a test.'
        );

        // `ExpenseSheetRepository` is a Domain port, so it imports Domain and nothing else. If this
        // ever fails, the scan has started seeing something other than the layer it is aimed at.
        $found = array_map($this->relative(...), $files);

        $this->assertContains(
            'lib/Domain/Expense/Repository/ExpenseSheetRepository.php',
            $found,
            'A known Domain file is missing from the scan, so the scan is not reading lib/Domain'
        );
    }

    /**
     * One port, not two.
     *
     * T268's subject, stated as a property rather than as a one-off deletion. Two interfaces with the
     * same short name, in two layers, with identical method sets, is a duplication that a reviewer has
     * to notice by reading both files; this makes it a failure instead. Asserting the *count* rather
     * than the absence of one particular path means a future duplicate in a third location is caught
     * too.
     */
    public function testCommandResultRepositoryIsDeclaredExactlyOnce(): void
    {
        $declarations = [];

        foreach ($this->phpFilesIn('lib') as $file) {
            if (preg_match('/^interface\s+CommandResultRepository\b/m', $this->contents($file)) === 1) {
                $declarations[] = $this->relative($file);
            }
        }

        $this->assertSame(
            ['lib/Application/Command/CommandResultRepository.php'],
            $declarations,
            'CommandResultRepository must be declared exactly once, in the application layer. Two '
            . 'declarations means two contracts to keep in step, and the one in the inner layer is the '
            . 'one that breaks the boundary.'
        );
    }

    /**
     * The surviving port is the one the application actually consumes.
     *
     * Asserted separately from the count because "declared once" and "declared in the right place" are
     * different claims, and a deletion that left the *domain* copy behind would satisfy the first.
     */
    #[DataProvider('commandResultPortConsumers')]
    public function testEveryConsumerUsesTheApplicationLayerPort(string $file, string $expectedImport): void
    {
        $imports = $this->importsIn(self::APP_ROOT . '/' . $file);

        $this->assertArrayHasKey(
            $expectedImport,
            $imports,
            "{$file} must import {$expectedImport}. Aliasing the port under another name is what made "
            . 'the duplicate invisible in the first place: both imports read "CommandResultRepository" '
            . 'in the `use` block, so only the namespace distinguished them.'
        );
    }

    /** @return list<array{0: string, 1: string}> */
    public static function commandResultPortConsumers(): array
    {
        return [
            [
                'lib/Infrastructure/Persistence/Command/DbCommandResultRepository.php',
                'OCA\PettyCash\Application\Command\CommandResultRepository',
            ],
        ];
    }

    // ===== the scan =====

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $relativeDir): array
    {
        // `realpath` on the root, so the paths the iterator yields are spelled the same way as the
        // ones `relative()` produces. Without it the two disagree by an unresolved `../../..` and the
        // sanity check below -- the one that exists to prove the scan is looking at real files --
        // fails against a perfectly good scan.
        $root = realpath(self::APP_ROOT . '/' . $relativeDir);
        if ($root === false || !is_dir($root)) {
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

    /**
     * Every fully-qualified `OCA\PettyCash\...` class this file imports, keyed by that class name.
     *
     * Keyed rather than listed so a test can assert on a specific import by name, and so the alias is
     * deliberately not recorded: `as` renames are exactly how two same-named ports stayed invisible.
     *
     * @return array<string, string>
     */
    private function importsIn(string $file): array
    {
        $imports = [];
        if (preg_match_all('/^use\s+(OCA\\\\PettyCash\\\\[A-Za-z0-9_\\\\]+)\s*(?:as\s+\w+\s*)?;/m', $this->contents($file), $matches) > 0) {
            foreach ($matches[1] as $imported) {
                $imports[$imported] = $imported;
            }
        }

        return $imports;
    }

    private function contents(string $file): string
    {
        $contents = is_file($file) ? file_get_contents($file) : false;

        return $contents === false ? '' : $contents;
    }

    /**
     * The layer a class name belongs to, or null when it is not one of ours.
     */
    private function layerOf(string $class): ?string
    {
        $parts = explode('\\', $class);
        // OCA \ PettyCash \ <Layer> \ ...
        $layer = $parts[2] ?? '';

        return in_array($layer, self::LAYER_ORDER, true) ? $layer : null;
    }

    /**
     * Whether `$layer` sits above `$inner` in the declared order.
     *
     * `array_search` rather than a hand-rolled comparison so the order is stated once, in
     * `LAYER_ORDER`, and cannot drift from the test that checks it.
     */
    private function ranksAbove(string $layer, string $inner): bool
    {
        $above = array_search($layer, self::LAYER_ORDER, true);
        $innerIndex = array_search($inner, self::LAYER_ORDER, true);

        return $above !== false && $innerIndex !== false && $above > $innerIndex;
    }

    /**
     * The path relative to the app root, for messages a reader can act on.
     *
     * `realpath` on both sides, because `__DIR__ . '/../../..'` and the paths a
     * `RecursiveDirectoryIterator` yields are the same files spelled differently. Without it every
     * message reads `tests/Unit/Architecture/../../../lib/Domain/...`, which is technically accurate
     * and practically useless — the point of the message is that a reader can open the file.
     */
    private function relative(string $path): string
    {
        $root = realpath(self::APP_ROOT) ?: self::APP_ROOT;
        $real = realpath($path) ?: $path;

        return ltrim(str_replace($root, '', $real), '/');
    }
}
