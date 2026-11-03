// 005 T075 — the OPEN-sheet eye icon opens the existing edit form directly.
//
// This guard covers the route branch (OPEN requests the editor, non-OPEN keeps inspection), the
// capability fallback, persisted-field prefill, the existing evidence list/preview/removal, the
// draft save path (no submission, no receipt requirement), and destructive deletion confirmation.
// It mounts the real workspace, surface and form, replacing only the transport boundaries.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import SheetDetailView from '@/views/sheets/SheetDetailView.vue'
import { routes } from '@/router'
import { useSheetStore } from '@/stores/sheets'
import type {
	Evidence,
	Money,
	Revision,
	SheetDetail,
	SheetStatus,
	SheetSummary,
	SheetTotals,
	TransactionDetail,
	TransactionStatus,
	TransactionSummary
} from '@/types/api'

const ocsRequest = vi.hoisted(() => vi.fn())

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

const configurationApi = vi.hoisted(() => ({
	listCostCenters: vi.fn(),
	listActiveExpenseTypes: vi.fn()
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (...args: unknown[]) => ocsRequest(...args),
		isAxiosError: (error: unknown) => Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError)
	}
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
	imagePath: (path: string) => `/apps/pettycash/${path}`,
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'en'),
	isRTL: vi.fn(() => false),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

vi.mock('@/services/transactionsApi', () => transactionsApi)
vi.mock('@/services/evidenceApi', () => evidenceApi)
vi.mock('@/services/configurationApi', () => configurationApi)

// Shared component doubles: the guard asserts behaviour through the application's own markers.
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
		props: ['type', 'role', 'heading'],
		template: '<div v-bind="$attrs"><slot /></div>'
	},
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name', 'title'],
		emits: ['update:open'],
		template: '<div v-if="open"><slot /></div>'
	}
}))

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
		allowedActions: { canEdit: true, canDelete: true, canCorrect: false },
		decisions: [],
		evidence: [],
		...overrides
	}
}

function summaryOf(transaction: TransactionDetail): TransactionSummary {
	const { allowedActions: _allowedActions, decisions: _decisions, evidence: _evidence, ...summary } = transaction
	return summary
}

function totals(): SheetTotals {
	const zero: Money = { amountMinor: 0, currency: 'IRR', minorUnit: 0 }
	return {
		current: MONEY,
		submitted: zero,
		effectiveApproved: zero,
		effectiveRejected: zero,
		effectivePending: zero,
		costCenterApproved: zero,
		seniorApproved: zero,
		financeApproved: zero
	}
}

function sheetFixture(status: SheetStatus, transaction: TransactionDetail): SheetDetail {
	return {
		id: 'sh-1',
		accountId: 'b4b0b3a4-1111-4c2d-9e0f-000000000001',
		title: 'March travel',
		ownerId: 'employee1',
		ownerDisplayName: 'Employee One',
		currency: 'IRR',
		status,
		transactionCount: 1,
		totals: totals(),
		createdAt: '2026-09-01T08:00:00+00:00',
		submittedAt: status === 'OPEN' ? null : '2026-09-21T08:00:00+00:00',
		completedAt: null,
		firstExpenseDate: '2026-09-20',
		lastExpenseDate: '2026-09-20',
		activeRevisionNumber: 1,
		version: 2,
		allowedActions: {},
		transactions: [summaryOf(transaction)],
		...{}
	}
}

function sheetSummary(sheet: SheetDetail): SheetSummary {
	const { transactions: _transactions, allowedActions: _allowedActions, ...summary } = sheet
	return summary
}

function ocsOk(data: unknown) {
	return Promise.resolve({ data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } })
}

