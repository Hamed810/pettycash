<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Shared;

final readonly class ActorSnapshot
{
    public function __construct(public string $uid, public string $displayName, public string $role) {}

    public function userId(): string
    {
        return $this->uid;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function role(): string
    {
        return $this->role;
    }
}
