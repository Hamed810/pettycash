<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version0520Date20260909000000 extends SimpleMigrationStep {

    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {

        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('pcash_decision')) {
            return $schema;
        }

        $table = $schema->getTable('pcash_decision');

        if ($table->hasColumn('role')) {
            $column = $table->getColumn('role');

            $column->setLength(16);
        }

        return $schema;
    }
}