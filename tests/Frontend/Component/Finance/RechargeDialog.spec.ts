import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(params?.[key] ?? `{${key}}`))),
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
		template: '<div v-if="open" role="dialog" data-test="nc-dialog" :aria-label="name"><h2 data-test="nc-dialog-heading">{{ name }}</h2><slot /></div>'
	}
	return { NcButton: button, NcDialog: dialog }
})

const financeApi = vi.hoisted(() => ({
	listHolderAccounts: vi.fn(),
	fetchLedger: vi.fn()
}))

vi.mock('@/services/financeApi', () => financeApi)

vi.mock('@/services/accountsApi', () => ({
	chargeAccount: vi.fn()
}))

import { chargeAccount } from '@/services/accountsApi'
import { listHolderAccounts, fetchLedger } from '@/services/financeApi'
import { useFinanceStore } from '@/stores/finance'
import type { AccountMutationResult, FinanceLedgerPage, HolderAccountSummary } from '@/types/api'
import { ApiError } from '@/types/api'
import HolderAccountList from '@/components/finance/HolderAccountList.vue'

const chargeAccountMock = vi.mocked(chargeAccount)
const listHolderAccountsMock = vi.mocked(listHolderAccounts)
const fetchLedgerMock = vi.mocked(fetchLedger)

function account(overrides: Partial<HolderAccountSummary> = {}): HolderAccountSummary {
	return {
		id: 'acc-1',
		userId: 'uid-1',
		currency: 'IRR',
		minorUnit: 2,
		balance: { amountMinor: 150000, currency: 'IRR', minorUnit: 2 },
		active: true,
		version: 3,
		movementCount: 2,
		lastMovementAt: '2026-11-15T10:00:00+00:00',
		...overrides
	}
}

function chargeResult(overrides: Partial<AccountMutationResult> = {}): AccountMutationResult {
	return {
		account: { ...account(), balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 }, version: 4 },
		movement: {
			id: 'mv-1',
			type: 'CHARGE',
			amount: { amountMinor: 500000, currency: 'IRR', minorUnit: 2 },
			balanceAfter: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 },
			description: 'Recharge',
			occurredAt: '2026-11-16T10:00:00+00:00',
			sourceRevisionId: null
		},
		...overrides
	}
}

function ledgerPage(): FinanceLedgerPage {
	return { account: account({ id: 'acc-1' }), items: [], page: 1, pageSize: 25, total: 0 }
}

async function settle(): Promise<void> {
	await flushPromises()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await flushPromises()
}

async function mountAccountList(accounts: HolderAccountSummary[]) {
	const store = useFinanceStore()
	store.selectedHolderUid = 'uid-1'
	store.selectedHolderName = 'Alice'
	listHolderAccountsMock.mockResolvedValue({
		holder: { userId: 'uid-1', displayName: 'Alice' },
		items: accounts,
		page: 1,
		pageSize: 25,
		total: accounts.length
	})
	const wrapper = mount(HolderAccountList, { global: {} })
	await flushPromises()
	return { wrapper, store }
}

async function openAndConfirm(wrapper: VueWrapper, amount = '10.00'): Promise<void> {
	await wrapper.find('[data-testid="account-acc-1"] [data-testid="account-recharge"]').trigger('click')
	await flushPromises()
	await wrapper.find('[data-testid="recharge-amount-input"]').setValue(amount)
	await flushPromises()
	await wrapper.find('[data-testid="recharge-button-review"]').trigger('click')
	await flushPromises()
	await wrapper.find('[data-testid="recharge-button-confirm"]').trigger('click')
	await flushPromises()
}

