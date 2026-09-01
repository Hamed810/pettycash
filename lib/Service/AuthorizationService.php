<?php

declare(strict_types=1);
namespace OCA\PettyCash\Service;

use OCA\PettyCash\Db\ProjectMemberMapper;
use OCP\IGroupManager;
use OCP\IUserSession;

final class AuthorizationService {
    public function __construct(
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private ProjectMemberMapper $memberMapper,
    ) {}

    public function currentUserId(): ?string {
        return $this->userSession->getUser()?->getUID();
    }

    public function isAdmin(?string $userId = null): bool {
        $userId ??= $this->currentUserId();
        return $userId !== null && $this->groupManager->isAdmin($userId);
    }

    public function canAccessProject(int $projectId, ?string $userId = null): bool {
        $userId ??= $this->currentUserId();
        if ($userId === null) return false;
        if ($this->isAdmin($userId)) return true;
        return count($this->memberMapper->findForUser($projectId, $userId)) > 0;
    }

    /** @param list<string> $roles */
    public function hasAnyProjectRole(int $projectId, array $roles, ?string $userId = null): bool {
        $userId ??= $this->currentUserId();
        if ($userId === null) return false;
        if ($this->isAdmin($userId)) return true;
        foreach ($this->memberMapper->findForUser($projectId, $userId) as $member) {
            if (in_array($member->getRole(), $roles, true)) return true;
        }
        return false;
    }
}
