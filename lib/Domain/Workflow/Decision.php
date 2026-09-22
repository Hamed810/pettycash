<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Workflow;
enum Decision: string { case Approve = 'APPROVE'; case Reject = 'REJECT'; case Return = 'RETURN'; }
