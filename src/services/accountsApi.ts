import { ocs } from './http'
import type { Account, Movement, Page } from '../types/api'

export interface AccountListParams {
	page?: number
	pageSize?: number
}

/**
 * The authenticated caller's own petty-cash accounts.
 *
 * The server scopes this to the caller, so no user identifier is ever sent: the endpoint refuses a
 * `userId` naming anybody else. `minorUnit` travels with each balance so the UI formats at the
 * currency's configured scale instead of assuming rial.
 */
export async function listAccounts(params: AccountListParams = {}): Promise<Page<Account>> {
	const { page = 1, pageSize = 100 } = params
	const query = new URLSearchParams()
	query.set('page', String(page))
	query.set('pageSize', String(pageSize))
	return ocs<Page<Account>>(`/accounts?${query.toString()}`)
}

/**
 * One account's ledger: the charges that funded it and the approved consumptions against it.
 *
 * `minorUnit` travels with every amount on the page — on the movement and on the balance it leaves —
 * so the view formats each at the currency's configured scale rather than assuming rial. The
 * endpoint answers 404 for an account that is not the caller's, so there is no filter to apply here
 * and no `userId` to send.
 */
export async function listMovements(
	accountId: string,
	params: AccountListParams = {}
): Promise<Page<Movement>> {
	const { page = 1, pageSize = 25 } = params
	const query = new URLSearchParams()
	query.set('page', String(page))
	query.set('pageSize', String(pageSize))
	return ocs<Page<Movement>>(`/accounts/${encodeURIComponent(accountId)}/movements?${query.toString()}`)
}
