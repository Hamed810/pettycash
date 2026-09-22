<?php
declare(strict_types=1);
namespace OCA\PettyCash\Application\Port;
interface PrivateFileStore { public function put(string $key, string $content): void; public function read(string $key): string; public function deleteDraft(string $key): void; public function exists(string $key): bool; }
