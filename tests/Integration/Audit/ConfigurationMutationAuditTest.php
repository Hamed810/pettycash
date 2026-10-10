<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Audit;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Configuration\CostCenterService;
use OCA\PettyCash\Application\Configuration\CurrencyService;
use OCA\PettyCash\Application\Configuration\ExpenseTypeService;
use OCA\PettyCash\Application\Configuration\FinanceAssignmentService;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\FailingAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTypeRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * T086 — every configuration mutation writes a configuration audit event, and no read does.
 *
 * ## The eight mutations, and why they are not the seven
 *
 * The audit surface is every public write method on the four Phase-7 administration services, and
 * enumerating them produces **eight**, not seven:
 *
 * | # | Mutation | Event type |
 * |---|---|---|
 * | 1 | `CurrencyService::create()` | `CURRENCY_CREATED` |
 * | 2 | `CurrencyService::update()` | `CURRENCY_UPDATED` |
 * | 3 | `CostCenterService::create()` | `COST_CENTER_CREATED` |
 * | 4 | `CostCenterService::transferManager()` | `COST_CENTER_MANAGER_TRANSFERRED` |
 * | 5 | `ExpenseTypeService::create()` | `EXPENSE_TYPE_CREATED` |
 * | 6 | `ExpenseTypeService::updateByTitle()` | `EXPENSE_TYPE_UPDATED` |
 * | 7 | `ExpenseTypeService::deactivate()` | `EXPENSE_TYPE_DEACTIVATED` |
 * | 8 | `FinanceAssignmentService::create()` | `FINANCE_ASSIGNMENT_CHANGED` |
 *
 * Two of these are the ones a list written from the *contract's* routes would miss. `transferManager()`
 * closes one role assignment and opens another, and FR-017 names "assignments" as its own category, so
 * it is audited in its own right. `deactivate()` is the operation US5/AC2 turns on. Conversely the
 * assumed "cost centre update" does not exist: `CostCenterService` has no update method, though
 * `configuration#costCenterUpdate` is a registered route and `CostCenterWrite` carries a
 * `changeReason`. That gap is reported in `docs/reports/T086.md`, not closed here.
 *
 * ## One event per mutation, asserted per call site
 *
 * Each case asserts the **count** as well as the payload. "An event was written" is satisfied by a
 * service that audits every call twice, and a configuration history that double-counts every change is
 * not a history anyone can read a total from.
 *
 * ## What these tests cannot prove
 *
 * `InMemoryTransactionManager` does not roll back, so the atomicity assertions here are about ordering
 * and propagation. That a refused audit leaves **no committed configuration row** needs real MariaDB
 * through the real `NextcloudTransactionManager`, and is asserted in
 * `tests/Integration/Persistence/roundtrip-runner.php`.
 */
final class ConfigurationMutationAuditTest extends TestCase
{
    private const TODAY = '2026-01-01T00:00:00+00:00';
    private const ADMIN = 'test-admin';

    private InMemoryAuditWriter $audit;

    private InMemoryTransactionManager $transactions;

    private InMemoryCurrencyRepository $currencies;

    private InMemoryCostCenterRepository $costCenters;

    private InMemoryExpenseTypeRepository $expenseTypes;

    private InMemoryRoleAssignmentRepository $roleAssignments;

    private InMemoryUserDirectory $users;

    private CurrencyService $currencyService;

    private CostCenterService $costCenterService;

    private ExpenseTypeService $expenseTypeService;

    private FinanceAssignmentService $financeAssignmentService;

    protected function setUp(): void
    {
        $this->audit = new InMemoryAuditWriter();
        $this->transactions = new InMemoryTransactionManager();
        $this->currencies = new InMemoryCurrencyRepository();
        $this->costCenters = new InMemoryCostCenterRepository();
        $this->expenseTypes = new InMemoryExpenseTypeRepository();
        $this->roleAssignments = new InMemoryRoleAssignmentRepository();
        $this->users = new InMemoryUserDirectory();

        $configurationAudit = new ConfigurationAuditService($this->audit, $this->transactions);

        // T422: the account double FR-023's scale guard reads. Empty by default, so these audit cases are
        // unaffected unless one arranges an account.
        $this->currencyService = new CurrencyService(
            $this->currencies,
            new InMemoryAdministratorCheck(),
            $configurationAudit,
            new InMemoryAccountRepository()
        );
        $this->costCenterService = new CostCenterService(
            $this->costCenters,
            $this->roleAssignments,
            $this->users,
            new InMemoryAdministratorCheck(),
            new FixedClock(self::TODAY),
            $configurationAudit
        );
        $this->expenseTypeService = new ExpenseTypeService($this->expenseTypes, new InMemoryAdministratorCheck(), $configurationAudit);
        $this->financeAssignmentService = new FinanceAssignmentService(
            $this->roleAssignments,
            $this->users,
            new InMemoryAdministratorCheck(),
            new FixedClock(self::TODAY),
            $configurationAudit
        );
    }

