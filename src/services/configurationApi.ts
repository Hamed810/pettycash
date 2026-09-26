import { ocs } from './http'
import type { CostCenter, ExpenseType, Page } from '../types/api'

export interface ConfigurationListParams {
	page?: number
	pageSize?: number
}

function pageQuery(page: number, pageSize: number): string {
	const query = new URLSearchParams()
	query.set('page', String(page))
	query.set('pageSize', String(pageSize))
	return query.toString()
}

/**
 * The cost centers within the caller's view scope.
 *
 * Only active ones are requested, because a transaction is submitted against the cost center that is
 * in force on its expense date; an inactive center must not be offered. The scope is the server's
 * to decide, so nothing about the caller's role is sent.
 */
export async function listCostCenters(params: ConfigurationListParams = {}): Promise<Page<CostCenter>> {
	const { page = 1, pageSize = 100 } = params
	return ocs<Page<CostCenter>>(`/configuration/cost-centers?${pageQuery(page, pageSize)}&active=true`)
}

/**
 * The expense types, restricted to the active ones.
 *
 * `GET /configuration/expense-types` declares only `page` and `pageSize`, so the `active` filter is
 * applied to the returned page rather than asked for. FR-023 requires "one *active* expense type" at
 * submission, so a deactivated type must not be selectable.
 */
export async function listActiveExpenseTypes(params: ConfigurationListParams = {}): Promise<Page<ExpenseType>> {
	const { page = 1, pageSize = 100 } = params
	const response = await ocs<Page<ExpenseType>>(`/configuration/expense-types?${pageQuery(page, pageSize)}`)
	return { ...response, items: response.items.filter((type) => type.active) }
}
