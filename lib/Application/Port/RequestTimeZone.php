<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Port;

use DateTimeZone;

/**
 * The time zone a user's reporting periods must be resolved in. FR-068.
 *
 * ## Why this is a port and not a `new DateTimeZone('Asia/Tehran')` at the call site
 *
 * FR-068 requires the zone to come "from that user's own account settings, falling back to the
 * instance's configured default when the account states none; **no zone is hardcoded**". That is not a
 * preference, it is the whole reason the requirement exists: `plan.md` records the measured trap that
 * Tehran is UTC+3:30, so a period resolved in one zone and rendered through another moves by a day.
 *
 * **The source already exists and is not ours to invent.** Nextcloud resolves a user's zone as
 * `getUserValue($uid, 'core', 'timezone', '')` then `getSystemValueString('default_timezone', 'UTC')` —
 * see `OC\DateTimeZone::getTimeZone()` and `LocalTimeProvider`, which use exactly that chain. FR-068's
 * sentence describes that mechanism, so there is no new column, no admin surface and no migration:
 * the user sets it in Nextcloud's own settings and it is read from there.
 *
 * ## Why a separate port rather than a method on `UserDirectory`
 *
 * `UserDirectory` answers identity — does this person exist, what is their display name — and both of
 * its methods are about naming a person. FR-068's rule is a *policy* (user setting, then instance
 * default, then a final fallback), it is used by reporting alone, and folding it into `UserDirectory`
 * would make every identity consumer carry a rule none of them asked for. One collaborator, one job.
 *
 * ## What the implementation must guarantee, whoever provides it
 *
 * - **Always returns a zone.** A missing user setting is not an error; it is the documented fallback.
 * - **An invalid stored value is not fatal.** A user whose stored zone has since been removed from the
 *   system database must still get a working zone, or one bad preference breaks reporting for them.
 *   Nextcloud core catches this in `OC\DateTimeZone::getTimeZone()` and so must this.
 */
interface RequestTimeZone
{
    /**
     * The zone `$uid`'s reporting periods resolve in.
     *
     * Takes a uid rather than reading the session, so the rule is testable for two users at once —
     * which FR-068's own verification requires ("two users in different zones each receiving their own
     * correct range").
     */
    public function forUser(string $uid): DateTimeZone;
}