    // --- 1. each of the eight mutations writes exactly one event ---

    public function testCurrencyCreateWritesExactlyOneEvent(): void
    {
        $this->currencyService->create(self::ADMIN, 'USD', 'US Dollar', 2);

        $this->assertOneEvent(ConfigurationEventType::CurrencyCreated, 'currency', 'USD');
    }

    public function testCurrencyUpdateWritesExactlyOneEvent(): void
    {
        $created = $this->currencyService->create(self::ADMIN, 'USD', 'US Dollar', 2);
        $this->audit->clear();

        $this->currencyService->update(self::ADMIN, 'USD', 'US Dollar (revised)', 0, true, $created->lockVersion);

        $events = $this->audit->getConfigurationEvents();
        $this->assertCount(1, $events);
        $this->assertSame(ConfigurationEventType::CurrencyUpdated->value, $events[0]['event_type']);
        $this->assertSame('currency', $events[0]['entity_type']);
        $this->assertSame('USD', $events[0]['entity_id']);
    }

    public function testCostCenterCreateWritesExactlyOneEvent(): void
    {
        $created = $this->costCenterService->create(
            self::ADMIN,
            'CC-ENG',
            'Engineering',
            'the engineering cost centre',
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );

        // The stable public id, not the code. T082's reopened `update()` made `code` mutable, and an
        // event keyed by a mutable attribute stops resolving the moment the centre is renamed -- one
        // subject under two identities. `create()` was changed to match so both event types name the
        // same centre the same way.
        $this->assertOneEvent(
            ConfigurationEventType::CostCenterCreated,
            'cost_center',
            (string) $created['costCenter']->publicId
        );
    }

    public function testBothCostCentreEventTypesAddressTheCentreTheSameWay(): void
    {
        // The convention is only worth anything if it holds across event types: a creation keyed by
        // code and an update keyed by uuid would make "the history of this cost centre" unanswerable
        // without trying both, and would rename silently out from under every earlier event.
        $created = $this->costCenterService->create(
            self::ADMIN,
            'CC-ENG',
            'Engineering',
            null,
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );
        $this->audit->clear();

        $this->costCenterService->update(
            self::ADMIN,
            (string) $created['costCenter']->publicId,
            $created['costCenter']->lockVersion,
            'CC-RENAMED',
            'Engineering',
            null,
            $this->today(),
            null,
            true,
            null,
            null,
            'renamed'
        );

        $subjects = array_column($this->audit->getConfigurationEvents(), 'entity_id');
        foreach ($subjects as $subject) {
            $this->assertSame(
                (string) $created['costCenter']->publicId,
                $subject,
                'a cost centre event must be addressed by its uuid even when the code changed'
            );
        }
        $this->assertContains(ConfigurationEventType::CostCenterUpdated->value, array_column($this->audit->getConfigurationEvents(), 'event_type'));
    }

    public function testTransferringAManagerWritesItsOwnEvent(): void
    {
        // FR-017 names "assignments" as a category of its own, and this mutation closes one assignment
        // and opens another. Folding it into the cost-centre event would make the assignment change
        // unreportable, and would leave a cost centre with no assignment history at all.
        $result = $this->costCenterService->create(
            self::ADMIN,
            'CC-ENG',
            'Engineering',
            null,
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );
        $this->audit->clear();

        $this->costCenterService->transferManager(
            self::ADMIN,
            $result['costCenter'],
            Role::CostCenterManager,
            'test-finance',
            $this->today()
        );

        $events = $this->audit->getConfigurationEvents();
        $this->assertCount(1, $events);
        $this->assertSame(ConfigurationEventType::ManagerTransferred->value, $events[0]['event_type']);
        // The subject is the assignment being changed, not the centre it hangs off, so the assignment
        // history is reachable without joining through the cost centre.
        $this->assertSame('role_assignment', $events[0]['entity_type']);
    }

    public function testExpenseTypeCreateWritesExactlyOneEvent(): void
    {
        $this->expenseTypeService->create(self::ADMIN, 'Travel', 'taxi and hotel', true);

        $this->assertOneEvent(ConfigurationEventType::ExpenseTypeCreated, 'expense_type', 'Travel');
    }

