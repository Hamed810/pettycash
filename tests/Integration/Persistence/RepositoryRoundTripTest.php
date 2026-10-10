<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Persistence;

use OCA\PettyCash\Tests\Support\LeakedRows;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/Support/LeakedRows.php';

/**
 * Constitution V: persistence, revisions and audit records must have automated integration tests.
 *
 * ## Why this file exists
 *
 * Every other test in this suite substitutes one of 13 `InMemory*` doubles. A double accepts whatever
 * aggregate it is handed, so it cannot tell a correct repository from one that writes columns the
 * table does not have, forgets a `NOT NULL` one, or reads back a surrogate key where a public UUID
 * belongs. That is not a theoretical gap: the first run of this file found **eleven** such defects
 * across nine of the fourteen production repositories, including three write paths that had never
 * executed a single statement. `docs/reports/T236.md` has the list and the reasoning.
 *
 * ## How it reaches a real database
 *
 * Through `roundtrip-runner.php` in a subprocess, which bootstraps the Nextcloud server. The
 * PHPUnit process cannot: `tests/bootstrap.php` wires a container of in-memory doubles, and loading
 * `lib/base.php` into it would replace the global server. `tests/Contract/Fixture/seed-contract-fixtures.php`
 * established this pattern and the reason for it.
 *
 * ## Why the runner reports and this file asserts
 *
 * The runner performs the round trips and prints the values it read out of MariaDB; the assertions
 * are here. So a failure names the figure that was actually stored, and a bug in the runner cannot
 * turn a broken repository green by agreeing with itself.
 *
 * ## What it asserts
 *
 * Per repository: that an aggregate survives a save and is retrievable through the join keys the
 * repository itself offers — public id, surrogate key, and the natural or scope keys. For the chain
 * the task calls out: that a revision, a decision and an audit row each point at the correct parent.
 *
 * Scenarios are memoised per process, so the four Nextcloud bootstraps happen once rather than once
 * per assertion.
 */
