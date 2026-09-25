<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Port\CurrentUser;

final class InMemoryCurrentUser implements CurrentUser
{
    private string $uid;

    public function __construct(string $uid = 'test-user')
    {
        $this->uid = $uid;
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function setUid(string $uid): void
    {
        $this->uid = $uid;
    }
}