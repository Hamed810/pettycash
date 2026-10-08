<?php

declare(strict_types=1);

/**
 * Reads, writes or clears one user's `core.timezone`, and prints the resulting value as JSON.
 *
 * ## Why this exists
 *
 * FR-068 resolves a reporting period in *"that requesting user's own account settings, falling back to
 * the instance's configured default"*. On this instance both readings are UTC — the acting user states
 * no zone, `config.php` declares no `default_timezone`, and PHP's default is UTC — so the clause is
 * **unobservable over HTTP**: replacing `$this->timeZones->forUser(...)` with `new DateTimeZone('UTC')`
 * in the controller leaves every test in `ReportingPeriodContractTest` green. That was measured by
 * mutation, not inferred, and `testTheResolvedZoneIsTheInstancesConfiguredZone` recorded itself as a
 * skip because of it.
 *
 * Giving the acting user a real non-UTC zone is what makes the clause provable, and it cannot be done
 * from the PHPUnit process: `tests/bootstrap.php` wires a lightweight container of in-memory doubles,
 * so loading `lib/base.php` in-process would replace the global server. This is the same reason
 * `seed-contract-fixtures.php` exists, and the same subprocess shape.
 *
 * `occ` is not an option here — it is unusable in this container (`Call to undefined method
 * Symfony\Component\Console\Application::add()` in `core/register_command.php`) — so the preference is
 * written through `IConfig`, which is the service `NextcloudRequestTimeZone` itself reads. Writing the
 * preference the adapter reads is the whole point: a test that set some *other* key would pass against a
 * broken adapter and against a correct one alike.
 *
 * ## Usage (from the app root, inside the container)
 *
 *   php tests/Contract/Fixture/user-timezone-fixture.php <nextcloud-user> read
 *   php tests/Contract/Fixture/user-timezone-fixture.php <nextcloud-user> write <zone>
 *   php tests/Contract/Fixture/user-timezone-fixture.php <nextcloud-user> clear
 *
 * `<zone>` is a `DateTimeZone` identifier; an invalid one is refused before anything is written, so a
 * typo cannot leave the user with a zone no code can resolve.
 *
 * ## What this is allowed to touch
 *
 * **One user's `core.timezone` preference, and nothing else.** `clear` restores the prior value rather
 * than deleting the key blindly, so a run against a user who already had a zone leaves them with it.
 * The shared instance's `config.php` is never written — `default_timezone` is left exactly as found,
 * because changing it would move every user's period resolution, including the other applications in
 * this Nextcloud.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This fixture must be run from the command line.\n");
    exit(1);
}

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
if (!is_file($nextcloudRoot . '/lib/base.php')) {
    fwrite(STDERR, "Cannot locate Nextcloud at {$nextcloudRoot}; set NEXTCLOUD_ROOT.\n");
    exit(1);
}

require_once $nextcloudRoot . '/lib/base.php';

$userUid = $argv[1] ?? getenv('PETTYCASH_TEST_USER') ?: '';
if ($userUid === '') {
    fwrite(STDERR, "Usage: user-timezone-fixture.php <nextcloud-user> read|write <zone>|clear\n");
    exit(1);
}

$action = $argv[2] ?? 'read';

$userManager = \OCP\Server::get(\OCP\IUserManager::class);
$user = $userManager->get($userUid);
if ($user === null) {
    fwrite(STDERR, "No such Nextcloud user: {$userUid}\n");
    exit(1);
}

/** @var \OCP\IConfig $config */
$config = \OCP\Server::get(\OCP\IConfig::class);

// The **uid string**, not the `IUser` object. `OC\AllConfig::getUserValue()` reaches
// `OC\Config\UserConfig::hasKey(string $userId)` in Nextcloud 34, so passing the object raises a
// `TypeError` — and because the running server suppresses the fatal-error display, it does so with
// no output and exit status 0. That is worth writing down: the first version of this fixture called
// the object overload and appeared to "succeed" while doing nothing, which would have made the
// timezone test pass against an unset preference.
$uid = $user->getUID();

// The empty string is Nextcloud's own representation of "this user states no zone": `IConfig`'s
// default argument, and what `NextcloudRequestTimeZone` receives as `''`. Reading through
// `getUserValue` with an explicit `''` default therefore distinguishes an absent preference from a
// preference set to an empty value, which a `?? null` on a raw read could not.
$current = $config->getUserValue($uid, 'core', 'timezone', '');

if ($action === 'write') {
    $zone = $argv[3] ?? '';
    if ($zone === '') {
        fwrite(STDERR, "write requires a zone: user-timezone-fixture.php {$userUid} write <zone>\n");
        exit(1);
    }

    // Validated before the write, not after. `DateTimeZone` throws on an unknown identifier, and a
    // throw halfway through would leave the user holding a zone nothing can resolve — which is a
    // worse state than the one the fixture was asked to correct.
    try {
        new \DateTimeZone($zone);
    } catch (\Exception $e) {
        fwrite(STDERR, "Not a time zone identifier: {$zone} ({$e->getMessage()})\n");
        exit(1);
    }

    $config->setUserValue($uid, 'core', 'timezone', $zone);
    $current = $config->getUserValue($uid, 'core', 'timezone', '');
} elseif ($action === 'clear') {
    // `deleteUserValue` rather than writing `''`, so a user who had no preference gets back no
    // preference rather than a stored empty string. The two read alike through `NextcloudRequestTimeZone`
    // — both are "no zone stated" — but only one leaves the instance as it was found.
    $config->deleteUserValue($uid, 'core', 'timezone');
    $current = $config->getUserValue($uid, 'core', 'timezone', '');
} elseif ($action !== 'read') {
    fwrite(STDERR, "Unknown action '{$action}'; expected read, write or clear.\n");
    exit(1);
}

echo json_encode([
    'userId' => $userUid,
    'action' => $action,
    'core.timezone' => $current,
], JSON_UNESCAPED_SLASHES), "\n";