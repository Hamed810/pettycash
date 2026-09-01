<?php

declare(strict_types=1);
namespace OCA\PettyCash\Controller;
use OCA\PettyCash\Service\AttachmentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
final class AttachmentController extends BaseApiController {
    public function __construct(private IRequest $apiRequest,private AttachmentService $service){parent::__construct($apiRequest);}
    #[NoAdminRequired] #[ApiRoute(verb:'POST',url:'/api/v1/transactions/{txnUuid}/attachments')]
    public function create(string $txnUuid,string $type='RECEIPT'):DataResponse{return $this->respond(fn()=>$this->service->upload($txnUuid,$type,$this->apiRequest->getUploadedFile('file')),Http::STATUS_CREATED);}
    #[NoAdminRequired] #[ApiRoute(verb:'DELETE',url:'/api/v1/attachments/{uuid}')]
    public function delete(string $uuid):DataResponse{return $this->respond(function()use($uuid){$this->service->remove($uuid);return ['deleted'=>true];});}
}
