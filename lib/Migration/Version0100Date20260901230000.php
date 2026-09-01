<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version0100Date20260901230000 extends SimpleMigrationStep {
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options,
    ): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $this->createCurrencyTable($schema);
        $this->createProjectTable($schema);
        $this->createMemberTable($schema);
        $this->createCategoryTable($schema);
        $this->createVehicleTable($schema);
        $this->createCostListTable($schema);
        $this->createTransactionTable($schema);
        $this->createRevisionTable($schema);
        $this->createAttachmentTable($schema);
        $this->createOcrTable($schema);
        $this->createActionTable($schema);
        $this->createAuditTable($schema);

        return $schema;
    }

    private function addId(object $table): void {
        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->setPrimaryKey(['id']);
    }

    private function createCurrencyTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_currency')) {
            return;
        }

        $table = $schema->createTable('pcash_currency');
        $this->addId($table);
        $table->addColumn('code', Types::STRING, ['length' => 8, 'notnull' => true]);
        $table->addColumn('name', Types::STRING, ['length' => 128, 'notnull' => true]);
        $table->addColumn('symbol', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('decimal_places', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->addColumn('is_default', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addUniqueIndex(['code'], 'pcash_curr_code_uq');
        $table->addIndex(['active'], 'pcash_curr_active_ix');
    }

    private function createProjectTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_project')) {
            return;
        }

        $table = $schema->createTable('pcash_project');
        $this->addId($table);
        $table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
        $table->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('description', Types::TEXT, ['notnull' => false]);
        $table->addColumn('default_currency_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $table->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addUniqueIndex(['uuid'], 'pcash_proj_uuid_uq');
        $table->addUniqueIndex(['code'], 'pcash_proj_code_uq');
        $table->addIndex(['active'], 'pcash_proj_active_ix');
    }

    private function createMemberTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_member')) {
            return;
        }

        $table = $schema->createTable('pcash_member');
        $this->addId($table);
        $table->addColumn('project_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('role', Types::STRING, ['length' => 24, 'notnull' => true]);
        $table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addUniqueIndex(['project_id', 'user_id', 'role'], 'pcash_mem_pr_user_role_uq');
        $table->addIndex(['user_id', 'active'], 'pcash_mem_user_active_ix');
        $table->addIndex(['project_id', 'role'], 'pcash_mem_proj_role_ix');
    }

    private function createCategoryTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_category')) {
            return;
        }

        $table = $schema->createTable('pcash_category');
        $this->addId($table);
        $table->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('description', Types::TEXT, ['notnull' => false]);
        $table->addColumn('receipt_required', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $table->addColumn('vehicle_required', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('odometer_required', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('worker_required', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('permit_required', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('attendance_required', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addUniqueIndex(['code'], 'pcash_cat_code_uq');
        $table->addIndex(['active', 'sort_order'], 'pcash_cat_active_sort_ix');
    }

    private function createVehicleTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_vehicle')) {
            return;
        }

        $table = $schema->createTable('pcash_vehicle');
        $this->addId($table);
        $table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
        $table->addColumn('project_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('plate_number', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('vehicle_type', Types::STRING, ['length' => 128, 'notnull' => false]);
        $table->addColumn('notes', Types::TEXT, ['notnull' => false]);
        $table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addUniqueIndex(['uuid'], 'pcash_veh_uuid_uq');
        $table->addIndex(['project_id', 'active'], 'pcash_veh_proj_active_ix');
        $table->addIndex(['plate_number'], 'pcash_veh_plate_ix');
    }

    private function createCostListTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_list')) {
            return;
        }

        $table = $schema->createTable('pcash_list');
        $this->addId($table);
        $table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
        $table->addColumn('reference', Types::STRING, ['length' => 96, 'notnull' => false]);
        $table->addColumn('project_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('purchaser_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('currency_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('jalali_year', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('jalali_month', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('status', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'OPEN']);
        $table->addColumn('submitted_total', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('manager1_total', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('final_total', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('submitted_at', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('manager1_completed_at', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('manager2_completed_at', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('processed_at', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('version', Types::INTEGER, ['notnull' => true, 'default' => 1]);
        $table->addUniqueIndex(['uuid'], 'pcash_list_uuid_uq');
        $table->addUniqueIndex(['reference'], 'pcash_list_ref_uq');
        $table->addIndex(['project_id', 'status'], 'pcash_list_proj_status_ix');
        $table->addIndex(['purchaser_id', 'status'], 'pcash_list_user_status_ix');
        $table->addIndex(['jalali_year', 'jalali_month'], 'pcash_list_period_ix');
    }

    private function createTransactionTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_txn')) {
            return;
        }

        $table = $schema->createTable('pcash_txn');
        $this->addId($table);
        $table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
        $table->addColumn('list_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('category_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('purchaser_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('currency_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('amount_minor', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('purchase_date', Types::STRING, ['length' => 10, 'notnull' => true]);
        $table->addColumn('description', Types::TEXT, ['notnull' => true]);
        $table->addColumn('vendor', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        $table->addColumn('odometer_km', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        $table->addColumn('worker_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('worker_reference', Types::STRING, ['length' => 128, 'notnull' => false]);
        $table->addColumn('work_days', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('work_minutes', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('work_description', Types::TEXT, ['notnull' => false]);
        $table->addColumn('status', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'DRAFT']);
        $table->addColumn('current_revision', Types::INTEGER, ['notnull' => true, 'default' => 1]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('version', Types::INTEGER, ['notnull' => true, 'default' => 1]);
        $table->addUniqueIndex(['uuid'], 'pcash_txn_uuid_uq');
        $table->addIndex(['list_id', 'status'], 'pcash_txn_list_status_ix');
        $table->addIndex(['vehicle_id', 'purchase_date'], 'pcash_txn_veh_date_ix');
        $table->addIndex(['category_id'], 'pcash_txn_cat_ix');
    }

    private function createRevisionTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_revision')) {
            return;
        }

        $table = $schema->createTable('pcash_revision');
        $this->addId($table);
        $table->addColumn('txn_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('revision_number', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('category_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('currency_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('amount_minor', Types::BIGINT, ['notnull' => true]);
        $table->addColumn('purchase_date', Types::STRING, ['length' => 10, 'notnull' => true]);
        $table->addColumn('description', Types::TEXT, ['notnull' => true]);
        $table->addColumn('vendor', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('vehicle_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        $table->addColumn('odometer_km', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        $table->addColumn('worker_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('worker_reference', Types::STRING, ['length' => 128, 'notnull' => false]);
        $table->addColumn('work_days', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('work_minutes', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('work_description', Types::TEXT, ['notnull' => false]);
        $table->addColumn('changed_by', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('change_reason', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addUniqueIndex(['txn_id', 'revision_number'], 'pcash_rev_txn_num_uq');
        $table->addIndex(['txn_id'], 'pcash_rev_txn_ix');
    }

    private function createAttachmentTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_attach')) {
            return;
        }

        $table = $schema->createTable('pcash_attach');
        $this->addId($table);
        $table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
        $table->addColumn('txn_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('revision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        $table->addColumn('type', Types::STRING, ['length' => 32, 'notnull' => true]);
        $table->addColumn('storage_key', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('original_name', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('mime_type', Types::STRING, ['length' => 128, 'notnull' => true]);
        $table->addColumn('file_size', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('sha256', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('uploaded_by', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('sensitive', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addUniqueIndex(['uuid'], 'pcash_att_uuid_uq');
        $table->addIndex(['txn_id', 'active'], 'pcash_att_txn_active_ix');
        $table->addIndex(['sha256'], 'pcash_att_sha_ix');
    }

    private function createOcrTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_ocr')) {
            return;
        }

        $table = $schema->createTable('pcash_ocr');
        $this->addId($table);
        $table->addColumn('attachment_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('provider', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'QUEUED']);
        $table->addColumn('detected_language', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('raw_text', Types::TEXT, ['notnull' => false]);
        $table->addColumn('normalized_text', Types::TEXT, ['notnull' => false]);
        $table->addColumn('detected_amount', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('amount_confidence', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('detected_currency', Types::STRING, ['length' => 8, 'notnull' => false]);
        $table->addColumn('currency_confidence', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('detected_date', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('date_confidence', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('detected_vendor', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('vendor_confidence', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('invoice_number', Types::STRING, ['length' => 128, 'notnull' => false]);
        $table->addColumn('error_code', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('processed_at', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addIndex(['attachment_id'], 'pcash_ocr_att_ix');
        $table->addIndex(['status'], 'pcash_ocr_status_ix');
    }

    private function createActionTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_action')) {
            return;
        }

        $table = $schema->createTable('pcash_action');
        $this->addId($table);
        $table->addColumn('txn_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('revision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        $table->addColumn('stage', Types::STRING, ['length' => 24, 'notnull' => true]);
        $table->addColumn('action', Types::STRING, ['length' => 24, 'notnull' => true]);
        $table->addColumn('actor_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('comment', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addIndex(['txn_id', 'stage'], 'pcash_act_txn_stage_ix');
        $table->addIndex(['actor_id', 'created_at'], 'pcash_act_actor_time_ix');
    }

    private function createAuditTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_audit')) {
            return;
        }

        $table = $schema->createTable('pcash_audit');
        $this->addId($table);
        $table->addColumn('entity_type', Types::STRING, ['length' => 32, 'notnull' => true]);
        $table->addColumn('entity_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('action', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('actor_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('metadata', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addIndex(['entity_type', 'entity_id'], 'pcash_aud_entity_ix');
        $table->addIndex(['actor_id', 'created_at'], 'pcash_aud_actor_time_ix');
        $table->addIndex(['action', 'created_at'], 'pcash_aud_action_time_ix');
    }
}
