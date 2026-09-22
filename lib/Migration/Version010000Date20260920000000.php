<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version010000Date20260920000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('pc_currencies')) {
            $table = $schema->createTable('pc_currencies');
            $this->id($table);
            $table->addColumn('code', Types::STRING, ['length' => 3]);
            $table->addColumn('name', Types::STRING, ['length' => 100]);
            $table->addColumn('minor_unit', Types::SMALLINT, ['default' => 0]);
            $table->addColumn('active', Types::BOOLEAN, ['default' => true]);
            $this->auditColumns($table);
            $table->addUniqueIndex(['code'], 'pc_currency_code_uniq');
        }
        if (!$schema->hasTable('pc_cost_centers')) {
            $table = $schema->createTable('pc_cost_centers');
            $this->id($table);
            $this->publicId($table);
            $table->addColumn('code', Types::STRING, ['length' => 64]);
            $table->addColumn('name', Types::STRING, ['length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('valid_from', Types::DATE_IMMUTABLE);
            $table->addColumn('valid_to', Types::DATE_IMMUTABLE, ['notnull' => false]);
            $table->addColumn('active', Types::BOOLEAN, ['default' => true]);
            $this->auditColumns($table);
            $table->addUniqueIndex(['code'], 'pc_cost_center_code_uniq');
            $table->addIndex(['active', 'valid_from', 'valid_to'], 'pc_cost_center_validity');
        }
        if (!$schema->hasTable('pc_expense_types')) {
            $table = $schema->createTable('pc_expense_types');
            $this->id($table);
            $this->publicId($table);
            $table->addColumn('title', Types::STRING, ['length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('evidence_required', Types::BOOLEAN, ['default' => false]);
            $table->addColumn('active', Types::BOOLEAN, ['default' => true]);
            $this->auditColumns($table);
        }
        if (!$schema->hasTable('pc_role_assignments')) {
            $table = $schema->createTable('pc_role_assignments');
            $this->id($table);
            $this->publicId($table);
            $table->addColumn('user_uid', Types::STRING, ['length' => 64]);
            $table->addColumn('role', Types::STRING, ['length' => 32]);
            $table->addColumn('cost_center_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('valid_from', Types::DATE_IMMUTABLE);
            $table->addColumn('valid_to', Types::DATE_IMMUTABLE, ['notnull' => false]);
            $table->addColumn('active', Types::BOOLEAN, ['default' => true]);
            $this->auditColumns($table, false);
            $table->addIndex(['user_uid', 'role', 'active'], 'pc_role_user_active');
            $table->addIndex(['cost_center_id', 'role', 'active'], 'pc_role_scope_active');
        }
        if (!$schema->hasTable('pc_accounts')) {
            $table = $schema->createTable('pc_accounts');
            $this->id($table);
            $this->publicId($table);
            $table->addColumn('user_uid', Types::STRING, ['length' => 64]);
            $table->addColumn('currency_id', Types::BIGINT, ['unsigned' => true]);
            $table->addColumn('balance_minor', Types::BIGINT, ['default' => 0]);
            $table->addColumn('active', Types::BOOLEAN, ['default' => true]);
            $this->auditColumns($table);
            $table->addUniqueIndex(['user_uid', 'currency_id'], 'pc_account_user_currency');
        }
        if (!$schema->hasTable('pc_account_movements')) {
            $table = $schema->createTable('pc_account_movements');
            $this->id($table);
            $this->publicId($table);
            $table->addColumn('account_id', Types::BIGINT, ['unsigned' => true]);
            $table->addColumn('movement_type', Types::STRING, ['length' => 16]);
            $table->addColumn('amount_minor', Types::BIGINT);
            $table->addColumn('balance_after_minor', Types::BIGINT);
            $table->addColumn('description', Types::STRING, ['length' => 1000]);
            $table->addColumn('source_revision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('source_decision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('command_id', Types::STRING, ['length' => 36]);
            $this->actorColumns($table);
            $table->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
            $table->addUniqueIndex(['command_id'], 'pc_movement_command_uniq');
            $table->addUniqueIndex(['source_revision_id'], 'pc_movement_consumption_uniq');
            $table->addIndex(['account_id', 'occurred_at'], 'pc_movement_account_time');
        }
        return $schema;
    }

    private function id(object $table): void { $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true]); $table->setPrimaryKey(['id']); }
    private function publicId(object $table): void { $table->addColumn('public_id', Types::STRING, ['length' => 36]); $table->addUniqueIndex(['public_id']); }
    private function actorColumns(object $table): void { $table->addColumn('actor_uid', Types::STRING, ['length' => 64]); $table->addColumn('actor_name', Types::STRING, ['length' => 255]); $table->addColumn('actor_role', Types::STRING, ['length' => 32]); }
    private function auditColumns(object $table, bool $lock = true): void { $table->addColumn('created_at', Types::DATETIME_IMMUTABLE); $table->addColumn('created_by', Types::STRING, ['length' => 64]); $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE); $table->addColumn('updated_by', Types::STRING, ['length' => 64]); if ($lock) { $table->addColumn('lock_version', Types::INTEGER, ['default' => 0]); } }
}
