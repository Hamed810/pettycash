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

/**
 * `DELETE /sheets/{id}` — FR-086: delete an own sheet that has **never been submitted**.
 *
 * **The command id is sent, and unlike `deleteTransaction` it is required.** FR-084 and FR-086 state that this
 * operation is replay-guarded: a sheet delete without a gate would reach the server, find nothing on the retry,
 * and answer "not found" — leaving a client that lost the first response unable to tell its own successful
 * deletion from a failure. So the caller must supply `options.commandId` and should keep it across retries; the
 * `?? commandId()` fallback is a convenience for the common single-shot case, not a licence to retry with a
 * fresh id.
 *
 * Answers `204` with no body, so this returns `void` rather than a sheet — the caller reloads the list, which
 * is also what proves the sheet is really gone.
 */
export async function deleteSheet(
	sheetId: string,
	expectedVersion: number,
	options: CommandOptions = {}
): Promise<void> {
	await mutate<void>(`/sheets/${sheetId}`, 'DELETE', { expectedVersion }, options.commandId ?? commandId())
}