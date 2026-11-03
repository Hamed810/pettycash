<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Command;

use OCA\PettyCash\Application\Account\AccountAdministrationService;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Configuration\CurrencyService;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Application\Expense\SheetService;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Command\DevSeedSheetsCommand;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTypeRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionRevisionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * T060: the development fixture command.
 *
 * The tests exercise the real application services and the in-memory ports. The
 * only mocked boundaries are Nextcloud's config, user manager, and session:
 * those are container/runtime objects, not domain collaborators, and the command
 * reads one debug flag and one actor from them.
 *
 * The assertions are deliberately about the fixture *contract*, not about the
 * implementation's loops: exact 25/30/35/40 counts, exact missing-required
 * counts, both receipts on exempt rows and no receipt on exempt rows, and two
 * correction-returned transactions in sheet 35. A reimplementation that produced
 * the same shape would pass; a reimplementation that produced "about 30"
 * transactions or one generic set of receipt states would not.
 */
final class DevSeedSheetsCommandTest extends TestCase
{
    private const UID = 'fixture-owner';
    private const REQUIRED_TITLE = 'DEV T060 Receipt Required';
    private const EXEMPT_TITLE = 'DEV T060 Receipt Exempt';

    /**
     * The command is an `occ` adapter: its constructor legitimately depends on
     * Nextcloud's `OCP` interfaces, while this suite's bootstrap deliberately
     * does not boot Nextcloud. Register a narrow loader for the public
     * interfaces so the adapter can be tested against in-memory application
     * services without starting a server.
     *
     * The path is derived from the mounted app tree, not hardcoded: in the
     * canonical container this file is under
     * `/var/www/html/custom_apps/pettycash/tests/Contract/Command`, so three
     * `dirname()` calls reach the app and two more reach the Nextcloud root.
     * Nothing outside `OCP\` is handled.
     */
    public static function setUpBeforeClass(): void
    {
        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, 'OCP\\')) {
                return;
            }
            $root = dirname(__DIR__, 3) . '/../..';
            $file = $root . '/lib/public/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    public function testRefusesToRunWhenDebugModeIsDisabled(): void
    {
        $harness = $this->createHarness(false);
        $tester = new CommandTester($harness['command']);

        $exit = $tester->execute(['--owner' => self::UID]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('debug', strtolower($tester->getDisplay()));
        self::assertSame(0, $harness['sheets']->countAll());
        self::assertSame(0, $harness['transactions']->countAll());
    }

    public function testSeedsTheExactFixtureShapeAndReplacesItOnASecondRun(): void
    {
        $harness = $this->createHarness(true);
        $tester = new CommandTester($harness['command']);

        self::assertSame(0, $tester->execute(['--owner' => self::UID]), $tester->getDisplay());

        $this->assertFixtureShape($harness);

        // A second run is a replacement, not an append. This is the idempotency
        // requirement T060 names; it is asserted against the same repositories
        // rather than by reading output.
        self::assertSame(0, $tester->execute(['--owner' => self::UID]), $tester->getDisplay());

        $this->assertFixtureShape($harness);
    }

    public function testCleanupRemovesOnlyTheFixtureNamespace(): void
    {
        $harness = $this->createHarness(true);

        // A non-fixture sheet in the same owner's account is the control. It is
        // created before the seed so the seed's own cleanup runs with it present;
        // if cleanup matched too broadly, the fixture run would already delete it
        // and the final assertion would not be able to tell when.
        $harness['currentUser']->setUid(self::UID);
        $keep = $harness['sheetService']->createSheet('My own sheet');
        self::assertNotNull($keep->dbId);

        $tester = new CommandTester($harness['command']);
        self::assertSame(0, $tester->execute(['--owner' => self::UID]), $tester->getDisplay());
        self::assertCount(5, $harness['sheets']->findByOwnerUid(self::UID));

        self::assertSame(0, $tester->execute(['--cleanup' => true, '--owner' => self::UID]), $tester->getDisplay());

        $remaining = $harness['sheets']->findByOwnerUid(self::UID);
        self::assertCount(1, $remaining);
        self::assertSame('My own sheet', $remaining[0]->title);
        self::assertSame(0, $harness['transactions']->countAll());
        self::assertSame([], $harness['evidence']->findByTransactionId(1));
    }

    /**
     * @param array{
     *     command: DevSeedSheetsCommand,
     *     sheets: ExpenseSheetRepository,
     *     transactions: ExpenseTransactionRepository,
     *     evidence: EvidenceRepository,
     *     expenseTypes: ExpenseTypeRepository,
     *     currentUser: InMemoryCurrentUser,
     *     sheetService: SheetService
     * } $harness
     */
    private function assertFixtureShape(array $harness): void
    {
        $sheets = $harness['sheets']->findByOwnerUid(self::UID);
        self::assertCount(4, $sheets);

        $byCount = [];
        foreach ($sheets as $sheet) {
            $count = count($harness['transactions']->findBySheetId((int) $sheet->dbId));
            $byCount[$count] = $sheet;
        }
        ksort($byCount);
        self::assertSame([25, 30, 35, 40], array_keys($byCount));
        self::assertSame(130, $harness['transactions']->countAll());

        $required = $harness['expenseTypes']->findActiveByTitle(self::REQUIRED_TITLE);
        $exempt = $harness['expenseTypes']->findActiveByTitle(self::EXEMPT_TITLE);
        self::assertNotNull($required);
        self::assertNotNull($exempt);
        self::assertSame(true, $required->evidenceRequired);
        self::assertSame(false, $exempt->evidenceRequired);

        $expectedMissingRequired = [25 => 1, 30 => 5, 35 => 1, 40 => 5];
        $expectedExemptAttached = [25 => 2, 30 => 3, 35 => 2, 40 => 5];
        $receipts = 0;
        $corrections = [];

        foreach ($byCount as $count => $sheet) {
            self::assertSame($count === 35 ? SheetStatus::Correction : SheetStatus::Open, $sheet->status);

            $missingRequired = 0;
            $exemptAttached = 0;
            foreach ($harness['transactions']->findBySheetId((int) $sheet->dbId) as $transaction) {
                $activeEvidence = $harness['evidence']->findByTransactionId((int) $transaction->dbId, true);
                $typeId = $transaction->revision()->expenseTypeId();
                self::assertNotNull($typeId);

                if ($typeId === $required->publicId) {
                    if ($activeEvidence === []) {
                        $missingRequired++;
                    } else {
                        self::assertCount(1, $activeEvidence);
                        $receipts++;
                    }
                } elseif ($typeId === $exempt->publicId) {
                    if ($activeEvidence !== []) {
                        self::assertCount(1, $activeEvidence);
                        $exemptAttached++;
                        $receipts++;
                    }
                } else {
                    self::fail('Fixture transaction has an unknown expense type: ' . $typeId);
                }

                if ($transaction->status === TransactionStatus::AwaitingPurchaserCorrection) {
                    $corrections[] = $transaction;
                }
            }

            self::assertSame($expectedMissingRequired[$count], $missingRequired, 'missing required receipts on sheet ' . $count);
            self::assertSame($expectedExemptAttached[$count], $exemptAttached, 'attached exempt receipts on sheet ' . $count);
        }

        self::assertSame(66, $receipts);
        self::assertCount(2, $corrections);
        self::assertSame(
            [TransactionStatus::AwaitingPurchaserCorrection, TransactionStatus::AwaitingPurchaserCorrection],
            array_map(static fn($transaction): TransactionStatus => $transaction->status, $corrections)
        );

        $requiredCorrection = $this->findCorrectionByType($harness, $required->publicId);
        $exemptCorrection = $this->findCorrectionByType($harness, $exempt->publicId);
        self::assertNotNull($requiredCorrection);
        self::assertNotNull($exemptCorrection);
        self::assertSame([], $harness['evidence']->findByTransactionId((int) $requiredCorrection->dbId, true));
        self::assertSame([], $harness['evidence']->findByTransactionId((int) $exemptCorrection->dbId, true));
    }

    private function findCorrectionByType(array $harness, string $typePublicId): ?\OCA\PettyCash\Domain\Expense\ExpenseTransaction
    {
        foreach ($harness['sheets']->findByOwnerUid(self::UID) as $sheet) {
            foreach ($harness['transactions']->findBySheetId((int) $sheet->dbId) as $transaction) {
                if ($transaction->status !== TransactionStatus::AwaitingPurchaserCorrection) {
                    continue;
                }
                if ($transaction->revision()->expenseTypeId() === $typePublicId) {
                    return $transaction;
                }
            }
        }
        return null;
    }

    /**
     * @return array{
     *     command: DevSeedSheetsCommand,
     *     sheets: InMemoryExpenseSheetRepository,
     *     transactions: InMemoryExpenseTransactionRepository,
     *     evidence: InMemoryEvidenceRepository,
     *     expenseTypes: InMemoryExpenseTypeRepository,
     *     currentUser: InMemoryCurrentUser,
     *     sheetService: SheetService
     * }
     */
    private function createHarness(bool $debug): array
    {
        $currentUser = new InMemoryCurrentUser(self::UID);
        $fileStore = new InMemoryPrivateFileStore();
        $audit = new InMemoryAuditWriter();
        $transactionManager = new InMemoryTransactionManager();
        $sheetRepository = new InMemoryExpenseSheetRepository();
        $transactionRepository = new InMemoryExpenseTransactionRepository();
        $revisionRepository = new InMemoryTransactionRevisionRepository();
        $evidenceRepository = new InMemoryEvidenceRepository();
        $costCenters = new InMemoryCostCenterRepository();
        $expenseTypes = new InMemoryExpenseTypeRepository();
        $roles = new InMemoryRoleAssignmentRepository();
        $currencies = new InMemoryCurrencyRepository();
        $accounts = new InMemoryAccountRepository();
        $commandResults = new InMemoryCommandResultRepository();
        $userDirectory = new InMemoryUserDirectory();
        $userDirectory->addUser(self::UID, 'Fixture Owner');

        $configurationAudit = new ConfigurationAuditService($audit, $transactionManager);
        $currencyService = new CurrencyService(
            $currencies,
            new InMemoryAdministratorCheck(),
            $configurationAudit,
            $accounts
        );
        $accountAdministration = new AccountAdministrationService($accounts, $currencyService, $audit);
        $sheetService = new SheetService(
            $sheetRepository,
            $accounts,
            $currentUser,
            $transactionManager,
            $accountAdministration
        );
        $idempotency = new IdempotencyService($commandResults);
        $transactionService = new TransactionService(
            $sheetRepository,
            $transactionRepository,
            $revisionRepository,
            $costCenters,
            $expenseTypes,
            $evidenceRepository,
            $fileStore,
            $currentUser,
            $transactionManager,
            $audit,
            $idempotency
        );
        $evidenceService = new EvidenceService(
            $fileStore,
            $evidenceRepository,
            $currentUser,
            $audit,
            $userDirectory,
            $idempotency
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn(self::UID);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnCallback(
            static fn(string $uid): ?IUser => $uid === self::UID ? $user : null
        );
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);
        $session->method('setUser')->willReturnCallback(
            static function (?IUser $next) use ($currentUser): void {
                $currentUser->setUid($next?->getUID() ?? 'anonymous');
            }
        );
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')
            ->with('debug', false)
            ->willReturn($debug);

        $command = new DevSeedSheetsCommand(
            $config,
            $userManager,
            $session,
            $sheetService,
            $transactionService,
            $evidenceService,
            $sheetRepository,
            $transactionRepository,
            $evidenceRepository,
            $costCenters,
            $expenseTypes,
            $roles,
            $fileStore,
            null
        );

        return [
            'command' => $command,
            'sheets' => $sheetRepository,
            'transactions' => $transactionRepository,
            'evidence' => $evidenceRepository,
            'expenseTypes' => $expenseTypes,
            'currentUser' => $currentUser,
            'sheetService' => $sheetService,
        ];
    }
}
