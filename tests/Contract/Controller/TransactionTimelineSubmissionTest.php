<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * T321 — a transaction's audit must begin with the submission that caused it.
 *
 * ## The defect this pins
 *
 * `spec.md` FR-051: "record submissions, routing decisions, approvals, rejections, returns, holds,
 * evidence changes and the final outcome … Formal transaction audit **MUST begin with sheet
 * submission**." `spec.md:213-216` (US6/AC1) says the same of what a reader sees: "submission is the
 * **first formal event** and every later official event is shown in chronological order."
 *
 * `SubmitSheet.php:119-120` records the `SUBMITTED` event with `sheet_id` set and
 * `transaction_id => null` — correctly, because a submission is a fact about a *sheet* and covers
 * every transaction in it. `entity_type` is `'sheet'` and `entity_id` is the sheet's id, which is the
 * honest scope for that row.
 *
 * But `DbAuditEventRepository::findByTransactionId()` filtered on `transaction_id` alone, so a read
 * of a transaction's history could never return that event. A transaction's audit therefore opened
 * with `ROUTED` — the routing that happened *after* submission — and contained no submission at all.
 *
 * T320 fixed the *order* of this timeline and recorded this acceptance criterion as undelivered,
 * because the criterion was unreachable for this reason. This test is that criterion.
 *
 * ## The decision this encodes: resolution (B)
 *
 * Submission is one fact about one sheet, so it must not be duplicated per transaction. Resolution
 * (B) keeps the single sheet-scoped event and widens the transaction timeline to include its sheet's
 * events (`transaction_id IS NULL`), rather than (A) fanning the event out per transaction, which
 * would record the same submission N times and contradict the `entity_type`/`entity_id` snapshot
 * already written on the row.
 *
 * Two properties follow from (B) and both are asserted here, because either could be broken
 * independently:
 *
 * 1. **The submission is visible** — the timeline opens with it.
 * 2. **The submission is still one fact** — it appears exactly once, rather than being duplicated into
 *    the timeline as resolution (A) would have done.
 *
 * ## What this could not test, and why that is worth recording
 *
 * T321 as written asked for "a sheet with two transactions" so the *sharing* of one submission across
 * transactions could be asserted. **The public API cannot produce that state.**
 * `TransactionController::create()` (`:55`) accepts only `expenseDate` and `amountMinor` — there is no
 * cost-centre parameter — and `SubmitSheet` refuses to submit a sheet whose any transaction has no cost
 * centre (`SubmitSheet.php:100`, `422 "Transaction missing cost center"`). A transaction created
 * through the API therefore cannot be routed, so a two-transaction sheet can never be submitted and no
 * test can build one.
 *
 * That is a genuine finding, but a separate one: it concerns the create endpoint's surface, not the
 * audit read this task is about, and closing it would mean adding a parameter to a contract endpoint.
 * The sharing property is instead guaranteed structurally, by the predicate in
 * `DbAuditEventRepository` matching `transaction_id IS NULL` — a sheet-scoped event is *singleton by
 * construction*, so it can be included once and only once regardless of how many transactions the sheet
 * gains later. The two assertions above cover what the API can express; the two-transaction case belongs
 * to a task that first makes such a sheet buildable.
 */

    /**
     * ## Events are generated, not seeded
     *
     * `ContractFixtures` deletes `pettycash_transaction_audit` rows and inserts none, so a test assuming
     * any would walk an empty set and prove nothing. Everything here goes through the real submit route
     * and the real writers.
     *
     * The sheet-side history has no contract test here, and cannot have one: `appinfo/routes.php`
     * exposes only `transaction#audit` (`:29`) — there is no sheet-audit route at all, which is the same
     * reason `findBySheetId()` has no production caller. The risk this task could plausibly introduce
     * is that widening the *transaction* read also widens the *sheet* read and double-counts the
     * submission. That is prevented structurally rather than by assertion: the widened predicate lives
     * in a helper used only by `findByTransactionId()` and `countByTransactionId()`, and
     * `findBySheetId()` never calls it.
     *
     * The fixtures are also shared across the whole contract suite, which is why `setUp()` reseeds:
     * this test submits the sheet, so without a reseed the first test leaves the fixture submitted and
     * every later test fails on `422 "Stale version"`.
     */
final class TransactionTimelineSubmissionTest extends TestCase
{
    private Client $client;

