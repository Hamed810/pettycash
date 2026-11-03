<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use OCA\PettyCash\Tests\Contract\Fixture\CorruptRecordFixture;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * T438: a stored sheet whose account reference is broken is never presented as a readable sheet.
 *
 * ## Why this is a separate class from `DataIntegritySheetCurrencyTest`
 *
 * The two faults travel through the same `mapRow()` and answer the same status, so a test that only
 * asserted `422 DATA_FAULT` could not tell which reference was broken. The currency test already owns
 * the sheet-currency case and its list assertions remain the governing evidence for FR-083's
 * all-or-nothing list; this class owns the account cases and keeps the window in which each fault exists
 * confined to its own class.
 *
 * ## What the account fault looks like before T438
 *
 * `DbExpenseSheetRepository::getAccountPublicId()` returned `''` when the stored `account_id` matched no
 * row. `ExpenseSheet::open()` therefore always produced an aggregate, and `GET /sheets` and
 * `GET /sheets/{id}` answered `200` with an empty account id. The list looked complete. Nothing in the
 * response said that one row's financial identity could not be produced, which is the substitution the
 * immutable-account policy forbids.
 *
 * The second case is quieter: the account row exists and the sheet's currency row exists, but they name
 * different currencies. `mapRow()` used to read only the account id, so the disagreement was invisible
 * and the sheet was presented as if its amounts belonged to the account's currency.
 *
 * ## What is asserted
 *
 * The status, the error code, the absence of the probe from any result set, and the absence of the
 * internal message and surrogate ids from the body. The *structural* distinction between an account
 * fault and a currency fault is asserted over the real repository in `RepositoryRoundTripTest`, because
 * an HTTP body deliberately does not carry that implementation detail.
 */
final class DataIntegritySheetAccountTest extends TestCase
{
    private Client $client;

