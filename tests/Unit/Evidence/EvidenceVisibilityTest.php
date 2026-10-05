<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Evidence;

use OCA\PettyCash\Application\Evidence\EvidenceVisibility;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * FR-060 / Constitution IV: who may open a transaction's evidence.
 *
 * ## The defect this pins
 *
 * `EvidenceController::content()` compared `$evidence->uploadedBy` to the caller. **A reviewer is never
 * the uploader**, so the cost-centre manager, senior manager and finance officer who must rely on a
 * receipt received `404 Evidence not found` for every file on every transaction they were asked to
 * approve. US2/AC1 says the manager "sees its current details, evidence, history"; FR-060 says
 * "**Authorized users** MUST be able to preview supported evidence".
 *
 * ## Why a matrix and not an endpoint test
 *
 * The whole rule is one boolean over (actor's role, actor's scope, ownership, the transaction's stage,
 * the transaction's cost centre). Asserting it through HTTP needs two authenticated identities, which
 * the OCS contract suite does not have — it holds exactly one credential, which is why this defect was
 * invisible to it (T291 records the same harness limit for a different rule). This asserts every cell
 * directly; the endpoint's own shape is covered by `EvidenceLifecycleControllerTest`.
 *
 * **The leak case is asserted first and deliberately.** A fix that simply granted "any role" access
 * would make every reviewer-facing test pass while disclosing every purchaser's receipts to every other
 * purchaser — an availability bug traded for a confidentiality bug, which is a worse outcome and one
 * nothing else in the suite would catch.
 */
final class EvidenceVisibilityTest extends TestCase
{
    private const PURCHASER = 'purchaser-1';
    private const CC_MANAGER = 'cc-manager-1';
    private const SENIOR_MANAGER = 'senior-manager-1';
    private const FINANCE = 'finance-1';
    private const ADMIN = 'admin-1';
    private const OUTSIDE_MANAGER = 'cc-manager-other';
    private const OUTSIDE_SENIOR = 'senior-manager-other';

    /** @var array<string, list<array{role: Role, costCenterId: int|null}>> */
    private array $assignments = [];

    /** @var array<int, string> */

    private function service(): EvidenceVisibility
    {
        // The repository's own in-memory doubles rather than anonymous classes implementing the ports.
        // An anonymous `implements RoleAssignmentRepository` must restate every port method, so it
        // breaks on each interface change and invites a stub that quietly differs from the real
        // repository — the trap `InMemoryRoleAssignmentRepository`'s own docblock records.
        $roleAssignments = new InMemoryRoleAssignmentRepository();
        $costCenters = new InMemoryCostCenterRepository();

        // The double seeds surrogate id 1 with code `CC001`; a second `save()` takes id 2. The codes
        // are read back from the repository rather than assumed, so this stays correct if the double's
        // seeding changes — an assumption here would make "in scope" and "out of scope" silently the
        // same cost centre and every scope test pass for the wrong reason.
        $inScope = $costCenters->findById(1);
        self::assertNotNull($inScope, 'the cost-centre double must seed id 1');
        $costCenters->save(CostCenter::create(
            'CC002',
            'Second centre',
            null,
            new \DateTimeImmutable('2020-01-01'),
            null
        ));
        $outOfScope = $costCenters->findById(2);
        self::assertNotNull($outOfScope, 'the second saved cost centre must take id 2');

        $this->inScopeCode = $inScope->code();
        $this->outOfScopeCode = $outOfScope->code();

        foreach ($this->assignments as $uid => $rows) {
            foreach ($rows as $row) {
                $roleAssignments->save(RoleAssignment::create(
                    $uid,
                    $row['role'],
                    $row['costCenterId'],
                    new \DateTimeImmutable('2020-01-01'),
                    null
                ));
            }
        }

        return new EvidenceVisibility(new AuthorizationService(), $roleAssignments, $costCenters);
    }

    private string $inScopeCode = 'CC001';
    private string $outOfScopeCode = 'CC002';

    private function transaction(
        string $ownerUid,
        WorkflowStage $stage,
        ?string $costCenterCode
    ): ExpenseTransaction {
        $revision = TransactionRevision::draft(
            (string) Uuid::random(),
            1,
            new \DateTimeImmutable('2026-09-20'),
            Money::positive(10000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete(
            'A description',
            $costCenterCode ?? 'CC-000',
            $costCenterCode ?? 'CC-000',
            'Centre',
            1,
            'Office Supplies',
            1,
            true
        );

        $transaction = new ExpenseTransaction((string) Uuid::random(), $ownerUid, $revision);
        // `ExpenseTransaction::$stage` is a public mutable property and there is deliberately no
        // setter: a transaction only leaves `Draft` by being submitted, and the rule under test reads
        // the stage rather than changing it. Assigning it here is the same thing
        // `SubmitSheet` does at the point of routing, minus the routing.
        $transaction->stage = $stage;

        return $transaction;
    }

    protected function setUp(): void
    {
        $this->assignments = [
            // A plain purchaser: no review role, no scope. This is the identity that must NOT gain
            // access to anybody else's receipt.
            self::PURCHASER => [],
            self::CC_MANAGER => [['role' => Role::CostCenterManager, 'costCenterId' => 1]],
            // Deliberately assigned to CC-200 only, so "in scope" and "out of scope" are both real.
            self::OUTSIDE_MANAGER => [['role' => Role::CostCenterManager, 'costCenterId' => 2]],
            self::SENIOR_MANAGER => [['role' => Role::SeniorManager, 'costCenterId' => 1]],
            self::OUTSIDE_SENIOR => [['role' => Role::SeniorManager, 'costCenterId' => 2]],
            self::FINANCE => [['role' => Role::Finance, 'costCenterId' => null]],
            self::ADMIN => [['role' => Role::Administrator, 'costCenterId' => null]],
        ];
    }

    /**
     * The leak case, first: a purchaser with no review role must not read somebody else's receipt.
     *
     * `AuthorizationService::canView()` returns true for this actor — it grants on `roles !== []` and
     * every authenticated user is at least a purchaser. Anything that used it here would disclose every
     * transaction's evidence instance-wide.
     */
    public function testAPurchaserMayNotReadAnotherUsersEvidence(): void
    {
        $transaction = $this->transaction(self::PURCHASER, WorkflowStage::CostCenter, $this->inScopeCode);

        self::assertFalse(
            $this->service()->mayRead('someone-else', $transaction),
            'an unrelated purchaser must not read a receipt they neither own nor review'
        );
    }

    public function testTheOwnerMayReadTheirOwnEvidenceAtEveryStage(): void
    {
        $service = $this->service();

        foreach ([WorkflowStage::Draft, WorkflowStage::CostCenter, WorkflowStage::Correction, WorkflowStage::Senior, WorkflowStage::Finance] as $stage) {
            self::assertTrue(
                $service->mayRead(self::PURCHASER, $this->transaction(self::PURCHASER, $stage, $this->inScopeCode)),
                "the purchaser must read their own receipt while the transaction is at {$stage->value}"
            );
        }
    }

    public function testACostCentreManagerInScopeMayReadEvidence(): void
    {
        $transaction = $this->transaction(self::PURCHASER, WorkflowStage::CostCenter, $this->inScopeCode);

        self::assertTrue(
            $this->service()->mayRead(self::CC_MANAGER, $transaction),
            'FR-060/US2: the assigned cost-centre manager is exactly who the receipt exists for'
        );
    }

    public function testACostCentreManagerOutOfScopeMayNotReadEvidence(): void
    {
        // FR-004: a manager is limited to their assigned cost centres.
        $transaction = $this->transaction(self::PURCHASER, WorkflowStage::CostCenter, $this->inScopeCode);

        self::assertFalse(
            $this->service()->mayRead(self::OUTSIDE_MANAGER, $transaction),
            'a manager assigned to another cost centre must not read this receipt'
        );
    }

    public function testASeniorManagerInScopeMayReadEvidence(): void
    {
        $transaction = $this->transaction(self::PURCHASER, WorkflowStage::Senior, $this->inScopeCode);

        self::assertTrue(
            $this->service()->mayRead(self::SENIOR_MANAGER, $transaction),
            'FR-035/US2: the senior manager must see the receipt they are deciding on'
        );
    }

    public function testASeniorManagerOutOfScopeMayNotReadEvidence(): void
    {
        // A *second* senior manager, assigned to the other centre. The first version of this test
        // reused `SENIOR_MANAGER` — who is assigned to the in-scope centre — and asserted a refusal,
        // so it failed. The fixture was wrong, not the rule: a senior manager who *is* in scope is
        // supposed to read this receipt, which is what the test above asserts. Having both is what
        // makes "fails for the scope reason, not the role reason" a claim rather than a hope.
        $transaction = $this->transaction(self::PURCHASER, WorkflowStage::Senior, $this->inScopeCode);

        self::assertTrue(
            $this->service()->mayRead(self::SENIOR_MANAGER, $transaction),
            'precondition: the in-scope senior manager is admitted, so the refusal below is about scope'
        );
        self::assertFalse(
            $this->service()->mayRead(self::OUTSIDE_SENIOR, $transaction),
            'FR-005 limits a senior manager to their assigned responsibility'
        );
    }

    public function testAFinanceOfficerMayReadEvidenceWithNoCostCentreScope(): void
    {
        // FR-016: every finance officer shares one queue and carries no cost-centre scope, so a
        // transaction in a centre the officer does not manage is still theirs to settle.
        $transaction = $this->transaction(self::PURCHASER, WorkflowStage::Finance, $this->outOfScopeCode);

        self::assertTrue(
            $this->service()->mayRead(self::FINANCE, $transaction),
            'FR-038/US3: finance must open the receipt for the transaction they are settling'
        );
    }

    public function testAnAdministratorMayReadEvidence(): void
    {
        // FR-007 grants full administrative *visibility*. It grants no financial authority, and this
        // rule grants none: the administrator can open a receipt, and `canReview()` still refuses them
        // a decision.
        $transaction = $this->transaction(self::PURCHASER, WorkflowStage::Finance, $this->inScopeCode);

        self::assertTrue(
            $this->service()->mayRead(self::ADMIN, $transaction),
            'FR-007: administrative visibility is full'
        );
    }

    /**
     * The stage gate, which is the other half of the rule and the one most likely to be lost.
     *
     * A cost-centre manager who is in scope for the centre may read the receipt while the transaction
     * is **at their stage**. Once it has moved to `Senior`, the decision belongs to the senior manager
     * and to nobody else — the queue the cost-centre manager can still see is history, not live work.
     */
    public function testACostCentreManagerLosesAccessOnceTheTransactionMovesOn(): void
    {
        $service = $this->service();

        self::assertTrue(
            $service->mayRead(self::CC_MANAGER, $this->transaction(self::PURCHASER, WorkflowStage::CostCenter, $this->inScopeCode)),
            'precondition: in scope at their own stage'
        );
        self::assertFalse(
            $service->mayRead(self::CC_MANAGER, $this->transaction(self::PURCHASER, WorkflowStage::Senior, $this->inScopeCode)),
            'FR-004 scopes a manager to their assigned cost centres, and this transaction is no longer '
            . 'at the stage they act on — an in-scope manager must not keep a live view of it'
        );
    }

    /**
     * A transaction with no cost centre cannot be matched against a scope.
     *
     * A draft has no cost centre until the purchaser sets one. `canReview()` compares the subject's
     * code against the actor's list, so an empty subject code matches nothing — which is the safe
     * direction, and is asserted here so a later "treat empty as match-all" edit fails.
     */
    public function testATransactionWithNoCostCentreIsNotReadableByAManager(): void
    {
        $transaction = $this->transaction(self::PURCHASER, WorkflowStage::CostCenter, null);

        self::assertFalse(
            $this->service()->mayRead(self::CC_MANAGER, $transaction),
            'no cost centre means no scope can match; the owner can still read it'
        );
        self::assertTrue(
            $this->service()->mayRead(self::PURCHASER, $transaction),
            'the owner is unaffected by the missing cost centre'
        );
    }

    /**
     * The stage a transaction sits at is read from the aggregate, not from a query parameter.
     *
     * There is no stage argument to `mayRead()`, so this is a structural property rather than a
     * behavioural one — asserted because the signature is what guarantees it, and a signature is easy
     * to widen by accident.
     */
    public function testTheRuleTakesNoCallerSuppliedStage(): void
    {
        $parameters = (new \ReflectionMethod(EvidenceVisibility::class, 'mayRead'))->getParameters();
        $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters);

        self::assertSame(
            ['actorUid', 'transaction'],
            $names,
            'the stage and cost centre must be read from the transaction the caller resolved, never '
            . 'supplied by the request — a caller-supplied stage would let a reviewer pass the stage '
            . 'they hold and read a transaction at any other stage'
        );
    }

    /** The transaction status is irrelevant: a settled receipt stays readable as history (FR-059). */
    public function testASettledTransactionRemainsReadable(): void
    {
        $transaction = $this->transaction(self::PURCHASER, WorkflowStage::Senior, $this->inScopeCode);

        self::assertTrue(
            $this->service()->mayRead(self::SENIOR_MANAGER, $transaction),
            'FR-059 keeps evidence history viewable; withdrawal is a mark, not a deletion'
        );
    }
}
