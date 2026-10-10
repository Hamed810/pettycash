<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Configuration;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\Enum\Role;

/**
 * FR-016 / US5 shared-queue finance-officer administration. T084.
 *
 * ## The contract has no update route, so `POST` is an upsert that can also revoke
 *
 * `contracts/ocs-api.yaml` declares exactly two operations for finance assignments — `GET` and
 * `POST /configuration/finance-assignments` — and **no** `PATCH` and no `DELETE`. The three sibling
 * configuration resources each have a dedicated `PATCH`; this one does not, and
 * `FinanceAssignmentWrite` carries `active` as a **required** field. Those two facts together decide
 * the write semantics: `POST` has to be able to do all three of "add an officer", "correct an
 * officer" and "take an officer out of the queue", or a finance officer could never be removed
 * through the approved API and the shared queue would only ever grow.
 *
 * So this is an upsert keyed by `(user, FINANCE)`, and it is why no `expectedVersion` is required
 * here even though `CurrencyService::update()` and `ExpenseTypeService::updateByTitle()` both demand
 * one: there is a single row per user to edit, so two administrators acting on the same user
 * converge on the same row instead of each writing their own version, and the losing write is
 * visible in the `changeReason` the next reader sees rather than being a silently divergent branch.
 * Adding `PATCH` for this resource is a contract change, not a service change.
 *
 * ## Why the Clock is a dependency at all
 *
 * The decision "does this user already hold the role?" is a question about *today*, and it is what
 * separates an update from an insert. `GET` is specified as returning "Active **and historical**
 * finance assignments", so an ended row is part of the contract's answer and must survive; reusing it
 * on re-appointment would rewrite the record that the user held the role during that window and the
 * promised history would not exist.
 *
 * That decision is therefore made on the **validity window**, not on the `active` flag, and the two
 * are kept apart on purpose. A window that has *ended* is a historical claim — this user held the role
 * until that date — so a re-appointment becomes a **new row**. A row that is merely *inactive* carries
 * no such claim: it is the same assignment, switched off, so it is updated **in place**. Inserting a
 * second row there would claim the user held the role twice over one window.
 *
 * The repository's three pre-existing lookups cannot express either half. `findAllByRole()` (added for
 * this task) lists history but applies no date, and the other three all filter on `active = true` plus
 * a supplied date, so none of them can find the switched-off row. Hence `Clock` plus the
 * `findCurrentByUserAndRole()` added alongside it.
 *
 * ## One current assignment per user is this service's job
 *
 * The table carries only `pettycash_role_assignments_user_active_idx`, a **non-unique** index on
 * `(user_uid, role, active)`. Nothing below the application layer stops two current `FINANCE` rows for
 * one user, and FR-016's shared queue counts officers — it would count that user twice. Collapsing
 * every write onto the single current row is the half of the invariant this service can hold; the
 * unique index that would hold the other half does not exist and is not this task's to add.
 *
 * ## Calendar days, not instants
 *
 * `valid_from` and `valid_to` are DATE columns, `RoleAssignment::isValidOn()` is inclusive of
 * `valid_to`, and the adapter compares with `$date->format('Y-m-d')`. Comparing a *time of day*
 * against midnight would declare an assignment ending 2025-12-31 expired at 00:01 on 2025-12-31, a
 * whole day early. Every date this service builds is therefore anchored at UTC midnight on the
 * calendar day the clock reports, so the in-memory and database paths agree on every boundary and a
 * test that pins the last day of a window tests the real rule.
 *
 * ## `cost_center_id` is null, always
 *
 * `data-model.md` gives `role` the value `FINANCE` and `cost_center_id` the note "null for shared
 * finance role". A shared-queue officer is deliberately unscoped: FR-016 requires every officer to
 * see every transaction at the finance stage, so scoping one to a single cost centre would defeat the
 * queue it belongs to. `RoleAssignment::create()` already rejects a non-null cost centre for a
 * non-manager role, so this service passes `null` and lets the domain hold the line rather than
 * reaching around it.
 *
 * ## `changeReason` is validated here and reaches the audit history via T086
 *
 * `FinanceAssignmentWrite.changeReason` is `minLength: 1` and is enforced at this boundary, because
 * an empty explanation is unrecoverable once the write is accepted. `data-model.md` gives
 * `RoleAssignment` no reason column, so the string is stored nowhere on the row itself; it is handed to
 * `ConfigurationAuditService`, which records it as FR-017's "explanation" in the separate
 * configuration audit history. This is the only place it can be persisted, and T086's report is where
 * the arrangement is described.
 *
 * ## Authorization
 *
 * Writes are administrators only (T078's matrix: "administrators manage configuration"). Reads
 * require an authenticated caller but are not administrator-gated. Unlike `CostCenterService` and
 * `ExpenseTypeService`, this service has no purchaser-form consumer to justify an open read — the
 * sibling rationale does not transfer — but no artifact restricts the roster to administrators
 * either, and narrowing it here would invent a requirement. So the read gate is authentication,
 * which is the smaller claim of the two.
 */
