import type { ExportRequest } from './api'

/**
 * Report subjects from `contracts/ocs-api.yaml` (`/reports/{reportType}`). The set is fixed by the
 * contract: a client that invented a subject would request a report the server does not scope.
 */
export type ReportType =
	| 'sheets'
	| 'transactions'
	| 'cost-centers'
	| 'accounts'
	| 'process'
	| 'evidence'
	| 'audit'

export const REPORT_TYPES: readonly ReportType[] = [
	'sheets',
	'transactions',
	'cost-centers',
	'accounts',
	'process',
	'evidence',
	'audit',
]

export type ExportFormat = ExportRequest['format']

/**
 * FR-068 group-export reporting periods: the predefined calendar periods plus a custom range.
 * There is no fiscal calendar in V1, so `custom` means the explicit `dateFrom`/`dateTo` pair.
 */
export type ReportPeriodPreset = 'month' | 'quarter' | 'year' | 'custom'

export const REPORT_PERIOD_PRESETS: readonly ReportPeriodPreset[] = ['month', 'quarter', 'year', 'custom']

/**
 * FR-064 and FR-065 filters. Which filters apply depends on the report subject, so every field is
 * optional and only the fields the user actually filled in are sent (see `reportQuery`).
 */
export interface ReportFilters {
	status?: string
	ownerId?: string
	costCenterId?: string
	expenseTypeId?: string
	currency?: string
	actorId?: string
	stage?: string
	eventType?: string
	period?: ReportPeriodPreset
	dateFrom?: string
	dateTo?: string
	amountMin?: number
	amountMax?: number
}

export type ReportRow = Record<string, unknown>

/** `ReportPage` from `contracts/ocs-api.yaml`: page metadata plus `columns`, `rows`, `summary`. */
export interface ReportPage {
	page?: number
	pageSize?: number
	total?: number
	columns?: string[]
	rows?: ReportRow[]
	summary?: Record<string, unknown>
}

export interface ReportPageParams {
	page?: number
	pageSize?: number
	filters?: ReportFilters
}

/**
 * FR-066 exports permitted reports in the formats `contracts/exports.md` assigns to them. Group
 * evidence is the ZIP subject, so only the evidence report offers `zip`; the tabular and history
 * subjects offer the three document formats. The client never offers a format the subject does not
 * have, and never silently substitutes one.
 */
export const REPORT_EXPORT_FORMATS: Record<ReportType, readonly ExportFormat[]> = {
	sheets: ['csv', 'xlsx', 'pdf'],
	transactions: ['csv', 'xlsx', 'pdf'],
	'cost-centers': ['csv', 'xlsx', 'pdf'],
	accounts: ['csv', 'xlsx', 'pdf'],
	process: ['csv', 'xlsx', 'pdf'],
	audit: ['csv', 'xlsx', 'pdf'],
	evidence: ['csv', 'zip'],
}

/**
 * Maps a report subject onto the export `resource` the contract declares. Only the four subjects
 * that have their own resource name are mapped; the rest are exported through the generic `report`
 * resource, which is how a cost-center, account, or process report is requested.
 */
export const REPORT_EXPORT_RESOURCE: Record<ReportType, ExportRequest['resource']> = {
	sheets: 'sheets',
	transactions: 'transactions',
	evidence: 'evidence',
	audit: 'audit',
	'cost-centers': 'report',
	accounts: 'report',
	process: 'report',
}
