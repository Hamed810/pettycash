<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use OCA\PettyCash\Tests\Contract\Fixture\CorruptRecordFixture;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * T311: what a report does when a stored record cannot be read, observed over real HTTP.
 *
 * ## Why this is its own class rather than two more methods on the report suite
 *
 * Because of ordering. This class writes a record that is *supposed* to be unreadable into the MariaDB
 * the whole contract suite reads, so it must be certain that no other suite is running while it does,
 * and certain that the record is gone afterwards whatever happens. A `tearDown()` on a class of its own
 * is the only place that certainty can be expressed: `setUpBeforeClass` repairs a probe left by a
 * crashed run before any test in this file executes, and each test removes the probe in a `finally`, so
 * a failure -- an assertion, an exception, a killed process -- cannot leave the fault behind for the
 * next run to trip over.
 *
 * If the sweep is removed, {@see self::testACorruptRecordFromACrashedRunIsRepairedOnTheNextRun} fails on
 * its own, in the same file, with no ordering assumption.
 *
 * ## What is asserted, and what is deliberately not
 *
 * Asserted: the three subjects that read a revision's currency answer 422, they say the fault is in the
 * data rather than in the request or the caller's permissions, and they do not carry the internal
 * message or the surrogate key it names.
 *
 * Deliberately **not** asserted: that the report degrades gracefully. T308 settled that question with
 * the maintainer and chose refusal over a partial answer, and this task exists to verify that decision
 * rather than to reopen it. `total: 0` would be a worse answer than a refusal, and the test would be
 * asserting the wrong thing if it demanded a partial page.
 *
 * Also not asserted: that the fault is unreachable in production. T308's finding stands -- a genuinely
 * dangling `sheets.currency_id` destroys three whole reports -- and this only makes it observable.
 */
final class DataIntegrityReportTest extends TestCase
{
    /**
     * The subjects that cannot be produced while a stored currency reference does not resolve, and so
     * answer 422.
     *
     * A list rather than a single subject, because "the report" is not one endpoint: a fixture that
     * proved only `/reports/transactions` would be satisfied by a refusal that happened to be specific
     * to it, and would say nothing about the two that T308 measured losing all their data.
     *
     * `sheets` joined this list in T267, and not because it reads currency the same way. It reads the
     * *same broken column* — `sheets.currency_id` — through a different repository method, and that one
     * substituted the literal `'IRR'` for a reference that resolved to nothing. It answered `200` and
     * reported the unreadable sheet as a rial sheet, which is the worst of the three outcomes: a
     * confident number that is wrong, rather than an error that is visible. T311 measured exactly that
     * and deliberately did not assert it, because a test pinning `"IRR"` would enshrine a defect as a
     * requirement and would then have to be deleted when T267 removed it. T267 removed it, so the
     * honest answer is now assertable.
     */
    private const CURRENCY_READING_SUBJECTS = ['sheets', 'transactions', 'process', 'evidence'];

    /** The subjects that genuinely do not resolve a currency, and so must be untouched by the fault. */
    private const CURRENCY_FREE_SUBJECTS = ['audit'];

    private Client $client;

