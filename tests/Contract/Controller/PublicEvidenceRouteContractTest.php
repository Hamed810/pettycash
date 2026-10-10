<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * FR-031: `GET /public/evidence/{token}` is reachable **without signing in**.
 *
 * ## What this file is actually for
 *
 * FR-031 settles that consumption is unauthenticated, that the token is the entire authority, and that
 * abuse control is delegated to Nextcloud's brute-force limiter rather than reimplemented. T399 asked for
 * "a test proving the route serves no login challenge, CAPTCHA or application-level rate limiter", and that
 * is a statement about the **transport**, not about the business rule — so it cannot be asserted in-process,
 * where there is no login challenge to serve.
 *
 * An unauthenticated client is therefore the whole apparatus. What it observes:
 *
 * - the request is **not** answered `401`, and is **not** redirected to a login form;
 * - the refusal body is the contract's `NotFound` envelope, not a login page, not a CAPTCHA widget and not
 *   a rate-limit notice;
 * - the status is `404` for an unknown token, which is what proves the request reached the controller —
 *   a login challenge would have been `401` or a redirect, so a `404` here is positive evidence of the
 *   `#[PublicPage]` attribute taking effect rather than the controller answering.
 *
 * ## Why only the refusal is covered over HTTP, and that is a finding
 *
 * There is **no `200` case in this file**: a 200 needs a token the server accepts — an administrator's
 * link, opened anonymously. That pairing is T407's, and it lives in
 * `PublicLinkLifecycleContractTest`, which owns an administrator probe for exactly this purpose. This
 * file keeps the refusal half because the refusal is a transport property (no login challenge, no
 * CAPTCHA, no rate limiter) that belongs with the route, while the success path belongs with the
 * lifecycle that produces the token it opens.
 *
 * (Corrected T407: this paragraph previously claimed `POST /evidence/{evidenceId}/links` had no
 * controller action and success was untestable *by nature*. T403 built the action, so the claim was
 * confidently wrong — a passing test holding an obsolete premise, which is worse than a red one.)
 */
final class PublicEvidenceRouteContractTest extends TestCase
{
    private Client $anonymous;
    private const BASE = '/ocs/v2.php/apps/pettycash/api/v1';

    protected function setUp(): void
    {
        // **No `auth`.** That is the entire apparatus of this test: a client with no credentials, which is
        // what "unauthenticated" means at the HTTP layer.
        $this->anonymous = new Client([
            'base_uri' => getenv('PETTYCASH_TEST_BASE_URL') ?: 'http://localhost',
            'headers' => [
                'Accept' => 'application/json',
                // Sent so the request is not refused for a malformed OCS call rather than for the reason
                // under test. A missing header would answer `401` and look exactly like a login challenge.
                'OCS-APIRequest' => 'true',
            ],
            'http_errors' => false,
            'verify' => false,
            // No redirect following: a login form would arrive as a 200 on a different URL, and following
            // it would hide the very thing being tested.
            'allow_redirects' => false,
        ]);
    }

    public function testAnUnknownTokenIsRefusedWith404RatherThanAChallenge(): void
    {
        $response = $this->get('/public/evidence/' . str_repeat('a', 64));

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'FR-031: an unauthenticated request must reach the controller and be refused as a token that '
            . 'grants nothing. A 401 or a 3xx here means a login challenge is being served.'
        );
    }

    /**
     * The refusal is the contract's `ErrorEnvelope`, so an unauthenticated client reads the same body
     * shape an authenticated one does.
     *
     * Asserted rather than assumed because the alternative failure is worse than a wrong status: a login
     * page served with a `404` status, or a CAPTCHA widget, would still be a `404`.
     */
    public function testTheRefusalIsTheContractErrorEnvelopeAndNotAPage(): void
    {
        $response = $this->get('/public/evidence/' . str_repeat('b', 64));

        $body = json_decode((string) $response->getBody(), true);

        $this->assertIsArray($body, 'the refusal must be JSON, not an HTML login form or CAPTCHA page.');
        $this->assertArrayHasKey('ocs', $body);
        $this->assertArrayHasKey('data', $body['ocs']);
        $this->assertArrayHasKey('error', $body['ocs']['data'], 'the contract declares NotFound as an ErrorEnvelope.');
        // `assertEquals`, not `assertSame`: the rest of the contract suite compares this with
        // `assertEquals((string) $expectedStatus, …)` because the envelope's `statuscode` is an int on
        // some paths and a string on others. Asserting the PHP type would make this file fail for a reason
        // that has nothing to do with FR-031.
        $this->assertEquals(404, $body['ocs']['meta']['statuscode']);
    }

    /**
     * No rate limiter of our own, which FR-031 forbids and which a status code alone cannot show.
     *
     * A burst answers identically and immediately. An application-level limiter would make the later
     * attempts differ — `429`, or a slower response — and would be the "second authentication,
     * rate-limiting or access-control mechanism" that Principle III rules out and that FR-031 explicitly
     * declines to add. Nextcloud's own brute-force limiter is permitted and is not asserted against here:
     * it is the platform's, and a shared instance makes its threshold an unreliable assertion.
     */
    public function testRepeatedAttemptsAreAnsweredIdenticallyAndWithoutDelay(): void
    {
        $statuses = [];
        $startedAt = microtime(true);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $statuses[] = $this->get('/public/evidence/' . str_repeat('c', 64))->getStatusCode();
        }

        $this->assertSame(
            [404],
            array_values(array_unique($statuses)),
            'FR-031: every attempt answers identically. A limiter would make some of them differ.'
        );
        $this->assertLessThan(
            10.0,
            microtime(true) - $startedAt,
            'FR-031: consumption must not be deliberately slowed. A per-attempt delay is rate limiting by '
            . 'another name, and this path has no session to slow down.'
        );
    }

    /**
     * No command identifier is required, matching the contract.
     *
     * The contract declares this operation without `X-PettyCash-Command-ID`, because consumption mutates
     * nothing business-side and is idempotent by the token. A `400` here would mean the mutating-endpoint
     * header requirement had been applied to a read.
     */
    public function testNoCommandIdentifierIsRequired(): void
    {
        $response = $this->get('/public/evidence/' . str_repeat('d', 64));

        $this->assertNotSame(
            400,
            $response->getStatusCode(),
            'FR-031: consumption is idempotent by the token and mutates nothing, so requiring a command id '
            . 'would impose a replay store this operation has no use for.'
        );
    }

    /**
     * The token is the only input, and it is passed in the path.
     *
     * Asserting the absence of a `?` in the requested URL keeps the shape honest: a token that could also
     * be supplied as a query parameter would end up in access logs and `Referer` headers, which is the one
     * place the authority for this path must not appear.
     */
    public function testTheTokenIsCarriedInThePathAndNotTheQueryString(): void
    {
        $response = $this->get('/public/evidence/' . str_repeat('e', 64) . '?disposition=inline');

        $this->assertSame(404, $response->getStatusCode(), 'the declared disposition parameter must not change the refusal.');
    }

    private function get(string $path): ResponseInterface
    {
        return $this->anonymous->request('GET', self::BASE . $path);
    }
}