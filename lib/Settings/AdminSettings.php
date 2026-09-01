<?php

declare(strict_types=1);

namespace OCA\PettyCash\Settings;

use OCA\PettyCash\AppInfo\Application;
use OCA\PettyCash\Service\AppConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

final class AdminSettings implements ISettings {
    public function __construct(
        private AppConfigService $config,
    ) {
    }

    public function getForm(): TemplateResponse {
        return new TemplateResponse(
            Application::APP_ID,
            'settings/admin',
            [
                'timezone' => $this->config->getBusinessTimezone(),
                'defaultCurrency' => $this->config->getDefaultCurrencyCode(),
                'ocrEnabled' => $this->config->isOcrEnabled(),
            ],
            '',
        );
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
