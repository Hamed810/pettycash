import { ocs, mutate, commandId } from './http'
import type {
	CostCenter,
	CostCenterWrite,
	Currency,
	CurrencyWrite,
	ExpenseType,
	ExpenseTypeWrite,
	FinanceAssignment,
	FinanceAssignmentWrite,
	Page,
	CommandOptions
} from '../types/api'

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

/**
 * T088: the administrator's write surface for reference data, one function per endpoint.
 *
 * Each create takes the contract's `*Write` shape and each update takes it plus the
 * optimistic-lock version the server requires — the same `mutate()` + `CommandOptions` shape
 * `sheetsApi.updateSheet()` uses, so a stale write is refused as a conflict the caller recovers
 * from by reloading rather than applied to a row that moved on.
 *
 * `CurrencyId` is the code itself (T081), cost centers and finance assignments are addressed by
 * public UUID, and expense types by title — three different identity rules the server owns, so the
 * functions take the identifier each endpoint declares rather than normalising them.
 */
export async function listCurrencies(params: ConfigurationListParams = {}): Promise<Page<Currency>> {
	const { page = 1, pageSize = 100 } = params
	return ocs<Page<Currency>>(`/configuration/currencies?${pageQuery(page, pageSize)}`)
}

export async function createCurrency(data: CurrencyWrite, options: CommandOptions = {}): Promise<Currency> {
	return mutate<Currency>('/configuration/currencies', 'POST', data, options.commandId ?? commandId())
}

export async function updateCurrency(
	code: string,
	data: CurrencyWrite,
	expectedVersion: number,
	options: CommandOptions = {}
): Promise<Currency> {
	return mutate<Currency>(
		`/configuration/currencies/${encodeURIComponent(code)}`,
		'PATCH',
		{ ...data, expectedVersion },
		options.commandId ?? commandId()
	)
}

export async function createCostCenter(data: CostCenterWrite, options: CommandOptions = {}): Promise<CostCenter> {
	return mutate<CostCenter>('/configuration/cost-centers', 'POST', data, options.commandId ?? commandId())
}

export async function updateCostCenter(
	costCenterId: string,
	data: CostCenterWrite,
	expectedVersion: number,
	options: CommandOptions = {}
): Promise<CostCenter> {
	return mutate<CostCenter>(
		`/configuration/cost-centers/${encodeURIComponent(costCenterId)}`,
		'PATCH',
		{ ...data, expectedVersion },
		options.commandId ?? commandId()
	)
}

export async function listExpenseTypes(params: ConfigurationListParams = {}): Promise<Page<ExpenseType>> {
	const { page = 1, pageSize = 100 } = params
	return ocs<Page<ExpenseType>>(`/configuration/expense-types?${pageQuery(page, pageSize)}`)
}

export async function createExpenseType(data: ExpenseTypeWrite, options: CommandOptions = {}): Promise<ExpenseType> {
	return mutate<ExpenseType>('/configuration/expense-types', 'POST', data, options.commandId ?? commandId())
}

export async function updateExpenseType(
	title: string,
	data: ExpenseTypeWrite,
	expectedVersion: number,
	options: CommandOptions = {}
): Promise<ExpenseType> {
	return mutate<ExpenseType>(
		`/configuration/expense-types/${encodeURIComponent(title)}`,
		'PATCH',
		{ ...data, expectedVersion },
		options.commandId ?? commandId()
	)
}

export async function listFinanceAssignments(params: ConfigurationListParams = {}): Promise<Page<FinanceAssignment>> {
	const { page = 1, pageSize = 100 } = params
	return ocs<Page<FinanceAssignment>>(`/configuration/finance-assignments?${pageQuery(page, pageSize)}`)
}

export async function createFinanceAssignment(
	data: FinanceAssignmentWrite,
	options: CommandOptions = {}
): Promise<FinanceAssignment> {
	return mutate<FinanceAssignment>(
		'/configuration/finance-assignments',
		'POST',
		data,
		options.commandId ?? commandId()
	)
}
