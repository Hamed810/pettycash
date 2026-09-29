<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The server-side filter and pagination parameters of a report or dashboard query.
 *
 * ## Why the page size is clamped rather than rejected
 *
 * `contracts/exports.md` and the report contract fix a **maximum page size of 100**. A request for
 * 5,000 is not a caller making a mistake worth a 422 — it is a client that sends a "give me
 * everything" value and expects the server to bound it, which is precisely what "server-side filters"
 * means. So an over-large page size is clamped down to the maximum and an under-large or absent one
 * falls back to the default. A negative or zero page number *is* a mistake and is refused, because
 * there is no sensible bound to fall back to and a silently swapped page number would return the wrong
 * records without saying so.
 *
 * ## Why filters are values and not a bag of nullable arguments
 *
 * A report is scoped *before* filtering, aggregation or generation. Carrying the scope and the filters
 * together in one immutable object makes the order structural rather than a convention each query has
 * to remember: `ReportQuery` takes a `ReportScope` and a `ReportFilter`, and applies the scope first.
 * It also means a filter cannot be quietly dropped between the controller and the query, because
 * there is nowhere else for it to live.
 */
final readonly class ReportFilter
{
    /** The contract's maximum. A request for more is bounded down to this. */
    public const MAX_PAGE_SIZE = 100;

    public const DEFAULT_PAGE_SIZE = 25;

    public int $page;

    public int $pageSize;

    public int $offset;

    /**
     * @param list<string> $statuses          workflow or sheet statuses to include; empty means no filter
     * @param list<string> $currencyCodes     currency codes to include; empty means no filter
     * @param list<int>    $costCenterDbIds   cost centres to include; empty means no filter
     * @param list<string> $actorUids         audit actors to include; empty means no filter
     * @param list<string> $eventTypes        audit event types to include; empty means no filter
     * @param list<int>    $expenseTypeDbIds  expense types to include; empty means no filter
     * @param list<string> $stages            workflow stages to include; empty means no filter
     * @param int|null     $amountMinMinor    inclusive lower bound on the active revision's amount
     * @param int|null     $amountMaxMinor    inclusive upper bound on the active revision's amount
     */
    public function __construct(
        int $page = 1,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public array $statuses = [],
        public array $currencyCodes = [],
        public array $costCenterDbIds = [],
        public array $actorUids = [],
        public array $eventTypes = [],
        public ?string $createdBy = null,
        public array $expenseTypeDbIds = [],
        public array $stages = [],
        public ?int $amountMinMinor = null,
        public ?int $amountMaxMinor = null
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException("Report page must be 1 or greater; {$page} was requested");
        }
        if ($pageSize < 1) {
            throw new InvalidArgumentException("Report page size must be 1 or greater; {$pageSize} was requested");
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('A report date range cannot end before it starts');
        }
        if ($amountMinMinor !== null && $amountMaxMinor !== null && $amountMinMinor > $amountMaxMinor) {
            throw new InvalidArgumentException(
                "A report amount range cannot end below where it starts; {$amountMinMinor} > {$amountMaxMinor}"
            );
        }
        if ($amountMinMinor !== null && $amountMinMinor < 0) {
            throw new InvalidArgumentException('A report amount bound cannot be negative');
        }
        if ($amountMaxMinor !== null && $amountMaxMinor < 0) {
            throw new InvalidArgumentException('A report amount bound cannot be negative');
        }

        // Clamped, not rejected — see the class docblock.
        $this->pageSize = min($pageSize, self::MAX_PAGE_SIZE);
        $this->page = $page;
        $this->offset = ($page - 1) * $this->pageSize;
    }

    public function hasDateRange(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    public function hasStatusFilter(): bool
    {
        return $this->statuses !== [];
    }

    public function hasCostCenterFilter(): bool
    {
        return $this->costCenterDbIds !== [];
    }

    public function hasAmountRange(): bool
    {
        return $this->amountMinMinor !== null || $this->amountMaxMinor !== null;
    }

    /**
     * Whether any field-level filter is set, as opposed to only pagination.
     *
     * Used to distinguish "everything in scope" from "a filtered slice", because a report that claims
     * to be filtered and is not is a report whose totals cannot be reconciled with its rows.
     */
    public function hasFieldFilters(): bool
    {
        return $this->hasDateRange()
            || $this->hasStatusFilter()
            || $this->hasCostCenterFilter()
            || $this->currencyCodes !== []
            || $this->actorUids !== []
            || $this->eventTypes !== []
            || $this->expenseTypeDbIds !== []
            || $this->stages !== []
            || $this->hasAmountRange()
            || $this->createdBy !== null;
    }

    /**
     * The same filter with the given page, preserving everything else.
     */
    public function withPage(int $page): self
    {
        return new self(
            $page,
            $this->pageSize,
            $this->from,
            $this->to,
            $this->statuses,
            $this->currencyCodes,
            $this->costCenterDbIds,
            $this->actorUids,
            $this->eventTypes,
            $this->createdBy,
            $this->expenseTypeDbIds,
            $this->stages,
            $this->amountMinMinor,
            $this->amountMaxMinor
        );
    }
}
