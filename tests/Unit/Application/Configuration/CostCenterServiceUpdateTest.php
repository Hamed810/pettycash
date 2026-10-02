<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Configuration;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Configuration\ConfigurationConflict;
use OCA\PettyCash\Application\Configuration\CostCenterService;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\FailingAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * T082's reopened scope — `CostCenterService::update()`, which the completed task never delivered.
 *
 * ## Why this is a separate file
 *
 * The existing `CostCenterServiceTest` covers the delivered behaviour (`create()`, `transferManager()`,
 * `listValidOnForCaller()`, `currentManagersOn()`) and none of it is disturbed here. This file covers
 * only the method that was missing, so a failure names the reopened scope rather than a regression in
 * work that was already proven.
 *
 * ## What makes update different from every sibling write
 *
 * Three things, and each is asserted below rather than assumed:
 *
 * 1. **It is keyed by the stable uuid, not by code.** `PATCH
 *    /configuration/cost-centers/{costCenterId}` declares `costCenterId` as `{ format: uuid }`. This is
 *    the only one of the four configuration updates addressed by public id — `CurrencyService::update()`
 *    takes `code` and `ExpenseTypeService::updateByTitle()` takes `title`, because neither resource has a
 *    mutable natural key. A cost centre's `code` *is* mutable: FR-010 names it among the updatable fields
 *    and `CostCenterWrite` carries it. So keying the update by code would make a rename impossible to
 *    express and would let a caller address a different centre than the one they read.
 *
 * 2. **`code` is mutable here and only here.** `CostCenter::code()` has no setter, so an update builds a
 *    new aggregate through `CostCenter::create()` and carries the identity across — the
 *    `CurrencyService::update()` precedent, inverted because there the identity is the argument.
 *
 * 3. **A manager change is a transfer, not an overwrite.** US5/AC1 requires assignments to govern future
 *    routing "without changing historical actor records" and FR-011 requires exactly one current holder
 *    per role, so a reassignment closes the outgoing assignment at `validFrom - 1 second` — the day
 *    before, because `RoleAssignment::isValidOn()` is inclusive at both ends. Overwriting the row
 *    instead would silently destroy the record of who managed the centre before.
 */
final class CostCenterServiceUpdateTest extends TestCase
{
    private const TODAY = '2026-01-01T00:00:00+00:00';
    private const ADMIN = 'test-admin';

    private InMemoryCostCenterRepository $costCenters;

    private InMemoryRoleAssignmentRepository $roleAssignments;

    private InMemoryAuditWriter $audit;

    private InMemoryTransactionManager $transactions;

    private FixedClock $clock;

    private CostCenterService $service;

    protected function setUp(): void
    {
        $this->costCenters = new InMemoryCostCenterRepository();
        $this->roleAssignments = new InMemoryRoleAssignmentRepository();
        $this->audit = new InMemoryAuditWriter();
        $this->transactions = new InMemoryTransactionManager();
        $this->clock = new FixedClock(self::TODAY);
        $this->service = new CostCenterService(
            $this->costCenters,
            $this->roleAssignments,
            new InMemoryUserDirectory(),
            new InMemoryAdministratorCheck(),
            $this->clock,
            new ConfigurationAuditService($this->audit, $this->transactions)
        );
    }

