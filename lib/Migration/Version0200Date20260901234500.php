<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version0200Date20260901234500 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {}

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $now = time();

        foreach ([
            ['IRR', 'Iranian Rial', '﷼', 0, true],
            ['EUR', 'Euro', '€', 2, false],
            ['USD', 'US Dollar', '$', 2, false],
            ['AED', 'UAE Dirham', 'د.إ', 2, false],
            ['CNY', 'Chinese Yuan / Renminbi', '¥', 2, false],
        ] as [$code, $name, $symbol, $decimals, $default]) {
            if (!$this->exists('pcash_currency', 'code', $code)) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('pcash_currency')->values([
                    'code' => $qb->createNamedParameter($code),
                    'name' => $qb->createNamedParameter($name),
                    'symbol' => $qb->createNamedParameter($symbol),
                    'decimal_places' => $qb->createNamedParameter($decimals, IQueryBuilder::PARAM_INT),
                    'is_default' => $qb->createNamedParameter($default, IQueryBuilder::PARAM_BOOL),
                    'active' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                ])->executeStatement();
            }
        }

        $categories = [
            ['fuel', 'Fuel', true, true, true, false, false, false, 10],
            ['vehicle_maintenance', 'Vehicle Maintenance', true, true, true, false, false, false, 20],
            ['vehicle_repair', 'Vehicle Repair', true, true, true, false, false, false, 30],
            ['vehicle_spare_parts', 'Vehicle Spare Parts', true, true, true, false, false, false, 40],
            ['parking', 'Parking', true, false, false, false, false, false, 50],
            ['toll', 'Toll / Road Charges', true, false, false, false, false, false, 60],
            ['transportation', 'Transportation', true, false, false, false, false, false, 70],
            ['tools', 'Tools', true, false, false, false, false, false, 80],
            ['materials', 'Materials', true, false, false, false, false, false, 90],
            ['office_supplies', 'Office Supplies', true, false, false, false, false, false, 100],
            ['temporary_employee', 'Temporary / Daily Employee', false, false, false, true, true, true, 110],
            ['services', 'Services', true, false, false, false, false, false, 120],
            ['miscellaneous', 'Miscellaneous', true, false, false, false, false, false, 130],
        ];

        foreach ($categories as [$code, $name, $receipt, $vehicle, $odometer, $worker, $permit, $attendance, $sort]) {
            if (!$this->exists('pcash_category', 'code', $code)) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('pcash_category')->values([
                    'code' => $qb->createNamedParameter($code),
                    'name' => $qb->createNamedParameter($name),
                    'description' => $qb->createNamedParameter(null),
                    'receipt_required' => $qb->createNamedParameter($receipt, IQueryBuilder::PARAM_BOOL),
                    'vehicle_required' => $qb->createNamedParameter($vehicle, IQueryBuilder::PARAM_BOOL),
                    'odometer_required' => $qb->createNamedParameter($odometer, IQueryBuilder::PARAM_BOOL),
                    'worker_required' => $qb->createNamedParameter($worker, IQueryBuilder::PARAM_BOOL),
                    'permit_required' => $qb->createNamedParameter($permit, IQueryBuilder::PARAM_BOOL),
                    'attendance_required' => $qb->createNamedParameter($attendance, IQueryBuilder::PARAM_BOOL),
                    'active' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
                    'sort_order' => $qb->createNamedParameter($sort, IQueryBuilder::PARAM_INT),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                ])->executeStatement();
            }
        }
    }

    private function exists(string $table, string $column, string $value): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($table)
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($value)))
            ->setMaxResults(1);
        return $qb->executeQuery()->fetchOne() !== false;
    }
}
