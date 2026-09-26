<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use RuntimeException;

/**
 * An account-movement repository that fails on demand.
 *
 * A rollback test has to fail *something*, and the only way to fail the ledger write specifically
 * is to make the ledger write fail. Substituting the whole repository away would test a scenario
 * the settlement never encounters, and asserting on a run in which nothing threw would assert
 * nothing at all — which is what the pre-existing "rollback" tests in this suite do.
 *
 * It decorates the real in-memory double rather than replacing it, so the state that *did* get
 * written before the failure is still observable: that is what makes "nothing was committed" a
 * statement about the ledger rather than about an empty stand-in.
 */
final class FailingAccountMovementRepository implements AccountMovementRepository
{
    public bool $failOnSave = false;

    /** @var list<string> Commands the settlement tried to write before failing. */
    public array $attempted = [];

    public function __construct(private readonly AccountMovementRepository $inner)
    {
    }

    public function findById(int $id): ?AccountMovement
    {
        return $this->inner->findById($id);
    }

    public function findByCommandId(string $commandId): ?AccountMovement
    {
        return $this->inner->findByCommandId($commandId);
    }

    public function findBySourceRevisionId(int|string $sourceRevisionId): ?AccountMovement
    {
        return $this->inner->findBySourceRevisionId($sourceRevisionId);
    }

    public function findByAccountId(int $accountId, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        return $this->inner->findByAccountId($accountId, $from, $to);
    }

    public function findByAccountIdPaged(int $accountId, int $offset, int $limit): array
    {
        return $this->inner->findByAccountIdPaged($accountId, $offset, $limit);
    }

    public function save(AccountMovement $movement): void
    {
        if ($this->failOnSave) {
            throw new RuntimeException('Injected ledger write failure');
        }

        $this->attempted[] = $movement->commandId;
        $this->inner->save($movement);
    }

    public function inner(): AccountMovementRepository
    {
        return $this->inner;
    }
}
