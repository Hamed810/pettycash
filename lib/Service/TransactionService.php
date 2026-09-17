<?php

declare(strict_types=1);
namespace OCA\PettyCash\Service;

use OCA\PettyCash\Db\CostList;
use OCA\PettyCash\Db\CostListMapper;
use OCA\PettyCash\Db\CurrencyMapper;
use OCA\PettyCash\Db\ExpenseCategoryMapper;
use OCA\PettyCash\Db\ProjectMapper;
use OCA\PettyCash\Db\Transaction;
use OCA\PettyCash\Db\TransactionMapper;
use OCA\PettyCash\Db\TransactionRevision;
use OCA\PettyCash\Db\TransactionRevisionMapper;
use OCA\PettyCash\Db\VehicleMapper;
use OCA\PettyCash\Domain\AttachmentType;
use OCA\PettyCash\Domain\CostListStatus;
use OCA\PettyCash\Domain\DestinationType;
use OCA\PettyCash\Domain\Exception\ConflictException;
use OCA\PettyCash\Domain\Exception\ForbiddenException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCA\PettyCash\Domain\ListType;
use OCA\PettyCash\Domain\ProjectRole;
use OCA\PettyCash\Domain\TransactionStatus;
use OCP\AppFramework\Db\DoesNotExistException;

