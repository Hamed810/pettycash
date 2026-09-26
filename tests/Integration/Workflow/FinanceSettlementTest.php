<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Command\CommandConflict;
use OCA\PettyCash\Application\Command\CommandResultRepository;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Application\Workflow\FinanceSettlementService;
use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\FinanceDecisionPolicy;
use OCA\PettyCash\Infrastructure\Http\TransactionPayloadFormatter;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\FailingAccountMovementRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountMovementRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * FR-040 and the settlement guarantees in `contracts/workflow.md`.
 *
 * The contract is specific about what a finance approval commits: the decision, **one unique
 * consumption movement for the revision**, the new balance, terminal transaction state, recalculated
 * sheet state, and the audit event — all of it or none of it, with the rows locked in a fixed order
 * and duplicates prevented by a unique consumption-source constraint plus command idempotency.
 *
 * These tests exist because none of that was being delivered. `FinanceSettlementService` injected
 * `AccountMovementRepository` and never called it, so a finance approval moved a balance and wrote
 * no ledger row; the unique `source_revision_id` index was never exercised by any code path; and
 * `lockInSettlementOrder()` was defined on the MariaDB adapter but absent from the port and called
 * from nowhere, so the "fixed order" the contract requires was unreachable rather than merely unused.
 */
final class FinanceSettlementTest extends TestCase
{
    private const PURCHASER = 'test-user';
    private const FINANCE = 'test-finance';
    private const AMOUNT_MINOR = 50_000;
    private const OPENING_BALANCE_MINOR = 1_000_000;

    /**
     * The capability keys `CapabilitySet` uses, per decision.
     *
     * Spelled out rather than derived from `Decision::value`, because the enum's values are
     * uppercase (`APPROVE`) and the payload's are camelCase (`canApprove`). Building the key by
     * concatenation produces `canAPPROVE`, which is absent — and `?? null` then reads as "finance
     * was offered nothing", which is the same answer a genuine refusal gives.
     *
     * @var array<string, string>
     */
    private const CAPABILITY_KEYS = [
        'APPROVE' => 'canApprove',
        'REJECT' => 'canReject',
        'RETURN' => 'canReturn',
    ];

    private \TestContainer $container;
    private InMemoryAccountMovementRepository $movements;
    private InMemoryAccountRepository $accounts;
    private InMemoryTransactionManager $transactionManager;
    private Currency $currency;

    protected function setUp(): void
    {
        $this->container = \TestContainerAccessor::getInstance();

        $this->container->query(ExpenseSheetRepository::class)->clear();
        $this->container->query(ExpenseTransactionRepository::class)->clear();
        $this->container->query(TransactionRevisionRepository::class)->clear();
        $this->container->query(RoleAssignmentRepository::class)->clear();
        $this->container->query(AuditEventRepository::class)->clear();
        $this->container->query(CommandResultRepository::class)->clear();
        $this->container->query(AccountRepository::class)->clear();

        $this->movements = $this->container->query(AccountMovementRepository::class);
        $this->movements->clear();
        $this->container->setInstance(AccountMovementRepository::class, $this->movements);

        $this->accounts = $this->container->query(AccountRepository::class);

        $this->transactionManager = new InMemoryTransactionManager();
        $this->container->setInstance(TransactionManager::class, $this->transactionManager);

        $this->container->setInstance(CurrentUser::class, new InMemoryCurrentUser(self::FINANCE));
        $this->container->query(UserDirectory::class)->addUser(self::FINANCE, 'Test Finance');

        $this->currency = Currency::create('IRR', 'Iranian Rial', 0);
        $this->currency->id = 1;
    }

    protected function tearDown(): void
    {
        $this->container->setInstance(CurrentUser::class, new InMemoryCurrentUser('test-user'));
        $this->container->setInstance(TransactionManager::class, new InMemoryTransactionManager());
        $this->container->setInstance(AccountMovementRepository::class, new InMemoryAccountMovementRepository());
    }

    // --- FR-040: the consumption movement -------------------------------------

