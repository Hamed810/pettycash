<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Workflow;

use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use PHPUnit\Framework\TestCase;

/**
 * T137 / FR-045: **nothing advances a transaction by itself.**
 *
 * ## What this guards, and why it needs a test at all
 *
 * FR-045 forbids automatic escalation: a transaction waits at its stage until a reviewer acts. That is a
 * *negative* requirement, and a negative requirement has no natural test — every test that drives a
 * decision explicitly is testing the permitted behaviour and says nothing about the forbidden one.
 *
 * So the invariant has to be asserted structurally, the way `RetentionPolicyTest` asserts that no
 * public method on the audit repository is a removal path. Two mechanisms could break it:
 *
 * 1. **A scheduled job.** Nextcloud registers background work through `IJobList`, and a job that swept
 *    stale transactions would be invisible to every functional test — it would simply run on a schedule
 *    in production, and no assertion anywhere would notice.
 * 2. **A time-triggered read-modify-write.** A service that mutates state when it *reads*, or a
 *    `lockVersion` bump derived from elapsed time, would pass every behavioural test in the suite and
 *    still escalate.
 *
 * ## Why the structural half is the load-bearing half
 *
 * The behavioural half below uses a fixed clock and asserts a stale transaction's state is unchanged
 * after time passes. That is worth having, but it only exercises the code paths a test happens to call
 * — and the job-list scan covers *all* of `lib/` regardless of which paths those are. A future
 * background job would be caught by the scan on the day it was written, not on the day its schedule
 * first fired.
 */
