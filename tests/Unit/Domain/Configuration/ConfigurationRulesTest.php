<?php
declare(strict_types=1);
namespace OCA\PettyCash\Tests\Unit\Domain\Configuration;
use InvalidArgumentException;
use OCA\PettyCash\Domain\Configuration\Currency;
use PHPUnit\Framework\TestCase;
final class ConfigurationRulesTest extends TestCase { public function testCurrencyMinorUnitRange(): void { self::assertSame('IRR', Currency::create('IRR','Rial',0)->code()); $this->expectException(InvalidArgumentException::class); Currency::create('usd','Dollar',5); } }
