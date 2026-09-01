<?php

declare(strict_types=1);
namespace OCA\PettyCash\Controller;
use OCA\PettyCash\Service\CostListService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
final class CostListController extends BaseApiController {
    public function __construct(IRequest $request,private CostListService $service){parent::__construct($request);}
    #[NoAdminRequired] #[ApiRoute(verb:'GET',url:'/api/v1/lists')]
    public function index():DataResponse{return $this->respond(fn()=>['items'=>$this->service->listForCurrentUser()]);}
    #[NoAdminRequired] #[ApiRoute(verb:'POST',url:'/api/v1/lists')]
    public function create(string $projectUuid,int $jalaliYear,int $jalaliMonth,?int $currencyId=null):DataResponse{return $this->respond(fn()=>$this->service->create($projectUuid,$jalaliYear,$jalaliMonth,$currencyId),Http::STATUS_CREATED);}
    #[NoAdminRequired] #[ApiRoute(verb:'GET',url:'/api/v1/lists/{uuid}')]
    public function show(string $uuid):DataResponse{return $this->respond(fn()=>$this->service->detail($uuid));}
    #[NoAdminRequired] #[ApiRoute(verb:'POST',url:'/api/v1/lists/{uuid}/submit')]
    public function submit(string $uuid,int $version):DataResponse{return $this->respond(fn()=>$this->service->submit($uuid,$version));}
}