final class TransactionService {
    public function __construct(
        private TransactionMapper $mapper,
        private TransactionRevisionMapper $revisionMapper,
        private CostListMapper $listMapper,
        private ProjectMapper $projectMapper,
        private ExpenseCategoryMapper $categoryMapper,
        private CurrencyMapper $currencyMapper,
        private VehicleMapper $vehicleMapper,
        private AttachmentService $attachments,
        private AuthorizationService $auth,
        private UuidService $uuid,
        private MoneyService $money,
        private JalaliCalendarService $calendar,
        private AuditService $audit,
        private AssignmentService $assignment,
        private DecisionService $decisions,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(string $listUuid,array $input):array{
        $list=$this->getEditableList($listUuid);$uid=(string)$this->auth->currentUserId();
        $txn=new Transaction();$txn->setUuid($this->uuid->v4());$txn->setListId((int)$list->getId());$txn->setPurchaserId($uid);$txn->setCurrencyId((int)$list->getCurrencyId());$txn->setStatus(TransactionStatus::DRAFT);$txn->setCurrentRevision(1);$txn->setVersion(1);$now=time();$txn->setCreatedAt($now);$txn->setUpdatedAt($now);
        $warnings=$this->applyInput($txn,$list,$input,true);$txn=$this->mapper->insert($txn);$this->snapshot($txn,$uid,'Initial transaction');$this->refreshListTotal($list);$this->audit->record('TRANSACTION',(int)$txn->getId(),'TRANSACTION_CREATED',$uid,['listUuid'=>$listUuid]);
        return $this->serialize($txn,$warnings);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(string $txnUuid,int $version,array $input):array{
        $txn=$this->getTransaction($txnUuid);$list=$this->getListById((int)$txn->getListId());$this->assertPurchaserEditable($txn,$list);
        if($txn->getVersion()!==$version)throw new ConflictException('This transaction changed after you opened it. Refresh and try again.');
        $wasReturned=in_array($txn->getStatus(),[TransactionStatus::RETURNED_M1,TransactionStatus::RETURNED_M2],true);
        $warnings=$this->applyInput($txn,$list,$input,false);
        $txn->setCurrentRevision($txn->getCurrentRevision()+1);$txn->setVersion($txn->getVersion()+1);$txn->setUpdatedAt(time());
        $uid=(string)$this->auth->currentUserId();
        if($wasReturned){
            // Correcting a returned transaction is treated as a fresh
            // submission event for THIS transaction: routing is
            // re-resolved (destination or assignments may have
            // changed), and a new revision means prior decisions no
            // longer count (see DecisionService).
            $this->resolveAndApplyRouting($txn,$list);
        }
        $txn=$this->mapper->update($txn);
        $this->snapshot($txn,$uid,(string)($input['changeReason']??'Purchaser edit'));
        $this->refreshListTotal($list);
        if($wasReturned){
            $this->audit->record('TRANSACTION',(int)$txn->getId(),'TRANSACTION_RESUBMITTED',$uid,['revision'=>$txn->getCurrentRevision()]);
        }
        $this->audit->record('TRANSACTION',(int)$txn->getId(),'TRANSACTION_CHANGED',$uid,['revision'=>$txn->getCurrentRevision()]);
        return $this->serialize($txn,$warnings);
    }

    public function delete(string $txnUuid):void{$txn=$this->getTransaction($txnUuid);$list=$this->getListById((int)$txn->getListId());$this->assertOwner($txn);if($list->getStatus()!==CostListStatus::OPEN||$txn->getStatus()!==TransactionStatus::DRAFT)throw new ValidationException('Only draft expenses in an open Cost List can be deleted.');$uid=(string)$this->auth->currentUserId();$this->attachments->purgeDraftTransaction($txn);$this->revisionMapper->deleteByTransaction((int)$txn->getId());$this->mapper->delete($txn);$this->refreshListTotal($list);$this->audit->record('TRANSACTION',(int)$txn->getId(),'DRAFT_TRANSACTION_DELETED',$uid,['uuid'=>$txnUuid]);}

    /** @return list<array<string,mixed>> */
    public function listForCostList(CostList $list):array{return array_map(fn(Transaction $t)=>$this->serialize($t,$this->odometerWarnings($t)),$this->mapper->findByList((int)$list->getId()));}

    /**
     * Resolves Manager 1 / Manager 2 for a transaction and stores them
     * on it, then sets its status accordingly. Called at Cost List
     * submission time (see CostListService::submit) and when a
     * purchaser corrects a returned transaction. Assumes
     * validateForSubmission has already confirmed both assignments
     * exist where required -- callers must validate first.
     */
    public function resolveAndApplyRouting(Transaction $txn, CostList $list): void {
        $skipManager1 = $list->getListType() === ListType::BUSINESS_TRIP;
        $routing = $this->assignment->resolveRouting($txn->getPurchaserId(), (int)$txn->getDestinationId(), $skipManager1);
        $txn->setManager1Id($routing['manager1Id']);
        $txn->setManager2Id($routing['manager2Id']);
        $txn->setStatus($skipManager1 ? TransactionStatus::PENDING_M2 : TransactionStatus::PENDING_M1);
    }

    /** @return list<string> Blocking errors; empty means ready to submit. */
    public function validateForSubmission(Transaction $txn, CostList $list):array{
        $errors=[];try{$category=$this->categoryMapper->find((int)$txn->getCategoryId());}catch(DoesNotExistException){return ['Expense category no longer exists.'];}
        if(!$category->getActive())$errors[]='Expense category is inactive.';
        if($txn->getAmountMinor()<=0)$errors[]='Amount must be greater than zero.';
        if(trim($txn->getDescription())==='')$errors[]='Description is required.';
        if($txn->getDestinationId()===null){$errors[]='A destination (project/office/marketing/...) is required.';}
        else{
            try{$destination=$this->projectMapper->find((int)$txn->getDestinationId());}catch(DoesNotExistException){$destination=null;$errors[]='Selected destination no longer exists.';}
            if($destination!==null){
                $skipManager1=$list->getListType()===ListType::BUSINESS_TRIP;
                if(!$skipManager1 && $this->assignment->resolveManagerFor($txn->getPurchaserId())===null){
                    $errors[]='No Manager 1 (direct manager) is assigned to you. Contact your administrator.';
                }
                if($this->assignment->resolveOwnerFor((int)$destination->getId())===null){
                    $errors[]="No Manager 2 (destination owner) is assigned for '{$destination->getName()}'. Contact your administrator.";
                }
            }
        }
        if($category->getVehicleRequired() && $txn->getVehicleId()===null)$errors[]='Vehicle is required.';
        if($category->getOdometerRequired() && ($txn->getOdometerKm()===null||$txn->getOdometerKm()<=0))$errors[]='Current vehicle kilometer is required.';
        if($txn->getVehicleId()!==null && $txn->getDestinationId()!==null){try{$v=$this->vehicleMapper->find((int)$txn->getVehicleId());if($v->getProjectId()!==$txn->getDestinationId())$errors[]='Selected vehicle belongs to another destination.';}catch(DoesNotExistException){$errors[]='Selected vehicle no longer exists.';}}
        if($category->getWorkerRequired()){if(trim((string)$txn->getWorkerName())==='')$errors[]='Worker name is required.';if(trim((string)$txn->getWorkDescription())==='')$errors[]='Work description is required.';if(($txn->getWorkDays()??0)<=0&&($txn->getWorkMinutes()??0)<=0)$errors[]='Working days or hours are required.';}
        if($category->getReceiptRequired() && $this->attachments->countType((int)$txn->getId(),AttachmentType::RECEIPT)<1)$errors[]='Receipt is required.';
        if($category->getPermitRequired() && $this->attachments->countType((int)$txn->getId(),AttachmentType::HIRING_PERMIT)<1)$errors[]='Hiring Permit is required.';
        if($category->getAttendanceRequired() && $this->attachments->countType((int)$txn->getId(),AttachmentType::ATTENDANCE_EVIDENCE)<1)$errors[]='Attendance/Fingerprint Evidence is required.';
        return $errors;
    }

    /** @return array<string,mixed> */
    public function serialize(Transaction $txn,array $warnings=[]):array{
        try{$category=$this->categoryMapper->find((int)$txn->getCategoryId());$categoryData=['id'=>$category->getId(),'code'=>$category->getCode(),'name'=>$category->getName()];}catch(DoesNotExistException){$categoryData=null;}
        $destinationData=null;if($txn->getDestinationId()!==null){try{$d=$this->projectMapper->find((int)$txn->getDestinationId());$destinationData=['id'=>$d->getId(),'uuid'=>$d->getUuid(),'code'=>$d->getCode(),'name'=>$d->getName(),'type'=>$d->getType()];}catch(DoesNotExistException){}}
        $vehicleData=null;if($txn->getVehicleId()!==null){try{$v=$this->vehicleMapper->find((int)$txn->getVehicleId());$vehicleData=['id'=>$v->getId(),'uuid'=>$v->getUuid(),'name'=>$v->getName(),'plateNumber'=>$v->getPlateNumber()];}catch(DoesNotExistException){}}
        try{$currency=$this->currencyMapper->find((int)$txn->getCurrencyId());$formatted=$this->money->formatMinor($txn->getAmountMinor(),$currency->getDecimalPlaces());$currencyCode=$currency->getCode();}catch(DoesNotExistException){$formatted=(string)$txn->getAmountMinor();$currencyCode='';}
        return ['id'=>$txn->getId(),'uuid'=>$txn->getUuid(),'listId'=>$txn->getListId(),'category'=>$categoryData,'destination'=>$destinationData,'currency'=>$currencyCode,'amountMinor'=>$txn->getAmountMinor(),'amountFormatted'=>$formatted,'purchaseDate'=>$txn->getPurchaseDate(),'purchaseDateJalali'=>$this->calendar->gregorianToJalali($txn->getPurchaseDate()),'description'=>$txn->getDescription(),'vendor'=>$txn->getVendor(),'vehicle'=>$vehicleData,'odometerKm'=>$txn->getOdometerKm(),'workerName'=>$txn->getWorkerName(),'workerReference'=>$txn->getWorkerReference(),'workDays'=>$txn->getWorkDays(),'workMinutes'=>$txn->getWorkMinutes(),'workDescription'=>$txn->getWorkDescription(),'status'=>$txn->getStatus(),'manager1Id'=>$txn->getManager1Id(),'manager2Id'=>$txn->getManager2Id(),'sameManagerFlag'=>$this->decisions->sameManagerFlag($txn),'currentRevision'=>$txn->getCurrentRevision(),'version'=>$txn->getVersion(),'attachments'=>$this->attachments->listForTransaction((int)$txn->getId()),'decisions'=>$this->decisions->history((int)$txn->getId()),'warnings'=>$warnings];
    }

    private function getTransaction(string $uuid):Transaction{try{return $this->mapper->findByUuid($uuid);}catch(DoesNotExistException){throw new NotFoundException('Transaction not found.');}}
    private function getEditableList(string $uuid):CostList{try{$list=$this->listMapper->findByUuid($uuid);}catch(DoesNotExistException){throw new NotFoundException('Cost List not found.');}return $this->assertEditableList($list);}
    private function getListById(int $id):CostList{try{return $this->listMapper->find($id);}catch(DoesNotExistException){throw new NotFoundException('Cost List not found.');}}
    private function assertEditableList(CostList $list):CostList{$uid=$this->auth->currentUserId();if($uid===null||(!$this->auth->isAdmin($uid)&&$list->getPurchaserId()!==$uid))throw new ForbiddenException('You cannot edit this Cost List.');if($list->getStatus()!==CostListStatus::OPEN)throw new ValidationException('This Cost List is already submitted and locked.');return $list;}
    private function assertOwner(Transaction $txn):void{$uid=$this->auth->currentUserId();if($uid===null||(!$this->auth->isAdmin($uid)&&$txn->getPurchaserId()!==$uid))throw new ForbiddenException('You cannot edit this transaction.');}
    private function assertPurchaserEditable(Transaction $txn,CostList $list):void{$this->assertOwner($txn);if($list->getStatus()===CostListStatus::OPEN&&$txn->getStatus()===TransactionStatus::DRAFT)return;if($list->getStatus()===CostListStatus::SUBMITTED&&in_array($txn->getStatus(),[TransactionStatus::RETURNED_M1,TransactionStatus::RETURNED_M2],true))return;throw new ValidationException('This transaction is not currently open for purchaser correction.');}

    /** @param array<string,mixed> $input @return list<string> */
    private function applyInput(Transaction $txn,CostList $list,array $input,bool $creating):array{
        $categoryId=(int)($input['categoryId']??($creating?0:$txn->getCategoryId()));try{$category=$this->categoryMapper->find($categoryId);}catch(DoesNotExistException){throw new ValidationException('Expense category does not exist.');}if(!$category->getActive())throw new ValidationException('Expense category is inactive.');$txn->setCategoryId($categoryId);

        // v2.0.0: destination now lives on the transaction, chosen
        // alongside category, rather than being inherited from the
        // list's single project.
        if(array_key_exists('destinationId',$input)||$creating){
            $destinationUuid=(string)($input['destinationId']??'');
            if($destinationUuid==='')throw new ValidationException('Destination is required.');
            try{$destination=$this->projectMapper->findByUuid($destinationUuid);}catch(DoesNotExistException){throw new ValidationException('Destination does not exist.');}
            if(!$destination->getActive())throw new ValidationException('Destination is inactive.');
            $uid=(string)$this->auth->currentUserId();
            if($list->getListType()===ListType::BUSINESS_TRIP){
                if($destination->getType()===DestinationType::PROJECT)throw new ValidationException('Business Trip Cost Lists may only reference non-project destinations (office, marketing, etc.).');
                // Any Nextcloud user may post a Business Trip expense --
                // no per-destination purchaser assignment required.
            }else{
                if(!$this->auth->isAdmin($uid) && !$this->auth->hasAnyProjectRole((int)$destination->getId(),[ProjectRole::PURCHASER],$uid)){
                    throw new ValidationException("You are not assigned as a purchaser for '{$destination->getName()}'.");
                }
            }
            $txn->setDestinationId((int)$destination->getId());
        }

        try{$currency=$this->currencyMapper->find((int)$txn->getCurrencyId());}catch(DoesNotExistException){throw new ValidationException('Cost List currency does not exist.');}
        if(array_key_exists('amount',$input)||$creating){try{$amount=$this->money->parseToMinor((string)($input['amount']??''),$currency->getDecimalPlaces());}catch(\Throwable $e){throw new ValidationException($e->getMessage());}if($amount<=0)throw new ValidationException('Amount must be greater than zero.');$txn->setAmountMinor($amount);}
        if(array_key_exists('purchaseDateJalali',$input)||$creating)$txn->setPurchaseDate($this->calendar->jalaliToGregorian((string)($input['purchaseDateJalali']??'')));
        if(array_key_exists('description',$input)||$creating){$description=trim((string)($input['description']??''));if($description==='')throw new ValidationException('Description is required.');$txn->setDescription($description);}
        if(array_key_exists('vendor',$input))$txn->setVendor($input['vendor']!==null&&trim((string)$input['vendor'])!==''?trim((string)$input['vendor']):null);
        $vehicleUuid=$input['vehicleUuid']??null;if($vehicleUuid!==null&&$vehicleUuid!==''){try{$v=$this->vehicleMapper->findByUuid((string)$vehicleUuid);}catch(DoesNotExistException){throw new ValidationException('Vehicle does not exist.');}if($v->getProjectId()!==$txn->getDestinationId()||!$v->getActive())throw new ValidationException('Vehicle is not active for this destination.');$txn->setVehicleId((int)$v->getId());}elseif(array_key_exists('vehicleUuid',$input))$txn->setVehicleId(null);
        if(array_key_exists('odometerKm',$input))$txn->setOdometerKm($input['odometerKm']===null||$input['odometerKm']===''?null:(int)$input['odometerKm']);
        if(array_key_exists('workerName',$input))$txn->setWorkerName($this->nullableText($input['workerName']));if(array_key_exists('workerReference',$input))$txn->setWorkerReference($this->nullableText($input['workerReference']));if(array_key_exists('workDays',$input))$txn->setWorkDays($input['workDays']===null||$input['workDays']===''?null:(int)$input['workDays']);if(array_key_exists('workMinutes',$input))$txn->setWorkMinutes($input['workMinutes']===null||$input['workMinutes']===''?null:(int)$input['workMinutes']);if(array_key_exists('workDescription',$input))$txn->setWorkDescription($this->nullableText($input['workDescription']));
        if($category->getVehicleRequired()&&$txn->getVehicleId()===null)throw new ValidationException('Vehicle is required for this category.');if($category->getOdometerRequired()&&($txn->getOdometerKm()===null||$txn->getOdometerKm()<=0))throw new ValidationException('Current vehicle kilometer is required for this category.');
        if($category->getWorkerRequired()){if(trim((string)$txn->getWorkerName())==='')throw new ValidationException('Worker name is required for this category.');if(trim((string)$txn->getWorkDescription())==='')throw new ValidationException('Work description is required for this category.');if(($txn->getWorkDays()??0)<=0&&($txn->getWorkMinutes()??0)<=0)throw new ValidationException('Working days or hours are required for this category.');}
        return $this->odometerWarnings($txn);
    }

    /** @return list<string> */
    private function odometerWarnings(Transaction $txn):array{if($txn->getVehicleId()===null||$txn->getOdometerKm()===null)return[];$previous=$this->mapper->latestAcceptedOdometer((int)$txn->getVehicleId(),$txn->getId()!==null?(int)$txn->getId():null);if($previous!==null&&$txn->getOdometerKm()<$previous)return["Entered kilometer ({$txn->getOdometerKm()}) is lower than the latest recorded kilometer ({$previous})."];return[];}

    /**
     * Manager (M1/M2) financial edit. Creates a new revision and
     * re-resolves routing (destination may have changed), which
     * resets the review to pending -- consistent with the rule that a
     * financial edit invalidates the prior decision cycle for this
     * revision. Prior decisions remain in history, scoped to their
     * original revision, and are never deleted.
     *
     * @param array<string,mixed> $input @return array<string,mixed>
     */
    public function updateAsApprover(string $txnUuid,int $version,array $input,string $stage,string $actorId):array{
        $txn=$this->getTransaction($txnUuid);$list=$this->getListById((int)$txn->getListId());
        if($txn->getVersion()!==$version)throw new ConflictException('This transaction changed after you opened it. Refresh and review the latest revision.');
        $warnings=$this->applyInput($txn,$list,$input,false);
        $txn->setCurrentRevision($txn->getCurrentRevision()+1);$txn->setVersion($txn->getVersion()+1);$txn->setUpdatedAt(time());
        $this->resolveAndApplyRouting($txn,$list);
        $txn=$this->mapper->update($txn);
        $this->snapshot($txn,$actorId,$stage.' manager edit');
        $this->refreshListTotal($list);
        $this->audit->record('TRANSACTION',(int)$txn->getId(),'MANAGER_TRANSACTION_CHANGED',$actorId,['stage'=>$stage,'revision'=>$txn->getCurrentRevision()]);
        return $this->serialize($txn,$warnings);
    }

    private function nullableText(mixed $value):?string{$v=trim((string)($value??''));return $v===''?null:$v;}
    private function refreshListTotal(CostList $list):void{$list->setSubmittedTotal($this->mapper->sumByList((int)$list->getId()));$list->setVersion($list->getVersion()+1);$this->listMapper->update($list);}
    private function snapshot(
    Transaction $txn,
    string $uid,
    ?string $reason
): void {

    $r = new TransactionRevision();

    $r->setTxnId((int)$txn->getId());

    $r->setRevisionNumber(
        $txn->getCurrentRevision()
    );


    /*
     * Financial snapshot
     */

    $r->setCategoryId(
        $txn->getCategoryId()
    );

    $r->setCurrencyId(
        $txn->getCurrencyId()
    );

    $r->setAmountMinor(
        $txn->getAmountMinor()
    );


    /*
     * v2 routing snapshot
     *
     * Never recalculate these later.
     * They represent responsibility at this revision.
     */

    $r->setDestinationId(
        $txn->getDestinationId()
    );

    $r->setManager1Id(
        $txn->getManager1Id()
    );

    $r->setManager2Id(
        $txn->getManager2Id()
    );


    /*
     * Transaction details
     */

    $r->setPurchaseDate(
        $txn->getPurchaseDate()
    );

    $r->setDescription(
        $txn->getDescription()
    );

    $r->setVendor(
        $txn->getVendor()
    );


    /*
     * Vehicle
     */

    $r->setVehicleId(
        $txn->getVehicleId()
    );

    $r->setOdometerKm(
        $txn->getOdometerKm()
    );


    /*
     * Worker
     */

    $r->setWorkerName(
        $txn->getWorkerName()
    );

    $r->setWorkerReference(
        $txn->getWorkerReference()
    );


    /*
     * Work time
     */

    $r->setWorkDays(
        $txn->getWorkDays()
    );

    $r->setWorkMinutes(
        $txn->getWorkMinutes()
    );

    $r->setWorkDescription(
        $txn->getWorkDescription()
    );


    /*
     * Audit
     */

    $r->setChangedBy(
        $uid
    );

    $r->setChangeReason(
        $reason
    );

    $r->setCreatedAt(
        time()
    );


    $this->revisionMapper->insert($r);
}
    }
