<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * T298: a task note must not assert a completion that never happened.
 *
 * ## The defect
 *
 * Three task notes claimed some other task was already `[x]` when it was not:
 * T032's table said "T053 already `[x]`", and T195/T196 said "T067 already `[x]`" and
 * "T068 already `[x]`". `git log -L` showed all three claims were false at the moment they were
 * written — `e074cff` created T067 and T068 as `[ ]` and they were never checked.
 *
 * These are not cosmetic. A note saying a task is complete is the cheapest thing in `tasks.md` for a
 * later reader to believe, and it is what makes a completed-looking task skip verification. Two of
 * the three were load-bearing: T195 and T196 are themselves `[x]` on the strength of the claim, so
 * the error had already propagated into the record twice over.
 *
 * ## Why a test
 *
 * The cross-check that found all three is four lines of Python, which is exactly why it was never run
 * as part of anything. Constitution V requires a fix to carry a regression test or a repeatable
 * regression procedure; this is the former, and it means a fourth instance fails the suite rather
 * than waiting to be noticed by whoever next reads the whole file.
 *
 * A note claiming a task is *open* is not checked, and deliberately so: `[ ]` is the default state and
 * a note that misremembers an open task as open is not a claim worth auditing.
 */
final class TaskStateConsistencyTest extends TestCase
{
    /**
     * Matches a note asserting that a task is already complete.
     *
     * Two forms occur in this file: "T050 already `[x]`" and "T067 already `[x]`". The bare
     * `` `[x]` `` without a preceding task number is a task asserting *its own* state and is not a
     * cross-reference, so the task number is required.
     */
    private const COMPLETION_CLAIM = '/T(\d+)\s+(?:already\s+)?`\[x\]`/';

    public function testTheTaskArtifactIsReadable(): void
    {
        self::assertNotNull(
            $this->tasksFile(),
            'no tasks.md was found under specs/; this test cannot audit what it cannot read'
        );
    }

    public function testNoTaskNoteClaimsACompletionThatNeverHappened(): void
    {
        $file = $this->tasksFile();
        $contents = (string) file_get_contents($file);
        $shortName = basename(dirname(dirname($file))) . '/' . basename($file);

        $states = $this->taskStates($contents);
        self::assertNotEmpty($states, "no task lines were parsed out of {$shortName}");

        $falseClaims = [];
        foreach (preg_match_all(self::COMPLETION_CLAIM, $contents, $matches) ? $matches[1] : [] as $index => $id) {
            $id = (int) $id;
            if (!array_key_exists($id, $states)) {
                // The claim points at a task that does not exist, which is a different defect and is
                // reported rather than silently skipped.
                $falseClaims[] = sprintf('T%03d — claimed complete by a note, but no such task exists', $id);
                continue;
            }
            if (!$states[$id]) {
                $falseClaims[] = sprintf(
                    'T%03d — claimed complete by a note, but it is `[ ]`',
                    $id
                );
            }
        }

        self::assertSame(
            [],
            $falseClaims,
            "These notes assert a task is complete when it is not:\n  - " . implode("\n  - ", $falseClaims)
            . "\n\nA reader trusting the note skips verification, and if the *noting* task is itself `[x]` the"
            . ' error has already propagated. Either mark the task complete on real evidence, or correct the note to'
            . ' say what is actually true and why. T298 is the precedent: case 1 marked T067/T068 complete because'
            . ' their files existed and passed, case 2 corrected T032\'s note because T053 is correctly open.'
        );
    }

    public function testEveryClaimedTaskIsActuallyMentionedInTheSameFile(): void
    {
        $file = $this->tasksFile();
        $contents = (string) file_get_contents($file);
        $states = $this->taskStates($contents);

        preg_match_all(self::COMPLETION_CLAIM, $contents, $matches);
        $dangling = array_values(array_filter(
            array_map('intval', $matches[1] ?? []),
            static fn (int $id): bool => !array_key_exists($id, $states)
        ));

        self::assertSame(
            [],
            $dangling,
            'Notes reference task IDs that do not exist: ' . implode(', ', array_map(
                static fn (int $id): string => sprintf('T%03d', $id),
                array_unique($dangling)
            ))
        );
    }

    /**
     * Task ID => whether it is checked.
     *
     * @return array<int, bool>
     */
    private function taskStates(string $contents): array
    {
        $states = [];
        // Anchored at the start of a line so prose that mentions "- [x] T123" mid-sentence cannot
        // register as the task's own state.
        if (preg_match_all('/^\s*-\s*\[([ xX])\]\s*T(\d+)\b/m', $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $states[(int) $match[2]] = strtolower($match[1]) === 'x';
            }
        }

        return $states;
    }

    /**
     * The active feature's `tasks.md`, discovered rather than hardcoded.
     *
     * Globbed so the test follows the feature directory instead of pinning one, which would make it
     * fail the moment a second feature is branched.
     */
    private function tasksFile(): ?string
    {
        $root = __DIR__ . '/../../../specs';
        if (!is_dir($root)) {
            return null;
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'tasks.md') {
                $found[] = $file->getPathname();
            }
        }

        if ($found === []) {
            return null;
        }
        if (count($found) > 1) {
            throw new RuntimeException(
                'Found more than one tasks.md under specs/ (' . implode(', ', $found) . '); this test audits the'
                . ' active feature and cannot tell which one that is. Point it at the right file.'
            );
        }

        return $found[0];
    }
}
