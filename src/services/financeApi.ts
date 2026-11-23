import { ocs } from './http'
import type {
	CompletedSheetDetail,
	CompletedSheetSummary,
	FinanceHolderAccountsPage,
	FinanceLedgerPage,
	HolderSummary,
	Page
} from '../types/api'

/**
 * The additive Feature 008 Finance reads (`contracts/ocs-api.yaml` paths `/finance/...`).
 *
 * Every function is a plain read over the shared {@link ocs} client, so the error semantics are
 * exactly the server's: a 403 without an effective FINANCE assignment, a 404 for an unknown or
 * out-of-scope holder/account/sheet, and a 422 for invalid pagination arrive as a thrown
 * {@link ApiError} and are never converted into an empty-but-successful payload here. The actor's
 * identity travels in the authenticated session — there is no caller-supplied user parameter, and
 * no client-side authorization is attempted.
 *
 * ## The recharge boundary
 *
 * This module intentionally exposes **no recharge function**. The one financial write the Finance
 * module performs is the existing `POST /accounts/{accountId}/charges`, reused unchanged through
 * `chargeAccount()` in `services/accountsApi.ts` — same `AccountCharge` body, same
 * `X-PettyCash-Command-ID` idempotency header, same server-side idempotent replay. T027's recharge
 * flow composes that existing write; a second Finance-side charge path would create a second
 * financial workflow (FR-008-046).
 */

/** Shared pagination for every Finance read: `page` ≥ 1, `pageSize` 1–100, defaults 1/25. */
export interface FinanceListParams {
	page?: number
	pageSize?: number
}

/** Holder-directory parameters. `search` is trimmed before it is sent; empty means no filter. */
export interface HolderListParams extends FinanceListParams {
	search?: string
}

function listQuery(params: FinanceListParams): URLSearchParams {
	const query = new URLSearchParams()
	query.set('page', String(params.page ?? 1))
	query.set('pageSize', String(params.pageSize ?? 25))
	return query
}

/**
 * The paginated holder directory: users who own at least one petty-cash account, once each.
 *
 * `search` is matched server-side against the holder's `userId` or `displayName` across the
 * complete holder population — never page-locally — so the caller sends it verbatim (trimmed)
 * and the server decides scope; `total` counts all matching holders, not the page length.
 */
export async function listHolders(params: HolderListParams = {}): Promise<Page<HolderSummary>> {
	const query = listQuery(params)
	const search = params.search?.trim() ?? ''
	if (search !== '') query.set('search', search)
	return ocs<Page<HolderSummary>>(`/finance/holders?${query.toString()}`)
}

/** One holder's accounts, active and inactive, with the holder identity beside the page. */
export async function listHolderAccounts(
	holderUid: string,
	params: FinanceListParams = {}
): Promise<FinanceHolderAccountsPage> {
	const query = listQuery(params)
	return ocs<FinanceHolderAccountsPage>(
		`/finance/holders/${encodeURIComponent(holderUid)}/accounts?${query.toString()}`
	)
}

/**
 * One account's authoritative six-column ledger.
 *
 * The page carries the account summary beside the rows; every row's `occurredAt` and
 * `balanceAfter` are the persisted movement facts, so paging never changes the meaning of a
 * previously displayed balance and no client-side sum is ever computed.
 */
export async function fetchLedger(
	accountId: string,
	params: FinanceListParams = {}
): Promise<FinanceLedgerPage> {
	const query = listQuery(params)
	return ocs<FinanceLedgerPage>(
		`/finance/accounts/${encodeURIComponent(accountId)}/ledger?${query.toString()}`
	)
}

/** The paginated completed Cost Sheet history: terminal sheets only, `completedAt DESC`. */
export async function listCompletedSheets(params: FinanceListParams = {}): Promise<Page<CompletedSheetSummary>> {
	const query = listQuery(params)
	return ocs<Page<CompletedSheetSummary>>(`/finance/completed-sheets?${query.toString()}`)
}

/**
 * One completed Cost Sheet's read-only detail: transactions, stage decisions, evidence metadata,
 * and posting information. A sheet that is not terminal answers 404 — never a partial history.
 */
export async function getCompletedSheet(sheetId: string): Promise<CompletedSheetDetail> {
	return ocs<CompletedSheetDetail>(`/finance/completed-sheets/${encodeURIComponent(sheetId)}`)
}