final class RepositoryRoundTripTest extends TestCase
{
    /**
     * Memoised per process, and deliberately **not** cleared between test methods.
     *
     * Each scenario costs one Nextcloud bootstrap, and there are four of them; re-running per test
     * method would mean roughly eighty bootstraps for this file alone. A report is a read-only
     * record of what one round trip observed and nothing mutates it, so sharing it is safe — and the
     * runner has already deleted the rows it describes by the time the report is read.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $reports = [];

    // ===== CONFIGURATION =====

    // ===== T081 / T082 / T083: the application services over the real repositories =====
    //
    // Everything above proves the *persistence* layer round-trips. These prove the *application* layer
    // does, against the same MariaDB instance: a service whose rules only hold against an in-memory
    // double is a service that has never been run. Three properties each — the write lands, the
    // authorization gate holds against a real `IGroupManager`, and the invariant the schema does not
    // hold is enforced by the service rather than by a unique index that does not exist.

    public function testTheCurrencyServiceWritesThroughToTheRealTable(): void
    {
        $services = $this->report('configuration')['services'];

        // `minor_unit` 3 is a scale no built-in table would guess, so a projection that substituted one
        // would be caught here. `currencyPersisted` is the value read back out of MariaDB.
        $this->assertSame(3, $services['currencyPersisted'], 'the service-written currency must persist its configured scale');
        $this->assertSame(3, $services['currencyMinorUnit']);
    }

    public function testTheCurrencyServiceRefusesADuplicateCodeAgainstTheRealTable(): void
    {
        // `pettycash_currencies_code_uniq` exists, so the database would also refuse this — but as a
        // driver exception, which `OcsErrorMapper` cannot turn into the contract's 409. The service
        // check is what makes the refusal speakable, and this proves it fires first.
        $this->assertTrue(
            $this->report('configuration')['services']['duplicateRefused'],
            'a duplicate currency code must be refused by the service, not left to the unique index'
        );
    }

    public function testTheCurrencyServiceRefusesANonAdministratorAgainstTheRealGroupManager(): void
    {
        $this->assertTrue(
            $this->report('configuration')['services']['nonAdministratorRefused'],
            'FR-010/FR-013 and T078: only an administrator may administer configuration'
        );
    }

    public function testTheCurrencyServiceRefusesAStaleExpectedVersion(): void
    {
        $this->assertTrue($this->report('configuration')['services']['staleVersionRefused']);
    }

    public function testCostCenterManagersAreRoleAssignmentsAndNotPropertiesOfTheCentre(): void
    {
        // T082's whole reason for existing. The service was handed a `managerUserId` and a
        // `seniorManagerUserId`, and neither survived as a field on the aggregate — both came back as
        // rows scoped by the centre's surrogate key, which is the join
        // `ManagementReviewService::getManagedCostCenterCodes()` already walks to scope a reviewer.
        $services = $this->report('configuration')['services'];

        $this->assertNotEmpty($services['costCenterCode'], 'the service must have persisted a cost centre');
        $this->assertSame([self::actorUid()], $services['managerUsers'], 'FR-011: exactly one current cost-center manager, held as a role assignment');
        $this->assertSame(['admin'], $services['seniorManagerUsers'], 'FR-011: exactly one current senior manager, held as a role assignment');
        $this->assertCount(1, $services['managerUsers'], 'two current managers would route a submission ambiguously');
        $this->assertCount(1, $services['seniorManagerUsers']);
    }

    public function testTheExpenseTypeServiceWritesThroughToTheRealTable(): void
    {
        $services = $this->report('configuration')['services'];

        $this->assertNotEmpty($services['expenseTypeTitle']);
        $this->assertTrue(
            $services['expenseTypePersisted'],
            'FR-012: the evidence requirement must persist as written, not default to false'
        );
    }

    public function testTheExpenseTypeServiceRefusesADuplicateActiveTitle(): void
    {
        // **No unique index exists on `pettycash_expense_types.title`** — only
        // `pettycash_currencies_code_uniq` and `pettycash_cost_centers_code_uniq` are declared. So
        // nothing below the application layer prevents two active types sharing a title, and FR-023
        // makes a caller choose between them with different evidence rules. This is the one invariant in
        // this batch that only the service can hold.
        $this->assertTrue(
            $this->report('configuration')['services']['duplicateTitleRefused'],
            'active-title uniqueness is service-enforced; no database index backs it'
        );
    }

    public function testDeactivatingAnExpenseTypeKeepsTheRowAndItsHistoricalFields(): void
    {
        // FR-012 and US5/AC2: a deactivated type stops being offered while historical records keep their
        // original values. The row is read back by surrogate key *after* deactivation, so this fails if
        // deactivation deleted the row or rewrote the evidence rule records were filed against.
        $services = $this->report('configuration')['services'];

        $this->assertFalse($services['deactivated'], 'the type must be inactive');
        $this->assertSame($services['expenseTypeTitle'], $services['titleAfterDeactivation'], 'the retained row must keep its title');
        $this->assertTrue($services['evidenceRequiredAfterDeactivation'], 'the retained row must keep the evidence rule it was filed under');
        $this->assertFalse($services['stillListedAsActive'], 'a deactivated type must leave the active list');
    }

    // --- T084: the shared-queue finance roster over the real table ---

    public function testTheFinanceAssignmentServiceStoresAFinanceRoleWithANullCostCentreColumn(): void
    {
        // Asserted on the raw column, not on the aggregate. The unit suite can only ask
        // `RoleAssignment` what it believes about itself, and an insert that quietly filled in a cost
        // centre would leave the aggregate reporting null while the row said otherwise. FR-016's shared
        // queue is unscoped by definition: an officer scoped to one centre would not see the other
        // centres' transactions, which is the entire content of the queue.
        $services = $this->report('configuration')['services'];

        $this->assertSame('FINANCE', $services['financeRoleInColumn'], 'the stored role must be FINANCE');
        $this->assertTrue(
            $services['financeCostCenterColumnIsNull'],
            'data-model.md: cost_center_id is null for the shared finance role, in the column itself'
        );
        $this->assertSame('FINANCE', $services['financeAggregateRole']);
        $this->assertTrue($services['financeAggregateCostCenterIsNull']);
    }

    public function testTheFinanceAssignmentServicePersistsTheValidityWindowItWasGiven(): void
    {
        // The window was a lapsed one (ended yesterday) so that "is this user current?" has a negative
        // answer to read. Both ends are checked, because a projection that dropped `valid_to` would
        // leave every finance officer looking indefinitely current.
        $services = $this->report('configuration')['services'];

        $this->assertNotNull($services['financeValidFromInColumn'], 'valid_from must persist as written');
        $this->assertNotNull($services['financeValidToInColumn'], 'valid_to must persist as written');
    }

    public function testALapsedFinanceAssignmentIsNotTreatedAsCurrent(): void
    {
        // This is the query the whole upsert decision rests on. If it answered "current" for a window
        // that ended yesterday, re-appointment would rewrite history and the contract's "Active and
        // historical finance assignments" response would have nothing historical to return.
        $this->assertTrue(
            $this->report('configuration')['services']['noCurrentAssignmentAfterLapse'],
            'an assignment whose window has ended must not resolve as the current one'
        );
    }

    public function testReappointingAFinanceOfficerRetainsTheLapsedAssignment(): void
    {
        $services = $this->report('configuration')['services'];

        $this->assertTrue(
            $services['lapsedRowSurvivedReAppointment'],
            'the ended window must still be readable after the user is appointed again'
        );
        $this->assertSame(2, $services['rowsForFinanceUserAfterReAppointment'], 're-appointment adds a row rather than rewriting the lapsed one');
    }

    public function testAPostForAFinanceOfficerWhoAlreadyHoldsOneConvergesOnASingleRow(): void
    {
        // `contracts/ocs-api.yaml` declares no PATCH and no DELETE for finance assignments, so POST is
        // the only write and has to be idempotent. Nothing below the application layer enforces this:
        // `pettycash_role_assignments` carries only a non-unique index on `(user_uid, role, active)`.
        $services = $this->report('configuration')['services'];

        $this->assertTrue($services['repeatedPostUpdatedInPlace']);
        $this->assertSame(2, $services['rowsForFinanceUserAfterRepeat'], 'a repeated POST must not add a row');
    }

    public function testRevokingAFinanceOfficerClearsTheFlagButKeepsAndReusesTheRow(): void
    {
        // Revocation has to arrive as a POST carrying `active: false`, because the contract has no other
        // write. The row must survive (FR-016's queue is auditable, and the roster is specified as
        // active *and historical*), and re-validating must reuse it rather than claiming the officer held
        // the role twice over one window.
        $services = $this->report('configuration')['services'];

        $this->assertTrue($services['revocationClearedTheActiveFlag'], 'active: false must be persisted');
        $this->assertTrue($services['revocationReusedTheSameRow'], 'revocation must not insert a second row');
        $this->assertTrue($services['revokedRowStillInRoster'], 'a revoked assignment is history and stays listed');
        $this->assertSame(2, $services['rowsForFinanceUserAfterRevocation']);

        $this->assertTrue($services['revalidationReusedTheSameRow']);
        $this->assertSame(2, $services['rowsForFinanceUserAfterRevalidation'], 're-validating must reuse the retained row');
    }

    public function testTheFinanceRosterContainsOnlyFinanceAssignments(): void
    {
        // T082 created cost-centre-manager and senior-manager assignments in the same table during the
        // same scenario. Returning them here would answer "list the shared queue" with "list every
        // access assignment".
        $this->assertTrue($this->report('configuration')['services']['rosterExcludesManagerAssignments']);
    }

    public function testTheFinanceAssignmentServiceRefusesANonAdministratorAgainstTheRealGroupManager(): void
    {
        $this->assertTrue(
            $this->report('configuration')['services']['financeNonAdministratorRefused'],
            'T078: only an administrator may administer finance assignments'
        );
    }

    public function testTheFinanceAssignmentServiceRefusesAUserThatDoesNotExist(): void
    {
        // `pettycash_role_assignments.user_uid` is a plain string(64) with no foreign key, so the
        // database would happily accept an assignment naming nobody.
        $this->assertTrue(
            $this->report('configuration')['services']['financeUnknownHostUserRefused'],
            'an assignment may only name a user in this Nextcloud'
        );
    }

    // --- T082's reopened scope: `update()` against the real table ---

    public function testRenamingACostCentreActuallyPersistsTheNewCode(): void
    {
        // T082's reopened scope. `DbCostCenterRepository::save()`'s update branch omitted `code`, so a
        // rename reported success, advanced `lock_version`, and left the row keyed by the old code --
        // the new code resolved to nothing and the old one still resolved to the centre just renamed.
        // The in-memory double accepts the aggregate with any code, so no unit test could see it.
        $services = $this->report('configuration')['services'];

        $this->assertSame($services['updateNewCode'], $services['updateCodePersistedInColumn'], 'the renamed code must be the code in the column');
        $this->assertTrue($services['updateOldCodeNoLongerResolves'], 'the pre-rename code must no longer resolve to the centre');
        $this->assertTrue($services['updatePublicIdUnchanged'], 'and the stable uuid must survive the rename');
        $this->assertSame('After The Rename', $services['updateNamePersistedInColumn']);
        $this->assertFalse($services['updateDeactivatedInColumn'], 'FR-010: deactivation must persist as written');
    }

    public function testUpdatingACostCentreAdvancesLockVersionByOneAndPublishesIt(): void
    {
        // The contract's `CostCenter` response is required to carry `version`. If the adapter left the
        // aggregate holding the pre-update value, a client echoing it straight back into
        // `expectedVersion` would be refused for the write it had only just made.
        $services = $this->report('configuration')['services'];

        $this->assertSame(0, $services['updateVersionBefore']);
        $this->assertSame(1, $services['updateVersionInColumnAfter'], 'the row must advance by exactly one');
        $this->assertSame(
            $services['updateVersionInColumnAfter'],
            $services['updateVersionReturnedToCaller'],
            'the version handed back must be the one now in the column, not the superseded one'
        );
    }

    public function testAStaleCostCentreUpdateIsRefusedAndDoesNotOverwriteTheWinner(): void
    {
        $services = $this->report('configuration')['services'];

        $this->assertTrue($services['updateStaleRefused'], 'a stale expectedVersion must be a 409, not a silent overwrite');
        $this->assertSame('After The Rename', $services['updateNameAfterStaleAttempt'], 'the first writer must stand');
    }

    public function testACostCentreUpdateIsAddressedByUuidInTheAuditHistory(): void
    {
        $services = $this->report('configuration')['services'];

        // `code` is mutable under FR-010, so an event keyed by it stops resolving on rename. The uuid is
        // stable and is what the contract's own path parameter uses.
        $this->assertNotSame(
            $services['updateNewCode'],
            $services['updateEventEntityId'],
            'the audit subject must not be the code, which the same update just changed'
        );
        $this->assertSame('round trip: renaming the centre', $services['updateEventExplanation']);
    }

    public function testARefusedAuditRollsBackACostCentreUpdate(): void
    {
        // The real rollback for this write path. `InMemoryTransactionManager` rolls nothing back, so no
        // in-memory test can produce this failure.
        $services = $this->report('configuration')['services'];

        $this->assertNotNull($services['updateAuditRefusal']);
        $this->assertFalse(
            $services['updateRowSurvivedAuditRefusal'],
            'a cost-centre update must not stay committed when its audit record could not be written'
        );
    }

// --- T086: the configuration audit is atomic with its mutation, over the real transaction ---

    public function testAConfigurationWriteCommitsExactlyOneAuditEvent(): void
    {
        // FR-017 requires the actor, time, explanation, previous value and new value. One event per
        // mutation: an administration screen that showed every change three times would be unreadable,
        // and "an event exists" is satisfied by a double-count as happily as by a correct count.
        $services = $this->report('configuration')['services'];

        $this->assertTrue($services['auditedEventExists'], 'a configuration write must record a configuration audit event');
        $this->assertSame(1, $services['auditedEventsForSubject'], 'exactly one event per mutation');
        $this->assertSame('currency', $services['auditedRowEntityType']);
        $this->assertSame($services['auditedCurrencyCode'], $services['auditedRowEntityId'], 'the event must name its subject');
        $this->assertSame('CURRENCY_CREATED', $services['auditedRowEventType']);
    }

    public function testTheConfigurationAuditEventRecordsTheAuthenticatedActor(): void
    {
        // The session user, and *not* the `$administratorUid` the service authorized the write with.
        // `AuditWriterService` resolves the actor from `CurrentUser` and ignores any `actor_uid` in the
        // payload, because an actor supplied by a caller is a value the caller chose. `actor_role` is
        // passed explicitly, since the writer would otherwise default every configuration change in the
        // product to PURCHASER.
        $services = $this->report('configuration')['services'];

        $this->assertSame($services['sessionUid'], $services['auditedRowActorUid']);
        $this->assertSame('ADMINISTRATOR', $services['auditedRowActorRole']);
    }

    public function testTheConfigurationAuditEventStoresTheAfterSnapshotRatherThanEmptyJson(): void
    {
        // `AuditWriterService` documents two real callers that sent `before_json` / `after_json`, keys it
        // does not read, so every approval and return was stored with both snapshots as the literal
        // `"[]"` -- permanently, because FR-053 makes audit rows append-only. Asserted against the raw
        // columns rather than the domain object for that reason.
        $services = $this->report('configuration')['services'];
        $after = json_decode((string) $services['auditedRowAfterJson'], true);

        $this->assertIsArray($after, 'the after snapshot must decode as a JSON object, not the literal "[]"');
        $this->assertSame($services['auditedCurrencyCode'], $after['code'] ?? null);
        $this->assertSame(2, $after['minorUnit'] ?? null, 'a scale no built-in table would guess, so a substituted value would show here');

        // A *creation*'s empty `before` legitimately encodes as `"[]"`, which is byte-identical to the
        // failure above -- so the creation cannot discriminate, and the update case below must.
        $this->assertSame('[]', $services['auditedRowBeforeJson']);
    }

    public function testAnUpdateEventStoresAPopulatedBeforeAndAfterThatDiffer(): void
    {
        // The discriminating assertion for the snapshots. If `before` were read after the write, or not
        // read at all, this pair would be empty or identical to `after`, and FR-017's "previous value,
        // and new value" would be satisfied in form while answering nothing.
        $services = $this->report('configuration')['services'];

        $this->assertSame('CURRENCY_UPDATED', $services['updateRowEventType']);
        $this->assertIsArray($services['updateBeforeJson'], 'an update must carry a readable previous value');
        $this->assertIsArray($services['updateAfterJson']);

        // 'Before Name'/scale 3/active -> 'After Name'/scale 0/inactive.
        $this->assertSame('Before Name', $services['updateBeforeJson']['name'] ?? null);
        $this->assertSame(3, $services['updateBeforeJson']['minorUnit'] ?? null);
        $this->assertTrue($services['updateBeforeJson']['active'] ?? false);
        $this->assertSame('After Name', $services['updateAfterJson']['name'] ?? null);
        $this->assertSame(0, $services['updateAfterJson']['minorUnit'] ?? null);
        $this->assertFalse($services['updateAfterJson']['active'] ?? true);
        $this->assertNotSame(
            $services['updateBeforeJson'],
            $services['updateAfterJson'],
            'a before/after pair holding the same values records that nothing changed'
        );
    }

    public function testACurrencyEventCarriesNoExplanationBecauseTheContractSuppliesNoChangeReason(): void
    {
        // `contracts/ocs-api.yaml`'s `CurrencyWrite` has no `changeReason` field, so there is nothing for
        // a caller to supply, and the application payload carries a null explanation (asserted at that
        // boundary in `ConfigurationMutationAuditTest`).
        //
        // What lands in the column is `''` rather than NULL, and that is a pre-existing coercion in
        // `DbAuditEventRepository::rowValues()` -- `$event->explanation ?? ''` -- while every other
        // nullable column there binds a real NULL. It is asserted rather than worked around so that a
        // future change to that coercion cannot pass unnoticed: `''` and NULL read identically here, and
        // neither can be distinguished from "an explanation was given and it was blank". The gap is
        // recorded in `docs/reports/T086.md`; changing shared audit persistence is not this task's to do.
        $services = $this->report('configuration')['services'];

        $this->assertFalse(
            $services['auditedRowExplanationIsNull'],
            'the persisted explanation is "" today; if this now passes, DbAuditEventRepository stopped coercing null and this test needs rewriting'
        );
        $this->assertSame('', (string) $services['auditedRowExplanation']);
    }

    public function testARefusedAuditLeavesNoCommittedConfigurationRow(): void
    {
        // The load-bearing atomicity assertion, and the only one that needs a real database:
        // `InMemoryTransactionManager` runs its callback and rolls nothing back, so no in-memory test can
        // produce this failure at all. FR-017 forbids a configuration change that is not recorded, so a
        // write whose audit cannot be persisted must leave nothing behind -- the currency row included.
        $services = $this->report('configuration')['services'];

        $this->assertNotNull($services['auditRefusalObserved'], 'the audit refusal must propagate rather than be swallowed');
        $this->assertFalse(
            $services['currencyRowSurvivedAuditRefusal'],
            'a configuration change must not stay committed when its audit record could not be written'
        );
        $this->assertSame(0, $services['auditRowsAfterAuditRefusal'], 'and the refused audit must leave no row either');
    }

    public function testARefusedMutationLeavesNoFurtherAuditEvent(): void
    {
        // The other direction: a write the domain rejects must not appear in the configuration history,
        // or the history would describe changes that never happened.
        $services = $this->report('configuration')['services'];

        $this->assertNotNull($services['duplicateRefusalObserved'], 'the duplicate must actually be refused for this assertion to mean anything');
        $this->assertSame(1, $services['auditCountBeforeRefusedMutation']);
        $this->assertSame(
            $services['auditCountBeforeRefusedMutation'],
            $services['auditCountAfterRefusedMutation'],
            'a refused mutation must not add an audit event'
        );
    }

    public function testCurrencySurvivesASaveAndIsFoundByItsCode(): void
    {
        $currency = $this->report('configuration')['currency'];

        $this->assertNotNull($currency['byCode'], 'a saved currency must be readable back by its code');
        $this->assertSame($currency['code'], $currency['byCode']['code']);
        $this->assertSame('Round Trip Currency', $currency['byCode']['name']);
        // minorUnit 3 is a scale no built-in currency table would guess, so a projection that
        // substituted a hardcoded scale would be caught here rather than tolerated.
        $this->assertSame(3, $currency['byCode']['minorUnit'], 'the configured fraction digits must round-trip');
        $this->assertTrue($currency['byCode']['active']);
        $this->assertTrue($currency['byCode']['hasSurrogateKey'], 'mapRow must assign the surrogate key it read');
        $this->assertTrue($currency['listedAmongAllActive'], 'an active currency must be listed as active');
    }

    public function testAnActiveCurrencyIsFoundByTheActiveLookup(): void
    {
        $currency = $this->report('configuration')['currency'];

        $this->assertNotNull($currency['activeByCode']);
        $this->assertSame($currency['code'], $currency['activeByCode']['code']);
    }

    public function testCostCenterSurvivesASaveAndIsFoundByCodeAndPublicId(): void
    {
        $costCenter = $this->report('configuration')['costCenter'];

        $this->assertNotNull($costCenter['byCode'], 'a saved cost center must be readable back by its code');
        $this->assertSame($costCenter['code'], $costCenter['byCode']['code']);
        $this->assertSame('Round Trip Cost Center', $costCenter['byCode']['name']);
        $this->assertSame('created by the persistence round trip', $costCenter['byCode']['description']);
        $this->assertTrue($costCenter['byCode']['active']);
        $this->assertTrue($costCenter['byCode']['hasSurrogateKey'], 'save() must assign the surrogate key back');
        // `public_id` is NOT NULL with no default; the insert used to omit it, so every cost-center
        // write failed before it could fail on the two columns that did not exist.
        $this->assertTrue($costCenter['byCode']['hasPublicId'], 'save() must mint and write public_id');
        $this->assertSame($costCenter['code'], $costCenter['byPublicId']['code'], 'and it must resolve');
        $this->assertSame($costCenter['code'], $costCenter['activeOnToday']['code']);
        $this->assertTrue($costCenter['listedAmongAllActive']);
    }

    /**
     * A cost center's managers are role assignments, not columns.
     *
     * `DbCostCenterRepository` used to write `manager_uid` and `senior_manager_uid`, which exist in
     * no migration and in no table, so the insert failed with "Unknown column 'manager_uid'" every
     * time. This asserts the relationship that replaced it: a senior-manager assignment scoped to
     * the cost center resolves back to that cost center, which is the join
     * `ManagementReviewService::getManagedCostCenterCodes()` performs to scope a reviewer's queue.
     */
    public function testACostCentersManagersResolveThroughItsRoleAssignments(): void
    {
        $this->assertTrue(
            $this->report('configuration')['costCenter']['foundAsManagedBySeniorManager'],
            'a senior-manager assignment scoped to a cost center must resolve back to that cost center'
        );
    }