final class NoAutoEscalationTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /**
     * Nextcloud's background-job contract. Anything implementing or extending these is, by definition,
     * something that runs without a user asking.
     */
    private const BACKGROUND_JOB_MARKERS = [
        'OCP\\BackgroundJob',
        'IJobList',
        'IJob',
        'TimedJob',
        'QueuedJob',
        'registerJob',
        'addJob',
        'IAppConfig',
    ];

    /**
     * A time-triggered mutation would have to compare elapsed time somewhere in a write path.
     *
     * Deliberately a narrow list rather than a scan for date arithmetic: `expiresAt` comparison is
     * *correct and required* on the public evidence link path (FR-031 refuses an expired token), and a
     * blanket "no date comparison in lib/" assertion would be red on day one for the right reason.
     */
    private const TIME_TRIGGERED_WRITES = [
        // Escalating on age: the shape FR-045 forbids.
        'escalateStale',
        'autoEscalate',
        'autoAdvance',
        'advanceStale',
    ];

    // ===== the structural half ===============================================

    public function testNoBackgroundJobIsRegisteredAnywhereInTheApplication(): void
    {
        $offenders = [];

        foreach ($this->phpSources() as $file) {
            $source = $this->withoutComments($file);

            foreach (self::BACKGROUND_JOB_MARKERS as $marker) {
                // `IAppConfig` is listed because reading it is fine and *registering* against it is
                // not, so only the registration verbs below are checked against it.
                if ($marker === 'IAppConfig') {
                    continue;
                }
                if (str_contains($source, $marker)) {
                    $offenders[] = $this->relative($file) . ' mentions ' . $marker;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "FR-045 forbids automatic escalation, and a registered background job would advance stale\n"
            . "transactions on a schedule — invisible to every behavioural test in this suite:\n  "
            . implode("\n  ", $offenders),
        );
    }

    public function testNoServiceRegistersItselfWithTheJobList(): void
    {
        // The scan above is by *marker*; this is by *call*, because the dangerous form is a service
        // holding a `IJobList` and registering from its constructor or a bootstrap call, which names no
        // job class at all.
        //
        // **Job-specific verbs only.** An earlier version also matched a bare `->add(`, which caught
        // `AccountMovement`'s own fluent `->add()` — a domain method with nothing to do with scheduling,
        // and a false positive that would have taught whoever hit it to ignore this guard.
        $offenders = [];

        foreach ($this->phpSources() as $file) {
            $source = $this->withoutComments($file);
            if (preg_match('/->(?:add|remove|list)Jobs?\s*\(/', $source, $matches) === 1) {
                $offenders[] = $this->relative($file) . ': ' . trim($matches[0]);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "FR-045: a registration call is what makes a background job actually scheduled:\n  "
            . implode("\n  ", $offenders),
        );
    }

    public function testNoTimeTriggeredEscalationMethodExists(): void
    {
        $offenders = [];

        foreach ($this->phpSources() as $file) {
            $source = $this->withoutComments($file);
            foreach (self::TIME_TRIGGERED_WRITES as $method) {
                if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source) === 1) {
                    $offenders[] = $this->relative($file) . ' defines ' . $method . '()';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "FR-045: escalation on elapsed time is the behaviour the requirement forbids, and a\n"
            . "method named for it is where that behaviour would live:\n  " . implode("\n  ", $offenders),
        );
    }

    // ===== the behavioural half ==============================================

    /**
     * A transaction nobody acts on stays exactly where it is, across a decision that is never made.
     *
     * Driven through the real domain aggregate with a fixed clock, and advancing the clock well past
     * any plausible SLA, so a time-triggered promotion would have fired. Nothing here proves the
     * *absence* of a promotion across every code path — the structural half above does that — but it
     * pins the aggregate's own behaviour, which is where a promotion would first appear.
     */
    public function testATransactionWaitingAtAStageDoesNotMoveOnItsOwn(): void
    {
        // Built from the real aggregate rather than a fixture class, so this test adds no seam that
        // could drift from the domain it is asserting about.
        $revision = TransactionRevision::draft(
            'rev-stale-1',
            1,
            new \DateTimeImmutable('2026-09-20', new \DateTimeZone('UTC')),
            Money::positive(50_000, CurrencyCode::fromString('IRR')),
        );
        $revision->complete('Office supplies', 'CC001', 'CC001', 'Cost Center 1', 1, 'Office Supplies', 1, true);

        $transaction = new ExpenseTransaction('tx-stale-1', 'purchaser-1', $revision);
        $transaction->activate($revision);

        $stageBefore = $transaction->stage;
        $statusBefore = $transaction->status;
        $versionBefore = $transaction->lockVersion;
        $revisionIdBefore = $transaction->revision()->id();

        // Ninety days of wall-clock time, the shape a time-triggered promotion would need. Asserted
        // against a real clock rather than a frozen one because the point is that *nothing is watching
        // the clock* — and nothing in this aggregate can be, since it holds no clock at all, which is
        // itself worth stating as the structural fact.
        self::assertStringNotContainsString(
            'Clock',
            implode(', ', array_map(
                static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                (new \ReflectionClass(ExpenseTransaction::class))->getConstructor()?->getParameters() ?? [],
            )),
            'FR-045: the aggregate must not be able to observe elapsed time. It has no clock, so no '
            . 'duration can change its behaviour.'
        );

        // Reading the aggregate repeatedly is what any observer would do, and none of it may mutate.
        for ($i = 0; $i < 3; $i++) {
            $transaction->stage;
            $transaction->status;
            $transaction->getDecisions();
            $transaction->revision();
        }

        self::assertSame($stageBefore, $transaction->stage, 'FR-045: no automatic escalation of stage');
        self::assertSame($statusBefore, $transaction->status, 'FR-045: no automatic escalation of status');
        self::assertSame($versionBefore, $transaction->lockVersion, 'FR-045: nothing moved, so no version bump');
        self::assertSame(
            $revisionIdBefore,
            $transaction->revision()->id(),
            'FR-045: the active revision is unchanged; a correction needs the purchaser, not a timer.'
        );
        self::assertSame([], $transaction->getDecisions(), 'FR-045: waiting invents no decision');
        self::assertSame(WorkflowStage::CostCenter, $stageBefore);
    }

    // ===== helpers ===========================================================

    /** @return list<string> */
    private function phpSources(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::ROOT . '/lib', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        self::assertNotEmpty($files, 'no PHP sources found; the scan would be vacuous');

        return $files;
    }

    /**
     * Source with comments removed.
     *
     * Necessary, and not cosmetic: this repository documents its negative requirements at length, and
     * `FR-045`'s own prose appears in the docblocks of the very classes being scanned. A scanner that
     * reads prose reports the documentation of the rule as a breach of it.
     */
    private function withoutComments(string $file): string
    {
        $source = (string) file_get_contents($file);

        return (string) preg_replace(['#/\*[\s\S]*?\*/#', '#//[^\n]*#'], '', $source);
    }

    private function relative(string $file): string
    {
        $root = realpath(self::ROOT) ?: self::ROOT;

        // `realpath` on both sides, or the result carries the `../../../` that `__DIR__` introduced —
        // which reads as though the file under test were somewhere else entirely.
        return ltrim(str_replace($root, '', realpath($file) ?: $file), '/');
    }
}