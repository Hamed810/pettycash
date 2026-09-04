<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Service\AdminSettingsService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\IRequest;

final class AdminController extends BaseApiController {

   public function __construct(
    IRequest $request,
    private AdminSettingsService $settings
    ) {
    parent::__construct($request);
    }


    #[ApiRoute(
        verb:'GET',
        url:'/api/v1/admin/settings'
    )]
    public function index(): DataResponse {

        return new DataResponse(
            $this->settings->getSettings()
        );
    }



    #[ApiRoute(
        verb:'PUT',
        url:'/api/v1/admin/settings'
    )]
    public function save(
        array $settings
    ): DataResponse {

        return new DataResponse(
            $this->settings->saveSettings($settings)
        );
    }
}