import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useRouter } from 'vue-router'

const financeApi = vi.hoisted(() => ({
	listHolders: vi.fn(),
	listHolderAccounts: vi.fn(),
	fetchLedger: vi.fn(),
	listCompletedSheets: vi.fn(),
	getCompletedSheet: vi.fn(),
}))

vi.mock('@/services/financeApi', () => financeApi)

vi.mock('@nextcloud/l10n', () => {
	const dict: Record<string, string> = {
		'common.loading': 'Loading',
		'common.empty': 'No items',
		'common.error': 'Error',
		'common.previous': 'Previous',
		'common.next': 'Next',
		'common.retry': 'Retry',
		'common.close': 'Close',
		'completedSheets.title': 'Completed Cost Sheet History',
		'completedSheets.column.reference': 'Reference',
		'completedSheets.column.title': 'Title',
		'completedSheets.column.purchaser': 'Purchaser',
		'completedSheets.column.status': 'Status',
		'completedSheets.column.total': 'Total',
		'completedSheets.column.approved': 'Approved',
		'completedSheets.column.rejected': 'Rejected',
		'completedSheets.column.postedAmount': 'Posted amount',
		'completedSheets.column.postingCount': 'Posted count',
		'completedSheets.column.completedAt': 'Completed at',
		'completedSheets.empty': 'No completed sheets',
		'completedSheets.emptySearch': 'No completed sheets match your search',
		'completedSheets.loading': 'Loading completed sheets',
		'completedSheets.completedWithRejections': 'Completed with rejections',
		'financeAccounts.status.active': 'Active',
		'financeAccounts.status.inactive': 'Inactive'
	}
	return {
		t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) => {
			let result = dict[text] ?? text
			if (params) {
				result = result.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))
			}
			return result
		}),
		getLanguage: vi.fn(() => 'en'),
		isRTL: vi.fn(() => false)
	}
})

vi.mock('vue-router', () => ({
	useRouter: vi.fn(() => ({
		push: vi.fn()
	})),
	useRoute: vi.fn(() => ({ params: {} }))
}))

import CompletedSheetTable from '@/components/finance/CompletedSheetTable.vue'

const sheets = [
	{
		id: 'sheet-1',
		reference: 'SHEET-001',
		title: 'Office supplies Q1',
		purchaser: { userId: 'uid-1', displayName: 'Alice' },
		status: 'COMPLETED' as const,
		totalTransactionCount: 5,
		approvedTransactionCount: 4,
		rejectedTransactionCount: 1,
		postedAmount: { amountMinor: 150000, currency: 'IRR', minorUnit: 2 },
		postingCount: 4,
		completedAt: '2026-11-15T10:00:00+00:00'
	},
	{
		id: 'sheet-2',
		reference: 'SHEET-002',
		title: 'Travel expenses',
		purchaser: { userId: 'uid-2', displayName: 'Bob' },
		status: 'COMPLETED_WITH_REJECTIONS' as const,
		totalTransactionCount: 3,
		approvedTransactionCount: 1,
		rejectedTransactionCount: 2,
		postedAmount: { amountMinor: 50000, currency: 'IRR', minorUnit: 2 },
		postingCount: 1,
		completedAt: '2026-11-14T09:00:00+00:00'
	}
]

function mountTable() {
	return mount(CompletedSheetTable, {
		global: { plugins: [createPinia()] }
	})
}

