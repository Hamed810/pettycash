<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Account;

use LogicException;
use OCA\PettyCash\Application\Account\AccountService;
use OCA\PettyCash\Application\Account\AccountAlreadyExists;
use OCA\PettyCash\Application\Account\AccountPayloadFormatter;
use OCA\PettyCash\Application\Account\StaleAccountVersion;
use OCA\PettyCash\Application\Account\UnknownAccount;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountMovementRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;

/**
 * T409 / FR-013: an administrator deactivates or reactivates a petty-cash account.
 *
 * ## Why this file exists
 *
 * Before it, no artifact could deactivate an account: the route table held index/create/charge/movements
 * with no PATCH, and the sole writer of `active = false` was owner deletion. Currencies, cost centres and
 * expense types each had an update endpoint carrying an `active` toggle, so accounts were the only
 * configurable resource without one — and FR-020's inactive-account refusal had no reachable trigger.
 *
 * ## Why the gate is on the role rather than on ownership
 *
 * There is deliberately **no** self-service branch: a purchaser retiring their own account would strand
 * themselves with no sheet-creation path except through the administrator they bypassed. Deactivation is a
 * deliberate, audited act, and deliberation is what the administrator role marks — so even the holder is
 * refused, and that refusal is asserted rather than left to inference.
 */
final class AccountActiveStateTest extends TestCase
{
    private const ADMIN = 'admin-1';
    private const HOLDER = 'purchaser-1';

    private InMemoryAccountRepository $accounts;
    private InMemoryAuditWriter $audit;
    private AccountService $service;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->audit = new InMemoryAuditWriter();

        $roles = new InMemoryRoleAssignmentRepository();
        $roles->save(RoleAssignment::create(
            self::ADMIN,
            Role::Administrator,
            null,
            new \DateTimeImmutable('2020-01-01', new \DateTimeZone('UTC')),
            null
        ));

