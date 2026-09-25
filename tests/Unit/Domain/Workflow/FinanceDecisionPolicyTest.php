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
        $this->expectExceptionMessage('Finance cannot approve a senior-rejected transaction');

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
        $movement = AccountMovement::createConsumption('move-1', $amount, 'Test expense', $account, 'rev-1');

        // Movement should be created successfully even if it results in negative balance
        self::assertEquals(-40000, $movement->balanceAfter()->minorUnits());
        self::assertEquals(50000, $movement->amount()->minorUnits());
    }

    public function testAccountMovementConsumptionReducesBalance(): void
    {
        $amount = Money::positive(50000, CurrencyCode::fromString('IRR'));
        $movement = AccountMovement::createConsumption('move-1', $amount, 'Test expense', $this->account, 'rev-1');

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
        $movement1 = AccountMovement::createConsumption('move-1', $amount, 'Test 1', $this->account, 'rev-1');
        $movement2 = AccountMovement::createConsumption('move-2', $amount, 'Test 2', $this->account, 'rev-2');

        self::assertNotEquals($movement1->commandId(), $movement2->commandId());
    }

    public function testAccountMovementUniqueConsumptionSource(): void
    {
        $amount = Money::positive(50000, CurrencyCode::fromString('IRR'));
        $movement = AccountMovement::createConsumption('move-1', $amount, 'Test', $this->account, 'rev-1');

        self::assertNotNull($movement->sourceRevisionId());
    }
}