    public function testExpenseTypeUpdateWritesExactlyOneEvent(): void
    {
        $this->expenseTypeService->create(self::ADMIN, 'Travel', 'taxi and hotel', true);
        $this->audit->clear();

        $this->expenseTypeService->updateByTitle(self::ADMIN, 'Travel', 'taxi, hotel and rail', false, true, 0);

        $events = $this->audit->getConfigurationEvents();
        $this->assertCount(1, $events);
        $this->assertSame(ConfigurationEventType::ExpenseTypeUpdated->value, $events[0]['event_type']);
    }

    public function testDeactivatingAnExpenseTypeWritesItsOwnEvent(): void
    {
        // US5/AC2 turns on this specific operation, so it cannot be indistinguishable from an update.
        $this->expenseTypeService->create(self::ADMIN, 'Travel', 'taxi and hotel', true);
        $this->audit->clear();

        $this->expenseTypeService->deactivate(self::ADMIN, 'Travel', 0);

        $this->assertOneEvent(ConfigurationEventType::ExpenseTypeDeactivated, 'expense_type', 'Travel');
    }

    public function testFinanceAssignmentCreateWritesExactlyOneEvent(): void
    {
        $this->financeAssignmentService->create(self::ADMIN, 'test-finance', '2026-01-01', 'officer appointed');

        $this->assertOneEvent(
            ConfigurationEventType::FinanceAssignmentChanged,
            'finance_assignment',
            'FINANCE:test-finance'
        );
    }

    // --- 2. reads write none ---

    public function testNoReadWritesAnAuditEvent(): void
    {
        // Seed real data first, so the reads below have something to return. A read over an empty set
        // would pass trivially and prove nothing.
        $this->currencyService->create(self::ADMIN, 'USD', 'US Dollar', 2);
        $this->expenseTypeService->create(self::ADMIN, 'Travel', 'taxi and hotel', true);
        $this->costCenterService->create(self::ADMIN, 'CC-ENG', 'Engineering', null, $this->today(), null, 'test-manager', 'test-senior');
        $this->financeAssignmentService->create(self::ADMIN, 'test-finance', '2026-01-01', 'officer appointed');
        $this->audit->clear();

        // `CurrencyService::listForCaller()` is administrator-gated, unlike `CostCenterService`'s and
        // `ExpenseTypeService`'s listings which require only an authenticated caller. Read as it is
        // written: loosening a sibling's authorization is not this task's decision, and a read that
        // emits no event must hold whichever gate is in front of it.
        $this->currencyService->listForCaller(self::ADMIN);
        $this->expenseTypeService->listActiveForCaller('test-user');
        $this->costCenterService->listValidOnForCaller('test-user', $this->today());
        $this->financeAssignmentService->listForCaller('test-user');
        $this->currencyService->defaultRial();
        $this->currencyService->isDefaultRial('IRR');

        $this->assertSame(
            [],
            $this->audit->getConfigurationEvents(),
            'FR-017 audits changes. A read that appends an event would make the history unreadable and would grow on every page view.'
        );
        $this->assertSame([], $this->audit->getEvents());
    }

    // --- 3. changeReason reaches the persisted event ---

    public function testChangeReasonReachesTheEventForEveryResourceWhoseContractCarriesIt(): void
    {
        $reason = 'quarterly reorganisation';

        $this->currencyService->create(self::ADMIN, 'USD', 'US Dollar', 2);
        $this->costCenterService->create(self::ADMIN, 'CC-A', 'A', null, $this->today(), null, 'test-manager', 'test-senior', true, $reason);
        $this->expenseTypeService->create(self::ADMIN, 'Travel', 'taxi', true, true, $reason);
        $this->financeAssignmentService->create(self::ADMIN, 'test-finance', '2026-01-01', $reason);

        $byEntity = [];
        foreach ($this->audit->getConfigurationEvents() as $event) {
            $byEntity[$event['entity_type']] = $event['explanation'];
        }

        // `CurrencyWrite` has no `changeReason` field in `contracts/ocs-api.yaml`, so its explanation is
        // null -- a recorded absence, not an invented one. Asserted explicitly because "silently null"
        // is indistinguishable from "never threaded" without it.
        $this->assertArrayNotHasKey('currency', array_filter($byEntity, static fn($v): bool => $v !== null));
        $this->assertSame($reason, $byEntity['cost_center']);
        $this->assertSame($reason, $byEntity['expense_type']);
        $this->assertSame($reason, $byEntity['finance_assignment']);
    }

