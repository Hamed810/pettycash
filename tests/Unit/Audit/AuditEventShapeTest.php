<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Audit;

use InvalidArgumentException;
use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * T213: an audit event that cannot be read is refused, not stored as `UNKNOWN`.
 *
 * ## The defect this exists for
 *
 * `AuditWriterService::append()` takes an `array` and reads it with **snake_case** keys —
 * `event_type`, `entity_type`, `before`, `after`. Every default in `toEvent()` is a fallback for a
 * *missing* key, so a payload spelled any other way is not rejected: it is stored.
 *
 * `EvidenceService::deactivateEvidence()` then built its event by constructing an `AuditEvent`
 * **positionally** and casting it:
 *
 * ```php
 * $auditEvent = new AuditEvent(..., 17 positional arguments, ...);
 * $this->auditWriter->append((array) $auditEvent);
 * ```
 *
 * Casting an object yields its **camelCase** property names — `eventType`, `entityType`,
 * `beforeJson` — so not one of the keys `toEvent()` looks for is present. Every field falls back:
 * `event_type => 'UNKNOWN'`, `stage => 'DRAFT'`, `entity_type => ''`, `entity_id => null`,
 * `sheet_id`/`transaction_id`/`revision_id` all `null`, and `before`/`after` both absent, so
 * `json_encode([])` stores the literal `"[]"`.
 *
 * FR-052 requires every event to retain "applicable before-and-after values". For this path they are
 * discarded, and FR-053 makes audit records append-only — so the degraded row is **permanent**. There
 * is no repair path for it, which is why refusing at the boundary is the only moment the mistake can
 * be caught.
 *
 * Measured on the live database the transaction-audit table is empty, so no such row exists *here* yet.
 * The defect is latent, not harmless: the first evidence deactivation in production writes it.
 */
final class AuditEventShapeTest extends TestCase
{
    private InMemoryAuditEventRepository $events;
    private AuditWriterService $writer;

    protected function setUp(): void
    {
        $this->events = new InMemoryAuditEventRepository();
        $this->writer = new AuditWriterService(
            $this->events,
            new InMemoryCurrentUser('employee1'),
            new InMemoryUserDirectory(),
            new InMemoryAuditEventRepository()
        );
    }

    /**
     * The shape `EvidenceService` actually produces: an `AuditEvent` cast to an array.
     *
     * Reproduced rather than described, so the test cannot drift from the defect. If that call site is
     * ever corrected, this test still asserts the writer's own rule and the *other* test below
     * (`EvidenceService` passes a readable shape) becomes the one that fails.
     */
    private function camelCasedPayload(): array
    {
        return (array) new AuditEvent(
            'id-1',
            11,
            22,
            33,
            'COST_CENTER',
            null,
            'evidence',
            'e-1',
            'EVIDENCE_DEACTIVATED',
            'employee1',
            'Employee One',
            'PURCHASER',
            'submitted in error',
            '{"active":true}',
            '{"active":false}',
            'cmd-1',
            new \DateTimeImmutable('2026-09-29T00:00:00+00:00')
        );
    }

    public function testAnEventWhoseShapeCannotBeReadIsRefusedRatherThanStoredAsUnknown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event_type');
        $this->expectExceptionMessage('append-only');

