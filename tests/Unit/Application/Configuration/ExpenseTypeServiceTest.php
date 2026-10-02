<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Configuration;

use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Configuration\ConfigurationConflict;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Configuration\ExpenseTypeService;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTypeRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * T083 — `ExpenseTypeService`, over the in-memory repository double.
 *
 * The double seeds one active type, `Office Supplies`, which requires evidence. The evidence-required
 * flag is the point of FR-012's "including whether a type requires specific evidence", so most of these
 * cases are about it surviving a write rather than about titles.
 */
final class ExpenseTypeServiceTest extends TestCase
{
    private InMemoryExpenseTypeRepository $expenseTypes;

    private ExpenseTypeService $service;

    protected function setUp(): void
    {
        $this->expenseTypes = new InMemoryExpenseTypeRepository();
        // T086: see `CurrencyServiceTest` -- the audit collaborator is supplied so the constructor is complete;
        // the audit behaviour is asserted in `tests/Integration/Audit/ConfigurationMutationAuditTest.php`.
        $this->service = new ExpenseTypeService(
            $this->expenseTypes,
            new InMemoryAdministratorCheck(),
            new ConfigurationAuditService(new InMemoryAuditWriter(), new InMemoryTransactionManager())
        );
    }

    // --- authorization ---

    public function testANonAdministratorIsRefusedEveryWrite(): void
    {
        foreach (
            [
            fn () => $this->service->create('test-user', 'Travel'),
            fn () => $this->service->updateByTitle('test-user', 'Office Supplies', null, true, true, 0),
            fn () => $this->service->deactivate('test-user', 'Office Supplies', 0),
            ] as $index => $write
        ) {
            try {
                $write();
                self::fail(sprintf('write %d was allowed for a non-administrator', $index));
            } catch (LogicException $e) {
                self::assertStringContainsString('administrator', $e->getMessage());
            }
        }
    }

    public function testAReadIsNotRestrictedToAdministratorsBecauseThePurchaserFormNeedsIt(): void
    {
        // `src/views/transactions/TransactionFormView.vue` loads the active types on every open, and
        // FR-023 requires "one active expense type" at submission. Restricting this read would break the
        // purchaser form rather than protect anything.
        $page = $this->service->listActiveForCaller('test-user');

        self::assertCount(1, $page['items']);
        self::assertSame('Office Supplies', $page['items'][0]->title);
    }

