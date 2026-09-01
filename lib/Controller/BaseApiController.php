<?php

declare(strict_types=1);
namespace OCA\PettyCash\Controller;

use OCA\PettyCash\AppInfo\Application;
use OCA\PettyCash\Domain\Exception\ConflictException;
use OCA\PettyCash\Domain\Exception\ForbiddenException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

abstract class BaseApiController extends OCSController {
    public function __construct(IRequest $request) { parent::__construct(Application::APP_ID, $request); }

    protected function respond(callable $callback, int $successStatus = Http::STATUS_OK): DataResponse {
        try { return new DataResponse($callback(), $successStatus); }
        catch (ValidationException $e) { return new DataResponse(['error'=>'VALIDATION_ERROR','message'=>$e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY); }
        catch (ConflictException $e) { return new DataResponse(['error'=>'CONFLICT','message'=>$e->getMessage()], Http::STATUS_CONFLICT); }
        catch (ForbiddenException $e) { return new DataResponse(['error'=>'FORBIDDEN','message'=>$e->getMessage()], Http::STATUS_FORBIDDEN); }
        catch (NotFoundException $e) { return new DataResponse(['error'=>'NOT_FOUND','message'=>$e->getMessage()], Http::STATUS_NOT_FOUND); }
    }
}
