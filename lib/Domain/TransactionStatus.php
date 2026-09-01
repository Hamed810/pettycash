<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain;

final class TransactionStatus {
    public const DRAFT = 'DRAFT';
    public const PENDING_M1 = 'PENDING_M1';
    public const RETURNED_M1 = 'RETURNED_M1';
    public const REJECTED_M1 = 'REJECTED_M1';
    public const APPROVED_M1 = 'APPROVED_M1';
    public const PENDING_M2 = 'PENDING_M2';
    public const RETURNED_M2 = 'RETURNED_M2';
    public const REJECTED_M2 = 'REJECTED_M2';
    public const FINAL_APPROVED = 'FINAL_APPROVED';

    public const ALL = [
        self::DRAFT,
        self::PENDING_M1,
        self::RETURNED_M1,
        self::REJECTED_M1,
        self::APPROVED_M1,
        self::PENDING_M2,
        self::RETURNED_M2,
        self::REJECTED_M2,
        self::FINAL_APPROVED,
    ];

    private function __construct() {}
}
