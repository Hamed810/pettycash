<?php
declare(strict_types=1);
namespace OCA\PettyCash\Infrastructure\Nextcloud;
use Psr\Log\LoggerInterface;
final readonly class TechnicalLogger { public function __construct(private LoggerInterface $logger) {} public function unexpected(\Throwable $error, ?string $commandId = null): void { $this->logger->error('PettyCash operation failed', ['app' => 'pettycash', 'commandId' => $commandId, 'exception' => $error]); } }
