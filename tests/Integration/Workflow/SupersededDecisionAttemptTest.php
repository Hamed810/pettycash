<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Command\CommandResultRepository;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Application\Workflow\FinanceSettlementService;
use OCA\PettyCash\Application\Workflow\ManagementReviewService;
use OCA\PettyCash\Application\Workflow\SupersededDecisionAttempt;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\AuditEventType;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * FR-085: two decisions for one transaction and stage, first write wins, and the loser leaves a trace.
 *
 * ## The defect this exists for
 *
 * The safety half was never at risk. `pettycash_workflow_decisions_revision_stage_uniq` makes a second
 * row at the same revision and stage impossible, so no double-booking — and no account decremented
 * twice — could occur. What did not exist was the *outcome*: the stale second decision reached the
 * insert, the unique-index violation reached `OcsErrorMapper`'s default arm, and the caller was told
 * `500 UNEXPECTED_FAILURE` with its message withheld. A legitimate business race was reported as a
 * server fault, and the reviewer who lost the race left no record that they had acted.
 *
 * ## Why the ordering assertions are the interesting half
 *
 * The obvious implementation — write the audit event where the exception is thrown, then throw —
 * satisfies every behavioural assertion in this file and is **wrong**. The throw rolls the database
 * transaction back, so the event would be discarded with it. An in-memory repository cannot show that,
 * which is why `theSupersededAttemptEventIsWrittenAfterTheRollback` asserts against the live
 * transaction-open probe instead of against the event's mere existence.
 */
final class SupersededDecisionAttemptTest extends TestCase
{
    private $container;
    private InMemoryTransactionManager $transactions;

    protected function setUp(): void
    {
        $this->container = \TestContainerAccessor::getInstance();

        $this->container->query(ExpenseTransactionRepository::class)->clear();
        $this->container->query(ExpenseSheetRepository::class)->clear();
        $this->container->query(TransactionRevisionRepository::class)->clear();
        $this->container->query(AuditEventRepository::class)->clear();
        $this->container->query(CommandResultRepository::class)->clear();
        $this->container->query(RoleAssignmentRepository::class)->clear();
        $this->container->query(AccountRepository::class)->clear();
        $this->container->query(AccountMovementRepository::class)->clear();

        $this->transactions = $this->container->query(TransactionManager::class);
    }

    protected function tearDown(): void
    {
        $this->container->setInstance(CurrentUser::class, new InMemoryCurrentUser('test-user'));
    }

    // ===== the refusal =======================================================

    public function testASecondDecisionAtTheSameStageIsRefused(): void
    {
        $tx = $this->submittedTransaction('sheet-085-1');
        $this->authorize('manager-1', Role::CostCenterManager, 'CC085');

        $this->review()->recordDecision($tx->id, WorkflowStage::CostCenter, Decision::Approve, 'ok', $this->commandId('first'), $tx->lockVersion);

        $this->expectException(SupersededDecisionAttempt::class);

        $this->review()->recordDecision(
            $tx->id,
            WorkflowStage::CostCenter,
            Decision::Reject,
            'a second opinion',
            $this->commandId('second'),
            $this->currentVersion($tx->id),
        );
    }

