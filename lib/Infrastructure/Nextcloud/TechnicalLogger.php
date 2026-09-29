<?php
declare(strict_types=1);
namespace OCA\PettyCash\Infrastructure\Nextcloud;
use Psr\Log\LoggerInterface;
final readonly class TechnicalLogger { public function __construct(private LoggerInterface $logger) {} public function unexpected(\Throwable $error, ?string $commandId = null): void { $this->logger->error('PettyCash operation failed', ['app' => 'pettycash', 'commandId' => $commandId, 'exception' => $error]); }

    /**
     * Record something an operator needs to know that is not an exception.
     *
     * Added because `ReportController::accountsByDbIds()` called `$this->logger->log(...)` on a class
     * that only had `unexpected()`. PHP resolves that at call time, not compile time, so the file
     * parsed, the type checker flagged it, and the endpoint answered 500 with a
     * `Call to undefined method` **only when the branch was reached** -- a charged account that no
     * longer resolves. That is the worst shape a latent defect can have: a code path exercised by
     * nothing, failing on exactly the data fault an operator would most want logged.
     *
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, ['app' => 'pettycash'] + $context);
    }
}
