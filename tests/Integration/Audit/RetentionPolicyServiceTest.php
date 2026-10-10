<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Audit;

use InvalidArgumentException;
use OCA\PettyCash\Application\Audit\RetentionPolicyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Domain\Audit\AuditRetentionPolicy;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRetentionPolicyStore;
use PHPUnit\Framework\TestCase;

/**
 * T237 / FR-057: policy configuration and compliance verification.
 *
 * The distinction this file exists to hold is between a floor that is *enforced on writes* and one that
 * is *guaranteed to be in force*. A service that only validated `configure()` would satisfy a test that
 * only called `configure()`, and would still return a three-year policy if something else had written
 * one. So the stored-value cases below matter as much as the refusal case: FR-057's guarantee is about
 * the policy actually in force, not about the code path that created it.
 *
 * T138 owns `RetentionPolicyTest.php`, which measures the same floor against the real audit
 * repositories over HTTP. This file is the service's own contract, and does not pre-empt that.
 */
final class RetentionPolicyServiceTest extends TestCase
{
    private InMemoryRetentionPolicyStore $store;
    private InMemoryAuditWriter $auditWriter;
    private FixedClock $clock;
    private RetentionPolicyService $service;

    protected function setUp(): void
    {
        $this->store = new InMemoryRetentionPolicyStore();
        $this->auditWriter = new InMemoryAuditWriter();
        $this->clock = new FixedClock('2026-09-27T12:00:00+00:00');
        $this->service = new RetentionPolicyService(
            $this->store,
            $this->auditWriter,
            $this->clock
        );
    }

    private function serviceWith(AuditWriter $auditWriter): RetentionPolicyService
    {
        return new RetentionPolicyService($this->store, $auditWriter, $this->clock);
    }

    // --- Reading the policy in force ---

    public function testWithNothingConfiguredTheFloorIsInForce(): void
    {
        self::assertSame(7, $this->service->currentPolicy()->retentionYears());
    }

    public function testAConfiguredPeriodIsReadBack(): void
    {
        $this->service->configure(12, 'Twelve years for legal hold');

        self::assertSame(12, $this->service->currentPolicy()->retentionYears());
    }

    // --- Refusing a sub-floor period ---

    public function testConfiguringBelowTheFloorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->configure(3, 'too short');
    }

    public function testARefusedConfigurationWritesNothingAndAuditsNothing(): void
    {
        try {
            $this->service->configure(3, 'too short');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertNull($this->store->load());
        self::assertSame(0, $this->store->saveCount());
        self::assertSame([], $this->auditWriter->getEvents());
    }

    public function testARefusedConfigurationLeavesAnExistingPolicyIntact(): void
    {
        $this->service->configure(20, 'legal hold');
        $this->auditWriter->clear();

        try {
            $this->service->configure(1, 'someone tries to shorten it');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(20, $this->service->currentPolicy()->retentionYears());
        self::assertSame([], $this->auditWriter->getEvents());
    }

    // --- A stored value that predates the floor ---

    public function testAStoredPeriodBelowTheFloorFallsBackToSevenYears(): void
    {
        $this->store->loadRaw([
            'retentionYears' => 2,
            'updatedAt' => '2020-01-01T00:00:00+00:00',
            'updatedBy' => 'legacy',
        ]);

        self::assertSame(7, $this->service->currentPolicy()->retentionYears());
    }

    public function testComplianceReportsASubFloorStoredValueAsNonCompliant(): void
    {
        $this->store->loadRaw([
            'retentionYears' => 2,
            'updatedAt' => '2020-01-01T00:00:00+00:00',
            'updatedBy' => 'legacy',
        ]);

        $report = $this->service->complianceReport();

        // The stored configuration breaches FR-057 and is reported as such ...
        self::assertFalse($report['honorsMinimum']);
        self::assertSame(2, $report['configuredYears']);
        self::assertTrue($report['defaultApplied']);
        // ... while what the service will actually enforce stays safe.
        self::assertTrue($report['effectiveHonorsMinimum']);
        self::assertSame(7, $report['effectiveYears']);
    }

    public function testAnUnconfiguredPolicyIsCompliantBecauseTheDefaultIsTheFloor(): void
    {
        $report = $this->service->complianceReport();

        self::assertTrue($report['honorsMinimum']);
        self::assertNull($report['configuredYears']);
        self::assertSame(7, $report['effectiveYears']);
        self::assertTrue($report['defaultApplied']);
    }

    public function testAConfiguredCompliantPolicyIsNotReportedAsDefaulted(): void
    {
        $this->service->configure(9, 'Nine years');

        $report = $this->service->complianceReport();

        self::assertTrue($report['honorsMinimum']);
        self::assertSame(9, $report['configuredYears']);
        self::assertSame(9, $report['effectiveYears']);
        self::assertFalse($report['defaultApplied']);
    }

    // --- The prohibition, as reported ---

    public function testComplianceAlwaysReportsThatDeletionIsNotPermitted(): void
    {
        self::assertFalse($this->service->complianceReport()['permitsDeletion']);

        $this->service->configure(50, 'Fifty years');

        self::assertFalse($this->service->complianceReport()['permitsDeletion']);
    }

    // --- The configuration audit requirement (FR-017) ---

    public function testChangingThePolicyWritesAConfigurationAuditEvent(): void
    {
        $this->service->configure(10, 'Regulatory hold');

        $events = $this->auditWriter->getEvents();
        self::assertCount(1, $events);
        self::assertSame('RETENTION_POLICY_CHANGED', $events[0]['event_type']);
        self::assertSame('CONFIGURATION', $events[0]['stage']);
        self::assertSame('audit_retention_policy', $events[0]['entity_type']);
        self::assertSame('Regulatory hold', $events[0]['explanation']);
    }

    public function testTheFirstChangeRecordsNoPreviousValueAndTheNewOne(): void
    {
        $this->service->configure(10, 'first');

        $event = $this->auditWriter->getEvents()[0];
        self::assertSame([], $event['before']);
        self::assertSame(['retentionYears' => 10], $event['after']);
    }

    public function testASecondChangeRecordsThePreviousAndTheNewValue(): void
    {
        $this->service->configure(10, 'first');
        $this->auditWriter->clear();
        $this->service->configure(12, 'second');

        $event = $this->auditWriter->getEvents()[0];
        self::assertSame(['retentionYears' => 10], $event['before']);
        self::assertSame(['retentionYears' => 12], $event['after']);
        self::assertSame('second', $event['explanation']);
    }

    public function testTheStoredRecordCarriesTheConfigurationTime(): void
    {
        $this->service->configure(10, 'first');

        self::assertSame('2026-09-27T12:00:00+00:00', $this->store->load()['updatedAt']);
    }

    public function testTheStoredRecordCarriesNoActorUntilOneIsKnown(): void
    {
        $this->service->configure(10, 'first');

        // The actor is resolved by the audit writer from the current session, so the policy record
        // does not restate it. It is reported as absent rather than as an empty identity.
        self::assertSame('', $this->store->load()['updatedBy']);
        self::assertNull($this->service->complianceReport()['updatedBy']);
    }

    public function testEveryAcceptedChangeAppendsExactlyOneEvent(): void
    {
        $this->service->configure(7, 'one');
        $this->service->configure(8, 'two');
        $this->service->configure(9, 'three');

        self::assertCount(3, $this->auditWriter->getEvents());
        self::assertSame(3, $this->store->saveCount());
    }

    // --- The value object is what enforces the floor ---

    public function testTheServiceNeverHandsBackAPolicyThatViolatesTheFloor(): void
    {
        $this->store->loadRaw([
            'retentionYears' => 0,
            'updatedAt' => '',
            'updatedBy' => '',
        ]);

        $policy = $this->service->currentPolicy();

        self::assertGreaterThanOrEqual(
            AuditRetentionPolicy::MINIMUM_RETENTION_YEARS,
            $policy->retentionYears()
        );
        self::assertTrue($policy->honorsMinimum());
    }
}
