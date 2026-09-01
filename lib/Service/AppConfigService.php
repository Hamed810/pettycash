<?php

declare(strict_types=1);

namespace OCA\PettyCash\Service;

use OCA\PettyCash\AppInfo\Application;
use OCP\IConfig;

final class AppConfigService {
    public const DEFAULT_TIMEZONE = 'Asia/Tehran';
    public const DEFAULT_CURRENCY = 'IRR';

    private const KEY_TIMEZONE = 'business_timezone';
    private const KEY_DEFAULT_CURRENCY = 'default_currency';
    private const KEY_OCR_ENABLED = 'ocr_enabled';

    public function __construct(
        private IConfig $config,
    ) {
    }

    public function getBusinessTimezone(): string {
        $value = $this->config->getAppValue(
            Application::APP_ID,
            self::KEY_TIMEZONE,
            self::DEFAULT_TIMEZONE,
        );

        return $value !== '' ? $value : self::DEFAULT_TIMEZONE;
    }

    public function getDefaultCurrencyCode(): string {
        $value = strtoupper($this->config->getAppValue(
            Application::APP_ID,
            self::KEY_DEFAULT_CURRENCY,
            self::DEFAULT_CURRENCY,
        ));

        return $value !== '' ? $value : self::DEFAULT_CURRENCY;
    }

    public function isOcrEnabled(): bool {
        return $this->config->getAppValue(
            Application::APP_ID,
            self::KEY_OCR_ENABLED,
            '0',
        ) === '1';
    }

    public function setBusinessTimezone(string $timezone): void {
        // The product baseline is Tehran. This setter exists for controlled
        // future administration while keeping the default explicit.
        new \DateTimeZone($timezone);
        $this->config->setAppValue(Application::APP_ID, self::KEY_TIMEZONE, $timezone);
    }

    public function setDefaultCurrencyCode(string $code): void {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            throw new \InvalidArgumentException('Currency code must be a three-letter ISO-style code.');
        }

        $this->config->setAppValue(Application::APP_ID, self::KEY_DEFAULT_CURRENCY, $code);
    }

    public function setOcrEnabled(bool $enabled): void {
        $this->config->setAppValue(
            Application::APP_ID,
            self::KEY_OCR_ENABLED,
            $enabled ? '1' : '0',
        );
    }
}
