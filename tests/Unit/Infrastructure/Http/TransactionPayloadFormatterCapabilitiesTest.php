<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Infrastructure\Http;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Infrastructure\Http\TransactionPayloadFormatter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryWorkflowDecisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * T099: `allowedActions` follows the stage the transaction is actually in.
 *
 * The finance decision policy (`FinanceDecisionPolicy`) answers senior-outcome
 * rules, so applying it at every stage offered a cost-center manager an empty
 * decision form: a cost-center transaction is never senior-approved, and all
 * three capabilities read false. The management stages follow the command
 * path's rule instead — a reviewer who may decide may record any of approve,
 * reject or return — while finance keeps the senior-outcome matrix (FR-039).
 */
final class TransactionPayloadFormatterCapabilitiesTest extends TestCase
{
    public function testManagerAtCostCenterStageMayRecordAllThreeDecisions(): void
    {
        $transaction = $this->submittedTransaction(
            WorkflowStage::CostCenter,
            TransactionStatus::AwaitingCostCenterReview
        );

        $actions = $this->formatter('manager-1', true)->detail($transaction)['allowedActions'];

        self::assertTrue($actions['canApprove'], 'a cost-center manager may approve');
        self::assertTrue($actions['canReject'], 'a cost-center manager may reject');
        self::assertTrue($actions['canReturn'], 'a cost-center manager may return for correction');
    }

    public function testSeniorManagerAtSeniorStageMayRecordAllThreeDecisions(): void
    {
        $transaction = $this->submittedTransaction(
            WorkflowStage::Senior,
            TransactionStatus::AwaitingSeniorReview
        );

        $actions = $this->formatter('senior-1', true, Role::SeniorManager)->detail($transaction)['allowedActions'];

        self::assertTrue($actions['canApprove'], 'a senior manager may approve');
        self::assertTrue($actions['canReject'], 'a senior manager may reject');
        self::assertTrue($actions['canReturn'], 'a senior manager may return for correction');
    }

    public function testFinanceKeepsTheSeniorOutcomeMatrix(): void
    {
        $approved = $this->submittedTransaction(WorkflowStage::Finance, TransactionStatus::SeniorApproved);
        $actions = $this->formatter('finance-1', true, Role::Finance)->detail($approved)['allowedActions'];
        self::assertTrue($actions['canApprove']);
        self::assertTrue($actions['canReject']);
        self::assertTrue($actions['canReturn']);

        $rejected = $this->submittedTransaction(WorkflowStage::Finance, TransactionStatus::SeniorRejected);
        $actions = $this->formatter('finance-1', true, Role::Finance)->detail($rejected)['allowedActions'];
        self::assertFalse($actions['canApprove'], 'FR-039 forbids approving a senior-rejected transaction');
        self::assertTrue($actions['canReject']);
        self::assertTrue($actions['canReturn']);
    }

    public function testOutOfScopeReaderIsOfferedNoDecision(): void
    {
        $transaction = $this->submittedTransaction(
            WorkflowStage::CostCenter,
            TransactionStatus::AwaitingCostCenterReview
        );

        $actions = $this->formatter('stranger-1', false)->detail($transaction)['allowedActions'];

        self::assertFalse($actions['canApprove']);
        self::assertFalse($actions['canReject']);
        self::assertFalse($actions['canReturn']);
    }

    public function testDraftOwnerIsOfferedEditsButNoDecisions(): void
    {
        $revision = TransactionRevision::draft(
            'rev-draft',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $transaction = new ExpenseTransaction('tx-draft', 'purchaser-1', $revision);

        $actions = $this->formatter('purchaser-1', false)->detail($transaction)['allowedActions'];

        self::assertTrue($actions['canEdit']);
        self::assertFalse($actions['canApprove']);
        self::assertFalse($actions['canReject']);
        self::assertFalse($actions['canReturn']);
    }

    // ===== helpers -----------------------------------------------------------

    private function formatter(
        string $actorUid,
        bool $assignManagerRole,
        ?Role $role = null
    ): TransactionPayloadFormatter {
        $assignments = new InMemoryRoleAssignmentRepository();
        if ($assignManagerRole) {
            $role ??= Role::CostCenterManager;
            $assignments->save(RoleAssignment::create(
                $actorUid,
                $role,
                $role === Role::CostCenterManager || $role === Role::SeniorManager ? 7 : null,
                new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
                null
            ));
        }

        $costCenters = new InMemoryCostCenterRepository();
        $costCenter = CostCenter::create(
            'CC-1',
            'Cost Center',
            'Cost Center',
            new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
            null
        );
        $costCenter->id = 7;
        $costCenters->save($costCenter);

        return new TransactionPayloadFormatter(
            new InMemoryWorkflowDecisionRepository(),
            new InMemoryEvidenceRepository(),
            $costCenters,
            new InMemoryCurrencyRepository(),
            $assignments,
            new InMemoryExpenseSheetRepository(),
            new InMemoryCurrentUser($actorUid)
        );
    }

    private function submittedTransaction(
        WorkflowStage $stage,
        TransactionStatus $status
    ): ExpenseTransaction {
        $revision = TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('Description', 'cc-1', 'CC-1', 'Cost Center', 7, 'Travel', 9, false);

        $transaction = new ExpenseTransaction('tx-1', 'purchaser-1', $revision);
        $transaction->stage = $stage;
        $transaction->status = $status;

        return $transaction;
    }
}
