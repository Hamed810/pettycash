<?php
declare(strict_types=1);
namespace OCA\PettyCash\Application\Port;
interface AuditWriter { /** @param array<string,mixed> $event */ public function append(array $event): void; }
