<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain;

final class OcrStatus {
    public const QUEUED = 'QUEUED';
    public const PROCESSING = 'PROCESSING';
    public const SUCCESS = 'SUCCESS';
    public const PARTIAL = 'PARTIAL';
    public const FAILED = 'FAILED';
    public const NOT_REQUIRED = 'NOT_REQUIRED';

    public const ALL = [
        self::QUEUED,
        self::PROCESSING,
        self::SUCCESS,
        self::PARTIAL,
        self::FAILED,
        self::NOT_REQUIRED,
    ];

    private function __construct() {}
}
