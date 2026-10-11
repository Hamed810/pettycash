<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Contract tests for the shared finance queue and the finance decision responses.
 *
 * `ManagementReviewControllerTest` already asserts that `GET /queues/finance` answers 200 and
 * that a decision answers with an OCS envelope. Both of those hold against an empty queue and
 * against a body that carries nothing but the envelope, which is what the `finance` fixture state
 * did not exist to prevent: with no transaction awaiting finance review, the queue is empty and
 * every implementation of it — shared, scoped, or broken — returns the same empty page.
 *
 * So the `finance` state seeds three transactions at the finance stage, each for a different
 * claim, and each with the two management decisions that put it there:
 *
 * - the primary trio, senior-approved, in the cost center the acting user manages;
 * - one in CC-200, which the acting user does **not** manage, so a queue filtered to the caller's
 *   own cost centers returns one row instead of three (FR-034, T062);
 * - one the senior manager rejected, so a policy that assumes every senior decision was an
 *   approval is caught (FR-039).
 *
 * The second half of the file is about the decision response. The contract answers
 * `POST /transactions/{id}/decisions` with `DecisionResult` — the decision that was recorded, the
 * transaction it was recorded on, and on a finance approval the account mutation it caused. The
 * controller answered with a bare `TransactionDetail`, so the recorded decision was not in the
 * response at all and the money that moved was not either.
 */
