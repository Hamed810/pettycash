<?php

declare(strict_types=1);

namespace OCA\PettyCash\Command;

use DateTimeImmutable;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Application\Expense\SheetService;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function sprintf;

/**
 * T060: `occ pettycash:dev:seed-sheets` — development fixtures for 005 Phase 10.
 *
 * The command is hard-gated on Nextcloud's `debug` system value. It is a
 * fixture writer, not an application feature, so it must be impossible to run
 * through a production config mistake, a wrong route, or a background job.
 *
 * Scope guarantees:
 * - The fixture namespace (`DEV T060 fixture`) is the only thing cleanup
 *   deletes, and cleanup runs before every seed. A second run therefore replaces
 *   the fixture set rather than appending to it, including after a partial run.
 * - Business writes go through the application services (`createSheet()`,
 *   `createTransaction()`, `uploadEvidence()`) so fixtures exercise the same
 *   invariants, audit trail, and idempotency as a live client.
 * - Reference data (cost centre, expense types, role assignments) is created
 *   directly from domain factories and repositories, because it is not a
 *   business write.
 * - Optional `IDBConnection` cleanup covers the append-only side effects the
 *   application services create: transaction audit rows and the
 *   `transaction.create` command results. Those rows are removed before the
 *   aggregates they describe; all other cleanup stays behind the ports.
 */
final class DevSeedSheetsCommand extends Command
{
    protected static $defaultName = 'pettycash:dev:seed-sheets';

    private const USER_OPTION_DEFAULT = 'admin';

    /**
     * The fixture namespace in a sheet's title. No side table, marker row, or
     * special lookup: the title prefix is the namespace, and cleanup checks it.
     */
    private const TITLE_PREFIX = 'DEV T060 fixture';

    private const COST_CENTER_CODE = 'DEV-T060';
    private const RECEIPT_REQUIRED_TITLE = 'DEV T060 Receipt Required';
    private const RECEIPT_EXEMPT_TITLE = 'DEV T060 Receipt Exempt';

    private const SHEET_COUNTS = [25, 30, 35, 40];

    /**
     * Number of required-receipt transactions left without an active receipt on
     * each sheet. Required transactions are the odd indices, and these are the
     * final required indices on the sheet. Sheet 25's exactly-one case is the
     * readiness fixture; sheet 30's five cases are the multiple-error fixture.
     */
    private const MISSING_REQUIRED = [
        25 => 1,
        30 => 5,
        35 => 1,
        40 => 5,
    ];

    /**
     * Number of receipt-exempt transactions that still receive a receipt. The
     * rule is an exemption, not a prohibition, so the fixture set proves both
     * shapes: attached receipts on an exempt type and no receipt on an exempt
     * type.
     */
    private const EXEMPT_ATTACHED = [
        25 => 2,
        30 => 3,
        35 => 2,
        40 => 5,
    ];

    private const CORRECTION_SHEET_COUNT = 35;
    private const CORRECTION_REQUIRED_INDEX = 35;
    private const CORRECTION_EXEMPT_INDEX = 12;

