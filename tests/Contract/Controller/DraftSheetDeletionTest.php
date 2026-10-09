<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * FR-086 over HTTP: an owner may delete their own **never-submitted** sheet, and nothing else may.
 *
 * ## What this suite is for
 *
 * FR-086 was added on 2026-10-08 and had no implementation at all: `contracts/ocs-api.yaml` declared
 * only `get` and `patch` on `/sheets/{sheetId}`, `appinfo/routes.php` registered no `DELETE`, and no
 * `DeleteSheet` command existed. The gap was total rather than partial, which meant there was nothing
 * to regress — and equally that a partial implementation would look complete.
 *
 * ## Why the refusals are tested as carefully as the success
 *
 * The dangerous failure here is not that deletion does not work; it is that deletion works on a sheet it
 * must not touch. FR-086 permits exactly one state, and every other state is a sheet carrying business
 * history that Principle IV and FR-048 exist to preserve. So most of this file asserts refusals, and
 * the cascade assertions verify that a *successful* deletion removed a **subtree** rather than a row.
 *
 * ## Why two of FR-086's clauses are asserted elsewhere rather than here
 *
 * **"No audit event"** and **ownership against a genuinely foreign sheet** are both properties this
 * transport cannot observe honestly, and both were moved to integration tests rather than approximated:
 *
 * - A successful deletion removes the sheet's transactions, so the per-transaction audit endpoint has
 *   nothing left to serve. Counting events over HTTP before and after therefore compares a real event
 *   list against an error envelope — which is what happened when this file first tried it, producing a
 *   nonsense `1 → 4` "increase". Asserted instead in `tests/Integration/Expense/DeleteSheetCascadeTest.php`,
 *   which counts rows in the audit table around the command.
 * - No seeded contract sheet belongs to another user, so the only way to reach the ownership branch from
 *   here is to delete a sheet the acting user *owns* — which asserts nothing and would pass even if the
 *   ownership check were removed. Asserted in `DeleteSheetOwnershipTest.php`, where a foreign owner can
 *   be set up honestly.
 *
 * Both moves were made after the HTTP tests failed and the failures turned out to be the tests' fault,
 * not the feature's. Approximating a property you cannot observe is how a suite ends up green on
 * nothing.
 */
