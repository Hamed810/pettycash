<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Shared;

use InvalidArgumentException;

/**
 * The `page` and `pageSize` a paged OCS endpoint was asked for.
 *
 * ## Why this exists
 *
 * `contracts/ocs-api.yaml` declares `Page` and `PageSize` **once**, in `components/parameters`
 * (`:766-772`), and `$ref`s them into every paged operation: the queues, the account movements, the
 * reports, the exports, and the audit pages. They are therefore one rule with many call sites, and
 * every call site that re-derives the bounds re-opens the chance of the copies disagreeing.
 *
 * That has already happened once. T106 records it: the report surface was built returning
 * `items`/`offset` where the contract says `PageMeta` (`page`, `pageSize`, `total`) plus
 * `columns`/`rows`/`summary`, and the report type enum had drifted from the contract's at the same
 * time. Both were corrected against the artifact. T319 is the same failure on the audit page: the
 * endpoint declared `Page`/`pageSize` and then answered with a bare array, using neither.
 *
 * `ReportFilter` already encodes these bounds for the reporting surface and is left alone — it
 * carries a dozen report-specific filters that mean nothing to an audit page, so it is not something
 * a second feature should have to reach into. **This class is the part of it that is genuinely
 * shared**, extracted rather than copied. Where the two overlap — `MAX_PAGE_SIZE`, `DEFAULT_PAGE_SIZE`
 * — they are asserted equal by `PageBoundsTest`, so a future divergence fails the suite instead of
 * producing two endpoints with different limits.
 *
 * ## Why the page size is clamped rather than rejected
 *
 * This is `ReportFilter`'s documented rule (`:13-21`), restated because it is not the obvious one.
 * The contract fixes `maximum: 100`. A request for 5,000 is not a caller making a mistake worth a
 * 422 — it is a client that sends a "give me everything" value and expects the server to bound it,
 * which is precisely what a server-side maximum means. So an over-large page size is clamped down
 * and an under-large or absent one falls back to the default.
 *
 * A page number or page size below the minimum *is* a mistake and is refused: there is no sensible
 * bound to fall back to, and silently swapping it would return the wrong records without saying so.
 * On an audit page that is worse than wrong — the caller would believe it had seen the whole
 * history of a transaction.
 *
 * ## Why `total` is not here
 *
 * `PageBounds` knows which window was asked for. It cannot know how many records match it, because
 * that depends on the caller's scope and belongs to whatever performs the query. Carrying a `total`
 * here would be a field that is trivially wrong, so it is the query's to produce.
 */
final readonly class PageBounds
{
    /** `contracts/ocs-api.yaml` `PageSize.maximum`. A request beyond it is bounded down to this. */
    public const MAX_PAGE_SIZE = 100;

    /** `contracts/ocs-api.yaml` `PageSize.default`. */
    public const DEFAULT_PAGE_SIZE = 25;

    /** `contracts/ocs-api.yaml` `Page.default`. */
    public const DEFAULT_PAGE = 1;

    public int $page;

    public int $pageSize;

    public int $offset;

    /**
     * @param int|null $page     absent means the contract default; below the minimum is refused
     * @param int|null $pageSize absent means the contract default; below the minimum is refused,
     *                           above `MAX_PAGE_SIZE` is clamped
     */
    public function __construct(?int $page = null, ?int $pageSize = null)
    {
        $page = $page ?? self::DEFAULT_PAGE;
        $pageSize = $pageSize ?? self::DEFAULT_PAGE_SIZE;

        if ($page < 1) {
            throw new InvalidArgumentException("Page must be 1 or greater; {$page} was requested");
        }
        if ($pageSize < 1) {
            throw new InvalidArgumentException(
                "Page size must be 1 or greater; {$pageSize} was requested"
            );
        }

        $this->page = $page;
        $this->pageSize = min($pageSize, self::MAX_PAGE_SIZE);
        $this->offset = ($page - 1) * $this->pageSize;
    }

    /**
     * The response members `contracts/ocs-api.yaml`'s `PageMeta` requires.
     *
     * `total` is passed in rather than derived, because it counts the whole scoped set and only the
     * caller knows the scope. `ReportFilter`'s counterpart asserts the same distinction: the account
     * page's total "counts the ledger, not the page".
     *
     * @return array{page: int, pageSize: int, total: int}
     */
    public function pageMeta(int $total): array
    {
        return [
            'page' => $this->page,
            'pageSize' => $this->pageSize,
            'total' => $total,
        ];
    }
}