    public function testAReadWithNoAuthenticatedCallerIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->service->listActiveForCaller('');
    }

    // --- create ---

    public function testCreateStoresTheEvidenceRequirement(): void
    {
        $withoutEvidence = $this->service->create('test-admin', 'Travel', 'Trips', false);
        $withEvidence = $this->service->create('test-admin', 'Accommodation', 'Hotels', true);

        self::assertFalse($withoutEvidence->evidenceRequired);
        self::assertTrue($withEvidence->evidenceRequired);
        self::assertTrue($this->expenseTypes->findActiveByTitle('Travel')?->evidenceRequired === false);
        self::assertTrue($this->expenseTypes->findActiveByTitle('Accommodation')?->evidenceRequired === true);
    }

    public function testCreateTrimsTheTitleAndRefusesABlankOne(): void
    {
        $this->service->create('test-admin', '  Travel  ');
        self::assertNotNull($this->expenseTypes->findActiveByTitle('Travel'));

        $this->expectException(InvalidArgumentException::class);
        $this->service->create('test-admin', '   ');
    }

    public function testCreateTreatsABlankDescriptionAsAbsent(): void
    {
        $type = $this->service->create('test-admin', 'Travel', '   ');

        self::assertNull($type->description);
    }

    public function testCreateRefusesATitleAnActiveTypeAlreadyHolds(): void
    {
        // This is the invariant no unique index holds. `pettycash_expense_types` declares no unique
        // index on `title`, so nothing below this layer stops two active types sharing one — and FR-023
        // makes a caller choose between them, with different evidence rules.
        try {
            $this->service->create('test-admin', 'Office Supplies', 'A second one', false);
            self::fail('a duplicate active title was accepted');
        } catch (ConfigurationConflict $e) {
            self::assertSame('CONFIGURATION_EXPENSE_TYPE_ALREADY_EXISTS', $e->errorCode());
            self::assertStringContainsString('Office Supplies', $e->getMessage());
        }
    }

    public function testATitleFreedByDeactivationMayBeReusedWithoutOverwritingTheRetainedRow(): void
    {
        // FR-012's historical retention means the deactivated row stays; reuse must therefore create a
        // *second* row rather than reviving or overwriting the first, or history would change.
        $original = $this->expenseTypes->findActiveByTitle('Office Supplies');
        self::assertNotNull($original);
        $this->service->deactivate('test-admin', 'Office Supplies', 0);

        $reused = $this->service->create('test-admin', 'Office Supplies', 'A different type', false);

        self::assertNotSame($original->id, $reused->id, 'reuse must insert, not overwrite the retained row');
        self::assertSame('A different type', $reused->description);
        self::assertFalse($original->active, 'the deactivated original stays inactive');
    }

    // --- update ---

    public function testUpdateChangesDescriptionEvidenceRequirementAndActiveState(): void
    {
        $updated = $this->service->updateByTitle(
            'test-admin',
            'Office Supplies',
            'Now optional',
            false,
            true,
            0
        );

        self::assertSame('Now optional', $updated->description);
        self::assertFalse($updated->evidenceRequired);
    }

    public function testUpdateRefusesAStaleVersionAsAConflict(): void
    {
        try {
            $this->service->updateByTitle('test-admin', 'Office Supplies', null, true, true, 9);
            self::fail('a stale version was accepted');
        } catch (ConfigurationConflict $e) {
            self::assertSame('CONFIGURATION_EXPENSE_TYPE_VERSION_CONFLICT', $e->errorCode());
        }
    }

    public function testUpdateRefusesAnUnknownTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->updateByTitle('test-admin', 'Nonexistent', null, true, true, 0);
    }

    public function testUpdateWritesThroughTheSurrogateKeySoTheRepositoryCannotAddressADifferentRow(): void
    {
        // `DbExpenseTypeRepository::save()` resolves an existing row by `findActiveByTitle()` when no id
        // is set. For an update the id is what makes the write address the row the caller read; without
        // it a rename would insert a second row instead of updating.
        $before = $this->expenseTypes->findActiveByTitle('Office Supplies');
        $this->service->updateByTitle('test-admin', 'Office Supplies', 'changed', false, true, 0);
        $after = $this->expenseTypes->findActiveByTitle('Office Supplies');

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame($before->id, $after->id, 'the same row must have been updated');
    }

    // --- deactivation: FR-012 and US5/AC2's historical retention ---

    public function testDeactivationStopsATypeBeingOfferedWithoutDeletingIt(): void
    {
        $deactivated = $this->service->deactivate('test-admin', 'Office Supplies', 0);

        self::assertFalse($deactivated->active);
        self::assertNull($this->expenseTypes->findActiveByTitle('Office Supplies'));
        self::assertNotNull(
            $this->expenseTypes->findById((int) $deactivated->id),
            'the row must survive deactivation: history reads it through a transaction revision'
        );
        self::assertSame([], $this->service->listActiveForCaller('test-user')['items']);
    }

    public function testDeactivationPreservesEveryOtherField(): void
    {
        // Retiring a type must not silently change the evidence rule historical records were filed
        // against, so `deactivate()` touches `active` and nothing else.
        //
        // The fields are snapshotted into **scalars** before the call, and that is load-bearing rather
        // than stylistic. `InMemoryExpenseTypeRepository` stores the aggregate itself, not a copy, so an
        // object captured beforehand is the *same* object the service mutates: holding `$before` and
        // comparing `$before->description` with `$after->description` afterwards compares a rewritten
        // value with itself and passes whatever `deactivate()` did. An earlier version of this test was
        // written that way and **survived a mutation that made `deactivate()` rewrite the description**,
        // which is the only reason it was caught.
        $stored = $this->expenseTypes->findActiveByTitle('Office Supplies');
        self::assertNotNull($stored);
        $id = (int) $stored->id;
        $title = $stored->title;
        $description = $stored->description;
        $evidenceRequired = $stored->evidenceRequired;

        $this->service->deactivate('test-admin', 'Office Supplies', 0);

        $after = $this->expenseTypes->findById($id);
        self::assertNotNull($after, 'the row must survive deactivation');
        self::assertSame($title, $after->title);
        self::assertSame($description, $after->description);
        self::assertSame($evidenceRequired, $after->evidenceRequired);
        self::assertFalse($after->active, 'the one field that must change');
    }

    public function testDeactivationRefusesAStaleVersion(): void
    {
        $this->expectException(ConfigurationConflict::class);
        $this->service->deactivate('test-admin', 'Office Supplies', 3);
    }

    // --- listing ---

    public function testListingPagesTheActiveTypes(): void
    {
        $this->service->create('test-admin', 'Travel');
        $this->service->create('test-admin', 'Accommodation');

        $page = $this->service->listActiveForCaller('test-user', 1, 2);

        self::assertCount(2, $page['items']);
        self::assertSame(3, $page['total']);
        self::assertSame(2, $page['pageSize']);
    }

    public function testListingRefusesAPageSizeBelowTheContractMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->listActiveForCaller('test-user', 1, 0);
    }
}
