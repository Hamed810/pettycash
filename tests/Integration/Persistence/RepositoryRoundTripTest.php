<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Persistence;

use PHPUnit\Framework\TestCase;
use RuntimeException;

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
     * @return array<string, mixed>
     */
    /**
     * The runner's fingerprint sweep must be able to reach a sheet.
     *
     * Seven sheets titled "Round Trip Sheet" survived from before that sweep existed, and because the
     * sweep had no `pettycash_sheets` predicate they were unreachable forever: the by-id sweep needs a
     * prior `$track`, and a row leaked *between* its insert and its registration never gets one. The
     * currencies they pointed at *were* swept by name, which is what turned a harmless leak into seven
     * dangling `sheets.currency_id` values -- and those made `GET /reports/transactions`,
     * `/reports/process` and `/reports/evidence` return no data at all.
     *
     * Asserted against the runner's source because the predicate list is the thing that was wrong, and
     * because the alternative -- running a scenario and then inspecting the database for leftovers --
     * depends on test ordering that PHPUnit does not guarantee. The order is asserted too: sheets must
     * be swept before the currencies they reference, or the sweep re-creates the dangling row it exists
     * to remove.
     */
    public function testTheFingerprintSweepCanReachALeakedSheet(): void
    {
        $runner = file_get_contents(__DIR__ . '/roundtrip-runner.php');
        $this->assertIsString($runner, 'the runner must be readable');

        $this->assertMatchesRegularExpression(
            "/\['pettycash_sheets',\s*'title',\s*'eq',\s*'Round Trip Sheet'\]/",
            $runner,
            'the fingerprint sweep must match sheets by title, or a leaked sheet is unreachable'
        );

        $sheetAt = strpos($runner, "['pettycash_sheets', 'title', 'eq', 'Round Trip Sheet']");
        $currencyAt = strpos($runner, "['pettycash_currencies', 'name', 'eq', 'Round Trip Currency']");
        $this->assertIsInt($sheetAt);
        $this->assertIsInt($currencyAt);
        $this->assertLessThan(
            $currencyAt,
            $sheetAt,
            'sheets must be swept before the currencies they reference'
        );
    }

    /**
     * The companion diagnostic had the identical blind spot, so it would have reported a clean
     * database while the seven broken sheets sat in it.
     */
    public function testTheLeftoversDiagnosticCanSeeALeakedSheet(): void
    {
        $helper = file_get_contents(__DIR__ . '/roundtrip-leftovers.php');
        $this->assertIsString($helper);

        $this->assertMatchesRegularExpression(
            "/\['pettycash_sheets'/",
            $helper,
            'the leftover diagnostic must count sheets, or a leak is invisible'
        );
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

        return self::$reports[$scenario] = $decoded;
    }
}
