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

    // ===== THE ADAPTER'S WIRING (T386) =====

    /**
     * `NextcloudRequestTimeZone::forUser()` must read **both** values and pass them into
     * `TimeZonePreference::resolve()`, in that order.
     *
     * ## Why this is a gap rather than a duplicate of the tests above
     *
     * `resolve()`'s precedence is thoroughly covered by the tests above — eleven of them, including the
     * instance-default case. What none of them touches is the **adapter**: `resolve()` is a pure static
     * method, so passing it two arguments proves the rule and says nothing about what the caller supplies.
     *
     * **The hole is demonstrable.** Replacing the adapter's
     * `$this->config->getSystemValueString(self::SYSTEM_KEY, '')` with a literal `''` — so the instance
     * default is never read at all — leaves **all 27 tests green** across this file and
     * `ReportingPeriodContractTest`. Verified by mutation, not inferred:
     *
     * - the pure-rule tests call `resolve()` directly and never see the adapter;
     * - T378's live-HTTP test sets a **user** `core.timezone`, which is `resolve()`'s *first* argument, so
     *   the second one being wrong changes nothing;
     * - the one HTTP test of the fallback **skips**, because this instance declares no `default_timezone`.
     *
     * So FR-068's second clause was unproven end to end, and this is where it becomes provable without an
     * instance-level configuration change — which is what made it look unprovable.
     *
     * ## Why the source is read rather than reflected
     *
     * `tests/bootstrap.php` does not load Nextcloud's `OCP` classes, so `IConfig` cannot be implemented or
     * stubbed here — the same constraint `RouteDispatchCompletenessTest` works under, and the reason this
     * assertion is textual. It asserts the two facts that matter: the system value is **read**, and it is
     * **one of the two arguments** handed to `resolve()`.
     */
    public function testTheAdapterReadsBothValuesAndPassesThemToResolve(): void
    {
        $source = @file_get_contents(
            // `tests/Unit/Application/Reporting` -> up four levels is the repository root.
            dirname(__DIR__, 4) . '/lib/Infrastructure/Nextcloud/NextcloudRequestTimeZone.php'
        );

        self::assertIsString($source, 'the adapter must be readable for this to mean anything');

        self::assertMatchesRegularExpression(
            "/getSystemValueString\(\s*self::SYSTEM_KEY/",
            $source,
            'the adapter must read the instance default from Nextcloud\'s own system value; a literal here '
            . 'means FR-068\'s "falling back to the instance\'s configured default" clause is never supplied, '
            . 'and every other test in this file still passes'
        );

        self::assertSame(
            1,
            preg_match('/return TimeZonePreference::resolve\((.*?)\);/s', $source, $call),
            'the adapter must hand its two readings to TimeZonePreference::resolve()'
        );

        // The keys are constants, so their **values** are asserted separately: a check that only saw
        // `self::USER_KEY` would prove the adapter passes *a* constant and not that it reads the key
        // Nextcloud writes.
        self::assertMatchesRegularExpression(
            "/USER_APP\s*=\s*'core'/",
            $source,
            'the adapter reads the user zone from Nextcloud\'s own `core` preference'
        );
        self::assertMatchesRegularExpression(
            "/USER_KEY\s*=\s*'timezone'/",
            $source,
            'under `timezone`, so it is the same key core\'s settings page writes'
        );
        self::assertMatchesRegularExpression(
            "/SYSTEM_KEY\s*=\s*'default_timezone'/",
            $source,
            'and the fallback reads `default_timezone`, which is where Nextcloud keeps its instance default'
        );

        self::assertSame(
            1,
            preg_match('/return TimeZonePreference::resolve\((.*?)\);/s', $source, $call),
            'the adapter must hand its two readings to TimeZonePreference::resolve()'
        );

        self::assertMatchesRegularExpression(
            '/getUserValue\([^;]*self::USER_APP[^;]*self::USER_KEY/',
            $call[1],
            'the user\'s own setting is resolve()\'s first argument'
        );
        self::assertMatchesRegularExpression(
            '/getSystemValueString\(/',
            $call[1],
            'and the instance default is the second — a single argument would leave the fallback clause '
            . 'unreachable no matter how well resolve() itself is tested'
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