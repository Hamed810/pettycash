<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * SC-011 and FR-044 over HTTP: a completed sheet is locked for mutation and open for reading.
 *
 * ## What this suite is for, and why a green suite could not have found the gap
 *
 * SC-011 asks that "100% of completed sheets reject attempts" to add, edit and submit. Every guard
 * that answers such an attempt exists and is correct by inspection — `TransactionService::
 * createTransaction()`, `SheetService::updateSheet()`, `SubmitSheet::execute()`,
 * `TransactionService::updateTransaction()`, `TransactionService::deleteTransaction()` and
 * `ExpenseSheet::addTransaction()` all refuse a sheet whose status is not `Open`, and
 * `SheetCapabilities::derive()` denies the same three capabilities for both completed states.
 *
 * What no suite asserted was the other half: that the **endpoint** refuses, rather than the
 * service being bypassed. The capability test exercises `SheetCapabilities::derive()`, a pure
 * function over a status — it would keep passing if `SheetService::updateSheet()` dropped its
 * guard, because nothing routes a request through it. The guards are `status !== Open`, so by
 * construction there is no code path that could pass a `COMPLETED` sheet; and precisely because the
 * code is right, no test failed, so nothing pointed at the missing coverage. This is the shape
 * Phase 55 recorded as "an absence, so there is no failing test to point at it."
 *
 * ## Why the completion is produced rather than seeded
 *
 * The seeder already writes a `COMPLETED` sheet (`ledgerSheet`). Asserting against that would pin
 * this suite to a fixture-only route into the state and prove nothing about how a sheet gets there.
 * So `setUp()` drives both completed sheets through the finance decision path, one decision per
 * sheet, and every test asserts the arrived-at status before asserting the lock. That ordering is
 * what makes the lock meaningful: the state under test is the one the application produces, not one
 * a fixture declared.
 *
 * The two sheets differ in outcome and so in status. `ExpenseSheet::recomputeStatus()` reads "every
 * transaction completed" plus "at least one rejected". Each finance fixture owns a sheet holding
 * exactly one transaction, so approving one yields `COMPLETED` and rejecting the other yields
 * `COMPLETED_WITH_REJECTIONS` — both reachable from one decision, and both covered, because a sheet
 * everyone rejected is locked exactly as hard as an approved one and FR-044 says "completed" without
 * distinguishing them.
 *
 * ## Why the refusals assert the message and not only the status
 *
 * Every guard **here** raises `InvalidArgumentException`, which `OcsErrorMapper` maps to a single
 * `422 VALIDATION_FAILED`. Among themselves, status alone cannot tell these guards apart — a missing
 * field and an unknown cost centre answer identically — so each refusal also asserts the message its
 * guard names, which is what makes this a statement about *this* rule rather than about the endpoint
 * being unfriendly.
 *
 * **T416 narrowed that overlap, and this suite's assertion strategy is now stricter than it needs to
 * be for its own guards, deliberately.** A stale `expectedVersion` used to be one of the
 * indistinguishable `422`s named above; it is now a `409` (`StaleVersionConflictTest` covers it), so
 * the *status* now separates these guards from it. The message assertions are kept anyway: a status
 * assertion distinguishes guards that share a status, and nothing distinguishes guards that share a
 * status *and* a message.
 *
 * The consequence that matters most for review: mutating a guard to accept a completed sheet turns
 * these tests red, whereas the capability test alone would not notice, because it never calls the
 * service.
 */
final class CompletedSheetLockTest extends TestCase
{
    private Client $client;

    /**
     * The sheets `setUp()` completed, named by the outcome that completed them.
     *
     * Held as properties rather than recomputed per test because the settlement is a one-shot
     * command: a second decision on an already-settled transaction is refused, so a helper that
     * settled "on demand" would fail on any test that asked for the sheet twice.
     */
    private string $completedSheetId;

    private string $completedWithRejectionsSheetId;

    private string $settledTransactionId;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('finance');

        $this->settledTransactionId = ContractFixtures::unmanagedFinanceTransactionId();
        $this->completedSheetId = ContractFixtures::financeSharedSheetId();
        $this->completedWithRejectionsSheetId = ContractFixtures::financeRejectedSheetId();

