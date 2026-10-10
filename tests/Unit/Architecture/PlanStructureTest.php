<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * T238: every path `plan.md`'s Project Structure declares must exist.
 *
 * ## The defect this exists to stop recurring
 *
 * The plan declared `lib/Application/Dto/`, `lib/Application/Query/`, `src/formatters/` and
 * `tests/Frontend/Unit/`. None of them ever existed, and no task owned creating any of them, so
 * nothing was going to notice: the suite passes, the app runs, and the approved plan quietly
 * describes a module layout the repository does not have.
 *
 * That is not cosmetic. A reader who trusts the plan looks for a `Dto/` directory when deciding
 * where a payload shape belongs, finds nothing, and has no way to tell whether the plan is wrong or
 * their understanding is. Worse, the drift runs in the direction that costs a review: a later
 * author honours the plan by creating the directory, and now an empty declared-but-unused directory
 * is a *second* thing to reconcile. `AccountPayloadFormatter` documents exactly that hesitation —
 * it sits beside `AccountService` and says so, because moving it into a `Dto/` directory would
 * have quietly taken a piece of this task's work.
 *
 * ## Why this reads text rather than loading code
 *
 * A declared directory has no behaviour. There is nothing to call and nothing to assert about it
 * except whether the path resolves, so the honest assertion is the correspondence between the
 * plan's declared paths and the filesystem — a fact about two sets of strings. That is the same
 * shape as `LayerBoundaryTest` (an import scan) and `RouteDispatchCompletenessTest` (a path scan),
 * and it fails at build time naming the path, which is far cheaper than a reviewer discovering the
 * disagreement later.
 *
 * ## The allowlist, and why it is explicit rather than empty
 *
 * Three declared paths legitimately do not exist yet: `src/composables/`, which T121 creates when it
 * lands `useAsyncAction.ts`; `l10n/`, which T119 creates with the Persian catalogue; and
 * `tests/Integration/Storage/`, which T091 fills with `EvidenceLifecycleTest.php`. A test that
 * demanded those exist would be red for work that is correctly in progress, so they are listed
 * here **naming the task that owns them**. That makes the list self-clearing: whoever lands T091,
 * T119 or T121 removes the entry, and `testPendingAllowlistHasNoStaleEntry()` fails if one is
 * left behind — so an allowance cannot outlive the work that justified it. When all three land,
 * `PENDING` is empty and the allowlist goes away.
 *
 * A path added here without a task ID is the failure mode this file cannot prevent on its own —
 * which is why the message names the allowance rather than just reporting the path.
 */
