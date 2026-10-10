<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Workflow;

use OCA\PettyCash\Application\Conflict;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use RuntimeException;

/**
 * FR-085: a decision arrived for a transaction that had already moved on, so **first write wins**.
 *
 * ## What this is, and what it is not
 *
 * This is **not** FR-084's replay. A replay is one command arriving twice under the same
 * `X-PettyCash-Command-ID`, and it returns the first outcome — `IdempotencyService` answers that, and
 * the operation callback never runs a second time. This is a *different* command issued against
 * state that had already changed, which no command identifier can detect: the command id is new, the
 * payload hash is new, and the only thing that reveals the race is the server reading the
 * transaction's current state. That is why the check that raises this lives inside the transaction
 * that would otherwise record the decision.
 *
 * ## Why 409, and why it is a `Conflict`
 *
 * The request was correct, the transaction exists, and applying the decision now would be wrong. That
 * is the third answer `Conflict` documents, and `OcsErrorMapper` already maps it to 409 — so this
 * class deliberately adds **no new arm** there. The task that raised this (T397) asked for a 409 "in
 * `OcsErrorMapper`"; adding a second arm for a type the existing arm already answers would put two
 * places to change if the status ever moved, and `Conflict`'s own docblock records why the mapper
 * must not accumulate a table of exception classes. The *code* is still client-distinguishable
 * because `errorCode()` is the exception's own.
 *
 * ## Why the caller gets no effect but the reviewer still leaves a trace
 *
 * Applying nothing is the safety property: no decision, no revision, no transition, no balance
 * movement, and above all no audit event representing the attempt as having taken effect. But
 * discarding it silently is not acceptable either, because a discarded attempt is indistinguishable
 * from a request that never happened — a reviewer who clicked would leave nothing to investigate.
 * So the attempt is recorded as `SUPERSEDED_ATTEMPT`, its own event type, which is neither an
 * approval nor a rejection.
 *
 * **The audit write cannot happen where this is thrown.** Throwing rolls the database transaction
 * back, so the event must be written by {@see SupersededDecisionAudit} *after* the rollback, in its
 * own transaction. That is why this exception carries the facts the event needs rather than writing
 * it itself.
 */
final class SupersededDecisionAttempt extends RuntimeException implements Conflict
{
    /**
     * @param string $transactionPublicId the transaction the caller composed the decision for
     * @param string $revisionPublicId    the revision that was active when the caller composed it
     * @param WorkflowStage $stage        the stage the caller was deciding
     * @param Decision $attemptedDecision the decision the caller asked for
     * @param Decision|null $inForceDecision the decision already recorded, when one can be read.
     *                                      `null` only when the race was detected by the database
     *                                      unique index rather than by the in-transaction read, and
     *                                      the read-back raced too.
     */
    public function __construct(
        private readonly string $transactionPublicId,
        private readonly string $revisionPublicId,
        private readonly WorkflowStage $stage,
        private readonly Decision $attemptedDecision,
        private readonly ?Decision $inForceDecision,
    ) {
        parent::__construct(self::messageFor($stage, $attemptedDecision));
    }

    /**
     * The caller-facing sentence FR-085 requires: that a decision is already recorded, and that the
     * caller must reload to see the current outcome.
     *
     * It names **no** internal id and no row. The exception's own message is what the client is
     * shown (`OcsErrorMapper` publishes `$error->getMessage()` for a 4xx), so anything identifying a
     * stored row would be a disclosure of exactly the kind T029 removed.
     */
    private static function messageFor(WorkflowStage $stage, Decision $attempted): string
    {
        return sprintf(
            'A decision is already recorded for this transaction at the %s stage, so this %s decision '
            . 'was not applied. Reload the transaction to see the decision that is currently in force.',
            strtolower($stage->value),
            strtolower($attempted->value),
        );
    }

    public function errorCode(): string
    {
        return 'SUPERSEDED_ATTEMPT';
    }

    public function transactionPublicId(): string
    {
        return $this->transactionPublicId;
    }

    public function revisionPublicId(): string
    {
        return $this->revisionPublicId;
    }

    public function stage(): WorkflowStage
    {
        return $this->stage;
    }

    public function attemptedDecision(): Decision
    {
        return $this->attemptedDecision;
    }

    public function inForceDecision(): ?Decision
    {
        return $this->inForceDecision;
    }
}