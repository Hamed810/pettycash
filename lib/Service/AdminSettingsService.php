<?php

declare(strict_types=1);

namespace OCA\PettyCash\Service;

final class AdminSettingsService {

    public function __construct(
        private SettingsService $settings
    ) {}


    /**
     * @return array<string,mixed>
     */
    public function getSettings(): array {

        return [
            'allowMultipleOpenCostLists'
                => $this->settings->allowMultipleOpenCostLists(),

            'allowUserDeleteOpenCostLists'
                => $this->settings->allowUserDeleteOpenCostLists(),

            'requireVehicleKilometer'
                => $this->settings->requireVehicleKilometer(),

            'requireHiringPermit'
                => $this->settings->requireHiringPermit(),

            'requireFingerprint'
                => $this->settings->requireFingerprint(),

            'ocrEnabled'
                => $this->settings->ocrEnabled(),

            'ocrLanguage'
                => $this->settings->ocrLanguage(),

            'timezone'
                => $this->settings->timezone(),

            'defaultCurrency'
                => $this->settings->defaultCurrency(),
        ];
    }


    /**
     * @param array<string,mixed> $data
     */
    public function saveSettings(
        array $data
    ): array {


        if (isset($data['allowMultipleOpenCostLists'])) {

            $this->settings->setBool(
                'allow_multiple_open_cost_lists',
                (bool)$data['allowMultipleOpenCostLists']
            );
        }


        if (isset($data['allowUserDeleteOpenCostLists'])) {

            $this->settings->setBool(
                'allow_user_delete_open_cost_lists',
                (bool)$data['allowUserDeleteOpenCostLists']
            );
        }


        if (isset($data['requireVehicleKilometer'])) {

            $this->settings->setBool(
                'require_vehicle_kilometer',
                (bool)$data['requireVehicleKilometer']
            );
        }


        if (isset($data['requireHiringPermit'])) {

            $this->settings->setBool(
                'require_hiring_permit',
                (bool)$data['requireHiringPermit']
            );
        }


        if (isset($data['requireFingerprint'])) {

            $this->settings->setBool(
                'require_fingerprint_document',
                (bool)$data['requireFingerprint']
            );
        }


        if (isset($data['ocrEnabled'])) {

            $this->settings->setBool(
                'ocr_enabled',
                (bool)$data['ocrEnabled']
            );
        }


        if (isset($data['ocrLanguage'])) {

            $this->settings->set(
                'ocr_language',
                (string)$data['ocrLanguage']
            );
        }


        if (isset($data['timezone'])) {

            $this->settings->set(
                'timezone',
                (string)$data['timezone']
            );
        }


        if (isset($data['defaultCurrency'])) {

            $this->settings->set(
                'default_currency',
                (string)$data['defaultCurrency']
            );
        }


        return $this->getSettings();
    }
}
