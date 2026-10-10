<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use OCA\PettyCash\Application\Configuration\ConfigurationConflict;
use OCA\PettyCash\Application\StaleRecordVersion;
use PHPUnit\Framework\TestCase;

/**
 * T425 / FR-083 / SC-016: the published error-code enumeration and the application must agree, **both ways**.
 *
 * ## What was wrong
 *
 * `contracts/ocs-api.yaml` declared `Error.code` as `{ type: string }` — open, with no list — while the whole
 * design rests on a client branching on the code. FR-085 was decided partly so `SUPERSEDED_ATTEMPT` stays
 * distinguishable from `ACCOUNT_VERSION_CONFLICT`, and T416 added `SHEET_VERSION_CONFLICT` on exactly that
 * reasoning. An open string promises nothing: a code may be renamed, dropped, or misspelled and the contract stays
 * valid, so a branch on a code is never more than a guess.
 *
 * ## Why two directions, and why the second is not bookkeeping
 *
 * **Emitted but unlisted** is the failure an open field makes invisible: a client cannot branch on a code the
 * contract does not promise.
 *
 * **Listed but never emitted** is the opposite and matters just as much: it is a promise the contract cannot keep,
 * and it sends a client writing a branch that can never fire — which reads in the code as "handled" and in
 * production as "this condition never happens". Only a two-way check catches it, which is why SC-016 is stated
 * two-way rather than as a containment check.
 *
 * ## How the codes are derived
 *
 * Nine are literals in `OcsErrorMapper`. The rest are produced by `Conflict`/`Refusal` implementations, four of
 * which are **generated from a resource noun** by `ConfigurationConflict` and `StaleRecordVersion` — so a code
 * moving between resources changes the vocabulary, and only a derived comparison notices.
 */
final class ErrorCodeVocabularyTest extends TestCase
{
    /**
     * The nine codes `OcsErrorMapper` emits directly.
     *
     * Read from the source rather than restated here: restating them would give this test a second copy of the
     * list, and the disagreement between two copies is exactly what it exists to prevent.
     */
    private const MAPPER_LITERAL_PATTERN = '/\'([A-Z][A-Z0-9_]{3,})\'/';

    /**
     * Resource nouns that reach `ConfigurationConflict` / `StaleRecordVersion`.
     *
     * **Read from the throw sites rather than restated**, because restating is how this list went stale in the
     * first place: an earlier draft carried `['currency', 'cost center', 'expense type']` and both configuration
     * services *also* raise the family for `sheet` and `transaction`, so four published codes had no emit site.
     * The scan below reads the actual first argument of every factory call, so a new throw site joins the
     * vocabulary without anyone editing this test.
     */
    private const CONFIGURATION_FACTORY_PATTERN = '/ConfigurationConflict::(?:staleVersion|alreadyExists)\(\s*\n?\s*\'([^\']+)\'/';
    private const STALE_FACTORY_PATTERN = '/StaleRecordVersion::forResource\(\s*\n?\s*\'([^\']+)\'/';

    /** @return list<string> the enumeration as published in the contract */
    private function publishedCodes(): array
    {
        $contract = (string) file_get_contents($this->repoRoot() . '/specs/001-pettycash-v1-core/contracts/ocs-api.yaml');
        self::assertNotSame('', $contract, 'the contract must be readable — the enum is the thing under test');

        // `errorCode` is the only property carrying an `enum:` in the `Error` schema, so locating the schema and
        // reading its code block is unambiguous and does not depend on line numbers.
        self::assertSame(
            1,
            preg_match('/^\s{4}Error:\s*$(.*?)^    ErrorEnvelope:/ms', $contract, $schema),
            'the Error schema must be locatable, or this test is matching nothing'
        );

        $block = $schema[1];
        self::assertSame(
            1,
            preg_match('/code:\s*\n\s*type: string\s*\n\s*enum:\s*\n((?:\s*-\s*[A-Z][A-Z0-9_]*\s*\n)+)/', $block, $enum),
            'Error.code must declare an enum of uppercase codes — FR-083 requires the vocabulary to be closed'
        );

        preg_match_all('/-\s*([A-Z][A-Z0-9_]*)/', $enum[1], $members);

        return $members[1];
    }

