<?php

declare(strict_types=1);
namespace OCA\PettyCash\Service;

use OCA\PettyCash\Db\ApprovalActionEntity;
use OCA\PettyCash\Db\ApprovalActionMapper;
use OCA\PettyCash\Db\CostList;
use OCA\PettyCash\Db\CostListMapper;
use OCA\PettyCash\Db\ProjectMapper;
use OCA\PettyCash\Db\Transaction;
use OCA\PettyCash\Db\TransactionMapper;
use OCA\PettyCash\Db\TransactionRevisionMapper;
use OCA\PettyCash\Domain\ApprovalAction;
use OCA\PettyCash\Domain\ApprovalStage;
use OCA\PettyCash\Domain\CostListStatus;
use OCA\PettyCash\Domain\Exception\ConflictException;
use OCA\PettyCash\Domain\Exception\ForbiddenException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCA\PettyCash\Domain\ProjectRole;
use OCA\PettyCash\Domain\TransactionStatus;
use OCP\AppFramework\Db\DoesNotExistException;

final class ApprovalService {
    public function __construct(
        private CostListMapper $listMapper,
        private TransactionMapper $txnMapper,
        private ProjectMapper $projectMapper,
        private TransactionRevisionMapper $revisionMapper,
        private ApprovalActionMapper $actionMapper,
        private CostListService $costLists,
        private TransactionService $transactions,
        private AuthorizationService $auth,
        private AuditService $audit,
    ) {}

    /** @return list<array<string,mixed>> */
    public function queue(string $stage):array{
        [$role,$status]=$this->stageConfig($stage);$uid=$this->auth->currentUserId();if($uid===null)return[];
        $lists=$this->auth->isAdmin($uid)?$this->listMapper->findByStatus($status):$this->listMapper->findForReviewer($uid,$role,$status);
        return array_map(fn(CostList $l)=>$this->summary($l),$lists);
    }

    /** @return array<string,mixed> */
    public function detail(string $listUuid,string $stage):array{
        $data=$this->costLists->detail($listUuid);$projectId=(int)($data['project']['id']??0);[$role,$status]=$this->stageConfig($stage);$uid=$this->auth->currentUserId();if($uid===null||(!$this->auth->isAdmin($uid)&&!$this->auth->hasAnyProjectRole($projectId,[$role],$uid)))throw new ForbiddenException('You are not assigned to this approval stage.');if($data['status']!==$status)throw new ValidationException('This Cost List is not currently at your approval stage.');return $data;
    }

