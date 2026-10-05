<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Conformance check: the keys `GET /sheets` emits are the keys `contracts/ocs-api.yaml` declares for
 * `SheetSummary` — no fewer, no more.
 *
 * ## Why this file exists
 *
 * `SheetSummary` declares `firstExpenseDate` and `lastExpenseDate`, and **nothing in `lib/` emitted
 * either of them**: `SheetPayloadFormatter::summary()` is a complete array literal with exactly one
 * nested call (`totals()`), and neither property appeared in it. Neither is in `required`, so a
 * strict validator accepted the absence; the only reader in the repository was a frontend **fixture**
 * — which is the trap T327 documented and the reason Phase 55's pass found fourteen wire gaps behind a
 * green suite (T337).
 *
 * ## Why the declared set is **parsed** rather than copied
 *
 * `ConfigurationPayloadFormatterTest` states its key sets literally and says so: "if the contract
 * changes, these fail until someone re-reads it". That is a canary, not a check — it fails when a
 * human renames a property and does nothing when a property is added. Reading `SheetSummary.properties`
 * out of the document instead means **adding a fourth unproducible field fails here immediately**,
 * which is the whole point of the criterion: a third payload cannot drift the same way.
 *
 * The reader is deliberately narrow — it understands exactly the shape this document uses
 * (`components.schemas.<Name>` → `properties:` → `name: …`) and throws on anything else, so it cannot
 * quietly return an empty set and pass every assertion.
 */
final class SheetSummaryConformanceTest extends TestCase
{
    private const CONTRACT = __DIR__ . '/../../../specs/001-pettycash-v1-core/contracts/ocs-api.yaml';

    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

    /**
     * Every declared property is emitted, on the list route and on the detail route.
     *
     * Both routes are asserted because they are two different code paths now: the list formats a whole
     * page through `summaries()` with the date ranges prefetched in one query, while detail formats a
     * single sheet and computes its own range. A field added to one and forgotten in the other is
     * exactly the drift this file exists to catch.
     */
    public function testEverySheetSummaryPropertyIsEmittedOnBothRoutes(): void
    {
        $declared = $this->contractProperties('SheetSummary');
        $this->assertNotEmpty($declared, 'the contract reader found the schema it was pointed at');

        $listItem = $this->firstListedSheet();
        foreach ($declared as $property) {
            $this->assertArrayHasKey(
                $property,
                $listItem,
                "SheetSummary declares '{$property}' and GET /sheets must emit it"
            );
        }

        $detail = $this->detail(ContractFixtures::sheetId());
        foreach ($declared as $property) {
            $this->assertArrayHasKey(
                $property,
                $detail,
                "SheetSummary declares '{$property}' and GET /sheets/{id} must emit it"
            );
        }
    }

    /**
     * Nothing is emitted that the contract does not declare.
     *
     * The other direction, and the one that catches a controller's private copy of the shape — which is
     * how `SheetController` came to emit an `accountId` the contract never described.
     */
    public function testTheListRouteEmitsNoUndeclaredProperty(): void
    {
        $declared = $this->contractProperties('SheetSummary');

        foreach ($this->listedSheets() as $sheet) {
            $extra = array_diff(array_keys($sheet), $declared);
            $this->assertSame(
                [],
                array_values($extra),
                'an emitted property the contract does not describe is one nothing can be tested against'
            );
        }
    }

    /**
     * `SheetDetail` is `allOf: [SheetSummary, …]`, so every `SheetSummary` property must be present there
     * too — the same comparison T327 asked for on `SheetDetail`, which is what this check generalises.
     */
    public function testTheDetailRouteEmitsNoUndeclaredSheetSummaryProperty(): void
    {
        $declared = $this->contractProperties('SheetSummary');
        $detail = $this->detail(ContractFixtures::sheetId());

        $extra = array_diff(array_keys($detail), array_merge($declared, ['allowedActions', 'transactions']));
        $this->assertSame(
            [],
            array_values($extra),
            'SheetDetail adds allowedActions and transactions and nothing else'
        );
    }

