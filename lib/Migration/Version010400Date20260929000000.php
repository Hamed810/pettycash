<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Makes `command_id` nullable on both audit tables.
 *
 * ## Why
 *
 * The column was created by `Version010200Date20260920000000` as
 * `Types::STRING` with no `'notnull' => false`. Doctrine's default is NOT NULL, so the column inherited
 * that default while the three nullable columns beside it -- `explanation`, `before_json`, `after_json`
 * -- were each declared explicitly nullable. `command_id` read as though it were required, and in the
 * paths that were actually command-wrapped it always was: `SubmitSheet`, both decision services and
 * `ResubmitCorrection` all pass a real command id, and `SubmitSheet` carries a comment describing the
 * insert failing on exactly this NOT NULL column when the key went missing.
 *
 * That is why the mismatch stayed invisible. Every writer that existed supplied a command id, so the
 * constraint was never actually violated, and the column looked like a deliberate requirement.
 *
 * ## What changed
 *
 * FR-051 requires ten transitions to be recorded. Four of them had no writer, and the four that were
 * added for this task -- revision creation, transaction changes, evidence additions, and the per-
 * transaction routing events -- are **not** command-wrapped: `TransactionService::createTransaction()`,
 * `updateTransaction()` and `EvidenceService::uploadEvidence()` take no command id and do not run
 * through `IdempotencyService`. There is no honest command id to record for them.
 *
 * The alternatives were both worse. A generated UUID would put a plausible-looking correlation id on
 * events that were never part of a command, and a `NOT NULL` column would force one. FR-052 lists what
 * an event must retain -- event time, actor identifier, actor display name, actor role, workflow
 * stage, event type, decision, explanation, and the applicable before-and-after values -- and
 * `command_id` is not among them. It is a correlation convenience, so a nullable column is the
 * accurate representation: non-null where a command exists, null where the transition is not a command.
 *
 * Wrapping these four transitions in `IdempotencyService` is the proper follow-up and is tracked
 * separately as T214. When that happens these rows begin carrying real command ids, and this migration
 * stays correct precisely because it allows both.
 *
 * ## Safety
 *
 * Relaxing NOT NULL to nullable cannot lose data and cannot change any existing row. No column is
 * dropped, retyped or rewritten, and no index covers `command_id` on either audit table -- the unique
 * indexes on `command_id` belong to `pettycash_command_results`,
 * `pettycash_workflow_decisions` and `pettycash_account_movements`, none of which is touched here.
 * Both audit tables were measured empty, so there is in any case nothing to rewrite.
 */
final class Version010400Date20260929000000 extends SimpleMigrationStep
{
    /** @var list<string> */
    private const AUDIT_TABLES = [
        'pettycash_transaction_audit',
        'pettycash_configuration_audit',
    ];

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        foreach (self::AUDIT_TABLES as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $column = $schema->getTable($table)->getColumn('command_id');
            if (!$column->getNotnull()) {
                continue;
            }

            $schema->getTable($table)->modifyColumn('command_id', [
                'notnull' => false,
                'length' => 36,
            ]);
        }

        return $schema;
    }
}
