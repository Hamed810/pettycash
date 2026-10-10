<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;

final class WorkflowDecision
{
    public function __construct(
        public string $id,
        public string $transactionId,
        public string $revisionId,
        public WorkflowStage $stage,
        public Decision $decision,
        public ?string $explanation,
        public string $commandId,
        public ?DateTimeImmutable $decidedAt = null,
        public ?string $actorUid = null,
        public ?string $actorName = null,
        public ?string $actorRole = null,
        public ?int $dbId = null,
        public ?string $publicId = null
    ) {}

    public function decision(): Decision
    {
        return $this->decision;
    }

    public function explanation(): ?string
    {
        return $this->explanation;
    }

    public function actor(): \OCA\PettyCash\Domain\Shared\ActorSnapshot
    {
        return new \OCA\PettyCash\Domain\Shared\ActorSnapshot(
            $this->actorUid ?? '',
            $this->actorName ?? '',
            $this->actorRole ?? ''
        );
    }

    public static function create(
        string $id,
        string $transactionId,
        string $revisionId,
        WorkflowStage $stage,
        Decision $decision,
        ?string $explanation,
        string $commandId
    ): self {
        if (in_array($decision, [Decision::Reject, Decision::Return], true) && trim((string) $explanation) === '') {
            throw new InvalidArgumentException('Explanation required');
        }
        return new self($id, $transactionId, $revisionId, $stage, $decision, $explanation, $commandId);
    }
}