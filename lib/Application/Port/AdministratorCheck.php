<?php
declare(strict_types=1);
namespace OCA\PettyCash\Application\Port;
interface AdministratorCheck { public function isAdministrator(string $uid): bool; }
