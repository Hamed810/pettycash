<?php

declare(strict_types=1);

namespace OCA\PettyCash\Service;

use OCA\PettyCash\Db\CostList;
use OCA\PettyCash\Db\CostListMapper;
use OCA\PettyCash\Db\CurrencyMapper;
use OCA\PettyCash\Db\ProjectMapper;
use OCA\PettyCash\Db\TransactionMapper;
use OCA\PettyCash\Domain\CostListStatus;
use OCA\PettyCash\Domain\Exception\ConflictException;
use OCA\PettyCash\Domain\Exception\ForbiddenException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCA\PettyCash\Domain\ListType;
use OCP\AppFramework\Db\DoesNotExistException;


final class CostListService {

    public function __construct(
        private CostListMapper $mapper,
        private ProjectMapper $projectMapper,
        private CurrencyMapper $currencyMapper,
        private TransactionMapper $txnMapper,
        private TransactionService $transactions,
        private AuthorizationService $auth,
        private UuidService $uuid,
        private AuditService $audit,
        private SettingsService $settings
    ) {}


    /**
     * v2.0.0: no longer takes a projectUuid -- a Cost List is scoped
     * to the purchaser, not a project. Destination is chosen per
     * transaction (see TransactionService::applyInput). Any Nextcloud
     * user may open either list type; for REGULAR lists, whether they
     * can actually add a transaction against a given destination is
     * checked when the transaction is added, not here.
     *
     * @return array<string,mixed>
     */
    public function create(
        int $jalaliYear,
        int $jalaliMonth,
        string $listType = ListType::REGULAR,
        ?int $currencyId = null
    ): array {

        if (!in_array($listType, ListType::ALL, true)) {
            throw new ValidationException('Unknown Cost List type.');
        }

        $uid = $this->auth->currentUserId();

        if ($uid === null) {
            throw new ForbiddenException('Login is required.');
        }

        if (
            $jalaliYear < 1300 ||
            $jalaliYear > 1700 ||
            $jalaliMonth < 1 ||
            $jalaliMonth > 12
        ) {
            throw new ValidationException(
                'Invalid Jalali accounting period.'
            );
        }

        /*
         * Administrator controls whether users can have multiple open
         * Cost Lists. Business Trip lists always bypass this --
         * purchasers may have several open trip sheets at once
         * regardless of the setting; only REGULAR lists are subject
         * to the single-open-list restriction.
         */
        if ($listType === ListType::REGULAR && !$this->settings->allowMultipleOpenCostLists()) {
            foreach ($this->mapper->findOpenForPurchaser($uid) as $existing) {
                if ($existing->getListType() === ListType::REGULAR) {
                    throw new ConflictException(
                        'You already have an open Cost List. Multiple open Cost Lists are disabled.'
                    );
                }
            }
        }

        if ($currencyId === null) {
            try {
                $currency = $this->currencyMapper->findByCode($this->settings->defaultCurrency());
                $currencyId = (int)$currency->getId();
            } catch (DoesNotExistException) {
                throw new ValidationException('No currency specified and no default currency is configured.');
            }
        }

        try {
            $currency = $this->currencyMapper->find($currencyId);
        } catch (DoesNotExistException) {
            throw new ValidationException('Currency does not exist.');
        }

        if (!$currency->getActive()) {
            throw new ValidationException('Currency is inactive.');
        }

        $list = new CostList();
        $list->setUuid($this->uuid->v4());
        $list->setReference(null);
        $list->setPurchaserId($uid);
        $list->setCurrencyId($currencyId);
        $list->setJalaliYear($jalaliYear);
        $list->setJalaliMonth($jalaliMonth);
        $list->setListType($listType);
        $list->setStatus(CostListStatus::OPEN);
        $list->setSubmittedTotal(0);
        $list->setManager1Total(0);
        $list->setFinalTotal(0);
        $list->setCreatedAt(time());
        $list->setDeleted(false);
        $list->setVersion(1);

        $list = $this->mapper->insert($list);

        $this->audit->record('COST_LIST', (int)$list->getId(), 'COST_LIST_CREATED', $uid, [
            'listType' => $listType,
        ]);

        return $this->serialize($list, true);
    }


    /** @return list<array<string,mixed>> */
    public function listForCurrentUser(): array {
        $uid = $this->auth->currentUserId();
        if ($uid === null) return [];
        $lists = $this->auth->isAdmin($uid) ? $this->mapper->findAll() : $this->mapper->findForPurchaser($uid);
        return array_map(fn(CostList $list) => $this->serialize($list, false), $lists);
    }


    /** @return array<string,mixed> */
    public function detail(string $uuid): array {
        $list = $this->getAccessible($uuid);
        return $this->serialize($list, true);
    }


