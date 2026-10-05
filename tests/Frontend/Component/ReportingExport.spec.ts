import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory } from 'vue-router'

// `generateOcsUrl` only prefixes the OCS base path; the caller supplies the app route, exactly as
// the real helper does, so the requested URL can be asserted end to end.
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php${path}`),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`),
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: vi.fn(),
		post: vi.fn()
	}
}))

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((app: string, key: string, vars?: Record<string, string>) =>
		vars ? `${key} ${Object.values(vars).join(' ')}` : key),
	n: vi.fn((key: string) => key),
	getLanguage: vi.fn(() => 'fa'),
	isRTL: vi.fn(() => true)
}))

// The reporting components import the Nextcloud components by their deep specifiers, so each one is
// mocked at that specifier. The mocks keep the props the components bind and let every undeclared
// attribute (`data-testid`) fall through to the root element.
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading', 'type', 'size', 'ariaPressed'],
		template: '<button :type="type" :disabled="disabled" :aria-pressed="ariaPressed"><slot /></button>'
	}
}))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'label', 'error', 'disabled', 'loading', 'name'],
		emits: ['update:modelValue'],
		template: '<select :aria-label="label" :disabled="disabled" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option></select>'
	}
}))

vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'type', 'error', 'required', 'disabled', 'readonly', 'helperText'],
		emits: ['update:modelValue'],
		template: '<input :type="type" :aria-label="label" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">'
	}
}))

import axios from '@nextcloud/axios'
import ReportFilterBar from '@/components/reports/ReportFilterBar.vue'
import ReportTable from '@/components/reports/ReportTable.vue'
import ExportControls from '@/components/reports/ExportControls.vue'
import ReportView from '@/views/reports/ReportView.vue'
import { routes } from '@/router'
import { REPORT_PAGE_SIZE_MAX, fetchReport, pruneFilters, reportQuery } from '@/services/reportsApi'
import { useReportsStore } from '@/stores/reports'
import type { ReportFilters, ReportPage, ReportRow } from '@/types/reporting'

const requestMock = vi.mocked(axios.request)
const postMock = vi.mocked(axios.post)

const SHEETS_PAGE: ReportPage = {
	page: 1,
	pageSize: 25,
	total: 1,
	columns: ['title', 'status', 'currentTotal'],
	rows: [{ title: 'January petty cash', status: 'COMPLETED', currentTotal: 1250000 }],
	summary: { currentTotal: 1250000, rowCount: 1 }
}

function ocsResponse(data: unknown) {
	return { data: { ocs: { meta: { status: 'ok' }, data } } }
}

function reportResponse(overrides: Partial<ReportPage> = {}): ReportPage {
	return { ...SHEETS_PAGE, ...overrides }
}

function createTestRouter() {
	// The application's own route table, components replaced with inert placeholders (T359). This
	// file declared a single synthetic route for `dashboard`, which meant any control under test that
	// linked by name was resolved against a table the application does not have.
	return createRouter({
		history: createWebHashHistory(),
		routes: routes.map(({ path, name, props }) => ({
			path,
			name,
			props,
			component: { template: '<div />' }
		}))
	})
}

function mountView() {
	const pinia = createPinia()
	setActivePinia(pinia)
	return mount(ReportView, {
		global: {
			plugins: [pinia, createTestRouter()],
			stubs: { 'router-link': true, 'router-view': true }
		}
	})
}

function requestedUrl(call = 0): string {
	return String(requestMock.mock.calls[call]?.[0]?.url ?? '')
}

/**
 * The bytes the exporter wrote, and the `ExportResult` envelope the server actually returns.
 *
 * Nextcloud's OCS layer wraps every body as JSON, so `ExportController` carries the artifact
 * base64-encoded in `data` and states the artifact's own `mediaType` beside it. A mock that answered
 * a bare `Blob` is what let the client believe the response body *was* the artifact, and it is the
 * reason the saved file would have contained the JSON envelope instead of a CSV.
 */