    public static function setUpBeforeClass(): void
    {
        // Repair before anything else, unconditionally: a run killed between the write and the removal
        // would otherwise leave a sheet that refuses every list for every later run.
        CorruptRecordFixture::sweep();
    }

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('submitted');
    }

    /**
     * The stored account id resolves to no row. The old read answered `200` with `accountId === ''`.
     */
    public function testASheetWhoseAccountDoesNotResolveIsRefused(): void
    {
        $probe = CorruptRecordFixture::insert(CorruptRecordFixture::FAULT_ACCOUNT);

        try {
            $detail = $this->get('/sheets/' . $probe['sheetId']);

            $this->assertSame(
                422,
                $detail['status'],
                'a sheet whose account reference resolves to nothing is a data fault, not a readable sheet'
            );
            $this->assertSame(
                'DATA_FAULT',
                $detail['data']['error']['code'] ?? null,
                'FR-083 requires one data-fault answer across the sheet endpoints, and an account reference '
                . 'is not a validation failure the client can correct'
            );
            $this->assertNull(
                $this->entryFor($detail['data'], $probe['sheetId']),
                'the unreadable sheet must not be emitted with an empty or guessed account id'
            );
            $this->assertStringContainsString(
                'data fault',
                (string) ($detail['data']['error']['message'] ?? ''),
                'the explanation must name the fault as being in the data'
            );

            $list = $this->get('/sheets?pageSize=100');

            $this->assertSame(
                422,
                $list['status'],
                'FR-083 makes the list all-or-nothing: a list containing this sheet must not answer 200'
            );
            $this->assertSame('DATA_FAULT', $list['data']['error']['code'] ?? null);
            $this->assertNull(
                $this->entryFor($list['data'], $probe['sheetId']),
                'the list must not silently omit the unreadable sheet and report the rest as complete'
            );
        } finally {
            CorruptRecordFixture::remove();
        }
    }

    /**
     * T441: the purchaser dashboard reads sheets through `findByOwnerUid(OPEN)`, so an unreadable
     * account reference must refuse the dashboard too. A dashboard that looked complete while one
     * sheet's financial identity could not be produced would be the same substitution T438 forbids on
     * the list and detail routes.
     */
    public function testThePurchaserDashboardRefusesAnUnreadableAccountReference(): void
    {
        $probe = CorruptRecordFixture::insert(CorruptRecordFixture::FAULT_ACCOUNT);

        try {
            $response = $this->get('/dashboard');

            $this->assertSame(
                422,
                $response['status'],
                'the purchaser dashboard must refuse a sheet whose account reference resolves to nothing'
            );
            $this->assertSame(
                'DATA_FAULT',
                $response['data']['error']['code'] ?? null,
                'the dashboard must report the account reference as stored-data corruption, not a client error'
            );
            $this->assertStringContainsString(
                'data fault',
                (string) ($response['data']['error']['message'] ?? ''),
                'the dashboard refusal must name the fault as being in the data'
            );

            $raw = $this->raw('/dashboard');
            $this->assertStringNotContainsString(
                (string) $probe['danglingAccountId'],
                $raw,
                'the dashboard disclosed the account id that does not resolve'
            );
            $this->assertStringNotContainsString(
                'Cannot resolve the account',
                $raw,
                'the dashboard echoed the internal account-resolution message'
            );
        } finally {
            CorruptRecordFixture::remove();
        }

        $this->assertSame(
            200,
            $this->get('/dashboard')['status'],
            'the dashboard must answer 200 once the unreadable record is removed'
        );
    }

    /**
     * The account resolves, the sheet currency resolves, and they disagree.
     *
     * This is the case the account-id lookup alone cannot see. Both surrogate keys are valid, so only an
     * explicit account/currency comparison refuses it -- and reinterpreting the amounts in either
     * currency would be exactly the silent repair the policy forbids.
     */
    public function testASheetWhoseAccountCurrencyDisagreesIsRefused(): void
    {
        $probe = CorruptRecordFixture::insert(CorruptRecordFixture::FAULT_ACCOUNT_CURRENCY);

        try {
            foreach (['/sheets/' . $probe['sheetId'], '/sheets?pageSize=100'] as $path) {
                $response = $this->get($path);

                $this->assertSame(
                    422,
                    $response['status'],
                    "{$path} must refuse a sheet whose account and sheet currencies disagree"
                );
                $this->assertSame(
                    'DATA_FAULT',
                    $response['data']['error']['code'] ?? null,
                    "{$path} must report the disagreement as a stored-data fault, not a validation failure"
                );
                $this->assertNull(
                    $this->entryFor($response['data'], $probe['sheetId']),
                    "{$path} must not present the mismatched sheet as a readable record"
                );
            }
        } finally {
            CorruptRecordFixture::remove();
        }
    }

    /**
     * Nothing about the account fault may reach the caller: not the surrogate keys, not the account id,
     * and not the internal message.
     *
     * The operator needs all of those to find the row. A client that learns which reference is broken
     * learns something about rows it may not be able to see, and a client that reads the internal
     * message learns which ids to probe. The body is asserted whole rather than only its message field
     * because a leak through any other key would be just as bad.
     */
    public function testTheAccountFaultIsLoggedRatherThanDisclosed(): void
    {
        $probe = CorruptRecordFixture::insert(CorruptRecordFixture::FAULT_ACCOUNT);

        try {
            foreach (['/sheets/' . $probe['sheetId'], '/sheets?pageSize=100'] as $path) {
                $raw = $this->raw($path);

                $this->assertStringNotContainsString(
                    (string) $probe['danglingAccountId'],
                    $raw,
                    "{$path} disclosed the account id that does not resolve"
                );
                $this->assertStringNotContainsString(
                    (string) $probe['accountDbId'],
                    $raw,
                    "{$path} disclosed a real account surrogate key from the probe"
                );
                $this->assertStringNotContainsString(
                    (string) $probe['sheetDbId'],
                    $raw,
                    "{$path} disclosed the sheet surrogate key from the probe"
                );
                $this->assertStringNotContainsString(
                    'Cannot resolve the account',
                    $raw,
                    "{$path} echoed the internal account-resolution message to the caller"
                );
            }
        } finally {
            CorruptRecordFixture::remove();
        }
    }

    /**
     * The mismatch message names both currencies and the account, and none of it may be disclosed.
     *
     * The two small currency ids are not asserted absent: a JSON status or a page number contains the
     * same digits, so that assertion would fail for an unrelated reason. The account surrogate key and
     * the message prefix are unambiguous.
     */
    public function testTheMismatchFaultIsLoggedRatherThanDisclosed(): void
    {
        $probe = CorruptRecordFixture::insert(CorruptRecordFixture::FAULT_ACCOUNT_CURRENCY);

        try {
            $raw = $this->raw('/sheets/' . $probe['sheetId']);

            $this->assertStringNotContainsString(
                (string) $probe['accountDbId'],
                $raw,
                'the mismatch response disclosed the account surrogate key'
            );
            $this->assertStringNotContainsString(
                (string) $probe['sheetDbId'],
                $raw,
                'the mismatch response disclosed the sheet surrogate key'
            );
            $this->assertStringNotContainsString(
                'Cannot read sheet',
                $raw,
                'the mismatch response echoed the internal message that names both currencies'
            );
        } finally {
            CorruptRecordFixture::remove();
        }
    }

    /**
     * A healthy sheet stays available while another sheet's account is broken.
     *
     * Without this, "the endpoint refused" would be a sufficient way to pass, including a way that
     * refuses everything -- which is a different defect and not the one under test.
     */
    public function testAHealthySheetIsStillServedWhileAnotherAccountReferenceIsBroken(): void
    {
        CorruptRecordFixture::insert(CorruptRecordFixture::FAULT_ACCOUNT);

        try {
            $response = $this->get('/sheets/' . ContractFixtures::sheetId());
            $raw = $this->raw('/sheets/' . ContractFixtures::sheetId());

            $this->assertSame(200, $response['status'], 'a sheet with a resolvable account must be served');
            $this->assertStringContainsString(
                ContractFixtures::sheetId(),
                $raw,
                'the healthy sheet must be the one in the body'
            );
        } finally {
            CorruptRecordFixture::remove();
        }
    }

    /**
     * The control: once the probe is gone, both endpoints are served again.
     *
     * A fixture that can only demonstrate a failure has not shown that the failure is the fixture's own
     * doing. The `remove()` in `finally` throws if anything survived, so reaching the second loop also
     * proves the cleanup completed rather than merely ran.
     */
    public function testBothEndpointsAreServedAgainOnceTheProbeIsRemoved(): void
    {
        $probe = CorruptRecordFixture::insert(CorruptRecordFixture::FAULT_ACCOUNT);

        try {
            $this->assertSame(
                422,
                $this->get('/sheets/' . $probe['sheetId'])['status'],
                'the probe must be what changes the answer, or the assertions above are vacuous'
            );
        } finally {
            CorruptRecordFixture::remove();
        }

        foreach (['/sheets?pageSize=100', '/sheets/' . ContractFixtures::sheetId()] as $path) {
            $this->assertSame(200, $this->get($path)['status'], "{$path} must be healthy once the probe is gone");
        }
    }

    // ===== helpers =====

    /** @return array{status: int, data: array<string, mixed>, message: string} */
    private function get(string $path): array
    {
        return $this->decode($this->request('GET', $path));
    }

    /** The undecoded body, for asserting on what is *absent* from it. */
    private function raw(string $path): string
    {
        return (string) $this->request('GET', $path)->getBody();
    }

    private function request(string $method, string $path): ResponseInterface
    {
        return $this->client->request($method, '/ocs/v2.php/apps/pettycash/api/v1' . $path);
    }

    /** @return array{status: int, data: array<string, mixed>, message: string} */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The response was not a JSON OCS envelope.');
        }

        return [
            'status' => (int) ($decoded['ocs']['meta']['statuscode'] ?? 0),
            'data' => (array) ($decoded['ocs']['data'] ?? []),
            'message' => (string) ($decoded['ocs']['meta']['message'] ?? ''),
        ];
    }

    /**
     * The probe's own record within a response, or null when the response does not carry it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function entryFor(array $data, string $sheetId): ?array
    {
        if (($data['id'] ?? null) === $sheetId) {
            return $data;
        }

        foreach ((array) ($data['items'] ?? []) as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $sheetId) {
                return $item;
            }
        }

        return null;
    }
}
