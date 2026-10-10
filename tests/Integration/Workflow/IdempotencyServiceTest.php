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
    private function newRepository(): CommandResultRepository
    {
        return new class implements CommandResultRepository {
            /** @var array<string, CommandResult> */ public array $items = [];
            public function find(string $id): ?CommandResult { return $this->items[$id] ?? null; }
            public function save(CommandResult $result): void { $this->items[$result->commandId] = $result; }
        };
    }

    public function testReplayReturnsOriginalAndChangedPayloadConflicts(): void
    {
        $repository = $this->newRepository();
        $service = new IdempotencyService($repository);
        $first = $service->execute('4d572034-15ef-4a22-8bf7-c1b64024dabe', 'actor-1', 'test.op', 'target-1', ['b' => 2, 'a' => 1], static fn (): array => ['id' => 'x'], 200);
        $replay = $service->execute('4d572034-15ef-4a22-8bf7-c1b64024dabe', 'actor-1', 'test.op', 'target-1', ['a' => 1, 'b' => 2], static fn (): array => ['id' => 'y'], 200);
        self::assertSame($first, $replay);
        $this->expectException(CommandConflict::class);
        $service->execute('4d572034-15ef-4a22-8bf7-c1b64024dabe', 'actor-1', 'test.op', 'target-1', ['a' => 9], static fn (): array => [], 200);
    }

    public function testReplayByADifferentActorConflicts(): void
    {
        $service = new IdempotencyService($this->newRepository());
        $service->execute('4d572034-15ef-4a22-8bf7-c1b64024dabe', 'actor-1', 'test.op', 'target-1', ['a' => 1], static fn (): array => ['id' => 'x'], 200);
        $this->expectException(CommandConflict::class);
        $service->execute('4d572034-15ef-4a22-8bf7-c1b64024dabe', 'actor-2', 'test.op', 'target-1', ['a' => 1], static fn (): array => ['id' => 'x'], 200);
    }

    public function testReplayWithDifferentOperationOrTargetConflicts(): void
    {
        $service = new IdempotencyService($this->newRepository());
        $service->execute('4d572034-15ef-4a22-8bf7-c1b64024dabe', 'actor-1', 'test.op', 'target-1', ['a' => 1], static fn (): array => ['id' => 'x'], 200);
        try {
            $service->execute('4d572034-15ef-4a22-8bf7-c1b64024dabe', 'actor-1', 'other.op', 'target-1', ['a' => 1], static fn (): array => ['id' => 'x'], 200);
            self::fail('different operation must conflict');
        } catch (CommandConflict $e) {
            self::assertNotEmpty($e->getMessage());
        }
        $this->expectException(CommandConflict::class);
        $service->execute('4d572034-15ef-4a22-8bf7-c1b64024dabe', 'actor-1', 'test.op', 'target-2', ['a' => 1], static fn (): array => ['id' => 'x'], 200);
    }
}
