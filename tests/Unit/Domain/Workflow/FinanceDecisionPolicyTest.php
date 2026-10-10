<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Workflow;

use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\FinanceDecisionPolicy;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class FinanceDecisionPolicyTest extends TestCase
{
    private PettyCashAccount $account;
    private Currency $currency;

    protected function setUp(): void
    {
        $this->currency = Currency::create('IRR', 'Iranian Rial', 0);
        $this->currency->id = 1;

        $this->account = PettyCashAccount::create('acc-1', 'user-1', $this->currency);
        $this->account->id = 'acc-1';
        $this->account->balanceMinor = 1000000; // 10,000 IRR
        $this->account->active = true;
    }

    public function testSeniorApprovedAllowsApproveRejectReturn(): void
    {
        // Test that senior-approved transaction can be approved, rejected, or returned by finance
        $policy = new FinanceDecisionPolicy();

        self::assertTrue($policy->canApprove(TransactionStatus::SeniorApproved));
        self::assertTrue($policy->canReject(TransactionStatus::SeniorApproved));
        self::assertTrue($policy->canReturn(TransactionStatus::SeniorApproved));
    }

    public function testSeniorApprovedAllowsFinanceApprove(): void
    {
        $policy = new FinanceDecisionPolicy();
        
        // Finance can approve senior-approved transaction
        self::assertTrue($policy->canFinanceApprove(TransactionStatus::SeniorApproved));
    }

    /**
     * The decision matrix of `contracts/workflow.md`, asserted as a matrix.
     *
     * `canApprove` used to accept `SeniorRejected` as well, on the reasoning that both statuses mean
     * "the senior has decided". That is the conflation FR-039 forbids, and it is why the
     * capability endpoint offered an Approve button on a senior-rejected transaction. Asserting the
     * whole table rather than one status at a time is what makes that class of drift visible: a
     * change to any single cell now fails here.
     */
    public function testTheDecisionMatrixMatchesTheWorkflowContract(): void
    {
        $policy = new FinanceDecisionPolicy();

        $expected = [
            // status => [approve, reject, return]
            TransactionStatus::SeniorApproved->value => [true, true, true],
            TransactionStatus::SeniorRejected->value => [false, true, true],
        ];

        foreach ($expected as $statusValue => $row) {
            $status = TransactionStatus::from($statusValue);
            self::assertSame(
                $row,
                [
                    $policy->can($status, Decision::Approve),
                    $policy->can($status, Decision::Reject),
                    $policy->can($status, Decision::Return),
                ],
                "contracts/workflow.md: finance after a {$statusValue} transaction may [approve, reject, return]"
            );
        }
    }

    public function testCanApproveIsTheSameQuestionAsCanFinanceApprove(): void
    {
        // The two names used to answer different questions, which is how the wrong one got used.
        $policy = new FinanceDecisionPolicy();
        foreach (TransactionStatus::cases() as $status) {
            self::assertSame(
                $policy->canFinanceApprove($status),
                $policy->canApprove($status),
                "the two spellings must not disagree for {$status->value}"
            );
        }
    }

    public function testAssertCanDecideNamesTheDecisionItRefused(): void
    {
        $policy = new FinanceDecisionPolicy();

        // Rejecting a senior-rejected transaction is permitted, so the *approve* guard must not be
        // what refuses it. The message has to name the decision asked about, or a caller that was
        // refused a rejection is left hunting for an approval problem.
        $policy->assertCanDecide(TransactionStatus::SeniorRejected, Decision::Reject);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Finance cannot APPROVE a transaction in status SENIOR_REJECTED');

        $policy->assertCanDecide(TransactionStatus::SeniorRejected, Decision::Approve);
    }

    public function testSeniorRejectedForbidsFinanceApprove(): void
    {
        $policy = new FinanceDecisionPolicy();

        // Finance CANNOT approve senior-rejected transaction
        self::assertFalse($policy->canFinanceApprove(TransactionStatus::SeniorRejected));
        
        // But can reject or return
        self::assertTrue($policy->canFinanceReject(TransactionStatus::SeniorRejected));
        self::assertTrue($policy->canFinanceReturn(TransactionStatus::SeniorRejected));
    }

    public function testFinanceAttemptToApproveSeniorRejectedReturnsForbidden(): void
    {
        $policy = new FinanceDecisionPolicy();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Finance cannot APPROVE a transaction in status SENIOR_REJECTED (senior-rejected)');

        $policy->assertCanApprove(TransactionStatus::SeniorRejected);
    }

    public function testPendingRejectedHasNoBalanceEffect(): void
    {
        // Transactions that are pending or rejected should not affect balance
        $policy = new FinanceDecisionPolicy();

        // These statuses should not allow balance-affecting approve
        self::assertFalse($policy->canFinanceApprove(TransactionStatus::AwaitingCostCenterReview));
        self::assertFalse($policy->canFinanceApprove(TransactionStatus::CostCenterApproved));
        self::assertFalse($policy->canFinanceApprove(TransactionStatus::CostCenterRejected));
        self::assertFalse($policy->canFinanceApprove(TransactionStatus::AwaitingSeniorReview));
        self::assertFalse($policy->canFinanceApprove(TransactionStatus::AwaitingFinanceReview));
        self::assertFalse($policy->canFinanceApprove(TransactionStatus::AwaitingPurchaserCorrection));
    }

    public function testNegativeBalancesPermitted(): void
    {
        // Approved consumption exceeding account balance results in negative balance
        $account = PettyCashAccount::create('acc-1', 'user-1', $this->currency);
        $account->balanceMinor = 10000; // Small balance
        $account->active = true;

        $amount = Money::positive(50000, CurrencyCode::fromString('IRR')); // Larger than balance
        $movement = AccountMovement::createConsumption('move-1', $amount, 'Test expense', $account, 'rev-1', 'dec-1');

        // Movement should be created successfully even if it results in negative balance
        self::assertEquals(-40000, $movement->balanceAfter()->minorUnits());
        self::assertEquals(50000, $movement->amount()->minorUnits());
    }

    public function testAccountMovementConsumptionReducesBalance(): void
    {
        $amount = Money::positive(50000, CurrencyCode::fromString('IRR'));
        $movement = AccountMovement::createConsumption('move-1', $amount, 'Test expense', $this->account, 'rev-1', 'dec-1');

        self::assertEquals(950000, $movement->balanceAfter()->minorUnits()); // 1,000,000 - 50,000
        self::assertEquals('CONSUMPTION', $movement->type()->value);
    }

    public function testAccountMovementChargeIncreasesBalance(): void
    {
        $amount = Money::positive(200000, CurrencyCode::fromString('IRR'));
        $movement = AccountMovement::createCharge('move-2', $amount, 'Funding', $this->account);

        self::assertEquals(1200000, $movement->balanceAfter()->minorUnits()); // 1,000,000 + 200,000
        self::assertEquals('CHARGE', $movement->type()->value);
    }

    public function testAccountMovementUniqueCommandId(): void
    {
        $amount = Money::positive(50000, CurrencyCode::fromString('IRR'));
        $movement1 = AccountMovement::createConsumption('move-1', $amount, 'Test 1', $this->account, 'rev-1', 'dec-1');
        $movement2 = AccountMovement::createConsumption('move-2', $amount, 'Test 2', $this->account, 'rev-2', 'dec-2');

        self::assertNotEquals($movement1->commandId(), $movement2->commandId());
    }

    public function testAccountMovementUniqueConsumptionSource(): void
    {
        $amount = Money::positive(50000, CurrencyCode::fromString('IRR'));
        $movement = AccountMovement::createConsumption('move-1', $amount, 'Test', $this->account, 'rev-1', 'dec-1');

        self::assertNotNull($movement->sourceRevisionId());
    }
}