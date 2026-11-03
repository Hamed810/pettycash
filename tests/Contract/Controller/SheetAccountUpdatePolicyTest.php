<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * T440 / FR-019 / FR-021: `PATCH /sheets/{sheetId}` is title-only.
 *
 * The account and currency a sheet was created against are immutable. This suite pins the HTTP edge
 * of that policy: a title-only body succeeds and leaves the stored account and currency exactly as
 * they were, while **any** supplied `accountId` — null, blank, the current account, or another owned
 * account — is refused with `422 VALIDATION_FAILED` and `fieldErrors.accountId` before persistence.
 *
 * The account value is deliberately tested in more than one shape. A refusal that only rejected an
 * unknown id, or that treated the current id as "unchanged", would satisfy a narrower test while
 * still allowing a client to believe it had replaced the account. The same expectedVersion is reused
 * across every refused attempt, so an implementation that had mutated the sheet would expose itself
 * as a version or title change on the next read.
 */
final class SheetAccountUpdatePolicyTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

    /**
     * The permissive half: omitting `accountId` entirely is the only valid shape.
     */
    public function testATitleOnlyUpdateSucceedsAndPreservesTheStoredAccountAndCurrency(): void
    {
        $before = $this->sheet();
        $expectedVersion = (int) $before['version'];

        $response = $this->patch('/sheets/' . $this->sheetId(), [
            'title' => 'T440 title-only rename',
            'expectedVersion' => $expectedVersion,
        ]);

        self::assertSame(200, $response->getStatusCode(), 'a title-only PATCH must succeed: ' . (string) $response->getBody());
        $updated = $this->data($response);
        self::assertSame('T440 title-only rename', $updated['title'] ?? null);
        self::assertSame(
            ContractFixtures::accountId(),
            $updated['accountId'] ?? null,
            'the response must still report the stored account; update is not a replacement path'
        );
        self::assertSame('IRR', $updated['currency'] ?? null, 'the sheet currency is immutable with its account');
        self::assertSame(
            $expectedVersion + 1,
            $updated['version'] ?? null,
            'a successful title-only update increments the optimistic-lock version exactly once'
        );

        $stored = $this->sheet();
        self::assertSame('T440 title-only rename', $stored['title'] ?? null);
        self::assertSame(ContractFixtures::accountId(), $stored['accountId'] ?? null);
        self::assertSame('IRR', $stored['currency'] ?? null);
        self::assertSame($expectedVersion + 1, $stored['version'] ?? null, 'the stored read must agree with the response');
    }

    /**
     * Every supplied value is the same refusal, including `null` and the current account.
     *
     * The current and another owned account are both present and resolvable, so an implementation
     * that validated the target account would have accepted them. The point is that no value is a
     * valid target: the field is not part of the update contract at all.
     */
    public function testEverySuppliedAccountIdIsRefusedBeforeAnyPersistence(): void
    {
        $before = $this->sheet();
        $expectedVersion = (int) $before['version'];
        $originalTitle = (string) $before['title'];

        $values = [
            'explicit null' => null,
            'empty string' => '',
            'whitespace' => '   ',
            'the current account' => (string) $before['accountId'],
            'another owned account' => ContractFixtures::secondAccountId(),
        ];

        foreach ($values as $label => $accountId) {
            $response = $this->patch('/sheets/' . $this->sheetId(), [
                'title' => 'T440 must not accept ' . $label,
                'accountId' => $accountId,
                'expectedVersion' => $expectedVersion,
            ]);

            $body = (string) $response->getBody();
            self::assertSame(422, $response->getStatusCode(), "{$label}: a supplied accountId must be refused. Body: {$body}");
            $error = $this->data($response)['error'] ?? [];
            self::assertSame('VALIDATION_FAILED', $error['code'] ?? null, "{$label}: the contract's validation code is required");
            self::assertArrayHasKey('accountId', $error['fieldErrors'] ?? [], "{$label}: the refusal must name the field");
            self::assertNotEmpty($error['fieldErrors']['accountId'] ?? [], "{$label}: the field error must carry a message");

            $after = $this->sheet();
            self::assertSame($originalTitle, $after['title'] ?? null, "{$label}: a refused account change must not persist the title");
            self::assertSame(ContractFixtures::accountId(), $after['accountId'] ?? null, "{$label}: the stored account must not move");
            self::assertSame('IRR', $after['currency'] ?? null, "{$label}: the stored currency must not move");
            self::assertSame($expectedVersion, $after['version'] ?? null, "{$label}: no lock version may be consumed by a refused request");
        }
    }

    /**
     * A title-only update must still work when the stored account has been deactivated.
     *
     * Deactivation is not corruption: the account row and currency still resolve, so FR-083 has
     * nothing to refuse. The title-only policy preserves the inactive account rather than adopting
     * or provisioning another one.
     */
    public function testATitleOnlyUpdateSucceedsWhenTheStoredAccountIsInactive(): void
    {
        ContractFixtures::prepare('deactivated-rial');

        $before = $this->sheet();
        $expectedVersion = (int) $before['version'];

        $response = $this->patch('/sheets/' . $this->sheetId(), [
            'title' => 'T440 inactive-account rename',
            'expectedVersion' => $expectedVersion,
        ]);

        self::assertSame(200, $response->getStatusCode(), 'an inactive but resolvable account must not block a title-only save: ' . (string) $response->getBody());
        $updated = $this->data($response);
        self::assertSame('T440 inactive-account rename', $updated['title'] ?? null);
        self::assertSame(ContractFixtures::accountId(), $updated['accountId'] ?? null, 'the inactive stored account must be preserved');
        self::assertSame('IRR', $updated['currency'] ?? null, 'the currency follows the stored account and stays unchanged');
        self::assertSame($expectedVersion + 1, $updated['version'] ?? null);
    }

    /**
     * An inactive stored account is still not a valid update target.
     *
     * The previous test proves the account can be preserved; this one proves deactivation does not
     * turn the same field into a permitted replacement. Both halves are needed because an
     * implementation that accepted the current account whenever it was inactive would pass the
     * preserve test and still violate the title-only contract.
     */
    public function testTheInactiveStoredAccountIsStillRefusedWhenSupplied(): void
    {
        ContractFixtures::prepare('deactivated-rial');

        $before = $this->sheet();
        $expectedVersion = (int) $before['version'];
        $originalTitle = (string) $before['title'];

        $response = $this->patch('/sheets/' . $this->sheetId(), [
            'title' => 'T440 must not adopt the inactive account',
            'accountId' => ContractFixtures::accountId(),
            'expectedVersion' => $expectedVersion,
        ]);

        $body = (string) $response->getBody();
        self::assertSame(422, $response->getStatusCode(), 'a supplied inactive account must be refused. Body: ' . $body);
        $error = $this->data($response)['error'] ?? [];
        self::assertSame('VALIDATION_FAILED', $error['code'] ?? null);
        self::assertArrayHasKey('accountId', $error['fieldErrors'] ?? [], 'the refusal must name the field');

        $after = $this->sheet();
        self::assertSame($originalTitle, $after['title'] ?? null, 'the refused title must not persist');
        self::assertSame(ContractFixtures::accountId(), $after['accountId'] ?? null, 'the stored inactive account must not move');
        self::assertSame('IRR', $after['currency'] ?? null);
        self::assertSame($expectedVersion, $after['version'] ?? null, 'no lock version may be consumed');
    }

    /**
     * `expectedVersion` is still required, and a missing one persists nothing.
     */
    public function testExpectedVersionRemainsRequiredForATitleOnlyUpdate(): void
    {
        $before = $this->sheet();

        $response = $this->patch('/sheets/' . $this->sheetId(), ['title' => 'T440 no version']);

        self::assertSame(422, $response->getStatusCode());
        $error = $this->data($response)['error'] ?? [];
        self::assertSame('VALIDATION_FAILED', $error['code'] ?? null);
        self::assertArrayHasKey('expectedVersion', $error['fieldErrors'] ?? [], 'the missing version must be named');

        $after = $this->sheet();
        self::assertSame($before['title'] ?? null, $after['title'] ?? null);
        self::assertSame($before['version'] ?? null, $after['version'] ?? null);
        self::assertSame($before['accountId'] ?? null, $after['accountId'] ?? null);
    }

    /** @return array<string, mixed> */
    private function sheet(): array
    {
        $response = $this->client->request('GET', $this->url('/sheets/' . $this->sheetId()));
        self::assertSame(200, $response->getStatusCode(), 'the T440 probe needs a readable fixture sheet');

        return $this->data($response);
    }

    private function sheetId(): string
    {
        return ContractFixtures::sheetId();
    }

    private function patch(string $path, array $body): ResponseInterface
    {
        return $this->client->request('PATCH', $this->url($path), [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => $body,
        ]);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? ($decoded['ocs']['data'] ?? []) : [];
    }

    private function url(string $path): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1' . $path;
    }
}