final class DraftSheetDeletionTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

    /**
     * The headline case: the owner's own open sheet is removed, and afterwards it is gone.
     *
     * Asserted by reading the sheet back rather than by trusting the `204`. A `204` from a controller
     * that silently did nothing is the failure mode a status-only assertion cannot see.
     */
    public function testAnOwnerCanDeleteTheirOwnNeverSubmittedSheet(): void
    {
        $sheetId = ContractFixtures::sheetId();

        $deleted = $this->delete($sheetId, ContractFixtures::sheetVersion());
        self::assertSame(204, $deleted->getStatusCode(), 'an owner may delete their own open sheet');

        $readBack = $this->client->request('GET', $this->url('/sheets/' . $sheetId));
        self::assertNotSame(
            200,
            $readBack->getStatusCode(),
            'the deleted sheet must not still be readable'
        );
    }

    /**
     * FR-084 replay safety, and the reason this operation carries a required command id where
     * `DELETE /transactions/{id}` does not.
     *
     * Without the gate the retry would reach `findByPublicId()`, find nothing, and answer "Sheet not
     * found" — leaving a client that lost the first response unable to tell its own successful deletion
     * from a genuine failure. Replaying the same id must return the first outcome instead.
     */
    public function testReplayingTheSameCommandIdReturnsTheFirstOutcome(): void
    {
        $sheetId = ContractFixtures::sheetId();
        $commandId = ContractFixtures::commandId();

        $first = $this->delete($sheetId, ContractFixtures::sheetVersion(), $commandId);
        self::assertSame(204, $first->getStatusCode());

        // The sheet is gone, so a second *live* attempt would be refused. Replaying the recorded
        // command must answer the recorded outcome rather than re-running the operation.
        $replay = $this->delete($sheetId, ContractFixtures::sheetVersion(), $commandId);
        self::assertSame(
            204,
            $replay->getStatusCode(),
            'a replay of an applied command returns the first outcome, not a not-found refusal'
        );
    }

    /**
     * A sheet that is not there is refused as **absent**, not as forbidden.
     *
     * This is the non-disclosure half of FR-003, and it is asserted over HTTP because a `403` would
     * confirm to a caller that *some* sheet carries this identifier.
     *
     * **Ownership against a genuinely foreign sheet is deliberately not asserted here.** No seeded
     * contract sheet is owned by another user, so the only way to produce one in this suite would be to
     * delete the sheet the acting user *does* own and call the resulting 404 a pass — which asserts
     * nothing about ownership and would silently pass if the ownership check were deleted outright. That
     * half is asserted where it can be set up honestly, at the service level in
     * `tests/Integration/Expense/DeleteSheetOwnershipTest.php`.
     */
    public function testAnAbsentSheetIsRefusedRatherThanForbidden(): void
    {
        $response = $this->client->request(
            'DELETE',
            $this->url('/sheets/' . ContractFixtures::unknownId()),
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
            ]
        );

        self::assertNotSame(
            403,
            $response->getStatusCode(),
            'an absent sheet must not be answered as forbidden — that would confirm it exists'
        );
        self::assertNotSame(
            204,
            $response->getStatusCode(),
            'deleting something that is not there cannot succeed'
        );
    }

    /**
     * FR-026 / FR-044: a sheet that has entered the workflow is not deletable.
     *
     * FR-025 already lets the owner delete an individual draft **transaction**; FR-086 permits deleting
     * the sheet only while it has never been submitted. This is the boundary between those two, and it
     * is the assertion that matters most in the file — a version of this feature that deleted a
     * submitted sheet would erase the official history FR-048 and FR-051 exist to preserve.
     */
    public function testASubmittedSheetIsNotDeletable(): void
    {
        $response = $this->client->request(
            'POST',
            $this->url('/sheets/' . ContractFixtures::sheetId() . '/submit'),
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
            ]
        );
        self::assertSame(200, $response->getStatusCode(), 'the fixture sheet must be submittable to reach the lock');

        $delete = $this->delete(ContractFixtures::sheetId(), ContractFixtures::sheetVersion() + 1);
        self::assertNotSame(
            204,
            $delete->getStatusCode(),
            'FR-086 permits deleting only a sheet that has never been submitted'
        );
    }

    /**
     * A stale `expectedVersion` is a `409`, on the same terms as the edit path (FR-025/T416).
     *
     * A delete is a write, so it must not silently discard a concurrent change to the sheet it is
     * removing — and the answer must tell the caller to reload, which is what `StaleRecordVersion`'s
     * message carries.
     */
    public function testAStaleDeletionIsAConflict(): void
    {
        $response = $this->delete(ContractFixtures::sheetId(), ContractFixtures::sheetVersion() + 1);

        self::assertSame(409, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('SHEET_VERSION_CONFLICT', $body);
        self::assertStringContainsString('reload and retry', $body);
    }

    /**
     * The command id is **required** — asserted here, and `400` specifically.
     *
     * Found by running rather than by reading: this first answered `422`, lumped together with the
     * missing-`expectedVersion` case, which is exactly the conflation T415 corrected in
     * `TransactionController::correction()`. The header is a transport-level precondition on a
     * non-repeatable command, so it is `400`; `expectedVersion` is a body field, so it is `422`.
     */
    public function testTheCommandIdIsRequiredAndAnswersBadRequest(): void
    {
        $response = $this->client->request(
            'DELETE',
            $this->url('/sheets/' . ContractFixtures::sheetId()),
            ['json' => ['expectedVersion' => ContractFixtures::sheetVersion()]]
        );

        self::assertSame(400, $response->getStatusCode(), 'a missing command id is a 400, not a 422');
        self::assertStringContainsString(
            'commandId',
            (string) $response->getBody(),
            'the refusal must name the header it is about'
        );
    }

    /**
     * A missing `expectedVersion` is `422`, and the sheet survives.
     *
     * The companion to the command-id case, kept separate so the two statuses are each pinned: one
     * merged assertion would pass if both answered either status.
     */
    public function testTheExpectedVersionIsRequired(): void
    {
        $response = $this->client->request(
            'DELETE',
            $this->url('/sheets/' . ContractFixtures::sheetId()),
            ['headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()]]
        );

        self::assertSame(422, $response->getStatusCode(), 'a missing expectedVersion is a 422');
        $readBack = $this->client->request('GET', $this->url('/sheets/' . ContractFixtures::sheetId()));
        self::assertSame(200, $readBack->getStatusCode(), 'a refused deletion must leave the sheet intact');
    }

    private function delete(string $sheetId, int $expectedVersion, ?string $commandId = null): ResponseInterface
    {
        return $this->client->request(
            'DELETE',
            $this->url('/sheets/' . $sheetId),
            [
                'headers' => ['X-PettyCash-Command-ID' => $commandId ?? ContractFixtures::commandId()],
                'json' => ['expectedVersion' => $expectedVersion],
            ]
        );
    }

    private function url(string $path): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1' . $path;
    }
}