describe('OPEN-sheet transaction editing (T075)', () => {
	let pinia = createPinia()
	let router: Router
	let wrappers: VueWrapper[] = []
	let currentDetail: TransactionDetail
	let originalCreate: typeof URL.createObjectURL
	let originalRevoke: typeof URL.revokeObjectURL

	function track<T extends VueWrapper>(wrapper: T): T {
		wrappers.push(wrapper)
		return wrapper
	}

	function stubTransport(sheet: SheetDetail): void {
		ocsRequest.mockImplementation((config: { url?: string; method?: string }) => {
			const url = config?.url ?? ''
			if (config?.method && config.method !== 'GET') return ocsOk(sheet)
			if (/\/sheets\/sh-1(\?|$)/.test(url)) return ocsOk(sheet)
			if (/\/sheets\?/.test(url)) return ocsOk({ items: [sheetSummary(sheet)], page: 1, pageSize: 25, total: 1 })
			return ocsOk({ items: [], page: 1, pageSize: 25, total: 0 })
		})
	}

	async function mountWorkspace(sheet: SheetDetail, transaction: TransactionDetail): Promise<VueWrapper> {
		currentDetail = transaction
		transactionsApi.getTransaction.mockImplementation(async () => currentDetail)
		transactionsApi.listRevisions.mockResolvedValue([revision()])
		transactionsApi.updateTransaction.mockImplementation(async (_id: string, data: any, _version: number) => {
			currentDetail = detail({
				...currentDetail,
				version: currentDetail.version + 1,
				activeRevision: revision({
					expenseDate: data.expenseDate,
					amount: { ...MONEY, amountMinor: data.amountMinor },
					description: data.description,
					costCenter: { ...COST_CENTER, id: data.costCenterId },
					expenseType: { ...EXPENSE_TYPE, id: data.expenseTypeId }
				})
			})
			return currentDetail
		})
		transactionsApi.deleteTransaction.mockResolvedValue(undefined)
		evidenceApi.deleteEvidence.mockResolvedValue(evidence())
		evidenceApi.getEvidenceContent.mockResolvedValue(new Blob(['png']))
		configurationApi.listCostCenters.mockResolvedValue({ items: [COST_CENTER], page: 1, pageSize: 100, total: 1 })
		configurationApi.listActiveExpenseTypes.mockResolvedValue({ items: [EXPENSE_TYPE], page: 1, pageSize: 100, total: 1 })

		stubTransport(sheet)
		pinia = createPinia()
		setActivePinia(pinia)
		router = createRouter({ history: createWebHashHistory(), routes })
		await router.push('/sheets/sh-1')
		await router.isReady()

		const wrapper = track(mount(SheetDetailView, {
			global: { plugins: [pinia, router] },
			attachTo: document.body
		}))
		await flushPromises()
		await flushPromises()
		return wrapper
	}

	async function openEye(wrapper: VueWrapper): Promise<void> {
		const eye = wrapper.find('[data-test="view-transaction"][data-transaction-id="tx-1"]')
		expect(eye.exists()).toBe(true)
		await eye.trigger('click')
		await flushPromises()
		await flushPromises()
	}

	function controlValue(wrapper: VueWrapper, testId: string): string {
		const root = wrapper.find(`[data-test="${testId}"]`)
		const control = /^(INPUT|TEXTAREA|SELECT)$/.test(root.element.tagName)
			? root
			: root.find('input, textarea, select')
		return (control.element as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement).value
	}

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		ocsRequest.mockReset()
		for (const fn of Object.values(transactionsApi)) fn.mockReset()
		for (const fn of Object.values(evidenceApi)) fn.mockReset()
		for (const fn of Object.values(configurationApi)) fn.mockReset()

		originalCreate = URL.createObjectURL
		originalRevoke = URL.revokeObjectURL
		URL.createObjectURL = vi.fn(() => 'blob:preview') as unknown as typeof URL.createObjectURL
		URL.revokeObjectURL = vi.fn() as unknown as typeof URL.revokeObjectURL
	})

	afterEach(() => {
		for (const wrapper of wrappers) wrapper.unmount()
		wrappers = []
		document.body.innerHTML = ''
		URL.createObjectURL = originalCreate
		URL.revokeObjectURL = originalRevoke
		vi.restoreAllMocks()
	})

	it('opens the editable form directly from an OPEN sheet eye click, with persisted values', async () => {
		const transaction = detail()
		const wrapper = await mountWorkspace(sheetFixture('OPEN', transaction), transaction)

		await openEye(wrapper)

		expect(router.currentRoute.value.query).toMatchObject({ transaction: 'tx-1', edit: '1' })
		expect(wrapper.find('[data-test="transaction-surface"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="transaction-form-view"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="transaction-surface-active-revision"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="transaction-surface-title"]').text()).toBe('transactions.editTitle')
		expect(controlValue(wrapper, 'amount-input')).toBe('125000')
		expect(controlValue(wrapper, 'description-input')).toBe('Airport taxi')
		expect(controlValue(wrapper, 'expense-date-input')).toBe('2026-09-20')
	})

	it('falls back to read-only inspection when the server does not grant edit', async () => {
		const transaction = detail({ allowedActions: {} })
		const wrapper = await mountWorkspace(sheetFixture('OPEN', transaction), transaction)

		await openEye(wrapper)

		expect(wrapper.find('[data-test="transaction-form-view"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="transaction-surface-active-revision"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="edit-transaction-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="transaction-surface-readonly"]').exists()).toBe(false)
	})

	it('keeps the non-OPEN inspection path unchanged and does not request the editor', async () => {
		const transaction = detail({
			status: 'AWAITING_COST_CENTER_REVIEW',
			stage: 'COST_CENTER',
			allowedActions: {}
		})
		const wrapper = await mountWorkspace(sheetFixture('COST_CENTER', transaction), transaction)

		await openEye(wrapper)

		expect(router.currentRoute.value.query.transaction).toBe('tx-1')
		expect(router.currentRoute.value.query.edit).toBeUndefined()
		expect(wrapper.find('[data-test="transaction-form-view"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="transaction-surface-active-revision"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="transaction-surface-readonly"]').exists()).toBe(true)
	})

	it('shows active evidence with add, preview, download and authorized removal inside the edit form', async () => {
		const receipt = evidence()
		const transaction = detail({ evidence: [receipt], evidenceCount: 1 })
		const wrapper = await mountWorkspace(sheetFixture('OPEN', transaction), transaction)
		vi.spyOn(window, 'confirm').mockReturnValue(true)

		await openEye(wrapper)

		expect(wrapper.find('[data-test="evidence-uploader"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="evidence-upload-input"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="evidence-name"]').text()).toBe('receipt.png')
		expect(wrapper.find('[data-test="download-evidence-btn"]').exists()).toBe(true)

		const surface = wrapper.find('[data-test="transaction-surface"]')
		expect(surface.attributes('role')).toBe('dialog')

		await wrapper.find('[data-test="preview-evidence-btn"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-test="evidence-preview"]').exists()).toBe(true)
		expect(surface.attributes('role')).toBeUndefined()
		expect(surface.attributes('inert')).toBeDefined()
		expect(wrapper.findAll('[role="dialog"]')).toHaveLength(1)

		await wrapper.find('[data-test="evidence-preview"]').trigger('keydown', { key: 'Escape' })
		await flushPromises()

		expect(wrapper.find('[data-test="evidence-preview"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="transaction-form-view"]').exists()).toBe(true)

		await wrapper.find('[data-test="delete-evidence-btn"]').trigger('click')
		await flushPromises()

		expect(evidenceApi.deleteEvidence).toHaveBeenCalledOnce()
		expect(evidenceApi.deleteEvidence.mock.calls[0]?.[0]).toBe('ev-1')
		expect(transactionsApi.getTransaction.mock.calls.length).toBeGreaterThanOrEqual(2)
	})

	it('saves through the existing update path without submitting or requiring a receipt', async () => {
		const transaction = detail({ evidenceRequired: true, evidenceCount: 0 })
		const wrapper = await mountWorkspace(sheetFixture('OPEN', transaction), transaction)

		await openEye(wrapper)

		// T075 refinement (B): the OPEN/DRAFT edit footer keeps the native submit control and hides
		// the secondary save. Both used to call the same handler, and two buttons for one save is the
		// defect this refinement removes.
		const saveActions = wrapper.findAll('[data-test="submit-btn"]')
		expect(saveActions).toHaveLength(1)
		expect(saveActions[0]?.text()).toBe('transactions.saveChanges')
		expect(wrapper.find('[data-test="transaction-save-draft"]').exists()).toBe(false)
		expect((saveActions[0]?.element as HTMLButtonElement).disabled).toBe(false)

		await wrapper.find('[data-test="description-input"]').setValue('Airport taxi, corrected')
		await wrapper.find('[data-test="transaction-form-view"] form').trigger('submit.prevent')
		await flushPromises()
		await flushPromises()

		expect(transactionsApi.updateTransaction).toHaveBeenCalledOnce()
		const [id, payload, version] = transactionsApi.updateTransaction.mock.calls[0] as [
			string,
			Record<string, unknown>,
			number
		]
		expect(id).toBe('tx-1')
		expect(version).toBe(4)
		expect(payload).toEqual({
			expenseDate: '2026-09-20',
			amountMinor: 125000,
			description: 'Airport taxi, corrected',
			costCenterId: COST_CENTER.id,
			expenseTypeId: EXPENSE_TYPE.id
		})
		expect(currentDetail.activeRevision.description).toBe('Airport taxi, corrected')
		expect(currentDetail.status).toBe('DRAFT')
		expect(transactionsApi.submitTransactionDecision).not.toHaveBeenCalled()
		expect(transactionsApi.resubmitCorrection).not.toHaveBeenCalled()

		// The host reloads the owning sheet and returns to the same workspace route.
		await vi.waitFor(() => {
			expect(router.currentRoute.value.name).toBe('sheet-detail')
			expect(router.currentRoute.value.query.transaction).toBeUndefined()
			expect(router.currentRoute.value.query.edit).toBeUndefined()
		})
		expect(useSheetStore().currentSheet?.status).toBe('OPEN')
	})

	it('deletes only after confirmation, with the existing versioned DELETE, and returns to the workspace', async () => {
		const transaction = detail()
		const wrapper = await mountWorkspace(sheetFixture('OPEN', transaction), transaction)

		await openEye(wrapper)

		const deleteButton = wrapper.find('[data-test="delete-transaction-btn"]')
		expect(deleteButton.exists()).toBe(true)
		await deleteButton.trigger('click')
		expect(wrapper.find('[data-test="delete-transaction-dialog"]').exists()).toBe(true)
		expect(transactionsApi.deleteTransaction).not.toHaveBeenCalled()

		await wrapper.find('[data-test="confirm-delete-transaction"]').trigger('click')
		await flushPromises()
		await flushPromises()

		expect(transactionsApi.deleteTransaction).toHaveBeenCalledOnce()
		const [id, version, options] = transactionsApi.deleteTransaction.mock.calls[0] as [
			string,
			number,
			{ commandId?: string }
		]
		expect(id).toBe('tx-1')
		expect(version).toBe(4)
		expect(options.commandId).toBeTruthy()
		await vi.waitFor(() => {
			expect(router.currentRoute.value.query.transaction).toBeUndefined()
			expect(router.currentRoute.value.query.edit).toBeUndefined()
		})
	})
})
