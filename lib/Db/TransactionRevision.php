<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\Entity;

final class TransactionRevision extends Entity {

    protected int $txnId = 0;

    /**
     * Incrementing revision number.
     */
    protected int $revisionNumber = 1;


    /*
     * Financial snapshot
     */

    protected int $categoryId = 0;

    protected int $currencyId = 0;

    protected int $amountMinor = 0;


    /*
     * v2 Routing snapshot
     *
     * These values must never be recalculated later.
     *
     * They represent who was responsible
     * at the time of submission/revision.
     */

    protected int $destinationId = 0;

    protected ?string $manager1Id = null;

    protected ?string $manager2Id = null;


    /*
     * Transaction details snapshot
     */

    protected string $purchaseDate = '';

    protected string $description = '';

    protected ?string $vendor = null;


    /*
     * Vehicle information snapshot
     */

    protected ?int $vehicleId = null;

    protected ?int $odometerKm = null;


    /*
     * Worker information snapshot
     */

    protected ?string $workerName = null;

    protected ?string $workerReference = null;


    /*
     * Work time snapshot
     */

    protected ?int $workDays = null;

    protected ?int $workMinutes = null;

    protected ?string $workDescription = null;


    /*
     * Audit information
     */

    protected string $changedBy = '';

    protected ?string $changeReason = null;

    protected int $createdAt = 0;


    public function __construct() {

        foreach ([
            'txnId',
            'revisionNumber',
            'categoryId',
            'currencyId',
            'amountMinor',
            'destinationId',
            'vehicleId',
            'odometerKm',
            'workDays',
            'workMinutes',
            'createdAt',
        ] as $field) {

            $this->addType($field, 'integer');
        }
    }
}