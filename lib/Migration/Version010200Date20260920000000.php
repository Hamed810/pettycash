<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version010200Date20260920000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */ $schema = $schemaClosure();
        if (!$schema->hasTable('pettycash_evidence')) {
            $t = $schema->createTable('pettycash_evidence'); $this->id($t); $this->publicId($t, 'evidence');
            $t->addColumn('transaction_id', Types::BIGINT, ['unsigned' => true]); $t->addColumn('origin_revision_id', Types::BIGINT, ['unsigned' => true]);
            $t->addColumn('appdata_key', Types::STRING, ['length' => 1024]); $t->addColumn('original_name', Types::STRING, ['length' => 255]);
            $t->addColumn('media_type', Types::STRING, ['length' => 255]); $t->addColumn('size_bytes', Types::BIGINT); $t->addColumn('checksum', Types::STRING, ['length' => 128]);
            $t->addColumn('active', Types::BOOLEAN, ['default' => true]); $t->addColumn('uploaded_by', Types::STRING, ['length' => 64]); $t->addColumn('uploaded_at', Types::DATETIME_IMMUTABLE);
            $t->addColumn('deactivated_by', Types::STRING, ['length' => 64, 'notnull' => false]); $t->addColumn('deactivated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]); $t->addColumn('deactivation_reason', Types::TEXT, ['notnull' => false]);
            $t->addIndex(['transaction_id', 'active'], 'pettycash_evidence_transaction_active_idx');
        }
        if (!$schema->hasTable('pettycash_evidence_links')) {
            $t = $schema->createTable('pettycash_evidence_links'); $this->id($t); $this->publicId($t, 'evidence_links');
            $t->addColumn('evidence_id', Types::BIGINT, ['unsigned' => true]); $t->addColumn('token_hash', Types::STRING, ['length' => 128]); $t->addColumn('active', Types::BOOLEAN, ['default' => false]);
            $t->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]); $t->addColumn('created_by', Types::STRING, ['length' => 64]); $t->addColumn('created_at', Types::DATETIME_IMMUTABLE); $t->addColumn('deactivated_by', Types::STRING, ['length' => 64, 'notnull' => false]); $t->addColumn('deactivated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $t->addUniqueIndex(['token_hash'], 'pettycash_evidence_links_token_uniq');
        }
        if (!$schema->hasTable('pettycash_transaction_audit')) { $this->auditTable($schema->createTable('pettycash_transaction_audit'), true, 'transaction_audit'); }
        if (!$schema->hasTable('pettycash_configuration_audit')) { $this->auditTable($schema->createTable('pettycash_configuration_audit'), false, 'configuration_audit'); }
        if (!$schema->hasTable('pettycash_command_results')) {
            $t = $schema->createTable('pettycash_command_results'); $this->id($t);
            $t->addColumn('command_id', Types::STRING, ['length' => 36]); $t->addColumn('payload_hash', Types::STRING, ['length' => 64]); $t->addColumn('response_json', Types::TEXT); $t->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $t->addUniqueIndex(['command_id'], 'pettycash_command_results_command_id_uniq');
        }
        return $schema;
    }
    private function auditTable(object $t, bool $transaction, string $name): void {
        $this->id($t); $this->publicId($t, $name);
        if ($transaction) { $t->addColumn('sheet_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]); $t->addColumn('transaction_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]); $t->addColumn('revision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]); $t->addColumn('stage', Types::STRING, ['length' => 24, 'notnull' => false]); $t->addColumn('decision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]); }
        else { $t->addColumn('entity_type', Types::STRING, ['length' => 64]); $t->addColumn('entity_id', Types::STRING, ['length' => 64]); }
        $t->addColumn('event_type', Types::STRING, ['length' => 48]); $t->addColumn('actor_uid', Types::STRING, ['length' => 64]); $t->addColumn('actor_name', Types::STRING, ['length' => 255]); $t->addColumn('actor_role', Types::STRING, ['length' => 32]);
        $t->addColumn('explanation', Types::TEXT, ['notnull' => false]); $t->addColumn('before_json', Types::TEXT, ['notnull' => false]); $t->addColumn('after_json', Types::TEXT, ['notnull' => false]); $t->addColumn('command_id', Types::STRING, ['length' => 36]); $t->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $t->addIndex(['occurred_at'], $transaction ? 'pettycash_transaction_audit_occurred_at_idx' : 'pettycash_configuration_audit_occurred_at_idx');
    }
    private function id(object $t): void { $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true]); $t->setPrimaryKey(['id']); }
    private function publicId(object $t, string $name): void { $t->addColumn('public_id', Types::STRING, ['length' => 36]); $t->addUniqueIndex(['public_id'], 'pettycash_' . $name . '_public_id_uniq'); }
}