    /**
     * The three answers FR-085 requires of the caller, asserted separately because each is a
     * different promise: the status, the code a client branches on, and the instruction that makes
     * the refusal recoverable.
     */
    public function testTheRefusalNamesTheConflictAndInstructsTheCallerToReload(): void
    {
        $tx = $this->submittedTransaction('sheet-085-2');
        $this->authorize('manager-1', Role::CostCenterManager, 'CC085');
        $this->review()->recordDecision($tx->id, WorkflowStage::CostCenter, Decision::Approve, 'ok', $this->commandId('first'), $tx->lockVersion);

        $refusal = $this->captureRefusal(
            fn () => $this->review()->recordDecision(
                $tx->id,
                WorkflowStage::CostCenter,
                Decision::Reject,
                'too late',
                $this->commandId('second'),
                $this->currentVersion($tx->id),
            ),
        );

        self::assertSame('SUPERSEDED_ATTEMPT', $refusal->errorCode(), 'a client must be able to branch on this code');
        self::assertStringContainsString('already recorded', $refusal->getMessage());
        self::assertStringContainsString(
            'Reload',
            $refusal->getMessage(),
            'FR-085 requires the caller to be told to reload to see the current outcome, not merely that '
            . 'something conflicted.'
        );
        self::assertSame(Decision::Reject, $refusal->attemptedDecision());
        self::assertSame(
            Decision::Approve,
            $refusal->inForceDecision(),
            'FR-085 requires the decision already in force to be named.'
        );
        self::assertSame(WorkflowStage::CostCenter, $refusal->stage());
        self::assertSame($tx->id, $refusal->transactionPublicId());
    }

    /**
     * FR-085's first sentence: the decision already recorded **stands and is not overwritten**.
     *
     * Asserted against the repository rather than inferred from the exception, because an overwrite
     * would leave exactly one decision row — the same count a refusal leaves — and the two are only
     * distinguishable by reading the row back.
     */
    public function testTheDecisionAlreadyRecordedStands(): void
    {
        $tx = $this->submittedTransaction('sheet-085-3');
        $this->authorize('manager-1', Role::CostCenterManager, 'CC085');
        $this->review()->recordDecision($tx->id, WorkflowStage::CostCenter, Decision::Approve, 'ok', $this->commandId('first'), $tx->lockVersion);

        $this->captureRefusal(fn () => $this->review()->recordDecision(
            $tx->id,
            WorkflowStage::CostCenter,
            Decision::Reject,
            'too late',
            $this->commandId('second'),
            $this->currentVersion($tx->id),
        ));

        $decisions = $this->container->query(WorkflowDecisionRepository::class)
            ->findByRevisionAndStage($this->revisionDbId($tx->id), WorkflowStage::CostCenter);

        self::assertCount(1, $decisions, 'FR-085 forbids a second decision at the same stage on the same revision.');
        self::assertSame(
            Decision::Approve,
            $decisions[0]->decision,
            'first write wins: the decision in force is the one that must remain.'
        );
    }

    // ===== no business effect ================================================

    public function testTheRefusalMovesNoWorkflowState(): void
    {
        $tx = $this->submittedTransaction('sheet-085-4');
        $this->authorize('manager-1', Role::CostCenterManager, 'CC085');
        $this->review()->recordDecision($tx->id, WorkflowStage::CostCenter, Decision::Approve, 'ok', $this->commandId('first'), $tx->lockVersion);

        $before = $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($tx->id);
        self::assertNotNull($before);

        $this->captureRefusal(fn () => $this->review()->recordDecision(
            $tx->id,
            WorkflowStage::CostCenter,
            Decision::Reject,
            'too late',
            $this->commandId('second'),
            $this->currentVersion($tx->id),
        ));

        $after = $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($tx->id);
        self::assertNotNull($after);

        self::assertSame($before->stage, $after->stage, 'FR-085: no workflow transition.');
        self::assertSame($before->status, $after->status, 'FR-085: no workflow transition.');
        self::assertSame($before->finalOutcome, $after->finalOutcome, 'FR-085: no final outcome is recorded.');
        self::assertSame($before->revision()->id(), $after->revision()->id(), 'FR-085: no revision is created.');
        self::assertSame($before->revision()->number(), $after->revision()->number(), 'FR-085: no revision is created.');
    }

    // ===== the audit event ===================================================

