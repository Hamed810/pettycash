<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Account;

use OCA\PettyCash\Application\Account\AccountPayloadFormatter;
use OCA\PettyCash\Application\Account\AccountService;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountMovementRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the invariant T289 established: the contract's `Account` and `Movement` arrays are defined
 * exactly once, in `AccountPayloadFormatter`.
 *
 * ## Why a structural test, and not only a behavioural one
 *
 * A behavioural test alone cannot see this defect. Both copies build the same array, so a projection
 * that has drifted from its twin still answers every assertion anyone can make about the endpoint —
 * and the two differ only in the field one of them forgot, which is exactly the field nobody asked
 * about. That is not hypothetical: `TransactionPayloadFormatter` exists because the transaction
 * payload had a flat `revision` object with no `allowedActions`, `decisions` or `evidence`, and every
 * affected template dereferenced `undefined` on a live response while the endpoint answered 200 the
 * whole time.
 *
 * So the guard reads the source. `AccountPayloadFormatter` is the single owner of the `Account` and
 * `Movement` shapes; if a second definition of either appears anywhere under `lib/`, this fails and
 * names the file — a far cheaper signal than a template rendering `undefined` in a browser nobody is
 * watching.
 *
 * ## Why there is a behavioural half as well
 *
 * A text match cannot tell a real projection from a comment, and it cannot tell a projection that is
 * *called* from one that merely exists. The two behavioural tests below ask the formatter and the
 * service's own read paths for the same aggregate and require the identical array, so a service that
 * rebuilt the shape inline while the formatter went on alone is caught even if its copy was written
 * differently enough to dodge the grep.
 */
final class AccountPayloadSingleDefinitionTest extends TestCase
{
    private InMemoryAccountRepository $accounts;
    private InMemoryAccountMovementRepository $movements;
    private InMemoryCurrencyRepository $currencies;
    private InMemoryRoleAssignmentRepository $roleAssignments;
    private AccountService $service;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->movements = new InMemoryAccountMovementRepository();
        $this->currencies = new InMemoryCurrencyRepository();
        $this->roleAssignments = new InMemoryRoleAssignmentRepository();

