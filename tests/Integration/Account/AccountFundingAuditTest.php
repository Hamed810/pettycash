<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Account;

use OCA\PettyCash\Application\Account\AccountPayloadFormatter;
use OCA\PettyCash\Application\Account\AccountService;
use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Reporting\ChargedAccountScope;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountMovementRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * T369: FR-015's account funding writes a configuration audit event, and FR-055's scope follows it.
 *
 * ## What was missing
 *
 * FR-055 says an officer's charged accounts are "the distinct account subjects of that officer's own
 * **account funding and balance-charging** configuration audit events" — two kinds — and that
 * "an event type outside funding and charging … MUST NOT count toward the set". T294 delivered the
 * settlement half. `AccountService::charge()` wrote the movement and the new balance and **no**
 * configuration event at all.
 *
 * The consequence lands on FR-055's own wording: an officer who had funded an account and whose
 * expense had not yet settled had no event naming that account, so it was absent from
 * `ChargedAccountScope::chargedAccountDbIds()`, and therefore from the `accounts` report and from
 * their configuration-audit visibility. FR-055 anticipates exactly this, in its clarification:
 * "relying on the transaction-audit decision alone would leave this scope empty for every officer
 * who had actually charged an account." T294 closed half that sentence; this closes the other half.
 *
 * ## Why this file asserts through the real `AuditWriterService`
 *
 * The event is built here as a PHP array and would be *readable* straight off the writer double —
 * `InMemoryAuditWriter` records what it was handed. This file deliberately does not use that double
 * for the assertions. It wires the production `AuditWriterService` over two in-memory repositories so
 * the event passes through `toEvent()`, which is where the actor, the role, the JSON encoding and
 * the `entity_type` refusal actually happen.
 *
 * The reason is specific: `AuditWriterService::toEvent()` reads the actor from `CurrentUser` and
 * **ignores** an `actor_uid` key in the payload, so an assertion against the raw array would report
 * an actor the stored row does not have. FR-055 requires the event to "name the charging officer",
 * and that requirement is only testable against the row as stored.
 *
 * Scope note: this exercises the service and not the SQL layer, matching `AccountServiceTest` and
 * `AccountChargeAuthorizationTest` beside it. The `entity_id` → surrogate-key resolution
 * `ChargedAccountScope` depends on is a repository concern, and T236's real-database harness is what
 * covers it; what is pinned here is that the event names the account **by public id**, which is the
 * thing this service can get wrong and which a database id would fail silently rather than loudly.
 */
final class AccountFundingAuditTest extends TestCase
{
    private InMemoryAccountRepository $accounts;
    private InMemoryAccountMovementRepository $movements;
    private InMemoryRoleAssignmentRepository $roleAssignments;
    private InMemoryAuditEventRepository $configurationAudit;
    private AccountService $service;

    private const OWNER = 'the-account-owner';
    private const CURRENCY = 'FND';
    private const FINANCE = 'the-finance-officer';
    private const ADMINISTRATOR = 'the-administrator';

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->movements = new InMemoryAccountMovementRepository();
        $this->roleAssignments = new InMemoryRoleAssignmentRepository();
        // The configuration stream. A funding event goes here and nowhere else — FR-056 requires the
        // two streams to stay separately identifiable — so the transaction stream is a separate double.
        $this->configurationAudit = new InMemoryAuditEventRepository();
        $transactionAudit = new InMemoryAuditEventRepository();

