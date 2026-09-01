<?php

declare(strict_types=1);
namespace OCA\PettyCash\Controller;
use OCA\PettyCash\AppInfo\Application;
use OCA\PettyCash\Domain\Exception\ForbiddenException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Service\AttachmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
final class AttachmentContentController extends Controller {
 public function __construct(IRequest $request,private AttachmentService $service){parent::__construct(Application::APP_ID,$request);}
 #[NoAdminRequired] #[FrontpageRoute(verb:'GET',url:'/evidence/{uuid}')]
 public function show(string $uuid):Response{try{$data=$this->service->content($uuid);$response=new DataDisplayResponse($data['content']);$safe=str_replace(['"',"\r","\n"],'_',basename($data['originalName']));$response->addHeader('Content-Type',$data['mimeType']);$response->addHeader('Content-Disposition','inline; filename="'.$safe.'"');$response->addHeader('X-Content-Type-Options','nosniff');return $response;}catch(ForbiddenException $e){return new DataResponse(['message'=>$e->getMessage()],Http::STATUS_FORBIDDEN);}catch(NotFoundException $e){return new DataResponse(['message'=>$e->getMessage()],Http::STATUS_NOT_FOUND);}}
}