        $this->service = new AccountService(
            $this->accounts,
            $this->movements,
            $this->currencies,
            $this->roleAssignments,
            new IdempotencyService(new InMemoryCommandResultRepository()),
            new InMemoryCurrentUser(self::PROBE_USER),
            new AccountPayloadFormatter(),
            new \OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter()
        );
    }

    private const PROBE_USER = 'single-definition-probe';
    private const PROBE_CODE = 'SDX';

    /**
     *
     * The **quoted array-key** form is the discriminator, not the bare member name: `balanceAfter`
     * and `sourceRevisionId` are also property names on the `AccountMovement` aggregate and column
     * names in the persistence adapter, so an unqualified match reports the domain and the row mapper
     * as offenders. Only a file that writes the key inside an array literal is building a contract
     * payload, which is what this invariant is about.
     *
     * `balanceAfter` is the primary witness because it is a member of `Movement` alone;
     * `sourceRevisionId` is a second, independent one, because a single grep-able token is a thin
     * thing to hang a whole-file invariant on.
     *
     * @return array<string, array{0: string}>
     */
    public static function movementShapeKeyProvider(): array
    {
        return [
            'Movement.balanceAfter' => ["'balanceAfter' =>"],
            'Movement.sourceRevisionId' => ["'sourceRevisionId' =>"],
        ];
    }

    #[DataProvider('movementShapeKeyProvider')]
    public function testTheMovementShapeIsDefinedInOneFileOnly(string $key): void
    {
        $owners = $this->filesDefining($key);

        $this->assertSame(
            [$this->formatterPath()],
            $owners,
            sprintf(
                "%s is an array key of the contract's Movement, so a file that writes it is building "
                . "that shape, and it must be built only by %s. %s",
                $key,
                $this->formatterPath(),
                $this->describeOwners($owners)
            )
        );
    }

    /**
     * The `Account` shape, keyed the same way.
     *
     * `'userId' => $account->userUid` is the `Account` projection written out: the only member that
     * is a top-level key read off the aggregate rather than part of the nested `Money`. `active` and
     * `version` appear elsewhere under `lib/` as array keys for unrelated reasons, so they cannot
     * carry the check.
     */
    public function testTheAccountShapeIsDefinedInOneFileOnly(): void
    {
        $owners = $this->filesDefining('\'userId\' => $account->userUid');

        $this->assertSame(
            [$this->formatterPath()],
            $owners,
            sprintf(
                'The contract\'s Account shape must be built only by %s, and every caller must '
                . 'delegate to it. %s',
                $this->formatterPath(),
                $this->describeOwners($owners)
            )
        );
    }

    /**
     * The invariant behaviourally: the formatter and the service's own read paths must produce the
     * identical array for the identical aggregate.
     */
    public function testTheServiceAndTheFormatterAgreeOnTheAccountShape(): void
    {
        $account = $this->seedAccount(0, 4242);

        $listed = $this->service->listForUser(self::PROBE_USER)['items'][0];

        $this->assertSame(
            (new AccountPayloadFormatter())->account($account),
            $listed,
            'the listing must be the formatter\'s Account, byte for byte, not a second build of it'
        );
    }

    public function testTheServiceAndTheFormatterAgreeOnTheMovementShape(): void
    {
        $account = $this->seedAccount(3, 7000);
        $movement = $account->charge(Money::of(1500, $account->currencyCode()), 'probe-command', 'probe charge');
        $this->movements->save($movement);

        $listed = $this->service->listMovements(self::PROBE_USER, $account->id)['items'][0];

        $this->assertSame(
            (new AccountPayloadFormatter())->movement($movement, $account),
            $listed,
            'the ledger must be the formatter\'s Movement, byte for byte, not a second build of it'
        );
    }

    /**
     * The write path answers the same shapes as the reads, which is the fourth caller the duplication
     * had reached by the time T289 ran.
     *
     * `charge()` and `createFor()` return through `IdempotencyService`, so their arrays are not only
     * the response — they are the *stored* result a replay is answered from. That makes an
     * independently-built copy here more than a style question: it is a second definition of the
     * answer a retried command will be given, days later, from the database.
     */
    public function testTheWritePathReturnsTheSameShapesAsTheReads(): void
    {
        // `createFor` refuses a currency that is not configured and active, so the probe currency has
        // to exist as configuration and not merely as an aggregate property.
        $this->currencies->save(Currency::create(self::PROBE_CODE, 'Single Definition Probe', 2));
        $this->grantFinanceRole();

        $created = $this->service->createFor(self::PROBE_USER, self::PROBE_USER, self::PROBE_CODE);
        $this->assertSame(
            (new AccountPayloadFormatter())->account($this->accounts->findByPublicId($created['id'])),
            $created,
            'a created account is the formatter\'s Account too'
        );

        $charged = $this->service->charge(
            self::PROBE_USER,
            $created['id'],
            1000,
            'probe charge',
            new \DateTimeImmutable('2026-01-02T03:04:05+00:00'),
            $created['version'],
            'probe-charge-command'
        );

        $this->assertSame(
            ['account', 'movement'],
            array_keys($charged),
            'AccountMutationResult is the account and the movement, in that order'
        );
        $this->assertSame(
            (new AccountPayloadFormatter())->account($this->accounts->findByPublicId($created['id'])),
            $charged['account'],
            'and both members are the formatter\'s shapes rather than private twins of them'
        );
        $this->assertSame(
            'CHARGE',
            $charged['movement']['type'],
            'a charge is a CHARGE row, and the projection is the one that says so'
        );
    }

    /**
     * FR-015: only finance or an administrator may charge.
     *
     * The acting identity is given the role rather than the rule being bypassed, because this test is
     * about the shape of the answer and not about the gate. `T291` is the open task that covers the
     * refusal itself, and it needs a second acting identity to do it honestly.
     */
    private function grantFinanceRole(): void
    {
        $this->roleAssignments->save(
            RoleAssignment::create(
                self::PROBE_USER,
                Role::Finance,
                null,
                new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
                null
            )
        );
    }

    /**
     * A persisted account for the probe user, with the surrogate key the ledger read needs.
     *
     * The `dbId` and the movement repository's account map are set by hand because these are
     * in-memory doubles: the real adapter assigns the surrogate key on insert and the real movement
     * adapter joins on it. The ledger read refuses an account with no `dbId`, so without this the
     * movement assertions would die in the service rather than on a behaviour.
     */
    private function seedAccount(int $minorUnit, int $balanceMinor): PettyCashAccount
    {
        $account = PettyCashAccount::create(
            (string) Uuid::random(),
            self::PROBE_USER,
            Currency::create(self::PROBE_CODE, 'Single Definition Probe', $minorUnit)
        );
        $account->balanceMinor = $balanceMinor;
        $account->dbId = 1;

        $this->accounts->save($account);
        $this->movements->mapAccount(1, $account->id);

        return $account;
    }

    // ===== STRUCTURE =====

    /**
     * Every file under `lib/` that writes the given literal, as repository-root-relative paths.
     *
     * @return list<string>
     */
    private function filesDefining(string $literal): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (!str_contains((string) file_get_contents($file->getPathname()), $literal)) {
                continue;
            }
            $found[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
        }

        sort($found);

        return $found;
    }

    private function formatterPath(): string
    {
        return 'lib/Application/Account/AccountPayloadFormatter.php';
    }

    /** @param list<string> $owners */
    private function describeOwners(array $owners): string
    {
        $others = array_values(array_filter($owners, fn (string $owner): bool => $owner !== $this->formatterPath()));

        if ($others === []) {
            return $owners === [] ? 'No file under lib/ defines it at all.' : '';
        }

        return 'It is also built in: ' . implode(', ', $others) . '.';
    }
}
