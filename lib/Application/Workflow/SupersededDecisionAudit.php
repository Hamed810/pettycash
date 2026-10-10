<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Shared\Enum\AuditEventType;
use OCA\PettyCash\Domain\Shared\Uuid;
use Psr\Log\LoggerInterface;

/**
 * FR-085: the one place a `SUPERSEDED_ATTEMPT` event is written.
 *
 * ## Why this exists rather than a call inside each decision service
 *
 * Two services raise {@see SupersededDecisionAttempt} — the cost-center/senior path and the finance
 * path — and FR-085 requires *exactly one* event per refused attempt carrying the same seven facts.
 * Two copies of that payload builder would be two places for the two paths to drift, which is
 * exactly how the cost-center and finance decision paths came to disagree about the audit stream in
 * the first place (`DecisionEventType` exists for that reason).
 *
 * ## Why it must run *after* the rollback
 *
 * The refusal is raised from inside `TransactionManager::transactional()`, and that rolls the
 * database transaction back. An audit event written before the rollback would be discarded with it,
 * so the attempt would leave no trace — the precise outcome FR-085 says is unacceptable. Therefore
 * this service is invoked from the *catching* side, after the rollback has completed, and opens its
 * own short transaction for the single insert.
 *
 * That ordering is the whole reason this class is not a method on the exception: an exception that
 * wrote its own audit record before being thrown would be writing inside the transaction that is
 * about to roll back, and would look correct in every test that used an in-memory repository.
 *
 * ## Why the event asserts nothing about the transaction's state
 *
 * `before` carries the decision already in force and `after` carries the decision the caller
 * attempted — the shape `contracts/ocs-api.yaml` documents on `AuditEvent.eventType` for this
 * member. `financial_effect` is `NONE` and no `decision_id` is attached to the *attempted* decision,
 * because no such decision exists. Pointing `decision_id` at the in-force decision would be
 * defensible, but it is left null so that nothing in the row can be read as "this attempt produced
 * this decision" — the failure mode FR-085 exists to prevent.
 */
final class SupersededDecisionAudit
{
    public function __construct(
        private readonly AuditWriter $auditWriter,
        private readonly ExpenseTransactionRepository $transactionRepository,
        private readonly TransactionManager $transactionManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Record one refused attempt. Never throws: it runs on the failure path of another operation, and
     * an audit-write fault here must not replace the caller's actionable 409 with a 500 — or, worse,
     * silently swallow the refusal and let the request look successful.
     */
    public function record(
        SupersededDecisionAttempt $attempt,
        string $actorUid,
        string $actorRole,
        ?string $commandId,
    ): void {
        try {
            $this->transactionManager->transactional(function () use ($attempt, $actorUid, $actorRole, $commandId): void {
                // Re-read the transaction rather than carrying the aggregate out of the rolled-back
                // transaction: after the rollback the row is the *first* decision's committed state,
                // which is the state the caller must be told to reload and look at.
                $transaction = $this->transactionRepository->findByPublicId(
                    $attempt->transactionPublicId()
                );

                $this->auditWriter->append([
                    'id' => (string)Uuid::random(),
                    // Both are nullable in the schema, and both are filled from the live read. A null
                    // here would make the event unaddressable for the access investigation it exists
                    // to serve, so a transaction that cannot be read back is logged rather than
                    // written as an unattributable row.
                    'sheet_id' => $transaction?->sheetId,
                    'transaction_id' => $transaction?->dbId,
                    'revision_id' => $transaction?->revision()->dbId,
                    'stage' => $attempt->stage()->value,
                    // Deliberately null: no decision row exists for the attempted decision. See the
                    // class docblock.
                    'decision_id' => null,
                    'entity_type' => 'transaction',
                    'entity_id' => $attempt->transactionPublicId(),
                    'event_type' => AuditEventType::SupersededAttempt->value,
                    'actor_uid' => $actorUid,
                    'actor_role' => $actorRole,
                    'explanation' => null,
                    // FR-052 still applies: the applicable before-and-after values. `before` is the
                    // decision that stands; `after` is what the caller asked for. One read of this
                    // row reconstructs the race without a second query.
                    'before' => [
                        'decision' => $attempt->inForceDecision()?->value,
                        'revision_id' => $attempt->revisionPublicId(),
                        'revision_number' => $transaction?->revision()->number(),
                    ],
                    'after' => [
                        'attempted_decision' => $attempt->attemptedDecision()->value,
                        'stage' => $attempt->stage()->value,
                        // Recorded so a reader can tell "the decision changed" from "the revision
                        // changed underneath" — both refuse, and FR-085 names both.
                        'revision_id' => $attempt->revisionPublicId(),
                        'financial_effect' => 'NONE',
                    ],
                    'command_id' => $commandId,
                    'occurred_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
                ]);
            });
        } catch (\Throwable $error) {
            // The refusal itself must still reach the client: the 409 is the answer the business
            // rule requires, and losing it because the trace could not be written would turn a
            // correct, explained refusal into an unexplained fault. The failure is recorded
            // technically, where FR-053's append-only business rule does not reach.
            $this->logger->error(
                'A superseded decision attempt (FR-085) could not be recorded in audit',
                [
                    // Matches `TechnicalLogger`, so an operator greps one app tag rather than two
                    // spellings. PSR-3 rather than `TechnicalLogger` itself: that class is an
                    // infrastructure wrapper over Nextcloud's logger, and this service is in the
                    // application layer, which does not depend on infrastructure.
                    'app' => 'pettycash',
                    'transaction' => $attempt->transactionPublicId(),
                    'stage' => $attempt->stage()->value,
                    'attemptedDecision' => $attempt->attemptedDecision()->value,
                    'exception' => $error,
                ]
            );
        }
    }
}