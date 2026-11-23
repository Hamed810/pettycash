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
		'completedSheetDetail.title': 'Cost Sheet {reference}',
		'completedSheetDetail.column.reference': 'Reference',
		'completedSheetDetail.column.revision': 'Revision',
		'completedSheetDetail.column.description': 'Description',
		'completedSheetDetail.column.expenseDate': 'Expense date',
		'completedSheetDetail.column.amount': 'Amount',
		'completedSheetDetail.column.finalOutcome': 'Final outcome',
		'completedSheetDetail.column.costCenter': 'Cost Center',
		'completedSheetDetail.column.seniorManager': 'Senior Manager',
		'completedSheetDetail.column.financeManager': 'Finance Manager',
		'completedSheetDetail.column.evidence': 'Evidence',
		'completedSheetDetail.column.postedAt': 'Posted at',
		'completedSheetDetail.column.postedAmount': 'Posted amount',
		'completedSheetDetail.column.balanceAfter': 'Balance after',
		'completedSheetDetail.finalOutcome.approved': 'Approved',
		'completedSheetDetail.finalOutcome.rejected': 'Rejected',
		'completedSheetDetail.decision.approved': 'Approved',
		'completedSheetDetail.decision.rejected': 'Rejected',
		'completedSheetDetail.decision.returned': 'Returned for correction',
		'completedSheetDetail.decision.pending': 'Pending',
		'completedSheetDetail.noTransaction': 'No transactions',
		'completedSheetDetail.evidence.none': 'No evidence',
		'completedSheetDetail.openEvidence': 'Open evidence',
		'completedSheetDetail.closeDetail': 'Close detail',
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
	useRoute: vi.fn(() => ({ params: { sheetId: 'sheet-1' } }))
}))

import CompletedSheetDetail from '@/components/finance/CompletedSheetDetail.vue'

const detail = {
	id: 'sheet-1',
	userId: 'uid-1',
	reference: 'SHEET-001',
	title: 'Office supplies Q1',
	currency: 'IRR',
	minorUnit: 2,
	purchaser: { userId: 'uid-1', displayName: 'Alice' },
	status: 'COMPLETED' as const,
	totalTransactionCount: 2,
	approvedTransactionCount: 1,
	rejectedTransactionCount: 1,
	postedAmount: { amountMinor: 150000, currency: 'IRR', minorUnit: 2 },
	postingCount: 1,
	createdAt: '2026-11-15T10:00:00+00:00',
	completedAt: '2026-11-16T11:00:00+00:00',
	accountId: 'acc-1',
	financeStartedAt: null,
	firstSubmittedAt: null,
	transactions: [
		{
			id: 'tx-1',
			reference: 'TX-001',
			revisionNumber: 1,
			description: 'Office chairs',
			expenseDate: '2026-11-10',
			amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 2 },
			finalOutcome: 'APPROVED' as const,
			status: 'COMPLETED_APPROVED' as const,
			decisions: {
				costCenter: { value: 'APPROVE', decidedAt: '2026-11-11T09:00:00+00:00', decidedBy: 'cc-1' },
				senior: { value: 'APPROVE', decidedAt: '2026-11-12T09:00:00+00:00', decidedBy: 'sm-1' },
				finance: { value: 'APPROVE', decidedAt: '2026-11-13T09:00:00+00:00', decidedBy: 'fm-1' }
			},
			decisionHistory: [
				{ value: 'APPROVE', decidedAt: '2026-11-11T09:00:00+00:00', decidedBy: 'cc-1', stage: 'costCenter' },
				{ value: 'APPROVE', decidedAt: '2026-11-12T09:00:00+00:00', decidedBy: 'sm-1', stage: 'senior' },
				{ value: 'APPROVE', decidedAt: '2026-11-13T09:00:00+00:00', decidedBy: 'fm-1', stage: 'finance' }
			],
			evidence: [
				{ id: 'ev-1', name: 'receipt.pdf', mediaType: 'application/pdf', active: true, sizeBytes: 1024, uploadedAt: '2026-11-10T10:00:00+00:00', uploadedBy: 'uid-1' }
			],
			posting: {
				amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 2 },
				balanceAfter: { amountMinor: 50000, currency: 'IRR', minorUnit: 2 },
				occurredAt: '2026-11-13T10:00:00+00:00'
			},
			sourceRevisionId: null
		},
		{
			id: 'tx-2',
			reference: 'TX-002',
			revisionNumber: 1,
			description: 'Travel',
			expenseDate: '2026-11-11',
			amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 2 },
			finalOutcome: 'REJECTED' as const,
			status: 'COMPLETED_REJECTED' as const,
			decisions: {
				costCenter: null,
				senior: null,
				finance: { value: 'REJECT', decidedAt: '2026-11-13T10:00:00+00:00', decidedBy: 'fm-1' }
			},
			decisionHistory: [
				{ value: 'REJECT', decidedAt: '2026-11-13T10:00:00+00:00', decidedBy: 'fm-1', stage: 'finance' }
			],
			evidence: [],
			posting: null,
			sourceRevisionId: null
		}
	]
}

