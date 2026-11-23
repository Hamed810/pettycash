import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BalanceAmount from '@/components/finance/BalanceAmount.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'en'),
}))

function money(amountMinor: number, currency = 'IRR', minorUnit = 2) {
	return { amountMinor, currency, minorUnit }
}

describe('BalanceAmount (T032)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('renders a positive balance as-is', () => {
		const wrapper = mount(BalanceAmount, { props: { balance: money(500000), minorUnit: 2 } })
		expect(wrapper.find('[data-testid="balance"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="balance-owed"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('5,000.00 IRR')
	})

	it('renders a zero balance as-is', () => {
		const wrapper = mount(BalanceAmount, { props: { balance: money(0), minorUnit: 2 } })
		expect(wrapper.find('[data-testid="balance"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="balance-owed"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('0.00 IRR')
	})

	it('renders a negative balance with magnitude and owed label', () => {
		const wrapper = mount(BalanceAmount, { props: { balance: money(-500000), minorUnit: 2 } })
		expect(wrapper.find('[data-testid="balance-owed"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="balance"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('5,000.00 IRR')
		expect(wrapper.text()).toContain('financeBalance.owedLabel')
	})

	it('does not clamp a negative balance to zero', () => {
		const wrapper = mount(BalanceAmount, { props: { balance: money(-100, 'IRR', 2), minorUnit: 2 } })
		expect(wrapper.text()).toContain('1.00 IRR')
		expect(wrapper.text()).toContain('financeBalance.owedLabel')
	})

	it('does not flip the sign in state — amountMinor remains negative', () => {
		const wrapper = mount(BalanceAmount, { props: { balance: money(-500000), minorUnit: 2 } })
		expect(wrapper.props('balance').amountMinor).toBe(-500000)
	})
})
