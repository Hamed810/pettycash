<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Application\Workflow\ManagementReviewService;
use OCA\PettyCash\Application\Workflow\SupersededDecisionAudit;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryWorkflowDecisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Feature 007 (T024/T025): the senior-scoped sheet read's service-level acceptance evidence.
 *
 * `getSeniorSheetForManager()` is the Senior twin of `getCostCenterSheetForManager()` (B001–B004):
 * authorized by the caller's active `SENIOR_MANAGER` assignments, containing only current-revision
 * transactions awaiting any senior or decided by this senior, and carrying the two fields only the
 * Senior surface has — the backend-computed sheet-wide cost-center completion gate
 * (`allTransactionsCostCenterDecided`, T025/FR-007-013/001 FR-037) and the presentation
 * `reviewStatus`. These tests pin the authorization boundary (FR-007-056) and the gate semantics
 * against the authoritative decision repository, exactly as the manager twin does for its read.
 */
final class SeniorManagerSheetReviewTest extends TestCase
{
    private $container;

    /** The uuid that matches no stored record, for the same-null-as-out-of-scope assertions. */
    private const UNKNOWN_SHEET = '00000000-0000-4000-8000-000000000000';

    protected function setUp(): void
    {
        $this->container = \TestContainerAccessor::getInstance();

        $this->container->query(ExpenseTransactionRepository::class)->clear();
        $this->container->query(ExpenseSheetRepository::class)->clear();
        $this->container->query(RoleAssignmentRepository::class)->clear();
        $this->container->query(AuditEventRepository::class)->clear();
        $this->container->query(\OCA\PettyCash\Application\Command\CommandResultRepository::class)->clear();

        $decisions = $this->container->query(WorkflowDecisionRepository::class);
        if ($decisions instanceof InMemoryWorkflowDecisionRepository) {
            $decisions->clear();
        }
    }

    protected function tearDown(): void
    {
        $this->container->setInstance(
            CurrentUser::class,
            new InMemoryCurrentUser('test-user')
        );
    }

