<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

/**
 * Serves the session endpoints registered in `appinfo/routes.php`
 * (`session#me` and `session#dashboard`).
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
            ]);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    #[NoAdminRequired]
    public function dashboard(): DataResponse
    {
        try {
            $uid = $this->currentUser->uid();
            if ($uid === '') {
                return $this->ocsErrorMapper->forbidden('No authenticated user in session');
            }

            $roles = $this->resolveRoles($uid);
            $dashboard = [];

            // Cards are only present for the roles the caller actually holds, so a
            // purchaser never sees finance or administration summaries.
            if (in_array(Role::Purchaser->value, $roles, true)) {
                $dashboard['purchaser'] = ['userId' => $uid];
            }
            if (in_array(Role::CostCenterManager->value, $roles, true)) {
                $dashboard['costCenter'] = ['userId' => $uid];
            }
            if (in_array(Role::SeniorManager->value, $roles, true)) {
                $dashboard['senior'] = ['userId' => $uid];
            }
            if (in_array(Role::Finance->value, $roles, true)) {
                $dashboard['finance'] = ['userId' => $uid];
            }
            if (in_array(Role::Administrator->value, $roles, true)) {
                $dashboard['admin'] = ['userId' => $uid];
            }

            return new DataResponse($dashboard);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
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
