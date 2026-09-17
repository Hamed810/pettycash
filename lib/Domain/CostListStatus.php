<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain;

/**
 * v2.0.0: simplified from OPEN/M1_REVIEW/M2_REVIEW/ACCOUNTING/PROCESSED.
 * Approval routing is now per-transaction (see TransactionStatus +
 * DecisionService), so a list can contain transactions at different
 * stages simultaneously -- "M1_REVIEW"/"M2_REVIEW" no longer describe
 * a single, list-wide truth. SUBMITTED covers the entire in-review
 * period; per-transaction status is the source of detail.
 */
final class CostListStatus {
    public const OPEN = 'OPEN';
    public const SUBMITTED = 'SUBMITTED';
    public const ACCOUNTING = 'ACCOUNTING';
    public const PROCESSED = 'PROCESSED';

    public const ALL = [
        self::OPEN,
        self::SUBMITTED,
        self::ACCOUNTING,
        self::PROCESSED,
    ];

    private function __construct() {}
}
