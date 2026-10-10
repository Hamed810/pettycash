<?php
declare(strict_types=1);
namespace OCA\PettyCash\Application\Port;
interface UserDirectory { public function exists(string $uid): bool; public function displayName(string $uid): string; }
