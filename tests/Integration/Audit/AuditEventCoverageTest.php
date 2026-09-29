<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Audit;

use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Application\Workflow\SubmitSheet;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTypeRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionRevisionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * T213: the four FR-051 transitions that had no writer at all.
 *
 * `submissions`, decisions, `corrections` and `evidence deactivations` were already recorded. These four
 * were named by FR-051 and written by nothing, so the official history was missing them entirely:
 *
 * - **routing** (FR-032) -- which manager a transaction was sent to
 * - **revision creation** -- a transaction's first revision
 * - **transaction changes** -- a field edit, whose whole point is the before-and-after pair
 * - **evidence additions** -- a file being attached, so a deactivation had no recorded arrival
 *
 * Each test drives the real service and reads the event the real call site emitted. Asserting on the
 * payload rather than on "an event was written" is deliberate: a writer that emits an event with the
 * wrong subject or an empty snapshot has not recorded the transition, and that is the failure mode this
 * task exists to prevent.
 */
final class AuditEventCoverageTest extends TestCase
{
    private const OWNER = 'purchaser-1';
    private const MANAGER = 'manager-1';
    private const SHEET_ID = 'sheet-1';

    private InMemoryAuditWriter $audit;
    private InMemoryExpenseSheetRepository $sheets;
    private InMemoryExpenseTransactionRepository $transactions;
    private InMemoryTransactionRevisionRepository $revisions;
    private InMemoryCostCenterRepository $costCenters;
    private InMemoryRoleAssignmentRepository $assignments;

    protected function setUp(): void
    {
        $this->audit = new InMemoryAuditWriter();
        $this->sheets = new InMemoryExpenseSheetRepository();
        $this->transactions = new InMemoryExpenseTransactionRepository();
        $this->revisions = new InMemoryTransactionRevisionRepository();
        $this->costCenters = new InMemoryCostCenterRepository();
        $this->assignments = new InMemoryRoleAssignmentRepository();
    }

    /** @return array<int, array<string,mixed>> */
    private function eventsOfType(string $type): array
    {
        return array_values(array_filter(
            $this->audit->getEvents(),
            static fn(array $e): bool => ($e['event_type'] ?? null) === $type
        ));
    }

    private function seedCostCenter(int $id = 1, string $code = 'CC-100'): CostCenter
    {
        $costCenter = CostCenter::create($code, "Cost Center $code", null, new \DateTimeImmutable('2026-01-01'), null);
        $costCenter->id = $id;
        $costCenter->publicId = "cc-$id";
        $this->costCenters->save($costCenter);

        return $costCenter;
    }

    private function seedSheet(string $publicId = self::SHEET_ID): ExpenseSheet
    {
        $sheet = new ExpenseSheet(
            $publicId,
            self::OWNER,
            'account-1',
            CurrencyCode::fromString('IRR')
        );
        $sheet->dbId = 1;
        // The in-memory double keys sheets by `publicId`; the real repository stores the aggregate's
        // `id` in the `public_id` column and the two coincide in production. Setting both keeps the
        // fixture readable by the double without asserting a shape the database cannot hold.
        $sheet->publicId = $publicId;
        $this->sheets->save($sheet);

        return $sheet;
    }

    // ===== revision creation =====

    public function testCreatingATransactionRecordsARevisionCreatedEvent(): void
    {
        $this->seedSheet();

        $this->transactionService()->createTransaction(self::SHEET_ID, '2026-02-01', 5000);

        $events = $this->eventsOfType('REVISION_CREATED');
        self::assertCount(1, $events, 'creating a transaction must record exactly one revision creation');
        self::assertSame('transaction', $events[0]['entity_type']);
        self::assertSame('DRAFT', $events[0]['stage'], 'a new revision belongs to the draft stage');
    }

    public function testTheRevisionCreatedEventCarriesTheAmountAndDateItDescribes(): void
    {
        $this->seedSheet();

        $this->transactionService()->createTransaction(self::SHEET_ID, '2026-02-01', 5000);

        $after = $this->eventsOfType('REVISION_CREATED')[0]['after'];
        self::assertSame(5000, $after['amount_minor'] ?? null);
        self::assertSame('2026-02-01', substr((string)($after['expense_date'] ?? ''), 0, 10));
        self::assertSame(1, $after['revision_number'] ?? null, 'the first revision is number 1');
        self::assertFalse($after['is_correction'] ?? null, 'a first revision is not a correction');
    }

    // ===== transaction changes =====

