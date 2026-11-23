import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useFinanceStore } from '@/stores/finance'

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
	getLanguage: vi.fn(() => 'en')
}))

vi.mock('@nextcloud/vue', () => {
	const button = {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading', 'type', 'size'],
		emits: ['click'],
		template: '<button type="button" v-bind="$attrs" :disabled="disabled || loading" @click="$emit(\'click\', $event)"><slot /></button>'
	}
	const dialog = {
		name: 'NcDialog',
		props: ['open', 'name', 'size', 'noClose', 'closeOnClickOutside'],
		emits: ['update:open'],
		template: '<div v-if="open" role="dialog" data-test="nc-dialog" :aria-label="name"><slot /></div>'
	}
	return { NcButton: button, NcDialog: dialog }
})


import HolderAccountList from '@/components/finance/HolderAccountList.vue'

const accounts = [
	{
		id: 'acc-1',
		userId: 'uid-1',
		currency: 'IRR',
		minorUnit: 2,
		balance: { amountMinor: 150000, currency: 'IRR', minorUnit: 2 },
		active: true,
		version: 3,
		movementCount: 5,
		lastMovementAt: '2026-11-15T10:00:00+00:00',
	},
	{
		id: 'acc-2',
		userId: 'uid-1',
		currency: 'IRR',
		minorUnit: 2,
		balance: { amountMinor: -50000, currency: 'IRR', minorUnit: 2 },
		active: false,
		version: 1,
		movementCount: 0,
		lastMovementAt: null,
	},
]

function mountAccountList() {
	return mount(HolderAccountList, {
		global: {},
	})
}

describe('HolderAccountList (T030)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		financeApi.listHolderAccounts.mockReset()
	})

	it('renders active accounts', async () => {
		const store = useFinanceStore()
		store.selectedHolderUid = 'uid-1'
		financeApi.listHolderAccounts.mockResolvedValue({
			holder: { userId: 'uid-1', displayName: 'Alice' },
			items: accounts,
			page: 1,
			pageSize: 25,
			total: 2,
		})
		const wrapper = mountAccountList()
		await flushPromises()
		expect(wrapper.find('[data-testid="account-acc-1"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('financeAccounts.status.active')
		wrapper.unmount()
	})

	it('renders inactive accounts', async () => {
		const store = useFinanceStore()
		store.selectedHolderUid = 'uid-1'
		financeApi.listHolderAccounts.mockResolvedValue({
			holder: { userId: 'uid-1', displayName: 'Alice' },
			items: accounts,
			page: 1,
			pageSize: 25,
			total: 2,
		})
		const wrapper = mountAccountList()
		await flushPromises()
		expect(wrapper.find('[data-testid="account-acc-2"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('financeAccounts.status.inactive')
		wrapper.unmount()
	})

	it('shows balance via BalanceAmount', async () => {
		const store = useFinanceStore()
		store.selectedHolderUid = 'uid-1'
		financeApi.listHolderAccounts.mockResolvedValue({
			holder: { userId: 'uid-1', displayName: 'Alice' },
			items: accounts,
			page: 1,
			pageSize: 25,
			total: 2,
		})
		const wrapper = mountAccountList()
		await flushPromises()
		const row = wrapper.find('[data-testid="account-acc-1"]')
		expect(row.text()).toContain('1,500.00 IRR')
		wrapper.unmount()
	})

	it('shows owed label for negative balance', async () => {
		const store = useFinanceStore()
		store.selectedHolderUid = 'uid-1'
		financeApi.listHolderAccounts.mockResolvedValue({
			holder: { userId: 'uid-1', displayName: 'Alice' },
			items: accounts,
			page: 1,
			pageSize: 25,
			total: 2,
		})
		const wrapper = mountAccountList()
		await flushPromises()
		const row = wrapper.find('[data-testid="account-acc-2"]')
		expect(row.text()).toContain('financeBalance.owedLabel')
		wrapper.unmount()
	})

	it('shows recharge button for active accounts only', async () => {
		const store = useFinanceStore()
		store.selectedHolderUid = 'uid-1'
		financeApi.listHolderAccounts.mockResolvedValue({
			holder: { userId: 'uid-1', displayName: 'Alice' },
			items: accounts,
			page: 1,
			pageSize: 25,
			total: 2,
		})
		const wrapper = mountAccountList()
		await flushPromises()
		const activeRow = wrapper.find('[data-testid="account-acc-1"]')
		expect(activeRow.find('[data-testid="account-recharge"]').exists()).toBe(true)
		const inactiveRow = wrapper.find('[data-testid="account-acc-2"]')
		expect(inactiveRow.find('[data-testid="account-recharge-disabled"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('selects an account on row click', async () => {
		const store = useFinanceStore()
		store.selectedHolderUid = 'uid-1'
		financeApi.listHolderAccounts.mockResolvedValue({
			holder: { userId: 'uid-1', displayName: 'Alice' },
			items: accounts,
			page: 1,
			pageSize: 25,
			total: 2,
		})
		const wrapper = mountAccountList()
		await flushPromises()
		await wrapper.find('[data-testid="account-acc-2"]').trigger('click')
		expect(wrapper.find('[data-testid="account-acc-2"]').exists()).toBe(true)
		wrapper.unmount()
	})
})
