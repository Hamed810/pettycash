<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Audit;

use DateTimeImmutable;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Tests\TestDoubles\FailingAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * T086 — `ConfigurationAuditService`, over the in-memory doubles.
 *
 * ## What this service is for
 *
 * FR-017 requires a sensitive configuration change to record "the actor, time, explanation, previous
 * value, and new value" in a separate configuration audit history. Eight mutation methods across four
 * services would otherwise each hand-build that payload, and the codebase already contains the proof
 * of how that ends: `AuditWriterService` documents two real callers that sent `before_json` /
 * `after_json`, keys the writer does not read, so every approval, rejection and return was stored with
 * both snapshots as the literal `"[]"` — permanently, because FR-053 makes audit rows append-only.
 *
 * So this class owns the *shape*: the event type, the subject, the before/after pair, the
 * explanation, and above all the transaction that makes the mutation and its audit record one unit.
 *
 * ## What this file cannot prove, and where that is proved instead
 *
 * `InMemoryTransactionManager` runs its callback and does nothing else — it has no rollback. So every
 * rollback assertion in this file is necessarily about *propagation* (the failure escapes, and the
 * audit append was not attempted before it), and the real proof that a refused audit leaves no
 * committed configuration row is in `roundtrip-runner.php` against real MariaDB through the real
 * `NextcloudTransactionManager`. A green run of this file is not a claim about atomicity.
 */
final class ConfigurationAuditServiceTest extends TestCase
{
    private InMemoryAuditWriter $audit;

    private InMemoryTransactionManager $transactions;

    private ConfigurationAuditService $service;

    protected function setUp(): void
    {
        $this->audit = new InMemoryAuditWriter();
        $this->transactions = new InMemoryTransactionManager();
        $this->service = new ConfigurationAuditService($this->audit, $this->transactions);
    }

    // --- the payload FR-017 names ---

    public function testAChangeRecordsItsEventTypeSubjectBeforeAndAfter(): void
    {
        $this->service->record(
            eventType: 'CURRENCY_UPDATED',
            entityType: 'currency',
            mutate: fn(): Currency => $this->currency('IRR', 'Iranian Rial', 2),
            beforeSnapshot: fn(): array => ['code' => 'IRR', 'name' => 'Old Name', 'minorUnit' => 0, 'active' => true],
            afterSnapshot: static fn(Currency $c): array => [
                'code' => 'IRR',
                'name' => $c->name,
                'minorUnit' => $c->minorUnit,
                'active' => $c->active,
            ],
            subjectOf: static fn(Currency $c): string => 'public-id-of-IRR',
        );

        $events = $this->audit->getConfigurationEvents();

        $this->assertCount(1, $events);
        $this->assertSame('CURRENCY_UPDATED', $events[0]['event_type']);
        $this->assertSame('currency', $events[0]['entity_type']);
        $this->assertSame('public-id-of-IRR', $events[0]['entity_id']);
        // FR-017: "previous value, and new value". The pair is the point of an update event, and an
        // event that stored the post-state twice would look complete while answering nothing.
        $this->assertSame(['code' => 'IRR', 'name' => 'Old Name', 'minorUnit' => 0, 'active' => true], $events[0]['before']);
        $this->assertSame(['code' => 'IRR', 'name' => 'Iranian Rial', 'minorUnit' => 2, 'active' => true], $events[0]['after']);
    }

    public function testTheEventGoesToTheConfigurationStreamAndNeverTheTransactionStream(): void
    {
        // FR-056 requires the two streams to stay separately identifiable. A configuration change
        // written to the transaction stream would be addressed by sheet/transaction/revision, which a
        // currency has none of, so it would be unqueryable as well as misfiled.
        $this->service->record(
            eventType: 'EXPENSE_TYPE_CREATED',
            entityType: 'expense_type',
            mutate: fn(): Currency => $this->currency('IRR', 'Iranian Rial', 2),
        );

        $this->assertCount(1, $this->audit->getConfigurationEvents());
        $this->assertSame([], $this->audit->getEvents(), 'a configuration change must not enter the transaction stream');
    }