function mountDetail() {
	return mount(CompletedSheetDetail, {
		props: { sheetId: 'sheet-1' },
		global: {}
	})
}

describe('CompletedSheetDetail (T034)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		financeApi.getCompletedSheet.mockReset()
	})

	it('shows loading while detail is in flight', async () => {
		financeApi.getCompletedSheet.mockReturnValue(new Promise(() => {}))
		const wrapper = mountDetail()
		await flushPromises()
		expect(wrapper.find('[data-testid="completed-sheet-detail-loading"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('renders header data from server', async () => {
		financeApi.getCompletedSheet.mockResolvedValue(detail)
		const wrapper = mountDetail()
		await flushPromises()
		expect(wrapper.text()).toContain('SHEET-001')
		expect(wrapper.text()).toContain('Office supplies Q1')
		expect(wrapper.text()).toContain('Alice')
		wrapper.unmount()
	})

	it('renders all transactions', async () => {
		financeApi.getCompletedSheet.mockResolvedValue(detail)
		const wrapper = mountDetail()
		await flushPromises()
		expect(wrapper.find('[data-testid="completed-tx-tx-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="completed-tx-tx-2"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('renders approved transaction amounts', async () => {
		financeApi.getCompletedSheet.mockResolvedValue(detail)
		const wrapper = mountDetail()
		await flushPromises()
		expect(wrapper.text()).toContain('1,000.00')
		wrapper.unmount()
	})

	it('rejected transaction row exists without posting row', async () => {
		financeApi.getCompletedSheet.mockResolvedValue(detail)
		const wrapper = mountDetail()
		await flushPromises()
		const row = wrapper.find('[data-testid="completed-tx-tx-2"]')
		expect(row.exists()).toBe(true)
		wrapper.unmount()
	})

	it('renders Cost Center, Senior Manager, Finance decision data', async () => {
		financeApi.getCompletedSheet.mockResolvedValue(detail)
		const wrapper = mountDetail()
		await flushPromises()
		const row = wrapper.find('[data-testid="completed-tx-tx-1"]')
		expect(row.text()).toContain('Approved')
		wrapper.unmount()
	})

	it('renders evidence metadata button', async () => {
		financeApi.getCompletedSheet.mockResolvedValue(detail)
		const wrapper = mountDetail()
		await flushPromises()
		expect(wrapper.find('[data-testid="completed-tx-evidence-btn"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('shows no evidence when none exists', async () => {
		financeApi.getCompletedSheet.mockResolvedValue(detail)
		const wrapper = mountDetail()
		await flushPromises()
		expect(wrapper.find('[data-testid="completed-tx-evidence-none"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('does not render any mutation controls', async () => {
		financeApi.getCompletedSheet.mockResolvedValue(detail)
		const wrapper = mountDetail()
		await flushPromises()
		expect(wrapper.find('[data-testid="approve-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="reject-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="return-for-correction-btn"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('shows error state on load failure', async () => {
		financeApi.getCompletedSheet.mockRejectedValue(new Error('load failed'))
		const wrapper = mountDetail()
		await wrapper.vm.$nextTick()
		await flushPromises()
		expect(wrapper.find('[data-testid="completed-sheet-detail-error"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('closes detail and navigates back', async () => {
		const push = vi.fn()
		vi.mocked(useRouter).mockReturnValue({ push } as any)
		financeApi.getCompletedSheet.mockResolvedValue(detail)
		const wrapper = mountDetail()
		await flushPromises()
		await wrapper.find('[data-testid="completed-sheet-close"]').trigger('click')
		expect(push).toHaveBeenCalledWith({ name: 'finance-completed-sheets' })
		wrapper.unmount()
	})
})
