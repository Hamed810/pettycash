<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Migration;

use OCA\PettyCash\Domain\Shared\Enum\AuditEventType;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards every enum-valued column against the string width the migrations actually leave behind.
 * A column narrower than the longest backing value cannot be written, so the state it represents
 * is unreachable no matter what the application code does.
 *
 * The effective width of a column is the last declaration applied to it, so migrations are read
 * in the same ascending version order Nextcloud applies them in and later declarations win.
 */
final class EnumColumnWidthTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function enumColumnProvider(): array
    {
        return [
            'current_status' => ['current_status', self::values(TransactionStatus::class)],
            'current_stage' => ['current_stage', self::values(WorkflowStage::class)],
            // data-model.md restricts final_outcome to APPROVED or REJECTED only.
            'final_outcome' => ['final_outcome', ['APPROVED', 'REJECTED']],
            'pettycash_sheets.status' => ['status', self::values(SheetStatus::class)],
            'decision stage' => ['stage', self::values(WorkflowStage::class)],
            'decision value' => ['decision', self::values(Decision::class)],
            'movement_type' => ['movement_type', self::values(MovementType::class)],
            'role' => ['role', self::values(Role::class)],
            'event_type' => ['event_type', self::values(AuditEventType::class)],
            'actor_role' => ['actor_role', self::values(Role::class)],
        ];
    }

    /**
     * @param list<string> $values
     */
    #[DataProvider('enumColumnProvider')]
    public function testEnumColumnIsWideEnoughForEveryBackingValue(string $column, array $values): void
    {
        $required = max(array_map(static fn (string $value): int => strlen($value), $values));
        $widths = $this->effectiveStringWidths();
        $declared = $widths[$column] ?? null;

        self::assertNotNull(
            $declared,
            sprintf('No migration declares %s as a string column, so no enum value can be stored.', $column)
        );
        self::assertGreaterThanOrEqual(
            $required,
            $declared,
            sprintf(
                '%s is left as VARCHAR(%d) but it must hold %d characters (%s).',
                $column,
                $declared,
                $required,
                implode(', ', $values)
            )
        );
    }

    /**
     * @return list<string>
     */
    private static function values(string $enum): array
    {
        return array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());
    }

    /**
     * @return array<string, int>
     */
    private function effectiveStringWidths(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/lib/Migration/*.php') ?: [];
        self::assertNotEmpty($files, 'No migration files were found.');

        // Nextcloud applies migrations in ascending version order; mirror that here.
        usort($files, static function (string $a, string $b): int {
            return (int) self::versionOf($a) <=> (int) self::versionOf($b);
        });

        $widths = [];
        // Matches addColumn/changeColumn('<col>', Types::STRING, ['length' => N]) and the
        // modifyColumn('<col>', ['length' => N]) form Nextcloud 34 uses for widening. Matched
        // against whole files because a declaration may span several lines; matches come back
        // in source order, so a later declaration still overrides an earlier one.
        $pattern = "/(?:add|change|modify)Column\('([a-z_]+)',\s*(?:Types::STRING,\s*)?\[(?:[^\]]*'length' => (\d+))/";
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) < 1) {
                continue;
            }
            foreach ($matches as $match) {
                $widths[$match[1]] = (int) $match[2];
            }
        }
        return $widths;
    }

    private static function versionOf(string $file): int
    {
        preg_match('/Version(\d+)/', basename($file, '.php'), $m);
        return (int) ($m[1] ?? 0);
    }
}
