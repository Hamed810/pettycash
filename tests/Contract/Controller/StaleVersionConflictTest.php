<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * T416: a stale `expectedVersion` answers `409 Conflict`, on every endpoint that takes one.
 *
 * ## What was wrong, and why a green suite could not have found it
 *
 * Eight guards raised `InvalidArgumentException('Stale version')`, and `OcsErrorMapper` maps that
 * one class to `422 VALIDATION_FAILED`. So a stale write was reported to the caller as *invalid
 * input* — the one answer that is actively misleading, because nothing about the request was wrong.
 * The caller's `expectedVersion` was correct when they read it and out of date when they sent it.
 *
 * Three things had to be true for that to survive, and each is worth naming because each is a way
 * this kind of defect hides:
 *
 * 1. **The code contradicted the contract the project had already written.** `contracts/ocs-api.yaml`
 *    declared a `Conflict` response on `PATCH /transactions/{id}`, `DELETE /transactions/{id}`,
 *    `POST /transactions/{id}/decisions` and `POST /sheets/{id}/submit`, and that response's own
 *    description opens with the words *"Stale version, duplicate/conflicting command, invalid state,
 *    or concurrency conflict"*. Nothing asserted that the server honoured it, so a contract that was
 *    correct on paper and wrong in the container passed review.
 * 2. **The application was already inconsistent with itself.** The *configuration* path did this
 *    correctly — `ConfigurationConflict::staleVersion()` and `StaleAccountVersion` both implement
 *    `Conflict` and both answered `409`. Only the Expense and Workflow domains were on the old
 *    exception, so the codebase demonstrated the right pattern and did not follow it.
 * 3. **The one existing stale-version assertion was on the exception *class*, not the status.**
 *    `SheetSubmissionTest` asserted `InvalidArgumentException` — a service-level test with no HTTP
 *    status in it. Changing the class broke it; changing nothing left it green. Neither the class nor
 *    the status was pinned where the status is produced.
 *
 * ## Why this file exists rather than a line in an existing suite
 *
 * The completed-sheet suite (`CompletedSheetLockTest`) was the one place that discussed stale
 * versions at length, and it did so to argue that a *status* assertion was insufficient there. That
 * argument was correct and is still correct for its own guards — they remain `422` — but it is now
 * only true of them. Once a stale version is its own `409`, **status and message together** tell
 * every guard on these endpoints apart, and this file is what makes that true rather than aspirational.
 * It asserts both on every case, which is the assertion that would fail if a guard were reverted to
 * `InvalidArgumentException` by someone who had not read the history.
 *
 * ## Why each endpoint is listed separately instead of data-driven
 *
 * They look like one case, and a data provider would say it in a third of the lines. They are kept
 * separate because they are protected by **different** contracts and **different** requirements:
 * four of these `409` declarations predate this task and four were added with it, and a single
 * provider row cannot distinguish "the contract already promised this" from "this task made the
 * contract promise it". That distinction is the part a reviewer needs to see.
 */
