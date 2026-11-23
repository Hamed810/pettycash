import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const financeApi = vi.hoisted(() => ({
	listHolders: vi.fn(),
	listHolderAccounts: vi.fn(),
	fetchLedger: vi.fn(),
	listCompletedSheets: vi.fn(),
	getCompletedSheet: vi.fn(),
}))

vi.mock('@/services/financeApi', () => financeApi)

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	getLanguage: vi.fn(() => 'en'),
}))

import AccountLedgerTable from '@/components/finance/AccountLedgerTable.vue'
import { useFinanceStore } from '@/stores/finance'

const movements = [
	{
		id: 'mv-1',
		type: 'CHARGE',
		currency: 'IRR',
		minorUnit: 2,
		occurredAt: '2026-11-16T10:00:00+00:00',
		description: 'Recharge via bank transfer',
		recharge: { amountMinor: 500000, currency: 'IRR', minorUnit: 2 },
		expense: null,
		balanceAfter: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 },
		approved: null,
		sheet: null,
		sourceRevisionId: null,
	},
	{
		id: 'mv-2',
		type: 'CONSUMPTION',
		currency: 'IRR',
		minorUnit: 2,
		occurredAt: '2026-11-15T09:00:00+00:00',
		description: '',
		recharge: null,
		expense: { amountMinor: 200000, currency: 'IRR', minorUnit: 2 },
		balanceAfter: { amountMinor: 150000, currency: 'IRR', minorUnit: 2 },
		approved: { approvedCount: 3, totalCount: 10 },
		sheet: { id: 'sheet-1', reference: 'SHEET-001', title: 'Office supplies' },
		sourceRevisionId: 'rev-1',
	},
	{
		id: 'mv-3',
		type: 'CONSUMPTION',
		currency: 'IRR',
		minorUnit: 2,
		occurredAt: '2026-11-14T08:00:00+00:00',
		description: '',
		recharge: null,
		expense: { amountMinor: 100000, currency: 'IRR', minorUnit: 2 },
		balanceAfter: { amountMinor: 350000, currency: 'IRR', minorUnit: 2 },
		approved: { approvedCount: 0, totalCount: 5 },
		sheet: { id: 'sheet-2', reference: 'SHEET-002', title: 'Travel expenses' },
		sourceRevisionId: null,
	},
]

function mountLedger() {
	return mount(AccountLedgerTable, {
		props: { accountId: 'acc-1' },
		global: {},
	})
}

