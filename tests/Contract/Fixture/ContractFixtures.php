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
     * @param 'draft'|'submitted'|'correction'|'usd'|'scope'|'finance' $state
     */
    public static function prepare(string $state = 'draft'): void
    {
        if (!in_array($state, ['draft', 'submitted', 'correction', 'usd', 'scope', 'finance'], true)) {
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

    public static function foreignAccountId(): string
    {
        return self::id('foreignAccountId');
    }

    public static function chargeMovementId(): string
    {
        return self::id('chargeMovementId');
    }

    public static function consumptionMovementId(): string
    {
        return self::id('consumptionMovementId');
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

    /**
     * The revision the seeded ledger consumption settled.
     *
     * Not the working revision: `pettycash_account_movements_consumption_uniq` is unique on
     * `source_revision_id`, so a revision that the ledger already claims can never be settled —
     * and the finance suite needs a revision it can settle.
     */
    public static function ledgerRevisionId(): string
    {
        return self::id('ledgerRevisionId');
    }

    /**
     * The evidence row seeded by the `correction` state, and `null` in every other state.
     *
     * Needed by the correction suite to exercise the evidence half of `workflow.md`'s Correction
     * Contract clause 4 — a resubmission must be refused once the transaction's evidence has been
     * deactivated, which is only reachable through a state that has some.
     */
    public static function evidenceId(): ?string
    {
        $id = self::all()['evidenceId'] ?? null;

        return is_string($id) ? $id : null;
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
     * The USD sheet, which the currency read-back assertions need because FR-021
     * makes the sheet the authority for its transactions' currency and the rial
     * default would hide a hardcoded currency.
     */
    public static function usdSheetId(): string
    {
        return self::id('usdSheetId');
    }

    public static function usdTransactionId(): string
    {
        return self::id('usdTransactionId');
    }

    /** The USD transaction's current optimistic-lock value. */
    public static function usdTransactionVersion(): int
    {
        $version = self::all()['usdTransactionVersion'] ?? null;
        if (!is_int($version)) {
            throw new \RuntimeException(
                'The USD fixture is only seeded in the `usd` state; call ContractFixtures::prepare(\'usd\') first.'
            );
        }

        return $version;
    }

    /**
     * A transaction inside a cost center the acting user holds no assignment for,
     * used to prove the decision path refuses an out-of-scope decision server-side.
     */
    public static function outOfScopeTransactionId(): string
    {
        return self::id('otherTransactionId');
    }

    /** The out-of-scope transaction's current optimistic-lock value. */
    public static function outOfScopeTransactionVersion(): int
    {
        $version = self::all()['otherTransactionVersion'] ?? null;
        if (!is_int($version)) {
            throw new \RuntimeException(
                'The unmanaged cost center is only seeded in the `scope` state; call ContractFixtures::prepare(\'scope\') first.'
            );
        }

        return $version;
    }

    /**
     * A transaction awaiting finance review inside the cost center the acting user
     * holds no assignment for.
     *
     * FR-034 makes the finance queue shared rather than scoped to the caller's own
     * cost centers, and the acting user manages CC-100, so this is the only row in
     * the fixture that can tell a shared queue from a scoped one.
     */
    public static function unmanagedFinanceTransactionId(): string
    {
        return self::id('financeSharedTransactionId');
    }

    public static function unmanagedFinanceTransactionVersion(): int
    {
        $version = self::all()['financeSharedTransactionVersion'] ?? null;
        if (!is_int($version)) {
            throw new \RuntimeException(
                'The finance review fixture is only seeded in the `finance` state; call ContractFixtures::prepare(\'finance\') first.'
            );
        }

        return $version;
    }

    /**
     * A transaction awaiting finance review that the senior manager rejected.
     *
     * FR-039 draws the finance decision matrix from the senior outcome: this one may be
     * rejected or returned by finance but must not be approved. A fixture where the
     * senior always agreed cannot tell a policy that reads the outcome from one that
     * assumes approval.
     */
    public static function seniorRejectedFinanceTransactionId(): string
    {
        return self::id('financeRejectedTransactionId');
    }

    public static function seniorRejectedFinanceTransactionVersion(): int
    {
        $version = self::all()['financeRejectedTransactionVersion'] ?? null;
        if (!is_int($version)) {
            throw new \RuntimeException(
                'The finance review fixture is only seeded in the `finance` state; call ContractFixtures::prepare(\'finance\') first.'
            );
        }

        return $version;
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
