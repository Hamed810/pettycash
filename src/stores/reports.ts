import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, type ExportRequest } from '@/types/api'
import {
	REPORT_EXPORT_FORMATS,
	REPORT_EXPORT_RESOURCE,
	REPORT_TYPES,
	readPeriodDisclosure,
	type ExportFormat,
	type ReportFilters,
	type ReportPeriodDisclosure,
	type ReportRow,
	type ReportType,
} from '@/types/reporting'
import {
	REPORT_PAGE_SIZE_DEFAULT,
	clampPage,
	clampPageSize,
	exportArtifactBlob,
	fetchReport,
	requestExport,
} from '@/services/reportsApi'

export interface ReportLoadOptions {
	reportType?: ReportType
	page?: number
	pageSize?: number
	filters?: ReportFilters
}

export interface DownloadableExport {
	blob: Blob
	filename: string
	format: ExportFormat
	/**
	 * FR-068: the period this artifact covers, and the range and zone it resolved to — or `null` when the
	 * request carried no period.
	 *
	 * **This was the discard.** `POST /exports` returns `period`, `resolvedFrom`, `resolvedTo` and
	 * `resolvedTimeZone`, and `exportReport()` read the response for exactly one thing — the artifact bytes
	 * — so all four were dropped here and the file reached the user naming neither the period nor the zone.
	 * An export whose numbers cannot be reconciled against another user's is not a smaller version of the
	 * feature, so the disclosure travels with the artifact rather than being left on the wire.
	 */
	disclosure: ReportPeriodDisclosure | null
}

const EXPORT_MEDIA_EXTENSION: Record<ExportFormat, string> = {
	csv: 'csv',
	xlsx: 'xlsx',
	pdf: 'pdf',
	zip: 'zip',
}

