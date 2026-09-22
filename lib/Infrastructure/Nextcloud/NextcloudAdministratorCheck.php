<?php
declare(strict_types=1);
namespace OCA\PettyCash\Infrastructure\Nextcloud;
use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCP\IGroupManager;
final readonly class NextcloudAdministratorCheck implements AdministratorCheck { public function __construct(private IGroupManager $groups) {} public function isAdministrator(string $uid): bool { return $this->groups->isAdmin($uid); } }