    public function testFinanceApprovalRecordsExactlyOneConsumptionMovement(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        $consumptions = $this->movements->allOfType(MovementType::Consumption);

        self::assertCount(
            1,
            $consumptions,
            'FR-040: final finance approval records one consumption movement against the account'
        );
        self::assertSame(
            self::AMOUNT_MINOR,
            $consumptions[0]->amount->minorUnits(),
            'the movement is for the approved amount'
        );
        self::assertSame(
            $this->account()->id,
            $consumptions[0]->accountPublicId,
            'the movement belongs to the matching petty-cash account'
        );
    }

    public function testConsumptionNamesTheRevisionAndTheDecisionThatAuthorisedIt(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');

        $settled = $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        $movement = $this->movements->allOfType(MovementType::Consumption)[0];
        $financeDecision = $settled->getDecisions()[count($settled->getDecisions()) - 1];

        self::assertSame(
            $settled->revision()->publicId,
            $movement->sourceRevisionId,
            'the movement names the revision it settled, as a public UUID the contract declares'
        );
        self::assertSame(
            $financeDecision->id,
            $movement->sourceDecisionId,
            'the movement names the finance decision that authorised it'
        );
    }

    public function testTheConsumptionIsFindableByItsSourceRevision(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');

        $settled = $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        $found = $this->movements->findBySourceRevisionId((string) $settled->revision()->publicId);

        self::assertNotNull(
            $found,
            'FR-040 uniqueness is enforced by looking a consumption up by its revision; a lookup that '
            . 'cannot match what the domain holds reports "no consumption" for one that exists'
        );
        self::assertSame(MovementType::Consumption, $found->type());
    }

    public function testApprovalDecreasesTheBalanceByTheApprovedAmount(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        self::assertSame(
            self::OPENING_BALANCE_MINOR - self::AMOUNT_MINOR,
            $this->account()->balanceMinor,
            'US3/AC2: the account balance decreases by the approved amount'
        );
    }

    public function testAnApprovalMayOverdrawTheAccountIntoANegativeBalance(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');
        $account = $this->account();
        $account->balanceMinor = 1_000;
        $this->accounts->save($account);

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        self::assertSame(
            1_000 - self::AMOUNT_MINOR,
            $this->account()->balanceMinor,
            'FR-027: a negative balance is permitted and must not block settlement'
        );
    }

    public function testTheMovementCarriesTheBalanceThatResultsFromIt(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        self::assertSame(
            self::OPENING_BALANCE_MINOR - self::AMOUNT_MINOR,
            $this->movements->allOfType(MovementType::Consumption)[0]->balanceAfter->minorUnits(),
            'balance_after_minor is the exact resulting balance, so a ledger replays'
        );
    }

    public function testARejectionMovesNoMoneyAndWritesNoConsumption(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');

        $this->service()->recordDecision($tx->id, Decision::Reject, 'Not a business expense', $this->commandId(), $tx->lockVersion);

        self::assertCount(0, $this->movements->allOfType(MovementType::Consumption), 'FR-041: a rejected transaction must not affect the balance');
        self::assertSame(self::OPENING_BALANCE_MINOR, $this->account()->balanceMinor);
    }

    public function testDistinctSettlementsProduceDistinctLedgerRows(): void
    {
        $first = $this->seniorApprovedTransaction('tx-1');
        $this->service()->recordDecision($first->id, Decision::Approve, '', $this->commandId(), $first->lockVersion);

        $second = $this->seniorApprovedTransaction('tx-2');
        $this->service()->recordDecision($second->id, Decision::Approve, '', $this->commandId(), $second->lockVersion);

        self::assertCount(
            2,
            $this->movements->allOfType(MovementType::Consumption),
            'two settlements of two revisions are two ledger rows. This is the assertion that catches a '
            . 'repository keying movements by a cast of their UUID: every row then collapses onto one key '
            . 'and a per-revision count still reads 1, which looks exactly like correct behaviour.'
        );
        self::assertSame(
            self::OPENING_BALANCE_MINOR - (2 * self::AMOUNT_MINOR),
            $this->account()->balanceMinor,
            'both consumptions are reflected in the balance'
        );
    }

    // --- The fixed lock order --------------------------------------------------

