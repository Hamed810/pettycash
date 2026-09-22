<?php
declare(strict_types=1);
namespace OCA\PettyCash\Infrastructure\Nextcloud;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCP\IUserSession;
use RuntimeException;
final readonly class NextcloudCurrentUser implements CurrentUser { public function __construct(private IUserSession $session) {} public function uid(): string { return $this->session->getUser()?->getUID() ?? throw new RuntimeException('Authenticated user required'); } }
