<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * T320 — the transaction audit timeline is a chronology, and it was newest-first.
 *
 * ## What this pins
 *
 * `spec.md:213-216` (US6/AC1): "Given a sheet is submitted, When an authorized user views a
 * transaction timeline or history table, **Then submission is the first formal event and every later
 * official event is shown in chronological order.**" `spec.md:647` (SC-006) repeats it: "100% of
 * official events in the acceptance workflow appear in **chronological history**".
 *
 * `DbAuditEventRepository` ordered `occurred_at DESC` on all four timeline reads, so the submission —
 * which by definition happened first — was rendered last. `DbTransactionRevisionRepository:56` already
 * ordered `revision_number ASC`, so a single screen listed the oldest revision first and the newest
 * event first at the same time.
 *
 * ## Why no test caught it, and why that matters here
 *
 * The audit table in `SheetDetailView.vue:255` is `v-if="false"` with no data binding: it renders no
 * rows, so the endpoint's ordering has no user-visible symptom yet, and no test asserted an order.
 * These tests therefore pin the requirement **before** the table is wired (T098), because once it is,
 * the order becomes user-visible and a regression would be found in the interface rather than here.
 *
 * ## Events are generated, not seeded
 *
 * `ContractFixtures` deletes `pettycash_transaction_audit` rows and inserts none, so a test that
 * assumed any would walk an empty set and prove nothing. Submitting and editing through the API writes
 * real events through the real writers — `SUBMITTED` and one `TRANSACTION_CHANGED` per edit.
 *
 * ## T321 widened what this timeline can see
 *
 * T320 fixed the order and left the *first* event wrong: the timeline opened with `ROUTED` because
 * `SubmitSheet.php:120` writes `SUBMITTED` with `transaction_id => null`, invisible to a read filtered
 * on `transaction_id`. T321 fixed that, and these sequences now lead with `SUBMITTED`. The ordering
 * assertions below are unchanged in intent — they are still about sequence, not membership — but a
 * reader comparing them against T320's report should expect the extra leading element.
 */