    public function testExpenseTypeSurvivesASaveAndIsFoundByTitleAndPublicId(): void
    {
        $expenseType = $this->report('configuration')['expenseType'];

        $this->assertNotNull($expenseType['byTitle'], 'a saved expense type must be readable back by its title');
        $this->assertSame($expenseType['title'], $expenseType['byTitle']['title']);
        $this->assertTrue($expenseType['byTitle']['evidenceRequired'], 'the evidence rule must survive as true');
        $this->assertTrue($expenseType['byTitle']['active']);
        $this->assertTrue($expenseType['byTitle']['hasSurrogateKey']);
        $this->assertSame($expenseType['title'], $expenseType['byPublicId']['title']);
        $this->assertTrue($expenseType['listedAmongAllActive']);
    }

    public function testRoleAssignmentIsFoundByEveryScopeKeyItDeclares(): void
    {
        $roleAssignment = $this->report('configuration')['roleAssignment'];

        $this->assertTrue($roleAssignment['foundForUser'], 'findActiveByUserUid must find it');
        $this->assertTrue($roleAssignment['foundForUserAndRole'], 'findActiveByUserAndRole must find it');
        // The scoped read is how a queue is narrowed to the cost centers a user actually manages, so
        // it is the one that matters most and the one a surrogate key in place of a public id breaks.
        $this->assertTrue(
            $roleAssignment['foundForRoleAndCostCenter'],
            'findActiveByRoleAndCostCenter must find it, which means cost_center_id must round-trip'
        );
    }

    // ===== ACCOUNT AND LEDGER =====

    public function testAccountIsFoundByPublicIdAndByItsSurrogateKey(): void
    {
        $account = $this->report('account')['account'];

        $this->assertNotNull($account['byPublicId'], 'a saved account must be readable back by its public id');
        $this->assertSame($account['publicId'], $account['byPublicId']['publicId']);
        $this->assertSame($account['publicId'], $account['bySurrogateKey']['publicId']);
        $this->assertTrue($account['byPublicId']['active']);
    }

    /**
     * The account's balance and lock version must survive the charge that changed them.
     *
     * The account is read back *after* the charge is persisted, so this is the update branch and not
     * merely the insert: the balance has to be the post-charge figure and the lock version has to
     * have been incremented under the optimistic lock. Reading before the charge would only have
     * proved the insert wrote the opening balance of zero.
     */
    public function testTheAccountBalanceAndLockVersionSurviveACharge(): void
    {
        $account = $this->report('account')['account'];

        $this->assertSame(100500, $account['balanceAfterCharge'], 'the charge adds 100500 minor units');
        $this->assertSame(
            $account['balanceAfterCharge'],
            $account['byPublicId']['balanceMinor'],
            'the balance MariaDB holds must be the one the aggregate ended with'
        );
        $this->assertSame(1, $account['lockVersionAfterCharge'], 'the update must increment the lock version');
        $this->assertSame(
            $account['lockVersionAfterCharge'],
            $account['byPublicId']['lockVersion'],
            'and the incremented version must be the one stored'
        );
    }

    /**
     * The account projection takes its scale from the account's own currency, through a join.
     *
     * The currency here is a three-letter code that exists only because this run created it, with
     * `minor_unit` 2. A projection that guessed from a built-in table would answer 0, and every
     * figure the ledger shows would be out by a factor of a hundred.
     */
    public function testTheAccountProjectionJoinsItsConfiguredCurrencyScale(): void
    {
        $byPublicId = $this->report('account')['account']['byPublicId'];

        $this->assertSame(2, $byPublicId['minorUnit'], 'the join must supply the configured minor unit');
        $this->assertSame(100500, $byPublicId['balanceMinor']);
    }

    public function testAccountIsListedForItsOwnerByBothScopeReads(): void
    {
        $account = $this->report('account')['account'];

        $this->assertTrue($account['listedForUser'], 'findActiveByUserUid must list it');
        $this->assertTrue(
            $account['listedForUserAndCurrency'],
            'findActiveByUserAndCurrency must list it, which means the currency join must resolve'
        );
    }

    /**
     * A charge is on the ledger with a null source revision.
     *
     * This is the row an inner join to the revision table would have silently dropped, because
     * `source_revision_id` is null for exactly the charges.
     */
    public function testAChargeIsOnTheLedgerAndFindableByItsCommandId(): void
    {
        $movement = $this->report('account')['movement'];

        $this->assertNotNull($movement['byCommandId'], 'a saved movement must be findable by its command id');
        $this->assertSame($movement['publicId'], $movement['byCommandId']['publicId']);
        $this->assertSame('CHARGE', $movement['byCommandId']['type']);
        $this->assertSame(100500, $movement['byCommandId']['amountMinor']);
        $this->assertSame(100500, $movement['byCommandId']['balanceAfterMinor']);
        $this->assertSame('round trip charge', $movement['byCommandId']['description']);
        $this->assertNull(
            $movement['byCommandId']['sourceRevisionId'],
            'a charge is not justified by a revision, and the left join must yield null rather than drop the row'
        );
        $this->assertTrue($movement['byCommandId']['hasOccurredAt'], 'occurred_at must be written');
    }

    public function testTheLedgerIsListedForTheAccountAndPaginates(): void
    {
        $movement = $this->report('account')['movement'];

        $this->assertSame(1, $movement['ledgerCount']);
        $this->assertSame(['CHARGE'], $movement['ledgerTypes']);
        $this->assertSame([null], $movement['ledgerSourceRevisionIds']);
        $this->assertSame(1, $movement['pagedCount']);
    }

    // ===== SHEET, TRANSACTION, REVISION =====

    public function testSheetIsFoundByPublicIdAndByItsSurrogateKey(): void
    {
        $sheet = $this->report('expense')['sheet'];

        $this->assertNotNull($sheet['byPublicId']);
        $this->assertSame($sheet['publicId'], $sheet['byPublicId']['publicId']);
        $this->assertSame($sheet['publicId'], $sheet['bySurrogateKey']['publicId']);
        $this->assertSame('Round Trip Sheet', $sheet['byPublicId']['title']);
        $this->assertSame('OPEN', $sheet['byPublicId']['status']);
        $this->assertTrue($sheet['listedForOwner']);
        $this->assertTrue($sheet['listedUnderStatus']);
    }

