<?php
declare(strict_types=1);
namespace OCA\PettyCash\Application\Security;
use OCA\PettyCash\Domain\Shared\Enum\Role;
final readonly class AuthorizationContext {
    /** @param list<Role> $roles @param list<string> $costCenterIds */
    public function __construct(public string $actorUid, public string $ownerUid, public array $roles, public array $costCenterIds, public bool $administrator) {}
}
