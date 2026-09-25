<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Application\Account\AccountService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

class AccountController extends OCSController
{
    public function __construct(
        \OCP\IRequest $request,
        private AccountService $accountService,
        private CurrentUser $currentUser,
        private OcsErrorMapper $ocsErrorMapper
    ) {
        parent::__construct('pettycash', $request);
    }

    /**
     * The authenticated caller's own petty-cash accounts.
     *
     * A read-only projection of the caller's own records, so no identifier is accepted from the
     * request: there is no way to ask for another user's account. A `userId` naming somebody else
     * is refused rather than ignored, so the endpoint cannot be used to probe for other users. An
     * empty page is returned as success, because having no account is a normal state the purchaser
     * UI has to render.
     */
    #[NoAdminRequired]
    public function index(?string $userId = null, int $page = 1, int $pageSize = 25): DataResponse
    {
        try {
            $uid = $this->currentUser->uid();
            if ($uid === '') {
                return $this->ocsErrorMapper->forbidden('No authenticated user in session');
            }

            if ($userId !== null && trim($userId) !== '' && $userId !== $uid) {
                return $this->ocsErrorMapper->forbidden('Accounts may only be listed for the authenticated user');
            }

            return new DataResponse($this->accountService->listForUser($uid, $page, $pageSize));
        } catch (\InvalidArgumentException $e) {
            return $this->ocsErrorMapper->validation(
                $e->getMessage(),
                $this->request->getHeader('X-PettyCash-Command-ID'),
                ['page' => [$e->getMessage()]]
            );
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $this->request->getHeader('X-PettyCash-Command-ID'));
        }
    }
}
