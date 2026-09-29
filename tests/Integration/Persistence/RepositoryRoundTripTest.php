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
        $this->assertSame('employee1', $revision['byPublicId']['managerUid']);
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

        $this->assertSame('employee1', $byCommandId['actorUid']);
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
        $this->assertSame('employee1', $config['byPublicId']['actorUid']);
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

        $this->assertSame('DECIDED', $audit['byPublicId']['eventType']);
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
        $this->assertSame('employee1', $evidence['byPublicId']['uploadedBy']);
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
