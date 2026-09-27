<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Account;

use OCA\PettyCash\Application\Account\AccountPayloadFormatter;
use OCA\PettyCash\Application\Account\AccountService;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountMovementRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * FR-015's actor gate: only finance or an administrator may charge a petty-cash account.
 *
 * ## Why this file exists, and what it does not claim
 *
 * `T063` implemented the rule and **removing it failed nothing**. The contract suite could not see
 * it: its single credential is a finance officer, and the `finance` fixture state is the only one
 * that grants the `FINANCE` role, so a request in that suite is one the rule always permits. A
 * refusal is unobservable over HTTP with a single acting identity.
 *
 * This file closes the rule at the layer where the rule is written. `AccountService::charge()`
 * performs the check, and it can be called with any actor uid and any set of role assignments, so
 * both the refusal and the two permissions the rule grants are assertable here — and each one fails
 * when the gate is removed, which is the property the contract suite could not provide.
 *
 * **It does not claim endpoint-level coverage.** Whether `POST /accounts/{id}/charges` answers 403
 * to a caller without the role is a different assertion, over real HTTP, as a real second identity.
 * That needs a second credential in `.env.testing` and is the remaining half of `T291`; see the
 * report. What is verified here is that the rule the endpoint delegates to refuses correctly, and
 * that the 403 mapping itself is already covered by the other charge refusals in
 * `AccountWriteControllerTest` (a stale version, an unknown account, a missing command id), all of
 * which reach the same `OcsErrorMapper::forbidden()` arm.
 *
 * Scope note: like `AccountServiceTest`, this exercises the service and not the SQL layer — `T236`
 * is the open task that adds the real-database harness.
 */
final class AccountChargeAuthorizationTest extends TestCase
{
    private InMemoryAccountRepository $accounts;
    private InMemoryAccountMovementRepository $movements;
    private InMemoryRoleAssignmentRepository $roleAssignments;
    private AccountService $service;

    private const OWNER = 'the-account-owner';
    private const CURRENCY = 'ATZ';

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->movements = new InMemoryAccountMovementRepository();
        $this->roleAssignments = new InMemoryRoleAssignmentRepository();