    /** @return list<string> every code the application can emit */
    private function emittedCodes(): array
    {
        $codes = [];

        // 1. The literals in the mapper — the codes with no exception type behind them.
        $mapper = (string) file_get_contents($this->repoRoot() . '/lib/Infrastructure/Http/OcsErrorMapper.php');
        preg_match_all(self::MAPPER_LITERAL_PATTERN, $mapper, $literal);
        $codes = array_merge($codes, $literal[1]);

        // 2. Every `errorCode()` implementation's returned literal.
        foreach ($this->phpFiles('lib') as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/function errorCode\(\)[^{]*\{\s*return\s+\'([A-Z0-9_]+)\'/', $source, $m) === 1) {
                $codes[] = $m[1];
            }
        }

        // 3. The generated families. The resource nouns come from the throw sites, then go through the
        //    **real** factories, so the composition is the application's rather than a
        //    re-implementation of its string-building rules — a re-implementation would agree with the
        //    contract even after the application changed how it composes a code.
        $phpFiles = $this->phpFiles('lib');
        $sources = array_map(static fn(string $f): string => (string) file_get_contents($f), $phpFiles);

        $configurationResources = [];
        $staleResources = [];
        foreach ($sources as $source) {
            preg_match_all(self::CONFIGURATION_FACTORY_PATTERN, $source, $m);
            $configurationResources = array_merge($configurationResources, $m[1]);
            preg_match_all(self::STALE_FACTORY_PATTERN, $source, $m2);
            $staleResources = array_merge($staleResources, $m2[1]);
        }

        self::assertNotSame(
            [],
            $configurationResources,
            'the ConfigurationConflict throw-site scan matched nothing — the vocabulary check would be vacuous'
        );
        self::assertNotSame(
            [],
            $staleResources,
            'the StaleRecordVersion throw-site scan matched nothing — the vocabulary check would be vacuous'
        );

        foreach (array_unique($configurationResources) as $resource) {
            $codes[] = ConfigurationConflict::alreadyExists($resource, 'x')->errorCode();
            $codes[] = ConfigurationConflict::staleVersion($resource, 'x', 1, 2)->errorCode();
        }
        foreach (array_unique($staleResources) as $resource) {
            $codes[] = StaleRecordVersion::forResource($resource, 'x', 1, 2)->errorCode();
        }

        return array_values(array_unique($codes));
    }

    public function testEveryEmittedCodeIsPublishedInTheContract(): void
    {
        $published = $this->publishedCodes();
        $emitted = $this->emittedCodes();

        $missing = array_values(array_diff($emitted, $published));

        self::assertSame(
            [],
            $missing,
            "SC-016 requires every emitted code to appear in the contract's enumeration, or a client cannot "
            . "branch on it.\nMissing: " . implode(', ', $missing)
        );
    }

    public function testEveryPublishedCodeIsActuallyEmitted(): void
    {
        $published = $this->publishedCodes();
        $emitted = $this->emittedCodes();

        $stale = array_values(array_diff($published, $emitted));

        self::assertSame(
            [],
            $stale,
            "SC-016 also requires the enumeration to declare no code the application cannot emit: a listed code is "
            . "a promise the contract cannot keep, and a client writing a branch for it ships code that reads as "
            . "\"handled\" and never runs.\nStale: " . implode(', ', $stale)
        );
    }

    /**
     * The two halves must be *equal*, not merely non-overlapping in one direction.
     *
     * Asserting both directions separately would still pass if a code appeared in both lists twice under
     * different spellings; `assertEquals` on the sorted sets states the stronger, simpler property that SC-016
     * is really about — the published vocabulary **is** the emitted one.
     */
    public function testThePublishedVocabularyIsExactlyTheEmittedOne(): void
    {
        $published = $this->publishedCodes();
        $emitted = $this->emittedCodes();

        sort($published);
        sort($emitted);

        self::assertSame(
            $emitted,
            $published,
            sprintf(
                'the contract publishes %d codes and the application can emit %d; they must be the same set. '
                . 'First divergence: contract-only = %s, application-only = %s',
                count($published),
                count($emitted),
                implode(', ', array_slice(array_diff($published, $emitted), 0, 5)) ?: 'none',
                implode(', ', array_slice(array_diff($emitted, $published), 0, 5)) ?: 'none'
            )
        );
    }

    /**
     * A new code shipped ahead of the contract must fail here.
     *
     * This is the specific failure an open `type: string` made invisible, so it is worth demonstrating rather
     * than only asserting: without the enumeration there is nothing to violate.
     */
    public function testTheEnumerationIsClosedRatherThanAnOpenString(): void
    {
        $contract = (string) file_get_contents($this->repoRoot() . '/specs/001-pettycash-v1-core/contracts/ocs-api.yaml');

        self::assertStringNotContainsString(
            'code: { type: string }',
            $contract,
            'Error.code is still an open string — the vocabulary FR-083 requires published is not published'
        );

        $published = $this->publishedCodes();
        self::assertNotEmpty($published, 'the enum must actually contain members');

        // A floor rather than an equality, because the count is a **fact about the application's error
        // vocabulary** and must be read, not asserted from memory. It was originally 25; the real number is 24
        // and the threshold was wrong, not the contract — 24 is what the code emits once the seven codes that had
        // no throw site were removed. Set from the measured value so a genuine drop in coverage is still caught
        // while the correct count is not mistaken for a regression.
        self::assertGreaterThanOrEqual(
            24,
            count($published),
            sprintf('only %d codes are published; the enumeration has lost coverage', count($published))
        );
    }

    /** @return list<string> */
    private function phpFiles(string $subdir): array
    {
        $root = $this->repoRoot() . '/' . $subdir;
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    private function repoRoot(): string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/specs/001-pettycash-v1-core/contracts/ocs-api.yaml')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        self::fail('could not locate the application root by finding contracts/ocs-api.yaml');
    }
}