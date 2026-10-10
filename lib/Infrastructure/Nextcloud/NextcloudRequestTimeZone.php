<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Nextcloud;

use DateTimeZone;
use OCA\PettyCash\Application\Port\RequestTimeZone;
use OCA\PettyCash\Application\Reporting\TimeZonePreference;
use OCP\IConfig;

/**
 * FR-068's request time zone, read from where Nextcloud already keeps it.
 *
 * ## The chain is deliberately Nextcloud's, not ours
 *
 * ```
 * getUserValue($uid, 'core', 'timezone', '')  ?:  getSystemValueString('default_timezone', 'UTC')
 * ```
 *
 * That is the chain Nextcloud uses for itself — `OC\DateTimeZone::getTimeZone()` and
 * `LocalTimeProvider`, which spells it out — and FR-068's sentence ("that user's own account settings,
 * falling back to the instance's configured default when the account states none; no zone is hardcoded")
 * is a description of it rather than a request for something new.
 *
 * A petty-cash-specific timezone column would be a second source of truth for a fact the host already
 * holds, and would need a migration and an administration screen to do it. It would also let a user's
 * reporting periods disagree with the times Nextcloud renders beside them.
 *
 * This class only reads. Every decision about what an absent or unusable value means belongs to
 * {@see TimeZonePreference}, which is a pure rule and is unit-tested; see that class for why the
 * separation is necessary rather than tidiness.
 */
final readonly class NextcloudRequestTimeZone implements RequestTimeZone
{
    /** The per-user preference Nextcloud's settings page writes. */
    private const USER_APP = 'core';
    private const USER_KEY = 'timezone';

    /** The instance-wide default, read from `config.php` / administration settings. */
    private const SYSTEM_KEY = 'default_timezone';

    public function __construct(
        private IConfig $config
    ) {}

    public function forUser(string $uid): DateTimeZone
    {
        return TimeZonePreference::resolve(
            $this->config->getUserValue($uid, self::USER_APP, self::USER_KEY, ''),
            $this->config->getSystemValueString(self::SYSTEM_KEY, '')
        );
    }
}