<?php

declare(strict_types=1);

use Nextcloud\CodingStandard\Config;

$config = new Config();
return $config->getConfig()
	->setRiskyAllowed(true)
	->setFinder(
		PhpCsFixer\Finder::create()
			->in(__DIR__ . '/lib')
			->in(__DIR__ . '/tests')
	);
