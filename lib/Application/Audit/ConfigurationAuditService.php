<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Audit;

use Closure;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Domain\Shared\Uuid;

/**
 * FR-017 configuration audit, applied uniformly to every configuration mutation. T086.
 *
 * ## Why one service rather than an audit call in each of the four
 *
 * FR-017 requires a sensitive change to record "the actor, time, explanation, previous value, and new
 * value" in a separate configuration audit history. Left to itself each of the four configuration
 * services would hand-build that payload, and this codebase already contains what that costs.
 *
 * `AuditWriterService` documents two real callers that sent `before_json` / `after_json` -- keys it does
 * not read -- so `ManagementReviewService` and `FinanceSettlementService` stored the literal `"[]"` in
 * both snapshot columns for every approval, rejection and return, discarding the decision, the stage,
 * the explanation and the revision number. FR-053 makes audit rows append-only, so those rows can
 * never be repaired. The writer's vocabulary was never the problem; it was simply undocumented, and
 * nothing detected a caller disagreeing with it.
 *
 * Eight mutations later, the cheapest guard against the ninth instance is that no call site writes a
 * payload at all. They name an event type and a subject, and hand over the mutation.
 *
 * ## Atomicity is the substance of this task, not a detail of it
 *
 * `record()` runs the mutation *and* the audit append inside a single
 * `TransactionManager::transactional()` call, in that order. That ordering is what makes both
 * directions of FR-017 hold:
 *
 * - an audit that cannot be persisted propagates out of the same closure the mutation ran in, so the
 *   transaction rolls back and the configuration change is not committed unrecorded;
 * - a mutation that throws never reaches `appendConfiguration()`, so a refused write cannot leave a
 *   history entry describing a change that did not happen.
 *
 * Emitting the audit *after* the transaction committed would satisfy "an audit event exists" and fail
 * both. The proof that neither can happen needs real MariaDB through the real
 * `NextcloudTransactionManager` -- `InMemoryTransactionManager` does not roll anything back -- so that
 * is asserted in `tests/Integration/Persistence/roundtrip-runner.php` rather than here.
 *
 * ## Nesting
 *
 * `record()` opens a transaction, so a configuration mutation called from inside another
 * `transactional()` would nest. Doctrine DBAL increments its nesting counter and, unless savepoints
 * are enabled, an inner `rollBack()` discards the **whole** outer transaction. That is the
 * conservative direction to fail in: a refused configuration audit inside a larger operation rolls
 * back more than strictly necessary rather than committing an unrecorded change. No current call path
 * nests, and `record()` deliberately does not try to detect the case, because detecting it would mean
 * reaching into infrastructure this port does not expose.
 *
 * ## Actor identity is not taken from the payload
 *
 * The payload this class builds carries no `actor_uid`, `actor_name` or `occurred_at`.
 * `AuditWriterService` resolves the actor from `CurrentUser` -- the authenticated session -- and the
 * time from its own clock, and it is right to: an `actor_uid` arriving in a payload would be a value
 * the caller could have chosen, and FR-017's actor has to be the person who was actually signed in.
 * The `$actorUid` the configuration services take as a parameter exists to *authorize* a write, and
 * recording it here would make two different identities indistinguishable in the history.
 *
 * `actor_role` is passed, and only because `AuditWriterService` defaults it to `PURCHASER`. Every
 * mutation this service wraps has already been refused to a non-administrator, so the default would
 * attribute every configuration change in the product to a purchaser.
 *
 * ## Snapshots
 *
 * `beforeSnapshot` is read *before* the mutation and `afterSnapshot` from its return value. A `before`
 * captured afterwards would be the post-state wearing a "before" label, which is the specific
 * dishonesty `FinanceSettlementService::recordConsumption()` documents about its own
 * `ACCOUNT_CHARGED` event and the reason that event records no balance pair at all.
 *
 * Omitting `beforeSnapshot` records `[]`, which is how a creation is distinguished from a change
 * without a second event type.
 */
final readonly class ConfigurationAuditService
{
    public function __construct(
        private AuditWriter $auditWriter,
        private TransactionManager $transactionManager
    ) {}

    /**
     * Run a configuration mutation and record it, atomically.
     *
     * @template T
     *
     * @param callable(): T                 $mutate         The mutation. Runs inside the transaction.
     * @param (callable(): array<string,mixed>)|null $beforeSnapshot Read before `$mutate` runs; omit
     *        for a creation, which has no previous value.
     * @param (callable(T): array<string,mixed>)|null $afterSnapshot Read from `$mutate`'s return value.
     * @param (callable(T): string)|null    $subjectOf       The stable identity to record as
     *        `entity_id`. Omitted leaves it null rather than guessing; a guessed subject would make two
     *        different subjects collide on one id.
     * @param string|null                   $changeReason    FR-017's explanation. Null for the
     *        resources whose write payload carries no such field -- `contracts/ocs-api.yaml` has
     *        `changeReason` on `CostCenterWrite`, `ExpenseTypeWrite` and `FinanceAssignmentWrite`, and
     *        none on `CurrencyWrite`.
     *
     * @return T The mutation's return value, passed straight back to the caller.
     */
    public function record(
        string $eventType,
        string $entityType,
        callable $mutate,
        ?callable $beforeSnapshot = null,
        ?callable $afterSnapshot = null,
        ?string $changeReason = null,
        ?callable $subjectOf = null
    ): mixed {
        $audit = $this->auditWriter;
        $mutate = Closure::fromCallable($mutate);

        return $this->transactionManager->transactional(function () use (
            $audit,
            $mutate,
            $eventType,
            $entityType,
            $beforeSnapshot,
            $afterSnapshot,
            $changeReason,
            $subjectOf
        ): mixed {
            $before = $beforeSnapshot !== null ? $beforeSnapshot() : [];

            $result = $mutate();

            $after = $afterSnapshot !== null ? $afterSnapshot($result) : [];

            // `before` / `after`, never `before_json` / `after_json`. See the class docblock and
            // `AuditWriterService::rejectPreEncodedSnapshots()`: the writer reads these two keys and
            // encodes them itself, so a payload spelled the other way stores `"[]"` in both columns
            // and discards the values permanently.
            $audit->appendConfiguration([
                'id' => Uuid::random()->__toString(),
                'entity_type' => $entityType,
                'entity_id' => $subjectOf !== null ? $subjectOf($result) : null,
                'event_type' => $eventType,
                'actor_role' => \OCA\PettyCash\Domain\Shared\Enum\Role::Administrator->value,
                'explanation' => $changeReason,
                'before' => $before,
                'after' => $after,
            ]);

            return $result;
        });
    }
}