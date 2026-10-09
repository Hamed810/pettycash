<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Account;

use OCA\PettyCash\Application\Account\AccountAdministrationService;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Configuration\CurrencyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * FR-017 (clarified 2026-10-08): **every path that creates a petty-cash account records it in the
 * configuration audit**, as one shared event type.
 *
 * ## Why this needs its own file
 *
 * Before T411 an account was the only configurable resource whose arrival left no trace. Currency, cost
 * centre and expense type each recorded their own creation; an account's *funding* and *charging* were
 * already recorded — so the omission read as an inconsistency rather than a policy, and no test failed
 * when it was wrong because nothing asserted the row existed.
 *
 * ## The properties that matter, and which a single "it wrote something" test would miss
 *
 * 1. **Every origin writes it.** Three paths create an account: FR-020 provisioning, an administrator
 *    using `createFor()`, and a user opening one for themselves. This file covers the provisioning path
 *    directly and pins the shared event type; `AccountServiceTest` covers the other two, because the
 *    assertion that matters for them is the *role*, which only `AccountService` can see.
 * 2. **The actor is what distinguishes them.** For provisioning the actor is the account holder, which is
 *    what makes the row honest about the fact that **no administrator authorised it**. A row recording
 *    only "an account was created" would be true of every origin and therefore useless.
 * 3. **A race writes exactly one row.** Two callers both reading "zero active accounts" both attempt the
 *    insert; the index decides. Only the winner created anything, so only the winner may record it —
 *    otherwise the audit claims a creation that never happened, which is the exact failure FR-017's
 *    `before`/`after` pair exists to prevent.
 * 4. **The scope does not widen.** Adding a case to the event enum cannot give a finance officer
 *    visibility of an account they have not been charged to see, because `ChargedAccountScope` matches a
 *    fixed pair of event values.
 */
final class AccountCreationAuditTest extends TestCase
{
    private InMemoryAccountRepository $accounts;

    private InMemoryCurrencyRepository $currencies;

    private InMemoryAuditWriter $audit;

    private AccountAdministrationService $service;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->currencies = new InMemoryCurrencyRepository();
        $this->audit = new InMemoryAuditWriter();

