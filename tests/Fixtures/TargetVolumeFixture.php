<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Fixtures;

/**
 * T127: deterministic target-volume fixture generation.
 *
 * Produces up to 100 active users and 10,000 annual transactions with a fixed
 * seed, so every run yields the same bytes. Pure data: no database, no clock,
 * no randomness outside the seeded generator. Performance and stress suites
 * consume these payloads rather than inventing their own volumes.
 */
final class TargetVolumeFixture
{
    public const MAX_USERS = 100;
    public const ANNUAL_TRANSACTIONS = 10000;

    /**
     * @return list<string> deterministic user UIDs: user-001 … user-100
     */
    public static function users(int $count = self::MAX_USERS): array
    {
        $count = max(0, min(self::MAX_USERS, $count));
        $users = [];
        for ($i = 1; $i <= $count; $i++) {
            $users[] = sprintf('user-%03d', $i);
        }

        return $users;
    }

    /**
     * Deterministic transaction payloads for one year.
     *
     * @return list<array{uid: string, costCenter: string, amountMinor: int, currency: string, expenseDate: string, description: string}>
     */
    public static function annualTransactions(int $count = self::ANNUAL_TRANSACTIONS, int $seed = 20261011): array
    {
        $count = max(0, min(self::ANNUAL_TRANSACTIONS, $count));
        $rng = self::seeded($seed);
        $users = self::users();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $users[$rng() % count($users)];
            $amount = 1000 + ($rng() % 999000);
            $month = 1 + ($rng() % 12);
            $day = 1 + ($rng() % 28);
            $rows[] = [
                'uid' => $user,
                'costCenter' => sprintf('CC-%02d', 1 + ($rng() % 8)),
                'amountMinor' => $amount,
                'currency' => 'IRR',
                'expenseDate' => sprintf('2026-%02d-%02d', $month, $day),
                'description' => sprintf('volume probe %05d', $i),
            ];
        }

        return $rows;
    }

    /**
     * Deterministic integer generator (xorshift32): same seed, same sequence,
     * on every platform. `rand()`/`mt_rand()` are global-state and version
     * sensitive, so they must not be used for fixtures.
     *
     * @return callable(): int
     */
    private static function seeded(int $seed): callable
    {
        $state = $seed !== 0 ? $seed : 1;

        return static function () use (&$state): int {
            $state ^= ($state << 13) & 0xFFFFFFFF;
            $state ^= ($state >> 17) & 0xFFFFFFFF;
            $state ^= ($state << 5) & 0xFFFFFFFF;

            return $state & 0x7FFFFFFF;
        };
    }
}
