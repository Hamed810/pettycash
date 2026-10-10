<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Workflow;
enum SheetStatus: string { case Open = 'OPEN'; case CostCenter = 'COST_CENTER'; case Correction = 'CORRECTION'; case Senior = 'SENIOR'; case Finance = 'FINANCE'; case Completed = 'COMPLETED'; case CompletedWithRejections = 'COMPLETED_WITH_REJECTIONS'; }
