<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

/**
 * Serves the `session#me` endpoint registered in `appinfo/routes.php`.
 *
 * `/api/v1/dashboard` used to live here too, behind `session#dashboard`. It moved to
 * `DashboardController` (T112) because this handler returned a role *stub* — a `userId` and nothing
 * else — and never consulted `DashboardQuery`, so it satisfied the route-resolution guard without
 * satisfying US7/AC1. A resolved route is not the same thing as a working endpoint.
 *
 * Role resolution follows FR-002/FR-018: every authenticated user with
 * application access may act as a purchaser, additional roles come from
 * effective-dated assignments, and administrator status is never a finance
 * grant (FR-007).
 */
class SessionController extends OCSController
{
    public function __construct(
        \OCP\IRequest $request,
        private CurrentUser $currentUser,
        private UserDirectory $userDirectory,
        private AdministratorCheck $administratorCheck,
        private RoleAssignmentRepository $roleAssignmentRepository,
        private CostCenterRepository $costCenterRepository,
        private Clock $clock,
        private OcsErrorMapper $ocsErrorMapper
    ) {
        parent::__construct('pettycash', $request);
    }

    #[NoAdminRequired]
    public function me(): DataResponse
    {
        try {
            $uid = $this->currentUser->uid();
            if ($uid === '') {
                return $this->ocsErrorMapper->forbidden('No authenticated user in session');
            }

            $roles = $this->resolveRoles($uid);

            return new DataResponse([
                'userId' => $uid,
                'displayName' => $this->userDirectory->exists($uid)
                    ? $this->userDirectory->displayName($uid)
                    : $uid,
                'roles' => $roles,
                'capabilities' => $this->capabilities($roles),
                'costCenterCodes' => $this->costCenterCodes($uid),
            ]);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * The cost centres this caller is assigned to review, as codes.
     *
     * FR-004 and FR-005 scope a manager's transactions and reports to assigned cost centres, and the
     * client needs that scope to present anything at all: `src/stores/auth.ts` read
     * `capabilities.costCenterCodes`, which the server has never sent, so `costCenterCodes` was
     * permanently `[]` and both `hasCostCenterScope()` and `canReview()` were permanently `false` for
     * the two management stages (T336).
     *
     * **Codes, not public ids, and the reason is that this is the vocabulary the server already
     * authorizes with.** `ManagementReviewService::getManagedCostCenterCodes()` resolves a manager's
     * scope to codes, and a transaction row carries `costCenter.code`. Emitting the same strings the
     * decision path compares is what keeps a client gate and the rule behind it from disagreeing
     * about what identifies a cost centre.
     *
     * Read from the **same** repository and the same effective-date rule as `resolveRoles()`, so the
     * scope and the roles cannot come from two different points in time. An administrator with no
     * manager assignment gets an empty list on purpose: FR-007 grants visibility, not decision
     * authority, and this list is scope rather than a grant.
     *
     * An assignment pointing at a cost centre that is no longer there contributes nothing rather than
     * a null, for the reason `ReportScope::forActor()` gives — dropping a null is the difference
     * between "no results" and "all results", and only the first is safe.
     *
     * @return list<string>
     */
    private function costCenterCodes(string $uid): array
    {
        $scopedRoles = [Role::CostCenterManager, Role::SeniorManager];
        $codes = [];

        foreach ($this->roleAssignmentRepository->findActiveByUserUid($uid, $this->clock->now()) as $assignment) {
            if (!$assignment->isValidOn($this->clock->now()) || !in_array($assignment->role, $scopedRoles, true)) {
                continue;
            }
            if ($assignment->costCenterId === null) {
                continue;
            }

            $code = $this->costCenterRepository->findById($assignment->costCenterId)?->code();
            if ($code !== null && $code !== '') {
                $codes[] = $code;
            }
        }

        $unique = array_values(array_unique($codes));

        // Sorted so the answer does not depend on the order the assignments came back in: this is a
        // set, and a client comparing it should not be sensitive to row order.
        sort($unique);

        return $unique;
    }

    /**
     * @return list<string>
     */
    private function resolveRoles(string $uid): array
    {
        // FR-018: any authenticated user with application access is a purchaser.
        $roles = [Role::Purchaser->value];

        foreach ($this->roleAssignmentRepository->findActiveByUserUid($uid, $this->clock->now()) as $assignment) {
            $value = $assignment->role->value;
            if (!in_array($value, $roles, true)) {
                $roles[] = $value;
            }
        }

        if ($this->administratorCheck->isAdministrator($uid)
            && !in_array(Role::Administrator->value, $roles, true)
        ) {
            $roles[] = Role::Administrator->value;
        }

        return $roles;
    }

    /**
     * Capability flags derived from the caller's effective roles.
     *
     * @param list<string> $roles
     * @return array<string, bool>
     */
    private function capabilities(array $roles): array
    {
        return [
            'canPurchase' => in_array(Role::Purchaser->value, $roles, true),
            'canReviewCostCenter' => in_array(Role::CostCenterManager->value, $roles, true),
            'canReviewSenior' => in_array(Role::SeniorManager->value, $roles, true),
            // FR-007: administrator status alone must never grant finance rights.
            'canDecideFinance' => in_array(Role::Finance->value, $roles, true),
            'canAdminister' => in_array(Role::Administrator->value, $roles, true),
        ];
    }
}
