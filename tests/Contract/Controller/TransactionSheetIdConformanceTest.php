<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * `TransactionSummary.sheetId` is the owning sheet's **public uuid**, on every route that emits it.
 *
 * ## The defect this pins
 *
 * `contracts/ocs-api.yaml:1154` declares `sheetId` as `format: uuid` and lists it among
 * `TransactionSummary`'s required members, while
 * `TransactionPayloadFormatter::summary()` emitted `'sheetId' => $transaction->sheetId` — the surrogate
 * key from `pettycash_transactions.sheet_id`. One line above it emits the transaction's own id as
 * `$transaction->publicId ?? $transaction->id`, so this was an inconsistency inside one array literal
 * rather than a convention.
 *
 * It was visible on screen, not only on the wire: `TransactionHeader.vue:22` and the three queue views
 * render this value in a "Sheet" column, so a reviewer read integers such as `41`; and the value could
 * not be used as the `{sheetId}` path parameter the contract declares, which is the moment a queue row
 * becomes a link (T338).
 *
 * **Every emitting route is asserted**, because there are several and they are separate call sites:
 * the transaction detail, the three review queues, and a report/export row would each have to resolve
 * the sheet independently.
 */
final class TransactionSheetIdConformanceTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('submitted');
    }

    public function testTheDetailRouteEmitsTheOwningSheetPublicId(): void
    {
        $data = $this->get('/transactions/' . ContractFixtures::transactionId());
        $this->assertSame(200, $data['status']);

        $sheetId = $data['data']['sheetId'] ?? null;
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $sheetId,
            'contracts/ocs-api.yaml declares sheetId as format: uuid'
        );
        $this->assertSame(
            ContractFixtures::sheetId(),
            $sheetId,
            'and it is the public id of the sheet that owns the transaction, not an internal key'
        );
    }

    /**
     * The queue rows, which is where the integer was actually rendered.
     *
     * Asserted against every row of every queue rather than one row, so a queue that resolves the
     * sheet for its first row only fails here. The paths are the routed ones — `GET /queues/{stage}` —
     * not `/reviews/*`, which is the hash route the frontend uses and answers 404 over OCS.
     */
    public function testEveryQueueRowEmitsAUuidSheetId(): void
    {
        // Which queue holds rows depends on the fixture state, and asserting "this queue is non-empty"
        // would then be a claim about the fixtures rather than about the server. So the sweep visits
        // every fixture state and every stage, checks the shape of **every row it finds**, and asserts
        // only that the sweep as a whole saw rows — otherwise "no rows" would pass vacuously.
        $states = ['draft', 'submitted', 'finance'];
        $rows = 0;

        foreach ($states as $state) {
            ContractFixtures::prepare($state);

            foreach (['cost-center', 'senior', 'finance'] as $stage) {
                $response = $this->client->request(
                    'GET',
                    '/ocs/v2.php/apps/pettycash/api/v1/queues/' . $stage . '?page=1&pageSize=50'
                );
                $this->assertSame(200, $response->getStatusCode(), $state . '/' . $stage);

                foreach ($this->items($response) as $row) {
                    $this->assertMatchesRegularExpression(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                        (string) ($row['sheetId'] ?? ''),
                        $state . '/' . $stage . ' rendered a sheet reference that is not a uuid'
                    );
                    $rows++;
                }
            }
        }

        $this->assertGreaterThan(0, $rows, 'no queue returned a row, so this case measured nothing');
    }

    /**
     * A transaction whose sheet row has been removed must not present an internal key as an identifier.
     *
     * **Asserted as a unit case against the in-memory doubles rather than over HTTP, and the reason is
     * deliberate.** Producing this fault over HTTP means writing a transaction whose `sheet_id` matches
     * no row — a second corrupt-record probe, and `CorruptRecordFixture`'s own docblock is an argument
     * against adding one casually: it already carries one probe, and a probe whose failure mode is
     * "leaves a landmine that breaks three reports" is what produced seven leaked sheets once already.
     * What the criterion needs is the *direction* of the answer, and the formatter's branch is
     * reachable in-process with no shared state at all. `TransactionPayloadFormatterSheetIdTest`
     * covers the three states: a sheet that resolves, one that does not, and a transaction with no
     * sheet.
     *
     * **`''` and not a 422, and the refusal is T313/T314's to make.** FR-083 requires a broken
     * reference to be answered as a data fault and it names `GET /sheets`, `GET /sheets/{id}`,
     * `GET /dashboard` and the report endpoints — this transaction route is not among them. A number in
     * a uuid field is a value a client will try to use, and this one cannot be; that is what this task
     * removes.
     */
    public function testTheQueueRowsCarryNoSurrogateKeyAnywhereInThePayload(): void
    {
        ContractFixtures::prepare('finance');

        // The same absence, checked on the routes a user actually reads. A surrogate key would be an
        // integer where the contract declares a uuid, so any integer-valued `sheetId` is the defect.
        foreach (['cost-center', 'senior', 'finance'] as $stage) {
            $response = $this->client->request(
                'GET',
                '/ocs/v2.php/apps/pettycash/api/v1/queues/' . $stage . '?page=1&pageSize=50'
            );
            $this->assertSame(200, $response->getStatusCode(), 'the ' . $stage . ' queue');

            foreach ($this->items($response) as $row) {
                $this->assertIsString($row['sheetId'] ?? null, 'sheetId is a string in the contract');
                $this->assertNotSame('', $row['sheetId'], 'every queued transaction has a sheet');
            }
        }
    }

    // ===== helpers -----------------------------------------------------------

    /**
     * @return array{status: int, data: array<string, mixed>, error?: array<string, mixed>}
     */
    private function get(string $path): array
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1' . $path);
        $body = json_decode((string) $response->getBody(), true);
        $data = $body['ocs']['data'] ?? [];

        return [
            'status' => $response->getStatusCode(),
            'data' => is_array($data) ? $data : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        $items = $body['ocs']['data']['items'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }
}