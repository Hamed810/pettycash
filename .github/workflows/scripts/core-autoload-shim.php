<?php

declare(strict_types=1);

/**
 * CI shim: keep Nextcloud's own Composer loader authoritative over an app's.
 *
 * ## WHY THIS EXISTS, AND WHY IT IS NOT T239's FAULT
 *
 * `OC_App::registerAutoloading()` requires `<app>/composer/autoload.php` for every enabled app. This
 * repository's `composer/autoload.php` returns the **development** `vendor/autoload.php`, which
 * includes `symfony/console` **v8.1.7**. Symfony 8 removed `Application::add()` (renamed to
 * `addCommand()`), and Nextcloud 34's `core/register_command.php:116` calls `add()`. Because
 * `OC_App` registers the app's loader with `spl_autoload_register(..., prepend: true)`, the app's
 * Symfony 8 sits IN FRONT of the copy in `/var/www/html/3rdparty` that Nextcloud was built against,
 * and every `occ` command that registers a core command dies with:
 *
 *     Error: Call to undefined method Symfony\Component\Console\Application::add()
 *
 * This is a pre-existing application defect, not something the portability matrix introduced. It is
 * already known and worked around in the development lab, which binds an equivalent shim via a
 * `conf.d` ini (`~/projects/nextcloud-lab/shims/nextcloud-core-autoload.php`). That lab shim states the
 * real fix belongs in this repository's `composer/autoload.php`, which is a separate change from T239
 * and is deliberately not smuggled in here.
 *
 * ## WHY CI NEEDS ITS OWN COPY
 *
 * The lab shim lives in the lab repository and is mounted by the lab's Compose file, so a GitHub
 * Actions runner has no access to it. Without an equivalent, no `occ` command works and the matrix
 * cannot run at all — it is a prerequisite for T239, not part of its subject.
 *
 * Duplicating it here rather than reaching across repositories is the right trade: CI must be
 * self-contained, and the alternative is a CI job that silently depends on a sibling checkout's
 * layout. When the application defect is fixed at source, this file and its activation in
 * `portability-pass.sh` should be deleted, and the deletion is the evidence the fix landed.
 *
 * ## WHAT IT DOES
 *
 * On each class PHP fails to autoload, it partitions the registered Composer loaders by WHERE THEIR
 * FILES LIVE — anything searching under `/var/www/html/custom_apps/` is an app loader, everything else
 * is core — then re-registers them with every core loader ahead of every app loader. No loader is
 * removed and no namespace mapping is rewritten, so the app's own `PhpOffice\*` and
 * `OCA\PettyCash\*` still resolve; only the order changes.
 *
 * It re-sweeps whenever the loader set has changed, because apps register their loaders one at a time
 * during bootstrap: a single sweep can fire before any app loader exists, and the next app then
 * prepends itself back in front. The loader count is the signal, and reordering is idempotent, so a
 * redundant sweep costs microseconds.
 */

final class PettycashCiCoreAutoload
{
    private const CUSTOM_APPS_DIR = '/var/www/html/custom_apps/';

    private static ?int $lastSweptCount = null;

    public static function loadClass(string $class): void
    {
        $loaders = self::composerLoaders();

        if (count($loaders) === self::$lastSweptCount) {
            return;
        }
        self::$lastSweptCount = count($loaders);

        [$app, $core] = self::partition($loaders);

        // `spl_autoload_register(..., prepend: true)` inserts at index 0, so re-registering in forward
        // order would invert the grouping. Walking backwards and prepending each puts core in front.
        foreach (array_reverse($app) as $autoloader) {
            spl_autoload_unregister($autoloader);
            spl_autoload_register($autoloader, true, true);
        }
        foreach (array_reverse($core) as $autoloader) {
            spl_autoload_unregister($autoloader);
            spl_autoload_register($autoloader, true, true);
        }
    }

    /**
     * @return list<array{0: \Composer\Autoload\ClassLoader, 1: string}>
     */
    private static function composerLoaders(): array
    {
        $loaders = [];
        foreach (spl_autoload_functions() as $autoloader) {
            if (is_array($autoloader) && ($autoloader[0] instanceof \Composer\Autoload\ClassLoader)) {
                $loaders[] = $autoloader;
            }
        }

        return $loaders;
    }

    /**
     * @param  list<array{0: \Composer\Autoload\ClassLoader, 1: string}> $loaders
     * @return array{0: list<array{0: \Composer\Autoload\ClassLoader, 1: string}>, 1: list<array{0: \Composer\Autoload\ClassLoader, 1: string}>}
     */
    private static function partition(array $loaders): array
    {
        $app = [];
        $core = [];

        foreach ($loaders as $autoloader) {
            if (self::servesAppDirectory($autoloader[0])) {
                $app[] = $autoloader;
            } else {
                $core[] = $autoloader;
            }
        }

        return [$app, $core];
    }

    private static function servesAppDirectory(\Composer\Autoload\ClassLoader $loader): bool
    {
        // Both getters, because a loader may map namespaces only through PSR-4 prefixes, only
        // through fallback directories, or through both.
        $candidates = array_merge(
            array_values($loader->getPrefixesPsr4()),
            $loader->getFallbackDirsPsr4(),
        );

        foreach ($candidates as $dirs) {
            foreach ((array) $dirs as $dir) {
                if (is_string($dir) && str_starts_with($dir, self::CUSTOM_APPS_DIR)) {
                    return true;
                }
            }
        }

        return false;
    }
}

spl_autoload_register([PettycashCiCoreAutoload::class, 'loadClass'], true, true);
