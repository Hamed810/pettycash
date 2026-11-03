// 005 T076 — FR-005-047 / C-21: evidence lifecycle presentation follows the transaction's state.
//
// A never-submitted DRAFT on an OPEN sheet shows the purchaser's ordinary working set: no
// Active/Inactive badge, no withdrawal timestamp, and no withdrawn rows. Submitted and correction
// transactions keep the inactive history that 001 FR-059/FR-060 require. The conjunction matters:
// `OPEN` alone must not hide lifecycle presentation, and a submitted legacy row can still read
// `DRAFT` while its sheet has left OPEN.
//
// The guards mount the real surface/form and replace only the transport boundaries.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import type {
	Evidence,
	Money,
	Revision,
	SheetDetail,
	SheetStatus,
	TransactionDetail,
	TransactionStatus
} from '@/types/api'
import { routes } from '@/router'
import { useSheetStore } from '@/stores/sheets'

const transactionsApi = vi.hoisted(() => ({
	createTransaction: vi.fn(),
	getTransaction: vi.fn(),
	updateTransaction: vi.fn(),
	deleteTransaction: vi.fn(),
	submitTransactionDecision: vi.fn(),
	createCorrection: vi.fn(),
	resubmitCorrection: vi.fn(),
	listRevisions: vi.fn(),
	listAudit: vi.fn()
}))

const evidenceApi = vi.hoisted(() => ({
	uploadEvidence: vi.fn(),
	deleteEvidence: vi.fn(),
	getEvidenceContent: vi.fn()
}))

vi.mock('@/services/transactionsApi', () => transactionsApi)
vi.mock('@/services/evidenceApi', () => evidenceApi)

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'en'),
	isRTL: vi.fn(() => false),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading', 'type', 'ariaLabel'],
		emits: ['click'],
		template: '<button type="button" :disabled="disabled" v-bind="$attrs" @click="$emit(\'click\', $event)"><slot /></button>'
	},
	NcChip: {
		name: 'NcChip',
		props: ['variant', 'noClose'],
		template: '<span v-bind="$attrs"><slot /></span>'
	},
	NcIconSvgWrapper: {
		name: 'NcIconSvgWrapper',
		props: ['path', 'size'],
		template: '<span data-test="nc-icon" />'
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type', 'role'],
		template: '<div v-bind="$attrs"><slot /></div>'
	},
	NcProgressBar: {
		name: 'NcProgressBar',
		props: ['value'],
		template: '<div />'
	},
	NcBreadcrumbs: {
		name: 'NcBreadcrumbs',
		props: ['items'],
		template: '<nav><slot /></nav>'
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'ariaLabelCombobox', 'reduce', 'getOptionKey', 'loading', 'disabled', 'required', 'error', 'helperText'],
		template: '<div data-test="nc-select" />'
	},
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name', 'title', 'size'],
		emits: ['update:open'],
		template: '<div v-if="open"><slot /></div>'
	}
}))

import TransactionSurface from '@/components/transactions/TransactionSurface.vue'
import TransactionDetailView from '@/views/transactions/TransactionDetailView.vue'

const MONEY: Money = { amountMinor: 125000, currency: 'IRR', minorUnit: 0 }
const COST_CENTER = { id: 'cc-1', code: 'OPS', name: 'Operations' }
const EXPENSE_TYPE = { id: 'et-1', code: 'TAXI', name: 'Taxi' }

function revision(overrides: Partial<Revision> = {}): Revision {
	return {
		id: 'rev-1',
		number: 1,
		expenseDate: '2026-09-20',
		amount: MONEY,
		description: 'Airport taxi',
		costCenter: COST_CENTER,
		expenseType: EXPENSE_TYPE,
		createdBy: 'employee1',
		createdAt: '2026-09-20T10:00:00+00:00',
		active: true,
		...overrides
	}
}

function evidence(overrides: Partial<Evidence> = {}): Evidence {
	return {
		id: 'ev-1',
		transactionId: 'tx-1',
		revisionId: 'rev-1',
		name: 'receipt.png',
		mediaType: 'image/png',
		sizeBytes: 1024,
		active: true,
		uploadedBy: 'employee1',
		uploadedAt: '2026-09-20T10:05:00+00:00',
		...overrides
	}
}

