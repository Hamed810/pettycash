<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Port\UserDirectory;

final class InMemoryUserDirectory implements UserDirectory
{
    /**
     * @var array<string, string>
     */
    private array $users = [];

    public function __construct()
    {
        $this->users['test-user'] = 'Test User';
        $this->users['test-manager'] = 'Test Manager';
        $this->users['test-senior'] = 'Test Senior';
        $this->users['test-finance'] = 'Test Finance';
        $this->users['test-admin'] = 'Test Admin';
    }

    public function exists(string $uid): bool
    {
        return isset($this->users[$uid]);
    }

    public function displayName(string $uid): string
    {
        return $this->users[$uid] ?? $uid;
    }

    public function addUser(string $uid, string $displayName): void
    {
        $this->users[$uid] = $displayName;
    }
}