<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version0300Date20260904000000 extends SimpleMigrationStep {

    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {

        $schema = $schemaClosure();

        if (!$schema->hasTable('pcash_list')) {
            return $schema;
        }

        $table = $schema->getTable('pcash_list');

        if (!$table->hasColumn('deleted')) {
            $table->addColumn('deleted', 'boolean', [
                'notnull' => true,
                'default' => false,
            ]);
        }

        if (!$table->hasColumn('deleted_at')) {
            $table->addColumn('bigint', 'bigint', [
                'notnull' => false,
            ]);
        }

        if (!$table->hasColumn('deleted_by')) {
            $table->addColumn('deleted_by', 'string', [
                'notnull' => false,
                'length' => 64,
            ]);
        }

        return $schema;
    }
}
