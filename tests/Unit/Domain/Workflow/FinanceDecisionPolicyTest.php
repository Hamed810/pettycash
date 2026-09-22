<?php
declare(strict_types=1);
namespace OCA\PettyCash\Tests\Unit\Domain\Workflow;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\FinanceDecisionPolicy;
use PHPUnit\Framework\TestCase;
final class FinanceDecisionPolicyTest extends TestCase { public function testSeniorRejectionForbidsFinanceApproval(): void { $p = new FinanceDecisionPolicy(); self::assertTrue($p->allows(Decision::Approve, Decision::Approve)); self::assertFalse($p->allows(Decision::Reject, Decision::Approve)); self::assertTrue($p->allows(Decision::Reject, Decision::Reject)); } }
