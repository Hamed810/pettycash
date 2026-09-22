<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use PHPUnit\Framework\TestCase;

final class MoneyAndIdentityTest extends TestCase
{
    public function testUuidAndMoneyUseCanonicalExactRepresentations(): void
    {
        $id = Uuid::fromString('4d572034-15ef-4a22-8bf7-c1b64024dabe');
        self::assertSame('4d572034-15ef-4a22-8bf7-c1b64024dabe', (string) $id);
        self::assertSame(1250, Money::positive(1250, CurrencyCode::fromString('IRR'))->minorUnits());
    }

    public function testMoneyRejectsNonPositiveAndCurrencyMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::positive(0, CurrencyCode::fromString('IRR'));
    }

    public function testAdditionRejectsDifferentCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(1, CurrencyCode::fromString('IRR'))->add(Money::of(1, CurrencyCode::fromString('USD')));
    }

    public function testUtcTimestampIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \OCA\PettyCash\Domain\Shared\Utc::assert(new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')));
    }
}
