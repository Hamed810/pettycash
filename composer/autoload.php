<?php

declare(strict_types=1);

/**
 * The autoloader Nextcloud actually loads for this app.
 *
 * ## Why this file exists
 *
 * `OC_App::registerAutoloading()` — reached on every request through
 * `AppManager::loadApp()` and `Coordinator::bootApp()` — makes exactly one check:
 *
 * ```php
 * if (file_exists($path . '/composer/autoload.php')) {
 *     require_once $path . '/composer/autoload.php';
 * } else {
 *     \OC::$composerAutoloader->addPsr4($appNamespace . '\\', $path . '/lib/', true);
 * }
 * ```
 *
 * `$path` is the app's own directory, so the file it looks for is `<app>/composer/autoload.php` and
 * nothing else. Without it the `else` branch runs, which registers **only** `OCA\PettyCash\` against
 * `<app>/lib`. That is enough for the app's own classes and for no third-party class at all: with the
 * file absent, `PhpOffice\PhpSpreadsheet\*` was unresolvable, and every XLSX export — one of the four
 * formats FR-066 declares supported — answered HTTP 500 `Class "PhpOffice\PhpSpreadsheet\Spreadsheet"
 * not found`. Every NC 34 core app ships this file for the same reason (`apps/files/composer/` and its
 * twenty siblings).
 *
 * ## Why a one-line shim rather than the full Nextcloud layout
 *
 * The convention is for Composer's whole project to live in `composer/` with `vendor-dir` set to `.`,
 * which puts the packages beside `autoload.php`. This shim instead points the convention's entry point
 * at the `vendor/` tree Composer already installs from the repository root, so no dependency has to be
 * relocated, re-fetched, or re-resolved — `composer.json`, `composer.lock`, `tests/bootstrap.php`,
 * `scripts/dev/*`, `phpunit.xml` and `psalm.xml` all keep the paths they have.
 *
 * ## Why nothing here loads a web request
 *
 * `appinfo/app.php` would have been the older way to do this and is what T345 originally proposed. It no
 * longer works: NC 34 never includes it. `AppManager::loadApp()` only checks whether it exists and
 * writes `'/appinfo/app.php is not supported anymore, use \OCP\AppFramework\Bootstrap\IBootstrap on the
 * application class instead.'` to the log (`lib/private/App/AppManager.php:490`) — a shim there would be
 * inert and would log an error on every app load.
 *
 * ## If `vendor/` is missing
 *
 * `vendor/` is gitignored, so a fresh checkout has no dependencies until `composer install` runs. The
 * failure is deliberately explicit rather than a `require_once` fatal, because the bare fatal
 * (`Failed opening required`) names neither the missing directory nor the command that fills it.
 *
 * Do **not** set `config.vendor-dir` to `composer/` in the root `composer.json` while this file is
 * tracked: Composer would generate its own `composer/autoload.php` and overwrite this one.
 * `DeployedAutoloadReachabilityTest` asserts the shim keeps resolving `lib/`'s vendor namespaces, and
 * fails with that exact instruction if it is replaced.
 */

$pettycashAutoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($pettycashAutoload)) {
    // `http_response_code()` rather than an exception: this file is required during app bootstrap, before
    // the AppFramework error handler exists, so an exception here would surface as an unhandled fatal.
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'pettycash_dependencies_missing',
            'message' => 'pettycash has no Composer dependencies installed. Run "composer install" in '
                . 'the app directory.',
        ]);
    } else {
        fwrite(STDERR, "pettycash: {$pettycashAutoload} is missing. Run \"composer install\".\n");
    }

    return;
}

// `require`, not `require_once`, and the loader's value is returned: that is Composer's own contract for
// an `autoload.php` (`return ComposerAutoloaderInit…::getLoader();`), and `autoload_real.php` is itself
// idempotent, so a second inclusion returns the same loader rather than a new one. Nextcloud discards
// the value, but `DeployedAutoloadReachabilityTest` asserts against the loader this returns.
return require $pettycashAutoload;