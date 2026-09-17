<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain;

final class DestinationType {
    public const PROJECT = 'PROJECT';
    public const OFFICE = 'OFFICE';
    public const MARKETING = 'MARKETING';
    public const OTHER = 'OTHER';

    public const ALL = [
        self::PROJECT,
        self::OFFICE,
        self::MARKETING,
        self::OTHER,
    ];

    /** Destinations a Business Trip Cost List may reference. */
    public const NON_PROJECT = [
        self::OFFICE,
        self::MARKETING,
        self::OTHER,
    ];

    private function __construct() {}
}
