<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\PettyCash\Application\Account\AccountAdministrationService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\StaleRecordVersion;
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
        private TransactionManager $transactionManager,
        /**
         * FR-020's rule, injected rather than reimplemented here.
         *
         * The three clauses — none provisions, one resolves, several refuse — live in
         * `AccountAdministrationService::resolveAccountForNewSheet()` because T085 wrote them there with
         * their idempotency and concurrent-insert handling. Re-deriving the rule in the sheet path would
         * give provisioning two homes, and the one without the duplicate-key handling would lose a race
         * the other one survives.
         */
        private AccountAdministrationService $accountAdministration
    ) {}

    /**
     * Create a new open sheet.
     *
     * ## Why `accountId` is nullable, and what its absence means
     *
     * FR-020 resolves the account from the caller's own active accounts: none means the default rial
     * account is **provisioned on this attempt** and used, exactly one means that account is used
     * automatically, and several means the caller must choose. The third case is the only one that is an
     * error, and it is an error *for the caller to fix* by sending `accountId` — which is why it is
     * refused rather than guessed.
     *
     * A purchaser with zero accounts has no `accountId` to send, so a contract that made the field
     * required made FR-020 unreachable: the creation attempt could never reach the code that would
     * provision, because it was refused one step earlier for the missing field. `SheetCreate.required` no
     * longer names `accountId`, and that amendment is the whole of the fix — the provisioning itself was
     * already implemented and idempotent.
     *
     * @param string $title
     * @param string|null $accountId the caller's choice, or null to have it resolved from FR-020's rule
     * @throws InvalidArgumentException when the account is unknown, not the caller's, inactive, or the
     *         caller holds several and so must choose
     * @return ExpenseSheet
     */
    public function createSheet(string $title, ?string $accountId = null): ExpenseSheet
    {
        $ownerUid = $this->currentUser->uid();

        $account = ($accountId === null || trim($accountId) === '')
            // No choice offered, so FR-020 decides. A blank string is treated as "none" rather than as a
            // public id, because `trim($accountId) === ''` is exactly how an omitted field arrives from a
            // client that sent an empty input.
            ? $this->accountAdministration->resolveAccountForNewSheet($ownerUid)
            : $this->accountRepository->findByPublicId($accountId);

        if ($account === null || $account->userUid !== $ownerUid) {
            // `resolveAccountForNewSheet()` returns null for a caller holding **several** active accounts,
            // which is not the same as an unknown id and deserves the different message: this one is fixed
            // by choosing, not by correcting a typo. Telling a caller with three accounts that their
            // "account not found" would send them looking for a typo that does not exist.
            throw new InvalidArgumentException(
                ($accountId === null || trim($accountId) === '')
                    ? 'Several active accounts: send accountId to choose which one this sheet draws on'
                    : 'Account not found or access denied'
            );
        }
        if (!$account->active) {
            throw new InvalidArgumentException('Cannot create sheet with inactive account');
        }

        $currency = $account->currencyCode();

        $sheetId = (string)Uuid::random();
        $sheet = ExpenseSheet::open($sheetId, $ownerUid, $account->id, $currency, $title);

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

        // FR-025 covers a sheet's own pre-submission fields, and `contracts/ocs-api.yaml`'s
        // `Conflict` response names a stale version first, so this is a 409 rather than a 422.
        if ($sheet->lockVersion !== $expectedVersion) {
            throw StaleRecordVersion::forResource(
                'sheet',
                $sheetId,
                $expectedVersion,
                $sheet->lockVersion
            );
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