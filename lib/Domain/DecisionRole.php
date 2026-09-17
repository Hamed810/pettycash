<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain;

final class DecisionRole {

    public const M1 = 'M1';

    public const M2 = 'M2';

    public const ACCOUNTANT = 'ACCOUNTANT';


    public const ALL = [
        self::M1,
        self::M2,
        self::ACCOUNTANT,
    ];


    private function __construct() {}
}