describe('AccountLedgerTable (T031)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		financeApi.fetchLedger.mockReset()
		const store = useFinanceStore()
		store.selectedAccountId = 'acc-1'
	})

	it('shows loading while ledger is in flight', async () => {
		financeApi.fetchLedger.mockReturnValue(new Promise(() => {}))
		const wrapper = mountLedger()
		await flushPromises()
		expect(wrapper.find('[data-testid="ledger-loading"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('renders populated ledger rows', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 3, lastMovementAt: '2026-11-16T10:00:00+00:00' },
			items: movements,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountLedger()
		await flushPromises()
		expect(wrapper.find('[data-testid="ledger-mv-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="ledger-mv-2"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="ledger-mv-3"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('renders exactly six columns', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 3, lastMovementAt: '2026-11-16T10:00:00+00:00' },
			items: movements,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountLedger()
		await flushPromises()
		const headers = wrapper.findAll('th')
		expect(headers.length).toBe(6)
		expect(headers.map((h) => h.text())).toEqual([
			'financeLedger.column.date',
			'financeLedger.column.description',
			'financeLedger.column.approved',
			'financeLedger.column.recharge',
			'financeLedger.column.expense',
			'financeLedger.column.balance',
		])
		wrapper.unmount()
	})

	it('shows date from occurredAt', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 3, lastMovementAt: '2026-11-16T10:00:00+00:00' },
			items: movements,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountLedger()
		await flushPromises()
		const dateCell = wrapper.find('[data-testid="ledger-mv-1"] [data-testid="ledger-date"]')
		expect(dateCell.exists()).toBe(true)
		wrapper.unmount()
	})

	it('shows sheet title+reference for consumption description', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 3, lastMovementAt: '2026-11-16T10:00:00+00:00' },
			items: movements,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountLedger()
		await flushPromises()
		const descCell = wrapper.find('[data-testid="ledger-mv-2"] [data-testid="ledger-description"]')
		expect(descCell.text()).toContain('financeLedger.sheet')
		wrapper.unmount()
	})

	it('shows stored charge description for recharge', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 3, lastMovementAt: '2026-11-16T10:00:00+00:00' },
			items: movements,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountLedger()
		await flushPromises()
		const descCell = wrapper.find('[data-testid="ledger-mv-1"] [data-testid="ledger-description"]')
		expect(descCell.text()).toContain('Recharge via bank transfer')
		wrapper.unmount()
	})

	it('shows approved count as "X of Y" for consumption', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 3, lastMovementAt: '2026-11-16T10:00:00+00:00' },
			items: movements,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountLedger()
		await flushPromises()
		const approvedCell = wrapper.find('[data-testid="ledger-mv-2"] [data-testid="ledger-approved"]')
		expect(approvedCell.text()).toContain('financeLedger.approvedPattern')
		wrapper.unmount()
	})

	it('shows N/A for approved on recharge rows', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 3, lastMovementAt: '2026-11-16T10:00:00+00:00' },
			items: movements,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountLedger()
		await flushPromises()
		const approvedCell = wrapper.find('[data-testid="ledger-mv-1"] [data-testid="ledger-approved"]')
		expect(approvedCell.text()).toContain('financeLedger.notApplicable')
		wrapper.unmount()
	})

	it('shows recharge amount only in Recharge column', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 3, lastMovementAt: '2026-11-16T10:00:00+00:00' },
			items: movements,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountLedger()
		await flushPromises()
		const rechargeCell = wrapper.find('[data-testid="ledger-mv-1"] [data-testid="ledger-recharge"]')
		const expenseCell = wrapper.find('[data-testid="ledger-mv-1"] [data-testid="ledger-expense"]')
		expect(rechargeCell.text()).toContain('5,000.00')
		expect(expenseCell.text()).toContain('—')
		wrapper.unmount()
	})

	it('shows expense amount only in Expense column', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 3, lastMovementAt: '2026-11-16T10:00:00+00:00' },
			items: movements,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountLedger()
		await flushPromises()
		const rechargeCell = wrapper.find('[data-testid="ledger-mv-2"] [data-testid="ledger-recharge"]')
		const expenseCell = wrapper.find('[data-testid="ledger-mv-2"] [data-testid="ledger-expense"]')
		expect(rechargeCell.text()).toContain('—')
		expect(expenseCell.text()).toContain('2,000.00')
		wrapper.unmount()
	})

	it('shows server-provided balance in Balance column', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 3, lastMovementAt: '2026-11-16T10:00:00+00:00' },
			items: movements,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountLedger()
		await flushPromises()
		const balanceCell = wrapper.find('[data-testid="ledger-mv-1"] [data-testid="ledger-balance"]')
		expect(balanceCell.text()).toContain('6,500.00')
		wrapper.unmount()
	})

	it('shows empty state when no movements', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 0, lastMovementAt: null },
			items: [],
			page: 1,
			pageSize: 25,
			total: 0,
		})
		const wrapper = mountLedger()
		await flushPromises()
		expect(wrapper.find('[data-testid="ledger-empty"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('paginates when more than one page', async () => {
		financeApi.fetchLedger.mockResolvedValue({
			account: { id: 'acc-1', userId: 'uid-1', currency: 'IRR', minorUnit: 2, balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, active: true, version: 4, movementCount: 3, lastMovementAt: '2026-11-16T10:00:00+00:00' },
			items: movements,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountLedger()
		await flushPromises()
		expect(wrapper.find('[data-testid="ledger-pagination"]').exists()).toBe(false)
		wrapper.unmount()
	})
})
