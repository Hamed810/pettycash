<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Account;
use InvalidArgumentException;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;
final readonly class AccountMovement { public function __construct(public string $id,public MovementType $type,public int $amountMinor,public int $balanceAfterMinor,public string $commandId,public ?string $sourceRevisionId=null){if($amountMinor<=0)throw new InvalidArgumentException('Movement amount must be positive');if($type===MovementType::Consumption&&$sourceRevisionId===null)throw new InvalidArgumentException('Consumption source is required');} public function signedAmount():int{return $this->type===MovementType::Charge?$this->amountMinor:-$this->amountMinor;} }
