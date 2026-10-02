<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * T069 — US4 contract tests for the correction path, written RED.
 *
 * ## What this file is for
 *
 * US4 ("Correct a Returned Transaction", spec.md:148-168) is P1, and its whole point is that a
 * correction must not erase the version it replaces. This file asserts that against the live OCS
 * endpoints, over real HTTP, so a client written against `contracts/ocs-api.yaml` is what the tests
 * describe.
 *
 * ## Why these assertions are not the ones already in `PurchaserFlowControllerTest`
 *
 * That file has `testCreateCorrectionReturns201`, `testResubmitCorrectionReturns200`, and
 * `testListRevisionsReturns200` (`:532-576`). All three assert **only a status code and the OCS
 * envelope**. Between them they would stay green if correction produced a revision that was a copy of
 * the old one, if resubmitting left the old revision active, or if history silently truncated. That
 * is the exact failure US4 exists to prevent, and T106 records the same shape of test letting a
 * real defect through on the report surface. So every test here asserts the *state* the correction
 * produced, not merely that a request was accepted.
 *
 * ## Where the assertions come from
 *
 * Each test names its source: `contracts/workflow.md`'s Correction Contract (six numbered clauses),
 * FR-046/048/049/050, and US4's three acceptance scenarios. No test asserts behaviour no artifact
 * states, and none asserts current behaviour that contradicts one.
 *
 * ## RED
 *
 * These tests are expected to fail. They assert specification behaviour the implementation does not
 * yet provide. That is the point of the task — T069 is a test-writing task, and T070-T076 implement
 * against it. A test that passed here would be asserting the defect instead.
 */
