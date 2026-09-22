<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Migration;

use PHPUnit\Framework\TestCase;

final class V1SchemaMigrationTest extends TestCase
{
    public function testMigrationsOnlyDeclareNewPcTables(): void
    {
        $root = dirname(__DIR__, 3) . '/lib/Migration';
        $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), glob($root . '/*.php') ?: []));
        self::assertStringNotContainsString("dropTable", $source);
        self::assertStringNotContainsString("renameTable", $source);
        self::assertStringNotContainsString("pcash_", $source);
        self::assertStringContainsString("createTable('pc_", $source);
    }
}