final readonly class FinanceAssignmentService
{
    public function __construct(
        private RoleAssignmentRepository $roleAssignments,
        private UserDirectory $userDirectory,
        private AdministratorCheck $administrators,
        private Clock $clock,
        private ConfigurationAuditService $configurationAudit
    ) {}

    /**
     * The finance roster: every `FINANCE` assignment, active and historical alike.
     *
     * Backs `GET /configuration/finance-assignments`, whose response is a bare array of
     * `RoleAssignment` — deliberately not the paged envelope the other three configuration listings
     * return, so no `PageBounds` is applied here either.
     *
     * @return list<RoleAssignment>
     */
    public function listForCaller(string $actorUid): array
    {
        $this->requireAuthenticated($actorUid);

        return $this->roleAssignments->findAllByRole(Role::Finance);
    }

    /**
     * Add, correct or revoke one shared-queue finance assignment.
     *
     * @throws LogicException            403 when the actor is not an administrator
     * @throws InvalidArgumentException 400 when `userId` is blank or names no host user, when
     *                                   `changeReason` is blank, or when a date is malformed or
     *                                   `validTo` precedes `validFrom`
     */
    public function create(
        string $actorUid,
        string $userId,
        string $validFrom,
        string $changeReason,
        ?string $validTo = null,
        bool $active = true
    ): RoleAssignment {
        $this->requireAdministrator($actorUid);

        $userId = $this->requireHostUser($userId);
        $this->requireChangeReason($changeReason);

        $from = $this->parseDate($validFrom, 'validFrom');
        $to = $validTo === null ? null : $this->parseDate($validTo, 'validTo');

        $today = $this->today();

        // T086 / FR-017: the assignment and its audit record commit together, and `changeReason` -- which
        // `RoleAssignment` has no column for -- becomes the recorded explanation here.
        //
        // The subject is the upsert key `(role, user)`, which is exactly the identity this method
        // resolves on. `publicId` would be the tidier value, but `pettycash_role_assignments` is the one
        // configuration table whose `public_id` is minted by the adapter rather than by the aggregate, so
        // it is null on a path that has not saved yet; a subject that is sometimes null would make some
        // rows in the history unaddressable.
        return $this->configurationAudit->record(
            eventType: ConfigurationEventType::FinanceAssignmentChanged->value,
            entityType: 'finance_assignment',
            subjectOf: static fn(RoleAssignment $a): string => $a->role->value . ':' . $a->userUid,
            changeReason: $changeReason,
            // Read *before* the write, so an upsert over a live assignment records what it replaced.
            // Empty on a creation, which is how the history distinguishes the two.
            beforeSnapshot: function () use ($userId, $today): array {
                $current = $this->roleAssignments->findCurrentByUserAndRole($userId, Role::Finance, $today);
                if ($current === null) {
                    return [];
                }

                return [
                    'role' => $current->role->value,
                    'user' => $current->userUid,
                    'validFrom' => $current->validFrom->format('Y-m-d'),
                    'validTo' => $current->validTo?->format('Y-m-d'),
                    'active' => $current->active,
                ];
            },
            afterSnapshot: static fn(RoleAssignment $a): array => [
                'role' => $a->role->value,
                'user' => $a->userUid,
                'costCenterId' => $a->costCenterId,
                'validFrom' => $a->validFrom->format('Y-m-d'),
                'validTo' => $a->validTo?->format('Y-m-d'),
                'active' => $a->active,
            ],
            mutate: function () use ($userId, $from, $to, $active, $today): RoleAssignment {
                // The user's current assignment for this role — the row whose validity window covers
                // today, whether or not it is switched off. Updated in place, so repeated POSTs converge
                // on one row instead of accumulating one row per write.
                $current = $this->roleAssignments->findCurrentByUserAndRole($userId, Role::Finance, $today);

                if ($current !== null) {
                    $current->validFrom = $from;
                    $current->validTo = $to;
                    $current->active = $active;
                    $this->roleAssignments->save($current);

                    return $current;
                }

                // Either the first assignment for this user, or a re-appointment after the previous one
                // lapsed or was revoked. A new row in the second case is what preserves the history the
                // listing promises; `RoleAssignment::create()` rejects a non-null cost centre for
                // `FINANCE`, so the shared queue is unscoped by construction rather than by convention.
                $assignment = RoleAssignment::create($userId, Role::Finance, null, $from, $to);
                $assignment->active = $active;
                $this->roleAssignments->save($assignment);

                return $assignment;
            },
        );
    }

    /**
     * Today, as a calendar day at UTC midnight.
     *
     * @see the class docblock on calendar days versus instants
     */
    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            $this->clock->now()->format('Y-m-d'),
            new DateTimeZone('UTC')
        );
    }

    /**
     * A `YYYY-MM-DD` calendar day at UTC midnight.
     *
     * The round-trip check is what rejects `01/01/2026`: `createFromFormat()` accepts it, so without
     * comparing the formatted result back a malformed date would be silently interpreted as a valid
     * one and the service would persist a window the caller never asked for.
     */
    private function parseDate(string $value, string $field): DateTimeImmutable
    {
        $value = trim($value);

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(
                sprintf('%s must be a calendar date as YYYY-MM-DD, got "%s"', $field, $value)
            );
        }

        return $parsed;
    }

    /**
     * The assignment may only name a user that exists in this Nextcloud.
     *
     * `user_uid` is a plain `string(64)` with no foreign key, so the database cannot stop a typo or a
     * departed user from being granted the finance role. FR-016's shared queue is built from these
     * uids, so a row naming nobody would make a real officer unreachable in every finance decision.
     */
    private function requireHostUser(string $uid): string
    {
        $uid = trim($uid);

        if ($uid === '') {
            throw new InvalidArgumentException('userId is required');
        }

        if (!$this->userDirectory->exists($uid)) {
            throw new InvalidArgumentException(
                sprintf('userId names "%s", which is not a user in this Nextcloud', $uid)
            );
        }

        return $uid;
    }

    /**
     * @see the class docblock: validated here, persisted by T086
     */
    private function requireChangeReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'changeReason is required: a change to a sensitive assignment must carry an explanation'
            );
        }
    }

    private function requireAdministrator(string $actorUid): void
    {
        if ($actorUid === '' || !$this->administrators->isAdministrator($actorUid)) {
            throw new LogicException('Only an administrator may administer finance assignments');
        }
    }

    private function requireAuthenticated(string $actorUid): void
    {
        if (trim($actorUid) === '') {
            throw new LogicException('An authenticated caller is required');
        }
    }
}