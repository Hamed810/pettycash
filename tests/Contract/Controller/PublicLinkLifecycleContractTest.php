<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * T407 / FR-031: the public-link lifecycle over HTTP — create, open unauthenticated, revoke, refused.
 *
 * ## What was wrong
 *
 * The producer half (`EvidenceLinkServiceTest`: creation stores a digest) and the consumer half
 * (`PublicEvidenceLinkServiceTest`: consumption reads one) were tested on opposite sides of the
 * process boundary, and **neither test used the other's code**. `roundtrip-runner.php` saved a link
 * and read it back by token hash against real MariaDB, so *persistence* was covered; the **HTTP
 * lifecycle** was not. Four things only a cross-boundary test can establish, and each is asserted
 * below.
 *
 * ## Why an administrator probe, and why it is purged
 *
 * Creation and revocation are administrator-only, and the suite's shared credential is not an
 * administrator. `probe-user-fixture.php` provisions a principal this suite owns (`create <uid>
 * <password> admin`, which joins Nextcloud's `admin` group — the group `AdministratorCheck` reads),
 * and `tearDown()` purges it. A dedicated principal rather than promoting the shared credential,
 * because a run dying before teardown would leave the promotion behind and silently change what other
 * suites' role-scope assertions observe. The probe holds no accounts and makes no sheets, so `purge`
 * has nothing financial to remove; the link row and its audit trail deliberately survive, because the
 * audit is append-only and must.
 */
final class PublicLinkLifecycleContractTest extends TestCase
{
    /**
     * A principal this suite creates, promotes, and removes.
     *
     * Named for the task rather than reusing an existing probe account: this suite sets the password
     * and the admin-group membership, and it must not do either to a principal another suite may own.
     */
    private const PROBE_UID = 'pettycash-t407-probe';

    private Client $admin;
    private Client $anonymous;
    private string $probePassword = '';

    protected function setUp(): void
    {
        // No fixture state is depended on: the probe builds its own sheet, transaction and evidence
        // through the API, so this lifecycle is self-contained rather than coupled to whatever rows a
        // seeder happens to hold. In particular the suite does NOT use the `correction` state's seeded
        // evidence row — that row is metadata only ("assertions that read evidence bytes upload their
        // own file instead"), pointing at an AppData key that was never written, so opening a link to
        // it answers 500 (a storage fault, by design) rather than the lifecycle under test.
        ContractFixtures::prepare('draft');

        $this->probePassword = bin2hex(random_bytes(16));
        $manifest = $this->runFixture('create', [$this->probePassword, 'admin']);

        self::assertTrue(
            $manifest['administrator'] ?? false,
            'the probe must actually hold the administrator right, or every creation below answers '
            . '403 and blames the endpoint: ' . json_encode($manifest)
        );

        // Basic auth as the probe: `OcsTestClient` is a singleton bound to the shared credential, so
        // an administrator-authenticated client has to be built directly. Same base, headers and
        // no-redirect policy as the anonymous client below.
        $baseUrl = getenv('PETTYCASH_TEST_BASE_URL') ?: 'http://localhost';
        $common = [
            'base_uri' => $baseUrl,
            'headers' => ['Accept' => 'application/json', 'OCS-APIRequest' => 'true'],
            'http_errors' => false,
            'verify' => false,
            'allow_redirects' => false,
        ];
        $this->admin = new Client($common + ['auth' => [self::PROBE_UID, $this->probePassword]]);
        // **No `auth`.** That is the entire apparatus of the unauthenticated half: a client with no
        // credentials, which is what "unauthenticated" means at the HTTP layer. No redirect
        // following, so a login form would arrive as a 200 elsewhere instead of hiding.
        $this->anonymous = new Client($common);
    }

    protected function tearDown(): void
    {
        $this->runFixture('purge', []);
    }

    /**
     * The lifecycle, in order, because an investigation is a sequence.
     *
     * One test rather than four: create → open → revoke → refused is a single story, and splitting it
     * across tests would couple them through a shared link id while pretending they were independent.
     * Each assertion group below names which of T407's four items it establishes.
     */
    public function testCreateOpenRevokeRefusedLifecycle(): void
    {
        // The probe owns every row below: it is both the purchaser (sheet, transaction, evidence) and
        // the administrator (link, revocation, audit read). One principal for both halves is what makes
        // the cross-boundary assertions possible without a second credential.
        $sheetId = $this->createSheet();
        $transactionId = $this->createTransaction($sheetId);
        $evidenceId = $this->uploadEvidence($transactionId);

        // --- 1. Create: the producer half meets the consumer half. ---
        //
        // Creation hashes with `hashToken()` and consumption hashes with the same call; if they ever
        // diverged, a link would be created 201 and then refused with the identical `404 "Not found"`
        // a caller gets for a link that never existed. A 201 followed by a 200 on the returned URL is
        // the assertion that they meet, and no pair of single-sided tests can make it.
        $commandId = ContractFixtures::commandId();
        $created = $this->admin->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId . '/links',
            ['headers' => ['X-PettyCash-Command-ID' => $commandId], 'json' => (object) []]
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $createdData = $this->data($created);
        self::assertArrayHasKey('url', $createdData, 'the creation must disclose the URL once');
        self::assertNotEmpty($createdData['url']);
        self::assertArrayHasKey('id', $createdData, 'the creation must identify the link for revocation');
        $linkId = (string) $createdData['id'];
        $path = $this->pathFromUrl((string) $createdData['url']);

        // The token is disclosed **once**: replaying the creation returns the same link with a null
        // URL, which is the contract's own shape for "returned only at creation".
        $replay = $this->admin->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId . '/links',
            ['headers' => ['X-PettyCash-Command-ID' => $commandId], 'json' => (object) []]
        );
        $replayData = $this->data($replay);
        self::assertSame($linkId, (string) ($replayData['id'] ?? null), 'a replay returns the same link');
        self::assertNull($replayData['url'] ?? null, '...but never re-discloses the URL');

        // --- 2. Open: the returned URL is a URL that works. ---
        //
        // The path is composed in the Application layer from the token and the base comes from
        // `IURLGenerator` in the controller; a wrong segment would produce a 201 carrying a dead
        // link, and nothing else exercises that composition.
        $opened = $this->anonymous->request('GET', $path);
        self::assertSame(200, $opened->getStatusCode(), 'the disclosed URL must open unauthenticated');
        self::assertNotSame('', (string) $opened->getBody(), 'opening must serve the evidence bytes');

        // --- 3. Revoke: deactivation stops a working link. ---
        //
        // `deactivate()` is unit-tested in isolation; what is proven here is that revocation through
        // the endpoint stops the link the previous step opened, rather than merely flipping a row.
        $revoked = $this->admin->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/evidence-links/' . $linkId . '/deactivate',
            ['headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()], 'json' => (object) []]
        );
        self::assertSame(200, $revoked->getStatusCode(), (string) $revoked->getBody());

        $reopened = $this->anonymous->request('GET', $path);
        self::assertSame(404, $reopened->getStatusCode(), 'a revoked link must stop opening');

        // The refusal is the contract's `NotFound` envelope — identical to a link that never existed,
        // so the refusal reveals neither that a link existed nor when it lapsed.
        $refusedUnknown = $this->anonymous->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/public/evidence/does-not-exist');
        self::assertSame(404, $refusedUnknown->getStatusCode());
        self::assertSame(
            $this->errorCode($refusedUnknown),
            $this->errorCode($reopened),
            'expired, deactivated and unknown tokens must be refused identically'
        );

        // --- 4. Audit: the writes happened, in order — proven where they land. ---
        //
        // The three rows exist in the configuration stream in lifecycle order, and that is asserted in
        // `tests/Unit/Application/Evidence/PublicLinkAuditSequenceTest.php`, which drives both services
        // against one shared audit double and reads the stream back in insertion order. It lives there
        // rather than here for a structural reason, not convenience: the audit report enumerates the
        // *reader's own* events, and the access row's viewer is `anonymous` — a principal that never
        // queries — so no HTTP reader can observe the sequence. Asserting it here would mean asserting
        // on rows no endpoint serves. The HTTP test proves the three writes happen (creation answers,
        // opening serves, revocation stops); the service test proves they land ordered, with the right
        // viewers, in the right stream.
        //
        // That unobservability is itself a product gap worth naming: FR-031 requires the access record
        // "for the investigation it exists to serve", and today no report reader can reach an
        // anonymous-actor row. Recorded here rather than silently absorbed, so a follow-up can decide
        // whether the audit report needs an administrator-visible investigation view.
    }

    // ===== helpers =====

    /**
     * A sheet for the probe, provisioned on first attempt: the probe holds no accounts, so FR-020
     * opens its default rial account as a side effect of this call.
     */
    private function createSheet(): string
    {
        $response = $this->admin->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets', [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['title' => 'Link lifecycle sheet'],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $id = $this->data($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function createTransaction(string $sheetId): string
    {
        $response = $this->admin->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . $sheetId . '/transactions',
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['expenseDate' => '2026-09-20', 'amountMinor' => 1000],
            ]
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $id = $this->data($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * A real file, not a metadata row: consumption serves bytes from AppData, so a row pointing at a
     * key that was never written answers 500 rather than the lifecycle. Uploaded pre-submit, which
     * FR-026 permits the owner.
     */
    private function uploadEvidence(string $transactionId): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 't407_');
        self::assertIsString($tempFile);
        file_put_contents($tempFile, 'lifecycle evidence bytes');

        try {
            $response = $this->admin->request(
                'POST',
                '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . $transactionId . '/evidence',
                [
                    'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                    'multipart' => [
                        ['name' => 'file', 'contents' => fopen($tempFile, 'r'), 'filename' => 'receipt.txt'],
                    ],
                ]
            );
        } finally {
            @unlink($tempFile);
        }

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $id = $this->data($response)['id'] ?? null;
        self::assertIsString($id, 'the upload response must carry the evidence id');

        return $id;
    }

    /**
     * @param list<string> $args extra argv after the action
     * @return array<string, mixed>
     */
    private function runFixture(string $action, array $args): array
    {
        $fixture = dirname(__DIR__) . '/Fixture/probe-user-fixture.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . $action . ' '
            . escapeshellarg(self::PROBE_UID);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $output = shell_exec($command . ' 2>&1');

        self::assertIsString($output, 'the probe fixture must run');
        $decoded = json_decode(trim($output), true);
        self::assertIsArray(
            $decoded,
            "probe-user-fixture.php did not return a manifest, so the administrator precondition was "
            . "never established and nothing below would be evidence:\n" . $output
        );

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The path of an absolute URL, because the anonymous client needs a path and the creation hands
     * back a full URL. Asserted to belong to this application rather than followed blindly: following
     * any URL the server returns would also follow one pointing elsewhere.
     */
    private function pathFromUrl(string $url): string
    {
        $parts = parse_url($url);
        self::assertIsArray($parts, 'the creation must disclose a parseable URL');
        $path = (string) ($parts['path'] ?? '');
        self::assertStringContainsString(
            '/apps/pettycash/api/v1/public/evidence/',
            $path,
            'the disclosed URL must address the public evidence route'
        );

        return $path;
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded, 'the response body must be JSON');

        return $decoded['ocs']['data'] ?? [];
    }

    private function errorCode(ResponseInterface $response): ?string
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded['ocs']['data']['error']['code'] ?? null;
    }
}