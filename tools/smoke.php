<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/Domain/Exception/ValidationException.php';
require_once __DIR__ . '/../lib/Service/PersianNumberService.php';
require_once __DIR__ . '/../lib/Service/MoneyService.php';
require_once __DIR__ . '/../lib/Service/JalaliCalendarService.php';

use OCA\PettyCash\Service\MoneyService;
use OCA\PettyCash\Service\JalaliCalendarService;
use OCA\PettyCash\Service\PersianNumberService;

$numbers = new PersianNumberService();
$money = new MoneyService($numbers);
$calendar = new JalaliCalendarService($numbers);

$checks = [
    $numbers->normalizeDigits('۱۴۰۵/۰۶/۱۲') === '1405/06/12',
    $numbers->normalize('شركت كيان') === 'شرکت کیان',
    $money->parseToMinor('۴۸,۰۰۰,۰۰۰', 0) === 48000000,
    $money->parseToMinor('125.75', 2) === 12575,
    $money->formatMinor(12575, 2) === '125.75',
    $calendar->jalaliToGregorian('۱۴۰۵/۰۶/۱۰') === '2026-09-01',
    $calendar->gregorianToJalali('2026-09-01') === '1405/06/10',
];

foreach ($checks as $index => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'Smoke check failed: ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo "Petty Cash utility smoke checks passed." . PHP_EOL;