        $this->service = new AccountService(
            $this->accounts,
            $this->movements,
            new InMemoryCurrencyRepository(),
            $this->roleAssignments,
            new IdempotencyService(new InMemoryCommandResultRepository()),
            new InMemoryCurrentUser(self::OWNER),
            new AccountPayloadFormatter()
        );
    }

    /**
     * The refusal.
     *
     * A purchaser is the identity most likely to try this, so the actor holds the account and no
     * finance role — the most realistic version of the attempt.
     */
    public function testAnActorWithNoFinanceRoleIsRefused(): void
    {
        $account = $this->seedAccount();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only finance may charge a petty-cash account');

        $this->charge('the-purchaser', $account->id, 1000);
    }

    /**
     * The refusal is atomic: nothing reaches the ledger and the balance is untouched.
     *
     * A gate that refuses *after* the money has moved is not a gate, and an assertion that only
     * checks the thrown exception would pass against one that had already written the movement.
     */
    public function testARefusedChargeRecordsNothingAndLeavesTheBalanceAlone(): void
    {
        $account = $this->seedAccount();

        try {
            $this->charge('the-purchaser', $account->id, 1000);
            $this->fail('an actor without the finance role must be refused');
        } catch (\LogicException $e) {
            // The refusal itself is asserted by the test above; this one is about the state.
        }

        $this->assertSame(0, $this->movements->count(), 'a refused charge must leave no movement');
        $this->assertSame(
            0,
            $this->accounts->findByPublicId($account->id)->balanceMinor,
            'and must leave the balance exactly as it was'
        );
    }

    /**
     * The permission the rule names first, and the control for the refusal above.
     *
     * Without this, a service that refused every charge would satisfy the refusal test perfectly.
     * That is the failure mode `AccountControllerTest::testTheCallersOwnSecondAccountIsStillReadable`
     * exists to rule out for the ownership check, repeated here for the role check.
     */
    public function testAFinanceOfficerMayCharge(): void
    {
        $account = $this->seedAccount();
        $this->grantRole('the-finance-officer', Role::Finance);

        $result = $this->charge('the-finance-officer', $account->id, 1000);

        $this->assertSame(1000, $result['account']['balance']['amountMinor']);
        $this->assertSame('CHARGE', $result['movement']['type']);
    }

    /**
     * The second permission, which the refusal test above would not notice being dropped.
     *
     * FR-013 puts account funding with administrators and the spec's journey has one person doing
     * both, so an administrator is allowed as well as a finance officer. A test of the refusal alone
     * passes with the administrator branch deleted.
     */
    public function testAnAdministratorMayCharge(): void
    {
        $account = $this->seedAccount();
        $this->grantRole('the-administrator', Role::Administrator);

        $result = $this->charge('the-administrator', $account->id, 1000);

        $this->assertSame(1000, $result['account']['balance']['amountMinor']);
        $this->assertSame(1, $this->movements->count());
    }

    /**
     * A role that is real but is not one of the two is still a refusal.
     *
     * `FINANCE` is not the only role in the schema, and a gate written as "is this actor anybody
     * with any role" would pass every test above while opening the endpoint to every manager in the
     * installation. The two named roles are the whole of the rule, so the boundary is asserted.
     */
    public function testAManagerRoleIsNotEnoughToCharge(): void
    {
        $account = $this->seedAccount();
        // A manager role is the one role that cannot be granted without a cost center, and the cost
        // center is deliberately absent: the gate under test is about the role, not the scope.
        $this->grantRole('the-cost-centre-manager', Role::SeniorManager, 1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only finance may charge a petty-cash account');

        $this->charge('the-cost-centre-manager', $account->id, 1000);
    }

    /**
     * A role that has expired is not a permission.
     *
     * `RoleAssignment::valid_to` is how a departure is enforced, and a gate that reads the role
     * without asking whether it still holds would keep funding accounts for someone who left. The
     * date is fixed in the past so the assertion does not depend on the clock.
     */
    public function testAnExpiredFinanceRoleIsRefused(): void
    {
        $account = $this->seedAccount();
        $this->grantRole('the-departed-finance-officer', Role::Finance, null, '2020-01-01T00:00:00+00:00', '2021-01-01T00:00:00+00:00');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only finance may charge a petty-cash account');

        $this->charge('the-departed-finance-officer', $account->id, 1000);
    }

    /**
     * A role that is not yet effective is not a permission either, in the other direction.
     *
     * An effective-dated assignment (`plan.md` makes future routing a requirement of T082) must not
     * authorise anything before `valid_from`, or a rotation prepared a month early would let the
     * incoming officer fund accounts a week before the role starts.
     */
    public function testAFinanceRoleThatIsNotYetEffectiveIsRefused(): void
    {
        $account = $this->seedAccount();
        $this->grantRole('the-future-finance-officer', Role::Finance, null, '2099-01-01T00:00:00+00:00');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only finance may charge a petty-cash account');

        $this->charge('the-future-finance-officer', $account->id, 1000);
    }

    // ===== SUBJECTS =====

    private function grantRole(
        string $userUid,
        Role $role,
        ?int $costCenterId = null,
        string $validFrom = '2020-01-01T00:00:00+00:00',
        ?string $validTo = null
    ): void {
        $this->roleAssignments->save(
            RoleAssignment::create(
                $userUid,
                $role,
                $costCenterId,
                new \DateTimeImmutable($validFrom),
                $validTo === null ? null : new \DateTimeImmutable($validTo)
            )
        );
    }

    private function seedAccount(): \OCA\PettyCash\Domain\Account\PettyCashAccount
    {
        $account = \OCA\PettyCash\Domain\Account\PettyCashAccount::create(
            (string) \OCA\PettyCash\Domain\Shared\Uuid::random(),
            self::OWNER,
            Currency::create(self::CURRENCY, 'Account Authorization Test', 2)
        );
        $this->accounts->save($account);

        return $account;
    }

    /** @return array<string, mixed> */
    private function charge(string $actorUid, string $accountPublicId, int $amountMinor): array
    {
        return $this->service->charge(
            $actorUid,
            $accountPublicId,
            $amountMinor,
            'test charge',
            new \DateTimeImmutable('2026-01-02T03:04:05+00:00'),
            0,
            'test-command-' . $actorUid
        );
    }
}
