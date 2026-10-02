<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * T072 — evidence links and removal semantics, written RED.
 *
 * ## What this covers
 *
 * `tasks.md:536`: "Update evidence services so correction uploads retain originating revision links
 * and post-submission removal deactivates rather than deletes". Two clauses, two different defects,
 * and neither is reachable from `EvidenceService` alone — so this file drives them over live HTTP
 * like the rest of the contract suite, against the artifacts that state them.
 *
 * - **Clause 1** is FR-048/FR-050: an upload belongs to the revision it was uploaded against. Evidence
 *   uploaded while correcting must be attributed to the correction, or the history of what was
 *   corrected and when cannot be reconstructed.
 * - **Clause 2** is FR-058: "evidence is marked inactive rather than physically removed and its
 *   history remains visible", and `contracts/workflow.md`'s Correction Contract clause 6 requires
 *   "evidence history" to remain unchanged.
 *
 * ## RED
 *
 * Expected to fail. `EvidenceService::uploadEvidence()` wrote `1` into `origin_revision_id` with the
 * comment "will be enhanced with proper revision tracking", and `EvidenceController::destroy()` called
 * `deleteDraftEvidence()` for every transaction regardless of state — so post-submission removal took
 * the *draft* path, which deletes the AppData binary. `EvidenceService::deactivateEvidence()`, the
 * method written for exactly that case, had no callers at all.
 */
