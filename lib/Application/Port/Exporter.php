<?php
declare(strict_types=1);
namespace OCA\PettyCash\Application\Port;
interface Exporter { /** @param iterable<array<string,mixed>> $rows */ public function export(iterable $rows): string; public function mediaType(): string; }
