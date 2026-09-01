<?php

declare(strict_types=1);
namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Service\ProjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class ProjectController extends BaseApiController {
    public function __construct(IRequest $request,private ProjectService $service,private IUserSession $userSession){parent::__construct($request);}

    #[NoAdminRequired]
    #[ApiRoute(verb:'GET',url:'/api/v1/projects')]
    public function index(bool $includeInactive=false):DataResponse{return $this->respond(fn()=>['items'=>$this->service->listForCurrentUser($includeInactive)]);}

    #[ApiRoute(verb:'POST',url:'/api/v1/projects')]
    public function create(string $code,string $name,int $defaultCurrencyId,?string $description=null,bool $active=true):DataResponse{
        $uid=$this->userSession->getUser()?->getUID() ?? 'unknown';
        return $this->respond(fn()=>$this->service->create($code,$name,$description,$defaultCurrencyId,$uid,$active),Http::STATUS_CREATED);
    }

    #[ApiRoute(verb:'PATCH',url:'/api/v1/projects/{uuid}')]
    public function update(string $uuid,?string $name=null,?string $description=null,?int $defaultCurrencyId=null,?bool $active=null):DataResponse{
        $changes=[];foreach(compact('name','description','defaultCurrencyId','active') as $k=>$v)if($v!==null)$changes[$k]=$v;
        return $this->respond(fn()=>$this->service->update($uuid,$changes));
    }

    #[ApiRoute(verb:'GET',url:'/api/v1/projects/{uuid}/members')]
    public function members(string $uuid):DataResponse{return $this->respond(fn()=>['items'=>$this->service->members($uuid)]);}

    /** @param list<array{userId:string,role:string}> $members */
    #[ApiRoute(verb:'PUT',url:'/api/v1/projects/{uuid}/members')]
    public function replaceMembers(string $uuid,array $members=[]):DataResponse{return $this->respond(fn()=>['items'=>$this->service->replaceMembers($uuid,$members)]);}
}
