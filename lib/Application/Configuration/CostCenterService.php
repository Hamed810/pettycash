<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Configuration;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\PageBounds;
use OCA\PettyCash\Application\Port\UserDirectory;

/**
 * FR-010 / FR-011 cost-centre and effective-dated manager/senior assignment administration. T082.
 *
 * ## This service creates role assignments; it does not set managers on a cost centre
 *
 * `data-model.md` gives `CostCenter` no manager field and states the relationship as "Has
 * effective-dated cost-center-manager and senior-manager **role assignments**". `managerUid` and
 * `seniorManagerUid` were removed from the aggregate because `DbCostCenterRepository` was writing them
 * to columns that exist in no migration and no table, so every cost-centre write died on
 * `Unknown column 'manager_uid'` and had never been executed by a test. T236's real-database round trip
 * is what found it.
 *
 * So `contracts/ocs-api.yaml`'s `CostCenterWrite.managerUserId` / `seniorManagerUserId` are **inputs to
 * this service, not columns on a row**. Both are required by the contract, both become a
 * `RoleAssignment` scoped by `cost_center_id`, and neither survives on the `CostCenter` aggregate —
 * which is the only way the data can be both persisted and readable by
 * `ManagementReviewService::getManagedCostCenterCodes()`, the code that already resolves a reviewer's
 * scope exactly this way.
 *
 * ## Why "exactly one current manager" is enforced here and not by the schema
 *
 * FR-011: "Each cost center MUST have one current cost center manager and one current senior manager,
 * and one person MAY manage multiple cost centers." `pettycash_role_assignments` carries only
 * `pettycash_role_assignments_user_active_idx` — an index on `(user_uid, role, active)`, which is the
 * wrong shape for this rule: it permits two *different* users to be current manager of the same centre.
 * The rule is therefore a conditional uniqueness over `(role, cost_center_id)` among rows valid on a
 * date, and only this service can see that. `RoleAssignmentRepository::findActiveByRoleAndCostCenter()`
 * exists for exactly this check.
 *
 * ## Future routing
 *
 * A cost centre carries `validFrom` / `validTo`, and `CostCenter::isValidOn()` answers per date. A
 * centre whose `validFrom` is next month is therefore valid *next month* and absent today, which is
 * what FR-010's "validity dates … govern future transaction routing" asks for. Every read here takes
 * the date to evaluate on, defaulting to the clock's today — a listing whose members depend on an
 * unstated "now" would be a listing whose answer depends on when it was asked.
 *
 * ## Authorization
 *
 * Writes are administrators only (FR-010, T078's matrix). Reads are open to any authenticated caller,
 * because `TransactionFormView.vue` loads the active cost centres on every open and FR-023/FR-032 both
 * require a purchaser to choose one.
 */
