<?php

declare(strict_types=1);

namespace OCA\PettyCash\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
    public const APP_ID = 'pettycash';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Services and controllers currently rely on Nextcloud autowiring.
        // Background OCR jobs and activity/notification providers are registered in later phases.
    }

    public function boot(IBootContext $context): void {
        // No global boot work is required for the current workflow modules.
    }
}
