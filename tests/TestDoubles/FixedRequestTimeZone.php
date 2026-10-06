<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use DateTimeZone;
use OCA\PettyCash\Application\Port\RequestTimeZone;

/**
 * A fixed zone, or one zone per uid.
 *
 * FR-068's own verification needs "two users in different zones each receiving their own correct range
 * with the zone disclosed in both", which a single fixed zone cannot express — so a map is accepted as
 * well as a string.
 */
final class FixedRequestTimeZone implements RequestTimeZone
{
    /** @param array<string, string>|string $zones */
    public function __construct(private array|string $zones) {}

    public function forUser(string $uid): DateTimeZone
    {
        $name = is_array($this->zones) ? ($this->zones[$uid] ?? 'UTC') : $this->zones;

        return new DateTimeZone($name);
    }
}
