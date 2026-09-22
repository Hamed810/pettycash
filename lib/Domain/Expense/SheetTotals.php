<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Expense;
use OCA\PettyCash\Domain\Workflow\Decision;
final readonly class SheetTotals { public function __construct(public int $current,public int $approved,public int $rejected,public int $pending,public int $managerApproved,public int $seniorApproved,public int $financeApproved){} /** @param list<array{amount:int,latest:?Decision,manager:?Decision,senior:?Decision,finance:?Decision}> $rows */ public static function fromRows(array $rows):self{$current=$approved=$rejected=$pending=$manager=$senior=$finance=0;foreach($rows as $r){$a=$r['amount'];$current+=$a;if($r['latest']===Decision::Approve)$approved+=$a;elseif($r['latest']===Decision::Reject)$rejected+=$a;else$pending+=$a;if($r['manager']===Decision::Approve)$manager+=$a;if($r['senior']===Decision::Approve)$senior+=$a;if($r['finance']===Decision::Approve)$finance+=$a;}return new self($current,$approved,$rejected,$pending,$manager,$senior,$finance);} }
