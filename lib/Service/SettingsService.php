<?php

declare(strict_types=1);

namespace OCA\PettyCash\Service;

use OCP\IConfig;

final class SettingsService {

    private string $appId = 'pettycash';


    public function __construct(
        private IConfig $config
    ) {}


    public function get(
        string $key,
        ?string $default = null
    ): ?string {

        return $this->config->getAppValue(
            $this->appId,
            $key,
            $default
        );
    }


    public function set(
        string $key,
        string $value
    ): void {

        $this->config->setAppValue(
            $this->appId,
            $key,
            $value
        );
    }


    public function getBool(
    string $key,
    bool $default = false
    ): bool {

        $value = $this->config->getAppValue(
            $this->appId,
            $key,
            $default ? '1' : '0'
        );

        return $value === '1'
            || $value === 1
            || $value === true
            || $value === 'true';
    }


    public function setBool(
        string $key,
        bool $value
    ): void {

        $config = $value ? '1' : '0';

        $this->config->setAppValue(
            $this->appId,
            $key,
            $config
        );
    }


    /*
     * v0.4.1 Settings
     */


    public function allowMultipleOpenCostLists(): bool {

        return $this->getBool(
            'allow_multiple_open_cost_lists',
            true
        );
    }


    public function allowUserDeleteOpenCostLists(): bool {

        return $this->getBool(
            'allow_user_delete_open_cost_lists',
            true
        );
    }


    public function requireVehicleKilometer(): bool {

        return $this->getBool(
            'require_vehicle_kilometer',
            true
        );
    }


    public function requireHiringPermit(): bool {

        return $this->getBool(
            'require_hiring_permit',
            true
        );
    }


    public function requireFingerprint(): bool {

        return $this->getBool(
            'require_fingerprint_document',
            true
        );
    }


    public function ocrEnabled(): bool {

        return $this->getBool(
            'ocr_enabled',
            true
        );
    }


    public function ocrLanguage(): string {

        return $this->get(
            'ocr_language',
            'fa'
        ) ?? 'fa';
    }


    public function timezone(): string {

        return $this->get(
            'timezone',
            'Asia/Tehran'
        ) ?? 'Asia/Tehran';
    }


    public function defaultCurrency(): string {

        return $this->get(
            'default_currency',
            'IRR'
        ) ?? 'IRR';
    }
}
