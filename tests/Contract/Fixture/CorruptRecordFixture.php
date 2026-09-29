<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Fixture;

use RuntimeException;

/**
 * Drives the deliberately-unreadable record the data-integrity contract test needs, from the PHPUnit
 * process.
 *
 * ## Why this is a subprocess and not a `setUp()`
 *
 * The probe writes invalid data into the MariaDB that the live contract suite also reads, so it cannot
 * live in a test's `setUp()` and be cleaned in `tearDown()` without a process that can be killed: the
 * PHPUnit process deliberately does not bootstrap Nextcloud (`tests/bootstrap.php` replaces the server
 * with in-memory doubles), so it has no database handle to write with or to undo the write with. The
 * same split the seeder and the round-trip runner already use applies here.
 *
 * ## Why the removal is the guaranteed half
 *
 * T308 implemented a 422 for a report over an unreadable record and could not verify it, because
 * producing one means writing exactly the fault the persistence round trip exists to remove. An earlier
 * attempt at a one-off injector was abandoned for the right reason: a probe whose failure mode is
 * "leaves a landmine that breaks three reports" is the same trade that produced the seven leaked sheets.
 *
 * So the ordering is deliberate and is not negotiable:
 *
 * - {@see self::insert()} **repairs before it writes.** A previous run killed between the write and the
 *   removal is swept first, so the fault lasts one run rather than accumulating one per attempt.
 * - {@see self::remove()} **verifies rather than assumes.** It exits non-zero if anything it owns
 *   survived, and this method turns that into a thrown exception, so a failed removal fails the test that
 *   asked for it instead of quietly leaving the fault behind.
 * - {@see self::sweep()} is the repair path on its own, for a suite that must not itself write the
 *   fault but still has to be immune to a crashed one.
 *
 * The definitions those three share with `roundtrip-leftovers.php` and `roundtrip-runner.php` live in
 * `tests/Support/LeakedRows.php`, so a leftover is findable by name from a log line, removable with
 * `roundtrip-leftovers.php --delete`, and covered by the round-trip suite's own post-condition.
 */
final class CorruptRecordFixture
{
    /**
     * Write the probe and return what it wrote.
     *
     * @return array{sheetId: string, transactionId: string, revisionId: string, revisionDbId: int, danglingCurrencyId: int, repairedBeforeWriting: int}
     */
    public static function insert(): array
    {
        $manifest = self::run('insert');

        foreach (['sheetId', 'transactionId', 'revisionId', 'revisionDbId', 'danglingCurrencyId'] as $key) {
            if (!array_key_exists($key, $manifest)) {
                throw new RuntimeException(
                    "The corrupt-record fixture did not report '{$key}'.\n" . self::describe($manifest)
                );
            }
        }

        return [
            'sheetId' => (string) $manifest['sheetId'],
            'transactionId' => (string) $manifest['transactionId'],
            'revisionId' => (string) $manifest['revisionId'],
            // The surrogate key, so the caller can assert the *absence* of this number from a response
            // body. `UnreadableRecordData` names it, and T029's whole point was that it must not reach a
            // caller -- which cannot be asserted against a number the test does not know.
            'revisionDbId' => (int) $manifest['revisionDbId'],
            'danglingCurrencyId' => (int) $manifest['danglingCurrencyId'],
            'repairedBeforeWriting' => (int) ($manifest['repairedBeforeWriting'] ?? 0),
        ];
    }

    /**
     * Remove the probe, and fail loudly if anything survived.
     *
     * @return int rows removed
     */
    public static function remove(): int
    {
        $manifest = self::run('remove');

        $removed = $manifest['removed'] ?? null;
        if (!is_array($removed)) {
            throw new RuntimeException(
                'The corrupt-record fixture did not report what it removed.' . self::describe($manifest)
            );
        }

        $leftovers = $manifest['leftovers'] ?? null;
        if (!is_array($leftovers) || $leftovers !== []) {
            // The subprocess already exits non-zero in this case, so reaching here means the guard was
            // removed rather than tripped. Both are worth failing on, and they are different failures,
            // so both are named.
            throw new RuntimeException(
                'The corrupt-record probe is still in the database after removal: '
                . json_encode($leftovers, JSON_UNESCAPED_SLASHES)
                . '. Every report the probe can reach will answer 422 until it is removed; run '
                . 'scripts/dev/in-container php tests/Integration/Persistence/roundtrip-leftovers.php --delete.'
            );
        }

        return array_sum(array_map(static fn ($n): int => (int) $n, $removed));
    }

    /**
     * Remove the probe without writing one, and report what was there.
     *
     * For a suite that must be immune to a crashed probe but has no business creating one.
     *
     * @return int rows removed
     */
    public static function sweep(): int
    {
        return self::remove();
    }

    /**
     * @return array<string, mixed>
     */
    private static function run(string $action): array
    {
        $user = getenv('PETTYCASH_TEST_USER');
        if ($user === false || trim($user) === '') {
            throw new RuntimeException(
                'The corrupt-record fixture requires PETTYCASH_TEST_USER. '
                . 'Run it through scripts/dev/phpunit, which loads .env.testing.'
            );
        }

        $fixture = dirname(__DIR__) . '/Fixture/corrupt-record-fixture.php';
        if (!is_file($fixture)) {
            throw new RuntimeException("The corrupt-record fixture is missing at {$fixture}.");
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' '
            . escapeshellarg(trim($user)) . ' ' . escapeshellarg($action) . ' 2>&1';
        $output = shell_exec($command);

        if ($output === null || trim($output) === '') {
            throw new RuntimeException(
                "The corrupt-record fixture produced no output for '{$action}'. It exits with a message "
                . 'on stderr when it cannot reach the database, so an empty result means the subprocess '
                . 'did not run at all.'
            );
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("The corrupt-record fixture did not return JSON:\n" . $output);
        }
        if (isset($decoded['error'])) {
            throw new RuntimeException("The corrupt-record fixture failed on '{$action}': " . $decoded['error']);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $manifest */
    private static function describe(array $manifest): string
    {
        return json_encode($manifest, JSON_UNESCAPED_SLASHES) . "\n"
            . 'A NULL in that list means the probe did not reach the report path at all, which is a '
            . 'different failure from the one it exists to produce -- so it is not treated as a pass.';
    }
}
