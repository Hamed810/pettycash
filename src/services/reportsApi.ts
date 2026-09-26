import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { isAxiosError } from 'axios'
import { ApiError, type ApiErrorBody, type ExportRequest } from '@/types/api'
import type { ReportPage, ReportPageParams, ReportType } from '@/types/reporting'
import { commandId, ocs } from './http'

/**
 * `contracts/ocs-api.yaml` declares `page` with `minimum: 1, default: 1` and `pageSize` with
 * `minimum: 1, maximum: 100, default: 25`. The client clamps to the same window instead of
 * sending a value the server must reject.
 */
export const REPORT_PAGE_SIZE_MIN = 1
export const REPORT_PAGE_SIZE_MAX = 100
export const REPORT_PAGE_SIZE_DEFAULT = 25

export function clampPageSize(pageSize: number | undefined): number {
	if (pageSize === undefined || !Number.isFinite(pageSize)) return REPORT_PAGE_SIZE_DEFAULT
	return Math.min(REPORT_PAGE_SIZE_MAX, Math.max(REPORT_PAGE_SIZE_MIN, Math.trunc(pageSize)))
}

export function clampPage(page: number | undefined): number {
	if (page === undefined || !Number.isFinite(page)) return 1
	return Math.max(1, Math.trunc(page))
}

/**
 * Builds the report query string. `contracts/ocs-api.yaml` describes the filter object as
 * "report-specific filter object encoded as query parameters by the client", so each populated
 * filter becomes its own parameter under the same name used by `ReportFilters`; empty, `null`, and
 * `undefined` values are omitted so an unfilled field can never narrow a result set.
 */
export function reportQuery(params: ReportPageParams = {}): URLSearchParams {
	const query = new URLSearchParams()
	query.set('page', String(clampPage(params.page)))
	query.set('pageSize', String(clampPageSize(params.pageSize)))
	for (const [name, value] of Object.entries(params.filters ?? {})) {
		if (value === undefined || value === null || value === '') continue
		query.set(name, String(value))
	}
	return query
}

export async function fetchReport(reportType: ReportType, params: ReportPageParams = {}): Promise<ReportPage> {
	return ocs<ReportPage>(`/reports/${reportType}?${reportQuery(params).toString()}`)
}

function exportErrorBody(status: number): ApiErrorBody {
	return status === 0
		? { code: 'NETWORK_FAILURE', message: 'pettycash.error.network' }
		: { code: 'EXPORT_FAILED', message: 'pettycash.error.export' }
}

function asApiError(error: unknown): ApiError {
	if (error instanceof ApiError) return error
	if (isAxiosError(error)) {
		const status = error.response?.status ?? 0
		return new ApiError(status, exportErrorBody(status))
	}
	return new ApiError(0, {
		code: 'EXPORT_FAILED',
		message: error instanceof Error ? error.message : 'pettycash.error.export'
	})
}

/**
 * FR-066 requests an export of the permitted report/sheets/transactions/audit/evidence in the
 * supported format. `POST /exports` streams the artifact, so the OCS `data` envelope the JSON
 * helper unwraps does not apply and the raw response body is returned instead. The client-generated
 * command UUID is still sent, so a retried export replays the original artifact per FR-042.
 */
export async function requestExport(body: ExportRequest, id: string = commandId()): Promise<Blob> {
	try {
		const response = await axios.post<Blob>(generateOcsUrl('/apps/pettycash/api/v1/exports'), body, {
			headers: { 'OCS-APIRequest': 'true', 'X-PettyCash-Command-ID': id },
			responseType: 'blob',
		})
		return response.data
	} catch (error: unknown) {
		throw asApiError(error)
	}
}