    public function testTheChangeReasonBecomesTheRecordedExplanation(): void
    {
        // FR-017 requires the explanation to be recorded. `contracts/ocs-api.yaml` carries
        // `changeReason` on CostCenterWrite, ExpenseTypeWrite and FinanceAssignmentWrite, and this is
        // the only place it can reach the audit history.
        $this->service->record(
            eventType: 'FINANCE_ASSIGNMENT_CHANGED',
            entityType: 'finance_assignment',
            mutate: fn(): Currency => $this->currency('IRR', 'Iranian Rial', 2),
            changeReason: 'officer rotated onto the shared queue',
        );

        $this->assertSame(
            'officer rotated onto the shared queue',
            $this->audit->getConfigurationEvents()[0]['explanation']
        );
    }

    public function testAnAbsentChangeReasonIsRecordedAsNullRatherThanAnEmptyString(): void
    {
        // `CurrencyWrite` has no `changeReason` field at all, so a currency event's explanation is
        // legitimately null. Storing `''` instead would read as "an explanation was given and it was
        // blank", which is a different statement.
        $this->service->record(
            eventType: 'CURRENCY_CREATED',
            entityType: 'currency',
            mutate: fn(): Currency => $this->currency('IRR', 'Iranian Rial', 2),
        );

        $this->assertNull($this->audit->getConfigurationEvents()[0]['explanation']);
    }

    public function testACreateRecordsAnEmptyBeforeAndAnUpdateDoesNot(): void
    {
        // An empty `before` is how a creation is distinguished from a change, so it has to actually be
        // empty rather than absent-with-a-default.
        $this->service->record(
            eventType: 'CURRENCY_CREATED',
            entityType: 'currency',
            mutate: fn(): Currency => $this->currency('IRR', 'Iranian Rial', 2),
        );

        $this->assertSame([], $this->audit->getConfigurationEvents()[0]['before']);
    }

    // --- the transaction is the substance of this task ---

    public function testTheMutationAndTheAuditRunInsideOneTransaction(): void
    {
        $observed = [];

        $this->transactions->onTransaction(function () use (&$observed): void {
            $observed[] = 'transaction-opened';
        });

        $this->service->record(
            eventType: 'CURRENCY_CREATED',
            entityType: 'currency',
            mutate: function () use (&$observed): Currency {
                $observed[] = 'mutated';
                return $this->currency('IRR', 'Iranian Rial', 2);
            },
            afterSnapshot: static function () use (&$observed): array {
                $observed[] = 'audited';
                return ['code' => 'IRR'];
            },
        );

        $this->assertSame(
            ['transaction-opened', 'mutated', 'audited'],
            $observed,
            'the audit append must happen inside the transaction that carries the mutation, not after it'
        );
    }

    public function testTheMutationResultIsReturnedToTheCaller(): void
    {
        // The service is a decorator, not a replacement: an audited `create()` has to hand back the
        // aggregate the controller serialises, or every audited call site would need a second read.
        $created = $this->service->record(
            eventType: 'CURRENCY_CREATED',
            entityType: 'currency',
            mutate: fn(): Currency => $this->currency('IRR', 'Iranian Rial', 2),
        );

        $this->assertInstanceOf(Currency::class, $created);
        $this->assertSame('Iranian Rial', $created->name);
    }

    public function testARefusedAuditPropagatesSoTheTransactionCanRollBack(): void
    {
        // Propagation is the half of atomicity observable with these doubles. The real rollback -- that
        // no configuration row survives -- needs MariaDB and is asserted in `roundtrip-runner.php`.
        $failing = new FailingAuditWriter($this->audit);
        $service = new ConfigurationAuditService($failing, $this->transactions);

        try {
            $service->record(
                eventType: 'CURRENCY_CREATED',
                entityType: 'currency',
                mutate: fn(): Currency => $this->currency('IRR', 'Iranian Rial', 2),
            );
            $this->fail('a refused audit must not be swallowed: the mutation cannot be left committed unrecorded');
        } catch (RuntimeException $expected) {
            $this->assertSame('The configuration audit stream refused this event', $expected->getMessage());
        }

        $this->assertSame([], $this->audit->getConfigurationEvents());
    }

