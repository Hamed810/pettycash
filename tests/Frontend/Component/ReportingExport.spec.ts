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
import { REPORT_PAGE_SIZE_MAX, fetchReport, reportQuery } from '@/services/reportsApi'
import { useReportsStore } from '@/stores/reports'
import type { ReportPage, ReportRow } from '@/types/reporting'

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
	return createRouter({
		history: createWebHashHistory(),
		routes: [{ path: '/', name: 'dashboard', component: { template: '<div>Dashboard</div>' } }]
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
		postMock.mockResolvedValue({ data: new Blob(['id,status\n1,COMPLETED\n']) })
	})

	describe('reportsApi service', () => {
		it('requests the contract path with the default page window', async () => {
			await fetchReport('sheets')
			expect(requestedUrl()).toBe('/ocs/v2.php/apps/pettycash/api/v1/reports/sheets?page=1&pageSize=25')
		})
		it('clamps pageSize to the contract maximum of 100', () => {
			expect(reportQuery({ pageSize: 5000 }).get('pageSize')).toBe(String(REPORT_PAGE_SIZE_MAX))
		})

		it('omits unfilled filter values instead of narrowing the result set with empty strings', () => {
			const query = reportQuery({ filters: { status: 'COMPLETED', costCenterId: '', ownerId: undefined, amountMin: 0 } })
			expect(query.get('status')).toBe('COMPLETED')
			expect(query.has('costCenterId')).toBe(false)
			expect(query.has('ownerId')).toBe(false)
			expect(query.get('amountMin')).toBe('0')
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
			await store.applyFilters({ period: 'month', status: 'COMPLETED', costCenterId: '' })
			expect(requestedUrl(1)).toContain('page=1')
			expect(requestedUrl(1)).toContain('period=month')
			expect(requestedUrl(1)).toContain('status=COMPLETED')
			expect(requestedUrl(1)).not.toContain('costCenterId')
		})

		it('discards the previous subject filters when the report subject changes', async () => {
			const store = useReportsStore()
			await store.applyFilters({ period: 'year', status: 'COMPLETED' })
			await store.selectReportType('audit')
			const url = requestedUrl(1)
			expect(url).toContain('/reports/audit')
			expect(url).not.toContain('status=')
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
			await store.applyFilters({ period: 'month' })
			postMock.mockClear()

			const payload = await store.exportReport('xlsx')
			const [url, body, config] = postMock.mock.calls[0] ?? []
			expect(url).toBe('/ocs/v2.php/apps/pettycash/api/v1/exports')
			expect(body).toMatchObject({ resource: 'sheets', format: 'xlsx', reportType: 'sheets' })
			expect(config?.headers).toMatchObject({ 'X-PettyCash-Command-ID': expect.any(String) })
			expect(config?.responseType).toBe('blob')
			expect(payload.filename).toMatch(/^pettycash-sheets-\d{4}-\d{2}-\d{2}\.xlsx$/)
			expect(payload.blob).toBeInstanceOf(Blob)
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
				expect(postMock.mock.calls[0]?.[1]).toMatchObject({ resource: 'sheets', format: 'pdf' })
				expect(createObjectURL).toHaveBeenCalledTimes(1)
				expect(revokeObjectURL).toHaveBeenCalledWith('blob:report')
			} finally {
				URL.createObjectURL = originalCreate
				URL.revokeObjectURL = originalRevoke
			}
		})
	})

	describe('ReportFilterBar', () => {
		function mountBar(filters = {}) {
			return mount(ReportFilterBar, { props: { filters, reportType: 'sheets' } })
		}

		it('emits only the filters the user filled in', async () => {
			const wrapper = mountBar()
			await wrapper.find('[data-testid="report-filter-status"]').setValue('COMPLETED')
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')
			expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({ period: 'custom', status: 'COMPLETED' })
		})

		it('omits the explicit range unless the custom period is selected (FR-068)', async () => {
			const wrapper = mountBar({ period: 'month', dateFrom: '2026-01-01', dateTo: '2026-01-31' })
			expect(wrapper.find('[data-testid="report-filter-date-from"]').exists()).toBe(false)
			await wrapper.find('[data-testid="report-filter-period"]').setValue('custom')
			expect(wrapper.find('[data-testid="report-filter-date-from"]').exists()).toBe(true)
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')
			expect(wrapper.emitted('apply')?.[0]?.[0]).toMatchObject({ period: 'custom', dateFrom: '2026-01-01', dateTo: '2026-01-31' })
		})

		it('resets without carrying any filter into the request', async () => {
			const wrapper = mountBar({ status: 'COMPLETED' })
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
	})
})
