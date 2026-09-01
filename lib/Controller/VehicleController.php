<?php

declare(strict_types=1);
namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Service\VehicleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

final class VehicleController extends BaseApiController {
    public function __construct(IRequest $request,private VehicleService $service){parent::__construct($request);}

    #[NoAdminRequired]
    #[ApiRoute(verb:'GET',url:'/api/v1/projects/{projectUuid}/vehicles')]
    public function index(string $projectUuid,bool $includeInactive=false):DataResponse{return $this->respond(fn()=>['items'=>$this->service->list($projectUuid,$includeInactive)]);}

    #[ApiRoute(verb:'POST',url:'/api/v1/projects/{projectUuid}/vehicles')]
    public function create(string $projectUuid,string $name,string $plateNumber,?string $vehicleType=null,?string $notes=null,bool $active=true):DataResponse{return $this->respond(fn()=>$this->service->create($projectUuid,$name,$plateNumber,$vehicleType,$notes,$active),Http::STATUS_CREATED);}

    #[ApiRoute(verb:'PATCH',url:'/api/v1/vehicles/{uuid}')]
    public function update(string $uuid,?string $name=null,?string $plateNumber=null,?string $vehicleType=null,?string $notes=null,?bool $active=null):DataResponse{$changes=[];foreach(compact('name','plateNumber','vehicleType','notes','active') as $k=>$v)if($v!==null)$changes[$k]=$v;return $this->respond(fn()=>$this->service->update($uuid,$changes));}
}
