<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Workflow;
final class FinanceDecisionPolicy { public function allows(Decision $senior,Decision $finance):bool{return !($senior===Decision::Reject&&$finance===Decision::Approve);} }
