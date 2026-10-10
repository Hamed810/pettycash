<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Brings `pettycash_command_results` to the `Command Result` entity (T251).
 *
 * ## Why
 *
 * `Version010200Date20260920000000` created the table with only `command_id`,
 * `payload_hash` (64), `response_json` and `created_at`, so `IdempotencyService`
 * could compare nothing but the payload hash: a command ID replayed by a
 * different actor, operation or target was not detected as a conflict, returned
 * the first caller's stored response, and silently skipped the second caller's
 * operation (FR-042).
 *
 * ## What changed
 *
 * Adds nullable `actor_uid`, `operation`, `target_public_id`, `result_json`
 * and `result_status`, and widens `payload_hash` to the 128 characters the
 * data model specifies (same `modifyColumn` API as `Version010400...`).
 *
 * ## Safety
 *
 * Forward-only and row-preserving: no column is dropped, retyped destructively
 * or rewritten, and no existing row is touched. Pre-existing rows keep NULL in
 * the new columns, which the repository reads as the legacy marker: replays of
 * those rows compare hash-only, exactly as before. `response_json` is kept
 * (unread for new rows) so legacy rows keep their stored response verbatim;
 * the repository prefers `result_json` and falls back to it.
 */
final class Version010500Date20261010000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */ $schema = $schemaClosure();
        if (!$schema->hasTable('pettycash_command_results')) {
            return $schema;
        }
        $table = $schema->getTable('pettycash_command_results');
        if ($table->hasColumn('payload_hash') && $table->getColumn('payload_hash')->getLength() < 128) {
            $table->modifyColumn('payload_hash', ['length' => 128]);
        }
        if (!$table->hasColumn('actor_uid')) {
            $table->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
        }
        if (!$table->hasColumn('operation')) {
            $table->addColumn('operation', Types::STRING, ['length' => 100, 'notnull' => false]);
        }
        if (!$table->hasColumn('target_public_id')) {
            $table->addColumn('target_public_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        }
        if (!$table->hasColumn('result_json')) {
            $table->addColumn('result_json', Types::TEXT, ['notnull' => false]);
        }
        if (!$table->hasColumn('result_status')) {
            $table->addColumn('result_status', Types::INTEGER, ['notnull' => false]);
        }
        return $schema;
    }
}
