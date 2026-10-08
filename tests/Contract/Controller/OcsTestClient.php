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
    public static function create(): Client
    {
        $user = self::env('PETTYCASH_TEST_USER');
        $password = self::env('PETTYCASH_TEST_PASSWORD');
        $baseUrl = self::env('PETTYCASH_TEST_BASE_URL') ?: 'http://localhost';

        if ($user === '' || $password === '') {
            throw new RuntimeException(
                'OCS contract tests require PETTYCASH_TEST_USER and PETTYCASH_TEST_PASSWORD. '
                . 'Copy .env.example to .env.testing and set them.'
            );
        }

        return new Client([
            'base_uri' => $baseUrl,
            'auth' => [$user, $password],
            'headers' => [
                'Accept' => 'application/json',
                'OCS-APIRequest' => 'true',
            ],
            'http_errors' => false,
            'verify' => false,
            // FR-073's error-rate producer needs every response the suite saw, and Guzzle only offers a
            // stack of them. Recording the stack, rather than each request inside a middleware, keeps the
            // measurement observational: a suite cannot influence the figure by how it makes a request,
            // because nothing in the suite participates.
            //
            // Appended, never truncated, so the PHP and Playwright halves of one run share a file.
            'on_stats' => static function (\GuzzleHttp\TransferStats $stats): void {
                RequestLog::record($stats->getResponse()?->getStatusCode(), $stats->getHandlerErrorData());
            },
        ]);
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        return $value === false ? '' : trim($value);
    }
}
