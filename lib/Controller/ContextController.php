<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\AppInfo\Application;
use OCA\PettyCash\Service\AppConfigService;
use OCA\PettyCash\Service\AuthorizationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

final class ContextController extends OCSController {
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private AppConfigService $config,
        private AuthorizationService $authorization,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/context')]
    public function index(): DataResponse {
        $user = $this->userSession->getUser();

        return new DataResponse([
            'app' => [
                'id' => Application::APP_ID,
                'version' => '0.4.0',
            ],
            'user' => [
                'id' => $user?->getUID(),
                'displayName' => $user?->getDisplayName(),
                'isAdmin' => $this->authorization->isAdmin($user?->getUID()),
            ],
            'business' => [
                'timezone' => $this->config->getBusinessTimezone(),
                'calendar' => 'jalali',
                'defaultCurrency' => $this->config->getDefaultCurrencyCode(),
            ],
            'ocr' => [
                'enabled' => $this->config->isOcrEnabled(),
                'primaryLanguage' => 'fa',
                'secondaryLanguage' => 'en',
            ],
            'phase' => 4,
        ]);
    }
}