final class AuditTimelineOrderTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
    }

    // ===== HELPERS =====

    private function transactionUrl(string $suffix = ''): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . $suffix;
    }

    private function sheetUrl(string $suffix): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . $suffix;
    }

    private function currentVersion(): int
    {
        $response = $this->client->request('GET', $this->transactionUrl());
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        return (int) ($data['version'] ?? -1);
    }

    /**
     * Submit the sheet, then record two decisions, so the timeline holds three events in a known order.
     *
     * **Decisions, not edits.** The first version of this helper tried to add events with `PATCH` after
     * the submit, and every test failed with `422 "Cannot update transactions in a submitted sheet"` —
     * which is correct. FR-026 forbids editing after submission and the correction path is the one
     * exception, so a submitted transaction is not editable at all. A decision is a post-submission
     * event, which is exactly what a chronology needs.
     *
     * The `submitted` fixture state is not used to obtain the `SUBMITTED` event either: `ContractFixtures`
     * deletes `pettycash_transaction_audit` rows and inserts none, so the submit is performed here and
     * the event written by the real writer.
     */
    private function recordAtLeastThreeEvents(): void
    {
        $submit = $this->client->request('POST', $this->sheetUrl('/submit'), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
        ]);
        self::assertSame(
            200,
            $submit->getStatusCode(),
            'the submit must succeed. Answered instead: ' . (string) $submit->getBody()
        );

        // Two approvals, one per stage. `ContractFixtures` gives the acting user both the cost-centre
        // and the senior assignment in the `draft` -> submitted transition, so the same session can
        // carry the transaction through both and produce a second event type for the ordering to be
        // checked within as well as across.
        foreach (['cost centre', 'senior'] as $label) {
            $decision = $this->client->request('POST', $this->transactionUrl('/decisions'), [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['decision' => 'APPROVE', 'expectedVersion' => $this->currentVersion()],
            ]);
            self::assertSame(
                201,
                $decision->getStatusCode(),
                "the {$label} approval must succeed so the timeline has events to order. "
                . 'Answered instead: ' . (string) $decision->getBody()
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timeline(int $pageSize = 100): array
    {
        $response = $this->client->request('GET', $this->transactionUrl('/audit'), [
            'query' => ['page' => 1, 'pageSize' => $pageSize],
        ]);

        self::assertSame(200, $response->getStatusCode(), 'the audit page must answer 200. Answered instead: ' . (string) $response->getBody());

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];
        self::assertIsArray($data['items'] ?? null, 'AuditPage carries `items`');

        return $data['items'];
    }

    // ===== ORDERING =====

    /**
     * The load-bearing assertion, and the one that caught the defect.
     *
     * US6/AC1 says "chronological order", and chronological means time order — oldest first. AC1 names
     * submission as the *first* event, which is only true ascending, so this is the requirement read
     * literally rather than the "newest first is friendlier" reading the reversed implementation
     * happened to satisfy.
     *
     * **The time comparison alone is not sufficient, and finding that out is why this test reads the
     * event types as well.** `occurred_at` is stored at second granularity and a submit followed by two
     * approvals lands inside one second, so every row in the sequence carries the *same* timestamp: the
     * ascending-times check passed against the reversed implementation. It is kept — it is the direct
     * statement of the requirement and it bites the moment a timeline spans seconds — but the type
     * sequence is asserted in the same test so it cannot pass vacuously.
     */
    public function testTheTimelineIsInChronologicalOrder(): void
    {
        ContractFixtures::prepare('draft');
        $this->recordAtLeastThreeEvents();

        $items = $this->timeline();

        self::assertGreaterThanOrEqual(
            3,
            count($items),
            'the fixture must produce several events, or an ascending assertion could pass on one row. '
            . 'Saw: ' . json_encode(array_column($items, 'eventType'))
        );

        $times = array_map(static fn (array $e): string => (string) ($e['occurredAt'] ?? ''), $items);
        $sorted = $times;
        sort($sorted, SORT_STRING);

        self::assertSame(
            $sorted,
            $times,
            'US6/AC1 requires the timeline in chronological order. The endpoint returned these times out '
            . 'of order, which puts the submission — the first formal event by definition — last. '
            . 'Saw: ' . json_encode($times)
        );

        // The half that actually discriminates, because the timestamps above are one second apart at
        // closest. `occurred_at` cannot separate these rows, so the order has to come from the
        // monotonic insert key — which is what the tiebreak is for.
        //
        // `SUBMITTED` leads because T321 widened this read to include the sheet's sheet-scoped events
        // (resolution B). Before that it was `ROUTED`, and the gap it left is now closed: see
        // `TransactionTimelineSubmissionTest`, which is the test that pins the submission's presence,
        // while these pin its position. The two are deliberately separate — presence and ordering can
        // break independently, and folding them together would let a revert of either hide behind the
        // other's assertion.
        self::assertSame(
            ['SUBMITTED', 'ROUTED', 'APPROVED', 'APPROVED'],
            array_column($items, 'eventType'),
            'the events must appear in the order they happened. This is the assertion that fails when '
            . 'the timeline is reversed, and the timestamps above cannot detect it because every event '
            . 'in this run shares one second'
        );
    }

    /**
     * The first event the timeline can see is the first one that happened.
     *
     * This is the ordering half of AC1's claim, asserted on its own so the failure names the
     * requirement rather than a comparison. Ascending order implies it, so it is in principle
     * redundant — and that redundancy is the point: it fails with a message a reader of AC1 recognises.
     *
     * **This assertion changed under T321, and that change is the honest history of the gap.** The first
     * event used to be `ROUTED`: `SubmitSheet.php:120` writes the `SUBMITTED` event with
     * `transaction_id => null`, so a read filtered on `transaction_id` could not see it and US6/AC1's
     * "submission is the first formal event" could not hold. T320 asserted `ROUTED` and recorded the
     * requirement as unmet rather than assuming a fix. T321 resolved it (B — the read now folds in the
     * sheet's sheet-scoped events) and this assertion became `SUBMITTED`, which is what the requirement
     * always said.
     *
     * So this test is now a **revert detector for T321**: if the timeline is widened back to
     * `transaction_id` alone, this fails on the requirement's own words rather than on a comparison.
     */
    public function testTheFirstEventTheTimelineCanSeeIsTheFirstOneThatHappened(): void
    {
        ContractFixtures::prepare('draft');
        $this->recordAtLeastThreeEvents();

        $items = $this->timeline();

        self::assertNotEmpty($items, 'the fixture must produce events');
        self::assertSame(
            'SUBMITTED',
            $items[0]['eventType'] ?? null,
            'FR-051 requires formal transaction audit to begin with sheet submission, and US6/AC1 that '
            . 'submission is the first formal event a reader sees. A reversed timeline reports the last '
            . 'decision first; a timeline that lost the sheet-scoped submission reports the routing '
            . 'first and never shows the submission at all'
        );
    }

    /**
     * The edits appear after the submission, in the order they were made.
     *
     * Both later events are `APPROVED`, so this checks the sequence *within* a type rather than just the
     * first element — a sort that put SUBMITTED first and then reversed the rest would pass the two
     * tests above.
     */
    public function testLaterEventsFollowInTheOrderTheyHappened(): void
    {
        ContractFixtures::prepare('draft');
        $this->recordAtLeastThreeEvents();

        $items = $this->timeline();
        $types = array_column($items, 'eventType');

        self::assertSame(
            ['SUBMITTED', 'ROUTED', 'APPROVED', 'APPROVED'],
            $types,
            'the two approvals were recorded in that order after the routing, and the timeline must read '
            . 'that way. Both later events are the same type, so a sort that ordered only across types — '
            . 'or reversed within one — would pass the two tests above and fail here'
        );
    }

    /**
     * The order is deterministic, not incidental.
     *
     * `occurred_at` is stored at second granularity — `DbAuditEventRepository` and every writer format
     * `Y-m-d H:i:s` — and a submit followed by two edits lands inside one second routinely. Ordering by
     * `occurred_at` alone therefore leaves same-second events in whatever order the database returns,
     * which differs between runs and between page boundaries. The repository must break ties on the
     * monotonic autoincrement `id`, so the sequence is stable and a paged walk cannot repeat or drop an
     * event across a page seam.
     *
     * Read twice rather than compared against a hardcoded sequence: what is being asserted is that two
     * identical requests agree, which is the property that makes the timeline safe to page over.
     */
    public function testTheOrderIsStableAcrossRepeatedReads(): void
    {
        ContractFixtures::prepare('draft');
        $this->recordAtLeastThreeEvents();

        $first = array_column($this->timeline(), 'id');
        $second = array_column($this->timeline(), 'id');

        self::assertSame(
            $first,
            $second,
            'two identical requests must return the same sequence. An order that depends on how the '
            . 'database happened to return same-second rows is not a chronology, and it makes T319\'s '
            . 'paged walk able to repeat or skip an event at a page seam'
        );
    }

    /**
     * Paging must not disturb the order.
     *
     * T319 established that `page`/`pageSize` select a window and `total` counts the whole set. Reading
     * the same events one at a time has to reconstruct the same sequence, or the timeline is ordered
     * per page rather than as a whole — which is the shape of bug that looks correct in any single
     * response.
     */
    public function testPagingReconstructsTheSameSequence(): void
    {
        ContractFixtures::prepare('draft');
        $this->recordAtLeastThreeEvents();

        $whole = array_column($this->timeline(100), 'id');
        self::assertNotEmpty($whole, 'the fixture must produce events');

        $walked = [];
        $page = 1;
        while ($page <= 20) {
            $batch = array_column($this->pageItems($page), 'id');
            if ($batch === []) {
                break;
            }
            $walked = [...$walked, ...$batch];
            $page++;
        }

        self::assertSame(
            $whole,
            $walked,
            'walking the timeline one event per page must reproduce the full sequence exactly. Order that '
            . 'holds within a page but not across pages is not a chronology'
        );
    }

    /** @return list<array<string, mixed>> */
    private function pageItems(int $page): array
    {
        $response = $this->client->request('GET', $this->transactionUrl('/audit'), [
            'query' => ['page' => $page, 'pageSize' => 1],
        ]);

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        return is_array($data['items'] ?? null) ? array_values($data['items']) : [];
    }
}
