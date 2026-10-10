<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The `Evidence` schema, asserted on **both** endpoints that publish it.
 *
 * ## What was wrong
 *
 * `contracts/ocs-api.yaml` declares `Evidence.transactionId` and `Evidence.revisionId` as
 * `format: uuid` and lists both among the required members. The aggregate carried
 * `public int $transactionId` / `public int $originRevisionId`, populated from
 * `(int) $row['transaction_id']`, and **both** emitters passed them straight through — so every
 * evidence payload this application has ever produced carried two integers where the contract promises
 * two uuids. `src/types/api.ts` typed both `string`, which is why nothing failed: a silent runtime
 * violation that `npm run typecheck` cannot see (T339).
 *
 * ## Why "both endpoints" is the criterion and not one of them
 *
 * There were two emitters of this schema and they disagreed: `EvidenceController::formatEvidence()`
 * added a `deactivationReason` the contract does not declare and wrote `id` differently. A client could
 * not tell which endpoint it had called and get a shape that held for both. There is now **one**
 * emitter, so the key-set comparison below is a statement about a property the code has rather than
 * about two copies that happened to agree today.
 */
final class EvidencePayloadConformanceTest extends TestCase
{
    private Client $client;
    private string $transactionId;
    private string $uploadedEvidenceId;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
        $this->transactionId = ContractFixtures::transactionId();

