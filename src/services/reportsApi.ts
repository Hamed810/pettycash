import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { isAxiosError } from 'axios'
import { ApiError, type ApiErrorBody, type ApiErrorEnvelope, type ExportRequest, type ExportResult } from '@/types/api'
import type { ReportFilters, ReportPage, ReportPageParams, ReportType } from '@/types/reporting'
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
 * Builds the report query string.
 *
 * ## The whole filter object travels as one `filters` parameter
 *
 * The endpoint binds a single `string $filters = ''` (`lib/Controller/ReportController.php`) and
 * JSON-decodes it before handing it to `ReportFilterFactory`. It does not read one query parameter per
 * filter, and it has never done so.
 *
 * This function used to write each populated filter as its own parameter, on the reasoning that
 * `contracts/ocs-api.yaml` describes the filter object as "encoded as query parameters by the client".
 * Read as an instruction to spread the object across the query string, that is what it did — and the
 * effect was that `$filters` was always `''`, `ReportController` returned an unfiltered
 * `new ReportFilter($page, $pageSize)`, and the user was shown the whole report under a filter bar they
 * had just filled in. The contract sentence fixes the *shape* (a bare string parameter) and leaves the
 * encoding to the client; JSON is the one encoding a bare string carries unambiguously.
 *
 * An empty filter object is omitted rather than sent as `{}`. `json_decode('{}', true)` is `[]` in
 * PHP, which `ReportFilterFactory` has to special-case, so not sending it at all is both smaller and
 * one fewer edge to get wrong.
 */
export function reportQuery(params: ReportPageParams = {}): URLSearchParams {
	const query = new URLSearchParams()
	query.set('page', String(clampPage(params.page)))
	query.set('pageSize', String(clampPageSize(params.pageSize)))

	const filters = pruneFilters(params.filters)
	if (Object.keys(filters).length > 0) {
		query.set('filters', JSON.stringify(filters))
	}

	return query
}

/**
 * Drops the entries the user did not fill in.
 *
 * An empty list, an empty string and `undefined` all mean "no filter". Sending them is not harmless:
 * `{"statuses":[]}` is a recognised key with a value the filter treats as matching nothing, and
 * `{"from":""}` is a date the server must reject. A partially filled form must narrow by what is in it
 * and nothing else.
 *
 * A list is kept when it has at least one non-empty member, so a multi-select the user cleared is
 * dropped rather than sent as `[]`.
 */
export function pruneFilters(filters: ReportFilters = {}): ReportFilters {
	const pruned: Record<string, unknown> = {}

	for (const [name, value] of Object.entries(filters)) {
		if (value === undefined || value === null || value === '') continue

		if (Array.isArray(value)) {
			const members = value.filter((member) => member !== undefined && member !== null && member !== '')
			if (members.length > 0) pruned[name] = members
			continue
		}

		pruned[name] = value
	}

	return pruned as ReportFilters
}

export async function fetchReport(reportType: ReportType, params: ReportPageParams = {}): Promise<ReportPage> {
	return ocs<ReportPage>(`/reports/${reportType}?${reportQuery(params).toString()}`)
}

function exportErrorBody(status: number): ApiErrorBody {
	return status === 0
		? { code: 'NETWORK_FAILURE', message: 'pettycash.error.network' }
		: { code: 'EXPORT_FAILED', message: 'pettycash.error.export' }
}

/** The `ErrorEnvelope` a refusal arrives in, when the response really carries one (T334). */
function errorEnvelopeOf(error: unknown): ApiErrorBody | undefined {
	if (!isAxiosError(error)) return undefined
	const data = error.response?.data as { ocs?: { data?: ApiErrorEnvelope } } | undefined

	return data?.ocs?.data?.error
}

function asApiError(error: unknown): ApiError {
	if (error instanceof ApiError) return error
	if (isAxiosError(error)) {
		const status = error.response?.status ?? 0
		// The server's own body when there is one, because it says two things the synthetic body
		// cannot: `message` names what was refused, and `fieldErrors` names *which field*. Replacing
		// every refusal with `EXPORT_FAILED` is what left a `422` about `fileFormat` reaching the
		// user as "export failed" — a refusal the form could have placed next to the control the
		// user was looking at (T334).
		const envelope = errorEnvelopeOf(error)
		if (envelope?.code) return new ApiError(status, envelope)

		return new ApiError(status, exportErrorBody(status))
	}
	return new ApiError(0, {
		code: 'EXPORT_FAILED',
		message: error instanceof Error ? error.message : 'pettycash.error.export'
	})
}

/**
 * FR-066 requests an export of the permitted report/sheets/transactions/audit/evidence in the
 * supported format.
 *
 * **The artifact does not arrive as the response body, and this decodes it rather than saving the
 * envelope.** `ExportController` returns the contract's `ExportResult` with the artifact base64-encoded
 * in `data`, because Nextcloud's OCS layer wraps every body as JSON and cannot return raw bytes. An
 * earlier version of this function requested `responseType: 'blob'` and returned `response.data`
 * unchanged on the reasoning that the endpoint "streams the artifact" — which the controller's own
 * comment contradicts. The saved file would have been named `pettycash-sheets-<date>.csv` and contained
 * `{"ocs":{"data":{…,"data":"<base64>"}}}`.
 *
 * `atob` is used rather than a Buffer shim because this runs in the browser, and its output is turned
 * back into bytes explicitly: `atob` yields one binary string per character, and handing that to `Blob`
 * would encode each character's code point as UTF-8 — which corrupts every byte above 0x7F and turns a
 * Persian CSV into mojibake. The byte array is what makes the artifact byte-identical to what the
 * exporter wrote, which is the whole point of the exercise.
 *
 * The client-generated command UUID is still sent, so a retried export replays the original artifact
 * per FR-042.
 */
export async function requestExport(body: ExportRequest, id: string = commandId()): Promise<ExportResult> {
	try {
		const response = await axios.post<{ ocs?: { data?: ExportResult } }>(
			generateOcsUrl('/apps/pettycash/api/v1/exports'),
			body,
			{
				headers: { 'OCS-APIRequest': 'true', 'X-PettyCash-Command-ID': id },
			}
		)

		const result = response?.data?.ocs?.data
		if (!result || typeof result.data !== 'string') {
			throw new ApiError(response?.status ?? 0, {
				code: 'MALFORMED_RESPONSE',
				message: 'pettycash.error.malformedResponse',
			})
		}

		return result
	} catch (error: unknown) {
		throw asApiError(error)
	}
}

/**
 * The decoded artifact as a `Blob` typed by the server's own `mediaType`.
 *
 * Kept beside `requestExport` rather than in the store so the byte-level concern — and the UTF-8 trap
 * above — has one owner, and so a test can assert the bytes without a store or a component.
 */
export function exportArtifactBlob(result: ExportResult): Blob {
	const binary = atob(result.data)
	const bytes = new Uint8Array(binary.length)
	for (let i = 0; i < binary.length; i++) {
		bytes[i] = binary.charCodeAt(i)
	}

	return new Blob([bytes], { type: result.mediaType })
}
