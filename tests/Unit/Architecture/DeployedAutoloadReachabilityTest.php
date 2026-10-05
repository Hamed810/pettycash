<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * T345: every third-party namespace `lib/` imports must be reachable through the autoloader
 * Nextcloud actually loads for a deployed app.
 *
 * ## The defect this exists to stop recurring
 *
 * `POST /exports` with `fileFormat: xlsx` answered HTTP 500 `Class
 * "PhpOffice\PhpSpreadsheet\Spreadsheet" not found` for every sheet, on every request, in the deployed
 * app — while `scripts/dev/phpunit` reported 1226 passing tests and `npm run test` reported 296.
 *
 * The reason no suite could see it is the whole point. `tests/bootstrap.php:5` requires
 * `vendor/autoload.php` by hand, so **the CLI always has a dependency graph the deployed app does not
 * have.** Every test in this repository therefore resolves `PhpOffice\PhpSpreadsheet\*` through a
 * second autoloader that no HTTP request ever loads. `assertTrue(class_exists('…\Spreadsheet'))` inside
 * this suite is *true by construction* and asserts nothing about the app Nextcloud boots.
 *
 * `OC_App::registerAutoloading()` (`lib/private/legacy/OC_App.php:116`) makes one check — does
 * `<app>/composer/autoload.php` exist — and falls back to a PSR-4 prefix for `OCA\PettyCash\` alone when
 * it does not. That fallback resolves this app's own classes and **no third-party class at all**. The
 * package was installed, present on disk, and loadable from a shell; it was simply unreachable from the
 * one entry point the server consults.
 *
 * ## Why this asserts structure rather than issuing a request
 *
 * For the same reason `LayerBoundaryTest` and `RouteDispatchCompletenessTest` read source instead of
 * calling code: this is a fact about *which file the loader chain starts from*, and no request can
 * distinguish an app that works from an app that works by accident. A contract test would load
 * `vendor/autoload.php` through the very bootstrap whose independence is the defect.
 *
 * So the assertion is made against the `Composer\Autoload\ClassLoader` that `composer/autoload.php`
 * itself returns, and against `ClassLoader::findFile()` — which consults only that loader's own classmap
 * and PSR-4 prefixes. It never falls through to the ambient autoloader, which is the only way to say
 * anything about the deployed path.
 *
 * ## What is deliberately not asserted
 *
 * That `composer/` holds Composer's whole project with `vendor-dir: .`, as `apps/files/composer/` does.
 * That layout would work, but it relocates every dependency and rewrites `composer.json`,
 * `tests/bootstrap.php`, `scripts/dev/*`, `phpunit.xml` and `psalm.xml` to match. What Nextcloud requires
 * is one reachable file at one path; `composer/autoload.php` shimming to the existing `vendor/` tree
 * satisfies that without moving a byte of `vendor/`. This test therefore pins the *reachable file*, not
 * the layout, and would keep passing if the project were ever migrated to the full convention.
 */
final class DeployedAutoloadReachabilityTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/../../..';

    /**
     * The exact path `OC_App::registerAutoloading()` probes. Spelled out rather than assembled from a
     * constant so that a rename of either half breaks this file loudly instead of silently moving the
     * assertion along with the code it is meant to police.
     */
    private const DEPLOYED_ENTRY_POINT = '/composer/autoload.php';

    /**
     * Namespaces Nextcloud supplies itself, so a missing Composer autoloader does not affect them.
     *
     * `OC\` is the server's internals and `OCP\` its public API; `Psr\` arrives from Nextcloud's own
     * `3rdparty` autoloader, which is why `Psr\Log` is usable in `lib/` today. `OCA\PettyCash\` is this
     * app, and the PSR-4 fallback branch of `registerAutoloading()` maps it to `lib/` whether or not the
     * Composer autoloader is found — so none of these four are what this test is about.
     */
    private const SERVER_SUPPLIED = ['OC\\', 'OCP\\', 'OCA\\', 'Psr\\'];

    /**
     * The deployed autoloader must exist at the one path the server looks for.
     *
     * Asserting the path *and* the requirement separately is the point: a shim placed at
     * `appinfo/app.php` satisfies neither, because NC 34 never includes that file —
     * `AppManager::loadApp()` only logs `'/appinfo/app.php is not supported anymore'` when it is
     * present (`lib/private/App/AppManager.php:490`). That was T345's originally proposed fix, and it is
     * the reason the path is asserted rather than merely "some autoloader is required somewhere".
     */
    public function testTheDeployedAutoloadEntryPointExists(): void
    {
        $entryPoint = self::APP_ROOT . self::DEPLOYED_ENTRY_POINT;

        $this->assertFileExists(
            $entryPoint,
            self::DEPLOYED_ENTRY_POINT . ' is the only autoloader '
            . 'OC_App::registerAutoloading() will load for this app. Without it the server falls back to '
            . 'a PSR-4 prefix for OCA\\PettyCash\\ alone, which resolves no third-party class, and every '
            . 'XLSX export answers 500 "Class PhpOffice\\PhpSpreadsheet\\Spreadsheet not found".\n\n'
            . 'Do not substitute appinfo/app.php: NC 34 does not include it. Do not substitute '
            . 'vendor/autoload.php either — the server never probes that path.'
        );

        $contents = (string) file_get_contents($entryPoint);

        // A real Composer-generated entry point carries this marker. If it appears, someone set
        // `config.vendor-dir` to `composer/`, Composer overwrote the tracked shim, and the shim's own
        // failure message about that exact collision is no longer being honoured.
        $this->assertStringNotContainsString(
            '@generated by Composer',
            $contents,
            'composer/autoload.php has been replaced by a Composer-generated entry point. That happens '
            . 'when config.vendor-dir is set to "composer/", which overwrites this tracked file. Either '
            . 'revert vendor-dir, or complete the migration to the full apps/files/composer/ layout and '
            . 'delete this file.'
        );
    }

    /**
     * Every third-party class `lib/` imports must resolve through the loader the entry point returns.
     *
     * `findFile()` is used rather than `class_exists()` precisely because `class_exists()` would be
     * answered by the test bootstrap's already-loaded loader and would therefore pass on an app that is
     * broken in production — which is what happened for the whole life of this defect.
     */
    public function testEveryThirdPartyImportInLibResolvesThroughTheDeployedLoader(): void
    {
        $imports = $this->thirdPartyImportsInLib();

        $unreachable = [];
        foreach ($imports as $class => $file) {
            if ($this->deployedLoader()->findFile($class) === false) {
                $unreachable[] = sprintf('%s (%s)', $class, $file);
            }
        }

        $this->assertSame(
            [],
            $unreachable,
            "Every third-party class lib/ imports must resolve through the loader "
            . self::DEPLOYED_ENTRY_POINT . " returns.\nUnreachable:\n  " . implode("\n  ", $unreachable)
            . "\n\nA class that resolves in the CLI but not here is the T345 failure exactly: "
            . "tests/bootstrap.php requires vendor/autoload.php directly, so no test in this repository "
            . "can observe the deployed app's dependency graph."
        );
    }

    /**
     * The scan must actually find third-party imports, or the assertion above passes over all of `lib/`.
     *
     * The same self-check `LayerBoundaryTest` and `RouteDispatchCompletenessTest` carry, for the same
     * reason: a regex over source that stops matching reports nothing while proving nothing. Here the
     * regex additionally has an exclusion list, so a namespace prefix added to `SERVER_SUPPLIED` would
     * silently shrink the set rather than fail.
     */
    public function testTheScanActuallyFindsThirdPartyImports(): void
    {
        $imports = $this->thirdPartyImportsInLib();

        $this->assertNotEmpty(
            $imports,
            'no third-party import was recognised in lib/, so the reachability scan is vacuous'
        );

        $this->assertArrayHasKey(
            'PhpOffice\\PhpSpreadsheet\\Spreadsheet',
            $imports,
            'lib/Infrastructure/Export/XlsxExporter.php imports PhpOffice\\PhpSpreadsheet\\Spreadsheet. '
            . 'Its absence from the scan means the exclusion list or the pattern no longer matches, '
            . 'and every other assertion in this file is then checking an empty set.'
        );
    }

    /**
     * The loader `composer/autoload.php` hands back, loaded the way Nextcloud loads it.
     *
     * `require_once` because the CLI may already hold it via `tests/bootstrap.php`; the file is
     * required for its return value either way, which is why the shim `return`s the loader rather than
     * merely requiring `vendor/autoload.php`.
     */
    private function deployedLoader(): \Composer\Autoload\ClassLoader
    {
        $loader = require self::APP_ROOT . self::DEPLOYED_ENTRY_POINT;

        $this->assertInstanceOf(
            'Composer\\Autoload\\ClassLoader',
            $loader,
            self::DEPLOYED_ENTRY_POINT . ' must return the ClassLoader, as a Composer-generated '
            . 'autoload.php does. A shim that only requires vendor/autoload.php returns true instead, '
            . 'which leaves the deployed load unassertable.'
        );

        return $loader;
    }

    /**
     * Fully-qualified third-party class names imported anywhere under `lib/`, keyed by class name.
     *
     * Keyed so a failure message names the class; the alias is not recorded, because a rename is not
     * what is being checked. Group and function imports (`use function …`, `use const …`) are skipped,
     * and so is any import with no namespace separator — `use DateTimeImmutable;` names a global class
     * that needs no autoloader.
     *
     * @return array<string, string> class name => relative file
     */
    private function thirdPartyImportsInLib(): array
    {
        $imports = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::APP_ROOT . '/lib', \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(self::APP_ROOT) + 1);
            $source = (string) file_get_contents($file->getPathname());

            // `^use\s+FQCN;` at the start of a line. `function`/`const` after `use` are excluded, and the
            // optional `as Alias` is allowed and discarded.
            $pattern = '/^use\s+(?!function\s|const\s)([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)'
                . '\s*(?:as\s+\w+\s*)?;/m';

            if (preg_match_all($pattern, $source, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $imported) {
                if ($this->isServerSupplied($imported)) {
                    continue;
                }
                $imports[$imported] = $relative;
            }
        }

        ksort($imports);

        return $imports;
    }

    private function isServerSupplied(string $imported): bool
    {
        foreach (self::SERVER_SUPPLIED as $prefix) {
            if (str_starts_with($imported, $prefix)) {
                return true;
            }
        }

        return false;
    }
}