        $this->service = new AccountService(
            $this->accounts,
            new InMemoryAccountMovementRepository(),
            new InMemoryCurrencyRepository(),
            $roles,
            new \OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck(),
            new IdempotencyService(new InMemoryCommandResultRepository()),
            new InMemoryCurrentUser(self::ADMIN),
            new AccountPayloadFormatter(),
            $this->audit
        );
    }

    // ===== the transitions =====

    /**
     * A platform administrator without an assignment row holds the
     * Administrator role too: the session, report scope and configuration
     * controllers all resolve it from the admin group, so an admin who can
     * configure currencies but cannot deactivate an account is the
     * contradiction FR-013 forbids. `test-admin` is the uid
     * InMemoryAdministratorCheck recognises; it holds no assignment row here.
     */
    public function testAPlatformAdministratorWithoutAnAssignmentRowDeactivates(): void
    {
        $account = $this->openAccount();

        $result = $this->service->setActive('test-admin', $account->id, false, 0);

        self::assertFalse($this->accounts->findByPublicId($account->id)->active);
        self::assertFalse($result['active'], 'the returned shape must show the new state');
    }

    public function testAnAdministratorDeactivatesAnActiveAccount(): void
    {
        $account = $this->openAccount();

        $result = $this->service->setActive(self::ADMIN, $account->id, false, 0);

        self::assertFalse($this->accounts->findByPublicId($account->id)->active);
        self::assertSame(1, $this->accounts->findByPublicId($account->id)->lockVersion);
        self::assertFalse($result['active'], 'the returned shape must show the new state');
    }

    /**
     * Re-opening is not a second account: `createFor()` on a retired pair
     * reactivates it through the audited `setActive()` path — same public id,
     * ledger and balance preserved — and reports `created: false` so the
     * controller answers 200 rather than 201.
     */
    public function testAnAdministratorReopensARetiredPairThroughCreateFor(): void
    {
        $account = $this->openAccount();
        $this->service->setActive(self::ADMIN, $account->id, false, 0);

        $result = $this->service->createFor(self::ADMIN, self::HOLDER, 'IRR');

        self::assertFalse($result['created'], 'a re-opened pair is not created');
        self::assertSame($account->id, $result['account']['id'], 're-opening keeps the public id');
        self::assertTrue($result['account']['active']);
        self::assertCount(1, $this->reactivationEvents(), 're-opening records a reactivation, not a creation');
    }

    public function testTheHolderCannotSelfReactivateARetiredPair(): void
    {
        $account = $this->openAccount();
        $this->service->setActive(self::ADMIN, $account->id, false, 0);

        $this->expectException(AccountAlreadyExists::class);
        $this->service->createFor(self::HOLDER, self::HOLDER, 'IRR');
    }

    public function testAnAdministratorReactivatesWithACallerSuppliedReason(): void
    {
        $account = $this->openAccount();
        $this->service->setActive(self::ADMIN, $account->id, false, 0);

        $this->service->setActive(self::ADMIN, $account->id, true, 1, 'Returned from leave; spending again');

        $events = $this->reactivationEvents();
        self::assertCount(1, $events);
        self::assertSame('Returned from leave; spending again', $events[0]['explanation']);
        self::assertTrue($this->accounts->findByPublicId($account->id)->active);
    }

    // ===== the audit row =====

    public function testDeactivationWritesAnAccountDeactivatedEventWithBeforeAndAfter(): void
    {
        $account = $this->openAccount();

        $this->service->setActive(self::ADMIN, $account->id, false, 0);

        $events = $this->deactivationEvents();
        self::assertCount(1, $events, 'FR-017: deactivation is a sensitive change and must record one row');
        self::assertSame('account', $events[0]['entity_type']);
        // The public id, matching the funding and creation events: `ChargedAccountScope` resolves it
        // through `findByPublicId()`, so a surrogate key would silently produce an empty scope.
        self::assertSame($account->id, $events[0]['entity_id']);
        self::assertSame(Role::Administrator->value, $events[0]['actor_role']);
        self::assertSame(['active' => true], $events[0]['before']);
        self::assertSame(['active' => false], $events[0]['after']);
        self::assertSame('Deactivated by an administrator', $events[0]['explanation']);
    }

    public function testTheNewEventTypesCannotWidenTheChargedAccountScope(): void
    {
        // `ChargedAccountScope` matches a fixed pair of event values, so a new case cannot widen it —
        // but only if the new case reuses neither value. Asserted by value rather than by type, because
        // sharing the *string* is what would match, whatever the case is named.
        self::assertNotSame(ConfigurationEventType::AccountFunded->value, ConfigurationEventType::AccountDeactivated->value);
        self::assertNotSame(ConfigurationEventType::AccountCharged->value, ConfigurationEventType::AccountDeactivated->value);
        self::assertNotSame(ConfigurationEventType::AccountFunded->value, ConfigurationEventType::AccountReactivated->value);
        self::assertNotSame(ConfigurationEventType::AccountCharged->value, ConfigurationEventType::AccountReactivated->value);
    }

    // ===== the refusals =====

    public function testTheHolderThemselvesCannotDeactivateTheirOwnAccount(): void
    {
        $account = $this->openAccount();

        $this->expectException(LogicException::class);
        // Even the holder: self-service deactivation would strand them, so deliberation is what the
        // administrator role marks rather than ownership.
        $this->service->setActive(self::HOLDER, $account->id, false, 0);
    }

    public function testAnUnknownAccountIsRefusedAsAbsent(): void
    {
        $this->expectException(UnknownAccount::class);

        $this->service->setActive(self::ADMIN, 'no-such-account', false, 0);
    }

    public function testAStaleVersionIsAConflict(): void
    {
        $account = $this->openAccount();

        try {
            $this->service->setActive(self::ADMIN, $account->id, false, 999);
            self::fail('a stale version must be refused');
        } catch (StaleAccountVersion $e) {
            self::assertSame('ACCOUNT_VERSION_CONFLICT', $e->errorCode());
        }

        self::assertTrue(
            $this->accounts->findByPublicId($account->id)->active,
            'a refused change must leave the account untouched'
        );
    }

    /**
     * Requesting the state already held succeeds with the current state and writes nothing.
     *
     * A no-op that bumped the version would move `lockVersion` without a state change — a lost-update
     * signal telling a concurrent reader its data changed when it did not — and an audit row for it
     * would claim a transition that never happened.
     */
    public function testRequestingTheCurrentStateIsANoOpWithoutAnAuditRow(): void
    {
        $account = $this->openAccount();
        $this->service->setActive(self::ADMIN, $account->id, false, 0);
        $eventsBefore = count($this->deactivationEvents());

        $result = $this->service->setActive(self::ADMIN, $account->id, false, 1);

        self::assertFalse($result['active']);
        self::assertSame(1, $this->accounts->findByPublicId($account->id)->lockVersion);
        self::assertCount($eventsBefore, $this->deactivationEvents(), 'a transition that never happened must record nothing');
    }

    // ===== helpers =====

    private function openAccount(): PettyCashAccount
    {
        $currencies = new InMemoryCurrencyRepository();
        $irr = $currencies->findByCode('IRR');
        self::assertNotNull($irr);

        $account = PettyCashAccount::create('acc-' . $irr->code() . '-' . count($this->accounts->findAllByUserUid(self::HOLDER)), self::HOLDER, $irr);
        $this->accounts->save($account);

        return $account;
    }

    /** @return list<array<string,mixed>> */
    private function deactivationEvents(): array
    {
        return array_values(array_filter(
            $this->audit->getConfigurationEvents(),
            static fn (array $e): bool => $e['event_type'] === ConfigurationEventType::AccountDeactivated->value
        ));
    }

    /** @return list<array<string,mixed>> */
    private function reactivationEvents(): array
    {
        return array_values(array_filter(
            $this->audit->getConfigurationEvents(),
            static fn (array $e): bool => $e['event_type'] === ConfigurationEventType::AccountReactivated->value
        ));
    }
}