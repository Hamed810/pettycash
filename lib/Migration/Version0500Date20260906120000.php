<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v2.0.0 schema changes.
 *
 * - pcash_project gains `type` (PROJECT default, OFFICE, MARKETING, ...)
 *   so it can act as the general "destination" concept without a
 *   disruptive table rename.
 * - pcash_list gains `list_type` (REGULAR default, BUSINESS_TRIP).
 * - pcash_txn gains `destination_id`, decoupling destination from the
 *   list. `pcash_list.project_id` is kept (not dropped) for backward
 *   compatibility during the transition; it is superseded by
 *   pcash_txn.destination_id going forward.
 * - New tables: pcash_manager_assignment, pcash_destination_owner,
 *   pcash_decision (append-only, never updated in place).
 * - Backfill: already-submitted transactions (list status != OPEN)
 *   get destination_id copied from their list's project_id.
 *   Still-open lists are left untouched; purchasers must re-tag each
 *   transaction with a destination before submitting.
 */
final class Version0500Date20260906120000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db
    ) {}

    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options,
    ): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $this->addDestinationType($schema);
        $this->addListType($schema);
        $this->addTransactionDestination($schema);
        $this->createManagerAssignmentTable($schema);
        $this->createDestinationOwnerTable($schema);
        $this->createDecisionTable($schema);

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

    private function addDestinationType(ISchemaWrapper $schema): void {
        if (!$schema->hasTable('pcash_project')) {
            return;
        }

        $table = $schema->getTable('pcash_project');

        if (!$table->hasColumn('type')) {
            $table->addColumn('type', Types::STRING, [
                'length' => 24,
                'notnull' => true,
                'default' => 'PROJECT',
            ]);
            $table->addIndex(['type'], 'pcash_proj_type_ix');
        }
    }

    private function addListType(ISchemaWrapper $schema): void {
        if (!$schema->hasTable('pcash_list')) {
            return;
        }

        $table = $schema->getTable('pcash_list');

        if (!$table->hasColumn('list_type')) {
            $table->addColumn('list_type', Types::STRING, [
                'length' => 24,
                'notnull' => true,
                'default' => 'REGULAR',
            ]);
        }
    }

    private function addTransactionDestination(ISchemaWrapper $schema): void {
        if (!$schema->hasTable('pcash_txn')) {
            return;
        }

        $table = $schema->getTable('pcash_txn');

        if (!$table->hasColumn('destination_id')) {
            $table->addColumn('destination_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $table->addIndex(['destination_id'], 'pcash_txn_dest_ix');
        }

        // Resolved at submission time from pcash_manager_assignment /
        // pcash_destination_owner and never recomputed afterward, so a
        // later reassignment does not retroactively change who is
        // responsible for an already-submitted transaction.
        if (!$table->hasColumn('manager1_id')) {
            $table->addColumn('manager1_id', Types::STRING, [
                'length' => 64,
                'notnull' => false,
            ]);
        }

        if (!$table->hasColumn('manager2_id')) {
            $table->addColumn('manager2_id', Types::STRING, [
                'length' => 64,
                'notnull' => false,
            ]);
        }
    }

    private function createManagerAssignmentTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_manager_assignment')) {
            return;
        }

        $table = $schema->createTable('pcash_manager_assignment');
        $this->addId($table);
        $table->addColumn('purchaser_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('manager_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('valid_from', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('valid_to', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addIndex(['purchaser_id', 'valid_to'], 'pcash_mgra_purch_active_ix');
        $table->addIndex(['manager_id'], 'pcash_mgra_manager_ix');
    }

    private function createDestinationOwnerTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_destination_owner')) {
            return;
        }

        $table = $schema->createTable('pcash_destination_owner');
        $this->addId($table);
        $table->addColumn('destination_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('owner_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('valid_from', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('valid_to', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addIndex(['destination_id', 'valid_to'], 'pcash_deso_dest_active_ix');
        $table->addIndex(['owner_id'], 'pcash_deso_owner_ix');
    }

    private function createDecisionTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('pcash_decision')) {
            return;
        }

        $table = $schema->createTable('pcash_decision');
        $this->addId($table);
        $table->addColumn('txn_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('revision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        $table->addColumn('role', Types::STRING, ['length' => 8, 'notnull' => true]); // M1 | M2
        $table->addColumn('decision', Types::STRING, ['length' => 24, 'notnull' => true]);
        $table->addColumn('reason', Types::TEXT, ['notnull' => false]);
        $table->addColumn('actor_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addIndex(['txn_id', 'role'], 'pcash_dec_txn_role_ix');
        $table->addIndex(['revision_id'], 'pcash_dec_rev_ix');
    }

    /**
     * Backfill destination_id on already-submitted transactions from
     * their list's current project_id. Still-open (unsubmitted) lists
     * are intentionally left untouched -- see class docblock.
     */
    public function postSchemaChange(
        IOutput $output,
        Closure $schemaClosure,
        array $options,
    ): void {
        $qb = $this->db->getQueryBuilder();

        $qb->select('t.id', 'l.project_id')
            ->from('pcash_txn', 't')
            ->innerJoin('t', 'pcash_list', 'l', $qb->expr()->eq('t.list_id', 'l.id'))
            ->where($qb->expr()->isNull('t.destination_id'))
            ->andWhere($qb->expr()->neq('l.status', $qb->createNamedParameter('OPEN')));

        $rows = $qb->executeQuery()->fetchAll();

        foreach ($rows as $row) {
            $update = $this->db->getQueryBuilder();
            $update->update('pcash_txn')
                ->set('destination_id', $update->createNamedParameter(
                    (int)$row['project_id'],
                    IQueryBuilder::PARAM_INT
                ))
                ->where($update->expr()->eq('id', $update->createNamedParameter(
                    (int)$row['id'],
                    IQueryBuilder::PARAM_INT
                )))
                ->executeStatement();
        }

        $output->info(sprintf(
            'v2.0.0: backfilled destination_id on %d already-submitted transaction(s).',
            count($rows)
        ));
    }
}
