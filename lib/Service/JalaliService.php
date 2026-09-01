<?php

declare(strict_types=1);

namespace OCA\PettyCash\Service;

use DateTimeInterface;
use IntlDateFormatter;

final class JalaliService {
    public function __construct(
        private AppConfigService $config,
    ) {
    }

    /**
     * Format a Gregorian/UTC-backed date using the Persian calendar.
     * ASCII digits are used for predictable API/display interoperability.
     */
    public function formatDate(DateTimeInterface $date, string $pattern = 'yyyy/MM/dd'): string {
        $formatter = new IntlDateFormatter(
            'en_US@calendar=persian',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $this->config->getBusinessTimezone(),
            IntlDateFormatter::TRADITIONAL,
            $pattern,
        );

        $formatted = $formatter->format($date);
        if ($formatted === false) {
            throw new \RuntimeException('Unable to format Jalali date.');
        }

        return $formatted;
    }

    public function formatDateTime(DateTimeInterface $date): string {
        return $this->formatDate($date, 'yyyy/MM/dd HH:mm');
    }
}
