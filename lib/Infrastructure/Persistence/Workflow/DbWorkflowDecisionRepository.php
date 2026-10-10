<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\Workflow\SupersededDecisionAttempt;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

final class DbWorkflowDecisionRepository implements WorkflowDecisionRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?WorkflowDecision
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_workflow_decisions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?WorkflowDecision
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_workflow_decisions')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByCommandId(string $commandId): ?WorkflowDecision
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_workflow_decisions')
            ->where($qb->expr()->eq('command_id', $qb->createNamedParameter($commandId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<WorkflowDecision> */
    public function findByTransactionId(int $transactionId): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('d.*')
            ->from('pettycash_workflow_decisions', 'd')
            ->join('d', 'pettycash_transaction_revisions', 'r', $qb->expr()->eq('d.revision_id', 'r.id'))
            ->join('r', 'pettycash_transactions', 't', $qb->expr()->eq('r.transaction_id', 't.id'))
            ->where($qb->expr()->eq('t.id', $qb->createNamedParameter($transactionId)))
            ->orderBy('d.decided_at', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<WorkflowDecision> */
    public function findByRevisionAndStage(int $revisionId, WorkflowStage $stage): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_workflow_decisions')
            ->where($qb->expr()->eq('revision_id', $qb->createNamedParameter($revisionId)))
            ->andWhere($qb->expr()->eq('stage', $qb->createNamedParameter($stage->value)))
            ->orderBy('decided_at', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(WorkflowDecision $decision): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $decision->dbId ? $this->findById($decision->dbId) : $this->findByPublicId($decision->id);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($existing) {
            // Decisions are immutable - should not be updated
            return;
        }

        $transactionDbId = $this->getTransactionDbIdForRevision($decision->revisionId);
        $revisionDbId = $this->getRevisionDbId($decision->revisionId);

        try {
            $qb->insert('pettycash_workflow_decisions')
                ->values([
                    'public_id' => $qb->createNamedParameter($decision->id),
                    'transaction_id' => $qb->createNamedParameter($transactionDbId),
                    'revision_id' => $qb->createNamedParameter($revisionDbId),
                    'stage' => $qb->createNamedParameter($decision->stage->value),
                    'decision' => $qb->createNamedParameter($decision->decision->value),
                    'explanation' => $qb->createNamedParameter($decision->explanation ?? ''),
                    'actor_uid' => $qb->createNamedParameter($decision->actorUid ?? $this->currentUser->uid()),
                    'actor_name' => $qb->createNamedParameter($decision->actorName ?? $this->userDirectory->displayName($decision->actorUid ?? $this->currentUser->uid())),
                    'actor_role' => $qb->createNamedParameter($decision->actorRole ?? 'FINANCE'),
                    'command_id' => $qb->createNamedParameter($decision->commandId),
                    'decided_at' => $decision->decidedAt ? $qb->createNamedParameter($decision->decidedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                ])
                ->executeStatement();
        } catch (Throwable $error) {
            // FR-085: a racing decision is a business outcome, not a database fault.
            //
            // The application layer already refuses a second decision at the same stage with a read
            // inside the transaction, but a read and an insert cannot be made atomic without
            // serialising the whole decision path — which FR-085 explicitly declines, and which the
            // unique index exists to make unnecessary. So the window between them is real and narrow,
            // and closing it here means a race that lands in that window is answered the same way as
            // one caught by the read: 409 with the decision in force named, plus a
            // `SUPERSEDED_ATTEMPT` event.
            //
            // Without this arm the violation reached `OcsErrorMapper`'s default arm as an unexplained
            // `500 UNEXPECTED_FAILURE` with its message withheld, and the losing reviewer left no
            // record — the exact worst-of-both-halves FR-085 was written to end.
            //
            // Only a violation of `pettycash_workflow_decisions_revision_stage_uniq` is converted. Any
            // other driver failure keeps its own type: this must not launder a genuine persistence
            // fault into a business answer a client would treat as "reload and continue".
            if (!self::isRevisionStageUniqueViolation($error)) {
                throw $error;
            }

            $inForce = $this->findByRevisionAndStage($revisionDbId, $decision->stage);

            throw new SupersededDecisionAttempt(
                $this->getTransactionPublicId($transactionDbId),
                $decision->revisionId,
                $decision->stage,
                $decision->decision,
                $inForce === [] ? null : $inForce[count($inForce) - 1]->decision,
            );
        }

        $decision->dbId = (int) $qb->getLastInsertId();
        // The insert writes `public_id` from `$decision->id`, but never assigned it back to the
        // aggregate's own `publicId` mirror, so a decision that had just been saved reported
        // `publicId === null` and `findByPublicId(null)` could not find the row it had written.
        // `mapRow()` sets the mirror on the way back in; this sets it on the way out. Found by
        // T236's real-database round trip.
        $decision->publicId = $decision->id;
    }

    /**
     * Whether this failure is the `revision_stage_uniq` index refusing a second decision.
     *
     * Matched on the constraint name rather than on "some unique violation", because the table
     * carries two unique indexes that mean different things: `..._revision_stage_uniq` is the race,
     * and `..._command_uniq` is FR-084's replay arriving under an id that is somehow not in
     * `command_results`. Reporting the second as a superseded attempt would tell a client that a
     * *decision* is in force when what actually happened is a command-identifier collision.
     *
     * The message is inspected rather than the exception class, because Nextcloud wraps each backend
     * in its own exception type (`Doctrine\DBAL\Exception\UniqueConstraintViolationException` on
     * Doctrine 4, and it was a `DbalException` with a code before that), so a class check would be
     * a version-dependent guess. Every declared backend reports the constraint name, and
     * `DatabasePortabilityTest` asserts the application compiles SQL that does not depend on which one
     * is in use.
     */
    private static function isRevisionStageUniqueViolation(Throwable $error): bool
    {
        $chain = '';
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $chain .= ' ' . $current->getMessage();
        }

        return str_contains($chain, 'revision_stage_uniq');
    }

    /**
     * Resolve the owning transaction through the revision's own `transaction_id`
     * join key.
     *
     * Deliberately NOT routed through `pettycash_transactions.active_revision_id`: a
     * decision stays attached to the revision it was made against, and once a
     * correction activates a new revision the superseded one must still resolve,
     * otherwise an immutable decision would be silently orphaned.
     */
    private function getTransactionDbIdForRevision(string $revisionPublicId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('r.transaction_id')
            ->from('pettycash_transaction_revisions', 'r')
            ->where($qb->expr()->eq('r.public_id', $qb->createNamedParameter($revisionPublicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if ($row === false || $row['transaction_id'] === null) {
            throw new \LogicException(
                'Cannot persist a workflow decision whose owning revision is unknown'
            );
        }

        return (int) $row['transaction_id'];
    }

    private function getRevisionDbId(string $publicId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pettycash_transaction_revisions')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Resolve an internal `pettycash_transactions.id` to its external UUID.
     */
    private function getTransactionPublicId(int $id): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('public_id')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if ($row === false) {
            throw new \LogicException(
                'Workflow decision references a transaction that no longer exists'
            );
        }

        return (string) $row['public_id'];
    }

    /**
     * Resolve an internal `pettycash_transaction_revisions.id` to its external UUID.
     */
    private function getRevisionPublicId(int $id): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('public_id')
            ->from('pettycash_transaction_revisions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if ($row === false) {
            throw new \LogicException(
                'Workflow decision references a revision that no longer exists'
            );
        }

        return (string) $row['public_id'];
    }

    private function mapRow(array $row): WorkflowDecision
    {
        $decision = new WorkflowDecision(
            $row['public_id'],
            $this->getTransactionPublicId((int) $row['transaction_id']),
            $this->getRevisionPublicId((int) $row['revision_id']),
            WorkflowStage::from($row['stage']),
            Decision::from($row['decision']),
            $row['explanation'] ?? null,
            $row['command_id'],
            $row['decided_at'] ? new DateTimeImmutable($row['decided_at']) : null,
            $row['actor_uid'] ?? null,
            $row['actor_name'] ?? null,
            $row['actor_role'] ?? null
        );
        $decision->dbId = (int) $row['id'];
        $decision->publicId = $row['public_id'];
        return $decision;
    }
}