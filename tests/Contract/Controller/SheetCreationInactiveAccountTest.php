<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * T428 / FR-020: sheet creation against an exists-but-inactive rial account.
 *
 * FR-020's trigger fires on zero **active** accounts, but a deactivated account is
 * not active — so the precondition is met by a record somebody deliberately
 * retired. The carve-out: where a rial account **exists and is inactive**,
 * provisioning MUST NOT fire and the attempt MUST be refused with `422`, naming
 * the inactive account and stating that an administrator must reactivate or
 * replace it.
 *
 * The two cases MUST NOT be collapsed: no rial record at all provisions (see
 * `probe-user-fixture.php`, T390); an inactive rial record refuses (this file).
 *
 * The `deactivated-rial` fixture state retires both of the acting user's accounts,
 * so zero active accounts remain and the refusal names the rial one. Every other
 * state upserts those rows active, so preparing any of them restores the baseline
 * and this state cannot leak into another suite.
 */
final class SheetCreationInactiveAccountTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('deactivated-rial');
    }

    /**
     * An exists-but-inactive rial account refuses sheet creation with `422`,
     * naming the account and the administrator remedy — rather than provisioning
     * a replacement account behind the purchaser's back.
     */
    public function testSheetCreationNamesTheInactiveAccountAndTheRemedy(): void
    {
        // No `accountId`: FR-020 must decide, find zero active accounts with an
        // inactive rial record behind them, and refuse rather than provision.
        $refused = $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets',
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['title' => 'T428 exists-but-inactive sheet'],
            ]
        );

        self::assertSame(422, $refused->getStatusCode(), 'an inactive rial record must refuse, not provision');
        $body = (string) $refused->getBody();
        self::assertStringContainsString(
            ContractFixtures::accountId(),
            $body,
            'the refusal must name the inactive account so the purchaser knows which one is meant'
        );
        self::assertStringContainsString(
            'administrator must reactivate',
            $body,
            'the refusal must state the administrator remedy rather than merely reporting no active account'
        );
    }
}
