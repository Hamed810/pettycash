<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Port\AuditWriter;
use RuntimeException;

/**
 * An audit writer that refuses to write.
 *
 * This exists to prove the half of FR-017's atomicity that a happy-path test cannot: that a
 * configuration mutation and its audit record are one unit, so an audit that cannot be persisted
 * must leave the mutation uncommitted too.
 *
 * `InMemoryAuditWriter` accepts everything, and `InMemoryTransactionManager` runs its callback with no
 * rollback at all, so a suite built from those two can only ever show that an append was *attempted*.
 * The real rollback needs the real `NextcloudTransactionManager` against real MariaDB, which is what
 * `roundtrip-runner.php` does; this double supplies the failure the rollback has to survive.
 */
final class FailingAuditWriter implements AuditWriter
{
    /**
     * Payloads handed to the writer before it began refusing, so a test can assert that a refusal
     * happened at the intended point rather than before any append was reached at all.
     *
     * @var list<array<string,mixed>>
     */
    private array $attempted = [];

    private bool $failing = true;

    public function __construct(private readonly AuditWriter $inner) {}

    /** @param array<string,mixed> $event */
    public function append(array $event): void
    {
        $this->attempted[] = $event;
        if ($this->failing) {
            throw new RuntimeException('The transaction audit stream refused this event');
        }
        $this->inner->append($event);
    }

    /** @param array<string,mixed> $event */
    public function appendConfiguration(array $event): void
    {
        $this->attempted[] = $event;
        if ($this->failing) {
            throw new RuntimeException('The configuration audit stream refused this event');
        }
        $this->inner->appendConfiguration($event);
    }

    /**
 * FR-031's unauthenticated viewer path.
 *
     * Added with the port method rather than left abstract, because the point of this double is that
     * *every* writer path fails identically — a test that only ever exercised the transaction stream would
     * not know whether the configuration stream behaves the same way.
     *
     * @param array<string,mixed> $event
     */
    public function appendConfigurationAsViewer(string $viewerUid, array $event): void
    {
        $this->attempted[] = $event;
        if ($this->failing) {
            throw new RuntimeException('The configuration audit stream refused this event');
        }
        $this->inner->appendConfigurationAsViewer($viewerUid, $event);
    }

    /** Stop refusing, so a test can prove the same path succeeds once the stream recovers. */
    public function recover(): void
    {
        $this->failing = false;
    }

    /** @return list<array<string,mixed>> */
    public function attemptedEvents(): array
    {
        return $this->attempted;
    }
}