    /**
     * The transaction carries its active revision, and that revision knows its own currency.
     *
     * `pettycash_transaction_revisions` stores an integer minor-unit amount with no currency of its
     * own, so the read path has to walk revision -> transaction -> sheet -> currency. The currency
     * here has `minor_unit` 0, and the amount is 250000: a read that assumed a default scale would
     * report a different real amount for the same integer, and every total, settlement and export
     * derived from it would be wrong.
     */
    public function testTransactionCarriesItsActiveRevisionWithTheSheetsCurrency(): void
    {
        $report = $this->report('expense');
        $transaction = $report['transaction'];

        $this->assertNotNull($transaction['byPublicId']);
        $this->assertSame($transaction['publicId'], $transaction['bySurrogateKey']['publicId']);

        $revision = $transaction['byPublicId']['revision'];
        $this->assertSame($report['revision']['firstPublicId'], $revision['publicId']);
        $this->assertSame(1, $revision['number']);
        $this->assertSame(250000, $revision['amountMinor']);
        $this->assertSame(
            $report['sheet']['byPublicId']['currency'],
            $revision['currency'],
            'the revision must be denominated in the currency resolved through its sheet'
        );
        $this->assertTrue($transaction['listedForSheet']);
        $this->assertSame(1, $transaction['countForSheet']);
    }

    /**
     * The revision's own row, read directly, keeps every business field.
     *
     * T227's predecessor problem was a read path that dropped every one of them, which then failed
     * submission validation and emptied the authorization scope built from the cost center.
     */
    public function testRevisionKeepsItsSnapshotsAndItsParentLink(): void
    {
        $revision = $this->report('expense')['revision'];

        $this->assertNotNull($revision['byPublicId']);
        $this->assertSame($revision['firstPublicId'], $revision['byPublicId']['publicId']);
        $this->assertSame(1, $revision['byPublicId']['number']);
        $this->assertSame(250000, $revision['byPublicId']['amountMinor']);
        // The cost center's numeric join key and its public UUID are different things, and the
        // authorization scope is built from the UUID. A read that returns the key empties it.
        $this->assertNotNull($revision['byPublicId']['costCenterId']);
        $this->assertSame(
            $revision['byPublicId']['costCenterId'],
            $revision['byPublicId']['costCenterId'],
            'costCenterId must be the public UUID, not the surrogate key'
        );
        $this->assertNotNull($revision['byPublicId']['costCenterCode']);
        $this->assertNotNull($revision['byPublicId']['expenseTypeTitle'], 'the expense type title is a snapshot');
        $this->assertNotNull($revision['byPublicId']['expenseTypeDbId']);
        $this->assertSame(self::actorUid(), $revision['byPublicId']['managerUid']);
        $this->assertNotNull(
            $revision['byPublicId']['transactionDbId'],
            'the revision must point back at the transaction that owns it'
        );
    }

    public function testTheActiveRevisionIsTheOneTheTransactionNames(): void
    {
        $revision = $this->report('expense')['revision'];

        // This is a join through `pettycash_transactions.active_revision_id`, so it only resolves
        // once the transaction has been re-saved pointing at its revision.
        $this->assertSame($revision['firstPublicId'], $revision['activeForTransaction']['publicId']);
        $this->assertSame(1, $revision['activeForTransaction']['number']);
        $this->assertSame(1, $revision['countForTransaction']);
    }

    public function testASecondRevisionSupersedesTheFirstByPublicId(): void
    {
        $revision = $this->report('expense')['revision'];

        $this->assertSame(2, $revision['second']['number']);
        // `supersedes_revision_id` is an int join key in the table and a public UUID on the
        // aggregate. Returning the key breaks every caller that resolves a revision by public id.
        $this->assertTrue(
            $revision['second']['supersedesIsPublicId'],
            'supersedes must read back as the first revision\'s public UUID'
        );
        $this->assertSame([1, 2], $revision['numbersInOrder'], 'revisions must come back in revision order');
    }

    // ===== DECISION AND AUDIT: THE LINKAGE THE TASK CALLS OUT =====

    public function testDecisionPointsAtTheTransactionAndRevisionItWasTakenOn(): void
    {
        $report = $this->report('expense');
        $decision = $report['decision'];

        $this->assertNotNull($decision['byCommandId'], 'a decision must be findable by the command that recorded it');
        $this->assertSame($decision['publicId'], $decision['byCommandId']['publicId']);
        $this->assertSame(
            $report['transaction']['publicId'],
            $decision['byCommandId']['transactionPublicId'],
            'the decision must name the transaction it was taken on'
        );
        $this->assertSame(
            $report['revision']['firstPublicId'],
            $decision['byCommandId']['revisionPublicId'],
            'and the revision it was taken on'
        );
        $this->assertSame('COST_CENTER', $decision['byCommandId']['stage']);
        $this->assertSame('APPROVE', $decision['byCommandId']['decision']);
        $this->assertSame('approved in the round trip', $decision['byCommandId']['explanation']);
        $this->assertSame($decision['publicId'], $decision['byPublicId']['publicId']);
        $this->assertSame($decision['publicId'], $decision['bySurrogateKey']['publicId']);
        $this->assertSame(1, $decision['countForTransaction']);
        $this->assertTrue($decision['foundForRevisionAndStage'], 'the revision-and-stage read must find it');
    }

    /**
     * The actor snapshot must survive, because Constitution IV requires historical actor identity and
     * role-at-event to outlive the account.
     */
    public function testDecisionKeepsItsActorSnapshot(): void
    {
        $byCommandId = $this->report('expense')['decision']['byCommandId'];

        $this->assertSame(self::actorUid(), $byCommandId['actorUid']);
        $this->assertSame('Round Trip Actor', $byCommandId['actorName']);
        $this->assertSame('COST_CENTER_MANAGER', $byCommandId['actorRole']);
    }

    /**
     * FR-056: the configuration audit stream is a real table, not a second name for the
     * transaction one.
     *
     * This is the assertion a unit test over two in-memory doubles cannot make. The doubles
     * prove the writer sends each path to a different repository; only the real schema can prove
     * the configuration binding writes to `pettycash_configuration_audit` with `entity_type` and
     * `entity_id`, and that the transaction binding cannot see the row.
     */
    public function testAConfigurationAuditEventLandsInItsOwnTableAndIsInvisibleToTheTransactionStream(): void
    {
        $report = $this->report('expense');
        $config = $report['configurationAudit'];

        $this->assertSame(
            1,
            $config['rowCountInConfigurationTable'],
            'the configuration event must be a real row in pettycash_configuration_audit; if the '
            . 'stream binding silently fell back to the transaction table there would be none here'
        );
        $this->assertNotNull(
            $config['byPublicId'],
            'a configuration event must be readable back through the configuration binding'
        );
        $this->assertSame($config['publicId'], $config['byPublicId']['publicId']);
        $this->assertSame(
            $config['publicId'],
            $config['bySurrogateKey']['publicId'],
            'save() must set the surrogate key, or the event cannot be found by id'
        );

        // FR-055: a configuration event is addressed by entity_type/entity_id.
        $this->assertSame('account', $config['byPublicId']['entityType']);
        $this->assertSame('round-trip-account-1', $config['byPublicId']['entityId']);

        // The transaction stream's subject columns do not exist on the configuration table, so
        // they must come back empty rather than borrowing values from somewhere else.
        $this->assertNull($config['byPublicId']['sheetDbId']);
        $this->assertNull($config['byPublicId']['transactionDbId']);
        $this->assertNull($config['byPublicId']['revisionDbId']);
        $this->assertNull($config['byPublicId']['decisionDbId']);

        // FR-052: the shared actor columns survive the stream binding.
        $this->assertSame(self::actorUid(), $config['byPublicId']['actorUid']);
        $this->assertSame('Round Trip Actor', $config['byPublicId']['actorName']);
        $this->assertSame('FINANCE', $config['byPublicId']['actorRole']);
        $this->assertSame('ACCOUNT_CHARGED', $config['byPublicId']['eventType']);
        $this->assertSame('{"movement_id":"mov-1"}', $config['byPublicId']['afterJson']);

        $this->assertTrue($config['foundForActor'], 'the configuration stream must answer findByActorUid');
        $this->assertTrue($config['foundInDateRange'], 'the configuration stream must answer findByDateRange');

        $this->assertFalse(
            $config['visibleToTransactionStream'],
            'the transaction binding must not be able to read a configuration event; if both '
            . 'streams resolved to one table the two audit trails are indistinguishable'
        );
    }

    public function testAuditEventPointsAtItsSheetTransactionRevisionAndDecision(): void
    {
        $report = $this->report('expense');
        $audit = $report['audit'];

        $this->assertNotNull($audit['byPublicId'], 'an audit event must be readable back by its public id');
        $this->assertSame($audit['publicId'], $audit['byPublicId']['publicId']);
        $this->assertSame($audit['publicId'], $audit['bySurrogateKey']['publicId']);
        $this->assertNotNull(
            $audit['byPublicId']['sheetDbId'],
            'the audit event must name the sheet; save() used to leave dbId and publicId null'
        );
        $this->assertSame($report['sheet']['byPublicId']['surrogateKey'], $audit['byPublicId']['sheetDbId']);
        $this->assertSame(
            $report['transaction']['byPublicId']['surrogateKey'],
            $audit['byPublicId']['transactionDbId'],
            'the transaction link is the whole point of an audit row'
        );
        $this->assertNotNull($audit['byPublicId']['revisionDbId']);
        $this->assertNotNull($audit['byPublicId']['decisionDbId'], 'and so is the decision link');
    }

    public function testAuditEventKeepsItsPayloadAndIsFoundByEveryScopeRead(): void
    {
        $audit = $this->report('expense')['audit'];

        $this->assertSame('APPROVED', $audit['byPublicId']['eventType']);
        $this->assertSame('COST_CENTER', $audit['byPublicId']['stage']);
        $this->assertSame('{"stage":"DRAFT"}', $audit['byPublicId']['beforeJson']);
        $this->assertSame('{"stage":"COST_CENTER"}', $audit['byPublicId']['afterJson']);
        $this->assertSame('approved in the round trip', $audit['byPublicId']['explanation']);
        $this->assertNotNull($audit['byPublicId']['commandId'], 'the command id is the correlation reference');
        $this->assertSame(1, $audit['countForTransaction']);
        $this->assertSame(1, $audit['countForSheet']);
        $this->assertTrue($audit['foundForActor']);
        $this->assertTrue($audit['foundInDateRange'], 'an event dated now must fall inside a window around now');
        // The negative case, because a date filter that ignored its bounds would pass the positive one.
        $this->assertFalse(
            $audit['foundOutsideDateRange'],
            'and must not fall inside a window two days out'
        );
    }

    // ===== EVIDENCE =====

