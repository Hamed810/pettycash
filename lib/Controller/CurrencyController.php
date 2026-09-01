<?php

declare(strict_types=1);
namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Service\CurrencyService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

final class CurrencyController extends BaseApiController {
    public function __construct(IRequest $request, private CurrencyService $service) { parent::__construct($request); }

    #[NoAdminRequired]
    #[ApiRoute(verb:'GET', url:'/api/v1/currencies')]
    public function index(bool $includeInactive=false): DataResponse { return $this->respond(fn()=>['items'=>$this->service->list($includeInactive)]); }

    #[ApiRoute(verb:'POST', url:'/api/v1/currencies')]
    public function create(string $code,string $name,?string $symbol=null,int $decimalPlaces=0,bool $isDefault=false,bool $active=true): DataResponse {
        return $this->respond(fn()=>$this->service->create($code,$name,$symbol,$decimalPlaces,$isDefault,$active), Http::STATUS_CREATED);
    }

    #[ApiRoute(verb:'PATCH', url:'/api/v1/currencies/{id}')]
    public function update(int $id,?string $name=null,?string $symbol=null,?int $decimalPlaces=null,?bool $isDefault=null,?bool $active=null): DataResponse {
        return $this->respond(fn()=>$this->service->update($id,$name,$symbol,$decimalPlaces,$isDefault,$active));
    }
}
