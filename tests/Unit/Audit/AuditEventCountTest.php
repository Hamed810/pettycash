<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Audit;

use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use PHPUnit\Framework\TestCase;

/**
 * T319: the `total` in `AuditPage` needs a count the repository does not expose.
 *
 * ## Why a count is needed at all
 *
 * `contracts/ocs-api.yaml:581` declares `GET /transactions/{transactionId}/audit` as returning
 * `AuditPage`, which at `:913-922` is `PageMeta` — `page`, `pageSize`, `total`, all `required` —
 * plus `items`. `AuditEventRepository::findByTransactionId()` takes an offset and a limit, so
 * `items` was always obtainable. **`total` was not**: the port had no count method, and the
 * controller called the find with one argument.
 *
 * The tempting implementation is `count($items)`. It is wrong in a way that looks correct, and this
 * test exists to kill it: a count of the *page* makes one page's length read as the whole history,
 * a paginated client stops after page 1 having silently missed every later event, and the endpoint
 * still answers 200 with all four required keys present. US6/AC1 requires every official event to
 * be reachable, so this is the field that makes the rest reachable.
 *
 * `AccountControllerTest.php:228` already pins the same distinction for its endpoint ("total counts
 * the ledger, not the page"). This is the same rule, asserted at the port rather than through HTTP.
 *
 * ## Why the ordering matters for the walk
 *
 * `findByTransactionId` returns newest-first (`orderBy('occurred_at', 'DESC')`). The paged walk in
 * the contract test depends on that being a total order, otherwise the same event can appear on two
 * pages — which is why `total` being a plain count is not sufficient to make paging safe.
 */
final class AuditEventCountTest extends TestCase
{
    private InMemoryAuditEventRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryAuditEventRepository();
    }

    private function seedTransactionEvents(int $transactionDbId, int $howMany): void
    {
        for ($i = 0; $i < $howMany; $i++) {
            $this->repository->save($this->event(
                id: 'ev-' . $transactionDbId . '-' . $i,
                transactionDbId: $transactionDbId,
                occurredAt: new \DateTimeImmutable('2026-09-29T00:00:00+00:00', new \DateTimeZone('UTC'))
                    ->modify('+' . $i . ' minutes'),
            ));
        }
    }

    private function event(string $id, int $transactionDbId, \DateTimeImmutable $occurredAt): AuditEvent
    {
        return new AuditEvent(
            id: $id,
            sheetId: 7,
            transactionId: $transactionDbId,
            revisionId: 42,
            stage: 'COST_CENTER',
            decisionId: null,
            entityType: '',
            entityId: null,
            eventType: 'TRANSACTION_CREATED',
            actorUid: 'officer1',
            actorName: 'Officer One',
            actorRole: 'PURCHASER',
            explanation: null,
            beforeJson: '{}',
            afterJson: '{}',
            commandId: 'cmd-1',
            occurredAt: $occurredAt,
        );
    }

    /**
     * The count must exceed any single page, which is the property `count($items)` cannot have.
     *
     * A page size of 2 against 5 events is the smallest setup where the two implementations
     * disagree: `count($items)` answers 2, the real count answers 5.
     */
    public function testTheCountCoversEveryEventAndNotJustThePage(): void
    {
        $this->seedTransactionEvents(1, 5);

        $page = $this->repository->findByTransactionId(1, 0, 2);
        self::assertCount(2, $page, 'the fixture must produce a page smaller than the set');

        self::assertSame(
            5,
            $this->repository->countByTransactionId(1),
            'the count must describe the whole scoped set. A count of the returned page answers 2 here, '
            . 'which tells a client the history ends two events in and makes every later event '
            . 'unreachable'
        );
    }

    public function testTheCountIsScopedToTheTransaction(): void
    {
        $this->seedTransactionEvents(1, 3);
        $this->seedTransactionEvents(2, 7);

        self::assertSame(3, $this->repository->countByTransactionId(1));
        self::assertSame(7, $this->repository->countByTransactionId(2));
    }

    public function testAnUnknownTransactionCountsZeroRatherThanFailing(): void
    {
        self::assertSame(
            0,
            $this->repository->countByTransactionId(999),
            'no events is zero, not an error. The endpoint reaches this whenever a transaction exists '
            . 'with no recorded history yet, which is every freshly created draft'
        );
    }

    public function testCountingNeverMutatesTheStoredEvents(): void
    {
        $this->seedTransactionEvents(1, 2);

        $before = $this->repository->getEvents();
        $this->repository->countByTransactionId(1);
        $after = $this->repository->getEvents();

        self::assertCount(
            count($before),
            $after,
            'a count is a read; it must not add, remove, or alter a record. Audit events are append-only '
            . 'per FR-053, and a count that wrote would be a mutation reachable from a GET'
        );
    }

    /**
     * The port must actually declare the method.
     *
     * Asserted on the interface rather than on the double, because the double is the one thing that
     * is guaranteed to have it: a repository implementation that forgot the method would satisfy
     * every behavioural test above and still break the real `DbAuditEventRepository`.
     */
    public function testThePortDeclaresTheCount(): void
    {
        self::assertTrue(
            method_exists(AuditEventRepository::class, 'countByTransactionId'),
            'AuditEventRepository must declare countByTransactionId(). T319 needs the count in '
            . 'production, and a method that exists only on the in-memory double would let every '
            . 'behavioural test pass while the real repository fatals'
        );
    }
}
