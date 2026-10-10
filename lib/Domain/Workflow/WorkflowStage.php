<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Workflow;
enum WorkflowStage: string { case Draft = 'DRAFT'; case CostCenter = 'COST_CENTER'; case Correction = 'CORRECTION'; case Senior = 'SENIOR'; case Finance = 'FINANCE'; case Final = 'FINAL'; }
