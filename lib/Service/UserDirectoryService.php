<?php

declare(strict_types=1);

namespace OCA\PettyCash\Service;

use OCP\IUserManager;

/**
 * Live Nextcloud user directory lookup, backing the manager/owner
 * assignment dropdowns (per Administration Guide v2.0.0: assignment
 * is always via dropdown of real accounts, never free text).
 */
final class UserDirectoryService {
    public function __construct(
        private IUserManager $userManager,
    ) {}

    /** @return list<array{userId:string,displayName:string}> */
    public function search(string $query, int $limit = 20): array {
        $limit = max(1, min($limit, 50));
        $results = [];

        foreach ($this->userManager->searchDisplayName($query, $limit) as $user) {
            $results[] = [
                'userId' => $user->getUID(),
                'displayName' => $user->getDisplayName(),
            ];
        }

        return $results;
    }

    public function exists(string $userId): bool {
        return $this->userManager->userExists($userId);
    }
}
