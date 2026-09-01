<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain;

final class ProjectRole {
    public const PURCHASER = 'PURCHASER';
    public const MANAGER1 = 'MANAGER1';
    public const MANAGER2 = 'MANAGER2';
    public const ACCOUNTANT = 'ACCOUNTANT';

    public const ALL = [
        self::PURCHASER,
        self::MANAGER1,
        self::MANAGER2,
        self::ACCOUNTANT,
    ];

    private function __construct() {}
}
