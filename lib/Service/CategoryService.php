<?php

declare(strict_types=1);
namespace OCA\PettyCash\Service;

use OCA\PettyCash\Db\ExpenseCategory;
use OCA\PettyCash\Db\ExpenseCategoryMapper;
use OCA\PettyCash\Domain\Exception\ConflictException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

final class CategoryService {
    public function __construct(private ExpenseCategoryMapper $mapper) {}
    /** @return list<array<string,mixed>> */
    public function list(bool $includeInactive = false): array { return array_map($this->serialize(...), $this->mapper->findAll($includeInactive)); }

    /** @param array<string,mixed> $rules @return array<string,mixed> */
    public function create(string $code, string $name, ?string $description, array $rules, int $sortOrder = 0, bool $active = true): array {
        $code = strtolower(trim($code)); $name = trim($name);
        if (!preg_match('/^[a-z0-9_\-]{2,64}$/', $code)) throw new ValidationException('Category code must use lowercase letters, digits, underscore or dash.');
        if ($name === '') throw new ValidationException('Category name is required.');
        try { $this->mapper->findByCode($code); throw new ConflictException('Category code already exists.'); } catch (DoesNotExistException) {}
        $e = new ExpenseCategory(); $now=time();
        $e->setCode($code); $e->setName($name); $e->setDescription($description); $this->applyRules($e,$rules);
        $e->setSortOrder($sortOrder); $e->setActive($active); $e->setCreatedAt($now); $e->setUpdatedAt($now);
        return $this->serialize($this->mapper->insert($e));
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function update(int $id, array $changes): array {
        try { $e=$this->mapper->find($id); } catch (DoesNotExistException) { throw new NotFoundException('Category not found.'); }
        if (array_key_exists('name',$changes)) { $name=trim((string)$changes['name']); if($name==='') throw new ValidationException('Category name is required.'); $e->setName($name); }
        if (array_key_exists('description',$changes)) $e->setDescription($changes['description'] !== null ? (string)$changes['description'] : null);
        $this->applyRules($e,$changes);
        if (array_key_exists('sortOrder',$changes)) $e->setSortOrder((int)$changes['sortOrder']);
        if (array_key_exists('active',$changes)) $e->setActive((bool)$changes['active']);
        $e->setUpdatedAt(time()); return $this->serialize($this->mapper->update($e));
    }

    /** @param array<string,mixed> $r */
    private function applyRules(ExpenseCategory $e,array $r): void {
        $map=['receiptRequired'=>'setReceiptRequired','vehicleRequired'=>'setVehicleRequired','odometerRequired'=>'setOdometerRequired','workerRequired'=>'setWorkerRequired','permitRequired'=>'setPermitRequired','attendanceRequired'=>'setAttendanceRequired'];
        foreach($map as $key=>$setter) if(array_key_exists($key,$r)) $e->$setter((bool)$r[$key]);
        if($e->getOdometerRequired() && !$e->getVehicleRequired()) throw new ValidationException('Odometer-required categories must also require a vehicle.');
        if(($e->getPermitRequired() || $e->getAttendanceRequired()) && !$e->getWorkerRequired()) throw new ValidationException('Permit/attendance requirements require worker information.');
    }

    /** @return array<string,mixed> */
    private function serialize(ExpenseCategory $e): array { return ['id'=>$e->getId(),'code'=>$e->getCode(),'name'=>$e->getName(),'description'=>$e->getDescription(),'receiptRequired'=>$e->getReceiptRequired(),'vehicleRequired'=>$e->getVehicleRequired(),'odometerRequired'=>$e->getOdometerRequired(),'workerRequired'=>$e->getWorkerRequired(),'permitRequired'=>$e->getPermitRequired(),'attendanceRequired'=>$e->getAttendanceRequired(),'active'=>$e->getActive(),'sortOrder'=>$e->getSortOrder()]; }
}