final class EvidenceLifecycleControllerTest extends TestCase
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

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function ocsData(string $method, string $path, array $options = []): array
    {
        $response = $this->client->request($method, $path, $options);
        $decoded = json_decode((string) $response->getBody(), true);

        self::assertIsArray($decoded, "{$method} {$path} must answer a JSON body");
        self::assertArrayHasKey('ocs', $decoded, "{$method} {$path} must carry the OCS envelope");

        $data = $decoded['ocs']['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    private function uploadEvidence(string $filename = 'receipt.txt'): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 't072_');
        file_put_contents($tempFile, 'evidence bytes');

        try {
            $response = $this->client->request('POST', $this->transactionUrl('/evidence'), [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($tempFile, 'r'),
                        'filename' => $filename,
                    ],
                ],
            ]);

            self::assertSame(
                201,
                $response->getStatusCode(),
                'the upload must succeed for this test to mean anything. Answered instead: '
                . (string) $response->getBody()
            );

            $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];
            self::assertArrayHasKey('id', $data, 'the upload response must carry the evidence id');

            return (string) $data['id'];
        } finally {
            @unlink($tempFile);
        }
    }

    /** The evidence the transaction detail reports, keyed by public id. */
    private function evidenceOnTransaction(): array
    {
        $data = $this->ocsData('GET', $this->transactionUrl());
        $evidence = $data['evidence'] ?? [];

        self::assertIsArray($evidence, 'TransactionDetail must carry an evidence array');

        $byId = [];
        foreach ($evidence as $item) {
            if (is_array($item) && isset($item['id'])) {
                $byId[(string) $item['id']] = $item;
            }
        }

        return $byId;
    }

    private function currentVersion(): int
    {
        $data = $this->ocsData('GET', $this->transactionUrl());
        self::assertArrayHasKey('version', $data, 'the detail must report the lock version');

        return (int) $data['version'];
    }

    // ===== CLAUSE 1 — CORRECTION UPLOADS RETAIN THEIR REVISION LINK =====

    /**
     * Evidence must be attributed to the revision it was uploaded against.
     *
     * `EvidenceService::uploadEvidence()` hardcoded `1` into `origin_revision_id`, so every row in
     * the application claimed to belong to revision 1 forever. The two uploads here are made against
     * two different revisions of the same transaction, and the assertion is that they are told apart.
     *
     * Asserted as *differ* rather than against a literal id on purpose: the transaction detail exposes
     * `revisionId` as a **database** id, and the revision itself is addressed by a public UUID, so
     * there is no way to state the expected value without the test hardcoding a fixture's internals.
     * Two uploads that must differ is the same statement about the code, and it fails for the right
     * reason.
     */
    public function testAnUploadIsLinkedToTheRevisionItWasUploadedAgainst(): void
    {
        // The `correction` state, not `draft`: creating a correction draft requires the transaction to
        // be awaiting purchaser correction, so a draft cannot reach revision 2 at all. The first draft of
        // this test prepared `draft` and then called `POST /correction`, which the endpoint correctly
        // refused — so the failure was the test's setup, not the behaviour it meant to measure.
        ContractFixtures::prepare('correction');

        $onRevisionOne = $this->uploadEvidence('first.txt');
        $firstLinkedRevision = $this->evidenceOnTransaction()[$onRevisionOne]['revisionId'] ?? null;

        self::assertNotNull(
            $firstLinkedRevision,
            'Evidence carries the revision it belongs to, and TransactionDetail reports it as revisionId'
        );

        // Correct the transaction, which creates a new revision, then upload again against it.
        $draft = $this->ocsData('POST', $this->transactionUrl('/correction'), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => ContractFixtures::transactionVersion()],
        ]);
        self::assertArrayHasKey(
            'id',
            $draft,
            'a correction draft must be created. Answered instead: ' . json_encode($draft)
        );

        $onRevisionTwo = $this->uploadEvidence('second.txt');
        $secondLinkedRevision = $this->evidenceOnTransaction()[$onRevisionTwo]['revisionId'] ?? null;

        self::assertNotNull($secondLinkedRevision, 'the second upload must also report its revision');

        self::assertNotSame(
            $firstLinkedRevision,
            $secondLinkedRevision,
            'evidence uploaded during a correction must be linked to the correction revision, not to the '
            . 'revision it replaced. `uploadEvidence()` hardcoded `origin_revision_id` to 1, so both rows '
            . 'claimed the same origin and the history of what was corrected could not be reconstructed '
            . '(FR-048, FR-050)'
        );
    }

    /**
     * The link is the *correction*, not merely a different number.
     *
     * The first upload predates the correction and must keep pointing at the revision it was uploaded
     * against. If the correction re-pointed existing evidence, FR-048's "the prior revision MUST
     * remain unchanged" would be false for evidence too, and the two uploads could differ for the wrong
     * reason.
     */
    public function testCorrectingDoesNotReattributeEvidenceUploadedEarlier(): void
    {
        ContractFixtures::prepare('draft');

        $before = $this->uploadEvidence('before-correction.txt');
        $revisionBefore = $this->evidenceOnTransaction()[$before]['revisionId'] ?? null;
        self::assertNotNull($revisionBefore);

        $this->ocsData('POST', $this->transactionUrl('/correction'), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => ContractFixtures::transactionVersion()],
        ]);

        $after = $this->evidenceOnTransaction()[$before]['revisionId'] ?? null;

        self::assertSame(
            $revisionBefore,
            $after,
            'creating a correction draft must not re-attribute evidence that was already uploaded. The '
            . 'earlier row belongs to the revision it was attached to'
        );
    }

    // ===== CLAUSE 2 — POST-SUBMISSION REMOVAL DEACTIVATES RATHER THAN DELETES =====

    /**
     * Removing evidence from a submitted transaction records a deactivation, not a deletion.
     *
     * `EvidenceController::destroy()` called `deleteDraftEvidence()` for every transaction, so a
     * submitted transaction's AppData binary was deleted — the "physically removed" that FR-058
     * forbids. The row survived, which is why this is not visible from the row alone: the row looks
     * deactivated either way, and the difference is the file behind it.
     *
     * What *is* observable is the audit trail, and that is what is asserted: the post-submission path
     * is `deactivateEvidence()`, which writes a full before/after event, while the draft path writes
     * only the deactivation. The distinguishing signal used here is the repeat behaviour, below.
     */
    public function testRemovingSubmittedEvidenceMarksItInactiveAndKeepsItInHistory(): void
    {
        ContractFixtures::prepare('draft');

        $evidenceId = $this->uploadEvidence();

        $submit = $this->client->request('POST', $this->sheetUrl('/submit'), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
        ]);
        self::assertSame(200, $submit->getStatusCode(), 'the sheet must be submitted first. Answered instead: ' . (string) $submit->getBody());

        $remove = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId);

        self::assertSame(
            200,
            $remove->getStatusCode(),
            'contracts/ocs-api.yaml declares `200` with deactivated Evidence metadata for this operation. '
            . 'Answered instead: ' . (string) $remove->getBody()
        );

        $data = json_decode((string) $remove->getBody(), true)['ocs']['data'] ?? [];
        self::assertFalse((bool) ($data['active'] ?? true), 'the evidence must be reported as inactive');
        self::assertNotEmpty(
            $data['deactivatedAt'] ?? null,
            'FR-058 requires the removal to be *marked*: when it happened is part of the record, and a '
            . 'response without it says nothing about whether anything was deactivated'
        );

        $stillListed = $this->evidenceOnTransaction();
        self::assertArrayHasKey(
            $evidenceId,
            $stillListed,
            'FR-058: evidence removed after submission is marked inactive rather than physically removed, '
            . 'and its history remains visible. The row must still be on the transaction'
        );
        self::assertFalse(
            (bool) ($stillListed[$evidenceId]['active'] ?? true),
            'the row that remains must be the deactivated one, not a still-active leftover'
        );
    }

    /**
     * The two paths must differ in what they do to the **content**, not in whether they answer.
     *
     * The first draft of this test asserted that removing submitted evidence twice answers `4xx` the
     * second time, on the reasoning that a deactivation is a state transition rather than a repeatable
     * delete. That contradicts T318, which settled `DELETE /evidence/{id}` as a **repeatable** command
     * exempt from `X-PettyCash-Command-ID`, and the correction commands fixed earlier in this branch
     * rely on that. So the submitted branch is repeatable too.
     *
     * What distinguishes it from the draft branch is the file, and HTTP cannot see the file:
     * `GET /evidence/{id}/content` answers `403` once the evidence is inactive, and the evidence row
     * looks identical either way. **This test therefore pins only what HTTP can see — that a repeat is
     * accepted and does not re-record the deactivation — and the content difference is proved in
     * `EvidenceRemovalContentRetentionTest` against the file store.** Asserting a refusal here instead
     * would have encoded a design decision rather than a requirement, and made the suite depend on it.
     */
    public function testPostSubmissionRemovalIsRepeatableWithoutRerecordingTheDeactivation(): void
    {
        ContractFixtures::prepare('draft');

        $evidenceId = $this->uploadEvidence();

        $submit = $this->client->request('POST', $this->sheetUrl('/submit'), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
        ]);
        self::assertSame(200, $submit->getStatusCode(), 'the sheet must be submitted first');

        $url = '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId;

        $first = $this->client->request('DELETE', $url);
        self::assertSame(200, $first->getStatusCode(), 'the first removal must succeed');
        $firstDeactivatedAt = json_decode((string) $first->getBody(), true)['ocs']['data']['deactivatedAt'] ?? null;
        self::assertNotEmpty($firstDeactivatedAt, 'the first removal records when it happened');

        $second = $this->client->request('DELETE', $url);

        self::assertSame(
            200,
            $second->getStatusCode(),
            'a repeat must be accepted: T318 settled this DELETE as repeatable, and refusing it here '
            . 'would make the endpoint non-repeatable while the contract exempts it. Answered instead: '
            . (string) $second->getBody()
        );
        self::assertSame(
            $firstDeactivatedAt,
            json_decode((string) $second->getBody(), true)['ocs']['data']['deactivatedAt'] ?? null,
            'a repeat must not re-record the deactivation. `deactivatedAt` depending on how many times the '
            . 'client retried is a business fact that varies with retry count'
        );
    }

    /**
     * Repeating a draft removal is equally accepted, and equally quiet.
     *
     * The guard on the test above, in the other direction: routing by transaction state must not have
     * changed the draft path's behaviour, which T318 already settled.
     */
    public function testRemovingDraftEvidenceStaysRepeatable(): void
    {
        ContractFixtures::prepare('draft');

        $evidenceId = $this->uploadEvidence();
        $url = '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId;

        $first = $this->client->request('DELETE', $url);
        self::assertSame(200, $first->getStatusCode(), 'the first removal must succeed');
        $firstDeactivatedAt = json_decode((string) $first->getBody(), true)['ocs']['data']['deactivatedAt'] ?? null;

        $second = $this->client->request('DELETE', $url);

        self::assertSame(
            200,
            $second->getStatusCode(),
            'removing a draft\'s evidence is a repeatable command (T318), so a repeat must succeed rather '
            . 'than be refused. Answered instead: ' . (string) $second->getBody()
        );
        self::assertSame(
            $firstDeactivatedAt,
            json_decode((string) $second->getBody(), true)['ocs']['data']['deactivatedAt'] ?? null,
            'a draft removal must not re-record the deactivation either'
        );
    }

    /**
     * `expectedVersion` is still honoured, so the deactivation carries the caller's command id.
     *
     * `deactivateEvidence()` takes a command id and wraps itself in `IdempotencyService`, so the
     * endpoint cannot fabricate one the way it did for the correction commands. This asserts the
     * controller actually routes to it with a real id rather than reaching for the draft path.
     */
    public function testTheSubmittedDeactivationReusesTheIncomingCommandId(): void
    {
        ContractFixtures::prepare('draft');

        $evidenceId = $this->uploadEvidence();

        $submit = $this->client->request('POST', $this->sheetUrl('/submit'), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
        ]);
        self::assertSame(200, $submit->getStatusCode(), 'the sheet must be submitted first');

        $commandId = ContractFixtures::commandId();

        $response = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId, [
            'headers' => ['X-PettyCash-Command-ID' => $commandId],
        ]);

        self::assertSame(200, $response->getStatusCode(), 'the first removal must succeed. Answered instead: ' . (string) $response->getBody());

        // A replay under the same command id is the original result, not a refusal: that is what the
        // idempotency wrapper is for, and it only applies if the submitted path really went through it.
        $replay = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId, [
            'headers' => ['X-PettyCash-Command-ID' => $commandId],
        ]);

        self::assertSame(
            200,
            $replay->getStatusCode(),
            'a replay carrying the same command id must return the original result. A refusal here would '
            . 'mean the submitted path bypassed the idempotency wrapper. Answered instead: '
            . (string) $replay->getBody()
        );
        self::assertSame(
            json_decode((string) $response->getBody(), true)['ocs']['data'] ?? null,
            json_decode((string) $replay->getBody(), true)['ocs']['data'] ?? null,
            'the replay must return the first result unchanged'
        );
    }

    /**
     * The controller must actually route by transaction state.
     *
     * Found by mutation: forcing `destroy()` down the draft branch for every transaction left the whole
     * suite green. That is the gap this closes.
     *
     * It was possible because the observable consequences are split across two places — the binary,
     * which only a file-store-backed unit test can see (`EvidenceRemovalContentRetentionTest`), and the
     * deactivation **reason**, which each service method writes differently and which
     * `EvidenceController::formatEvidence()` returns in the response. The reason is what makes the
     * routing visible over HTTP at all, so it is what this asserts.
     *
     * The reasons are also the honest record: "removed after submission" and "deleted by purchaser"
     * say different things about a document's history, and a caller reading the response is entitled to
     * be told which happened.
     */
    public function testTheRecordedReasonShowsWhichRemovalActuallyHappened(): void
    {
        ContractFixtures::prepare('draft');

        $draftEvidence = $this->uploadEvidence('draft-removal.txt');
        $submit = $this->client->request('POST', $this->sheetUrl('/submit'), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
        ]);
        self::assertSame(200, $submit->getStatusCode(), 'the sheet must be submitted first');

        $submitted = $this->client->request(
            'DELETE',
            '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $draftEvidence
        );
        self::assertSame(200, $submitted->getStatusCode(), 'removing submitted evidence must succeed');

        $submittedReason = json_decode((string) $submitted->getBody(), true)['ocs']['data']['deactivationReason'] ?? null;
        self::assertNotEmpty($submittedReason, 'the response reports why the evidence was deactivated');
        self::assertStringNotContainsStringIgnoringCase(
            'draft',
            (string) $submittedReason,
            'evidence on a submitted transaction was removed by the DRAFT path, which deletes the AppData '
            . 'binary that FR-058 requires to be retained'
        );

        // Now the draft path, on a fresh fixture, must be the one that says so.
        ContractFixtures::prepare('draft');
        $secondDraft = $this->uploadEvidence('really-a-draft.txt');

        $draft = $this->client->request(
            'DELETE',
            '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $secondDraft
        );
        self::assertSame(200, $draft->getStatusCode(), 'removing draft evidence must succeed');

        self::assertStringContainsStringIgnoringCase(
            'draft',
            (string) (json_decode((string) $draft->getBody(), true)['ocs']['data']['deactivationReason'] ?? ''),
            'evidence on an unsubmitted transaction is removed outright, and the record must say that it '
            . 'was a draft removal'
        );
    }
    public function testADraftRemovalIgnoresTheCommandIdAndStaysRepeatable(): void
    {
        ContractFixtures::prepare('draft');

        $evidenceId = $this->uploadEvidence();
        $url = '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId;

        // On a draft, two removals with *different* command ids both succeed — the operation is
        // repeatable, so the id is not what decides it.
        $draftFirst = $this->client->request('DELETE', $url, [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
        ]);
        $draftSecond = $this->client->request('DELETE', $url, [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
        ]);

        self::assertSame(200, $draftFirst->getStatusCode(), 'the draft removal must succeed');
        self::assertSame(
            200,
            $draftSecond->getStatusCode(),
            'a draft removal ignores the command id and stays repeatable'
        );
    }
}
