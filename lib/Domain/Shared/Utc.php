<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Shared;

use DateTimeImmutable;
use InvalidArgumentException;

final class Utc
{
    public static function assert(DateTimeImmutable $time): DateTimeImmutable
    {
        if ($time->getOffset() !== 0) { throw new InvalidArgumentException('Timestamp must be UTC'); }
        return $time;
    }
}
