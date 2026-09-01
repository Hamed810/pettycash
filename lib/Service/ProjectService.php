<?php

declare(strict_types=1);
namespace OCA\PettyCash\Service;

use OCA\PettyCash\Db\CurrencyMapper;
use OCA\PettyCash\Db\Project;
use OCA\PettyCash\Db\ProjectMapper;
use OCA\PettyCash\Db\ProjectMember;
use OCA\PettyCash\Db\ProjectMemberMapper;
use OCA\PettyCash\Domain\ProjectRole;
use OCA\PettyCash\Domain\Exception\ConflictException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

final class ProjectService {
    public function __construct(private ProjectMapper $mapper, private ProjectMemberMapper $memberMapper, private CurrencyMapper $currencyMapper, private IUserManager $userManager, private AuthorizationService $auth, private UuidService $uuid) {}

    /** @return list<array<string,mixed>> */
    public function listForCurrentUser(bool $includeInactive=false): array {
        $items=$this->auth->isAdmin() ? $this->mapper->findAll($includeInactive) : $this->mapper->findForUser((string)$this->auth->currentUserId());
        return array_map(fn(Project $p)=>$this->serialize($p),$items);
    }

    /** @return array<string,mixed> */
    public function create(string $code,string $name,?string $description,int $defaultCurrencyId,string $createdBy,bool $active=true): array {
        $code=strtoupper(trim($code)); $name=trim($name);
        if(!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,63}$/',$code)) throw new ValidationException('Project code must be 2-64 letters, digits, dash or underscore.');
        if($name==='') throw new ValidationException('Project name is required.');
        try{$this->mapper->findByCode($code);throw new ConflictException('Project code already exists.');}catch(DoesNotExistException){}
        try{$currency=$this->currencyMapper->find($defaultCurrencyId);}catch(DoesNotExistException){throw new ValidationException('Default currency does not exist.');}
        if(!$currency->getActive()) throw new ValidationException('Default currency must be active.');
        $now=time(); $p=new Project(); $p->setUuid($this->uuid->v4());$p->setCode($code);$p->setName($name);$p->setDescription($description);$p->setDefaultCurrencyId($defaultCurrencyId);$p->setActive($active);$p->setCreatedBy($createdBy);$p->setCreatedAt($now);$p->setUpdatedAt($now);
        return $this->serialize($this->mapper->insert($p));
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function update(string $uuid,array $changes): array {
        try{$p=$this->mapper->findByUuid($uuid);}catch(DoesNotExistException){throw new NotFoundException('Project not found.');}
        if(array_key_exists('name',$changes)){ $name=trim((string)$changes['name']); if($name==='') throw new ValidationException('Project name is required.'); $p->setName($name); }
        if(array_key_exists('description',$changes))$p->setDescription($changes['description']!==null?(string)$changes['description']:null);
        if(array_key_exists('defaultCurrencyId',$changes)){ try{$currency=$this->currencyMapper->find((int)$changes['defaultCurrencyId']);}catch(DoesNotExistException){throw new ValidationException('Default currency does not exist.');} if(!$currency->getActive())throw new ValidationException('Default currency must be active.'); $p->setDefaultCurrencyId((int)$changes['defaultCurrencyId']); }
        if(array_key_exists('active',$changes))$p->setActive((bool)$changes['active']);
        $p->setUpdatedAt(time()); return $this->serialize($this->mapper->update($p));
    }

    /** @param list<array{userId:string,role:string}> $members @return list<array<string,mixed>> */
    public function replaceMembers(string $projectUuid,array $members): array {
        try{$p=$this->mapper->findByUuid($projectUuid);}catch(DoesNotExistException){throw new NotFoundException('Project not found.');}
        $allowed=[ProjectRole::PURCHASER,ProjectRole::MANAGER1,ProjectRole::MANAGER2,ProjectRole::ACCOUNTANT];
        $desired=[];$roleUsers=[];
        foreach($members as $m){$uid=trim((string)($m['userId']??''));$role=(string)($m['role']??'');if($uid===''||!in_array($role,$allowed,true))throw new ValidationException('Every project member needs a valid user ID and role.');if(!$this->userManager->userExists($uid))throw new ValidationException("Nextcloud user '{$uid}' does not exist.");$desired[$uid.'|'.$role]=['userId'=>$uid,'role'=>$role];$roleUsers[$role][$uid]=true;}
        foreach(array_keys($roleUsers[ProjectRole::MANAGER1]??[]) as $uid){if(isset($roleUsers[ProjectRole::MANAGER2][$uid]))throw new ValidationException('Manager 1 and Manager 2 must be different Nextcloud users.');}
        $existing=$this->memberMapper->findByProject((int)$p->getId(),false);$byKey=[];foreach($existing as $e)$byKey[$e->getUserId().'|'.$e->getRole()]=$e;
        foreach($existing as $e){$key=$e->getUserId().'|'.$e->getRole();$e->setActive(isset($desired[$key]));$this->memberMapper->update($e);}
        foreach($desired as $key=>$m){if(isset($byKey[$key]))continue;$e=new ProjectMember();$e->setProjectId((int)$p->getId());$e->setUserId($m['userId']);$e->setRole($m['role']);$e->setActive(true);$e->setCreatedAt(time());$this->memberMapper->insert($e);}
        return array_map(fn(ProjectMember $m)=>$this->serializeMember($m),$this->memberMapper->findByProject((int)$p->getId()));
    }

    /** @return list<array<string,mixed>> */
    public function members(string $projectUuid): array {try{$p=$this->mapper->findByUuid($projectUuid);}catch(DoesNotExistException){throw new NotFoundException('Project not found.');}return array_map(fn(ProjectMember $m)=>$this->serializeMember($m),$this->memberMapper->findByProject((int)$p->getId()));}
    /** @return array<string,mixed> */
    private function serialize(Project $p): array {return ['id'=>$p->getId(),'uuid'=>$p->getUuid(),'code'=>$p->getCode(),'name'=>$p->getName(),'description'=>$p->getDescription(),'defaultCurrencyId'=>$p->getDefaultCurrencyId(),'active'=>$p->getActive(),'createdBy'=>$p->getCreatedBy()];}
    /** @return array<string,mixed> */
    private function serializeMember(ProjectMember $m): array {return ['id'=>$m->getId(),'projectId'=>$m->getProjectId(),'userId'=>$m->getUserId(),'role'=>$m->getRole(),'active'=>$m->getActive()];}
}