    public function testExactlyOneSupersededAttemptEventIsRecorded(): void
    {
        $tx = $this->submittedTransaction('sheet-085-5');
        $this->authorize('manager-1', Role::CostCenterManager, 'CC085');
        $this->review()->recordDecision($tx->id, WorkflowStage::CostCenter, Decision::Approve, 'ok', $this->commandId('first'), $tx->lockVersion);

        $this->captureRefusal(fn () => $this->review()->recordDecision(
            $tx->id,
            WorkflowStage::CostCenter,
            Decision::Reject,
            'too late',
            $this->commandId('second'),
            $this->currentVersion($tx->id),
        ));

        $events = $this->container->query(AuditEventRepository::class)
            ->findByTransactionId($tx->dbId ?? 0, 0, 100);

        $superseded = array_values(array_filter(
            $events,
            static fn ($e): bool => $e->eventType === AuditEventType::SupersededAttempt->value,
        ));

        self::assertCount(1, $superseded, 'FR-085 records the attempt exactly once.');
        self::assertSame('SUPERSEDED_ATTEMPT', $superseded[0]->eventType);
        self::assertSame(WorkflowStage::CostCenter->value, $superseded[0]->stage);
        self::assertSame($tx->id, $superseded[0]->entityId, 'the event must name the transaction it concerns.');
    }

    /**
     * FR-085's "rather than as an approval or a rejection", asserted as data.
     *
     * A stream that shows `APPROVED` twice for one transaction and stage is indistinguishable from
     * one where two approvals really happened — which is exactly the history Principle IV protects.
     * And because FR-053 makes audit append-only, such a row can never be repaired afterwards.
     */
    public function testTheEventIsNeitherAnApprovalNorARejection(): void
    {
        $tx = $this->submittedTransaction('sheet-085-6');
        $this->authorize('manager-1', Role::CostCenterManager, 'CC085');
        $this->review()->recordDecision($tx->id, WorkflowStage::CostCenter, Decision::Approve, 'ok', $this->commandId('first'), $tx->lockVersion);

        $this->captureRefusal(fn () => $this->review()->recordDecision(
            $tx->id,
            WorkflowStage::CostCenter,
            Decision::Reject,
            'too late',
            $this->commandId('second'),
            $this->currentVersion($tx->id),
        ));

        $events = $this->container->query(AuditEventRepository::class)->findByTransactionId($tx->dbId ?? 0, 0, 100);
        $types = array_map(static fn ($e): string => $e->eventType, $events);

        self::assertSame(
            1,
            count(array_filter($types, static fn (string $t): bool => $t === AuditEventType::Approved->value)),
            'the refused REJECT must not appear as a second APPROVED, and the refused decision must not '
            . 'add an outcome record of its own.'
        );
        self::assertNotContains(
            AuditEventType::Rejected->value,
            $types,
            'FR-085: the refused attempt must not be recorded as a rejection.'
        );
    }

