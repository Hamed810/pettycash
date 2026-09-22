<?php
declare(strict_types=1);
namespace OCA\PettyCash\Infrastructure\Nextcloud;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCP\IUserManager;
final readonly class NextcloudUserDirectory implements UserDirectory { public function __construct(private IUserManager $users) {} public function exists(string $uid): bool { return $this->users->userExists($uid); } public function displayName(string $uid): string { return $this->users->get($uid)?->getDisplayName() ?? $uid; } }
