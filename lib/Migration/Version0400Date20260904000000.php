<?php

declare(strict_types=1);

namespace OCA\PettyCash\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version0400Date20260904000000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db
    ) {}

    public function postSchemaChange(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): void {

        $now = time();

        /*
         * Default currencies
         */

        $currencies = [
            ['IRR', 'Iranian Rial', '﷼', 0, true],
            ['USD', 'US Dollar', '$', 2, false],
            ['EUR', 'Euro', '€', 2, false],
            ['AED', 'UAE Dirham', 'د.إ', 2, false],
        ];

        foreach ($currencies as [
            $code,
            $name,
            $symbol,
            $decimal,
            $default
        ]) {

            if (!$this->exists(
                'pcash_currency',
                'code',
                $code
            )) {

                $qb = $this->db->getQueryBuilder();

                $qb->insert('pcash_currency')
                    ->values([

                        'code' => $qb->createNamedParameter($code),

                        'name' => $qb->createNamedParameter($name),

                        'symbol' => $qb->createNamedParameter($symbol),

                        'decimal_places' => $qb->createNamedParameter(
                            $decimal,
                            IQueryBuilder::PARAM_INT
                        ),

                        'is_default' => $qb->createNamedParameter(
                            $default,
                            IQueryBuilder::PARAM_BOOL
                        ),

                        'active' => $qb->createNamedParameter(
                            true,
                            IQueryBuilder::PARAM_BOOL
                        ),

                        'created_at' => $qb->createNamedParameter(
                            $now,
                            IQueryBuilder::PARAM_INT
                        ),

                        'updated_at' => $qb->createNamedParameter(
                            $now,
                            IQueryBuilder::PARAM_INT
                        ),

                    ])
                    ->executeStatement();
            }
        }


        /*
         * Default expense categories
         */

        $categories = [
            [
                'fuel',
                'Fuel',
                true,
                true,
                true
            ],
            [
                'vehicle_maintenance',
                'Vehicle Maintenance',
                true,
                true,
                false
            ],
            [
                'transportation',
                'Transportation',
                true,
                false,
                false
            ],
            [
                'office_supplies',
                'Office Supplies',
                true,
                false,
                false
            ],
            [
                'services',
                'Services',
                true,
                false,
                false
            ],
            [
                'miscellaneous',
                'Miscellaneous',
                false,
                false,
                false
            ],
        ];


        $sort = 10;

        foreach ($categories as [
            $code,
            $name,
            $receipt,
            $vehicle,
            $odometer
        ]) {

            if (!$this->exists(
                'pcash_category',
                'code',
                $code
            )) {

                $qb = $this->db->getQueryBuilder();

                $qb->insert('pcash_category')
                    ->values([

                        'code' => $qb->createNamedParameter($code),

                        'name' => $qb->createNamedParameter($name),

                        'description' => $qb->createNamedParameter(null),

                        'receipt_required' => $qb->createNamedParameter(
                            $receipt,
                            IQueryBuilder::PARAM_BOOL
                        ),

                        'vehicle_required' => $qb->createNamedParameter(
                            $vehicle,
                            IQueryBuilder::PARAM_BOOL
                        ),

                        'odometer_required' => $qb->createNamedParameter(
                            $odometer,
                            IQueryBuilder::PARAM_BOOL
                        ),

                        'worker_required' => $qb->createNamedParameter(
                            false,
                            IQueryBuilder::PARAM_BOOL
                        ),

                        'permit_required' => $qb->createNamedParameter(
                            false,
                            IQueryBuilder::PARAM_BOOL
                        ),

                        'attendance_required' => $qb->createNamedParameter(
                            false,
                            IQueryBuilder::PARAM_BOOL
                        ),

                        'active' => $qb->createNamedParameter(
                            true,
                            IQueryBuilder::PARAM_BOOL
                        ),

                        'sort_order' => $qb->createNamedParameter(
                            $sort,
                            IQueryBuilder::PARAM_INT
                        ),

                        'created_at' => $qb->createNamedParameter(
                            $now,
                            IQueryBuilder::PARAM_INT
                        ),

                        'updated_at' => $qb->createNamedParameter(
                            $now,
                            IQueryBuilder::PARAM_INT
                        ),

                    ])
                    ->executeStatement();

                $sort += 10;
            }
        }
    }


    private function exists(
        string $table,
        string $column,
        string $value
    ): bool {

        $qb = $this->db->getQueryBuilder();

        $qb->select('id')
            ->from($table)
            ->where(
                $qb->expr()->eq(
                    $column,
                    $qb->createNamedParameter($value)
                )
            )
            ->setMaxResults(1);

        return $qb
            ->executeQuery()
            ->fetchOne() !== false;
    }
}