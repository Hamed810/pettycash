<?php

declare(strict_types=1);

namespace OCA\PettyCash\Service;

final class PersianNumberService {
    private const PERSIAN_DIGITS = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    private const ARABIC_DIGITS = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    private const LATIN_DIGITS = ['0','1','2','3','4','5','6','7','8','9'];

    public function normalizeDigits(string $value): string {
        return str_replace(
            [...self::PERSIAN_DIGITS, ...self::ARABIC_DIGITS],
            [...self::LATIN_DIGITS, ...self::LATIN_DIGITS],
            $value,
        );
    }

    public function normalizePersianCharacters(string $value): string {
        return strtr($value, [
            'ي' => 'ی',
            'ى' => 'ی',
            'ك' => 'ک',
        ]);
    }

    public function normalize(string $value): string {
        return $this->normalizePersianCharacters(
            $this->normalizeDigits($value),
        );
    }
}