    // --- helpers ---

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }

    /** @return array{costCenter: CostCenter, manager: RoleAssignment, seniorManager: RoleAssignment} */
    private function seedCentre(string $code = 'CC-ENG', string $manager = 'test-manager', string $senior = 'test-senior'): array
    {
        return $this->service->create(
            self::ADMIN,
            $code,
            'Engineering',
            'the engineering cost centre',
            $this->today(),
            null,
            $manager,
            $senior
        );
    }

    /**
     * Sentinel distinguishing "leave this field alone" from "set it to null".
     *
     * `??` cannot tell those apart, and `description` is exactly the field where the difference matters:
     * an update that clears a description and one that leaves it alone look identical through a
     * null-coalescing default. A named argument the caller simply omits cannot express "null" at all.
     */
    private const UNCHANGED = "\0__unchanged__";

    /**
     * Update `$centre`, varying only the named fields.
     *
     * Every parameter defaults to `UNCHANGED` rather than to the current value, so this helper never
     * has to be told what the centre currently holds, and an explicit `description: null` reaches the
     * service as a real null.
     */
    private function update(
        CostCenter $centre,
        mixed $expectedVersion = self::UNCHANGED,
        mixed $code = self::UNCHANGED,
        mixed $name = self::UNCHANGED,
        mixed $description = self::UNCHANGED,
        mixed $validFrom = self::UNCHANGED,
        mixed $validTo = self::UNCHANGED,
        mixed $active = self::UNCHANGED,
        mixed $manager = null,
        mixed $senior = null,
        mixed $changeReason = null
    ): CostCenter {
        $keep = static fn(mixed $given, mixed $current): mixed => $given === self::UNCHANGED ? $current : $given;

        return $this->service->update(
            self::ADMIN,
            (string) $centre->publicId,
            $keep($expectedVersion, $centre->lockVersion),
            $keep($code, $centre->code()),
            $keep($name, $centre->name),
            $keep($description, $centre->description),
            $keep($validFrom, $centre->validFrom),
            $keep($validTo, $centre->validTo),
            $keep($active, $centre->active),
            $manager,
            $senior,
            $changeReason
        );
    }

    // --- identity ---

    public function testItUpdatesTheCostCentreAddressedByItsUuid(): void
    {
        $seeded = $this->seedCentre();

        $updated = $this->update($seeded['costCenter'], name: 'Engineering (EMEA)');

        $this->assertSame('Engineering (EMEA)', $this->costCenters->findByPublicId((string) $seeded['costCenter']->publicId)?->name);
        $this->assertSame('Engineering (EMEA)', $updated->name);
        $this->assertSame(
            $seeded['costCenter']->publicId,
            $updated->publicId,
            'a rename of any other field must not change the public id'
        );
    }

    public function testItRefusesAnUnknownPublicId(): void
    {
        $seeded = $this->seedCentre();
        $this->audit->clear();

        $this->expectException(InvalidArgumentException::class);

        $this->service->update(
            self::ADMIN,
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            $seeded['costCenter']->lockVersion,
            'CC-ENG',
            'Name',
            null,
            $this->today(),
            null,
            true,
            'test-manager',
            'test-senior'
        );
    }

    // --- authorization ---

    public function testANonAdministratorCannotUpdate(): void
    {
        $seeded = $this->seedCentre();
        $this->audit->clear();

        try {
            $this->service->update(
                'test-manager',
                (string) $seeded['costCenter']->publicId,
                $seeded['costCenter']->lockVersion,
                'CC-ENG',
                'Hijacked',
                null,
                $this->today(),
                null,
                true,
                'test-manager',
                'test-senior'
            );
            $this->fail('a non-administrator must be refused');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame(
            'Engineering',
            $this->costCenters->findByPublicId((string) $seeded['costCenter']->publicId)?->name,
            'a refused update must leave the centre untouched'
        );
        $this->assertSame([], $this->audit->getConfigurationEvents(), 'a refused update must record nothing');
    }

    // --- optimistic concurrency ---

    public function testAStaleExpectedVersionIsRefusedAsAConflict(): void
    {
        $seeded = $this->seedCentre();
        $centre = $seeded['costCenter'];

        $first = $this->update($centre, name: 'First writer');
        $this->assertNotSame($centre->lockVersion, $first->lockVersion, 'a successful update must advance the version');

        // A second administrator still holding the version they read.
        $this->expectException(ConfigurationConflict::class);

        $this->update($centre, expectedVersion: $centre->lockVersion, name: 'Second writer');
    }

    public function testTheStaleWriteIsNotAppliedToTheRowThatMovedOn(): void
    {
        $seeded = $this->seedCentre();
        $centre = $seeded['costCenter'];
        $this->update($centre, name: 'First writer');

        try {
            $this->update($centre, expectedVersion: $centre->lockVersion, name: 'Second writer');
        } catch (ConfigurationConflict) {
            // expected
        }

        $this->assertSame(
            'First writer',
            $this->costCenters->findByPublicId((string) $centre->publicId)?->name,
            'a stale write must not overwrite the winner'
        );
    }

    public function testAStaleUpdateEmitsNoAuditEvent(): void
    {
        $seeded = $this->seedCentre();
        $centre = $seeded['costCenter'];
        $this->update($centre, name: 'First writer');
        $this->audit->clear();

        try {
            $this->update($centre, expectedVersion: $centre->lockVersion, name: 'Second writer');
        } catch (ConfigurationConflict) {
            // expected
        }

        $this->assertSame([], $this->audit->getConfigurationEvents());
    }

    public function testTheVersionAdvancesByExactlyOnePerUpdate(): void
    {
        $seeded = $this->seedCentre();
        $centre = $seeded['costCenter'];
        $this->assertSame(0, $centre->lockVersion);

        $first = $this->update($centre, name: 'One');
        $this->assertSame(1, $first->lockVersion);

        $second = $this->update($first, name: 'Two');
        $this->assertSame(2, $second->lockVersion);
    }

    // --- mutable FR-010 fields ---

    public function testTheCodeIsMutableAndTheOldCodeStopsResolvingToIt(): void
    {
        // FR-010 names code among the updatable fields. The consequence that matters: after a rename the
        // old code must not still find the centre, or two codes would resolve to one centre and the
        // purchaseer's cost-centre picker would show a centre nobody can address by its new code.
        $seeded = $this->seedCentre('CC-OLD');

        $updated = $this->update($seeded['costCenter'], code: 'CC-NEW');

        $this->assertSame('CC-NEW', $updated->code());
        $this->assertSame('CC-NEW', $this->costCenters->findByCode('CC-NEW')?->code());
        $this->assertNull($this->costCenters->findByCode('CC-OLD'), 'the pre-rename code must no longer resolve');
        $this->assertSame(
            $seeded['costCenter']->publicId,
            $updated->publicId,
            'and the stable identity must survive the rename'
        );
    }

    public function testRenamingOntoAnotherCostCentresCodeIsAConflict(): void
    {
        // `pettycash_cost_centers_code_uniq` exists, so the database would also refuse this — but as a
        // driver exception that `OcsErrorMapper` cannot turn into the contract's 409. The service check
        // is what makes the refusal speakable.
        $this->seedCentre('CC-ONE');
        $second = $this->seedCentre('CC-TWO');

        $this->expectException(ConfigurationConflict::class);

        $this->update($second['costCenter'], code: 'CC-ONE');
    }

    public function testRenamingToTheSameCodeIsNotAConflictWithItself(): void
    {
        $seeded = $this->seedCentre('CC-SAME');

        $updated = $this->update($seeded['costCenter'], code: 'CC-SAME', name: 'Renamed name only');

        $this->assertSame('CC-SAME', $updated->code());
    }

    public function testNameDescriptionAndValidityDatesAreUpdatable(): void
    {
        $seeded = $this->seedCentre();

        $updated = $this->update(
            $seeded['costCenter'],
            name: 'Engineering and Research',
            description: 'merged with R&D',
            validFrom: new DateTimeImmutable('2026-02-01', new \DateTimeZone('UTC')),
            validTo: new DateTimeImmutable('2026-12-31', new \DateTimeZone('UTC'))
        );

        $this->assertSame('Engineering and Research', $updated->name);
        $this->assertSame('merged with R&D', $updated->description);
        $this->assertSame('2026-02-01', $updated->validFrom->format('Y-m-d'));
        $this->assertSame('2026-12-31', $updated->validTo?->format('Y-m-d'));
    }

    public function testActiveStateIsUpdatable(): void
    {
        // FR-010 requires activate and deactivate. The contract carries no separate route for either, so
        // `active` on this write is how both are expressed — which is why update() is the whole of the
        // reopened scope and there is no second missing method.
        $seeded = $this->seedCentre();

        $deactivated = $this->update($seeded['costCenter'], active: false);
        $this->assertFalse($deactivated->active);
        $this->assertFalse($this->costCenters->findByPublicId((string) $seeded['costCenter']->publicId)?->active);

        $reactivated = $this->update($deactivated, active: true);
        $this->assertTrue($reactivated->active);
    }

    public function testADescriptionCanBeCleared(): void
    {
        $seeded = $this->seedCentre();

        $updated = $this->update($seeded['costCenter'], description: null);

        $this->assertNull($updated->description);
    }

    public function testValidationStillRunsThroughTheDomainOnUpdate(): void
    {
        // A blank name must be refused by `CostCenter::create()`, not accepted by an update path that
        // assigned the property directly — the same reason `CurrencyService::update()` re-validates.
        $seeded = $this->seedCentre();

        $this->expectException(InvalidArgumentException::class);

        $this->update($seeded['costCenter'], name: '   ');
    }

    public function testAValidToBeforeValidFromIsRefused(): void
    {
        $seeded = $this->seedCentre();

        $this->expectException(InvalidArgumentException::class);

        $this->update(
            $seeded['costCenter'],
            validFrom: new DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')),
            validTo: new DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'))
        );
    }

    // --- manager reassignment is a transfer ---

    public function testChangingTheManagerTransfersRatherThanOverwrites(): void
    {
        $seeded = $this->seedCentre('CC-ENG', 'test-manager', 'test-senior');
        $centre = $seeded['costCenter'];
        $transferDate = new DateTimeImmutable('2026-03-01', new \DateTimeZone('UTC'));

        $this->update($centre, manager: 'test-finance', validFrom: $transferDate);

        // The outgoing assignment is retained, and closed the day before — `isValidOn()` is inclusive at
        // both ends, so closing on the transfer date would leave one day with two current managers.
        $outgoing = $this->roleAssignments->findActiveByUserAndRole('test-manager', Role::CostCenterManager, $transferDate->modify('-1 day'));
        $this->assertCount(1, $outgoing, 'the previous manager must remain valid the day before the transfer');

        $this->assertSame(
            [],
            $this->roleAssignments->findActiveByUserAndRole('test-manager', Role::CostCenterManager, $transferDate),
            'and must not be current on the transfer date'
        );

        $incoming = $this->roleAssignments->findActiveByUserAndRole('test-finance', Role::CostCenterManager, $transferDate);
        $this->assertCount(1, $incoming, 'the incoming manager must be current from the transfer date');
    }

    public function testChangingTheSeniorManagerAlsoTransfers(): void
    {
        $seeded = $this->seedCentre('CC-ENG', 'test-manager', 'test-senior');
        $transferDate = new DateTimeImmutable('2026-03-01', new \DateTimeZone('UTC'));

        $this->update($seeded['costCenter'], senior: 'test-finance', validFrom: $transferDate);

        $this->assertSame([], $this->roleAssignments->findActiveByUserAndRole('test-senior', Role::SeniorManager, $transferDate));
        $this->assertCount(1, $this->roleAssignments->findActiveByUserAndRole('test-finance', Role::SeniorManager, $transferDate));
        // The other role is untouched: a manager change must not silently reassign the senior manager.
        $this->assertCount(1, $this->roleAssignments->findActiveByUserAndRole('test-manager', Role::CostCenterManager, $transferDate));
    }

    public function testAnUpdateThatDoesNotChangeTheManagerLeavesTheAssignmentAlone(): void
    {
        // Both manager fields are required by `CostCenterWrite`, so the caller resends the current holder.
        // Treating that as a transfer to the same person would close and reopen the assignment on every
        // rename, churning the history FR-011 depends on for no reason.
        $seeded = $this->seedCentre('CC-ENG', 'test-manager', 'test-senior');
        $before = count($this->roleAssignments->findActiveByUserAndRole('test-manager', Role::CostCenterManager, $this->today()));

        $this->update($seeded['costCenter'], name: 'Just a rename of the centre');

        $after = $this->roleAssignments->findActiveByUserAndRole('test-manager', Role::CostCenterManager, $this->today());
        $this->assertCount($before, $after);
        $this->assertSame('test-manager', $after[0]->userUid);
    }

    public function testAChangedManagerMustBeAHostUser(): void
    {
        $seeded = $this->seedCentre();

        $this->expectException(InvalidArgumentException::class);

        $this->update($seeded['costCenter'], manager: 'nobody');
    }

    public function testAChangedSeniorManagerMustBeAHostUser(): void
    {
        $seeded = $this->seedCentre();

        $this->expectException(InvalidArgumentException::class);

        $this->update($seeded['costCenter'], senior: 'nobody');
    }

    public function testOnePersonMayManageTwoCostCentres(): void
    {
        // FR-011: "Each cost center MUST have one current cost center manager and one current senior
        // manager, and **one person MAY manage multiple cost centers**." An earlier version of this file
        // asserted the opposite -- that naming a user who already managed *another* centre is a
        // conflict. It is not: the uniqueness FR-011 requires is per centre, and refusing this would
        // break the requirement's own second clause. The check is scoped to the centre being updated.
        $first = $this->seedCentre('CC-ONE', 'test-manager', 'test-senior');
        $second = $this->seedCentre('CC-TWO', 'test-manager', 'test-senior');

        $this->update($second['costCenter'], manager: 'test-finance');

        $managers = $this->roleAssignments->findActiveByUserAndRole('test-finance', Role::CostCenterManager, $this->today());
        $this->assertCount(1, $managers, 'the new centre now has its own current manager');

        // And the first centre keeps the person it had.
        $this->assertSame(
            'test-manager',
            $this->service->currentManagersOn($first['costCenter'], $this->today())[Role::CostCenterManager->value][0]->userUid
        );
    }

    public function testResendingTheCurrentHolderOfAnotherCentreIsNotTreatedAsATransferOfThisOne(): void
    {
        // The per-centre scope, asserted from the other direction: `test-finance` manages CC-TWO, and
        // naming it as CC-ONE's manager must transfer CC-ONE's role without closing anything on CC-TWO.
        $second = $this->seedCentre('CC-TWO', 'test-finance', 'test-senior');
        $first = $this->seedCentre('CC-ONE', 'test-manager', 'test-senior');

        $this->update($first['costCenter'], manager: 'test-finance');

        // Asserted as the *set* of centres, not `stillCurrent[0]`: the lookup is by user and role, so it
        // spans every centre, and FR-011's second clause means test-finance legitimately holds two
        // assignments here. Indexing the first element asserted whichever row sorted first -- which was
        // CC-TWO's, the centre that was never touched -- so the test passed or failed on ordering
        // rather than on the transfer.
        $centreIds = array_map(
            static fn(RoleAssignment $a): int => $a->costCenterId,
            $this->roleAssignments->findActiveByUserAndRole('test-finance', Role::CostCenterManager, $this->today())
        );
        sort($centreIds);

        $this->assertSame(
            [$second['costCenter']->id, $first['costCenter']->id],
            $centreIds,
            'the new assignment belongs to the centre that was updated, alongside the untouched one'
        );
    }

    // --- T086 audit integration ---

    public function testASuccessfulUpdateEmitsExactlyOneCostCenterUpdatedEvent(): void
    {
        $seeded = $this->seedCentre();
        $this->audit->clear();

        $this->update($seeded['costCenter'], name: 'Engineering (EMEA)');

        $events = $this->audit->getConfigurationEvents();
        $this->assertCount(1, $events);
        $this->assertSame(ConfigurationEventType::CostCenterUpdated->value, $events[0]['event_type']);
        $this->assertSame('cost_center', $events[0]['entity_type']);
    }

    public function testTheEventIsAddressedByTheStablePublicIdNotTheCode(): void
    {
        // `code` is mutable under FR-010, so an event keyed by it stops resolving the moment the centre
        // is renamed — the same event would answer to two different subjects before and after. The
        // contract itself addresses a cost centre by `costCenterId: { format: uuid }`, and
        // `pettycash_configuration_audit.entity_id` is a `string(64)` that already holds a uuid for
        // `ACCOUNT_CHARGED`, so nothing in the audit model objects.
        $seeded = $this->seedCentre('CC-OLD');
        $this->audit->clear();

        $this->update($seeded['costCenter'], code: 'CC-NEW');

        $this->assertSame(
            (string) $seeded['costCenter']->publicId,
            $this->audit->getConfigurationEvents()[0]['entity_id']
        );
    }

    public function testTheEventRecordsTheBeforeAndAfterPairIncludingBothCodes(): void
    {
        $seeded = $this->seedCentre('CC-OLD');
        $this->audit->clear();

        $this->update($seeded['costCenter'], code: 'CC-NEW', name: 'Renamed');

        $event = $this->audit->getConfigurationEvents()[0];

        $this->assertSame('CC-OLD', $event['before']['code'] ?? null);
        $this->assertSame('Engineering', $event['before']['name'] ?? null);
        $this->assertSame('CC-NEW', $event['after']['code'] ?? null);
        $this->assertSame('Renamed', $event['after']['name'] ?? null);
    }

    public function testChangeReasonReachesTheAuditEvent(): void
    {
        $seeded = $this->seedCentre();
        $this->audit->clear();

        $this->update($seeded['costCenter'], name: 'Engineering', changeReason: 'reorganisation');

        $this->assertSame('reorganisation', $this->audit->getConfigurationEvents()[0]['explanation']);
    }

    public function testAManagerChangeAlsoEmitsTheAssignmentTransferEvent(): void
    {
        // FR-017 names "assignments" as a category of its own, and T086 already gave the transfer its
        // own event type. Two events for this call is the correct count here: one for the centre, one
        // for the assignment, because two different subjects changed.
        $seeded = $this->seedCentre();
        $this->audit->clear();

        $this->update($seeded['costCenter'], manager: 'test-finance');

        $types = array_map(static fn(array $e): string => $e['event_type'], $this->audit->getConfigurationEvents());

        $this->assertContains(ConfigurationEventType::CostCenterUpdated->value, $types);
        $this->assertContains(ConfigurationEventType::ManagerTransferred->value, $types);
        $this->assertCount(2, $types, 'exactly one event per changed subject, and no more');
    }

    public function testAPlainUpdateEmitsNoTransferEvent(): void
    {
        $seeded = $this->seedCentre();
        $this->audit->clear();

        $this->update($seeded['costCenter'], name: 'Engineering (EMEA)');

        $types = array_map(static fn(array $e): string => $e['event_type'], $this->audit->getConfigurationEvents());

        $this->assertNotContains(
            ConfigurationEventType::ManagerTransferred->value,
            $types,
            'an update that reassigns nobody must not claim an assignment changed'
        );
    }

    public function testARefusedAuditPropagates(): void
    {
        // Propagation is the observable half of atomicity with these doubles; the real rollback -- that no
        // cost-centre row survives -- is asserted against MariaDB in the round-trip runner.
        $failing = new FailingAuditWriter($this->audit);
        $service = new CostCenterService(
            $this->costCenters,
            $this->roleAssignments,
            new InMemoryUserDirectory(),
            new InMemoryAdministratorCheck(),
            $this->clock,
            new ConfigurationAuditService($failing, $this->transactions)
        );
        $seeded = $this->service->create(
            self::ADMIN,
            'CC-ENG',
            'Engineering',
            null,
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );

        $this->expectException(\RuntimeException::class);

        $service->update(
            self::ADMIN,
            (string) $seeded['costCenter']->publicId,
            $seeded['costCenter']->lockVersion,
            'CC-ENG',
            'Never Committed',
            null,
            $this->today(),
            null,
            true,
            'test-manager',
            'test-senior'
        );
    }

    public function testTheAuditEventIsAppendedWhileTheTransactionIsStillOpen(): void
    {
        // Position, not merely existence. Recording that `transactional()` was entered proves only that
        // a transaction began: an audit appended after the callback returned still produces an event,
        // still produces exactly one, and still satisfies every count-based assertion in this file --
        // while committing the configuration change with no record of it, which is the failure FR-017
        // exists to prevent. This assertion is what makes "audit outside the transaction" a killed
        // mutant rather than a green run.
        $openWhenAppended = [];
        $spy = new class ($this->audit, $this->transactions, $openWhenAppended) implements \OCA\PettyCash\Application\Port\AuditWriter {
            /** @param list<bool> $probe */
            public function __construct(
                private readonly \OCA\PettyCash\Application\Port\AuditWriter $inner,
                private readonly InMemoryTransactionManager $transactions,
                private array &$probe
            ) {}

            public function append(array $event): void
            {
                $this->probe[] = $this->transactions->isTransactionOpen();
                $this->inner->append($event);
            }

            public function appendConfiguration(array $event): void
            {
                $this->probe[] = $this->transactions->isTransactionOpen();
                $this->inner->appendConfiguration($event);
            }
        };

        $service = new CostCenterService(
            $this->costCenters,
            $this->roleAssignments,
            new InMemoryUserDirectory(),
            new InMemoryAdministratorCheck(),
            $this->clock,
            new ConfigurationAuditService($spy, $this->transactions)
        );

        $seeded = $this->service->create(
            self::ADMIN,
            'CC-ENG',
            'Engineering',
            null,
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );
        $openWhenAppended = [];
        $service->update(
            self::ADMIN,
            (string) $seeded['costCenter']->publicId,
            $seeded['costCenter']->lockVersion,
            'CC-ENG',
            'Engineering (EMEA)',
            null,
            $this->today(),
            null,
            true,
            null,
            null,
            'reorganisation'
        );

        $this->assertCount(1, $openWhenAppended, 'exactly one event was appended');
        $this->assertTrue(
            $openWhenAppended[0],
            'the audit append must happen inside the transaction carrying the mutation'
        );
    }

    // --- delivered behaviour is not disturbed ---

    public function testTheAlreadyProvenReadsAndTransferStillWorkAfterAnUpdate(): void
    {
        $seeded = $this->seedCentre();
        $centre = $seeded['costCenter'];

        $updated = $this->update($centre, name: 'Engineering (EMEA)', active: false);

        // `listValidOnForCaller()` must exclude a deactivated centre -- T082's own retained rule.
        $codes = array_map(static fn(CostCenter $c): string => $c->code(), $this->service->listValidOnForCaller(self::ADMIN, $this->today())['items']);
        $this->assertNotContains('CC-ENG', $codes);

        // And `currentManagersOn()` must still resolve the assignments through the renamed centre.
        $current = $this->service->currentManagersOn($updated, $this->today());
        $this->assertSame('test-manager', $current[Role::CostCenterManager->value][0]->userUid);
        $this->assertSame('test-senior', $current[Role::SeniorManager->value][0]->userUid);
    }
}