final class CorrectionControllerTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
    }

    // ===== HELPERS =====

    private function correctionUrl(): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/correction';
    }

    private function resubmitUrl(): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/resubmit';
    }

    private function revisionsUrl(): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/revisions';
    }

    /**
     * @return array<string, mixed>
     */
    private function ocsData(Client $client, string $method, string $path, array $options = []): array
    {
        $response = $client->request($method, $path, $options);
        $decoded = json_decode((string) $response->getBody(), true);

        self::assertIsArray($decoded, "{$method} {$path} must answer a JSON body");
        self::assertArrayHasKey(
            'ocs',
            $decoded,
            "{$method} {$path} must answer the OCS envelope; a bare body is a dispatcher-level failure "
            . '(see T225), not a business one'
        );

        return is_array($decoded['ocs']['data'] ?? null) ? $decoded['ocs']['data'] : ['__list' => $decoded['ocs']['data'] ?? null];
    }

    /**
     * Create the correction draft and return the created revision.
     *
     * Every test that needs a draft goes through here, so the 201 and its body are asserted once by
     * the test that cares about them and merely arranged by the rest.
     *
     * @return array<string, mixed>
     */
    private function createCorrectionDraft(): array
    {
        $data = $this->ocsData($this->client, 'POST', $this->correctionUrl(), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => ContractFixtures::transactionVersion()],
        ]);

        return $data;
    }

    /**
     * The transaction's current lock version, read back from the detail endpoint.
     *
     * Creating a correction bumps it, so a caller that needs the *current* version has to ask. The
     * tests deliberately do not guess it: a hardcoded version makes a test pass or fail for a reason
     * that has nothing to do with the behaviour it claims to cover.
     */
    private function currentVersion(): int
    {
        $data = $this->ocsData(
            $this->client,
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
        );

        self::assertArrayHasKey('version', $data, 'the transaction detail must report its lock version');

        return (int) $data['version'];
    }

    /** @return list<array<string, mixed>> */
    private function revisions(): array
    {
        $data = $this->ocsData($this->client, 'GET', $this->revisionsUrl());

        // `ocs.data` is a bare array here (the contract declares `Revision[]`, not a page), so it is
        // read through the list wrapper rather than as an object.
        $list = $data['__list'] ?? $data;
        self::assertIsArray($list, 'revisions must answer an array');

        return array_values(array_filter($list, 'is_array'));
    }

    // ===== CORRECTION CREATION (workflow.md clause 3, FR-048) =====

    /**
     * workflow.md clause 3: "The draft begins from the active revision but has a new revision
     * identity." FR-048 says the same from the requirement side.
     *
     * The two halves are asserted separately because they can diverge. A clone that keeps the old
     * `id` overwrites history and passes "begins from the active revision"; a blank draft with a new
     * id loses the business data the purchaser is correcting and passes "new identity". Only the
     * combination is the requirement.
     */
    public function testTheCorrectionDraftGetsANewRevisionIdentityWhileCarryingTheActiveRevisionsData(): void
    {
        ContractFixtures::prepare('correction');

        $before = $this->revisions();
        self::assertNotEmpty($before, 'the correction fixture must seed at least one revision');
        $activeBefore = $this->activeRevision($before);

        $draft = $this->createCorrectionDraft();

        self::assertNotSame(
            $activeBefore['id'] ?? null,
            $draft['id'] ?? null,
            'FR-048: correcting MUST create a new revision. A draft that reuses the active revision\'s '
            . 'id would overwrite the version it replaces and destroy the history US4/AC3 requires to '
            . 'stay viewable'
        );
        self::assertGreaterThan(
            (int) ($activeBefore['number'] ?? 0),
            (int) ($draft['number'] ?? 0),
            'a new revision must carry a higher number than the one it corrects, so ordering and '
            . 'identity cannot be confused'
        );
        self::assertSame(
            $activeBefore['amount'] ?? null,
            $draft['amount'] ?? null,
            'workflow.md clause 3: the draft BEGINS FROM the active revision, so it carries its amount '
            . 'forward. A blank draft discards the business data the purchaser is correcting'
        );
        self::assertSame(
            $activeBefore['expenseDate'] ?? null,
            $draft['expenseDate'] ?? null,
            'the draft inherits the active revision\'s expense date'
        );
    }

    /**
     * workflow.md clause 5: creating a draft must not **activate** it — review does not restart.
     *
     * The first draft of this test asserted that the *previously* active revision stayed active. That
     * was wrong, and the implementation is what corrected it: the draft has to become the
     * transaction's current revision or nothing can reach it, and FR-050 makes the current revision
     * the one that determines the transaction's information. So the draft is current from the moment
     * it is created.
     *
     * What must **not** happen is the other half: no submission. The stage and status stay in
     * correction, and the draft carries no activation of its own. That is the part clause 5 governs
     * and the part that used to be broken — `updateTransaction()` called `activate()` on every edit,
     * so a purchaser's second edit ended correction and routed the expense to a manager.
     */
    public function testCreatingADraftDoesNotSubmitIt(): void
    {
        ContractFixtures::prepare('correction');

        $draft = $this->createCorrectionDraft();

        self::assertFalse(
            (bool) ($draft['active'] ?? true),
            'the draft has not been submitted, so it must not report itself as activated. '
            . 'workflow.md clause 5 puts activation at resubmission, and activating here would route a '
            . 'half-entered revision to a manager'
        );

        $transaction = $this->ocsData(
            $this->client,
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
        );

        self::assertSame(
            'AWAITING_PURCHASER_CORRECTION',
            $transaction['status'] ?? null,
            'creating a draft leaves the transaction awaiting the purchaser. The transaction has not gone '
            . 'back to a manager'
        );
        self::assertNotSame(
            'COST_CENTER',
            $transaction['stage'] ?? null,
            'workflow.md clause 5 routes to cost-center review on resubmission, not on draft creation'
        );
    }

    /**
     * FR-048: "the prior revision MUST remain unchanged and viewable."
     *
     * Captured before the correction and compared field by field afterwards. A whole-object
     * comparison would pass on a revision whose `active` flag legitimately flipped, and would fail
     * on one that merely gained a field — so the immutable business fields are named explicitly and
     * the flag is excluded.
     */
    public function testThePriorRevisionRemainsUnchangedAndViewable(): void
    {
        ContractFixtures::prepare('correction');

        $before = $this->activeRevision($this->revisions());
        $countBefore = count($this->revisions());

        $this->createCorrectionDraft();

        $revisions = $this->revisions();
        $prior = $this->findRevision($revisions, (string) ($before['id'] ?? ''));

        self::assertNotNull(
            $prior,
            'FR-048 and US4/AC3: the prior revision must remain viewable after a correction. Losing it '
            . 'is the failure US4 exists to prevent'
        );

        foreach (['id', 'number', 'expenseDate', 'amount', 'description', 'createdBy', 'createdAt'] as $field) {
            self::assertSame(
                $before[$field] ?? null,
                $prior[$field] ?? null,
                "the prior revision's '{$field}' must be unchanged. FR-048 makes prior revisions "
                . 'immutable, and a correction that rewrote one would falsify the decision history '
                . 'attached to it'
            );
        }

        self::assertCount(
            $countBefore + 1,
            $revisions,
            'a correction ADDS a revision; it must not remove or merge any. US4/AC3 requires every prior '
            . 'revision to remain available'
        );
    }

    /**
     * workflow.md clause 2: "Only the transaction owner may create and edit the correction draft."
     *
     * The refusal is asserted as a status the caller can act on rather than an exact code: the
     * contract declares no `4xx` for this operation at all (`:519-531` lists only `201`), so pinning
     * one number would be asserting something no artifact states. What must hold is that it is
     * refused rather than silently succeeding.
     */
    public function testACorrectionIsRefusedWhenTheTransactionIsNotAwaitingCorrection(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('POST', $this->correctionUrl(), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => ContractFixtures::transactionVersion()],
        ]);

        self::assertGreaterThanOrEqual(
            400,
            $response->getStatusCode(),
            'workflow.md clause 1 puts a transaction in purchaser correction only after a return. A draft '
            . 'transaction is not in that state, so creating a correction draft from it must be refused '
            . 'rather than accepted'
        );

        $this->assertOcsEnvelope($response, 'a refused correction');
    }

    // ===== RESUBMISSION (workflow.md clauses 4 and 5, FR-049) =====

    /**
     * FR-049: "A corrected transaction MUST restart review with its cost center manager."
     *
     * The strongest form of this assertion available over HTTP: after resubmission the transaction
     * must be back at the first management stage and no longer in purchaser correction. Asserting
     * only "not AWAITING_PURCHASER_CORRECTION" would pass for a transaction that had fallen through
     * to a terminal state, which is the opposite of restarting review.
     */
    public function testResubmissionRestartsReviewAtTheCostCenterStage(): void
    {
        ContractFixtures::prepare('correction');

        $this->createCorrectionDraft();

        $response = $this->client->request('POST', $this->resubmitUrl(), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => $this->currentVersion()],
        ]);
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        self::assertSame(
            200,
            $response->getStatusCode(),
            'a complete correction must resubmit. Answered instead: ' . (string) $response->getBody()
        );

        self::assertNotSame(
            'AWAITING_PURCHASER_CORRECTION',
            $data['status'] ?? null,
            'a resubmitted correction leaves purchaser correction'
        );
        self::assertSame(
            'AWAITING_COST_CENTER_REVIEW',
            $data['status'] ?? null,
            'FR-049: review MUST restart with the cost center manager, so the transaction belongs back at '
            . 'the first management stage and not at any other'
        );
    }

    /**
     * workflow.md clause 5: "Activation atomically switches the active revision."
     *
     * Asserted by identity, not by count: after resubmission exactly one revision is active, and it
     * is the draft that was just created. A resubmission that activated the wrong revision would
     * satisfy "one active revision" while leaving the purchaser\'s corrections unused.
     */
    public function testResubmissionActivatesTheDraftAndLeavesEveryOtherRevisionInactive(): void
    {
        ContractFixtures::prepare('correction');

        $draft = $this->createCorrectionDraft();

        $this->ocsData($this->client, 'POST', $this->resubmitUrl(), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => $this->currentVersion()],
        ]);

        $revisions = $this->revisions();
        $active = array_values(array_filter($revisions, static fn (array $r): bool => (bool) ($r['active'] ?? false)));

        self::assertCount(
            1,
            $active,
            'exactly one revision may be active. FR-050 says the active revision determines the '
            . 'transaction\'s current information, so two active revisions make that information ambiguous'
        );
        self::assertSame(
            $draft['id'] ?? null,
            $active[0]['id'] ?? null,
            'the activated revision must be the correction draft, so FR-050\'s "current transaction '
            . 'information" is the information the purchaser corrected'
        );
    }

    /**
     * FR-049 also requires "follow the full management and finance path again", and US4/AC3 requires
     * the prior decisions to survive. Both are asserted together because they are the two halves of
     * the same requirement: restarting review is only safe if the old decisions are preserved rather
     * than being the thing that gets cleared.
     */
    public function testPriorRevisionsSurviveAResubmission(): void
    {
        ContractFixtures::prepare('correction');

        $before = $this->revisions();
        self::assertNotEmpty($before, 'the fixture must seed revisions before a resubmission');

        $this->createCorrectionDraft();
        $this->ocsData($this->client, 'POST', $this->resubmitUrl(), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => $this->currentVersion()],
        ]);

        $after = $this->revisions();

        foreach ($before as $original) {
            $still = $this->findRevision($after, (string) ($original['id'] ?? ''));
            self::assertNotNull(
                $still,
                'US4/AC3 and FR-048: every prior revision must remain viewable after a resubmission. '
                . 'Resubmission activates the new revision; it must not delete the old ones'
            );
            self::assertSame(
                $original['number'] ?? null,
                $still['number'] ?? null,
                'a surviving revision keeps its number, so the sequence stays readable'
            );
        }
    }

    /**
     * workflow.md clause 4: "Resubmission validates the full required dataset and evidence."
     *
     * US4/AC2 has the purchaser change the transaction's business information before resubmitting, so
     * the flow under test is *edit then resubmit*, not resubmit immediately. This drives that path
     * through the ordinary `PATCH` endpoint — the same call the correction form makes — because a
     * resubmission test that never edits proves nothing about correction.
     */
    public function testACorrectedTransactionResubmitsWithItsChanges(): void
    {
        ContractFixtures::prepare('correction');

        $this->createCorrectionDraft();

        $update = $this->client->request(
            'PATCH',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(),
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => [
                    'expenseDate' => '2026-09-27',
                    'amountMinor' => 24680,
                    'description' => 'Corrected expense after return',
                    'expectedVersion' => $this->currentVersion(),
                ],
            ]
        );
        self::assertSame(
            200,
            $update->getStatusCode(),
            'the purchaser must be able to edit the correction draft before resubmitting it (US4/AC2). '
            . 'Answered instead: ' . (string) $update->getBody()
        );

        // The edit must leave the transaction *in* correction.
        //
        // `TransactionService::updateTransaction()` used to call `activate()` unconditionally, which
        // marks the revision submitted, moves the stage to `COST_CENTER`, and sets the status to
        // `AWAITING_COST_CENTER_REVIEW`. On an ordinary draft that is harmless — nothing had been
        // submitted. On a **correction draft** it meant the purchaser's very first edit ended the
        // correction and routed the expense to a manager before they resubmitted anything, which
        // contradicts `workflow.md`'s Correction Contract clause 5 (activation happens at
        // resubmission) and FR-050.
        //
        // This assertion was missing and its absence was found by mutation: reverting the correction
        // branch to plain `activate()` left the whole file green.
        $midEdit = $this->ocsData(
            $this->client,
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
        );

        self::assertSame(
            'AWAITING_PURCHASER_CORRECTION',
            $midEdit['status'] ?? null,
            'editing the correction draft must not submit it. Review restarts at RESUBMISSION '
            . '(workflow.md clause 5, FR-049), not on the first keystroke'
        );
        self::assertNotSame(
            'COST_CENTER',
            $midEdit['stage'] ?? null,
            'the transaction must still be in correction until the purchaser resubmits'
        );

        $this->ocsData($this->client, 'POST', $this->resubmitUrl(), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => $this->currentVersion()],
        ]);

        $active = $this->activeRevision($this->revisions());

        self::assertSame(
            24680,
            (int) (($active['amount']['amountMinor'] ?? null) === null ? PHP_INT_MAX : $active['amount']['amountMinor']),
            'the resubmitted revision must carry the corrected amount. FR-049 sends the transaction '
            . 'through review again, and review of the uncorrected figures would defeat US4 entirely'
        );
        self::assertSame(
            'Corrected expense after return',
            $active['description'] ?? null,
            'the corrected description must survive to the active revision'
        );
    }

    /**
     * workflow.md clause 4: resubmission validates the full required dataset **and evidence**, and a
     * correction that fails it must not reach review.
     *
     * **The case this asserts changed, and the change is the point.** The first draft drove an empty
     * description through `PATCH` and expected a refusal. Measured: none came, because
     * `TransactionRevision::assertSubmittable()` checks `description === null` and an empty string is
     * not null — so that premise was never supported and the test was asserting an inference.
     *
     * Evidence is the half of clause 4 that *is* enforced and that has a real observable consequence,
     * so it is what this drives. Deactivating the transaction's only evidence is a supported move —
     * `DELETE /evidence/{id}` deactivates rather than deletes (FR-058) and T318 made that repeatable
     * — and once no active evidence remains, the correction is incomplete and resubmission must stop.
     *
     * That also pins the evidence predicate from the other side: `data-model.md:248` scopes the
     * requirement to the revision/**transaction**, so evidence carried by the *superseded* revision
     * still counts — and deactivated evidence does not. A predicate that ignored `active` would pass
     * this test while treating withdrawn evidence as if it were still there.
     */
    public function testResubmissionIsRefusedOnceTheTransactionsEvidenceIsDeactivated(): void
    {
        ContractFixtures::prepare('correction');

        $this->createCorrectionDraft();

        $before = $this->activeRevision($this->revisions());

        $deactivate = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . ContractFixtures::evidenceId());
        self::assertSame(
            200,
            $deactivate->getStatusCode(),
            'the fixture must seed evidence that can be deactivated. Answered instead: '
            . (string) $deactivate->getBody()
        );

        $response = $this->client->request('POST', $this->resubmitUrl(), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => $this->currentVersion()],
        ]);

        self::assertGreaterThanOrEqual(
            400,
            $response->getStatusCode(),
            'workflow.md clause 4 requires resubmission to validate evidence. With none active the '
            . 'correction is incomplete and must be refused rather than routed to a manager. Answered '
            . 'instead: ' . (string) $response->getBody()
        );

        $after = $this->activeRevision($this->revisions());
        self::assertSame(
            $before['id'] ?? null,
            $after['id'] ?? null,
            'a refused resubmission must not activate anything. workflow.md clause 5 is atomic: either '
            . 'the revision activates and routes, or nothing changes'
        );
    }

    // ===== THE CORRECTION CONTRACT'S COMMAND-ID REQUIREMENT =====

    /**
     * `contracts/ocs-api.yaml:523-525` declares `X-PettyCash-Command-ID` required on both
     * `POST /correction` and `POST /resubmit`.
     *
     * Both are non-repeatable commands under `workflow.md`'s definition — a replayed resubmission
     * would otherwise activate a revision twice — so unlike the T318 DELETEs the header stays
     * required here. The status is asserted as the repository's established missing-parameter
     * convention (400, per T317 and `ManagementReviewControllerTest`) rather than as whatever the
     * code happens to emit.
     */
    public function testBothCorrectionCommandsRequireACommandId(): void
    {
        ContractFixtures::prepare('correction');

        $correction = $this->client->request('POST', $this->correctionUrl(), [
            'json' => ['expectedVersion' => ContractFixtures::transactionVersion()],
        ]);

        self::assertSame(
            400,
            $correction->getStatusCode(),
            'contracts/ocs-api.yaml declares X-PettyCash-Command-ID required on POST /correction, and a '
            . 'missing required *parameter* is 400 under the convention T317 established — distinct '
            . 'from the 422 used for invalid body fields'
        );
        $this->assertOcsEnvelope($correction, 'a correction missing its command id');

        $resubmit = $this->client->request('POST', $this->resubmitUrl(), [
            'json' => ['expectedVersion' => ContractFixtures::transactionVersion()],
        ]);

        self::assertSame(
            400,
            $resubmit->getStatusCode(),
            'the same header is required on POST /resubmit, and a replayed resubmission is exactly the '
            . 'non-repeatable case workflow.md requires the header to protect'
        );
        $this->assertOcsEnvelope($resubmit, 'a resubmission missing its command id');
    }

    /**
     * A replayed resubmission must not activate a second revision.
     *
     * This is the reason the header exists on this endpoint and the assertion a mere status check
     * cannot make: `resubmitCorrection()` is wrapped in `IdempotencyService`, and if that wrapper
     * were removed the first call would succeed, the replay would fail on its own state check, and
     * the purchaser would be told their correction was lost when it had in fact been applied.
     */
    public function testReplayingTheResubmissionDoesNotActivateASecondRevision(): void
    {
        ContractFixtures::prepare('correction');

        $this->createCorrectionDraft();

        $commandId = ContractFixtures::commandId();
        $version = $this->currentVersion();
        $body = ['expectedVersion' => $version];

        $first = $this->client->request('POST', $this->resubmitUrl(), [
            'headers' => ['X-PettyCash-Command-ID' => $commandId],
            'json' => $body,
        ]);
        self::assertSame(200, $first->getStatusCode(), 'the first resubmission must succeed');

        $countAfterFirst = count($this->revisions());

        $second = $this->client->request('POST', $this->resubmitUrl(), [
            'headers' => ['X-PettyCash-Command-ID' => $commandId],
            'json' => $body,
        ]);

        self::assertSame(
            200,
            $second->getStatusCode(),
            'a replay answers with the original result rather than becoming an error, which is what '
            . 'workflow.md\'s Command Contract promises for non-repeatable commands'
        );

        self::assertCount(
            $countAfterFirst,
            $this->revisions(),
            'a replayed resubmission must not activate or create another revision. FR-049 restarts '
            . 'review once; a second activation would restart it twice'
        );
    }

    // ===== REVISION SCHEMA CONFORMANCE =====

    /**
     * Every revision the endpoint returns must satisfy `contracts/ocs-api.yaml`'s `Revision`
     * schema, whose `required` list is `[id, number, expenseDate, amount, createdBy, createdAt]`.
     *
     * Asserted over the whole history rather than the newest revision, because a formatter that
     * fills the active revision and leaves stored ones incomplete would corrupt the one record a
     * reviewer most needs — the one they are auditing.
     */
    public function testEveryRevisionCarriesTheContractsRequiredMembers(): void
    {
        ContractFixtures::prepare('correction');

        $this->createCorrectionDraft();

        $revisions = $this->revisions();
        self::assertNotEmpty($revisions, 'the fixture must yield revisions to check');

        foreach ($revisions as $revision) {
            foreach (['id', 'number', 'expenseDate', 'amount', 'createdBy', 'createdAt'] as $field) {
                self::assertArrayHasKey(
                    $field,
                    $revision,
                    "contracts/ocs-api.yaml marks '{$field}' as required on Revision. A history entry "
                    . 'missing it cannot be rendered or audited'
                );
                self::assertNotNull(
                    $revision[$field] ?? null,
                    "Revision.'{$field}' must be present, not null. An empty id or createdAt reads as a "
                    . 'real record in a history the user relies on for accountability'
                );
            }
            self::assertIsArray($revision['amount'] ?? null, 'Revision.amount is a Money object');
            self::assertArrayHasKey('amountMinor', $revision['amount'] ?? [], 'Money requires amountMinor');
            self::assertArrayHasKey('currency', $revision['amount'] ?? [], 'Money requires currency');
        }
    }

    // ===== SMALL HELPERS =====

    /**
     * The one revision the transaction currently treats as active.
     *
     * Asserted as **exactly** one rather than "the first that claims to be". `ExpenseTransaction::activate()`
     * marks the incoming revision active without clearing the flag on the one it replaces, so after a
     * resubmission two revisions can carry `active: true` — and a helper that returned the first would
     * silently report the superseded one and make the whole file pass against a broken flow.
     *
     * @param list<array<string, mixed>> $revisions
     * @return array<string, mixed>
     */
    private function activeRevision(array $revisions): array
    {
        $active = array_values(array_filter($revisions, static fn (array $r): bool => (bool) ($r['active'] ?? false)));

        self::assertCount(
            1,
            $active,
            'exactly one revision may be active — FR-050 says the active revision determines the '
            . 'transaction\'s current information, so two of them make that information ambiguous. '
            . 'Revisions seen: ' . json_encode(array_map(
                static fn (array $r): array => [
                    'id' => $r['id'] ?? null,
                    'number' => $r['number'] ?? null,
                    'active' => $r['active'] ?? null,
                    'amountMinor' => $r['amount']['amountMinor'] ?? null,
                ],
                $revisions
            ))
        );

        return $active[0];
    }

    /**
     * @param list<array<string, mixed>> $revisions
     * @return array<string, mixed>|null
     */
    private function findRevision(array $revisions, string $id): ?array
    {
        foreach ($revisions as $revision) {
            if (($revision['id'] ?? null) === $id) {
                return $revision;
            }
        }

        return null;
    }

    private function assertOcsEnvelope(\Psr\Http\Message\ResponseInterface $response, string $what): void
    {
        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray(
            $body,
            "{$what} must answer a JSON body with the `ocs` envelope defined in "
            . 'contracts/ocs-api.yaml. An empty body means the failure happened in the framework '
            . 'before the action ran (T225), which a caller cannot handle'
        );
        self::assertArrayHasKey('ocs', $body, "{$what} must carry the `ocs` envelope");
        self::assertArrayHasKey(
            'error',
            $body['ocs']['data'] ?? [],
            "{$what} must carry the Error payload (`code`, `message`) so a client can report something "
            . 'specific rather than "request failed"'
        );
    }
}
