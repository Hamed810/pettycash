<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain;

final class AttachmentType {
    public const RECEIPT = 'RECEIPT';
    public const HIRING_PERMIT = 'HIRING_PERMIT';
    public const ATTENDANCE_EVIDENCE = 'ATTENDANCE_EVIDENCE';
    public const OTHER = 'OTHER';

    public const ALL = [
        self::RECEIPT,
        self::HIRING_PERMIT,
        self::ATTENDANCE_EVIDENCE,
        self::OTHER,
    ];

    private function __construct() {}
}
