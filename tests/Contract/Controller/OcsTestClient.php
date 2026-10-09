<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * Builds the authenticated HTTP client used by the live OCS contract tests.
 *
 * Credentials are supplied through the environment by `scripts/dev/phpunit`,
 * which reads them from the gitignored `.env.testing`. Nothing secret is
 * hardcoded here so the tests can never commit a credential.
 */
final class OcsTestClient
{
    /**
     * One client per process.
     *
     * Not an optimisation. FR-073's error-rate measurement counts every request the suite issues, and
     * the first version of this helper authenticated with HTTP basic auth on the **client**, which
     * Nextcloud resolves per request through `User\Session::logClientIn()` →
     * `sleepDelayOrThrowOnMax($remoteAddress, 'login')` — scored **per IP**. A full contract run is 387
     * requests, so the suite exhausted the 30-minute ceiling partway through and every later response
     * was a `429`: 387 issued, 0 counted, figure not measurable. Two finance officers approving at once
     * is not the exotic case here; a test suite authenticating 387 times was.
     *
     * A token resolves through `tryTokenLogin()` → `loginWithToken()`, which never touches the
     * limiter. `PETTYCASH_TEST_TOKEN` is preferred whenever it is set; basic auth remains the fallback
     * so the suite still runs on a lab with no token, and only the *measurement* requires one.
     */
    private static ?Client $shared = null;

    public static function create(): Client
    {
        if (self::$shared !== null) {
            return self::$shared;
        }

        $user = self::env('PETTYCASH_TEST_USER');
        $password = self::env('PETTYCASH_TEST_PASSWORD');
        $baseUrl = self::env('PETTYCASH_TEST_BASE_URL') ?: 'http://localhost';
        $token = self::env('PETTYCASH_TEST_TOKEN');

        if ($user === '' || ($password === '' && $token === '')) {
            throw new RuntimeException(
                'OCS contract tests require PETTYCASH_TEST_USER and either '
                . 'PETTYCASH_TEST_PASSWORD or PETTYCASH_TEST_TOKEN. '
                . 'Copy .env.example to .env.testing and set them.'
            );
        }

        $headers = [
            'Accept' => 'application/json',
            'OCS-APIRequest' => 'true',
        ];

        $config = [
            'base_uri' => $baseUrl,
            'headers' => $headers,
            'http_errors' => false,
            'verify' => false,
            // FR-073's error-rate producer needs every response the suite saw, and Guzzle only offers a
            // stack of them. Recording the stack, rather than each request inside a middleware, keeps the
            // measurement observational: no suite code participates, so none can influence the figure by
            // how it makes a request.
            //
            // Appended, never truncated, so the PHP and Playwright halves of one run share a file.
            'on_stats' => static function (\GuzzleHttp\TransferStats $stats): void {
                RequestLog::record(
                    $stats->getResponse()?->getStatusCode(),
                    $stats->getHandlerErrorData(),
                    // `getEffectiveUri()` rather than the request's own URI: on a redirect it is the URL
                    // actually served, which is the one whose status the figure counts.
                    (string) $stats->getEffectiveUri()->getPath(),
                );
            },
        ];

        if ($token !== '') {
            // **No `auth` key at all.** Leaving it would send basic auth as well, and the password path
            // is the one that scores against the limiter.
            $headers['Authorization'] = 'Bearer ' . $token;
        } else {
            $config['auth'] = [$user, $password];
        }

        // Re-assigned rather than mutated in place. PHP copies arrays on assignment, so
        // `$config['headers'] = $headers` **above** took a snapshot: adding `Authorization` to
        // `$headers` afterwards left the client without it, and every request answered `401` with
        // nothing in the failure to say the header was never sent. Setting it here — after both
        // branches are known — is the only order that cannot lose it.
        $config['headers'] = $headers;

        return self::$shared = new Client($config);
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        return $value === false ? '' : trim($value);
    }
}
