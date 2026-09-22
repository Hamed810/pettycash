<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Shared\Enum;
enum Role: string { case Purchaser = 'PURCHASER'; case CostCenterManager = 'COST_CENTER_MANAGER'; case SeniorManager = 'SENIOR_MANAGER'; case Finance = 'FINANCE'; case Administrator = 'ADMINISTRATOR'; }