    public function testACurrencyEventCarriesANullExplanationRatherThanAnEmptyString(): void
    {
        $this->currencyService->create(self::ADMIN, 'USD', 'US Dollar', 2);

        $this->assertNull($this->audit->getConfigurationEvents()[0]['explanation']);
    }

    // --- 4. actor identity ---

    public function testTheEventNamesTheAdministratorAsTheActorRole(): void
    {
        // The payload carries no `actor_uid`: `AuditWriterService` resolves the actor from the
        // authenticated session, and an actor supplied in a payload would be a value the caller chose.
        // What the payload *can* fix is the role, and `AuditWriterService` would otherwise default it
        // to PURCHASER -- attributing every configuration change in the product to a purchaser.
        $this->currencyService->create(self::ADMIN, 'USD', 'US Dollar', 2);

        $event = $this->audit->getConfigurationEvents()[0];

        $this->assertSame(Role::Administrator->value, $event['actor_role']);
        $this->assertArrayNotHasKey('actor_uid', $event, 'the writer resolves the actor from the session; a payload value would be a caller-chosen identity');
        $this->assertArrayNotHasKey('occurred_at', $event, 'the writer owns the clock');
    }

    // --- 5. target identity and before/after ---

    public function testACreationRecordsAnEmptyBeforeAndAFilledAfter(): void
    {
        $this->currencyService->create(self::ADMIN, 'USD', 'US Dollar', 2);

        $event = $this->audit->getConfigurationEvents()[0];

        $this->assertSame([], $event['before'], 'a creation has no previous value, and "" would read as "cleared"');
        $this->assertSame(['code' => 'USD', 'name' => 'US Dollar', 'minorUnit' => 2, 'active' => true], $event['after']);
    }

    public function testAnUpdateRecordsTheRealBeforeAndAfterPair(): void
    {
        $created = $this->currencyService->create(self::ADMIN, 'USD', 'US Dollar', 2);
        $this->audit->clear();

        $this->currencyService->update(self::ADMIN, 'USD', 'US Dollar', 0, false, $created->lockVersion);

        $event = $this->audit->getConfigurationEvents()[0];

        // FR-017 requires "previous value, and new value". A pair where both sides hold the post-state
        // would satisfy a count and answer nothing.
        $this->assertSame(2, $event['before']['minorUnit']);
        $this->assertTrue($event['before']['active']);
        $this->assertSame(0, $event['after']['minorUnit']);
        $this->assertFalse($event['after']['active']);
    }

    public function testTheBeforeSnapshotIsTheStateTheCallerActuallySaw(): void
    {
        $created = $this->expenseTypeService->create(self::ADMIN, 'Travel', 'taxi', true);
        $this->audit->clear();

        $this->expenseTypeService->updateByTitle(self::ADMIN, 'Travel', 'taxi and rail', true, true, $created->lockVersion ?? 0);

        $event = $this->audit->getConfigurationEvents()[0];

        $this->assertSame('taxi', $event['before']['description']);
        $this->assertSame('taxi and rail', $event['after']['description']);
    }

    // --- 6. a failed mutation leaves no event ---

