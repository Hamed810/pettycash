<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use DateTimeZone;
use Exception;

/**
 * FR-068's time-zone precedence, as a pure rule over two strings.
 *
 * ## Why this is separate from the adapter that reads the values
 *
 * The rule is "the user's own setting, falling back to the instance's configured default, and never a
 * hardcoded zone". Everything interesting about it — a blank setting, a setting naming a zone the
 * system no longer has, a *default* that is itself broken — is a decision, and a decision is not
 * something that should only be reachable by booting Nextcloud.
 *
 * `OCP\` is not in this application's Composer autoload, so a unit test cannot implement or fake an
 * `IConfig`: no test under `tests/Unit/` implements an OCP interface anywhere in this repository, and
 * `NextcloudUserDirectory` has no unit test either. Putting the precedence here keeps the branches
 * testable while the adapter reduces to two reads and a delegate — which is the same untested surface
 * every other OCP adapter in `Infrastructure/Nextcloud/` has, and no more.
 *
 * ## The two failure modes it exists to prevent
 *
 * 1. **`new DateTimeZone($name)` throws** for a name the tzdata no longer contains. A user's preference
 *    can outlive that — a tzdata upgrade, a renamed zone, a value written through the API — and without
 *    a catch one user's bad preference turns every report they request into a 500. Nextcloud core makes
 *    the same catch in `OC\DateTimeZone::getTimeZone()`.
 * 2. **An empty string reaching the constructor.** Nextcloud stores an untouched preference as `''`
 *    rather than deleting the row, so blank is what "the user stated nothing" actually looks like.
 *
 * ## `UTC` is a last resort, not a default
 *
 * `plan.md` chose `morilog/jalali` partly because Nextcloud 34 does not require `ext-intl`, and FR-068
 * ends its own chain at the instance default. `UTC` appears here only so the method returns a zone in
 * every case — it is what Nextcloud core ends at as well, so the two agree rather than diverging.
 */
final class TimeZonePreference
{
    /** What Nextcloud core ends at, and the last resort here. */
    public const LAST_RESORT = 'UTC';

    /**
     * The zone implied by a user's stored setting and the instance default.
     *
     * @param string $userZone the user's stored `core.timezone`, or '' when they state none
     * @param string $instanceDefault the instance's `default_timezone`, or '' when unset
     */
    public static function resolve(string $userZone, string $instanceDefault): DateTimeZone
    {
        $candidate = trim($userZone);

        if ($candidate === '') {
            $candidate = trim($instanceDefault);
        }

        $resolved = self::tryZone($candidate);

        if ($resolved !== null) {
            return $resolved;
        }

        // Either the user stated nothing and the instance default is unusable, or the user's own value
        // is unusable. Both fall back to the instance default first — it is the documented fallback and
        // is far likelier to be right than UTC — and only then to the last resort.
        if ($candidate !== trim($instanceDefault)) {
            $viaDefault = self::tryZone(trim($instanceDefault));
            if ($viaDefault !== null) {
                return $viaDefault;
            }
        }

        return new DateTimeZone(self::LAST_RESORT);
    }

    /**
     * A zone for `$name`, or null when it is blank or unusable.
     *
     * Null rather than throwing so each caller decides what an unusable value means, which is the
     * difference between a fallback and a swallowed error.
     */
    private static function tryZone(string $name): ?DateTimeZone
    {
        if ($name === '') {
            return null;
        }

        try {
            return new DateTimeZone($name);
        } catch (Exception) {
            return null;
        }
    }
}