        $this->settle(
            $this->settledTransactionId,
            'APPROVE',
            null,
            ContractFixtures::unmanagedFinanceTransactionVersion()
        );
        $this->settle(
            ContractFixtures::seniorRejectedFinanceTransactionId(),
            'REJECT',
            'Not a reimbursable expense',
            ContractFixtures::seniorRejectedFinanceTransactionVersion()
        );
    }

    // ===== THE STATE UNDER TEST =====

    /**
     * The precondition for the whole suite, asserted over HTTP rather than assumed.
     *
     * Without this the refusals below would be unfalsifiable in the one way that matters: a seeder
     * that failed to reach the finance stage would leave every sheet in review, the guards would
     * fire for the ordinary "submitted" reason, and every lock test in the file would still pass
     * while proving nothing about completion. So the status these tests claim to be about is
     * itself a tested fact, produced by the application's own completion rule.
     */
    public function testSettlingTheLastTransactionCompletesItsSheet(): void
    {
        $this->assertSame(
            'COMPLETED',
            $this->sheet($this->completedSheetId)['status'] ?? null,
            'approving a sheet\'s only transaction completes the sheet'
        );
        $this->assertSame(
            'COMPLETED_APPROVED',
            $this->transaction($this->settledTransactionId)['status'] ?? null
        );
    }

    /**
     * The other terminal outcome, and so the other completed state.
     *
     * Asserted in this suite rather than left as a comment on the test above because
     * `SheetStatus::CompletedWithRejections` is a second value the guards reach through the same
     * `!== Open` comparison. This is the assertion that would fail if that comparison were ever
     * narrowed to `=== Completed` — a lock that admits a sheet everyone rejected.
     */
    public function testRejectingTheLastTransactionCompletesTheSheetWithRejections(): void
    {
        $this->assertSame(
            'COMPLETED_WITH_REJECTIONS',
            $this->sheet($this->completedWithRejectionsSheetId)['status'] ?? null
        );
    }

    // ===== ADD, EDIT AND SUBMIT ARE REFUSED (SC-011) =====

    /**
     * SC-011's "add": `POST /sheets/{id}/transactions` on a completed sheet.
     *
     * The body is deliberately well-formed and would otherwise have been accepted, because the
     * guard fires on the sheet's status before anything else about the transaction is read. A test
     * that omitted the required fields could pass against a `VALIDATION_FAILED` raised by field
     * validation instead, and would then prove nothing about the lock.
     */
    public function testACompletedSheetRefusesToAcceptANewTransaction(): void
    {
        $response = $this->post('/sheets/' . $this->completedSheetId . '/transactions', [
            'expenseDate' => '2026-09-20',
            'amountMinor' => 1000,
        ]);

        $this->assertRefused($response, 'Cannot add transactions to a submitted sheet');
    }

    /**
     * SC-011's "edit", at the sheet: `PATCH /sheets/{id}`.
     *
     * `expectedVersion` is read from the live sheet rather than from the fixture manifest. The
     * settlement incremented the sheet's lock version, and `SheetService::updateSheet()` checks the
     * version *before* the status — so a stale value would answer `Stale version` and the test would
     * pass without the status guard ever running.
     *
     * **T416 changed what that stale answer is, and the reason this reads live is now stronger rather
     * than weaker.** A stale version is a `409` (see `StaleVersionConflictTest`), so the guards here
     * remain the only `422` on this endpoint and a stale value would now answer a *different status*
     * rather than the same status with a different message. Reading live is what keeps this test a
     * statement about the completed-sheet lock instead of about lock ordering.
     */
    public function testACompletedSheetRefusesAnUpdate(): void
    {
        $response = $this->patch('/sheets/' . $this->completedSheetId, [
            'title' => 'Renamed after completion',
            'accountId' => ContractFixtures::accountId(),
            'expectedVersion' => $this->sheetVersion($this->sheet($this->completedSheetId)),
        ]);

        $this->assertRefused($response, 'Only open sheets can be updated');
    }

    /**
     * SC-011's "submit": `POST /sheets/{id}/submit`.
     *
     * Re-submitting a settled sheet is the most tempting of the four, because the payload it needs
     * is the same one that worked minutes earlier — nothing about the request itself signals that
     * it should now be refused.
     */
    public function testACompletedSheetRefusesResubmission(): void
    {
        $response = $this->post('/sheets/' . $this->completedSheetId . '/submit', [
            'expectedVersion' => $this->sheetVersion($this->sheet($this->completedSheetId)),
        ]);

        $this->assertRefused($response, 'Only open sheets can be submitted');
    }

    /**
     * SC-011's "edit", at the transaction: `PATCH /transactions/{id}`.
     *
     * This is the guard whose rule moved. It reads `sheet not open && transaction is not awaiting
     * purchaser correction`, because FR-026's one exception — correcting a returned transaction —
     * lives on the transaction while the check used to live on the sheet. Widening it to let
     * corrections through is what made FR-026 reachable at all, and it is exactly the widening that
     * could have let a settled sheet be edited too; a settled transaction is not awaiting
     * correction, so the guard still refuses.
     */
    public function testASettledTransactionRefusesAnEdit(): void
    {
        $response = $this->patch('/transactions/' . $this->settledTransactionId, [
            // The three fields `TransactionController::update()` requires. Sent in full so the
            // request is refused by the *sheet's* status guard rather than by field validation —
            // `amountMinor` alone answers the same 422 with a different message, and the message
            // assertion below is what tells the two apart.
            'expenseDate' => '2026-09-20',
            'amountMinor' => 4242,
            'expectedVersion' => $this->transactionVersion($this->transaction($this->settledTransactionId)),
        ]);

        $this->assertRefused($response, 'Cannot update transactions in a submitted sheet');
    }

    /**
     * SC-011's third mutation, and the one with a second independent guard behind it: a settled
     * transaction is also not `Draft`. The sheet guard is the one asserted, because it is the one
     * SC-011 is about and it fires first — the draft check would otherwise mask a sheet guard that
     * had been removed.
     *
     * No command id, matching `PurchaserFlowControllerTest::testDeleteTransactionSucceedsWithout
     * ACommandIdHeader()`: deleting a draft is repeatable, so T318 removed the header from this
     * operation rather than leaving a refusal attributable to the header check.
     */
    public function testASettledTransactionRefusesDeletion(): void
    {
        $response = $this->client->request(
            'DELETE',
            $this->url('/transactions/' . $this->settledTransactionId),
            ['json' => ['expectedVersion' => $this->transactionVersion($this->transaction($this->settledTransactionId))]]
        );

        $this->assertRefused($response, 'Cannot delete transactions from a submitted sheet');
    }

    // ===== AND THE PERMISSIVE HALF, WHICH IS THE ONE THAT MAKES THIS SUITE WORTH WRITING =====

    /**
     * FR-044 locks a completed sheet "for viewing and reporting **only**".
     *
     * Nothing asserted that permissive half. A suite of refusals alone passes just as happily
     * against an implementation that broke the read path — and such an implementation would satisfy
     * every refusal in this file perfectly, because a sheet that cannot be read is very much a sheet
     * that cannot be written. So the read is asserted, and asserted on a sheet this suite's own
     * decision completed rather than one seeded as completed.
     */
    public function testACompletedSheetStillAnswersTheDetailRead(): void
    {
        $sheet = $this->sheet($this->completedSheetId);

        $this->assertSame('COMPLETED', $sheet['status'] ?? null);
        $this->assertNotEmpty($sheet['totals'] ?? null, 'a completed sheet still reports its totals');
        $this->assertNotEmpty($sheet['transactions'] ?? null, 'and its settled transactions');
    }

    /**
     * The same permissive half through the list the purchaser actually opens.
     *
     * `SheetController::index()` scopes to the caller and applies no default status filter, so a
     * completed sheet stays listed. Asserted on the row rather than the status code, because a
     * completed sheet that stopped being listed is invisible rather than broken — only the presence
     * of its row can catch that.
     */
    public function testACompletedSheetStillAppearsInTheSheetList(): void
    {
        $ids = array_column($this->pageItems('/sheets?page=1&pageSize=100'), 'id');

        $this->assertContains($this->completedSheetId, $ids, 'a completed sheet stays in the purchaser\'s sheet list');
    }

    /**
     * The same permissive half through the report, which is the other word FR-044 uses.
     *
     * `ReportController::sheetsReport()` reads every sheet the caller owns for a non-administrator
     * and applies no status predicate, so completion does not remove the row. Asserted on both
     * states, since the report is where an operator would look for a settled sheet.
     */
    public function testCompletedSheetsStillAppearInTheSheetsReport(): void
    {
        $ids = array_column($this->reportRows('/reports/sheets?page=1&pageSize=100'), 'id');

        $this->assertContains($this->completedSheetId, $ids, 'an approved completed sheet stays in the sheets report');
        $this->assertContains(
            $this->completedWithRejectionsSheetId,
            $ids,
            'and so does a completed sheet that carries rejections'
        );
    }

    // ===== THE SAME LOCK ON COMPLETED_WITH_REJECTIONS =====

    /**
     * The three write refusals, repeated on the rejected sheet.
     *
     * Written as one test over the three requests rather than three tests because the value here is
     * that the *state* is covered a second time, not that each endpoint is named again — and a
     * failure naming which of the three broke is more useful at a glance than three files to open.
     */
    public function testACompletedWithRejectionsSheetIsLockedAgainstEveryMutation(): void
    {
        $sheetId = $this->completedWithRejectionsSheetId;

        $this->assertRefused(
            $this->post('/sheets/' . $sheetId . '/transactions', [
                'expenseDate' => '2026-09-20',
                'amountMinor' => 1000,
            ]),
            'Cannot add transactions to a submitted sheet',
            'a sheet completed with rejections accepts no new transaction'
        );

        $this->assertRefused(
            $this->patch('/sheets/' . $sheetId, [
                'title' => 'Renamed after completion',
                'accountId' => ContractFixtures::accountId(),
                'expectedVersion' => $this->sheetVersion($this->sheet($sheetId)),
            ]),
            'Only open sheets can be updated',
            'a sheet completed with rejections cannot be updated'
        );

        $this->assertRefused(
            $this->post('/sheets/' . $sheetId . '/submit', [
                'expectedVersion' => $this->sheetVersion($this->sheet($sheetId)),
            ]),
            'Only open sheets can be submitted',
            'a sheet completed with rejections cannot be resubmitted'
        );
    }

    /**
     * The permissive half again, on the second completed state.
     *
     * A rejection is a terminal outcome too, so it must not make a sheet unreadable — the one place
     * where "the money was refused" and "the record is gone" would be easy to conflate.
     */
    public function testACompletedWithRejectionsSheetStillAnswersTheDetailRead(): void
    {
        $sheet = $this->sheet($this->completedWithRejectionsSheetId);

        $this->assertSame('COMPLETED_WITH_REJECTIONS', $sheet['status'] ?? null);
        $this->assertNotEmpty($sheet['transactions'] ?? null, 'the refused expense is still readable on the sheet');
    }

    // ===== HELPERS =====

    /**
     * Record a finance decision, asserting it was accepted.
     *
     * The assertion is the point. If settlement ever started failing, every lock assertion in this
     * file would still pass — a sheet left in review is refused by the very same guards — so this is
     * the only place that notices, and it is why it is in `setUp()` rather than inside each test.
     */
    private function settle(string $transactionId, string $decision, ?string $explanation, int $version): void
    {
        $payload = ['decision' => $decision, 'expectedVersion' => $version];
        if ($explanation !== null) {
            $payload['explanation'] = $explanation;
        }

        $response = $this->client->request(
            'POST',
            $this->url('/transactions/' . $transactionId . '/decisions'),
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => $payload,
            ]
        );

        $this->assertEquals(
            201,
            $response->getStatusCode(),
            'the finance decision that completes the sheet should be accepted: ' . $this->errorMessage($response)
        );
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): ResponseInterface
    {
        return $this->client->request('POST', $this->url($path), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => $body,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function patch(string $path, array $body): ResponseInterface
    {
        return $this->client->request('PATCH', $this->url($path), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => $body,
        ]);
    }

    private function url(string $path): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1' . $path;
    }

    /**
     * A completed sheet refuses a mutation: `422`, the guard's own message, and the contract's
     * `Error` envelope.
     *
     * The message is the load-bearing assertion. `OcsErrorMapper` maps every
     * `InvalidArgumentException` to `422 VALIDATION_FAILED`, so these refusals share a status with
     * every other client error their endpoints can produce; without the message, a guard that had
     * been deleted entirely and a request that failed on an unrelated field would look identical.
     */
    private function assertRefused(ResponseInterface $response, string $expectedMessage, string $context = ''): void
    {
        $suffix = $context === '' ? '' : ' — ' . $context;

        $this->assertEquals(422, $response->getStatusCode(), 'a completed sheet must refuse the mutation' . $suffix);
        $this->assertSame(
            $expectedMessage,
            $this->errorMessage($response),
            'the refusal must come from the completed-sheet guard, not from another validation' . $suffix
        );
        $this->assertSame(
            'VALIDATION_FAILED',
            $this->data($response)['error']['code'] ?? null,
            'and it must carry the contract\'s error code' . $suffix
        );
    }

    /** @return array<string, mixed> */
    private function sheet(string $sheetId): array
    {
        $response = $this->client->request('GET', $this->url('/sheets/' . $sheetId));
        $this->assertEquals(200, $response->getStatusCode(), 'FR-044 keeps a completed sheet readable');

        return $this->data($response);
    }

    /** @return array<string, mixed> */
    private function transaction(string $transactionId): array
    {
        $response = $this->client->request('GET', $this->url('/transactions/' . $transactionId));
        $this->assertEquals(200, $response->getStatusCode(), 'a settled transaction stays readable');

        return $this->data($response);
    }

    /**
     * A record's live optimistic-lock value, read from the record itself.
     *
     * Read live rather than from the fixture manifest because the guards that take an
     * `expectedVersion` check the version *before* the status. A manifest value that had gone stale
     * — the settlement incremented it — would answer `Stale version`, and the suite would pass
     * without ever reaching the guard it exists to test.
     *
     * Since T416 that stale answer is a `409`, so reading live is now also what separates these
     * guards' `422` from the version guard's `409` by status alone — the distinction this helper's
     * per-resource split exists to preserve.
     *
     * Split per resource rather than probing one endpoint and falling back to the other: the probe
     * form costs an extra HTTP round trip on every call and, worse, would silently read a
     * *sheet's* version for a transaction if the first guess ever resolved.
     */