    public function testEvidenceIsFoundByPublicIdAndCarriesItsMetadata(): void
    {
        $evidence = $this->report('expense')['evidence'];

        $this->assertNotNull($evidence['byPublicId']);
        $this->assertSame($evidence['publicId'], $evidence['byPublicId']['publicId']);
        $this->assertSame($evidence['publicId'], $evidence['bySurrogateKey']['publicId']);
        $this->assertSame('receipt.pdf', $evidence['byPublicId']['originalName']);
        $this->assertSame('application/pdf', $evidence['byPublicId']['mediaType']);
        $this->assertSame(2048, $evidence['byPublicId']['sizeBytes']);
        $this->assertSame(str_repeat('a', 64), $evidence['byPublicId']['checksum']);
        $this->assertTrue($evidence['byPublicId']['active']);
        $this->assertSame(self::actorUid(), $evidence['byPublicId']['uploadedBy']);
    }

    public function testEvidenceIsListedForItsTransactionAndRevision(): void
    {
        $evidence = $this->report('expense')['evidence'];

        $this->assertSame(1, $evidence['countForTransaction']);
        $this->assertSame(1, $evidence['countForTransactionActiveOnly']);
        $this->assertSame(1, $evidence['countForRevision']);
    }

    public function testEvidenceLinkIsFoundByItsTokenHashAndListsForItsEvidence(): void
    {
        $link = $this->report('expense')['evidenceLink'];

        $this->assertNotNull($link['byTokenHash'], 'a public link must be findable by its token hash');
        $this->assertSame($link['publicId'], $link['byTokenHash']['publicId']);
        $this->assertSame($link['publicId'], $link['byPublicId']['publicId']);
        $this->assertNotNull($link['byTokenHash']['evidenceDbId'], 'the link must name the evidence it opens');
        // FR-031 requires public links to be disabled by default, so `false` here is the rule and
        // not an incidental default.
        $this->assertFalse($link['byTokenHash']['active'], 'a public link must be disabled until activated');
        $this->assertTrue($link['byTokenHash']['hasExpiry'], 'an expiry must round-trip');
        $this->assertSame(1, $link['countForEvidence']);
    }

    // ===== THE CONSUMPTION, AND THE COMMAND RESULT =====

    /**
     * The one movement that names a source revision, which is also the row the unique
     * `source_revision_id` index exists to keep single.
     */
    public function testAConsumptionIsFoundByTheRevisionItSettled(): void
    {
        $report = $this->report('expense');
        $consumption = $report['consumption'];

        $this->assertNotNull($consumption['bySourceRevisionId'], 'a consumption must be findable by its source revision');
        $this->assertSame($consumption['publicId'], $consumption['bySourceRevisionId']['publicId']);
        $this->assertSame('CONSUMPTION', $consumption['bySourceRevisionId']['type']);
        $this->assertSame(250000, $consumption['bySourceRevisionId']['amountMinor']);
        $this->assertSame(
            $report['revision']['firstPublicId'],
            $consumption['bySourceRevisionId']['sourceRevisionId'],
            'the source revision must read back as the public UUID that settled'
        );
        $this->assertSame(['CONSUMPTION'], $consumption['ledgerTypes']);
    }

    public function testAStoredCommandResultIsReturnedUnchangedForAReplay(): void
    {
        $command = $this->report('command');

        $this->assertNotNull($command['found'], 'a stored command result must be found by its command id');
        $this->assertSame($command['commandId'], $command['found']['commandId']);
        $this->assertTrue($command['found']['hashMatches'], 'the payload hash must round-trip');
        // The stored response is the frozen answer a replay is rebuilt from. A round trip that
        // reshaped it would make a retry report something the first attempt never said.
        $this->assertTrue(
            $command['found']['responseIsIdentical'],
            'the stored response must be returned exactly as it was written'
        );
        $this->assertSame('CHARGE', $command['found']['response']['movement']['type']);
        $this->assertTrue($command['unknownCommandIdIsNull'], 'an unissued command id must find nothing');
        // T251: the replay dimensions round-trip through the new columns.
        $this->assertSame('round-trip', $command['found']['actorUid']);
        $this->assertSame('roundtrip.probe', $command['found']['operation']);
        $this->assertNull($command['found']['targetPublicId']);
        $this->assertSame(200, $command['found']['resultStatus']);
    }

    // ===== T332: `GET /sheets` reports a real `total` =====

    /**
     * T332: `SheetService::listSheets()`'s `total` is the owner's record count, not the page's length.
     *
     * ## The defect
     *
     * ```php
     * // For total count, we need to add a method to the repository
     * // For now, we'll estimate from the returned items
     * $total = count($sheets);
     * ```
     *
     * `$sheets` was the already-paged read, so `total` was the page size and nothing else: 26 sheets,
     * a page of 25, and a response that said the caller held 25 while printing 25 of its own rows.
     *
     * ## Why this assertion needs more than one page of sheets
     *
     * Below 25 sheets the defect is invisible — `count($sheets)` and the true count coincide, which is
     * why the whole suite was green and why no fixture-based contract test could have found it. The
     * scenario therefore writes 26 and reads it back, and the count it is compared with is a `COUNT(*)`
     * issued as plain SQL against the table, so "the service agrees with the repository" cannot be
     * satisfied by two halves of the same defect agreeing with each other.
     */
    public function testTheSheetListReportsTheOwnersRecordCountAndNotThePageSize(): void
    {
        $list = $this->report('sheetlist')['sheetList'];

        $this->assertGreaterThan(
            $list['pageSize'],
            $list['written'],
            'this scenario is only meaningful with more sheets than one page can carry; a fixture that '
            . 'fits in one page cannot distinguish a page length from a record count'
        );
        $this->assertSame(
            $list['written'],
            $list['rawOwnerRowCount'],
            'the probe sheets must actually be in `pettycash_sheets`, or the figures below measure nothing'
        );
        $this->assertSame($list['pageSize'], $list['pageOne']['itemCount'], 'the first page must be full');
        $this->assertSame(
            $list['written'],
            $list['pageOne']['total'],
            '`total` is the owner-scoped record count, not the number of rows on this page'
        );
        $this->assertSame(
            $list['written'],
            $list['ownerScopedCount'],
            'the repository count the service reads must equal the table'
        );
    }

    /**
     * Every page of the same scope reports the same `total` — including one past the end.
     *
     * The page past the end is the interesting one: its rows are empty, so a `total` taken from them is
     * zero, and a client that walks forward until it sees an empty page concludes from that number that
     * it has reached the end of the owner's history. It has; that is the problem. The number beside an
     * empty page still describes the set the page was drawn from.
     */
    public function testTheSheetTotalIsTheOwnersCountOnEveryPageIncludingOnePastTheEnd(): void
    {
        $list = $this->report('sheetlist')['sheetList'];

        $this->assertSame($list['pageOne']['total'], $list['pageTwo']['total']);
        $this->assertSame(
            $list['pageOne']['total'],
            $list['pageThree']['total'],
            'a page past the end carries no rows, but its total still describes the owner\'s whole set'
        );
        $this->assertSame(
            $list['written'] - $list['pageSize'],
            $list['pageTwo']['itemCount'],
            'the second page must carry exactly the rows the first one could not'
        );
        $this->assertSame(0, $list['pageThree']['itemCount'], 'a page past the end carries no rows');
    }

    /**
     * Walking the pages reaches every sheet the owner holds, once each.
     *
     * The `total` above says how many rows exist; this says the pages can actually produce them, which
     * is a separate property — a paged read whose offset drifts repeats rows and drops others while
     * still reporting the right total. Only detectable by comparing the pages against one unbounded
     * read of the same scope.
     */
    public function testPagingTheSheetListReachesEveryOwnedSheetExactlyOnce(): void
    {
        $list = $this->report('sheetlist')['sheetList'];

        $paged = array_merge($list['pageOne']['ids'], $list['pageTwo']['ids']);
        $owned = $list['everyOwnedId'];

        sort($paged);
        sort($owned);

        $this->assertSame(
            array_values(array_unique($paged)),
            $paged,
            'a sheet must not appear on two pages, or the pager shows it twice'
        );
        $this->assertSame(
            $owned,
            $paged,
            'paging through the list must reach every sheet the owner holds, so no row is dropped '
            . 'between pages'
        );
    }

    /**
     * The count is scoped, not global and not blind to the predicate it shares with the read.
     *
     * Two things are checked, and neither is visible from the unfiltered figure alone. The status-scoped
     * counts must **partition** the owner's sheets: if the count ignored `status` it would answer 26 for
     * both, and the sum would be 52 — which is why the assertion is on the sum rather than on either
     * value. And an owner with no sheets must be answered zero rather than an exception, since that is
     * what every new purchaser arrives at.
     */
    public function testTheSheetCountIsScopedToItsOwnerAndHonoursItsPredicate(): void
    {
        $list = $this->report('sheetlist')['sheetList'];

        $this->assertSame(
            $list['written'],
            $list['openCount'] + $list['submittedCount'],
            'the status-scoped counts must partition the owner\'s sheets, so the count filters by the '
            . 'same predicate its read uses rather than answering one number for any argument'
        );
        $this->assertGreaterThan(0, $list['openCount']);
        $this->assertGreaterThan(0, $list['submittedCount']);
        $this->assertLessThan($list['written'], $list['openCount'], 'both statuses must be present, or the partition proves nothing');
        $this->assertSame(
            0,
            $list['otherOwnerCount'],
            'an owner who holds no sheets is answered zero, and is never told about another owner\'s'
        );
    }

    // ===== T379: the instance-wide counts, against the table =====

    /**
     * Each `countAll()` equals `COUNT(*)` on its own table.
     *
     * **T379's defect was not a wrong number, it was a name that did not describe the read.** The
     * administrator card published `count(findByStatus(Open, ...))` as `totalSheets`,
     * `count(findForFinanceQueue(...))` as `totalTransactions`, and a literal `0` as `totalAccounts`
     * — so two subsets and one constant were presented as three instance totals. `0` in particular
     * was wrong on every instance, always, and rendered as a headline metric.
     *
     * The expectation here is the table. Asserting `countAll()` against a repository method would be
     * satisfied by two halves of the same predicate agreeing with each other, which is precisely how
     * this defect survived; only the table can disagree with the repository.
     */
    public function testEachInstanceCountEqualsTheTableItCounts(): void
    {
        $counts = $this->dashboardCounts();

        $this->assertSame(
            $counts['rawSheets'],
            $counts['totalSheets'],
            '`totalSheets` is a COUNT over `pettycash_sheets`, not the length of a status-filtered read'
        );
        $this->assertSame(
            $counts['rawTransactions'],
            $counts['totalTransactions'],
            '`totalTransactions` is a COUNT over `pettycash_transactions`, not the depth of the '
            . 'finance queue'
        );
        $this->assertSame(
            $counts['rawAccounts'],
            $counts['totalAccounts'],
            '`totalAccounts` must be counted, not passed as the literal `0` the controller used to send; '
            . 'an instance with accounts must never answer zero here'
        );
    }