    /** @return array<string,mixed> */
    public function decide(string $txnUuid,string $stage,string $action,int $version,?string $comment):array{
        $action=strtoupper($action);if(!in_array($action,[ApprovalAction::APPROVE,ApprovalAction::REJECT,ApprovalAction::RETURN],true))throw new ValidationException('Unsupported approval action.');if(in_array($action,[ApprovalAction::REJECT,ApprovalAction::RETURN],true)&&trim((string)$comment)==='')throw new ValidationException('A reason/comment is required for reject or return.');
        [$txn,$list,$uid]=$this->assertTransactionStage($txnUuid,$stage,$version);
        if($stage===ApprovalStage::MANAGER1){$txn->setStatus(match($action){ApprovalAction::APPROVE=>TransactionStatus::APPROVED_M1,ApprovalAction::REJECT=>TransactionStatus::REJECTED_M1,default=>TransactionStatus::RETURNED_M1});}
        else{$txn->setStatus(match($action){ApprovalAction::APPROVE=>TransactionStatus::FINAL_APPROVED,ApprovalAction::REJECT=>TransactionStatus::REJECTED_M2,default=>TransactionStatus::RETURNED_M2});}
        $txn->setVersion($txn->getVersion()+1);$txn->setUpdatedAt(time());$this->txnMapper->update($txn);$this->recordAction($txn,$stage,$action,$uid,$comment);$this->recalculateTotals($list);
        if($stage===ApprovalStage::MANAGER1){if($action!==ApprovalAction::RETURN)$this->advanceManager1IfReady($list);}
        else{if($action===ApprovalAction::RETURN){$list->setStatus(CostListStatus::M1_REVIEW);$list->setManager1CompletedAt(null);$list->setManager2CompletedAt(null);$list->setVersion($list->getVersion()+1);$this->listMapper->update($list);}else{$this->advanceManager2IfReady($list);}}
        $this->audit->record('TRANSACTION',(int)$txn->getId(),$stage.'_'.$action,$uid,['comment'=>$comment,'revision'=>$txn->getCurrentRevision()]);
        return $this->transactions->serialize($txn);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function edit(string $txnUuid,string $stage,int $version,array $data,?string $reason):array{
        if(trim((string)$reason)==='')throw new ValidationException('A reason is required when a manager edits financial data.');[$txn,$list,$uid]=$this->assertTransactionStage($txnUuid,$stage,$version);$data['changeReason']=$reason;$updated=$this->transactions->updateAsApprover($txnUuid,$version,$data,$stage,$uid);$this->audit->record('COST_LIST',(int)$list->getId(),'APPROVAL_RESET_TO_MANAGER1',$uid,['transactionUuid'=>$txnUuid,'stage'=>$stage]);return $updated;
    }

    /** @return array{0:Transaction,1:CostList,2:string} */
    private function assertTransactionStage(string $txnUuid,string $stage,int $version):array{
        try{$txn=$this->txnMapper->findByUuid($txnUuid);$list=$this->listMapper->find((int)$txn->getListId());}catch(DoesNotExistException){throw new NotFoundException('Transaction or Cost List not found.');}
        [$role,$listStatus]=$this->stageConfig($stage);$expected=$stage===ApprovalStage::MANAGER1?TransactionStatus::PENDING_M1:TransactionStatus::PENDING_M2;$uid=$this->auth->currentUserId();if($uid===null)throw new ForbiddenException('Login is required.');if(!$this->auth->isAdmin($uid)&&!$this->auth->hasAnyProjectRole((int)$list->getProjectId(),[$role],$uid))throw new ForbiddenException('You are not assigned to this approval stage.');if($txn->getPurchaserId()===$uid)throw new ForbiddenException('A purchaser cannot approve or edit their own transaction.');if($list->getStatus()!==$listStatus)throw new ValidationException('The Cost List is not currently at this approval stage.');if($txn->getStatus()!==$expected)throw new ValidationException('This transaction is not pending at this approval stage.');if($txn->getVersion()!==$version)throw new ConflictException('This transaction changed after you opened it. Review the latest revision.');return[$txn,$list,$uid];
    }

    /** @return array{0:string,1:string} */
    private function stageConfig(string $stage):array{return match(strtoupper($stage)){ApprovalStage::MANAGER1=>[ProjectRole::MANAGER1,CostListStatus::M1_REVIEW],ApprovalStage::MANAGER2=>[ProjectRole::MANAGER2,CostListStatus::M2_REVIEW],default=>throw new ValidationException('Unknown approval stage.')};}

    private function advanceManager1IfReady(CostList $list):void{
        if($this->txnMapper->countByListStatuses((int)$list->getId(),[TransactionStatus::PENDING_M1,TransactionStatus::RETURNED_M1,TransactionStatus::RETURNED_M2])>0)return;
        $hasM2=false;foreach($this->txnMapper->findByList((int)$list->getId()) as $txn){if($txn->getStatus()===TransactionStatus::APPROVED_M1){$txn->setStatus(TransactionStatus::PENDING_M2);$txn->setVersion($txn->getVersion()+1);$txn->setUpdatedAt(time());$this->txnMapper->update($txn);$hasM2=true;}elseif($txn->getStatus()===TransactionStatus::PENDING_M2){$hasM2=true;}}
        $list->setManager1CompletedAt(time());if($hasM2){$list->setStatus(CostListStatus::M2_REVIEW);}else{$list->setStatus(CostListStatus::ACCOUNTING);$list->setManager2CompletedAt(time());}$list->setVersion($list->getVersion()+1);$this->recalculateTotals($list,false);$this->listMapper->update($list);
    }

    private function advanceManager2IfReady(CostList $list):void{if($this->txnMapper->countByListStatuses((int)$list->getId(),[TransactionStatus::PENDING_M2])>0)return;$list->setStatus(CostListStatus::ACCOUNTING);$list->setManager2CompletedAt(time());$list->setVersion($list->getVersion()+1);$this->recalculateTotals($list,false);$this->listMapper->update($list);}

    private function recalculateTotals(CostList $list,bool $save=true):void{$m1Statuses=[TransactionStatus::APPROVED_M1,TransactionStatus::PENDING_M2,TransactionStatus::FINAL_APPROVED,TransactionStatus::REJECTED_M2,TransactionStatus::RETURNED_M2];$list->setManager1Total($this->txnMapper->sumByListStatuses((int)$list->getId(),$m1Statuses));$list->setFinalTotal($this->txnMapper->sumByListStatuses((int)$list->getId(),[TransactionStatus::FINAL_APPROVED]));if($save){$list->setVersion($list->getVersion()+1);$this->listMapper->update($list);}}

    private function recordAction(Transaction $txn,string $stage,string $action,string $actorId,?string $comment):void{try{$rev=$this->revisionMapper->findRevision((int)$txn->getId(),$txn->getCurrentRevision());$revisionId=(int)$rev->getId();}catch(DoesNotExistException){$revisionId=null;}$a=new ApprovalActionEntity();$a->setTxnId((int)$txn->getId());$a->setRevisionId($revisionId);$a->setStage($stage);$a->setAction($action);$a->setActorId($actorId);$a->setComment($comment);$a->setCreatedAt(time());$this->actionMapper->insert($a);}

    /** @return array<string,mixed> */
    private function summary(CostList $l):array{try{$p=$this->projectMapper->find((int)$l->getProjectId());$project=['uuid'=>$p->getUuid(),'code'=>$p->getCode(),'name'=>$p->getName()];}catch(DoesNotExistException){$project=null;}return['uuid'=>$l->getUuid(),'reference'=>$l->getReference(),'project'=>$project,'purchaserId'=>$l->getPurchaserId(),'jalaliYear'=>$l->getJalaliYear(),'jalaliMonth'=>$l->getJalaliMonth(),'status'=>$l->getStatus(),'submittedTotal'=>$l->getSubmittedTotal(),'manager1Total'=>$l->getManager1Total(),'finalTotal'=>$l->getFinalTotal(),'transactionCount'=>count($this->txnMapper->findByList((int)$l->getId())),'submittedAt'=>$l->getSubmittedAt()];}
}