const ARTIFACT_BYTES = 'id,status\n1,COMPLETED\n'

function exportResultBody(): unknown {
	return {
		ocs: {
			meta: { status: 'ok', statuscode: 200 },
			data: {
				artifactName: 'pettycash-export-abc123',
				mediaType: 'text/csv',
				format: 'csv',
				byteSize: ARTIFACT_BYTES.length,
				rowCount: 1,
				data: btoa(ARTIFACT_BYTES),
				artifactRemoved: true,
			},
		},
	}
}

/**
 * Reads a `Blob`'s bytes.
 *
 * `Blob.prototype.text()` and `arrayBuffer()` are **absent in this environment** — jsdom's `Blob`
 * exposes only `slice`, `size` and `type` — so the usual one-liners throw `is not a function` and a
 * byte-level assertion cannot be written at all. `FileReader` is the API jsdom does implement, so
 * that is what reads the bytes here.
 */
function blobBytes(blob: Blob): Promise<Uint8Array> {
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onload = () => resolve(new Uint8Array(reader.result as ArrayBuffer))
		reader.onerror = () => reject(reader.error)
		reader.readAsArrayBuffer(blob)
	})
}

async function flushPromises(): Promise<void> {
	await Promise.resolve()
	await Promise.resolve()
	await Promise.resolve()
}

