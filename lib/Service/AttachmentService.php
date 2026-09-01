<?php

declare(strict_types=1);
namespace OCA\PettyCash\Service;

use OCA\PettyCash\Db\Attachment;
use OCA\PettyCash\Db\AttachmentMapper;
use OCA\PettyCash\Db\CostListMapper;
use OCA\PettyCash\Db\TransactionMapper;
use OCA\PettyCash\Domain\AttachmentType;
use OCA\PettyCash\Domain\CostListStatus;
use OCA\PettyCash\Domain\ProjectRole;
use OCA\PettyCash\Domain\TransactionStatus;
use OCA\PettyCash\Domain\Exception\ForbiddenException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FileNotFoundException;

final class AttachmentService {
    private const MAX_BYTES=15_728_640;
    private const MIME_TO_EXT=['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
    public function __construct(private AttachmentMapper $mapper,private TransactionMapper $txnMapper,private CostListMapper $listMapper,private AuthorizationService $auth,private IAppData $appData,private UuidService $uuid,private AuditService $audit){}

    /** @param array<string,mixed>|null $upload @return array<string,mixed> */
    public function upload(string $txnUuid,string $type,?array $upload):array{
        $type=strtoupper(trim($type));if(!in_array($type,AttachmentType::ALL,true))throw new ValidationException('Unsupported attachment type.');
        if($upload===null||!isset($upload['tmp_name'],$upload['name'],$upload['size']))throw new ValidationException('A file is required.');
        if(isset($upload['error'])&&(int)$upload['error']!==UPLOAD_ERR_OK)throw new ValidationException('The file upload did not complete successfully.');
        try{$txn=$this->txnMapper->findByUuid($txnUuid);}catch(DoesNotExistException){throw new NotFoundException('Transaction not found.');}
        try{$list=$this->listMapper->find((int)$txn->getListId());}catch(DoesNotExistException){throw new NotFoundException('Cost List not found.');}
        $uid=$this->auth->currentUserId();if($uid===null||(!$this->auth->isAdmin($uid)&&$txn->getPurchaserId()!==$uid))throw new ForbiddenException('You cannot modify this transaction.');
        if(!$this->purchaserCanModify($txn,$list))throw new ValidationException('Attachments can only be changed on drafts or returned transactions.');
        $tmp=(string)$upload['tmp_name'];if($tmp===''||!is_file($tmp))throw new ValidationException('Uploaded file is not available.');
        $content=file_get_contents($tmp);if($content===false)throw new ValidationException('Unable to read uploaded file.');
        $size=strlen($content);if($size<=0||$size>self::MAX_BYTES)throw new ValidationException('Attachment must be between 1 byte and 15 MB.');
        $finfo=new \finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->buffer($content);if(!isset(self::MIME_TO_EXT[$mime]))throw new ValidationException('Only JPG, PNG and PDF attachments are supported.');
        $attachmentUuid=$this->uuid->v4();$fileName=$attachmentUuid.'.'.self::MIME_TO_EXT[$mime];
        try{$folder=$this->appData->getFolder($txnUuid);}catch(FileNotFoundException){$folder=$this->appData->newFolder($txnUuid);}
        $folder->newFile($fileName,$content);
        $a=new Attachment();$a->setUuid($attachmentUuid);$a->setTxnId((int)$txn->getId());$a->setRevisionId(null);$a->setType($type);$a->setStorageKey($txnUuid.'/'.$fileName);$a->setOriginalName(basename((string)$upload['name']));$a->setMimeType($mime);$a->setFileSize($size);$a->setSha256(hash('sha256',$content));$a->setUploadedBy($uid);$a->setSensitive($type===AttachmentType::ATTENDANCE_EVIDENCE);$a->setActive(true);$a->setCreatedAt(time());$a=$this->mapper->insert($a);
        $this->audit->record('ATTACHMENT',(int)$a->getId(),'ATTACHMENT_ADDED',$uid,['transactionUuid'=>$txnUuid,'type'=>$type]);return $this->serialize($a);
    }

    /** @return list<array<string,mixed>> */
    public function listForTransaction(int $txnId):array{return array_map($this->serialize(...),$this->mapper->findByTransaction($txnId));}
    public function countType(int $txnId,string $type):int{return $this->mapper->countType($txnId,$type);}

    public function remove(string $uuid):void{
        try{$a=$this->mapper->findByUuid($uuid);$txn=$this->txnMapper->find((int)$a->getTxnId());$list=$this->listMapper->find((int)$txn->getListId());}catch(DoesNotExistException){throw new NotFoundException('Attachment not found.');}
        $uid=$this->auth->currentUserId();if($uid===null||(!$this->auth->isAdmin($uid)&&$txn->getPurchaserId()!==$uid))throw new ForbiddenException('You cannot modify this attachment.');if(!$this->purchaserCanModify($txn,$list))throw new ValidationException('Submitted evidence is locked unless the transaction has been returned for correction.');
        if($list->getStatus()===CostListStatus::OPEN){[$folderName,$fileName]=explode('/',$a->getStorageKey(),2);try{$this->appData->getFolder($folderName)->getFile($fileName)->delete();}catch(FileNotFoundException){}}
        $a->setActive(false);$this->mapper->update($a);$this->audit->record('ATTACHMENT',(int)$a->getId(),'ATTACHMENT_REMOVED',$uid);
    }


    /** @return array{content:string,mimeType:string,originalName:string} */
    public function content(string $uuid):array{try{$a=$this->mapper->findByUuid($uuid);$txn=$this->txnMapper->find((int)$a->getTxnId());$list=$this->listMapper->find((int)$txn->getListId());}catch(DoesNotExistException){throw new NotFoundException('Attachment not found.');}$uid=$this->auth->currentUserId();if($uid===null)throw new ForbiddenException('Login is required.');$allowed=$this->auth->isAdmin($uid)||$txn->getPurchaserId()===$uid||$this->auth->hasAnyProjectRole((int)$list->getProjectId(),[ProjectRole::MANAGER1,ProjectRole::MANAGER2,ProjectRole::ACCOUNTANT],$uid);if(!$allowed)throw new ForbiddenException('You cannot view this financial evidence.');[$folderName,$fileName]=explode('/',$a->getStorageKey(),2);try{$content=$this->appData->getFolder($folderName)->getFile($fileName)->getContent();}catch(FileNotFoundException){throw new NotFoundException('Attachment file is missing.');}return['content'=>$content,'mimeType'=>$a->getMimeType(),'originalName'=>$a->getOriginalName()];}
    private function purchaserCanModify(\OCA\PettyCash\Db\Transaction $txn,\OCA\PettyCash\Db\CostList $list):bool{if($list->getStatus()===CostListStatus::OPEN&&$txn->getStatus()===TransactionStatus::DRAFT)return true;return $list->getStatus()===CostListStatus::M1_REVIEW&&in_array($txn->getStatus(),[TransactionStatus::RETURNED_M1,TransactionStatus::RETURNED_M2],true);}


    public function purgeDraftTransaction(\OCA\PettyCash\Db\Transaction $txn):void{
        foreach($this->mapper->findByTransaction((int)$txn->getId(),false) as $a){$this->mapper->delete($a);}
        try{$this->appData->getFolder($txn->getUuid())->delete();}catch(FileNotFoundException){}
    }

    /** @return array<string,mixed> */
    private function serialize(Attachment $a):array{return ['id'=>$a->getId(),'uuid'=>$a->getUuid(),'type'=>$a->getType(),'originalName'=>$a->getOriginalName(),'mimeType'=>$a->getMimeType(),'fileSize'=>$a->getFileSize(),'sha256'=>$a->getSha256(),'sensitive'=>$a->getSensitive(),'createdAt'=>$a->getCreatedAt()];}
}