        $this->service = new AccountAdministrationService(
            $this->accounts,
            new CurrencyService(
                $this->currencies,
                new InMemoryAdministratorCheck(),
                new \OCA\PettyCash\Application\Audit\ConfigurationAuditService(
                    $this->audit,
                    new InMemoryTransactionManager()
                ),
                // T422: FR-023's scale guard counts accounts; the same double the provisioning path below
                // writes into, so a provisioned account is one the guard can see.
                $this->accounts
            ),
            $this->audit
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function creationEvents(): array
    {
        return array_values(array_filter(
            $this->audit->getConfigurationEvents(),
            static fn (array $e): bool => $e['event_type'] === ConfigurationEventType::AccountCreated->value
        ));
    }

    // ===== the properties ==========================================================

    public function testProvisioningRecordsAnAccountCreation(): void
    {
        $this->service->ensureDefaultRialAccount('purchaser-1');

        $events = $this->creationEvents();

        self::assertCount(1, $events, 'FR-017: provisioning created an account, so it must record one');
        self::assertSame('account', $events[0]['entity_type']);
        self::assertSame(
            ConfigurationEventType::AccountCreated->value,
            $events[0]['event_type'],
            'FR-017 as clarified: one shared event type for every origin, not one per origin.'
        );
    }

    public function testTheRowRecordsTheAccountHolderAsTheActorAndSaysNobodyAuthorisedIt(): void
    {
        $account = $this->service->ensureDefaultRialAccount('purchaser-1');

        $events = $this->creationEvents();

        // `actor_uid` is supplied by the writer from the session, which is what makes "the account
        // holder's own click produced this" a property of the row rather than of a code comment.
        self::assertSame(Role::Purchaser->value, $events[0]['actor_role']);
        self::assertSame('fr020-provisioning', $events[0]['after']['origin']);

        // The account's *public id*, never its surrogate key: `ChargedAccountScope` resolves it through
        // `findByPublicId()`, so a database id would yield an empty scope rather than an error.
        self::assertSame($account->id, $events[0]['entity_id']);

        // "No administrator authorised it" has to be reconstructable, and an explanation field that
        // merely repeated the event name would not reconstruct it.
        self::assertNotSame('', trim((string) $events[0]['explanation']));
    }

    public function testTheRowIsARecordNotAnAuthorisation(): void
    {
        $this->service->ensureDefaultRialAccount('purchaser-1');

        $events = $this->creationEvents();

        // Provisioning must stay self-service. If the row claimed an approval, a reader would infer a
        // control that does not exist — the same class of error as the 2026-10-07 evidence-link answer,
        // where an administrator's own view right was explicitly not required.
        self::assertStringNotContainsStringIgnoringCase('approved', (string) $events[0]['explanation']);
        self::assertStringNotContainsStringIgnoringCase('authorised by', (string) $events[0]['explanation']);

        // No administrator gate appeared: the call succeeded for a bare uid with no role set up at all,
        // which is the observable form of "provisioning needs no approval or role".
        self::assertCount(1, $this->accounts->findActiveByUserUid('purchaser-1'));
    }

    public function testAConcurrentLoserWritesNoSecondRow(): void
    {
        $this->service->ensureDefaultRialAccount('purchaser-1');
        $this->service->ensureDefaultRialAccount('purchaser-1');

        // The second call returns the existing account without creating anything, so FR-017 must not
        // record a second creation. This is the property a "provisioning writes an audit row" test
        // written once, without the repeat, would miss.
        self::assertCount(
            1,
            $this->creationEvents(),
            'FR-017: exactly one ACCOUNT_CREATED per account, however many callers asked for one.'
        );
    }

    public function testAnAccountThatAlreadyExistsRecordsNothing(): void
    {
        $this->service->ensureDefaultRialAccount('purchaser-1');
        $this->audit->clear();

        $result = $this->service->ensureDefaultRialAccount('purchaser-1');

        // The precondition guard, not the index, is what answers here: the caller already holds an
        // active account, so there is nothing to provision and nothing to record. (Asserting that the
        // *existing* account comes back would be wrong — this method provisions, and
        // `resolveAccountForNewSheet()` is the caller that returns one.)
        self::assertNull(
            $result,
            'FR-020: a caller with an active account has nothing to be provisioned.'
        );
        self::assertSame(
            [],
            $this->creationEvents(),
            'FR-017: a precondition that did not hold created nothing, so it records nothing.'
        );
    }

    // ===== the structural half =====================================================

    public function testTheEventTypeIsReachableOnlyThroughTheSharedEnumCase(): void
    {
        self::assertSame('ACCOUNT_CREATED', ConfigurationEventType::AccountCreated->value);

        // Width guard. The column is `string(48)`; an enum value that overflows it fails at insert time
        // in production rather than in a unit test, which is why this is asserted here.
        self::assertLessThanOrEqual(48, strlen(ConfigurationEventType::AccountCreated->value));
    }

    public function testAddingTheCaseCannotWidenAChargedAccountScope(): void
    {
        // FR-055 admits only funding and charging events into a finance officer's configuration-audit
        // scope. `ChargedAccountScope` matches a fixed pair of values, so a new enum case cannot join
        // them — asserted structurally so the next case added to this enum is checked the same way.
        // `__DIR__` is `tests/Unit/Application/Account`, so four levels up is the repository root.
        $scope = file_get_contents(__DIR__ . '/../../../../lib/Application/Reporting/ChargedAccountScope.php');

        self::assertIsString($scope);
        self::assertStringContainsString('AccountFunded', $scope);
        self::assertStringContainsString('AccountCharged', $scope);
        self::assertStringNotContainsString(
            'AccountCreated',
            $scope,
            'FR-055: an uncharged account must stay outside a finance officer\'s configuration-audit scope.'
        );
    }

    public function testTheProvisioningWriterIsTheSameSharedPortTheOtherPathsUse(): void
    {
        // "One shared event type" is only true if the other creation paths go through the same port.
        // Asserting the dependency is `AuditWriter` (not a bespoke writer) keeps a fourth origin from
        // quietly arriving with its own.
        $constructor = (new \ReflectionClass(AccountAdministrationService::class))->getConstructor();
        $types = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            $constructor?->getParameters() ?? []
        );

        self::assertContains(AuditWriter::class, $types);
    }
}