describe('US7 Reporting and Export — Vue Component Tests', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		setActivePinia(createPinia())
		requestMock.mockResolvedValue(ocsResponse(reportResponse()))
		postMock.mockResolvedValue({ data: exportResultBody() })
	})

	describe('reportsApi service', () => {
		it('requests the contract path with the default page window', async () => {
			await fetchReport('sheets')
			expect(requestedUrl()).toBe('/ocs/v2.php/apps/pettycash/api/v1/reports/sheets?page=1&pageSize=25')
		})
		it('clamps pageSize to the contract maximum of 100', () => {
			expect(reportQuery({ pageSize: 5000 }).get('pageSize')).toBe(String(REPORT_PAGE_SIZE_MAX))
		})

		/**
		 * T329: the whole filter object travels as ONE `filters` parameter.
		 *
		 * This test previously asserted the opposite — that each filter became its own query parameter
		 * — and it passed, because it agreed with `reportQuery`. The endpoint binds a single
		 * `string $filters` and JSON-decodes it, so those parameters were all discarded, `$filters` was
		 * always `''`, and the user was shown the unfiltered report. A client-side unit test cannot
		 * catch that on its own, which is why the contract test
		 * `testAStatusAndADateRangeInOneRequestReturnOnlyRowsSatisfyingBoth` is the one that carries the
		 * weight; this one pins the encoding the contract expects.
		 */
		it('sends the whole filter object as one JSON `filters` parameter', () => {
			const query = reportQuery({
				filters: { statuses: ['OPEN'], costCenterIds: ['22222222-2222-4222-8222-222222222222'] },
			})

			expect(JSON.parse(query.get('filters') ?? '{}')).toEqual({
				statuses: ['OPEN'],
				costCenterIds: ['22222222-2222-4222-8222-222222222222'],
			})
			// No filter may leak out as a top-level parameter: the endpoint reads none, so one would be
			// silently discarded -- the original defect.
			expect(query.has('statuses')).toBe(false)
			expect(query.has('costCenterIds')).toBe(false)
		})

		/**
		 * An unfilled field must narrow nothing.
		 *
		 * `{"statuses":[]}` is a *recognised* key carrying a value that matches no row, and `{"from":""}`
		 * is a date the server refuses. So the empty forms are dropped rather than sent, and an entirely
		 * empty filter object is omitted rather than serialised as `{}`.
		 */
		it('omits unfilled filter values instead of narrowing the result set with empty strings', () => {
			const query = reportQuery({
				filters: {
					statuses: ['OPEN'],
					costCenterIds: [],
					actorUids: [''],
					createdBy: undefined,
					from: '',
					amountMinMinor: 0,
				} as ReportFilters,
			})

			expect(JSON.parse(query.get('filters') ?? '{}')).toEqual({ statuses: ['OPEN'], amountMinMinor: 0 })
		})

		it('sends no `filters` parameter at all when nothing is filled in', () => {
			expect(reportQuery({ filters: {} }).has('filters')).toBe(false)
			expect(reportQuery({ filters: { statuses: [] } as ReportFilters }).has('filters')).toBe(false)
			expect(reportQuery().has('filters')).toBe(false)
		})

		/**
		 * `amountMinMinor: 0` must survive. A `!value` truthiness check — the obvious way to write the
		 * prune — drops zero, and a zero lower bound is a real filter: it excludes nothing, which is
		 * different from "no lower bound" only by accident, and a negative-amount report would then
		 * include rows the user asked to exclude.
		 */
		it('keeps a zero amount bound rather than treating it as unfilled', () => {
			expect(pruneFilters({ amountMinMinor: 0 })).toEqual({ amountMinMinor: 0 })
		})
	})

	describe('reports store', () => {
		it('projects the report page into the store', async () => {
			const store = useReportsStore()
			await store.loadReport()
			expect(store.columns).toEqual(['title', 'status', 'currentTotal'])
			expect(store.rows).toHaveLength(1)
			expect(store.summary).toEqual({ currentTotal: 1250000, rowCount: 1 })
			expect(store.total).toBe(1)
			expect(store.error).toBeNull()
		})

		it('records a failure and drops the previous rows instead of showing stale results', async () => {
			const store = useReportsStore()
			await store.loadReport()
			expect(store.rows).toHaveLength(1)

			requestMock.mockRejectedValueOnce({
				isAxiosError: true,
				response: { status: 403, data: { ocs: { data: { error: { code: 'FORBIDDEN', message: 'out of scope' } } } } }
			})
			await expect(store.loadReport()).rejects.toThrow('out of scope')
			expect(store.error).toBe('out of scope')
			expect(store.rows).toEqual([])
			expect(store.hasRows).toBe(false)
		})

		it('does not retry a failed read on its own (FR-075)', async () => {
			requestMock.mockRejectedValue({
				isAxiosError: true,
				response: { status: 500, data: { ocs: { data: { error: { code: 'SERVER', message: 'boom' } } } } }
			})
			const store = useReportsStore()
			await expect(store.loadReport()).rejects.toThrow('boom')
			expect(requestMock).toHaveBeenCalledTimes(1)
		})

		it('resets to the first page and sends only the filters the user filled in', async () => {
			requestMock.mockResolvedValue(ocsResponse(reportResponse({ page: 3, total: 120 })))
			const store = useReportsStore()
			await store.loadReport()
			expect(store.page).toBe(3)

			requestMock.mockResolvedValue(ocsResponse(reportResponse()))
			await store.applyFilters({ statuses: ['COMPLETED'], costCenterIds: [] })
			const url = new URL(requestedUrl(1), 'http://localhost')
			expect(url.searchParams.get('page')).toBe('1')
			// The filters travel inside `filters`, so the assertion reads the decoded object rather than
			// matching substrings against the whole query string.
			expect(JSON.parse(url.searchParams.get('filters') ?? '{}')).toEqual({ statuses: ['COMPLETED'] })
		})

		it('discards the previous subject filters when the report subject changes', async () => {
			const store = useReportsStore()
			await store.applyFilters({ statuses: ['COMPLETED'] })
			await store.selectReportType('audit')
			const url = new URL(requestedUrl(1), 'http://localhost')
			expect(url.pathname).toContain('/reports/audit')
			expect(url.searchParams.has('filters')).toBe(false)
			expect(store.filters).toEqual({})
		})

		it('does not offer a next page past the last page of the result set', async () => {
			requestMock.mockResolvedValue(ocsResponse(reportResponse({ page: 1, pageSize: 25, total: 25 })))
			const store = useReportsStore()
			await store.loadReport()
			expect(store.totalPages).toBe(1)
			expect(store.canGoNext).toBe(false)
			expect(store.canGoPrevious).toBe(false)
		})

		it('moves to the next page when more results exist', async () => {
			requestMock.mockResolvedValue(ocsResponse(reportResponse({ page: 1, pageSize: 25, total: 60 })))
			const store = useReportsStore()
			await store.loadReport()
			expect(store.canGoNext).toBe(true)
			await store.goToPage(2)
			expect(requestedUrl(1)).toContain('page=2')
		})
	})

	describe('report export', () => {
		it('posts the export with the mapped resource, format, filters, and command identifier', async () => {
			const store = useReportsStore()
			await store.applyFilters({ stages: ['FINANCE'] })
			postMock.mockClear()

			const payload = await store.exportReport('xlsx')
			const [url, body, config] = postMock.mock.calls[0] ?? []
			expect(url).toBe('/ocs/v2.php/apps/pettycash/api/v1/exports')
			// `fileFormat`, not `format`. The endpoint binds `fileFormat` and answers `422` with
			// `fieldErrors.fileFormat` when it is empty, so a body carrying `format` made **every**
			// export a refusal — for every format, for every subject.
			expect(body).toMatchObject({ resource: 'sheets', fileFormat: 'xlsx', reportType: 'sheets' })
			expect(body).not.toHaveProperty('format')
			expect(config?.headers).toMatchObject({ 'X-PettyCash-Command-ID': expect.any(String) })
			// Not `responseType: 'blob'`: the body is JSON, so asking for a blob would hand the client
			// the envelope's bytes typed as the artifact's media type.
			expect(config?.responseType).toBeUndefined()
			expect(payload.filename).toMatch(/^pettycash-sheets-\d{4}-\d{2}-\d{2}\.xlsx$/)
			expect(payload.blob).toBeInstanceOf(Blob)
		})

		/**
		 * T341: the downloaded bytes must be the artifact, not the envelope that carries it.
		 *
		 * Asserting only `payload.blob instanceof Blob` is what passed before, and a `Blob` wrapping
		 * `{"ocs":{"data":{…,"data":"<base64>"}}}` satisfies it perfectly while producing a corrupt
		 * download. So the bytes themselves are read back and compared with what the exporter wrote.
		 */
		it('decodes the artifact out of the OCS envelope rather than saving the envelope', async () => {
			const store = useReportsStore()
			await store.applyFilters({ stages: ['FINANCE'] })
			postMock.mockClear()

			const payload = await store.exportReport('csv')

			expect(new TextDecoder().decode(await blobBytes(payload.blob))).toBe(ARTIFACT_BYTES)
			expect(payload.blob.type).toBe('text/csv')
		})

		/**
		 * The UTF-8 trap `exportArtifactBlob()` exists to avoid.
		 *
		 * `atob` yields one binary string per byte; handing that string straight to `Blob` encodes each
		 * character's code point as UTF-8, which is lossless only below 0x80. A Persian CSV is above it
		 * everywhere, so the round trip is asserted with bytes that a code-point encoding would change.
		 */
		it('round-trips bytes above 0x7F unchanged', async () => {
			const binary = String.fromCharCode(0xD8, 0xA7, 0xD8, 0xB3, 0x0A) // Persian text plus LF
			postMock.mockResolvedValueOnce({
				data: {
					ocs: {
						data: {
							artifactName: 'a', mediaType: 'text/csv', format: 'csv',
							byteSize: binary.length, rowCount: 1, data: btoa(binary), artifactRemoved: true,
						},
					},
				},
			})

			const store = useReportsStore()
			await store.applyFilters({ stages: ['FINANCE'] })
			const payload = await store.exportReport('csv')

			expect(Array.from(await blobBytes(payload.blob))).toEqual([0xD8, 0xA7, 0xD8, 0xB3, 0x0A])
		})

		it('offers the grouped evidence archive only for the evidence report', async () => {
			const store = useReportsStore()
			expect(store.availableFormats).toEqual(['csv', 'xlsx', 'pdf'])
			await store.selectReportType('evidence')
			expect(store.availableFormats).toEqual(['csv', 'zip'])
		})

		it('refuses an unsupported format rather than substituting a different one', async () => {
			const store = useReportsStore()
			await expect(store.exportReport('zip')).rejects.toThrow('pettycash.error.unsupportedFormat:zip')
			expect(postMock).not.toHaveBeenCalled()
		})

		/**
		 * T342: the three subjects the client invents a `report` resource for offer no format at all.
		 *
		 * They mapped to the generic `report` resource with three formats each, and the server resolved
		 * rows for `sheet`, `sheets` and `transactions` only — so an export produced a well-formed CSV
		 * with a header row, no rows, and a `rowCount: 0` that read as a measured zero. The server now
		 * refuses those resources; this asserts the client offers nothing to click, which is the half a
		 * store test can actually see.
		 */
		it('offers no format for the subjects that have no exporter', async () => {
			const store = useReportsStore()

			for (const subject of ['cost-centers', 'accounts', 'process'] as const) {
				await store.selectReportType(subject)
				expect(store.availableFormats, subject).toEqual([])
				// And nothing is requested when a caller ignores the empty list.
				postMock.mockClear()
				await expect(store.exportReport('csv')).rejects.toThrow('pettycash.error.unsupportedFormat:csv')
				expect(postMock).not.toHaveBeenCalled()
			}
		})

		it('still offers the grouped evidence archive for the evidence report', async () => {
			// The counterpart to the case above: a blanket "nothing is exportable" would satisfy it too.
			const store = useReportsStore()
			await store.selectReportType('evidence')
			expect(store.availableFormats).toEqual(['csv', 'zip'])
		})

		it('surfaces an export failure and leaves no export in flight', async () => {
			postMock.mockRejectedValueOnce({
				isAxiosError: true,
				response: { status: 422, data: {} }
			})
			const store = useReportsStore()
			await expect(store.exportReport('csv')).rejects.toBeTruthy()
			expect(store.exportError).toBe('pettycash.error.export')
			expect(store.exporting).toBe(false)
		})

		/**
		 * T334: a refusal must reach the form as the server stated it.
		 *
		 * `asApiError` used to replace every axios failure with a synthetic `EXPORT_FAILED` body, so
		 * a `422` naming `fileFormat` reached the user as "export failed" and `fieldErrors` — the
		 * payload a form places next to its input — was dropped on the way. The mock below is the
		 * real envelope the endpoint answers with.
		 */
		it('carries the refusal\'s field errors from the error envelope into the store', async () => {
			postMock.mockRejectedValueOnce({
				isAxiosError: true,
				response: {
					status: 422,
					data: {
						ocs: {
							meta: { status: 'failure', statuscode: 422 },
							data: {
								error: {
									code: 'VALIDATION_FAILED',
									message: 'Required fields are missing',
									fieldErrors: { fileFormat: ['fileFormat is required'] }
								}
							}
						}
					}
				}
			})
			const store = useReportsStore()

			await expect(store.exportReport('csv')).rejects.toBeTruthy()

			// The server's message, not the synthetic one: `exportError` answers *why*.
			expect(store.exportError).toBe('Required fields are missing')
			// And `fieldErrors` answers *which*, which is the half a form can place beside an input.
			expect(store.exportFieldErrors).toEqual({ fileFormat: ['fileFormat is required'] })
		})

		it('clears the previous refusal\'s field errors when a new export starts', async () => {
			postMock.mockRejectedValueOnce({
				isAxiosError: true,
				response: {
					status: 422,
					data: { ocs: { data: { error: { code: 'VALIDATION_FAILED', message: 'no', fieldErrors: { fileFormat: ['required'] } } } } }
				}
			})
			const store = useReportsStore()
			await expect(store.exportReport('csv')).rejects.toBeTruthy()

			postMock.mockResolvedValueOnce({ data: exportResultBody() })
			await store.exportReport('csv')

			expect(store.exportFieldErrors).toEqual({})
		})
	})

	describe('ReportView', () => {
		it('loads the default report on mount and renders the returned rows', async () => {
			const wrapper = mountView()
			await flushPromises()
			expect(requestedUrl()).toContain('/reports/sheets')
			expect(wrapper.find('[data-testid="report-table"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="report-row-0"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="report-cell-title"]').text()).toBe('January petty cash')
		})

		it('presents the empty state when the report has no rows', async () => {
			requestMock.mockResolvedValue(ocsResponse(reportResponse({ rows: [], total: 0, summary: {} })))
			const wrapper = mountView()
			await flushPromises()
			expect(wrapper.find('[data-testid="report-view-empty"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="report-pagination"]').exists()).toBe(false)
		})

		it('shows the failure with a retry the user must trigger (FR-075)', async () => {
			requestMock.mockRejectedValue({
				isAxiosError: true,
				response: { status: 500, data: { ocs: { data: { error: { code: 'SERVER', message: 'report failed' } } } } }
			})
			const wrapper = mountView()
			await flushPromises()
			expect(wrapper.find('[data-testid="report-view-error"]').text()).toContain('report failed')
			expect(requestMock).toHaveBeenCalledTimes(1)

			requestMock.mockResolvedValue(ocsResponse(reportResponse()))
			await wrapper.find('[data-testid="report-view-retry"]').trigger('click')
			await flushPromises()
			expect(requestMock).toHaveBeenCalledTimes(2)
			expect(wrapper.find('[data-testid="report-table"]').exists()).toBe(true)
		})

		it('switches the report subject on request', async () => {
			const wrapper = mountView()
			await flushPromises()
			await wrapper.find('[data-testid="report-subject-audit"]').trigger('click')
			await flushPromises()
			expect(requestedUrl(1)).toContain('/reports/audit')
		})

		it('paginates to the next page of the current report', async () => {
			requestMock.mockResolvedValueOnce(ocsResponse(reportResponse({ page: 1, total: 60 })))
			requestMock.mockResolvedValueOnce(ocsResponse(reportResponse({ page: 2, total: 60 })))
			const wrapper = mountView()
			await flushPromises()
			await wrapper.find('[data-testid="report-next"]').trigger('click')
			await flushPromises()
			expect(requestedUrl(1)).toContain('page=2')
			expect(wrapper.find('[data-testid="report-page-info"]').text()).toContain('2')
		})

		it('exports the current report in the format chosen by the user', async () => {
			const originalCreate = URL.createObjectURL
			const originalRevoke = URL.revokeObjectURL
			const createObjectURL = vi.fn(() => 'blob:report')
			const revokeObjectURL = vi.fn()
			URL.createObjectURL = createObjectURL as unknown as typeof URL.createObjectURL
			URL.revokeObjectURL = revokeObjectURL as unknown as typeof URL.revokeObjectURL
			try {
				const wrapper = mountView()
				await flushPromises()
				await wrapper.find('[data-testid="export-format"]').setValue('pdf')
				await wrapper.find('[data-testid="export-submit"]').trigger('click')
				await flushPromises()

				expect(postMock).toHaveBeenCalledTimes(1)
				expect(postMock.mock.calls[0]?.[1]).toMatchObject({ resource: 'sheets', fileFormat: 'pdf' })
				expect(createObjectURL).toHaveBeenCalledTimes(1)
				expect(revokeObjectURL).toHaveBeenCalledWith('blob:report')
			} finally {
				URL.createObjectURL = originalCreate
				URL.revokeObjectURL = originalRevoke
			}
		})
	})

	describe('ReportFilterBar', () => {
		function mountBar(filters: ReportFilters = {}) {
			return mount(ReportFilterBar, { props: { filters, reportType: 'sheets' } })
		}

		/**
		 * The emitted object is in the server's vocabulary, list-valued where the server refuses a
		 * scalar.
		 *
		 * This asserted `{ period: 'custom', status: 'COMPLETED' }` before T329 — two names the server
		 * has never accepted, one of which (`period`) is still not in the vocabulary. Every field would
		 * have been refused as unknown, and the user would have been shown the unfiltered report.
		 */
		it('emits the filters the user filled in under the names the server accepts', async () => {
			const wrapper = mountBar()
			await wrapper.find('[data-testid="report-filter-status"]').setValue('COMPLETED')
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({ statuses: ['COMPLETED'] })
		})

		/**
		 * A single-select control still sends a **list**, because `ReportFilterFactory` refuses a
		 * non-array for every list-valued name. Sending a bare string would be a 422 on a filter the
		 * user filled in correctly.
		 */
		it('wraps every single-value facet in a one-element list', async () => {
			const wrapper = mountBar()
			await wrapper.find('[data-testid="report-filter-cost-center"]').setValue('22222222-2222-4222-8222-222222222222')
			await wrapper.find('[data-testid="report-filter-stage"]').setValue('FINANCE')
			await wrapper.find('[data-testid="report-filter-event-type"]').setValue('SHEET_SUBMITTED')
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({
				costCenterIds: ['22222222-2222-4222-8222-222222222222'],
				stages: ['FINANCE'],
				eventTypes: ['SHEET_SUBMITTED'],
			})
		})

		it('emits the date range under `from`/`to`, the names the factory accepts', async () => {
			const wrapper = mountBar({ from: '2026-01-01', to: '2026-01-31' })
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({ from: '2026-01-01', to: '2026-01-31' })
		})

		it('emits no filter the server would refuse as unknown', async () => {
			const wrapper = mountBar()
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			const emitted = wrapper.emitted('apply')?.[0]?.[0] as Record<string, unknown>
			// `period` in particular: it is not a member of the accepted vocabulary, and sending it is a
			// 422. Its absence here is T312's precondition, not an oversight.
			expect(Object.keys(emitted)).not.toContain('period')
			expect(Object.keys(emitted)).not.toContain('dateFrom')
			expect(Object.keys(emitted)).not.toContain('costCenterId')
		})

		/**
		 * The Jalali presets are T312's, and `period` is not in the accepted vocabulary, so the control
		 * must not offer a filter that would return the unfiltered report.
		 */
		it('offers only the period presets the server can currently answer', () => {
			const wrapper = mountBar()
			const options = wrapper.findComponent({ name: 'NcSelect' }).props('options') as { value: string }[]

			expect(options.map((option) => option.value)).toEqual(['custom'])
		})

		it('resets without carrying any filter into the request', async () => {
			const wrapper = mountBar({ statuses: ['COMPLETED'] })
			await wrapper.find('[data-testid="report-filter-reset"]').trigger('click')
			expect(wrapper.emitted('reset')).toBeTruthy()
		})
	})

	describe('ReportTable', () => {
		const rows: ReportRow[] = [
			{ title: 'January petty cash', status: 'COMPLETED', currentTotal: 1250000 },
			{ title: 'February petty cash', status: null, currentTotal: 0 }
		]

		it('renders the summary alongside the rows', () => {
			const wrapper = mount(ReportTable, {
				props: { columns: ['title', 'status', 'currentTotal'], rows, summary: { currentTotal: 1250000 } }
			})
			expect(wrapper.find('[data-testid="report-summary-currentTotal"]').text()).toContain('۱٬۲۵۰٬۰۰۰')
		})

		it('marks a value the projection did not supply instead of rendering a blank cell', () => {
			const wrapper = mount(ReportTable, {
				props: { columns: ['title', 'status', 'currentTotal'], rows }
			})
			expect(wrapper.findAll('[data-testid="report-cell-status"]')[1]?.text()).toBe('—')
		})

		it('keeps a server column identifier the catalog does not name yet', () => {
			const wrapper = mount(ReportTable, { props: { columns: ['unmappedColumn'], rows: [{ unmappedColumn: 'x' }] } })
			expect(wrapper.find('th').text()).toBe('unmappedColumn')
		})

		it('renders a per-row card for the narrow viewport layout (FR-071)', () => {
			const wrapper = mount(ReportTable, { props: { columns: ['title'], rows } })
			expect(wrapper.find('[data-testid="report-card-0"]').exists()).toBe(true)
			expect(wrapper.findAll('.report-card')).toHaveLength(2)
		})
	})

	describe('ExportControls', () => {
		it('offers only the formats the subject supports', () => {
			const wrapper = mount(ExportControls, { props: { availableFormats: ['csv', 'zip'] } })
			const options = wrapper.findAll('[data-testid="export-format"] option')
			expect(options.map((option) => option.attributes('value'))).toEqual(['csv', 'zip'])
		})

		it('does not start an export while one is in flight', () => {
			const wrapper = mount(ExportControls, { props: { availableFormats: ['csv'], exporting: true } })
			expect(wrapper.find('[data-testid="export-submit"]').attributes('disabled')).toBeDefined()
		})

		it('retries an export only when the user asks for it', async () => {
			const wrapper = mount(ExportControls, { props: { availableFormats: ['csv'], error: 'export failed' } })
			expect(wrapper.find('[data-testid="export-error"]').text()).toContain('export failed')
			expect(wrapper.emitted('export')).toBeFalsy()
			await wrapper.find('[data-testid="export-retry"]').trigger('click')
			expect(wrapper.emitted('export')?.[0]?.[0]).toBe('csv')
		})

		/**
		 * T334: the field name is rendered, not only the message.
		 *
		 * A refusal that says "required" without saying *which* field is required gives the user
		 * nothing to act on, and `fileFormat` is a value of this very control.
		 */
		it('names each refused field next to the refusal', () => {
			const wrapper = mount(ExportControls, {
				props: {
					availableFormats: ['csv'],
					error: 'Required fields are missing',
					fieldErrors: { fileFormat: ['fileFormat is required'] }
				}
			})

			const row = wrapper.find('[data-testid="export-field-error-fileFormat"]')
			expect(row.exists()).toBe(true)
			expect(row.text()).toContain('fileFormat')
			expect(row.text()).toContain('fileFormat is required')
		})

		it('renders no field-error list when the refusal named no field', () => {
			const wrapper = mount(ExportControls, { props: { availableFormats: ['csv'], error: 'not allowed' } })

			expect(wrapper.find('[data-testid="export-field-errors"]').exists()).toBe(false)
		})

		/**
		 * T342: a subject with nothing to export says so.
		 *
		 * A disabled control with no explanation is the failure mode this whole task is about, one level
		 * up: the user learns nothing except that a button is grey. `role="note"` rather than `alert`
		 * because this is not a failure that just happened — it is a standing property of the subject.
		 */
		it('states why a subject cannot be exported', () => {
			const wrapper = mount(ExportControls, { props: { availableFormats: [] } })

			expect(wrapper.find('[data-testid="export-submit"]').attributes('disabled')).toBeDefined()
			const note = wrapper.find('[data-testid="export-unavailable"]')
			expect(note.exists()).toBe(true)
			expect(note.text()).toBe('reports.export.unsupportedSubject')
			expect(note.attributes('role')).toBe('note')
		})

		it('shows no availability note while an export is in flight', () => {
			// Nothing to export *and* busy cannot both be true in practice; if they ever are, the busy
			// state is the truthful one and a second message would compete with it.
			const wrapper = mount(ExportControls, { props: { availableFormats: [], exporting: true } })

			expect(wrapper.find('[data-testid="export-unavailable"]').exists()).toBe(false)
		})

		it('prefers the server\'s refusal over the availability note', () => {
			const wrapper = mount(ExportControls, {
				props: { availableFormats: [], error: 'Exporting resource \'report\' is not supported yet' }
			})

			expect(wrapper.find('[data-testid="export-error"]').text()).toContain('not supported yet')
			expect(wrapper.find('[data-testid="export-unavailable"]').exists()).toBe(false)
		})
	})
})
