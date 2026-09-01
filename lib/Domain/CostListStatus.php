<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain;

final class CostListStatus {
    public const OPEN = 'OPEN';
    public const M1_REVIEW = 'M1_REVIEW';
    public const M2_REVIEW = 'M2_REVIEW';
    public const ACCOUNTING = 'ACCOUNTING';
    public const PROCESSED = 'PROCESSED';

    public const ALL = [
        self::OPEN,
        self::M1_REVIEW,
        self::M2_REVIEW,
        self::ACCOUNTING,
        self::PROCESSED,
    ];

    private function __construct() {}
}