describe('CompletedSheetTable (T033)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		financeApi.listCompletedSheets.mockReset()
	})

	it('shows loading while sheets are in flight', async () => {
		financeApi.listCompletedSheets.mockReturnValue(new Promise(() => {}))
		const wrapper = mountTable()
		await flushPromises()
		expect(wrapper.find('[data-testid="completed-sheets-loading"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('renders populated sheet rows', async () => {
		financeApi.listCompletedSheets.mockResolvedValue({
			items: sheets,
			page: 1,
			pageSize: 25,
			total: 2
		})
		const wrapper = mountTable()
		await flushPromises()
		expect(wrapper.find('[data-testid="completed-sheet-sheet-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="completed-sheet-sheet-2"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('shows empty state when no sheets', async () => {
		financeApi.listCompletedSheets.mockResolvedValue({ items: [], page: 1, pageSize: 25, total: 0 })
		const wrapper = mountTable()
		await flushPromises()
		expect(wrapper.find('[data-testid="completed-sheets-empty"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('shows error state on load failure', async () => {
		financeApi.listCompletedSheets.mockRejectedValue(new Error('load failed'))
		const wrapper = mountTable()
		await flushPromises()
		expect(wrapper.find('[data-testid="completed-sheets-error"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('renders reference, title, and purchaser', async () => {
		financeApi.listCompletedSheets.mockResolvedValue({
			items: sheets,
			page: 1,
			pageSize: 25,
			total: 2
		})
		const wrapper = mountTable()
		await flushPromises()
		const row = wrapper.find('[data-testid="completed-sheet-sheet-1"]')
		expect(row.text()).toContain('SHEET-001')
		expect(row.text()).toContain('Office supplies Q1')
		expect(row.text()).toContain('Alice')
		wrapper.unmount()
	})

	it('shows completed-with-rejections status', async () => {
		financeApi.listCompletedSheets.mockResolvedValue({
			items: [sheets[1]],
			page: 1,
			pageSize: 25,
			total: 1
		})
		const wrapper = mountTable()
		await flushPromises()
		expect(wrapper.text()).toContain('Completed with rejections')
		wrapper.unmount()
	})

	it('renders posted amount from server values', async () => {
		financeApi.listCompletedSheets.mockResolvedValue({
			items: sheets,
			page: 1,
			pageSize: 25,
			total: 2
		})
		const wrapper = mountTable()
		await flushPromises()
		expect(wrapper.text()).toContain('1,500.00')
		wrapper.unmount()
	})

	it('renders counts from server values', async () => {
		financeApi.listCompletedSheets.mockResolvedValue({
			items: sheets,
			page: 1,
			pageSize: 25,
			total: 2
		})
		const wrapper = mountTable()
		await flushPromises()
		const row = wrapper.find('[data-testid="completed-sheet-sheet-1"]')
		expect(row.text()).toContain('5')
		expect(row.text()).toContain('4')
		expect(row.text()).toContain('1')
		wrapper.unmount()
	})

	it('has exactly 10 columns', async () => {
		financeApi.listCompletedSheets.mockResolvedValue({
			items: sheets,
			page: 1,
			pageSize: 25,
			total: 2
		})
		const wrapper = mountTable()
		await flushPromises()
		const headers = wrapper.findAll('th')
		expect(headers.length).toBe(10)
		wrapper.unmount()
	})

	it('does not render any mutation controls', async () => {
		financeApi.listCompletedSheets.mockResolvedValue({
			items: sheets,
			page: 1,
			pageSize: 25,
			total: 2
		})
		const wrapper = mountTable()
		await flushPromises()
		expect(wrapper.find('[data-testid="approve-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="reject-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="return-for-correction-btn"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('navigates to detail when a row is clicked', async () => {
		const push = vi.fn()
		vi.mocked(useRouter).mockReturnValue({ push } as any)
		financeApi.listCompletedSheets.mockResolvedValue({
			items: sheets,
			page: 1,
			pageSize: 25,
			total: 2
		})
		const wrapper = mountTable()
		await flushPromises()
		await wrapper.find('[data-testid="completed-sheet-sheet-1"]').trigger('click')
		expect(push).toHaveBeenCalledWith({
			name: 'finance-completed-sheet-detail',
			params: { sheetId: 'sheet-1' }
		})
		wrapper.unmount()
	})

	it('paginates when more than one page', async () => {
		financeApi.listCompletedSheets.mockResolvedValue({
			items: sheets,
			page: 1,
			pageSize: 25,
			total: 2
		})
		const wrapper = mountTable()
		await flushPromises()
		expect(wrapper.find('[data-testid="completed-sheets-pagination"]').exists()).toBe(false)
		wrapper.unmount()
	})
})