    /**
     * The ordering property, and the reason this file is not satisfied by the event merely existing.
     *
     * An event written inside the rolled-back business transaction disappears with it. The probe here
     * reads the *depth* of open transactions at the instant `append()` is called, not whether one is
     * open — the recorder legitimately opens a transaction of its own to write the event, so
     * "is a transaction open" is true either way and proves nothing.
     *
     * Depth 1 is the recorder's transaction alone. Depth 2 would mean the write happened inside the
     * business transaction, which is the defect.
     */
    public function testTheSupersededAttemptEventIsWrittenAfterTheRollback(): void
    {
        $tx = $this->submittedTransaction('sheet-085-7');
        $this->authorize('manager-1', Role::CostCenterManager, 'CC085');
        $this->review()->recordDecision($tx->id, WorkflowStage::CostCenter, Decision::Approve, 'ok', $this->commandId('first'), $tx->lockVersion);

        $writer = new class ($this->transactions) implements AuditWriter {
            /** @var list<int> */
            public array $depthWhenWritten = [];

            public function __construct(private readonly InMemoryTransactionManager $transactions) {}

            public function append(array $event): void
            {
                $this->depthWhenWritten[] = $this->transactions->transactionDepth();
            }

            public function appendConfiguration(array $event): void
            {
                $this->depthWhenWritten[] = $this->transactions->transactionDepth();
            }

            public function appendConfigurationAsViewer(string $viewerUid, array $event): void
            {
                $this->depthWhenWritten[] = $this->transactions->transactionDepth();
            }
        };

        $audit = $this->container->query(\OCA\PettyCash\Application\Workflow\SupersededDecisionAudit::class);
        self::assertInstanceOf(
            \OCA\PettyCash\Application\Workflow\SupersededDecisionAudit::class,
            $audit,
            'the container must resolve the recorder, or the production wiring is untested.'
        );

        // The recorder is rebuilt around the observing writer rather than having its writer swapped
        // after construction, so the thing under test is the real class on every other axis.
        $recorder = new \OCA\PettyCash\Application\Workflow\SupersededDecisionAudit(
            $writer,
            $this->container->query(ExpenseTransactionRepository::class),
            $this->transactions,
            new \Psr\Log\NullLogger(),
        );

        $this->captureRefusal(fn () => $this->review($recorder)->recordDecision(
            $tx->id,
            WorkflowStage::CostCenter,
            Decision::Reject,
            'too late',
            $this->commandId('second'),
            $this->currentVersion($tx->id),
        ));

        self::assertNotEmpty($writer->depthWhenWritten, 'the attempt must leave an event');
        self::assertSame(
            [1],
            $writer->depthWhenWritten,
            'FR-085: the event must be written in its own transaction, opened after the business one '
            . 'rolled back. A depth of 2 means it was written inside the business transaction and is '
            . 'discarded with it, so the reviewer who acted leaves no trace.'
        );
    }

    // ===== FR-084 is not broken =============================================

    /**
     * FR-085 is explicitly "distinct from FR-084". A replay is the *same* command under the same id
     * and must return the first outcome; only a *different* command against changed state is a
     * superseded attempt. Pinning this is what stops the fix being over-applied — refusing every
     * second arrival would tell a client that timed out and retried that its decision had been
     * rejected when it had in fact been applied.
     */
    public function testAReplayOfTheCommandThatRecordedTheDecisionStillReturnsIt(): void
    {
        $tx = $this->submittedTransaction('sheet-085-8');
        $this->authorize('manager-1', Role::CostCenterManager, 'CC085');

        $commandId = $this->commandId('replayable');
        $first = $this->review()->recordDecision($tx->id, WorkflowStage::CostCenter, Decision::Approve, 'ok', $commandId, $tx->lockVersion);

        // The transaction has moved on, and the caller sends the identical command again.
        $replay = $this->review()->recordDecision($tx->id, WorkflowStage::CostCenter, Decision::Approve, 'ok', $commandId, $tx->lockVersion);

        self::assertSame(
            Decision::Approve,
            $replay->decision->decision,
            'FR-084: a replayed command returns the first outcome, not a 409.'
        );
        self::assertSame(
            $first->decision->id,
            $replay->decision->id,
            'FR-084: it is the same decision row, so the two responses describe one decision.'
        );
    }

    // ===== the finance path =================================================