final class StaleVersionConflictTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

    // ===== The four endpoints whose contract already declared a 409 =====

    /**
     * `PATCH /transactions/{id}` — FR-025's own example, and the draft edit it is about.
     *
     * Sent with the transaction's **real** version plus one, so the only thing wrong with the request
     * is that it is a version behind. Sending a missing field instead would be refused by field
     * validation — also a `422`, and indistinguishable from the bug this suite exists to prevent.
     *
     * The command id is required (`contracts/ocs-api.yaml` marks `X-PettyCash-Command-ID`
     * `required: true` on this PATCH, and T317/T318 enforce it). Omitting it answers `400` for a
     * different reason entirely — found by running this test, not by reading the contract — so the
     * header is sent to isolate the version guard.
     */
    public function testAStaleDraftEditIsAConflict(): void
    {
        $response = $this->request(
            'PATCH',
            $this->url('/transactions/' . ContractFixtures::transactionId()),
            [
                'expenseDate' => '2026-09-20',
                'amountMinor' => 4242,
                'expectedVersion' => ContractFixtures::transactionVersion() + 1,
            ],
            ['X-PettyCash-Command-ID' => ContractFixtures::commandId()]
        );

        $this->assertStaleConflict($response, 'TRANSACTION_VERSION_CONFLICT');
    }

    /**
     * `DELETE /transactions/{id}`.
     *
     * No command id, matching the contract's note that deleting a draft row is a *repeatable*
     * command — T318 removed the header from this operation. Sending one would prove nothing about
     * the version guard, since the header is not what makes the version stale.
     */
    public function testAStaleDraftDeletionIsAConflict(): void
    {
        $response = $this->request('DELETE', $this->url('/transactions/' . ContractFixtures::transactionId()), [
            'expectedVersion' => ContractFixtures::transactionVersion() + 1,
        ]);

        $this->assertStaleConflict($response, 'TRANSACTION_VERSION_CONFLICT');
    }

    /**
     * `PATCH /sheets/{sheetId}` — a sheet's own pre-submission fields, which FR-025 names
     * explicitly, so that a reader arriving at the requirement need not know about the transaction
     * path to read it correctly.
     *
     * The contract's `409` on this operation was **added by this task**: the operation already
     * required `ExpectedVersion` and already refused a stale one, but declared no answer for it.
     */
    public function testAStaleSheetEditIsAConflict(): void
    {
        $response = $this->request('PATCH', $this->url('/sheets/' . ContractFixtures::sheetId()), [
            'title' => 'A stale edit',
            'accountId' => ContractFixtures::accountId(),
            'expectedVersion' => ContractFixtures::sheetVersion() + 1,
        ]);

        $this->assertStaleConflict($response, 'SHEET_VERSION_CONFLICT');
    }

    /**
     * `POST /sheets/{sheetId}/submit` — the one endpoint declaring **both** a `409` and a `422`.
     *
     * The pair is the point. This endpoint refuses a sheet that is not open (`422`) and a stale
     * version (`409`), and a suite that asserted only the status could not tell which guard fired.
     * Both messages are asserted, in their own tests, so the two guards cannot be confused for one
     * another again — which is exactly the confusion `CompletedSheetLockTest`'s docblock warns of, and
     * the reason this file asserts a message on every case.
     */
    public function testAStaleSheetSubmissionIsAConflict(): void
    {
        $response = $this->request(
            'POST',
            $this->url('/sheets/' . ContractFixtures::sheetId() . '/submit'),
            ['expectedVersion' => ContractFixtures::sheetVersion() + 1],
            ['X-PettyCash-Command-ID' => ContractFixtures::commandId()]
        );

        $this->assertStaleConflict($response, 'SHEET_VERSION_CONFLICT');
    }

    // ===== The operations whose 409 this task added to the contract =====

    /**
     * `POST /transactions/{id}/correction`.
     *
     * This operation already answered `409` before this task, for a **different** reason: a
     * transaction not awaiting correction raises `NotAwaitingCorrection`, a `Conflict`. So the stale
     * version was the *only* refusal on this endpoint that answered `422`, and the fixture below is
     * chosen so the stale guard is reached first — `CreateCorrectionDraft` checks the version before
     * the status, so an out-of-date version wins over the ordinary refusal.
     */
    public function testAStaleCorrectionDraftIsAConflict(): void
    {
        $response = $this->request(
            'POST',
            $this->url('/transactions/' . ContractFixtures::transactionId() . '/correction'),
            ['expectedVersion' => ContractFixtures::transactionVersion() + 1],
            ['X-PettyCash-Command-ID' => ContractFixtures::commandId()]
        );

        $this->assertStaleConflict($response, 'TRANSACTION_VERSION_CONFLICT');
    }

    /**
     * `POST /transactions/{id}/resubmit`.
     *
     * The contract declares a **required** `ExpectedVersion` body on this operation, so a stale
     * version is reachable by construction and the contract had no stated answer. Its `409` was added
     * with it.
     */
    public function testAStaleResubmissionIsAConflict(): void
    {
        $response = $this->request(
            'POST',
            $this->url('/transactions/' . ContractFixtures::transactionId() . '/resubmit'),
            ['expectedVersion' => ContractFixtures::transactionVersion() + 1],
            ['X-PettyCash-Command-ID' => ContractFixtures::commandId()]
        );

        // Asserted unconditionally rather than conditionally. A conditional
        // `if (409) { assertStaleConflict(...) }` would pass on a `422` and prove nothing, which is
        // the failure mode this whole file was written to eliminate.
        $this->assertStaleConflict($response, 'TRANSACTION_VERSION_CONFLICT');
    }

    // ===== The assertion that makes all of the above falsifiable =====

    /**
     * Status, code, and the caller's next action — all three, every time.
     *
     * The status alone would not have caught this bug: it is what the contract already declared and
     * what the tests already implied, and the server answered `422` regardless. The **code** is what
     * a client can branch on, and it is what distinguishes a stale version from the other `409`s
     * this app raises (`SUPERSEDED_ATTEMPT`, `ACCOUNT_VERSION_CONFLICT`, `*_ALREADY_EXISTS`) without
     * the mapper growing a table of exception classes. The **message** is what carries the recovery,
     * and FR-025 requires the client to be told to reload — a bare "Stale version" gave it nothing
     * to do, which is the second half of why the old answer was wrong.
     */
    private function assertStaleConflict(ResponseInterface $response, string $expectedCode): void
    {
        $body = (string) $response->getBody();

        self::assertSame(
            409,
            $response->getStatusCode(),
            'a stale expectedVersion is a 409 (the caller must reload), not a 422 (the caller must fix '
            . 'a request that was already correct). Body: ' . $body
        );
        self::assertStringContainsString(
            $expectedCode,
            $body,
            'the error code is what a client branches on, and must stay distinct from the other 409s'
        );
        self::assertStringContainsString(
            'Stale version',
            $body,
            'the message must name the condition, so an operator reading a log can tell it from '
            . 'another 409 on the same endpoint'
        );
        self::assertStringContainsString(
            'reload and retry',
            $body,
            'FR-025 requires the client to be told its next action; "Stale version" alone is a dead end'
        );
    }

    private function request(string $method, string $url, array $body, array $headers = []): ResponseInterface
    {
        return $this->client->request($method, $url, [
            'headers' => $headers + ['Content-Type' => 'application/json'],
            'json' => $body,
        ]);
    }

    private function url(string $path): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1' . $path;
    }
}