    public function testEditingATransactionRecordsATransactionChangedEvent(): void
    {
        $this->seedSheet();
        $this->seedCostCenter();
        $expenseType = ExpenseType::create('Travel', null, false);
        $expenseType->id = 1;
        $expenseType->publicId = 'et-1';

        $service = $this->transactionService([$expenseType]);
        $transaction = $service->createTransaction(self::SHEET_ID, '2026-02-01', 5000);
        $this->audit->clear();

        $service->updateTransaction(
            $transaction->id,
            '2026-02-02',
            6000,
            'Taxi to client site',
            'cc-1',
            'et-1',
            $transaction->lockVersion
        );

        $events = $this->eventsOfType('TRANSACTION_CHANGED');
        self::assertCount(1, $events, 'an edit must record exactly one transaction change');
        self::assertSame($transaction->id, $events[0]['entity_id']);
    }

    /**
     * The load-bearing assertion for this event: a change is only legible as a diff.
     *
     * Asserting the two snapshots *differ* and that each names the value it is supposed to, rather than
     * merely that both keys exist. An implementation that emitted `'before' => []` -- the exact shape that
     * silently discarded data twice in this task -- would satisfy a key-existence check and fail here.
     */
    public function testTheTransactionChangedEventCarriesThePriorAndNewValues(): void
    {
        $this->seedSheet();
        $this->seedCostCenter();
        $expenseType = ExpenseType::create('Travel', null, false);
        $expenseType->id = 1;
        $expenseType->publicId = 'et-1';

        $service = $this->transactionService([$expenseType]);
        $transaction = $service->createTransaction(self::SHEET_ID, '2026-02-01', 5000);
        $this->audit->clear();

        $service->updateTransaction(
            $transaction->id,
            '2026-02-02',
            6000,
            'Taxi to client site',
            'cc-1',
            'et-1',
            $transaction->lockVersion
        );

        $event = $this->eventsOfType('TRANSACTION_CHANGED')[0];
        $before = $event['before'];
        $after = $event['after'];

        self::assertNotSame($before, $after, 'a change event whose snapshots are equal records nothing');
        self::assertArrayHasKey('description', $before, 'the prior value must be present, even when null');
        self::assertNull($before['description'], 'the prior revision had no description');
        self::assertArrayHasKey('description', $after);
        self::assertSame('Taxi to client site', $after['description']);
        self::assertArrayHasKey('expense_type_title', $before);
        self::assertArrayHasKey('expense_type_title', $after);
        self::assertNotSame(
            $before['expense_type_title'],
            $after['expense_type_title'],
            'the expense type changed, so the two snapshots must differ on that field too'
        );
        self::assertSame('Travel', $after['expense_type_title']);
        self::assertSame('CC-100', $after['cost_center_code'] ?? null);
        self::assertNotSame(
            '[]',
            json_encode($before),
            'an empty before-snapshot is the exact defect this task found twice already'
        );
    }

    // ===== evidence additions =====

    public function testUploadingEvidenceRecordsAnEvidenceAddedEvent(): void
    {
        $this->evidenceService()->uploadEvidence(
            'txn-1',
            1,
            1,
            'invoice.pdf',
            'file-bytes',
            'application/pdf'
        );

        $events = $this->eventsOfType('EVIDENCE_ADDED');
        self::assertCount(1, $events, 'attaching a file must record that it arrived');
        self::assertSame('evidence', $events[0]['entity_type']);
        self::assertSame('invoice.pdf', $events[0]['after']['original_filename'] ?? null);
        self::assertSame('application/pdf', $events[0]['after']['media_type'] ?? null);
        self::assertSame(10, $events[0]['after']['size_bytes'] ?? null, 'the byte count, not the filename');
        self::assertArrayHasKey('checksum', $events[0]['after'], 'the checksum identifies the stored bytes');
    }

    public function testEvidenceAdditionAndDeactivationBothAppearForTheSameEvidence(): void
    {
        $service = $this->evidenceService();
        $evidence = $service->uploadEvidence('txn-1', 1, 1, 'invoice.pdf', 'bytes', 'application/pdf');
        $this->audit->clear();

        $service->deactivateEvidence($evidence->id, 'wrong file', 'cmd-1');

        self::assertCount(0, $this->eventsOfType('EVIDENCE_ADDED'), 'cleared: only the deactivation is under test');
        self::assertCount(1, $this->eventsOfType('EVIDENCE_DEACTIVATED'));
    }

    // ===== routing =====

    public function testSubmittingASheetRecordsOneRoutedEventPerTransaction(): void
    {
        $this->seedSheet();
        $this->seedCostCenter();
        $transaction = $this->routableTransaction();

        $this->submitSheetService()->execute(self::SHEET_ID, 'cmd-submit', 0);

        $routed = $this->eventsOfType('ROUTED');
        self::assertCount(1, $routed, 'routing is per transaction, so one transaction yields one event');
        self::assertSame($transaction->id, $routed[0]['entity_id']);
        self::assertSame('transaction', $routed[0]['entity_type'], 'routing is a fact about the transaction');
    }

