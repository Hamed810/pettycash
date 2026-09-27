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

    // ===== POST /exports =====

    public function testAConformingExportRequestAnswersJson(): void
    {
        $response = $this->post('/exports', ['resource' => 'sheets', 'fileFormat' => 'csv'], $this->commandId());

        $this->assertEquals(200, $response['status'], json_encode($response['data']));
        // The regression this file is for: with a field named `format`, this envelope was XML.
        $this->assertSame('json', $response['envelope'], 'POST /exports must answer an OCS JSON envelope');
    }

    public function testACsvExportAnswersTheContractsResultShape(): void
    {
        $data = $this->post('/exports', ['resource' => 'sheets', 'fileFormat' => 'csv'], $this->commandId())['data'];

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
        $data = $this->post('/exports', ['resource' => 'sheets', 'fileFormat' => 'csv'], $this->commandId())['data'];

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
