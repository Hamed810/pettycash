// QuickDecisionModal — RTL mixed-direction value isolation (BUG-04).
//
// The shared decision confirmation renders LTR values (the sheet UUID reference and the money
// amount) inside a Persian RTL modal. BUG-04: those values were wrapped in a bare `<bdi>`, which
// only isolates with the base (RTL) direction, so the LTR tokens rendered in the wrong order. The
// fix puts `dir="ltr"` on the reference and amount `<bdi>`s (matching the parent senior modal's
// `senior-modal__reference` pattern) while the surrounding Persian text stays RTL.
//
// jsdom cannot measure bidi geometry — this spec pins the authoritative attributes (the same source
// the run-time unit produces); the live Chrome guard adds the geometric/bounds check.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import QuickDecisionModal from '@/components/review/QuickDecisionModal.vue'

const l10n = vi.hoisted(() => ({
	t: vi.fn((_app: string, text: string) => text),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'fa'),
	isRTL: vi.fn(() => true),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

vi.mock('@nextcloud/l10n', () => l10n)

// `direction` is a computed reading the active language; drive a real ref so the modal presents RTL.
const directionHolder = vi.hoisted(() => ({
	direction: null as null | { value: 'ltr' | 'rtl' }
}))

vi.mock('@/utils/direction', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/utils/direction')>()
	const { ref } = await import('vue')
	const controlled = ref<'ltr' | 'rtl'>('rtl')
	directionHolder.direction = controlled
	return { ...actual, direction: controlled }
})

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading'],
		emits: ['click'],
		template: '<button type="button" :disabled="disabled" v-bind="$attrs" @click="$emit(\'click\', $event)"><slot /></button>'
	},
	NcIconSvgWrapper: {
		name: 'NcIconSvgWrapper',
		props: ['path', 'size'],
		template: '<span data-test="nc-icon" />'
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		props: ['size'],
		template: '<span data-test="nc-loading" />'
	}
}))

const SHEET_REF = '630a6e00-f7ca-43d7-9bb4-002ce19bfc6b'

function mountModal(overrides: Record<string, unknown> = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	return mount(QuickDecisionModal, {
		props: {
			open: true,
			decision: 'APPROVE',
			transaction: {
				id: '2d671926-372f-48ca-81f1-1865386c28c2',
				title: 'B4 taxi to client site',
				sheetReference: SHEET_REF,
				amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
				expenseDate: '2026-10-01',
			},
			allowedActions: { canApprove: true, canReject: true, canReturn: true },
			...overrides,
		},
		global: { plugins: [pinia] },
		attachTo: document.body,
	})
}

describe('QuickDecisionModal RTL bidi (BUG-04)', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	it('renders the LTR sheet reference and amount as LTR-isolated tokens inside the RTL modal', () => {
		const wrapper = mountModal()
		const layer = wrapper.get('[data-testid="decision-modal-layer"]')
		expect(layer.attributes('dir')).toBe('rtl')

		// The reference appears twice (eyebrow + summary meta); both must be pinned LTR like the
		// parent senior modal's `<bdi dir="ltr">`, not merely isolated under the RTL base direction.
		const references = wrapper.findAll('.decision-modal__eyebrow bdi, .decision-modal__summary-meta bdi')
		expect(references).toHaveLength(2)
		for (const ref of references) {
			expect(ref.attributes('dir'), `reference bdi must be dir=ltr (rendered '${ref.text()}', wrong ordering)`)
				.toBe('ltr')
			expect(ref.text()).toBe(SHEET_REF)
		}

		// The amount keeps the same pinned-LTR treatment (locale digits may be Persian — ۵۰٬۰۰۰ IRR).
		const amount = wrapper.get('[data-testid="decision-modal-amount"] bdi')
		expect(amount.attributes('dir')).toBe('ltr')
		expect(amount.text()).toContain('IRR')

		// The transaction-id isolate keeps its existing explicit `dir="ltr"`.
		expect(wrapper.get('[data-testid="decision-modal-transaction-id"]').attributes('dir')).toBe('ltr')
	})

	it('keeps every `<bdi>` an explicit direction so no bare RTL default remains', () => {
		const wrapper = mountModal()
		const bdis = wrapper.findAll('[data-testid="decision-modal-layer"] bdi')
		expect(bdis.length).toBeGreaterThanOrEqual(4)
		for (const bdi of bdis) {
			expect(bdi.attributes('dir'), `bdi '${bdi.text()}…' must pin its direction (BUG-04)`).toBe('ltr')
		}
	})
})