final readonly class CostCenterService
{
    /** The two roles FR-011 requires each cost centre to have exactly one current holder of. */
    public const REQUIRED_MANAGER_ROLES = [Role::CostCenterManager, Role::SeniorManager];

    public function __construct(
        private CostCenterRepository $costCenters,
        private RoleAssignmentRepository $roleAssignments,
        private UserDirectory $userDirectory,
        private AdministratorCheck $administrators,
        private Clock $clock,
        private ConfigurationAuditService $configurationAudit
    ) {}

    /**
     * The cost centres valid on `$on`, paged.
     *
     * @return array{items: list<CostCenter>, page: int, pageSize: int, total: int}
     */
    public function listValidOnForCaller(
        string $actorUid,
        ?DateTimeImmutable $on = null,
        ?int $page = null,
        ?int $pageSize = null
    ): array {
        $this->requireAuthenticated($actorUid);
        $on ??= $this->clock->now();

        $bounds = new PageBounds($page, $pageSize);
        $valid = array_values($this->costCenters->findAllActiveOn($on));
        $total = count($valid);

        return [
            'items' => array_slice($valid, $bounds->offset, $bounds->pageSize),
            ...$bounds->pageMeta($total),
        ];
    }

    /**
     * Create a cost centre together with the two role assignments FR-011 requires.
     *
     * All three writes belong to one call because a cost centre with no manager cannot route a single
     * transaction: FR-032 sends a submitted transaction to "the manager assigned to its cost center",
     * and with no assignment there is nobody to send it to. The caller therefore cannot create the
     * half that is useless and add the half that is mandatory later.
     *
     * Both user ids are validated against the host directory first. An assignment naming a user who
     * does not exist is not a routing decision, it is a typo that would leave the centre unroutable
     * in a way that only surfaces when someone tries to submit an expense against it.
     *
     * @return array{costCenter: CostCenter, manager: RoleAssignment, seniorManager: RoleAssignment}
     *
     * @throws ConfigurationConflict 409 when the code is already in use
     */
    public function create(
        string $actorUid,
        string $code,
        string $name,
        ?string $description,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        string $managerUserId,
        string $seniorManagerUserId,
        bool $active = true,
        ?string $changeReason = null
    ): array {
        $this->requireAdministrator($actorUid);
        $this->requireHostUser($managerUserId, 'managerUserId');
        $this->requireHostUser($seniorManagerUserId, 'seniorManagerUserId');

        if ($this->costCenters->findByCode(trim($code)) !== null) {
            throw ConfigurationConflict::alreadyExists('cost center', trim($code));
        }

        // `CostCenter::create()` owns the shape of a code, a name and a validity window; letting it
        // raise keeps one definition rather than a service copy that can drift from the domain's.
        // T086 / FR-017: the centre and the two assignments it creates are one administrative act, so
        // they are one event -- the assignments are recorded inside its `after`. Emitting three would
        // make one change read as three in the history.
        return $this->configurationAudit->record(
            eventType: ConfigurationEventType::CostCenterCreated->value,
            entityType: 'cost_center',
            // The stable public id, not the code. FR-010 makes `code` mutable, so an event keyed by it
            // would answer to one subject before a rename and a different one after -- and the
            // contract itself addresses a cost centre by `costCenterId: { format: uuid }`. Changed from
            // the `code` T086 first used, together with `update()`, so the two event types name the
            // same centre the same way; see `update()`'s docblock for the evidence.
            subjectOf: static fn(array $r): string => (string) $r['costCenter']->publicId,
            changeReason: $changeReason,
            afterSnapshot: static fn(array $r): array => [
                'code' => $r['costCenter']->code(),
                'name' => $r['costCenter']->name,
                'description' => $r['costCenter']->description,
                'validFrom' => $r['costCenter']->validFrom->format('Y-m-d'),
                'validTo' => $r['costCenter']->validTo?->format('Y-m-d'),
                'active' => $r['costCenter']->active,
                // Named because FR-011 makes "who manages this centre" a question of the configuration,
                // and an audit entry that recorded the centre without its manager would not answer it.
                'manager' => $r['manager']->userUid,
                'seniorManager' => $r['seniorManager']->userUid,
            ],
            mutate: function () use ($code, $name, $description, $validFrom, $validTo, $active, $managerUserId, $seniorManagerUserId): array {
                $costCenter = CostCenter::create(trim($code), trim($name), $description, $validFrom, $validTo);
                $costCenter->active = $active;
                $this->costCenters->save($costCenter);

                // The assignments need the cost centre's surrogate key, which only exists after the save —
                // and this is the reason the aggregate's `public_id` cannot be used for it: `RoleAssignment`
                // holds a `cost_center_id` bigint, matching `pettycash_cost_centers.id`.
                $costCenterId = $this->requireSurrogateKey($costCenter, 'cost center');

                $manager = RoleAssignment::create(
                    $managerUserId,
                    Role::CostCenterManager,
                    $costCenterId,
                    $validFrom,
                    $validTo
                );
                $this->roleAssignments->save($manager);

                $seniorManager = RoleAssignment::create(
                    $seniorManagerUserId,
                    Role::SeniorManager,
                    $costCenterId,
                    $validFrom,
                    $validTo
                );
                $this->roleAssignments->save($seniorManager);

                return [
                    'costCenter' => $costCenter,
                    'manager' => $manager,
                    'seniorManager' => $seniorManager,
                ];
            },
        );
    }

    /**
     * Update a cost centre, and its current manager assignments. T082's reopened scope.
     *
     * ## Why this method was missing while the task read as complete
     *
     * `FR-010` requires administrators to "create, update, activate, and deactivate" cost centers,
     * `contracts/ocs-api.yaml` declares `PATCH /configuration/cost-centers/{costCenterId}`, and
     * `appinfo/routes.php` has registered `configuration#costCenterUpdate` since before this task
     * landed -- but no method answered it. Activation and deactivation are *not* missing separately:
     * `CostCenterWrite` carries `active` and the contract has no second route, so this one write
     * expresses all three of update, activate and deactivate.
     *
     * ## Keyed by the uuid, unlike its two siblings
     *
     * The contract's path parameter is `costCenterId: { format: uuid }`, so the write addresses
     * `CostCenter::publicId` through `findByPublicId()`. `CurrencyService::update()` takes `code` and
     * `ExpenseTypeService::updateByTitle()` takes `title`, because a currency's code is its identity and
     * cannot change. A cost centre's code **can** change -- FR-010 names it among the updatable fields --
     * so keying this on the code would make a rename inexpressible and would let a caller address a
     * different centre than the one they read.
     *
     * ## `code` is mutable here, and that drives three decisions
     *
     * 1. The aggregate is rebuilt through `CostCenter::create()` and the identity carried across,
     *    because `CostCenter::code()` has no setter. `CurrencyService::update()` does the same for the
     *    opposite reason: there the code is the argument, here it is the thing being changed.
     * 2. A rename must be refused when the target code is taken, speaking 409 through
     *    `ConfigurationConflict` rather than leaving it to `pettycash_cost_centers_code_uniq`, which
     *    would surface as a driver exception `OcsErrorMapper` cannot map.
     * 3. **The audit subject is the public id, not the code.** An event keyed by a mutable attribute
     *    stops resolving the moment the centre is renamed -- one subject, two identities. Nothing in the
     *    audit model objects: `pettycash_configuration_audit.entity_id` is a `string(64)` that already
     *    holds a uuid for `ACCOUNT_CHARGED`, and `data-model.md` prescribes only the
     *    `entity_type`/`entity_id` pair, never the value. `create()` was changed to match, so the two
     *    event types name the same centre the same way.
     *
     * ## A manager change is a transfer, never an overwrite
     *
     * US5/AC1 requires assignments to govern future routing "without changing historical actor records"
     * and FR-011 requires exactly one current holder per role, so a reassignment delegates to
     * `transferManager()` and its close-at-`validFrom - 1 second` rule. Passing the *same* holder is
     * treated as "unchanged": `CostCenterWrite` makes both manager fields required, so a caller renaming
     * a centre necessarily resends the current holders, and treating that as a transfer would close and
     * reopen the assignment on every rename, churning the history FR-011 rests on.
     *
     * `null` means "leave alone" and is distinct from the required fields, so a caller that genuinely
     * wants to clear a role -- which FR-011 would forbid anyway -- cannot express it here, and should
     * not be able to.
     *
     * ## The transaction, and why nesting is safe here
     *
     * The whole call is one `ConfigurationAuditService::record()`, so the update and its
     * `COST_CENTER_UPDATED` event commit together. A manager change additionally emits the existing
     * `COST_CENTER_MANAGER_TRANSFERRED` event from `transferManager()`'s own `record()`, which opens a
     * *nested* transaction. That was verified against MariaDB before relying on it: a nested commit
     * persists both the outer and the inner write, and a nested failure propagates the original
     * exception unmasked while discarding both -- so a refused transfer cannot leave the centre updated
     * without its assignment change.
     */
    public function update(
        string $actorUid,
        string $costCenterPublicId,
        int $expectedVersion,
        string $code,
        string $name,
        ?string $description,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        bool $active,
        ?string $managerUserId = null,
        ?string $seniorManagerUserId = null,
        ?string $changeReason = null
    ): CostCenter {
        $this->requireAdministrator($actorUid);

        $existing = $this->costCenters->findByPublicId($costCenterPublicId);
        if ($existing === null) {
            throw new InvalidArgumentException(sprintf('No cost center with id %s exists', $costCenterPublicId));
        }
        if ($existing->lockVersion !== $expectedVersion) {
            throw ConfigurationConflict::staleVersion(
                'cost center',
                $costCenterPublicId,
                $expectedVersion,
                $existing->lockVersion
            );
        }

        $code = trim($code);

        // Only a *change* of code can collide. Re-sending the current code is what every caller does,
        // because `CostCenterWrite` makes `code` required, so testing it unconditionally would refuse
        // every update of every other field.
        if ($code !== $existing->code()) {
            $clash = $this->costCenters->findByCode($code);
            if ($clash !== null && $clash->id !== $existing->id) {
                throw ConfigurationConflict::alreadyExists('cost center', $code);
            }
        }

        // Validate before opening the transaction, so a malformed request is refused without touching
        // the audit stream. The domain factory stays the one definition of a valid code, name and window
        // rather than this method keeping a copy that can drift.
        $updated = CostCenter::create($code, $name, $description, $validFrom, $validTo);
        $updated->active = $active;
        $updated->id = $existing->id;
        $updated->publicId = $existing->publicId;
        $updated->lockVersion = $existing->lockVersion;
        $updated->createdAt = $existing->createdAt;
        $updated->createdBy = $existing->createdBy;

        if ($managerUserId !== null) {
            $this->requireHostUser($managerUserId, 'managerUserId');
        }
        if ($seniorManagerUserId !== null) {
            $this->requireHostUser($seniorManagerUserId, 'seniorManagerUserId');
        }

        return $this->configurationAudit->record(
            eventType: ConfigurationEventType::CostCenterUpdated->value,
            entityType: 'cost_center',
            subjectOf: static fn(CostCenter $c): string => (string) $c->publicId,
            changeReason: $changeReason,
            beforeSnapshot: fn(): array => $this->snapshotOf($existing),
            afterSnapshot: fn(CostCenter $c): array => $this->snapshotOf($c),
            mutate: function () use (
                $actorUid,
                $existing,
                $updated,
                $managerUserId,
                $seniorManagerUserId,
                $validFrom,
                $changeReason
            ): CostCenter {
                $this->costCenters->save($updated);

                // Each transfer opens its own `record()`, nested inside this one. Resending the current
                // holder is a no-op rather than a transfer -- see the docblock.
                foreach ([
                    [Role::CostCenterManager, $managerUserId],
                    [Role::SeniorManager, $seniorManagerUserId],
                ] as [$role, $incoming]) {
                    if ($incoming === null) {
                        continue;
                    }
                    $current = $this->currentManagersOn($updated, $validFrom)[$role->value] ?? [];
                    $alreadyCurrent = false;
                    foreach ($current as $assignment) {
                        if ($assignment->userUid === $incoming) {
                            $alreadyCurrent = true;
                            break;
                        }
                    }
                    if ($alreadyCurrent) {
                        continue;
                    }
                    $this->transferManager($actorUid, $updated, $role, $incoming, $validFrom, $changeReason);
                }

                return $updated;
            },
        );
    }

    /**
     * The audit snapshot of a cost centre: its own fields plus the holders in force today.
     *
     * Shared by `update()`'s before and after so the two halves of the pair cannot be spelled
     * differently -- a `before` from one spelling and an `after` from another is a pair no query can
     * compare. The holders are read on the clock's today rather than on the incoming `validFrom`
     * because that is what is genuinely in force; a transfer dated next month correctly reports today's
     * holder as unchanged.
     *
     * @return array<string,mixed>
     */
    private function snapshotOf(CostCenter $costCenter): array
    {
        $current = $this->currentManagersOn($costCenter, $this->todayOn());

        return [
            'code' => $costCenter->code(),
            'name' => $costCenter->name,
            'description' => $costCenter->description,
            'validFrom' => $costCenter->validFrom->format('Y-m-d'),
            'validTo' => $costCenter->validTo?->format('Y-m-d'),
            'active' => $costCenter->active,
            'lockVersion' => $costCenter->lockVersion,
            'manager' => $current[Role::CostCenterManager->value][0]->userUid ?? null,
            'seniorManager' => $current[Role::SeniorManager->value][0]->userUid ?? null,
        ];
    }

    /**
     * Today as a calendar day at UTC midnight.
     *
     * `currentManagersOn()` defaults to `$this->clock->now()`, which carries a time of day. The
     * validity comparisons behind `isValidOn()` are inclusive at both ends, so a raw instant can read a
     * same-day window as already expired; anchoring on the calendar day keeps the snapshot's notion of
     * "in force" the same one the assignments were written against.
     */
    private function todayOn(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            $this->clock->now()->format('Y-m-d'),
            new \DateTimeZone('UTC')
        );
    }

    /**
     * Give a cost centre a new manager or senior manager from `$validFrom`, closing the previous one.
     *
     * This is a **transfer**, not a second assignment. FR-011 permits exactly one *current* holder per
     * role, so adding without closing would leave two people both current and both routing — a state the
     * requirement forbids and that nothing below the application layer can prevent, because
     * `pettycash_role_assignments` has no uniqueness over `(role, cost_center_id)`.
     *
     * The outgoing assignment is closed with `validTo = $validFrom - 1 second`, not `$validFrom`, because
     * `RoleAssignment::isValidOn()` is inclusive at both ends: closing on the same date would leave one
     * day on which both holders are valid, and a transaction dated that day would route ambiguously.
     *
     * @return array{outgoing: ?RoleAssignment, incoming: RoleAssignment}
     *
     * @throws ConfigurationConflict 409 when the incoming user is already current in that role
     */
    public function transferManager(
        string $actorUid,
        CostCenter $costCenter,
        Role $role,
        string $incomingUserId,
        DateTimeImmutable $validFrom,
        ?string $changeReason = null
    ): array {
        $this->requireAdministrator($actorUid);

        if (!in_array($role, self::REQUIRED_MANAGER_ROLES, true)) {
            throw new InvalidArgumentException(
                'Only a cost-center manager or senior manager role may be transferred'
            );
        }
        $this->requireHostUser($incomingUserId, 'incomingUserId');

        $costCenterId = $this->requireSurrogateKey($costCenter, 'cost center');

        $current = $this->roleAssignments->findActiveByRoleAndCostCenter($role, $costCenterId, $validFrom);
        foreach ($current as $assignment) {
            if ($assignment->userUid === $incomingUserId) {
                throw ConfigurationConflict::alreadyExists(
                    $role->value,
                    sprintf('%s for cost center %s', $incomingUserId, $costCenter->code())
                );
            }
        }

        // T086 / FR-017: "assignments" is a category of its own in the requirement, so a transfer is
        // audited as a change to an assignment rather than folded into the cost-centre stream. The
        // subject is the *incoming* assignment, because that is the row this change created; the
        // outgoing one it closed is recorded in `before`, which is the only place a "who held this
        // before" question can be answered from afterwards.
        return $this->configurationAudit->record(
            eventType: ConfigurationEventType::ManagerTransferred->value,
            entityType: 'role_assignment',
            subjectOf: static fn(array $r): string => $r['incoming']->publicId ?? ($r['incoming']->role->value . ':' . $r['incoming']->userUid),
            changeReason: $changeReason,
            beforeSnapshot: static fn(): array => [
                'costCenter' => $costCenter->code(),
                'role' => $role->value,
                'holders' => array_map(
                    static fn(RoleAssignment $a): string => $a->userUid,
                    $current
                ),
            ],
            afterSnapshot: static fn(array $r): array => [
                'costCenter' => $costCenter->code(),
                'role' => $role->value,
                'incoming' => $r['incoming']->userUid,
                'validFrom' => $r['incoming']->validFrom->format('Y-m-d'),
                'closedAt' => $r['outgoing']?->validTo?->format('Y-m-d H:i:s'),
            ],
            mutate: function () use ($current, $validFrom, $costCenterId, $role, $incomingUserId): array {
                // Close every current holder of the role, so the invariant is restored whatever state the
                // centre was in before — including a centre that somehow already had two.
                $outgoing = null;
                foreach ($current as $assignment) {
                    $assignment->validTo = $validFrom->modify('-1 second');
                    $this->roleAssignments->save($assignment);
                    $outgoing ??= $assignment;
                }

                $incoming = RoleAssignment::create($incomingUserId, $role, $costCenterId, $validFrom, null);
                $this->roleAssignments->save($incoming);

                return ['outgoing' => $outgoing, 'incoming' => $incoming];
            },
        );
    }

    /**
     * The current manager and senior manager of a cost centre on `$on`.
     *
     * @return array<string, list<RoleAssignment>> keyed by `Role::value`
     */
    public function currentManagersOn(CostCenter $costCenter, ?DateTimeImmutable $on = null): array
    {
        $on ??= $this->clock->now();
        $costCenterId = $this->requireSurrogateKey($costCenter, 'cost center');

        $managers = [];
        foreach (self::REQUIRED_MANAGER_ROLES as $role) {
            $managers[$role->value] = $this->roleAssignments->findActiveByRoleAndCostCenter(
                $role,
                $costCenterId,
                $on
            );
        }
        return $managers;
    }

    /**
     * A cost centre's surrogate key, or a loud failure if it was never persisted.
     *
     * `RoleAssignment::costCenterId` is a bigint foreign key, so a `null` here would be written as
     * `cost_center_id = 0` — an assignment attached to no centre, which is invisible to every scope
     * read and leaves the centre silently unroutable. Failing here names the cause instead.
     */
    private function requireSurrogateKey(CostCenter $costCenter, string $what): int
    {
        if ($costCenter->id === null) {
            throw new \LogicException(
                sprintf('The %s must be persisted before it can be referenced', $what)
            );
        }
        return $costCenter->id;
    }

    private function requireHostUser(string $uid, string $field): void
    {
        $uid = trim($uid);
        if ($uid === '') {
            throw new InvalidArgumentException(sprintf('%s is required', $field));
        }
        if (!$this->userDirectory->exists($uid)) {
            throw new InvalidArgumentException(
                sprintf('%s names "%s", which is not a user in this Nextcloud', $field, $uid)
            );
        }
    }

    private function requireAdministrator(string $actorUid): void
    {
        if ($actorUid === '' || !$this->administrators->isAdministrator($actorUid)) {
            throw new \LogicException('Only an administrator may administer cost centers');
        }
    }

    private function requireAuthenticated(string $actorUid): void
    {
        if (trim($actorUid) === '') {
            throw new \LogicException('An authenticated caller is required');
        }
    }
}