    public static function setUpBeforeClass(): void
    {
        // Repair before anything else, unconditionally.
        //
        // Without this, a run that was killed inside `try { ... } finally { remove(); }` -- between the
        // write and the removal, which is exactly the window this class exists to occupy -- would leave
        // the fault in place, and the *next* run's report tests would answer 422 for a reason three
        // files away from its cause. That is the same search T308's note records: the report is broken
        // and the investigation starts in the report.
        CorruptRecordFixture::sweep();
    }

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('submitted');
    }

    /**
     * The refusal, and the withholding of the internal message, in one fixture lifetime.
     *
     * One lifetime rather than two on purpose. Each write opens a window in which the probe is in the
     * database, and the window is the risk this task is managing; splitting the assertions across two
     * windows doubles the exposure to buy nothing, because both assertions are about the same response.
     */
    public function testAnUnreadableStoredRecordRefusesTheReportWithoutDisclosingTheInternalMessage(): void
    {
        $probe = CorruptRecordFixture::insert();

        try {
            foreach (self::CURRENCY_READING_SUBJECTS as $subject) {
                $response = $this->get('/reports/' . $subject);
                $raw = $this->raw('/reports/' . $subject);

                $this->assertSame(
                    422,
                    $response['status'],
                    "GET /reports/{$subject} over an unreadable record must answer 422, not a partial "
                    . "page and not a 500: the caller can act on being told the data is at fault"
                );

                $message = (string) ($response['data']['error']['message'] ?? '');
                $this->assertNotSame('', $message, "GET /reports/{$subject} must carry a message");

                // It has to say *what kind* of problem this is. T029's defect was a persistence fault
                // answered as `403 Forbidden` with the internal message attached, which sent the whole
                // investigation after permissions that were never the problem. A 422 whose text called it
                // a data fault is the correction; a 422 whose text did not would repeat the confusion in
                // a different status code.
                $this->assertStringContainsString(
                    'data fault',
                    $message,
                    "GET /reports/{$subject} must name the fault as being in the data, so a caller does "
                    . 'not go looking for a permission or filter problem'
                );
                $this->assertStringContainsString(
                    'not a permission or filter problem',
                    $message,
                    "GET /reports/{$subject} must rule out the two causes a caller would otherwise chase"
                );

                // The internal message, verbatim, is what must not appear.
                $this->assertStringNotContainsString(
                    'Cannot resolve the currency of transaction revision',
                    $raw,
                    "GET /reports/{$subject} leaked the internal exception message. That message names "
                    . 'the surrogate ids, which is what an operator needs and what a caller must not be '
                    . 'given; it belongs in the log, not in the body.'
                );

                // Wording-independent, because the wording is not the point and two subjects do not
                // throw the same message. The surrogate keys are: a client that learns which stored row
                // is broken learns something about rows it may not be able to see, and that is the
                // disclosure T029 was raised for. Checking them by value rather than by message text
                // also stops this assertion from quietly ceasing to cover a subject whose message
                // happens to differ — which is what `sheets` did.
                $this->assertStringNotContainsString(
                    (string) $probe['revisionDbId'],
                    $raw,
                    "GET /reports/{$subject} leaked the revision's surrogate key, which is the specific "
                    . 'disclosure T029 was raised for'
                );
                $this->assertStringNotContainsString(
                    (string) $probe['danglingCurrencyId'],
                    $raw,
                    "GET /reports/{$subject} leaked the currency id that does not resolve. Telling a "
                    . 'caller *which* reference is broken is exactly as much as it needs to be told; the '
                    . 'value itself belongs in the log.'
                );

                if ($subject === 'sheets') {
                    $this->assertStringNotContainsString(
                        '"IRR"',
                        $raw,
                        'GET /reports/sheets must not report a sheet whose currency resolves to nothing '
                        . 'as a rial sheet. That is T267\'s silent substitution: the amount is read in '
                        . 'the wrong unit, so every derived total is wrong by a factor of 10^4, and the '
                        . 'answer looks measured.'
                    );
                }
            }
        } finally {
            CorruptRecordFixture::remove();
        }
    }

    /**
     * The control: with the probe gone the same three requests answer 200.
     *
     * Without this the test above would still pass against a database that was *already* refusing those
     * reports for some other reason -- which is not a hypothetical, because that is exactly the state
     * T308's seven leaked sheets left behind. A test that asserts a refusal and never asserts the
     * restoration cannot tell "the refusal works" from "the report was already broken".
     */
    public function testTheReportAnswersAgainOnceTheUnreadableRecordIsRemoved(): void
    {
        CorruptRecordFixture::insert();

        try {
            $this->assertSame(
                422,
                $this->get('/reports/transactions')['status'],
                'the probe must be what refuses the report, or the next assertion is vacuous'
            );
        } finally {
            // `remove()` throws if anything it owns survived, so reaching the assertions below means the
            // database is clean -- which is the precondition for them meaning anything.
            CorruptRecordFixture::remove();
        }

        foreach (self::CURRENCY_READING_SUBJECTS as $subject) {
            $this->assertSame(
                200,
                $this->get('/reports/' . $subject)['status'],
                "GET /reports/{$subject} must answer 200 once the unreadable record is gone. A fixture "
                . 'that can only demonstrate a failure is not evidence that the failure is the '
                . "fixture's own doing; three subjects refusing for a reason that outlived the probe "
                . 'would be a defect this test would otherwise be reading as success.'
            );
        }
    }

    /**
     * The self-healing property, exercised rather than described.
     *
     * A probe is written and deliberately *not* removed -- the state a killed process leaves behind --
     * and the next sweep has to find it. The sweep's own removal is verified by `remove()`, so this
     * asserts the two halves separately on purpose: that the repair path works, and that the removal
     * path reports success.
     */
    public function testACorruptRecordFromACrashedRunIsRepairedOnTheNextRun(): void
    {
        $probe = CorruptRecordFixture::insert();

        try {
            $this->assertSame(
                422,
                $this->get('/reports/transactions')['status'],
                'the probe must be present and refusing the report before the repair is attempted'
            );

            $repaired = CorruptRecordFixture::sweep();

            $this->assertGreaterThan(
                0,
                $repaired,
                'the repair must have removed something. A sweep that reports zero while a probe is in '
                . 'the database is the failure mode this test exists to catch: a removal that claims '
                . 'success without doing anything is worse than no removal, because the operator stops '
                . 'looking.'
            );
            $this->assertSame(
                200,
                $this->get('/reports/transactions')['status'],
                'the report must answer 200 after the crashed probe is swept'
            );
        } finally {
            // Belt and braces: the fixture's own `finally` in the test above already swept, so this
            // exists for the case where an assertion above threw before the sweep ran.
            try {
                CorruptRecordFixture::remove();
            } catch (Throwable $e) {
                // Reported rather than swallowed: a removal that cannot complete is a real failure, and
                // replacing it with a different failure would hide which one happened.
                throw $e;
            }
        }

        // The probe's own rows are gone, and nothing is left that a later run would trip over.
        $this->assertSame(200, $this->get('/reports/transactions')['status']);
        $this->assertNotSame(
            $probe['transactionId'],
            '',
            'sanity: the probe reported a transaction id to sweep, so the sweep had something to match'
        );
    }

    /**
     * The subjects that do not resolve a currency are unaffected, which is why the probe is scoped the
     * way it is.
     *
     * The probe is owned by the acting user, and `ReportController::transactionsReport()` reads
     * `findByOwnerUid(...)` for a non-administrator but `findByStageAndStatus(WorkflowStage::Final, ...)`
     * for an administrator. The probe is therefore at `DRAFT` on purpose: a probe at Final would be read
     * by both, and a crashed probe would refuse the report for a user with nothing to do with it.
     *
     * ## `sheets` was in this list, and this task moved it
     *
     * Until T267 it was, and it was here because `/reports/sheets` *did* answer `200` with the probe in
     * the database — reporting the unreadable sheet as a rial sheet, through
     * `DbExpenseSheetRepository::getCurrencyCode()`'s hardcoded `'IRR'`. The assertion below was written
     * to pin that reachability, not the correctness of the answer: it claimed the subject was not
     * *refused*, and said so in as many words. T267 removed the substitution, so the claim is now false
     * and the test moves to `CURRENCY_READING_SUBJECTS`.
     *
     * That is worth stating plainly, because the alternative was to leave the assertion and let a
     * correct fix fail it. The assertion was never wrong about what it measured; it was wrong about
     * what that measurement should be.
     */
    public function testTheSubjectsThatDoNotResolveTheCurrencyAreUnaffected(): void
    {
        $before = $this->get('/reports/sheets')['status'];
        $this->assertSame(200, $before, 'the baseline must be healthy, or the next assertion is vacuous');

        CorruptRecordFixture::insert();

        try {
            foreach (self::CURRENCY_FREE_SUBJECTS as $subject) {
                $this->assertSame(
                    200,
                    $this->get('/reports/' . $subject)['status'],
                    "GET /reports/{$subject} does not resolve a currency and must not be refused by this "
                    . 'fault. If it now answers 422 the refusal is coming from somewhere other than the '
                    . 'broken reference, and this is the assertion that would say so.'
                );
            }
        } finally {
            CorruptRecordFixture::remove();
        }

        foreach (self::CURRENCY_READING_SUBJECTS as $subject) {
            $this->assertSame(
                200,
                $this->get('/reports/' . $subject)['status'],
                "GET /reports/{$subject} must be healthy again once the probe is gone"
            );
        }
    }

    /**
     * The removal leaves nothing behind, and says so.
     *
     * `remove()` throws on a non-empty post-condition, so this asserts the *clean* case explicitly: a
     * removal that is only ever run while something is there has never been observed succeeding, and a
     * guard that has only failed has not been shown to pass.
     */
    public function testRemovingAnAbsentProbeReportsCleanRatherThanFailing(): void
    {
        $removed = CorruptRecordFixture::remove();

        $this->assertSame(
            0,
            $removed,
            'nothing was written, so nothing can be removed; a non-zero count here would mean the sweep '
            . 'had deleted rows this test does not own'
        );
        $this->assertSame(
            200,
            $this->get('/reports/transactions')['status'],
            'the report must be healthy when no probe has ever been written'
        );
    }

    /**
     * Writing a probe over an existing one repairs the first, so the fault cannot accumulate.
     *
     * This is the property that makes the fixture safe rather than merely careful, and it is the one
     * that cannot be observed by any test which removes its probe: the damage it prevents is a probe
     * left by a *previous, killed* run, and the only way to see that behaviour from inside a run is to
     * put the database into that state on purpose and then write another probe.
     *
     * Two things are asserted, and the second is the one that matters. `repairedBeforeWriting` proves
     * the repair ran. The follow-up request proves the *result*: still exactly one fault, refusing the
     * same way. A sweep that ran but matched nothing would satisfy the first assertion and leave every
     * earlier probe in place, and the number of them would grow with every run.
     */
    public function testWritingAProbeRepairsAnyProbeAnEarlierRunLeftBehind(): void
    {
        $first = CorruptRecordFixture::insert();
        $this->assertSame(
            0,
            $first['repairedBeforeWriting'],
            'nothing can be repaired before the very first probe of a clean database; a non-zero count '
            . 'here means an earlier run leaked and this run is the first to notice'
        );

        try {
            $second = CorruptRecordFixture::insert();

            $this->assertGreaterThan(
                0,
                $second['repairedBeforeWriting'],
                'inserting over an existing probe must remove the existing one first. Without this the '
                . 'fixture accumulates one unreadable record per attempt, and a run killed between the '
                . 'write and the removal would compound it on every subsequent run.'
            );
            $this->assertSame(
                $first['transactionId'],
                $second['transactionId'],
                'the probe is identified by a fixed public id precisely so a repeat run replaces it '
                . 'rather than adding a second one'
            );

            $this->assertSame(
                422,
                $this->get('/reports/transactions')['status'],
                'after repairing and rewriting there must still be exactly one fault, refusing the same '
                . 'way -- if the first probe had survived there would be two, and this request could not '
                . 'tell the difference'
            );
        } finally {
            CorruptRecordFixture::remove();
        }

        $this->assertSame(200, $this->get('/reports/transactions')['status']);
    }

    /** @return array{status: int, data: array<string, mixed>, message: string} */
    private function get(string $path): array
    {
        return $this->decode($this->request('GET', $path));
    }

    /** The undecoded body, for asserting on what is *absent* from it. */
    private function raw(string $path): string
    {
        return (string) $this->request('GET', $path)->getBody();
    }

    private function request(string $method, string $path): ResponseInterface
    {
        return $this->client->request($method, '/ocs/v2.php/apps/pettycash/api/v1' . $path);
    }

    /** @return array{status: int, data: array<string, mixed>, message: string} */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The response was not a JSON OCS envelope.');
        }

        return [
            'status' => (int) ($decoded['ocs']['meta']['statuscode'] ?? 0),
            'data' => (array) ($decoded['ocs']['data'] ?? []),
            'message' => (string) ($decoded['ocs']['meta']['message'] ?? ''),
        ];
    }
}
