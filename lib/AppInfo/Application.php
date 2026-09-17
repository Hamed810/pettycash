<?php

namespace OCA\PettyCash\AppInfo;

use OCP\AppFramework\App;

class Application extends App
{
    public const APP_ID = 'pettycash';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }
}
