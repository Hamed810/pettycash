<?php
declare(strict_types=1);
namespace OCA\PettyCash\Infrastructure\Nextcloud;
use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Domain\Shared\Clock;
final class SystemClock implements Clock { public function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('UTC')); } }