function detail(overrides: Partial<TransactionDetail> = {}): TransactionDetail {
	const status: TransactionStatus = overrides.status ?? 'DRAFT'
	return {
		id: 'tx-1',
		sheetId: 'sh-1',
		activeRevision: revision(),
		stage: status === 'DRAFT' ? 'DRAFT' : 'COST_CENTER',
		status,
		finalOutcome: null,
		evidenceRequired: false,
		evidenceCount: 0,
		version: 4,
		allowedActions: {},
		decisions: [],
		evidence: [],
		...overrides
	}
}

function sheet(status: SheetStatus): SheetDetail {
	return {
		id: 'sh-1',
		accountId: 'b4b0b3a4-1111-4c2d-9e0f-000000000001',
		title: 'March travel',
		ownerId: 'employee1',
		ownerDisplayName: 'Employee One',
		currency: 'IRR',
		status,
		transactionCount: 1,
		totals: {
			current: MONEY,
			submitted: { ...MONEY, amountMinor: 0 },
			effectiveApproved: { ...MONEY, amountMinor: 0 },
			effectiveRejected: { ...MONEY, amountMinor: 0 },
			effectivePending: { ...MONEY, amountMinor: 0 },
			costCenterApproved: { ...MONEY, amountMinor: 0 },
			seniorApproved: { ...MONEY, amountMinor: 0 },
			financeApproved: { ...MONEY, amountMinor: 0 }
		},
		createdAt: '2026-09-01T08:00:00+00:00',
		submittedAt: status === 'OPEN' ? null : '2026-09-21T08:00:00+00:00',
		completedAt: null,
		firstExpenseDate: '2026-09-20',
		lastExpenseDate: '2026-09-20',
		activeRevisionNumber: 1,
		version: 2,
		allowedActions: {},
		transactions: []
	}
}

