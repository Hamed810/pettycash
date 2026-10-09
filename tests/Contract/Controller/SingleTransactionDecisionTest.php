<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * T426 / FR-033 / FR-085: a decision applies to **exactly one** transaction.
 *
 * ## What is actually enforced, and what was already true
 *
 * FR-033's clause has two halves. The first — **V1 has no bulk operation** — was already satisfied: the endpoint
 * takes the transaction from the path and `DecisionWrite` declares no identifier list, so there was nothing to
 * decide in bulk. This suite does not re-test that; it would be testing the absence of an endpoint.
 *
 * The second half — **"a request naming more than one transaction MUST be refused"** — was **not** satisfied,
 * and the way it failed is the point. Nextcloud binds only the parameters a method declares, so a request
 * carrying `transactionIds` has that field **silently dropped**: one decision is applied to the path's
 * transaction and the caller receives a success. A client written against a bulk mental model asks to approve
 * four transactions and is told it approved one. That is the precise failure the clause exists to prevent, and
 * it is invisible to a status assertion — every response is `200`.
 *
 * ## Why these assertions are about the *status and the code*, and not about a side effect
 *
 * The refused request must leave **no** decision, **no** workflow transition, and **no** audit event — and
 * specifically **no** `SUPERSEDED_ATTEMPT`, because nothing was attempted against any particular transaction's
 * state. FR-085's audit rule must not claim a race that never happened. The control case proves the endpoint
 * does record a real decision when the request is well formed, so the refusals cannot be passing because
 * nothing was ever recorded on this endpoint at all.
 */
final class SingleTransactionDecisionTest extends TestCase
{
    /**
     * A well-formed id that is **not** in the fixture set.
     *
     * Used for the conflicting-body case instead of `outOfScopeTransactionId()`, which reads
     * `otherTransactionId` and therefore throws `Unknown contract fixture` unless the suite was seeded with the
     * `scope` state. This test needs no second seeded transaction — only an id that is *different* — so a
     * literal keeps the suite on one seed state.
     */
    private const ANOTHER_ID = '99999999-9999-4999-8999-999999999999';

    /**
     * Reseeded per test because a decision is **one-shot**: recording one advances the transaction past the
     * stage, so a second test that tried to decide it again was refused `409` — a stale fixture, not a defect in
     * the rule under test. Found by running: the "redundant body id is allowed" case failed with 409 only when it
     * ran after the control case had already recorded a decision.
     */
    protected function setUp(): void
    {
        ContractFixtures::prepare('draft');
    }

