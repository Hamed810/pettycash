<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Port\AdministratorCheck;

final class InMemoryAdministratorCheck implements AdministratorCheck
{
    public function isAdministrator(string $uid): bool
    {
        return $uid === 'test-admin';
    }
}