describe('Evidence-status presentation (T076 / FR-005-047 / C-21)', () => {
	let pinia = createPinia()
	let router: Router
	let wrappers: VueWrapper[] = []

	function track<T extends VueWrapper>(wrapper: T): T {
		wrappers.push(wrapper)
		return wrapper
	}

	async function mountSurface(options: {
		detail?: TransactionDetail
		sheetStatus?: SheetStatus | null
		startInEdit?: boolean
		revisions?: Revision[]
		currentSheet?: SheetDetail
	} = {}): Promise<VueWrapper> {
		transactionsApi.getTransaction.mockResolvedValue(options.detail ?? detail())
		transactionsApi.listRevisions.mockResolvedValue(options.revisions ?? [revision()])
		transactionsApi.updateTransaction.mockResolvedValue(options.detail ?? detail())
		evidenceApi.deleteEvidence.mockResolvedValue(evidence())
		evidenceApi.getEvidenceContent.mockResolvedValue(new Blob(['png']))

		pinia = createPinia()
		setActivePinia(pinia)
		if (options.currentSheet) useSheetStore().currentSheet = options.currentSheet
		router = createRouter({ history: createWebHashHistory(), routes })
		await router.push('/sheets/sh-1')
		await router.isReady()

		const wrapper = track(mount(TransactionSurface, {
			props: {
				transactionId: 'tx-1',
				sheetId: 'sh-1',
				sheetStatus: options.sheetStatus ?? 'OPEN',
				startInEdit: options.startInEdit ?? false
			},
			global: { plugins: [pinia, router] },
			attachTo: document.body
		}))
		await flushPromises()
		await flushPromises()
		return wrapper
	}

	async function mountDetail(options: {
		detail: TransactionDetail
		sheetDetail?: SheetDetail
		revisions?: Revision[]
	}): Promise<VueWrapper> {
		transactionsApi.getTransaction.mockResolvedValue(options.detail)
		transactionsApi.listRevisions.mockResolvedValue(options.revisions ?? [revision()])

		pinia = createPinia()
		setActivePinia(pinia)
		if (options.sheetDetail) useSheetStore().currentSheet = options.sheetDetail
		router = createRouter({ history: createWebHashHistory(), routes })
		await router.push('/transactions/tx-1')
		await router.isReady()

		const wrapper = track(mount(TransactionDetailView, {
			global: { plugins: [pinia, router] },
			attachTo: document.body
		}))
		await flushPromises()
		await flushPromises()
		return wrapper
	}

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		for (const fn of Object.values(transactionsApi)) fn.mockReset()
		for (const fn of Object.values(evidenceApi)) fn.mockReset()
	})

	afterEach(() => {
		for (const wrapper of wrappers) wrapper.unmount()
		wrappers = []
		document.body.innerHTML = ''
		vi.restoreAllMocks()
	})

	it('hides lifecycle chrome and withdrawn rows for a never-submitted DRAFT on an OPEN sheet', async () => {
		const active = evidence({ id: 'ev-active', name: 'current.png', active: true })
		const withdrawn = evidence({
			id: 'ev-withdrawn',
			name: 'withdrawn.png',
			active: false,
			deactivatedAt: '2026-09-21T09:00:00+00:00'
		})
		const wrapper = await mountSurface({
			detail: detail({ evidence: [active, withdrawn], evidenceCount: 2 }),
			sheetStatus: 'OPEN'
		})

		const rows = wrapper.findAll('[data-test="evidence-list-item"]')
		expect(rows).toHaveLength(1)
		expect(rows[0]?.text()).toContain('current.png')
		expect(wrapper.find('[data-test="evidence-name"]').text()).toBe('current.png')
		expect(wrapper.findAll('[data-test="evidence-active-badge"]')).toHaveLength(0)
		expect(wrapper.findAll('[data-test="deactivation-info"]')).toHaveLength(0)
		// Authorized addition and removal remain available.
		expect(wrapper.find('[data-test="download-evidence-btn"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="delete-evidence-btn"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="transaction-surface-evidence"]').text()).toContain('(1)')
	})

	it('does not hide lifecycle presentation when only the sheet is OPEN', async () => {
		const active = evidence({ id: 'ev-active', name: 'current.png', active: true })
		const withdrawn = evidence({
			id: 'ev-withdrawn',
			name: 'withdrawn.png',
			active: false,
			deactivatedAt: '2026-09-21T09:00:00+00:00'
		})
		const wrapper = await mountSurface({
			detail: detail({
				status: 'AWAITING_COST_CENTER_REVIEW',
				stage: 'COST_CENTER',
				evidence: [active, withdrawn],
				evidenceCount: 2
			}),
			sheetStatus: 'OPEN'
		})

		expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(2)
		expect(wrapper.findAll('[data-test="evidence-active-badge"]')).toHaveLength(2)
		expect(wrapper.findAll('[data-test="deactivation-info"]')).toHaveLength(1)
	})

	it('keeps inactive evidence history for a submitted transaction', async () => {
		const active = evidence({ id: 'ev-active', name: 'current.png', active: true })
		const withdrawn = evidence({
			id: 'ev-withdrawn',
			name: 'withdrawn.png',
			active: false,
			deactivatedAt: '2026-09-21T09:00:00+00:00'
		})
		const wrapper = await mountSurface({
			detail: detail({
				status: 'AWAITING_COST_CENTER_REVIEW',
				stage: 'COST_CENTER',
				evidence: [active, withdrawn],
				evidenceCount: 2
			}),
			sheetStatus: 'COST_CENTER'
		})

		expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(2)
		expect(wrapper.findAll('[data-test="evidence-active-badge"]')).toHaveLength(2)
		expect(wrapper.find('[data-test="deactivation-info"]').text()).toContain('2026')
	})

	it('keeps inactive evidence history for a correction-stage transaction', async () => {
		const working = revision({ id: 'rev-2', number: 2, active: true })
		const active = evidence({ id: 'ev-active', name: 'current.png', revisionId: 'rev-2', active: true })
		const withdrawn = evidence({
			id: 'ev-withdrawn',
			name: 'withdrawn.png',
			revisionId: 'rev-1',
			active: false,
			deactivatedAt: '2026-09-21T09:00:00+00:00'
		})
		const wrapper = await mountSurface({
			detail: detail({
				status: 'AWAITING_PURCHASER_CORRECTION',
				stage: 'CORRECTION',
				activeRevision: working,
				allowedActions: { canCorrect: true },
				evidence: [active, withdrawn],
				evidenceCount: 2
			}),
			sheetStatus: 'CORRECTION',
			revisions: [revision({ active: false }), working]
		})

		expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(2)
		expect(wrapper.findAll('[data-test="evidence-active-badge"]')).toHaveLength(2)
		expect(wrapper.find('[data-test="deactivation-info"]').text()).toContain('2026')
		expect(wrapper.find('[data-test="create-correction-btn"]').exists()).toBe(true)
	})

	it('carries the working-set presentation into the embedded edit form', async () => {
		const active = evidence({ id: 'ev-active', name: 'current.png', active: true })
		const withdrawn = evidence({ id: 'ev-withdrawn', name: 'withdrawn.png', active: false })
		const current = detail({
			allowedActions: { canEdit: true, canDelete: true },
			evidence: [active, withdrawn],
			evidenceCount: 2
		})

		pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = await mountSurface({
			detail: current,
			sheetStatus: 'OPEN',
			startInEdit: true,
			currentSheet: sheet('OPEN')
		})

		expect(wrapper.find('[data-test="transaction-form-view"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="evidence-uploader"]').exists()).toBe(true)
		expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(1)
		expect(wrapper.findAll('[data-test="evidence-active-badge"]')).toHaveLength(0)
		expect(wrapper.findAll('[data-test="deactivation-info"]')).toHaveLength(0)
		expect(wrapper.find('[data-test="delete-evidence-btn"]').exists()).toBe(true)
	})

	it('applies the same working-set presentation on the direct detail route', async () => {
		const active = evidence({ id: 'ev-active', name: 'current.png', active: true })
		const withdrawn = evidence({
			id: 'ev-withdrawn',
			name: 'withdrawn.png',
			active: false,
			deactivatedAt: '2026-09-21T09:00:00+00:00'
		})
		const wrapper = await mountDetail({
			detail: detail({ evidence: [active, withdrawn], evidenceCount: 2 }),
			sheetDetail: sheet('OPEN')
		})

		expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(1)
		expect(wrapper.findAll('[data-test="evidence-active-badge"]')).toHaveLength(0)
		expect(wrapper.findAll('[data-test="deactivation-info"]')).toHaveLength(0)
		expect(wrapper.find('[data-test="evidence-list"]').text()).toContain('(1)')
	})

	it('keeps the detail route lifecycle history for a submitted transaction', async () => {
		const active = evidence({ id: 'ev-active', name: 'current.png', active: true })
		const withdrawn = evidence({
			id: 'ev-withdrawn',
			name: 'withdrawn.png',
			active: false,
			deactivatedAt: '2026-09-21T09:00:00+00:00'
		})
		const wrapper = await mountDetail({
			detail: detail({
				status: 'AWAITING_COST_CENTER_REVIEW',
				stage: 'COST_CENTER',
				evidence: [active, withdrawn],
				evidenceCount: 2
			}),
			sheetDetail: sheet('COST_CENTER')
		})

		expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(2)
		expect(wrapper.findAll('[data-test="evidence-active-badge"]')).toHaveLength(2)
		expect(wrapper.find('[data-test="deactivation-info"]').exists()).toBe(true)
	})

	it('drops a removed attachment from the direct detail route list', async () => {
		const active = evidence({ id: 'ev-active', name: 'current.png', active: true })
		const wrapper = await mountDetail({
			detail: detail({
				allowedActions: { canEdit: true, canDelete: true },
				evidence: [active],
				evidenceCount: 1
			}),
			sheetDetail: sheet('OPEN')
		})
		vi.spyOn(window, 'confirm').mockReturnValue(true)

		expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(1)
		transactionsApi.getTransaction.mockResolvedValueOnce(detail({ evidence: [], evidenceCount: 0 }))
		await wrapper.find('[data-test="delete-evidence-btn"]').trigger('click')
		await flushPromises()
		await flushPromises()

		expect(evidenceApi.deleteEvidence).toHaveBeenCalledWith('ev-active')
		expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(0)
	})

	it('drops a removed attachment from the ordinary list after the host refreshes', async () => {
		const active = evidence({ id: 'ev-active', name: 'current.png', active: true })
		transactionsApi.getTransaction
			.mockResolvedValueOnce(detail({ evidence: [active], evidenceCount: 1 }))
			.mockResolvedValueOnce(detail({ evidence: [], evidenceCount: 0 }))
		vi.spyOn(window, 'confirm').mockReturnValue(true)

		const wrapper = await mountSurface({
			detail: detail({ evidence: [active], evidenceCount: 1 }),
			sheetStatus: 'OPEN'
		})

		expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(1)
		await wrapper.find('[data-test="delete-evidence-btn"]').trigger('click')
		await flushPromises()
		await flushPromises()

		expect(evidenceApi.deleteEvidence).toHaveBeenCalledWith('ev-active')
		expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(0)
		expect(wrapper.find('[data-test="evidence-empty"]').exists()).toBe(true)
	})
})
