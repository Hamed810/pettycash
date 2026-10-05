<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Workflow\SheetStatus;

/**
 * The four facets FR-064 names for a sheet list: status, date, currency and creator.
 *
 * ## Why creator is not here
 *
 * FR-064 lists "creator" among the sheet facets and fixes its meaning: "a sheet's **creator** is its
 * owner". That makes it the *scope* of the list rather than one of its predicates -- `SheetService`
 * reads the owner from the trusted session, and `GET /sheets` refuses an `ownerId` naming anybody else
 * rather than widening the list. Carrying it as a fifth nullable field would have been an invitation to
 * pass a request parameter into the scope, which is exactly the hole `AccountController` refuses to
 * offer on `GET /accounts`.
 *
 * ## Why this lives in the domain
 *
 * The repository interface takes it, and a domain port cannot name an application-layer type without
 * inverting the dependency direction. Every facet here is a field the sheet aggregate actually has, so
 * the criteria object is a statement about sheets rather than about HTTP or about SQL.
 *
 * ## Why an inverted range is refused here and not by the caller
 *
 * `from` after `to` is a filter that matches nothing, and a filter that matches nothing is
 * indistinguishable from a sheet list that really is empty. The same reasoning as
 * `ReportFilterFactory` applies: refuse the combination at the point it is built, where the message can
 * still name which bounds are wrong.
 */
final readonly class SheetListFilter
{
    public function __construct(
        public ?SheetStatus $status = null,
        public ?CurrencyCode $currency = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null
    ) {
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException(sprintf(
                'A sheet date range cannot end before it starts; from=%s, to=%s',
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s')
            ));
        }
    }

    /**
     * Whether any facet is set, as opposed to only pagination.
     *
     * A narrowed list and an unnarrowed one are answered by the same code path; this exists so a caller
     * can say which one it got, which is what lets a test assert that a filter narrowed anything at all.
     */
    public function hasFacets(): bool
    {
        return $this->status !== null
            || $this->currency !== null
            || $this->from !== null
            || $this->to !== null;
    }
}