    /** Soft delete open Cost List -- pre-submission only. */
    public function delete(string $uuid): void {
        $list = $this->getAccessible($uuid);
        $uid = $this->auth->currentUserId();

        if ($uid === null) {
            throw new ForbiddenException('Login is required.');
        }

        if (!$this->settings->allowUserDeleteOpenCostLists()) {
            throw new ForbiddenException('Deleting Cost Lists is disabled by administrator.');
        }

        if (!$this->auth->isAdmin($uid) && $list->getPurchaserId() !== $uid) {
            throw new ForbiddenException('You cannot delete this Cost List.');
        }

        if ($list->getStatus() !== CostListStatus::OPEN) {
            throw new ValidationException('Only OPEN Cost Lists can be deleted -- once submitted, use revise/return instead.');
        }

        $list->setDeleted(true);
        $list->setDeletedAt(time());
        $list->setDeletedBy($uid);
        $list->setVersion($list->getVersion() + 1);
        $this->mapper->update($list);

        $this->audit->record('COST_LIST', (int)$list->getId(), 'COST_LIST_DELETED', $uid);
    }


    /**
     * Close & Submit. Blocks with a named error per transaction if
     * that transaction's purchaser has no Manager 1 assigned (unless
     * its destination is a Business-Trip non-project one, which skips
     * Manager 1) or its destination has no Manager 2 owner. On
     * success, resolves and stores routing on every transaction (see
     * TransactionService::resolveAndApplyRouting) -- this is the
     * submission-time snapshot that later reassignments do not
     * retroactively change.
     *
     * @return array<string,mixed>
     */
    public function submit(string $uuid, int $version): array {
        $list = $this->getAccessible($uuid);
        $uid = $this->auth->currentUserId();

        if ($uid === null || (!$this->auth->isAdmin($uid) && $list->getPurchaserId() !== $uid)) {
            throw new ForbiddenException('Only the purchaser who owns this Cost List can submit it.');
        }

        if ($list->getStatus() !== CostListStatus::OPEN) {
            throw new ValidationException('Cost List has already been submitted.');
        }

        if ($list->getVersion() !== $version) {
            throw new ConflictException('The Cost List changed. Refresh it before submitting.');
        }

        $txns = $this->txnMapper->findByList((int)$list->getId());

        if ($txns === []) {
            throw new ValidationException('Add at least one expense before submitting.');
        }

        $errors = [];
        foreach ($txns as $txn) {
            foreach ($this->transactions->validateForSubmission($txn, $list) as $err) {
                $errors[] = $txn->getUuid() . ': ' . $err;
            }
        }

        if ($errors !== []) {
            throw new ValidationException("Cost List cannot be submitted:\n" . implode("\n", $errors));
        }

        foreach ($txns as $txn) {
            $this->transactions->resolveAndApplyRouting($txn, $list);
            $txn->setVersion($txn->getVersion() + 1);
            $txn->setUpdatedAt(time());
            $this->txnMapper->update($txn);
        }

        $total = $this->txnMapper->sumByList((int)$list->getId());
        $reference = sprintf(
            'PC-%s-%04d-%02d-%04d',
            strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $list->getPurchaserId()), 0, 8)),
            $list->getJalaliYear(),
            $list->getJalaliMonth(),
            (int)$list->getId()
        );

        $list->setReference($reference);
        $list->setSubmittedTotal($total);
        $list->setManager1Total(0);
        $list->setFinalTotal(0);
        $list->setStatus(CostListStatus::SUBMITTED);
        $list->setSubmittedAt(time());
        $list->setVersion($list->getVersion() + 1);
        $this->mapper->update($list);

        $this->audit->record('COST_LIST', (int)$list->getId(), 'LIST_SUBMITTED', $uid, [
            'reference' => $reference,
            'total' => $total,
        ]);

        return $this->serialize($list, true);
    }


    private function getAccessible(string $uuid): CostList {
        try {
            $list = $this->mapper->findByUuid($uuid);
        } catch (DoesNotExistException) {
            throw new NotFoundException('Cost List not found.');
        }

        $uid = $this->auth->currentUserId();
        if ($uid === null || (!$this->auth->isAdmin($uid) && $list->getPurchaserId() !== $uid)) {
            throw new ForbiddenException('You cannot access this Cost List.');
        }

        return $list;
    }


    /** @return array<string,mixed> */
    private function serialize(CostList $list, bool $withTransactions): array {
        try {
            $c = $this->currencyMapper->find((int)$list->getCurrencyId());
            $currency = ['id' => $c->getId(), 'code' => $c->getCode(), 'name' => $c->getName(), 'symbol' => $c->getSymbol(), 'decimalPlaces' => $c->getDecimalPlaces()];
        } catch (DoesNotExistException) {
            $currency = null;
        }

        $data = [
            'id' => $list->getId(),
            'uuid' => $list->getUuid(),
            'reference' => $list->getReference(),
            'purchaserId' => $list->getPurchaserId(),
            'listType' => $list->getListType(),
            'currency' => $currency,
            'jalaliYear' => $list->getJalaliYear(),
            'jalaliMonth' => $list->getJalaliMonth(),
            'status' => $list->getStatus(),
            'submittedTotal' => $list->getSubmittedTotal(),
            'manager1Total' => $list->getManager1Total(),
            'finalTotal' => $list->getFinalTotal(),
            'createdAt' => $list->getCreatedAt(),
            'submittedAt' => $list->getSubmittedAt(),
            'version' => $list->getVersion(),
            'deleted' => $list->getDeleted(),
            'deletedAt' => $list->getDeletedAt(),
        ];

        if ($withTransactions) {
            $data['transactions'] = $this->transactions->listForCostList($list);
        }

        return $data;
    }
}