    /**
     * Each `countAll()` is strictly wider than the read it replaced.
     *
     * The assertion above alone would pass for a `countAll()` that kept the old predicate, because on
     * a populated instance a status-filtered count can coincide with the table by accident. This one
     * cannot: the scenario wrote a sheet in a second status, a transaction outside the finance stage
     * and a **deactivated** account, so a count that reused any of the three old reads is strictly
     * smaller than the table and fails here.
     */
    public function testEachInstanceCountIsWiderThanTheFilteredReadItReplaced(): void
    {
        $counts = $this->dashboardCounts();

        $this->assertSame(
            1,
            $counts['inactiveAccountWritten'],
            'the scenario must write a deactivated account, or the account assertion below measures '
            . 'nothing: no read `AccountRepository` offered could see that row'
        );
        $this->assertGreaterThan(
            $counts['openSheetsOnly'],
            $counts['totalSheets'],
            '`totalSheets` must count every status; `findByStatus(Open, ...)` is one status and the '
            . 'scenario wrote a completed sheet as well as an open one'
        );
        $this->assertGreaterThan(
            $counts['financeQueueOnly'],
            $counts['totalTransactions'],
            '`totalTransactions` must count every stage; `findForFinanceQueue()` is one stage and the '
            . 'scenario wrote a draft transaction as well'
        );
        $this->assertGreaterThan(
            $counts['activeAccountsForProbe'],
            $counts['totalAccounts'],
            '`totalAccounts` must count inactive accounts too, or the name is wrong in the same way the '
            . 'other two names were'
        );
    }

    /**
     * The `dashboardcounts` payload, with its keys proved present before any of them is read.
     *
     * **Why this exists.** Both tests above index the scenario report, and `report()` returns
     * `$report = match ($scenario) { ... }` — the scenario's **own** return value, not a map keyed by
     * scenario name. Indexing it a second time as `['dashboardcounts']['totalSheets']` reads `null`,
     * and `assertSame(null, null)` **passes**. So a wrong key was one of these tests' two halves
     * satisfying each other while measuring nothing: the "equals the table" test was green against a
     * payload it never read, and only the `assertSame(1, ...)` below it exposed the mistake.
     *
     * A guard here rather than a comment, because the failure this protects against is invisible in
     * the output — a vacuous pass looks exactly like a real one. Every key is checked, so a renamed
     * scenario key fails at the point of the rename instead of quietly becoming `null`.
     *
     * @return array<string, int>
     */
    private function dashboardCounts(): array
    {
        $counts = $this->report('dashboardcounts');
        $required = [
            'totalSheets',
            'totalTransactions',
            'totalAccounts',
            'rawSheets',
            'rawTransactions',
            'rawAccounts',
            'openSheetsOnly',
            'financeQueueOnly',
            'activeAccountsForProbe',
            'inactiveAccountWritten',
        ];

        $missing = array_values(array_filter(
            $required,
            static fn (string $key): bool => !is_int($counts[$key] ?? null)
        ));

        $this->assertSame(
            [],
            $missing,
            'the `dashboardcounts` scenario must report every integer this file compares, or the '
            . 'comparisons above compare `null` with `null` and pass while measuring nothing; '
            . 'reported keys were: ' . implode(', ', array_keys($counts))
        );

        return $counts;
    }

    /**
     * T388: FR-020's observable outcome, over real MariaDB.
     *
     * **What T358 delivered and what was still unobserved.** T358 delivered the trigger -- the frontend
     * sends no `accountId`, the controller stopped refusing one, and `SheetService` resolves it through
     * `resolveAccountForNewSheet()` -- and proved that wiring. The criterion's own words are about the
     * *outcome*: a purchaser with no accounts creates a first sheet, `GET /accounts` then reports exactly
     * one active `IRR` account, and repeating the attempt provisions nothing further. Nothing observed that.
     *
     * `ensureDefaultRialAccount()` has extensive unit coverage of idempotency and the duplicate-key race,
     * every one of it against in-memory doubles. So a race that lost on a different index, or a resolution
     * that returned an account no list could see, satisfied every test that existed.
     *
     * **Why this is not a live-HTTP test, stated rather than assumed.** `OcsTestClient::create()`
     * authenticates with one credential, `PETTYCASH_TEST_USER`, which holds accounts and so is never in
     * FR-020's precondition. Proving this over HTTP needs a **fresh Nextcloud account** — an authentication
     * principal — on an instance shared with other applications, which is an environment decision and not a
     * test one. The criterion is therefore verified here at the service boundary against real persistence,
     * and the HTTP variant is recorded as **not established** rather than implied by this passing.
     */
    public function testAFirstSheetCreationProvisionsExactlyOneActiveRialAccount(): void
    {
        // `report()` returns the scenario's **own** payload -- `$report = match ($scenario) { … }` -- so it is
        // NOT nested under the scenario name. Indexing it a second time yields an empty array and every key
        // reads `null`, which is the trap T383 recorded for `dashboardcounts` and the reason this scenario's
        // report goes through a guard rather than a bare index.
        $report = $this->provisioningReport();

        $this->assertSame(
            0,
            $report['preconditionAccounts'],
            'FR-020\'s precondition is zero active accounts; a scenario that did not start there is not '
            . 'testing provisioning, it is testing an ordinary sheet creation'
        );

        $this->assertSame(
            1,
            $report['accountsAfterFirst'],
            'the first creation provisions exactly one account, not none and not one per sheet'
        );
        $this->assertSame('IRR', $report['provisionedCurrencyCode'], 'and it is the default rial');
        $this->assertTrue($report['provisionedIsActive'], 'FR-014 requires it to be active');
    }

    /**
     * Repeating the attempt provisions **nothing further** — twice, so idempotency is not an artefact of
     * the sheet count happening to be zero on the second call.
     *
     * `assertSame(1, …)` rather than `assertLessThanOrEqual`: a second account would make every sheet's
     * currency ambiguous, which is the whole reason FR-020 provisions exactly one.
     */
    public function testRepeatingTheCreationProvisionsNothingFurther(): void
    {
        // `report()` returns the scenario's **own** payload -- `$report = match ($scenario) { … }` -- so it is
        // NOT nested under the scenario name. Indexing it a second time yields an empty array and every key
        // reads `null`, which is the trap T383 recorded for `dashboardcounts` and the reason this scenario's
        // report goes through a guard rather than a bare index.
        $report = $this->provisioningReport();

        $this->assertSame(1, $report['accountsAfterSecond'], 'a second creation must not provision a second account');
        $this->assertSame(
            1,
            $report['accountsAfterThird'],
            'and neither must a third -- proving idempotency is not an artefact of a zero sheet count'
        );
        $this->assertTrue(
            $report['secondProvisionCallReturnedNull'],
            'once an active account exists, the precondition no longer holds and provisioning must report '
            . 'that it did nothing rather than create again'
        );
    }

    /**
     * The provisioned account is the one the sheet actually draws on, and the sheet inherits its currency.
     *
     * Tied to the same response rather than asserted as a literal, so a resolution that returned an account
     * the sheet did not use — which would leave the sheet with a currency no account backs — fails here.
     */
    public function testTheSheetDrawsOnTheProvisionedAccountAndInheritsItsCurrency(): void
    {
        // `report()` returns the scenario's **own** payload -- `$report = match ($scenario) { … }` -- so it is
        // NOT nested under the scenario name. Indexing it a second time yields an empty array and every key
        // reads `null`, which is the trap T383 recorded for `dashboardcounts` and the reason this scenario's
        // report goes through a guard rather than a bare index.
        $report = $this->provisioningReport();

        $this->assertSame(
            $report['provisionedAccountId'],
            $report['firstSheetAccountId'],
            'the sheet must draw on the account provisioning created, not on one that existed already'
        );
        $this->assertSame(
            $report['provisionedCurrencyCode'],
            $report['firstSheetCurrency'],
            'FR-021: the sheet currency is the selected account\'s currency'
        );
    }

    /**
     * The `provisioning` scenario's report, with its keys proved present before any is read.
     *
     * The same guard `dashboardCounts()` carries, for the same reason: `report()` returns the scenario's
     * own payload, so an extra `[scenario]` index reads `null` for every key and `assertSame(null, …)` passes.
     * T383 shipped that bug in `dashboardcounts` and found it only because one assertion compared against a
     * literal; this one has three, and a silent null would have read as "provisioning did nothing".
     *
     * @return array<string, mixed>
     */
    private function provisioningReport(): array
    {
        $report = $this->report('provisioning');
        $required = [
            'preconditionAccounts',
            'accountsAfterFirst',
            'accountsAfterSecond',
            'accountsAfterThird',
            'provisionedCurrencyCode',
            'provisionedIsActive',
            'firstSheetCurrency',
            'firstSheetAccountId',
            'provisionedAccountId',
        ];

        $missing = array_values(array_filter(
            $required,
            static fn (string $key): bool => !array_key_exists($key, $report)
        ));

        $this->assertSame(
            [],
            $missing,
            'the `provisioning` scenario must report every key this file reads, or the assertions below '
            . 'compare `null` with a literal and pass while measuring nothing; reported keys were: '
            . implode(', ', array_keys($report))
        );

        return $report;
    }

    // ===== HARNESS =====

    /**
     * Every table the runner writes has a fingerprint that can remove it.
     *
     * ## Why this is the important test in the file
     *
     * The runner's by-id sweep is precise but incomplete: a row is only registered once its id has
     * resolved, so a run that dies *between* an insert and its `$track()` leaks the row into the shared
     * MariaDB with nothing left that can find it. The fingerprint sweep exists for exactly that window,
     * and it only helps for tables it has a predicate for.
     *
     * It did not have predicates for six. Writing the list by hand and reading it back is what found
     * them, and it found live rows while it was being written: **35 cost centres** named
     * "Round Trip Expense Cost Center" (the predicate was `eq 'Round Trip Cost Center'`, so the expense
     * scenario's differently-named centre never matched), and **one configuration audit row** whose
     * `command_id` carried the harness prefix (the predicate was `explanation like 'Retention probe %'`,
     * and this row's explanation was "charged in the round trip"). Both had been sitting in the shared
     * database, invisible to `roundtrip-leftovers.php`, which is the tool whose job was to report them.
     *
     * So this test asserts a property of the whole set rather than one predicate: **every table the
     * runner registers with `$track()` is swept by the shared definition.** A new table the runner
     * starts writing is covered the moment it is tracked, and a step that is removed fails here rather
     * than in a report three files away.
     */
    public function testEveryTableTheRunnerWritesHasAFingerprintThatCanRemoveIt(): void
    {
        $runner = file_get_contents(__DIR__ . '/roundtrip-runner.php');
        $this->assertIsString($runner, 'the runner must be readable');

        preg_match_all("/\\\$track\('(pettycash_[a-z_]+)'/", $runner, $matches);
        $tracked = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($tracked, 'the runner must register rows with $track(), or this test is vacuous');

        $swept = LeakedRows::sweptTables();
        $uncovered = array_values(array_diff($tracked, $swept));

        $this->assertSame(
            [],
            $uncovered,
            'these tables are written by the runner and no fingerprint step can remove them, so a run '
            . 'that dies between an insert and its $track() leaks them into the shared database '
            . 'permanently. Add a step to tests/Support/LeakedRows.php.'
        );
    }

