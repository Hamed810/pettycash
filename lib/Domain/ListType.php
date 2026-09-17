<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain;

final class ListType {
    public const REGULAR = 'REGULAR';
    public const BUSINESS_TRIP = 'BUSINESS_TRIP';

    public const ALL = [
        self::REGULAR,
        self::BUSINESS_TRIP,
    ];

    private function __construct() {}
}
