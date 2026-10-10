<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Shared\Enum;
enum MovementType: string { case Charge = 'CHARGE'; case Consumption = 'CONSUMPTION'; }