    /**
     * The sheets are swept before the currencies they reference.
     *
     * Order, not membership. Deleting the currency first re-creates the dangling `sheets.currency_id`
     * the sheet sweep exists to remove, and a dangling currency is what made
     * `GET /reports/transactions`, `/reports/process` and `/reports/evidence` return no data at all --
     * so a sweep listed in the wrong order is a sweep that manufactures the fault it was written for.
     *
     * Asserted on the shared definition rather than on the runner's source, because the two are now one
     * list: a test that read the runner's text would pass against a runner whose sweep had been moved
     * into a module the test no longer inspects, and would fail against a correctly-ordered module it
     * never mentions.
     */
    public function testSheetsAreSweptBeforeTheCurrenciesTheyReference(): void
    {
        $stepAt = [];
        foreach (LeakedRows::steps() as $index => $step) {
            $stepAt[$step['name']] = $index;
        }

        $this->assertArrayHasKey(
            'round trip sheets',
            $stepAt,
            'the round-trip sheet step must exist, or a leaked sheet is unreachable'
        );
        $this->assertArrayHasKey(
            'currencies',
            $stepAt,
            'the currency step must exist, or the ordering below is about nothing'
        );
        $this->assertLessThan(
            $stepAt['currencies'],
            $stepAt['round trip sheets'],
            'sheets must be swept before the currencies they reference, or the sweep re-creates the '
            . 'dangling reference it exists to remove'
        );
        $this->assertLessThan(
            $stepAt['currencies'],
            $stepAt['corrupt probe sheets'],
            'the corrupt probe sheet references a currency too, so it is swept before them as well'
        );
    }

    /**
     * Deleting a parent leaves a subtree, and a one-column predicate cannot describe that.
     *
     * Deleting a sheet without its transactions leaves `pettycash_transactions.sheet_id` pointing at
     * nothing. Those rows are unreachable by every sheet-scoped read and by every simple predicate, yet
     * they break the currency join in `resolveCurrencyCode()` (revision -> transaction -> sheet ->
     * currency), so a single orphan refuses the whole `transactions` report. Measured before the fix:
     * 28 orphaned transactions carrying 56 revisions, and `GET /reports/transactions` answering 422
     * while the sheets report answered 200.
     *
     * Two shapes have to be present, and the second is the one that is easy to lose: the **cascade**,
     * which deletes the dependents before the parent, and the **orphan net**, keyed off the orphan
     * itself rather than off the sheet -- because by the time a sweep runs the offending sheet has
     * already been deleted, so a sheet-keyed predicate matches none of them. The sheet-keyed version of
     * that step was written first, and it found 0 of 28.
     */
    public function testTheSweepTakesASheetsSubtreeAndNetsOffTheOrphanItself(): void
    {
        $kinds = array_column(LeakedRows::steps(), 'kind', 'name');

        $this->assertArrayHasKey(
            'orphaned transactions',
            $kinds,
            'the orphan net must exist: a sheet leaked by an *earlier* run has already been swept, so its '
            . 'id is unknowable and the damage can only be found from the transactions themselves'
        );
        $this->assertSame('orphans', $kinds['orphaned transactions']);

        $source = file_get_contents(dirname(__DIR__, 2) . '/Support/LeakedRows.php');
        $this->assertIsString($source);

        $this->assertStringContainsString(
            "leftJoin('t', 'pettycash_sheets', 's', 't.sheet_id = s.id')",
            $source,
            'the orphan is found by joining transactions to sheets, because a leaked sheet is itself '
            . 'already deleted by the time the sweep runs'
        );
        $this->assertStringContainsString(
            "isNull('s.id')",
            $source,
            'the orphan is the row whose sheet join is empty; anything else finds the wrong set'
        );
        $this->assertStringContainsString(
            "isNotNull('t.sheet_id')",
            $source,
            'a transaction with no sheet at all is a different fault and must be kept out of this sweep, '
            . 'or the sweep quietly deletes a row broken for another reason'
        );

        // Deepest first, or a table is left holding a key to a row that was just removed. The order is
        // asserted where it is written rather than where it is used, because the cascade list is the one
        // thing in the module that is order-dependent, and therefore the one thing a later edit is most
        // likely to reorder without noticing.
        $evidenceAt = strpos($source, "'pettycash_evidence',");
        $revisionsAt = strpos($source, "'pettycash_transaction_revisions',");
        $this->assertIsInt($evidenceAt);
        $this->assertIsInt($revisionsAt);
        $this->assertLessThan(
            $revisionsAt,
            $evidenceAt,
            'evidence is swept before the revisions it belongs to'
        );

        $source = file_get_contents(__DIR__ . '/roundtrip-runner.php');
        $this->assertIsString($source);
        $this->assertStringContainsString(
            'LeakedRows::sweep($db);',
            $source,
            'the sweep must actually be invoked, not merely defined. This exact gap was found by '
            . 'mutating the call away and watching the suite stay green: a closure nobody calls '
            . 'satisfies every assertion about its body while the database keeps filling with orphans.'
        );
    }

    /**
     * The runner checks that its own sweep worked, and the suite fails when it did not.
     *
     * A sweep that runs and is never checked is a sweep that can quietly stop working, and the failure
     * it prevents is not visible where it happens. It surfaces in some later run's report, which answers
     * 422 because a record cannot be read, and the search starts in the report. That is the whole of
     * T308's investigation: three endpoints returning no data, and the cause in a test harness a dozen
     * files away.
     *
     * Both halves are asserted. The runner computing the post-condition is necessary and not sufficient;
     * a `leftovers` key that nothing turns into a failure would be reported to nobody.
     */
    public function testTheRunnerFailsItsOwnScenarioWhenTheSweepLeftRowsBehind(): void
    {
        $runner = file_get_contents(__DIR__ . '/roundtrip-runner.php');
        $this->assertIsString($runner, 'the runner must be readable');

        $this->assertStringContainsString(
            'LeakedRows::leftovers($db)',
            $runner,
            'the runner must re-count its own fingerprints after sweeping. A sweep that is not checked '
            . 'cannot be distinguished from a sweep that did nothing.'
        );
        $this->assertStringContainsString(
            "\$report['leftovers'] = \$leftovers;",
            $runner,
            'the surviving rows must be reported in the runner output, or the number is only ever seen '
            . 'by whoever happens to read stderr'
        );
        $this->assertStringContainsString(
            'LeakedRows::assertNothingSurvived(',
            $runner,
            'the runner must fail its own scenario on a surviving row, not merely note it. The suite '
            . 'enforces the same guard on the reported value, and that enforcement is itself tested in '
            . 'testTheLeakPostConditionFailsTheRunThatLeakedRatherThanOnlyNamingIt.'
        );
    }

