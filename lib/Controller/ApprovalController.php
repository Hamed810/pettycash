<?php

declare(strict_types=1);
namespace OCA\PettyCash\Controller;
use OCA\PettyCash\Service\ApprovalService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
final class ApprovalController extends BaseApiController {
 public function __construct(IRequest $request,private ApprovalService $service){parent::__construct($request);}
 #[NoAdminRequired] #[ApiRoute(verb:'GET',url:'/api/v1/approvals/{stage}')]
 public function queue(string $stage):DataResponse{return $this->respond(fn()=>['items'=>$this->service->queue(strtoupper($stage))]);}
 #[NoAdminRequired] #[ApiRoute(verb:'GET',url:'/api/v1/approvals/{stage}/lists/{uuid}')]
 public function detail(string $stage,string $uuid):DataResponse{return $this->respond(fn()=>$this->service->detail($uuid,strtoupper($stage)));}
 #[NoAdminRequired] #[ApiRoute(verb:'POST',url:'/api/v1/approvals/{stage}/transactions/{uuid}/{action}')]
 public function decide(string $stage,string $uuid,string $action,int $version,?string $comment=null):DataResponse{return $this->respond(fn()=>$this->service->decide($uuid,strtoupper($stage),strtoupper($action),$version,$comment));}
 /** @param array<string,mixed> $data */
 #[NoAdminRequired] #[ApiRoute(verb:'PATCH',url:'/api/v1/approvals/{stage}/transactions/{uuid}')]
 public function edit(string $stage,string $uuid,int $version,array $data=[],?string $reason=null):DataResponse{return $this->respond(fn()=>$this->service->edit($uuid,strtoupper($stage),$version,$data,$reason));}
}
