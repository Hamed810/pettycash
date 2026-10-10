<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;

final class InMemoryAccountMovementRepository implements AccountMovementRepository
{
    /**
     * Movements keyed by their surrogate id, mirroring the real table.
     *
     * The previous version keyed on `(int) $movement->id`, but `AccountMovement::$id` is a
     * public UUID string — `{accountId}-{commandId}`, built by `PettyCashAccount::consume()`
     * — so that cast collapsed **every** movement onto key `0`. Two movements could not
     * coexist: the second silently replaced the first. FR-040's "exactly one consumption per
     * revision" is therefore not assertable against this double at all, because the double
     * can only ever hold one row no matter what the service does.
     *
     * @var array<int, AccountMovement>
     */
    private array $movements = [];

    private int $nextDbId = 1;

    /**
     * Surrogate account key to the account's public UUID.
     *
     * The aggregate carries `accountPublicId` while the port's `findByAccountId` takes the
     * surrogate key, so the double needs the mapping to answer the same question the real
     * adapter answers with a join. Seeded explicitly by the test rather than inferred,
     * because inferring it would mean guessing which account a movement belongs to.
     *
     * @var array<int, string>
     */
    private array $accountIds = [];

    public function findById(int $id): ?AccountMovement
    {
        return $this->movements[$id] ?? null;
    }

    public function findByCommandId(string $commandId): ?AccountMovement
    {
        foreach ($this->movements as $movement) {
            if ($movement->commandId === $commandId) {
                return $movement;
            }
        }
        return null;
    }

    /**
     * Accepts either reference form, because the port declares a surrogate key while the
     * aggregate and `contracts/ocs-api.yaml` (`sourceRevisionId: format: uuid`) both use the
     * public UUID. The old body compared a `?string` against the `int` parameter with `===`,
     * so it could never match and this lookup always answered "not found" — which is
     * indistinguishable from correct behaviour until a test asserts that the consumption a
     * service claims to have written is actually findable.
     */
    public function findBySourceRevisionId(int|string $sourceRevisionId): ?AccountMovement
    {
        $needle = (string) $sourceRevisionId;
        foreach ($this->movements as $movement) {
            if ($movement->sourceRevisionId !== null && $movement->sourceRevisionId === $needle) {
                return $movement;
            }
        }
        return null;
    }

    /**
     * @return list<AccountMovement>
     */
    public function findByAccountId(int $accountId, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $publicId = $this->accountIds[$accountId] ?? null;
        $rows = array_values(array_filter(
            $this->movements,
            static function (AccountMovement $m) use ($publicId, $from, $to): bool {
                if ($publicId === null || $m->accountPublicId !== $publicId) {
                    return false;
                }
                if ($from !== null && $m->occurredAt !== null && $m->occurredAt < $from) {
                    return false;
                }
                if ($to !== null && $m->occurredAt !== null && $m->occurredAt > $to) {
                    return false;
                }
                return true;
            }
        ));
        return $this->sorted($rows);
    }

    /**
     * @return list<AccountMovement>
     */
    public function findByAccountIdPaged(int $accountId, int $offset, int $limit): array
    {
        return array_slice($this->findByAccountId($accountId), $offset, $limit);
    }

    public function save(AccountMovement $movement): void
    {
        // The real adapter is idempotent per command id, because replaying a command must
        // not append a second row to the ledger. A double that appended unconditionally
        // would make a replay test measure the double instead of the service.
        if ($this->findByCommandId($movement->commandId) !== null) {
            return;
        }

        if ($movement->dbId !== null) {
            $this->movements[$movement->dbId] = $movement;
            return;
        }

        $this->movements[$this->nextDbId] = $movement;
        $this->nextDbId++;
    }

    /** Bind a surrogate account key to the public UUID movements will carry. */
    public function mapAccount(int $accountDbId, string $accountPublicId): void
    {
        $this->accountIds[$accountDbId] = $accountPublicId;
    }

    /** @return list<AccountMovement> */
    public function all(): array
    {
        return $this->sorted(array_values($this->movements));
    }

    /** @return list<AccountMovement> */
    public function allOfType(MovementType $type): array
    {
        return array_values(array_filter($this->all(), static fn (AccountMovement $m): bool => $m->type === $type));
    }

    public function count(): int
    {
        return count($this->movements);
    }

    /** Test isolation: the double is a container singleton shared across a test process. */
    public function clear(): void
    {
        $this->movements = [];
        $this->accountIds = [];
        $this->nextDbId = 1;
    }

    /**
     * @param list<AccountMovement> $rows
     * @return list<AccountMovement>
     */
    private function sorted(array $rows): array
    {
        usort($rows, static fn (AccountMovement $a, AccountMovement $b): int
            => ($a->occurredAt?->getTimestamp() ?? 0) <=> ($b->occurredAt?->getTimestamp() ?? 0));

        return $rows;
    }
}
