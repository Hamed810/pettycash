<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version0510Date20260906130000 extends SimpleMigrationStep {

    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {

        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();


        /*
         * Transaction routing snapshot
         */

        if ($schema->hasTable('pcash_txn')) {

            $table = $schema->getTable('pcash_txn');


            if (!$table->hasColumn('destination_id')) {
                $table->addColumn(
                    'destination_id',
                    'bigint',
                    [
                        'notnull' => true,
                        'default' => 0,
                    ]
                );
            }


            if (!$table->hasColumn('manager1_id')) {
                $table->addColumn(
                    'manager1_id',
                    'string',
                    [
                        'length' => 64,
                        'notnull' => false,
                    ]
                );
            }


            if (!$table->hasColumn('manager2_id')) {
                $table->addColumn(
                    'manager2_id',
                    'string',
                    [
                        'length' => 64,
                        'notnull' => false,
                    ]
                );
            }
        }



        /*
         * Revision routing snapshot
         */

        if ($schema->hasTable('pcash_revision')) {

            $table = $schema->getTable('pcash_revision');


            if (!$table->hasColumn('destination_id')) {
                $table->addColumn(
                    'destination_id',
                    'bigint',
                    [
                        'notnull' => true,
                        'default' => 0,
                    ]
                );
            }


            if (!$table->hasColumn('manager1_id')) {
                $table->addColumn(
                    'manager1_id',
                    'string',
                    [
                        'length' => 64,
                        'notnull' => false,
                    ]
                );
            }


            if (!$table->hasColumn('manager2_id')) {
                $table->addColumn(
                    'manager2_id',
                    'string',
                    [
                        'length' => 64,
                        'notnull' => false,
                    ]
                );
            }
        }


        return $schema;
    }
}