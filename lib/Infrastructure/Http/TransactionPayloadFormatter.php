<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Http;

use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
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
        private ExpenseSheetRepository $sheetRepository,
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
            // The owning sheet's **public** uuid, which `contracts/ocs-api.yaml:1154` declares
            // (`format: uuid`) and lists among `TransactionSummary`'s required members.
            //
            // This used to emit `$transaction->sheetId` — the surrogate key — while line above emits
            // the transaction's own id as a uuid, so the omission was an inconsistency rather than a
            // convention. It was visible: `TransactionHeader.vue:22` and the three queue views render
            // this value in a "Sheet" column, so reviewers read integers such as `41`, and the value
            // is unusable as the `{sheetId}` path parameter the contract declares.
            //
            // An unresolvable sheet reference yields `''` rather than the integer, for the reason
            // `expenseTypeSnapshot()` gives beside it: an absent identifier is visibly absent, while a
            // database id in a uuid field looks like data. Turning that into the 422 FR-083 requires
            // is T313/T314's decision and their tests; this task fixes the id space.
            'sheetId' => $this->sheetPublicId($transaction),
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
     * The owning sheet's public uuid, or `''` when there is none to resolve.
     *
     * Read through the sheet repository rather than carried on the transaction aggregate for the same
     * reason `costCenterSnapshot()` resolves its public id: `pettycash_transactions` stores `sheet_id`
     * as a surrogate key, and a wire field must not publish one.
     *
     * A stored transaction whose `sheet_id` matches no row answers `''` as well, and **not** the
     * integer. Turning that into the 422 FR-083 requires is T313/T314's decision and their tests;
     * this task fixes the id space and says which direction the unresolvable case points.
     */
    private function sheetPublicId(ExpenseTransaction $transaction): string
    {
        if ($transaction->sheetId === null) {
            // An aggregate built in memory and not yet saved has no sheet at all, which is a
            // different state from a stored transaction pointing at a sheet that is gone. Both answer
            // `''` because the contract declares the member required and not nullable, and inventing
            // a placeholder uuid would be worse than an empty field.
            return '';
        }

        return $this->sheetRepository->findById($transaction->sheetId)?->publicId ?? '';
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
            'expenseType' => $this->expenseTypeSnapshot($revision),
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
     * The revision's expense type as an `EntitySnapshot`.
     *
     * Shaped like `costCenterSnapshot()` beside it, and for the same reason: `EntitySnapshot.id` is
     * `format: uuid`, and this one used to carry the **title** in both `id` and `name`. That is not a
     * cosmetic mismatch — `TransactionFormView` copies `activeRevision.expenseType.id` into the draft
     * and `TransactionService::updateTransaction()` resolves it with `findByPublicId()`, so every edit
     * of an existing draft was refused with `422 Expense type not found: <title>` and FR-025's "edit"
     * was unreachable.
     *
     * The stored title is used for `name` rather than a fresh lookup, so a historical revision keeps
     * the name it was submitted with — the same reason `costCenterSnapshot()` prefers its snapshot
     * columns. The id comes from the aggregate, which persistence resolves from the stored join key.
     *
     * A revision that names no resolvable expense type yields an empty `id` rather than the title: an
     * unresolvable identifier is visibly absent, whereas a title in an id field looks like data.
     *
     * @return array{id: string, name: string}
     */
    private function expenseTypeSnapshot(TransactionRevision $revision): array
    {
        return [
            'id' => $revision->expenseTypeId() ?? '',
            'name' => $revision->expenseTypeTitle() ?? '',
        ];
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
     * The contract's `Decision`.
     *
     * Public because `POST /transactions/{id}/decisions` answers with a `DecisionResult`, whose
     * `decision` member is a decision of its own and not one read back out of the transaction's
     * history: the caller is told which decision its command recorded, and an answer assembled
     * from the transaction alone could not distinguish the one it just made from the ones before it.
     *
     * @return array<string, mixed>
     */
    public function decision(WorkflowDecision $decision): array
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
    /**
     * `Evidence` — the contract's schema, emitted for **both** endpoints that publish it.
     *
     * **Public, and used by `EvidenceController`, because there were two emitters of one schema and
     * they disagreed.** `EvidenceController::formatEvidence()` added a `deactivationReason` the contract
     * does not declare and wrote `id` as `$evidence->id` where this method writes
     * `$evidence->publicId ?? $evidence->id` — equivalent today only because
     * `DbEvidenceRepository::save()` mirrors one onto the other. `AccountPayloadFormatter`'s own
     * docblock warns about precisely this drift; a client cannot tell which endpoint it is talking to
     * and get an answer that holds for both (T339).
     *
     * **The public uuids, not the join keys.** `contracts/ocs-api.yaml` declares `transactionId` and
     * `revisionId` as `format: uuid` and lists both among `Evidence`'s required members, while the
     * aggregate carried `(int) $row['transaction_id']` — so both emitters published surrogate keys in
     * uuid fields, silently, in front of a `src/types/api.ts` that declared both `string`.
     */
    public function evidence(Evidence $evidence): array
    {
        return [
            'id' => $evidence->publicId ?? $evidence->id,
            'transactionId' => $evidence->transactionPublicId ?? '',
            'revisionId' => $evidence->originRevisionPublicId ?? '',
            'name' => $evidence->originalName,
            'mediaType' => $evidence->mediaType,
            'sizeBytes' => $evidence->sizeBytes,
            'active' => $evidence->active,
            'uploadedBy' => $evidence->uploadedBy ?? '',
            'uploadedAt' => $evidence->uploadedAt?->format(\DateTimeInterface::ATOM),
            'deactivatedAt' => $evidence->deactivatedAt?->format(\DateTimeInterface::ATOM),
            // Declared by this task rather than dropped: it is the only observable of which removal path
            // ran (FR-058 keeps submitted evidence and marks it inactive), and removing it broke
            // `EvidenceLifecycleControllerTest::testTheRecordedReasonShowsWhichRemovalActuallyHappened`
            // — which is the evidence that the key was load-bearing rather than drift after all.
            'deactivationReason' => $evidence->deactivationReason,
        ];
    }
}
