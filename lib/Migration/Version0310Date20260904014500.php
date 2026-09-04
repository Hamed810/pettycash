<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version0310Date20260904014500 extends SimpleMigrationStep {

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

        // Remove the accidental column created by Version0300
        if ($table->hasColumn('bigint')) {
            $table->dropColumn('bigint');
        }

        // Add the correct column
        if (!$table->hasColumn('deleted_at')) {
            $table->addColumn('deleted_at', 'bigint', [
                'notnull' => false,
            ]);
        }

        return $schema;
    }
}