        // The `draft` fixture transaction ships without evidence, and every read-path case below needs
        // at least one row to be a statement about evidence at all. One upload in `setUp()` rather than
        // one per case: this is a shared instance, and a case that creates evidence per test would add
        // rows to it on every run — the hygiene T333 recorded when its contract test created probes.
        $this->uploadedEvidenceId = $this->uploadEvidence();
    }

    /**
     * The transaction detail publishes evidence inside the transaction payload.
     */
    public function testEvidenceInsideTheTransactionPayloadCarriesUuidReferences(): void
    {
        $rows = $this->evidenceRows();

        foreach ($rows as $row) {
            $this->assertUuid($row['transactionId'] ?? null, 'transactionId');
            $this->assertUuid($row['revisionId'] ?? null, 'revisionId');
        }
    }

    /**
     * The reference is the transaction's own public id, not merely some uuid.
     *
     * Asserted as an equality because "it is a uuid" is satisfiable by the wrong uuid, which is the
     * shape of the defect: an integer is replaced by whatever identifier happens to be in reach.
     */
    public function testTheTransactionIdIsTheOwningTransactionsPublicId(): void
    {
        foreach ($this->evidenceRows() as $row) {
            $this->assertSame(
                $this->transactionId,
                $row['transactionId'] ?? null,
                'the evidence points at the transaction that owns it'
            );
        }
    }

    /**
     * The evidence endpoints publish the same schema, so the same ids must be uuids there.
     *
     * `POST /transactions/{id}/evidence` answers `201` with an `Evidence` and
     * `DELETE /evidence/{id}` answers `200` with the deactivated one — two more call sites of the same
     * private method, which is why the assertion is on all of them rather than on the read path.
     */
    public function testTheEvidenceEndpointsPublishTheSameSchemaWithUuidReferences(): void
    {
        $uploaded = $this->uploadedEvidencePayload();

        $this->assertUuid($uploaded['transactionId'] ?? null, 'transactionId');
        $this->assertUuid($uploaded['revisionId'] ?? null, 'revisionId');
        $this->assertSame($this->transactionId, $uploaded['transactionId'] ?? null);

        // And the deactivate response — the third call site of the same method — publishes the same
        // schema with the same id spaces.
        $deactivated = $this->client->request(
            'DELETE',
            '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . ($uploaded['id'] ?? ''),
            ['headers' => ['X-PettyCash-Command-ID' => 't339-evidence-deactivate']]
        );
        $this->assertSame(200, $deactivated->getStatusCode(), (string) $deactivated->getBody());

        $after = json_decode((string) $deactivated->getBody(), true)['ocs']['data'] ?? [];
        $this->assertUuid($after['transactionId'] ?? null, 'transactionId');
        $this->assertUuid($after['revisionId'] ?? null, 'revisionId');
        // The **schema** and the id spaces are identical; the two members that legitimately change are
        // excluded by name, because comparing whole payloads would assert that deactivating a file does
        // not deactivate it. The first draft of this assertion did exactly that and failed on
        // `active: true → false`, which is the correct behaviour.
        $this->assertSame(
            array_keys($uploaded),
            array_keys($after),
            'the deactivate response is the same schema as the upload response'
        );
        $this->assertSame($uploaded['id'] ?? null, $after['id'] ?? null);
        $this->assertSame($uploaded['transactionId'] ?? null, $after['transactionId'] ?? null);
        $this->assertSame($uploaded['revisionId'] ?? null, $after['revisionId'] ?? null);
        $this->assertFalse($after['active'] ?? true, 'and it does report the evidence as inactive');
    }

    /**
     * Both emitters produce the same key set, and it is the contract's.
     *
     * This is the criterion's "schema-shape test". It compares the evidence array inside
     * `GET /transactions/{id}` against the **contract's own `Evidence` property list**, so it also
     * fails if a fifth emitter of this schema ever appears with a different shape — which is how the
     * undeclared `deactivationReason` was found.
     */
    public function testTheEmittedKeySetIsTheContractsEvidenceSchema(): void
    {
        $declared = $this->contractEvidenceProperties();

        foreach ($this->evidenceRows() as $row) {
            $actual = array_keys($row);
            sort($actual);
            $expected = $declared;
            sort($expected);

            $this->assertSame(
                $expected,
                $actual,
                'an emitted property the contract does not describe, or a described one that is missing'
            );
            // The key this file originally asserted must be *absent*, which was the reading taken from
            // `EvidenceController` emitting a member the schema did not declare. That reading was
            // wrong: `EvidenceLifecycleControllerTest` pins `deactivationReason` as the only observable
            // of which removal path ran, so the schema was what was missing. The contract now declares
            // it, and this case finds it in the contract's own property list rather than in a hard-coded
            // expectation — which is what makes it a check rather than a transcription.
            $this->assertContains(
                'deactivationReason',
                $declared,
                'the reason a file was marked inactive is declared, because it is the only observable of that path'
            );
            $this->assertArrayHasKey('deactivationReason', $row, 'and the single emitter publishes it');
        }
    }

    // ===== helpers -----------------------------------------------------------

    /**
     * One evidence row on the fixture transaction, uploaded once per test class run.
     *
     * @return list<array<string, mixed>>
     */
    private function evidenceRows(): array
    {
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . $this->transactionId
        );
        $this->assertSame(200, $response->getStatusCode());

        $rows = $this->data($response)['evidence'] ?? [];
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows, 'no evidence on the transaction, so this case would measure nothing');

        return array_values($rows);
    }

    /**
     * Upload one evidence file and return its id.
     *
     * Multipart because that is how the endpoint is driven — `EvidenceLifecycleControllerTest`
     * established the shape — and the 201 body is an `Evidence` produced by the very
     * `formatEvidence()` that used to publish the integers.
     */
    private function uploadEvidence(): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 't339_');
        file_put_contents($tempFile, 't339 evidence bytes');

        try {
            $response = $this->client->request(
                'POST',
                '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . $this->transactionId . '/evidence',
                [
                    'headers' => ['X-PettyCash-Command-ID' => 't339-evidence-upload'],
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => fopen($tempFile, 'r'),
                            'filename' => 't339-evidence.txt',
                        ],
                    ],
                ]
            );
        } finally {
            @unlink($tempFile);
        }

        $this->assertSame(
            201,
            $response->getStatusCode(),
            'the upload must succeed for this class to mean anything: ' . (string) $response->getBody()
        );

        $id = json_decode((string) $response->getBody(), true)['ocs']['data']['id'] ?? null;
        $this->assertIsString($id, 'the upload response must carry the evidence id');

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadedEvidencePayload(): array
    {
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . $this->transactionId
        );
        $rows = $this->data($response)['evidence'] ?? [];

        foreach ($rows as $row) {
            if (($row['id'] ?? null) === $this->uploadedEvidenceId) {
                return $row;
            }
        }

        $this->fail('the evidence uploaded in setUp() is not in the transaction payload');
    }

    private function assertUuid(mixed $value, string $field): void
    {
        $this->assertIsString($value, $field . ' is a uuid in the contract, so it cannot be an integer');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $value,
            $field . ' must be a public uuid, not a surrogate key'
        );
    }

    /**
     * @return list<string>
     */
    private function contractEvidenceProperties(): array
    {
        $path = __DIR__ . '/../../../specs/001-pettycash-v1-core/contracts/ocs-api.yaml';
        $this->assertFileExists($path);
        $lines = explode("\n", (string) file_get_contents($path));

        $inSchema = false;
        $inProperties = false;
        $properties = [];

        foreach ($lines as $line) {
            if (preg_match('/^ {4}Evidence:\s*$/', $line) === 1) {
                $inSchema = true;
                continue;
            }
            if (!$inSchema) {
                continue;
            }
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

        $this->assertNotEmpty($properties, 'the contract reader found the Evidence schema');

        return $properties;
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