    public function __construct(
        private readonly IConfig $config,
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly SheetService $sheetService,
        private readonly TransactionService $transactionService,
        private readonly EvidenceService $evidenceService,
        private readonly ExpenseSheetRepository $sheets,
        private readonly ExpenseTransactionRepository $transactions,
        private readonly EvidenceRepository $evidence,
        private readonly CostCenterRepository $costCenters,
        private readonly ExpenseTypeRepository $expenseTypes,
        private readonly RoleAssignmentRepository $roles,
        private readonly PrivateFileStore $fileStore,
        private readonly ?IDBConnection $connection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Seed T060 development fixture sheets (25/30/35/40 transactions) in a debug-enabled instance')
            ->addOption(
                'owner',
                null,
                InputOption::VALUE_REQUIRED,
                'Nextcloud user uid who owns the fixtures',
                self::USER_OPTION_DEFAULT
            )
            ->addOption(
                'cleanup',
                null,
                InputOption::VALUE_NONE,
                'Remove only the fixture namespace, leaving everything else untouched'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Evaluate the debug gate before reading any option that could lead to a
        // write. A fixture command that got as far as resolving a user has no
        // way to undo a run that should not have started.
        if (!$this->config->getSystemValueBool('debug', false)) {
            $output->writeln('<error>This is a development command. Enable Nextcloud debug mode before running it.</error>');
            return Command::FAILURE;
        }

        $uid = trim((string) $input->getOption('owner'));
        if ($uid === '') {
            $output->writeln('<error>The fixture owner uid must not be empty.</error>');
            return Command::FAILURE;
        }

        $user = $this->userManager->get($uid);
        if ($user === null) {
            $output->writeln(sprintf('<error>Unknown fixture owner uid: %s</error>', $uid));
            return Command::FAILURE;
        }

        $cleanupOnly = (bool) $input->getOption('cleanup');
        $previous = $this->userSession->getUser();

        // CurrentUser is the session adapter. Application services read the actor
        // from there, so the command switches the session for the duration of the
        // run and restores whatever it found in `finally`. A failed run must not
        // leave the process acting as a different user.
        $this->userSession->setUser($user);
        try {
            $removed = $this->cleanupFixtures($uid);
            if ($cleanupOnly) {
                $output->writeln(sprintf(
                    'Cleaned T060 fixtures: %d sheet(s), %d transaction(s), %d evidence row(s), %d file(s).',
                    $removed['sheets'],
                    $removed['transactions'],
                    $removed['evidence'],
                    $removed['files']
                ));
                return Command::SUCCESS;
            }

            [$reports, $receiptCount] = $this->seed($uid);
            $totalTransactions = 0;
            foreach ($reports as $report) {
                $output->writeln(sprintf(
                    'DEV T060 fixture · %d transactions · sheet %s · missing required receipt(s): %d · exempt receipt(s): %d · correction-returned: %d',
                    $report['count'],
                    $report['sheetId'],
                    $report['missingRequired'],
                    $report['exemptAttached'],
                    $report['corrections']
                ));
                $totalTransactions += $report['count'];
            }

            $output->writeln(sprintf(
                'Seeded %d sheet(s) (%d transaction(s), %d receipt(s)) for owner %s.',
                count($reports),
                $totalTransactions,
                $receiptCount,
                $uid
            ));
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Seed failed.</error>');
            $output->writeln(sprintf('<comment>%s</comment>', $e->getMessage()));
            return Command::FAILURE;
        } finally {
            $this->userSession->setUser($previous);
        }
    }

    /**
     * @return array{0: list<array{count:int, sheetId:string, missingRequired:int, exemptAttached:int, corrections:int}>, 1: int}
     */
    private function seed(string $uid): array
    {
        $costCenter = $this->ensureCostCenter($uid);
        $costCenterId = $this->requirePublicId($costCenter->publicId, 'Cost center');
        $required = $this->ensureExpenseType($uid, self::RECEIPT_REQUIRED_TITLE, true);
        $exempt = $this->ensureExpenseType($uid, self::RECEIPT_EXEMPT_TITLE, false);
        $this->ensureManagerRoles($uid, $costCenter);

        $receiptCount = 0;
        $reports = [];
        foreach (self::SHEET_COUNTS as $count) {
            $title = sprintf('%s · %d', self::TITLE_PREFIX, $count);
            $sheet = $this->sheetService->createSheet($title);
            if ($sheet->publicId === null) {
                // The DB adapter publishes `public_id` from the aggregate id on
                // insert. `InMemoryExpenseSheetRepository` does not, and this
                // command and its unit harness both need `findByPublicId()` to
                // resolve a just-created sheet. Harmless in production, where
                // the mirror is already populated.
                $sheet->publicId = $sheet->id;
                $this->sheets->save($sheet);
            }

            [$transactions, $receipts] = $this->fillSheet(
                $sheet->id,
                $costCenterId,
                $count,
                $required,
                $exempt
            );
            $receiptCount += $receipts;

            $corrections = $count === self::CORRECTION_SHEET_COUNT
                ? $this->markCorrections($transactions)
                : 0;

            // The corrected sheet carries transactions awaiting purchaser
            // correction, so its sheet status must agree with them. Other sheets
            // remain OPEN: they are the readiness/submission fixtures.
            if ($corrections > 0) {
                $sheet->status = SheetStatus::Correction;
                $sheet->submittedTotalMinor = array_sum(array_map(
                    static fn(ExpenseTransaction $transaction): int => $transaction->revision()->amount()->minorUnits(),
                    $transactions
                ));
                $this->sheets->save($sheet);
            }

            $reports[] = [
                'count' => $count,
                'sheetId' => $sheet->id,
                'missingRequired' => self::MISSING_REQUIRED[$count],
                'exemptAttached' => self::EXEMPT_ATTACHED[$count],
                'corrections' => $corrections,
            ];
        }

        return [$reports, $receiptCount];
    }

    /**
     * @param list<ExpenseTransaction> $transactions
     */
    private function markCorrections(array $transactions): int
    {
        $marked = 0;
        foreach ([self::CORRECTION_REQUIRED_INDEX, self::CORRECTION_EXEMPT_INDEX] as $index) {
            $transaction = $transactions[$index - 1] ?? null;
            if ($transaction === null) {
                continue;
            }

            $transaction->status = TransactionStatus::AwaitingPurchaserCorrection;
            $transaction->stage = WorkflowStage::Correction;
            $this->transactions->save($transaction);
            $marked++;
        }

        return $marked;
    }

    /**
     * @return array{0: list<ExpenseTransaction>, 1: int}
     */
    private function fillSheet(
        string $sheetId,
        string $costCenterId,
        int $count,
        ExpenseType $required,
        ExpenseType $exempt
    ): array {
        $requiredIndices = [];
        for ($index = 1; $index <= $count; $index++) {
            if ($index % 2 === 1) {
                $requiredIndices[] = $index;
            }
        }

        $missing = array_fill_keys(
            array_slice($requiredIndices, -self::MISSING_REQUIRED[$count]),
            true
        );

        $transactions = [];
        $attachedExempt = 0;
        $receiptCount = 0;

        for ($index = 1; $index <= $count; $index++) {
            $isRequired = $index % 2 === 1;
            $type = $isRequired ? $required : $exempt;

            $transaction = $this->transactionService->createTransaction(
                $sheetId,
                sprintf('2026-01-%02d', (($index - 1) % 28) + 1),
                100000 + ($index * 1000),
                (string) Uuid::random(),
                sprintf('T060 receipt fixture %d · transaction %02d', $count, $index),
                $costCenterId,
                $this->requirePublicId($type->publicId, 'Expense type')
            );
            $transactions[] = $transaction;

            // Required rows are attached except for the explicitly missing ones;
            // exempt rows are attached only for the first N exempt indices.
            $attach = $isRequired
                ? !isset($missing[$index])
                : $attachedExempt < self::EXEMPT_ATTACHED[$count];

            if (!$attach) {
                continue;
            }
            if (!$isRequired) {
                $attachedExempt++;
            }

            $this->evidenceService->uploadEvidence(
                $transaction->publicId ?? $transaction->id,
                (int) $transaction->dbId,
                $transaction->revision()->number(),
                $transaction->revision()->dbId,
                sprintf('t060-receipt-%02d.txt', $index),
                sprintf("T060 receipt fixture %d · transaction %02d\n", $count, $index),
                'text/plain'
            );
            $receiptCount++;
        }

        return [$transactions, $receiptCount];
    }

    private function ensureCostCenter(string $uid): CostCenter
    {
        $costCenter = $this->costCenters->findByCode(self::COST_CENTER_CODE);
        if ($costCenter === null) {
            $costCenter = CostCenter::create(
                self::COST_CENTER_CODE,
                'T060 development fixtures',
                'Reference cost centre owned by the development fixture command',
                new DateTimeImmutable('2020-01-01'),
                null
            );
            $costCenter->createdBy = $uid;
            $this->costCenters->save($costCenter);
        }

        if (!$costCenter->active) {
            $costCenter->active = true;
            $this->costCenters->save($costCenter);
        }
        $this->requirePublicId($costCenter->publicId, 'Cost center');

        return $costCenter;
    }

    private function ensureExpenseType(string $uid, string $title, bool $evidenceRequired): ExpenseType
    {
        $type = $this->expenseTypes->findActiveByTitle($title);
        if ($type === null) {
            $type = ExpenseType::create(
                $title,
                $evidenceRequired
                    ? 'T060 fixture: an active receipt is required to submit'
                    : 'T060 fixture: a receipt is optional for submission',
                $evidenceRequired
            );
            $type->createdBy = $uid;
            $this->expenseTypes->save($type);
        }

        if ($type->evidenceRequired !== $evidenceRequired) {
            $type->evidenceRequired = $evidenceRequired;
            $this->expenseTypes->save($type);
        }
        if ($type->publicId === null) {
            // Mirrors the DB adapter's insert side effect for the in-memory
            // repository. The real adapter has already populated this.
            $type->publicId = (string) Uuid::random();
            $this->expenseTypes->save($type);
        }

        return $type;
    }

    private function ensureManagerRoles(string $uid, CostCenter $costCenter): void
    {
        if ($costCenter->id === null) {
            throw new RuntimeException('Cost center was saved without a surrogate id');
        }

        $today = new DateTimeImmutable('today');
        foreach ([Role::CostCenterManager, Role::SeniorManager] as $role) {
            $present = false;
            foreach ($this->roles->findActiveByUserAndRole($uid, $role, $today) as $assignment) {
                if ($assignment->costCenterId === $costCenter->id) {
                    $present = true;
                    break;
                }
            }
            if ($present) {
                continue;
            }

            $this->roles->save(RoleAssignment::create(
                $uid,
                $role,
                $costCenter->id,
                new DateTimeImmutable('2020-01-01'),
                null
            ));
        }
    }

    /**
     * Remove every fixture sheet owned by `$uid`, and its namespace-owned rows
     * and files. Finishes in an idempotent state: a second cleanup finds zero
     * sheets and deletes nothing outside the namespace.
     *
     * @return array{sheets:int, transactions:int, evidence:int, files:int}
     */
    private function cleanupFixtures(string $uid): array
    {
        $sheets = $this->findByNamespace($uid);
        $transactionIds = [];
        $sheetIds = [];
        $sheetPublicIds = [];
        $keys = [];
        $evidenceCount = 0;
        $transactionsDeleted = 0;

        foreach ($sheets as $sheet) {
            if ($sheet->dbId !== null) {
                $sheetIds[] = $sheet->dbId;
                $sheetPublicIds[] = $sheet->id;
                foreach ($this->transactions->findBySheetId($sheet->dbId) as $transaction) {
                    if ($transaction->dbId === null) {
                        continue;
                    }
                    $transactionIds[] = $transaction->dbId;
                    foreach ($this->evidence->findByTransactionId($transaction->dbId) as $evidence) {
                        $evidenceCount++;
                        $keys[] = $evidence->appDataKey;
                        $this->evidence->delete($evidence);
                    }
                    $this->transactions->delete($transaction);
                    $transactionsDeleted++;
                }
            }
            $this->sheets->delete($sheet);
        }

        // Side-effect tables have no aggregate to walk. Delete only rows tied to
        // the fixture sheet or transaction ids. In the in-memory harness the
        // connection is null; those stores are already in-memory and do not need
        // this layer.
        $this->deleteAuditAndCommandRows($transactionIds, $sheetIds, $sheetPublicIds);

        $filesDeleted = 0;
        foreach ($keys as $key) {
            $this->fileStore->deleteDraftEvidence($key);
            $filesDeleted++;
        }

        return [
            'sheets' => count($sheets),
            'transactions' => $transactionsDeleted,
            'evidence' => $evidenceCount,
            'files' => $filesDeleted,
        ];
    }

    /**
     * @return list<ExpenseSheet>
     */
    private function findByNamespace(string $uid): array
    {
        $matches = [];
        foreach ($this->sheets->findByOwnerUid($uid, null, 0, 1000) as $sheet) {
            $title = $sheet->title ?? '';
            if (str_starts_with($title, self::TITLE_PREFIX . ' · ')) {
                $matches[] = $sheet;
            }
        }
        return $matches;
    }

    /**
     * @param list<int> $transactionIds
     * @param list<int> $sheetIds
     * @param list<string> $sheetPublicIds
     */
    private function deleteAuditAndCommandRows(array $transactionIds, array $sheetIds, array $sheetPublicIds): void
    {
        if ($this->connection === null || $sheetIds === []) {
            return;
        }

        // Audit rows are deleted by both keys because sheet-level events have a
        // null transaction id and transaction-level events also carry a sheet id.
        if ($transactionIds !== []) {
            $this->deleteWhereIn('pettycash_transaction_audit', 'transaction_id', $transactionIds, IQueryBuilder::PARAM_INT_ARRAY);
        }
        $this->deleteWhereIn('pettycash_transaction_audit', 'sheet_id', $sheetIds, IQueryBuilder::PARAM_INT_ARRAY);

        if ($sheetPublicIds !== []) {
            // `target_public_id` is the sheet's public uuid, not its surrogate
            // key. The operation predicate keeps cleanup away from any future
            // command type that happens to target a sheet.
            $qb = $this->connection->getQueryBuilder();
            $qb->delete('pettycash_command_results')
                ->where($qb->expr()->eq('operation', $qb->createNamedParameter('transaction.create')))
                ->andWhere($qb->expr()->in(
                    'target_public_id',
                    $qb->createNamedParameter($sheetPublicIds, IQueryBuilder::PARAM_STR_ARRAY)
                ))
                ->executeStatement();
        }
    }

    /**
     * @param list<int> $values
     */
    private function deleteWhereIn(string $table, string $column, array $values, int $parameterType): void
    {
        if ($this->connection === null || $values === []) {
            return;
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->delete($table)
            ->where($qb->expr()->in(
                $column,
                $qb->createNamedParameter($values, $parameterType)
            ))
            ->executeStatement();
    }

    private function requirePublicId(?string $publicId, string $resource): string
    {
        if ($publicId === null || $publicId === '') {
            throw new RuntimeException($resource . ' has no public id');
        }
        return $publicId;
    }
}
