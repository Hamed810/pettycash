<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Domain\Expense\SheetListFilter;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Workflow\SheetStatus;

/**
 * The one place `GET /sheets`'s query strings become a {@see SheetListFilter}.
 *
 * ## Why this is not a private method of `SheetController`
 *
 * `ReportFilterFactory` exists because the same vocabulary was written twice — once for
 * `GET /reports` and once for `POST /exports` — and the two copies drifted until a filter name meant
 * one thing on one endpoint and another on the other. The sheet list has a second consumer coming:
 * the management and finance views that will want the same four facets. The rule belongs in the
 * application layer so the second caller asks this class rather than writing it again.
 *
 * ## Why every unrecognised value is refused
 *
 * FR-064 requires it in as many words: "A filter naming any field outside this set MUST be refused
 * with a 422 naming the accepted filters, and MUST NOT be ignored -- an unrecognised filter silently
 * returning the unfiltered report is a wrong answer, not an empty one." An unknown `status` is the
 * damaging case, because a misspelling of `OPEN` as `OPENN` looks filtered and is not.
 *
 * ## Why a well-formed currency code that names no configured currency is *not* refused
 *
 * `T329` refused an unresolvable **identifier** rather than dropping it, because dropping it returns
 * the superset while the caller believes the filter applied. A currency code is a value rather than an
 * identifier: a sheet's currency is a foreign key into `pettycash_currencies`, so a code no configured
 * currency carries cannot be any sheet's currency, and the truthful answer to "show me sheets in XYZ" is
 * an empty page. Refusing it would need a second lookup on every list request to prove a negative, and
 * would make a filter that *was* applied indistinguishable from a typo — the opposite of the rule. The
 * code must still be three uppercase letters, which is the contract's `minLength`/`maxLength` and
 * `CurrencyCode`'s own invariant.
 */
final class SheetListFilterFactory
{
    /**
     * The filter names `GET /sheets` accepts, per `contracts/ocs-api.yaml` and FR-064.
     *
     * Public and asserted as data for the same reason `ReportFilterFactory::ACCEPTED_FILTERS` is: a
     * client reads the accepted set out of a refusal message, and a test iterates it. `ownerId` is
     * listed because the contract declares it, but it is answered by the *scope* rather than by this
     * filter — see {@see SheetListFilter}.
     */
    public const ACCEPTED_FILTERS = ['status', 'ownerId', 'currency', 'from', 'to'];

    /**
     * @throws InvalidArgumentException for an unrecognised `status`, a currency that is not three
     *         uppercase letters, a date that is neither `YYYY-MM-DD` nor a full ISO-8601 instant, or an
     *         inverted range
     */
    public function fromQuery(
        ?string $status,
        ?string $currency,
        ?string $from,
        ?string $to
    ): SheetListFilter {
        return new SheetListFilter(
            $this->status($status),
            $this->currency($currency),
            $this->date($from, 'from', false),
            $this->date($to, 'to', true)
        );
    }

    private function status(?string $value): ?SheetStatus
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $candidate = strtoupper(trim($value));
        $status = SheetStatus::tryFrom($candidate);
        if ($status === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown filter value for "status": "%s". Accepted values are: %s. An unrecognised value '
                . 'is refused rather than ignored, because ignoring it returns the unfiltered answer and '
                . 'the caller cannot tell the difference.',
                $value,
                $this->statusVocabulary()
            ));
        }

        return $status;
    }

    private function currency(?string $value): ?CurrencyCode
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CurrencyCode::fromString(strtoupper(trim($value)));
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(sprintf(
                'Unknown filter value for "currency": "%s". %s',
                $value,
                $e->getMessage()
            ));
        }
    }

    /**
     * One bound of the date range.
     *
     * The contract declares both bounds as `format: date`, so `2026-10-04` means **the whole of that
     * day**. Reading it as midnight would silently exclude every sheet created during it, which is the
     * filter-appears-to-do-nothing failure this class exists to prevent — a range ending on the only day
     * that has rows would return nothing while looking correctly bounded. A full ISO-8601 instant is also
     * accepted because it is unambiguous rather than open to interpretation; it is used exactly as given.
     *
     * `$endOfDay` is what separates the two bounds: the start of a day is its default time, the end of one
     * is its last second.
     */
    private function date(?string $value, string $key, bool $endOfDay): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $candidate = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) === 1) {
            $parsed = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $candidate,
                new \DateTimeZone('UTC')
            );
            $errors = DateTimeImmutable::getLastErrors();
            // `getLastErrors()` returns `false` rather than an array when there were none, so a strict
            // comparison against `false` is what recognises a clean parse.
            if ($parsed === false || $errors !== false) {
                throw new InvalidArgumentException(sprintf(
                    'Filter "%s" is not a valid date: "%s". Expected YYYY-MM-DD or an ISO-8601 instant.',
                    $key,
                    $candidate
                ));
            }

            return $endOfDay ? $parsed->setTime(23, 59, 59) : $parsed;
        }

        try {
            return new DateTimeImmutable($candidate);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(sprintf(
                'Filter "%s" is not a valid date: "%s". Expected YYYY-MM-DD or an ISO-8601 instant.',
                $key,
                $candidate
            ));
        }
    }

    private function statusVocabulary(): string
    {
        return implode(', ', array_map(
            static fn (SheetStatus $case): string => $case->value,
            SheetStatus::cases()
        ));
    }
}