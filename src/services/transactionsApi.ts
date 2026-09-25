import { ocs, mutate, commandId } from './http'
import type {
	TransactionDraftWrite,
	TransactionSummary,
	TransactionDetail,
	Revision,
	Page,
	CommandOptions
} from '../types/api'

export interface TransactionFilters {
	status?: string
	costCenterId?: string
	expenseTypeId?: string
	dateFrom?: string
	dateTo?: string
	amountMin?: number
	amountMax?: number
}

export interface TransactionPageParams {
	page?: number
	pageSize?: number
	filters?: TransactionFilters
}

export async function listTransactions(sheetId: string, params: TransactionPageParams = {}): Promise<Page<TransactionSummary>> {
	const { page = 1, pageSize = 25, filters = {} } = params
	const query = new URLSearchParams()
	query.set('page', String(page))
	query.set('pageSize', String(pageSize))
	if (filters.status) query.set('status', filters.status)
	if (filters.costCenterId) query.set('costCenterId', filters.costCenterId)
	if (filters.expenseTypeId) query.set('expenseTypeId', filters.expenseTypeId)
	if (filters.dateFrom) query.set('dateFrom', filters.dateFrom)
	if (filters.dateTo) query.set('dateTo', filters.dateTo)
	if (filters.amountMin !== undefined) query.set('amountMin', String(filters.amountMin))
	if (filters.amountMax !== undefined) query.set('amountMax', String(filters.amountMax))
	return ocs(`/sheets/${sheetId}/transactions?${query.toString()}`)
}

export async function createTransaction(
	sheetId: string,
	data: TransactionDraftWrite,
	options: CommandOptions = {}
): Promise<TransactionDetail> {
	return mutate<TransactionDetail>(`/sheets/${sheetId}/transactions`, 'POST', data, options.commandId ?? commandId())
}

export async function getTransaction(transactionId: string): Promise<TransactionDetail> {
	return ocs(`/transactions/${transactionId}`)
}

export async function updateTransaction(
	transactionId: string,
	data: TransactionDraftWrite,
	expectedVersion: number,
	options: CommandOptions = {}
): Promise<TransactionDetail> {
	return mutate<TransactionDetail>(`/transactions/${transactionId}`, 'PATCH', { ...data, expectedVersion }, options.commandId ?? commandId())
}

export async function deleteTransaction(transactionId: string, expectedVersion: number, options: CommandOptions = {}): Promise<void> {
	await mutate<void>(`/transactions/${transactionId}`, 'DELETE', { expectedVersion }, options.commandId ?? commandId())
}

export async function submitTransactionDecision(
	transactionId: string,
	decision: 'APPROVE' | 'REJECT' | 'RETURN',
	explanation: string | undefined,
	expectedVersion: number,
	options: CommandOptions = {}
): Promise<TransactionDetail> {
	return mutate<TransactionDetail>(`/transactions/${transactionId}/decisions`, 'POST', { decision, explanation, expectedVersion }, options.commandId ?? commandId())
}

export async function createCorrection(transactionId: string, options: CommandOptions = {}): Promise<Revision> {
	return mutate<Revision>(`/transactions/${transactionId}/correction`, 'POST', {}, options.commandId ?? commandId())
}

export async function resubmitCorrection(transactionId: string, expectedVersion: number, options: CommandOptions = {}): Promise<TransactionDetail> {
	return mutate<TransactionDetail>(`/transactions/${transactionId}/resubmit`, 'POST', { expectedVersion }, options.commandId ?? commandId())
}

export async function listRevisions(transactionId: string): Promise<Revision[]> {
	return ocs(`/transactions/${transactionId}/revisions`)
}

export async function listAudit(transactionId: string, page = 1, pageSize = 25): Promise<Page<unknown>> {
	const query = new URLSearchParams()
	query.set('page', String(page))
	query.set('pageSize', String(pageSize))
	return ocs(`/transactions/${transactionId}/audit?${query.toString()}`)
}