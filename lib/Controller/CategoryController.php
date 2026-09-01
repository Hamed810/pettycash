<?php

declare(strict_types=1);
namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Service\CategoryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

final class CategoryController extends BaseApiController {
    public function __construct(IRequest $request,private CategoryService $service){parent::__construct($request);}

    #[NoAdminRequired]
    #[ApiRoute(verb:'GET',url:'/api/v1/categories')]
    public function index(bool $includeInactive=false):DataResponse{return $this->respond(fn()=>['items'=>$this->service->list($includeInactive)]);}

    #[ApiRoute(verb:'POST',url:'/api/v1/categories')]
    public function create(string $code,string $name,?string $description=null,bool $receiptRequired=true,bool $vehicleRequired=false,bool $odometerRequired=false,bool $workerRequired=false,bool $permitRequired=false,bool $attendanceRequired=false,int $sortOrder=0,bool $active=true):DataResponse{
        $rules=compact('receiptRequired','vehicleRequired','odometerRequired','workerRequired','permitRequired','attendanceRequired');
        return $this->respond(fn()=>$this->service->create($code,$name,$description,$rules,$sortOrder,$active),Http::STATUS_CREATED);
    }

    #[ApiRoute(verb:'PATCH',url:'/api/v1/categories/{id}')]
    public function update(int $id,?string $name=null,?string $description=null,?bool $receiptRequired=null,?bool $vehicleRequired=null,?bool $odometerRequired=null,?bool $workerRequired=null,?bool $permitRequired=null,?bool $attendanceRequired=null,?int $sortOrder=null,?bool $active=null):DataResponse{
        $changes=[];foreach(compact('name','description','receiptRequired','vehicleRequired','odometerRequired','workerRequired','permitRequired','attendanceRequired','sortOrder','active') as $k=>$v)if($v!==null)$changes[$k]=$v;
        return $this->respond(fn()=>$this->service->update($id,$changes));
    }
}