/** @param array<string, mixed> $record */
    private function sheetVersion(array $record): int
    {
        return $this->versionOf($record);
    }

    /** @param array<string, mixed> $record */
    private function transactionVersion(array $record): int
    {
        return $this->versionOf($record);
    }

    /** @param array<string, mixed> $record */
    private function versionOf(array $record): int
    {
        $version = $record['version'] ?? null;
        $this->assertIsInt($version, 'the record must carry its optimistic-lock value');

        return $version;
    }

    /**
     * The `items` of one page of a collection endpoint.
     *
     * @return list<array<string, mixed>>
     */
    private function pageItems(string $pathAndQuery): array
    {
        $response = $this->client->request('GET', $this->url($pathAndQuery));
        $this->assertEquals(200, $response->getStatusCode(), "GET {$pathAndQuery} must answer 200");

        return $this->data($response)['items'] ?? [];
    }

    /**
     * The `rows` of one page of a report.
     *
     * A report page and a collection page are two different shapes and reading one for the other
     * yields an empty list rather than an error — which is how the first version of this suite
     * "passed" the permissive report assertion while asserting nothing at all.
     * `ReportQuery::paginate()` returns the contract's `ReportPage`, whose members are `rows`,
     * `columns` and `summary`; only the REST collections use `items`.
     *
     * @return list<array<string, mixed>>
     */
    private function reportRows(string $pathAndQuery): array
    {
        $response = $this->client->request('GET', $this->url($pathAndQuery));
        $this->assertEquals(200, $response->getStatusCode(), "GET {$pathAndQuery} must answer 200");

        $data = $this->data($response);
        $this->assertArrayHasKey('rows', $data, 'a report page carries rows, not items');

        return $data['rows'];
    }

    private function errorMessage(ResponseInterface $response): string
    {
        return (string) ($this->data($response)['error']['message'] ?? '');
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) ? ($body['ocs']['data'] ?? []) : [];
    }
}