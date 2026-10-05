<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Evidence;

use DateTimeImmutable;

final class Evidence
{
    public function __construct(
        public string $id,
        public int $transactionId,
        public int $originRevisionId,
        public string $appDataKey,
        public string $originalName,
        public string $mediaType,
        public int $sizeBytes,
        public string $checksum,
        public bool $active = true,
        public ?string $uploadedBy = null,
        public ?DateTimeImmutable $uploadedAt = null,
        public ?string $deactivatedBy = null,
        public ?DateTimeImmutable $deactivatedAt = null,
        public ?string $deactivationReason = null,
        public ?int $dbId = null,
        public ?string $publicId = null,
        /**
         * The owning transaction's **public** uuid, paired with `$transactionId` exactly as
         * `TransactionRevision::$expenseTypeId` is paired with `$expenseTypeDbId`.
         *
         * `contracts/ocs-api.yaml` declares `Evidence.transactionId` as `format: uuid` and lists it
         * among the required members, while this property is an `int` read from
         * `(int) $row['transaction_id']` — so **both emitters of this schema published a surrogate key
         * in a uuid field**, and `src/types/api.ts` typed the field `string`, which made that a runtime
         * violation `npm run typecheck` cannot see (T339).
         *
         * Trailing and optional for the same reason `TransactionRevision::$expenseTypeId` is: the join
         * keys stay available to the repository, so no query is rewritten and no existing construction
         * site has to change.
         */
        public ?string $transactionPublicId = null,
        /**
         * The revision this evidence was uploaded against, as a public uuid — for the same reason and
         * with the same pairing as `$transactionPublicId`.
         */
        public ?string $originRevisionPublicId = null
    ) {}

    public function deactivate(string $deactivatedBy, string $reason): void
    {
        $this->active = false;
        $this->deactivatedBy = $deactivatedBy;
        $this->deactivatedAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->deactivationReason = $reason;
    }
}