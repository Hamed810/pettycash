<?php

declare(strict_types=1);

namespace OCA\PettyCash\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IURLGenerator;

final class Application extends App implements IBootstrap {

    public const APP_ID = 'pettycash';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
    }

    public function boot(IBootContext $context): void {

        $server = $context->getServerContainer();

        $navigation = $server->get(INavigationManager::class);
        $urlGenerator = $server->get(IURLGenerator::class);

        $navigation->add([
            'id' => self::APP_ID,
            'order' => 50,
            'href' => $urlGenerator->linkToRoute(
                'pettycash.page.index'
            ),
            'icon' => $urlGenerator->imagePath(
                self::APP_ID,
                'app.svg'
            ),
            'name' => 'Project Petty Cash',
        ]);
    }
}