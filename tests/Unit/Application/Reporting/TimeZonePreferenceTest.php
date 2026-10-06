<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Reporting;

use OCA\PettyCash\Application\Reporting\TimeZonePreference;
use PHPUnit\Framework\TestCase;

/**
 * T312 / FR-068: the time-zone precedence, as a pure rule.
 *
 * ## Why the rule is testable without Nextcloud
 *
 * `OCP\` is not in this application's Composer autoload, so a unit test cannot implement an `IConfig` —
 * no test under `tests/Unit/` in this repository implements an OCP interface, and `NextcloudUserDirectory`
 * has no unit test for the same reason. So the precedence lives in `TimeZonePreference` and this file
 * covers every branch, including the two that a live instance could not be put into cheaply: a blank
 * stored preference (which is what Nextcloud actually stores for "unset") and a stored zone the tzdata
 * no longer contains.
 *
 * ## Why the last test matters more than it looks
 *
 * The reason the rule reads Nextcloud's `core.timezone` rather than a petty-cash column is that the host
 * already owns this fact. That is a claim about Nextcloud, so it is checked against Nextcloud's own
 * source in the container — and **skipped** rather than failed when that tree is not where it is
 * expected, because a missing file is a deployment difference and not a defect in the rule.
 */
final class TimeZonePreferenceTest extends TestCase
{
    // ===== THE CHAIN =====

    public function testTheUsersOwnSettingWins(): void
    {
        self::assertSame(
            'Asia/Tehran',
            TimeZonePreference::resolve('Asia/Tehran', 'Europe/Berlin')->getName(),
            "FR-068 takes the zone from that user's own account settings first"
        );
    }

    public function testTheInstanceDefaultAppliesWhenTheUserStatesNone(): void
    {
        self::assertSame(
            'Europe/Berlin',
            TimeZonePreference::resolve('', 'Europe/Berlin')->getName()
        );
    }

    /**
     * Blank is "states none", not a zone.
     *
     * Nextcloud stores an untouched preference as an empty row rather than deleting it, and a
     * whitespace-only value is what an API-injected setting looks like. Both must fall through rather
     * than reach `new DateTimeZone('')`.
     */
    public function testABlankStoredValueCountsAsNoSetting(): void
    {
        self::assertSame(
            'Europe/Berlin',
            TimeZonePreference::resolve('   ', 'Europe/Berlin')->getName()
        );
    }

    public function testUtcIsTheLastResortWhenNeitherIsSet(): void
    {
        self::assertSame(
            'UTC',
            TimeZonePreference::resolve('', '')->getName(),
            'a zone must come back in every case; UTC is what Nextcloud core also ends at'
        );
    }

    /**
     * Surrounding whitespace is not part of the zone name.
     *
     * `new DateTimeZone(' Asia/Tehran ')` throws, so a value with a stray space would take the
     * unusable-value path and silently resolve to the *instance* default — a wrong answer with no
     * error, which is the failure mode this rule exists to avoid.
     */
    public function testSurroundingWhitespaceIsTolerated(): void
    {
        self::assertSame('Asia/Tehran', TimeZonePreference::resolve('  Asia/Tehran  ', 'UTC')->getName());
    }

    // ===== THE BRANCH THAT PREVENTS A 500 =====

    /**
     * A stored zone the system no longer knows must not become a failed report.
     *
     * A tzdata upgrade or a renamed zone leaves a preference pointing at nothing, and
     * `new DateTimeZone()` throws on it. Without this the user loses every report with a 500 over a
     * preference they cannot see or edit; Nextcloud core makes the same catch in
     * `OC\DateTimeZone::getTimeZone()`.
     */
    public function testAnUnusableStoredZoneFallsBackRatherThanThrowing(): void
    {
        self::assertSame(
            'Europe/Berlin',
            TimeZonePreference::resolve('Mars/Olympus_Mons', 'Europe/Berlin')->getName(),
            'an unusable stored value falls back to the instance default rather than failing the report'
        );
    }

    public function testAnUnusableInstanceDefaultStillYieldsAWorkingZone(): void
    {
        self::assertSame(
            'UTC',
            TimeZonePreference::resolve('', 'Nowhere/Special')->getName(),
            'a misconfigured instance must not turn one bad preference into an exception'
        );
    }

    public function testBothValuesUnusableStillYieldsAWorkingZone(): void
    {
        self::assertSame('UTC', TimeZonePreference::resolve('Mars/Olympus_Mons', 'Nowhere/Special')->getName());
    }

    /**
     * An unusable stored value still prefers the instance default over UTC.
     *
     * The order matters and is easy to invert: a user with a broken preference is far better served by
     * the instance's configured zone than by UTC, because that is what every other user is on.
     */
    public function testABrokenUserValuePrefersTheInstanceDefaultOverUtc(): void
    {
        self::assertSame(
            'Europe/Berlin',
            TimeZonePreference::resolve('Mars/Olympus_Mons', 'Europe/Berlin')->getName()
        );
    }

    // ===== WHY THESE KEYS =====

    /**
     * The chain is Nextcloud's, not this app's invention — checked against Nextcloud's own source.
     *
     * If core renames `core.timezone` or `default_timezone`, this fails and points at the change,
     * rather than leaving petty-cash quietly reading a preference nothing writes.
     */
    public function testTheChainIsNextcloudsOwn(): void
    {
        $source = @file_get_contents(
            '/var/www/html/lib/private/Contacts/ContactsMenu/Providers/LocalTimeProvider.php'
        );

        if ($source === false) {
            self::markTestSkipped(
                'Nextcloud core is not at the expected path in this container, so the chain cannot be '
                . 'cross-checked against core. The rule is still implemented and tested; only the claim '
                . 'that core uses the same two keys is unverified here.'
            );
        }

        self::assertStringContainsString(
            "getUserValue(\$targetUser->getUID(), 'core', 'timezone')",
            $source,
            'Nextcloud reads a user zone from core.timezone; the adapter reads the same key'
        );
        self::assertStringContainsString(
            "getSystemValueString('default_timezone', 'UTC')",
            $source,
            'and falls back to the default_timezone system value ending at UTC, in the same order'
        );
    }
}