describe('RechargeDialog via HolderAccountList (T027)', () => {
	let pinia: Pinia

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		chargeAccountMock.mockReset()
		listHolderAccountsMock.mockReset()
		fetchLedgerMock.mockReset()
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('opens the recharge dialog for an active account', async () => {
		const { wrapper } = await mountAccountList([account(), account({ id: 'acc-2', active: false })])
		await wrapper.find('[data-testid="account-acc-1"] [data-testid="account-recharge"]').trigger('click')
		await flushPromises()
		expect(wrapper.findAll('[role="dialog"]')).toHaveLength(1)
		expect(wrapper.find('[data-testid="recharge-step-amount"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('offers no executable recharge action for an inactive account', async () => {
		const { wrapper } = await mountAccountList([account(), account({ id: 'acc-2', active: false })])
		const row = wrapper.find('[data-testid="account-acc-2"]')
		expect(row.find('[data-testid="account-recharge"]').exists()).toBe(false)
		expect(row.find('[data-testid="account-recharge-disabled"]').exists()).toBe(true)
		expect(wrapper.findAll('[role="dialog"]')).toHaveLength(0)
		wrapper.unmount()
	})

	it('blocks review on an invalid amount and sends no POST', async () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		const { wrapper, store } = await mountAccountList([account()])
		await wrapper.find('[data-testid="account-acc-1"] [data-testid="account-recharge"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="recharge-amount-input"]').setValue('abc')
		await flushPromises()
		expect(store.rechargeValidationReason).toBe('malformed')
		expect(store.canConfirm).toBe(false)
		expect(store.rechargeStatus).toBe('idle')
		await wrapper.find('[data-testid="recharge-button-review"]').trigger('click')
		await flushPromises()
		expect(store.rechargeStatus).toBe('idle')
		expect(chargeAccountMock).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('reaches review with a valid amount', async () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		const { wrapper, store } = await mountAccountList([account()])
		await wrapper.find('[data-testid="account-acc-1"] [data-testid="account-recharge"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="recharge-amount-input"]').setValue('10.00')
		await flushPromises()
		expect(store.rechargeAmountMinor).toBe(1000)
		await wrapper.find('[data-testid="recharge-button-review"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="recharge-step-review"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('sends no POST when entering review', async () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		const { wrapper } = await mountAccountList([account()])
		await wrapper.find('[data-testid="account-acc-1"] [data-testid="account-recharge"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="recharge-amount-input"]').setValue('10.00')
		await flushPromises()
		await wrapper.find('[data-testid="recharge-button-review"]').trigger('click')
		await flushPromises()
		expect(chargeAccountMock).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('shows account, currency, amount and balance in review', async () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		const { wrapper } = await mountAccountList([account()])
		await wrapper.find('[data-testid="account-acc-1"] [data-testid="account-recharge"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="recharge-amount-input"]').setValue('10.00')
		await flushPromises()
		await wrapper.find('[data-testid="recharge-button-review"]').trigger('click')
		await flushPromises()
		const review = wrapper.find('[data-testid="recharge-step-review"]')
		expect(review.text()).toContain('acc-1')
		expect(review.text()).toContain('IRR')
		expect(review.text()).toContain('10.00 IRR')
		expect(review.text()).toContain('1,500.00 IRR')
		wrapper.unmount()
	})

	it('calls the store submission exactly once on explicit confirm', async () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		const { wrapper } = await mountAccountList([account()])
		await openAndConfirm(wrapper)
		expect(chargeAccountMock).toHaveBeenCalledTimes(1)
		wrapper.unmount()
	})

	it('does not re-submit while submitting and reaches success', async () => {
		let resolveCharge!: (value: AccountMutationResult) => void
		const pending = new Promise<AccountMutationResult>((res) => {
			resolveCharge = res
		})
		chargeAccountMock.mockReturnValue(pending)
		const { wrapper, store } = await mountAccountList([account()])
		await wrapper.find('[data-testid="account-acc-1"] [data-testid="account-recharge"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="recharge-amount-input"]').setValue('10.00')
		await flushPromises()
		await wrapper.find('[data-testid="recharge-button-review"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="recharge-button-confirm"]').trigger('click')
		await flushPromises()
		expect(chargeAccountMock).toHaveBeenCalledTimes(1)
		expect(store.rechargeStatus).toBe('submitting')
		expect(wrapper.find('[data-testid="recharge-button-confirm"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="recharge-step-submitting"]').exists()).toBe(true)
		resolveCharge(chargeResult())
		await settle()
		expect(chargeAccountMock).toHaveBeenCalledTimes(1)
		expect(store.rechargeStatus).toBe('success')
		wrapper.unmount()
	})

	it('shows the server-authoritative balance on success', async () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		const { wrapper, store } = await mountAccountList([account()])
		await openAndConfirm(wrapper)
		await settle()
		expect(store.rechargeStatus).toBe('success')
		const success = wrapper.find('[data-testid="recharge-step-success"]')
		expect(success.exists()).toBe(true)
		expect(success.text()).toContain('financeRecharge.state.success')
		expect(success.text()).toContain('6,500.00 IRR')
		expect(success.text()).not.toContain('1,510.00 IRR')
		expect(store.rechargeBalance?.amountMinor).toBe(650000)
		wrapper.unmount()
	})

	it('refreshes holder accounts and ledger page 1 after success', async () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		fetchLedgerMock.mockResolvedValue(ledgerPage())
		const { wrapper, store } = await mountAccountList([account()])
		store.selectedAccountId = 'acc-1'
		await openAndConfirm(wrapper)
		await settle()
		expect(listHolderAccountsMock).toHaveBeenCalledTimes(2)
		expect(fetchLedgerMock).toHaveBeenCalledWith('acc-1', { page: 1, pageSize: 25 })
		wrapper.unmount()
	})

	it('keeps a definitive failure as error, never success', async () => {
		chargeAccountMock.mockRejectedValue(new Error('pettycash.error.forbidden'))
		const { wrapper, store } = await mountAccountList([account()])
		await openAndConfirm(wrapper)
		await settle()
		expect(store.rechargeStatus).toBe('error')
		expect(wrapper.find('[data-testid="recharge-step-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="recharge-error"]').text()).toContain('pettycash.error.forbidden')
		expect(store.rechargeBalance?.amountMinor).toBe(150000)
		wrapper.unmount()
	})

	it('exposes safe retry for an unknown network result', async () => {
		chargeAccountMock.mockRejectedValue(new ApiError(0, { code: 'NETWORK_FAILURE', message: 'pettycash.error.network' }))
		const { wrapper, store } = await mountAccountList([account()])
		await openAndConfirm(wrapper)
		await settle()
		expect(store.rechargeStatus).toBe('unknown')
		expect(wrapper.find('[data-testid="recharge-step-unknown"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="recharge-button-retry"]').text()).toContain('financeRecharge.button.retry')
		wrapper.unmount()
	})

	it('retry reuses the same command id via the store', async () => {
		chargeAccountMock.mockRejectedValueOnce(new ApiError(0, { code: 'NETWORK_FAILURE', message: 'pettycash.error.network' }))
		chargeAccountMock.mockResolvedValue(chargeResult())
		const { wrapper, store } = await mountAccountList([account()])
		await openAndConfirm(wrapper)
		await settle()
		const firstId = chargeAccountMock.mock.calls[0]![2]?.commandId
		expect(firstId).toBeDefined()
		await wrapper.find('[data-testid="recharge-button-retry"]').trigger('click')
		await settle()
		expect(chargeAccountMock).toHaveBeenCalledTimes(2)
		expect(chargeAccountMock.mock.calls[1]![2]?.commandId).toBe(firstId)
		expect(store.rechargeStatus).toBe('success')
		wrapper.unmount()
	})

	it('cancel/close clears the recharge draft', async () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		const { wrapper, store } = await mountAccountList([account()])
		await wrapper.find('[data-testid="account-acc-1"] [data-testid="account-recharge"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="recharge-amount-input"]').setValue('10.00')
		await flushPromises()
		expect(store.rechargeAccountId).toBe('acc-1')
		expect(store.rechargeAmountMinor).toBe(1000)
		await wrapper.find('[data-testid="recharge-button-cancel"]').trigger('click')
		await flushPromises()
		expect(store.rechargeAccountId).toBeNull()
		expect(store.rechargeAmountText).toBe('')
		expect(store.rechargeAmountMinor).toBeNull()
		expect(store.rechargeStatus).toBe('idle')
		expect(store.rechargeCommandId).toBeNull()
		expect(wrapper.findAll('[role="dialog"]')).toHaveLength(0)
		wrapper.unmount()
	})

	it('switching account clears the recharge draft', async () => {
		const { wrapper, store } = await mountAccountList([
			account(),
			account({ id: 'acc-2', active: true, userId: 'uid-1', version: 5 })
		])
		fetchLedgerMock.mockResolvedValue(ledgerPage())
		await wrapper.find('[data-testid="account-acc-1"] [data-testid="account-recharge"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="recharge-amount-input"]').setValue('10.00')
		await flushPromises()
		expect(store.rechargeAccountId).toBe('acc-1')
		expect(store.rechargeStatus).toBe('idle')
		store.selectAccount('acc-2')
		expect(store.rechargeAccountId).toBeNull()
		expect(store.rechargeStatus).toBe('idle')
		expect(store.rechargeCommandId).toBeNull()
		wrapper.unmount()
	})
})
