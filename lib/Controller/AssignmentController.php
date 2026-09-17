<?php

declare(strict_types=1);
namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Db\ProjectMapper;
use OCA\PettyCash\Domain\Exception\ForbiddenException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Service\AssignmentService;
use OCA\PettyCash\Service\UserDirectoryService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * v2.0.0: manager (M1) and destination-owner (M2) assignment, plus
 * the live Nextcloud user directory lookup that backs their dropdowns.
 * GET routes are usable by any logged-in user (so the frontend can
 * show current assignments); assignment writes (PUT) are admin-only
 * by omission of #[NoAdminRequired], consistent with
 * CurrencyController/CategoryController's create/update routes.
 */
final class AssignmentController extends BaseApiController {
    public function __construct(
        IRequest $request,
        private AssignmentService $assignments,
        private UserDirectoryService $users,
        private ProjectMapper $projectMapper,
        private IUserSession $userSession,
    ) { parent::__construct($request); }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/users')]
    public function searchUsers(string $query = '', int $limit = 20): DataResponse {
        return $this->respond(fn() => ['items' => $this->users->search($query, $limit)]);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/purchasers/{userId}/manager')]
    public function getManager(string $userId): DataResponse {
        return $this->respond(fn() => ['purchaserId' => $userId, 'managerId' => $this->assignments->resolveManagerFor($userId)]);
    }

    #[ApiRoute(verb: 'PUT', url: '/api/v1/purchasers/{userId}/manager')]
    public function setManager(string $userId, string $managerId): DataResponse {
        return $this->respond(fn() => $this->assignments->assignManager($userId, $managerId, $this->currentUserId()));
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/destinations/{uuid}/owner')]
    public function getOwner(string $uuid): DataResponse {
        return $this->respond(function () use ($uuid) {
            $destination = $this->findDestination($uuid);
            return [
                'destinationUuid' => $uuid,
                'ownerId' => $this->assignments->resolveOwnerFor((int)$destination->getId()),
            ];
        });
    }

    #[ApiRoute(verb: 'PUT', url: '/api/v1/destinations/{uuid}/owner')]
    public function setOwner(string $uuid, string $ownerId): DataResponse {
        return $this->respond(function () use ($uuid, $ownerId) {
            $destination = $this->findDestination($uuid);
            return $this->assignments->assignDestinationOwner((int)$destination->getId(), $ownerId, $this->currentUserId());
        });
    }

    private function findDestination(string $uuid): \OCA\PettyCash\Db\Project {
        try {
            return $this->projectMapper->findByUuid($uuid);
        } catch (DoesNotExistException) {
            throw new NotFoundException('Destination not found.');
        }
    }

    private function currentUserId(): string {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            throw new ForbiddenException('Login is required.');
        }
        return $uid;
    }
}