final class PlanStructureTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/../../..';

    private const PLAN = 'specs/001-pettycash-v1-core/plan.md';

    /**
     * Declared paths that an open task owns, as `path => task ID`.
     *
     * Every entry is a promise that a named task will create the path. When that task lands, the
     * entry is deleted in the same change that creates the directory.
     */
    private const PENDING = [
        'l10n' => 'T119',
        'tests/Integration/Storage' => 'T091',
    ];

    /**
     * Declared paths that are generated build output rather than module layout.
     *
     * `js/` is produced by `npm run build`, so on a checkout that has not been built the directory
     * is absent — and that is not a defect in the plan. `testBuildOutputExists()` reports the
     * missing bundle separately, because "the entry point is not there" is a real deployment
     * failure that must not be silently allowed here.
     */
    private const GENERATED = [
        'js',
        'css',
    ];

    public function testEveryDeclaredPathExists(): void
    {
        $declared = $this->declaredPaths();

        $this->assertNotEmpty(
            $declared,
            'No paths parsed from the plan\'s Project Structure block, so this scan proves nothing. '
            . 'The block is delimited by a ```text fence under "### Source Code (repository root)"; '
            . 'if that heading was renamed, this test is now vacuous.'
        );

        $missing = [];
        foreach ($declared as $path) {
            if (isset(self::PENDING[$path]) || in_array($path, self::GENERATED, true)) {
                continue;
            }
            if (!file_exists(self::APP_ROOT . '/' . $path)) {
                $missing[] = $path;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Directories `plan.md` declares that do not exist.\nMissing:\n  "
            . implode("\n  ", $missing)
            . "\n\nThe plan is the approved description of the module layout. A declared path that "
            . "does not exist sends the next author looking for a directory that was never built, and "
            . "invites them to create it as a second, empty copy of the disagreement.\n\n"
            . "Either create the path with the content the plan assigns, or amend the plan's Project "
            . "Structure to match where the work actually lands — and if the path is owned by an open "
            . "task, record that task ID in self::PENDING rather than leaving it unexplained.\n\n"
            . 'Currently allowed as pending: '
            . (self::PENDING === [] ? '(none)' : implode(', ', array_map(
                static fn (string $p, string $t): string => $p . ' → ' . $t,
                array_keys(self::PENDING),
                self::PENDING,
            )))
        );
    }

    /**
     * The allowlist must not outlive the tasks that justify it.
     *
     * An entry that names a task which has already landed is an allowance for a path that now
     * exists, so `testEveryDeclaredPathExists()` silently stops checking it — the exact drift this
     * file exists to catch, reintroduced through its own escape hatch. Asserting the direction
     * closes that: an entry may only be there if the path is genuinely still absent.
     */
    public function testPendingAllowlistHasNoStaleEntry(): void
    {
        $stale = [];
        foreach (array_keys(self::PENDING) as $path) {
            if (file_exists(self::APP_ROOT . '/' . $path)) {
                $stale[] = $path;
            }
        }

        $this->assertSame(
            [],
            $stale,
            "Entries in PlanStructureTest::PENDING whose path now exists:\n  "
            . implode("\n  ", $stale)
            . "\n\nRemove these entries. An allowance for a directory that is present is an allowance "
            . "that no longer checks anything, so the drift this test detects returns through the "
            . "mechanism meant to record why it was tolerated."
        );
    }

    /**
     * The generated bundle the plan declares under `js/`.
     *
     * Reported rather than failed: `js/` is build output, so a checkout that has not run
     * `npm run build` legitimately has no `js/pettycash-main.mjs`, and that is not a plan defect.
     * The test still has to exist, because "the entry point is missing" is a real deployment
     * failure and it should be visible without anyone opening a browser.
     */
    public function testBuildOutputExists(): void
    {
        $entryPoint = self::APP_ROOT . '/js/pettycash-main.mjs';

        if (!file_exists($entryPoint)) {
            $this->markTestSkipped(
                'js/pettycash-main.mjs is absent — this checkout has not been built. Run '
                . '`npm run build` before deploying; Nextcloud cannot serve the app without it.'
            );
        }

        $this->assertFileExists($entryPoint);
    }

    /**
     * Every declared directory, parsed out of the plan's source-layout block.
     *
     * Parsed from the ```text fence under "### Source Code (repository root)" rather than from the
     * whole document, because the Documentation block above it declares `specs/` paths that are
     * deliberately not a mirror of the repository tree.
     *
     * @return list<string>
     */
    private function declaredPaths(): array
    {
        $plan = self::APP_ROOT . '/' . self::PLAN;
        $source = file_get_contents($plan);
        self::assertIsString($source, 'plan.md could not be read, so nothing can be asserted about it');

        $marker = "### Source Code (repository root)";
        $at = strpos($source, $marker);
        self::assertNotFalse($at, 'plan.md no longer contains the "' . $marker . '" heading');

        $fenceAt = strpos($source, "```text", $at);
        self::assertNotFalse($fenceAt, 'the source-layout block no longer opens with a ```text fence');

        $fenceEnd = strpos($source, '```', $fenceAt + 7);
        self::assertNotFalse($fenceEnd, 'the source-layout block is not closed, so it cannot be parsed');

        $block = substr($source, $fenceAt + 7, $fenceEnd - $fenceAt - 7);

        return $this->pathsFromTree($block);
    }

    /**
     * Resolve a box-drawing tree into full paths, one per declared line.
     *
     * The block is a tree, so a name only identifies a path in combination with its ancestors:
     * `Domain/` under `lib/` is `lib/Domain/`, and the same word under `tests/Unit/` is
     * `tests/Unit/Domain/`. Each line's prefix says how deep it sits — `├── ` at one level,
     * `│   ├── ` at the next — so the ancestors are tracked on a stack rather than guessed from
     * the name. Every entry then resolves to the path the plan actually declares, and the parent
     * directories come out asserted for free: a declared `lib/Domain/` also asserts `lib/`.
     *
     * @return list<string>
     */
    private function pathsFromTree(string $block): array
    {
        $paths = [];
        /** @var list<string> $ancestors from the outermost container down to the current depth */
        $ancestors = [];
        // Whether the stack is currently rooted at a flush-left container, which shifts every
        // marker depth by one level. See the flush-left branch below.
        $rooted = false;

        foreach (explode("\n", $block) as $line) {
            if (trim($line) === '') {
                continue;
            }

            // A top-level declaration (`templates/main.php  # comment`) carries no box-drawing
            // prefix: it is written flush left with its comment beside it. Every other line opens
            // with one `│   ` group per level of nesting, so the groups are counted rather than the
            // byte length — `│` is three bytes in UTF-8 and a column count would be wrong.
            $matched = preg_match('/^((?:│ {3})*)([├└])── /u', $line, $m) === 1;
            $depth = $matched ? preg_match_all('/│ {3}/u', $m[1]) : 0;

            // Strip the trailing `# comment`, then the box-drawing prefix.
            $name = preg_replace('/\s+#.*$/', '', rtrim($line));
            if ($name === null || trim($name) === '') {
                continue;
            }
            $name = trim(preg_replace('/^(?:│\s{3})*[├└]──\s/u', '', $name) ?? $name);

            if ($name === '') {
                continue;
            }

            if (!$matched) {
                // A flush-left line is either a container root written without a `├──` marker
                // (`lib/`, `tests/`) or a whole path stated on one line (`templates/main.php`,
                // `l10n/`). Its children are then drawn at depth 0, so the marker depth is one
                // less than the real nesting and the root has to occupy a level of its own --
                // otherwise `├── info.xml` under `appinfo/` resolves to a bare `info.xml` at the
                // repository root, and `appinfo/` itself is never checked. A flush-left entry with
                // no trailing slash is a file: it has no children and resets the stack.
                $container = str_ends_with($name, '/');
                $ancestors = $container ? [rtrim($name, '/')] : [];
                $rooted = $container;
                $paths[] = rtrim($name, '/');
                continue;
            }

            $level = $rooted ? $depth + 1 : $depth;
            $ancestors = array_slice($ancestors, 0, $level);
            $ancestors[] = rtrim($name, '/');

            $paths[] = implode('/', $ancestors);
        }

        // A leaf written without a trailing slash is a file (`main.ts`, `AppRoot.vue`,
        // `Application.php`); everything else is asserted as a directory. Both are checked with
        // `file_exists`, which does not care which it is, so no classification is needed here.
        return array_values(array_unique($paths));
    }
}