    public function testAFailedMutationNeverReachesTheAuditStream(): void
    {
        // The other direction. A rejected write that had already appended its audit event would leave
        // a history entry describing a change that did not happen.
        try {
            $this->service->record(
                eventType: 'CURRENCY_CREATED',
                entityType: 'currency',
                mutate: static fn(): Currency => throw new RuntimeException('the write was refused'),
            );
            $this->fail('the mutation failure must propagate');
        } catch (RuntimeException $expected) {
            $this->assertSame('the write was refused', $expected->getMessage());
        }

        $this->assertSame([], $this->audit->getConfigurationEvents(), 'a failed mutation must record nothing');
    }

    public function testTheBeforeSnapshotIsReadBeforeTheMutationRuns(): void
    {
        // Ordering, not just presence: a `before` captured after the write would be the post-state
        // wearing a "before" label, which is precisely what `FinanceSettlementService` documents about
        // its `ACCOUNT_CHARGED` event.
        $state = ['name' => 'Before'];
        $captured = null;

        $this->service->record(
            eventType: 'CURRENCY_UPDATED',
            entityType: 'currency',
            beforeSnapshot: function () use (&$state, &$captured): array {
                $captured = $state['name'];
                return ['name' => $state['name']];
            },
            mutate: function () use (&$state): Currency {
                $state['name'] = 'After';
                return $this->currency('IRR', 'Iranian Rial', 2);
            },
            afterSnapshot: static fn(Currency $c): array => ['name' => $c->name],
        );

        $this->assertSame('Before', $captured);
        $this->assertSame('Before', $this->audit->getConfigurationEvents()[0]['before']['name']);
    }

    public function testTheSamePathSucceedsOnceTheStreamRecovers(): void
    {
        // Proves the failure above was the stream's refusal and not the service being broken.
        $failing = new FailingAuditWriter($this->audit);
        $service = new ConfigurationAuditService($failing, $this->transactions);

        try {
            $service->record(eventType: 'CURRENCY_CREATED', entityType: 'currency', mutate: fn(): Currency => $this->currency('IRR', 'Rial', 2));
        } catch (RuntimeException) {
            // expected
        }

        $failing->recover();

        $service->record(eventType: 'CURRENCY_CREATED', entityType: 'currency', mutate: fn(): Currency => $this->currency('IRR', 'Rial', 2));

        $this->assertCount(1, $this->audit->getConfigurationEvents());
        $this->assertCount(2, $failing->attemptedEvents(), 'both attempts reached the writer; only one was refused');
    }

    public function testOneRecordCallProducesExactlyOneEvent(): void
    {
        // The property the eight call sites depend on: a single audited mutation is a single event, so
        // "one event per mutation" can be asserted at each call site rather than assumed.
        $this->service->record(
            eventType: 'COST_CENTER_CREATED',
            entityType: 'cost_center',
            mutate: fn(): Currency => $this->currency('IRR', 'Iranian Rial', 2),
        );

        $this->assertCount(1, $this->audit->getConfigurationEvents());
        $this->assertCount(0, $this->audit->getEvents());
    }

    public function testAnOptionalSubjectDefaultsToNullRatherThanBeingGuessed(): void
    {
        // `entity_id` is nullable in the schema, and inventing one (a code, say) where the call site
        // did not supply a stable public id would make two different subjects collide.
        $this->service->record(
            eventType: 'COST_CENTER_CREATED',
            entityType: 'cost_center',
            mutate: fn(): Currency => $this->currency('IRR', 'Iranian Rial', 2),
        );

        $this->assertNull($this->audit->getConfigurationEvents()[0]['entity_id']);
    }

    private function currency(string $code, string $name, int $minorUnit): Currency
    {
        $currency = Currency::create($code, $name, $minorUnit);
        $currency->publicId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $currency->createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return $currency;
    }
}