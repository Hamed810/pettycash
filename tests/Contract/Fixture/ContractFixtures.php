<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Fixture;

use OCA\PettyCash\Domain\Shared\Uuid;

/**
 * Gives the live-HTTP OCS contract tests the identifiers of the fixtures they
 * assert against.
 *
 * The suites talk to a running Nextcloud over HTTP, so they cannot build domain
 * aggregates in-process. Seeding therefore happens in a separate process (see
 * `seed-contract-fixtures.php`, which bootstraps the server) and this helper
 * caches the resulting manifest for the duration of the run.
 *
 * Seeding is idempotent, so the cost is one subprocess launch per test process.
 */
final class ContractFixtures
{
    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /**
     * Put the fixtures back into a known state and cache the resulting manifest.
     *
     * The contract suites mutate the same sheet and transaction, so every test
     * that asserts on a mutated record calls this first with the state that test
     * needs. Without it the suites assert against whatever the previously executed
     * test happened to leave behind, which is order-dependent and hides real
     * behaviour.
     *
     * @param 'draft'|'submitted'|'correction' $state
     */
    public static function prepare(string $state = 'draft'): void
    {
        if (!in_array($state, ['draft', 'submitted', 'correction'], true)) {
            throw new \RuntimeException("Unknown contract fixture state '{$state}'.");
        }

        self::$manifest = self::seed($state);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$manifest ??= self::seed('draft');
    }

    /**
     * @return array<string, mixed>
     */
    private static function seed(string $state): array
    {
        $user = getenv('PETTYCASH_TEST_USER');
        if ($user === false || trim($user) === '') {
            throw new \RuntimeException(
                'The OCS contract tests require PETTYCASH_TEST_USER. '
                . 'Run them through scripts/dev/phpunit, which loads .env.testing.'
            );
        }

        $seeder = dirname(__DIR__) . '/Fixture/seed-contract-fixtures.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($seeder) . ' '
            . escapeshellarg($user) . ' ' . escapeshellarg($state) . ' 2>&1';

        $output = shell_exec($command);
        if ($output === null || trim($output) === '') {
            throw new \RuntimeException('Contract fixture seeding produced no output.');
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded) || !isset($decoded['accountId'])) {
            throw new \RuntimeException(
                "Contract fixture seeding did not return a manifest:\n" . $output
            );
        }

        return $decoded;
    }

    /**
     * Read one fixture identifier, failing loudly rather than silently asserting
     * against a placeholder UUID that matches no record.
     */
    public static function id(string $key): string
    {
        $manifest = self::all();
        if (!isset($manifest[$key]) || !is_string($manifest[$key])) {
            throw new \RuntimeException("Unknown contract fixture '{$key}'.");
        }

        return $manifest[$key];
    }

    public static function accountId(): string
    {
        return self::id('accountId');
    }

    public static function secondAccountId(): string
    {
        return self::id('secondAccountId');
    }

    public static function sheetId(): string
    {
        return self::id('sheetId');
    }

    public static function transactionId(): string
    {
        return self::id('transactionId');
    }

    public static function revisionId(): string
    {
        return self::id('revisionId');
    }

    public static function costCenterId(): string
    {
        return self::id('costCenterId');
    }

    public static function expenseTypeId(): string
    {
        return self::id('expenseTypeId');
    }

    /**
     * The sheet's current optimistic-lock value, which a mutable-resource command
     * must echo back as `expectedVersion` to avoid a stale-version rejection.
     */
    public static function sheetVersion(): int
    {
        return (int) (self::all()['sheetVersion'] ?? -1);
    }

    /** The transaction's current optimistic-lock value. */
    public static function transactionVersion(): int
    {
        return (int) (self::all()['transactionVersion'] ?? -1);
    }

    /**
     * A fresh idempotency key for a single request.
     *
     * The suites deliberately do not reuse fixed command IDs: a command result is
     * immutable, so a hardcoded ID would make the second run of a test replay the
     * first run's stored response instead of exercising the endpoint again.
     */
    public static function commandId(): string
    {
        return (string) Uuid::random();
    }

    /**
     * A UUID that is well formed but matches no stored record, for the
     * not-found assertions.
     */
    public static function unknownId(): string
    {
        return '00000000-0000-4000-8000-000000000000';
    }
}
