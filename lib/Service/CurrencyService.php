<?php

declare(strict_types=1);
namespace OCA\PettyCash\Service;

use OCA\PettyCash\Db\Currency;
use OCA\PettyCash\Db\CurrencyMapper;
use OCA\PettyCash\Domain\Exception\ConflictException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

final class CurrencyService {
    public function __construct(private CurrencyMapper $mapper) {}

    /** @return list<array<string,mixed>> */
    public function list(bool $includeInactive = false): array {
        return array_map($this->serialize(...), $this->mapper->findAll($includeInactive));
    }

    /** @return array<string,mixed> */
    public function create(string $code, string $name, ?string $symbol, int $decimalPlaces, bool $isDefault, bool $active = true): array {
        $code = strtoupper(trim($code));
        $name = trim($name);
        if (!preg_match('/^[A-Z0-9]{2,8}$/', $code)) throw new ValidationException('Currency code must contain 2-8 letters or digits.');
        if ($name === '') throw new ValidationException('Currency name is required.');
        if ($decimalPlaces < 0 || $decimalPlaces > 6) throw new ValidationException('Decimal places must be between 0 and 6.');
        try { $this->mapper->findByCode($code); throw new ConflictException('Currency code already exists.'); } catch (DoesNotExistException) {}
        if ($isDefault) $this->mapper->clearDefaultExcept();
        $now = time();
        $entity = new Currency();
        $entity->setCode($code); $entity->setName($name); $entity->setSymbol($symbol !== null ? trim($symbol) : null);
        $entity->setDecimalPlaces($decimalPlaces); $entity->setIsDefault($isDefault); $entity->setActive($active);
        $entity->setCreatedAt($now); $entity->setUpdatedAt($now);
        return $this->serialize($this->mapper->insert($entity));
    }

    /** @return array<string,mixed> */
    public function update(int $id, ?string $name, ?string $symbol, ?int $decimalPlaces, ?bool $isDefault, ?bool $active): array {
        try { $entity = $this->mapper->find($id); } catch (DoesNotExistException) { throw new NotFoundException('Currency not found.'); }
        if ($name !== null) { $name = trim($name); if ($name === '') throw new ValidationException('Currency name is required.'); $entity->setName($name); }
        if ($symbol !== null) $entity->setSymbol(trim($symbol));
        if ($decimalPlaces !== null) { if ($decimalPlaces < 0 || $decimalPlaces > 6) throw new ValidationException('Decimal places must be between 0 and 6.'); $entity->setDecimalPlaces($decimalPlaces); }
        if ($isDefault !== null) { if ($isDefault) $this->mapper->clearDefaultExcept($id); $entity->setIsDefault($isDefault); }
        if ($active !== null) $entity->setActive($active);
        if ($entity->getIsDefault() && !$entity->getActive()) throw new ValidationException('The default currency cannot be inactive.');
        $entity->setUpdatedAt(time());
        return $this->serialize($this->mapper->update($entity));
    }

    /** @return array<string,mixed> */
    private function serialize(Currency $c): array {
        return ['id'=>$c->getId(),'code'=>$c->getCode(),'name'=>$c->getName(),'symbol'=>$c->getSymbol(),'decimalPlaces'=>$c->getDecimalPlaces(),'isDefault'=>$c->getIsDefault(),'active'=>$c->getActive()];
    }
}
