<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use InvalidArgumentException;

/**
 * The one place a request's filter object becomes a `ReportFilter`.
 *
 * ## Why this is not a controller's private method
 *
 * `ReportController` grew a closed filter vocabulary and the coercion that goes with it in T309, both
 * private to it. `POST /exports` then needed the *same* rule for `ExportRequest.filters`, and the
 * available move was to write it again. Two copies of a vocabulary is one copy too many: they drift,
 * and when they do, a filter name means one thing on `GET /reports` and another on `POST /exports` —
 * which is the same class of defect as T307, where the filter and the rows each had a private idea of
 * the row vocabulary and neither matched the other.
 *
 * So the rule lives in the application layer and both controllers ask this class. The unit test
 * asserts the rule; the contract tests assert that both endpoints obey it.
 *
 * ## The vocabulary is closed, and that is a requirement rather than a hardening
 *
 * An unrecognised key is refused, not ignored. Ignoring it returns the *unfiltered* answer while the
 * caller believes their filter was applied, and nothing in the response distinguishes the two — the
 * most damaging possible failure for a financial report, and the one a client cannot detect at all.
 * The damaging case is the typo, because a misspelling of an accepted name is silently unfiltered
 * while looking filtered.
 *
 * ## Why each value is validated here rather than left to `ReportFilter`
 *
 * `ReportFilter` validates what it can (`from > to`, a negative bound). It cannot say *which request
 * key* was wrong, because it never sees the keys. The caller can, so the message names the key — the
 * difference between a client that fixes its request in one attempt and one that guesses.
 */
final class ReportFilterFactory
{
    /**
     * The filter names this application accepts, per FR-064.
     *
     * Public and asserted as data, because it is a contract fact rather than an implementation detail:
     * a client reads it out of a refusal message and a test iterates it. Two copies of this list would
     * be exactly the drift this class exists to prevent.
     */
    public const ACCEPTED_FILTERS = [
        'from', 'to', 'statuses', 'currencyCodes', 'costCenterDbIds', 'actorUids', 'eventTypes',
        'createdBy', 'expenseTypeDbIds', 'stages', 'amountMinMinor', 'amountMaxMinor',
    ];

    /**
     * Build a filter from an already-decoded filter object.
     *
     * Decoding is deliberately not this class's job. `GET /reports` receives `filters` as a query
     * parameter and therefore as a JSON *string*, while `POST /exports` receives a JSON body and
     * therefore an *array*; each controller owns how its own transport encodes the value, and both
     * hand the decoded object here. That keeps the rule single without making one endpoint's encoding
     * the other endpoint's problem.
     *
     * Pagination is passed separately because only the report pages. An export is the whole selection:
     * a paged export would understate `rowCount` and silently omit authorized records, so the export
     * path asks for a filter and calls `ReportQuery::narrow()` rather than `paginate()`.
     *
     * @param array<array-key, mixed> $decoded
     * @throws InvalidArgumentException for an unrecognised key, a value of the wrong shape, or a
     *         combination the filter itself refuses (an inverted range, a negative bound)
     */
    public function fromArray(array $decoded, int $page = 1, int $pageSize = ReportFilter::DEFAULT_PAGE_SIZE): ReportFilter
    {
        if ($decoded !== [] && array_is_list($decoded)) {
            // `is_array()` alone would accept `['statuses']` and then read nothing from it: silently
            // unfiltered again, by a different route.
            //
            // The empty array is the one exception, and it is not a special case so much as a PHP one:
            // `json_decode('{}', true)` is `[]`, which `array_is_list()` reports as a list, so a test for
            // emptiness is the only thing that can tell an empty filter object from an empty list. An
            // empty filter object is a caller that asked for no filter, and refusing it would fail an
            // unrelated client change for no reason a reader of the message could act on.
            throw new InvalidArgumentException(sprintf(
                'The `filters` value must be a JSON object of filter names, not a list; got %s',
                $this->describe($decoded)
            ));
        }

        $unknown = array_values(array_diff(array_keys($decoded), self::ACCEPTED_FILTERS));
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown filter%s %s. Accepted filters are: %s. An unrecognised filter is refused rather '
                . 'than ignored, because ignoring it returns the unfiltered answer and the caller cannot '
                . 'tell the difference.',
                count($unknown) === 1 ? '' : 's',
                '"' . implode('", "', $unknown) . '"',
                implode(', ', self::ACCEPTED_FILTERS)
            ));
        }

        return new ReportFilter(
            $page,
            $pageSize,
            $this->date($decoded, 'from'),
            $this->date($decoded, 'to'),
            $this->strings($decoded, 'statuses'),
            $this->strings($decoded, 'currencyCodes'),
            $this->ints($decoded, 'costCenterDbIds'),
            $this->strings($decoded, 'actorUids'),
            $this->strings($decoded, 'eventTypes'),
            isset($decoded['createdBy']) ? (string) $decoded['createdBy'] : null,
            $this->ints($decoded, 'expenseTypeDbIds'),
            $this->strings($decoded, 'stages'),
            $this->bound($decoded, 'amountMinMinor'),
            $this->bound($decoded, 'amountMaxMinor')
        );
    }

    private function date(array $decoded, string $key): ?\DateTimeImmutable
    {
        if (!isset($decoded[$key])) {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $decoded[$key]);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Filter '{$key}' is not a valid date: " . $e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function strings(array $decoded, string $key): array
    {
        if (!isset($decoded[$key])) {
            return [];
        }
        if (!is_array($decoded[$key])) {
            throw new InvalidArgumentException("Filter '{$key}' must be an array of strings.");
        }

        return array_values(array_map(static fn ($value): string => (string) $value, $decoded[$key]));
    }

    /**
     * @return list<int>
     */
    private function ints(array $decoded, string $key): array
    {
        if (!isset($decoded[$key])) {
            return [];
        }
        if (!is_array($decoded[$key])) {
            throw new InvalidArgumentException("Filter '{$key}' must be an array of integers.");
        }

        return array_values(array_map(static fn ($value): int => (int) $value, $decoded[$key]));
    }

    /**
     * One inclusive amount bound, in the minor units of the row's own currency.
     *
     * A bound is a single integer rather than a list, and is validated here rather than left to
     * `ReportFilter` alone so the caller is told which *key* was wrong.
     */
    private function bound(array $decoded, string $key): ?int
    {
        if (!isset($decoded[$key])) {
            return null;
        }
        if (!is_int($decoded[$key]) && !(is_string($decoded[$key]) && ctype_digit($decoded[$key]))) {
            throw new InvalidArgumentException(
                "Filter '{$key}' must be a whole number of minor units; got "
                . $this->describe($decoded[$key])
            );
        }

        return (int) $decoded[$key];
    }

    /**
     * A rejected value, quoted and truncated.
     *
     * Arrays are rendered as JSON rather than as `array`, because the one place an array is refused
     * is the list check below, and "not a list; got array" tells a client nothing about what it sent.
     */
    private function describe(mixed $value): string
    {
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES);
            $text = $json === false ? 'an array' : $json;
        } elseif (is_scalar($value) || $value === null) {
            $text = var_export($value, true);
        } else {
            $text = get_debug_type($value);
        }

        return strlen($text) > 60 ? substr($text, 0, 60) . '...' : $text;
    }
}