export const useReportsStore = defineStore('reports', () => {
	const reportType = ref<ReportType>('sheets')
	const columns = ref<string[]>([])
	const rows = ref<ReportRow[]>([])
	const summary = ref<Record<string, unknown>>({})
	const page = ref(1)
	const pageSize = ref(REPORT_PAGE_SIZE_DEFAULT)
	const total = ref(0)
	const filters = ref<ReportFilters>({})
	const loading = ref(false)
	const error = ref<string | null>(null)
	const exporting = ref(false)
	const exportError = ref<string | null>(null)
	/**
	 * `fieldErrors` from a refused export, keyed by the field the server named.
	 *
	 * `Error.fieldErrors` is the payload FR-023/FR-064 validation messages are read from, and it
	 * arrives at `ocs.data.error.fieldErrors` (T334). It is kept beside `exportError` rather than
	 * folded into that string: `exportError` answers *why* the request was refused, this answers
	 * *which* of the fields the caller sent was unacceptable, and a form can only place the second
	 * next to its input.
	 */
	const exportFieldErrors = ref<Record<string, string[]>>({})
	/**
	 * FR-068's disclosure for the export most recently produced, or `null`.
	 *
	 * Held here rather than only on the returned `DownloadableExport` because the caller — `ReportView` —
	 * triggers a download and has nothing left to render afterwards; the disclosure has to survive the
	 * click. Reset when a new export starts, so the screen cannot pair a fresh download with a stale
	 * period.
	 */
	const lastExportDisclosure = ref<ReportPeriodDisclosure | null>(null)

	const reportTypes = REPORT_TYPES
	const availableFormats = computed<ExportFormat[]>(() => [...REPORT_EXPORT_FORMATS[reportType.value]])
	const hasRows = computed(() => rows.value.length > 0)
	const isEmpty = computed(() => !loading.value && !error.value && rows.value.length === 0)
	const totalPages = computed(() => (total.value <= 0 ? 1 : Math.ceil(total.value / pageSize.value)))
	const canGoPrevious = computed(() => page.value > 1)
	const canGoNext = computed(() => page.value < totalPages.value)
	const hasFilters = computed(() => Object.entries(filters.value).some(([, value]) => value !== undefined && value !== ''))

	function clearResult() {
		columns.value = []
		rows.value = []
		summary.value = {}
		total.value = 0
	}

	/**
	 * FR-075: a failed read is reported and left for the user to retry. Nothing here re-issues the
	 * request, and a failure clears the previous result so stale rows are never presented as current.
	 */
	async function loadReport(options: ReportLoadOptions = {}): Promise<void> {
		if (options.reportType) reportType.value = options.reportType
		if (options.filters) filters.value = options.filters
		if (options.pageSize !== undefined) pageSize.value = clampPageSize(options.pageSize)
		page.value = clampPage(options.page ?? page.value)
		loading.value = true
		error.value = null
		try {
			const result = await fetchReport(reportType.value, {
				page: page.value,
				pageSize: pageSize.value,
				filters: filters.value,
			})
			columns.value = result.columns ?? []
			rows.value = result.rows ?? []
			summary.value = result.summary ?? {}
			page.value = clampPage(result.page ?? page.value)
			pageSize.value = clampPageSize(result.pageSize ?? pageSize.value)
			total.value = result.total ?? rows.value.length
		} catch (e: unknown) {
			error.value = e instanceof Error ? e.message : 'pettycash.error.network'
			page.value = clampPage(page.value)
			clearResult()
			throw e
		} finally {
			loading.value = false
		}
	}

	/**
	 * A report subject accepts its own filter set, so switching subject discards the previous
	 * subject's filters rather than sending fields the new subject does not define.
	 */
	async function selectReportType(next: ReportType): Promise<void> {
		await loadReport({ reportType: next, page: 1, filters: {} })
	}

	async function goToPage(next: number): Promise<void> {
		await loadReport({ page: clampPage(next) })
	}

	async function applyFilters(next: ReportFilters): Promise<void> {
		await loadReport({ filters: next, page: 1 })
	}

	async function resetFilters(): Promise<void> {
		await loadReport({ filters: {}, page: 1 })
	}

	function setPageSize(next: number): Promise<void> {
		return loadReport({ pageSize: next, page: 1 })
	}

	function clearError(): void {
		error.value = null
	}

	function clearExportError(): void {
		exportError.value = null
		exportFieldErrors.value = {}
	}

	/**
	 * FR-066/FR-068: export the current report subject with the current filters. A format the
	 * subject does not support is refused instead of being swapped for a supported one, so the
	 * artifact the user receives is the one they asked for or none at all.
	 */
	async function exportReport(format: ExportFormat): Promise<DownloadableExport> {
		if (!availableFormats.value.includes(format)) {
			throw new Error(`pettycash.error.unsupportedFormat:${format}`)
		}
		exporting.value = true
		exportError.value = null
		exportFieldErrors.value = {}
		// Cleared at the *start* of a request, not on its success: an export that fails leaves no disclosure
		// behind, so the screen never shows the previous file's period while a new attempt is in flight.
		lastExportDisclosure.value = null
		try {
			const body: ExportRequest = {
				resource: REPORT_EXPORT_RESOURCE[reportType.value],
				// `fileFormat`, not `format`: the endpoint binds `fileFormat`, and Nextcloud's OCS layer
				// reserves a request parameter called `format` for envelope selection. Sending `format`
				// made every export a `422` with `fieldErrors.fileFormat` — see `ExportRequest`.
				fileFormat: format,
				reportType: reportType.value,
				filters: filters.value,
			}
			const result = await requestExport(body)
			// Read before the artifact is unwrapped, because `exportArtifactBlob` is what consumes the
			// base64 payload and the disclosure sits beside it in the same response.
			const disclosure = readPeriodDisclosure(result)
			lastExportDisclosure.value = disclosure
			return {
				// The artifact, decoded out of the OCS envelope and re-typed by the server's own
				// `mediaType`. Returning the envelope bytes would save a file whose contents are JSON.
				blob: exportArtifactBlob(result),
				format,
				filename: exportFilename(format, disclosure),
				disclosure,
			}
		} catch (e: unknown) {
			exportError.value = e instanceof Error ? e.message : 'pettycash.error.export'
			// Read straight off the envelope rather than parsed out of the message: the server names
			// the offending field and this is the only place that name arrives intact.
			exportFieldErrors.value = e instanceof ApiError ? { ...e.body.fieldErrors } : {}
			throw e
		} finally {
			exporting.value = false
		}
	}

	/**
	 * The filename names **what the file covers**, not when it was downloaded.
	 *
	 * With a period resolved, the server's own boundaries go in the name — `month-2026-07-01_2026-07-30`.
	 * Two users exporting the same period get the same filename regardless of when they pressed the
	 * button, which is what makes two files comparable once they are attached to the same reconciliation.
	 *
	 * Without a period there is nothing to state, so today's date stands in: naming a range the request
	 * never used would be the same class of error as sending a filter the server refuses, and this one
	 * would be *in the filename of a financial artifact*.
	 *
	 * **The zone is deliberately not in the filename.** A zone name contains `/` (`Asia/Tehran`), which is
	 * a path separator — a filename carrying one either becomes a directory or is silently rewritten by the
	 * browser's download handling. The zone travels in the disclosure rendered beside the download, where
	 * it is readable and cannot become a path. `resolvedFrom`/`resolvedTo` are `Y-m-d` by contract, so
	 * neither needs sanitising.
	 */
	function exportFilename(format: ExportFormat, disclosure: ReportPeriodDisclosure | null): string {
		const { resolvedFrom, resolvedTo } = disclosure ?? {}

		const coverage = resolvedFrom && resolvedTo
			? `${disclosure?.period}-${resolvedFrom}_${resolvedTo}`
			: new Date().toISOString().slice(0, 10)

		return `pettycash-${reportType.value}-${coverage}.${EXPORT_MEDIA_EXTENSION[format]}`
	}

	return {
		reportType,
		reportTypes,
		columns,
		rows,
		summary,
		page,
		pageSize,
		total,
		filters,
		loading,
		error,
		exporting,
		exportError,
		exportFieldErrors,
		lastExportDisclosure,
		availableFormats,
		hasRows,
		isEmpty,
		totalPages,
		canGoPrevious,
		canGoNext,
		hasFilters,
		loadReport,
		selectReportType,
		goToPage,
		applyFilters,
		resetFilters,
		setPageSize,
		clearError,
		clearExportError,
		exportReport,
		exportFilename,
	}
})