    /**
     * The post-condition fails the run that leaked it, rather than only naming it.
     *
     * Called directly with a synthetic leak, because the alternative is asserting that this suite's
     * source *contains* the guard -- and that assertion is satisfied by a guard nothing calls, or by one
     * behind an `if (false)`. Both were tried and both passed with the enforcement removed, which is
     * the reason this test exists and the reason the guard is a function rather than an inline `throw`.
     *
     * The clean case is asserted too, and for the same reason: a guard only ever observed failing has
     * not been shown to pass, and a post-condition that rejects a healthy run would take the whole
     * suite with it.
     */
    public function testTheLeakPostConditionFailsTheRunThatLeakedRatherThanOnlyNamingIt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('corrupt probe sheets: 3');
        LeakedRows::assertNothingSurvived(
            ['corrupt probe sheets' => 3],
            "The 'expense' round trip"
        );
    }

    /** The healthy case: a run that leaked nothing is not failed. */
    public function testTheLeakPostConditionPassesWhenNothingSurvivedTheSweep(): void
    {
        $leaked = false;
        try {
            LeakedRows::assertNothingSurvived([], "The 'expense' round trip");
        } catch (RuntimeException $e) {
            $leaked = true;
        }

        $this->assertFalse(
            $leaked,
            'a run whose sweep removed everything must pass. A post-condition that fires on a clean '
            . 'database would fail every scenario and be switched off, taking the real check with it.'
        );

        // And the same guard must accept what the runner actually produces on a clean database, which
        // is the one input that matters and the one no synthetic array can stand in for.
        foreach (['configuration', 'expense'] as $scenario) {
            $report = $this->report($scenario);
            $this->assertArrayNotHasKey(
                'leftovers',
                $report,
                "the '{$scenario}' round trip must complete with nothing left behind; if it did not, the "
                . 'runner would already have failed and this assertion would not be reached'
            );
        }
    }

    /**
     * The diagnostic reads the same definition the sweep does, so the two cannot disagree.
     *
     * They were two separately written lists of the same set of rows, and they disagreed: the
     * diagnostic had no `pettycash_sheets` predicate at all and printed `No leftovers` for as long as
     * seven sheets with a dangling `currency_id` sat in the database refusing three whole reports. The
     * operator running the one tool whose job was to say otherwise was told everything was fine.
     */
    public function testTheDiagnosticAndTheSweepShareOneDefinition(): void
    {
        $helper = file_get_contents(__DIR__ . '/roundtrip-leftovers.php');
        $this->assertIsString($helper);

        $this->assertStringContainsString(
            "require_once __DIR__ . '/../../Support/LeakedRows.php';",
            $helper,
            'the diagnostic must load the shared definition rather than carrying its own predicates'
        );
        $this->assertStringContainsString(
            'LeakedRows::steps()',
            $helper,
            'the diagnostic must enumerate the shared steps, or it is describing a different set of '
            . 'rows than the sweep removes'
        );
        $this->assertStringContainsString(
            'LeakedRows::probeOne(',
            $helper,
            'the diagnostic must re-derive each count from the database. Reusing the sweep\'s own '
            . 'arithmetic would mean it could not detect the sweep having done nothing.'
        );

        // It also has to say something when it cannot finish. `lib/base.php` switches display_errors off,
        // so an uncaught error here used to exit 0 having printed nothing -- which for a diagnostic is
        // the exact failure it was written to prevent: a clean database, reported by a crashed tool.
        $this->assertStringContainsString(
            'could not complete',
            $helper,
            'the diagnostic must report a crash as an unknown state rather than exiting quietly'
        );
        $this->assertStringContainsString(
            'exit(2)',
            $helper,
            'a crashed diagnostic must exit non-zero, so a script cannot mistake silence for cleanliness'
        );
    }

    /**
     * The fingerprints match real rows, not only the source text that declares them.
     *
     * Every other assertion about the sweep reads `LeakedRows` or the runner's file. A predicate that
     * matches nothing passes all of them, and a sweep built from predicates that match nothing removes
     * nothing while looking completely correct -- which is the shape of the bug T308 had to unpick: a
     * currency sweep that *did* match, ordered wrongly, plus a diagnostic whose sheet predicate did not
     * match at all.
     *
     * So this plants the row and looks at the database. One sheet, no transactions, so the worst outcome
     * of a crashed test is a single extra row the next run removes -- not a subtree.
     *
     * It also settles what the leak post-condition can and cannot be tested against, which is worth
     * recording because it was arrived at by trying. A post-condition fires when a sweep leaves a row
     * behind, and every row a predicate can *see* is a row the sweep *deletes*: the schema declares no
     * foreign keys, so a delete does not fail on a dependent, and there is no way to plant a visible row
     * that survives. The end-to-end path is therefore exercised where a mismatch can be manufactured --
     * `corrupt-record-fixture.php remove`, whose post-condition is mutation-proven to fail five tests --
     * and what is asserted here is the property that makes it meaningful: the two halves of the sweep
     * agree, on real rows, about what they are looking at.
     */
    public function testTheFingerprintsMatchRealRowsRatherThanOnlyTheSourceThatDeclaresThem(): void
    {
        $this->sweepLeakedRows();
        $this->insertLeakedSheet();

        $planted = $this->countLeakedSheets();
        $this->assertSame(
            1,
            $planted,
            'the planted row must be visible to the shared definition. A predicate that matches nothing '
            . 'passes every source-text assertion in this file while the sweep it belongs to removes '
            . 'nothing -- which is how a leak survives a sweep that "works".'
        );

        $this->sweepLeakedRows();

        $this->assertSame(
            0,
            $this->countLeakedSheets(),
            'the sweep must remove a row its own predicate found. `leftovers()` reporting one and '
            . '`sweep()` removing none is the disagreement that would make the post-condition '
            . 'unfalsifiable, and it is invisible to any assertion that reads the source.'
        );
    }

    /**
     * The fingerprints name what the runner actually writes.
     *
     * {@see testEveryTableTheRunnerWritesHasAFingerprintThatCanRemoveIt()} checks that every *table* is
     * swept. This checks the other half: that the *values* the predicates match are the values the runner
     * writes. A table can be covered by a predicate keyed on a name nothing produces, which satisfies
     * every table-level assertion and removes nothing — and the first version of the planted-row test
     * could not see it, because planting and counting both read the same constant, so a typo in the
     * constant left plant, count and sweep in perfect agreement on a row nothing would ever write.
     *
     * The sheet title is therefore a single constant the runner uses, and the remaining names are checked
     * against the prefix the predicates key off.
     */
    public function testTheFingerprintValuesAreTheValuesTheRunnerWrites(): void
    {
        $runner = file_get_contents(__DIR__ . '/roundtrip-runner.php');
        $this->assertIsString($runner);

        $this->assertStringContainsString(
            'title: LeakedRows::ROUND_TRIP_SHEET_TITLE',
            $runner,
            'the runner must write the shared sheet title rather than a second literal. Two spellings of '
            . 'one fingerprint is the divergence this module exists to end, and it is invisible to a test '
            . 'that reads either spelling from the same place.'
        );

        // Every remaining name the runner writes into a swept column has to start with the prefix the
        // predicates match on. A name that does not is a row nothing can remove.
        preg_match_all("/'(Round Trip[^']*)'/", $runner, $matches);
        $this->assertNotEmpty($matches[1], 'the runner must write at least one fingerprinted name');

        foreach (array_unique($matches[1]) as $name) {
            $this->assertStringStartsWith(
                'Round Trip',
                $name,
                "'{$name}' is written into a swept column but is not a harness fingerprint, so no step "
                . 'can remove it'
            );
        }

        // And the prefix the module matches on has to be a prefix of what the runner writes. Without this
        // leg the previous assertion checks the runner against a phrase in this file while the predicates
        // match on a *different* phrase in the module, and the two are free to drift.
        $module = file_get_contents(dirname(__DIR__, 2) . '/Support/LeakedRows.php');
        $this->assertIsString($module);
        $this->assertMatchesRegularExpression(
            "/private const NAME_PREFIX = '([^']*)';/",
            $module,
            'the module must declare the prefix its name predicates match on'
        );
        preg_match("/private const NAME_PREFIX = '([^']*)';/", $module, $prefixMatch);
        $prefix = rtrim($prefixMatch[1], '%');

        $this->assertNotSame('', $prefix, 'the name prefix must not be empty, or every step matches everything');
        foreach (array_unique($matches[1]) as $name) {
            $this->assertStringStartsWith(
                $prefix,
                $name,
                "the module's name predicate matches '{$prefix}%' but the runner writes '{$name}', so a "
                . 'leaked row carrying that name is invisible to the sweep that is supposed to remove it'
            );
        }
    }

    /**
     * Run a scenario without the memoisation, so the guard at the end of the reader is reached.
     *
     * @return array<string, mixed>
     */
    private function runScenarioUnmemoised(string $scenario): array
    {
        $runner = __DIR__ . '/roundtrip-runner.php';
        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
            . escapeshellarg($scenario) . ' 2>/dev/null'
        );
        $this->assertIsString($output, 'the runner must produce output');

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'the runner must return JSON: ' . $output);

        // The same guard `report()` applies, reached here deliberately.
        LeakedRows::assertNothingSurvived(
            isset($decoded['leftovers']) && is_array($decoded['leftovers']) ? $decoded['leftovers'] : [],
            "The '{$scenario}' round trip"
        );

        return $decoded;
    }

    /**
     * Write one sheet carrying a harness fingerprint, which is what a run that died between an insert and
     * its registration leaves behind. In a subprocess, because the PHPUnit process has no database
     * handle: `tests/bootstrap.php` deliberately does not bootstrap Nextcloud.
     */
    private function insertLeakedSheet(): void
    {
        $user = trim((string) getenv('PETTYCASH_TEST_USER'));
        $this->assertNotSame('', $user, 'the round-trip scenarios require PETTYCASH_TEST_USER');

        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/leak-a-sheet.php') . ' '
            . escapeshellarg($user) . ' 2>&1'
        );
        $this->assertIsString($output);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'the leak helper must report JSON: ' . $output);
        $this->assertArrayNotHasKey('error', $decoded, $output);
    }

    private function countLeakedSheets(): int
    {
        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/leak-a-sheet.php') . ' --count 2>&1'
        );
        $this->assertIsString($output);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'the leak helper must report JSON: ' . $output);

        return (int) ($decoded['sheets'] ?? -1);
    }

    private function sweepLeakedRows(): void
    {
        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/roundtrip-leftovers.php')
            . ' --delete 2>&1'
        );
        $this->assertIsString($output);
        $this->assertStringNotContainsString(
            'STILL PRESENT',
            $output,
            'the diagnostic could not remove everything it found: ' . $output
        );
    }

    /** @return array<string, mixed> */
    /**
     * The uid the round trips ran as.
     *
     * `PETTYCASH_TEST_USER` is what `roundtrip-runner.php` puts in the session, so it is the identity
     * every `created_by` / `actor_uid` column in the report must carry. Reading it from the
     * environment is what lets the same file run against the development lab (`employee1`) and the
     * ephemeral CI instances (`.github/workflows/scripts/portability-pass.sh` creates its own
     * `pettycash-portability`).
     *
     * Hardcoding `employee1` here instead would make the assertions correct for exactly one
     * environment and would fail on the other three, which is a property of the lab rather than of the
     * repositories under test. T239 measured that: on SQLite the run reached 443 assertions with 8
     * failures, every one of them this literal disagreeing with the acting user.
     */
    private static function actorUid(): string
    {
        $uid = getenv('PETTYCASH_TEST_USER') ?: '';

        self::assertNotSame(
            '',
            $uid,
            'PETTYCASH_TEST_USER is required: it is the identity the round trips run as, and the '
                . 'assertions about actor columns cannot be made without it. Run through '
                . '`scripts/dev/phpunit` or the CI portability pass, both of which set it.'
        );

        return $uid;
    }

    private function report(string $scenario): array
    {
        if (isset(self::$reports[$scenario])) {
            return self::$reports[$scenario];
        }

        $runner = __DIR__ . '/roundtrip-runner.php';
        if (!is_file($runner)) {
            throw new RuntimeException("The round-trip runner is missing at {$runner}.");
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
            . escapeshellarg($scenario) . ' 2>/dev/null';
        $output = shell_exec($command);

        if ($output === null || trim($output) === '') {
            throw new RuntimeException(
                "The round-trip runner produced no output for the '{$scenario}' scenario. It exits with a "
                . 'message on stderr when it cannot reach the database, so an empty result here means the '
                . 'subprocess did not run at all.'
            );
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("The round-trip runner did not return JSON:\n" . $output);
        }
        if (isset($decoded['error'])) {
            throw new RuntimeException(
                "The '{$scenario}' round trip failed against the real database: " . $decoded['error']
            );
        }

        // The runner's own post-condition, enforced here.
        //
        // The runner re-counts its fingerprints after sweeping and reports what survived; this turns a
        // non-empty answer into a failure of the scenario that produced it. Without this the number
        // would be printed to stderr by a subprocess whose stderr is discarded (`2>/dev/null` above),
        // so a leak would be reported to nobody and would surface later as some unrelated report
        // answering 422.
        //
        // The guard is a *function* rather than an inline `throw`, and
        // {@see testTheLeakPostConditionFailsTheRunThatLeakedRatherThanOnlyNamingIt} calls it directly
        // with a synthetic leak. An inline throw asserted by reading this file's source could be made
        // unreachable without the assertion noticing -- the first version of it was satisfied by an
        // `if (false)` guard with the whole body still in place.
        LeakedRows::assertNothingSurvived(
            isset($decoded['leftovers']) && is_array($decoded['leftovers']) ? $decoded['leftovers'] : [],
            "The '{$scenario}' round trip"
        );

        return self::$reports[$scenario] = $decoded;
    }
}
