import { ocs } from './http'
import type { Account, Page } from '../types/api'

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
