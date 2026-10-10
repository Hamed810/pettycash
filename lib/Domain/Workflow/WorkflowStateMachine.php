<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Workflow;
use LogicException;
final class WorkflowStateMachine { public function next(WorkflowStage $stage,Decision $decision,?Decision $seniorDecision=null):WorkflowStage{if($decision===Decision::Return)return WorkflowStage::Correction;return match($stage){WorkflowStage::CostCenter=>WorkflowStage::Senior,WorkflowStage::Senior=>WorkflowStage::Finance,WorkflowStage::Finance=>WorkflowStage::Final,default=>throw new LogicException('Decision is not allowed in this stage')};} }
