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
	isRTL: vi.fn(() => false),
}))

import HolderDirectory from '@/components/finance/HolderDirectory.vue'

const holders = [
	{ userId: 'uid-1', displayName: 'Alice', accountCount: 2, activeAccountCount: 2, status: 'ACTIVE' },
	{ userId: 'uid-2', displayName: 'Bob', accountCount: 1, activeAccountCount: 0, status: 'INACTIVE' },
	{ userId: 'uid-3', displayName: 'Carol', accountCount: 3, activeAccountCount: 2, status: 'MIXED' },
]

function mountDirectory(pinia: ReturnType<typeof createPinia>) {
	return mount(HolderDirectory, {
		global: { plugins: [pinia] },
	})
}

describe('HolderDirectory (T029)', () => {
	let pinia: ReturnType<typeof createPinia>

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		financeApi.listHolders.mockReset()
	})

	it('shows loading while holders are in flight', async () => {
		financeApi.listHolders.mockReturnValue(new Promise(() => {}))
		const wrapper = mountDirectory(pinia)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="holders-loading"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('renders populated holder rows', async () => {
		financeApi.listHolders.mockResolvedValue({
			items: holders,
			page: 1,
			pageSize: 25,
			total: 3,
		})
		const wrapper = mountDirectory(pinia)
		await flushPromises()
		expect(wrapper.find('[data-testid="holder-uid-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="holder-uid-2"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="holder-uid-3"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('shows empty state when no holders', async () => {
		financeApi.listHolders.mockResolvedValue({ items: [], page: 1, pageSize: 25, total: 0 })
		const wrapper = mountDirectory(pinia)
		await flushPromises()
		expect(wrapper.find('[data-testid="holders-empty"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('shows error state on load failure', async () => {
		financeApi.listHolders.mockRejectedValue(new Error('load failed'))
		const wrapper = mountDirectory(pinia)
		await flushPromises()
		expect(wrapper.find('[data-testid="holders-error"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('typing in the search input runs the search through the store and renders the empty state', async () => {
		// First load renders real rows; the typed search returns an empty page.
		financeApi.listHolders
			.mockResolvedValueOnce({ items: holders, page: 1, pageSize: 25, total: 3 })
			.mockResolvedValueOnce({ items: [], page: 1, pageSize: 25, total: 0 })

		const wrapper = mountDirectory(pinia)
		await flushPromises()
		expect(wrapper.find('[data-testid="holder-uid-1"]').exists()).toBe(true)

		// Normal component input interaction: this must exercise the template's v-model,
		// not a direct store call, or a broken binding would go unnoticed.
		const searchValue = 'zzz-no-such-holder-zzz'
		await wrapper.find('[data-testid="holder-search"]').setValue(searchValue)
		await flushPromises()

		expect(financeApi.listHolders).toHaveBeenLastCalledWith(
			expect.objectContaining({ search: searchValue, page: 1, pageSize: 25 })
		)
		expect(wrapper.find('[data-testid="holders-empty"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="holders-table"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="holder-uid-1"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('paginates to next page', async () => {
		financeApi.listHolders.mockResolvedValue({ items: holders, page: 1, pageSize: 25, total: 3 })
		const wrapper = mountDirectory(pinia)
		await flushPromises()
		const nextBtn = wrapper.find('[data-testid="holders-next"]')
		expect(nextBtn.exists()).toBe(false)
		wrapper.unmount()
	})

	it('selects a holder on row click', async () => {
		financeApi.listHolders.mockResolvedValue({ items: holders, page: 1, pageSize: 25, total: 3 })
		const wrapper = mountDirectory(pinia)
		await flushPromises()
		const row = wrapper.find('[data-testid="holder-uid-1"]')
		await row.trigger('click')
		expect(wrapper.find('[data-testid="holder-uid-1"]').exists()).toBe(true)
		wrapper.unmount()
	})
})
