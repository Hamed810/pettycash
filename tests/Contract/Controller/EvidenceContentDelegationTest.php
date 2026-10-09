<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * FR-074 (clarified 2026-10-08): evidence content handling is delegated to Nextcloud, not to PettyCash.
 *
 * ## What the requirement settled
 *
 * V1 imposes **no file-type allow-list, no extension allow-list, and no content scanning of its own**. An
 * upload MUST be accepted whenever the host file service accepts it, whatever its type; and where the
 * platform's antivirus capability is enabled, its verdict governs access.
 *
 * ## Why a "nothing is forbidden" requirement needs a test
 *
 * A delegation is the kind of rule that survives only while it is asserted. Nothing in the code *forbids* an
 * allow-list — there is simply no allow-list — so the failure mode is **additive**: someone adds
 * `if (!$this->isAllowed($mime)) { throw ... }` to a future upload path, every existing test still passes
 * because the fixtures upload `.txt`, `.png` and `.pdf`, and a purchaser can no longer attach the `.zip`
 * their scanner or their receipts actually produced. The absence of a defect is indistinguishable from the
 * absence of a check.
 *
 * So the files chosen here are the two the clarification names explicitly — **an executable and an archive**.
 * Both sit outside every plausible allow-list, which is precisely why they are the interesting cases: a
 * future allow-list of "documents and images" would reject both and fail these tests.
 *
 * ## Why the disposition assertion is in the same file
 *
 * The delegation is only safe *because* FR-060 serves anything outside images, PDF, and plain text as
 * `application/octet-stream` with an `attachment` disposition, from the file's **stored** media type rather
 * than from anything the uploader asserts. Accepting an arbitrary upload is only defensible if serving it
 * cannot execute it in the viewer's session. Asserting acceptance without asserting disposition would leave
 * the safety argument untested and make "we accept everything" look like a hole rather than a decision.
 */
final class EvidenceContentDelegationTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

    /**
     * An `.exe` is accepted, stored, and served as a download.
     *
     * The strongest single case in the file: an executable is the type an allow-list would exclude first, so
     * a future allow-list fails here before it affects anything a user would notice.
     */
    public function testAnExecutableIsAcceptedAndServedAsADownload(): void
    {
        $evidenceId = $this->upload('invoice.exe', 'MZ not really an executable');

        $served = $this->download($evidenceId);

        self::assertSame(200, $served->getStatusCode());
        // `getHeaderLine()`, not `getHeader()`: the latter returns an array and would make this assertion
        // fail on a type mismatch while appearing to be about the disposition.
        $disposition = $served->getHeaderLine('Content-Disposition');
        self::assertStringContainsString(
            'attachment',
            $disposition,
            'FR-060: a stored type outside images/PDF/plain text must be offered as a download'
        );
        self::assertStringNotContainsString(
            'inline',
            $disposition,
            'serving an attacker-supplied executable inline from the Nextcloud origin is the one thing '
            . 'this delegation must never permit'
        );
    }

    /**
     * An archive is accepted too, because a scanner or an expense bundle is routinely a `.zip`.
     */
    public function testAnArchiveIsAccepted(): void
    {
        $evidenceId = $this->upload('receipts.zip', "PK not really an archive");

        self::assertSame(200, $this->download($evidenceId)->getStatusCode(), 'a .zip must be attachable');
    }

    /**
     * A file with no extension at all is still accepted.
     *
     * Included because an extension-based check is the *other* obvious way someone might implement the rule
     * V1 declined to implement. A file called `receipt` is not a thing a purchaser would attach by accident.
     */
    public function testAFileWithNoExtensionIsAccepted(): void
    {
        $evidenceId = $this->upload('receipt', 'plain bytes, no extension');

        self::assertSame(200, $this->download($evidenceId)->getStatusCode());
    }

    /**
     * The application records **no scan verdict of its own**.
     *
     * Asserted negatively: the upload response must not carry a field claiming the file was scanned, because
     * V1 does not scan and a field implying otherwise would let a UI render "safe" on the strength of a check
     * that never ran. Where the platform's antivirus capability is enabled, *its* verdict is authoritative —
     * and it arrives through the platform, not through this response.
     */
    public function testTheUploadResponseCarriesNoVerdictOfItsOwn(): void
    {
        $response = $this->uploadResponse('invoice.exe', 'bytes');

        self::assertSame(201, $response->getStatusCode());
        $body = (string) $response->getBody();

        foreach (['scanned', 'scanVerdict', 'virusScan', 'infected', 'malwareDetected'] as $claim) {
            self::assertStringNotContainsStringIgnoringCase(
                $claim,
                $body,
                'V1 scans nothing, so the response must not carry a field that reads as a scan verdict'
            );
        }
    }

    /**
     * The 100 MB ceiling still applies, and it is *PettyCash's*, not the host's.
     *
     * Included so this file cannot be read as "the upload path accepts anything". FR-074's ceiling is the one
     * limit V1 does own, and the two must not be confused — a delegation on **content** is not a delegation
     * on **size**.
     */
    public function testTheContentDelegationDoesNotAffectTheSizeCeiling(): void
    {
        $response = $this->uploadResponse('receipt.txt', str_repeat('x', 101 * 1024 * 1024));

        self::assertSame(
            413,
            $response->getStatusCode(),
            'FR-074: 100 MB is PettyCash\'s own ceiling and is unrelated to the content delegation. '
            . 'Expected 413 rather than 422 because an upload that exceeds the ceiling is too large for the '
            . 'transport to accept, which is what 413 means — and which is what this test found the '
            . 'application already answering.'
        );
        self::assertStringContainsString('100', (string) $response->getBody());
    }

    private function upload(string $filename, string $contents): string
    {
        $response = $this->uploadResponse($filename, $contents);

        self::assertSame(
            201,
            $response->getStatusCode(),
            'FR-074: whatever the host accepts, PettyCash accepts. Answered instead: '
            . (string) $response->getBody()
        );

        $id = json_decode((string) $response->getBody(), true)['ocs']['data']['id'] ?? null;
        self::assertIsString($id, 'the upload response must carry the evidence id');

        return $id;
    }

    private function uploadResponse(string $filename, string $contents): ResponseInterface
    {
        $tempFile = tempnam(sys_get_temp_dir(), 't420_');
        file_put_contents($tempFile, $contents);

        try {
            return $this->client->request(
                'POST',
                '/ocs/v2.php/apps/pettycash/api/v1/transactions/'
                    . ContractFixtures::transactionId() . '/evidence',
                [
                    'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => fopen($tempFile, 'r'),
                            'filename' => $filename,
                        ],
                    ],
                ]
            );
        } finally {
            @unlink($tempFile);
        }
    }

    private function download(string $evidenceId): ResponseInterface
    {
        return $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId . '/content'
        );
    }
}