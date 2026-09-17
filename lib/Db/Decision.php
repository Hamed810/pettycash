<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\Entity;

/**
 * One decision record per role (M1 or M2) per transaction. A
 * transaction's "effective status" is computed from these rows by
 * DecisionService -- never stored as a single mutable status field.
 * Never updated or deleted; a re-decision inserts a new row.
 */
final class Decision extends Entity {
    protected int $txnId = 0;
    protected ?int $revisionId = null;
    /**
     * DecisionRole:
     * M1
     * M2
     * ACCOUNTANT
     */
    protected string $role = '';    protected string $decision = ''; // ApprovalAction::APPROVE | REJECT | RETURN
    protected ?string $reason = null;
    protected string $actorId = '';
    protected int $createdAt = 0;

    public function __construct() {
        foreach (['txnId', 'revisionId', 'createdAt'] as $f) {
            $this->addType($f, 'integer');
        }
    }
}
