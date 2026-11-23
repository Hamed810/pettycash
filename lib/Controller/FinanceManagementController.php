<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use InvalidArgumentException;
use OCA\PettyCash\Application\Finance\FinanceAccess;
use OCA\PettyCash\Application\Finance\FinanceLedgerService;
use OCA\PettyCash\Application\Finance\CompletedSheetHistoryService;
use OCA\PettyCash\Application\Finance\HolderDirectoryService;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Infrastructure\Http\FinancePayloadFormatter;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Throwable;

/**
 * Feature 008 (T009/T010/T014) — the public Finance holder reads and the account ledger.
 *
 * All actions answer only when the authenticated actor holds an effective `FINANCE` assignment;
 * the actor is read from the session by `FinanceAccess` and is never accepted from the request.
 * A caller without the assignment receives `403` and no holder identity, account, balance, or
 * movement data, and that gate runs **before** any pagination validation so an unauthorized caller
 * cannot use validation responses to learn anything about the read.
 *
 * The reads are projections only. Nothing here creates, updates, deletes, recharges, or decides
 * anything, and no response carries a mutation capability.
 */
class FinanceManagementController extends OCSController
{
    public function __construct(
        IRequest $request,
        private FinanceAccess $financeAccess,
        private HolderDirectoryService $holderDirectory,
        private FinanceLedgerService $financeLedger,
        private CompletedSheetHistoryService $completedSheetHistory,
        private UserDirectory $userDirectory,
        private FinancePayloadFormatter $payloadFormatter,
        private OcsErrorMapper $ocsErrorMapper
    ) {
        parent::__construct('pettycash', $request);
    }

    /**
     * `GET /api/v1/finance/holders` — the petty-cash holders, one row per account owner.
     *
     * `search` is trimmed and bounded by `HolderDirectoryService`; an empty or whitespace-only term
     * means no filter. An empty result is a successful empty page, never a 404, because "no holder
     * matched" is a normal answer to a filter.
     */
    #[NoAdminRequired]
    public function holders(?string $search = null, int $page = 1, int $pageSize = 25): DataResponse
    {
        if (!$this->financeAccess->hasEffectiveFinanceAssignment()) {
            return $this->ocsErrorMapper->forbidden('Finance access is required');
        }

        try {
            $holders = $this->holderDirectory->page($search, $page, $pageSize);

            return new DataResponse($this->payloadFormatter->holderPage($holders));
        } catch (InvalidArgumentException $e) {
            return $this->validationFailure($e);
        } catch (Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * `GET /api/v1/finance/holders/{holderUid}/accounts` — every account owned by one holder,
     * active and inactive.
     *
     * A uid that owns no account and a uid that names nobody are one answer: `404`, produced from
     * the service's single `null` result. The endpoint therefore cannot be used to discover whether
     * a platform user exists. The display name is resolved only after the holder is known to own an
     * account, and only for the header of this one response.
     */
    #[NoAdminRequired]
    public function holderAccounts(string $holderUid, int $page = 1, int $pageSize = 25): DataResponse
    {
        if (!$this->financeAccess->hasEffectiveFinanceAssignment()) {
            return $this->ocsErrorMapper->forbidden('Finance access is required');
        }

        try {
            $accounts = $this->financeLedger->accountsForHolder($holderUid, $page, $pageSize);
            if ($accounts === null) {
                return $this->ocsErrorMapper->notFound('No petty-cash account is held by this user');
            }

            return new DataResponse($this->payloadFormatter->holderAccountPage(
                $holderUid,
                $this->displayName($holderUid),
                $accounts
            ));
        } catch (InvalidArgumentException $e) {
            return $this->validationFailure($e);
        } catch (Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * `GET /api/v1/finance/accounts/{accountId}/ledger` — the authoritative six-column ledger
     * for one account.
     *
     * The account is resolved by public id; an unknown id is `404`. The ledger page returns the
     * account header (current balance, version, movement count, last movement timestamp) plus the
     * requested page of movement rows. Each row carries the persisted `occurredAt` and `balanceAfter`,
     * exactly one monetary side (`recharge` for a charge, `expense` for a consumption), the Cost Sheet
     * reference/title and approved/total counts for consumptions, and `approved: null` for recharges.
     * Negative balances pass through unchanged. No mutation capability is exposed.
     */
    #[NoAdminRequired]
    public function ledger(string $accountId, int $page = 1, int $pageSize = 25): DataResponse
    {
        if (!$this->financeAccess->hasEffectiveFinanceAssignment()) {
            return $this->ocsErrorMapper->forbidden('Finance access is required');
        }

        try {
            $ledger = $this->financeLedger->ledger($accountId, $page, $pageSize);
            if ($ledger === null) {
                return $this->ocsErrorMapper->notFound('No petty-cash account exists with this id');
            }

            return new DataResponse($this->payloadFormatter->ledgerPage($ledger));
        } catch (InvalidArgumentException $e) {
            return $this->validationFailure($e);
        } catch (Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * `GET /api/v1/finance/completed-sheets` — the read-only completed Cost Sheet list.
     *
     * Only sheets whose status is COMPLETED or COMPLETED_WITH_REJECTIONS are listed,
     * ordered `completed_at DESC, id DESC`, paginated with an authoritative `total`.
     */
    #[NoAdminRequired]
    public function completedSheets(int $page = 1, int $pageSize = 25): DataResponse
    {
        if (!$this->financeAccess->hasEffectiveFinanceAssignment()) {
            return $this->ocsErrorMapper->forbidden('Finance access is required');
        }

        try {
            $page = $this->completedSheetHistory->page($page, $pageSize);

            return new DataResponse($this->payloadFormatter->completedSheetPage($page));
        } catch (InvalidArgumentException $e) {
            return $this->validationFailure($e);
        } catch (Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * `GET /api/v1/finance/completed-sheets/{sheetId}` — read-only completed Cost Sheet detail.
     *
     * Returned only when the sheet is terminal and every transaction is terminal; otherwise 404.
     * The response carries no decision, mutation, or capability field.
     */
    #[NoAdminRequired]
    public function completedSheetDetail(string $sheetId): DataResponse
    {
        if (!$this->financeAccess->hasEffectiveFinanceAssignment()) {
            return $this->ocsErrorMapper->forbidden('Finance access is required');
        }

        try {
            $detail = $this->completedSheetHistory->detail($sheetId);
            if ($detail === null) {
                return $this->ocsErrorMapper->notFound('No completed Cost Sheet exists with this id');
            }

            return new DataResponse($this->payloadFormatter->completedSheetDetail($detail));
        } catch (Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * A display name for a holder that the ledger service has already proven owns an account.
     *
     * `UserDirectory::displayName()` already falls back to the uid when the platform has no usable
     * name; the second fallback keeps an empty string from reaching the wire. Resolution happens
     * here rather than in the formatter because the formatter must stay directory-free, and rather
     * than in the service because that would turn its not-found rule into a user-existence probe.
     */
    private function displayName(string $holderUid): string
    {
        $name = $this->userDirectory->displayName($holderUid);

        return $name !== '' ? $name : $holderUid;
    }

    /**
     * The service owns the bounds; this maps its message back to the query parameter that caused it
     * so the contract's `fieldErrors` names the field the caller must change.
     */
    private function validationFailure(InvalidArgumentException $e): DataResponse
    {
        $message = $e->getMessage();
        $field = match (true) {
            str_starts_with($message, 'pageSize') => 'pageSize',
            str_starts_with($message, 'search') => 'search',
            default => 'page',
        };

        return $this->ocsErrorMapper->validation($message, null, [$field => [$message]]);
    }
}