    /**
     * The settlement is where the safety property matters most: a second accepted `APPROVE` is the
     * only outcome that could decrement the account twice.
     */
    public function testASecondFinanceApprovalMovesNoMoney(): void
    {
        $tx = $this->submittedTransactionAtFinance('sheet-085-9');
        $this->seedActiveAccount('acc-finance', 'test-user');
        $this->authorize('finance-1', Role::Finance, 'CC085');

        $this->finance()->recordDecision($tx->id, Decision::Approve, 'settled', $this->commandId('first'), $tx->lockVersion);

        $movementsAfterFirst = count($this->container->query(AccountMovementRepository::class)->all());
        $accountAfterFirst = $this->container->query(AccountRepository::class)->findActiveByUserAndCurrency('test-user', CurrencyCode::fromString('IRR'))[0] ?? null;
        $balanceAfterFirst = $accountAfterFirst?->balanceMinor;
        // The first approval legitimately completes the transaction, so "no transition" is measured
        // against the state *that* left behind — not against a stage the refusal has to preserve.
        $stageAfterFirst = $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($tx->id)?->stage;

        $refusal = $this->captureRefusal(fn () => $this->finance()->recordDecision(
            $tx->id,
            Decision::Reject,
            'second thoughts',
            $this->commandId('second'),
            $this->currentVersion($tx->id),
        ));

        self::assertSame('SUPERSEDED_ATTEMPT', $refusal->errorCode());
        self::assertSame(WorkflowStage::Finance, $refusal->stage());

        self::assertCount(
            $movementsAfterFirst,
            $this->container->query(AccountMovementRepository::class)->all(),
            'FR-085: no balance movement.'
        );
        self::assertSame(
            $balanceAfterFirst,
            $this->container->query(AccountRepository::class)
                ->findActiveByUserAndCurrency('test-user', CurrencyCode::fromString('IRR'))[0]?->balanceMinor,
            'FR-085: the account balance must be untouched by a refused decision.'
        );

        $after = $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($tx->id);
        self::assertNotNull($after);
        self::assertSame(
            $stageAfterFirst,
            $after->stage,
            'FR-085: no workflow transition out of the state the winning decision left.'
        );
        self::assertSame(
            'APPROVED',
            $after->finalOutcome,
            'FR-085: the refused decision must not change the final outcome the first one recorded.'
        );
    }

    // ===== the 409 itself =====================================================

/**
 * The status the caller receives, and why this is asserted through the marker rather than over HTTP.
 *
 * **There is no HTTP contract test for this 409, and that is a finding rather than an omission.**
 * `POST /transactions/{id}/decisions` routes on the transaction's *current* stage, and every decision
 * leaves the stage it was made at — approve advances it, reject finalises it, return sends it back for
 * correction. So a transaction that is at stage X with a decision already recorded at stage X is
 * **unreachable sequentially**: the second request always arrives after the first has moved the
 * transaction on, and is refused by the ordinary stage guard. The state FR-085 describes is only
 * produced by two requests genuinely in flight at once, which a sequential HTTP client cannot schedule.
 *
 * So the 409 is established by composition instead, and both halves are pinned:
 *
 * 1. This test asserts the exception implements `Conflict` — the same marker `StaleAccountVersion`
 *    implements, and `AccountWriteControllerTest::testAChargeAgainstAStaleVersionIsRefused` already
 *    pins that marker over HTTP as `409` carrying `$error->errorCode()`. `OcsErrorMapper` therefore
 *    needs no new arm for either, and the status is identical by construction.
 * 2. `testASupersededAttemptIsNotReachableSequentially` pins the premise of that sentence, so the day
 *    a decision *can* leave the stage unchanged, this test fails and says the contract test is now
 *    writable.
 *
 * The alternative — an `OcsErrorMapper` arm written per exception class — is what `Conflict`'s own
 * docblock rules out, and it is what T063's broad `catch` then made worse.
 */
public function testASupersededAttemptIsAnswered409ThroughTheSharedConflictArm(): void
{
    $refusal = new SupersededDecisionAttempt(
        'tx-1',
        'rev-1',
        WorkflowStage::CostCenter,
        Decision::Approve,
        Decision::Reject,
    );

    self::assertInstanceOf(
        \OCA\PettyCash\Application\Conflict::class,
        $refusal,
        'the 409 comes from the shared `Conflict` arm. Losing the marker silently turns this into a 500.'
    );
    self::assertSame(
        'SUPERSEDED_ATTEMPT',
        $refusal->errorCode(),
        'the code is the exception\'s own, so a client can branch on it without the mapper knowing the class.'
    );
}

/**
 * Why the HTTP contract test cannot be written: deciding always leaves the stage.
 *
 * Asserted so this file's central claim — that the superseded state is concurrent-only — is a checked
 * property rather than a remark that quietly goes stale. If a decision ever leaves the transaction at
 * the stage it was made at, this fails and the next step is to write
 * `tests/Contract/Controller/SupersededDecisionContractTest.php`.
 */
public function testASupersededAttemptIsNotReachableSequentially(): void
{
    foreach ([Decision::Approve, Decision::Reject, Decision::Return] as $decision) {
        $tx = $this->submittedTransaction('sheet-085-10-' . $decision->value);
        $this->authorize('manager-1', Role::CostCenterManager, 'CC085');

        $stageBefore = $tx->stage;
        $this->review()->recordDecision(
            $tx->id,
            WorkflowStage::CostCenter,
            $decision,
            'Outcome under test',
            $this->commandId('sequential-' . $decision->value),
            $tx->lockVersion,
        );

        $stageAfter = $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($tx->id)?->stage;

        self::assertNotSame(
            $stageBefore,
            $stageAfter,
            sprintf(
                'A %s decision must leave the %s stage. If this ever passes, a transaction at a stage '
                . 'with a decision already recorded there becomes reachable sequentially, and the '
                . 'end-to-end 409 can finally be asserted over HTTP.',
                $decision->value,
                $stageBefore->value,
            ),
        );
    }
}

// ===== helpers ===========================================================

