// T030/T031/T032 — the read-only transaction details drawer.
//
// The drawer renders either a value the server returned or its explicit unavailable state —
// merchant, paid-on, payment method and category are not in the required-capability scope and must
// never be fabricated. Its decision controls are the drawer's only writes and they emit to the
// shared confirmation workflow rather than posting anything. A refusal, an absence and a failure of
// the detail read stay three different states.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import TransactionDetailsDrawer from '@/components/review/TransactionDetailsDrawer.vue'
import type {
	ManagerSheetSummary,
	ManagerTransactionReadState,
	ManagerTransactionState,
	TransactionDetail,
} from '@/types/api'

const l10n = vi.hoisted(() => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'en'),
	isRTL: vi.fn(() => false),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

vi.mock('@nextcloud/l10n', () => l10n)

// The drawer reads `direction` from this module (a cached computed), so the test controls a real
// ref directly rather than trying to re-derive the cached value through the language readers. The
// ref is created inside the mock factory (vue is available there through the dynamic import) and
// published on this holder so the tests can drive it.
const directionHolder = vi.hoisted(() => ({
	direction: null as null | { value: 'ltr' | 'rtl' }
}))

vi.mock('@/utils/direction', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/utils/direction')>()
	const { ref } = await import('vue')
	const controlled = ref<'ltr' | 'rtl'>('ltr')
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

function detail(overrides: Partial<TransactionDetail> = {}): TransactionDetail {
	return {
		id: 'tx-1',
		sheetId: 'sheet-1',
		stage: 'COST_CENTER',
		status: 'AWAITING_COST_CENTER_REVIEW',
		finalOutcome: null,
		evidenceRequired: true,
		evidenceCount: 2,
		version: 4,
		activeRevision: {
			id: 'rev-1',
			number: 1,
			expenseDate: '2026-09-20',
			amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
			description: 'Taxi to client site',
			costCenter: { id: 'cc-1', code: 'CC-42', name: 'Operations' },
			expenseType: { id: 'et-1', name: 'Travel' },
			createdBy: 'user1',
			createdAt: '2026-09-20T10:00:00Z',
			active: true
		},
		allowedActions: { canApprove: true, canReject: true, canReturn: true },
		decisions: [
			{
				id: 'd-1',
				stage: 'COST_CENTER',
				decision: 'RETURN',
				explanation: 'Attach the itemised receipt.',
				actor: { userId: 'manager1', displayName: 'Manager One', role: 'COST_CENTER_MANAGER' },
				decidedAt: '2026-09-21T10:30:00Z'
			}
		],
		evidence: [
			{
				id: 'ev-1', transactionId: 'tx-1', revisionId: 'rev-1', name: 'receipt.png',
				mediaType: 'image/png', sizeBytes: 2048, active: true,
				uploadedBy: 'user1', uploadedAt: '2026-09-20T10:00:00Z'
			},
			{
				id: 'ev-2', transactionId: 'tx-1', revisionId: 'rev-1', name: 'invoice.pdf',
				mediaType: 'application/pdf', sizeBytes: 4096, active: true,
				uploadedBy: 'user1', uploadedAt: '2026-09-20T10:05:00Z'
			}
		],
		...overrides
	} as TransactionDetail
}

function sheet(overrides: Partial<ManagerSheetSummary> = {}): ManagerSheetSummary {
	return {
		id: 'sheet-1',
		title: 'September travel',
		ownerId: 'user1',
		ownerDisplayName: 'User One',
		currency: 'IRR',
		status: 'COST_CENTER',
		submittedTotalMinor: 50000,
		submittedTotal: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
		submittedAt: '2026-09-20T10:00:00Z',
		version: 1,
		...overrides
	}
}

interface MountOptions {
	transaction?: TransactionDetail | null
	managerState?: ManagerTransactionState
	readState?: ManagerTransactionReadState
	sheet?: ManagerSheetSummary | null
}

function mountDrawer(options: MountOptions = {}) {
	const {
		transaction = detail(),
		managerState = 'AWAITING_MANAGER_DECISION',
		readState = 'loaded',
		sheet: sheetSummary = sheet()
	} = options
	return mount(TransactionDetailsDrawer, {
		props: {
			open: false,
			transaction,
			managerState,
			managerDecision: null,
			sheet: sheetSummary,
			readState
		},
		attachTo: document.body
	})
}

async function open(wrapper: Awaited<ReturnType<typeof mountDrawer>>) {
	await wrapper.setProps({ open: true })
	return wrapper.get('[data-testid="details-drawer"]')
}

describe('TransactionDetailsDrawer (T030/T031/T032)', () => {
	beforeEach(() => {
		l10n.t.mockClear()
	})

	it('renders the real fields: description, amount, expense date, sheet, purchaser and cost centre', async () => {
		const wrapper = mountDrawer()
		const drawer = await open(wrapper)

		expect(drawer.find('[data-testid="details-drawer-title"]').text()).toBe('Taxi to client site')
		expect(drawer.find('[data-testid="details-drawer-transaction-id"]').text()).toBe('tx-1')
		expect(drawer.find('[data-testid="details-drawer-amount"]').text()).toContain('50,000')
		expect(drawer.find('[data-testid="details-drawer-expense-date"]').exists()).toBe(true)
		expect(drawer.find('[data-testid="drawer-field-sheet"]').text()).toContain('September travel')
		expect(drawer.find('[data-testid="drawer-field-purchaser"]').text()).toContain('User One')
		expect(drawer.find('[data-testid="drawer-field-cost-center"]').text()).toContain('CC-42')
		expect(drawer.find('[data-testid="drawer-description"]').text()).toBe('Taxi to client site')
	})

	it('states which fields the server does not deliver instead of inventing them', async () => {
		const wrapper = mountDrawer()
		const drawer = await open(wrapper)

		for (const testid of ['drawer-field-merchant', 'drawer-field-paid-on', 'drawer-field-method', 'drawer-field-category']) {
			const field = drawer.find(`[data-testid="${testid}"]`)
			expect(field.exists(), `${testid} must render`).toBe(true)
			expect(field.text()).toContain('reviews.sheetQueue.unavailableField')
		}
	})

	it('falls back honestly when sheet title and purchaser are absent', async () => {
		const wrapper = mountDrawer({ sheet: sheet({ title: '  ', ownerDisplayName: '' }) })
		const drawer = await open(wrapper)

		expect(drawer.find('[data-testid="drawer-field-sheet"]').text()).toContain('reviews.sheetQueue.sheetTitleUnavailable')
		expect(drawer.find('[data-testid="drawer-field-purchaser"]').text()).toContain('reviews.sheetQueue.purchaserUnavailable')
	})

	it('switches between the description and history tabs by click and arrow keys', async () => {
		const wrapper = mountDrawer()
		const drawer = await open(wrapper)

		expect(drawer.find('[data-testid="drawer-panel-history"]').isVisible()).toBe(false)
		expect(drawer.find('[data-testid="drawer-panel-description"]').isVisible()).toBe(true)

		await drawer.find('[data-testid="drawer-tab-history"]').trigger('click')
		expect(drawer.find('[data-testid="drawer-panel-history"]').isVisible()).toBe(true)
		// The prior decision history renders the server's own decision records.
		expect(drawer.find('[data-testid="drawer-panel-history"]').text()).toContain('Attach the itemised receipt.')

		const descriptionTab = drawer.find('[data-testid="drawer-tab-description"]')
		await descriptionTab.trigger('keydown', { key: 'ArrowRight' })
		expect(drawer.find('[data-testid="drawer-panel-history"]').isVisible()).toBe(true)
	})

	it('renders the returned evidence set and emits preview for an attachment', async () => {
		const wrapper = mountDrawer()
		const drawer = await open(wrapper)

		const attachments = drawer.find('[data-testid="drawer-attachments"]')
		expect(attachments.exists()).toBe(true)
		expect(attachments.findAll('li')).toHaveLength(2)

		await drawer.find('[data-testid="drawer-attachment-ev-2"]').trigger('click')
		expect(wrapper.emitted('preview')).toEqual([[detail().evidence[1]]])
	})

	it('renders an empty evidence state, never a fabricated row', async () => {
		const wrapper = mountDrawer({ transaction: detail({ evidence: [] }) })
		const drawer = await open(wrapper)

		expect(drawer.find('[data-testid="drawer-no-attachments"]').exists()).toBe(true)
		expect(drawer.find('[data-testid="drawer-attachments"]').exists()).toBe(false)
	})

	it('offers exactly the decisions the server granted, only while awaiting', async () => {
		const wrapper = mountDrawer()
		const drawer = await open(wrapper)

		expect((drawer.find('[data-testid="drawer-decide-approve"]').element as HTMLButtonElement).disabled).toBe(false)
		expect((drawer.find('[data-testid="drawer-decide-reject"]').element as HTMLButtonElement).disabled).toBe(false)
		expect((drawer.find('[data-testid="drawer-decide-return"]').element as HTMLButtonElement).disabled).toBe(false)

		await drawer.find('[data-testid="drawer-decide-reject"]').trigger('click')
		expect(wrapper.emitted('decide')).toEqual([['REJECT']])
	})

	it('disables decisions the server refused and withholds the whole footer for a decided transaction', async () => {
		const refused = mountDrawer({
			transaction: detail({ allowedActions: { canApprove: true, canReject: false } })
		})
		const drawer = await open(refused)
		expect((drawer.find('[data-testid="drawer-decide-approve"]').element as HTMLButtonElement).disabled).toBe(false)
		expect((drawer.find('[data-testid="drawer-decide-reject"]').element as HTMLButtonElement).disabled).toBe(true)
		expect((drawer.find('[data-testid="drawer-decide-return"]').element as HTMLButtonElement).disabled).toBe(true)

		const decided = mountDrawer({ managerState: 'DECIDED_BY_MANAGER' })
		const decidedDrawer = await open(decided)
		expect(decidedDrawer.find('[data-testid="drawer-decide-approve"]').exists()).toBe(false)
		expect(decidedDrawer.find('[data-testid="drawer-decide-reject"]').exists()).toBe(false)
	})

	it('keeps refusal, absence and failure three different answers', async () => {
		const forbidden = mountDrawer({ readState: 'forbidden' })
		await open(forbidden)
		expect(forbidden.find('[data-testid="details-drawer-forbidden"]').exists()).toBe(true)
		expect(forbidden.find('[data-testid="drawer-field-sheet"]').exists()).toBe(false)

		const notFound = mountDrawer({ readState: 'notFound' })
		await open(notFound)
		expect(notFound.find('[data-testid="details-drawer-not-found"]').exists()).toBe(true)

		const unavailable = mountDrawer({ readState: 'unavailable', transaction: null })
		await open(unavailable)
		expect(unavailable.find('[data-testid="details-drawer-unavailable"]').exists()).toBe(true)

		const loading = mountDrawer({ readState: 'loading', transaction: null })
		await open(loading)
		expect(loading.find('[data-testid="details-drawer-loading"]').exists()).toBe(true)
	})

	it('closes on Escape and restores focus to the control that opened it', async () => {
		const opener = document.createElement('button')
		opener.setAttribute('data-testid', 'drawer-opener')
		document.body.appendChild(opener)
		opener.focus()

		const wrapper = mountDrawer()
		await open(wrapper)
		expect(document.activeElement).toBe(wrapper.get('[data-testid="details-drawer"]').element)

		await wrapper.get('[data-testid="details-drawer"]').trigger('keydown', { key: 'Escape' })
		expect(wrapper.emitted('close')).toHaveLength(1)

		await wrapper.setProps({ open: false })
		expect(document.activeElement).toBe(opener)
		opener.remove()
	})

	it('inherits direction from the running language instead of pinning one', async () => {
		const controlled = directionHolder.direction
		expect(controlled, 'the direction mock must be installed').not.toBeNull()
		const wrapper = mountDrawer()
		await open(wrapper)
		expect(wrapper.get('[data-testid="details-drawer-layer"]').attributes('dir')).toBe('ltr')

		controlled!.value = 'rtl'
		const rtl = mountDrawer()
		await open(rtl)
		expect(rtl.get('[data-testid="details-drawer-layer"]').attributes('dir')).toBe('rtl')
		controlled!.value = 'ltr'
	})

	it('renders nothing while closed', () => {
		const wrapper = mountDrawer()

		expect(wrapper.find('[data-testid="details-drawer-layer"]').exists()).toBe(false)
	})
})