    public function testSeniorSeesAnAwaitingTransactionAfterCostCenterApprovalWithBackendGates(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $sheet = $this->openSheet('sheet-senior-awaiting', 'purchaser-1', 'Senior Awaiting');
        $transaction = $this->addTransaction($sheet, 'tx-senior-awaiting', 101, 'CC-100', 50000);
        $this->submit($sheet);

        $this->actAs('senior-1');
        $this->decide('senior-1', $transaction->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');

        $review = $this->review()->getSeniorSheetForManager($sheet->publicId);

        self::assertNotNull($review, 'A senior assigned to the cost centre must see the sheet once it awaits them');
        self::assertSame('sheet-senior-awaiting', $review['sheet']->publicId);
        self::assertSame('Senior Awaiting', $review['sheet']->title);
        self::assertSame('purchaser-1', $review['sheet']->ownerUid);
        self::assertSame(50000, $review['sheet']->submittedTotalMinor);
        self::assertNotNull($review['sheet']->firstSubmittedAt);

        self::assertCount(1, $review['transactions']);
        self::assertSame($transaction->id, $review['transactions'][0]['transaction']->id);
        self::assertSame('AWAITING_SENIOR_DECISION', $review['transactions'][0]['state']);
        self::assertNull($review['transactions'][0]['decision']);

        self::assertSame(
            [
                'awaitingSenior' => 1,
                'decidedBySenior' => 0,
                'total' => 1,
            ],
            $review['progress']
        );
        self::assertTrue(
            $review['allTransactionsCostCenterDecided'],
            'The only transaction carries a final Cost Center approval on its current revision, so the sheet-wide gate holds'
        );
        self::assertSame('PENDING_REVIEW', $review['reviewStatus']);
    }

    public function testUnknownAndOutOfScopeSheetsAreIndistinguishable(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->seedCostCenter(102, 'CC-200');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $outOfScope = $this->openSheet('sheet-senior-out', 'purchaser-1', 'Outside Scope');
        $this->addTransaction($outOfScope, 'tx-senior-out', 102, 'CC-200', 75000);
        $this->submit($outOfScope);

        $this->actAs('senior-1');

        self::assertNull(
            $this->review()->getSeniorSheetForManager($outOfScope->publicId),
            'A sheet whose only transaction is outside the senior cost centre must not be returned'
        );
        self::assertNull(
            $this->review()->getSeniorSheetForManager(self::UNKNOWN_SHEET),
            'An unknown sheet must answer the same null as an out-of-scope sheet (FR-007-056)'
        );
    }

    public function testSeniorWithNoSeniorAssignmentSeesNothing(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $sheet = $this->openSheet('sheet-senior-unassigned', 'purchaser-1', 'Not Mine');
        $this->addTransaction($sheet, 'tx-senior-unassigned', 101, 'CC-100', 50000);
        $this->submit($sheet);

        // The actor holds the cost-centre-manager assignment for CC-100 but not the senior one, so
        // the empty senior scope must answer the same null as a missing sheet.
        $this->assignManager('senior-1', 101);

        $this->actAs('senior-1');
        self::assertNull($this->review()->getSeniorSheetForManager($sheet->publicId));
    }

    public function testAnotherSeniorsDecisionIsNotDisclosed(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);
        $this->assignSeniorManager('senior-2', 101);

        $sheet = $this->openSheet('sheet-senior-two', 'purchaser-1', 'Two Seniors');
        $transaction = $this->addTransaction($sheet, 'tx-senior-two', 101, 'CC-100', 50000);
        $this->submit($sheet);

        $this->actAs('senior-1');
        $this->decide('senior-1', $transaction->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
        $this->decide('senior-1', $transaction->id, WorkflowStage::Senior, Decision::Approve, 'Approved at Senior');

        // The deciding senior still reads their own decision back.
        $owner = $this->review()->getSeniorSheetForManager($sheet->publicId);
        self::assertNotNull($owner);
        self::assertSame('DECIDED_BY_SENIOR', $owner['transactions'][0]['state']);
        self::assertSame('senior-1', $owner['transactions'][0]['decision']->actorUid);

        // Another senior must not be able to confirm the sheet exists by reading it: the only
        // decision on it is senior-1's, and a senior-2 view of it is indistinguishable from an
        // absent sheet (FR-007-056).
        $this->actAs('senior-2');
        self::assertNull($this->review()->getSeniorSheetForManager($sheet->publicId));
    }

    public function testSeniorDecisionIsReportedAsDecidedByThisSeniorAndReviewCompletes(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $sheet = $this->openSheet('sheet-senior-decided', 'purchaser-1', 'Decided');
        $transaction = $this->addTransaction($sheet, 'tx-senior-decided', 101, 'CC-100', 60000);
        $this->submit($sheet);

        $this->actAs('senior-1');
        $this->decide('senior-1', $transaction->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
        $this->decide('senior-1', $transaction->id, WorkflowStage::Senior, Decision::Approve, 'Approved at Senior');

        $review = $this->review()->getSeniorSheetForManager($sheet->publicId);

        self::assertNotNull($review);
        self::assertSame('DECIDED_BY_SENIOR', $review['transactions'][0]['state']);
        self::assertNotNull($review['transactions'][0]['decision']);
        self::assertSame(Decision::Approve, $review['transactions'][0]['decision']->decision);
        self::assertSame('senior-1', $review['transactions'][0]['decision']->actorUid);
        self::assertSame(1, $review['progress']['decidedBySenior']);
        self::assertSame(0, $review['progress']['awaitingSenior']);
        self::assertSame('REVIEW_COMPLETED', $review['reviewStatus']);
        self::assertTrue(
            $review['allTransactionsCostCenterDecided'],
            'The current-revision Cost Center approval is final, so the gate holds after the senior decides'
        );
    }

    public function testTheGateIsSheetWideAndFailsWhileAnyTransactionStillAwaitsCostCenter(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->seedCostCenter(102, 'CC-200');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $sheet = $this->openSheet('sheet-senior-partial', 'purchaser-1', 'Partial Scope');
        $managed = $this->addTransaction($sheet, 'tx-senior-managed', 101, 'CC-100', 50000);
        $outside = $this->addTransaction($sheet, 'tx-senior-outside', 102, 'CC-200', 70000);
        $this->submit($sheet);

        $this->actAs('senior-1');
        $this->decide('senior-1', $managed->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved');

        $review = $this->review()->getSeniorSheetForManager($sheet->publicId);

        self::assertNotNull($review);
        self::assertCount(1, $review['transactions'], 'Only the managed-cost-centre transaction is in this senior\'s scope');
        self::assertSame($managed->id, $review['transactions'][0]['transaction']->id);
        self::assertNull(
            $this->findTransaction($review['transactions'], $outside->id),
            'A transaction in a cost centre this senior does not manage must not be exposed'
        );
        self::assertFalse(
            $review['allTransactionsCostCenterDecided'],
            'The gate is computed over the whole sheet, so a sibling still awaiting Cost Center fails it even '
            . 'though it is outside this senior\'s scope (T025, 001 FR-037)'
        );
        self::assertSame('PENDING_REVIEW', $review['reviewStatus']);
    }

    public function testACostCenterRejectionSatisfiesTheGateWithoutEstablishingSeniorEligibility(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $sheet = $this->openSheet('sheet-senior-rejected-cc', 'purchaser-1', 'Rejected at CC');
        $transaction = $this->addTransaction($sheet, 'tx-senior-rejected-cc', 101, 'CC-100', 50000);
        $this->submit($sheet);

        $this->actAs('senior-1');
        $this->decide('senior-1', $transaction->id, WorkflowStage::CostCenter, Decision::Reject, 'Out of policy');

        $review = $this->review()->getSeniorSheetForManager($sheet->publicId);

        self::assertNotNull($review);
        self::assertSame('AWAITING_SENIOR_DECISION', $review['transactions'][0]['state']);
        self::assertTrue(
            $review['allTransactionsCostCenterDecided'],
            'A Cost Center rejection on the current revision is final for the sheet-wide gate (001 FR-037 '
            . 'amendment) — it does not independently decide the transaction, which still awaits the senior'
        );
    }

    public function testAPendingSeniorReturnFailsTheGateUntilTheCorrectionIsRedecided(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $sheet = $this->openSheet('sheet-senior-return', 'purchaser-1', 'Pending Return');
        $transaction = $this->addTransaction($sheet, 'tx-senior-return', 101, 'CC-100', 50000);
        $this->submit($sheet);

        $this->actAs('senior-1');
        $this->decide('senior-1', $transaction->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
        $this->decide('senior-1', $transaction->id, WorkflowStage::Senior, Decision::Return, 'Missing receipt');

        // While the purchaser is correcting, the transaction sits in correction and its current
        // revision still carries the older CC approval — but the return means the sheet is not
        // all-CC-decided (T025 / 001 FR-037: a pending Return does not satisfy the gate).
        $pending = $this->review()->getSeniorSheetForManager($sheet->publicId);
        self::assertNotNull($pending, 'The senior who returned it still reads their own decision');
        self::assertSame('DECIDED_BY_SENIOR', $pending['transactions'][0]['state']);
        self::assertSame(Decision::Return, $pending['transactions'][0]['decision']->decision);
        self::assertSame(0, $pending['progress']['awaitingSenior']);
        self::assertSame(1, $pending['progress']['decidedBySenior']);
        self::assertFalse(
            $pending['allTransactionsCostCenterDecided'],
            'A pending Return fails the gate even when the current revision carries an older CC approval'
        );

        // The purchaser completes the correction and resubmits, which routes the transaction back to
        // Cost Center on a new revision; the manager re-decides it. The new revision holds a fresh,
        // final CC approval and the gate is satisfied again.
        $correctionRevision = $transaction->revision()->correction('tx-senior-return-rev-2');
        $correctionRevision->complete('Corrected description', 'CC-100', 'CC-100', 'CC-100', 101, 'Office Supplies', 1, true);
        $correctionRevision->activate();
        $transaction->activate($correctionRevision);
        $this->container->query(ExpenseTransactionRepository::class)->save($transaction);

        $this->decide('senior-1', $transaction->id, WorkflowStage::CostCenter, Decision::Approve, 'Re-approved after correction');

        $redecided = $this->review()->getSeniorSheetForManager($sheet->publicId);
        self::assertNotNull($redecided);
        self::assertTrue(
            $redecided['allTransactionsCostCenterDecided'],
            'A corrected and re-decided transaction satisfies the gate'
        );
        self::assertSame(
            'AWAITING_SENIOR_DECISION',
            $redecided['transactions'][0]['state'],
            'The old senior Return sits on the superseded revision, so the new revision awaits the senior again'
        );
        self::assertSame(1, $redecided['progress']['awaitingSenior']);
        self::assertSame(0, $redecided['progress']['decidedBySenior']);
        self::assertSame('PENDING_REVIEW', $redecided['reviewStatus']);
    }

    // ===== Feature 007 (UX-007-07): the senior sheet list keeps completed sheets discoverable =====

    public function testSeniorSheetsListKeepsPendingAndCompletedSheetsDiscoverable(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $pending = $this->openSheet('sheet-list-pending', 'purchaser-1', 'Pending Sheet');
        $pendingTx = $this->addTransaction($pending, 'tx-list-pending', 101, 'CC-100', 50000);
        $this->submit($pending);

        $completed = $this->openSheet('sheet-list-completed', 'purchaser-1', 'Completed Sheet');
        $completedTx = $this->addTransaction($completed, 'tx-list-completed', 101, 'CC-100', 60000);
        $this->submit($completed);

        $this->actAs('senior-1');
        $this->decide('senior-1', $pendingTx->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
        $this->decide('senior-1', $completedTx->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
        $this->decide('senior-1', $completedTx->id, WorkflowStage::Senior, Decision::Approve, 'Approved at Senior');

        $page = $this->review()->getSeniorSheetsForManager(1, 25);

        self::assertSame(2, $page['total'], 'both the pending and the completed sheet are in the scope');

        $pendingItem = $this->findListItem($page['items'], $pending->publicId);
        $completedItem = $this->findListItem($page['items'], $completed->publicId);

        self::assertNotNull($pendingItem, 'a sheet awaiting the senior must be listed');
        self::assertSame('Pending Sheet', $pendingItem['sheet']->title);
        self::assertSame('purchaser-1', $pendingItem['sheet']->ownerUid);
        self::assertSame(['awaitingSenior' => 1, 'decidedBySenior' => 0, 'total' => 1], $pendingItem['progress']);
        self::assertSame('PENDING_REVIEW', $pendingItem['reviewStatus']);
        self::assertTrue($pendingItem['allTransactionsCostCenterDecided']);

        self::assertNotNull($completedItem, 'a sheet whose review is complete must stay listed');
        self::assertSame('Completed Sheet', $completedItem['sheet']->title);
        self::assertSame(['awaitingSenior' => 0, 'decidedBySenior' => 1, 'total' => 1], $completedItem['progress']);
        self::assertSame('REVIEW_COMPLETED', $completedItem['reviewStatus']);
        self::assertTrue($completedItem['allTransactionsCostCenterDecided']);
        self::assertNotNull($completedItem['latestActivityAt'], 'a completed sheet reports its latest authoritative activity');
    }

    public function testACompletedSheetStaysDiscoverableAfterItsDecisionAdvancedToFinance(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $sheet = $this->openSheet('sheet-list-advanced', 'purchaser-1', 'Advanced to Finance');
        $transaction = $this->addTransaction($sheet, 'tx-list-advanced', 101, 'CC-100', 50000);
        $this->submit($sheet);

        $this->actAs('senior-1');
        $this->decide('senior-1', $transaction->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
        $this->decide('senior-1', $transaction->id, WorkflowStage::Senior, Decision::Approve, 'Approved at Senior');

        // The workflow progression is unchanged: the senior approval advances the transaction to
        // Finance (FR-037) — the list read does not and must not block it.
        $stored = $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($transaction->id);
        self::assertNotNull($stored);
        self::assertSame(WorkflowStage::Finance, $stored->stage);

        $page = $this->review()->getSeniorSheetsForManager(1, 25);
        $item = $this->findListItem($page['items'], $sheet->publicId);

        self::assertNotNull($item, 'the sheet must remain discoverable after its transaction left the Senior stage');
        self::assertSame('REVIEW_COMPLETED', $item['reviewStatus']);
        self::assertSame(['awaitingSenior' => 0, 'decidedBySenior' => 1, 'total' => 1], $item['progress']);
    }

    public function testASeniorReturnDoesNotMateTheSheetCompleted(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $sheet = $this->openSheet('sheet-list-returned', 'purchaser-1', 'Returned to Correction');
        $transaction = $this->addTransaction($sheet, 'tx-list-returned', 101, 'CC-100', 50000);
        $this->submit($sheet);

        $this->actAs('senior-1');
        $this->decide('senior-1', $transaction->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
        $this->decide('senior-1', $transaction->id, WorkflowStage::Senior, Decision::Return, 'Missing receipt');
        self::assertSame(
            WorkflowStage::Correction,
            $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($transaction->id)->stage
        );

        $item = $this->findListItem($this->review()->getSeniorSheetsForManager(1, 25)['items'], $sheet->publicId);

        self::assertNotNull($item, 'the returned sheet stays listed');
        self::assertSame(['awaitingSenior' => 0, 'decidedBySenior' => 1, 'total' => 1], $item['progress']);
        self::assertSame(
            'PENDING_REVIEW',
            $item['reviewStatus'],
            'a Return sends the sheet to correction instead of completing the review, so it must not wear REVIEW_COMPLETED'
        );
    }

    public function testAnotherSeniorsDecisionNeverPopulatesAnotherSeniorsList(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);
        $this->assignSeniorManager('senior-2', 101);

        $sheet = $this->openSheet('sheet-list-two-seniors', 'purchaser-1', 'Two Seniors');
        $transaction = $this->addTransaction($sheet, 'tx-list-two-seniors', 101, 'CC-100', 50000);
        $this->submit($sheet);

        $this->actAs('senior-1');
        $this->decide('senior-1', $transaction->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
        $this->decide('senior-1', $transaction->id, WorkflowStage::Senior, Decision::Approve, 'Approved at Senior');

        $ownerList = $this->review()->getSeniorSheetsForManager(1, 25);
        self::assertSame(1, $ownerList['total'], 'the deciding senior sees the sheet');

        // senior-2 shares the cost centre but recorded no decision; the only scoped decision is
        // senior-1's, which is never disclosed, so the sheet must not populate senior-2's list.
        $this->actAs('senior-2');
        $foreignList = $this->review()->getSeniorSheetsForManager(1, 25);
        self::assertSame(0, $foreignList['total'], 'another senior\'s historical decision must not leak (FR-007-056)');
    }

    public function testSeniorWithNoAssignmentOrOutOfScopeSheetGetsAnEmptyList(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->seedCostCenter(102, 'CC-200');

        $inScope = $this->openSheet('sheet-list-in-scope', 'purchaser-1', 'In Scope');
        $inScopeTx = $this->addTransaction($inScope, 'tx-list-in-scope', 101, 'CC-100', 50000);
        $this->submit($inScope);

        $outOfScope = $this->openSheet('sheet-list-out', 'purchaser-1', 'Outside Scope');
        $this->addTransaction($outOfScope, 'tx-list-out', 102, 'CC-200', 75000);
        $this->submit($outOfScope);

        // Cost-centre manager only. Advance the in-scope transaction to the Senior stage, then
        // assert that the missing senior assignment still answers an empty list: the point is the
        // scope, so the sheet must be senior-relevant *and* still absent.
        $this->assignManager('senior-1', 101);
        $this->actAs('senior-1');
        $this->decide('senior-1', $inScopeTx->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
        self::assertSame(
            0,
            $this->review()->getSeniorSheetsForManager(1, 25)['total'],
            'without an active SENIOR_MANAGER assignment the senior scope is empty, whatever the sheets hold'
        );

        // Now grant the senior assignment: the in-scope sheet is listed (awaiting Senior), while
        // the CC-200 sheet — outside the senior's cost-centre scope and still awaiting its own Cost
        // Center — is not.
        $this->assignSeniorManager('senior-1', 101);
        $scoped = $this->review()->getSeniorSheetsForManager(1, 25);
        self::assertSame(1, $scoped['total']);
        self::assertSame($inScope->publicId, $scoped['items'][0]['sheet']->publicId);
        self::assertNull(
            $this->findListItem($scoped['items'], $outOfScope->publicId),
            'a sheet outside the manager\'s cost-centre scope must not be listed'
        );
    }

    public function testListPaginationReportsTheTrueTotalAndNeverOmitsCompletedSheets(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $this->actAs('senior-1');

        $sheetIds = [];
        foreach (['list-page-a', 'list-page-b', 'list-page-c'] as $i => $publicId) {
            $sheet = $this->openSheet($publicId, 'purchaser-1', 'Sheet ' . $i);
            $transaction = $this->addTransaction($sheet, 'tx-' . $publicId, 101, 'CC-100', 50000 + $i);
            $this->submit($sheet);

            $this->decide('senior-1', $transaction->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
            $this->decide('senior-1', $transaction->id, WorkflowStage::Senior, Decision::Approve, 'Approved at Senior');

            $sheetIds[] = $publicId;
        }

        $pageOne = $this->review()->getSeniorSheetsForManager(1, 2);
        $pageTwo = $this->review()->getSeniorSheetsForManager(2, 2);

        self::assertSame(3, $pageOne['total'], '`total` counts the sheets in scope, not the page');
        self::assertCount(2, $pageOne['items']);
        self::assertCount(1, $pageTwo['items']);

        $seen = array_merge($pageOne['items'], $pageTwo['items']);
        $seenIds = array_map(static fn (array $item): string => $item['sheet']->publicId, $seen);

        foreach ($sheetIds as $publicId) {
            self::assertContains($publicId, $seenIds, 'a completed sheet must never be silently truncated by pagination');
        }
        foreach ($seen as $item) {
            self::assertSame('REVIEW_COMPLETED', $item['reviewStatus']);
        }
    }

    public function testListItemCarriesTheSheetWideCostCenterGate(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->seedCostCenter(102, 'CC-200');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $sheet = $this->openSheet('sheet-list-gate', 'purchaser-1', 'Gate Sheet');
        $managed = $this->addTransaction($sheet, 'tx-list-gate-managed', 101, 'CC-100', 50000);
        $this->addTransaction($sheet, 'tx-list-gate-outside', 102, 'CC-200', 70000);
        $this->submit($sheet);

        $this->actAs('senior-1');
        $this->decide('senior-1', $managed->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved');

        $item = $this->findListItem($this->review()->getSeniorSheetsForManager(1, 25)['items'], $sheet->publicId);

        self::assertNotNull($item);
        self::assertSame(['awaitingSenior' => 1, 'decidedBySenior' => 0, 'total' => 1], $item['progress']);
        self::assertFalse(
            $item['allTransactionsCostCenterDecided'],
            'the gate is sheet-wide, so a sibling outside this senior\'s scope still awaiting Cost Center fails the sheet'
        );
    }

    public function testAMixedSheetWithAnIncompleteCostCenterDecisionIsNeverReportedCompleted(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $sheet = $this->openSheet('sheet-list-mixed-cc', 'purchaser-1', 'Mixed CC Sheet');
        $decided = $this->addTransaction($sheet, 'tx-list-mixed-cc-a', 101, 'CC-100', 50000);
        $awaitingCc = $this->addTransaction($sheet, 'tx-list-mixed-cc-b', 101, 'CC-100', 70000);
        $this->submit($sheet);

        $this->actAs('senior-1');
        // The first transaction completes the whole senior review and advances to Finance, while the
        // second still awaits Cost Center (FR-007-013 gate unresolved). Regression: the sheet must
        // never wear REVIEW_COMPLETED while any transaction lacks a final Cost Center decision.
        $this->decide('senior-1', $decided->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
        $this->decide('senior-1', $decided->id, WorkflowStage::Senior, Decision::Approve, 'Approved at Senior');
        self::assertSame(
            WorkflowStage::Finance,
            $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($decided->id)->stage
        );
        self::assertSame(
            WorkflowStage::CostCenter,
            $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($awaitingCc->id)->stage,
            'the sibling is still awaiting its Cost Center decision'
        );

        $item = $this->findListItem($this->review()->getSeniorSheetsForManager(1, 25)['items'], $sheet->publicId);
        self::assertNotNull($item, 'the sheet stays listed for this senior');
        self::assertSame(['awaitingSenior' => 0, 'decidedBySenior' => 1, 'total' => 1], $item['progress']);
        self::assertFalse(
            $item['allTransactionsCostCenterDecided'],
            'one transaction still awaiting Cost Center fails the whole-sheet gate'
        );
        self::assertSame(
            'PENDING_REVIEW',
            $item['reviewStatus'],
            'an incomplete Cost Center decision must not produce an incorrectly completed Senior review (FR-007-013/017)'
        );

        // The per-sheet read agrees with the list: it is still shown, but never completed.
        $review = $this->review()->getSeniorSheetForManager($sheet->publicId);
        self::assertNotNull($review);
        self::assertFalse($review['allTransactionsCostCenterDecided']);
        self::assertSame('PENDING_REVIEW', $review['reviewStatus']);

        // Once the sibling receives its final Cost Center decision and the senior completes it, the
        // same sheet becomes a valid completed sheet and remains visible.
        $this->decide('senior-1', $awaitingCc->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
        $this->decide('senior-1', $awaitingCc->id, WorkflowStage::Senior, Decision::Approve, 'Approved at Senior');

        $completedItem = $this->findListItem($this->review()->getSeniorSheetsForManager(1, 25)['items'], $sheet->publicId);
        self::assertNotNull($completedItem, 'the completed sheet stays listed');
        self::assertSame(['awaitingSenior' => 0, 'decidedBySenior' => 2, 'total' => 2], $completedItem['progress']);
        self::assertTrue($completedItem['allTransactionsCostCenterDecided']);
        self::assertSame('REVIEW_COMPLETED', $completedItem['reviewStatus']);
    }

    // ===== Feature 007 scalability: the list is a bounded projection, never an aggregate hydrate =====

    public function testSeniorSheetListIsBoundedAgainstHistoricalTransactionVolumeAndN1(): void
    {
        $this->seedCostCenter(101, 'CC-100');
        $this->seedCostCenter(102, 'CC-200');
        $this->assignManager('senior-1', 101);
        $this->assignSeniorManager('senior-1', 101);

        $this->actAs('senior-1');

        // A volume the pre-fix path could not carry cheaply: three scoped sheets with several
        // transactions each plus an out-of-scope sheet that must never be enumerated. The list read
        // must answer this with one bounded projection and page-bound sheet hydration, and must not
        // touch the aggregate/bulk/N+1 entry points at all.
        foreach (['list-vol-a', 'list-vol-b', 'list-vol-c'] as $publicId) {
            $sheet = $this->openSheet($publicId, 'purchaser-1', 'Volume ' . $publicId);
            $transactionsOfSheet = [];
            for ($i = 1; $i <= 4; $i++) {
                $transactionsOfSheet[] = $this->addTransaction($sheet, 'tx-' . $publicId . '-' . $i, 101, 'CC-100', 10000 * $i);
            }
            $this->submit($sheet);

            // Cost Center decisions advance each transaction to the Senior stage, so every scoped
            // sheet counts as awaiting the senior — the candidate set the projection folds.
            foreach ($transactionsOfSheet as $transaction) {
                $this->decide('senior-1', $transaction->id, WorkflowStage::CostCenter, Decision::Approve, 'Approved at CC');
            }
        }

        $outOfScope = $this->openSheet('list-vol-out', 'purchaser-1', 'Outside');
        $this->addTransaction($outOfScope, 'tx-vol-out', 102, 'CC-200', 99000);
        $this->submit($outOfScope);

        $transactions = $this->container->query(ExpenseTransactionRepository::class);
        $sheets = $this->container->query(ExpenseSheetRepository::class);

        self::assertInstanceOf(InMemoryExpenseTransactionRepository::class, $transactions);
        self::assertInstanceOf(InMemoryExpenseSheetRepository::class, $sheets);

        $transactions->resetReadProbes();
        $sheets->resetReadProbes();

        $page = $this->review()->getSeniorSheetsForManager(1, 2);

        self::assertSame(3, $page['total'], 'the three scoped sheets are enumerated; the out-of-scope sheet never is');
        self::assertCount(2, $page['items']);

        self::assertSame(
            1,
            $transactions->readProbes['seniorSheetListRows'],
            'the list reads the bounded per-sheet projection exactly once'
        );
        self::assertSame(
            0,
            $transactions->readProbes['findByCostCenterIds'],
            'the historical bulk load no longer runs: no full aggregate is hydrated before pagination'
        );
        self::assertSame(
            0,
            $transactions->readProbes['findBySheetId'],
            'no per-sheet read follows the projection (the N+1 the projection replaced)'
        );
        self::assertSame(
            0,
            $transactions->readProbes['findById'],
            'no per-transaction aggregate is hydrated by the list read'
        );
        self::assertSame(
            2,
            $sheets->readProbes['findById'],
            'full sheet objects are hydrated only for the requested page (2 of 3), never all candidates'
        );
    }

    // ===== helpers =====

    private function openSheet(string $publicId, string $ownerUid, string $title): ExpenseSheet
    {
        $sheet = ExpenseSheet::open(
            $publicId,
            $ownerUid,
            'account-1',
            CurrencyCode::fromString('IRR'),
            $title
        );
        $sheet->publicId = $publicId;
        $this->container->query(ExpenseSheetRepository::class)->save($sheet);

        return $sheet;
    }

    private function addTransaction(
        ExpenseSheet $sheet,
        string $publicId,
        int $costCenterDbId,
        string $costCenterCode,
        int $amountMinor,
        int $revisionNumber = 1
    ): ExpenseTransaction {
        $revision = TransactionRevision::draft(
            $publicId . '-rev-' . $revisionNumber,
            $revisionNumber,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive($amountMinor, CurrencyCode::fromString('IRR'))
        );
        $revision->complete(
            'Description for ' . $publicId,
            $costCenterCode,
            $costCenterCode,
            $costCenterCode . ' name',
            $costCenterDbId,
            'Office Supplies',
            1,
            true
        );

        $transaction = new ExpenseTransaction($publicId, $sheet->ownerUid, $revision, $sheet->dbId);
        $this->container->query(ExpenseTransactionRepository::class)->save($transaction);

        $sheet->addTransaction($transaction);

        return $transaction;
    }

    private function submit(ExpenseSheet $sheet): void
    {
        $sheet->submit();
        $this->container->query(ExpenseSheetRepository::class)->save($sheet);
    }

    private function seedCostCenter(int $id, string $code): void
    {
        $costCenter = CostCenter::create(
            $code,
            $code . ' name',
            'Test cost centre',
            new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
            null
        );
        $costCenter->id = $id;
        $this->container->query(CostCenterRepository::class)->save($costCenter);
    }

    private function assignManager(string $uid, int $costCenterId): void
    {
        $this->container->query(RoleAssignmentRepository::class)->save(
            RoleAssignment::create(
                $uid,
                Role::CostCenterManager,
                $costCenterId,
                new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
                null
            )
        );
    }

    private function assignSeniorManager(string $uid, int $costCenterId): void
    {
        $this->container->query(RoleAssignmentRepository::class)->save(
            RoleAssignment::create(
                $uid,
                Role::SeniorManager,
                $costCenterId,
                new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
                null
            )
        );
    }

    private function actAs(string $uid): void
    {
        $this->container->setInstance(CurrentUser::class, new InMemoryCurrentUser($uid));
    }

    /**
     * Record a decision through the authoritative service, with the transaction's current lock
     * version read back from the repository so the optimistic guard is not the thing under test.
     */
    private function decide(
        string $actorUid,
        string $transactionPublicId,
        WorkflowStage $stage,
        Decision $decision,
        string $explanation
    ): void {
        $this->review()->recordDecision(
            $transactionPublicId,
            $stage,
            $decision,
            $explanation,
            'cmd-senior-' . Uuid::random(),
            $this->currentVersion($transactionPublicId)
        );
    }

    private function currentVersion(string $transactionPublicId): int
    {
        $transaction = $this->container->query(ExpenseTransactionRepository::class)->findByPublicId($transactionPublicId);
        self::assertNotNull($transaction, 'The transaction must exist to report its lock version');

        return $transaction->lockVersion;
    }

    private function review(): ManagementReviewService
    {
        return new ManagementReviewService(
            $this->container->query(TransactionManager::class),
            $this->container->query(ExpenseSheetRepository::class),
            $this->container->query(ExpenseTransactionRepository::class),
            $this->container->query(TransactionRevisionRepository::class),
            $this->container->query(CostCenterRepository::class),
            $this->container->query(RoleAssignmentRepository::class),
            $this->container->query(WorkflowDecisionRepository::class),
            $this->container->query(AuditWriter::class),
            $this->container->query(IdempotencyService::class),
            $this->container->query(CurrentUser::class),
            $this->container->query(UserDirectory::class),
            $this->container->query(AuthorizationService::class),
            $this->container->query(SupersededDecisionAudit::class)
        );
    }

    /** @param list<array{transaction: ExpenseTransaction}> $transactions */
    private function findTransaction(array $transactions, string $publicId): ?ExpenseTransaction
    {
        foreach ($transactions as $item) {
            if ($item['transaction']->id === $publicId) {
                return $item['transaction'];
            }
        }

        return null;
    }

    /**
     * Locate one list row of `getSeniorSheetsForManager()` by sheet public id.
     *
     * @param list<array{sheet: ExpenseSheet}> $items
     * @return array<string, mixed>|null
     */
    private function findListItem(array $items, string $publicId): ?array
    {
        foreach ($items as $item) {
            if ($item['sheet']->publicId === $publicId) {
                return $item;
            }
        }

        return null;
    }
}