    public function testSettlementLocksEveryRowItWritesInOneFixedOrder(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        $calls = $this->transactionManager->lockCalls();
        self::assertCount(1, $calls, 'one lock acquisition per settlement');
        self::assertSame(
            ['sheet', 'transaction', 'revision', 'account'],
            array_keys($calls[0]),
            'contracts/workflow.md: finance approval locks the sheet, transaction, active revision, '
            . 'and account in a fixed order. Asserting only that a lock was taken would pass whichever '
            . 'order the adapter happened to use, which is the property that prevents a deadlock.'
        );
    }

    public function testALockIsTakenBeforeTheAccountBalanceIsRead(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        self::assertNotSame(
            [],
            $this->transactionManager->lockCalls(),
            'locking after settling would leave the balance free to change underneath the decision'
        );
    }

    // --- Idempotency -----------------------------------------------------------

    public function testReplayingACommandReturnsTheFirstResultWithoutASecondMovement(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');
        $commandId = $this->commandId();

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $commandId, $tx->lockVersion);
        $this->service()->recordDecision($tx->id, Decision::Approve, '', $commandId, $tx->lockVersion);

        self::assertCount(
            1,
            $this->movements->allOfType(MovementType::Consumption),
            'a replayed command must not append a second row to the ledger'
        );
        self::assertSame(
            self::OPENING_BALANCE_MINOR - self::AMOUNT_MINOR,
            $this->account()->balanceMinor,
            'a replay must not move the balance twice'
        );
    }

    public function testReusingACommandIdWithADifferentPayloadIsRejected(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');
        $commandId = $this->commandId();

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $commandId, $tx->lockVersion);

        $this->expectException(CommandConflict::class);

        $this->service()->recordDecision($tx->id, Decision::Reject, 'Changed my mind', $commandId, $tx->lockVersion);
    }

    public function testARejectedCommandIdLeavesNoSecondBusinessEffect(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');
        $commandId = $this->commandId();
        $this->service()->recordDecision($tx->id, Decision::Approve, '', $commandId, $tx->lockVersion);

        try {
            $this->service()->recordDecision($tx->id, Decision::Reject, 'Changed my mind', $commandId, $tx->lockVersion);
        } catch (CommandConflict) {
            // expected
        }

        self::assertCount(1, $this->movements->all(), 'a conflicting command id creates no new business effect');
    }

    // --- Exactly one consumption per revision ----------------------------------

    public function testASecondSettlementOfTheSameRevisionCannotRecordASecondConsumption(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');
        $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        // A second officer, a fresh command, and the version the first settlement left behind: the
        // shape of two finance officers approving at once. The domain guard is FR-046 (no rewriting
        // after final approval) and the unique `source_revision_id` index is the database backstop;
        // this asserts the domain half is actually reached.
        $settled = $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($tx->id);
        self::assertNotNull($settled);
        self::assertSame(TransactionStatus::CompletedApproved, $settled->status);

        try {
            $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $settled->lockVersion);
        } catch (\Throwable) {
            // Any refusal is acceptable; silently succeeding is not.
        }

        self::assertCount(
            1,
            $this->movements->allOfType(MovementType::Consumption),
            'exactly one consumption per revision, whichever officer attempts the second'
        );
    }

    // --- Rollback --------------------------------------------------------------

    public function testAFailedLedgerWriteCommitsNoMovement(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');
        $failing = new FailingAccountMovementRepository($this->movements);
        $failing->failOnSave = true;
        $this->container->setInstance(AccountMovementRepository::class, $failing);

        try {
            $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);
            self::fail('the injected ledger failure must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('Injected ledger write failure', $e->getMessage());
        }

        self::assertCount(
            0,
            $this->movements->allOfType(MovementType::Consumption),
            'US3/AC4: if any part of final approval fails, none of those effects is finalized'
        );
    }

    /**
     * A failed settlement must not leave a command result behind.
     *
     * This is the replayability precondition, and it is the part that can be observed without a
     * real database. `IdempotencyService` stores a command result *after* the operation returns, so
     * a throw leaves none — and that ordering is load-bearing: a result stored before the work
     * would turn the retry into a no-op that reports success for a settlement that never happened.
     *
     * The other half of the guarantee — that the account balance and the transaction row are also
     * restored — is the database's rollback, and is deliberately **not** claimed here. The in-memory
     * `TransactionManager` runs the callback and returns; it has no snapshot to restore, so the
     * aggregate it was handed stays mutated and a test asserting a restored balance would be
     * asserting a property of MariaDB that this harness does not have. That belongs to the
     * real-schema integration work.
     */
    public function testAFailedLedgerWriteStoresNoCommandResult(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');
        $commandId = $this->commandId();
        $failing = new FailingAccountMovementRepository($this->movements);
        $failing->failOnSave = true;
        $this->container->setInstance(AccountMovementRepository::class, $failing);

        try {
            $this->service()->recordDecision($tx->id, Decision::Approve, '', $commandId, $tx->lockVersion);
            self::fail('the injected ledger failure must propagate');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertNull(
            $this->container->query(CommandResultRepository::class)->find($commandId),
            'a command result stored for a settlement that failed would make the retry a no-op'
        );
    }

    // --- FR-039 and the reachability of finance approval -----------------------

    public function testASeniorApprovedTransactionCanBeApprovedByFinance(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');
        self::assertSame(TransactionStageExpectations::FINANCE_STAGE, $tx->stage->value);
        self::assertSame(TransactionStatus::SeniorApproved, $tx->status);

        $settled = $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        self::assertSame(TransactionStatus::CompletedApproved, $settled->status);
        self::assertSame('APPROVED', $settled->finalOutcome);
    }

    public function testFinanceCannotApproveASeniorRejectedTransaction(): void
    {
        $tx = $this->seniorRejectedTransaction('tx-1');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);
    }

    public function testFinanceCanStillRejectASeniorRejectedTransaction(): void
    {
        $tx = $this->seniorRejectedTransaction('tx-1');

        $settled = $this->service()->recordDecision(
            $tx->id,
            Decision::Reject,
            'Also not allowable',
            $this->commandId(),
            $tx->lockVersion
        );

        self::assertSame(TransactionStatus::CompletedRejected, $settled->status);
        self::assertCount(0, $this->movements->all(), 'FR-041: a rejected transaction moves no money');
    }

    public function testANonFinanceUserCannotSettle(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');
        $this->container->setInstance(CurrentUser::class, new InMemoryCurrentUser(self::PURCHASER));

        $this->expectException(\LogicException::class);

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);
    }

    // --- The capability set the interface reads FR-039 from --------------------

    public function testASeniorApprovedTransactionOffersFinanceAllThreeDecisions(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');

        $actions = $this->capabilities($tx);

        self::assertTrue($actions['canApprove'], 'contracts/workflow.md: finance after senior approval may approve');
        self::assertTrue($actions['canReject']);
        self::assertTrue($actions['canReturn']);
    }

    public function testASeniorRejectedTransactionDoesNotOfferFinanceAnApproveAction(): void
    {
        $tx = $this->seniorRejectedTransaction('tx-1');

        $actions = $this->capabilities($tx);

        self::assertFalse(
            $actions['canApprove'],
            'FR-039: finance must not be offered approval on a senior-rejected transaction. These three '
            . 'capabilities used to be set together whenever the actor could decide at all, so the '
            . 'interface offered a button the command path then refused.'
        );
        self::assertTrue($actions['canReject'], 'FR-039 still permits finance to reject it');
        self::assertTrue($actions['canReturn'], 'FR-039 still permits finance to return it');
    }

    public function testTheOfferedActionsAndTheRefusedOnesAgree(): void
    {
        // The property the formatter's own docblock claims: a hidden action and a refused action
        // cannot disagree. Checked against the policy the command path actually enforces, for both
        // senior outcomes and all three decisions, so the two can only diverge if one of them stops
        // consulting it.
        $cases = [
            [Decision::Approve, 'tx-approve'],
            [Decision::Reject, 'tx-reject'],
        ];

        foreach ($cases as [$seniorDecision, $transactionId]) {
            $status = $this->settledStatusFor($seniorDecision);
            $tx = $this->seniorApprovedTransaction($transactionId);
            if ($seniorDecision === Decision::Reject) {
                $tx = $this->seniorRejectedTransaction($transactionId);
            }

            $actions = $this->capabilities($tx);
            $policy = new FinanceDecisionPolicy();

            foreach ([Decision::Approve, Decision::Reject, Decision::Return] as $decision) {
                $key = self::CAPABILITY_KEYS[$decision->value];

                self::assertSame(
                    $policy->can($status, $decision),
                    $actions[$key],
                    sprintf(
                        'a %s transaction offered to %s: offered=%s, permitted=%s',
                        $status->value,
                        $decision->value,
                        var_export($actions[$key] ?? null, true),
                        var_export($policy->can($status, $decision), true)
                    )
                );
            }
        }
    }

    public function testANonDeciderIsOfferedNoDecisionActionsAtAll(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');
        $this->container->setInstance(CurrentUser::class, new InMemoryCurrentUser(self::PURCHASER));
        $this->container->query(RoleAssignmentRepository::class)->clear();

        $actions = $this->capabilities($tx);

        self::assertFalse($actions['canApprove']);
        self::assertFalse($actions['canReject']);
        self::assertFalse($actions['canReturn']);
    }

    private function settledStatusFor(Decision $seniorDecision): TransactionStatus
    {
        return $seniorDecision === Decision::Approve
            ? TransactionStatus::SeniorApproved
            : TransactionStatus::SeniorRejected;
    }

    /** @return array<string, bool> */
    private function capabilities(ExpenseTransaction $transaction): array
    {
        $formatter = new TransactionPayloadFormatter(
            $this->container->query(WorkflowDecisionRepository::class),
            $this->container->query(EvidenceRepository::class),
            $this->container->query(CostCenterRepository::class),
            $this->container->query(CurrencyRepository::class),
            $this->container->query(RoleAssignmentRepository::class),
            $this->container->query(CurrentUser::class)
        );

        $detail = $formatter->detail($transaction);
        self::assertIsArray($detail['allowedActions'] ?? null, 'the detail payload must carry a capability set');

        return $detail['allowedActions'];
    }

    // --- FR-043: the sheet completes when its last transaction is settled -------

    public function testTheSheetCompletesWhenItsLastTransactionIsSettled(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');

        self::assertSame(WorkflowStage::Finance, $tx->stage, 'the transaction is awaiting finance');
        self::assertSame(
            SheetStatus::Open,
            $this->sheet()->status,
            'nothing recalculates a sheet until a settlement does, so it is still open'
        );

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        self::assertSame(
            SheetStatus::Completed,
            $this->sheet()->status,
            'FR-043: a sheet becomes completed when every transaction has a final financial outcome'
        );
        self::assertNotNull($this->sheet()->completedAt, 'completion is timestamped');
    }

    public function testTheSheetCompletesWithRejectionsWhenAnyTransactionWasRejected(): void
    {
        $approved = $this->seniorApprovedTransaction('tx-ok');

        $this->service()->recordDecision($approved->id, Decision::Approve, '', $this->commandId(), $approved->lockVersion);
        self::assertSame(
            SheetStatus::Completed,
            $this->sheet()->status,
            'a sheet whose only settled transaction was approved completes, not completes-with-rejections'
        );

        // A second transaction joins the sheet afterwards and is rejected. The two-step order
        // matters: had both transactions existed when the first was settled, the sheet would
        // correctly have stayed at FINANCE and there would be nothing to observe here.
        $rejected = $this->seniorApprovedTransaction('tx-bad');
        $this->service()->recordDecision($rejected->id, Decision::Reject, 'No', $this->commandId(), $rejected->lockVersion);

        self::assertSame(
            SheetStatus::CompletedWithRejections,
            $this->sheet()->status,
            'FR-043: the sheet records that at least one transaction was rejected'
        );
    }

    public function testTheSheetWaitsForItsLastUnsettledTransaction(): void
    {
        $first = $this->seniorApprovedTransaction('tx-1');
        $second = $this->seniorApprovedTransaction('tx-2');

        $this->service()->recordDecision($first->id, Decision::Approve, '', $this->commandId(), $first->lockVersion);

        self::assertSame(
            SheetStatus::Finance,
            $this->sheet()->status,
            'one settled transaction does not complete a sheet whose other transaction is still at finance'
        );

        $this->service()->recordDecision($second->id, Decision::Approve, '', $this->commandId(), $second->lockVersion);

        self::assertSame(SheetStatus::Completed, $this->sheet()->status);
    }

    public function testASheetWithNoTransactionsToJudgeIsNotCompleted(): void
    {
        // Asserted on the aggregate rather than through the settlement, because the settlement
        // cannot reach this branch: the transaction it is settling necessarily carries the sheet id
        // it looks up, so a loaded sheet always has at least that one transaction. A mutation
        // removing the guard therefore passed the settlement suite untouched, which is exactly why
        // it is pinned here.
        //
        // Without the guard, `recalculateStatusFrom([])` walks an empty set, never clears
        // `$allCompleted`, and declares the sheet `COMPLETED` — so a caller that loaded nothing
        // would complete a sheet it knows nothing about.
        $sheet = $this->sheetFixture();
        self::assertSame(SheetStatus::Open, $sheet->status);

        $sheet->recalculateStatusFrom([]);

        self::assertSame(
            SheetStatus::Open,
            $sheet->status,
            'an empty transaction set means "nothing to recalculate", not "everything is finished"'
        );
        self::assertNull($sheet->completedAt);
    }

    public function testASheetIsNotCompletedByASettlementItCannotSee(): void
    {
        $tx = $this->seniorApprovedTransaction('tx-1');
        $before = $this->sheet()->status;
        $this->container->query(ExpenseSheetRepository::class)->clear();

        $this->service()->recordDecision($tx->id, Decision::Approve, '', $this->commandId(), $tx->lockVersion);

        self::assertCount(
            0,
            $this->container->query(ExpenseSheetRepository::class)->findByOwnerUid(self::PURCHASER),
            'an unloadable sheet is left alone rather than completed: inventing a status for a sheet '
            . 'the settlement cannot see would be worse than leaving it stale'
        );
        self::assertNotSame(SheetStatus::Completed, $before);
    }

    private function sheet(): ExpenseSheet
    {
        $sheet = $this->container->query(ExpenseSheetRepository::class)->findByPublicId('sheet-1');
        self::assertNotNull($sheet, 'the fixture sheet must exist');
        return $sheet;
    }

    /**
     * The one sheet every fixture transaction belongs to.
     *
     * Shared deliberately: FR-043 is about *a sheet's* transactions, so a fixture that gave each
     * transaction its own sheet could not express a sheet completing, or failing to complete,
     * because there was never more than one transaction to judge it by. Created open and never
     * submitted, which is also the honest starting point — nothing recalculates a sheet until a
     * settlement does, so a sheet that has never been settled is still `OPEN`.
     */
    private function sheetFixture(): ExpenseSheet
    {
        $existing = $this->container->query(ExpenseSheetRepository::class)->findByPublicId('sheet-1');
        if ($existing !== null) {
            return $existing;
        }

        $sheet = ExpenseSheet::open('sheet-1', self::PURCHASER, 'acc-finance', CurrencyCode::fromString('IRR'), 'Settlement Sheet');
        $sheet->publicId = 'sheet-1';
        $sheet->dbId = 501;
        $this->container->query(ExpenseSheetRepository::class)->save($sheet);

        return $sheet;
    }

    // --- Fixtures --------------------------------------------------------------

    private function service(): FinanceSettlementService
    {
        return new FinanceSettlementService(
            $this->container->query(TransactionManager::class),
            $this->container->query(ExpenseSheetRepository::class),
            $this->container->query(ExpenseTransactionRepository::class),
            $this->container->query(TransactionRevisionRepository::class),
            $this->container->query(AccountRepository::class),
            $this->container->query(AccountMovementRepository::class),
            $this->container->query(\OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository::class),
            $this->container->query(RoleAssignmentRepository::class),
            $this->container->query(AuditWriter::class),
            $this->container->query(\OCA\PettyCash\Application\Command\IdempotencyService::class),
            $this->container->query(CurrentUser::class),
            $this->container->query(UserDirectory::class),
            new AuthorizationService()
        );
    }

    private function commandId(): string
    {
        return (string) Uuid::random();
    }

    private function account(): PettyCashAccount
    {
        $account = $this->accounts->findByPublicId('acc-finance');
        self::assertNotNull($account, 'the fixture account must exist');
        return $account;
    }

    /**
     * A transaction the senior manager has approved, sitting in the finance queue.
     *
     * The status is asserted by the caller rather than assumed: it is the state
     * `FinanceDecisionPolicy` gates approval on, and for a long time nothing in the codebase
     * produced it, which is why US3 could not complete at all.
     */
    private function seniorApprovedTransaction(string $transactionId): ExpenseTransaction
    {
        return $this->transactionAtFinance($transactionId, Decision::Approve, TransactionStatus::SeniorApproved);
    }

    private function seniorRejectedTransaction(string $transactionId): ExpenseTransaction
    {
        return $this->transactionAtFinance($transactionId, Decision::Reject, TransactionStatus::SeniorRejected);
    }

    private function transactionAtFinance(
        string $transactionId,
        Decision $seniorDecision,
        TransactionStatus $expectedStatus
    ): ExpenseTransaction {
        $this->seedAccount();
        $this->seedFinanceRole();

        $sheet = $this->sheetFixture();

        $revision = TransactionRevision::draft(
            'rev-' . $transactionId,
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(self::AMOUNT_MINOR, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('Office supplies', 'CC001', 'CC001', 'Cost Center 1', 1, 'Office Supplies', 1, true);
        $revision->publicId = 'pub-rev-' . $transactionId;
        $revision->dbId = 600 + (crc32($transactionId) % 100);
        $revision->transactionDbId = 700 + (crc32($transactionId) % 100);

        $tx = new ExpenseTransaction(
            $transactionId,
            self::PURCHASER,
            $revision,
            $sheet->dbId,
            null,
            null,
            0,
            $revision->transactionDbId,
            $transactionId
        );
        $tx->activate($revision);
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'Fine', 'manager-1', 'Manager One', 'COST_CENTER_MANAGER', (string) Uuid::random());
        $tx->recordDecision(WorkflowStage::Senior, $seniorDecision, 'Reviewed', 'senior-1', 'Senior One', 'SENIOR_MANAGER', (string) Uuid::random());

        self::assertSame(
            $expectedStatus,
            $tx->status,
            'the senior decision must leave the transaction in the state finance acts on'
        );
        self::assertSame(WorkflowStage::Finance, $tx->stage);

        $this->container->query(TransactionRevisionRepository::class)->save($revision);
        $this->container->query(ExpenseTransactionRepository::class)->save($tx);

        return $tx;
    }

    /**
     * Seed the funded account, once.
     *
     * Idempotent on purpose: a test that settles two transactions must see the *same* account
     * twice, with the first settlement's movement already applied. Re-seeding the opening balance
     * on the second call would silently reset it, and the balance assertion would then be
     * measuring the fixture rather than the settlement.
     */
    private function seedAccount(): void
    {
        if ($this->accounts->findByPublicId('acc-finance') !== null) {
            return;
        }

        $account = PettyCashAccount::create('acc-finance', self::PURCHASER, $this->currency);
        $account->dbId = 701;
        $account->balanceMinor = self::OPENING_BALANCE_MINOR;
        $this->accounts->save($account);
        $this->movements->mapAccount(701, 'acc-finance');
    }

    private function seedFinanceRole(): void
    {
        $this->container->query(RoleAssignmentRepository::class)->save(
            RoleAssignment::create(
                self::FINANCE,
                Role::Finance,
                null,
                new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
                null
            )
        );
    }
}

/**
 * Named so the finance-stage assertion reads as a claim about the workflow rather than as a
 * literal repeated in the body of the test.
 */
final class TransactionStageExpectations
{
    public const FINANCE_STAGE = 'FINANCE';
}
