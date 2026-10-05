import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, type ExportRequest } from '@/types/api'
import {
	REPORT_EXPORT_FORMATS,
	REPORT_EXPORT_RESOURCE,
	REPORT_TYPES,
	type ExportFormat,
	type ReportFilters,
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
			return {
				// The artifact, decoded out of the OCS envelope and re-typed by the server's own
				// `mediaType`. Returning the envelope bytes would save a file whose contents are JSON.
				blob: exportArtifactBlob(result),
				format,
				filename: exportFilename(format),
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

	function exportFilename(format: ExportFormat): string {
		return `pettycash-${reportType.value}-${new Date().toISOString().slice(0, 10)}.${EXPORT_MEDIA_EXTENSION[format]}`
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
