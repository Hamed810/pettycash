<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Http;

use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Workflow\FinanceDecisionPolicy;

/**
 * The single source of truth for the transaction OCS payload.
 *
 * `contracts/ocs-api.yaml` defines `TransactionSummary` and `TransactionDetail`, and the
 * frontend is written against those schemas. `TransactionController` and `ReviewController`
 * each carried their own private copy of this formatting, and both had drifted away from the
 * contract in the same way: a flat `revision` object instead of `activeRevision`, scalar
 * `costCenterId`/`expenseTypeTitle` instead of `EntitySnapshot` values, and no
 * `allowedActions`, `decisions`, or `evidence` at all. Because the views read
 * `item.activeRevision.costCenter.name`, every affected template dereferenced `undefined` on a
 * live response.
 *
 * One formatter, used by both controllers, is what keeps that from recurring.
 */
final readonly class TransactionPayloadFormatter
{
    public function __construct(
        private WorkflowDecisionRepository $decisionRepository,
        private EvidenceRepository $evidenceRepository,
        private CostCenterRepository $costCenterRepository,
        private CurrencyRepository $currencyRepository,
        private RoleAssignmentRepository $roleAssignmentRepository,
        private CurrentUser $currentUser
    ) {}

    /**
     * `TransactionSummary` — the queue and list shape.
     *
     * @return array<string, mixed>
     */
    public function summary(ExpenseTransaction $transaction): array
    {
        $decisions = $transaction->dbId === null
            ? []
            : $this->decisionRepository->findByTransactionId($transaction->dbId);
        $evidenceCount = $transaction->dbId === null
            ? 0
            : count($this->evidenceRepository->findByTransactionId($transaction->dbId, true));

        return [
            'id' => $transaction->publicId ?? $transaction->id,
            'sheetId' => $transaction->sheetId,
            'activeRevision' => $this->revisionFrom($transaction),
            'stage' => $transaction->stage->value,
            'status' => $transaction->status->value,
            'finalOutcome' => $transaction->finalOutcome,
            'latestDecision' => $decisions === [] ? null : $this->decision($decisions[count($decisions) - 1]),
            'evidenceCount' => $evidenceCount,
            'version' => $transaction->lockVersion,
        ];
    }

    /**
     * `TransactionDetail` — adds the capability set, the full decision history, and the
     * evidence list, which is what the review views and decision form consume.
     *
     * @return array<string, mixed>
     */
    public function detail(ExpenseTransaction $transaction): array
    {
        $decisions = $transaction->dbId === null
            ? []
            : $this->decisionRepository->findByTransactionId($transaction->dbId);
        $evidence = $transaction->dbId === null
            ? []
            : $this->evidenceRepository->findByTransactionId($transaction->dbId);

        return array_merge($this->summary($transaction), [
            'allowedActions' => $this->allowedActions($transaction),
            'decisions' => array_map([$this, 'decision'], $decisions),
            'evidence' => array_map([$this, 'evidence'], $evidence),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionFrom(ExpenseTransaction $transaction): array
    {
        return $this->revision($transaction->revision());
    }

    /**
     * The contract's `Revision` schema.
     *
     * Public because two endpoints answer with a bare revision rather than a transaction:
     * `POST /transactions/{id}/correction` returns 201 with a Revision, and
     * `GET /transactions/{id}/revisions` returns a list of them.
     *
     * @return array<string, mixed>
     */
    public function revision(TransactionRevision $revision): array
    {
        $amount = $revision->amount();
        $currency = (string) $amount->currency();

        return [
            'id' => $revision->id(),
            'number' => $revision->number(),
            'expenseDate' => $revision->expenseDate()->format('Y-m-d'),
            'amount' => [
                'amountMinor' => $amount->minorUnits(),
                'currency' => $currency,
                'minorUnit' => $this->minorUnit($currency),
            ],
            'description' => $revision->description(),
            'costCenter' => $this->costCenterSnapshot($revision),
            'expenseType' => [
                'id' => $revision->expenseTypeTitle() ?? '',
                'name' => $revision->expenseTypeTitle() ?? '',
            ],
            'createdBy' => $revision->createdBy ?? '',
            'createdAt' => $revision->createdAt?->format(\DateTimeInterface::ATOM),
            'active' => $revision->isActive(),
        ];
    }

    /**
     * The revision's cost center as an `EntitySnapshot`.
     *
     * The stored snapshot columns are preferred so a historical revision keeps the name it was
     * submitted with; the cost center is only looked up when the snapshot is absent, because
     * `EntitySnapshot.name` is required and an empty object would not satisfy the schema.
     *
     * @return array{id: string, code?: string, name: string}
     */
    private function costCenterSnapshot(TransactionRevision $revision): array
    {
        $id = $revision->costCenterId() ?? '';

        if ($revision->costCenterName !== null && $revision->costCenterName !== '') {
            $snapshot = ['id' => $id, 'name' => $revision->costCenterName];
            if ($revision->costCenterCode !== null && $revision->costCenterCode !== '') {
                $snapshot['code'] = $revision->costCenterCode;
            }
            return $snapshot;
        }

        if ($revision->costCenterDbId !== null) {
            $costCenter = $this->costCenterRepository->findById($revision->costCenterDbId);
            if ($costCenter !== null) {
                $snapshot = ['id' => $costCenter->publicId ?? $id, 'name' => $costCenter->name];
                if ($costCenter->code() !== '') {
                    $snapshot['code'] = $costCenter->code();
                }
                return $snapshot;
            }
        }

        return ['id' => $id, 'name' => ''];
    }

    /**
     * Fraction digits for a currency, from the administrator-configured `Currency`.
     *
     * `data-model.md` defines `Currency.minor_unit` as a configured value in 0–4, so it is read
     * from configuration rather than from the hardcoded tables T266 removes. An unknown
     * currency is reported as 0 digits rather than guessed, and T266 remains responsible for
     * turning that into an explicit error for an inactive or missing currency.
     */
    private function minorUnit(string $currencyCode): int
    {
        $currency = $this->currencyRepository->findActiveByCode($currencyCode);
        if ($currency !== null) {
            return $currency->minorUnit;
        }

        $fallback = $this->currencyRepository->findByCode($currencyCode);
        return $fallback?->minorUnit ?? 0;
    }

    /**
     * The capability set for the acting user on this transaction.
     *
     * `CapabilitySet` is a free-form boolean map in the contract, so the keys are the actions
     * the interface offers. Each is derived from the same server-side authorization decision the
     * command path enforces, so a hidden action and a refused action cannot disagree.
     *
     * @return array<string, bool>
     */
    private function allowedActions(ExpenseTransaction $transaction): array
    {
        $actions = [
            'canApprove' => false,
            'canReject' => false,
            'canReturn' => false,
            'canEdit' => false,
            'canDelete' => false,
            'canCorrect' => false,
        ];

        $actorUid = $this->currentUser->uid();
        $isOwner = $transaction->ownerUid === $actorUid;

        if ($isOwner && $transaction->status->value === 'DRAFT') {
            $actions['canEdit'] = true;
            $actions['canDelete'] = true;
        }

        if ($isOwner && $transaction->status->value === 'AWAITING_PURCHASER_CORRECTION') {
            $actions['canCorrect'] = true;
        }

        if ($this->canDecide($transaction, $actorUid)) {
            // Per-decision, from the same policy the command path enforces.
            //
            // These three used to be set together whenever the actor could decide at all, so a
            // senior-rejected transaction offered finance an Approve button that the command path
            // then refused — the exact disagreement this method's own docblock claims cannot
            // happen. FR-039 draws the line between the two senior outcomes, so the capabilities
            // have to be drawn with it rather than as one "can decide" flag.
            $policy = new FinanceDecisionPolicy();
            $status = $transaction->status;
            $actions['canApprove'] = $policy->canFinanceApprove($status);
            $actions['canReject'] = $policy->canFinanceReject($status);
            $actions['canReturn'] = $policy->canFinanceReturn($status);
        }

        return $actions;
    }

    /**
     * Whether the acting user may record a decision on this transaction right now.
     *
     * The decision is delegated to the same `AuthorizationService` the command path uses, so the
     * interface cannot offer an action the server would refuse. Scope comes from the actor's
     * real role assignments, resolved to the codes the comparison is strict about.
     */
    private function canDecide(ExpenseTransaction $transaction, string $actorUid): bool
    {
        $stage = $transaction->stage;

        if ($stage === WorkflowStage::Finance || $stage === WorkflowStage::CostCenter || $stage === WorkflowStage::Senior) {
            $revision = $transaction->revision();
            $code = $revision->costCenterCode;
            if ($code === null || $code === '') {
                if ($revision->costCenterDbId !== null) {
                    $code = $this->costCenterRepository->findById($revision->costCenterDbId)?->code();
                }
            }
            if ($code === null || $code === '') {
                return false;
            }

            $requiredRole = match ($stage) {
                WorkflowStage::CostCenter => Role::CostCenterManager,
                WorkflowStage::Senior => Role::SeniorManager,
                default => Role::Finance,
            };

            $hasRole = false;
            foreach ($this->rolesOf($actorUid) as $role) {
                if ($role === $requiredRole) {
                    $hasRole = true;
                    break;
                }
            }
            if (!$hasRole) {
                return false;
            }

            // The finance stage is a shared queue with no cost-center scoping.
            if ($stage === WorkflowStage::Finance) {
                return true;
            }

            return in_array($code, $this->managedCostCenterCodes($actorUid, $requiredRole), true);
        }

        return false;
    }

    /**
     * @return list<\OCA\PettyCash\Domain\Shared\Enum\Role>
     */
    private function rolesOf(string $actorUid): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $roles = [];
        foreach ($this->roleAssignmentRepository->findActiveByUserUid($actorUid, $now) as $assignment) {
            if ($assignment->active) {
                $roles[] = $assignment->role;
            }
        }
        return $roles;
    }

    /**
     * @return list<string>
     */
    private function managedCostCenterCodes(string $actorUid, Role $role): array
    {
        $codes = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($this->roleAssignmentRepository->findActiveByUserAndRole($actorUid, $role, $now) as $assignment) {
            if ($assignment->costCenterId === null) {
                continue;
            }
            $code = $this->costCenterRepository->findById($assignment->costCenterId)?->code();
            if ($code !== null && $code !== '') {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /**
     * @return array<string, mixed>
     */
    private function decision(WorkflowDecision $decision): array
    {
        return [
            'id' => $decision->publicId ?? $decision->id,
            'stage' => $decision->stage->value,
            'decision' => $decision->decision->value,
            'explanation' => $decision->explanation,
            'actor' => [
                'userId' => $decision->actorUid,
                'displayName' => $decision->actorName,
                'role' => $decision->actorRole,
            ],
            'decidedAt' => $decision->decidedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(Evidence $evidence): array
    {
        return [
            'id' => $evidence->publicId ?? $evidence->id,
            'transactionId' => $evidence->transactionId,
            'revisionId' => $evidence->originRevisionId,
            'name' => $evidence->originalName,
            'mediaType' => $evidence->mediaType,
            'sizeBytes' => $evidence->sizeBytes,
            'active' => $evidence->active,
            'uploadedBy' => $evidence->uploadedBy ?? '',
            'uploadedAt' => $evidence->uploadedAt?->format(\DateTimeInterface::ATOM),
            'deactivatedAt' => $evidence->deactivatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
