<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Audit;

use InvalidArgumentException;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\RetentionPolicyStore;
use OCA\PettyCash\Domain\Audit\AuditRetentionPolicy;
use OCA\PettyCash\Domain\Shared\Clock;

/**
 * Policy configuration and compliance verification for FR-057.
 *
 * Two responsibilities, kept together because they are two halves of one control: reading and writing
 * the policy, and reporting whether the policy in force actually satisfies the requirement. The second
 * exists because a floor that is only enforced on the write path is not a floor — a value that predates
 * the policy, or that was written by something else, must still be *detectable* as non-compliant rather
 * than silently assumed correct.
 *
 * Every write is a control-setting change, so it goes to the configuration audit with actor, time,
 * explanation, previous value and new value, per FR-017. Changing a retention policy is exactly the
 * kind of change that has to remain explainable after the fact: it decides what stays readable.
 */
final readonly class RetentionPolicyService
{
    public function __construct(
        private RetentionPolicyStore $store,
        private AuditWriter $auditWriter,
        private Clock $clock
    ) {}

    /**
     * The policy actually in force.
     *
     * Never returns a sub-floor policy. An absent setting, an unparsable one, or one that somehow
     * records a period shorter than FR-057 permits all resolve to the default of exactly seven years,
     * because the safe direction is to keep history for longer, not shorter.
     */
    public function currentPolicy(): AuditRetentionPolicy
    {
        $record = $this->store->load();
        if ($record === null) {
            return AuditRetentionPolicy::default();
        }

        try {
            return AuditRetentionPolicy::of($record['retentionYears']);
        } catch (InvalidArgumentException) {
            return AuditRetentionPolicy::default();
        }
    }

    /**
     * Set the retention period, refusing anything below the FR-057 floor.
     *
     * The refusal happens in the value object, so the floor cannot be bypassed by calling this method.
     * The write and its configuration audit event are separate steps rather than one transaction: the
     * policy lives in application configuration, not in the business database, and a partially applied
     * pair here would be a policy with no recorded change rather than a lost business record. The
     * audit event is written after the value, so the recorded history never claims a change that did
     * not take effect.
     *
     * @throws InvalidArgumentException when the requested period is shorter than the floor.
     */
    public function configure(int $retentionYears, string $explanation): AuditRetentionPolicy
    {
        $policy = AuditRetentionPolicy::of($retentionYears);

        $before = $this->store->load();
        $now = $this->clock->now();

        $this->store->save([
            'retentionYears' => $policy->retentionYears(),
            'updatedAt' => $now->format(DATE_ATOM),
            'updatedBy' => $before['updatedBy'] ?? '',
        ]);

        $this->auditWriter->append([
            'entity_type' => 'audit_retention_policy',
            'entity_id' => null,
            'event_type' => 'RETENTION_POLICY_CHANGED',
            'stage' => 'CONFIGURATION',
            'actor_role' => 'ADMINISTRATOR',
            'explanation' => $explanation,
            'before' => $before === null
                ? []
                : ['retentionYears' => $before['retentionYears']],
            'after' => ['retentionYears' => $policy->retentionYears()],
        ]);

        return $policy;
    }

    /**
     * Whether the stored configuration satisfies FR-057, and what is actually in force.
     *
     * `honorsMinimum` describes the **stored** value, not the effective one. That distinction is the
     * point of the method: `currentPolicy()` substitutes the default for anything absent or
     * sub-floor, so the *effective* policy is compliant by construction and reporting its compliance
     * would be a field that can never be false — a signal that measures nothing. A configuration
     * recording two years is a real discrepancy between what is stored and what FR-057 requires, and
     * it is this field that surfaces it. `effectiveHonorsMinimum` states the separate fact that what
     * the service will actually enforce is safe regardless.
     *
     * `defaultApplied` distinguishes "nothing is configured, so the floor applies" from "something is
     * configured and it is compliant" — the same effective policy, very different operational facts.
     *
     * `permitsDeletion` is reported rather than asked, because the answer cannot vary: FR-057 and
     * Constitution IV both make the record non-deletable regardless of how long it is kept. Reporting
     * it makes that visible to whoever reads the compliance output instead of leaving it implied.
     *
     * @return array{
     *     requiredMinimumYears: int,
     *     configuredYears: int|null,
     *     effectiveYears: int,
     *     honorsMinimum: bool,
     *     effectiveHonorsMinimum: bool,
     *     permitsDeletion: bool,
     *     defaultApplied: bool,
     *     updatedAt: string|null,
     *     updatedBy: string|null
     * }
     */
    public function complianceReport(): array
    {
        $record = $this->store->load();
        $policy = $this->currentPolicy();

        $configuredYears = $record === null ? null : $record['retentionYears'];
        $defaultApplied = $record === null
            || $configuredYears !== $policy->retentionYears();

        $updatedAt = $record === null || $record['updatedAt'] === ''
            ? null
            : $record['updatedAt'];
        $updatedBy = $record === null || $record['updatedBy'] === ''
            ? null
            : $record['updatedBy'];

        return [
            'requiredMinimumYears' => AuditRetentionPolicy::MINIMUM_RETENTION_YEARS,
            'configuredYears' => $configuredYears,
            'effectiveYears' => $policy->retentionYears(),
            'honorsMinimum' => $configuredYears === null
                ? $policy->honorsMinimum()
                : $configuredYears >= AuditRetentionPolicy::MINIMUM_RETENTION_YEARS,
            'effectiveHonorsMinimum' => $policy->honorsMinimum(),
            'permitsDeletion' => $policy->permitsDeletion(),
            'defaultApplied' => $defaultApplied,
            'updatedAt' => $updatedAt,
            'updatedBy' => $updatedBy,
        ];
    }
}