        $this->service = new AccountService(
            $this->accounts,
            $this->movements,
            new InMemoryCurrencyRepository(),
            $this->roleAssignments,
            new \OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck(),
            new IdempotencyService(new InMemoryCommandResultRepository()),
            new InMemoryCurrentUser(self::FINANCE),
            new AccountPayloadFormatter(),
            new AuditWriterService(
                $transactionAudit,
                new InMemoryCurrentUser(self::FINANCE),
                new InMemoryUserDirectory(),
                $this->configurationAudit
            )
        );
    }

    // ===== THE EVENT EXISTS =====

    /**
     * The requirement T369 exists to satisfy: a successful funding charge names its account under the
     * funding event type.
     *
     * Asserted as the *stored* row rather than the payload array, so the actor, the role and the
     * subject all come from the writer's own mapping.
     */
    public function testAFundingChargeWritesAConfigurationEventNamingTheAccount(): void
    {
        $account = $this->seedAccount();
        $this->grantRole(self::FINANCE, Role::Finance);

        $this->charge(self::FINANCE, $account->id, 1000);

        $events = $this->configurationEvents();
        $this->assertCount(1, $events, 'a funding charge writes exactly one configuration event');

        $event = $events[0];
        $this->assertSame('account', $event->entityType, 'FR-055 addresses configuration events by subject');
        $this->assertSame(
            $account->id,
            $event->entityId,
            'the subject must be the account\'s **public** id, which is what ChargedAccountScope resolves'
        );
        $this->assertSame(ConfigurationEventType::AccountFunded->value, $event->eventType);
    }

    /**
     * FR-055 requires the event to "name the charging officer and the account".
     *
     * Two things are asserted that are easy to get wrong independently: the uid comes from
     * `CurrentUser` rather than the `$actorUid` parameter, and the role is resolved rather than
     * defaulted. `AuditWriterService::toEvent()` would stamp `'PURCHASER'` on an event that did not
     * pass one, which would record a finance funding as a purchase.
     */
    public function testTheEventNamesTheChargingOfficerAndTheirRole(): void
    {
        $account = $this->seedAccount();
        $this->grantRole(self::FINANCE, Role::Finance);

        $this->charge(self::FINANCE, $account->id, 1000);

        $event = $this->configurationEvents()[0];
        $this->assertSame(self::FINANCE, $event->actorUid, 'FR-055 requires the event to name the charging officer');
        $this->assertSame(Role::Finance->value, $event->actorRole, 'and not the writer\'s PURCHASER default');
    }

    /**
     * The administrator half of FR-015's actor gate, on the audit row.
     *
     * FR-013 puts account funding with administrators, so an administrator funding an account is a
     * real case — and hardcoding `FINANCE` on this event would misattribute exactly the situation
     * FR-013 creates. The authorization test covers that the *charge* is permitted; this one covers
     * that it is *recorded* as what it was.
     */
    public function testAnAdministratorFundingIsRecordedAsAnAdministrator(): void
    {
        $account = $this->seedAccount();
        $this->grantRole(self::ADMINISTRATOR, Role::Administrator);

        $this->charge(self::ADMINISTRATOR, $account->id, 1000);

        $this->assertSame(
            Role::Administrator->value,
            $this->configurationEvents()[0]->actorRole,
            'an administrator funding an account must not be recorded as a finance charge'
        );
    }

    /**
     * A person holding both roles is recorded as the finance one, because FR-015 names finance as the
     * charging role and that is the action they performed.
     */
    public function testAnActorHoldingBothRolesIsRecordedAsFinance(): void
    {
        $account = $this->seedAccount();
        $this->grantRole(self::FINANCE, Role::Finance);
        $this->grantRole(self::FINANCE, Role::Administrator);

        $this->charge(self::FINANCE, $account->id, 1000);

        $this->assertSame(Role::Finance->value, $this->configurationEvents()[0]->actorRole);
    }

    // ===== THE BALANCE PAIR IS HONEST =====

    /**
     * FR-017 requires a sensitive change to record the previous value, and this event can.
     *
     * `PettyCashAccount::charge()` assigns `balanceMinor` in place, so a "before" read taken after
     * that call would be the post-charge value wearing a "before" label — the exact defect
     * `FinanceSettlementService::recordConsumption()` documents at length, and the reason that method
     * records no balance pair at all. This one reads the balance *before* charging, so it can carry an
     * honest pair, and the assertion is that the two differ by exactly the amount charged.
     *
     * Second charge is on a non-zero account deliberately: with a zero starting balance a "before" of
     * 0 and a "before" of the post-charge value would be trivially distinguishable, and the defect
     * would still slip through on the assertion that matters.
     */
    public function testTheEventRecordsAnHonestBeforeAndAfterBalance(): void
    {
        $account = $this->seedAccount();
        $this->grantRole(self::FINANCE, Role::Finance);

        $this->charge(self::FINANCE, $account->id, 1000, 'first funding');
        $this->charge(self::FINANCE, $account->id, 500, 'second funding', 0, 'command-2');

        $events = $this->configurationEvents();
        $this->assertCount(2, $events);

        $before = json_decode((string) $events[1]->beforeJson, true);
        $after = json_decode((string) $events[1]->afterJson, true);

        $this->assertSame(1000, $before['balance_minor'] ?? null, 'the previous balance, not the post-charge one');
        $this->assertSame(1500, $after['balance_minor'] ?? null);
        $this->assertSame(500, $after['amount_minor'] ?? null);
        $this->assertSame(
            self::CURRENCY,
            $before['currency'] ?? null,
            'a balance is meaningless without the currency it is denominated in'
        );
    }

    // ===== THE EVENT DOES NOT OVERWRITE OR MULTIPLY =====

    /**
     * A replayed command writes no second event.
     *
     * The write sits inside `IdempotencyService::execute()`'s closure, so on a replay the closure
     * never runs — the same rule `TransactionService::createTransaction()` and `updateTransaction()`
     * follow for the FR-051 events they write. Without it a retried charge, or a double-clicked button,
     * would leave a second `ACCOUNT_FUNDED` row for money that moved once.
     */
    public function testReplayingAFundingChargeWritesNoSecondEvent(): void
    {
        $account = $this->seedAccount();
        $this->grantRole(self::FINANCE, Role::Finance);

        $first = $this->charge(self::FINANCE, $account->id, 1000, 'funding', 0, 'replayed-command');
        $second = $this->charge(self::FINANCE, $account->id, 1000, 'funding', 0, 'replayed-command');

        $this->assertSame(
            $first,
            $second,
            'the replay returns the stored response rather than charging again'
        );
        $this->assertSame(1000, $this->accounts->findByPublicId($account->id)->balanceMinor, 'and moves no money twice');
        $this->assertCount(
            1,
            $this->configurationEvents(),
            'a replayed command must not write a second funding event'
        );
    }

    /**
     * A refused charge writes nothing at all — no movement, and no configuration event.
     *
     * The second half is T369's own: an audit row for a charge that never happened would put an
     * account into FR-055's scope on the strength of a refused request, and would survive the
     * rollback that removed the money. The actor gate runs before the closure, but the assertion is
     * here because the consequence is new.
     */
    public function testARefusedChargeWritesNoConfigurationEvent(): void
    {
        $account = $this->seedAccount();
        // No role granted: the FR-015 gate refuses.

        try {
            $this->charge('the-purchaser', $account->id, 1000);
            $this->fail('an actor without the finance role must be refused');
        } catch (\LogicException $e) {
            // The refusal itself is `AccountChargeAuthorizationTest`'s subject.
        }

        $this->assertSame([], $this->configurationEvents(), 'a refused charge must leave no configuration event');
    }

    // ===== AND THE SCOPE FOLLOWS THE EVENT =====

    /**
     * The full chain, which is T369's acceptance criterion: the event names the account, and
     * `ChargedAccountScope` includes it **with no settlement having occurred**.
     *
     * A real `ChargedAccountScope` is built over the same in-memory configuration stream this test
     * wrote to, so the assertion is about the derivation actually consuming the event rather than
     * about a constant being present in both files.
     *
     * The account is seeded at dbId 1 because `InMemoryAccountRepository` assigns surrogate keys on
     * save and the scope is compared in those terms — FR-055's derivation hands dbIds to
     * `ReportQuery::configurationAuditScope()`, so dbId is the currency of the answer.
     */
    public function testAFundedAccountIsInScopeWithoutAnySettlement(): void
    {
        $account = $this->seedAccount(dbId: 1);
        $this->grantRole(self::FINANCE, Role::Finance);

        $scope = new ChargedAccountScope($this->configurationAudit, $this->accounts);
        $this->assertSame(
            [],
            $scope->chargedAccountDbIds(),
            'before the charge, FR-055\'s scope is a true zero'
        );

        $this->charge(self::FINANCE, $account->id, 1000);

        $this->assertSame(
            [$account->dbId],
            $scope->chargedAccountDbIds(),
            'a funded account is in scope with no settlement recorded'
        );
    }

    /**
     * The true zero survives, and an unrelated event type does not widen it.
     *
     * FR-055's "an event type outside funding and charging MUST NOT count" has to keep holding after
     * this change, and it has to keep holding as a *zero* rather than becoming a widened scope — the
     * direction that would fail unsafe, since `ReportQuery::configurationAuditScope()` widens from
     * whatever ids it is handed.
     */
    public function testTheScopeStaysAZeroForAnUnrelatedEventType(): void
    {
        $account = $this->seedAccount(dbId: 1);
        $this->configurationAudit->save(
            new \OCA\PettyCash\Domain\Audit\AuditEvent(
                (string) \OCA\PettyCash\Domain\Shared\Uuid::random(),
                null,
                null,
                null,
                '',
                null,
                'account',
                $account->id,
                'ACCOUNT_DEACTIVATED',
                self::ADMINISTRATOR,
                'Administrator',
                Role::Administrator->value,
                null,
                '{}',
                '{}',
                null,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            )
        );

        $scope = new ChargedAccountScope($this->configurationAudit, $this->accounts);
        $this->assertSame(
            [],
            $scope->chargedAccountDbIds(),
            'deactivating an account is touching it, not charging it'
        );
    }

    // ===== HELPERS =====

    /**
     * @return list<\OCA\PettyCash\Domain\Audit\AuditEvent>
     */
    private function configurationEvents(): array
    {
        return $this->configurationAudit->getEvents();
    }

    private function grantRole(string $userUid, Role $role): void
    {
        $this->roleAssignments->save(
            RoleAssignment::create(
                $userUid,
                $role,
                null,
                new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
                null
            )
        );
    }

    /**
 * @param int|null $dbId the surrogate key, when the caller needs the account to be resolvable by
 *        `ChargedAccountScope` — which resolves through `AccountRepository` and compares dbIds.
 *        `InMemoryAccountRepository` keys by public id and never assigns one itself, so a null here
 *        means the account is unreachable from the scope, which is the true zero rather than a bug.
 */
private function seedAccount(?int $dbId = null): PettyCashAccount
    {
        $publicId = (string) \OCA\PettyCash\Domain\Shared\Uuid::random();
        $currency = Currency::create(self::CURRENCY, 'Funding Audit Test Currency', 2);

        $account = new PettyCashAccount(
            $publicId,
            self::OWNER,
            \OCA\PettyCash\Domain\Shared\CurrencyCode::fromString(self::CURRENCY),
            $currency,
            0,
            true,
            0,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            self::OWNER,
            null,
            null,
            $dbId
        );
        $this->accounts->save($account);

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(
        string $actorUid,
        string $accountPublicId,
        int $amountMinor,
        string $description = 'test funding',
        int $expectedVersion = 0,
        ?string $commandId = null
    ): array {
        return $this->service->charge(
            $actorUid,
            $accountPublicId,
            $amountMinor,
            $description,
            new \DateTimeImmutable('2026-01-02T03:04:05+00:00'),
            $expectedVersion,
            $commandId ?? 'funding-command-' . $actorUid
        );
    }
}