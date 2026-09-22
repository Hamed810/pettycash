<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Workflow;
enum TransactionStatus: string { case Draft = 'DRAFT'; case Pending = 'PENDING'; case Returned = 'RETURNED'; case Approved = 'APPROVED'; case Rejected = 'REJECTED'; }