    private function commandId(string $label): string
    {
        return 'cmd-' . $label . '-' . Uuid::random();
    }

    private function currentVersion(string $transactionPublicId): int
    {
        $transaction = $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($transactionPublicId);
        self::assertNotNull($transaction);

        return $transaction->lockVersion;
    }

    private function revisionDbId(string $transactionPublicId): int
    {
        $transaction = $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($transactionPublicId);
        self::assertNotNull($transaction);
        self::assertNotNull($transaction->revision()->dbId, 'the fixture revision must be persisted');

        return $transaction->revision()->dbId;
    }

    /** @template T @param callable():T $operation @return SupersededDecisionAttempt */
    private function captureRefusal(callable $operation): SupersededDecisionAttempt
    {
        try {
            $operation();
        } catch (SupersededDecisionAttempt $refusal) {
            return $refusal;
        }

        self::fail('FR-085: the second decision was not refused.');
    }

    private function review(?object $supersededAudit = null): ManagementReviewService
    {
        return new ManagementReviewService(
            $this->container->query(TransactionManager::class),
            $this->container->query(ExpenseSheetRepository::class),
            $this->container->query(ExpenseTransactionRepository::class),
            $this->container->query(TransactionRevisionRepository::class),
            $this->container->query(CostCenterRepository::class),
            $this->container->query(RoleAssignmentRepository::class),
            $this->container->query(WorkflowDecisionRepository::class),
            $this->container->query(AuditWriter::class),
            $this->container->query(\OCA\PettyCash\Application\Command\IdempotencyService::class),
            $this->container->query(CurrentUser::class),
            $this->container->query(UserDirectory::class),
            $this->container->query(AuthorizationService::class),
            $supersededAudit ?? $this->container->query(\OCA\PettyCash\Application\Workflow\SupersededDecisionAudit::class),
        );
    }

    private function finance(): FinanceSettlementService
    {
        return new FinanceSettlementService(
            $this->container->query(TransactionManager::class),
            $this->container->query(ExpenseSheetRepository::class),
            $this->container->query(ExpenseTransactionRepository::class),
            $this->container->query(TransactionRevisionRepository::class),
            $this->container->query(AccountRepository::class),
            $this->container->query(AccountMovementRepository::class),
            $this->container->query(CostCenterRepository::class),
            $this->container->query(RoleAssignmentRepository::class),
            $this->container->query(WorkflowDecisionRepository::class),
            $this->container->query(AuditWriter::class),
            $this->container->query(\OCA\PettyCash\Application\Command\IdempotencyService::class),
            $this->container->query(CurrentUser::class),
            $this->container->query(UserDirectory::class),
            $this->container->query(AuthorizationService::class),
            $this->container->query(\OCA\PettyCash\Application\Workflow\SupersededDecisionAudit::class),
        );
    }

