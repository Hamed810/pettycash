<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Workflow;

use PHPUnit\Framework\TestCase;

final class GlobalTest extends TestCase
{
    public function testGlobalContainerExists(): void
    {
        $this->assertNotNull(\TestContainerAccessor::getInstance());
    }
}