    /**
     * The two dates are dates, ordered, and equal to the extremes of the sheet's own transactions.
     *
     * The last clause is the load-bearing one: it ties the emitted range to data the same response
     * already carries, so a query that returned something else — the wrong revision, a created-at date
     * instead of an expense date, the range of a different sheet — fails here.
     */
    public function testTheExpenseDateRangeMatchesTheSheetTransactions(): void
    {
        $detail = $this->detail(ContractFixtures::sheetId());
        $transactions = $detail['transactions'] ?? [];
        $this->assertNotEmpty($transactions, 'the fixture sheet has transactions to check a range against');

        $dates = array_map(
            static fn (array $transaction): string => (string) $transaction['activeRevision']['expenseDate'],
            $transactions
        );
        foreach ($dates as $date) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date, 'the contract declares format: date');
        }

        $this->assertSame(min($dates), $detail['firstExpenseDate'], 'the range starts at the earliest expense date');
        $this->assertSame(max($dates), $detail['lastExpenseDate'], 'the range ends at the latest expense date');
        $this->assertLessThanOrEqual(
            (string) $detail['lastExpenseDate'],
            (string) $detail['firstExpenseDate'],
            'a first date after the last date is not a range'
        );
    }

    /**
     * The list row and the detail row agree.
     *
     * They are produced by different code paths now (`summaries()` versus `summary()`), and a purchaser
     * reading a list and then opening a sheet must not see two different ranges.
     */
    public function testTheListedRangeEqualsTheDetailRange(): void
    {
        $listed = null;
        foreach ($this->listedSheets() as $sheet) {
            if (($sheet['id'] ?? null) === ContractFixtures::sheetId()) {
                $listed = $sheet;
            }
        }
        $this->assertNotNull($listed, 'the fixture sheet is in the caller\'s listing');

        $detail = $this->detail(ContractFixtures::sheetId());

        $this->assertSame($detail['firstExpenseDate'], $listed['firstExpenseDate']);
        $this->assertSame($detail['lastExpenseDate'], $listed['lastExpenseDate']);
    }

    // ===== helpers -----------------------------------------------------------

    /**
     * The properties `components.schemas.<name>` declares.
     *
     * @return list<string>
     */
    private function contractProperties(string $schema): array
    {
        $this->assertFileExists(self::CONTRACT);
        $lines = explode("\n", (string) file_get_contents(self::CONTRACT));

        $inSchema = false;
        $inProperties = false;
        $properties = [];

        foreach ($lines as $line) {
            // `    SheetSummary:` — four spaces, inside `components.schemas`.
            if (preg_match('/^ {4}' . preg_quote($schema, '/') . ':\s*$/', $line) === 1) {
                $inSchema = true;
                continue;
            }
            if (!$inSchema) {
                continue;
            }
            // Any other four-space key ends the schema.
            if (preg_match('/^ {4}\S/', $line) === 1) {
                break;
            }
            if (preg_match('/^ {6}properties:\s*$/', $line) === 1) {
                $inProperties = true;
                continue;
            }
            if ($inProperties) {
                if (preg_match('/^ {8}([A-Za-z0-9_]+):/', $line, $matches) === 1) {
                    $properties[] = $matches[1];
                    continue;
                }
                if (preg_match('/^ {6}\S/', $line) === 1) {
                    break;
                }
            }
        }

        return $properties;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listedSheets(): array
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets?page=1&pageSize=100');
        $this->assertEquals(200, $response->getStatusCode());

        $items = $this->data($response)['items'] ?? [];
        $this->assertIsArray($items);

        return array_values($items);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstListedSheet(): array
    {
        $sheets = $this->listedSheets();
        $this->assertNotEmpty($sheets, 'the fixture seeds at least one sheet the caller owns');

        return $sheets[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(string $sheetId): array
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . $sheetId);
        $this->assertEquals(200, $response->getStatusCode());

        return $this->data($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body, 'The response body is JSON');

        return $body['ocs']['data'] ?? [];
    }
}