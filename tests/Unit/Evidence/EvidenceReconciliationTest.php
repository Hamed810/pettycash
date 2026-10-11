<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Evidence;

use OCA\PettyCash\Application\Evidence\EvidenceReconciliationService;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use PHPUnit\Framework\TestCase;

/**
 * T130: the reconciliation diagnostic reports without deleting.
 */
final class EvidenceReconciliationTest extends TestCase
{
    private EvidenceReconciliationService $service;

    protected function setUp(): void
    {
        $evidence = $this->createStub(\OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository::class);
        $files = $this->createStub(\OCA\PettyCash\Application\Port\PrivateFileStore::class);
        $this->service = new EvidenceReconciliationService($evidence, $files);
    }

    public function testMatchedPairsReportNothing(): void
    {
        $report = $this->service->reconcile(
            ['k1', 'k2'],
            [
                ['publicId' => 'e1', 'storageKey' => 'k1'],
                ['publicId' => 'e2', 'storageKey' => 'k2'],
            ]
        );
        self::assertSame([], $report['orphanFiles']);
        self::assertSame([], $report['missingBinaries']);
    }

    public function testOrphanFilesAreReported(): void
    {
        $report = $this->service->reconcile(
            ['k1', 'orphan'],
            [['publicId' => 'e1', 'storageKey' => 'k1']]
        );
        self::assertSame(['orphan'], $report['orphanFiles']);
        self::assertSame([], $report['missingBinaries']);
    }

    public function testMissingBinariesAreReported(): void
    {
        $report = $this->service->reconcile(
            ['k1'],
            [
                ['publicId' => 'e1', 'storageKey' => 'k1'],
                ['publicId' => 'e2', 'storageKey' => 'gone'],
            ]
        );
        self::assertSame([], $report['orphanFiles']);
        self::assertSame(['gone'], $report['missingBinaries']);
    }

    public function testMaxFileSizeIsThe100MbCeiling(): void
    {
        self::assertSame(EvidenceService::MAX_FILE_SIZE, EvidenceReconciliationService::MAX_FILE_SIZE);
        self::assertSame(104857600, EvidenceReconciliationService::MAX_FILE_SIZE);
    }

    public function testServiceExposesNoRemovalPath(): void
    {
        foreach (get_class_methods(EvidenceReconciliationService::class) as $method) {
            self::assertDoesNotMatchRegularExpression(
                '/delete|remove|purge|destroy|unlink/i',
                $method,
                "EvidenceReconciliationService::{$method}() must not be a removal path"
            );
        }
    }
}
