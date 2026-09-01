<?php

declare(strict_types=1);

namespace OCA\PettyCash\Service;

final class MoneyService {
    public function __construct(
        private PersianNumberService $numbers,
    ) {
    }

    /**
     * Convert user-entered decimal text into an integer minor-unit amount.
     *
     * Examples:
     *   IRR (0 decimals): "۴۸,۰۰۰,۰۰۰" -> 48000000
     *   EUR (2 decimals): "125.75" -> 12575
     */
    public function parseToMinor(string $input, int $decimalPlaces): int {
        if ($decimalPlaces < 0 || $decimalPlaces > 6) {
            throw new \InvalidArgumentException('Unsupported decimal precision.');
        }

        $normalized = $this->numbers->normalizeDigits(trim($input));
        $normalized = str_replace([',', '٬', ' '], '', $normalized);
        $normalized = str_replace('٫', '.', $normalized);

        if (!preg_match('/^\+?\d+(?:\.\d+)?$/', $normalized)) {
            throw new \InvalidArgumentException('Invalid money amount.');
        }

        [$whole, $fraction] = array_pad(explode('.', ltrim($normalized, '+'), 2), 2, '');
        if (strlen($fraction) > $decimalPlaces) {
            throw new \InvalidArgumentException('Too many decimal places.');
        }

        $fraction = str_pad($fraction, $decimalPlaces, '0');
        $minor = ltrim($whole . $fraction, '0');
        $minor = $minor === '' ? '0' : $minor;

        if (strlen($minor) > 18 || (strlen($minor) === 18 && $minor > (string)PHP_INT_MAX)) {
            throw new \OverflowException('Amount is too large.');
        }

        return (int)$minor;
    }

    public function formatMinor(int $amountMinor, int $decimalPlaces): string {
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('Negative petty-cash amounts are not supported in the MVP.');
        }

        if ($decimalPlaces === 0) {
            return number_format($amountMinor, 0, '.', ',');
        }

        $factor = 10 ** $decimalPlaces;
        return number_format($amountMinor / $factor, $decimalPlaces, '.', ',');
    }
}
