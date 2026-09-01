<?php

declare(strict_types=1);
namespace OCA\PettyCash\Service;

use OCA\PettyCash\Db\ProjectMapper;
use OCA\PettyCash\Db\Vehicle;
use OCA\PettyCash\Db\VehicleMapper;
use OCA\PettyCash\Domain\Exception\ForbiddenException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

final class VehicleService {
    public function __construct(private VehicleMapper $mapper,private ProjectMapper $projectMapper,private AuthorizationService $auth,private UuidService $uuid){}
    /** @return list<array<string,mixed>> */
    public function list(string $projectUuid,bool $includeInactive=false):array{try{$p=$this->projectMapper->findByUuid($projectUuid);}catch(DoesNotExistException){throw new NotFoundException('Project not found.');}if(!$this->auth->canAccessProject((int)$p->getId()))throw new ForbiddenException('You cannot access this project.');return array_map($this->serialize(...),$this->mapper->findByProject((int)$p->getId(),$includeInactive));}
    /** @return array<string,mixed> */
    public function create(string $projectUuid,string $name,string $plateNumber,?string $vehicleType,?string $notes,bool $active=true):array{try{$p=$this->projectMapper->findByUuid($projectUuid);}catch(DoesNotExistException){throw new NotFoundException('Project not found.');}$name=trim($name);$plateNumber=trim($plateNumber);if($name===''||$plateNumber==='')throw new ValidationException('Vehicle name and plate number are required.');$now=time();$v=new Vehicle();$v->setUuid($this->uuid->v4());$v->setProjectId((int)$p->getId());$v->setName($name);$v->setPlateNumber($plateNumber);$v->setVehicleType($vehicleType);$v->setNotes($notes);$v->setActive($active);$v->setCreatedAt($now);$v->setUpdatedAt($now);return $this->serialize($this->mapper->insert($v));}
    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function update(string $uuid,array $changes):array{try{$v=$this->mapper->findByUuid($uuid);}catch(DoesNotExistException){throw new NotFoundException('Vehicle not found.');}if(array_key_exists('name',$changes)){if(trim((string)$changes['name'])==='')throw new ValidationException('Vehicle name is required.');$v->setName(trim((string)$changes['name']));}if(array_key_exists('plateNumber',$changes)){if(trim((string)$changes['plateNumber'])==='')throw new ValidationException('Plate number is required.');$v->setPlateNumber(trim((string)$changes['plateNumber']));}if(array_key_exists('vehicleType',$changes))$v->setVehicleType($changes['vehicleType']!==null?(string)$changes['vehicleType']:null);if(array_key_exists('notes',$changes))$v->setNotes($changes['notes']!==null?(string)$changes['notes']:null);if(array_key_exists('active',$changes))$v->setActive((bool)$changes['active']);$v->setUpdatedAt(time());return $this->serialize($this->mapper->update($v));}
    /** @return array<string,mixed> */
    private function serialize(Vehicle $v):array{return ['id'=>$v->getId(),'uuid'=>$v->getUuid(),'projectId'=>$v->getProjectId(),'name'=>$v->getName(),'plateNumber'=>$v->getPlateNumber(),'vehicleType'=>$v->getVehicleType(),'notes'=>$v->getNotes(),'active'=>$v->getActive()];}
}
