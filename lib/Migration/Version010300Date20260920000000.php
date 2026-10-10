<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Widens `pettycash_transactions.current_status` so every `TransactionStatus` backing value fits.
 *
 * The column was created as VARCHAR(24) by Version010100Date20260920000000, but
 * `AWAITING_COST_CENTER_REVIEW` is 27 characters and `AWAITING_PURCHASER_CORRECTION` is 29, so
 * the two states that carry a sheet into formal cost-center review and a transaction back to the
 * purchaser could never be written.
 *
 * Forward-only: the column is only widened. No row is rewritten, no value is reinterpreted, and
 * the `pettycash_transactions_sheet_state_idx` index keeps the same columns in the same order. At utf8mb4 the widest
 * possible index key is 8 + (24 * 4) + (32 * 4) = 232 bytes, well inside the limit every declared
 * V1 backend requires, so no index change is needed on any of them.
 */
final class Version010300Date20260920000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('pettycash_transactions')) {
            $schema->getTable('pettycash_transactions')->modifyColumn('current_status', [
                'notnull' => true,
                'length' => 32,
            ]);
        }

        return $schema;
    }
}
