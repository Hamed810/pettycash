<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * T340: `SheetSummary.ownerDisplayName` names the owner, and it is the value the sheet detail renders.
 *
 * ## What was wrong
 *
 * `SheetPayloadFormatter::summary()` emitted `'ownerDisplayName' => ''` — a literal, with no lookup and
 * no `UserDirectory` injected — while `SheetDetailView.vue` prints
 * `sheetStore.currentSheet?.ownerDisplayName`. So the Owner row was **blank for every sheet in the
 * application**, and nothing failed because a shape check passes on an empty string.
 *
 * `UserDirectory` was already bound in the app and already used for exactly this: `SessionController::me()`
 * resolves the caller's own display name through it. This was a wiring omission, not a new dependency.
 *
 * ## Why "a sheet owned by another user" is not asserted here
 *
 * `SheetService::getSheet()` returns `null` for a sheet whose `ownerUid` is not the caller, and every
 * fixture sheet belongs to the acting user — so **no sheet owned by somebody else is reachable over
 * HTTP at all**. The criterion's cross-user case is therefore unreachable on the live endpoint, and the
 * assertion that can be made here is the stronger one for what a user sees: the name in a sheet payload
 * is the **same name the session endpoint reports for that user**. Two endpoints answering about one
 * person differently is the defect `ReportScope::forActor()`'s own comment records having been found
 * for `/dashboard` versus `/me`, so the check is written as a cross-endpoint equality rather than as an
 * absolute string.
 */
final class SheetOwnerDisplayNameTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

    public function testTheSheetPayloadNamesTheOwner(): void
    {
        $data = $this->data($this->get('/sheets/' . ContractFixtures::sheetId()));

        $this->assertArrayHasKey('ownerDisplayName', $data);
        $this->assertNotSame(
            '',
            (string) $data['ownerDisplayName'],
            'an Owner row that says nothing is what this task existed to end'
        );
        $this->assertIsString($data['ownerDisplayName']);
    }

    /**
     * The cross-endpoint equality, which is what makes the first case a check rather than a shape test.
     *
     * `GET /me` resolves the caller's display name through the same `UserDirectory` port, so the two
     * endpoints must agree about the same person. A `''` in the sheet payload fails this as surely as
     * the assertion above, and a *wrong* name fails it too.
     */
    public function testTheSheetPayloadAgreesWithTheSessionAboutTheSamePerson(): void
    {
        $sheet = $this->data($this->get('/sheets/' . ContractFixtures::sheetId()));
        $me = $this->data($this->get('/me'));

        $this->assertSame($sheet['ownerId'] ?? null, $me['userId'] ?? null, 'the sheet is owned by the caller');
        $this->assertSame(
            $me['displayName'] ?? null,
            $sheet['ownerDisplayName'] ?? null,
            'two endpoints describing one person must describe them the same way'
        );
    }

    /**
     * The list projection too.
     *
     * It is a different call path from the detail payload (`summaries()` versus `summary()`), so a fix
     * applied to one would leave the other blank — which is what happened for `firstExpenseDate` in T337
     * until the conformance check compared both routes.
     */
    public function testEveryListedSheetNamesItsOwner(): void
    {
        $items = $this->data($this->get('/sheets?page=1&pageSize=50'))['items'] ?? [];
        $this->assertNotEmpty($items, 'the fixture seeds sheets the caller owns');

        foreach ($items as $sheet) {
            $this->assertNotSame(
                '',
                (string) ($sheet['ownerDisplayName'] ?? ''),
                'every listed sheet names its owner'
            );
        }
    }

    // ===== helpers -----------------------------------------------------------

    private function get(string $path): ResponseInterface
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1' . $path);
        $this->assertSame(200, $response->getStatusCode(), $path);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body, 'The response body is JSON');

        $data = $body['ocs']['data'] ?? [];

        return is_array($data) ? $data : [];
    }
}