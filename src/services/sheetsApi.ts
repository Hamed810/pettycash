import { ocs, mutate, commandId } from './http'
import type {
	SheetCreate,
	SheetSummary,
	SheetDetail,
	Page,
	CommandOptions
} from '../types/api'

export interface SheetFilters {
	status?: string
	ownerId?: string
	currency?: string
	from?: string
	to?: string
}

export interface SheetPageParams {
	page?: number
	pageSize?: number
	filters?: SheetFilters
}

export async function listSheets(params: SheetPageParams = {}): Promise<Page<SheetSummary>> {
	const { page = 1, pageSize = 25, filters = {} } = params
	const query = new URLSearchParams()
	query.set('page', String(page))
	query.set('pageSize', String(pageSize))
	if (filters.status) query.set('status', filters.status)
	if (filters.ownerId) query.set('ownerId', filters.ownerId)
	if (filters.currency) query.set('currency', filters.currency)
	if (filters.from) query.set('from', filters.from)
	if (filters.to) query.set('to', filters.to)
	return ocs(`/sheets?${query.toString()}`)
}

export async function createSheet(data: SheetCreate, options: CommandOptions = {}): Promise<SheetDetail> {
	return mutate<SheetDetail>('/sheets', 'POST', data, options.commandId ?? commandId())
}

export async function getSheet(sheetId: string): Promise<SheetDetail> {
	return ocs(`/sheets/${sheetId}`)
}

export async function updateSheet(
	sheetId: string,
	data: SheetCreate,
	expectedVersion: number,
	options: CommandOptions = {}
): Promise<SheetDetail> {
	return mutate<SheetDetail>(`/sheets/${sheetId}`, 'PATCH', { ...data, expectedVersion }, options.commandId ?? commandId())
}

export async function submitSheet(sheetId: string, expectedVersion: number, options: CommandOptions = {}): Promise<SheetDetail> {
	return mutate<SheetDetail>(`/sheets/${sheetId}/submit`, 'POST', { expectedVersion }, options.commandId ?? commandId())
}