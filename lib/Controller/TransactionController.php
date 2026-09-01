<?php

declare(strict_types=1);
namespace OCA\PettyCash\Controller;
use OCA\PettyCash\Service\TransactionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
final class TransactionController extends BaseApiController {
    public function __construct(IRequest $request,private TransactionService $service){parent::__construct($request);}
    /** @param array<string,mixed> $data */
    #[NoAdminRequired] #[ApiRoute(verb:'POST',url:'/api/v1/lists/{listUuid}/transactions')]
    public function create(string $listUuid,array $data=[]):DataResponse{return $this->respond(fn()=>$this->service->create($listUuid,$data),Http::STATUS_CREATED);}
    /** @param array<string,mixed> $data */
    #[NoAdminRequired] #[ApiRoute(verb:'PATCH',url:'/api/v1/transactions/{uuid}')]
    public function update(string $uuid,int $version,array $data=[]):DataResponse{return $this->respond(fn()=>$this->service->update($uuid,$version,$data));}
    #[NoAdminRequired] #[ApiRoute(verb:'DELETE',url:'/api/v1/transactions/{uuid}')]
    public function delete(string $uuid):DataResponse{return $this->respond(function()use($uuid){$this->service->delete($uuid);return ['deleted'=>true];});}
}