    private function authorize(string $uid, Role $role, string $costCenterCode): void
    {
        $costCenter = CostCenter::create(
            $costCenterCode,
            'Cost centre ' . $costCenterCode,
            'fixture',
            new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
            null
        );
        $costCenter->id = 1;
        $this->container->query(CostCenterRepository::class)->save($costCenter);

        $this->container->query(RoleAssignmentRepository::class)->save(RoleAssignment::create(
            $uid,
            $role,
            $role === Role::CostCenterManager ? 1 : null,
            new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
            null,
            'fixture',
        ));

        $this->container->setInstance(CurrentUser::class, new InMemoryCurrentUser($uid));
    }

    private function submittedTransaction(string $sheetId): ExpenseTransaction
    {
        $sheet = $this->sheet($sheetId);
        $transaction = $sheet->transactions()[0];
        $transaction->activate($transaction->revision());
        $this->persist($sheet, $transaction);

        return $transaction;
    }

    /**
     * A transaction already at the finance stage, so the settlement path is reached without replaying
     * three review stages in the fixture.
     */
    private function submittedTransactionAtFinance(string $sheetId): ExpenseTransaction
    {
        $transaction = $this->submittedTransaction($sheetId);
        $transaction->stage = WorkflowStage::Finance;
        $transaction->status = TransactionStatus::SeniorApproved;
        $this->container->query(ExpenseTransactionRepository::class)->save($transaction);

        return $transaction;
    }

    /**
     * A funded, active rial account for the purchaser.
     *
     * Seeded explicitly rather than left to FR-020's auto-provisioning because that provisioning is
     * scoped to sheet creation and `UnsettleableAccount`'s whole point is that settlement must not
     * conjure one. A fixture that relied on it would be testing the opposite of the requirement.
     */
    private function seedActiveAccount(string $publicId, string $ownerUid, int $balanceMinor = 1_000_000): void
    {
        $account = \OCA\PettyCash\Domain\Account\PettyCashAccount::create(
            $publicId,
            $ownerUid,
            \OCA\PettyCash\Domain\Configuration\Currency::create('IRR', 'Iranian Rial', 0),
        );
        $account->dbId = 701;
        $account->balanceMinor = $balanceMinor;
        $this->container->query(AccountRepository::class)->save($account);
        $this->container->query(AccountMovementRepository::class)->mapAccount($account->dbId, $publicId);
    }

    private function sheet(string $sheetId): ExpenseSheet
    {
        $currency = CurrencyCode::fromString('IRR');
        $sheet = ExpenseSheet::open($sheetId, 'test-user', 'acc-finance', $currency, 'Sheet ' . $sheetId);
        $sheet->publicId = $sheetId;

        $revision = TransactionRevision::draft(
            'rev-' . $sheetId,
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, $currency)
        );
        $revision->complete('Description', 'CC085', 'CC085', 'Engineering', 1, 'Office Supplies', 1, true);

        $sheet->addTransaction(new ExpenseTransaction('tx-' . $sheetId, 'test-user', $revision));

        return $sheet;
    }

    private function persist(ExpenseSheet $sheet, ExpenseTransaction $transaction): void
    {
        // Order mirrors the real adapter: the transaction row exists first so its surrogate key can
        // be written onto the revision, because `transaction_id` is a NOT NULL join key and the
        // revision repository refuses a revision without it. `revisionDbId()` below depends on the
        // revision's own surrogate key existing too, so both are persisted here.
        $this->container->query(ExpenseTransactionRepository::class)->save($transaction);
        $transaction->revision()->transactionDbId = $transaction->dbId;
        $this->container->query(TransactionRevisionRepository::class)->save($transaction->revision());
        $this->container->query(ExpenseSheetRepository::class)->save($sheet);
    }
}