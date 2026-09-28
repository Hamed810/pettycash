<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * T112: `GET /reports/{reportType}` and `POST /exports` over the real OCS surface.
 *
 * ## The test that earns its place
 *
 * `testAConformingExportRequestAnswersJson` exists because of a defect it would have caught. The
 * contract's request field was literally named `format`, which is the parameter Nextcloud's OCS layer
 * reserves for choosing the response envelope: `Request::getFormat()` (`Request.php:903`) reads
 * `getParam('format')` *before* the `Accept` header, and `OCSMiddleware` falls back to `xml` whenever
 * that value is not a registered responder.
 *
 * So every *conforming* request answered XML while returning a correct status and a correct payload.
 * Nothing else in the app received a `format` field, which is why every other POST kept answering JSON
 * and the endpoint looked healthy. The contract field is now `fileFormat`, and this test asserts the
 * envelope is JSON so a future rename back to `format` fails here rather than in a client.
 */
final class ReportAndExportControllerTest extends TestCase
{
    /** The contract fixture's sheet, which the acting user owns. */
    private const FIXTURE_SHEET_ID = '55555555-5555-4555-8555-555555555555';

    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
    }

    // ===== GET /reports/{reportType} =====

    public function testAReportAnswersTheContractsPageShape(): void
    {
        $response = $this->get('/reports/sheets');

        $this->assertEquals(200, $response['status'], 'GET /reports/sheets must answer 200');
        $data = $response['data'];

        // PageMeta, as the contract's allOf declares.
        foreach (['page', 'pageSize', 'total'] as $key) {
            $this->assertArrayHasKey($key, $data);
            $this->assertIsInt($data[$key]);
        }
        foreach (['columns', 'rows', 'summary'] as $key) {
            $this->assertIsArray($data[$key], $key);
        }
        $this->assertIsArray($data['columns'], 'columns is a list of names, not of objects');

        $this->assertSame(1, $data['page'], 'the default page is 1');
        $this->assertSame(25, $data['pageSize'], 'the contract default pageSize is 25');
    }

    public function testEveryRowUsesOnlyTheColumnsTheReportDeclares(): void
    {
        $data = $this->get('/reports/sheets')['data'];

        foreach ($data['rows'] as $index => $row) {
            $this->assertIsArray($row, "row {$index} is an object");
            foreach (array_keys($row) as $key) {
                $this->assertContains(
                    $key,
                    $data['columns'],
                    "row {$index} has a key the report's own column list does not declare"
                );
            }
        }
    }

    public function testAnUndeclaredReportTypeIsRefusedWithTheContractsEnum(): void
    {
        $response = $this->get('/reports/balances');

        $this->assertEquals(422, $response['status'], 'balances is not in the contract enum');
        foreach (['sheets', 'transactions', 'cost-centers', 'accounts', 'process', 'evidence', 'audit'] as $type) {
            $this->assertStringContainsString($type, $response['message'], "the refusal should name '{$type}'");
        }
    }

    /**
     * FR-065 names balances and movements, FR-056 names configuration audit, and none is in the enum.
     * Asserted so the tempting fix -- adding them -- cannot happen quietly from a controller.
     */
    public function testSubjectsNamedByRequirementsButNotTheEnumAreNotServed(): void
    {
        foreach (['balances', 'movements', 'configuration_audit', 'configuration-audit'] as $type) {
            $this->assertEquals(422, $this->get('/reports/' . $type)['status'], "'{$type}' is not in the enum");
        }
    }

    public function testPagingIsHonoured(): void
    {
        $data = $this->get('/reports/sheets?page=2&pageSize=5')['data'];

        $this->assertSame(2, $data['page']);
        $this->assertSame(5, $data['pageSize']);
        $this->assertLessThanOrEqual(5, count($data['rows']), 'a page must not exceed its pageSize');
    }

    public function testTheAccountsReportSaysWhoseAccountsItCovers(): void
    {
        $summary = $this->get('/reports/accounts')['data']['summary'];

        // FR-055's "accounts they charge" is not derivable from the schema (T299), so the report
        // returns the caller's own accounts and says so. Without this a caller could read a scoped
        // report as an instance-wide one.
        $this->assertSame('own_accounts', $summary['scope']);
        $this->assertFalse($summary['chargedAccountsIncluded']);
    }

    public function testAMalformedFilterIsRefusedRatherThanIgnored(): void
    {
        $response = $this->get('/reports/sheets?filters=not-json');

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('filters', $response['message']);
    }

    // ===== T307: a filter the report cannot answer =====

    /**
     * The regression. `ReportQuery::applyFilter()` read `currencyCode` and `actorUid` while the sheets
     * rows emit `currency` and `ownerUid`, so these filters compared an absent key, matched nothing and
     * returned an empty page with a 200. On a sheet set that is entirely IRR and owned by
     * `employee1`, filtering by either returned `total: 0` -- a confident wrong answer.
     *
     * These run against the live endpoint rather than against rows built to suit the filter, because
     * building the rows in the test is precisely how the defect survived T106: its 53 tests used rows
     * whose keys matched the filter's expectations, never the keys a producer actually emits.
     */
    public function testFilteringSheetsByCurrencyReturnsTheSheets(): void
    {
        $data = $this->get('/reports/sheets?filters=' . rawurlencode('{"currencyCodes":["IRR"]}'))['data'];

        $this->assertGreaterThan(0, $data['total'], 'a currency every sheet has must not return nothing');
    }

    public function testFilteringSheetsByActorReturnsTheSheets(): void
    {
        $data = $this->get('/reports/sheets?filters=' . rawurlencode('{"actorUids":["employee1"]}'))['data'];

        $this->assertGreaterThan(0, $data['total'], 'a filter naming the owner must not return nothing');
    }

    public function testFilteringSheetsByStatusStillNarrows(): void
    {
        $all = $this->get('/reports/sheets')['data'];
        $open = $this->get('/reports/sheets?filters=' . rawurlencode('{"statuses":["OPEN"]}'))['data'];

        $this->assertLessThanOrEqual($all['total'], $open['total']);
        foreach ($open['rows'] as $row) {
            $this->assertSame('OPEN', $row['status'], 'a status filter must only return that status');
        }
    }

    /**
     * A filter no row in the report can answer is refused, naming the field and what the rows do carry.
     * A 200 with `total: 0` here would be indistinguishable from a true negative.
     */
    public function testAFilterTheReportCannotAnswerIsRefusedRatherThanReturningNothing(): void
    {
        foreach (['costCenterDbIds', 'eventTypes'] as $field) {
            $response = $this->get('/reports/sheets?filters=' . rawurlencode(json_encode([$field => [1]])));

            $this->assertEquals(422, $response['status'], "`{$field}` is not a sheets field and must be refused");
            $this->assertStringContainsString($field, $response['message']);
            $this->assertStringContainsString('status', $response['message'], 'the refusal should list what the rows do carry');
        }
    }

    public function testTheAuditReportDoesAcceptAnEventTypeFilter(): void
    {
        $response = $this->get('/reports/audit?filters=' . rawurlencode('{"eventTypes":["NOT_A_REAL_EVENT"]}'));

        // 200 with a true zero, not a 422: audit rows genuinely carry `eventType`, so this filter is
        // evaluable and simply matches nothing.
        $this->assertEquals(200, $response['status']);
        $this->assertSame(0, $response['data']['total']);
    }

    // ===== T309: the filter vocabulary is closed =====

    /**
     * The regression. `filterFrom()` used to read eight filter names and discard every other key in the
     * object without comment, so a caller who misspelled a filter received the **entire report** instead
     * of the subset they asked for, with nothing in the response saying so. Measured before the fix:
     * `GET /reports/transactions` returned `total: 4` unfiltered, and the same `total: 4` for
     * `{"stages":["FINANCE"]}`, for `{"banana":true}`, and for the typo `{"costcentreIds":[1]}`.
     *
     * FR-064 now requires an unrecognised filter to be refused with a 422 naming the accepted filters.
     */
    public function testAnUnrecognisedFilterIsRefusedRatherThanIgnored(): void
    {
        $response = $this->get('/reports/transactions?filters=' . rawurlencode('{"stages":["FINANCE"]}'));

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('stages', $response['message']);
        $this->assertStringContainsString('costCenterDbIds', $response['message'], 'the refusal should list what is accepted');
    }

    /**
     * The typo is the damaging case, because `costcentreIds` is a misspelling of a filter that *is*
     * accepted: ignoring it returns every transaction in the caller's scope as though the cost centre had
     * been chosen.
     */
    public function testAMisspelledFilterIsRefused(): void
    {
        $response = $this->get('/reports/transactions?filters=' . rawurlencode('{"costcentreIds":[1]}'));

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('costcentreIds', $response['message']);
    }

    public function testANonsenseFilterIsRefused(): void
    {
        $this->assertEquals(
            422,
            $this->get('/reports/transactions?filters=' . rawurlencode('{"banana":true}'))['status']
        );
    }

    public function testAFilterArrayIsRefusedRatherThanTreatedAsNoFilter(): void
    {
        // A JSON array is not a filter object. `is_array()` alone would have accepted it and then read
        // nothing from it -- silently unfiltered again, by a different route.
        $this->assertEquals(
            422,
            $this->get('/reports/transactions?filters=' . rawurlencode('["statuses"]'))['status']
        );
    }

    public function testEveryAcceptedFilterStillWorks(): void
    {
        $all = $this->get('/reports/transactions')['data']['total'];
        $statuses = $this->get('/reports/transactions?filters=' . rawurlencode('{"statuses":["DRAFT"]}'))['data'];
        $actors = $this->get('/reports/transactions?filters=' . rawurlencode('{"actorUids":["employee1"]}'))['data'];

        // Refusing unknown keys must not have broken the ones that work.
        $this->assertLessThanOrEqual($all, $statuses['total']);
        foreach ($statuses['rows'] as $row) {
            $this->assertSame('DRAFT', $row['status']);
        }
        foreach ($actors['rows'] as $row) {
            $this->assertSame('employee1', $row['ownerUid']);
        }
    }

    // ===== POST /exports =====

    public function testAConformingExportRequestAnswersJson(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            // Selects, so the request is servable and this test measures the envelope. The selection
            // behaviour itself is T302's subject and has its own tests below.
            'ids' => [self::FIXTURE_SHEET_ID],
        ], $this->commandId());

        $this->assertEquals(200, $response['status'], json_encode($response['data']));
        // The regression this file is for: with a field named `format`, this envelope was XML.
        $this->assertSame('json', $response['envelope'], 'POST /exports must answer an OCS JSON envelope');
    }

    public function testACsvExportAnswersTheContractsResultShape(): void
    {
        $data = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            // Added by T302: an export with no selection is refused, because an empty
            // artifact answers "you have no sheets" when it should say "I was not asked
            // which sheets".
            'ids' => [self::FIXTURE_SHEET_ID],
        ], $this->commandId())['data'];

        foreach (['artifactName', 'mediaType', 'format', 'byteSize', 'rowCount', 'data'] as $key) {
            $this->assertArrayHasKey($key, $data, "ExportResult requires '{$key}'");
        }

        $this->assertSame('csv', $data['format']);
        $this->assertStringStartsWith('text/csv', $data['mediaType'], 'mediaType names the artifact, not the envelope');
        $this->assertIsInt($data['byteSize']);
        $this->assertIsInt($data['rowCount']);

        $bytes = base64_decode((string) $data['data'], true);
        $this->assertIsString($bytes, 'data is base64-encoded artifact bytes');
        $this->assertSame(strlen($bytes), $data['byteSize'], 'byteSize must describe the decoded bytes');
    }

    public function testAnExportRemovesItsArtifactOnceStreamed(): void
    {
        $data = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            // Added by T302: an export with no selection is refused, because an empty
            // artifact answers "you have no sheets" when it should say "I was not asked
            // which sheets".
            'ids' => [self::FIXTURE_SHEET_ID],
        ], $this->commandId())['data'];

        // "Generated artifacts ... are removed after streaming." Asserted, not assumed: a controller
        // that streamed and forgot would leave every export in transient storage.
        $this->assertTrue($data['artifactRemoved']);
    }

    public function testAnExportWithoutTheRequiredCommandIdIsRefused(): void
    {
        $response = $this->post('/exports', ['resource' => 'sheets', 'fileFormat' => 'csv'], null);

        $this->assertEquals(422, $response['status'], 'the contract declares CommandId required: true');
        $this->assertArrayHasKey('commandId', $response['data']['error']['fieldErrors']);
    }

    public function testAnExportWithoutResourceOrFileFormatIsRefused(): void
    {
        $response = $this->post('/exports', [], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $errors = $response['data']['error']['fieldErrors'] ?? [];
        $this->assertArrayHasKey('resource', $errors);
        // Under the contract's current name; a client told to send `format` would send nothing valid.
        $this->assertArrayHasKey('fileFormat', $errors);
    }

    public function testAnUndeclaredExportFormatIsRefused(): void
    {
        $response = $this->post('/exports', ['resource' => 'sheets', 'fileFormat' => 'docx'], $this->commandId());

        $this->assertEquals(422, $response['status']);
    }

    /**
     * PDF is in the contract's format enum but T109 is blocked on the font asset, so it is refused
     * with a reason rather than producing a document that cannot render the contract's Persian.
     */
    public function testPdfIsRefusedWithTheRecordedReason(): void
    {
        $response = $this->post('/exports', ['resource' => 'sheets', 'fileFormat' => 'pdf'], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('T109', $response['message']);
    }

    // ===== FR-068: the selection is honoured, or refused =====

    /**
     * The regression T302 records. `ExportRequest.ids` and `.filters` were declared, documented, and
     * read by nobody: the controller generated from a literal empty row set, so this request used to
     * answer `200` with a correctly-shaped artifact containing none of the sheet it named. Asserted on
     * `rowCount` *and* on the CSV body, because a correct count with the wrong rows would still be a
     * silently wrong answer.
     */
    public function testAnExportHonoursTheSelectedIds(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
        ], $this->commandId());

        $this->assertEquals(200, $response['status'], json_encode($response['data']));
        $this->assertSame(1, $response['data']['rowCount'], 'exactly the selected sheet must be exported');

        $csv = (string) base64_decode((string) $response['data']['data'], true);
        $this->assertStringContainsString(self::FIXTURE_SHEET_ID, $csv, 'the artifact must contain the selected sheet');
    }

    public function testExportingEverySheetIsRefusedRatherThanAnsweredEmpty(): void
    {
        $response = $this->post('/exports', ['resource' => 'sheets', 'fileFormat' => 'csv'], $this->commandId());

        // The important half: a 422 with a reason, not a 200 whose `rowCount: 0` reads as a measured
        // result. An empty artifact would be indistinguishable from "you have no sheets".
        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('selection', $response['message']);
    }

    /**
     * A missing id and a foreign id must be indistinguishable, or this endpoint confirms the
     * existence of records the caller may not see (FR-003).
     */
    public function testAnUnknownIdIsRefused(): void
    {
        $unknown = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => ['11111111-1111-4111-8111-111111111111'],
        ], $this->commandId());

        $foreign = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => ['99999999-9999-4999-8999-999999999999'],
        ], $this->commandId());

        $this->assertEquals(422, $unknown['status']);
        $this->assertEquals(422, $foreign['status']);
    }

    /**
     * `report`, `audit` and `evidence` do not resolve a selection yet. The point of T302 is that this
     * is now visible as a refusal instead of an artifact that quietly omits the selection.
     */
    public function testASelectionForAnUnwiredResourceIsRefusedWithAReason(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'report',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
        ], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('not supported yet', $response['message']);
        $this->assertStringContainsString('sheet', $response['message'], 'the reason should name what does work');
    }

    public function testANonStringIdIsRefused(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [42],
        ], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('UUID', $response['message']);
    }

    // ===== helpers =====

    private function commandId(): string
    {
        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            substr(bin2hex(random_bytes(2)), 1),
            dechex(random_int(8, 11)),
            substr(bin2hex(random_bytes(2)), 1),
            bin2hex(random_bytes(6))
        );
    }

    /** @return array{status: int, data: array<string, mixed>, message: string} */
    private function get(string $path): array
    {
        return $this->decode($this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1' . $path));
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, data: array<string, mixed>, message: string}
     */
    private function post(string $path, array $body, ?string $commandId): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($commandId !== null) {
            $headers['X-PettyCash-Command-ID'] = $commandId;
        }

        return $this->decode($this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1' . $path,
            ['headers' => $headers, 'json' => $body]
        ));
    }

    /**
     * The envelope's shape is decoded, and the envelope's *format* is reported rather than assumed.
     *
     * `json_decode` of an XML body returns null, which is how the `format` collision first presented:
     * as an assertion that the body was not JSON, with no hint that it was a well-formed XML OCS
     * response. `envelope` makes that visible instead.
     *
     * @return array{status: int, data: array<string, mixed>, message: string, envelope: string}
     */
    private function decode(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        $envelope = str_starts_with(ltrim($raw), '<?xml') ? 'xml' : 'json';

        if ($envelope === 'xml') {
            $this->fail(
                "The OCS response was XML, not JSON (status {$response->getStatusCode()}): "
                . substr(preg_replace('/\s+/', ' ', $raw) ?? '', 0, 300)
            );
        }

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'The response body is a JSON OCS envelope');
        $this->assertArrayHasKey('ocs', $decoded);

        $data = $decoded['ocs']['data'] ?? [];

        // Nextcloud's OCS convention puts the human-readable detail in `data.error.message` and
        // leaves `meta.message` empty for these responses, so reading only `meta` yields an empty
        // string and every "the refusal should explain itself" assertion fails against a correct API.
        // `meta.message` is kept as the fallback because other error shapes do populate it.
        $message = (string) ($data['error']['message'] ?? $decoded['ocs']['meta']['message'] ?? '');

        return [
            'status' => $response->getStatusCode(),
            'data' => $data,
            'message' => $message,
            'envelope' => $envelope,
        ];
    }
}
