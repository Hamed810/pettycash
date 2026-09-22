<?php

declare(strict_types=1);

namespace OCA\PettyCash\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap
{
    public const APP_ID = 'pettycash';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
        // Prefer constructor injection and explicit interfaces. Concrete adapters are
        // registered here only when their interface cannot be autowired by Nextcloud.
    }

    public function boot(IBootContext $context): void
    {
        // Intentionally lightweight: migrations and commands perform explicit work.
    }
}
