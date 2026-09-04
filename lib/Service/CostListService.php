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
use OCA\PettyCash\Domain\ProjectRole;
use OCA\PettyCash\Domain\TransactionStatus;
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
     * @return array<string,mixed>
     */
    public function create(
        string $projectUuid,
        int $jalaliYear,
        int $jalaliMonth,
        ?int $currencyId = null
    ): array {

        try {
            $project = $this->projectMapper->findByUuid($projectUuid);
        } catch (DoesNotExistException) {
            throw new NotFoundException('Project not found.');
        }


        $uid = $this->auth->currentUserId();

        if ($uid === null) {
            throw new ForbiddenException('Login is required.');
        }


        if (
            !$this->auth->hasAnyProjectRole(
                (int)$project->getId(),
                [ProjectRole::PURCHASER],
                $uid
            )
        ) {
            throw new ForbiddenException(
                'You are not assigned as a purchaser for this project.'
            );
        }


        if (!$project->getActive()) {
            throw new ValidationException(
                'Project is inactive.'
            );
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
         * v0.4.1
         *
         * Administrator controls whether users
         * can have multiple open cost lists.
         */
        if (!$this->settings->allowMultipleOpenCostLists()) {

            try {

                $this->mapper->findOpenForPurchaser($uid);

                throw new ConflictException(
                    'You already have an open Cost List. Multiple open Cost Lists are disabled.'
                );

            } catch (DoesNotExistException) {
            }
        }


        $currencyId ??= (int)$project->getDefaultCurrencyId();


        try {
            $currency = $this->currencyMapper->find($currencyId);
        } catch (DoesNotExistException) {
            throw new ValidationException(
                'Currency does not exist.'
            );
        }


        if (!$currency->getActive()) {
            throw new ValidationException(
                'Currency is inactive.'
            );
        }


        $list = new CostList();

        $list->setUuid(
            $this->uuid->v4()
        );

        $list->setReference(null);

        $list->setProjectId(
            (int)$project->getId()
        );

        $list->setPurchaserId($uid);

        $list->setCurrencyId($currencyId);

        $list->setJalaliYear($jalaliYear);

        $list->setJalaliMonth($jalaliMonth);

        $list->setStatus(
            CostListStatus::OPEN
        );

        $list->setSubmittedTotal(0);

        $list->setManager1Total(0);

        $list->setFinalTotal(0);

        $list->setCreatedAt(
            time()
        );

        $list->setDeleted(false);

        $list->setVersion(1);


        $list = $this->mapper->insert($list);


        $this->audit->record(
            'COST_LIST',
            (int)$list->getId(),
            'COST_LIST_CREATED',
            $uid,
            [
                'projectUuid'=>$projectUuid
            ]
        );


        return $this->serialize(
            $list,
            true
        );
    }



    /**
     * @return list<array<string,mixed>>
     */
    public function listForCurrentUser(): array {

        $uid = $this->auth->currentUserId();

        if ($uid === null) {
            return [];
        }


        $lists = $this->mapper->findForPurchaser($uid);


        if ($this->auth->isAdmin($uid)) {

            foreach (
                $this->projectMapper->findAll(true)
                as $project
            ) {

                foreach (
                    $this->mapper->findForProject(
                        (int)$project->getId()
                    )
                    as $list
                ) {

                    $lists[(int)$list->getId()] = $list;
                }
            }


            $lists = array_values($lists);
        }


        return array_map(
            fn(CostList $list)
                => $this->serialize($list,false),
            $lists
        );
    }



    /**
     * @return array<string,mixed>
     */
    public function detail(string $uuid): array {

        $list = $this->getAccessible($uuid);

        return $this->serialize(
            $list,
            true
        );
    }



    /**
     * Soft delete open Cost List
     */
    public function delete(string $uuid): void {

        $list = $this->getAccessible($uuid);


        $uid = $this->auth->currentUserId();


        if ($uid === null) {
            throw new ForbiddenException(
                'Login is required.'
            );
        }


        if (
            !$this->settings->allowUserDeleteOpenCostLists()
        ) {
            throw new ForbiddenException(
                'Deleting Cost Lists is disabled by administrator.'
            );
        }


        if (
            !$this->auth->isAdmin($uid)
            &&
            $list->getPurchaserId() !== $uid
        ) {
            throw new ForbiddenException(
                'You cannot delete this Cost List.'
            );
        }


        if (
            $list->getStatus()
            !== CostListStatus::OPEN
        ) {
            throw new ValidationException(
                'Only OPEN Cost Lists can be deleted.'
            );
        }


        $list->setDeleted(true);

        $list->setDeletedAt(
            time()
        );

        $list->setDeletedBy($uid);

        $list->setVersion(
            $list->getVersion()+1
        );


        $this->mapper->update($list);


        $this->audit->record(
            'COST_LIST',
            (int)$list->getId(),
            'COST_LIST_DELETED',
            $uid
        );
    }



    /**
     * @return array<string,mixed>
     */
    public function submit(
        string $uuid,
        int $version
    ): array {

        $list = $this->getAccessible($uuid);

        $uid = $this->auth->currentUserId();


        if (
            $uid === null
            ||
            (
                !$this->auth->isAdmin($uid)
                &&
                $list->getPurchaserId() !== $uid
            )
        ) {
            throw new ForbiddenException(
                'Only the purchaser who owns this Cost List can submit it.'
            );
        }


        if (
            $list->getStatus()
            !== CostListStatus::OPEN
        ) {
            throw new ValidationException(
                'Cost List has already been submitted.'
            );
        }


        if (
            $list->getVersion()
            !==
            $version
        ) {
            throw new ConflictException(
                'The Cost List changed. Refresh it before submitting.'
            );
        }


        try {

            $project =
                $this->projectMapper->find(
                    (int)$list->getProjectId()
                );

        } catch (DoesNotExistException) {

            throw new NotFoundException(
                'Project not found.'
            );
        }


        $txns =
            $this->txnMapper->findByList(
                (int)$list->getId()
            );


        if ($txns === []) {
            throw new ValidationException(
                'Add at least one expense before submitting.'
            );
        }


        $errors=[];


        foreach ($txns as $txn) {

            $txnErrors =
                $this->transactions->validateForSubmission(
                    $txn,
                    (int)$project->getId()
                );


            foreach ($txnErrors as $error) {

                $errors[] =
                    $txn->getUuid()
                    .': '
                    .$error;
            }
        }


        if ($errors !== []) {

            throw new ValidationException(
                "Cost List cannot be submitted:\n"
                .
                implode("\n",$errors)
            );
        }


        foreach ($txns as $txn) {

            $txn->setStatus(
                TransactionStatus::PENDING_M1
            );

            $txn->setVersion(
                $txn->getVersion()+1
            );

            $txn->setUpdatedAt(
                time()
            );

            $this->txnMapper->update($txn);
        }


        $total =
            $this->txnMapper->sumByList(
                (int)$list->getId()
            );


        $reference =
            sprintf(
                'PC-%s-%04d-%02d-%04d',
                $project->getCode(),
                $list->getJalaliYear(),
                $list->getJalaliMonth(),
                (int)$list->getId()
            );


        $list->setReference($reference);

        $list->setSubmittedTotal($total);

        $list->setManager1Total(0);

        $list->setFinalTotal(0);

        $list->setStatus(
            CostListStatus::M1_REVIEW
        );

        $list->setSubmittedAt(
            time()
        );

        $list->setVersion(
            $list->getVersion()+1
        );


        $this->mapper->update($list);


        $this->audit->record(
            'COST_LIST',
            (int)$list->getId(),
            'LIST_SUBMITTED',
            $uid,
            [
                'reference'=>$reference,
                'total'=>$total
            ]
        );


        return $this->serialize(
            $list,
            true
        );
    }



    private function getAccessible(
        string $uuid
    ): CostList {

        try {

            $list =
                $this->mapper->findByUuid($uuid);

        } catch (DoesNotExistException) {

            throw new NotFoundException(
                'Cost List not found.'
            );
        }


        if (
            !$this->auth->canAccessProject(
                (int)$list->getProjectId()
            )
        ) {

            throw new ForbiddenException(
                'You cannot access this Cost List.'
            );
        }


        return $list;
    }



    /**
     * @return array<string,mixed>
     */
    private function serialize(
        CostList $list,
        bool $withTransactions
    ): array {

        try {

            $p =
                $this->projectMapper->find(
                    (int)$list->getProjectId()
                );


            $project=[
                'id'=>$p->getId(),
                'uuid'=>$p->getUuid(),
                'code'=>$p->getCode(),
                'name'=>$p->getName()
            ];

        } catch (DoesNotExistException) {

            $project=null;
        }



        try {

            $c =
                $this->currencyMapper->find(
                    (int)$list->getCurrencyId()
                );


            $currency=[
                'id'=>$c->getId(),
                'code'=>$c->getCode(),
                'name'=>$c->getName(),
                'symbol'=>$c->getSymbol(),
                'decimalPlaces'=>$c->getDecimalPlaces()
            ];

        } catch (DoesNotExistException) {

            $currency=null;
        }



        $data=[
            'id'=>$list->getId(),
            'uuid'=>$list->getUuid(),
            'reference'=>$list->getReference(),
            'project'=>$project,
            'purchaserId'=>$list->getPurchaserId(),
            'currency'=>$currency,
            'jalaliYear'=>$list->getJalaliYear(),
            'jalaliMonth'=>$list->getJalaliMonth(),
            'status'=>$list->getStatus(),
            'submittedTotal'=>$list->getSubmittedTotal(),
            'manager1Total'=>$list->getManager1Total(),
            'finalTotal'=>$list->getFinalTotal(),
            'createdAt'=>$list->getCreatedAt(),
            'submittedAt'=>$list->getSubmittedAt(),
            'version'=>$list->getVersion(),
            'deleted'=>$list->getDeleted(),
            'deletedAt'=>$list->getDeletedAt(),
        ];


        if ($withTransactions) {

            $data['transactions'] =
                $this->transactions->listForCostList($list);
        }


        return $data;
    }
}