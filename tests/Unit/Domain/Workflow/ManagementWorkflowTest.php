<?php
declare(strict_types=1);
namespace OCA\PettyCash\Tests\Unit\Domain\Workflow;
use InvalidArgumentException;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Workflow\WorkflowStateMachine;
use PHPUnit\Framework\TestCase;
final class ManagementWorkflowTest extends TestCase {
 public function testApprovalsAdvanceAndReturnRestartsCorrection(): void { $s = new WorkflowStateMachine(); self::assertSame(WorkflowStage::Senior, $s->next(WorkflowStage::CostCenter, Decision::Approve)); self::assertSame(WorkflowStage::Correction, $s->next(WorkflowStage::Senior, Decision::Return)); }
 public function testRejectAndReturnRequireExplanation(): void { $this->expectException(InvalidArgumentException::class); \OCA\PettyCash\Domain\Workflow\WorkflowDecision::create('id', 't', 'r', WorkflowStage::CostCenter, Decision::Reject, null, 'cmd'); }
}