    public function testTheRoutedEventNamesTheManagerItWasSentTo(): void
    {
        $this->seedSheet();
        $this->seedCostCenter();
        $this->assignManager();
        $this->routableTransaction();

        $this->submitSheetService()->execute(self::SHEET_ID, 'cmd-submit', 0);

        $routed = $this->eventsOfType('ROUTED');
        self::assertSame(
            self::MANAGER,
            $routed[0]['after']['assigned_manager_uid'] ?? null,
            'the manager is the fact only this event records, and it must be the one currently assigned'
        );
        self::assertSame('CC-100', $routed[0]['after']['cost_center_code'] ?? null);
    }

    /**
     * No assigned manager is recorded as null rather than invented.
     *
     * Submission does not require an assignee, so this is a reachable state today. Writing a plausible
     * UID would put a real-looking name on a routing decision nobody made, which is worse than admitting
     * the gap; the assertion pins that honesty so a later "fix" cannot quietly fabricate one.
     */
    public function testRoutingToACostCentreWithNoManagerRecordsNullRatherThanInventingOne(): void
    {
        $this->seedSheet();
        $this->seedCostCenter();
        $this->routableTransaction();

        $this->submitSheetService()->execute(self::SHEET_ID, 'cmd-submit', 0);

        $routed = $this->eventsOfType('ROUTED');
        self::assertArrayHasKey('assigned_manager_uid', $routed[0]['after']);
        self::assertNull($routed[0]['after']['assigned_manager_uid']);
    }

    /**
     * A transaction that actually has a cost centre, built through the real edit path.
     *
     * The revision's cost-centre fields are private and are only populated by `complete()`, so writing
     * them directly would have required reaching past the aggregate to construct a state the domain
     * cannot hold. Editing the transaction is also the more honest setup: it is the same route a
     * purchaser takes, and it leaves the revision holding a real snapshotted code and name for the
     * routing event to read.
     *
     * A description and an expense type are both supplied because editing a revision that has neither is
     * a pre-existing crash unrelated to auditing: `description()` and `expenseTypeTitle()` are nullable
     * while `complete()` requires `string` for both, and `updateTransaction()` passes
     * `$description ?? $current->description()` straight through. Omitting either throws a TypeError, so
     * a draft cannot be completed by adding only a cost centre. Recorded in the T213 report rather than
     * fixed here: it is T212's behaviour, and whether `complete()` should accept null is a domain
     * decision rather than an audit concern.
     */
    private function routableTransaction(): \OCA\PettyCash\Domain\Expense\ExpenseTransaction
    {
        $expenseType = ExpenseType::create('Travel', null, false);
        $expenseType->id = 1;
        $expenseType->publicId = 'et-1';

        $service = $this->transactionService([$expenseType]);
        $transaction = $service->createTransaction(self::SHEET_ID, '2026-02-01', 5000);
        $service->updateTransaction(
            $transaction->id,
            '2026-02-01',
            5000,
            'Original description',
            'cc-1',
            'et-1',
            $transaction->lockVersion
        );
        $this->audit->clear();

        return $transaction;
    }

    private function assignManager(): void
    {
        $assignment = RoleAssignment::create(
            self::MANAGER,
            Role::CostCenterManager,
            1,
            new \DateTimeImmutable('2020-01-01'),
            null
        );
        $assignment->id = 1;
        $this->assignments->save($assignment);
    }

    // ===== wiring =====

    /** @param array<int, ExpenseType> $expenseTypes */
    private function transactionService(array $expenseTypes = []): TransactionService
    {
        $expenseTypeRepository = new InMemoryExpenseTypeRepository();
        foreach ($expenseTypes as $expenseType) {
            $expenseTypeRepository->save($expenseType);
        }

        return new TransactionService(
            $this->sheets,
            $this->transactions,
            $this->revisions,
            $this->costCenters,
            $expenseTypeRepository,
            new InMemoryEvidenceRepository(),
            new InMemoryPrivateFileStore(),
            new InMemoryCurrentUser(self::OWNER),
            new InMemoryTransactionManager(),
            $this->audit
        );
    }

    private function evidenceService(): EvidenceService
    {
        return new EvidenceService(
            new InMemoryPrivateFileStore(),
            new InMemoryEvidenceRepository(),
            new InMemoryCurrentUser(self::OWNER),
            $this->audit,
            new InMemoryUserDirectory(),
            new IdempotencyService(new InMemoryCommandResultRepository())
        );
    }

    private function submitSheetService(): SubmitSheet
    {
        return new SubmitSheet(
            new InMemoryTransactionManager(),
            $this->sheets,
            $this->audit,
            new IdempotencyService(new InMemoryCommandResultRepository()),
            new InMemoryCurrentUser(self::OWNER),
            $this->costCenters,
            $this->transactions,
            $this->assignments
        );
    }
}
