<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Workflow;

use OCA\PettyCash\Application\Command\CommandConflict;
use OCA\PettyCash\Application\Command\CommandResult;
use OCA\PettyCash\Application\Command\CommandResultRepository;
use OCA\PettyCash\Application\Command\IdempotencyService;
use PHPUnit\Framework\TestCase;

final class IdempotencyServiceTest extends TestCase
{
    public function testReplayReturnsOriginalAndChangedPayloadConflicts(): void
    {
        $repository = new class implements CommandResultRepository {
            /** @var array<string, CommandResult> */ public array $items = [];
            public function find(string $id): ?CommandResult { return $this->items[$id] ?? null; }
            public function save(CommandResult $result): void { $this->items[$result->commandId] = $result; }
        };
        $service = new IdempotencyService($repository);
        $first = $service->execute('4d572034-15ef-4a22-8bf7-c1b64024dabe', ['b' => 2, 'a' => 1], static fn (): array => ['id' => 'x']);
        $replay = $service->execute('4d572034-15ef-4a22-8bf7-c1b64024dabe', ['a' => 1, 'b' => 2], static fn (): array => ['id' => 'y']);
        self::assertSame($first, $replay);
        $this->expectException(CommandConflict::class);
        $service->execute('4d572034-15ef-4a22-8bf7-c1b64024dabe', ['a' => 9], static fn (): array => []);
    }
}
