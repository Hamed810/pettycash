<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version010100Date20260920000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */ $schema = $schemaClosure();
        if (!$schema->hasTable('pc_sheets')) {
            $t = $schema->createTable('pc_sheets'); $this->id($t); $this->publicId($t);
            $t->addColumn('title', Types::STRING, ['length' => 255]);
            $t->addColumn('owner_uid', Types::STRING, ['length' => 64]);
            $t->addColumn('account_id', Types::BIGINT, ['unsigned' => true]);
            $t->addColumn('currency_id', Types::BIGINT, ['unsigned' => true]);
            $t->addColumn('status', Types::STRING, ['length' => 40]);
            $t->addColumn('submitted_total_minor', Types::BIGINT, ['notnull' => false]);
            foreach (['created_at', 'first_submitted_at', 'management_completed_at', 'finance_started_at', 'completed_at'] as $column) { $t->addColumn($column, Types::DATETIME_IMMUTABLE, ['notnull' => $column === 'created_at']); }
            $t->addColumn('lock_version', Types::INTEGER, ['default' => 0]);
            $t->addIndex(['owner_uid', 'status', 'created_at'], 'pc_sheet_owner_status');
        }
        if (!$schema->hasTable('pc_transactions')) {
            $t = $schema->createTable('pc_transactions'); $this->id($t); $this->publicId($t);
            $t->addColumn('sheet_id', Types::BIGINT, ['unsigned' => true]);
            $t->addColumn('owner_uid', Types::STRING, ['length' => 64]);
            $t->addColumn('active_revision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('current_stage', Types::STRING, ['length' => 24]);
            $t->addColumn('current_status', Types::STRING, ['length' => 24]);
            $t->addColumn('final_outcome', Types::STRING, ['length' => 16, 'notnull' => false]);
            $t->addColumn('created_at', Types::DATETIME_IMMUTABLE); $t->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $t->addColumn('lock_version', Types::INTEGER, ['default' => 0]);
            $t->addIndex(['sheet_id', 'current_stage', 'current_status'], 'pc_tx_sheet_state');
        }
        if (!$schema->hasTable('pc_transaction_revisions')) {
            $t = $schema->createTable('pc_transaction_revisions'); $this->id($t); $this->publicId($t);
            $t->addColumn('transaction_id', Types::BIGINT, ['unsigned' => true]);
            $t->addColumn('revision_number', Types::INTEGER);
            $t->addColumn('expense_date', Types::DATE_IMMUTABLE);
            $t->addColumn('amount_minor', Types::BIGINT);
            $t->addColumn('description', Types::TEXT, ['notnull' => false]);
            $t->addColumn('cost_center_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('cost_center_code', Types::STRING, ['length' => 64, 'notnull' => false]);
            $t->addColumn('cost_center_name', Types::STRING, ['length' => 255, 'notnull' => false]);
            $t->addColumn('expense_type_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('expense_type_title', Types::STRING, ['length' => 255, 'notnull' => false]);
            $t->addColumn('manager_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
            $t->addColumn('senior_manager_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
            $t->addColumn('created_at', Types::DATETIME_IMMUTABLE); $t->addColumn('created_by', Types::STRING, ['length' => 64]);
            $t->addColumn('activated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $t->addColumn('supersedes_revision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $t->addUniqueIndex(['transaction_id', 'revision_number'], 'pc_revision_number_uniq');
        }
        if (!$schema->hasTable('pc_workflow_decisions')) {
            $t = $schema->createTable('pc_workflow_decisions'); $this->id($t); $this->publicId($t);
            $t->addColumn('transaction_id', Types::BIGINT, ['unsigned' => true]); $t->addColumn('revision_id', Types::BIGINT, ['unsigned' => true]);
            $t->addColumn('stage', Types::STRING, ['length' => 24]); $t->addColumn('decision', Types::STRING, ['length' => 16]);
            $t->addColumn('explanation', Types::TEXT, ['notnull' => false]);
            $t->addColumn('actor_uid', Types::STRING, ['length' => 64]); $t->addColumn('actor_name', Types::STRING, ['length' => 255]); $t->addColumn('actor_role', Types::STRING, ['length' => 32]);
            $t->addColumn('command_id', Types::STRING, ['length' => 36]); $t->addColumn('decided_at', Types::DATETIME_IMMUTABLE);
            $t->addUniqueIndex(['revision_id', 'stage'], 'pc_decision_revision_stage'); $t->addUniqueIndex(['command_id'], 'pc_decision_command_uniq');
        }
        return $schema;
    }
    private function id(object $t): void { $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true]); $t->setPrimaryKey(['id']); }
    private function publicId(object $t): void { $t->addColumn('public_id', Types::STRING, ['length' => 36]); $t->addUniqueIndex(['public_id']); }
}