final class FinanceReviewControllerTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('finance');
    }

    // ===== THE SHARED FINANCE QUEUE =====

    /**
     * The queue has to carry the transactions that are actually in it.
     *
     * The status is `SENIOR_APPROVED` rather than the `AWAITING_FINANCE_REVIEW` the enum also
     * offers, because a senior *approval* is what routes a transaction to finance and
     * `findForFinanceQueue()` selects the two senior outcomes. A fixture at the status nothing
     * produces would have tested a state the application cannot reach.
     */
    public function testFinanceQueueListsTheTransactionsAwaitingFinanceReview(): void
    {
        $items = $this->allQueueItems();

        $byId = $this->byId($items);
        $this->assertArrayHasKey(ContractFixtures::transactionId(), $byId, 'the senior-approved transaction awaits finance');

        $item = $byId[ContractFixtures::transactionId()];
        $this->assertSame('FINANCE', $item['stage']);
        $this->assertSame('SENIOR_APPROVED', $item['status']);
        $this->assertNull($item['finalOutcome'], 'nothing is settled until finance decides');
    }

    /**
     * FR-034: the finance queue is shared. A transaction in a cost center the caller does not
     * manage belongs in it.
     *
     * The acting user manages CC-100, so a queue scoped to the caller's own cost centers would
     * return the primary row and the rejected row and look correct. Only the presence of CC-200
     * distinguishes the two, which is why the fixture seeds it and why the count is asserted
     * rather than just the membership.
     */
    public function testTheFinanceQueueIsNotScopedToTheCallersCostCenters(): void
    {
        $items = $this->allQueueItems();

        $byId = $this->byId($items);
        $this->assertArrayHasKey(
            ContractFixtures::unmanagedFinanceTransactionId(),
            $byId,
            'finance decides the whole company\'s queue, not only the cost centers it manages'
        );
        $this->assertSame(
            'CC-200',
            $byId[ContractFixtures::unmanagedFinanceTransactionId()]['activeRevision']['costCenter']['code'] ?? null,
            'the unlisted row is the one in the unmanaged cost center'
        );
        // The three seeded rows must all be present; the total is a lower
        // bound, not an exact count, because the queue is shared (FR-006):
        // other users' finance-stage transactions from earlier runs
        // legitimately appear here too. A queue scoped to the caller's own
        // cost centers would return two rows and drop CC-200, which the
        // assertions above still catch.
        foreach ([ContractFixtures::transactionId(), ContractFixtures::unmanagedFinanceTransactionId(), ContractFixtures::seniorRejectedFinanceTransactionId()] as $seeded) {
            $this->assertArrayHasKey($seeded, $byId, 'every seeded finance-stage transaction must be in the shared queue');
        }
        $this->assertGreaterThanOrEqual(3, count($items), 'the three seeded rows must all be present');
    }

    /**
     * FR-037 routes senior-rejected transactions to finance as well, so the queue has to show
     * them; `findForFinanceQueue()` selects both senior outcomes for exactly this reason.
     */
    public function testTheFinanceQueueAlsoCarriesSeniorRejectedTransactions(): void
    {
        $byId = $this->byId($this->allQueueItems());

        $this->assertArrayHasKey(
            ContractFixtures::seniorRejectedFinanceTransactionId(),
            $byId,
            'a senior-rejected transaction is still finance\'s to act on'
        );
        $this->assertSame(
            'SENIOR_REJECTED',
            $byId[ContractFixtures::seniorRejectedFinanceTransactionId()]['status']
        );
    }

    /**
     * The whole `TransactionSummary` schema, because the queue is what the finance view renders
     * and a member the view dereferences that the response omits is a blank cell in a live queue.
     */
    public function testFinanceQueueItemsCarryTheTransactionSummarySchema(): void
    {
        $items = $this->queueItems();
        $this->assertNotEmpty($items, 'the finance state seeds transactions awaiting finance review');

        foreach ($items as $item) {
            foreach (['id', 'sheetId', 'activeRevision', 'stage', 'status', 'evidenceCount', 'version'] as $member) {
                $this->assertArrayHasKey($member, $item, "TransactionSummary requires {$member}");
            }
            $this->assertContains($item['stage'], ['DRAFT', 'COST_CENTER', 'CORRECTION', 'SENIOR', 'FINANCE', 'FINAL']);
            $this->assertContains($item['status'], [
                'DRAFT', 'AWAITING_COST_CENTER_REVIEW', 'COST_CENTER_APPROVED', 'COST_CENTER_REJECTED',
                'AWAITING_SENIOR_REVIEW', 'SENIOR_APPROVED', 'SENIOR_REJECTED', 'AWAITING_FINANCE_REVIEW',
                'AWAITING_PURCHASER_CORRECTION', 'COMPLETED_APPROVED', 'COMPLETED_REJECTED',
            ]);
            $this->assertIsInt($item['version']);

            $revision = $item['activeRevision'];
            foreach (['id', 'number', 'expenseDate', 'amount', 'createdBy', 'createdAt'] as $member) {
                $this->assertArrayHasKey($member, $revision, "Revision requires {$member}");
            }
            $this->assertIsInt($revision['amount']['amountMinor'] ?? null);
            $this->assertIsString($revision['amount']['currency'] ?? null);
            $this->assertIsInt($revision['amount']['minorUnit'] ?? null);
        }
    }

    /**
     * FR-036: the senior decision is the last management decision, and finance acts on top of it
     * rather than changing it, so the row has to carry it. It must not present the row as settled
     * — that is the management-decided versus financially-settled distinction the finance view
     * exists to keep visible, and `finalOutcome` is null until finance decides.
     */
    public function testTheQueueCarriesTheSeniorDecisionFinanceIsDecidingOnTopOf(): void
    {
        $byId = $this->byId($this->allQueueItems());
        $item = $byId[ContractFixtures::transactionId()];

        $this->assertIsArray($item['latestDecision'] ?? null, 'the senior decision is on the row');
        $this->assertSame('SENIOR', $item['latestDecision']['stage']);
        $this->assertSame('APPROVE', $item['latestDecision']['decision']);
        $this->assertNull($item['finalOutcome'], 'the money has not moved yet');
    }

    /**
     * Paging is real: the finance view offers navigation, so a page request must actually narrow
     * the result while `total` keeps reporting the whole shared queue.
     */
    public function testFinanceQueuePagingNarrowsThePageAndTotalCountsTheQueue(): void
    {
        // Paging is computed from the live total, not from a fixed count of
        // three: the queue is shared, so earlier runs' finance-stage rows are
        // legitimately present. What must hold at any total is that pages
        // narrow, total counts the queue rather than the page, and pages
        // neither repeat nor drop rows.
        $all = $this->queue('finance', '?page=1&pageSize=100');
        $total = $all['total'];
        $this->assertGreaterThan(2, $total, 'the finance fixture seeds at least three rows');
        $this->assertCount($total, $all['items']);

        $first = $this->queue('finance', '?page=1&pageSize=2');
        $this->assertCount(min(2, $total), $first['items']);
        $this->assertSame(1, $first['page']);
        $this->assertSame(2, $first['pageSize']);
        $this->assertSame($total, $first['total'], 'total counts the queue, not the page');

        $second = $this->queue('finance', '?page=2&pageSize=2');
        $this->assertCount(min(2, max(0, $total - 2)), $second['items']);
        $seen = [$first['items'][0]['id']];
        $this->assertNotContains(
            $second['items'][0]['id'],
            $seen,
            'the second page must not repeat the first'
        );

        $union = [];
        $pages = (int) ceil($total / 2);
        for ($page = 1; $page <= $pages; $page++) {
            $slice = $this->queue('finance', "?page={$page}&pageSize=2");
            $this->assertSame($total, $slice['total'], "page {$page} must report the whole queue's total");
            foreach ($slice['items'] as $item) {
                $union[] = $item['id'];
            }
        }
        $this->assertCount($total, array_unique($union), 'every page walked must cover the queue exactly once');
    }

    /**
     * A page past the end is an empty success, not an error: the queue is a live view, so a
     * refresh can land after another officer has taken the last row.
     */
    public function testAPageBeyondTheEndOfTheFinanceQueueIsAnEmptySuccess(): void
    {
        $data = $this->queue('finance', '?page=99&pageSize=25');

        $this->assertSame([], $data['items']);
        $this->assertGreaterThan(0, $data['total']);
    }

    /**
     * The queue is selected by stage, not by role alone.
     *
     * The acting user holds the finance role from the moment the `finance` state has ever been
     * seeded — the role assignment is never revoked — so the emptiness here cannot be explained
     * by a missing role. A queue that returned every transaction the officer can see, or that
     * ignored the stage column, would return the cost-centre-review fixture instead.
     */
    public function testTransactionsAtOtherStagesAreNotInTheFinanceQueue(): void
    {
        ContractFixtures::prepare('submitted');

        $data = $this->queue();

        // Relative, not absolute: the queue is shared, so other users'
        // finance-stage rows from earlier runs are legitimately present and a
        // global emptiness assertion would fail on correct work. What this
        // test owns is that the submitted-state fixture transaction, which is
        // awaiting cost-centre review, is absent from finance's queue. The
        // whole queue is walked: a page-one absence proves nothing once the
        // shared queue holds more rows than one page.
        $byId = $this->byId($this->allQueueItems());
        $this->assertArrayNotHasKey(
            ContractFixtures::transactionId(),
            $byId,
            'a transaction awaiting cost-centre review is not finance\'s'
        );
    }

    // ===== THE FINANCE DECISION RESPONSE =====

    /**
     * The contract answers a decision with `DecisionResult`: the decision, the transaction it was
     * recorded on, and any account effect. A bare transaction detail answers 201 and satisfies an
     * envelope assertion while omitting the decision that was just taken — and the frontend
     * `reviewsStore` reads `result.transaction`, so it dereferenced `undefined` on every decision.
     */
    public function testFinanceApprovalAnswersWithTheDecisionResult(): void
    {
        $data = $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion());

        $this->assertArrayHasKey('decision', $data, 'DecisionResult requires the decision that was recorded');
        $this->assertArrayHasKey('transaction', $data, 'DecisionResult requires the transaction it was recorded on');

        foreach (['id', 'stage', 'decision', 'actor', 'decidedAt'] as $member) {
            $this->assertArrayHasKey($member, $data['decision'], "Decision requires {$member}");
        }
        $this->assertSame('FINANCE', $data['decision']['stage']);
        $this->assertSame('APPROVE', $data['decision']['decision']);
        $this->assertSame('FINANCE', $data['decision']['actor']['role'] ?? null, 'the acting role is recorded, not inferred by the caller');
        $this->assertNotEmpty($data['decision']['actor']['userId'] ?? null);
        $this->assertNotEmpty($data['decision']['decidedAt'] ?? null);

        $this->assertSame(ContractFixtures::transactionId(), $data['transaction']['id'] ?? null);
        $this->assertArrayHasKey('allowedActions', $data['transaction'], 'TransactionDetail carries the capability set');
    }

    /**
     * The decision in the response is the one that was stored, not a copy of the request.
     *
     * A wrapper that echoed the request body's `decision` would satisfy the assertion above for
     * every request, including a rejected one. The stored id is the only thing that ties the
     * response to the history, so it has to appear in the transaction's own decision list.
     */
    public function testTheDecisionInTheResultIsTheOneThatWasRecorded(): void
    {
        $data = $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion());

        $ids = array_column($data['transaction']['decisions'] ?? [], 'id');
        $this->assertContains(
            $data['decision']['id'],
            $ids,
            'the decision reported is in the transaction\'s own history'
        );

        $seniorIds = array_values(array_filter(
            $data['transaction']['decisions'] ?? [],
            static fn (array $d): bool => $d['stage'] === 'SENIOR'
        ));
        $this->assertNotEmpty($seniorIds, 'the management decisions seeded behind it are still there');
    }

    /**
     * FR-040: a finance approval records one consumption against the petty-cash account, and the
     * response has to say so — `AccountMutationResult` is the only place the account effect is
     * reported, and without it the caller has to re-read the ledger to learn that money moved.
     */
    public function testFinanceApprovalReportsTheAccountMutationItCaused(): void
    {
        $data = $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion());

        $this->assertArrayHasKey('accountMutation', $data, 'a finance approval moves money and must report it');
        $mutation = $data['accountMutation'];

        $this->assertArrayHasKey('account', $mutation);
        $this->assertArrayHasKey('movement', $mutation);
        $this->assertSame(ContractFixtures::accountId(), $mutation['account']['id'] ?? null);
        $this->assertSame(
            100000000 - 100000,
            $mutation['account']['balance']['amountMinor'] ?? null,
            'the account is left at the opening balance less the settled expense'
        );

        $this->assertSame('CONSUMPTION', $mutation['movement']['type'] ?? null);
        $this->assertSame(100000, $mutation['movement']['amount']['amountMinor'] ?? null);
        $this->assertSame(
            100000000 - 100000,
            $mutation['movement']['balanceAfter']['amountMinor'] ?? null
        );
        $this->assertSame(
            ContractFixtures::revisionId(),
            $mutation['movement']['sourceRevisionId'] ?? null,
            'the consumption names the revision that justified it'
        );
        $this->assertNotEmpty($mutation['movement']['occurredAt'] ?? null);
    }

    /**
     * The mutation reported and the ledger written are the same row, not two claims about it.
     */
    public function testTheReportedMovementIsTheOneOnTheLedger(): void
    {
        $data = $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion());

        $ledger = $this->consumptions();
        $this->assertArrayHasKey(
            $data['accountMutation']['movement']['id'],
            $ledger,
            'the movement the response reports is the one the ledger read back; ledger holds '
            . implode(', ', array_keys($ledger))
        );
    }

    /**
     * Exactly one consumption, for exactly the revision's amount, and the account balance falls by
     * exactly that much. FR-040 says one; the unique `source_revision_id` index is what makes it
     * one rather than one by luck, and a second row would make the ledger unreconcilable.
     */
    public function testFinanceApprovalLeavesExactlyOneConsumptionOnTheLedger(): void
    {
        $before = $this->consumptions();

        $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion());

        $after = $this->consumptions();
        $this->assertCount(
            count($before) + 1,
            $after,
            'an approval records one consumption, not one per read of the response'
        );

        $new = array_values(array_diff(array_keys($after), array_keys($before)));
        $this->assertCount(1, $new);
        $this->assertSame(100000, $after[$new[0]]['amount']['amountMinor']);
        $this->assertSame(
            $before[ContractFixtures::consumptionMovementId()]['balanceAfter']['amountMinor'] - 100000,
            $after[$new[0]]['balanceAfter']['amountMinor'],
            'the balance after the settlement is the one before it, less the expense'
        );
    }

    /**
     * FR-043: the transaction and the sheet it belongs to both reach a final state on the same
     * settlement. The sheet's own projection is recomputed by the settlement, so a sheet left at
     * `FINANCE` after its last transaction settled is a status the dashboard and the list would
     * both report as still in progress.
     */
    public function testFinanceApprovalFinalisesTheTransactionAndItsSheet(): void
    {
        $data = $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion());

        $this->assertSame('FINAL', $data['transaction']['stage'] ?? null);
        $this->assertSame('COMPLETED_APPROVED', $data['transaction']['status'] ?? null);
        $this->assertSame('APPROVED', $data['transaction']['finalOutcome'] ?? null);

        $sheet = $this->sheet();
        $this->assertSame('COMPLETED', $sheet['status'] ?? null, 'a sheet whose last transaction settled is complete');
    }

    /**
     * The capabilities the response reports are the ones the command path enforces. Once the
     * transaction is settled there is nothing left to decide, so offering the actions again is how
     * a finance officer ends up looking at a queue full of buttons that all answer 422.
     */
    public function testASettledTransactionOffersNoFurtherDecisions(): void
    {
        $data = $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion());

        $this->assertFalse($data['transaction']['allowedActions']['canApprove'] ?? true);
        $this->assertFalse($data['transaction']['allowedActions']['canReject'] ?? true);
        $this->assertFalse($data['transaction']['allowedActions']['canReturn'] ?? true);
    }

    /**
     * FR-034 applies to the decision path as well as to the read: finance settles the whole
     * shared queue, so a transaction in a cost center it does not manage is decidable.
     */
    public function testFinanceMaySettleATransactionInACostCenterItDoesNotManage(): void
    {
        $data = $this->decide(
            ContractFixtures::unmanagedFinanceTransactionId(),
            'APPROVE',
            null,
            ContractFixtures::unmanagedFinanceTransactionVersion()
        );

        $this->assertSame('COMPLETED_APPROVED', $data['transaction']['status'] ?? null);
        $this->assertSame(60000, $data['accountMutation']['movement']['amount']['amountMinor'] ?? null);
    }

    /**
     * FR-039: finance may reject or return a senior-rejected transaction but must not approve it.
     *
     * 422 rather than 403: the actor is a finance officer and the queue is shared, so nothing about
     * the caller's authority is wrong — the decision is not one this transaction's state permits.
     */
    public function testFinanceCannotApproveASeniorRejectedTransaction(): void
    {
        $response = $this->postDecision(
            ContractFixtures::seniorRejectedFinanceTransactionId(),
            'APPROVE',
            null,
            ContractFixtures::seniorRejectedFinanceTransactionVersion()
        );

        $this->assertEquals(422, $response->getStatusCode(), 'FR-039 forbids approving a senior-rejected transaction');
        $this->assertStringContainsString(
            'APPROVE',
            $this->errorMessage($response),
            'the refusal names the decision that was refused'
        );
    }

    /**
     * The permitted half of the same row, asserted next to the refusal so a policy that denies
     * everything cannot pass the test above.
     */
    public function testFinanceCanRejectASeniorRejectedTransactionWithAnExplanation(): void
    {
        $data = $this->decide(
            ContractFixtures::seniorRejectedFinanceTransactionId(),
            'REJECT',
            'Not a reimbursable expense',
            ContractFixtures::seniorRejectedFinanceTransactionVersion()
        );

        $this->assertSame('REJECT', $data['decision']['decision'] ?? null);
        $this->assertSame('COMPLETED_REJECTED', $data['transaction']['status'] ?? null);
        $this->assertSame('REJECTED', $data['transaction']['finalOutcome'] ?? null);
    }

    /**
     * A rejected expense has no balance effect. The ledger assertion is the point: an approval that
     * spent nothing and a rejection that spent something are indistinguishable in the response
     * otherwise.
     */
    public function testARejectedFinanceDecisionMovesNoMoney(): void
    {
        $before = $this->consumptions();

        $this->decide(
            ContractFixtures::seniorRejectedFinanceTransactionId(),
            'REJECT',
            'Not a reimbursable expense',
            ContractFixtures::seniorRejectedFinanceTransactionVersion()
        );

        $after = $this->consumptions();
        $this->assertSame(
            array_keys($before),
            array_keys($after),
            'rejecting an expense records no consumption'
        );
        $this->assertSame(100000000, $this->accountBalance(), 'and leaves the account balance alone');
    }

    /**
     * A reject or return without a reason is not actionable, so it is refused with the field
     * error attached — the caller has to know which field to fill in.
     */
    public function testARejectOrReturnWithoutAnExplanationIsRefused(): void
    {
        foreach (['REJECT', 'RETURN'] as $decision) {
            $response = $this->postDecision(
                ContractFixtures::transactionId(),
                $decision,
                null,
                ContractFixtures::transactionVersion()
            );

            $this->assertEquals(422, $response->getStatusCode(), "{$decision} without an explanation must be refused");
            $body = json_decode((string) $response->getBody(), true);
            $this->assertArrayHasKey(
                'explanation',
                $body['ocs']['data']['error']['fieldErrors'] ?? null,
                "the {$decision} refusal names the field to fill in"
            );
        }
    }

    /**
     * A retried command must not spend the money twice.
     *
     * The caller's command id is the idempotency key, so a replay — a client retrying after a
     * timeout, an officer double-clicking — is answered from the stored result without running
     * the settlement again. A second consumption would leave a ledger that does not reconcile.
     */
    public function testReplayingAFinanceApprovalDoesNotMoveTheMoneyTwice(): void
    {
        $commandId = ContractFixtures::commandId();
        $before = $this->consumptions();

        $first = $this->postDecision(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion(), $commandId);
        $second = $this->postDecision(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion(), $commandId);

        $this->assertEquals(201, $first->getStatusCode());
        $this->assertEquals(201, $second->getStatusCode(), 'a replay is answered, not refused: ' . $this->errorMessage($second));

        $after = $this->consumptions();
        $this->assertCount(count($before) + 1, $after, 'the replay did not record a second consumption');

        $firstDecision = json_decode((string) $first->getBody(), true)['ocs']['data']['decision']['id'] ?? null;
        $secondDecision = json_decode((string) $second->getBody(), true)['ocs']['data']['decision']['id'] ?? null;
        $this->assertSame($firstDecision, $secondDecision, 'both responses report the one decision that was recorded');
    }

    /**
     * Both responses report the account effect, not only the first.
     *
     * A client that retried after a timeout is the case this is about: it holds no first response, so
     * a replay without `accountMutation` tells it that the approval happened and not that money moved,
     * and the only way to learn the rest is to go and read the ledger. The stored command result used
     * to hold only the transaction id, which is why the first response had it and the second did not.
     *
     * The mutation must be the *same* one — the same movement id and the same resulting balance — and
     * not merely present, because a replay that reported a fresh zero-valued movement would be worse
     * than one that reported nothing.
     */
    public function testAReplayedFinanceApprovalReportsTheSameAccountMutation(): void
    {
        $commandId = ContractFixtures::commandId();

        $first = $this->data($this->postDecision(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion(), $commandId));
        $second = $this->data($this->postDecision(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion(), $commandId));

        $this->assertArrayHasKey(
            'accountMutation',
            $second,
            'a replay reports the money that moved, not only that the decision was recorded'
        );
        $this->assertSame(
            $first['accountMutation']['movement']['id'] ?? null,
            $second['accountMutation']['movement']['id'] ?? null,
            'it is the one movement the first attempt wrote'
        );
        $this->assertSame(
            $first['accountMutation']['account']['balance']['amountMinor'] ?? null,
            $second['accountMutation']['account']['balance']['amountMinor'] ?? null,
            'and the balance it left, not a balance recomputed as if it were about to be spent again'
        );
    }

    /**
     * A replay of a decision that moved no money still reports no account effect, rather than
     * attaching whichever consumption happened to share the command id.
     */
    public function testAReplayedRejectionReportsNoAccountMutation(): void
    {
        $commandId = ContractFixtures::commandId();

        $first = $this->data($this->postDecision(ContractFixtures::transactionId(), 'REJECT', 'Not reimbursable', ContractFixtures::transactionVersion(), $commandId));
        $second = $this->data($this->postDecision(ContractFixtures::transactionId(), 'REJECT', 'Not reimbursable', ContractFixtures::transactionVersion(), $commandId));

        $this->assertArrayNotHasKey('accountMutation', $first, 'a rejection moves no money');
        $this->assertArrayNotHasKey(
            'accountMutation',
            $second,
            'and a replay of it must not invent one'
        );
    }

    // ===== HELPERS =====

    /** @return array<string, mixed> */
    private function queue(string $stage = 'finance', string $query = ''): array
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/queues/' . $stage . $query);
        $this->assertEquals(200, $response->getStatusCode(), "GET /queues/{$stage} must answer 200");

        return $this->data($response);
    }

    /** @return list<array<string, mixed>> */
    private function queueItems(string $query = ''): array
    {
        return $this->queue('finance', $query)['items'] ?? [];
    }

    /**
     * Every row of the shared finance queue, across pages.
     *
     * Membership assertions must not read page one alone: the queue is
     * shared, so earlier runs' finance-stage rows legitimately push fixtures
     * beyond the default page. Walks pageSize=100 windows until a short
     * page; a queue that never ends would fail here rather than hang,
     * because the walk is bounded by the reported total.
     *
     * @return list<array<string, mixed>>
     */
    private function allQueueItems(): array
    {
        $items = [];
        $page = 1;
        for (;;) {
            $data = $this->queue('finance', "?page={$page}&pageSize=100");
            foreach ($data['items'] ?? [] as $item) {
                $items[] = $item;
            }
            if (count($data['items'] ?? []) < 100) {
                return $items;
            }
            $page++;
            if ($page > 100) {
                self::fail('the finance queue walk did not terminate');
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $items): array
    {
        $byId = [];
        foreach ($items as $item) {
            $byId[$item['id']] = $item;
        }

        return $byId;
    }

    /**
     * @return array<string, mixed>
     */
    private function decide(string $transactionId, string $decision, ?string $explanation, int $version): array
    {
        $response = $this->postDecision($transactionId, $decision, $explanation, $version);
        $this->assertEquals(
            201,
            $response->getStatusCode(),
            'the staged finance decision should be accepted: ' . $this->errorMessage($response)
        );

        return $this->data($response);
    }

    private function postDecision(
        string $transactionId,
        string $decision,
        ?string $explanation,
        int $version,
        ?string $commandId = null
    ): ResponseInterface {
        $payload = ['decision' => $decision, 'expectedVersion' => $version];
        if ($explanation !== null) {
            $payload['explanation'] = $explanation;
        }

        return $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . $transactionId . '/decisions',
            [
                'headers' => ['X-PettyCash-Command-ID' => $commandId ?? ContractFixtures::commandId()],
                'json' => $payload,
            ]
        );
    }

    /**
     * Every movement on the acting user's primary account, keyed by its public id, so a test can
     * diff the ledger across a decision instead of asserting on a row index.
     *
     * @return array<string, array<string, mixed>>
     */
    private function consumptions(): array
    {
        $byId = [];
        foreach ($this->ledgerItems() as $movement) {
            $byId[$movement['id']] = $movement;
        }

        return $byId;
    }

    /** @return list<string> */
    private function ledgerIds(): array
    {
        return array_keys($this->consumptions());
    }

    /** @return list<array<string, mixed>> */
    private function ledgerItems(): array
    {
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/accounts/' . ContractFixtures::accountId() . '/movements?page=1&pageSize=100'
        );
        $this->assertEquals(200, $response->getStatusCode());

        return $this->data($response)['items'] ?? [];
    }

    private function accountBalance(): int
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/accounts?page=1&pageSize=100');
        $this->assertEquals(200, $response->getStatusCode());

        foreach ($this->data($response)['items'] ?? [] as $account) {
            if ($account['id'] === ContractFixtures::accountId()) {
                return $account['balance']['amountMinor'];
            }
        }

        $this->fail('The fixture account is not in the account listing.');
    }

    /** @return array<string, mixed> */
    private function sheet(): array
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $this->assertEquals(200, $response->getStatusCode());

        return $this->data($response);
    }

    /**
     * The `Error` schema's `message`, which is where a refusal states which decision it refused.
     */
    private function errorMessage(ResponseInterface $response): string
    {
        $body = json_decode((string) $response->getBody(), true);

        return $body['ocs']['data']['error']['message'] ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body, 'The response body is JSON');

        return $body['ocs']['data'] ?? [];
    }
}