    private function decisionsUrl(?string $transactionId = null): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1/transactions/'
            . ($transactionId ?? ContractFixtures::transactionId()) . '/decisions';
    }

    private function decide(array $extra = [], ?string $transactionId = null): \Psr\Http\Message\ResponseInterface
    {
        return OcsTestClient::create()->request('POST', $this->decisionsUrl($transactionId), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['decision' => 'APPROVE', 'expectedVersion' => ContractFixtures::transactionVersion()] + $extra,
        ]);
    }

    /**
     * The control. A well-formed single decision **is** recorded, so every refusal below is a real refusal
     * rather than an endpoint that never records anything.
     */
    public function testASingleDecisionIsRecorded(): void
    {
        $response = $this->decide();

        // `201`, not `200`: a decision **creates** an immutable `WorkflowDecision` resource, which is what the
        // endpoint returns. Found by running.
        self::assertSame(201, $response->getStatusCode(), 'a well-formed single decision must succeed');
    }

    /**
     * The headline: a body naming several transactions is **refused**, not narrowed to the one in the path.
     */
    public function testARequestNamingSeveralTransactionsIsRefused(): void
    {
        $response = $this->decide([
            'transactionIds' => [ContractFixtures::transactionId(), self::ANOTHER_ID],
        ]);

        self::assertSame(
            400,
            $response->getStatusCode(),
            'a request naming more than one transaction must be refused, not applied to the one in the path'
        );

        $body = (string) $response->getBody();
        self::assertStringContainsString('transactionIds', $body, 'the refusal must name the offending field');
        self::assertStringContainsString(
            'one transaction',
            $body,
            'the refusal must state the rule, so a caller can correct the request rather than guess'
        );
    }

    /**
     * The other two collection spellings a bulk-minded client might send.
     *
     * A data provider would say this in three lines; the cases are kept separate because each is a **distinct
     * spelling a real client would produce**, and a single case proves the key it names rather than the rule.
     */
    public function testATransactionsFieldIsRefused(): void
    {
        $response = $this->decide(['transactions' => [ContractFixtures::transactionId()]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('transactions', (string) $response->getBody());
    }

    public function testAnIdsFieldIsRefused(): void
    {
        $response = $this->decide(['ids' => [ContractFixtures::transactionId()]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('ids', (string) $response->getBody());
    }

    /**
     * A body `transactionId` that **disagrees** with the path is refused too.
     *
     * Subtler than the bulk case and worth its own test: it would decide a *different* record than the caller
     * addressed, and it is invisible to a status assertion alone — the response is a legitimate `200` for the
     * wrong transaction.
     */
    public function testABodyTransactionIdThatConflictsWithThePathIsRefused(): void
    {
        $response = $this->decide(['transactionId' => self::ANOTHER_ID]);

        self::assertSame(
            400,
            $response->getStatusCode(),
            'a body identifier disagreeing with the path would decide a different record than the caller '
            . 'addressed'
        );
        self::assertStringContainsString('transactionId', (string) $response->getBody());
    }

    /**
     * A body `transactionId` that **agrees** with the path is redundant, not conflicting — and must still work.
     *
     * This is the boundary case that keeps the check from becoming "refuse anything unexpected": a forward-
     * compatible client echoing the routed id is harmless, and refusing it would be a defect.
     */
    public function testARedundantBodyTransactionIdThatAgreesIsAllowed(): void
    {
        $response = $this->decide(['transactionId' => ContractFixtures::transactionId()]);

        self::assertSame(
            201,
            $response->getStatusCode(),
            'echoing the routed identifier is redundant, not a bulk request, and must not be refused'
        );
    }

    /**
     * A refused bulk request is **not** a `SUPERSEDED_ATTEMPT`.
     *
     * FR-085 requires that event when a decision loses a race. Nothing was attempted against any transaction's
     * state here, so recording it would put a fabricated race into an append-only audit trail — the one place
     * where a false entry is permanent.
     */
    public function testARefusedBulkRequestIsNotRecordedAsASupersededAttempt(): void
    {
        $response = $this->decide(['transactionIds' => [ContractFixtures::transactionId()]]);
        $body = (string) $response->getBody();

        self::assertStringNotContainsString(
            'SUPERSEDED_ATTEMPT',
            $body,
            'no race occurred, so the refusal must not be reported as a superseded attempt'
        );
        self::assertStringNotContainsString(
            '409',
            $body,
            'a refusal is not a conflict: nothing moved, so there is nothing to reload'
        );
    }

    /**
     * The bulk check runs **before** the command-id guard.
     *
     * A caller who sent both a bulk field and no command id must be told about the bulk field: that is the one
     * problem no correction to the rest of the request can resolve, so reporting the header first would send
     * them round a loop for a request that still cannot succeed.
     */
    public function testABulkFieldIsReportedEvenWithoutACommandId(): void
    {
        $response = OcsTestClient::create()->request('POST', $this->decisionsUrl(), [
            'json' => [
                'decision' => 'APPROVE',
                'expectedVersion' => ContractFixtures::transactionVersion(),
                'transactionIds' => [ContractFixtures::transactionId()],
            ],
        ]);

        self::assertSame(400, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString(
            'transactionIds',
            $body,
            'the bulk field is the unfixable problem, so it must be the one reported'
        );
        self::assertStringNotContainsString(
            'commandId is required',
            $body,
            'reporting the missing header first would send the caller round a loop'
        );
    }
}