    /**
     * Reseed to a fresh `draft` sheet before every test.
     *
     * This test *mutates* the fixture by submitting the sheet, and the contract
     * fixtures are a single shared database seeded once per process, not per test. Without the reseed
     * the first test's submit leaves the sheet submitted and every later test fails `422 "Stale version"`
     * or `422 "Cannot add transactions to a submitted sheet"`, which is a setup failure that reads like
     * a product failure. `ContractFixtures::prepare()` is the documented way to get the `draft` state back.
     */
    protected function setUp(): void
    {
        ContractFixtures::prepare();
        $this->client = OcsTestClient::create();
    }

    // ===== HELPERS =====

    private function api(string $suffix): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1' . $suffix;
    }

    private function submitSheet(): void
    {
        $response = $this->client->request('POST', $this->api('/sheets/' . ContractFixtures::sheetId() . '/submit'), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => $this->currentSheetVersion()],
        ]);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'the submit must succeed, or there is no SUBMITTED event to find. Answered instead: ' . (string) $response->getBody()
        );
    }

    /**
     * The sheet's live version, re-read rather than taken from the manifest.
     *
     * Reading the version is the same optimistic-concurrency contract the endpoint enforces rather than
     * a workaround: the value in the fixture manifest describes the seeded sheet, and a test that has
     * done anything to the sheet must not submit against a stale one.
     */
    private function currentSheetVersion(): int
    {
        $response = $this->client->request('GET', $this->api('/sheets/' . ContractFixtures::sheetId()));
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        return (int) ($data['version'] ?? -1);
    }


    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function timeline(string $transactionId, int $pageSize = 100): array
    {
        $response = $this->client->request(
            'GET',
            $this->api('/transactions/' . $transactionId . '/audit'),
            ['query' => ['page' => 1, 'pageSize' => $pageSize]]
        );

        self::assertSame(200, $response->getStatusCode(), 'the audit page must answer 200. Answered instead: ' . (string) $response->getBody());

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];
        self::assertIsArray($data['items'] ?? null, 'AuditPage carries `items`');

        return ['items' => $data['items'], 'total' => (int) ($data['total'] ?? -1)];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function eventTypes(array $items): array
    {
        return array_map(static fn (array $i): string => (string) ($i['eventType'] ?? ''), $items);
    }

    // ===== THE REQUIREMENT =====

    public function testTheTimelineBeginsWithTheSubmission(): void
    {
        $this->submitSheet();

        $items = $this->timeline(ContractFixtures::transactionId())['items'];
        self::assertNotEmpty($items, 'a submitted transaction has events, or this test proves nothing');

        self::assertSame(
            'SUBMITTED',
            $this->eventTypes($items)[0],
            'FR-051 / US6-AC1: formal transaction audit MUST begin with sheet submission. The timeline instead began: '
            . implode(', ', $this->eventTypes($items))
        );
    }

    public function testTheSubmissionPrecedesTheTransactionOwnEvents(): void
    {
        $this->submitSheet();

        $types = $this->eventTypes($this->timeline(ContractFixtures::transactionId())['items']);

        self::assertContains('ROUTED', $types, 'submitting routes the transaction, so ROUTED is on its timeline');
        self::assertSame(
            'SUBMITTED',
            $types[0],
            'the sheet-scoped submission must sort ahead of the transaction-scoped ROUTED, since it happened first'
        );
    }

    // ===== RESOLUTION (B): ONE FACT, NOT ONE PER TRANSACTION =====

    public function testTheSubmissionAppearsExactlyOnceInTheTransactionTimeline(): void
    {
        $this->submitSheet();

        $types = $this->eventTypes($this->timeline(ContractFixtures::transactionId())['items']);

        self::assertSame(
            1,
            count(array_keys($types, 'SUBMITTED', true)),
            'resolution (B) must include the sheet-scoped submission exactly once, not fold it in '
            . 'per event type or per read. Sequence: ' . implode(', ', $types)
        );
    }

    // ===== T319: THE COUNT MUST DESCRIBE THE WIDENED READ =====

    public function testTheTotalCountsTheSheetScopedSubmission(): void
    {
        $this->submitSheet();

        $page = $this->timeline(ContractFixtures::transactionId());

        self::assertSame(
            $page['total'],
            count($page['items']),
            'T319 requires the total to describe the rows the read can return. Widening the read to '
            . 'include the sheet-scoped submission without widening the count would report a total that '
            . 'contradicts its own page — the exact failure T319\'s docblock warns about'
        );
    }

}
