<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\SheetListFilter;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Uuid;

final readonly class SheetService
{
    public function __construct(
        private ExpenseSheetRepository $sheetRepository,
        private AccountRepository $accountRepository,
        private CurrentUser $currentUser,
        private TransactionManager $transactionManager
    ) {}

    /**
     * Create a new open sheet
     *
     * @param string $title
     * @param string $accountId
     * @return ExpenseSheet
     */
    public function createSheet(string $title, string $accountId): ExpenseSheet
    {
        $ownerUid = $this->currentUser->uid();

        // Verify account exists and get its currency
        $account = $this->accountRepository->findByPublicId($accountId);
        if ($account === null || $account->userUid !== $ownerUid) {
            throw new InvalidArgumentException('Account not found or access denied');
        }
        if (!$account->active) {
            throw new InvalidArgumentException('Cannot create sheet with inactive account');
        }

        $currency = $account->currencyCode();

        $sheetId = (string)Uuid::random();
        $sheet = ExpenseSheet::open($sheetId, $ownerUid, $accountId, $currency, $title);

        return $this->transactionManager->transactional(function () use ($sheet): ExpenseSheet {
            $this->sheetRepository->save($sheet);
            return $sheet;
        });
    }

    /**
     * Get a sheet by ID with owner-scoped access
     *
     * @param string $sheetId
     * @return ExpenseSheet|null
     */
    public function getSheet(string $sheetId): ?ExpenseSheet
    {
        $ownerUid = $this->currentUser->uid();
        $sheet = $this->sheetRepository->findByPublicId($sheetId);

        if ($sheet === null || $sheet->ownerUid !== $ownerUid) {
            return null;
        }

        return $sheet;
    }

    /**
     * List sheets for the current user, narrowed by FR-064's sheet facets.
     *
     * `total` is the count of records in scope, not the number of rows on this page. It used to be
     * `count($sheets)` over the already-paged read, with a comment saying the repository would need a
     * count method "for now" — so a purchaser holding 40 sheets was told they held 25, page 2 was
     * reported as empty, and the response contradicted the rows printed inside it. The count now comes
     * from the repository (T332), and from the *filtered* read (T333).
     *
     * ## Why the filter is applied before the count, not after
     *
     * The only order in which `total` means "matching records". A count taken before the narrowing
     * answers "how many sheets do you own" and prints it beside a page of sheets the caller filtered, so
     * the two numbers describe different sets and a client deriving a page count from `total` walks off
     * the end of the filtered result. Both the read and the count are given the same filter object, which
     * is what makes the order structural rather than a convention each repository remembers.
     *
     * @param int $page
     * @param int $pageSize
     * @return array{items: list<ExpenseSheet>, total: int}
     */
    public function listSheets(int $page = 1, int $pageSize = 25, ?SheetListFilter $filter = null): array
    {
        $ownerUid = $this->currentUser->uid();
        $offset = ($page - 1) * $pageSize;
        $sheets = $this->sheetRepository->findByOwnerUid($ownerUid, $filter, $offset, $pageSize);
        $total = $this->sheetRepository->countByOwnerUid($ownerUid, $filter);

        return ['items' => $sheets, 'total' => $total];
    }

    /**
     * Update an open sheet
     *
     * @param string $sheetId
     * @param string $title
     * @param string $accountId
     * @param int $expectedVersion
     * @return ExpenseSheet
     */
    public function updateSheet(string $sheetId, string $title, string $accountId, int $expectedVersion): ExpenseSheet
    {
        $ownerUid = $this->currentUser->uid();
        $sheet = $this->sheetRepository->findByPublicId($sheetId);

        if ($sheet === null || $sheet->ownerUid !== $ownerUid) {
            throw new InvalidArgumentException('Sheet not found');
        }

        if ($sheet->lockVersion !== $expectedVersion) {
            throw new InvalidArgumentException('Stale version');
        }

        if ($sheet->status !== \OCA\PettyCash\Domain\Workflow\SheetStatus::Open) {
            throw new InvalidArgumentException('Only open sheets can be updated');
        }

        $sheet->title = $title;
        $sheet->accountId = $accountId;
        $sheet->lockVersion++;
        $sheet->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->transactionManager->transactional(function () use ($sheet): ExpenseSheet {
            $this->sheetRepository->save($sheet);
            return $sheet;
        });
    }
}