        $this->writer->append($this->camelCasedPayload());
    }

    public function testNothingIsWrittenWhenTheShapeCannotBeRead(): void
    {
        // The load-bearing half. A refusal that still persisted a row would leave the same permanent
        // junk the refusal exists to prevent, so the post-condition is asserted and not inferred from
        // the exception.
        try {
            $this->writer->append($this->camelCasedPayload());
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(
            [],
            $this->events->getEvents(),
            'a refused audit event must leave no row. FR-053 makes these records append-only, so a row '
            . 'written before the refusal is a row nobody can remove.'
        );
    }

    /**
     * The configuration stream is the strict one, and stays strict.
     *
     * FR-055 addresses configuration audit events by `entity_type`, so an event without a subject is
     * unaddressable there. This was already refused before this task and must stay refused: the point of
     * the transaction-stream rule is that it is *not* widened to match, so both streams are pinned.
     */
    public function testAConfigurationEventWithNoSubjectIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('entity_type');

        $this->writer->appendConfiguration(['event_type' => 'CURRENCY_CREATED']);
    }

    // ===== the shape callers are supposed to use, asserted so the fix is verifiable =====

    public function testAReadablePayloadKeepsItsTypeSubjectStageAndSnapshots(): void
    {
        $this->writer->append([
            'id' => 'id-1',
            'sheet_id' => 11,
            'transaction_id' => 22,
            'revision_id' => 33,
            'stage' => 'COST_CENTER',
            'entity_type' => 'evidence',
            'entity_id' => 'e-1',
            'event_type' => 'EVIDENCE_DEACTIVATED',
            'explanation' => 'submitted in error',
            'before' => ['active' => true],
            'after' => ['active' => false],
            'command_id' => 'cmd-1',
        ]);

        $stored = $this->events->getEvents();
        self::assertCount(1, $stored);
        self::assertSame('EVIDENCE_DEACTIVATED', $stored[0]->eventType);
        self::assertSame('evidence', $stored[0]->entityType);
        self::assertSame('e-1', $stored[0]->entityId);
        self::assertSame('COST_CENTER', $stored[0]->stage);
        self::assertSame(11, $stored[0]->sheetId);
        self::assertSame(22, $stored[0]->transactionId);
        self::assertSame(33, $stored[0]->revisionId);
        self::assertSame('submitted in error', $stored[0]->explanation);
        self::assertSame('cmd-1', $stored[0]->commandId);
        self::assertSame('id-1', $stored[0]->id);
        self::assertStringContainsString('"active":true', $stored[0]->beforeJson, 'FR-052: the before value must survive');
        self::assertStringContainsString('"active":false', $stored[0]->afterJson, 'FR-052: the after value must survive');
    }

    // ===== the second instance of the same defect: a mis-spelled snapshot key =====

    /**
     * A caller that pre-encodes its snapshots is refused instead of stored as `"[]"`.
     *
     * `ManagementReviewService` and `FinanceSettlementService` both sent `before_json` / `after_json`
     * on the `DECISION` path. The writer reads `before` / `after` and encodes them itself, so those
     * payloads had no snapshot key at all and every approval, rejection and return was written with
     * `"[]"` in both columns, discarding the decision, stage, explanation, revision number and finance
     * `financial_effect`.
     */
    public function testAPayloadThatPreEncodesItsSnapshotsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('before');

        $this->writer->append([
            'event_type' => 'DECISION',
            'entity_type' => 'transaction',
            'entity_id' => 't-1',
            'before_json' => '[]',
            'after_json' => '{"decision":"APPROVE"}',
        ]);
    }

    public function testRefusingAPreEncodedSnapshotPayloadStillWritesNothing(): void
    {
        try {
            $this->writer->append([
                'event_type' => 'DECISION',
                'entity_type' => 'transaction',
                'entity_id' => 't-1',
                'before_json' => '[]',
                'after_json' => '{"decision":"APPROVE"}',
            ]);
        } catch (\InvalidArgumentException) {
            // expected
        }

        self::assertSame([], $this->events->getEvents());
    }

    public function testTheConfigurationStreamRefusesPreEncodedSnapshotsToo(): void
    {
        // One `toEvent()` serves both streams, so the configuration stream inherits the rule. Asserted
        // so a future refactor that splits the two conversion paths cannot quietly exempt one of them.
        $this->expectException(\InvalidArgumentException::class);

        $this->writer->appendConfiguration([
            'event_type' => 'ACCOUNT_CHARGED',
            'entity_type' => 'account',
            'entity_id' => 'acc-1',
            'after_json' => '{"charged":true}',
        ]);
    }

    /**
     * No production caller may hand the writer a key it does not read.
     *
     * Both defects above were a caller and a writer disagreeing about a key name, with nothing in
     * between to notice. A unit test can only cover the callers that already exist, so this pins the
     * whole `lib/` tree: any future caller that sends a pre-encoded snapshot fails here instead of
     * writing a permanent row with empty before-and-after values.
     *
     * The scan is scoped to the payload keys passed to the writer and deliberately ignores the database
     * column names of the same spelling -- `before_json` and `after_json` are the real column names in
     * the migration and the repository, and requiring those to change would be wrong.
     */
    public function testNoProductionCallerPassesAPreEncodedSnapshotToTheWriter(): void
    {
        $root = realpath(__DIR__ . '/../../..') ?: __DIR__ . '/../../..';
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, 'auditWriter->append')) {
                continue;
            }
            foreach (["'before_json'", "'after_json'"] as $key) {
                if (str_contains($source, $key)) {
                    $offenders[] = $file->getPathname() . ' sends ' . $key;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These callers pass a snapshot key the writer never reads, so the event is stored with the "
            . "literal \"[]\" in both columns and the values are lost permanently:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * Every transaction-stream event type in `lib/` must come from the enum.
     *
     * The reason this scan exists: `AuditEventType` used to be used by nothing except
     * `EnumColumnWidthTest`, so the column was sized for a vocabulary nobody wrote while five different
     * strings were stored in it -- and only one of them appeared in the enum at all. Nothing detected
     * that, because a raw string satisfies every other check.
     *
     * `AuditEventType` is now the single source of truth, so a producer that hardcodes a string is
     * asserting a value no compiler or test elsewhere will ever look at. This fails on that.
     *
     * Configuration events are excluded by design: `ACCOUNT_CHARGED` and `RETENTION_POLICY_CHANGED`
     * belong to the FR-055 configuration stream, which is a different table with its own vocabulary.
     * `RetentionPolicyService` and the `ACCOUNT_CHARGED` write in `FinanceSettlementService` are the only
     * legitimate raw-string producers, and both are named so that adding a third fails here rather than
     * passing unnoticed.
     */
    public function testEveryTransactionEventTypeInProductionComesFromTheEnum(): void
    {
        $root = realpath(__DIR__ . '/../../..') ?: __DIR__ . '/../../..';
        $allowed = [
            // FR-055 configuration stream, addressed by entity_type rather than by the FR-051 list.
            'lib/Application/Audit/RetentionPolicyService.php',
            'lib/Application/Reporting/ChargedAccountScope.php',
        ];
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $real = realpath($file->getPathname()) ?: $file->getPathname();
            $relative = ltrim(str_replace($root, '', $real), '/');
            if (in_array($relative, $allowed, true)) {
                continue;
            }
            $source = (string) file_get_contents($real);
            if (!str_contains($source, "'event_type' =>")) {
                continue;
            }
            if (preg_match_all("/'event_type'\s*=>\s*'([A-Z_]+)'/", $source, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $literal) {
                $offenders[] = $relative . ' hardcodes event_type ' . $literal;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These producers hardcode a transaction event type instead of using `AuditEventType`, which is "
            . "what let the stored vocabulary and the enum drift apart unnoticed:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * The enum must cover every transition FR-051 names, and nothing that FR-051 does not.
     *
     * Both directions are asserted. A missing case is an event kind with no vocabulary, which is how
     * routing, revision creation and transaction changes came to have no writer. An *extra* case is a
     * value no requirement asked for -- `SETTLED` was one, and it duplicated a fact the FR-055
     * `ACCOUNT_CHARGED` configuration event already recorded.
     */
    public function testTheEnumCoversExactlyTheTransitionsFr051Names(): void
    {
        $expected = [
            // submissions, routing, approvals, rejections, returns, corrections, revision creation,
            // transaction changes, evidence additions, evidence deactivations -- FR-051, in order.
            'SUBMITTED', 'ROUTED', 'APPROVED', 'REJECTED', 'RETURNED', 'CORRECTED',
            'REVISION_CREATED', 'TRANSACTION_CHANGED', 'EVIDENCE_ADDED', 'EVIDENCE_DEACTIVATED',
        ];

        $actual = array_map(static fn($case) => $case->value, \OCA\PettyCash\Domain\Shared\Enum\AuditEventType::cases());
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /**
     * All ten must fit the column the migrations actually leave behind.
     *
     * A new event type longer than the column would be truncated or rejected at insert time, and the
     * failure would surface as a database error on a workflow path rather than as a test failure. The
     * column is read from the migration rather than hardcoded, so it tracks the schema.
     */
    public function testEveryEventTypeFitsTheEventTypeColumn(): void
    {
        $length = 0;
        foreach (glob(__DIR__ . '/../../../lib/Migration/*.php') ?: [] as $migration) {
            if (preg_match("/addColumn\('event_type'[^\n]*'length'\s*=>\s*(\d+)/", (string) file_get_contents($migration), $m) === 1) {
                $length = (int) $m[1];
            }
        }

        self::assertGreaterThan(0, $length, 'the event_type column width must be readable from a migration');

        foreach (\OCA\PettyCash\Domain\Shared\Enum\AuditEventType::cases() as $case) {
            self::assertLessThanOrEqual(
                $length,
                strlen($case->value),
                "event_type {$case->value} does not fit the VARCHAR({$length}) column"
            );
        }
    }
}