    public function testARefusedMutationRecordsNoEvent(): void
    {
        try {
            $this->currencyService->create('test-user', 'USD', 'US Dollar', 2);
            $this->fail('a non-administrator must be refused');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame([], $this->audit->getConfigurationEvents(), 'a history entry describing a change that did not happen');
    }

    public function testADuplicateThatTheDomainRefusesRecordsNoEvent(): void
    {
        $this->currencyService->create(self::ADMIN, 'USD', 'US Dollar', 2);
        $this->audit->clear();

        try {
            $this->currencyService->create(self::ADMIN, 'USD', 'Duplicate', 2);
            $this->fail('a duplicate code must be refused');
        } catch (\OCA\PettyCash\Application\Configuration\ConfigurationConflict) {
            // expected
        }

        $this->assertSame([], $this->audit->getConfigurationEvents());
    }

    public function testAnInvalidWriteRecordsNoEvent(): void
    {
        try {
            $this->currencyService->update(self::ADMIN, 'MISSING', 'No Such Currency', 2, true, 0);
            $this->fail('updating a currency that does not exist must be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame([], $this->audit->getConfigurationEvents());
    }

    // --- 7. a refused audit propagates ---

    public function testARefusedAuditPropagatesOutOfEveryAuditedMutation(): void
    {
        // Propagation is the observable half of atomicity with these doubles. The real rollback -- no
        // configuration row survives -- is asserted against MariaDB in the round trip runner.
        $failing = new FailingAuditWriter($this->audit);
        $configurationAudit = new ConfigurationAuditService($failing, $this->transactions);
        $currencies = new CurrencyService(
            $this->currencies,
            new InMemoryAdministratorCheck(),
            $configurationAudit,
            new InMemoryAccountRepository()
        );

        $this->expectException(\RuntimeException::class);

        $currencies->create(self::ADMIN, 'USD', 'US Dollar', 2);
    }

    // --- 8. the mutation and its event commit together ---

    public function testTheAuditAppendIsInsideTheTransactionThatCarriesTheMutation(): void
    {
        $observed = [];

        $this->transactions->onTransaction(function () use (&$observed): void {
            $observed[] = 'opened';
        });

        $this->currencyService->create(self::ADMIN, 'USD', 'US Dollar', 2);

        $this->assertSame(['opened'], $observed);
        $this->assertCount(1, $this->audit->getConfigurationEvents());
    }

    // --- 9. history does not accumulate duplicate events ---

    public function testRepeatedMutationsAccumulateOneEventEachRatherThanOnePerCall(): void
    {
        $created = $this->currencyService->create(self::ADMIN, 'USD', 'US Dollar', 2);
        $this->currencyService->update(self::ADMIN, 'USD', 'Dollar', 0, true, $created->lockVersion);
        $this->currencyService->update(self::ADMIN, 'USD', 'Dollar again', 0, true, 0);
        $this->currencyService->update(self::ADMIN, 'USD', 'Dollar a third time', 0, true, 0);

        $types = array_map(
            static fn(array $e): string => $e['event_type'],
            $this->audit->getConfigurationEvents()
        );

        // One create plus three updates: four mutations, four events. A service that audited both the
        // read and the write of the same call would report eight here and look busier, not safer.
        $this->assertCount(4, $types);
        $this->assertSame(ConfigurationEventType::CurrencyCreated->value, $types[0]);
        $this->assertSame(
            [ConfigurationEventType::CurrencyUpdated->value],
            array_values(array_unique(array_slice($types, 1)))
        );
    }

    public function testARepeatedFinanceAssignmentPostAuditsEachRequestWithoutCollapsingHistory(): void
    {
        // T084's `POST` is an upsert, so several calls touch one row. Each is still a distinct request
        // that changed the configuration and each gets its own event; collapsing them would lose the
        // revocation, which is the operation FR-016 most needs to be able to prove.
        $this->financeAssignmentService->create(self::ADMIN, 'test-finance', '2026-01-01', 'appointed');
        $this->financeAssignmentService->create(self::ADMIN, 'test-finance', '2026-01-01', 'revoked', null, false);
        $this->financeAssignmentService->create(self::ADMIN, 'test-finance', '2026-01-01', 'reappointed');

        $events = $this->audit->getConfigurationEvents();

        $this->assertCount(3, $events);
        $this->assertSame(
            ['appointed', 'revoked', 'reappointed'],
            array_column($events, 'explanation')
        );
        // The same subject throughout: one officer, three changes to that one assignment.
        $this->assertCount(1, array_unique(array_column($events, 'entity_id')));
    }

    public function testACostCenterCreateAuditsOneEventEvenThoughItAlsoWritesTwoAssignments(): void
    {
        // `CostCenterService::create()` writes three rows. The *mutation* is one administrative act, so
        // it is one event; the assignments it created are recorded inside that event's `after`. Emitting
        // three events would make "one change" read as three, which is the duplication FR-052's
        // before-and-after requirement is meant to avoid.
        $this->costCenterService->create(self::ADMIN, 'CC-ENG', 'Engineering', null, $this->today(), null, 'test-manager', 'test-senior');

        $events = $this->audit->getConfigurationEvents();

        $this->assertCount(1, $events);
        $this->assertSame('test-manager', $events[0]['after']['manager']);
        $this->assertSame('test-senior', $events[0]['after']['seniorManager']);
    }

    // --- helpers ---

    private function assertOneEvent(ConfigurationEventType $type, string $entityType, string $entityId): void
    {
        $events = $this->audit->getConfigurationEvents();

        $this->assertCount(1, $events, sprintf('%s must write exactly one configuration audit event', $type->value));
        $this->assertSame($type->value, $events[0]['event_type']);
        $this->assertSame($entityType, $events[0]['entity_type']);
        $this->assertSame($entityId, $events[0]['entity_id']);
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }
}